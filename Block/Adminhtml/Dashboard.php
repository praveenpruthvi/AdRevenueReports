<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml;

use Magento\Backend\Block\Template;

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
}
