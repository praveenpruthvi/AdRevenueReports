# Progress — Aavirbhava_AdsAnalytics

Update this file in place after every work session. It's a current-state snapshot, not a log — session-by-session detail goes in `docs/status-reports/`.

**Last updated:** 2026-09-18
**Current phase:** Phase 1 — environment installed and schema verified live; capture pipeline not yet implemented
**Current task:** Phase 1 complete (15/15). Phase 2 **unblocked** — checkout-type decision resolved (LUMA now, Hyvä documented drop-in); P2-T1 and P2-T3 Done. Next: P2-T2 (add-to-cart observer) and P2-T4 (order attribution). Phase 1 scaffold and architecture are done, plus the first real method bodies: `Model\EventIngestService` (rate limiting + ip/UA capture) and `Model\Service\TrafficResolver` (fully implemented). Everything else is still a `TODO` stub

## Phase status
| Phase | Status |
|---|---|
| 1 — Scaffold + capture layer | **Complete (15/15)** — verified end to end in a real browser, reconciled exactly against a 300-visitor simulation, 104 unit + 26 integration tests green |
| 2 — Funnel events + order attribution | Blocked (checkout-type decision) |
| 3 — Aggregation + admin reporting | Not Started |
| 4 — Export + ad-spend/ROAS | Not Started |

## Open decisions / blockers
_(none — the checkout-type decision was resolved 2026-09-19; see CLAUDE.md §Resolved decisions)_

## Completed
- Full architecture/scaffold for Phase 1–4: module structure, `etc/*` config, five-table schema, webapi/queue wiring, admin menu/config, Hyvä-safe beacon skeleton.
- Paid **and** organic/direct/referral traffic classification designed and scaffolded: `traffic_type` dimension across `ads_analytics_visit`/`order_attribution`/`daily_summary`, `Model\Service\TrafficResolver` (paid → tagged-non-paid UTM → organic → referral → direct precedence, case-insensitive matching, label-boundary search-engine matching, campaign resolution, output length clamping).
- Third audit round fixed (this session):
  - `ip_hash`/`user_agent` are now real `EventInterface` fields with consts, getters/setters and a documented server-overwrite rule, matching the `platform_code` pattern.
  - `EventIngestService` now does cache-backed rate limiting (keyed on ip_hash+visitor_uuid), hashes the IP via `Encryptor::hash()` (HMAC-SHA256 on the install crypt key, 64 hex chars — fits `ip_hash varchar(64)`), truncates the UA to 255, and sets both on the event before publish. Still zero DB writes.
  - `ads_analytics_request_log.validation_status` now defaults to `'pending'` (NOT NULL retained) so the pre-validation insert succeeds; `'pending'` left on a row means the consumer died mid-message.
  - `EventConsumer` sequence now specifies the `click_id_param` allow-list guard (the actual lever for forged paid attribution, previously unguarded) and the missing-visit branch (create a minimal `traffic_type=unknown` row rather than failing the NOT NULL FK; a later landing backfills it instead of duplicating).
  - `TrafficResolver`: `mb_substr` instead of `substr` (byte-wise truncation was producing invalid UTF-8 that MySQL rejects in strict mode, and over-truncating non-ASCII), `click_id_value` now clamped too, `medium` lowercased on output, and the tagged-non-paid source fallback changed from `'unknown'` to `'not_set'` so it stops colliding with `visit.traffic_type`'s bug indicator.
  - Corrected the "skipping COALESCE duplicates rows" claim in `AggregateDailySummary`, `db_schema.xml` and SPECS §3 — with NOT NULL columns an explicit NULL raises MySQL 1048 and the cron fails loudly. Coalesce sentinel changed `'none'` → `'null_source'` so it stays distinct from the resolver's verified-absence `'none'`.
  - Reconciled rate-limit placement across SECURITY §4, TASKS P1-T9 and `EventIngestInterface`'s docblock (webapi layer, not the consumer); dropped the `platform_code` validation rule from SECURITY §2 since the value is always discarded and recomputed; corrected P1-T9b's false claim that tables are skipped without a schema whitelist.
