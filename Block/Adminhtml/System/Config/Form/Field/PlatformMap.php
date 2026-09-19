<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Editable platform / click-id map (docs/SPECS.md §2, P1-T3).
 *
 * Adding a new ad platform must be a row here and nothing else — no class in
 * this module may name a platform (CLAUDE.md #2). That is why `default_source`
 * and `alt_sources` are columns rather than conditionals: the facebook vs.
 * instagram distinction is data, not code.
 *
 * Columns:
 *   platform_code   — the stable, closed-set identifier persisted to
 *                     ads_analytics_visit.platform_code. Never derived from
 *                     user input; only ever one of these keys.
 *   click_id_param  — the query parameter that marks a paid click from this
 *                     platform (gclid, fbclid, ...). This is the ONLY signal
 *                     that drives paid classification, so the consumer
 *                     validates the client's click_id_param against this
 *                     column before trusting it (docs/SECURITY.md §2).
 *   default_source  — what `source` becomes when this platform matches and no
 *                     alt_source applies (meta -> facebook).
 *   alt_sources     — comma-separated utm_source values that override
 *                     default_source when present. Meta ships "instagram"
 *                     here, which is how an Instagram click is distinguished
 *                     from a Facebook one without a single platform name
 *                     appearing in TrafficResolver.
 */
class PlatformMap extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('platform_code', [
            'label' => __('Platform Code'),
            'class' => 'required-entry validate-code',
        ]);
        $this->addColumn('click_id_param', [
            'label' => __('Click ID Parameter'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('default_source', [
            'label' => __('Default Source'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('alt_sources', [
            'label' => __('Alt Sources (utm_source, comma-separated)'),
            'class' => '',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Platform');
    }
}
