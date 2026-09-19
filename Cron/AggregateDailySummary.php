<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Cron;

/**
 * P3-T2: rolls raw ads_analytics_visit / ads_analytics_funnel_event /
 * ads_analytics_order_attribution rows into ads_analytics_daily_summary.
 * Admin reports (P3-T4/P3-T5) read ONLY from the summary table — never from
 * raw tables at request time (CLAUDE.md constraint #6).
 */
class AggregateDailySummary
{
    public function execute(): void
    {
        // TODO(P3-T2): for the previous complete day (or since last run),
        // group by (date, traffic_type, platform_code, source, medium,
        // campaign) and upsert into ads_analytics_daily_summary: visits,
        // add_to_carts, checkout_starts, orders, revenue.
        //
        // IMPORTANT: ads_analytics_visit/funnel_event/order_attribution have
        // NULLABLE platform_code/source/medium/campaign, but
        // ads_analytics_daily_summary does NOT (see its db_schema.xml
        // comment) — substitute 'null_source' for any NULL value BEFORE
        // grouping (e.g. COALESCE(platform_code, 'null_source') in the
        // query, or in PHP after fetching).
        //
        // Skipping this does NOT silently duplicate rows — it fails loudly.
        // A column default only applies when the column is OMITTED from the
        // INSERT; passing an explicit NULL into a NOT NULL column raises
        // MySQL error 1048 ("Column 'source' cannot be null") and this cron
        // dies. If you are debugging a missing-summary-rows report, look for
        // 1048 in the cron log, not for duplicate slices.
        //
        // Use 'null_source', NOT 'none': TrafficResolver legitimately returns
        // medium='none' for direct traffic, meaning "verified: no medium".
        // Reusing 'none' as the coalesce sentinel would make that
        // indistinguishable from "the raw row was missing a medium".
    }
}