- Two earlier audit rounds against the scaffold found and fixed: a nullable-column unique-key bug in `ads_analytics_daily_summary` (silent duplicate rows), a sync-write contradiction between request logging and the async-only rule (resolved — `EventIngestService` is now publish-only, `EventConsumer` owns all DB writes including the request log), missing PHPDoc on webapi-facing interfaces, Instagram `platform_code` instability, and several `TrafficResolver` correctness bugs (case sensitivity, substring-vs-suffix search-engine matching, dropped UTM tagging, dropped campaign).

### P2-T3 complete — LUMA checkout adapter, Hyvä left as a drop-in (2026-09-19)

**Decision resolved by the user:** Hyvä Checkout is a paid product, so the module targets the default LUMA checkout and leaves Hyvä as a documented extension point rather than shipping a speculative implementation.

Shape: a **shared vanilla beacon plus a thin per-theme adapter**. The beacon now exposes one public function, and that is the whole contract:

```js
window.aavirbhavaAdsAnalytics.track(eventType, extra)
```

It owns the cookie, the consent gate, the payload envelope, the endpoint and per-page deduplication. An adapter supplies only *when* to fire and *which* event type. `view/frontend/web/js/checkout-luma.js` subscribes to `Magento_Checkout/js/model/step-navigator` and calls it; a Hyvä developer writes an Alpine adapter calling the same function, with instructions in README.md — no PHP, no di.xml, no change to the beacon.

**CLAUDE.md #4 had to be refined, not broken.** As written it forbade RequireJS/Knockout anywhere on the storefront, which would have made a LUMA checkout hook impossible. The rule is now: the SHARED path stays vanilla; a theme adapter may use that theme's stack, scoped by layout handle to that theme's checkout page only. `checkout-luma.js` loads solely via `checkout_index_index.xml`, so a Hyvä store never fetches it. The constraint text says explicitly not to relax this into "the storefront can use RequireJS".

**LUMA has only two steps.** It registers `shipping` and `payment` (titled "Review & Payments") — there is no separate review step, so **`checkout_step_review` is never emitted on LUMA**. Hyvä Checkout does split review out, so a Hyvä adapter will produce a more granular funnel. Reports must treat that event as optional rather than assuming four steps.

Verified:
- Merged layout registers the component on `checkout_index_index` (checked via `Layout\ProcessorFactory`, no cart needed); both JS files serve 200 as static assets.
- Adapter logic driven against a mocked step-navigator: `checkout_start` on open, `checkout_step_shipping` on the shipping step becoming visible, `checkout_step_payment` on advancing. **25 repeated Knockout re-evaluations still produced only 3 events** — the dedupe in `track()` is what makes this safe. Unknown third-party step codes are ignored, and a missing beacon (tracking disabled) does not throw.
- Full sequence landed end to end: landing → add_to_cart → checkout_start → checkout_step_shipping → checkout_step_payment → order_placed, all five funnel rows attributed to `paid`/`google`, 0 rejections.

Not done in a live browser: a complete click-through of the real LUMA checkout. Building a cart needs a form key that Magento injects client-side (so curl cannot), and driving Chrome over CDP kept dying in this environment. That walkthrough is P2-T6's job and is worth doing by hand.

### P1-T10 complete — integration tests (2026-09-19)

**26 tests / 58 assertions, all passing**, then the whole install was torn down. Footprint was only ~106MB (34MB database + 72MB sandbox), not the ~1GB previously estimated here — a Magento install with no sample data is small, so this is cheap to redo. Setup and teardown are documented in `docs/TESTING.md` §4b.

They assert what unit tests structurally cannot: that `db_schema.xml` really produces the tables, NOT NULL sentinels and the six-column unique key; that MySQL rejects an explicit NULL into a sentinel column; that the funnel-event FK refuses an orphan; and that the shipped `config.xml` defaults classify paid/organic/direct correctly once Magento loads them for real (the unit tests mock that config, so this is the first time the real defaults are exercised).

