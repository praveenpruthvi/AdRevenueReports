<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\Source\LoggingLevel;
use Aavirbhava\AdsAnalytics\Model\Service\TrafficResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Request-log settings (docs/SPECS.md §9), read by the queue consumer.
 *
 * Default scope, for the same reason as TrafficClassificationConfig: the
 * consumer runs in its own process with no store context.
 */
class RequestLogConfig
{
    private const XML_PATH_LOGGING_LEVEL = 'aavirbhava_adsanalytics/logging/logging_level';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function getLoggingLevel(): string
    {
        $level = (string)$this->scopeConfig->getValue(self::XML_PATH_LOGGING_LEVEL);

        return $level !== '' ? $level : LoggingLevel::REJECTED_ONLY;
    }

    /**
     * Whether a log row should be written at all.
     *
     * Called BEFORE validation, when the outcome is not yet known — so at
     * 'rejected_only' we must still write the row optimistically and delete
     * it again if the event turns out to be accepted. Writing only after
     * validation would lose the row for any event that crashes the consumer
     * mid-validation, which is precisely the case the log exists for.
     */
    public function shouldLogBeforeValidation(): bool
    {
        return $this->getLoggingLevel() !== LoggingLevel::OFF;
    }

    /**
     * Traffic types that reached the store from somewhere else. Anything not
     * in this list arrived without an external origin, so its URL cannot
     * carry a click id, a utm tag or a referrer the module failed to
     * recognise.
     */
    private const EXTERNAL_TRAFFIC_TYPES = [
        TrafficResolver::TYPE_PAID,
        TrafficResolver::TYPE_ORGANIC,
        TrafficResolver::TYPE_REFERRAL,
    ];

    /**
     * Whether a row that turned out to be ACCEPTED should be kept.
     *
     * At 'rejected_only' it is discarded once we know it was fine. At
     * 'external_only' it is kept only when the event was a landing that
     * resolved to an external traffic type.
     *
     * A null $trafficType means the event was not a landing — a product view
     * or a checkout step — and is therefore internal navigation. Those are
     * discarded at 'external_only' too: their URL is the store's own page,
     * reached from the store's own pages, so it can never reveal a source
     * the classifier missed. They are still kept at 'all', which remains the
     * setting to use when debugging the funnel itself rather than
     * acquisition.
     *
     * @param string|null $trafficType what TrafficResolver decided, or null
     *                                 when the event was not a landing
     */
    public function shouldKeepAcceptedRow(?string $trafficType = null): bool
    {
        $level = $this->getLoggingLevel();

        if ($level === LoggingLevel::ALL) {
            return true;
        }

        if ($level === LoggingLevel::EXTERNAL_ONLY) {
            return $trafficType !== null
                && in_array($trafficType, self::EXTERNAL_TRAFFIC_TYPES, true);
        }

        return false;
    }
}
