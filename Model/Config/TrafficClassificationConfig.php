<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Reads and normalises the three config-driven classification lists from
 * etc/adminhtml/system.xml (defaults in etc/config.xml), for
 * Model\Service\TrafficResolver (P1-T3/P1-T4, docs/SPECS.md §2).
 *
 * This class exists so TrafficResolver stays a pure classifier: all JSON
 * decoding, casing, trimming and malformed-config handling happens here once,
 * and the resolver receives clean arrays it can trust.
 *
 * SCOPE: read at default scope deliberately. The queue consumer that calls
 * TrafficResolver runs in a separate process with no store context, so there
 * is no per-store scope to resolve against at classification time. The
 * system.xml fields are showInWebsite="1", so a multi-store merchant CAN set
 * these per website — but only the default-scope value is used for
 * classification today. If per-website classification is ever needed, the
 * visit's store_id has to travel on the queue message and be passed in here.
 */
class TrafficClassificationConfig
{
    private const XML_PATH_PLATFORM_MAP = 'aavirbhava_adsanalytics/platforms/platform_map';
    private const XML_PATH_PAID_MEDIUMS = 'aavirbhava_adsanalytics/platforms/paid_mediums';
    private const XML_PATH_SEARCH_ENGINE_DOMAINS = 'aavirbhava_adsanalytics/platforms/search_engine_domains';

    private ScopeConfigInterface $scopeConfig;
    private Json $json;
    private LoggerInterface $logger;

    /** Memoised per instance — this is a DI singleton and the consumer calls it per message. */
    private ?array $platformMap = null;
    private ?array $paidMediums = null;
    private ?array $searchEngineDomains = null;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Platform map keyed by platform_code — a closed, stable set that
     * ads_analytics_visit.platform_code is drawn from.
     *
     * @return array<string, array{click_id_param: string, default_source: string, alt_sources: string[]}>
     */
    public function getPlatformMap(): array
    {
        if ($this->platformMap !== null) {
            return $this->platformMap;
        }

        $map = [];
        foreach ($this->readRows(self::XML_PATH_PLATFORM_MAP) as $row) {
            $platformCode = strtolower(trim((string)($row['platform_code'] ?? '')));
            $clickIdParam = trim((string)($row['click_id_param'] ?? ''));

            // A row without both of these classifies nothing, so drop it
            // rather than letting an empty click_id_param match every request
            // (empty($queryParams['']) would be a very bad default).
            if ($platformCode === '' || $clickIdParam === '') {
                continue;
            }

            $defaultSource = strtolower(trim((string)($row['default_source'] ?? '')));

            $map[$platformCode] = [
                'click_id_param' => $clickIdParam,
                'default_source' => $defaultSource !== '' ? $defaultSource : $platformCode,
                'alt_sources' => $this->splitList((string)($row['alt_sources'] ?? '')),
            ];
        }

        return $this->platformMap = $map;
    }

    /**
     * Lowercased utm_medium values that mean "paid" without a click id.
     *
     * @return string[]
     */
    public function getPaidMediums(): array
    {
        if ($this->paidMediums !== null) {
            return $this->paidMediums;
        }

        $mediums = [];
        foreach ($this->readRows(self::XML_PATH_PAID_MEDIUMS) as $row) {
            $medium = strtolower(trim((string)($row['medium'] ?? '')));
            if ($medium !== '') {
                $mediums[] = $medium;
            }
        }

        return $this->paidMediums = array_values(array_unique($mediums));
    }

    /**
     * Registrable domain => engine name. Matched at a label boundary by the
     * resolver, never as a substring.
     *
     * @return array<string, string>
     */
    public function getSearchEngineDomains(): array
    {
        if ($this->searchEngineDomains !== null) {
            return $this->searchEngineDomains;
        }

        $domains = [];
        foreach ($this->readRows(self::XML_PATH_SEARCH_ENGINE_DOMAINS) as $row) {
            // Tolerate an admin typing ".google.com" or " Google.COM " — a
            // leading dot or stray case would otherwise silently never match,
            // which looks exactly like "organic tracking is broken".
            $domain = strtolower(trim((string)($row['domain'] ?? ''), " \t\n\r\0\x0B."));
            $engine = strtolower(trim((string)($row['engine'] ?? '')));
            if ($domain === '') {
                continue;
            }
            $domains[$domain] = $engine !== '' ? $engine : $domain;
        }

        return $this->searchEngineDomains = $domains;
    }

    /**
     * Decodes one ArraySerialized config value into its list of rows.
     *
     * A malformed value yields an empty list plus a critical log entry rather
     * than an exception: this runs on every ingested event, and a broken
     * config row must not take the consumer down. The cost is that
     * classification silently degrades (everything falls through to
     * direct/referral), which is why it logs at critical.
     *
     * @return array<array<string, mixed>>
     */
    private function readRows(string $path): array
    {
        $raw = $this->scopeConfig->getValue($path);
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        try {
            $decoded = $this->json->unserialize((string)$raw);
        } catch (\InvalidArgumentException $e) {
            $this->logger->critical(
                sprintf(
                    'Aavirbhava_AdsAnalytics: config value at "%s" is not valid JSON, '
                    . 'traffic classification will degrade. Error: %s',
                    $path,
                    $e->getMessage()
                )
            );
            return [];
        }

        return is_array($decoded) ? array_filter($decoded, 'is_array') : [];
    }

    /**
     * @return string[] lowercased, trimmed, empties dropped
     */
    private function splitList(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        $parts = array_map(
            static fn ($v): string => strtolower(trim((string)$v)),
            explode(',', $csv)
        );

        return array_values(array_filter($parts, static fn ($v): bool => $v !== ''));
    }
}
