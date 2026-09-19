# Tasks — Aavirbhava_AdsAnalytics

Status values: `Not Started` | `In Progress` | `Done` | `Blocked`. Update status here (and mirror the summary in `PROGRESS.md`) in place — don't regenerate this file. Task IDs are stable; don't renumber.

## Phase 1 — Scaffold + capture layer
| ID | Task | Depends on | Status |
|---|---|---|---|
| P1-T1 | Module scaffold: `registration.php`, `composer.json`, `etc/module.xml`, admin menu entry under `Magento_Reports::report` | — | Done |
| P1-T2 | `etc/db_schema.xml` for `ads_analytics_visit`, including `traffic_type` column (SPECS.md §3) | P1-T1 | Done |
| P1-T3 | `etc/adminhtml/system.xml`: enable/disable, cookie lifetime, platform/click-id map, paid-mediums list, organic search-engine domain list, sampling rate (SPECS.md §4). Three `AbstractFieldArray` grids + `ArraySerialized` backends; defaults ship in `etc/config.xml` (NOT a data patch — a core_config_data row would shadow future defaults, so `Setup/Patch/Data/InstallDefaultPlatformMap.php` was removed). Platform map gained an `alt_sources` column so the facebook/instagram split becomes data, letting P1-T4 delete the last hardcoded platform conditional | P1-T1 | Done |
| P1-T3b | `etc/config.xml`: ship a `<default>` for every `system.xml` field (enabled, cookie_lifetime_days, attribution_model, sampling_rate, logging_level, both retention windows). Without it `core_config_data` has no row until an admin saves the section and every read returns null — which means `PurgeOldData` deleting against a null window | P1-T1 | Done |
| P1-T4 | `Model\Service\TrafficResolver`: all three `DEFAULT_*` constants replaced by `Model\Config\TrafficClassificationConfig` (reads system.xml, defaults from config.xml, memoised, degrades gracefully + logs critical on malformed JSON). The `meta`/`instagram` conditionals are gone — disambiguation now runs off the `alt_sources` column. Verified: zero platform/medium/engine names remain in the class, and adding Pinterest via config alone reclassified `?epik=` from direct to paid/pinterest with no code change | P1-T3 | Done |
| P1-T5 | REST endpoint `POST /rest/V1/adsanalytics/event`: publish-only, no validation/DB access (SPECS.md §6). Kill switch, deterministic per-visitor sampling, cache-backed rate limiting (now config-driven via new `rate_limit` group + `Model\Config\IngestConfig`), server-side ip_hash/user_agent overwrite, and `publish()` — all implemented. Publish failures are caught and logged, never surfaced to the storefront | P1-T2 | Done |
| P1-T5b | `ads_analytics_request_log`: **Done**. Consumer writes a `pending` row before validation, then marks accepted/rejected. At `rejected_only` (the default) accepted rows are discarded again, so the table does not grow at the rate of all traffic | P1-T5 | Done |
| P1-T6 | Queue consumer (`EventConsumer`): **Done**. Sequence is log-pending → validate → classify → persist, split across `EventValidator`, `RequestLogWriter`, `VisitManager` and `Model\Config\RequestLogConfig`. Includes the out-of-order branch (minimal `traffic_type=unknown` visit, backfilled by a later landing) and the `click_id_param` allow-list guard. Required fixing `etc/queue_consumer.xml`, which used non-existent `class`/`method` attributes instead of `handler="Class::method"` — the consumer had no handler and silently acked every message | P1-T5, P1-T4, P1-T5b | Done |
| P1-T7 | Frontend vanilla-JS beacon: **Done**. UTM/click-id capture, `document.referrer`, first-party cookie with sliding lifetime, `fetch(keepalive)`. Config is injected inline by `Block\Frontend\BeaconConfig` (no config-endpoint round trip); the beacon is told click-id param NAMES only and classifies nothing. Kill switch removes both the config global and the script download. Verified with real headless Chrome | P1-T4, P1-T5 | Done |
| P1-T8 | Cookie-consent check in beacon: **Done**. Gate resolved — the store runs only `Magento_Cookie` (native cookie-restriction mode), no third-party CMP. `BeaconConfig` delegates to `Magento\Cookie\Helper\Cookie::isCookieRestrictionModeEnabled()`; the beacon reads `user_allowed_save_cookie` at runtime and sets no cookie and sends nothing until consent. Verified live (0 events without consent) and across all four consent branches | P1-T7 | Done |
| P1-T9 | Input validation: **Done** in `EventValidator` — `event_type` enum, `click_id_param` against the config map, per-field character limits, and the empty-`visitor_uuid` hole found in live security testing. `platform_code` deliberately not validated (discarded and recomputed). Rate limiting stays at the webapi layer, already done in P1-T5 | P1-T6 | Done |
| P1-T9b | Generate `etc/db_schema_whitelist.json` and commit it. Note: tables/columns **are** created without it — `Diff::canBeRegistered()` only consults the whitelist for destructive operations — so this is needed for future column/index removal and coding-standard compliance, not to make the schema install (confirmed: schema installed fine before the file existed) | P1-T2, P1-T5b | Done |
| P1-T10 | Unit + integration tests: **Done**. Unit 104 tests/164 assertions; integration 26 tests/58 assertions covering schema reality (tables, NOT NULL sentinels, composite unique key, MySQL 1048 on explicit NULL, funnel-event FK) and the consumer against a real DB using the shipped `config.xml` defaults. Integration install is disposable — setup/teardown documented in docs/TESTING.md §4b; it was torn down after this run | P1-T6, P1-T9 | Done |
| P1-T11 | Manual verification: **Done**. Real headless Chrome, fresh profile per case: `?gclid=` -> paid/google/cpc; no params -> direct/none; `utm_source=newsletter&utm_medium=email` -> referral/email. Organic verified at both layers it depends on (beacon forwards `document.referrer` verbatim; server classifies `www.google.com` as organic/google) — a cross-origin referrer from a real search engine cannot be synthesised in headless Chrome | P1-T6, P1-T7 | Done |
| P1-T12 | `tools/simulate_traffic.py` smoke test: **Done**. 300 visitors -> 799 events accepted, 0 failed, 799 queued, drained to 0. Database reconciled EXACTLY on every dimension: visitors 300/300, add_to_cart 68/68, checkout_start 32/32, order_placed 3/3; traffic split paid 119 / organic 85 / referral 49 / direct 47; all five platform_codes exact; 0 rejected rows | P1-T9 | Done |

