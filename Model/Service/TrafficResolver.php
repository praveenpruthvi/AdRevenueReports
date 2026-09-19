<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;

/**
 * Classifies a visit into traffic_type = paid | organic | direct | referral,
 * and resolves platform_code/source/medium/campaign alongside it
 * (docs/SPECS.md §2). This is the ONLY class that does this classification —
 * do not duplicate any part of it elsewhere (CLAUDE.md #2).
 *
 * Precedence (first match wins) — do not reorder without updating
 * docs/SPECS.md §2 first:
 *   1. paid     — known click-id param present, OR utm_medium is in the
 *                 configured paid-mediums list
 *   2. tagged   — utm_source and/or utm_medium present but didn't match
 *                 "paid" (e.g. a manually-tagged email/social link:
 *                 utm_medium=email). Use the UTM values as
 *                 source/medium/campaign rather than discarding them —
 *                 explicit tagging beats guessing from the referrer.
 *                 Bucketed as traffic_type=referral (v1 has no dedicated
 *                 "email"/"organic_social" channel — see PROJECT_PLAN.md's
 *                 Out of scope section).
 *   3. organic  — no click-id/utm signal at all, referrer hostname matches
 *                 the configured search-engine domain list at a label
 *                 boundary (not a raw substring — "notgoogle.com" must NOT
 *                 match "google")
 *   4. referral — no click-id/utm signal, no organic match, but a referrer
 *                 is present
 *   5. direct   — no click-id, no utm_*, no referrer at all
 *
 * platform_code is ALWAYS one of the keys of the configured platform map
 * (e.g. always "meta", never "instagram") — SECURITY.md's allow-list
 * validation depends on this staying a closed, stable set. Only `source`
 * disambiguates, via that platform's own `alt_sources` column: if the
 * incoming utm_source matches one of them, it becomes the source and
 * platform_code is left alone. No platform, medium or search engine is named
 * anywhere in this class (CLAUDE.md #2) — all three lists come from
 * Model\Config\TrafficClassificationConfig.
 *
 * Output conventions — three different "empty-ish" values, deliberately not
 * interchangeable:
 *   - `medium = 'none'` (direct traffic only) means a VERIFIED absence: this
 *     visit genuinely had no medium.
 *   - `source = self::SOURCE_NOT_SET` ('not_set') means this branch had no
 *     utm_source to use. It is NOT 'unknown': that value is reserved for
 *     ads_analytics_visit.traffic_type as a bug indicator ("the consumer
 *     failed to classify this row"), and reusing it for a routine fallback
 *     would hide real classification failures in normal traffic.
 *   - `'null_source'` never appears here — that is the aggregation cron's
 *     COALESCE sentinel for a NULL read back out of the raw tables (see
 *     Cron\AggregateDailySummary and ads_analytics_daily_summary's schema
 *     comment), which is a different fact from either of the above.
 *
 * `medium` is lowercased on output; `source`/`medium`/`campaign`/
 * `click_id_value` are truncated to their destination column widths with
 * mb_substr. See normalizeAndClamp().
 *
 * All three lists (platform/click-id map, paid mediums, search-engine
 * domains) are injected via Model\Config\TrafficClassificationConfig, which
 * reads etc/adminhtml/system.xml with defaults from etc/config.xml. Adding an
 * ad platform or a search engine is a config row and nothing else — if you
 * find yourself editing this class to support one, that is the bug.
 */
class TrafficResolver
{
    public const TYPE_PAID = 'paid';
    public const TYPE_ORGANIC = 'organic';
    public const TYPE_DIRECT = 'direct';
    public const TYPE_REFERRAL = 'referral';

    /**
     * Sentinel for "this branch produced no source value". Deliberately NOT
     * 'unknown': that string is reserved for ads_analytics_visit.traffic_type,
     * where it is a bug indicator meaning "the consumer failed to classify
     * this row at all". Reusing it here — as an ordinary, expected fallback
     * on a correctly-classified visit — would make a real classification
     * failure indistinguishable from a normal untagged link.
     */
    public const SOURCE_NOT_SET = 'not_set';

