<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Filter options for the Request Log grid's validation_status column
 * (P3-T5b). Mirrors the enum on ads_analytics_request_log.validation_status
 * in etc/db_schema.xml — keep the two in step.
 */
class ValidationStatus implements OptionSourceInterface
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    public function toOptionArray(): array
    {
        return [
            // 'pending' is the column default: the row is inserted before
            // validation runs. A row still showing pending means the consumer
            // died mid-message, so it is worth being able to filter for.
            ['value' => self::PENDING, 'label' => __('Pending')],
            ['value' => self::ACCEPTED, 'label' => __('Accepted')],
            ['value' => self::REJECTED, 'label' => __('Rejected')],
        ];
    }
}