## Phase 2 — Funnel events + order attribution
**Unblocked (2026-09-19).** Checkout-type decision resolved: implement for default LUMA; leave Hyvä as a documented drop-in adapter (README.md). See CLAUDE.md §Resolved decisions.

| ID | Task | Depends on | Status |
|---|---|---|---|
| P2-T1 | `etc/db_schema.xml` for `ads_analytics_funnel_event` and `ads_analytics_order_attribution` | P1-T2 | Done |
| P2-T2 | Observer: `checkout_cart_product_add_after` → `add_to_cart` event | P2-T1 | Not Started |
| P2-T3 | Checkout-step hook — **LUMA variant Done**. `view/frontend/web/js/checkout-luma.js` subscribes to `Magento_Checkout/js/model/step-navigator` and calls the beacon's new public `track()` API; scoped to `checkout_index_index.xml` so no RequireJS/Knockout reaches any other page. Hyvä adapter deliberately NOT shipped — instructions in README.md. Note LUMA has no separate review step, so `checkout_step_review` is never emitted there | P2-T1, decision resolved | Done |
| P2-T4 | Observer: `sales_order_place_after` → `order_placed` event + write `ads_analytics_order_attribution` (first/last touch per config, including `traffic_type` from the linked visit) | P2-T1 | Not Started |
| P2-T5 | Confirm `customer_id`/`order_id` are resolved server-side only, never accepted from client payload (docs/SECURITY.md §3) | P2-T2, P2-T3, P2-T4 | Not Started |
| P2-T6 | Manual verification: full funnel walk-through (landing → cart → checkout steps → order), confirm all rows + correct attribution | P2-T2, P2-T3, P2-T4 | Not Started |
| P2-T7 | Run `tools/simulate_traffic.py` at higher volume (`--visitors 500`) to confirm queue drains cleanly and checkout latency is unaffected (docs/TESTING.md §5) | P2-T6 | Not Started |

