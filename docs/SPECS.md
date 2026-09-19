# Technical Specification — Aavirbhava_AdsAnalytics

## 1. Module identity
- Namespace: `Aavirbhava\AdsAnalytics`
- Composer package: `aavirbhava/module-ads-analytics`
- Admin menu: nested under `Magento_Reports::report`, alongside `Aavirbhava_SalesAnalytics`.

## 2. Traffic classification: paid platform map + organic/direct/referral

Every visit gets a `traffic_type`: `paid` | `organic` | `direct` | `referral`. Classification is config-driven and rule-based — no per-source code, so adding a search engine or an ad platform is a config row, never a new class or conditional.

**Precedence (first match wins):**
1. **Paid** — a known click-id param is present (table below), OR `utm_medium` is in a configured "paid mediums" list (e.g. `cpc`, `ppc`, `paid`, `paidsocial`) for manually-tagged paid links that carry no click id. Matched case-insensitively.
2. **Tagged (non-paid UTM)** — `utm_source` and/or `utm_medium` is present but didn't match paid (e.g. `utm_source=newsletter&utm_medium=email`, or `utm_medium=email` alone). Use the UTM values as `source`/`medium`/`campaign` rather than discarding them — explicit tagging beats guessing from the referrer. Bucketed as `traffic_type=referral` (v1 has no dedicated `email`/`organic_social` channel — see `PROJECT_PLAN.md`'s Out of scope section).
3. **Organic** — no click-id/UTM signal at all, and the referrer's hostname matches a configured search-engine domain **at a label boundary** (host equals the domain, or ends with `.<domain>` — never a raw substring match: `notgoogle.com` and `evil-bing.com.attacker.net` must NOT match `google`/`bing`).
4. **Referral** — no click-id/UTM signal, no organic match, but a referrer is present (some other site linked in).
5. **Direct** — no click-id, no `utm_*`, no referrer at all.

**Paid platform map** (`platform_code` → `click_id_param` → `default_source`):

| platform_code | click_id_param | default_source |
|---|---|---|
| google | gclid | google |
| meta | fbclid | facebook (or `instagram` if `utm_source=instagram`) |
| bing | msclkid | bing |
| tiktok | ttclid | tiktok |
| reddit | rdt_cid | reddit |

`platform_code` is **always** one of these five fixed keys — never `instagram`. Only `source` disambiguates facebook vs. instagram (per the table above); `platform_code` stays `meta` in both cases. This matters because `docs/SECURITY.md`'s allow-list validation depends on `platform_code` being a closed, stable set.

**Organic search-engine domain list** (default, config-editable registrable domains — no wildcards, matched at a label boundary per precedence rule 3 above):
`google.com`, `bing.com`, `search.yahoo.com`, `duckduckgo.com`, `yandex.com`, `yandex.ru`, `baidu.com`, `ecosia.org`.

For organic/referral/direct, `source`/`medium` are populated the conventional way: organic → `source=<engine name>`, `medium=organic`; referral → `source=<referrer hostname>` (or the UTM values, for tagged non-paid links), `medium=referral`; direct → `source=direct`, `medium=none`. `campaign` is populated from `utm_campaign` wherever it's present (paid or tagged), and `null` otherwise.

Adding a platform or a search engine = adding a row to its respective config list. No class touches either list except the generic resolver (`Model\Service\TrafficResolver`), which is the single source of truth for `traffic_type`, `platform_code`, `source`, `medium`, **and** `campaign` — nothing downstream re-derives any of these independently.

`source`/`medium`/`campaign` are truncated by the resolver to their column widths (64/64/128 chars) before being returned — never rely on the database to truncate silently.

## 3. Data model (declarative schema — `etc/db_schema.xml`)

**`ads_analytics_visit`**
`visit_id` (PK), `visitor_uuid` (varchar, indexed), `traffic_type` (`paid`|`organic`|`direct`|`referral`, indexed; column default `unknown` — this is a bug indicator, not a real classification, since `TrafficResolver::resolve()` always returns one of the four real values; a row still showing `unknown` means the consumer failed to classify it before insert), `first_touch_source`, `first_touch_medium`, `first_touch_campaign`, `last_touch_source`, `last_touch_medium`, `last_touch_campaign`, `click_id_param`, `click_id_value`, `platform_code` (nullable — only set for `traffic_type=paid`), `landing_page`, `customer_id` (nullable, indexed), `converted` (bool), `first_seen_at`, `last_seen_at`.

**`ads_analytics_funnel_event`**
`event_id` (PK), `visit_id` (FK), `event_type` (enum: `product_view`, `add_to_cart`, `checkout_start`, `checkout_step_shipping`, `checkout_step_payment`, `checkout_step_review`, `order_placed`), `entity_id` (nullable), `created_at` (indexed).

**`ads_analytics_order_attribution`**
`order_id` (PK/FK to sales_order), `visit_id` (FK), `attribution_model` (`first_touch`|`last_touch`), `traffic_type`, `source`, `medium`, `campaign`, `platform_code` (nullable).

