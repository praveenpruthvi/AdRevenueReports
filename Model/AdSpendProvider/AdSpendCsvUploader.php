<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\AdSpendProvider;

use Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Handles an admin's CSV upload for one platform's ad spend (follow-up to
 * P4-T4/P4-T5, requested directly after shipping the file-drop-only version:
 * "what if the user doesn't have access to the file server?").
 *
 * Deliberately does NOT use \Magento\Framework\File\Uploader. That class is
 * built for the media-gallery case — auto-renaming on a name collision,
 * optional directory dispersion, arbitrary destination names — and every one
 * of those behaviours is wrong here: this always needs to land at exactly
 * ONE fixed name per platform (google.csv, meta.csv, ...) and DELIBERATELY
 * overwrite whatever was there before, the same as if an admin had replaced
 * the file by hand. Reimplementing the handful of checks that matter
 * (upload succeeded, real upload not a forged path, size, extension) is a
 * few lines; fighting Uploader's rename/dispersion options to get
 * overwrite-a-fixed-name behaviour back out of it would be more code, not
 * less.
 *
 * VALIDATES BEFORE WRITING. The uploaded temp file is parsed through
 * AdSpendCsvFile — the SAME class CsvAdSpendProvider reads with — before
 * anything is written to the real destination. A file that parses to zero
 * valid rows is rejected outright rather than silently replacing a working
 * spend file with an empty one; a file with SOME valid rows is accepted (the
 * upload result reports how many were skipped and why), matching the
 * fail-open handling the read path already applies to a hand-edited file.
 */
class AdSpendCsvUploader
{
    /**
     * 5 MiB. A spend CSV for one platform is a few thousand rows at most —
     * this is generous headroom against a genuine file while still refusing
     * something clearly not a spend export.
     */
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const RELATIVE_DIRECTORY = 'aavirbhava/adsanalytics/adspend';

    private AdSpendCsvFile $csvFile;
    private AdSpendProviderPool $pool;
    private Filesystem $filesystem;
    private FileDriver $fileDriver;

    public function __construct(
        AdSpendCsvFile $csvFile,
        AdSpendProviderPool $pool,
        Filesystem $filesystem,
        FileDriver $fileDriver
    ) {
        $this->csvFile = $csvFile;
        $this->pool = $pool;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }

    /**
     * @param string $platformCode which platform this file is for (from a
     *                              fixed dropdown of registered platforms,
     *                              never raw free text — see the controller)
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $uploadedFile
     *        one entry of $_FILES, passed in rather than read here so this
     *        class has no superglobal dependency and is fully unit-testable
     * @return array{savedRows: int, skippedRows: int, skippedReasons: string[]}
     * @throws LocalizedException on anything that means nothing was saved
     */
    public function upload(string $platformCode, array $uploadedFile): array
    {
        if (!$this->pool->hasProvider($platformCode)) {
            // Uploading for a platform with no registered provider would
            // save a file nothing ever reads — almost certainly a stale
            // dropdown from before a provider was removed.
            throw new LocalizedException(
                __('"%1" is not a recognised ad-spend platform.', $platformCode)
            );
        }

        $this->assertUploadSucceeded($uploadedFile);

        $tmpName = (string)$uploadedFile['tmp_name'];

        if (!is_uploaded_file($tmpName)) {
            // The one check that cannot be meaningfully unit-tested (it is a
            // real PHP upload-mechanism check, not application logic — core
            // Magento does not unit-test it either) but must never be
            // skipped: without it, a forged tmp_name could make this class
            // read and "accept" an arbitrary file already on the server.
            throw new LocalizedException(__('The uploaded file could not be verified.'));
        }

        $size = (int)($uploadedFile['size'] ?? 0);
        if ($size <= 0) {
            throw new LocalizedException(__('The uploaded file is empty.'));
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new LocalizedException(
                __('The file is too large (max %1 MB).', (int)(self::MAX_UPLOAD_BYTES / 1024 / 1024))
            );
        }

        $originalName = (string)($uploadedFile['name'] ?? '');
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            throw new LocalizedException(__('Only .csv files are accepted.'));
        }

        $handle = $this->fileDriver->fileOpen($tmpName, 'r');
        try {
            $parsed = $this->csvFile->parseStream($handle, $platformCode, $originalName);
        } finally {
            $this->fileDriver->fileClose($handle);
        }

        if (count($parsed['rows']) === 0) {
            throw new LocalizedException(
                __(
                    'No valid rows were found in that file — nothing was saved. '
                    . 'Check it has a header row followed by date,campaign,spend rows.'
                )
            );
        }

        $this->write($platformCode, $tmpName);

        return [
            'savedRows' => count($parsed['rows']),
            'skippedRows' => $parsed['skippedCount'],
            'skippedReasons' => $parsed['skippedReasons'],
        ];
    }

    /**
     * @param array{error?: int} $uploadedFile
     */
    private function assertUploadSucceeded(array $uploadedFile): void
    {
        $error = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new LocalizedException(__('Choose a CSV file to upload.'));
        }
        if ($error !== UPLOAD_ERR_OK) {
            // UPLOAD_ERR_INI_SIZE/FORM_SIZE (too large), PARTIAL (connection
            // dropped mid-upload), CANT_WRITE/NO_TMP_DIR/EXTENSION (server
            // config) — none of these are the admin's mistake to decode, so
            // one message covers all of them rather than translating PHP's
            // own upload error constants one by one.
            throw new LocalizedException(__('The file upload failed. Please try again.'));
        }
    }

    /**
     * Writes via DirectoryWrite::writeFile() on a RELATIVE path, not an
     * absolute one built by hand — writeFile() validates internally that the
     * path stays inside the directory's own scope before touching disk, the
     * same safety property AdSpendCsvFile::pathFor() gives the read side.
     * Always overwrites: a re-upload for a platform replaces its file,
     * exactly like dropping a new file by hand would.
     */
    private function write(string $platformCode, string $tmpName): void
    {
        $safeCode = preg_match('/^[a-z0-9_-]+$/', $platformCode) === 1 ? $platformCode : '';
        $relativePath = self::RELATIVE_DIRECTORY . '/' . $safeCode . '.csv';

        $varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $varDirectory->create(self::RELATIVE_DIRECTORY);
        $varDirectory->writeFile($relativePath, (string)$this->fileDriver->fileGetContents($tmpName));
    }
}
