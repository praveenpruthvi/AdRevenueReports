<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Retention windows for Cron\PurgeOldData (P3-T7, docs/SECURITY.md §8).
 *
 * Read at default scope only, unlike IngestConfig. A purge is a DELETE
 * against whole tables; those tables have no store_id column, so there is no
 * coherent meaning to "retain 30 days on website A and 180 on website B" —
 * whichever scope the cron happened to resolve would silently govern every
 * store's data. The system.xml fields are marked showInWebsite for historical
 * reasons; this class deliberately ignores that and reads the default value.
 */
class RetentionConfig
{
    private const XML_PATH_EVENT_RETENTION_DAYS = 'aavirbhava_adsanalytics/retention/event_retention_days';
    private const XML_PATH_REQUEST_LOG_RETENTION_DAYS = 'aavirbhava_adsanalytics/logging/request_log_retention_days';

    /**
     * Returned when the value is absent or unparseable — NOT when it is zero
     * or negative. See getEventRetentionDays() for why those are different.
     */
    private const FALLBACK_EVENT_RETENTION_DAYS = 180;
    private const FALLBACK_REQUEST_LOG_RETENTION_DAYS = 14;

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Days of raw per-visitor data to keep, or 0 meaning "never purge".
     *
     * ZERO IS NOT A CUTOFF. A window of 0 would make the cutoff `now`, and
     * the purge would delete every row in the visit table on its next run —
     * cascading to every funnel event and every order attribution with it.
     * An admin who clears the field, or a fresh install whose config row is
     * missing, must not destroy the dataset, so 0 and negatives are mapped to
     * "disabled" and the caller skips the delete entirely. The same reasoning
     * applies to getRequestLogRetentionDays().
     */
    public function getEventRetentionDays(): int
    {
        return $this->readDays(self::XML_PATH_EVENT_RETENTION_DAYS, self::FALLBACK_EVENT_RETENTION_DAYS);
    }

    /**
     * Deliberately a separate config path from getEventRetentionDays(), and
     * much shorter by default (14 days vs 180): ads_analytics_request_log
     * stores raw unvalidated request payloads, which is attacker-controllable
     * text rather than derived analytics (docs/SECURITY.md §8).
     */
    public function getRequestLogRetentionDays(): int
    {
        return $this->readDays(
            self::XML_PATH_REQUEST_LOG_RETENTION_DAYS,
            self::FALLBACK_REQUEST_LOG_RETENTION_DAYS
        );
    }

    /**
     * Distinguishes "not configured" (use the shipped default) from
     * "configured to 0" (disabled). getValue() returns null only in the
     * former case, so the null check must come before the int cast — casting
     * first would turn null into 0 and silently disable the purge instead of
     * applying the default.
     */
    private function readDays(string $path, int $fallback): int
    {
        $raw = $this->scopeConfig->getValue($path);

        if ($raw === null || $raw === '') {
            return $fallback;
        }

        $days = (int)$raw;

        return $days > 0 ? $days : 0;
    }
}