**`ads_analytics_daily_summary`** (cron-built, this is what admin reports read from)
`summary_id` (PK), `date`, `traffic_type`, `platform_code` (**NOT NULL**, default `null_source`), `source` (**NOT NULL**, default `null_source`), `medium` (**NOT NULL**, default `null_source`), `campaign` (**NOT NULL**, default `null_source`), `visits`, `product_views`, `add_to_carts`, `checkout_starts`, `orders`, `revenue`. Unique key includes `traffic_type` alongside the existing slice.

**`product_views` was added after Phase 3** (it was not in the original column list). Without it the funnel's widest stage was captured in `ads_analytics_funnel_event` but could never appear in a report, because admin grids read only from this table.

It counts **visits that reached a product page, not raw product pageviews** — one visit that views eight products contributes 1, not 8. This is the one column whose aggregation is not a plain sum of events, so it is worth being explicit about why:

- It keeps the column comparable to the ones either side of it, so `visits -> product_views -> add_to_carts -> checkout_starts -> orders` reads as a single funnel rather than mixing per-visitor and per-event units.
- It bounds the derived `view_rate` at 100%. A per-event count would routinely exceed the visit count and make the rate meaningless.
- It is immune to a beacon that fires twice on one page, which a per-event count would silently inflate.

The aggregator implements this by collapsing to one row per (visit, day) in a derived table before summing (`DailySummaryAggregator::productViewFacts()`). If raw pageview volume is ever wanted, it is a different metric and needs its own column — do not redefine this one.

