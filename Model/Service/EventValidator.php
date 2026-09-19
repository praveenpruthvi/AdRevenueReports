<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;
use Aavirbhava\AdsAnalytics\Model\Config\TrafficClassificationConfig;

/**
 * Validates an ingested event before anything is persisted (P1-T9,
 * docs/SECURITY.md §2). Runs in the queue consumer, not on the webapi
 * request — see Model\EventIngestService's docblock for why.
 *
 * Returns a short, stable rejection reason string (stored in
 * ads_analytics_request_log.rejection_reason) or null when the event is
 * acceptable. Reasons are deliberately terse and machine-greppable so the
 * admin grid can be filtered by them.
 *
 * NOTE on platform_code: it is NOT validated here, on purpose. The consumer
 * discards it and recomputes it via TrafficResolver, so nothing downstream
 * ever reads the client's value — rejecting an otherwise good event over a
 * field nobody reads would lose real analytics data for no security benefit
 * (docs/SECURITY.md §2). `click_id_param` IS validated, because that one
 * genuinely drives paid classification.
 */
class EventValidator
{
    /**
     * docs/SPECS.md §6. 'landing' establishes/refreshes the visit; the rest
     * become ads_analytics_funnel_event rows.
     */
    public const EVENT_TYPE_LANDING = 'landing';

    private const VALID_EVENT_TYPES = [
        self::EVENT_TYPE_LANDING,
        'product_view',
        'add_to_cart',
        'checkout_start',
        'checkout_step_shipping',
        'checkout_step_payment',
        'checkout_step_review',
        'order_placed',
    ];

    /** Column widths from etc/db_schema.xml. */
    private const MAX_VISITOR_UUID = 64;
    private const MAX_CLICK_ID_VALUE = 128;
    private const MAX_UTM_SOURCE = 64;
    private const MAX_UTM_MEDIUM = 64;
    private const MAX_UTM_CAMPAIGN = 128;
    private const MAX_LANDING_PAGE = 255;

    private TrafficClassificationConfig $config;

    public function __construct(TrafficClassificationConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @return string|null rejection reason, or null if the event is valid
     */
    public function validate(EventInterface $event): ?string
    {
        $visitorUuid = trim($event->getVisitorUuid());

        // Found during security testing: a payload omitting visitor_uuid
        // reached the queue as an empty string, which would key a visit row
        // on ''. Every subsequent anonymous visitor would then collide onto
        // that one row.
        if ($visitorUuid === '') {
            return 'missing visitor_uuid';
        }
        if (mb_strlen($visitorUuid) > self::MAX_VISITOR_UUID) {
            return 'oversized field: visitor_uuid';
        }

        $eventType = $event->getEventType();
        if ($eventType === '') {
            return 'missing event_type';
        }
        if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
            return 'unknown event_type';
        }

        $clickIdParam = $event->getClickIdParam();
        if ($clickIdParam !== null && $clickIdParam !== '' && !$this->isKnownClickIdParam($clickIdParam)) {
            return 'unknown click_id_param';
        }

        foreach ($this->oversizedFields($event) as $field) {
            return 'oversized field: ' . $field;
        }

        return null;
    }

    public function isLanding(EventInterface $event): bool
    {
        return $event->getEventType() === self::EVENT_TYPE_LANDING;
    }

    /**
     * The client chooses which click-id param name to claim, and that name is
     * the single signal TrafficResolver uses to classify a visit as paid. An
     * unchecked value lets a caller book arbitrary traffic as paid Google.
     */
    private function isKnownClickIdParam(string $clickIdParam): bool
    {
        foreach ($this->config->getPlatformMap() as $platform) {
            if (strcasecmp($platform['click_id_param'], $clickIdParam) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject rather than silently truncate: these are the fields a merchant
     * reports on, and a quietly chopped campaign name produces a second,
     * near-identical row in the daily summary that looks like real data.
     * TrafficResolver clamps values it DERIVES (a referrer hostname it read
     * itself); values a client SENT are the client's problem to get right.
     *
     * @return \Generator<string>
     */
    private function oversizedFields(EventInterface $event): \Generator
    {
        $checks = [
            'click_id_value' => [$event->getClickIdValue(), self::MAX_CLICK_ID_VALUE],
            'utm_source' => [$event->getUtmSource(), self::MAX_UTM_SOURCE],
            'utm_medium' => [$event->getUtmMedium(), self::MAX_UTM_MEDIUM],
            'utm_campaign' => [$event->getUtmCampaign(), self::MAX_UTM_CAMPAIGN],
            'landing_page' => [$event->getLandingPage(), self::MAX_LANDING_PAGE],
        ];

        foreach ($checks as $name => [$value, $max]) {
            if ($value !== null && mb_strlen((string)$value) > $max) {
                yield $name;
            }
        }
    }
}
