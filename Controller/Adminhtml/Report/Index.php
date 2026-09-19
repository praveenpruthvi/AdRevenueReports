<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * P3-T3: the report page. Renders charts (P3-T4) above a filterable grid
 * (P3-T5) on ONE page rather than the originally planned Dashboard/Grid
 * tabs, because the charts bind to the grid's own UI-Component data
 * provider so that filtering the grid redraws them — see
 * view/adminhtml/layout/ads_analytics_report_index.xml and PROGRESS.md's
 * "P3-T3/T4/T5 complete" entry for why. P4-T2 adds a PDF export of the
 * aggregated data, linked from the same page (Controller\Adminhtml\Report\Pdf).
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

        return $resultPage;
    }
}
