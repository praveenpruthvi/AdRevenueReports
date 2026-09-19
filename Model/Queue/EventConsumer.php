<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Queue;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;
use Aavirbhava\AdsAnalytics\Model\Service\EventValidator;
use Aavirbhava\AdsAnalytics\Model\Service\RequestLogWriter;
use Aavirbhava\AdsAnalytics\Model\Service\TrafficResolver;
use Aavirbhava\AdsAnalytics\Model\Service\VisitManager;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the "aavirbhava.adsanalytics.event" queue (etc/queue_consumer.xml).
 *
 * This is the ONLY place that touches the database on the ingest path —
 * EventIngestService (the webapi entry point) does rate limiting, ip/UA
 * capture and publish, and nothing else, so every write here is already off
 * the customer-facing request thread (CLAUDE.md constraint #3 — no
 * exceptions, including request logging).
 *
 * Sequence, in order:
 *   1. Write an ads_analytics_request_log row with validation_status=pending,
 *      BEFORE validation. A row left at 'pending' means the consumer died
 *      mid-message — a visible symptom instead of a silent disappearance.
 *   2. Validate (EventValidator). On failure: mark the log row rejected with
 *      a reason and STOP — nothing is persisted to the analytics tables.
 *   3. Classify via TrafficResolver, which re-derives traffic_type /
 *      platform_code / source / medium / campaign server-side. The client's
 *      platform_code is never read.
 *   4. landing -> upsert the visit. Anything else -> resolve (or create) the
 *      visit and insert a funnel event.
 *
 * ERROR HANDLING: a message that throws is swallowed and logged rather than
 * rethrown. Rethrowing would make the broker redeliver it, and a message that
 * fails deterministically — a payload that trips a bug in classification, say
 * — would then redeliver for ever, blocking the queue behind it and filling
 * the log. Losing one analytics event is the cheaper failure.
 *
 * NOT YET HANDLED — idempotency. AMQP is at-least-once, so a redelivered
 * message writes a second funnel-event row. Deduplicating needs a stable
 * per-message id that the current payload does not carry; see PROGRESS.md.
 */
class EventConsumer
{
    private RequestLogWriter $requestLogWriter;
    private EventValidator $validator;
    private TrafficResolver $trafficResolver;
    private VisitManager $visitManager;
    private LoggerInterface $logger;

    public function __construct(
        RequestLogWriter $requestLogWriter,
        EventValidator $validator,
        TrafficResolver $trafficResolver,
        VisitManager $visitManager,
        LoggerInterface $logger
    ) {
        $this->requestLogWriter = $requestLogWriter;
        $this->validator = $validator;
        $this->trafficResolver = $trafficResolver;
        $this->visitManager = $visitManager;
        $this->logger = $logger;
    }

    public function process(EventInterface $event): void
    {
        $logId = $this->requestLogWriter->logPending($event);

        try {
            $rejectionReason = $this->validator->validate($event);

            if ($rejectionReason !== null) {
                $this->requestLogWriter->markRejected($logId, $rejectionReason);
                return;
            }

            $this->persist($event);
            $this->requestLogWriter->markAccepted($logId);
        } catch (\Throwable $e) {
            $this->requestLogWriter->markRejected($logId, 'consumer error: ' . $e->getMessage());
            $this->logger->error(
                sprintf(
                    'Aavirbhava_AdsAnalytics: failed to process event for visitor "%s": %s',
                    $event->getVisitorUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }
    }

    private function persist(EventInterface $event): void
    {
        if ($this->validator->isLanding($event)) {
            $this->visitManager->upsertLanding($event, $this->classify($event));
            return;
        }

        $visit = $this->visitManager->resolveOrCreateVisit($event->getVisitorUuid());
        $this->visitManager->recordFunnelEvent($visit, $event);
    }

    /**
     * Rebuilds the $_GET-style array TrafficResolver expects.
     *
     * The wire format carries click_id_param and click_id_value as separate
     * fields, so the param name has to be re-keyed back onto the array. That
     * name is client-supplied and is the single signal driving paid
     * classification — EventValidator has already checked it against the
     * configured platform map by this point, so an unknown one never reaches
     * here (docs/SECURITY.md §2).
     *
     * @return array{traffic_type: string, platform_code: ?string, click_id_param: ?string, click_id_value: ?string, source: ?string, medium: ?string, campaign: ?string}
     */
    private function classify(EventInterface $event): array
    {
        $queryParams = [
            'utm_source' => $event->getUtmSource(),
            'utm_medium' => $event->getUtmMedium(),
            'utm_campaign' => $event->getUtmCampaign(),
        ];

        $clickIdParam = $event->getClickIdParam();
        if ($clickIdParam !== null && $clickIdParam !== '') {
            $queryParams[$clickIdParam] = $event->getClickIdValue();
        }

        return $this->trafficResolver->resolve($queryParams, $event->getReferrer());
    }
}
