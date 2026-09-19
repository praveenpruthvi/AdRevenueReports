<?php

declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\AdSpendProvider;

// Shims is_uploaded_file() for AdSpendCsvUploader's call site only — see
// that file's own docblock for why and how. Required before
// AdSpendCsvUploader.php is autoloaded, so it happens here at the top of
// the test file rather than relying on load order.
require_once __DIR__ . '/_files/is_uploaded_file_override.php';

use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\AdSpendCsvFile;
use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\AdSpendCsvUploader;
use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Follow-up to P4-T4/T5: the upload path the admin config page's "Ad Spend
 * Data" section links to, added after shipping the file-drop-only version.
 *
 * is_uploaded_file() is shimmed via the require_once above (see that file's
 * own docblock) to always return true for the duration of this test file,
 * because a unit test has no real HTTP upload to satisfy it with — this is
 * the standard technique for that specific situation, not a gap in what
 * gets tested. Everything else AdSpendCsvUploader does — the platform/
 * error/size/extension checks, refusing to write a file with zero valid
 * rows, the path a valid file is written to — is exercised for real below.
 */
class AdSpendCsvUploaderTest extends TestCase
{
    /** @var AdSpendCsvFile&MockObject */
    private $csvFile;
    /** @var AdSpendProviderPool&MockObject */
    private $pool;
    /** @var Filesystem&MockObject */
    private $filesystem;
    /** @var FileDriver&MockObject */
    private $fileDriver;
    /** @var WriteInterface&MockObject */
    private $writeDirectory;

    protected function setUp(): void
    {
        $this->csvFile = $this->createMock(AdSpendCsvFile::class);
        $this->pool = $this->createMock(AdSpendProviderPool::class);
        $this->pool->method('hasProvider')->willReturn(true);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->fileDriver = $this->createMock(FileDriver::class);
        $this->writeDirectory = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($this->writeDirectory);
    }

    private function uploader(): AdSpendCsvUploader
    {
        return new AdSpendCsvUploader($this->csvFile, $this->pool, $this->filesystem, $this->fileDriver);
    }

    private function validFile(int $size = 100): array
    {
        return ['name' => 'spend.csv', 'tmp_name' => '/tmp/whatever', 'error' => UPLOAD_ERR_OK, 'size' => $size];
    }

    public function testRejectsAnUnregisteredPlatformBeforeTouchingTheUpload(): void
    {
        $this->pool = $this->createMock(AdSpendProviderPool::class);
        $this->pool->method('hasProvider')->willReturn(false);
        $this->fileDriver->expects($this->never())->method('fileOpen');

        $this->expectException(LocalizedException::class);
        $this->uploader()->upload('unknown_platform', $this->validFile());
    }

    public function testRejectsWhenNoFileWasChosen(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/choose/i');

        $this->uploader()->upload('google', ['error' => UPLOAD_ERR_NO_FILE]);
    }

    /**
     * @dataProvider phpUploadErrors
     */
    public function testAnyNonOkPhpUploadErrorIsRejectedWithAPlainMessage(int $errorCode): void
    {
        $this->expectException(LocalizedException::class);

        $this->uploader()->upload('google', ['error' => $errorCode, 'tmp_name' => '/tmp/x', 'size' => 10]);
    }

    /** @return array<string, array{0: int}> */
    public function phpUploadErrors(): array
    {
        return [
            'exceeds php.ini limit' => [UPLOAD_ERR_INI_SIZE],
            'exceeds form MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE],
            'partial upload' => [UPLOAD_ERR_PARTIAL],
            'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR],
            'cant write' => [UPLOAD_ERR_CANT_WRITE],
        ];
    }

    public function testRejectsAnEmptyFile(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/empty/i');

        $this->uploader()->upload('google', $this->validFile(0));
    }

    public function testRejectsAFileOverTheSizeCap(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/too large/i');

        $this->uploader()->upload('google', $this->validFile(6 * 1024 * 1024));
    }

    public function testRejectsANonCsvExtension(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/\.csv/');

        $this->uploader()->upload('google', ['name' => 'spend.xlsx', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10]);
    }

    /**
     * The whole point of validating before writing: a file that parses to
     * zero usable rows must not be allowed to silently wipe out a working
     * spend file that was there before.
     */
    public function testRejectsAFileWithNoValidRowsWithoutWriting(): void
    {
        $this->fileDriver->method('fileOpen')->willReturn(fopen('php://memory', 'r+'));
        $this->csvFile->method('parseStream')->willReturn(['rows' => [], 'skippedCount' => 3, 'skippedReasons' => []]);
        $this->writeDirectory->expects($this->never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/no valid rows/i');

        $this->uploader()->upload('google', $this->validFile());
    }

    public function testAcceptsAFileWithAtLeastOneValidRowAndReportsSkippedOnes(): void
    {
        $this->fileDriver->method('fileOpen')->willReturn(fopen('php://memory', 'r+'));
        $this->fileDriver->method('fileGetContents')->willReturn('date,campaign,spend' . PHP_EOL);
        $this->csvFile->method('parseStream')->willReturn([
            'rows' => [['date' => '2026-01-01', 'campaign' => 'a', 'spend' => 1.0]],
            'skippedCount' => 2,
            'skippedReasons' => ['line 3: invalid spend "x"'],
        ]);
        $this->writeDirectory->expects($this->once())->method('writeFile')
            ->with('aavirbhava/adsanalytics/adspend/google.csv', $this->anything());

        $result = $this->uploader()->upload('google', $this->validFile());

        $this->assertSame(1, $result['savedRows']);
        $this->assertSame(2, $result['skippedRows']);
    }

    public function testWritesToThePathMatchingTheConfiguredPlatformCode(): void
    {
        $this->fileDriver->method('fileOpen')->willReturn(fopen('php://memory', 'r+'));
        $this->fileDriver->method('fileGetContents')->willReturn('data');
        $this->csvFile->method('parseStream')->willReturn([
            'rows' => [['date' => '2026-01-01', 'campaign' => 'a', 'spend' => 1.0]],
            'skippedCount' => 0,
            'skippedReasons' => [],
        ]);

        $captured = null;
        $this->writeDirectory->method('writeFile')->willReturnCallback(
            function (string $path, string $content) use (&$captured) {
                $captured = $path;
            }
        );

        $this->uploader()->upload('meta', $this->validFile());

        $this->assertSame('aavirbhava/adsanalytics/adspend/meta.csv', $captured);
    }

    public function testAnUnsafePlatformCodeCannotEscapeTheTargetDirectory(): void
    {
        // hasProvider() would normally already refuse this platform, but the
        // path-building itself must independently refuse to escape the
        // directory even if it were somehow reached — defence in depth,
        // mirroring AdSpendCsvFile::pathFor()'s own read-side guarantee.
        $this->fileDriver->method('fileOpen')->willReturn(fopen('php://memory', 'r+'));
        $this->fileDriver->method('fileGetContents')->willReturn('data');
        $this->csvFile->method('parseStream')->willReturn([
            'rows' => [['date' => '2026-01-01', 'campaign' => 'a', 'spend' => 1.0]],
            'skippedCount' => 0,
            'skippedReasons' => [],
        ]);

        $captured = null;
        $this->writeDirectory->method('writeFile')->willReturnCallback(
            function (string $path, string $content) use (&$captured) {
                $captured = $path;
            }
        );

        $this->uploader()->upload('../../../../etc/passwd', $this->validFile());

        $this->assertStringNotContainsString('..', $captured);
        $this->assertSame('aavirbhava/adsanalytics/adspend/.csv', $captured);
    }
}