Three environment-specific traps, all worth knowing before re-running:
1. `setup:install` does **not** create the database. It fails with "Database 'magento_integration_tests' does not exist"; create it first.
2. The install died at step 1111/1531 with `Cannot instantiate interface Magento\TwoFactorAuth\Api\UserConfigManagerInterface`. Cause: `MarkShust_DisableTwoFactorAuth` sets `Magento_TwoFactorAuth => 0` in `app/etc/config.php`, so the compiled DI map has no preference for it — but the integration installer enables every module by default while reusing that same compiled DI. Fixed by passing `disable-modules` in the install config so the two agree.
3. Integration test classes must not use **non-nullable typed properties**. The framework nulls test properties between tests to reclaim memory, which is a fatal error on a typed property. Use untyped properties with `@var` docblocks, as core's own tests do.

Also: never pipe a failing integration run through `tail`. The real error appears near the START of the output and everything after is stack trace, so `| tail -N` hides the only useful line — that cost a full debug cycle here.

### Phase 1 sign-off — T8, T11, T12 (2026-09-19)

**P1-T8 (consent) — gate resolved.** The long-standing "confirm the store's consent solution first" blocker is answered: this store runs only `Magento_Cookie`, Magento's native cookie-restriction mode, with no third-party CMP installed. No further integration is needed.

Found and fixed a real privacy bug while verifying it: `BeaconConfig` read `web/cookie/cookie_restriction_enabled`, but core's actual path is **`web/cookie/cookie_restriction`** (`Magento\Cookie\Helper\Cookie::XML_PATH_COOKIE_RESTRICTION`). The guessed path always resolved to false, so the consent gate would have **silently never fired** on any store with restriction mode switched on — the module would have tracked without consent. It now delegates to core's own accessor so it cannot drift again.

Design point worth keeping: the block publishes whether consent is *required* (store config, safe under full-page cache) and the beacon reads whether *this visitor* gave it at runtime. Baking the visitor's decision into the page would let FPC serve one person's consent state to everyone.

Verified: live browser with restriction on and no consent → **0 events queued**; all four logic branches confirmed (not required→track, required+no cookie→block, required+cookie=0→block, required+cookie set→track).

**P1-T11 (manual verification) — done in a real browser**, fresh Chrome profile per case: `?gclid=` → paid/google/cpc; no params → direct/none; `utm_source=newsletter&utm_medium=email` → referral/email; organic → organic/google. The organic case is verified at both layers the module owns (the beacon forwards `document.referrer` verbatim; the server classifies `www.google.com` as organic) because headless Chrome cannot synthesise a cross-origin referrer from a real search engine.

**P1-T12 (simulation) — exact reconciliation.** 300 visitors → 799 events accepted, 0 failed, 799 queued, drained to 0:

| metric | simulator | database |
|---|---|---|
| visitors | 300 | 300 |
| add_to_cart | 68 | 68 |
| checkout_start | 32 | 32 |
| order_placed | 3 | 3 |

traffic split paid 119 / organic 85 / referral 49 / direct 47, all five `platform_code`s exact, 0 rejected rows. Zero data loss across 799 events.

**Process note for future sessions:** a cleanup step tacked onto the end of a long shell command does not run if an earlier step exits non-zero. A leftover test row (`search_engine_domains` = only `localhost`) survived that way and made google.com classify as *referral* on the next test — which briefly looked like a module bug. Clean up in its own command, or verify the cleanup landed.

### P1-T7 complete — real browser traffic now flows (2026-09-19)

The beacon is implemented and driven by `Block\Frontend\BeaconConfig`, which emits its config inline rather than having the beacon fetch a config endpoint. The scaffold proposed the endpoint; inline removes a blocking round trip on every cold page load for data the server already has while rendering.

