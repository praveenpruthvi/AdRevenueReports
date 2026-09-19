<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrafficClassificationConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;
    /** @var LoggerInterface&MockObject */
    private $logger;
    private TrafficClassificationConfig $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = new TrafficClassificationConfig($this->scopeConfig, new Json(), $this->logger);
    }

    private function stubConfig(string $path, $value): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn ($p) => $p === "aavirbhava_adsanalytics/platforms/$path" ? $value : null
        );
    }

    public function testParsesPlatformMapAndNormalisesCase(): void
    {
        $this->stubConfig('platform_map', json_encode([
            'r1' => ['platform_code' => '  GOOGLE ', 'click_id_param' => ' gclid ', 'default_source' => 'Google', 'alt_sources' => ''],
        ]));

        $map = $this->config->getPlatformMap();

        $this->assertArrayHasKey('google', $map);
        $this->assertSame('gclid', $map['google']['click_id_param']);
        $this->assertSame('google', $map['google']['default_source']);
    }

    /**
     * A row with an empty click_id_param would make empty($queryParams[''])
     * the match condition, which is true for essentially every request — so
     * such a row must be dropped, not kept.
     */
    public function testRowsWithoutPlatformCodeOrClickIdParamAreDropped(): void
    {
        $this->stubConfig('platform_map', json_encode([
            'r1' => ['platform_code' => 'google', 'click_id_param' => ''],
            'r2' => ['platform_code' => '', 'click_id_param' => 'gclid'],
            'r3' => ['platform_code' => 'bing', 'click_id_param' => 'msclkid', 'default_source' => 'bing'],
        ]));

        $this->assertSame(['bing'], array_keys($this->config->getPlatformMap()));
    }

    public function testDefaultSourceFallsBackToPlatformCodeWhenBlank(): void
    {
        $this->stubConfig('platform_map', json_encode([
            'r1' => ['platform_code' => 'tiktok', 'click_id_param' => 'ttclid', 'default_source' => ''],
        ]));

        $this->assertSame('tiktok', $this->config->getPlatformMap()['tiktok']['default_source']);
    }

    public function testAltSourcesCsvIsSplitTrimmedAndLowercased(): void
    {
        $this->stubConfig('platform_map', json_encode([
            'r1' => ['platform_code' => 'meta', 'click_id_param' => 'fbclid', 'default_source' => 'facebook',
                     'alt_sources' => ' InStaGram , , threads '],
        ]));

        $this->assertSame(['instagram', 'threads'], $this->config->getPlatformMap()['meta']['alt_sources']);
    }

    public function testPaidMediumsAreLowercasedAndDeduplicated(): void
    {
        $this->stubConfig('paid_mediums', json_encode([
            'r1' => ['medium' => 'CPC'], 'r2' => ['medium' => 'cpc'], 'r3' => ['medium' => ' PPC '], 'r4' => ['medium' => ''],
        ]));

        $this->assertSame(['cpc', 'ppc'], $this->config->getPaidMediums());
    }

    /**
     * An admin typing ".google.com" or " Google.COM " would otherwise produce
     * a domain that can never match, which looks exactly like "organic
     * tracking is broken" rather than "the config has a typo".
     */
    public function testSearchEngineDomainsToleratesLeadingDotAndCasing(): void
    {
        $this->stubConfig('search_engine_domains', json_encode([
            'r1' => ['domain' => '.Google.COM ', 'engine' => 'Google'],
        ]));

        $this->assertSame(['google.com' => 'google'], $this->config->getSearchEngineDomains());
    }

    public function testEngineFallsBackToDomainWhenBlank(): void
    {
        $this->stubConfig('search_engine_domains', json_encode([
            'r1' => ['domain' => 'ecosia.org', 'engine' => ''],
        ]));

        $this->assertSame(['ecosia.org' => 'ecosia.org'], $this->config->getSearchEngineDomains());
    }

    /**
     * A broken config row must not take down the queue consumer, which calls
     * this on every ingested event. It degrades to empty and logs critical.
     */
    public function testMalformedJsonDegradesToEmptyAndLogsCritical(): void
    {
        $this->stubConfig('platform_map', '{not valid json');
        $this->logger->expects($this->once())->method('critical')
            ->with($this->stringContains('is not valid JSON'));

        $this->assertSame([], $this->config->getPlatformMap());
    }

    public function testMissingConfigReturnsEmptyWithoutLogging(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->logger->expects($this->never())->method('critical');

        $this->assertSame([], $this->config->getPlatformMap());
        $this->assertSame([], $this->config->getPaidMediums());
        $this->assertSame([], $this->config->getSearchEngineDomains());
    }

    public function testNonArrayRowsAreIgnored(): void
    {
        $this->stubConfig('platform_map', json_encode(['r1' => 'a string', 'r2' => ['platform_code' => 'bing', 'click_id_param' => 'msclkid']]));

        $this->assertSame(['bing'], array_keys($this->config->getPlatformMap()));
    }

    /** Config is read once per instance — the consumer calls this per message. */
    public function testResultIsMemoised(): void
    {
        $this->scopeConfig->expects($this->once())->method('getValue')
            ->willReturn(json_encode(['r1' => ['platform_code' => 'google', 'click_id_param' => 'gclid']]));

        $this->config->getPlatformMap();
        $this->config->getPlatformMap();
    }
}
