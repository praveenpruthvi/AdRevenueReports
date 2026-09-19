<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Controller\Adminhtml\Report;

use Aavirbhava\AdsAnalytics\Controller\Adminhtml\Report\Pdf;
use Aavirbhava\AdsAnalytics\Model\Pdf\DashboardPdfGenerator;
use Aavirbhava\AdsAnalytics\Model\Pdf\DashboardReportBuilder;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * P4-T2. Covers resolveRange()/isValidDate() via reflection — the
 * date-range validation is the one part of this controller that touches
 * untrusted input (an admin-facing but still attacker-reachable URL query
 * param) before it reaches SQL, so it is the part most worth pinning
 * directly rather than only exercising through a full HTTP dispatch.
 */
class PdfTest extends TestCase
{
    /** @var RequestInterface&MockObject */
    private $request;
    /** @var DashboardReportBuilder&MockObject */
    private $reportBuilder;
    /** @var DateTime&MockObject */
    private $dateTime;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->reportBuilder = $this->createMock(DashboardReportBuilder::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-09-19');
    }

    private function controller(): Pdf
    {
        /** @var Context&MockObject $context */
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        return new Pdf(
            $context,
            $this->reportBuilder,
            $this->createMock(DashboardPdfGenerator::class),
            $this->createMock(FileFactory::class),
            $this->dateTime,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function resolveRange(): array
    {
        $method = new \ReflectionMethod(Pdf::class, 'resolveRange');
        $method->setAccessible(true);

        return $method->invoke($this->controller());
    }

    private function paramMap(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key) => $params[$key] ?? null
        );
    }

    public function testBothParamsAbsentDefaultsToTodayAndTheEarliestDate(): void
    {
        $this->paramMap([]);
        $this->reportBuilder->method('earliestDate')->willReturn('2026-01-01');

        [$from, $to, $error] = $this->resolveRange();

        $this->assertSame('2026-01-01', $from);
        $this->assertSame('2026-09-19', $to, '"to" must default to today (dateTime->gmtDate)');
        $this->assertNull($error);
    }

    public function testAnEmptySummaryTableDefaultsFromToToday(): void
    {
        $this->paramMap([]);
        $this->reportBuilder->method('earliestDate')->willReturn(null);

        [$from, $to, $error] = $this->resolveRange();

        // No data at all: from and to both collapse to today rather than to
        // an arbitrary lookback window or a null that would reach SQL.
        $this->assertSame('2026-09-19', $from);
        $this->assertSame('2026-09-19', $to);
        $this->assertNull($error);
    }

    public function testExplicitValidRangeIsUsedAsGiven(): void
    {
        $this->paramMap(['from' => '2026-03-01', 'to' => '2026-03-31']);

        [$from, $to, $error] = $this->resolveRange();

        $this->assertSame('2026-03-01', $from);
        $this->assertSame('2026-03-31', $to);
        $this->assertNull($error);
        // earliestDate() must not even be consulted when "from" was given.
        $this->reportBuilder->expects($this->never())->method('earliestDate');
    }

    /**
     * @dataProvider invalidDates
     */
    public function testAnInvalidToParamIsRejectedBeforeReachingSql(string $badValue): void
    {
        $this->paramMap(['to' => $badValue]);

        [, , $error] = $this->resolveRange();

        $this->assertNotNull($error);
        $this->assertStringContainsString('to', (string)$error);
    }

    /**
     * @dataProvider invalidDates
     */
    public function testAnInvalidFromParamIsRejected(string $badValue): void
    {
        $this->paramMap(['from' => $badValue, 'to' => '2026-09-19']);

        [, , $error] = $this->resolveRange();

        $this->assertNotNull($error);
        $this->assertStringContainsString('from', (string)$error);
    }

    /** @return array<string, array{0: string}> */
    public function invalidDates(): array
    {
        return [
            'not a date at all' => ['not-a-date'],
            // createFromFormat is lenient about calendar overflow — Feb 30
            // silently becomes March 2nd unless the round-trip is checked.
            // This case is what actually distinguishes a real validator
            // from one that merely checks createFromFormat() !== false.
            'invalid calendar date (Feb 30)' => ['2026-02-30'],
            'wrong format' => ['19-09-2026'],
            'sql-injection-shaped string' => ["2026-09-19' OR '1'='1"],
        ];
    }

    public function testFromAfterToIsRejected(): void
    {
        $this->paramMap(['from' => '2026-09-20', 'to' => '2026-09-19']);

        [, , $error] = $this->resolveRange();

        $this->assertNotNull($error);
        $this->assertStringContainsString('from', (string)$error);
    }

    public function testFromEqualToToIsAcceptedNotRejected(): void
    {
        $this->paramMap(['from' => '2026-09-19', 'to' => '2026-09-19']);

        [$from, $to, $error] = $this->resolveRange();

        $this->assertNull($error, 'a single-day range (from == to) must be valid, not off-by-one rejected');
        $this->assertSame($from, $to);
    }
}
