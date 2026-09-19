<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Observer;

use Aavirbhava\AdsAnalytics\Model\Service\ServerEventDispatcher;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

/**
 * P2-T4: sales_model_service_quote_submit_success -> order_placed funnel
 * event, which the
 * queue consumer turns into an ads_analytics_order_attribution row.
 *
 * NOT sales_order_place_after, despite that being the event docs/SPECS.md
 * originally named. That one is dispatched from inside Order::place(), before
 * the order is persisted, so getId() returns null and there is no order id to
 * attribute — the observer would silently drop every order.
 * This event fires immediately after OrderManagement::place() has persisted
 * the order, from a code path reached by both the frontend/REST placeOrder()
 * and the lower-level submit() that integrations call.
 *
 * This observer does NOT write the attribution row itself. Placing an order
 * is the most latency-sensitive request in the store, and CLAUDE.md #3
 * forbids synchronous DB writes on a customer-facing request with no
 * exceptions. It publishes and returns; the consumer does the writing.
 *
 * order_id is taken from the placed order here, server-side, and travels as
 * entity_id. It is never accepted from a client: `order_placed` is in
 * EventIngestService::SERVER_ONLY_EVENT_TYPES, so the public endpoint drops
 * it and only ServerEventDispatcher's trusted path can raise one
 * (docs/SECURITY.md §3).
 *
 * Orders raised outside a storefront checkout — admin, API, cron/reorder —
 * either do not fire this event at all or carry no visitor cookie, so
 * ServerEventDispatcher finds no visitor and drops them. That is correct: an
 * admin-created order has no ad click behind it and must not be attributed to
 * whoever the admin last browsed as.
 */
class OrderPlaceAfterObserver implements ObserverInterface
{
    private ServerEventDispatcher $dispatcher;

    public function __construct(ServerEventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function execute(EventObserver $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if ($order === null || !method_exists($order, 'getId') || !$order->getId()) {
            return;
        }

        $this->dispatcher->dispatch('order_placed', (int)$order->getId());
    }
}
