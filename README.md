# Aavirbhava_AdsAnalytics

Magento 2 module that tracks paid-ad visits, funnel progression (landing →
product view → cart → checkout steps → order), and attributes orders back to
ad platforms and campaigns — with async ingest, nightly aggregation, admin
reporting (dashboard, filterable grid, Excel/CSV/PDF export), and ROAS
(return on ad spend).

**Status: Phases 1–4 complete.** This is a working module, not a scaffold —
capture, attribution, aggregation, reporting and export all run end to end
and are covered by unit and integration tests. Phase 5 (an offline access-log
analysis page, unrelated to the live tracking pipeline below) is planned but
not yet implemented — see `docs/TASKS.md`.

**Docs map**, in the order worth reading them:
- `docs/SPECS.md` — schema, config, interfaces, payload contracts
- `docs/SECURITY.md` — threat model, validation, retention, ACL, secrets
- `docs/TASKS.md` — ordered task list per phase (source of truth for status)
- `docs/PROGRESS.md` — detailed session-by-session history and findings
- `docs/TESTING.md` — test strategy and how to use `tools/simulate_traffic.py`
- `CLAUDE.md` — standing architectural rules for anyone working on this code

## Install (composer path repository, typical local dev setup)
```json
"repositories": [
    { "type": "path", "url": "../aavirbhava-ads-analytics" }
]
```
```bash
composer require aavirbhava/module-ads-analytics:@dev
bin/magento module:enable Aavirbhava_AdsAnalytics
bin/magento setup:upgrade
```
A message queue (RabbitMQ) must be configured and running — see
"Limitations" below for what happens if it is not.

## How capture works
1. **A vanilla-JS beacon** (`view/frontend/web/js/ads-analytics-beacon.js`) on
   the storefront reads UTM/click-id params and `document.referrer` on
   landing, tracks funnel events, and posts each one to
   `POST /rest/V1/adsanalytics/event`.
2. **The webapi endpoint** (`Model\EventIngestService`) does nothing but
   apply the kill switch, sampling and rate limiting, then publish to a
   queue — no database write happens on the customer-facing request.
3. **A queue consumer** (`Model\Queue\EventConsumer`) does every database
   write off that request thread: logs the raw request, validates it,
   classifies the traffic (`Model\Service\TrafficResolver`, entirely
   config-driven — see "Paid Platform / Click-ID Map" in admin config), and
   writes the visit/funnel/attribution rows.
4. **A nightly cron** (`aavirbhava_adsanalytics_aggregate_daily_summary`)
   rolls those raw rows into `ads_analytics_daily_summary`, which is the
   only table the admin dashboard, grid and exports read from.
5. **A second cron** (`aavirbhava_adsanalytics_purge_old_data`) deletes raw
   per-visitor data and request-log rows past their own, separately
   configurable retention windows. The aggregated summary is never purged.

Server-side events (`add_to_cart`, `order_placed`) are raised by Magento
observers through the same pipeline, via a trusted internal entry point the
public endpoint cannot reach — see `docs/SECURITY.md` §3.

## Checkout-step tracking: LUMA now, Hyvä as a drop-in

Checkout is the one genuinely theme-specific part of this module, so it is
built as a **shared core plus a thin per-theme adapter**:

| Layer | File | Stack |
|---|---|---|
| Shared beacon (all themes) | `view/frontend/web/js/ads-analytics-beacon.js` | Vanilla JS |
| LUMA checkout steps | detected in the beacon from `window.location.hash` | Vanilla JS |
| Hyvä checkout adapter | *not shipped — see below* | Alpine.js |

LUMA needs no adapter at all: Magento's `step-navigator` sets
`window.location.hash` to the active step code, so the vanilla beacon detects
steps with a `hashchange` listener. **No RequireJS, Knockout or jQuery is used
anywhere on the storefront.**

