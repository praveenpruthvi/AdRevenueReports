<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Editable paid-mediums list (docs/SPECS.md §2, P1-T3).
 *
 * Covers manually-tagged paid links that carry no click id — e.g. a
 * newsletter-driven campaign tagged utm_medium=cpc. TrafficResolver matches
 * these case-insensitively, so "CPC" and "cpc" are the same row; there is no
 * need (and no benefit) to adding both.
 */
class PaidMediums extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('medium', [
            'label' => __('utm_medium Value'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Paid Medium');
    }
}
