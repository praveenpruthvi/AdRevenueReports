<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Aggregation;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Rolls raw visit / funnel / attribution rows into
 * ads_analytics_daily_summary (P3-T2).
 *
 * This is the only writer of that table, and everything the admin reports
 * read comes from here (CLAUDE.md #6 — reports never scan raw event tables at
 * request time).
 *
 * IDEMPOTENT BY DESIGN. It recomputes a whole date window and upserts with
 * `ON DUPLICATE KEY UPDATE col = VALUES(col)`, which REPLACES rather than
 * increments. Re-running it for the same day is therefore safe and is in fact
 * the intended behaviour: events arrive through a queue, so a visit recorded
 * at 23:59 may only be consumed after the nightly run. The default lookback
 * re-sweeps recent days so late arrivals are picked up.
 *
 * COALESCE IS MANDATORY. The raw tables allow NULL platform_code / source /
 * medium / campaign, but the summary's unique key spans those columns and is
 * NOT NULL. Passing an explicit NULL raises MySQL error 1048 and kills the
 * job — a column default does not apply to an explicitly-supplied NULL. Every
 * dimension below is wrapped in COALESCE to the 'null_source' sentinel, which
 * is deliberately distinct from the resolver's 'none' (a verified absence) so
 * a report can tell "no medium" from "medium missing".
 *
 * DATES ARE UTC. Magento stores timestamps in UTC and this buckets on the raw
 * DATE(). For a store whose timezone is far from UTC, "yesterday" in the
 * report is a UTC day, not a local one. Left as-is deliberately rather than
 * half-solved; converting properly means applying the store's timezone offset
 * consistently across all four source queries, and is worth doing before this
 * is used for financial reporting.
 */
