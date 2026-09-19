<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Block\Frontend;

use Aavirbhava\AdsAnalytics\Block\Frontend\BeaconConfig;
use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** P1-T7. */
class BeaconConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */ private $scopeConfig;
    /** @var TrafficClassificationConfig&MockObject */ private $classificationConfig;
    /** @var StoreInterface&MockObject */ private $store;
    /** @var CookieHelper&MockObject */ private $cookieHelper;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        // Default: every flag off. ScopeConfigInterface::isSetFlag always
        // returns bool in real Magento, so an unmocked null here would be a
        // test artifact rather than a real condition.
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->classificationConfig = $this->createMock(TrafficClassificationConfig::class);
        $this->cookieHelper = $this->createMock(CookieHelper::class);
        $this->cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn(false);
        $this->store = $this->getMockBuilder(StoreInterface::class)
            ->disableOriginalConstructor()->addMethods(['getBaseUrl'])->getMockForAbstractClass();
    }

    private function block(): BeaconConfig
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://shop.example/checkout/');

        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($this->scopeConfig);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getEscaper')->willReturn(
            (new ObjectManager($this))->getObject(\Magento\Framework\Escaper::class)
        );

        return new BeaconConfig($context, $this->classificationConfig, new Json(), $this->cookieHelper);
    }

    private function decodedConfig(): array
    {
        return json_decode($this->block()->getBeaconConfigJson(), true);
    }

    /**
     * Regression: this was originally built with getUrl(), which parses its
     * argument as route/controller/action and silently dropped the final
     * segment — producing ".../rest/V1/adsanalytics/" and pointing the beacon
     * at an endpoint that does not exist.
     */
    public function testEndpointKeepsTheEventSegment(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');

        $this->assertSame(
            'https://shop.example/rest/V1/adsanalytics/event',
            $this->decodedConfig()['endpoint']
        );
    }

    /**
     * URL_TYPE_WEB, not URL_TYPE_LINK: the latter prepends the store code
     * when "Add Store Code to URLs" is on, which would 404.
     */
    public function testEndpointUsesWebBaseUrlType(): void
    {
        $this->store->expects($this->once())->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB)->willReturn('https://shop.example/');

        $this->decodedConfig();
    }

    /**
     * The beacon is told parameter NAMES only. Leaking platform codes would
     * put classification data in the browser, where CLAUDE.md #2 says it must
     * never live.
     */
    public function testOnlyClickIdParamNamesAreExposedNeverPlatformCodes(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');
        $this->classificationConfig->method('getPlatformMap')->willReturn([
            'google' => ['click_id_param' => 'gclid', 'default_source' => 'google', 'alt_sources' => []],
            'meta' => ['click_id_param' => 'fbclid', 'default_source' => 'facebook', 'alt_sources' => ['instagram']],
        ]);

        $config = $this->decodedConfig();

        $this->assertSame(['gclid', 'fbclid'], $config['clickIdParams']);
        $encoded = $this->block()->getBeaconConfigJson();
        foreach (['google', 'meta', 'facebook', 'instagram'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    public function testDuplicateClickIdParamsAreDeduplicated(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');
        $this->classificationConfig->method('getPlatformMap')->willReturn([
            'a' => ['click_id_param' => 'gclid', 'default_source' => 'a', 'alt_sources' => []],
            'b' => ['click_id_param' => 'gclid', 'default_source' => 'b', 'alt_sources' => []],
        ]);

        $this->assertSame(['gclid'], $this->decodedConfig()['clickIdParams']);
    }

    /**
     * @dataProvider cookieLifetimeProvider
     */
    public function testCookieLifetimeFallsBackOnNonsenseValues($configured, int $expected): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');
        $this->scopeConfig->method('getValue')->willReturn($configured);

        $this->assertSame($expected, $this->decodedConfig()['cookieLifetimeDays']);
    }

    public function cookieLifetimeProvider(): array
    {
        return [
            'configured' => ['30', 30],
            'zero falls back' => ['0', 90],
            'negative falls back' => ['-1', 90],
            'null falls back' => [null, 90],
        ];
    }

    /**
     * Regression: this originally read `web/cookie/cookie_restriction_enabled`
     * directly. The real path is `web/cookie/cookie_restriction`, so the flag
     * silently always resolved to false and the consent gate would never have
     * fired on a store with restriction mode on. It now delegates to core's
     * own helper, which cannot drift from core's path.
     */
    public function testConsentFlagDelegatesToCoreCookieHelper(): void
    {
        $this->cookieHelper = $this->createMock(CookieHelper::class);
        $this->cookieHelper->expects($this->once())
            ->method('isCookieRestrictionModeEnabled')->willReturn(true);
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');

        $this->assertTrue($this->decodedConfig()['requireCookieConsent']);
    }

    public function testConsentFlagIsFalseWhenRestrictionModeOff(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');

        $this->assertFalse($this->decodedConfig()['requireCookieConsent']);
    }

    /**
     * The beacon detects the checkout page by path, so the path it is given
     * must be the one the store actually resolves — a store that renamed or
     * moved its checkout must still match.
     */
    public function testCheckoutPathIsDerivedFromTheStoreUrl(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');

        $this->assertSame('/checkout/', $this->decodedConfig()['checkoutPath']);
    }

    /**
     * The hash -> event map lives in PHP config, not in the beacon, so no
     * step name is hardcoded in JavaScript. LUMA has no separate review step,
     * so only two entries are expected.
     */
    public function testCheckoutStepEventMapIsSupplied(): void
    {
        $this->store->method('getBaseUrl')->willReturn('https://shop.example/');

        $this->assertSame(
            ['shipping' => 'checkout_step_shipping', 'payment' => 'checkout_step_payment'],
            $this->decodedConfig()['checkoutStepEvents']
        );
    }

    /**
     * Exercises the _toHtml() override directly rather than through
     * AbstractBlock::toHtml(), which needs the full block machinery (event
     * manager, cache keys, session) that is irrelevant to this behaviour.
     */
    public function testDisabledTrackingRendersNothingAtAll(): void
    {
        $block = $this->block(); // isSetFlag defaults to false => disabled

        $method = new \ReflectionMethod($block, '_toHtml');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke($block));
    }
}
