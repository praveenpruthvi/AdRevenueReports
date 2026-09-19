<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Aavirbhava\AdsAnalytics\Model\Visit;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Writes ads_analytics_order_attribution — the row that finally connects an
 * ad click to revenue (P2-T4, docs/SPECS.md §3/§4).
 *
 * WHICH TOUCH GETS THE CREDIT is a business decision, not a technical one, so
 * it comes from config (`general/attribution_model`, default last_touch):
 *   - last_touch  credits the ad that closed the sale
 *   - first_touch credits the ad that acquired the customer
 * The chosen model is stored ON the row, so a later config change does not
 * silently reinterpret history: rows already written keep saying which rule
 * produced them.
 *
 * This runs in the QUEUE CONSUMER, not in the order-place observer — see
 * Observer\OrderPlaceAfterObserver for why. CLAUDE.md #3 forbids DB writes on
 * the customer-facing request, and placing an order is the most
 * latency-sensitive request in the store.
 */
class OrderAttributionWriter
{
    private const XML_PATH_ATTRIBUTION_MODEL = 'aavirbhava_adsanalytics/general/attribution_model';

    public const MODEL_FIRST_TOUCH = 'first_touch';
    public const MODEL_LAST_TOUCH = 'last_touch';

    private ResourceConnection $resource;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Written with insertOnDuplicate rather than through the model/resource
     * ORM, deliberately.
     *
     * order_id is this table's primary key AND an id we supply, not an
     * auto-increment. AbstractDb handles that badly in both directions:
     *   - with the default _useIsObjectNew = false, isObjectNotNew() is just
     *     "getId() !== null", so having an id makes it UPDATE ... WHERE
     *     order_id = <id>, match zero rows, and return successfully having
     *     written nothing;
     *   - with _useIsObjectNew = true it INSERTs, but saveNewObject() unsets
     *     the id field first (assuming auto-increment), so the row lands with
     *     order_id = 0.
     * Both failures are silent. One SQL statement avoids the whole class of
     * problem.
     *
     * It is also exactly the semantics an at-least-once queue needs: AMQP
     * will redeliver eventually, and a redelivery must update the existing
     * row rather than duplicate or fail on it.
     */
    public function write(int $orderId, Visit $visit): void
    {
        $model = $this->getAttributionModel();
        $prefix = $model === self::MODEL_FIRST_TOUCH ? 'first_touch_' : 'last_touch_';

        $data = [
            'order_id' => $orderId,
            'visit_id' => (int)$visit->getId(),
            'attribution_model' => $model,
            // Copied from the visit, never recomputed here: the visit is the
            // single source of truth for how this visitor arrived.
            'traffic_type' => (string)$visit->getData('traffic_type'),
            'source' => $visit->getData($prefix . 'source'),
            'medium' => $visit->getData($prefix . 'medium'),
            'campaign' => $visit->getData($prefix . 'campaign'),
            // platform_code is not first/last-touch scoped — the visit holds
            // only the most recent one, which is the same value last_touch
            // attribution wants and the closest available for first_touch.
            'platform_code' => $visit->getData('platform_code'),
        ];

        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName('ads_analytics_order_attribution'),
            $data,
            // Everything except the key, so a redelivery refreshes the row.
            ['visit_id', 'attribution_model', 'traffic_type', 'source', 'medium', 'campaign', 'platform_code']
        );
    }

    private function getAttributionModel(): string
    {
        $model = (string)$this->scopeConfig->getValue(self::XML_PATH_ATTRIBUTION_MODEL);

        return $model === self::MODEL_FIRST_TOUCH ? self::MODEL_FIRST_TOUCH : self::MODEL_LAST_TOUCH;
    }
}
