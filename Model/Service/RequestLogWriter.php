<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Service;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;
use Aavirbhava\AdsAnalytics\Model\Config\RequestLogConfig;
use Aavirbhava\AdsAnalytics\Model\Config\Source\ValidationStatus;
use Aavirbhava\AdsAnalytics\Model\RequestLogFactory;
use Aavirbhava\AdsAnalytics\Model\ResourceModel\RequestLog as RequestLogResource;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Writes ads_analytics_request_log rows (P1-T5b, docs/SPECS.md §9).
 *
 * The row is written BEFORE validation with validation_status='pending', then
 * updated with the outcome. That ordering is the whole point of the table: if
 * the consumer dies mid-message the row survives as 'pending', which is a
 * visible symptom rather than a silent disappearance.
 *
 * HONEST LIMITATION: `raw_payload` here is a re-serialisation of the
 * deserialised EventInterface, not the literal HTTP body. Magento's webapi
 * layer parses and type-checks the body before EventIngestService is ever
 * called, so a truly malformed request (bad JSON, unknown property, wrong
 * scalar type) fails upstream and never reaches the queue — it cannot be
 * logged here at all. SPECS.md §9 was updated to say "the message body as
 * received by the consumer" for exactly this reason. Capturing the literal
 * body would need a plugin at the webapi request layer.
 */
class RequestLogWriter
{
    private const ENDPOINT = '/V1/adsanalytics/event';
    
    /** Matches ads_analytics_request_log.page_url width (etc/db_schema.xml). */
    private const MAX_PAGE_URL_LENGTH = 2048;
    private const MAX_REJECTION_REASON = 255;

    private RequestLogFactory $requestLogFactory;
    private RequestLogResource $requestLogResource;
    private RequestLogConfig $config;
    private Json $json;
    private LoggerInterface $logger;

    public function __construct(
        RequestLogFactory $requestLogFactory,
        RequestLogResource $requestLogResource,
        RequestLogConfig $config,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->requestLogFactory = $requestLogFactory;
        $this->requestLogResource = $requestLogResource;
        $this->config = $config;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * @return int|null log_id, or null when logging is off / the write failed
     */
    public function logPending(EventInterface $event): ?int
    {
        if (!$this->config->shouldLogBeforeValidation()) {
            return null;
        }

        try {
            $log = $this->requestLogFactory->create();
            $log->addData([
                'endpoint' => self::ENDPOINT,
                'raw_payload' => $this->json->serialize($this->toArray($event)),
                'validation_status' => ValidationStatus::PENDING,
                'visitor_uuid' => $this->nullIfBlank($event->getVisitorUuid()),
                'ip_hash' => $event->getIpHash(),
                'user_agent' => $event->getUserAgent(),
                'page_url' => $this->truncate($event->getPageUrl(), self::MAX_PAGE_URL_LENGTH),
            ]);
            $this->requestLogResource->save($log);

            return (int)$log->getId();
        } catch (\Throwable $e) {
            // Logging must never be the reason an event is lost.
            $this->logger->error('Aavirbhava_AdsAnalytics: could not write request log row: ' . $e->getMessage());
            return null;
        }
    }

    public function markRejected(?int $logId, string $reason): void
    {
        $this->finalise($logId, ValidationStatus::REJECTED, $reason);
    }

    /**
     * @param string|null $trafficType the type TrafficResolver decided, for a
     *                                 landing; null for any other event,
     *                                 which is never classified
     */
    public function markAccepted(?int $logId, ?string $trafficType = null): void
    {
        if ($logId === null) {
            return;
        }

        // The optimistic pending row is discarded now that we know the event
        // was fine and what it resolved to. At 'rejected_only' that means
        // every accepted row; at 'external_only' only the ones that did not
        // arrive from outside the store. Keeping them all would make the log
        // grow at the rate of total traffic, defeating the shorter retention
        // window that exists because this table holds raw unvalidated input.
        if (!$this->config->shouldKeepAcceptedRow($trafficType)) {
            $this->delete($logId);
            return;
        }

        $this->finalise($logId, ValidationStatus::ACCEPTED, null, $trafficType);
    }

    private function finalise(?int $logId, string $status, ?string $reason, ?string $trafficType = null): void
    {
        if ($logId === null) {
            return;
        }

        try {
            $log = $this->requestLogFactory->create();
            $this->requestLogResource->load($log, $logId);
            if (!$log->getId()) {
                return;
            }
            $log->setData('validation_status', $status);
            if ($trafficType !== null) {
                $log->setData('traffic_type', $trafficType);
            }
            $log->setData(
                'rejection_reason',
                $reason !== null ? mb_substr($reason, 0, self::MAX_REJECTION_REASON) : null
            );
            $this->requestLogResource->save($log);
        } catch (\Throwable $e) {
            $this->logger->error('Aavirbhava_AdsAnalytics: could not finalise request log row: ' . $e->getMessage());
        }
    }

    private function delete(int $logId): void
    {
        try {
            $log = $this->requestLogFactory->create();
            $this->requestLogResource->load($log, $logId);
            if ($log->getId()) {
                $this->requestLogResource->delete($log);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Aavirbhava_AdsAnalytics: could not discard accepted log row: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function toArray(EventInterface $event): array
    {
        return array_filter([
            'visitor_uuid' => $event->getVisitorUuid(),
            'event_type' => $event->getEventType(),
            'platform_code' => $event->getPlatformCode(),
            'click_id_param' => $event->getClickIdParam(),
            'click_id_value' => $event->getClickIdValue(),
            'utm_source' => $event->getUtmSource(),
            'utm_medium' => $event->getUtmMedium(),
            'utm_campaign' => $event->getUtmCampaign(),
            'referrer' => $event->getReferrer(),
            'landing_page' => $event->getLandingPage(),
            'page_url' => $event->getPageUrl(),
            'entity_id' => $event->getEntityId(),
            'timestamp' => $event->getTimestamp(),
        ], static fn ($v): bool => $v !== null && $v !== '');
    }

    /**
     * Truncates rather than rejects. An over-long URL is exactly the kind of
     * request worth seeing in the log, so storing a clipped one beats
     * dropping the row or letting the insert fail on column width.
     */
    private function truncate(?string $value, int $max): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, 64) : null;
    }
}
