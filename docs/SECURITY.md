# Security — Aavirbhava_AdsAnalytics

## 1. Attack surface
The public REST ingest endpoint (`POST /rest/V1/adsanalytics/event`, anonymous access) is the only externally-reachable surface this module adds. Everything else is admin-ACL-gated or cron/internal.

## 2. Input validation
- `click_id_param` and `event_type` must be validated against the config-driven allow-list/enum (§SPECS.md §2, §6) — reject anything else, don't persist unknown values. `click_id_param` matters most: it is the only client-supplied signal that drives paid classification, so an unvalidated one lets a caller book arbitrary traffic as paid.
- `platform_code` is deliberately **not** validated. It is discarded and recomputed server-side by `Model\Service\TrafficResolver` on every event (§SPECS.md §6), so nothing downstream ever reads the client's value — rejecting a whole request over a field nobody reads would drop good analytics data for no security benefit. Discard it, don't police it.
- Enforce max length on all string fields (`utm_*`, `landing_page`, `click_id_value`) **in the consumer, before persisting** — truncate or reject oversized payloads rather than letting them hit the DB. (Not "before queueing": validation moved to `Model\Queue\EventConsumer` so the webapi request does no DB work at all — see CLAUDE.md constraint #3.)
- Escape all of this data on output in admin grids/charts (Magento UI Components escape by default — don't bypass with raw HTML bindings). Campaign/UTM values are attacker-controllable strings; treat them as such.
- `ads_analytics_request_log.raw_payload` (SPECS.md §9) is logged **before** validation, so it can contain anything a client sent, including malformed or hostile input by design (that's the point of the log). Never render it unescaped in the admin grid, and never `eval`/deserialize it — display as plain escaped text only.

## 3. Never trust client-supplied identity
- `customer_id` and `order_id` are **never** accepted in the ingest payload. The consumer resolves `customer_id` from the authenticated session (if any) and links `order_id` server-side via the `sales_model_service_quote_submit_success` observer — not from anything the frontend sends. (`sales_order_place_after` is the wrong event for this: it is dispatched from inside `Order::place()` before the order is saved, so it carries no order id.)
- `order_placed` and `add_to_cart` are **server-only event types**. `Model\EventIngestService::ingest()`, the public webapi entry point, drops them; they reach the queue only via `ingestFromServer()`, which is deliberately absent from `Api\EventIngestInterface` and so cannot be exposed by `etc/webapi.xml`. Without this a caller could POST `{"event_type":"order_placed","entity_id":<someone else's order>}` and attribute that order, and its revenue, to their own visit.

## 4. Rate limiting / abuse
- Per-`visitor_uuid` and per-IP throttling on the ingest endpoint, implemented in `Model\EventIngestService` (cache-backed counter, drop beyond threshold) to prevent log-flooding or DoS via the public endpoint. **This belongs at the webapi layer, not in `EventConsumer`** — a consumer-side limit cannot shed load, because by the time the consumer runs the request has already been accepted, published and queued. Keyed on (`ip_hash`, `visitor_uuid`) together: `visitor_uuid` alone is client-generated and rotatable, `ip_hash` alone is shared behind NAT.
- The counter is a best-effort fixed window, not an exact quota — a concurrent burst can overshoot slightly. That is acceptable: this is load shedding, not access control.
- Sampling-rate config (SPECS.md §4) doubles as a load-shedding lever on high-traffic stores.
- Basic bot/known-crawler User-Agent filtering before writing visit rows — best-effort noise reduction, not a fraud-detection system; don't oversell this to stakeholders as ad-fraud protection.

## 5. CSRF
Anonymous POST endpoints aren't subject to Magento's form-key CSRF check. Mitigate by: same-origin `fetch` only (no cross-origin posting accepted — validate `Origin`/`Referer` where present), strict payload validation (§2), and no state-changing effect beyond analytics writes (no privilege, no order mutation) — so the worst case of a forged request is bad analytics data, not account/order compromise.

## 6. Data minimization / PII
- No email, name, or raw IP stored. If UA/IP is used transiently for bot filtering (§4), don't persist it — or hash+truncate if a persisted signal is genuinely needed.
- `referrer` (used for organic/referral classification, docs/SPECS.md §2) is truncated to **hostname only** before storage — never the full URL. A full referrer URL can carry a search query, an internal tracking token, or other sensitive path/query data that isn't ours to keep; the hostname alone is all classification needs.
- `ads_analytics_visit.customer_id` is the only PII-adjacent link, and only after a real order ties the anonymous visit to an account — consistent with CLAUDE.md's "no PII beyond what's already on the order" rule.

## 7. Consent
Beacon must not set the tracking cookie or send events before consent, if the store runs a cookie-consent solution (ties to `docs/TASKS.md` P1-T8). Confirm the store's actual consent mechanism before implementing the check.

## 8. Data retention
- Cron purges raw `ads_analytics_visit` / `ads_analytics_funnel_event` rows older than a configurable window (default suggestion: 180 days) — aggregated `ads_analytics_daily_summary` is retained indefinitely since it holds no per-visitor detail.
- `ads_analytics_request_log` (SPECS.md §9) gets its **own, shorter** retention window (default suggestion: 14 days) since it stores raw unvalidated payloads — purge on a separate config-driven schedule from the analytics tables.
- Retention windows should be admin-configurable (add to `system.xml` in Phase 3/4, alongside cron aggregation work).

**Implemented at P3-T7.** `Cron\PurgeOldData` runs at 02:30 — deliberately after the 02:00 aggregation job, so a day's rows are always rolled into `ads_analytics_daily_summary` before anything can delete them. `Model\Service\DataPurger` does the work in batches of 5,000 rows, and `bin/magento aavirbhava:adsanalytics:purge --dry-run` reports what a purge would remove without removing it.

Three behaviours to be aware of when changing these settings:

- **A window of 0 (or a negative number) disables that purge; it does not mean "delete everything".** This is enforced in `Model\Config\RetentionConfig` and covered by unit tests. An unconfigured value still falls back to the shipped default (180 / 14 days) rather than to 0.
- **Visits are deleted on `last_seen_at`**, so a visitor who is still active is never purged mid-journey; their old funnel events are removed separately on the events' own `created_at`. Deleting a visit cascades to its funnel events and its `ads_analytics_order_attribution` row.
- **Shortening the raw-event window shortens how far back the summary can be rebuilt.** `ads_analytics_daily_summary` is kept indefinitely, but re-aggregating a date whose raw rows have been purged would overwrite that date's real figures with zeros. `bin/magento aavirbhava:adsanalytics:aggregate` therefore refuses `--from` dates older than the retention window unless `--force` is given.

## 9. Admin ACL
Dedicated ACL resource for this module's admin menu/pages/config, independent of `Aavirbhava_SalesAnalytics`'s ACL — so access can be granted separately.

## 10. Secrets
Any ad-spend provider API credentials (Phase 4 — Google Ads/Meta Ads APIs) must be stored via Magento's encrypted config backend (`Magento\Config\Model\Config\Backend\Encrypted`), never plain text in `system.xml` defaults or DB.
