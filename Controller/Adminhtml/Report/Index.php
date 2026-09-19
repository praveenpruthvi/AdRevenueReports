<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * P3-T3: dashboard page — tabs for Dashboard (funnel/breakdown charts,
 * P3-T4) and Grid (filterable listing, P3-T5), matching the tab pattern
 * used in Aavirbhava_SalesAnalytics for UI/UX consistency.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AdsAnalytics::dashboard';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Aavirbhava_AdsAnalytics::report_dashboard');
        $resultPage->getConfig()->getTitle()->prepend(__('Ads Analytics'));

        // TODO(P3-T3): layout handle ads_analytics_report_index.xml renders
        // the tab container; Block\Adminhtml\Dashboard supplies the data for
        // the charts (P3-T4) once the daily-summary table is populated.

        return $resultPage;
    }
}
