<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\AdSpendProvider;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Everything both CsvAdSpendProvider (reads an already-placed file) and the
 * admin upload controller (validates a file BEFORE placing it) need to agree
 * on: where a platform's file lives, and what makes one row valid. Pulled
 * out of CsvAdSpendProvider specifically so there is exactly one
 * implementation of "is this row valid" — two independently-written copies
 * would inevitably drift, and the worst way to find out is an upload that
 * "validates fine" but reads back differently once saved.
 */
class AdSpendCsvFile
{
    private const RELATIVE_DIRECTORY = 'aavirbhava/adsanalytics/adspend';

    private const COLUMN_DATE = 0;
    private const COLUMN_CAMPAIGN = 1;
    private const COLUMN_SPEND = 2;
    private const EXPECTED_COLUMNS = 3;

    /**
     * Skip reasons are collected for on-screen feedback (the upload
     * controller shows them to the admin who just uploaded a bad file), not
     * only logged. Capped so a file that is mostly garbage cannot turn the
     * admin success/error message into a multi-thousand-line wall of text;
     * the log still has every occurrence.
     */
    private const MAX_COLLECTED_SKIP_REASONS = 20;

    private Filesystem $filesystem;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;

    public function __construct(Filesystem $filesystem, FileDriver $fileDriver, LoggerInterface $logger)
    {
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
    }

    /**
     * platform_code reaches here from etc/config.xml's platform_map
     * (admin-editable, not raw request input) on the read side, and from a
     * fixed dropdown of AdSpendProviderPool::getPlatformCodes() on the
     * upload side — neither is arbitrary user text, but it is validated to a
     * safe charset anyway before touching the filesystem, on the same "never
     * trust a string into a path" principle EventValidator applies to
     * click-id param names. Anything outside [a-z0-9_-] resolves to a path
     * that cannot exist, which callers already treat as "no data" rather
     * than an error.
     */
    public function pathFor(string $platformCode): string
    {
        $safeCode = preg_match('/^[a-z0-9_-]+$/', $platformCode) === 1 ? $platformCode : '';

        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        return $varDirectory->getAbsolutePath(self::RELATIVE_DIRECTORY . '/' . $safeCode . '.csv');
    }

    public function exists(string $platformCode): bool
    {
        return $this->fileDriver->isExists($this->pathFor($platformCode));
    }

    /**
     * For display only (the admin upload section on the report page) — not
     * used on the ROAS read path, which only needs exists()/parse().
     *
     * @return array{exists: bool, modifiedAt: ?string, validRows: int, skippedRows: int}
     */
    public function status(string $platformCode): array
    {
        $path = $this->pathFor($platformCode);

        if (!$this->fileDriver->isExists($path)) {
            return ['exists' => false, 'modifiedAt' => null, 'validRows' => 0, 'skippedRows' => 0];
        }

        $stat = $this->fileDriver->stat($path);
        $handle = $this->fileDriver->fileOpen($path, 'r');

        try {
            $parsed = $this->parseStream($handle, $platformCode, $path);
        } finally {
            $this->fileDriver->fileClose($handle);
        }

        return [
            'exists' => true,
            'modifiedAt' => isset($stat['mtime']) ? date('Y-m-d H:i:s', (int)$stat['mtime']) : null,
            'validRows' => count($parsed['rows']),
            'skippedRows' => $parsed['skippedCount'],
        ];
    }

    /**
     * Parses every row from an open stream (an already-placed file, or an
     * uploaded temp file — the caller opens it either way). Does NOT filter
     * by date; CsvAdSpendProvider::getSpend() does that itself over the
     * result, since "which rows are valid" and "which rows fall in this
     * date range" are different questions and only the first one needs to be
     * identical between the read path and the upload-validation path.
     *
     * @param resource $stream
     * @return array{rows: array<int, array{date: string, campaign: string, spend: float}>, skippedCount: int, skippedReasons: string[]}
     */
    public function parseStream($stream, string $platformCode, string $sourceLabel): array
    {
        $rows = [];
        $skippedCount = 0;
        $skippedReasons = [];

        // The header row is discarded unconditionally rather than validated
        // against an exact expected string: a merchant who exports from a
        // spreadsheet tool may end up with different capitalisation or
        // trailing whitespace in the header, and rejecting the whole file
        // over that would be a worse failure mode than just trusting column
        // POSITION (date, campaign, spend, in that order) as the format
        // documented in the admin UI already says.
        $this->fileDriver->fileGetCsv($stream);
        $lineNumber = 1;

        while (($line = $this->fileDriver->fileGetCsv($stream)) !== false) {
            $lineNumber++;

            // A genuinely blank line comes back as [null], not an empty
            // array — skip it silently, it is not malformed data, just
            // whitespace at the end of a file.
            if ($line === [null] || $line === false) {
                continue;
            }

            [$row, $reason] = $this->parseRow($line);
            if ($row !== null) {
                $rows[] = $row;
                continue;
            }

            $skippedCount++;
            $this->logger->warning(
                sprintf(
                    'Aavirbhava_AdsAnalytics: skipped malformed ad-spend row (platform "%s", %s line %d): %s',
                    $platformCode,
                    $sourceLabel,
                    $lineNumber,
                    $reason
                )
            );
            if (count($skippedReasons) < self::MAX_COLLECTED_SKIP_REASONS) {
                $skippedReasons[] = sprintf('line %d: %s', $lineNumber, $reason);
            }
        }

        return ['rows' => $rows, 'skippedCount' => $skippedCount, 'skippedReasons' => $skippedReasons];
    }

    /**
     * @param array<int, string|null> $line
     * @return array{0: array{date: string, campaign: string, spend: float}|null, 1: string|null} [row, skipReason]
     */
    private function parseRow(array $line): array
    {
        if (count($line) < self::EXPECTED_COLUMNS) {
            return [null, 'expected 3 columns (date,campaign,spend)'];
        }

        $rawDate = trim((string)$line[self::COLUMN_DATE]);
        $campaign = trim((string)$line[self::COLUMN_CAMPAIGN]);
        $rawSpend = trim((string)$line[self::COLUMN_SPEND]);

        $date = \DateTime::createFromFormat('Y-m-d', $rawDate);
        // createFromFormat is lenient about overflow (2026-02-30 silently
        // becomes March), so the round-trip check below is required, not
        // just the non-false check, to actually catch an invalid date.
        if ($date === false || $date->format('Y-m-d') !== $rawDate) {
            return [null, sprintf('invalid date "%s", expected YYYY-MM-DD', $rawDate)];
        }

        if ($campaign === '') {
            return [null, 'empty campaign'];
        }

        if (!is_numeric($rawSpend) || (float)$rawSpend < 0) {
            return [null, sprintf('invalid spend "%s"', $rawSpend)];
        }

        return [['date' => $rawDate, 'campaign' => $campaign, 'spend' => (float)$rawSpend], null];
    }
}
