<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Roas;

use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Return on ad spend, per platform (P4-T5).
 *
 * ROAS = revenue / spend. Revenue comes from ads_analytics_daily_summary
 * (paid traffic only — organic, direct and referral have no spend concept,
 * docs/SPECS.md section 5); spend comes from whichever AdSpendProviderInterface
 * is registered for the platform.
 *
 * GRAIN IS ONE ROW PER PLATFORM, by the user's explicit instruction. That is
 * also the safe grain: spend is recorded per (date, platform, campaign) while
 * a summary row is finer still — Meta splits into facebook and instagram rows
 * that share one date, platform and campaign — so any grain finer than the
 * platform would need care not to attach the same spend to two rows. Rolling
 * everything up to the platform sidesteps that, and it also means campaign
 * spelling differences between a spend file and the summary cannot cause a
 * missed join.
 *
 * THREE STATES, kept apart deliberately, because collapsing them misleads:
 *   - spend known and positive -> a real ROAS (which may be exactly 0.00:
 *                                 money went out and none came back, a real
 *                                 and important result)
 *   - spend known and zero     -> no ROAS. Division by zero is not "infinite
 *                                 return", it is "nothing was spent"
 *   - spend not known          -> no ROAS. NOT zero: 0.00 would say the ads
 *                                 earned nothing, when the truth is that
 *                                 nobody has told us what they cost
 *
 * Rows are built from the UNION of the platforms the summary saw and the
 * platforms that have a registered provider. Starting from the summary alone
 * would omit a platform that spent money but drew no traffic — precisely the
 * case a merchant most needs to see.
 *
 * Currency: revenue is base_grand_total (base currency); spend is whatever the
 * provider reports. They are only comparable on a single-currency store, and
 * nothing here converts.
 */
class RoasReportBuilder
{
    public const STATUS_OK = 'ok';
    public const STATUS_NO_SPEND_DATA = 'no_spend_data';
    public const STATUS_ZERO_SPEND = 'zero_spend';

    /**
     * The summary's sentinel for "no platform recognised" (etc/db_schema.xml).
     * A paid visit can carry a paid utm_medium without a known click-id, and
     * those rows land here.
     */
    private const UNRECOGNISED_PLATFORM = 'null_source';

