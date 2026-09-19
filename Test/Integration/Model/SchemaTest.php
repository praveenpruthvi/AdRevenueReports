<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Integration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * docs/TESTING.md §2 — "schema installs cleanly on a fresh DB".
 *
 * This is the one thing unit tests structurally cannot check: that
 * etc/db_schema.xml actually produces the tables, columns, defaults and
 * constraints it claims. Every assertion here corresponds to a decision that
 * was argued for in review and would be easy to undo by accident.
 */
class SchemaTest extends TestCase
{
    /**
     * Untyped on purpose: Magento's integration framework nulls out test
     * properties between tests to reclaim memory, which is a fatal error on a
     * non-nullable typed property.
     *
     * @var ResourceConnection
     */
    private $resource;

    protected function setUp(): void
    {
        $this->resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
    }

    private function connection()
    {
        return $this->resource->getConnection();
    }

    /**
     * @dataProvider tableProvider
     */
    public function testTableExists(string $table): void
    {
        $this->assertTrue(
            $this->connection()->isTableExists($this->resource->getTableName($table)),
            "$table was not created by declarative schema"
        );
    }

    public function tableProvider(): array
    {
        return [
            ['ads_analytics_visit'],
            ['ads_analytics_funnel_event'],
            ['ads_analytics_order_attribution'],
            ['ads_analytics_daily_summary'],
            ['ads_analytics_request_log'],
        ];
    }

    /**
     * CLAUDE.md #7. If any of these become nullable again, upsert-by-unique-key
     * silently breaks because MySQL never dedupes NULLs.
     *
     * @dataProvider summarySentinelProvider
     */
    public function testDailySummarySentinelColumns(string $column): void
    {
        $describe = $this->connection()->describeTable(
            $this->resource->getTableName('ads_analytics_daily_summary')
        );

        $this->assertFalse($describe[$column]['NULLABLE'], "$column must stay NOT NULL");
        $this->assertSame('null_source', $describe[$column]['DEFAULT'], "$column default drifted");
    }

    public function summarySentinelProvider(): array
    {
        return [['platform_code'], ['source'], ['medium'], ['campaign']];
    }

    public function testVisitTrafficTypeDefaultsToUnknownBugIndicator(): void
    {
        $describe = $this->connection()->describeTable($this->resource->getTableName('ads_analytics_visit'));

        $this->assertFalse($describe['traffic_type']['NULLABLE']);
        $this->assertSame('unknown', $describe['traffic_type']['DEFAULT']);
    }

    /**
     * The consumer inserts this row BEFORE validation, so the column must
     * have a default or that insert fails outright.
     */
    public function testRequestLogValidationStatusDefaultsToPending(): void
    {
        $describe = $this->connection()->describeTable($this->resource->getTableName('ads_analytics_request_log'));

        $this->assertFalse($describe['validation_status']['NULLABLE']);
        $this->assertSame('pending', $describe['validation_status']['DEFAULT']);
    }

    /**
     * The unique key is what makes the aggregation cron's upsert idempotent.
     * Magento hashes long referenceIds, so this looks it up by COLUMN SET
     * rather than by name.
     */
    public function testDailySummaryUniqueKeyCoversTheFullSlice(): void
    {
        $indexes = $this->connection()->getIndexList(
            $this->resource->getTableName('ads_analytics_daily_summary')
        );

        // getIndexList() reports uniqueness via INDEX_TYPE ('primary' |
        // 'unique' | 'index'), not a boolean UNIQUE key.
        $expected = ['date', 'traffic_type', 'platform_code', 'source', 'medium', 'campaign'];
        $found = false;
        foreach ($indexes as $index) {
            if ($index['COLUMNS_LIST'] === $expected && $index['INDEX_TYPE'] === 'unique') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'unique key over the full summary slice is missing');
    }

    /**
     * Proven in review against live MySQL; pinned here so a schema change
     * cannot quietly reintroduce the duplicate-rows bug.
     */
    public function testExplicitNullIntoSentinelColumnIsRejected(): void
    {
        $table = $this->resource->getTableName('ads_analytics_daily_summary');

        $this->expectException(\Exception::class);
        $this->connection()->insert($table, [
            'date' => '2026-01-01', 'traffic_type' => 'direct',
            'platform_code' => 'null_source', 'source' => null,
            'medium' => 'none', 'campaign' => 'null_source', 'visits' => 1,
        ]);
    }

    public function testFunnelEventRequiresAnExistingVisit(): void
    {
        $table = $this->resource->getTableName('ads_analytics_funnel_event');

        $this->expectException(\Exception::class);
        $this->connection()->insert($table, [
            'visit_id' => 999999999, 'event_type' => 'add_to_cart',
        ]);
    }
}
