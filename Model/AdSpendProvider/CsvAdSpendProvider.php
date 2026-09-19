<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\AdSpendProvider;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Reference implementation of AdSpendProviderInterface (P4-T4, docs/SPECS.md
 * §5), and the module's proof that the extension point actually works: it is
 * registered in THIS module's own etc/di.xml exactly the way a client
 * project would register any other provider — a di.xml array entry, nothing
 * in core aware of it by name.
 *
 * ONE GENERIC CLASS SERVES EVERY PLATFORM. platform_code arrives as a
 * getSpend() argument, not a constructor argument, so the same instance
 * (Magento DI shares it by default) can be registered under "google",
 * "meta", or any other key in the pool array — the platform selects which
 * CSV file gets read, not which class gets instantiated. That is what
 * CLAUDE.md #2 ("no platform-specific code") means applied to spend data:
 * adding CSV spend for a sixth platform is a di.xml line and a file drop,
 * never a new PHP class.
 *
 * WHY CSV, NOT A LIVE API CALL. Google Ads and Meta Ads both require OAuth
 * app registration and a paid/approved developer account that this
 * environment does not have (see docs/status-reports for the same reasoning
 * applied to Hyvä checkout — build the free path, document the extension
 * point). A real GoogleAdsProvider or MetaAdsProvider would implement this
 * same interface, call the platform's reporting API instead of reading a
 * file, and store its OAuth credentials via
 * Magento\Config\Model\Config\Backend\Encrypted per docs/SECURITY.md §10 —
 * P4-T6 covers that for whichever provider a deployment actually uses. This
 * class needs no credentials at all, which is also why it is the safe
 * default to ship registered rather than commented out.
 *
 * FILE LOCATION AND FORMAT. var/aavirbhava/adsanalytics/adspend/<platform
 * code>.csv, three columns, header row required: date (Y-m-d),
 * campaign, spend (decimal, store currency, no currency symbol). A merchant
 * or a scheduled export from the ad platform's own UI drops a new file at
 * that path; nothing here watches or imports it automatically, matching
 * "reference implementation" rather than "polished merchant workflow" — a
 * production provider would more likely poll an API on its own schedule.
 */
class CsvAdSpendProvider implements AdSpendProviderInterface
{
    private const RELATIVE_DIRECTORY = 'aavirbhava/adsanalytics/adspend';

    private const COLUMN_DATE = 0;
    private const COLUMN_CAMPAIGN = 1;
    private const COLUMN_SPEND = 2;
    private const EXPECTED_COLUMNS = 3;

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
     * @return array<array{date: string, campaign: string, spend: float}>
     */
    public function getSpend(string $platformCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $path = $this->resolvePath($platformCode);

        if (!$this->fileDriver->isExists($path)) {
            // Not an error: a platform can legitimately have no spend file
            // yet, and ROAS (P4-T5) must render "no data" for it rather than
            // an exception breaking the whole dashboard over one missing CSV.
            return [];
        }

        $fromKey = $from->format('Y-m-d');
        $toKey = $to->format('Y-m-d');
        $rows = [];

        $handle = $this->fileDriver->fileOpen($path, 'r');

        try {
            $header = $this->fileDriver->fileGetCsv($handle);
            $lineNumber = 1;

            while (($line = $this->fileDriver->fileGetCsv($handle)) !== false) {
                $lineNumber++;

                // fileGetCsv returns [null] for a genuinely blank line rather
                // than an empty array — skip it silently, it is not malformed
                // data, just whitespace at the end of a file.
                if ($line === [null] || $line === false) {
                    continue;
                }

                $row = $this->parseRow($line, $platformCode, $path, $lineNumber);
                if ($row !== null && $row['date'] >= $fromKey && $row['date'] <= $toKey) {
                    $rows[] = $row;
                }
            }
        } finally {
            $this->fileDriver->fileClose($handle);
        }

        return $rows;
    }

    /**
     * Validates and normalises one data row. Returns null and logs a warning
     * for anything malformed rather than throwing: one bad row in a
     * merchant-edited CSV must not blank out spend for the whole platform,
     * the same fail-open philosophy EventConsumer applies to a single bad
     * queue message.
     *
     * @param array<int, string|null> $line
     * @return array{date: string, campaign: string, spend: float}|null
     */
    private function parseRow(array $line, string $platformCode, string $path, int $lineNumber): ?array
    {
        if (count($line) < self::EXPECTED_COLUMNS) {
            $this->logMalformedRow($platformCode, $path, $lineNumber, 'expected 3 columns (date,campaign,spend)');
            return null;
        }

        $rawDate = trim((string)$line[self::COLUMN_DATE]);
        $campaign = trim((string)$line[self::COLUMN_CAMPAIGN]);
        $rawSpend = trim((string)$line[self::COLUMN_SPEND]);

        $date = \DateTime::createFromFormat('Y-m-d', $rawDate);
        // createFromFormat is lenient about overflow (2026-02-30 silently
        // becomes March), so the round-trip check below is required, not
        // just the non-false check, to actually catch an invalid date.
        if ($date === false || $date->format('Y-m-d') !== $rawDate) {
            $this->logMalformedRow($platformCode, $path, $lineNumber, "invalid date \"$rawDate\", expected Y-m-d");
            return null;
        }

        if ($campaign === '') {
            $this->logMalformedRow($platformCode, $path, $lineNumber, 'empty campaign');
            return null;
        }

        if (!is_numeric($rawSpend) || (float)$rawSpend < 0) {
            $this->logMalformedRow($platformCode, $path, $lineNumber, "invalid spend \"$rawSpend\"");
            return null;
        }

        return ['date' => $rawDate, 'campaign' => $campaign, 'spend' => (float)$rawSpend];
    }

    private function logMalformedRow(string $platformCode, string $path, int $lineNumber, string $reason): void
    {
        $this->logger->warning(
            sprintf(
                'Aavirbhava_AdsAnalytics: skipped malformed ad-spend row (platform "%s", %s line %d): %s',
                $platformCode,
                $path,
                $lineNumber,
                $reason
            )
        );
    }

    /**
     * platform_code ultimately comes from etc/config.xml's platform_map,
     * admin-editable configuration rather than untrusted request input — but
     * it is validated to a safe charset anyway before touching the
     * filesystem, on the same "never trust a string into a path" principle
     * the rest of this module applies to click-id param names
     * (EventValidator checks those against the configured map before
     * EventConsumer ever uses one). Anything outside [a-z0-9_-] resolves to
     * a path that cannot exist, which getSpend() already treats as "no
     * data" rather than an error.
     */
    private function resolvePath(string $platformCode): string
    {
        $safeCode = preg_match('/^[a-z0-9_-]+$/', $platformCode) === 1 ? $platformCode : '';

        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        return $varDirectory->getAbsolutePath(self::RELATIVE_DIRECTORY . '/' . $safeCode . '.csv');
    }
}