    private ResourceConnection $resource;
    private AdSpendProviderPool $pool;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        AdSpendProviderPool $pool,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->pool = $pool;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * @param string|null $from Y-m-d; defaults to the earliest date with data
     * @param string|null $to   Y-m-d, inclusive; defaults to today (UTC)
     * @return array{
     *     from: string, to: string,
     *     rows: array<int, array{platform_code: string, label: string, spend: ?float, revenue: float, roas: ?float, status: string}>,
     *     totals: array{spend: float, revenue: float, roas: ?float, rows_without_spend: int}
     * }
     */
    public function build(?string $from = null, ?string $to = null): array
    {
        $to = $to ?? $this->dateTime->gmtDate('Y-m-d');
        $from = $from ?? $this->earliestDate() ?? $to;

        $revenue = $this->revenueByPlatform($from, $to);
        $spend = $this->spendByPlatform($from, $to);

        $rows = [];
        foreach (array_unique(array_merge(array_keys($revenue), array_keys($spend))) as $platform) {
            $rowSpend = $spend[$platform] ?? null;
            $rowRevenue = $revenue[$platform] ?? 0.0;

            [$roas, $status] = $this->classify($rowSpend, $rowRevenue);

            $rows[] = [
                'platform_code' => (string)$platform,
                'label' => $platform === self::UNRECOGNISED_PLATFORM ? '(no platform recognised)' : (string)$platform,
                'spend' => $rowSpend,
                'revenue' => $rowRevenue,
                'roas' => $roas,
                'status' => $status,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            // Platforms with spend data first, biggest spender first; those
            // without after, by revenue, so an unmeasured platform that is
            // earning a lot still surfaces near the top of its group.
            $aKnown = $a['spend'] !== null;
            $bKnown = $b['spend'] !== null;
            if ($aKnown !== $bKnown) {
                return $aKnown ? -1 : 1;
            }

            return $aKnown
                ? ($b['spend'] <=> $a['spend'])
                : ($b['revenue'] <=> $a['revenue']);
        });

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Blended ROAS across the platforms that HAVE spend data.
     *
     * Revenue from a platform whose cost is unknown is left out of the
     * numerator. Including it would divide money attributable to ads by only
     * the portion of their cost we happen to know, which inflates the headline
     * number exactly when the data is least complete.
     *
     * @param array<int, array{spend: ?float, revenue: float}> $rows
     * @return array{spend: float, revenue: float, roas: ?float, rows_without_spend: int}
     */
    private function totals(array $rows): array
    {
        $spend = 0.0;
        $revenue = 0.0;
        $without = 0;

        foreach ($rows as $row) {
            if ($row['spend'] === null) {
                $without++;
                continue;
            }
            $spend += $row['spend'];
            $revenue += $row['revenue'];
        }

        return [
            'spend' => $spend,
            'revenue' => $revenue,
            'roas' => $spend > 0 ? $revenue / $spend : null,
            'rows_without_spend' => $without,
        ];
    }

    /**
     * @return array{0: ?float, 1: string} [roas, status]
     */
    private function classify(?float $spend, float $revenue): array
    {
        if ($spend === null) {
            return [null, self::STATUS_NO_SPEND_DATA];
        }
        if ($spend <= 0) {
            return [null, self::STATUS_ZERO_SPEND];
        }

        return [$revenue / $spend, self::STATUS_OK];
    }

    /**
     * @return array<string, float> keyed by normalised platform code
     */
    private function revenueByPlatform(string $from, string $to): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('ads_analytics_daily_summary');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['platform_code' => 'platform_code', 'revenue' => 'SUM(revenue)'])
                ->where('traffic_type = ?', 'paid')
                ->where('date >= ?', $from)
                ->where('date <= ?', $to)
                ->group('platform_code')
        );

        $result = [];
        foreach ($rows as $row) {
            $result[self::normalise((string)$row['platform_code'])] = (float)$row['revenue'];
        }

        return $result;
    }

    /**
     * Asks every registered provider, not only those whose platform sent
     * traffic, and sums every one of its rows.
     *
     * A platform is only present in the result if its provider returned at
     * least one row for the period. An empty answer (no file, or no rows in
     * range) means "no spend data", NOT "zero spend" — the two are different
     * states and the report must not turn one into the other.
     *
     * A provider that throws is treated as "no data" for that platform and
     * logged, never propagated: a real API-backed provider will fail on an
     * expired token or a rate limit, and one platform's outage must not blank
     * the report for the others.
     *
     * @return array<string, float> keyed by normalised platform code
     */
    private function spendByPlatform(string $from, string $to): array
    {
        $fromDate = new \DateTimeImmutable($from);
        $toDate = new \DateTimeImmutable($to);
        $result = [];

        foreach ($this->pool->getPlatformCodes() as $platformCode) {
            try {
                $entries = $this->pool->getProvider($platformCode)->getSpend($platformCode, $fromDate, $toDate);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Aavirbhava_AdsAnalytics: ad-spend provider for "%s" failed, treating as no data: %s',
                        $platformCode,
                        $e->getMessage()
                    )
                );
                continue;
            }

            if ($entries === []) {
                continue;
            }

            $total = 0.0;
            foreach ($entries as $entry) {
                $total += (float)$entry['spend'];
            }
            $result[self::normalise($platformCode)] = $total;
        }

        return $result;
    }

    private static function normalise(string $platformCode): string
    {
        return strtolower(trim($platformCode));
    }

    private function earliestDate(): ?string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('ads_analytics_daily_summary');

        $value = $connection->fetchOne($connection->select()->from($table, ['MIN(date)']));

        return $value !== false && $value !== null ? (string)$value : null;
    }
}
