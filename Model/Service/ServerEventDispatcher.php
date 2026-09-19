<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterfaceFactory;
use Aavirbhava\AdsAnalytics\Model\EventIngestService;
use Psr\Log\LoggerInterface;

/**
 * Lets server-side observers emit funnel events through the SAME path the
 * storefront beacon uses (P2-T2, P2-T4).
 *
 * It deliberately calls the ingest service rather than publishing to the
 * queue directly. That keeps one single ingest path, so an observer
 * automatically inherits the kill switch, sampling, rate limiting, the
 * server-side ip_hash/user_agent capture and the publish-failure containment
 * — none of which it should be reimplementing. The alternative (publishing
 * straight to the topic) would quietly bypass all five.
 *
 * It depends on the CONCRETE Model\EventIngestService, not on
 * Api\EventIngestInterface, because it needs ingestFromServer() — the
 * trusted entry point that accepts server-only event types such as
 * order_placed. That method is intentionally absent from the Api interface so
 * etc/webapi.xml cannot expose it.
 *
 * Events emitted here carry NO attribution fields. Source, medium, campaign
 * and click id belong to the landing that started the visit; a cart or order
 * event only says "this visitor did this". The consumer resolves the visit by
 * visitor_uuid and attaches the funnel event to it.
 */
class ServerEventDispatcher
{
    private EventIngestService $ingestService;
    private EventInterfaceFactory $eventFactory;
    private VisitorCookie $visitorCookie;
    private LoggerInterface $logger;

    public function __construct(
        EventIngestService $ingestService,
        EventInterfaceFactory $eventFactory,
        VisitorCookie $visitorCookie,
        LoggerInterface $logger
    ) {
        $this->ingestService = $ingestService;
        $this->eventFactory = $eventFactory;
        $this->visitorCookie = $visitorCookie;
        $this->logger = $logger;
    }

    /**
     * @param string   $eventType one of docs/SPECS.md §6's event types
     * @param int|null $entityId  contextual id (product id, order id)
     * @return bool whether an event was dispatched
     */
    public function dispatch(string $eventType, ?int $entityId = null): bool
    {
        $visitorUuid = $this->visitorCookie->getVisitorUuid();

        // No cookie means the beacon never ran for this visitor — tracking is
        // off, consent was declined, or it is a non-browser request such as
        // an admin-created order or an API call. Dropping is correct; minting
        // an id here would invent a visit with no landing behind it.
        if ($visitorUuid === null) {
            return false;
        }

        try {
            $event = $this->eventFactory->create();
            $event->setVisitorUuid($visitorUuid);
            $event->setEventType($eventType);
            if ($entityId !== null) {
                $event->setEntityId($entityId);
            }

            $this->ingestService->ingestFromServer($event);

            return true;
        } catch (\Throwable $e) {
            // An observer runs inside the customer's request — a tracking
            // failure must never surface as a broken add-to-cart or a failed
            // order placement (CLAUDE.md #3).
            $this->logger->error(
                sprintf(
                    'Aavirbhava_AdsAnalytics: could not dispatch "%s" event: %s',
                    $eventType,
                    $e->getMessage()
                )
            );

            return false;
        }
    }
}
