<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

/**
 * P2-T2: checkout_cart_product_add_after -> add_to_cart funnel event.
 * Must not write to the DB synchronously here (CLAUDE.md async-first rule) —
 * publish to the same queue used by the frontend beacon instead, so this
 * event lands through the identical EventConsumer path.
 */
class AddToCartObserver implements ObserverInterface
{
    public function execute(EventObserver $observer): void
    {
        // TODO(P2-T2): resolve the current visitor_uuid (from the cookie via
        // the request), build an Api\Data\EventInterface with
        // event_type=add_to_cart and the added product's entity_id, and
        // publish it via the "aavirbhava.adsanalytics.event" topic — do not
        // call EventIngestService/EventConsumer methods directly, publish
        // exactly like the frontend beacon does, to keep one single path.
    }
}
