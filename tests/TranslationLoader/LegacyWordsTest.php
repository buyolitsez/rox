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
    public function testPublicTranslationCallers(string $sentence, array $arguments, string $raw, string $formatted): void
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
        $dao->query('START TRANSACTION');
        try {
            $dao->query("INSERT INTO word (code, ShortCode, domain, Sentence, created, updated, majorupdate, donottranslate, Description, TranslationPriority) VALUES ('audit.legacy.format', 'en', 'messages', '" . $dao->escape($sentence) . "', NOW(), NOW(), NOW(), 'No', '', 5)");
            $words = new MOD_words();

            self::assertSame($raw, $words->getRaw('audit.legacy.format', $arguments));
            self::assertSame($formatted, $words->get('audit.legacy.format', ...$arguments));
        } finally {
            $dao->query('ROLLBACK');
        }
    }

    public static function translations(): iterable
    {
        yield 'no arguments' => ['Hello %s, %s', [], 'Hello %s, %s', 'Hello %s, %s'];
        yield 'missing arguments' => ['Hello %s, %s', ['guest'], 'Hello %s, %s', 'Hello %s, %s'];
        yield 'invalid specifier' => ['Hello %q', ['guest'], 'Hello %q', 'Hello %q'];
        yield 'trailing percent' => ['100%', [], '100%', '100%'];
        yield 'ordinary translation' => ['Hello %s, %d visits', ['guest', 2], 'Hello guest, 2 visits', 'Hello guest, 2 visits'];
        yield 'positional arguments' => ['%2$s, %1$s', ['first', 'second'], 'second, first', 'second, first'];
        yield 'escaped percent' => ['100%% %s', ['ready'], '100% ready', '100% ready'];
        yield 'zero literal' => ['0', [], '0', '0'];
        yield 'zero formatted' => ['%d', [0], '0', '0'];
        yield 'empty literal' => ['', [], '', ''];
        yield 'empty formatted' => ['%s', [''], '', ''];
        yield 'raw and formatted newlines' => ["Line\n%s", ['next'], "Line\nnext", "Line<br />\nnext"];
        yield 'raw and formatted slashes' => ["Guest\\'s %s", ['room'], "Guest\\'s room", "Guest's room"];
        yield 'malformed formatted newline' => ["Line\n%q", [], "Line\n%q", "Line<br />\n%q"];
    }
}
