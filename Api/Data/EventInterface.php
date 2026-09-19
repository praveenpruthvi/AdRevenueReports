<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Api\Data;

/**
 * Ingest payload contract — must match docs/SPECS.md §6 exactly.
 * If the schema changes, update both this interface and
 * tools/simulate_traffic.py together.
 *
 * PHPDoc @param/@return tags are required on every method here (not just
 * native PHP type hints) — Magento's webapi reflection layer generates the
 * REST/SOAP schema from these annotations, and this interface is exposed
 * directly via etc/webapi.xml.
 */
interface EventInterface
{
    public const VISITOR_UUID = 'visitor_uuid';
    public const EVENT_TYPE = 'event_type';
    public const PLATFORM_CODE = 'platform_code';
    public const CLICK_ID_PARAM = 'click_id_param';
    public const CLICK_ID_VALUE = 'click_id_value';
    public const UTM_SOURCE = 'utm_source';
    public const UTM_MEDIUM = 'utm_medium';
    public const UTM_CAMPAIGN = 'utm_campaign';
    public const REFERRER = 'referrer';
    public const IP_HASH = 'ip_hash';
    public const USER_AGENT = 'user_agent';
    public const LANDING_PAGE = 'landing_page';
    public const ENTITY_ID = 'entity_id';
    public const TIMESTAMP = 'timestamp';

    /**
     * @return string
     */
    public function getVisitorUuid(): string;

    /**
     * @param string $visitorUuid
     * @return $this
     */
    public function setVisitorUuid(string $visitorUuid): self;

    /**
     * @return string
     */
    public function getEventType(): string;

    /**
     * @param string $eventType
     * @return $this
     */
    public function setEventType(string $eventType): self;

    /**
     * NOTE: even though this is accepted in the payload, the server
     * (Model\Service\TrafficResolver via the queue consumer) always
     * recomputes platform_code from click_id_param/utm_* and ignores
     * whatever the client sent here — it is never trusted or persisted
     * as-received. See docs/SPECS.md §6 and §2.
     *
     * @return string|null
     */
    public function getPlatformCode(): ?string;

    /**
     * @param string|null $platformCode
     * @return $this
     */
    public function setPlatformCode(?string $platformCode): self;

    /**
     * @return string|null
     */
    public function getClickIdParam(): ?string;

    /**
     * @param string|null $clickIdParam
     * @return $this
     */
    public function setClickIdParam(?string $clickIdParam): self;

    /**
     * @return string|null
     */
    public function getClickIdValue(): ?string;

    /**
     * @param string|null $clickIdValue
     * @return $this
     */
    public function setClickIdValue(?string $clickIdValue): self;

    /**
     * @return string|null
     */
    public function getUtmSource(): ?string;

    /**
     * @param string|null $utmSource
     * @return $this
     */
    public function setUtmSource(?string $utmSource): self;

    /**
     * @return string|null
     */
    public function getUtmMedium(): ?string;

    /**
     * @param string|null $utmMedium
     * @return $this
     */
    public function setUtmMedium(?string $utmMedium): self;

    /**
     * @return string|null
     */
    public function getUtmCampaign(): ?string;

    /**
     * @param string|null $utmCampaign
     * @return $this
     */
    public function setUtmCampaign(?string $utmCampaign): self;

    /**
     * Full document.referrer as sent by the client. The server truncates
     * this to hostname-only before it reaches storage (docs/SECURITY.md §6)
     * — this getter/setter carries the raw value only as far as validation.
     *
     * @return string|null
     */
    public function getReferrer(): ?string;

    /**
     * @param string|null $referrer
     * @return $this
     */
    public function setReferrer(?string $referrer): self;

    /**
     * Hashed client IP (never the raw address — docs/SECURITY.md §6), used
     * only on the ads_analytics_request_log row for debugging.
     *
     * NOTE: like platform_code, whatever a client sends here is always
     * discarded. Model\EventIngestService recomputes it from the live HTTP
     * request and overwrites this field immediately before publishing, so a
     * client can never inject a forged or borrowed IP hash.
     *
     * @return string|null
     */
    public function getIpHash(): ?string;

    /**
     * @param string|null $ipHash
     * @return $this
     */
    public function setIpHash(?string $ipHash): self;

    /**
     * Truncated User-Agent header, used only on the
     * ads_analytics_request_log row for debugging.
     *
     * NOTE: like platform_code, whatever a client sends here is always
     * discarded. Model\EventIngestService reads the real User-Agent header
     * from the live HTTP request and overwrites this field immediately
     * before publishing.
     *
     * @return string|null
     */
    public function getUserAgent(): ?string;

    /**
     * @param string|null $userAgent
     * @return $this
     */
    public function setUserAgent(?string $userAgent): self;

    /**
     * @return string|null
     */
    public function getLandingPage(): ?string;

    /**
     * @param string|null $landingPage
     * @return $this
     */
    public function setLandingPage(?string $landingPage): self;

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int|null $entityId
     * @return $this
     */
    public function setEntityId(?int $entityId): self;

    /**
     * @return string|null
     */
    public function getTimestamp(): ?string;

    /**
     * @param string|null $timestamp
     * @return $this
     */
    public function setTimestamp(?string $timestamp): self;
}
