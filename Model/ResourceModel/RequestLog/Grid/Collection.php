<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog\Grid;

use Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog as RequestLogResource;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid data source for the read-only Request Log listing (P3-T5b,
 * docs/SPECS.md §9).
 *
 * This is the one admin grid that legitimately reads a raw table rather than
 * ads_analytics_daily_summary. CLAUDE.md #6 ("aggregation, not live joins")
 * is about the ANALYTICS reports, which must never scan raw event tables at
 * request time. The request log is a bounded debugging surface with its own
 * short retention (docs/SECURITY.md §8), not an analytics one — aggregating
 * it would defeat its entire purpose, which is to show individual malformed
 * requests verbatim.
 */
class Collection extends SearchResult implements SearchResultInterface
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'ads_analytics_request_log',
        $resourceModel = RequestLogResource::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }
}
