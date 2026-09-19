<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Integration\Model\Aggregation;

use Aavirbhava\AdsAnalytics\Model\Aggregation\DailySummaryAggregator;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * P3-T2. The aggregator is almost entirely SQL, so a unit test with mocks
 * would assert nothing that matters — these run it against a real database.
 */
class DailySummaryAggregatorTest extends TestCase
{
    /** @var DailySummaryAggregator */
    private $aggregator;

    /** @var ResourceConnection */
    private $resource;

    protected function setUp(): void
    {
        $om = Bootstrap::getObjectManager();
        $this->aggregator = $om->create(DailySummaryAggregator::class);
        $this->resource = $om->get(ResourceConnection::class);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    private function clean(): void
    {
        $c = $this->resource->getConnection();
        foreach ([
            'ads_analytics_daily_summary',
            'ads_analytics_order_attribution',
            'ads_analytics_funnel_event',
            'ads_analytics_visit',
        ] as $t) {
            $c->delete($this->resource->getTableName($t));
        }
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /** @return int visit_id */
    private function makeVisit(array $data): int
    {
        $c = $this->resource->getConnection();
        $c->insert($this->table('ads_analytics_visit'), $data + [
            'traffic_type' => 'paid',
            'first_seen_at' => '2026-05-10 10:00:00',
            'last_seen_at' => '2026-05-10 10:00:00',
        ]);

        return (int)$c->lastInsertId($this->table('ads_analytics_visit'));
    }

    private function summaryRows(): array
    {
        $c = $this->resource->getConnection();

        return $c->fetchAll(
            $c->select()->from($this->table('ads_analytics_daily_summary'))->order('source ASC')
        );
    }

    public function testVisitsAreBucketedByTheDayTheVisitStarted(): void
    {
        $this->makeVisit([
            'visitor_uuid' => 'v1', 'platform_code' => 'google',
            'last_touch_source' => 'google', 'last_touch_medium' => 'cpc',
            'last_touch_campaign' => 'spring',
            // last_seen_at is deliberately a LATER day: the visit must still
            // count on the day it started, or a returning visitor would
            // migrate between buckets and break idempotency.
            'first_seen_at' => '2026-05-10 23:50:00',
            'last_seen_at' => '2026-05-12 08:00:00',
        ]);

        $this->aggregator->aggregate('2026-05-01', '2026-05-31');

        $rows = $this->summaryRows();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-05-10', $rows[0]['date']);
        $this->assertSame(1, (int)$rows[0]['visits']);
    }

    /**
     * CLAUDE.md #7: the raw tables allow NULL dimensions but the summary's
     * unique key spans them and is NOT NULL. Without COALESCE this throws
     * MySQL 1048 and the whole job dies.
     */
    public function testNullDimensionsBecomeTheSentinelRatherThanFailing(): void
    {
        $this->makeVisit([
            'visitor_uuid' => 'v-organic', 'traffic_type' => 'organic',
            'platform_code' => null, 'last_touch_source' => 'google',
            'last_touch_medium' => 'organic', 'last_touch_campaign' => null,
        ]);

        $this->aggregator->aggregate('2026-05-01', '2026-05-31');

        $rows = $this->summaryRows();
        $this->assertCount(1, $rows);
        $this->assertSame('null_source', $rows[0]['platform_code']);
        $this->assertSame('null_source', $rows[0]['campaign']);
        $this->assertSame(
            'organic',
            $rows[0]['medium'],
            "a real value must survive — only NULLs become the sentinel"
        );
    }

    public function testFunnelEventsAreCountedAgainstTheirVisitsAttribution(): void
    {
        $visitId = $this->makeVisit([
            'visitor_uuid' => 'v2', 'platform_code' => 'meta',
            'last_touch_source' => 'facebook', 'last_touch_medium' => 'cpc',
            'last_touch_campaign' => 'retarget',
        ]);
        $c = $this->resource->getConnection();
        foreach (['add_to_cart', 'add_to_cart', 'checkout_start', 'product_view'] as $type) {
            $c->insert($this->table('ads_analytics_funnel_event'), [
                'visit_id' => $visitId, 'event_type' => $type,
                'created_at' => '2026-05-10 11:00:00',
            ]);
        }

        $this->aggregator->aggregate('2026-05-01', '2026-05-31');

        $rows = $this->summaryRows();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int)$rows[0]['add_to_carts']);
        $this->assertSame(1, (int)$rows[0]['checkout_starts']);
        // product_view is intentionally not a summary metric.
        $this->assertSame(1, (int)$rows[0]['visits']);
    }

    /**
     * Running the rollup twice must converge, not double. AMQP redelivery and
     * the cron's own lookback window both mean a day gets recomputed
     * routinely, so this is the single most important property here.
     */
    public function testAggregationIsIdempotent(): void
    {
        $visitId = $this->makeVisit([
            'visitor_uuid' => 'v3', 'platform_code' => 'bing',
            'last_touch_source' => 'bing', 'last_touch_medium' => 'cpc',
            'last_touch_campaign' => 'always-on',
        ]);
        $this->resource->getConnection()->insert($this->table('ads_analytics_funnel_event'), [
            'visit_id' => $visitId, 'event_type' => 'add_to_cart',
            'created_at' => '2026-05-10 11:00:00',
        ]);

        $this->aggregator->aggregate('2026-05-01', '2026-05-31');
        $first = $this->summaryRows();

        $this->aggregator->aggregate('2026-05-01', '2026-05-31');
        $this->aggregator->aggregate('2026-05-01', '2026-05-31');
        $second = $this->summaryRows();

        $this->assertEquals($first, $second, 're-running must not change the numbers');
        $this->assertCount(1, $second);
        $this->assertSame(1, (int)$second[0]['visits']);
        $this->assertSame(1, (int)$second[0]['add_to_carts']);
    }

    public function testOnlyTheRequestedDateWindowIsAggregated(): void
    {
        $this->makeVisit([
            'visitor_uuid' => 'in-window', 'last_touch_source' => 'in',
            'first_seen_at' => '2026-05-10 10:00:00', 'last_seen_at' => '2026-05-10 10:00:00',
        ]);
        $this->makeVisit([
            'visitor_uuid' => 'out-of-window', 'last_touch_source' => 'out',
            'first_seen_at' => '2026-06-20 10:00:00', 'last_seen_at' => '2026-06-20 10:00:00',
        ]);

        $this->aggregator->aggregate('2026-05-01', '2026-05-31');

        $rows = $this->summaryRows();
        $this->assertCount(1, $rows);
        $this->assertSame('in', $rows[0]['source']);
    }

    /** A window with nothing in it must be a clean no-op, not an error. */
    public function testEmptyWindowWritesNothing(): void
    {
        $this->aggregator->aggregate('2020-01-01', '2020-01-02');

        $this->assertSame([], $this->summaryRows());
    }
}
