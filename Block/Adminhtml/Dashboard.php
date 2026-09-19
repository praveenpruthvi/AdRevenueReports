<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml;

use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\AdSpendCsvFile;
use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Aavirbhava\AdsAnalytics\Model\Roas\RoasReportBuilder;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;

/**
 * Dashboard charts above the Ads Analytics report grid (P3-T4).
 *
 * Holds no data of its own. The charts are drawn from the SAME Ui Component
 * data provider the grid below them reads, so whatever the admin has filtered
 * the grid to — date range, traffic type, campaign — is exactly what the
 * charts show. A second server fetch here could disagree with the table on
 * screen, which is worse than having no chart.
 *
 * Consequence, disclosed in the template: the charts describe the CURRENT
 * PAGE of grid results, not every row matching the filters. Same limitation
 * as Aavirbhava_SalesAnalytics' dashboard, and the same deliberate v1
 * trade-off rather than an oversight.
 */
class Dashboard extends Template
{
    private RoasReportBuilder $roasReportBuilder;
    private AdSpendProviderPool $adSpendProviderPool;
    private AdSpendCsvFile $adSpendCsvFile;
    private AuthorizationInterface $authorization;

    /** @var array|null memoised: the template reads it more than once */
    private ?array $roasReport = null;

    public function __construct(
        Context $context,
        RoasReportBuilder $roasReportBuilder,
        AdSpendProviderPool $adSpendProviderPool,
        AdSpendCsvFile $adSpendCsvFile,
        AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->roasReportBuilder = $roasReportBuilder;
        $this->adSpendProviderPool = $adSpendProviderPool;
        $this->adSpendCsvFile = $adSpendCsvFile;
        $this->authorization = $authorization;
    }

    /**
     * Name of the Ui Component provider the charts bind to. Must match the
     * dataSource name in
     * view/adminhtml/ui_component/aavirbhava_adsanalytics_summary_listing.xml.
     */
    public function getListingProviderName(): string
    {
        return 'aavirbhava_adsanalytics_summary_listing.aavirbhava_adsanalytics_summary_listing_data_source';
    }

    /**
     * URL for the P4-T2 PDF report. No date params: the controller defaults
     * an absent range to the whole dataset, which is what a plain link
     * without JS to read the grid's current filter state can offer without
     * risking disagreeing with what the grid shows (see this class's
     * docblock on why the charts avoid a second, possibly-inconsistent
     * fetch).
     */
    public function getPdfReportUrl(): string
    {
        return $this->getUrl('ads_analytics/report/pdf');
    }

    /**
     * P4-T5. Return on ad spend, one row per platform, over the whole dataset.
     *
     * Deliberately NOT tied to the grid's filters, unlike the charts. ROAS
     * needs spend, which comes from a provider rather than from the summary
     * table, so it cannot be read off the grid's data provider. See
     * Model\Roas\RoasReportBuilder.
     *
     * @return array the shape RoasReportBuilder::build() returns
     */
    public function getRoasReport(): array
    {
        if ($this->roasReport === null) {
            $this->roasReport = $this->roasReportBuilder->build();
        }

        return $this->roasReport;
    }

    /**
     * Whether the current admin is allowed to upload an ad-spend CSV — the
     * same resource Controller\Adminhtml\AdSpend\Upload is gated on, checked
     * here so the template can hide the form entirely for an admin who would
     * only get "not authorized" trying to submit it, rather than show it and
     * let them find out by failing.
     */
    public function canManageAdSpendData(): bool
    {
        return $this->authorization->isAllowed('Aavirbhava_AdsAnalytics::config');
    }

    public function getAdSpendUploadUrl(): string
    {
        return $this->getUrl('ads_analytics/adspend/upload');
    }

    /**
     * One row per platform with a registered ad-spend provider — the same
     * set Model\Roas\RoasReportBuilder reads spend from — each with whether
     * a file currently exists for it, so the upload section can show
     * "already have data, uploading replaces it" versus "no data yet"
     * instead of always looking like a blank first-time form.
     *
     * @return array<int, array{code: string, label: string, exists: bool, modifiedAt: ?string, validRows: int, skippedRows: int}>
     */
    public function getAdSpendPlatforms(): array
    {
        $platforms = [];
        foreach ($this->adSpendProviderPool->getPlatformCodes() as $code) {
            $status = $this->adSpendCsvFile->status($code);
            $platforms[] = [
                'code' => $code,
                'label' => ucfirst($code),
                'exists' => $status['exists'],
                'modifiedAt' => $status['modifiedAt'],
                'validRows' => $status['validRows'],
                'skippedRows' => $status['skippedRows'],
            ];
        }

        return $platforms;
    }
}