class DailySummaryAggregator
{
    /** Sentinel for a NULL dimension — see the class docblock. */
    private const NULL_SENTINEL = 'null_source';

    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(ResourceConnection $resource, LoggerInterface $logger)
    {
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * @param string $from inclusive date, Y-m-d
     * @param string $to   inclusive date, Y-m-d
     * @return int number of summary rows written
     */
    public function aggregate(string $from, string $to): int
    {
        $connection = $this->resource->getConnection();
        $summaryTable = $this->resource->getTableName('ads_analytics_daily_summary');

        $sql = sprintf(
            'INSERT INTO %s
                (date, traffic_type, platform_code, source, medium, campaign,
                 visits, add_to_carts, checkout_starts, orders, revenue)
             SELECT date, traffic_type, platform_code, source, medium, campaign,
                    SUM(visits), SUM(add_to_carts), SUM(checkout_starts),
                    SUM(orders), SUM(revenue)
             FROM ( %s ) AS facts
             GROUP BY date, traffic_type, platform_code, source, medium, campaign
             ON DUPLICATE KEY UPDATE
                visits = VALUES(visits),
                add_to_carts = VALUES(add_to_carts),
                checkout_starts = VALUES(checkout_starts),
                orders = VALUES(orders),
                revenue = VALUES(revenue)',
            $summaryTable,
            implode(' UNION ALL ', [
                $this->visitFacts(),
                $this->funnelFacts(),
                $this->orderFacts(),
            ])
        );

        $bind = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        // Each of the three sub-selects binds the same window.
        $params = array_merge($bind, $bind, $bind);

        try {
            $statement = $connection->query(
                $sql,
                [
                    $params['from'], $params['to'],
                    $params['from'], $params['to'],
                    $params['from'], $params['to'],
                ]
            );

            return (int)$statement->rowCount();
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'Aavirbhava_AdsAnalytics: daily summary aggregation failed for %s..%s: %s',
                    $from,
                    $to,
                    $e->getMessage()
                )
            );
            throw $e;
        }
    }

    /**
     * One row per VISITOR, bucketed by the day the visit STARTED.
     *
     * first_seen_at, not last_seen_at: it never changes, so a returning
     * visitor cannot migrate between buckets and make a re-run produce
     * different numbers. Using last_seen_at would break idempotency.
     */
    private function visitFacts(): string
    {
        return sprintf(
            'SELECT DATE(v.first_seen_at) AS date,
                    v.traffic_type AS traffic_type,
                    COALESCE(v.platform_code, %1$s) AS platform_code,
                    COALESCE(v.last_touch_source, %1$s) AS source,
                    COALESCE(v.last_touch_medium, %1$s) AS medium,
                    COALESCE(v.last_touch_campaign, %1$s) AS campaign,
                    1 AS visits, 0 AS add_to_carts, 0 AS checkout_starts,
                    0 AS orders, 0 AS revenue
             FROM %2$s v
             WHERE v.first_seen_at BETWEEN ? AND ?',
            $this->quotedSentinel(),
            $this->resource->getTableName('ads_analytics_visit')
        );
    }

    /**
     * Cart and checkout-start events, attributed through the visit they
     * belong to so the whole funnel describes one acquisition.
     */
    private function funnelFacts(): string
    {
        return sprintf(
            'SELECT DATE(f.created_at) AS date,
                    v.traffic_type AS traffic_type,
                    COALESCE(v.platform_code, %1$s) AS platform_code,
                    COALESCE(v.last_touch_source, %1$s) AS source,
                    COALESCE(v.last_touch_medium, %1$s) AS medium,
                    COALESCE(v.last_touch_campaign, %1$s) AS campaign,
                    0 AS visits,
                    CASE WHEN f.event_type = "add_to_cart" THEN 1 ELSE 0 END AS add_to_carts,
                    CASE WHEN f.event_type = "checkout_start" THEN 1 ELSE 0 END AS checkout_starts,
                    0 AS orders, 0 AS revenue
             FROM %2$s f
             INNER JOIN %3$s v ON v.visit_id = f.visit_id
             WHERE f.event_type IN ("add_to_cart", "checkout_start")
               AND f.created_at BETWEEN ? AND ?',
            $this->quotedSentinel(),
            $this->resource->getTableName('ads_analytics_funnel_event'),
            $this->resource->getTableName('ads_analytics_visit')
        );
    }

    /**
     * Orders and revenue come from ads_analytics_order_attribution, NOT from
     * the funnel events, and are grouped by THAT table's own dimensions.
     *
     * That is the point of storing the attribution model on the row: when a
     * merchant runs first-touch attribution, the order belongs to the campaign
     * that acquired the visitor, which may differ from the last touch the
     * visit carries. Reading the dimensions from the attribution row honours
     * the configured model; reading them from the visit would silently ignore
     * it. A consequence worth expecting: under first-touch, a slice can show
     * orders with zero visits, because the visit counted under a different
     * campaign.
     *
     * Revenue is base_grand_total — base currency, so a multi-currency store
     * sums comparable numbers.
     */
    private function orderFacts(): string
    {
        return sprintf(
            'SELECT DATE(o.created_at) AS date,
                    a.traffic_type AS traffic_type,
                    COALESCE(a.platform_code, %1$s) AS platform_code,
                    COALESCE(a.source, %1$s) AS source,
                    COALESCE(a.medium, %1$s) AS medium,
                    COALESCE(a.campaign, %1$s) AS campaign,
                    0 AS visits, 0 AS add_to_carts, 0 AS checkout_starts,
                    1 AS orders,
                    COALESCE(o.base_grand_total, 0) AS revenue
             FROM %2$s a
             INNER JOIN %3$s o ON o.entity_id = a.order_id
             WHERE o.created_at BETWEEN ? AND ?',
            $this->quotedSentinel(),
            $this->resource->getTableName('ads_analytics_order_attribution'),
            $this->resource->getTableName('sales_order')
        );
    }

    private function quotedSentinel(): string
    {
        return $this->resource->getConnection()->quote(self::NULL_SENTINEL);
    }
}
