<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Entity model for the ads_analytics_daily_summary table (docs/SPECS.md §3/§9).
 */
class DailySummary extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Aavirbhava\AdsAnalytics\Model\ResourceModel\DailySummary::class);
    }
}
