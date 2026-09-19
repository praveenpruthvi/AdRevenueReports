<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Cron;

use Aavirbhava\AdsAnalytics\Model\Aggregation\DailySummaryAggregator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * P3-T2: nightly rollup into ads_analytics_daily_summary. Admin reports read
 * ONLY from that table (CLAUDE.md #6).
 *
 * Re-sweeps a lookback WINDOW rather than just yesterday. Events reach the
 * database through a queue, so a visit at 23:59 can be consumed after the
 * 02:00 run, and an order placed near midnight can have its attribution row
 * written later still. Recomputing recent days catches those; the aggregator's
 * upsert replaces rather than increments, so repeated sweeps converge instead
 * of double-counting.
 */
class AggregateDailySummary
{
    private const XML_PATH_LOOKBACK_DAYS = 'aavirbhava_adsanalytics/general/aggregation_lookback_days';
    private const DEFAULT_LOOKBACK_DAYS = 7;

    private DailySummaryAggregator $aggregator;
    private ScopeConfigInterface $scopeConfig;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        DailySummaryAggregator $aggregator,
        ScopeConfigInterface $scopeConfig,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->aggregator = $aggregator;
        $this->scopeConfig = $scopeConfig;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        $days = $this->getLookbackDays();
        $to = $this->dateTime->gmtDate('Y-m-d');
        $from = $this->dateTime->gmtDate('Y-m-d', strtotime('-' . $days . ' days'));

        try {
            $rows = $this->aggregator->aggregate($from, $to);
            $this->logger->info(
                sprintf(
                    'Aavirbhava_AdsAnalytics: aggregated daily summary %s..%s (%d row operations)',
                    $from,
                    $to,
                    $rows
                )
            );
        } catch (\Throwable $e) {
            // Already logged with detail by the aggregator. Swallowed so one
            // bad day cannot wedge the whole cron group.
            $this->logger->error('Aavirbhava_AdsAnalytics: daily aggregation cron aborted: ' . $e->getMessage());
        }
    }

    private function getLookbackDays(): int
    {
        $days = (int)$this->scopeConfig->getValue(self::XML_PATH_LOOKBACK_DAYS);

        return $days > 0 ? $days : self::DEFAULT_LOOKBACK_DAYS;
    }
}
