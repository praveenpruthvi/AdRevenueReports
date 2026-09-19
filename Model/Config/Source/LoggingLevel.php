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
    public const ALL = 'all';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFF, 'label' => __('Off')],
            ['value' => self::REJECTED_ONLY, 'label' => __('Rejected Only')],
            ['value' => self::ALL, 'label' => __('All Requests')],
        ];
    }
}
