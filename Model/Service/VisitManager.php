<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;
use Aavirbhava\AdsAnalytics\Model\FunnelEventFactory;
use Aavirbhava\AdsAnalytics\Model\ResourceModel\FunnelEvent as FunnelEventResource;
use Aavirbhava\AdsAnalytics\Model\ResourceModel\Visit as VisitResource;
use Aavirbhava\AdsAnalytics\Model\Visit;
use Aavirbhava\AdsAnalytics\Model\VisitFactory;

/**
 * Owns every write to ads_analytics_visit and ads_analytics_funnel_event
 * (P1-T6).
 *
 * ads_analytics_visit is one row per VISITOR (unique on visitor_uuid), not
 * per session — first_touch_* is set once and never overwritten, last_touch_*
 * is refreshed on every landing.
 */
class VisitManager
{
    /**
     * Bug indicator, matching ads_analytics_visit.traffic_type's column
     * default. A row carrying this was created by a funnel event that arrived
     * before any landing, so it has not been classified yet. It is treated as
     * "not set" and backfilled by a later landing — deliberately NOT
     * 'direct', which would silently book unattributed traffic as a real
     * channel.
     */
    public const TRAFFIC_TYPE_UNKNOWN = 'unknown';

    private VisitFactory $visitFactory;
    private VisitResource $visitResource;
    private FunnelEventFactory $funnelEventFactory;
    private FunnelEventResource $funnelEventResource;

    public function __construct(
        VisitFactory $visitFactory,
        VisitResource $visitResource,
        FunnelEventFactory $funnelEventFactory,
        FunnelEventResource $funnelEventResource
    ) {
        $this->visitFactory = $visitFactory;
        $this->visitResource = $visitResource;
        $this->funnelEventFactory = $funnelEventFactory;
        $this->funnelEventResource = $funnelEventResource;
    }

    /**
     * Landing event: create the visit, or refresh last-touch on an existing
     * one and backfill anything a placeholder row left unset.
     *
     * @param array{traffic_type: string, platform_code: ?string, click_id_param: ?string, click_id_value: ?string, source: ?string, medium: ?string, campaign: ?string} $classification
     */
    public function upsertLanding(EventInterface $event, array $classification): Visit
    {
        $visit = $this->loadByUuid($event->getVisitorUuid());

        if (!$visit->getId()) {
            $visit->setData('visitor_uuid', trim($event->getVisitorUuid()));
            $visit->setData('first_touch_source', $classification['source']);
            $visit->setData('first_touch_medium', $classification['medium']);
            $visit->setData('first_touch_campaign', $classification['campaign']);
        } elseif ($this->isUnclassified($visit)) {
            // Backfill a placeholder created by an out-of-order funnel event.
            // Without this the visit would keep traffic_type='unknown' for
            // ever even though its landing did eventually arrive.
            $visit->setData('first_touch_source', $classification['source']);
            $visit->setData('first_touch_medium', $classification['medium']);
            $visit->setData('first_touch_campaign', $classification['campaign']);
        }

        // Always refreshed: the most recent ad click is what last-touch
        // attribution credits.
        $visit->setData('traffic_type', $classification['traffic_type']);
        $visit->setData('last_touch_source', $classification['source']);
        $visit->setData('last_touch_medium', $classification['medium']);
        $visit->setData('last_touch_campaign', $classification['campaign']);
        $visit->setData('click_id_param', $classification['click_id_param']);
        $visit->setData('click_id_value', $classification['click_id_value']);
        $visit->setData('platform_code', $classification['platform_code']);

        if ($event->getLandingPage() !== null && $event->getLandingPage() !== '') {
            $visit->setData('landing_page', $event->getLandingPage());
        }

        $this->visitResource->save($visit);

        return $visit;
    }

    /**
     * Resolve the visit for a non-landing event, creating a minimal
     * placeholder if none exists.
     *
     * This is a normal case, not corruption: the landing beacon can be
     * blocked by an ad blocker or a consent gate, a visitor can arrive
     * mid-session, and AMQP gives no ordering guarantee across concurrent
     * consumers — so a cart or order event genuinely can arrive first.
     * ads_analytics_funnel_event.visit_id is NOT NULL with an FK, so there is
     * no option to simply skip the parent.
     */
    public function resolveOrCreateVisit(string $visitorUuid): Visit
    {
        $visit = $this->loadByUuid($visitorUuid);

        if (!$visit->getId()) {
            $visit->setData('visitor_uuid', trim($visitorUuid));
            $visit->setData('traffic_type', self::TRAFFIC_TYPE_UNKNOWN);
            $this->visitResource->save($visit);
        }

        return $visit;
    }

    public function recordFunnelEvent(Visit $visit, EventInterface $event): void
    {
        $funnelEvent = $this->funnelEventFactory->create();
        $funnelEvent->addData([
            'visit_id' => (int)$visit->getId(),
            'event_type' => $event->getEventType(),
            'entity_id' => $event->getEntityId(),
        ]);
        $this->funnelEventResource->save($funnelEvent);
    }

    /**
     * Flags the visit as converted. Kept separate from the attribution write
     * so a visit is marked converted exactly once per order without the
     * attribution row's idempotency having to care.
     */
    public function markConverted(Visit $visit): void
    {
        if ((int)$visit->getData('converted') === 1) {
            return;
        }

        $visit->setData('converted', 1);
        $this->visitResource->save($visit);
    }

    private function loadByUuid(string $visitorUuid): Visit
    {
        $visit = $this->visitFactory->create();
        $this->visitResource->load($visit, trim($visitorUuid), 'visitor_uuid');

        return $visit;
    }

    /**
     * True when the row is a placeholder whose first-touch data was never
     * captured — either the explicit 'unknown' bug indicator or a missing
     * source.
     */
    private function isUnclassified(Visit $visit): bool
    {
        return $visit->getData('traffic_type') === self::TRAFFIC_TYPE_UNKNOWN
            || $visit->getData('first_touch_source') === null;
    }
}
