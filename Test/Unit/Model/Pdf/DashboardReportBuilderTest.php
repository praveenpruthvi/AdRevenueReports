<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Pdf;

use Aavirbhava\AdsAnalytics\Model\Pdf\DashboardReportBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P4-T2. Pins the one thing most worth pinning here: the funnel query's NULL
 * normalisation. SUM() over zero matching rows returns NULL, not 0, and the
 * PDF generator does arithmetic (rates, subtraction) on every funnel field
 * unconditionally — an unnormalised null reaching it would be a TypeError on
 * an empty date range, which is a real range (a brand-new store, or a
 * mistyped ?from=/?to=) rather than an edge case worth ignoring.
 */
class DashboardReportBuilderTest extends TestCase
{
    /** @var ResourceConnection&MockObject */
    private $resource;
    /** @var AdapterInterface&MockObject */
    private $connection;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('having')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
    }

    private function builder(): DashboardReportBuilder
    {
        return new DashboardReportBuilder($this->resource);
    }

    public function testAnEmptyDateRangeNormalisesNullSumsToZero(): void
    {
        // fetchRow on a SUM() query with zero matching rows returns every
        // aggregate column as NULL, not absent and not 0 — this is the exact
        // shape MySQL/MariaDB return, not a contrived mock.
        $this->connection->method('fetchRow')->willReturn([
            'visits' => null, 'product_views' => null, 'add_to_carts' => null,
            'checkout_starts' => null, 'orders' => null, 'revenue' => null,
        ]);
        $this->connection->method('fetchAll')->willReturn([]);

        $report = $this->builder()->build('2099-01-01', '2099-01-02');

        $this->assertSame(
            ['visits' => 0, 'product_views' => 0, 'add_to_carts' => 0, 'checkout_starts' => 0, 'orders' => 0, 'revenue' => 0.0],
            $report['funnel']
        );
        $this->assertSame([], $report['by_traffic_type']);
        $this->assertSame([], $report['top_campaigns']);
    }

    public function testFetchRowReturningFalseIsAlsoNormalised(): void
    {
        // Zend_Db_Adapter_Abstract::fetchRow() returns false, not an array,
        // when the underlying query matches no row at all (distinct from
        // matching a row whose SUMs are null) — both must reach the
        // generator as zeroed integers/float, never false or a missing key.
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->method('fetchAll')->willReturn([]);

        $report = $this->builder()->build('2099-01-01', '2099-01-02');

        $this->assertSame(0, $report['funnel']['visits']);
        $this->assertSame(0.0, $report['funnel']['revenue']);
    }

    public function testRealFunnelRowIsCastToTheDeclaredTypes(): void
    {
        // PDO can return numeric columns as strings; the report's consumer
        // (DashboardPdfGenerator) does int/float arithmetic on these values,
        // so the builder must hand back real PHP int/float, not "123".
        $this->connection->method('fetchRow')->willReturn([
            'visits' => '120', 'product_views' => '95', 'add_to_carts' => '10',
            'checkout_starts' => '5', 'orders' => '2', 'revenue' => '99.50',
        ]);
        $this->connection->method('fetchAll')->willReturn([]);

        $funnel = $this->builder()->build('2026-01-01', '2026-01-31')['funnel'];

        $this->assertSame(120, $funnel['visits']);
        $this->assertIsInt($funnel['orders']);
        $this->assertSame(99.5, $funnel['revenue']);
        $this->assertIsFloat($funnel['revenue']);
    }

    public function testEarliestDateReturnsNullWhenTableIsEmpty(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $this->assertNull($this->builder()->earliestDate());
    }

    public function testEarliestDateReturnsTheValueAsAString(): void
    {
        $this->connection->method('fetchOne')->willReturn('2026-01-05');

        $this->assertSame('2026-01-05', $this->builder()->earliestDate());
    }
}
