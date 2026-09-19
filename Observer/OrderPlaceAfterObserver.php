<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

/**
 * P2-T4: sales_order_place_after -> order_placed funnel event +
 * ads_analytics_order_attribution row.
 *
 * This is the ONLY place order_id/customer_id get linked to a visit —
 * resolved server-side from the order/session here, never accepted from a
 * client payload (docs/SECURITY.md §3).
 */
class OrderPlaceAfterObserver implements ObserverInterface
{
    public function execute(EventObserver $observer): void
    {
        // TODO(P2-T4):
        //   1. Get the placed order from $observer->getEvent()->getOrder().
        //   2. Resolve the visitor_uuid (cookie) for the current
        //      request/session and look up its ads_analytics_visit row.
        //   3. Write ads_analytics_order_attribution using the configured
        //      attribution_model (first_touch|last_touch, docs/SPECS.md §4).
        //   4. Publish an order_placed funnel event (same queue path as
        //      AddToCartObserver) with entity_id = order id.
    }
}