    /** Matches ads_analytics_daily_summary column widths (etc/db_schema.xml) — truncate before returning, never let the DB do it silently. */
    private const MAX_SOURCE_LENGTH = 64;
    private const MAX_MEDIUM_LENGTH = 64;
    private const MAX_CAMPAIGN_LENGTH = 128;

    /** Matches ads_analytics_visit.click_id_value width (etc/db_schema.xml). */
    private const MAX_CLICK_ID_VALUE_LENGTH = 128;

    private TrafficClassificationConfig $config;

    public function __construct(TrafficClassificationConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string,string|null> $queryParams e.g. $_GET-style array from the landing URL (utm_* included)
     * @param string|null $referrer full document.referrer as sent by the client; this method reads only its host
     * @return array{
     *     traffic_type: string,
     *     platform_code: ?string,
     *     click_id_param: ?string,
     *     click_id_value: ?string,
     *     source: ?string,
     *     medium: ?string,
     *     campaign: ?string
     * }
     */
    public function resolve(array $queryParams, ?string $referrer): array
    {
        $paid = $this->resolvePaid($queryParams);
        if ($paid !== null) {
            return $this->normalizeAndClamp($paid);
        }

        // Explicit UTM tagging beats guessing from the referrer, even when
        // it didn't match "paid" — e.g. a newsletter link tagged
        // utm_source=newsletter&utm_medium=email. Gate on EITHER utm_source
        // OR utm_medium being present, not just utm_source, so a
        // utm_medium-only link doesn't fall through and lose its tagging.
        $utmSource = $queryParams['utm_source'] ?? null;
        $utmMedium = $queryParams['utm_medium'] ?? null;
        if (!empty($utmSource) || !empty($utmMedium)) {
            return $this->normalizeAndClamp([
                'traffic_type' => self::TYPE_REFERRAL,
                'platform_code' => null,
                'click_id_param' => null,
                'click_id_value' => null,
                'source' => !empty($utmSource) ? (string)$utmSource : self::SOURCE_NOT_SET,
                'medium' => !empty($utmMedium) ? (string)$utmMedium : 'referral',
                'campaign' => !empty($queryParams['utm_campaign']) ? (string)$queryParams['utm_campaign'] : null,
            ]);
        }

        $referrerHost = $this->extractHost($referrer);

        if ($referrerHost !== null) {
            $engine = $this->matchSearchEngine($referrerHost);
            if ($engine !== null) {
                return $this->normalizeAndClamp([
                    'traffic_type' => self::TYPE_ORGANIC,
                    'platform_code' => null,
                    'click_id_param' => null,
                    'click_id_value' => null,
                    'source' => $engine,
                    'medium' => 'organic',
                    'campaign' => null,
                ]);
            }

            return $this->normalizeAndClamp([
                'traffic_type' => self::TYPE_REFERRAL,
                'platform_code' => null,
                'click_id_param' => null,
                'click_id_value' => null,
                'source' => $referrerHost,
                'medium' => 'referral',
                'campaign' => null,
            ]);
        }

        return $this->normalizeAndClamp([
            'traffic_type' => self::TYPE_DIRECT,
            'platform_code' => null,
            'click_id_param' => null,
            'click_id_value' => null,
            'source' => 'direct',
            'medium' => 'none',
            'campaign' => null,
        ]);
    }

    /**
     * @param array<string,string|null> $queryParams
     * @return array{traffic_type: string, platform_code: ?string, click_id_param: ?string, click_id_value: ?string, source: ?string, medium: ?string, campaign: ?string}|null
     */
    private function resolvePaid(array $queryParams): ?array
    {
        $utmSource = $queryParams['utm_source'] ?? null;
        $utmCampaign = $queryParams['utm_campaign'] ?? null;
        $utmSourceNormalised = $utmSource !== null ? strtolower(trim((string)$utmSource)) : '';

        foreach ($this->config->getPlatformMap() as $platformCode => $platform) {
            if (empty($queryParams[$platform['click_id_param']])) {
                continue;
            }

            // platform_code ALWAYS stays the config map key (e.g. "meta") —
            // never overwritten with "instagram". Only `source` disambiguates,
            // via the platform's own alt_sources list. There is deliberately
            // no platform name in this method: which utm_source values are
            // alternatives for which platform is data, not code (CLAUDE.md #2).
            $source = $platform['default_source'];
            if ($utmSourceNormalised !== '' && in_array($utmSourceNormalised, $platform['alt_sources'], true)) {
                $source = $utmSourceNormalised;
            }

            return [
                'traffic_type' => self::TYPE_PAID,
                'platform_code' => $platformCode,
                'click_id_param' => $platform['click_id_param'],
                'click_id_value' => (string)$queryParams[$platform['click_id_param']],
                'source' => $source,
                'medium' => 'cpc',
                'campaign' => $utmCampaign !== null ? (string)$utmCampaign : null,
            ];
        }

        $medium = $queryParams['utm_medium'] ?? null;
        if ($medium !== null && in_array(strtolower(trim((string)$medium)), $this->config->getPaidMediums(), true)) {
            // Manually-tagged paid link with no click id — no platform_code,
            // since we can't map utm_source to a known ad platform reliably.
            return [
                'traffic_type' => self::TYPE_PAID,
                'platform_code' => null,
                'click_id_param' => null,
                'click_id_value' => null,
                'source' => $utmSource !== null ? (string)$utmSource : self::SOURCE_NOT_SET,
                'medium' => (string)$medium,
                'campaign' => $utmCampaign !== null ? (string)$utmCampaign : null,
            ];
        }

        return null;
    }

    private function extractHost(?string $referrer): ?string
    {
        if (empty($referrer)) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        return $host !== false && $host !== null ? strtolower($host) : null;
    }

    /**
     * Label-boundary match, NOT a raw substring match — "notgoogle.com" and
     * "evil-bing.com.attacker.net" must NOT match. A host matches a
     * registrable domain if it equals that domain exactly, or ends with
     * ".<domain>".
     */
    private function matchSearchEngine(string $host): ?string
    {
        foreach ($this->config->getSearchEngineDomains() as $domain => $engineName) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $engineName;
            }
        }