## Phase 3 — Aggregation + admin reporting
| ID | Task | Depends on | Status |
|---|---|---|---|
| P3-T1 | `etc/db_schema.xml` for `ads_analytics_daily_summary`, including `traffic_type` in columns and unique key (SPECS.md §3) | P2-T1 | Not Started |
| P3-T2 | Cron job: rolls raw visit/funnel/attribution data into daily summary, grouped by (`date`, `traffic_type`, `platform_code`, `source`, `medium`, `campaign`). Must COALESCE NULLs from the raw tables to `'null_source'` before grouping/inserting — skipping this raises MySQL error 1048 and kills the cron (it does **not** silently duplicate rows), and `'null_source'` must stay distinct from the resolver's `'none'` | P3-T1 | Not Started |
| P3-T3 | Admin controller + layout: dashboard page. **Layouts now exist** (`ads_analytics_report_index.xml`, `ads_analytics_requestlog_index.xml`) — both pages previously rendered blank because `view/adminhtml/layout/` was an empty directory. Both controllers now implement `HttpGetActionInterface`. Tabs (Dashboard/Grid) still to do | P1-T1 | In Progress |
| P3-T4 | Dashboard tab: funnel drop-off viz + source/medium/campaign breakdown, Chart.js (core bundled copy); paid-vs-organic-vs-direct-vs-referral toggle/breakdown (SPECS.md §8) | P3-T2, P3-T3 | Not Started |
| P3-T5 | Grid tab: UI Component grid, filterable by day/week/month/year + custom range, `traffic_type`, platform, campaign | P3-T2, P3-T3 | Not Started |
| P3-T5b | Admin "Request Log" grid (read-only): **Done**. UI Component listing + `Model\ResourceModel\RequestLog\Grid\Collection` registered on the `CollectionFactory`, filterable by validation_status (pending/accepted/rejected), date range and visitor_uuid. `raw_payload`/`user_agent` use the stock escaping text column and are hidden by default. Verified: data source resolves via `getReport()` and returns rows | P3-T3 | Done |
| P3-T6 | Manual verification: cron run produces correct summary rows; dashboard/grid numbers match raw data | P3-T4, P3-T5 | Not Started |
| P3-T7 | Data-retention purge cron for raw event tables and `ads_analytics_request_log`, each with its own configurable window (docs/SECURITY.md §8) | P3-T1, P1-T5b | Not Started |
| P3-T8 | Run `tools/simulate_traffic.py` with a known seed, reconcile dashboard/grid output against the printed summary (docs/TESTING.md §5) | P3-T6 | Not Started |

## Phase 4 — Export + ad-spend/ROAS
| ID | Task | Depends on | Status |
|---|---|---|---|
| P4-T1 | Excel export (grid + dashboard data) | P3-T5 | Not Started |
| P4-T2 | PDF export | P3-T5 | Not Started |
| P4-T3 | `Api/AdSpendProviderInterface` + `AdSpendProviderPool` (di.xml virtual-type array) | P3-T1 | Not Started |
| P4-T4 | Reference/example provider implementation (e.g. CSV-import based) | P4-T3 | Not Started |
| P4-T5 | ROAS calculation in dashboard (spend vs. revenue, where spend data available) | P4-T3, P3-T4 | Not Started |
| P4-T6 | Store any provider API credentials via encrypted config backend (docs/SECURITY.md §10) | P4-T4 | Not Started |
| P4-T7 | Final regression: re-run `tools/simulate_traffic.py`, full sign-off checklist (docs/TESTING.md §6) | P4-T1, P4-T2, P4-T5, P4-T6 | Not Started |

## Recommended order
P1 (all) → resolve checkout-type decision → P2 (all) → P3 (all) → P4 (T1/T2 and T3–T5 can run in parallel, they're independent, then T6 → T7).
