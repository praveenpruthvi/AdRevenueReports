<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Controller\Adminhtml\AdSpend;

use Aavirbhava\AdsAnalytics\Model\AdSpendProvider\AdSpendCsvUploader;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Handles the "Ad Spend Data" upload form on the report page
 * (view/adminhtml/templates/dashboard/overview.phtml) — an admin who has no
 * access to the server's filesystem can still get a spend CSV in front of
 * CsvAdSpendProvider this way, which is the whole reason this controller
 * exists rather than leaving the CSV as a file the admin has to be told to
 * place there by someone else.
 *
 * Gated on the same ACL resource as the module's settings
 * (Aavirbhava_AdsAnalytics::config), not the dashboard's own resource: this
 * writes data the report depends on, which is closer to "can change how this
 * module behaves" than "can view its reports". Block\Adminhtml\Dashboard
 * only renders the form for an admin who already holds this resource, so
 * nobody sees a form they are not allowed to submit.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AdsAnalytics::config';

    private const UPLOAD_FIELD = 'ad_spend_csv';

    private AdSpendCsvUploader $uploader;

    public function __construct(Context $context, AdSpendCsvUploader $uploader)
    {
        parent::__construct($context);
        $this->uploader = $uploader;
    }

    public function execute(): Redirect
    {
        $platformCode = (string)$this->getRequest()->getParam('platform_code');

        try {
            $uploadedFile = $this->getFileFromRequest();
            $result = $this->uploader->upload($platformCode, $uploadedFile);

            $this->messageManager->addSuccessMessage(
                $this->buildSuccessMessage($platformCode, $result)
            );
        } catch (LocalizedException $e) {
            // The uploader's exceptions are already written for an admin to
            // read (no class names, no file paths, no PHP details) — shown
            // verbatim rather than wrapped in a generic "upload failed".
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->error(
                'Aavirbhava_AdsAnalytics: ad-spend CSV upload failed unexpectedly: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->messageManager->addErrorMessage(
                __('Something went wrong saving that file. Please try again.')
            );
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('ads_analytics/report/index');
    }

    /**
     * @return array{name?: string, tmp_name?: string, error?: int, size?: int}
     */
    private function getFileFromRequest(): array
    {
        // Request::getFiles($name) returns the same shape as $_FILES[$name]
        // directly. An absent field (nothing chosen) comes back as an empty
        // default here, which AdSpendCsvUploader's own UPLOAD_ERR_NO_FILE
        // fallback turns into a clear message rather than a PHP notice.
        $field = $this->getRequest()->getFiles(self::UPLOAD_FIELD, []);

        return is_array($field) ? $field : [];
    }

    private function buildSuccessMessage(string $platformCode, array $result): \Magento\Framework\Phrase
    {
        if ($result['skippedRows'] > 0) {
            return __(
                'Saved %1 rows for "%2". %3 rows were skipped (see the log for details).',
                $result['savedRows'],
                $platformCode,
                $result['skippedRows']
            );
        }

        return __('Saved %1 rows for "%2".', $result['savedRows'], $platformCode);
    }
}
