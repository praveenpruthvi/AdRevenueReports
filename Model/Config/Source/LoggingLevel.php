<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * docs/SPECS.md §9 — controls what Aavirbhava\AdsAnalytics\Model\EventIngestService
 * writes to ads_analytics_request_log.
 */
class LoggingLevel implements OptionSourceInterface
{
    public const OFF = 'off';
    public const REJECTED_ONLY = 'rejected_only';

    /**
     * Rejections, plus accepted landings that came from somewhere OUTSIDE the
     * store — paid, organic or referral. Direct arrivals are dropped, as are
     * internal funnel events, neither of which carries an inbound URL worth
     * inspecting: a direct visit has no referrer and no campaign parameters
     * by definition, so its URL can never reveal a source the module failed
     * to classify, which is the whole reason this log records URLs.
     */
    public const EXTERNAL_ONLY = 'external_only';

    public const ALL = 'all';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFF, 'label' => __('Off')],
            ['value' => self::REJECTED_ONLY, 'label' => __('Rejected Only')],
            ['value' => self::EXTERNAL_ONLY, 'label' => __('Rejected + Externally Referred (paid / organic / referral)')],
            ['value' => self::ALL, 'label' => __('All Requests')],
        ];
    }
}
