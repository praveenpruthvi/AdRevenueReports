<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Deletes raw per-visitor data past its retention window (P3-T7,
 * docs/SECURITY.md §8). Driven by Cron\PurgeOldData and by
 * `bin/magento aavirbhava:adsanalytics:purge`.
 *
 * WHAT IS AND IS NOT PURGED
 * -------------------------
 * Purged: ads_analytics_visit, ads_analytics_funnel_event and (by cascade)
 * ads_analytics_order_attribution, plus ads_analytics_request_log on its own
 * shorter window.
 *
 * NEVER purged: ads_analytics_daily_summary. It holds no per-visitor detail —
 * only counts per (date, traffic_type, platform, source, medium, campaign) —
 * so there is nothing in it to retain a person's data, and it is the only
 * place long-range reporting history survives once the raw rows are gone.
 *
 * CONSEQUENCE WORTH KNOWING: because the summary outlives its source rows,
 * re-aggregating a date older than the retention window would recompute it
 * from a now-empty raw table and overwrite real figures with zeros — the
 * aggregator's upsert replaces rather than increments. Cron\AggregateDailySummary
 * only ever sweeps a short recent lookback so it cannot reach that far back,
 * but the manual `aggregate --from/--to` backfill can, which is why that
 * command refuses pre-retention dates.
 *
 * DELETES ARE BATCHED. A single unbounded DELETE against a visit table with
 * millions of rows holds row locks for the whole statement, cascades into two
 * child tables inside the same transaction, and can exceed the binlog cache
 * or lock-wait timeout — wedging cron and blocking live ingest writes behind
 * it. Each batch is its own autocommitted statement, so a failure part-way
 * through leaves the earlier batches purged rather than rolling everything
 * back, and the next run resumes from where it stopped.
 */
class DataPurger
{
    /**
     * Rows per DELETE. Large enough that purging a real backlog does not take
     * all night, small enough that each statement's locks are held briefly.
     */
    private const BATCH_SIZE = 5000;

    /**
     * Hard stop on batches per table per run, so a mis-set window or a clock
     * problem cannot turn one cron tick into an unbounded delete loop. At the
     * batch size above this caps a single run at 5 million rows per table;
     * whatever is left is picked up by the next run.
     */
    private const MAX_BATCHES = 1000;

    private ResourceConnection $resource;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * Purges the raw analytics tables.
     *
     * Visits are selected on last_seen_at, not first_seen_at. A visit row is
     * one row per VISITOR that is updated on every landing, so first_seen_at
     * is the date the person was first seen — deleting on it would destroy
     * the record of someone who is still actively shopping, taking their
     * in-flight funnel and order attribution with it via the cascade.
     * last_seen_at means "no activity for N days", which is both the safe
     * reading and the defensible one: with a 90-day cookie lifetime, a
     * visitor inactive that long is issued a new uuid anyway, so their old
     * row is genuinely dead data by the time this deletes it.
     *
     * Funnel events are ALSO purged on their own created_at rather than being
     * left to the cascade. Without that, a continuously active visitor would
     * keep every event they ever raised, indefinitely, because their visit
     * row never ages out.
     *
     * @return array{visits: int, funnel_events: int} rows deleted
     */
    public function purgeEvents(int $retentionDays, bool $dryRun = false): array
    {
        if ($retentionDays <= 0) {
            $this->logger->info(
                'Aavirbhava_AdsAnalytics: raw event purge is disabled (retention window is 0); nothing deleted.'
            );

            return ['visits' => 0, 'funnel_events' => 0];
        }

        $cutoff = $this->cutoff($retentionDays);

        // Funnel events first. Doing it in this order means the events
        // belonging to visits that are about to be deleted are already gone,
        // so the visit delete's cascade has less work to do inside its own
        // lock; reversing the order would make the cascade the expensive part
        // and it is the part that cannot be batched.
        $funnelEvents = $this->deleteInBatches(
            $this->resource->getTableName('ads_analytics_funnel_event'),
            'created_at',
            $cutoff,
            $dryRun
        );

        $visits = $this->deleteInBatches(
            $this->resource->getTableName('ads_analytics_visit'),
            'last_seen_at',
            $cutoff,
            $dryRun
        );

        return ['visits' => $visits, 'funnel_events' => $funnelEvents];
    }

    /**
     * Purges ads_analytics_request_log on its own, shorter window.
     */
    public function purgeRequestLog(int $retentionDays, bool $dryRun = false): int
    {
        if ($retentionDays <= 0) {
            $this->logger->info(
                'Aavirbhava_AdsAnalytics: request-log purge is disabled (retention window is 0); nothing deleted.'
            );

            return 0;
        }

        return $this->deleteInBatches(
            $this->resource->getTableName('ads_analytics_request_log'),
            'received_at',
            $this->cutoff($retentionDays),
            $dryRun
        );
    }

    /**
     * UTC, matching the tables' own CURRENT_TIMESTAMP defaults. Magento
     * stores these columns in UTC regardless of the store's display
     * timezone, so converting to store time here would shift the cutoff by
     * the UTC offset and delete up to a day too much or too little.
     */
    public function cutoff(int $retentionDays): string
    {
        return $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));
    }

    /**
     * Counts what a purge would remove, without removing it.
     *
     * @return array{visits: int, funnel_events: int, request_log: int}
     */
    public function preview(int $eventRetentionDays, int $requestLogRetentionDays): array
    {
        $events = $this->purgeEvents($eventRetentionDays, true);
        $events['request_log'] = $this->purgeRequestLog($requestLogRetentionDays, true);

        return $events;
    }

    /**
     * `DELETE ... LIMIT` in a loop, stopping as soon as a statement affects
     * fewer rows than the batch size (which means the last batch was
     * reached). $column is never caller-supplied — it comes from the two
     * methods above — and the cutoff is bound, not interpolated.
     */
    private function deleteInBatches(string $table, string $column, string $cutoff, bool $dryRun): int
    {
        $connection = $this->resource->getConnection();

        if ($dryRun) {
            return (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, 'COUNT(*)')
                    ->where($connection->quoteIdentifier($column) . ' < ?', $cutoff)
            );
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s < ? LIMIT %d',
            $connection->quoteIdentifier($table),
            $connection->quoteIdentifier($column),
            self::BATCH_SIZE
        );

        $deleted = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $affected = $connection->query($sql, [$cutoff])->rowCount();
            $deleted += $affected;

            if ($affected < self::BATCH_SIZE) {
                return $deleted;
            }
        }

        $this->logger->warning(
            sprintf(
                'Aavirbhava_AdsAnalytics: purge of %s hit the %d-batch cap with rows still older than %s; '
                . 'the remainder will be removed on the next run.',
                $table,
                self::MAX_BATCHES,
                $cutoff
            )
        );

        return $deleted;
    }
}
