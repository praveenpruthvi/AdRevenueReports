<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * docs/SPECS.md §4 — first_touch | last_touch.
 */
class AttributionModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'first_touch', 'label' => __('First Touch')],
            ['value' => 'last_touch', 'label' => __('Last Touch')],
        ];
    }
}
