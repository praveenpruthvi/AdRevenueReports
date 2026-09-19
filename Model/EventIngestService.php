<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;
use Aavirbhava\AdsAnalytics\Api\EventIngestInterface;
use Aavirbhava\AdsAnalytics\Model\Config\IngestConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Header as HttpHeader;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Implements Api/EventIngestInterface — the webapi entry point behind
 * POST /V1/adsanalytics/event.
 *
 * Does four things, none of them a DB write:
 *   1. Honours the kill switch and the sampling rate (docs/SPECS.md §4).
 *   2. Rate-limits the caller (cache-backed counter, docs/SECURITY.md §4).
 *      This belongs HERE and not in EventConsumer: throttling in the consumer
 *      would be useless against flooding, because by then the request has
 *      already been accepted, published and queued. Dropping here is the only
 *      point at which load is actually shed.
 *   3. Captures ip_hash/user_agent from the current HTTP request.
 *      EventConsumer runs in a separate process with no access to this
 *      request context, so these MUST be captured here (docs/SPECS.md §9).
 *      Both are overwritten on $event unconditionally — a client may send
 *      them, but whatever it sends is discarded, exactly as with
 *      platform_code (see Api\Data\EventInterface::getIpHash()).
 *   4. Publishes to the queue.
 *
 * NO validation and NO request-log DB write happen here — both live in
 * Model\Queue\EventConsumer (see its docblock) so that constraint #3 in
 * CLAUDE.md ("no synchronous DB writes on the customer-facing request") has
 * no exceptions anywhere, including request logging. The cache read/write
 * below is not a DB write; it is deliberately the only state this class
 * touches on the request thread.
 *
 * EVERY exit path from ingest() is silent. This endpoint is called from a
 * beacon on every storefront page, so a dropped, disabled, sampled-out or
 * failed event must look identical to a successful one from the outside.
 * Returning an error would put exception noise on every page view whenever
 * the broker is down, and would tell a flooder exactly where the threshold
 * is (docs/SECURITY.md §4).
 */
class EventIngestService implements EventIngestInterface
{
    private const TOPIC_NAME = 'aavirbhava.adsanalytics.event';

    /** Matches ads_analytics_request_log.user_agent width (etc/db_schema.xml). */
    private const MAX_USER_AGENT_LENGTH = 255;

    private const RATE_LIMIT_CACHE_PREFIX = 'aavirbhava_adsanalytics_ingest_rate_';

    private PublisherInterface $publisher;
    private RemoteAddress $remoteAddress;
    private HttpHeader $httpHeader;
    private EncryptorInterface $encryptor;
    private CacheInterface $cache;
    private IngestConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        PublisherInterface $publisher,
        RemoteAddress $remoteAddress,
        HttpHeader $httpHeader,
        EncryptorInterface $encryptor,
        CacheInterface $cache,
        IngestConfig $config,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->remoteAddress = $remoteAddress;
        $this->httpHeader = $httpHeader;
        $this->encryptor = $encryptor;
        $this->cache = $cache;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function ingest(EventInterface $event): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$this->passesSampling($event->getVisitorUuid())) {
            return;
        }

        $ipHash = $this->resolveIpHash();

        if ($this->isRateLimited($ipHash, $event->getVisitorUuid())) {
            return;
        }

        // Always overwrite — never trust what the client sent for these.
        $event->setIpHash($ipHash);
        $event->setUserAgent($this->resolveUserAgent());

        try {
            $this->publisher->publish(self::TOPIC_NAME, $event);
        } catch (\Throwable $e) {
            // A broker outage must not become a storefront error. Swallow and
            // log: losing an analytics event is an acceptable cost, breaking
            // every page on the site is not (CLAUDE.md #3 — checkout
            // performance is not allowed to regress).
            $this->logger->error(
                sprintf(
                    'Aavirbhava_AdsAnalytics: failed to publish ingest event to "%s": %s',
                    self::TOPIC_NAME,
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Deterministic per visitor, not random per request: a visitor that is
     * sampled in stays sampled in for their whole journey. Sampling each
     * event independently would shred the funnel — you would keep a visitor's
     * add_to_cart but drop their landing, and the visit could never be
     * reconstructed or attributed.
     */
    private function passesSampling(string $visitorUuid): bool
    {
        $rate = $this->config->getSamplingRate();
        if ($rate >= 100) {
            return true;
        }

        // crc32 of the uuid gives a stable, uniformly distributed bucket.
        return (crc32($visitorUuid) % 100) < $rate;
    }

    /**
     * Hashed with Encryptor::hash(), which is an HMAC-SHA256 keyed on this
     * install's crypt key — so the value is stable within an install (usable
     * as a rate-limit key and for grouping in the request log) but is not a
     * plain digest that could be brute-forced back to an IP from a stolen
     * table dump. 64 hex chars, matching ads_analytics_request_log.ip_hash.
     */
    private function resolveIpHash(): ?string
    {
        $ip = $this->remoteAddress->getRemoteAddress();

        return $ip ? $this->encryptor->hash((string)$ip) : null;
    }

    private function resolveUserAgent(): ?string
    {
        $userAgent = $this->httpHeader->getHttpUserAgent();

        return $userAgent !== '' && $userAgent !== null
            ? mb_substr((string)$userAgent, 0, self::MAX_USER_AGENT_LENGTH)
            : null;
    }

    /**
     * Throttles per (ip_hash, visitor_uuid) pair, per docs/SECURITY.md §4.
     * Both are used because either alone is trivially evaded: a visitor_uuid
     * is client-generated and can be rotated per request, and an ip_hash is
     * shared by everyone behind one NAT.
     *
     * Best-effort fixed window — not atomic, so a concurrent burst can
     * overshoot the limit slightly. That is acceptable: this is load
     * shedding, not access control.
     */
    private function isRateLimited(?string $ipHash, string $visitorUuid): bool
    {
        $cacheKey = self::RATE_LIMIT_CACHE_PREFIX . hash(
            'sha256',
            ($ipHash ?? 'no_ip') . '|' . $visitorUuid
        );

        $count = (int)$this->cache->load($cacheKey);

        if ($count >= $this->config->getRateLimitMaxRequests()) {
            return true;
        }

        $this->cache->save(
            (string)($count + 1),
            $cacheKey,
            [],
            $this->config->getRateLimitWindowSeconds()
        );

        return false;
    }
}
