<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config\Source;

use Aavirbhava\AdsAnalytics\Model\Service\TrafficResolver;
use Aavirbhava\AdsAnalytics\Model\Service\VisitManager;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * traffic_type filter options for the report grid (P3-T5, docs/SPECS.md §8).
 *
 * Sourced from TrafficResolver's own constants so the filter cannot drift
 * from what the classifier actually produces. 'unknown' is included because
 * it is a real stored value — a visit created by a funnel event that arrived
 * before its landing — and being able to filter for it is how you find
 * attribution gaps.
 */
class TrafficType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => TrafficResolver::TYPE_PAID, 'label' => __('Paid')],
            ['value' => TrafficResolver::TYPE_ORGANIC, 'label' => __('Organic')],
            ['value' => TrafficResolver::TYPE_REFERRAL, 'label' => __('Referral')],
            ['value' => TrafficResolver::TYPE_DIRECT, 'label' => __('Direct')],
            ['value' => VisitManager::TRAFFIC_TYPE_UNKNOWN, 'label' => __('Unknown (unclassified)')],
        ];
    }
}
