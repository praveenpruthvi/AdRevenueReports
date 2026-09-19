<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Block\Frontend;

use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * Supplies the storefront beacon with its configuration (P1-T7).
 *
 * WHY A BLOCK AND NOT A CONFIG ENDPOINT: the original scaffold proposed the
 * beacon fetch a config endpoint "once, cached client-side". That would add a
 * blocking network round trip before the first event could be sent, on every
 * cold page load, for data the server already has while rendering the page.
 * Emitting it inline costs nothing and removes a failure mode.
 *
 * WHY THE CLICK-ID LIST IS PASSED DOWN: the beacon has to know which query
 * parameters count as click-ids in order to name one in the payload. That
 * list is config (docs/SPECS.md §2), and hardcoding it in JavaScript would
 * reintroduce exactly the platform-specific code CLAUDE.md #2 forbids —
 * adding Pinterest would then mean editing a .js file. Note the beacon is
 * told the parameter NAMES only; it is never told which platform they map to
 * and never classifies anything. TrafficResolver still re-derives
 * traffic_type, platform_code, source and medium server-side.
 */
class BeaconConfig extends Template
{
    private const XML_PATH_ENABLED = 'aavirbhava_adsanalytics/general/enabled';
    private const XML_PATH_COOKIE_LIFETIME = 'aavirbhava_adsanalytics/general/cookie_lifetime_days';

    private const DEFAULT_COOKIE_LIFETIME_DAYS = 90;

    private TrafficClassificationConfig $classificationConfig;
    private Json $json;
    private CookieHelper $cookieHelper;

    public function __construct(
        Context $context,
        TrafficClassificationConfig $classificationConfig,
        Json $json,
        CookieHelper $cookieHelper,
        array $data = []
    ) {
        $this->classificationConfig = $classificationConfig;
        $this->json = $json;
        $this->cookieHelper = $cookieHelper;
        parent::__construct($context, $data);
    }

    /**
     * Rendering is skipped entirely when tracking is off, so a disabled
     * module ships no beacon markup at all rather than an inert script.
     */
    protected function _toHtml(): string
    {
        if (!$this->isTrackingEnabled()) {
            return '';
        }

        return parent::_toHtml();
    }

    public function isTrackingEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * JSON handed to the beacon. Deliberately minimal: an endpoint, a cookie
     * name and lifetime, and the click-id parameter names to look for.
     */
    public function getBeaconConfigJson(): string
    {
        return $this->json->serialize([
            'endpoint' => $this->getIngestEndpoint(),
            'cookieName' => 'aavirbhava_visitor_uuid',
            'cookieLifetimeDays' => $this->getCookieLifetimeDays(),
            'clickIdParams' => $this->getClickIdParams(),
            // P1-T8: when Magento's own cookie-restriction mode is on, the
            // storefront must not set a non-essential cookie until the
            // visitor accepts. Third-party consent modules are a separate
            // integration — see docs/TASKS.md P1-T8.
            'requireCookieConsent' => $this->isCookieRestrictionModeEnabled(),
        ]);
    }

    /**
     * Built from the base URL rather than getUrl(): getUrl() parses its
     * argument as route/controller/action, so 'rest/V1/adsanalytics/event'
     * came back as ".../rest/V1/adsanalytics/" with the action segment
     * silently dropped, pointing the beacon at a non-existent endpoint.
     *
     * URL_TYPE_WEB, not URL_TYPE_LINK: the latter prepends the store code
     * when "Add Store Code to URLs" is on, which would produce
     * /<store>/rest/V1/... and 404. REST resolves the store itself.
     */
    private function getIngestEndpoint(): string
    {
        return $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB)
            . 'rest/V1/adsanalytics/event';
    }

    /**
     * @return string[] click-id parameter names only — never platform codes
     */
    private function getClickIdParams(): array
    {
        $params = [];
        foreach ($this->classificationConfig->getPlatformMap() as $platform) {
            $params[] = $platform['click_id_param'];
        }

        return array_values(array_unique($params));
    }

    private function getCookieLifetimeDays(): int
    {
        $days = (int)$this->_scopeConfig->getValue(
            self::XML_PATH_COOKIE_LIFETIME,
            ScopeInterface::SCOPE_STORE
        );

        return $days > 0 ? $days : self::DEFAULT_COOKIE_LIFETIME_DAYS;
    }

    /**
     * Delegates to Magento\Cookie\Helper\Cookie rather than reading the
     * config path directly. The path is `web/cookie/cookie_restriction` —
     * this class originally guessed `..._enabled`, which silently always
     * resolved to false, so the consent gate would never have fired on a
     * store that had restriction mode switched on. Using core's own accessor
     * removes the chance of that drifting again.
     *
     * Note this reports whether consent is REQUIRED (store config, safe to
     * bake into a full-page-cached response), NOT whether this visitor has
     * given it. The latter is per-user state and must stay a runtime cookie
     * read in the beacon, or FPC would serve one visitor's consent decision
     * to everybody.
     */
    private function isCookieRestrictionModeEnabled(): bool
    {
        return (bool)$this->cookieHelper->isCookieRestrictionModeEnabled();
    }
}
