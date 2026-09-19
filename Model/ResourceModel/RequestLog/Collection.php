<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(
            \Aavirbhava\AdsAnalytics\Model\RequestLog::class,
            \Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog::class
        );
    }
}
