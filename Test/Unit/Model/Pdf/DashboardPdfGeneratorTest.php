<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Pdf;

use Aavirbhava\AdsAnalytics\Model\Pdf\DashboardPdfGenerator;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P4-T2. Renders a real \Zend_Pdf document (the class this session already
 * ships via magento/zend-pdf, same as Magento_Sales) and asserts on the
 * OUTPUT rather than mocking Zend_Pdf's drawing calls — there is no
 * interface to mock against, and a mock of a concrete drawing API would only
 * prove the generator calls methods that exist, not that the numbers it is
 * given end up on the page.
 */
class DashboardPdfGeneratorTest extends TestCase
{
    /** @var DateTime&MockObject */
    private $dateTime;

    protected function setUp(): void
    {
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');
    }

    private function generator(): DashboardPdfGenerator
    {
        return new DashboardPdfGenerator($this->dateTime);
    }

    private function emptyReport(string $from = '2026-01-01', string $to = '2026-01-31'): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'funnel' => [
                'visits' => 0, 'product_views' => 0, 'add_to_carts' => 0,
                'checkout_starts' => 0, 'orders' => 0, 'revenue' => 0.0,
            ],
            'by_traffic_type' => [],
            'top_campaigns' => [],
        ];
    }

    public function testProducesAWellFormedSinglePagePdf(): void
    {
        $pdf = $this->generator()->generate($this->emptyReport());
        $binary = $pdf->render();

        $this->assertSame('%PDF', substr($binary, 0, 4), 'a Zend_Pdf render must start with the PDF signature');
        $this->assertCount(1, $pdf->pages);
    }

    /**
     * The zero-visits case is the one this class cannot get from
     * DashboardReportBuilder's own null-normalisation test: division by
     * visits appears three times (view/cart/conversion rate), and a naive
     * implementation would throw DivisionByZeroError on an empty date range
     * rather than rendering "no data" gracefully.
     */
    public function testDoesNotThrowWhenVisitsIsZero(): void
    {
        $report = $this->emptyReport();
        $report['funnel'] = [
            'visits' => 0, 'product_views' => 5, 'add_to_carts' => 2,
            'checkout_starts' => 1, 'orders' => 0, 'revenue' => 0.0,
        ];

        $pdf = $this->generator()->generate($report);

        $this->assertSame('%PDF', substr($pdf->render(), 0, 4));
    }

    public function testRendersRealNumbersFromTheFunnel(): void
    {
        $report = $this->emptyReport();
        $report['funnel'] = [
            'visits' => 506, 'product_views' => 495, 'add_to_carts' => 37,
            'checkout_starts' => 57, 'orders' => 6, 'revenue' => 468.0,
        ];

        $text = $this->extractText($this->generator()->generate($report));

        $this->assertStringContainsString('506', $text);
        $this->assertStringContainsString('468.00', $text);
    }

    public function testRendersTrafficTypeAndCampaignRows(): void
    {
        $report = $this->emptyReport();
        $report['by_traffic_type'] = [
            ['traffic_type' => 'paid', 'visits' => 185, 'orders' => 3, 'revenue' => 312.0],
        ];
        $report['top_campaigns'] = [
            ['platform_code' => 'google', 'campaign' => 'retargeting', 'visits' => 10, 'orders' => 1, 'revenue' => 117.0],
        ];

        $text = $this->extractText($this->generator()->generate($report));

        $this->assertStringContainsString('312.00', $text);
        $this->assertStringContainsString('retargeting', $text);
    }

    public function testAnEmptySectionShowsANoDataNoticeRatherThanNothing(): void
    {
        $text = $this->extractText($this->generator()->generate($this->emptyReport()));

        // Both sections are empty in emptyReport(); the notice must appear
        // at least once so a merchant looking at a quiet period sees an
        // explicit "no data" rather than an unexplained blank page.
        $this->assertStringContainsString('No data for this period', $text);
    }

    /**
     * Extracts drawn text from the rendered document bytes rather than
     * shelling out to pdftotext, so this test has no external-binary
     * dependency. Zend_Pdf does not compress content streams by default
     * (verified against a real render — no FlateDecode filter present), so
     * the literal string operands Zend_Pdf places in parentheses before a Tj
     * show-text operator are readable directly in $pdf->render()'s output.
     * Good enough to assert a value is present, not to assert layout.
     */
    private function extractText(\Zend_Pdf $pdf): string
    {
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $pdf->render(), $matches);

        return implode(' ', $matches[1] ?? []);
    }
}
