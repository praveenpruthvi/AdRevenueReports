<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\AdSpendProvider;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

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
 * adding CSV spend for a sixth platform is a di.xml line and a file, never a
 * new PHP class.
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
 * FILE LOCATION AND FORMAT (AdSpendCsvFile::pathFor()/parseStream()): var/
 * aavirbhava/adsanalytics/adspend/<platform code>.csv, three columns, header
 * row required: date (Y-m-d), campaign, spend (decimal, store currency, no
 * currency symbol). A merchant places a new file at that path either
 * directly (Controller\Adminhtml\AdSpend\Upload, an upload form on the
 * report page) or by hand on the server; nothing here watches or imports it
 * automatically, matching "reference implementation" rather than "live
 * feed" — a production provider would more likely poll an API on its own
 * schedule.
 */
class CsvAdSpendProvider implements AdSpendProviderInterface
{
    private AdSpendCsvFile $csvFile;
    private FileDriver $fileDriver;

    public function __construct(AdSpendCsvFile $csvFile, FileDriver $fileDriver)
    {
        $this->csvFile = $csvFile;
        $this->fileDriver = $fileDriver;
    }

    /**
     * @return array<array{date: string, campaign: string, spend: float}>
     */
    public function getSpend(string $platformCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $path = $this->csvFile->pathFor($platformCode);

        if (!$this->fileDriver->isExists($path)) {
            // Not an error: a platform can legitimately have no spend file
            // yet, and ROAS (P4-T5) must render "no data" for it rather than
            // an exception breaking the whole dashboard over one missing CSV.
            return [];
        }

        $fromKey = $from->format('Y-m-d');
        $toKey = $to->format('Y-m-d');

        $handle = $this->fileDriver->fileOpen($path, 'r');
        try {
            $parsed = $this->csvFile->parseStream($handle, $platformCode, $path);
        } finally {
            $this->fileDriver->fileClose($handle);
        }

        return array_values(array_filter(
            $parsed['rows'],
            static fn (array $row): bool => $row['date'] >= $fromKey && $row['date'] <= $toKey
        ));
    }
}
