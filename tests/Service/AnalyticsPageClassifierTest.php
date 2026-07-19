<?php

namespace App\Tests\Service;

use App\Command\ValidateAnalyticsConsentLocalesCommand;
use App\Service\AnalyticsPageClassifier;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

class AnalyticsPageClassifierTest extends TestCase
{
    public function testEveryAllowlistedRouteHasOnlySyntheticContext(): void
    {
        $routeMap = new ReflectionClass(AnalyticsPageClassifier::class)
            ->getReflectionConstant('ROUTE_MAP')
            ?->getValue();
        self::assertIsArray($routeMap);

        foreach (array_keys($routeMap) as $routeName) {
            $context = $this->createClassifier()->classify($routeName, 'fr', 'visitor');

            self::assertNotNull($context, $routeName);
            self::assertMatchesRegularExpression('#^/_analytics/[a-z0-9/-]+$#', $context['path'], $routeName);
            self::assertNotSame('', $context['title'], $routeName);
            self::assertContains($context['contentGroup'], [
                'home', 'auth', 'signup', 'dashboard', 'search', 'profile', 'hospitality',
                'conversations', 'groups', 'forums', 'trips', 'activities', 'donations', 'community',
            ], $routeName);
            self::assertSame('fr', $context['locale'], $routeName);
            self::assertSame('visitor', $context['loginState'], $routeName);
        }
    }

    public function testAuthenticatedHomepageUsesTheFixedDashboardContext(): void
    {
        $context = $this->createClassifier()->classify('homepage', 'en', 'member');

        self::assertNotNull($context);
        self::assertSame('/_analytics/dashboard', $context['path']);
        self::assertSame('Member dashboard', $context['title']);
        self::assertSame('dashboard', $context['contentGroup']);
        self::assertSame('member', $context['loginState']);
    }

    public function testRuntimeTranslationCatalogueCoversEveryConfiguredLocale(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $englishSource = Yaml::parseFile($projectDir . '/translations/missing/analytics.yaml');
        self::assertSame(AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES, array_keys($englishSource));

        $catalogue = Yaml::parseFile($projectDir . '/translations/ready/analytics.yaml');
        $expectedLocales = array_values(array_diff(self::configuredLocales(), ['en']));
        self::assertSame($expectedLocales, array_keys($catalogue));
        foreach ($catalogue as $locale => $translations) {
            self::assertSame(AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES, array_keys($translations), $locale);
            foreach ($translations as $code => $translation) {
                self::assertIsString($translation, $locale . ':' . $code);
                self::assertNotSame('', trim($translation), $locale . ':' . $code);
            }
        }
    }

    public function testEveryLocaleHasAnIcuDatabaseCatalogueResource(): void
    {
        foreach (self::configuredLocales() as $locale) {
            self::assertFileExists(
                \dirname(__DIR__, 2) . '/translations/messages+intl-icu.' . $locale . '.db',
                $locale,
            );
        }
    }

    #[DataProvider('excludedRouteProvider')]
    public function testSensitiveAndUnknownRoutesFailClosed(string $routeName): void
    {
        self::assertNull($this->createClassifier()->classify($routeName, 'en', 'visitor'));
    }

    /** @return iterable<string, array{string}> */
    public static function excludedRouteProvider(): iterable
    {
        foreach (
            [
                'admin_spam',
                'member_download_data',
                'password_forgotten',
                'route_added_without_privacy_review',
            ] as $routeName
        ) {
            yield $routeName => [$routeName];
        }
    }

    #[DataProvider('invalidContextProvider')]
    public function testLocaleAndLoginStateMustBeExact(string $locale, string $loginState): void
    {
        self::assertNull($this->createClassifier()->classify('homepage', $locale, $loginState));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidContextProvider(): iterable
    {
        yield 'unsupported locale' => ['xx', 'visitor'];
        yield 'regional fallback is forbidden' => ['fr-FR', 'visitor'];
        yield 'case fallback is forbidden' => ['EN', 'visitor'];
        yield 'unapproved login state' => ['en', 'admin'];
    }

    public function testExactActiveTranslationsAreRequired(): void
    {
        $readyResult = $this->createMock(Result::class);
        $readyResult->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES);
        $missingResult = $this->createMock(Result::class);
        $missingResult->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(\array_slice(AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES, 0, -1));

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'TRIM(translation.Sentence)')
                    && str_contains($sql, 'translation.updated >= source.majorupdate')
                    && str_contains($sql, 'source.donottranslate = :translationAllowed')),
                [
                    'locale' => 'de',
                    'domain' => 'messages+intl-icu',
                    'codes' => AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES,
                    'translationAllowed' => 'no',
                ],
                ['codes' => ArrayParameterType::STRING],
            )
            ->willReturnOnConsecutiveCalls($readyResult, $missingResult);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $classifier = new AnalyticsPageClassifier($entityManager, ['de']);

        self::assertTrue($classifier->hasConsentTranslations('de'));
        self::assertSame([
            AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES[array_key_last(
                AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES,
            )],
        ], $classifier->getMissingConsentTranslations('de'));
    }

    public function testTranslationDatabaseFailureAndUnsupportedLocaleFailClosed(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new RuntimeException('database unavailable'));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $classifier = new AnalyticsPageClassifier($entityManager, ['en']);

        self::assertSame(
            AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES,
            $classifier->getMissingConsentTranslations('en'),
        );
        self::assertSame(
            AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES,
            $classifier->getMissingConsentTranslations('xx'),
        );
    }

    public function testTranslationReadinessIsMemoizedPerClassifierInstance(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn(AnalyticsPageClassifier::CONSENT_TRANSLATION_CODES);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->willReturn($result);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $classifier = new AnalyticsPageClassifier($entityManager, ['en']);

        self::assertTrue($classifier->hasConsentTranslations('en'));
        self::assertTrue($classifier->hasConsentTranslations('en'));
    }

    public function testLocaleValidationCommandBlocksMissingExactTranslation(): void
    {
        $locales = self::configuredLocales();
        $classifier = $this->createMock(AnalyticsPageClassifier::class);
        $classifier->expects($this->exactly(\count($locales)))
            ->method('getMissingConsentTranslations')
            ->willReturnCallback(static fn (string $locale): array => 'de' === $locale
                ? ['analytics.consent.title']
                : []);
        $tester = new CommandTester(new ValidateAnalyticsConsentLocalesCommand($classifier, $locales));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Locale "de"', $tester->getDisplay());
        self::assertStringContainsString('GOOGLE_ANALYTICS_MEASUREMENT_ID blank', $tester->getDisplay());
    }

    public function testLocaleValidationCommandAcceptsEveryExactLocale(): void
    {
        $locales = self::configuredLocales();
        $classifier = $this->createMock(AnalyticsPageClassifier::class);
        $classifier->expects($this->exactly(\count($locales)))
            ->method('getMissingConsentTranslations')
            ->willReturn([]);
        $tester = new CommandTester(new ValidateAnalyticsConsentLocalesCommand($classifier, $locales));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('translations are active', $tester->getDisplay());
    }

    private function createClassifier(): AnalyticsPageClassifier
    {
        return new AnalyticsPageClassifier(
            $this->createStub(EntityManagerInterface::class),
            self::configuredLocales(),
        );
    }

    /** @return string[] */
    private static function configuredLocales(): array
    {
        $env = file_get_contents(\dirname(__DIR__, 2) . '/.env');
        self::assertIsString($env);
        self::assertSame(1, preg_match('/^LOCALES=([^\r\n]+)$/m', $env, $matches));

        return explode(',', $matches[1]);
    }
}
