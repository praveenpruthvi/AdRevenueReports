<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml;

use Aavirbhava\AdsAnalytics\Model\ResourceModel\DailySummary\CollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Dashboard tab data (P3-T4 groundwork).
 *
 * Reads ONLY from ads_analytics_daily_summary (CLAUDE.md #6) — never from the
 * raw visit/funnel tables at request time, no matter how tempting that is
 * while the aggregation cron is still unimplemented. Showing raw counts here
 * "just for now" is exactly how that constraint gets quietly broken.
 *
 * TODO(P3-T4): Chart.js funnel drop-off + source/medium/campaign breakdown
 * using core's bundled copy, with the traffic_type toggle from SPECS.md §8.
 * This class currently supplies the tabular breakdown and totals the template
 * needs; the charts layer on top of the same data.
 */
class Dashboard extends Template
{
    private CollectionFactory $summaryCollectionFactory;

    public function __construct(
        Context $context,
        CollectionFactory $summaryCollectionFactory,
        array $data = []
    ) {
        $this->summaryCollectionFactory = $summaryCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * Rows grouped by traffic_type — the paid-vs-organic comparison that
     * PROJECT_PLAN.md exists for.
     *
     * @return array<array<string, mixed>>
     */
    public function getTrafficTypeBreakdown(): array
    {
        $collection = $this->summaryCollectionFactory->create();
        $select = $collection->getSelect();
        $select->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns([
                'traffic_type' => 'traffic_type',
                'visits' => new \Zend_Db_Expr('SUM(visits)'),
                'add_to_carts' => new \Zend_Db_Expr('SUM(add_to_carts)'),
                'checkout_starts' => new \Zend_Db_Expr('SUM(checkout_starts)'),
                'orders' => new \Zend_Db_Expr('SUM(orders)'),
                'revenue' => new \Zend_Db_Expr('SUM(revenue)'),
            ])
            ->group('traffic_type')
            ->order('visits DESC');

        return $collection->getConnection()->fetchAll($select);
    }

    /**
     * NOT named hasData(): Magento\Framework\DataObject (via Template ->
     * AbstractBlock) already defines hasData($key = ''), and overriding it
     * with a no-argument bool signature is a fatal incompatible-declaration
     * error at compile time.
     */
    public function hasSummaryData(): bool
    {
        return $this->summaryCollectionFactory->create()->getSize() > 0;
    }

    /**
     * Conversion rate as a percentage of visits, guarded against the
     * divide-by-zero that a traffic_type with zero visits would cause.
     */
    public function getConversionRate(array $row): string
    {
        $visits = (int)($row['visits'] ?? 0);
        if ($visits === 0) {
            return '—';
        }

        return number_format(((int)($row['orders'] ?? 0) / $visits) * 100, 2) . '%';
    }

    public function formatRevenue(array $row): string
    {
        return number_format((float)($row['revenue'] ?? 0), 2);
    }
}
