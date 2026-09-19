<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\RetentionConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P3-T7.
 *
 * These tests exist for one reason: the difference between "not configured"
 * and "configured to zero" is the difference between applying a 180-day
 * default and deleting every raw row in the module on the next cron tick.
 * The distinction is easy to erase with an innocent-looking `(int)` cast, so
 * it is pinned here.
 */
class RetentionConfigTest extends TestCase
{
    private const PATH_EVENTS = 'aavirbhava_adsanalytics/retention/event_retention_days';
    private const PATH_LOG = 'aavirbhava_adsanalytics/logging/request_log_retention_days';

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    private function config(): RetentionConfig
    {
        return new RetentionConfig($this->scopeConfig);
    }

    private function stub(?string $events, ?string $log): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            [self::PATH_EVENTS, 'default', null, $events],
            [self::PATH_LOG, 'default', null, $log],
        ]);
    }

    public function testReturnsConfiguredValues(): void
    {
        $this->stub('45', '3');

        $this->assertSame(45, $this->config()->getEventRetentionDays());
        $this->assertSame(3, $this->config()->getRequestLogRetentionDays());
    }

    /**
     * A missing config row must fall back to the shipped default, NOT to 0.
     * Casting null to int first would yield 0, which the purger reads as
     * "disabled" — silently turning off retention on any install whose
     * config row has not been written yet.
     */
    public function testMissingValueFallsBackToShippedDefaultNotZero(): void
    {
        $this->stub(null, null);

        $this->assertSame(180, $this->config()->getEventRetentionDays());
        $this->assertSame(14, $this->config()->getRequestLogRetentionDays());
    }

    public function testEmptyStringFallsBackToShippedDefault(): void
    {
        $this->stub('', '');

        $this->assertSame(180, $this->config()->getEventRetentionDays());
        $this->assertSame(14, $this->config()->getRequestLogRetentionDays());
    }

    /**
     * An explicit 0 means "never purge" and must be preserved as 0, so the
     * purger skips the delete entirely. It must never be rewritten into the
     * default, which would start deleting data an admin asked to keep.
     */
    public function testExplicitZeroMeansDisabledAndIsPreserved(): void
    {
        $this->stub('0', '0');

        $this->assertSame(0, $this->config()->getEventRetentionDays());
        $this->assertSame(0, $this->config()->getRequestLogRetentionDays());
    }

    /**
     * A negative window would produce a cutoff in the FUTURE, making
     * `WHERE last_seen_at < cutoff` match every row ever recorded. It is
     * clamped to the disabled state rather than trusted.
     */
    public function testNegativeValueIsClampedToDisabled(): void
    {
        $this->stub('-30', '-1');

        $this->assertSame(0, $this->config()->getEventRetentionDays());
        $this->assertSame(0, $this->config()->getRequestLogRetentionDays());
    }

    public function testNonNumericValueIsTreatedAsDisabledRatherThanDefault(): void
    {
        $this->stub('not a number', 'abc');

        // (int)'not a number' === 0, which is the disabled state. Failing
        // closed (keeping data) is the safe direction for a delete job.
        $this->assertSame(0, $this->config()->getEventRetentionDays());
        $this->assertSame(0, $this->config()->getRequestLogRetentionDays());
    }

    /**
     * The two windows must come from separate config paths — the request log
     * holds raw unvalidated payloads and is deliberately purged far sooner
     * (docs/SECURITY.md §8). Sharing one path would quietly retain attacker
     * -controllable text for the full analytics window.
     */
    public function testTheTwoWindowsAreIndependent(): void
    {
        $this->stub('180', '14');

        $this->assertNotSame(
            $this->config()->getEventRetentionDays(),
            $this->config()->getRequestLogRetentionDays()
        );
    }
}
