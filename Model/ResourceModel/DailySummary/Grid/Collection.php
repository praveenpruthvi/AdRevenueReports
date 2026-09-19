<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\ResourceModel\DailySummary\Grid;

use Aavirbhava\AdsAnalytics\Model\ResourceModel\DailySummary as DailySummaryResource;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid data source for the Ads Analytics report (P3-T5).
 *
 * Reads ads_analytics_daily_summary and nothing else — CLAUDE.md #6 forbids
 * admin grids from scanning the raw visit/funnel tables at request time. Every
 * number here was computed by Cron\AggregateDailySummary.
 */
class Collection extends SearchResult implements SearchResultInterface
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'ads_analytics_daily_summary',
        $resourceModel = DailySummaryResource::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    /**
     * Derived columns the grid shows but the table does not store.
     *
     * Computed in SQL rather than PHP so they can be SORTED and FILTERED like
     * any other column — a PHP-side calculation would only ever apply to the
     * current page, which is exactly the trap that makes a "conversion rate"
     * column misleading.
     *
     * NULLIF guards the divide-by-zero for a slice with no visits, which is
     * normal under first-touch attribution where an order can land on a slice
     * that recorded none.
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->getSelect()->columns([
            'conversion_rate' => new \Zend_Db_Expr(
                'ROUND(100 * orders / NULLIF(visits, 0), 2)'
            ),
            // Bounded by 100% because product_views counts visits that
            // reached a product page, not raw pageviews (see the column's
            // comment in etc/db_schema.xml).
            'view_rate' => new \Zend_Db_Expr(
                'ROUND(100 * product_views / NULLIF(visits, 0), 2)'
            ),
            'cart_rate' => new \Zend_Db_Expr(
                'ROUND(100 * add_to_carts / NULLIF(visits, 0), 2)'
            ),
            'revenue_per_visit' => new \Zend_Db_Expr(
                'ROUND(revenue / NULLIF(visits, 0), 2)'
            ),
        ]);

        return $this;
    }
}
