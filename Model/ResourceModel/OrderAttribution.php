<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class OrderAttribution extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ads_analytics_order_attribution', 'order_id');
    }
}
