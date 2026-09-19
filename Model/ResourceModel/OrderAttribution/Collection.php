<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\ResourceModel\OrderAttribution;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(
            \Aavirbhava\AdsAnalytics\Model\OrderAttribution::class,
            \Aavirbhava\AdsAnalytics\Model\ResourceModel\OrderAttribution::class
        );
    }
}