Unlike `ads_analytics_visit` (where these same-named columns are nullable — a raw visit legitimately has no `platform_code` if it's organic), this table makes them **NOT NULL with a `'null_source'` sentinel default**.

*Why NOT NULL:* MySQL unique indexes never treat two NULLs as duplicates, so a nullable column inside the unique key would break upsert-by-unique-key for any slice containing a NULL — which is most organic/direct/referral traffic.

*What happens if the cron skips COALESCE:* **not duplicate rows — a hard failure.** A column default only applies when the column is omitted from the INSERT; passing an explicit NULL into a NOT NULL column raises MySQL error 1048 (`Column 'source' cannot be null`) and the cron dies. The cron (`Cron\AggregateDailySummary`) must substitute `'null_source'` for any NULL pulled from the raw tables before grouping/inserting. When debugging missing summary rows, look for 1048 in the cron log, not for duplicated slices.

*Why `'null_source'` and not `'none'`:* `'none'` is a real value the resolver produces — `TrafficResolver` returns `medium='none'` for direct traffic, meaning *verified: this visit had no medium*. The coalesce sentinel has to stay distinct from it, or a report cannot tell "we know there was no medium" apart from "the medium was missing from the raw row". Three distinct empty-ish values exist across the pipeline and are not interchangeable: `'none'` (verified absence, resolver), `'not_set'` (no `utm_source` on this branch, resolver), `'null_source'` (raw value was NULL, cron). A fourth, `'unknown'`, is reserved for `ads_analytics_visit.traffic_type` as a bug indicator.

## 4. Config (`etc/adminhtml/system.xml`)
- Enable/disable tracking (kill switch)
- Cookie lifetime (days)
- Click-id → platform map (editable grid or serialized array)
- Paid-mediums list (for manually-tagged paid links with no click id, e.g. `cpc`, `ppc`)
- Organic search-engine domain list (editable grid or serialized array)
- Primary attribution model: `first_touch` | `last_touch` (default: `last_touch`)
- Sampling rate (%, for high-traffic sites — optional, default 100)

## 5. Extensibility mechanism
`Api/AdSpendProviderInterface` — `getSpend(string $platformCode, \DateTime $from, \DateTime $to): array`. Implementations registered via `di.xml`:
```xml
<type name="Aavirbhava\AdsAnalytics\Model\AdSpendProviderPool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="google" xsi:type="object">Aavirbhava\AdsAnalytics\Model\AdSpendProvider\GoogleAdsProvider</item>
        </argument>
    </arguments>
</type>
```
A client-project module can add `<item name="reddit" .../>` in its own `di.xml` with zero edits here — this is the actual extension point for "add another platform later." (Ad spend/ROAS applies to `traffic_type=paid` only — organic/direct/referral have no spend concept.)

## 6. Frontend capture (Hyvä-compatible)
- Single vanilla-JS file, no RequireJS/Knockout. Loaded via layout XML `<script>` (or Hyvä's hook system if present).
- On page load: parses `URLSearchParams` and reads `document.referrer`, sends both raw to the backend (classification happens server-side, per §2 — the client never decides `traffic_type`). Sets/refreshes first-party cookie `aavirbhava_visitor_uuid`.
- Sends events via `fetch(url, {keepalive: true})` to a lightweight REST endpoint (`POST /rest/V1/adsanalytics/event`) — never blocks page render, survives click-through navigation.
- Endpoint (`Model\EventIngestService`) does nothing but publish to Magento MessageQueue and return — no validation, no DB write, no exceptions to that (see `CLAUDE.md` constraint #3). `Model\Queue\EventConsumer` does everything else, off the request thread: request logging, validation, and writes to `ads_analytics_funnel_event`/`ads_analytics_visit`.

### Ingest payload shape (draft — implement exactly this in P1-T5, adjust here first if it changes)
```json
{
  "visitor_uuid": "uuid-v4-string",
  "event_type": "landing | product_view | add_to_cart | checkout_start | checkout_step_shipping | checkout_step_payment | checkout_step_review | order_placed",
  "platform_code": "google | meta | bing | tiktok | reddit | null — IGNORED server-side, see note below",
  "click_id_param": "gclid | fbclid | msclkid | ttclid | rdt_cid | null",
  "click_id_value": "string | null",
  "utm_source": "string | null",
  "utm_medium": "string | null",
  "utm_campaign": "string | null",
  "referrer": "string | null — full document.referrer from the client; server truncates to hostname before storing, see docs/SECURITY.md",
  "landing_page": "string | null",
  "entity_id": "int | null",
  "timestamp": "ISO-8601 string"
}
```
`traffic_type` is deliberately **not** in the client payload — it's derived server-side by `Model\Service\TrafficResolver` from `click_id_param`/`utm_*`/`referrer` (§2), including `campaign`, so classification logic lives in exactly one place and stays consistent even if the client changes.

`platform_code` **is** accepted in the payload shape (kept for forward-compatibility / debugging visibility in the request log) but the consumer must always ignore/overwrite it with `TrafficResolver`'s own result — never trust or persist the client-supplied value as-is. This is intentional, not a contradiction: the field exists in the wire format; the server just never believes it.

`customer_id`/`order_id` are never accepted from the client — the consumer resolves those server-side from session/order context, never trusts a client-supplied value (see `docs/SECURITY.md`).

## 7. Checkout-step tracking — **gated by open decision**
- **If Hyvä Checkout**: Alpine.js `x-init`/`x-on` hook on step components, calling the same beacon `fetch`.
- **If LUMA/default checkout**: Knockout component `mixin` on `Magento_Checkout` step view models.
- Both call the identical backend endpoint — only the trigger mechanism differs. Confirm which is in use before starting Phase 2 checkout tasks.

## 8. Admin reporting
- Dashboard tab: funnel drop-off (visits → cart → checkout → order) by platform; source/medium/campaign breakdown; Chart.js (core's bundled copy). Every view supports a `traffic_type` filter/toggle so paid and organic can be seen separately or side by side (e.g. paid vs. organic conversion rate).
- Grid tab: filterable by day/week/month/year + custom range, `traffic_type`, platform, campaign.
- Export: Excel and PDF, same pattern as [[sales-analytics-module]].

## 9. Request logging (debugging)
Every hit to the ingest endpoint gets logged by `Model\Queue\EventConsumer` — asynchronously, off the request thread, before it decides accept/reject — separately from the parsed analytics tables, so a bad/unexpected param can be diagnosed even if it never became a valid visit/funnel row. This is *not* a synchronous write on the customer-facing request (see `CLAUDE.md` constraint #3): `Model\EventIngestService` only publishes to the queue, so even a request that will be rejected still gets logged, just asynchronously like everything else.

**`ads_analytics_request_log`**
`log_id` (PK), `received_at` (indexed), `endpoint`, `raw_payload` (text — the message body as received by the consumer), `validation_status` (`accepted`|`rejected`), `rejection_reason` (nullable — e.g. `unknown platform_code`, `oversized field: utm_campaign`), `visitor_uuid` (nullable, indexed — populated when parseable, so a debug session can be searched by visitor even if the request was rejected), `ip_hash` (hashed, never raw IP — see docs/SECURITY.md), `user_agent` (truncated), `queue_message_id` (nullable — populated if the consumer has a usable message/correlation id available; not load-bearing for anything else, purely informational).

`ip_hash`/`user_agent` **must be captured in `EventIngestService` from the current HTTP request** (cheap — reading the request object, not a DB write) and attached to what gets published, since `EventConsumer` runs in a separate process with no access to the original request context. The two aren't part of the public `EventInterface` payload contract (a client shouldn't be able to inject a fake IP), so this needs either a small wrapper around the published message or the queue envelope's own headers — resolve this concretely in P1-T5/P1-T5b, it's real implementation work, not just plumbing.

- Config (`system.xml`): logging level — `Off` / `Rejected only` / `All requests` — and a retention window for this table, kept **shorter** than the analytics tables by default (e.g. 14 days) since it stores raw, unvalidated payloads.
- Admin: read-only grid (built alongside the Phase 3 admin controller — see `docs/TASKS.md`), filterable by `validation_status`, date range, `visitor_uuid`; raw payload viewable per row. Until Phase 3's admin UI exists, query the table directly for debugging.
- Same escaping/output rules as the analytics grids apply here — `raw_payload` is attacker-controllable text (see docs/SECURITY.md).

## 10. Privacy/compliance notes
- Visitor cookie is anonymous until an order links it to `customer_id`/order — no email/name stored pre-conversion.
- `referrer` is truncated to hostname only before storage (never the full URL with path/query) — see `docs/SECURITY.md` for why.
- Respect cookie-consent gating if the store has a consent module — beacon should check for a consent flag before setting the tracking cookie (implementation detail to confirm against the store's consent solution in Phase 1).
