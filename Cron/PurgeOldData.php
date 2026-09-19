<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Cron;

/**
 * P3-T7 / docs/SECURITY.md §8: purges raw event tables and the request log,
 * each against its OWN configurable retention window (request log's is
 * shorter by default — it holds raw unvalidated payloads).
 */
class PurgeOldData
{
    public function execute(): void
    {
        // TODO(P3-T7):
        //   - delete ads_analytics_visit / ads_analytics_funnel_event /
        //     ads_analytics_order_attribution rows older than
        //     general/event_retention_days (system.xml).
        //   - delete ads_analytics_request_log rows older than
        //     logging/request_log_retention_days (system.xml) — separately,
        //     do not share the same config value.
        //   - never touch ads_analytics_daily_summary — retained indefinitely.
    }
}