**No Hyvä adapter ships.** Hyvä Checkout is a paid product, so this
module does not assume you have it; the seam is left open instead of shipping
an untested implementation of a path most stores will not use.

The beacon exposes one public function. That is the entire contract:

```js
window.aavirbhavaAdsAnalytics.track(eventType, extra);
```

It already owns the visitor cookie, the consent gate (Magento's
cookie-restriction mode), the request envelope, the endpoint URL and
per-page-load deduplication. An adapter supplies nothing but *when* to fire
and *which* event type — it must not build payloads or read cookies itself.

Valid `eventType` values are the ones in `docs/SPECS.md` §6:
`checkout_start`, `checkout_step_shipping`, `checkout_step_payment`,
`checkout_step_review`, `order_placed`.

`track()` returns `false` if the event was suppressed (already fired this page,
or consent not given). It never throws.

### Adding a Hyvä Checkout adapter

No PHP class, no `di.xml` entry and no change to the beacon is required.

1. **Write the adapter.** In your Hyvä theme or a small companion module, hook
   Hyvä Checkout's step transitions and call `track()`:

   ```js
   // Alpine component, or a listener on Hyvä Checkout's step events
   const track = (type) => {
       try {
           window.aavirbhavaAdsAnalytics?.track(type);
       } catch (e) { /* tracking must never break checkout */ }
   };

   track('checkout_start');
   // then, as the customer advances:
   track('checkout_step_shipping');
   track('checkout_step_payment');
   track('checkout_step_review');
   ```

2. **Load it only on Hyvä checkout.** Nothing else in the module needs to know
   it exists. Do NOT emit it as an inline `<script>` — Magento's CSP blocks
   inline scripts on checkout, which is exactly how the LUMA path failed
   during development.

