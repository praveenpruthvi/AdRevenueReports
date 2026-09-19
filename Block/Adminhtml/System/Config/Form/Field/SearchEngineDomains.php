<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Editable organic search-engine domain list (docs/SPECS.md §2, P1-T3).
 *
 * `domain` is a registrable domain with NO leading dot and NO wildcard —
 * "google.com", not ".google.com" or "google.*". TrafficResolver matches it at
 * a label boundary (host equals the domain, or ends with "." + domain), so
 * "notgoogle.com" correctly does NOT match. Because the match is by label,
 * subdomains DO match: "mail.google.com" resolves to organic google. That
 * behaviour is still an open decision — see docs/TESTING.md §1.
 *
 * `engine` is the friendly name persisted as `source` on an organic visit.
 * Several domains can map to one engine (yandex.com and yandex.ru both ->
 * yandex); that is the intended way to cover a multi-TLD search engine, since
 * wildcards are deliberately not supported.
 */
class SearchEngineDomains extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('domain', [
            'label' => __('Registrable Domain'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('engine', [
            'label' => __('Engine Name (stored as source)'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Search Engine');
    }
}
