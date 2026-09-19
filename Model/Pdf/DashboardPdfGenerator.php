<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Pdf;

use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Draws the report shape DashboardReportBuilder produces into a PDF (P4-T2).
 *
 * Uses \Zend_Pdf directly, the same library Magento_Sales uses for order/
 * invoice PDFs (see Magento\Sales\Model\Order\Pdf\AbstractPdf) — it ships
 * with core (vendor/magento/zend-pdf) as a first-class Magento dependency,
 * so this needs no new composer package. Standard PDF fonts (Helvetica) are
 * used instead of the TTF files Sales bundles, since nothing here needs
 * non-Latin glyphs and a built-in font needs no filesystem path at all.
 */
class DashboardPdfGenerator
{
    private const PAGE_MARGIN_X = 35;
    private const PAGE_TOP = 800;
    private const LINE_HEIGHT = 16;
    private const SECTION_GAP = 24;

    private DateTime $dateTime;

    public function __construct(DateTime $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    /**
     * @param array $report the shape DashboardReportBuilder::build() returns
     */
    public function generate(array $report): \Zend_Pdf
    {
        $pdf = new \Zend_Pdf();
        $page = new \Zend_Pdf_Page(\Zend_Pdf_Page::SIZE_A4);
        $pdf->pages[] = $page;

        $y = self::PAGE_TOP;

        $y = $this->drawHeader($page, $report, $y);
        $y = $this->drawFunnelSection($page, $report['funnel'], $y);
        $y = $this->drawTrafficTypeTable($page, $report['by_traffic_type'], $y);
        $this->drawTopCampaignsTable($page, $report['top_campaigns'], $y);

        return $pdf;
    }

    private function drawHeader(\Zend_Pdf_Page $page, array $report, float $y): float
    {
        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD), 18);
        $page->drawText('Ads Analytics — Dashboard Report', self::PAGE_MARGIN_X, $y, 'UTF-8');
        $y -= 24;

        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA), 10);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.35));
        $page->drawText(
            sprintf('Period: %s to %s', $report['from'], $report['to']),
            self::PAGE_MARGIN_X,
            $y,
            'UTF-8'
        );
        $y -= self::LINE_HEIGHT;
        $page->drawText(
            'Generated: ' . $this->dateTime->gmtDate('Y-m-d H:i:s') . ' UTC',
            self::PAGE_MARGIN_X,
            $y,
            'UTF-8'
        );
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));

        return $y - self::SECTION_GAP;
    }

    private function drawFunnelSection(\Zend_Pdf_Page $page, array $funnel, float $y): float
    {
        $y = $this->drawSectionTitle($page, 'Funnel', $y);

        $visits = $funnel['visits'];
        // NULLIF-equivalent: a rate is meaningless (not zero) when its
        // denominator is zero, matching how the admin grid's SQL-derived
        // rate columns already treat this (NULLIF(visits, 0) — see
        // Model\ResourceModel\DailySummary\Grid\Collection), so the PDF
        // cannot disagree with what the grid shows for the same range.
        $viewRate = $visits > 0 ? $funnel['product_views'] / $visits * 100 : null;
        $cartRate = $visits > 0 ? $funnel['add_to_carts'] / $visits * 100 : null;
        $conversionRate = $visits > 0 ? $funnel['orders'] / $visits * 100 : null;

        $rows = [
            ['Visits', number_format($funnel['visits'])],
            ['Product Views', number_format($funnel['product_views']) . $this->formatRate($viewRate)],
            ['Add to Carts', number_format($funnel['add_to_carts']) . $this->formatRate($cartRate)],
            ['Checkout Starts', number_format($funnel['checkout_starts'])],
            ['Orders', number_format($funnel['orders']) . $this->formatRate($conversionRate)],
            ['Revenue', $this->formatCurrency($funnel['revenue'])],
        ];

        return $this->drawKeyValueRows($page, $rows, $y);
    }

    private function drawTrafficTypeTable(\Zend_Pdf_Page $page, array $rows, float $y): float
    {
        $y = $this->drawSectionTitle($page, 'By Traffic Type', $y);

        if (empty($rows)) {
            return $this->drawEmptyNotice($page, $y);
        }

        $columns = [
            ['label' => 'Traffic Type', 'width' => 140, 'align' => 'left'],
            ['label' => 'Visits', 'width' => 90, 'align' => 'right'],
            ['label' => 'Orders', 'width' => 90, 'align' => 'right'],
            ['label' => 'Revenue', 'width' => 120, 'align' => 'right'],
        ];
        $y = $this->drawTableHeader($page, $columns, $y);

        foreach ($rows as $row) {
            $y = $this->drawTableRow($page, $columns, [
                ucfirst($row['traffic_type']),
                number_format($row['visits']),
                number_format($row['orders']),
                $this->formatCurrency($row['revenue']),
            ], $y);
        }

        return $y - self::SECTION_GAP;
    }

    private function drawTopCampaignsTable(\Zend_Pdf_Page $page, array $rows, float $y): float
    {
        $y = $this->drawSectionTitle($page, 'Top Paid Campaigns by Revenue', $y);

        if (empty($rows)) {
            return $this->drawEmptyNotice($page, $y);
        }

        $columns = [
            ['label' => 'Platform', 'width' => 90, 'align' => 'left'],
            ['label' => 'Campaign', 'width' => 150, 'align' => 'left'],
            ['label' => 'Visits', 'width' => 70, 'align' => 'right'],
            ['label' => 'Orders', 'width' => 70, 'align' => 'right'],
            ['label' => 'Revenue', 'width' => 100, 'align' => 'right'],
        ];
        $y = $this->drawTableHeader($page, $columns, $y);

        foreach ($rows as $row) {
            $y = $this->drawTableRow($page, $columns, [
                $row['platform_code'],
                $row['campaign'],
                number_format($row['visits']),
                number_format($row['orders']),
                $this->formatCurrency($row['revenue']),
            ], $y);
        }

        return $y;
    }

    private function drawSectionTitle(\Zend_Pdf_Page $page, string $title, float $y): float
    {
        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD), 13);
        $page->drawText($title, self::PAGE_MARGIN_X, $y, 'UTF-8');

        return $y - self::LINE_HEIGHT - 4;
    }

    private function drawKeyValueRows(\Zend_Pdf_Page $page, array $rows, float $y): float
    {
        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA), 11);

        foreach ($rows as [$label, $value]) {
            $page->drawText($label, self::PAGE_MARGIN_X, $y, 'UTF-8');
            $page->drawText((string)$value, self::PAGE_MARGIN_X + 180, $y, 'UTF-8');
            $y -= self::LINE_HEIGHT;
        }

        return $y - (self::SECTION_GAP - self::LINE_HEIGHT);
    }

    /**
     * @param array<int, array{label: string, width: float, align: string}> $columns
     */
    private function drawTableHeader(\Zend_Pdf_Page $page, array $columns, float $y): float
    {
        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD), 10);
        $x = self::PAGE_MARGIN_X;
        foreach ($columns as $col) {
            $this->drawCell($page, $col['label'], $x, $y, $col['width'], $col['align']);
            $x += $col['width'];
        }
        $y -= 4;
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.6));
        $page->drawLine(self::PAGE_MARGIN_X, $y, $x, $y);

        return $y - self::LINE_HEIGHT;
    }

    /**
     * @param array<int, array{label: string, width: float, align: string}> $columns
     * @param array<int, string> $values
     */
    private function drawTableRow(\Zend_Pdf_Page $page, array $columns, array $values, float $y): float
    {
        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA), 9);
        $x = self::PAGE_MARGIN_X;
        foreach ($columns as $i => $col) {
            $this->drawCell($page, (string)($values[$i] ?? ''), $x, $y, $col['width'], $col['align']);
            $x += $col['width'];
        }

        return $y - (self::LINE_HEIGHT - 2);
    }

    private function drawCell(\Zend_Pdf_Page $page, string $text, float $x, float $y, float $width, string $align): void
    {
        if ($align === 'right') {
            $font = $page->getFont();
            $fontSize = $page->getFontSize();
            // Zend_Pdf has no built-in right-align: the text's own rendered
            // width has to be measured and subtracted from the column's
            // right edge. Falls back to left-aligned at the cell start if
            // the font/size is somehow unset, rather than throwing over a
            // cosmetic detail.
            if ($font !== null) {
                $textWidth = $this->measureTextWidth($font, $fontSize, $text);
                $x = $x + $width - $textWidth - 4;
            }
        }
        $page->drawText($text, $x, $y, 'UTF-8');
    }

    private function measureTextWidth(\Zend_Pdf_Resource_Font $font, float $fontSize, string $text): float
    {
        $drawingText = iconv('UTF-8', 'UTF-16BE//IGNORE', $text);
        $characters = [];
        for ($i = 0; $i < strlen($drawingText); $i += 2) {
            $characters[] = (ord($drawingText[$i]) << 8) | ord($drawingText[$i + 1]);
        }
        $glyphs = $font->glyphNumbersForCharacters($characters);
        $widths = $font->widthsForGlyphs($glyphs);

        return array_sum($widths) / $font->getUnitsPerEm() * $fontSize;
    }

    private function drawEmptyNotice(\Zend_Pdf_Page $page, float $y): float
    {
        $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_OBLIQUE), 10);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->drawText('No data for this period.', self::PAGE_MARGIN_X, $y, 'UTF-8');
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));

        return $y - self::SECTION_GAP;
    }

    private function formatRate(?float $rate): string
    {
        return $rate !== null ? sprintf('  (%.1f%%)', $rate) : '';
    }

    private function formatCurrency(float $amount): string
    {
        // Plain formatted number rather than a currency-symbol-prefixed
        // string: base_grand_total is a base-currency figure that may not
        // match the current admin locale's currency symbol, and Zend_Pdf's
        // standard fonts do not reliably render every currency glyph anyway.
        return number_format($amount, 2);
    }
}
