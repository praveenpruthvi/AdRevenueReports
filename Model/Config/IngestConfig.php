<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Ingest-endpoint settings for Model\EventIngestService (P1-T5).
 *
 * Separate from TrafficClassificationConfig on purpose: that one is read by
 * the queue consumer at classification time and is deliberately default-scope,
 * whereas everything here is read on the live storefront request, where a
 * store scope genuinely exists and a merchant may reasonably want a different
 * kill switch or sampling rate per website.
 */
class IngestConfig
{
    private const XML_PATH_ENABLED = 'aavirbhava_adsanalytics/general/enabled';
    private const XML_PATH_SAMPLING_RATE = 'aavirbhava_adsanalytics/general/sampling_rate';
    private const XML_PATH_RATE_LIMIT_MAX = 'aavirbhava_adsanalytics/rate_limit/max_requests';
    private const XML_PATH_RATE_LIMIT_WINDOW = 'aavirbhava_adsanalytics/rate_limit/window_seconds';

    /**
     * Used only when config is missing or nonsensical (0 or negative), which
     * would otherwise mean "reject everything" for the limit and "divide by
     * zero" for the window. Tracking must fail open, not closed.
     */
    private const FALLBACK_RATE_LIMIT_MAX = 120;
    private const FALLBACK_RATE_LIMIT_WINDOW = 60;

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Master kill switch. When off, the endpoint accepts the request and
     * discards it — it does not error, because the beacon is already deployed
     * on every page and a disabled module must not start returning failures
     * to the storefront.
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Percentage of events to keep, 1-100. Values outside that range are
     * clamped rather than trusted: a stray 0 would silently disable tracking
     * store-wide, and anything above 100 is meaningless.
     */
    public function getSamplingRate(): int
    {
        $rate = (int)$this->scopeConfig->getValue(self::XML_PATH_SAMPLING_RATE, ScopeInterface::SCOPE_STORE);

        if ($rate < 1) {
            return 100;
        }

        return min($rate, 100);
    }

    public function getRateLimitMaxRequests(): int
    {
        $max = (int)$this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT_MAX, ScopeInterface::SCOPE_STORE);

        return $max > 0 ? $max : self::FALLBACK_RATE_LIMIT_MAX;
    }

    public function getRateLimitWindowSeconds(): int
    {
        $window = (int)$this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT_WINDOW, ScopeInterface::SCOPE_STORE);

        return $window > 0 ? $window : self::FALLBACK_RATE_LIMIT_WINDOW;
    }
}
