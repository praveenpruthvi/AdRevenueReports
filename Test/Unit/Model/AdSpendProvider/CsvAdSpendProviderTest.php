<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\AdSpendProvider;

use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\AdSpendCsvFile;
use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\CsvAdSpendProvider;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * P4-T4. Row-validity parsing lives in AdSpendCsvFile (its own test class);
 * this covers only what CsvAdSpendProvider itself is responsible for: the
 * missing-file short circuit, and filtering parsed rows to the requested
 * date range.
 */
class CsvAdSpendProviderTest extends TestCase
{
    /** @var AdSpendCsvFile&MockObject */
    private $csvFile;
    /** @var FileDriver&MockObject */
    private $fileDriver;

    protected function setUp(): void
    {
        $this->csvFile = $this->createMock(AdSpendCsvFile::class);
        $this->fileDriver = $this->createMock(FileDriver::class);
        $this->csvFile->method('pathFor')->willReturnCallback(
            static fn (string $platform): string => "/var/adspend/$platform.csv"
        );
    }

    private function provider(): CsvAdSpendProvider
    {
        return new CsvAdSpendProvider($this->csvFile, $this->fileDriver);
    }

    public function testReturnsEmptyArrayWhenNoFileExistsForThePlatform(): void
    {
        $this->fileDriver->method('isExists')->willReturn(false);
        $this->fileDriver->expects($this->never())->method('fileOpen');
        $this->csvFile->expects($this->never())->method('parseStream');

        $rows = $this->provider()->getSpend('pinterest', new \DateTime('-7 days'), new \DateTime('now'));

        $this->assertSame([], $rows, 'a platform with no CSV yet must not be an error');
    }

    public function testFiltersParsedRowsToTheRequestedDateRangeInclusive(): void
    {
        $this->fileDriver->method('isExists')->willReturn(true);
        $resource = fopen('php://memory', 'r+');
        $this->fileDriver->method('fileOpen')->willReturn($resource);
        $this->fileDriver->expects($this->once())->method('fileClose')->with($resource);

        $this->csvFile->method('parseStream')->willReturn([
            'rows' => [
                ['date' => '2026-01-01', 'campaign' => 'c', 'spend' => 10.0],
                ['date' => '2026-01-02', 'campaign' => 'c', 'spend' => 20.0],
                ['date' => '2026-01-06', 'campaign' => 'c', 'spend' => 30.0],
                ['date' => '2026-01-10', 'campaign' => 'c', 'spend' => 40.0],
            ],
            'skippedCount' => 0,
            'skippedReasons' => [],
        ]);

        $rows = $this->provider()->getSpend('google', new \DateTime('2026-01-02'), new \DateTime('2026-01-06'));

        $this->assertSame(
            [
                ['date' => '2026-01-02', 'campaign' => 'c', 'spend' => 20.0],
                ['date' => '2026-01-06', 'campaign' => 'c', 'spend' => 30.0],
            ],
            $rows,
            'both boundary dates must be included, and rows outside them excluded'
        );
    }

    public function testFileHandleIsClosedEvenIfParsingThrows(): void
    {
        $this->fileDriver->method('isExists')->willReturn(true);
        $resource = fopen('php://memory', 'r+');
        $this->fileDriver->method('fileOpen')->willReturn($resource);
        $this->fileDriver->expects($this->once())->method('fileClose')->with($resource);
        $this->csvFile->method('parseStream')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->provider()->getSpend('google', new \DateTime('-1 day'), new \DateTime('now'));
    }
}
