<?php

namespace App\Tests\TranslationLoader;

use App\Utilities\SessionSingleton;
use Doctrine\DBAL\Connection;
use MOD_words;
use PDB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PVars;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('integration')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class LegacyWordsTest extends KernelTestCase
{
    #[DataProvider('translations')]
    public function testPublicTranslationCallers(string $sentence, array $arguments, string $raw, string $formatted, bool $malformed = false): void
    {
        self::bootKernel();
        $parameters = self::getContainer()->get(Connection::class)->getParams();
        $dsn = 'mysqli:host=' . $parameters['host'] . ';dbname=' . $parameters['dbname'];
        PVars::register('config_rdbms', ['dsn' => $dsn, 'user' => $parameters['user'], 'password' => $parameters['password']]);
        PVars::register('lang', 'en');
        $session = new Session(new MockArraySessionStorage());
        $session->set('Param', (object) ['memcache' => 'False', 'ToggleStatsForWordsUsage' => 'No']);
        SessionSingleton::createInstance($session);
        $dao = PDB::get($dsn, $parameters['user'], $parameters['password']);
        $logFile = tempnam(sys_get_temp_dir(), 'legacy-word-');
        $previousLog = ini_set('error_log', $logFile);
        $dao->query('START TRANSACTION');
        try {
            $dao->query("INSERT INTO word (code, ShortCode, domain, Sentence, created, updated, majorupdate, donottranslate, Description, TranslationPriority) VALUES ('test.legacy.format', 'en', 'messages', '" . $dao->escape($sentence) . "', NOW(), NOW(), NOW(), 'No', '', 5)");
            $words = new MOD_words();

            self::assertSame($raw, $words->getRaw('test.legacy.format', $arguments));
            self::assertSame($formatted, $words->get('test.legacy.format', ...$arguments));
            self::assertSame($malformed ? 2 : 0, substr_count(file_get_contents($logFile), 'Malformed translation format for word "test.legacy.format"'));
        } finally {
            $dao->query('ROLLBACK');
            ini_set('error_log', $previousLog);
            unlink($logFile);
        }
    }

    public static function translations(): iterable
    {
        yield 'no arguments' => ['Hello %s, %s', [], 'Hello  -x- ,  -x- ', 'Hello  -x- ,  -x- '];
        yield 'missing arguments' => ['Hello %s, %s', ['guest'], 'Hello guest,  -x- ', 'Hello guest,  -x- '];
        yield 'invalid specifier' => ['Hello %q', ['guest'], 'Hello %q', 'Hello %q', true];
        yield 'trailing percent' => ['100%', [], '100%', '100%', true];
        yield 'beyond fallback limit' => ['%11$s', [], '%11$s', '%11$s', true];
        yield 'more than ten supplied arguments' => ['%11$s', array_fill(0, 11, 'guest'), 'guest', 'guest'];
        yield 'ordinary translation' => ['Hello %s, %d visits', ['guest', 2], 'Hello guest, 2 visits', 'Hello guest, 2 visits'];
        yield 'positional arguments' => ['%2$s, %1$s', ['first', 'second'], 'second, first', 'second, first'];
        yield 'escaped percent' => ['100%% %s', ['ready'], '100% ready', '100% ready'];
        yield 'zero literal' => ['0', [], '0', '0'];
        yield 'zero formatted' => ['%d', [0], '0', '0'];
        yield 'empty literal' => ['', [], '', ''];
        yield 'empty formatted' => ['%s', [''], '', ''];
        yield 'raw and formatted newlines' => ["Line\n%s", ['next'], "Line\nnext", "Line<br />\nnext"];
        yield 'raw and formatted slashes' => ["Guest\\'s %s", ['room'], "Guest\\'s room", "Guest's room"];
        yield 'malformed formatted newline' => ["Line\n%q", [], "Line\n%q", "Line<br />\n%q", true];
    }
}
