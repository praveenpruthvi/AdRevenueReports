<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model;

use Aavirbhava\AdsAnalytics\Api\AdSpendProviderInterface;
use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\CsvAdSpendProvider;
use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Extensibility point (docs/SPECS.md §5). $providers is populated entirely
 * via di.xml virtual-type array entries — see etc/di.xml in this module for
 * the current entries, and add a NEW, non-CSV provider (a real Google Ads /
 * Meta Ads API integration, say) in a SEPARATE module's di.xml rather than
 * editing this module's core.
 *
 * FALLS BACK TO THE GENERIC CSV PROVIDER FOR ANY PLATFORM THE ADMIN HAS
 * ALREADY CONFIGURED, not only the ones explicitly listed in di.xml. Added
 * after shipping a version where only google/meta could upload a spend CSV
 * — an arbitrary limit an admin correctly pushed back on, since "Paid
 * Platform / Click-ID Map" (system.xml, TrafficClassificationConfig) already
 * lists every platform this store classifies traffic for, and
 * CsvAdSpendProvider is completely generic: platform_code arrives as a
 * getSpend() argument, not a constructor one, so the exact same instance can
 * serve a platform nobody ever wrote a di.xml line for. Without this
 * fallback, adding spend tracking for a 6th platform (Pinterest, say) would
 * need a developer and a di.xml change even though the class doing the work
 * needs no such thing — exactly the "no platform-specific code" principle
 * (CLAUDE.md #2) this module applies everywhere else, now applied here too.
 *
 * An explicit di.xml entry for a platform still WINS over the fallback: a
 * client project registering a real API-backed provider for "google"
 * overrides the generic CSV reader for that platform specifically, without
 * this class needing to know or care that it happened.
 */
class AdSpendProviderPool
{
    /** @var AdSpendProviderInterface[] keyed by platform_code, from di.xml */
    private array $providers;

    private TrafficClassificationConfig $classificationConfig;
    private CsvAdSpendProvider $csvAdSpendProvider;

    /**
     * @param AdSpendProviderInterface[] $providers keyed by platform_code
     */
    public function __construct(
        TrafficClassificationConfig $classificationConfig,
        CsvAdSpendProvider $csvAdSpendProvider,
        array $providers = []
    ) {
        $this->classificationConfig = $classificationConfig;
        $this->csvAdSpendProvider = $csvAdSpendProvider;
        $this->providers = $providers;
    }

    public function getProvider(string $platformCode): AdSpendProviderInterface
    {
        if (isset($this->providers[$platformCode])) {
            return $this->providers[$platformCode];
        }

        if ($this->isConfiguredPlatform($platformCode)) {
            return $this->csvAdSpendProvider;
        }

        throw new NoSuchEntityException(
            __('No ad-spend provider registered for platform "%1".', $platformCode)
        );
    }

    public function hasProvider(string $platformCode): bool
    {
        return isset($this->providers[$platformCode]) || $this->isConfiguredPlatform($platformCode);
    }

    /**
     * Every platform_code that has a registered provider — the union of
     * di.xml's explicit entries and whatever "Paid Platform / Click-ID Map"
     * currently lists (see this class's docblock for why the latter counts).
     *
     * Added for P4-T5's ROAS report. Iterating only the platforms that appear
     * in the summary would hide a campaign that SPENT money but drew no
     * traffic — which is exactly the case a merchant most needs to see — so
     * the report has to be able to ask "which platforms can report spend?"
     * independently of "which platforms sent visitors?".
     *
     * @return string[]
     */
    public function getPlatformCodes(): array
    {
        $codes = array_merge(
            array_keys($this->providers),
            array_keys($this->classificationConfig->getPlatformMap())
        );

        return array_values(array_unique(array_map('strval', $codes)));
    }

    /**
     * Whether platform_code is one the admin has configured for traffic
     * classification — the CSV fallback only ever covers a platform the
     * store actually recognises, never arbitrary caller-supplied text (the
     * same "never trust a string into a path" principle
     * AdSpendCsvFile::pathFor() applies independently on the read side).
     */
    private function isConfiguredPlatform(string $platformCode): bool
    {
        return isset($this->classificationConfig->getPlatformMap()[$platformCode]);
    }
}
