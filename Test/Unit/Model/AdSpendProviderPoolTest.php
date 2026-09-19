<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\CsvAdSpendProvider;
use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * docs/TESTING.md §1 — resolves by platform_code, handles a missing provider
 * gracefully, and (added directly in response to "why only google and meta —
 * I don't see any option to add for them") falls back to the generic CSV
 * provider for any platform the admin has already configured in "Paid
 * Platform / Click-ID Map", not only platforms with an explicit di.xml entry.
 */
class AdSpendProviderPoolTest extends TestCase
{
    /** @var TrafficClassificationConfig&MockObject */
    private $classificationConfig;
    /** @var CsvAdSpendProvider&MockObject */
    private $csvAdSpendProvider;

    protected function setUp(): void
    {
        $this->classificationConfig = $this->createMock(TrafficClassificationConfig::class);
        $this->classificationConfig->method('getPlatformMap')->willReturn([]);
        $this->csvAdSpendProvider = $this->createMock(CsvAdSpendProvider::class);
    }

    /**
     * @param AdSpendProviderInterface[] $providers
     */
    private function pool(array $providers = []): AdSpendProviderPool
    {
        return new AdSpendProviderPool($this->classificationConfig, $this->csvAdSpendProvider, $providers);
    }

    /** @param array<string, mixed> $platformMap */
    private function stubPlatformMap(array $platformMap): void
    {
        $this->classificationConfig = $this->createMock(TrafficClassificationConfig::class);
        $this->classificationConfig->method('getPlatformMap')->willReturn($platformMap);
    }

    public function testAnExplicitDiXmlProviderIsResolvedByPlatformCode(): void
    {
        $provider = $this->createMock(AdSpendProviderInterface::class);
        $pool = $this->pool(['google' => $provider]);

        $this->assertSame($provider, $pool->getProvider('google'));
        $this->assertTrue($pool->hasProvider('google'));
    }

    public function testHasProviderIsFalseForAPlatformNeitherRegisteredNorConfigured(): void
    {
        $this->assertFalse($this->pool()->hasProvider('pinterest'));
    }

    public function testGetProviderThrowsForAPlatformNeitherRegisteredNorConfigured(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->pool()->getProvider('pinterest');
    }

    /** The di.xml array can be empty — a bare pool must still construct. */
    public function testEmptyPoolConstructs(): void
    {
        $this->assertFalse($this->pool()->hasProvider('anything'));
    }

    /**
     * The behaviour this whole class was extended for: a platform with no
     * di.xml entry, but that DOES appear in the admin-editable Platform Map,
     * must still resolve — to the generic CSV provider — rather than
     * requiring a developer to add a di.xml line for every platform an
     * admin might ever configure.
     */
    public function testFallsBackToTheCsvProviderForAConfiguredPlatformWithNoExplicitEntry(): void
    {
        $this->stubPlatformMap(['reddit' => ['click_id_param' => 'rdt_cid']]);

        $pool = $this->pool([]);

        $this->assertTrue($pool->hasProvider('reddit'));
        $this->assertSame($this->csvAdSpendProvider, $pool->getProvider('reddit'));
    }

    public function testAnExplicitDiXmlEntryOverridesTheCsvFallbackForTheSamePlatform(): void
    {
        $this->stubPlatformMap(['google' => ['click_id_param' => 'gclid']]);
        $realProvider = $this->createMock(AdSpendProviderInterface::class);

        $pool = $this->pool(['google' => $realProvider]);

        $this->assertSame(
            $realProvider,
            $pool->getProvider('google'),
            'a real, explicitly-registered provider must win over the generic CSV fallback'
        );
    }

    public function testAPlatformNotInThePlatformMapStillHasNoProvider(): void
    {
        // A configured platform gets the fallback; an UNCONFIGURED one still
        // correctly has no provider at all — the fallback is not "always
        // true", it is scoped to what the admin has actually set up.
        $this->stubPlatformMap(['google' => ['click_id_param' => 'gclid']]);

        $this->assertFalse($this->pool([])->hasProvider('some_platform_nobody_configured'));
    }

    public function testGetPlatformCodesUnionsDiXmlEntriesAndThePlatformMap(): void
    {
        $this->stubPlatformMap([
            'google' => ['click_id_param' => 'gclid'],
            'reddit' => ['click_id_param' => 'rdt_cid'],
        ]);
        $realProvider = $this->createMock(AdSpendProviderInterface::class);

        // "meta" only via di.xml, "reddit" only via the platform map,
        // "google" via both — must appear exactly once in the result.
        $codes = $this->pool(['meta' => $realProvider, 'google' => $realProvider])->getPlatformCodes();

        sort($codes);
        $this->assertSame(['google', 'meta', 'reddit'], $codes);
    }

    public function testGetPlatformCodesHasNoDuplicatesWhenDiXmlAndThePlatformMapOverlap(): void
    {
        $this->stubPlatformMap(['google' => ['click_id_param' => 'gclid']]);
        $realProvider = $this->createMock(AdSpendProviderInterface::class);

        $codes = $this->pool(['google' => $realProvider])->getPlatformCodes();

        $this->assertCount(1, $codes);
    }
}
