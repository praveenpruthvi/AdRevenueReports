<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\RequestLogConfig;
use Aavirbhava\AdsAnalytics\Model\Config\Source\LoggingLevel;
use Aavirbhava\AdsAnalytics\Model\Service\TrafficResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins which accepted request-log rows survive at each logging level.
 *
 * The rule that matters is 'external_only': the log exists to reveal traffic
 * the module failed to classify, and only a request that arrived from
 * somewhere else can carry evidence of that. A direct visit has no referrer
 * and no campaign parameters by definition, so keeping its row adds volume
 * of raw payloads without adding insight.
 */
class RequestLogConfigTest extends TestCase
{
    /**
     * A FRESH mock per call, deliberately. Re-stubbing getValue() on one
     * shared mock silently keeps the first configured return value, so a test
     * that builds configs at two different levels would quietly assert
     * against the wrong one.
     *
     * @return RequestLogConfig
     */
    private function config(string $level): RequestLogConfig
    {
        /** @var ScopeConfigInterface&MockObject $scopeConfig */
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($level);

        return new RequestLogConfig($scopeConfig);
    }

    public function testAllKeepsEverythingIncludingDirectAndInternalEvents(): void
    {
        $config = $this->config(LoggingLevel::ALL);

        $this->assertTrue($config->shouldKeepAcceptedRow(TrafficResolver::TYPE_PAID));
        $this->assertTrue($config->shouldKeepAcceptedRow(TrafficResolver::TYPE_DIRECT));
        $this->assertTrue($config->shouldKeepAcceptedRow(null), 'a non-landing event is still kept at "all"');
    }

    public function testRejectedOnlyKeepsNoAcceptedRowAtAll(): void
    {
        $config = $this->config(LoggingLevel::REJECTED_ONLY);

        $this->assertFalse($config->shouldKeepAcceptedRow(TrafficResolver::TYPE_PAID));
        $this->assertFalse($config->shouldKeepAcceptedRow(null));
    }

    /**
     * @dataProvider externalTypes
     */
    public function testExternalOnlyKeepsTrafficThatCameFromElsewhere(string $trafficType): void
    {
        $this->assertTrue(
            $this->config(LoggingLevel::EXTERNAL_ONLY)->shouldKeepAcceptedRow($trafficType),
            $trafficType . ' arrived from outside the store, so its URL can carry an unrecognised source'
        );
    }

    /** @return array<string, array{0: string}> */
    public function externalTypes(): array
    {
        return [
            'paid' => [TrafficResolver::TYPE_PAID],
            'organic' => [TrafficResolver::TYPE_ORGANIC],
            'referral' => [TrafficResolver::TYPE_REFERRAL],
        ];
    }

    public function testExternalOnlyDropsDirectTraffic(): void
    {
        $this->assertFalse(
            $this->config(LoggingLevel::EXTERNAL_ONLY)->shouldKeepAcceptedRow(TrafficResolver::TYPE_DIRECT),
            'a direct visit has no referrer and no campaign parameters, so its URL reveals nothing'
        );
    }

    /**
     * A null traffic type means the event was not a landing — a product view
     * or a checkout step. Those are internal navigation: the store's own page
     * reached from the store's own pages.
     */
    public function testExternalOnlyDropsNonLandingEvents(): void
    {
        $this->assertFalse($this->config(LoggingLevel::EXTERNAL_ONLY)->shouldKeepAcceptedRow(null));
    }

    public function testExternalOnlyDropsAnUnclassifiedType(): void
    {
        $this->assertFalse($this->config(LoggingLevel::EXTERNAL_ONLY)->shouldKeepAcceptedRow('unknown'));
    }

    /**
     * Logging is written optimistically before validation at every level
     * except 'off', because the outcome is not yet known and a row lost to a
     * consumer crash mid-validation is exactly the case the log exists for.
     */
    public function testRowsAreWrittenBeforeValidationUnlessLoggingIsOff(): void
    {
        $this->assertTrue($this->config(LoggingLevel::EXTERNAL_ONLY)->shouldLogBeforeValidation());
        $this->assertFalse($this->config(LoggingLevel::OFF)->shouldLogBeforeValidation());
    }
}
