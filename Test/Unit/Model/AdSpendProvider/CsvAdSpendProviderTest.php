<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\AdSpendProvider;

use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\CsvAdSpendProvider;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P4-T4. Covers the two things this reference provider must get right:
 * treating a missing file as "no data" rather than an error (a platform can
 * legitimately have no spend recorded yet), and skipping a malformed row
 * without losing the rest of the file (a merchant-edited CSV will have typos
 * eventually).
 */
class CsvAdSpendProviderTest extends TestCase
{
    /** @var Filesystem&MockObject */
    private $filesystem;
    /** @var FileDriver&MockObject */
    private $fileDriver;
    /** @var LoggerInterface&MockObject */
    private $logger;
    /** @var ReadInterface&MockObject */
    private $readDirectory;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->fileDriver = $this->createMock(FileDriver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->readDirectory = $this->createMock(ReadInterface::class);

        $this->readDirectory->method('getAbsolutePath')
            ->willReturnCallback(static fn (string $relative): string => '/var/www/html/var/' . $relative);
        $this->filesystem->method('getDirectoryRead')
            ->with(DirectoryList::VAR_DIR)
            ->willReturn($this->readDirectory);
    }

    private function provider(): CsvAdSpendProvider
    {
        return new CsvAdSpendProvider($this->filesystem, $this->fileDriver, $this->logger);
    }

    public function testReturnsEmptyArrayWhenNoFileExistsForThePlatform(): void
    {
        $this->fileDriver->method('isExists')->willReturn(false);
        $this->fileDriver->expects($this->never())->method('fileOpen');

        $rows = $this->provider()->getSpend('pinterest', new \DateTime('-7 days'), new \DateTime('now'));

        $this->assertSame([], $rows, 'a platform with no CSV yet must not be an error');
    }

    public function testReadsAndFiltersRowsWithinTheDateRange(): void
    {
        $this->stubCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-01', 'spring_sale', '10.50'],
            ['2026-01-05', 'spring_sale', '20.00'],
            ['2026-01-10', 'spring_sale', '99.99'],
        ]);

        $rows = $this->provider()->getSpend('google', new \DateTime('2026-01-02'), new \DateTime('2026-01-06'));

        $this->assertSame([['date' => '2026-01-05', 'campaign' => 'spring_sale', 'spend' => 20.0]], $rows);
    }

    public function testDateRangeBoundsAreInclusive(): void
    {
        $this->stubCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-02', 'c', '1.00'],
            ['2026-01-06', 'c', '2.00'],
        ]);

        $rows = $this->provider()->getSpend('google', new \DateTime('2026-01-02'), new \DateTime('2026-01-06'));

        $this->assertCount(2, $rows, 'both boundary dates must be included, not just the interior');
    }

    /**
     * @dataProvider malformedRows
     */
    public function testSkipsAMalformedRowButKeepsTheRestOfTheFile(array $badRow, string $expectedReasonFragment): void
    {
        $this->stubCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-05', 'good_campaign', '15.00'],
            $badRow,
            ['2026-01-06', 'another_good_campaign', '25.00'],
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains($expectedReasonFragment));

        $rows = $this->provider()->getSpend('google', new \DateTime('2026-01-01'), new \DateTime('2026-01-31'));

        $this->assertCount(2, $rows, 'the two well-formed rows must survive the bad one between them');
        $this->assertSame('good_campaign', $rows[0]['campaign']);
        $this->assertSame('another_good_campaign', $rows[1]['campaign']);
    }

    /** @return array<string, array{0: array<int, string>, 1: string}> */
    public function malformedRows(): array
    {
        return [
            'non-numeric spend' => [['2026-01-05', 'bad', 'free'], 'invalid spend'],
            'negative spend' => [['2026-01-05', 'bad', '-5.00'], 'invalid spend'],
            'invalid calendar date' => [['2026-02-30', 'bad', '5.00'], 'invalid date'],
            'unparsable date' => [['not-a-date', 'bad', '5.00'], 'invalid date'],
            'empty campaign' => [['2026-01-05', '', '5.00'], 'empty campaign'],
            'too few columns' => [['2026-01-05', 'bad'], 'expected 3 columns'],
        ];
    }

    public function testEmptyStringSpendIsNotNumericAndIsRejected(): void
    {
        // is_numeric('') is false in PHP; this pins that fact isn't
        // accidentally reversed by a future refactor to e.g. (float) casting
        // before validating, which would turn '' into 0.0 and silently
        // record a real (if zero) spend row instead of flagging bad data.
        $this->stubCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-05', 'c', ''],
        ]);
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([], $this->provider()->getSpend('google', new \DateTime('2026-01-01'), new \DateTime('2026-01-31')));
    }

    /**
     * @param array<int, array<int, string>> $rows header row first
     */
    private function stubCsv(array $rows): void
    {
        $this->fileDriver->method('isExists')->willReturn(true);
        $resource = fopen('php://memory', 'r+');
        $this->fileDriver->method('fileOpen')->willReturn($resource);
        $this->fileDriver->method('fileGetCsv')->willReturnCallback(
            static function () use (&$rows) {
                return array_shift($rows) ?: false;
            }
        );
        $this->fileDriver->expects($this->once())->method('fileClose');
    }
}
