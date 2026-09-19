<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Controller\Adminhtml\RequestLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * P3-T5b: read-only Request Log grid (docs/SPECS.md §9). Filter by
 * validation_status, date range, visitor_uuid; raw_payload must render
 * escaped (docs/SECURITY.md §2) — never bypass the UI Component's default
 * escaping for this column.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AdsAnalytics::request_log';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Aavirbhava_AdsAnalytics::request_log');
        $resultPage->getConfig()->getTitle()->prepend(__('Ads Analytics Request Log'));

        return $resultPage;
    }
}
