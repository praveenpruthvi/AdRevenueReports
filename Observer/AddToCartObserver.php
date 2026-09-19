<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Observer;

use Aavirbhava\AdsAnalytics\Model\Service\ServerEventDispatcher;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

/**
 * P2-T2: checkout_cart_product_add_after -> add_to_cart funnel event.
 *
 * Emitted server-side rather than from the beacon because add-to-cart can
 * happen without a page load the beacon would see — an AJAX add from a
 * category page, a grouped/configurable product, a re-order. Observing the
 * cart is the only way to catch all of them.
 *
 * There is no double-counting risk: the beacon sends landing/product_view
 * only and never emits add_to_cart.
 *
 * Writes nothing itself. It hands the event to ServerEventDispatcher, which
 * routes it through the normal ingest path so it stays off the request's DB
 * budget (CLAUDE.md #3).
 */
class AddToCartObserver implements ObserverInterface
{
    private ServerEventDispatcher $dispatcher;

    public function __construct(ServerEventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function execute(EventObserver $observer): void
    {
        $productId = $this->resolveProductId($observer);

        $this->dispatcher->dispatch('add_to_cart', $productId);
    }

    /**
     * Prefer the quote item's product id over the event's `product`: for a
     * configurable product the event carries the parent, while the quote item
     * knows what actually went in the cart. Falls back to the product, then
     * to null — entity_id is nullable and a missing id must not cost us the
     * event.
     */
    private function resolveProductId(EventObserver $observer): ?int
    {
        $quoteItem = $observer->getEvent()->getData('quote_item');
        if ($quoteItem !== null && method_exists($quoteItem, 'getProductId') && $quoteItem->getProductId()) {
            return (int)$quoteItem->getProductId();
        }

        $product = $observer->getEvent()->getData('product');
        if ($product !== null && method_exists($product, 'getId') && $product->getId()) {
            return (int)$product->getId();
        }

        return null;
    }
}
