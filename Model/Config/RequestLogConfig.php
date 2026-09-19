<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\Source\LoggingLevel;
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
     * Whether a row that turned out to be ACCEPTED should be kept.
     * At 'rejected_only' it is discarded once we know it was fine.
     */
    public function shouldKeepAcceptedRow(): bool
    {
        return $this->getLoggingLevel() === LoggingLevel::ALL;
    }
}
