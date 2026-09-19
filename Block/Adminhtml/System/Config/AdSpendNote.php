<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Ad Spend Data" field's label + comment as static text instead
 * of an input box (P4-T4 follow-up: there is no real config value here to
 * save — spend comes from a file, not a setting — so this field exists only
 * to make that file's location and format discoverable from the admin UI
 * itself, where a merchant would otherwise have no way to find it without
 * reading source code or docs).
 *
 * Identical in approach to core's own
 * Magento\Analytics\Block\Adminhtml\System\Config\AdditionalComment — that
 * class is not reused directly because it lives in Magento_Analytics, a
 * module this one has no dependency on and should not acquire one for a
 * single render() method.
 */
class AdSpendNote extends Field
{
    public function render(AbstractElement $element): string
    {
        $html = '<div class="config-additional-comment-title">' . $element->getLabel() . '</div>'
            . '<div class="config-additional-comment-content">' . $element->getComment() . '</div>';

        return sprintf(
            '<tr id="row_%s"><td colspan="3"><div class="config-additional-comment">%s</div></td></tr>',
            $element->getHtmlId(),
            $html
        );
    }
}
