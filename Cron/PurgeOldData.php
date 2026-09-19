<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Cron;

use Aavirbhava\AdsAnalytics\Model\Config\RetentionConfig;
use Aavirbhava\AdsAnalytics\Model\Service\DataPurger;
use Psr\Log\LoggerInterface;

/**
 * P3-T7 / docs/SECURITY.md §8: purges raw event tables and the request log,
 * each against its OWN configurable retention window (the request log's is
 * shorter by default — it holds raw unvalidated payloads).
 *
 * All the logic lives in Model\Service\DataPurger; this class only resolves
 * config, reports, and contains failures. It is scheduled at 02:30 in
 * etc/crontab.xml, half an hour after the aggregation job, so a day's rows
 * are always rolled into ads_analytics_daily_summary before anything can
 * delete them.
 *
 * The two purges are run independently and each in its own try/catch: they
 * target unrelated tables on unrelated windows, so a failure to purge the
 * request log is no reason to leave 180-day-old visitor rows in place.
 */
class PurgeOldData
{
    private DataPurger $purger;
    private RetentionConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        DataPurger $purger,
        RetentionConfig $config,
        LoggerInterface $logger
    ) {
        $this->purger = $purger;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $result = $this->purger->purgeEvents($this->config->getEventRetentionDays());
            $this->logger->info(
                sprintf(
                    'Aavirbhava_AdsAnalytics: purged %d visits and %d funnel events older than %d days.',
                    $result['visits'],
                    $result['funnel_events'],
                    $this->config->getEventRetentionDays()
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Aavirbhava_AdsAnalytics: raw event purge failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        try {
            $deleted = $this->purger->purgeRequestLog($this->config->getRequestLogRetentionDays());
            $this->logger->info(
                sprintf(
                    'Aavirbhava_AdsAnalytics: purged %d request-log rows older than %d days.',
                    $deleted,
                    $this->config->getRequestLogRetentionDays()
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Aavirbhava_AdsAnalytics: request-log purge failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
