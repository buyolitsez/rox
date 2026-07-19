<?php

namespace App\Tests\Service;

use App\Service\AnalyticsPageClassifier;
use App\Twig\Extension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupInterface;

class AnalyticsConfigurationTest extends TestCase
{
    public function testEligiblePageUsesOnlySyntheticContext(): void
    {
        $request = $this->createRequest('search_locations', 'en');
        $classifier = $this->createMock(AnalyticsPageClassifier::class);
        $classifier->expects($this->once())
            ->method('hasConsentTranslations')
            ->with('en')
            ->willReturn(true);
        $classifier->expects($this->once())
            ->method('classify')
            ->with('search_locations', 'en', 'member')
            ->willReturn([
                'path' => '/_analytics/search/member-location',
                'title' => 'Member location search',
                'contentGroup' => 'search',
                'locale' => 'en',
                'loginState' => 'member',
            ]);

        $configuration = $this->createExtension($request, $classifier, ' G-TEST123 ')
            ->analyticsConfiguration(true);

        self::assertSame([
            'measurementId' => 'G-TEST123',
            'consentLocale' => 'en',
            'page' => [
                'page_location' => 'https://www.bewelcome.org/_analytics/search/member-location',
                'page_title' => 'Member location search',
                'content_group' => 'search',
                'language' => 'en',
                'login_state' => 'member',
            ],
        ], $configuration);
    }

    public function testInvalidMeasurementIdSkipsDatabaseWork(): void
    {
        $request = $this->createRequest('homepage', 'en');
        $classifier = $this->createMock(AnalyticsPageClassifier::class);
        $classifier->expects($this->never())->method('hasConsentTranslations');
        $classifier->expects($this->never())->method('classify');

        self::assertSame([
            'measurementId' => null,
            'consentLocale' => null,
            'page' => null,
        ], $this->createExtension($request, $classifier, 'invalid')->analyticsConfiguration(false));
    }

    public function testUnreadyLocaleUsesEnglishSettingsWithoutBecomingEligible(): void
    {
        $request = $this->createRequest('homepage', 'de');
        $classifier = $this->createStub(AnalyticsPageClassifier::class);
        $classifier->method('hasConsentTranslations')
            ->willReturnCallback(static fn (string $locale): bool => 'en' === $locale);
        $classifier->method('classify')->willReturn([
            'path' => '/_analytics/home',
            'title' => 'Home',
            'contentGroup' => 'home',
            'locale' => 'de',
            'loginState' => 'visitor',
        ]);

        $configuration = $this->createExtension($request, $classifier, 'G-TEST123')
            ->analyticsConfiguration(false);

        self::assertSame('en', $configuration['consentLocale']);
        self::assertNull($configuration['page']);
    }

    private function createRequest(string $route, string $locale): Request
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);
        $request->setLocale($locale);

        return $request;
    }

    private function createExtension(
        Request $request,
        AnalyticsPageClassifier $classifier,
        string $measurementId,
    ): Extension {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new Extension(
            $requestStack,
            $this->createStub(TranslatorInterface::class),
            $this->createStub(EntrypointLookupInterface::class),
            $this->createStub(LoggerInterface::class),
            [],
            '/tmp',
            $classifier,
            $measurementId,
            'https://www.bewelcome.org',
        );
    }
}
