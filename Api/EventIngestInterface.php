<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Api;

use Aavirbhava\AdsAnalytics\Api\Data\EventInterface;

/**
 * Webapi service contract for POST /V1/adsanalytics/event (etc/webapi.xml).
 * Implementation: Model/EventIngestService.php.
 */
interface EventIngestInterface
{
    /**
     * Accepts one storefront tracking event and hands it to the async queue.
     *
     * The implementation (Model\EventIngestService) does exactly three
     * things, none of them a DB write:
     *   1. Rate-limits the caller against a cache-backed counter
     *      (docs/SECURITY.md §4). Over the limit, the event is dropped
     *      without being published — silently, so the beacon never surfaces
     *      tracking state to the storefront. This is the only point where
     *      load is actually shed; throttling further down the pipeline would
     *      be too late, since the request would already be queued.
     *   2. Overwrites ip_hash and user_agent on $event from the live HTTP
     *      request. Whatever the client sent for those two fields is
     *      discarded (see Api\Data\EventInterface::getIpHash()).
     *   3. Publishes to the "aavirbhava.adsanalytics.event" topic.
     *
     * It does NOT validate the payload and does NOT write the
     * ads_analytics_request_log row — both happen asynchronously in
     * Model\Queue\EventConsumer (P1-T5b/P1-T9, docs/SPECS.md §9), so that
     * CLAUDE.md constraint #3 holds with no exceptions. A caller therefore
     * cannot infer from a successful return that the event was valid,
     * accepted, or stored — only that it was not rate-limited.
     *
     * @param EventInterface $event
     * @return void
     */
    public function ingest(EventInterface $event): void;
}
