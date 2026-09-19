<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Controller\Adminhtml\Report;

use Aavirbhava\AdsAnalytics\Model\Pdf\DashboardPdfGenerator;
use Aavirbhava\AdsAnalytics\Model\Pdf\DashboardReportBuilder;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * P4-T2: streams a one-page PDF summary of ads_analytics_daily_summary —
 * funnel totals, traffic-type breakdown, top paid campaigns — for an
 * optional ?from=&to= range (Y-m-d, inclusive both ends). Reachable from a
 * "Download PDF Report" button on the dashboard (P3-T3's report page).
 *
 * Deliberately a standalone report rather than "print the currently filtered
 * grid": the grid's own filters are already covered by the native Excel/CSV
 * export P4-T1 added, and duplicating that as a second PDF-shaped export
 * would just be the same rows in a harder-to-read format. This answers a
 * different question — "how did the whole store's paid/organic/direct/
 * referral mix perform over a period" — which a row-per-slice grid dump
 * does not summarize on its own.
 */
class Pdf extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AdsAnalytics::dashboard';

    private DashboardReportBuilder $reportBuilder;
    private DashboardPdfGenerator $pdfGenerator;
    private FileFactory $fileFactory;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        DashboardReportBuilder $reportBuilder,
        DashboardPdfGenerator $pdfGenerator,
        FileFactory $fileFactory,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->reportBuilder = $reportBuilder;
        $this->pdfGenerator = $pdfGenerator;
        $this->fileFactory = $fileFactory;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    public function execute(): ResultInterface
    {
        [$from, $to, $error] = $this->resolveRange();

        if ($error !== null) {
            $this->messageManager->addErrorMessage($error);

            return $this->redirectToReport();
        }

        try {
            $report = $this->reportBuilder->build($from, $to);
            $pdf = $this->pdfGenerator->generate($report);
        } catch (\Throwable $e) {
            $this->logger->error('Aavirbhava_AdsAnalytics: PDF report generation failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('Could not generate the PDF report. See the log for details.'));

            return $this->redirectToReport();
        }

        return $this->fileFactory->create(
            sprintf('ads-analytics-report_%s_%s.pdf', $from, $to),
            ['type' => 'string', 'value' => $pdf->render(), 'rm' => true],
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
    }

    /**
     * Resolves and validates the ?from=&to= query params.
     *
     * Both are optional: an absent `to` defaults to today, an absent `from`
     * defaults to the earliest date with any data (so a first-time visitor
     * to the report gets their whole history rather than an arbitrary
     * window that might exclude everything). Both, present or defaulted,
     * are validated as real Y-m-d dates with `from <= to` BEFORE either
     * reaches SQL — an admin-facing URL parameter is still an untrusted
     * input, and a malformed one must produce a clear redirect-with-message
     * rather than a query built from a bad date string.
     *
     * @return array{0: string, 1: string, 2: string|\Magento\Framework\Phrase|null} [from, to, error]
     */
    private function resolveRange(): array
    {
        $toParam = $this->getRequest()->getParam('to');
        $to = $toParam ? (string)$toParam : $this->dateTime->gmtDate('Y-m-d');

        if (!$this->isValidDate($to)) {
            return ['', '', __('Invalid "to" date. Use YYYY-MM-DD.')];
        }

        $fromParam = $this->getRequest()->getParam('from');
        if ($fromParam) {
            $from = (string)$fromParam;
            if (!$this->isValidDate($from)) {
                return ['', '', __('Invalid "from" date. Use YYYY-MM-DD.')];
            }
        } else {
            $from = $this->reportBuilder->earliestDate() ?? $to;
        }

        if ($from > $to) {
            return ['', '', __('"from" must not be after "to".')];
        }

        return [$from, $to, null];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        // The round-trip check (not just a non-false check) matters here for
        // the same reason it does in CsvAdSpendProvider::parseRow():
        // createFromFormat is lenient about calendar overflow, so
        // "2026-02-30" parses "successfully" into March 2nd unless the
        // formatted result is compared back against the original string.
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function redirectToReport(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('ads_analytics/report/index');
    }
}