        return null;
    }

    /**
     * @param array{traffic_type: string, platform_code: ?string, click_id_param: ?string, click_id_value: ?string, source: ?string, medium: ?string, campaign: ?string} $result
     * @return array{traffic_type: string, platform_code: ?string, click_id_param: ?string, click_id_value: ?string, source: ?string, medium: ?string, campaign: ?string}
     */
    private function normalizeAndClamp(array $result): array
    {
        // Lowercase medium on the way OUT, not just for the paid-mediums
        // comparison. utm_medium casing is wildly inconsistent in the wild
        // ("CPC", "Cpc", "cpc"), and an un-normalised medium becomes a
        // separate grouping key in Cron\AggregateDailySummary — two summary
        // rows for one logical medium if the cron groups in PHP, or a
        // unique-key collision that overwrites rather than sums if it groups
        // in SQL under a case-insensitive collation.
        if ($result['medium'] !== null) {
            $result['medium'] = mb_strtolower($result['medium']);
        }

        // mb_substr, NOT substr: these values are attacker/marketer-supplied
        // and routinely non-ASCII (campaign names in Hindi, Arabic, accented
        // Latin, emoji). Byte-wise substr can cut a multi-byte character in
        // half, producing invalid UTF-8 that MySQL rejects outright in strict
        // mode — and it also over-truncates, since the column widths below
        // are character counts, not byte counts.
        if ($result['source'] !== null) {
            $result['source'] = mb_substr($result['source'], 0, self::MAX_SOURCE_LENGTH);
        }
        if ($result['medium'] !== null) {
            $result['medium'] = mb_substr($result['medium'], 0, self::MAX_MEDIUM_LENGTH);
        }
        if ($result['campaign'] !== null) {
            $result['campaign'] = mb_substr($result['campaign'], 0, self::MAX_CAMPAIGN_LENGTH);
        }
        if ($result['click_id_value'] !== null) {
            $result['click_id_value'] = mb_substr(
                $result['click_id_value'],
                0,
                self::MAX_CLICK_ID_VALUE_LENGTH
            );
        }

        return $result;
    }
}
