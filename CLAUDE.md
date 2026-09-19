# CLAUDE.md — Aavirbhava_AdsAnalytics

Read this file first in every session on this module. It is the standing contract for how work here gets done. Update it in place when conventions change — never regenerate it from scratch.

## What this module is
A composer-installable Magento 2 module (`aavirbhava/module-ads-analytics`) that tracks paid-ad visits, funnel progression (visit → product view → cart → checkout steps → order), and attributes orders back to ad platforms and campaigns. Reports live in the admin panel with Chart.js visuals and Excel/PDF export.

Companion project: [[sales-analytics-module]] (`Aavirbhava_SalesAnalytics`) — separate module, same vendor namespace, focused on sales/product performance rather than ad attribution. Don't merge scope between them.

## Where things live
- `docs/SPECS.md` — the technical spec: schema, config, interfaces, contracts. Source of truth for "how should this work."
- `docs/TASKS.md` — ordered, granular task list across phases with dependencies.
- `docs/PROGRESS.md` — live status tracker: what's done, in progress, blocked. Updated after every work session.
- `docs/SECURITY.md` — threat surface, input validation, rate limiting, PII/consent, data retention, ACL, secrets.
- `docs/TESTING.md` — test strategy per phase, sign-off checklist, and how to use the traffic simulator.
- `tools/simulate_traffic.py` — synthetic multi-platform traffic generator for smoke/load testing the ingest endpoint (see docs/TESTING.md §5).
- `SKILL.md` — module-specific coding conventions Claude Code must follow while implementing.

## Doc update policy (important)
Never hand back a fresh copy of `TASKS.md`, `PROGRESS.md`, or `SPECS.md`. Every Claude Code task prompt for this module must instruct: **update the existing file in place** (edit the relevant section/checkbox), not regenerate it. Keep prompts token-efficient — only as much ceremony (inspect-first lists, verification steps) as the specific task actually needs.

## Non-negotiable architectural constraints
1. **Declarative schema only** (`etc/db_schema.xml` + data patches). No `InstallSchema.php`/`UpgradeSchema.php`.
2. **No platform- or source-specific code.** Ad platforms, paid mediums, and organic search engines are all config-driven lists (docs/SPECS.md §2), resolved by one generic `Model\Service\TrafficResolver` — not per-source classes or conditionals. Ad-spend/ROAS providers (paid only) go behind `Api/AdSpendProviderInterface` (see SPECS.md §5). Adding a new ad platform, a new search engine, or a new spend provider must never require touching this module's core classes.
3. **No synchronous DB writes on customer-facing requests — no exceptions, including request logging.** `Model\EventIngestService` (the webapi entry point) does nothing but publish to the queue; `Model\Queue\EventConsumer` does every DB write, including the request-log row, off the request thread. Checkout performance is not allowed to regress.
4. **Hyvä-compatible.** The SHARED storefront code — the beacon and anything loaded on every page — is vanilla JS with no RequireJS/Knockout/jQuery dependency. Admin can use standard Magento UI Components (Knockout there is fine — Hyvä doesn't touch admin).
   **Exception, added when P2-T3 was implemented:** a checkout is unavoidably theme-specific, so each theme gets a thin *adapter* written in that theme's own stack, scoped by layout handle to that theme's checkout page only. `view/frontend/web/js/checkout-luma.js` is RequireJS/Knockout and loads solely via `checkout_index_index.xml`; a Hyvä store never loads it. An adapter must contain no tracking logic — it calls the beacon's public `window.aavirbhavaAdsAnalytics.track(eventType)` and nothing else, so the cookie, consent gate and payload envelope stay in one vanilla place. Never relax this into "the storefront can use RequireJS": the shared path stays vanilla.
5. **No PII beyond what's already on the order.** Visitor tracking uses a first-party anonymous cookie UUID, not email/name, until/unless it's linked to an order.
6. **Aggregation, not live joins.** Reports read from `ads_analytics_daily_summary`, built by cron. Never query raw event tables for admin grids at request time.
7. **Never put a nullable column inside a unique-key constraint.** MySQL doesn't dedupe NULLs, so a nullable grouping column breaks upsert-by-unique-key silently (see `ads_analytics_daily_summary` in `etc/db_schema.xml` for the concrete example — `platform_code`/`campaign` are NOT NULL with a `'null_source'` sentinel default specifically because of this — `'none'` is a different, real value meaning a verified absence). Raw tables can stay nullable; anything cron-aggregated with a unique key cannot.
8. **Every ingest request is logged**, into `ads_analytics_request_log`, by the consumer before it decides accept/reject — separate table, own shorter retention, own admin grid. This is a debugging surface, not an analytics one; don't conflate it with the visit/funnel tables.

## Resolved decisions
- **Checkout type (resolved 2026-09-19): LUMA now, Hyvä as a documented drop-in.** Hyvä Checkout is a paid product, so the module does not assume the store has it. The default LUMA checkout adapter ships; the Hyvä adapter does not, and `README.md` §"Adding a Hyvä Checkout adapter" tells a developer how to add one without touching this module's core or any PHP class. Do NOT ship a speculative Hyvä implementation — the extension point is the deliverable.
  Consequence worth remembering: default LUMA registers only two steps (`shipping`, `payment` — the latter titled "Review & Payments"), so **`checkout_step_review` is never emitted on LUMA**. Any report must treat that event as optional rather than assuming a four-step funnel.

## Open decisions (resolve before touching gated tasks)
_(none currently open)_

## Status reports
Drop dated session summaries in `docs/status-reports/YYYY-MM-DD.md` (short: what changed, what's next). Don't fold these into PROGRESS.md itself — PROGRESS.md stays a current-state snapshot, not a log.