**The beacon knows no platform names.** It receives click-id parameter NAMES only (`["gclid","fbclid",...]`) and never learns which platform they map to. A unit test asserts the words google/meta/facebook/instagram never appear in the emitted JSON, so CLAUDE.md #2 holds in the browser too.

Verified with real headless Chrome against the live storefront:
- Landing on `?gclid=…&utm_campaign=…` → one queued message with a real UUID, the click id, the UTMs, a genuine Chrome user-agent and a server-side `ip_hash`.
- Second page view, same profile, no params → `product_view` attached to the SAME visit. One visit row, cookie persisted.
- **Returning visitor clicking a NEW ad** → `first_touch_source` stayed `google` while `last_touch` moved to `instagram`/`meta`/`retarget`, still one visit. First-touch vs last-touch attribution proven end to end.
- Kill switch → config global AND script download both vanish (0/0), restored on re-enable.

Two bugs found and fixed while building:
- `getUrl('rest/V1/adsanalytics/event')` silently **dropped the final segment**, producing `.../rest/V1/adsanalytics/`. getUrl parses its argument as route/controller/action. Endpoint is now built from `getBaseUrl(URL_TYPE_WEB)` — `URL_TYPE_LINK` would prepend the store code when "Add Store Code to URLs" is on and 404. Pinned by a regression test.
- The `<script src>` was declared in layout `<head>`, so disabling the module still shipped a JS download on every page view. Both the config and the tag now come from the conditional block, making the kill switch honest.

Event-type rule: `landing` when the visitor is new **or** any attribution param is present, otherwise `product_view`. A returning visitor arriving on a new ad click must produce a landing, or last-touch would keep crediting the campaign that first acquired them.

Unit suite now **103 tests / 162 assertions**.

### P1-T6 complete — the pipeline now works end to end (2026-09-19)

`EventConsumer` is implemented, split into testable pieces: `EventValidator` (P1-T9 rules), `RequestLogWriter` (P1-T5b), `VisitManager` (all visit/funnel writes) and `Model\Config\RequestLogConfig`.

**The bug that cost the most time, and that three static audits could never have found:** `etc/queue_consumer.xml` declared the handler as `class="…" method="process"`. Those attributes **do not exist** — `consumer.xsd` defines a single `handler="Class::method"` attribute. Magento ignored both, so the consumer ran with no handler at all: it dequeued all 275 parked messages, did nothing with them, acked them, and left the database empty with not one line in any log. Well-formedness checks pass on that file; only `schemaValidate()` against the XSD, or actually running it and noticing the tables stayed empty, catches it. **Validate module XML against its XSD, not just for well-formedness.**

Verified live, 7 hand-built messages (4 valid, 3 invalid):
- `v-paid` → `traffic_type=paid`, `platform_code=google`, `medium=cpc`, `campaign=spring`
- `v-organic` → `traffic_type=organic`, `platform_code=NULL`, `source=google`
- `v-orphan` (an `order_placed` with no prior landing) → placeholder visit with `traffic_type=unknown`, funnel event correctly attached
- 3 rejections with exact reasons: `unknown event_type`, `unknown click_id_param`, `missing visitor_uuid`

**Backfill proven**: sending `v-orphan`'s landing afterwards flipped it from `unknown` to `paid`/`meta` with `first_touch_source=instagram` — still 3 visits, not 4, and its funnel event stayed attached. That also exercises the `alt_sources` config column through the full queue path.