3. **Map Hyvä's step names to the event types above.** This is the part that
   cannot be shared, and the reason the adapter exists: default LUMA registers
   only two steps (`shipping` and `payment`, the latter titled "Review &
   Payments"), so **`checkout_step_review` is never emitted on LUMA**. Hyvä
   Checkout does split review into its own step, so a Hyvä adapter can emit all
   four and will produce a more granular funnel than LUMA can.

4. **Verify** the same way the LUMA path was: walk a checkout, then check that
   `ads_analytics_funnel_event` has one row per step for your `visitor_uuid`.
   Remember the queue consumer must be running for rows to appear —
   `bin/magento queue:consumers:start AavirbhavaAdsAnalyticsEventConsumer`.

Nothing server-side is theme-aware: events from either adapter go through the
same REST endpoint, the same queue and the same `EventConsumer`, and are
validated against the same event-type allow-list.

## Admin reporting

**Reports > Ads Analytics** — a single page (funnel/traffic-type charts, a
filterable grid, ROAS, and ad-spend data), rather than the separate
Dashboard/Grid tabs the original spec sketched: the charts bind to the
grid's own data provider so filtering the grid redraws them too, which a
second, independent chart data-fetch could not guarantee stays consistent.

- **Excel / CSV export** on the grid uses Magento's own stock export
  mechanism — no custom code. Every SQL-derived column (conversion rate,
  cart rate, revenue per visit) is included.
- **PDF export** (`Download PDF Report`) is a one-page summary — funnel
  totals, traffic-type breakdown, top paid campaigns, ROAS by platform — for
  the whole dataset or an explicit `?from=&to=` range, built with
  `\Zend_Pdf` (the same library Magento_Sales uses for invoices, so no new
  dependency).
- **ROAS** (return on ad spend), one row per platform, needs a spend figure
  per platform. See the next section for where that comes from.
- **Reports > Ads Analytics Request Log** is a separate, read-only debugging
  grid over raw (accepted and rejected) ingest requests, with its own short
  retention — not an analytics surface, see `docs/SECURITY.md` §8–9.

### Ad spend / ROAS

This module ships one ad-spend provider, `Model\AdSpendProvider\CsvAdSpendProvider`,
which reads a CSV file per platform rather than calling a live ad-platform
API (Google Ads and Meta Ads both require an approved OAuth developer
account, which a fresh install does not have — see "Limitations").

Two ways to get a CSV into place:

1. **Upload it** — Reports > Ads Analytics, the "Ad Spend Data" section near
   the bottom of the page. One small form per platform your store already
   classifies traffic for (see "Paid Platform / Click-ID Map" in config); a
   file is validated (rejected outright if it has zero usable rows) before
   anything is written, and a new upload replaces the previous file for that
   platform.
2. **Place it on the server directly**, at
   `var/aavirbhava/adsanalytics/adspend/<platform_code>.csv` — useful for a
   scripted/scheduled export from the ad platform's own UI.

Either way, the file needs a header row, then one row per day per campaign:

```
date,campaign,spend
2026-09-19,spring_sale,42.50
```

`date` is `YYYY-MM-DD`; `campaign` should match the campaign name already
used in your ad tracking links; `spend` is a plain number in your store's
currency — **no currency symbol and no thousands separator** (`1234.56`, not
`1,234.56` — the most common mistake, from copying a formatted currency
column straight out of Excel or Google Sheets). A row with a mistake in it
is skipped, with the reason reported, rather than rejecting the whole file.

**Any platform your store already lists** in "Paid Platform / Click-ID Map"
gets this CSV mechanism automatically — you do not need a developer to
register a new platform in `etc/di.xml` first. A real, live-API-backed
provider for a specific platform (once you have API access) is added the
same way, via `etc/di.xml` in your own module, and takes priority over the
generic CSV reader for that platform — see `docs/SPECS.md` §5.

## Cron jobs and CLI commands

Two cron jobs run in the `default` group:

| Job | Schedule | What it does |
|---|---|---|
| `aavirbhava_adsanalytics_aggregate_daily_summary` | `0 2 * * *` | Rolls raw visit/funnel/order rows into `ads_analytics_daily_summary`, re-sweeping a configurable lookback window (default 7 days) so events that arrived late through the queue are picked up. The rollup is idempotent. |
| `aavirbhava_adsanalytics_purge_old_data` | `30 2 * * *` | Deletes raw per-visitor data and request-log rows past their retention windows. Scheduled after the aggregation job so a day is always summarised before it can be purged. |

Both are also runnable by hand:

```bash
# Rebuild the summary for the last 7 days (idempotent — safe to re-run).
bin/magento aavirbhava:adsanalytics:aggregate --days=7

# Backfill a specific range.
bin/magento aavirbhava:adsanalytics:aggregate --from=2026-01-01 --to=2026-01-31

# See what a retention purge would delete, without deleting it.
bin/magento aavirbhava:adsanalytics:purge --dry-run

# Purge using the configured windows.
bin/magento aavirbhava:adsanalytics:purge
```

**The aggregate command refuses `--from` dates older than the raw-event retention window.** Reports read from `ads_analytics_daily_summary`, which is kept indefinitely, but the raw rows behind it are not — so recomputing a purged date would replace real figures with zeros. Pass `--force` only if you understand that. Raising the retention window does not bring purged rows back.

**Retention is configured per table**, under Stores > Configuration > Aavirbhava > Ads Analytics: raw events default to 180 days, and the request log to 14, because it stores raw unvalidated request payloads. Setting either to **0 disables that purge** rather than deleting everything.

## Configuration

Stores > Configuration > **Aavirbhava** > Ads Analytics: kill switch, cookie
lifetime, attribution model, sampling rate, paid-platform/click-ID map, paid
mediums, organic search-engine domains, rate limiting, request-log level and
retention, raw-event retention, and where to find the ad-spend upload option.
Every field's on-page help text is written for a store admin, in plain
language — it never points at a doc file or a PHP class name a browser can't
open.

## Limitations

These are known, deliberate trade-offs or open gaps — not oversights that
went unnoticed. Each is discussed in more depth in `docs/PROGRESS.md` and,
where relevant, `docs/SECURITY.md`.

- **A broker outage is invisible.** If RabbitMQ is down or unreachable, the
  ingest endpoint still returns HTTP 200 to the storefront (a tracking
  beacon must never surface an error to a shopper) and the publish failure
  is only logged, not surfaced anywhere in the admin. Every signal a
  merchant would normally check — the storefront, the endpoint's response —
  says "fine" while every event is silently dropped. There is no admin
  health indicator or failure counter for this today; watch
  `var/log/system.log` for `failed to publish` if traffic data unexpectedly
  stops growing.
- **The queue consumer is not idempotent.** AMQP is an at-least-once
  delivery guarantee, so a redelivered message (after a consumer crash or
  restart mid-message) writes a duplicate request-log row and a duplicate
  funnel event. There is no de-duplication key on the wire format today.
- **Dates are bucketed in UTC, not the store's timezone**, in the daily
  summary. For a store far from UTC, "yesterday" in the report is a UTC
  day, which can be off by several hours from the store's own calendar day.
- **The funnel is not guaranteed to be monotonic.** `visits`, `add_to_carts`
  and `orders` are recorded server-side; `checkout_starts` and the checkout
  step events come from the storefront beacon. An ad blocker, a declined
  cookie-consent prompt, or a dropped request can lose the beacon-side
  stage while the server-side order still lands — so a real slice can show
  more orders than checkout starts. The dashboard chart plots absolute
  counts for exactly this reason, never a percentage of the previous stage.
- **Ad spend is a manual, periodic CSV, not a live feed.** Nothing polls
  Google Ads or Meta Ads automatically; the numbers are only as fresh as the
  last upload or file placement. A live API-backed provider is a documented
  extension point (see "Ad spend / ROAS" above), not something this module
  ships.
- **ROAS and revenue-vs-spend comparisons assume a single-currency store.**
  Revenue is `base_grand_total` (Magento's base currency); ad spend is
  whatever currency the uploaded CSV happens to contain. Nothing here
  converts between them.
- **The attribution model is applied at order-placement time, not
  retroactively.** Changing "Primary Attribution Model" (first-touch vs.
  last-touch) in config only affects orders placed after the change;
  existing `ads_analytics_order_attribution` rows keep whichever model was
  configured when each order was placed.
- **No Hyvä Checkout adapter ships** — a paid product this module does not
  assume you have. See "Adding a Hyvä Checkout adapter" above.
- **The traffic simulator (`tools/simulate_traffic.py`) ships inside the
  module** rather than as a separate dev-only package, and it structurally
  cannot exercise `add_to_cart` or `order_placed` (both are server-only by
  design — see `docs/SECURITY.md` §3), so those columns and revenue can only
  be verified with a real add-to-cart and a real checkout, not the
  simulator.
- **A handful of traffic-classification edge cases are accepted defaults,
  not bugs:** a referral from `mail.google.com` or `sites.google.com`
  classifies as organic Google traffic, because both are genuine
  subdomains of a domain in the organic search-engine list. The default
  "Paid Platform / Click-ID Map" covers `google`, `meta`, `bing`, `tiktok`
  and `reddit`; a click-id parameter from a platform not in that list
  (Twitter/X's `twclid`, LinkedIn's `li_fat_id`, Pinterest's `epik`, a
  newsletter's `mc_cid`, etc.) is not recognised as paid until an admin adds
  a row for it — visible in the Request Log grid's "Page URL" /
  "Resolved Traffic Type" columns, which exist specifically to surface this.
- **`product_views` in the daily summary counts visits that viewed at least
  one product, not raw pageview volume** — a visitor viewing eight products
  contributes 1, not 8, so the figure stays comparable to `visits` and any
  rate derived from it stays bounded at 100%. Raw pageview volume is not
  tracked as a separate metric.
