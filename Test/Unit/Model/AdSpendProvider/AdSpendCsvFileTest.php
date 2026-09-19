<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\AdSpendProvider;

use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\AdSpendCsvFile;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P4-T4 / the ad-spend upload follow-up. This is the ONE place row validity
 * is decided — both CsvAdSpendProvider (reads an already-placed file) and
 * the admin upload controller (validates before placing one) go through it,
 * so a file that "uploads fine" and a file that "reads fine" can never
 * disagree about which rows are valid.
 */
class AdSpendCsvFileTest extends TestCase
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

    private function csvFile(): AdSpendCsvFile
    {
        return new AdSpendCsvFile($this->filesystem, $this->fileDriver, $this->logger);
    }

    public function testPathForUsesTheConventionDirectoryAndPlatformCode(): void
    {
        $this->assertSame(
            '/var/www/html/var/aavirbhava/adsanalytics/adspend/google.csv',
            $this->csvFile()->pathFor('google')
        );
    }

    public function testPathForRejectsUnsafeCharactersRatherThanEscapingThem(): void
    {
        // Resolves to a path that cannot exist, which callers already treat
        // as "no data" — never escapes the intended directory.
        $path = $this->csvFile()->pathFor('../../../../etc/passwd');

        $this->assertStringNotContainsString('..', $path);
        $this->assertSame('/var/www/html/var/aavirbhava/adsanalytics/adspend/.csv', $path);
    }

    public function testExistsDelegatesToTheFileDriver(): void
    {
        $this->fileDriver->expects($this->once())->method('isExists')
            ->with('/var/www/html/var/aavirbhava/adsanalytics/adspend/google.csv')
            ->willReturn(true);

        $this->assertTrue($this->csvFile()->exists('google'));
    }

    public function testParseStreamReadsAllRowsWithNoDateFiltering(): void
    {
        $resource = $this->openCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-01', 'a', '1.00'],
            ['2099-12-31', 'b', '2.00'],
        ]);

        $result = $this->csvFile()->parseStream($resource, 'google', 'test.csv');

        $this->assertCount(2, $result['rows'], "parseStream does not filter by date — that is the caller's job");
        $this->assertSame(0, $result['skippedCount']);
    }

    /**
     * @dataProvider malformedRows
     */
    public function testSkipsAMalformedRowButKeepsTheRestOfTheFile(array $badRow, string $expectedReasonFragment): void
    {
        $resource = $this->openCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-05', 'good_campaign', '15.00'],
            $badRow,
            ['2026-01-06', 'another_good_campaign', '25.00'],
        ]);

        $result = $this->csvFile()->parseStream($resource, 'google', 'test.csv');

        $this->assertCount(2, $result['rows']);
        $this->assertSame(1, $result['skippedCount']);
        $this->assertStringContainsString($expectedReasonFragment, $result['skippedReasons'][0]);
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
        $resource = $this->openCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-05', 'c', ''],
        ]);

        $result = $this->csvFile()->parseStream($resource, 'google', 'test.csv');

        $this->assertSame([], $result['rows']);
        $this->assertSame(1, $result['skippedCount']);
    }

    public function testSkippedReasonsAreCappedButSkippedCountIsNot(): void
    {
        $rows = [['date', 'campaign', 'spend']];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['bad-date', 'c', '1.00'];
        }
        $resource = $this->openCsv($rows);

        $result = $this->csvFile()->parseStream($resource, 'google', 'test.csv');

        $this->assertSame(30, $result['skippedCount'], 'every bad row is counted');
        $this->assertLessThanOrEqual(20, count($result['skippedReasons']), 'but the detail list is capped');
    }

    public function testEachMalformedRowIsLogged(): void
    {
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('invalid spend'));

        $resource = $this->openCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-05', 'c', 'not-a-number'],
        ]);
        $this->csvFile()->parseStream($resource, 'google', '/path/to/google.csv');
    }

    public function testStatusOnAMissingFileReportsNotExistsWithoutOpeningAnything(): void
    {
        $this->fileDriver->method('isExists')->willReturn(false);
        $this->fileDriver->expects($this->never())->method('fileOpen');

        $status = $this->csvFile()->status('google');

        $this->assertFalse($status['exists']);
        $this->assertSame(0, $status['validRows']);
    }

    public function testStatusOnARealFileReportsCountsAndModifiedTime(): void
    {
        $this->fileDriver->method('isExists')->willReturn(true);
        $this->fileDriver->method('stat')->willReturn(['mtime' => 1700000000]);
        $resource = $this->openCsv([
            ['date', 'campaign', 'spend'],
            ['2026-01-05', 'a', '1.00'],
            ['bad-date', 'a', '1.00'],
        ]);
        $this->fileDriver->method('fileOpen')->willReturn($resource);

        $status = $this->csvFile()->status('google');

        $this->assertTrue($status['exists']);
        $this->assertSame(1, $status['validRows']);
        $this->assertSame(1, $status['skippedRows']);
        $this->assertNotNull($status['modifiedAt']);
    }

    /**
     * @param array<int, array<int, string>> $rows header row first
     * @return resource
     */
    private function openCsv(array $rows)
    {
        $resource = fopen('php://memory', 'r+');
        $this->fileDriver->method('fileGetCsv')->willReturnCallback(
            static function () use (&$rows) {
                return array_shift($rows) ?: false;
            }
        );

        return $resource;
    }
}