**Volume reconciliation (P1-T12's actual goal):** 150 simulated visitors → 407 events accepted → 407 queued → drained. Database: **150 visits, 35 add_to_cart, 18 checkout_start** against the simulator's **150 / 35 / 18**. Exact. Split: paid 61, organic 47, referral 23, direct 19, with all five paid platforms present.

Unit suite now **93 tests / 148 assertions**, adding `EventValidatorTest` and `EventConsumerTest`.

Operational note: `queue:consumers:start --max-messages=N` blocks waiting for the Nth message if fewer are queued, and killing the host-side wrapper leaves the container process alive still eating messages. Kill it with `bin/cli pkill -f 'queue:consumers:start'` before running a controlled test, or the next published message vanishes into the old process.

### Admin menu + blank pages fixed (2026-09-19)

**Menu.** Both entries were direct children of `Magento_Reports::report` *with* an `action`, so Magento rendered them as two loose links with no heading: "Ads Analytics" landed mid-way down the Marketing column between "Newsletter Problem Reports" and "Reviews", and "Ads Analytics Request Log" was stranded at the bottom of that same column. `Aavirbhava_SalesAnalytics`' own `menu.xml` had already hit and documented this exact bug, so the fix is the established house pattern: a parent node with **no `action`** renders as a mega-menu column title. Items renamed to "Dashboard" and "Request Log" now that the group supplies the "Ads Analytics" context, `sortOrder=66` to sit next to Sales Analytics (65), `resource` reusing the existing `Aavirbhava_AdsAnalytics::report` ACL parent so no role permissions change.
Verified against the rendered admin HTML: `GROUP TITLE: Ads Analytics` with children `Dashboard` and `Request Log`.

**Blank pages.** Both admin pages rendered a title and a footer and nothing else, because `view/adminhtml/layout/` was an empty directory — and git does not track empty directories, so the layout handles had never existed. Added:
- `ads_analytics_requestlog_index.xml` + a real UI Component listing (P3-T5b) backed by a new grid collection registered on `UiComponent\DataProvider\CollectionFactory`. Without that di.xml entry a grid renders zero rows and no error, which is indistinguishable from an empty table.
- `ads_analytics_report_index.xml` + `Block\Adminhtml\Dashboard` reading **only** `ads_analytics_daily_summary` (CLAUDE.md #6) with a `traffic_type` breakdown, and an honest empty state explaining that the aggregation cron has not run rather than showing a blank panel or inventing numbers.

Both verified over real authenticated HTTP: dashboard 62,781 bytes with the empty-state copy present, request log 71,504 bytes with the grid columns rendering; breadcrumbs read "Ads Analytics / Ads Analytics / Reports".

Also fixed along the way: both controllers now implement `HttpGetActionInterface` (a carried-over audit finding), and `Dashboard::hasData()` had to be renamed `hasSummaryData()` — `Magento\Framework\DataObject` already defines `hasData($key = '')` and the incompatible override was a fatal error at compile time.

The request-log grid was proven with a row containing `<script>alert(1)</script>` in `raw_payload` and `<img src=x onerror=...>` in `user_agent`: both are stored **verbatim** per SPECS §9, and the grid's stock text columns escape at render via Knockout's `text:` binding. Test row removed afterwards.

### Testing pass (2026-09-19) — unit, security, simulation

**Unit tests (P1-T10, partial): 63 tests / 111 assertions, all passing.**
`Test/Unit/` covers `TrafficResolver` (26), `TrafficClassificationConfig` (11), `EventIngestService` (11), `IngestConfig` (11), `AdSpendProviderPool` (4). Run with:
`bin/dev-test-run unit ../../../app/code/Aavirbhava/AdsAnalytics/Test/Unit`
(Do NOT use `--filter A|B` — `bin/dev-test-run` passes `$*` unquoted into `bash -c`, so the pipe is re-interpreted inside the container.)
Most cases pin down a specific bug found in review; each one says which, so a regression is recognisable rather than mysterious.

**Security pass — three real findings, none previously known:**

1. **Unknown payload properties return HTTP 500, not 400.** `{"orderId":5}` throws `LogicException: Property "OrderId" does not have accessor method` from `NameFinder.php:103`. Magento does not classify `LogicException` as a client error, so it surfaces as 500. This violates docs/TESTING.md §2 ("rejected without a 500"). Good news: `customer_id`/`order_id` genuinely are NOT accepted, satisfying SECURITY §3 — just with the wrong status code.

2. **Log-amplification DoS that the rate limiter cannot stop.** Measured: with `rate_limit/max_requests` set to **1**, five malformed-property requests still wrote **175 lines** to `exception.log` — ~35 lines per request, unbounded. The cause is architectural: Magento's webapi deserialises the payload *before* calling `EventIngestService::ingest()`, so the exception is thrown upstream of the rate limiter, which never runs. An unauthenticated attacker can fill the disk at zero cost. This machine ran out of disk earlier today, so the risk is not theoretical. A fix needs to sit at the webapi/front-controller layer, not in this service.

3. **Cross-origin POSTs are accepted.** `Origin: https://evil.example` returns 200. SECURITY §5 says to "validate `Origin`/`Referer` where present"; nothing does. Worst case is polluted analytics rather than compromise, as §5 anticipates, but the stated control is absent.

Confirmed working: forged `ip_hash`/`user_agent` are overwritten server-side; the kill switch stops publishing; the rate limiter drops exactly the right number; malformed JSON and type-confusion (`entityId:"abc"`) are cleanly rejected with 400; SQLi and XSS strings are accepted and stored verbatim as designed (they matter at P1-T9 validation and P3-T5b grid escaping, neither built yet).

Known-and-expected gaps, all P1-T9 which is not yet implemented: oversized fields (10k utm_campaign accepted), unknown `event_type`, unknown `click_id_param`, and **a missing `visitor_uuid` being accepted as an empty string** — that last one is worth a validation rule of its own, since it would key a visit row on `''`.

**Simulation (P1-T12): working, after fixing two defects in the simulator itself.**
- The payload envelope was **wrong and had never worked**: it posted the body flat, but Magento requires it wrapped in the service parameter name (`{"event": {...}}`), returning `HTTP 400 '"%fieldName" is required'`. The old code only caught `RequestException`, and a 400 is a valid response, so this failed silently forever.
- The summary **reported success when every request had failed** — it counted what it intended to send. It now prints a Transport section first (accepted / failed / first error) and shouts if nothing landed, because reconciling a fictional summary against an empty dashboard would look like a module bug.
- Added `--insecure`, without which it cannot reach a local docker-magento store at all: the self-signed cert does not chain to the project `rootCA.pem` (`bin/setup-domain` was never run, since it only exists inside the destructive `bin/setup`).

Result: **275 events accepted, 0 failed, 275 messages on the queue** — exact 1:1. Spot-checked messages carry correct `platform_code`/`click_id_param`/`utm_*` and a server-side `ip_hash`.

Those 275 messages are deliberately left on the queue as ready-made input for verifying P1-T6. Purge with
`docker exec magento-rabbitmq-1 rabbitmqctl purge_queue aavirbhava.adsanalytics.event` if a clean slate is wanted first.

Still-open simulator defects (matter at P3-T8, not before): `revenue` is generated and printed but never sent, so revenue can never reconcile; `--seed` does not give reproducible runs (thread pool + unseeded `uuid4()`); `datetime.utcnow()` is deprecated on 3.12+.

### P1-T5 complete (2026-09-19)
`EventIngestService` now does kill switch -> sampling -> rate limit -> ip/UA overwrite -> publish. New `Model\Config\IngestConfig` plus a `rate_limit` config group (max_requests=120, window_seconds=60) moved the last hardcoded constants into config.

Verified live against RabbitMQ:
- A POST puts exactly one message on `aavirbhava.adsanalytics.event`.
- **Forged-field defence proven**: the client sent `ipHash":"CLIENT-FORGED"` and `userAgent":"CLIENT-FORGED"`; the queued message carried a real 64-char HMAC (`f8c1d726…44dbe`) and the genuine `AdsAnalyticsProbe/1.0` header. Server-side overwrite works.
- Kill switch (`general/enabled=0`): HTTP 200, queue unchanged. Back on: +1.
- Rate limit set to 3/60s, 6 requests fired from one visitor: exactly **3 published**, all six returning 200 — drops are silent by design.

Design notes worth keeping:
- Sampling is **deterministic per visitor** (`crc32(visitor_uuid) % 100 < rate`), not random per request. Random sampling would shred the funnel: you would keep a visitor's add_to_cart but drop their landing, leaving a visit that can never be reconstructed or attributed.
- `publish()` is wrapped in try/catch. A broker outage logs an error and returns; it must never become a storefront 500, since the beacon fires on every page.
- Every exit path is silent and returns 200 — disabled, sampled out, rate limited, or publish failure all look identical from outside. Anything else would leak the threshold to a flooder and put exception noise on every page view when the broker is down.

### P1-T4 complete (2026-09-19)
`Model\Config\TrafficClassificationConfig` now feeds all three lists to `TrafficResolver`; the three `DEFAULT_*` constants are gone, and so are the `meta`/`instagram` conditionals — the facebook-vs-instagram split runs off the `alt_sources` config column. A grep for platform, medium and search-engine names in `TrafficResolver` returns nothing, so CLAUDE.md #2 now holds in code, not just in intent.

Verified live through DI against real config:
- All 12 classification cases correct. `fbclid` + `utm_source=whatsapp` still yields `source=facebook` — only values listed in that platform's `alt_sources` can override `default_source`, so a client cannot rewrite source with an arbitrary utm_source.
- `yandex.com` and `yandex.ru` both resolve to engine `yandex` (multi-TLD without wildcards).
- **CLAUDE.md #2 proven end to end**: adding a Pinterest row (`epik`) through config alone flipped `?epik=pin123` from `direct` to `paid/pinterest` with zero code changes; deleting the row fell straight back to the five config.xml defaults. That fallback is also the concrete demonstration of why these defaults must not live in a data patch.
- Malformed JSON in config degrades to empty (all traffic falls through to direct) and logs at critical rather than throwing, so a bad config row cannot kill the consumer.

Scope caveat recorded in the class: lists are read at **default scope**. The fields are `showInWebsite="1"`, but the queue consumer has no store context, so per-website classification would require the visit's `store_id` to travel on the queue message.

### P1-T3 complete (2026-09-19)
Three admin grids (`PlatformMap`, `PaidMediums`, `SearchEngineDomains`) + `ArraySerialized` backends, wired in `system.xml` and verified building through `Config\Model\Config\Structure` with adminhtml DI loaded. Defaults confirmed readable via `ScopeConfigInterface`: 5 platforms, 4 paid mediums, 8 search-engine domains, plus all 7 scalar fields.

Two decisions worth knowing:
- **Defaults live in `etc/config.xml`, not a data patch.** `Setup/Patch/Data/InstallDefaultPlatformMap.php` was deleted. A patch writes a `core_config_data` row, and that row shadows the config.xml default permanently — so shipping a sixth platform in a later release would be silently ignored on every store that ran the patch. `config.xml` defaults reach existing installs; an admin edit still overrides them, which is the correct precedence. (The patch was already recorded in `patch_list` with an empty `apply()`, so removing it changes nothing at runtime.)
- **The platform map gained an `alt_sources` column.** Meta ships `instagram` there. This turns the facebook-vs-instagram split into data and lets P1-T4 delete the `$platformCode === 'meta'` conditionals in `TrafficResolver`, which are the last CLAUDE.md #2 violation in the module.

Note: `bin/magento config:show` prints nothing for these paths. That is expected — it reads `core_config_data` only, not `config.xml` defaults. Use `ScopeConfigInterface` to check them.

### Verified live on 2026-09-19 (first time this module has ever run)
Magento was reinstalled from scratch — the database had been lost (0 tables) though the codebase and 414MB of Luma media survived. `bin/setup-install` was used, NOT `bin/setup`, which contains `rm -rf src` and would have deleted this module. Old `app/etc/{env,config}.php` backed up to `backup-etc-20260919/`.

- All 5 tables created by `setup:upgrade`; module auto-enabled during install.
- Sentinel defaults confirmed in MySQL: `daily_summary.{platform_code,source,medium,campaign}` = `'null_source'`, `visit.traffic_type` = `'unknown'`, `request_log.validation_status` = `'pending'`.
- Unique key present as `UNQ_397A9B375400CA688EBEF1DE2C148D23` — Magento hashes the `referenceId`, so don't grep for `UNIQUE_SLICE` in the DB.
- **Upsert proven**: inserting the same all-sentinel slice twice yields 1 row, not 2. The original nullable-unique-key bug is genuinely fixed.
- **MySQL 1048 proven**: an explicit NULL into `source` raises `ERROR 1048: Column 'source' cannot be null`, confirming the corrected failure-mode documentation (it fails loudly; it does not duplicate rows).
- `POST /rest/V1/adsanalytics/event` returns **HTTP 200** — so the audit-1 reflection blocker (`@return` annotations on the webapi interfaces) is genuinely resolved in the real stack, and `EventIngestService`'s 5 injected dependencies resolve.
- Rate limiter confirmed live: per-(ip_hash, visitor_uuid) counters in Redis with a 60s TTL.
- AMQP topology declared: queue `aavirbhava.adsanalytics.event` exists with 0 messages — correct, since `publish()` is still a TODO.

**Gotcha worth remembering:** the first endpoint call 500'd with `Cannot instantiate interface EventInterface` via `ObjectManager\Factory\Compiled`. Cause was a stale `generated/metadata` from Aug 29, predating this module. `cache:flush` did NOT fix it; clearing `generated/` and running `setup:di:compile` did. This bit again in P1-T4: adding a constructor to `TrafficResolver` threw `ArgumentCountError: Too few arguments ... 0 passed` until `setup:di:compile` was re-run. **Any di.xml change OR constructor-signature change in this module needs `setup:di:compile`, not just `cache:flush`.**

## In progress
_(none — no task has been picked up for actual implementation yet)_

## Notes for next session
Start with `docs/TASKS.md` Phase 1, in order. Finish P1-T5 first — it is In Progress and only the `publish()` call is left.

Deliberately deferred to a later pass (known, not forgotten):
- `mail.google.com`/`sites.google.com` still classify as `organic` google — they are genuine subdomains, so the label-boundary match accepts them. Gmail/Docs referrals therefore inflate organic. Decision still open; `docs/TESTING.md` §1 flags it explicitly.
- ~~`etc/config.xml` does not exist~~ — done (P1-T3b); defaults now ship for all 7 fields. Was: every `system.xml` field read null — including both retention windows `PurgeOldData` will delete against, and the three config lists P1-T3 adds.
- ~~`composer.json`/`module.xml` omit message-queue and other deps~~ — done; 8 packages added to `require`, 11 modules to `<sequence>`.
- ~~`etc/db_schema_whitelist.json`~~ — done, generated and committed (P1-T9b).
- `Setup/Patch/Data/InstallDefaultPlatformMap.php`'s `apply()` body is still empty (P1-T3).
- Admin controllers lack `HttpGetActionInterface`; `view/adminhtml/layout/` is an empty directory (git won't track it), so both admin pages render blank.
- `tools/simulate_traffic.py`: `revenue` is printed but never sent, so P3-T8 revenue reconciliation can't work; `--seed` doesn't give reproducible runs (threaded, plus unseeded `uuid4()`); `datetime.utcnow()` is deprecated.
- `order_attribution` has no FK to `sales_order`, and its CASCADE from `visit_id` means `PurgeOldData` deleting old visits would destroy attribution for live orders.
- `order_attribution.traffic_type` and `daily_summary.traffic_type` are NOT NULL with no default, unlike `visit.traffic_type`'s `'unknown'` — P2-T4/P3-T2 must set them explicitly.
- No idempotency anywhere in the consumer: AMQP is at-least-once, so a redelivered message writes a duplicate request-log row and funnel event.
