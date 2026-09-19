<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Pdf;

use Magento\Framework\App\ResourceConnection;

/**
 * Shapes the PDF report's data (P4-T2). Reads ONLY from
 * ads_analytics_daily_summary — CLAUDE.md #6 ("aggregation, not live
 * joins"). Kept separate from Model\Pdf\DashboardPdfGenerator so the SQL and
 * the drawing code can each be tested and read on their own.
 */
class DashboardReportBuilder
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @return array{
     *     from: string, to: string,
     *     funnel: array{visits: int, product_views: int, add_to_carts: int, checkout_starts: int, orders: int, revenue: float},
     *     by_traffic_type: array<int, array{traffic_type: string, visits: int, orders: int, revenue: float}>,
     *     top_campaigns: array<int, array{platform_code: string, campaign: string, visits: int, orders: int, revenue: float}>
     * }
     */
    public function build(string $from, string $to, int $topCampaignLimit = 10): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('ads_analytics_daily_summary');

        $funnel = $connection->fetchRow(
            $connection->select()
                ->from($table, [
                    'visits' => 'SUM(visits)',
                    'product_views' => 'SUM(product_views)',
                    'add_to_carts' => 'SUM(add_to_carts)',
                    'checkout_starts' => 'SUM(checkout_starts)',
                    'orders' => 'SUM(orders)',
                    'revenue' => 'SUM(revenue)',
                ])
                ->where('date >= ?', $from)
                ->where('date <= ?', $to)
        ) ?: [];

        // fetchRow on zero matching rows returns SUM()s as NULL, not 0 —
        // normalise so the generator can do arithmetic without a null check
        // on every field.
        $funnel = array_map(static fn ($v) => $v !== null ? (float)$v : 0.0, $funnel);
        $funnel = [
            'visits' => (int)($funnel['visits'] ?? 0),
            'product_views' => (int)($funnel['product_views'] ?? 0),
            'add_to_carts' => (int)($funnel['add_to_carts'] ?? 0),
            'checkout_starts' => (int)($funnel['checkout_starts'] ?? 0),
            'orders' => (int)($funnel['orders'] ?? 0),
            'revenue' => (float)($funnel['revenue'] ?? 0.0),
        ];

        $byTrafficType = $connection->fetchAll(
            $connection->select()
                ->from($table, [
                    'traffic_type' => 'traffic_type',
                    'visits' => 'SUM(visits)',
                    'orders' => 'SUM(orders)',
                    'revenue' => 'SUM(revenue)',
                ])
                ->where('date >= ?', $from)
                ->where('date <= ?', $to)
                ->group('traffic_type')
                ->order('SUM(revenue) DESC')
        );
        foreach ($byTrafficType as &$row) {
            $row['visits'] = (int)$row['visits'];
            $row['orders'] = (int)$row['orders'];
            $row['revenue'] = (float)$row['revenue'];
        }
        unset($row);

        $topCampaigns = $connection->fetchAll(
            $connection->select()
                ->from($table, [
                    'platform_code' => 'platform_code',
                    'campaign' => 'campaign',
                    'visits' => 'SUM(visits)',
                    'orders' => 'SUM(orders)',
                    'revenue' => 'SUM(revenue)',
                ])
                ->where('date >= ?', $from)
                ->where('date <= ?', $to)
                ->where('traffic_type = ?', 'paid')
                ->group(['platform_code', 'campaign'])
                ->having('SUM(revenue) > 0')
                ->order('SUM(revenue) DESC')
                ->limit($topCampaignLimit)
        );
        foreach ($topCampaigns as &$row) {
            $row['visits'] = (int)$row['visits'];
            $row['orders'] = (int)$row['orders'];
            $row['revenue'] = (float)$row['revenue'];
        }
        unset($row);

        return [
            'from' => $from,
            'to' => $to,
            'funnel' => $funnel,
            'by_traffic_type' => $byTrafficType,
            'top_campaigns' => $topCampaigns,
        ];
    }

    /**
     * Earliest date with any data, or null if the summary table is empty —
     * used by the controller to default an omitted `from` to "the whole
     * dataset" rather than an arbitrary fixed lookback.
     */
    public function earliestDate(): ?string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('ads_analytics_daily_summary');

        $value = $connection->fetchOne($connection->select()->from($table, ['MIN(date)']));

        return $value !== false && $value !== null ? (string)$value : null;
    }
}
