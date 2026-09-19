# Progress — Aavirbhava_AdsAnalytics

Update this file in place after every work session. It's a current-state snapshot, not a log — session-by-session detail goes in `docs/status-reports/`.

**Last updated:** 2026-09-19
**Current phase:** Phase 3 complete; Phase 4 (export + ad-spend/ROAS) not yet started
**Current task:** Phases 1 through 4 are all complete. P4-T7's final regression found and fixed a real schema bug (`page_url` exceeded Magento's declarative-schema varchar cap; only a fresh-install integration test could catch it) and an unrelated dev-environment infrastructure gap (`nginx.conf` had been empty since project setup). Everything is green: 211 unit tests, 32 integration tests, 0 phpcs errors, 0 real phpstan findings, and an exact simulator reconciliation. Phase 5 (access-log simulator page) remains deferred by request, with its design questions still open. The user is verifying the PDF/CSV/Excel downloads and the ROAS table in a browser themselves.

## Phase status
| Phase | Status |
|---|---|
| 1 — Scaffold + capture layer | **Complete (15/15)** — verified end to end in a real browser, reconciled exactly against a 300-visitor simulation, 104 unit + 26 integration tests green |
| 2 — Funnel events + order attribution | **Complete (7/7)** — LUMA checkout adapter shipped, Hyvä left as a documented drop-in; full funnel `add_to_cart → checkout_start → checkout_step_shipping → checkout_step_payment → order_placed` confirmed live |
| 3 — Aggregation + admin reporting | **Complete (8/8)** — nightly rollup + admin charts/grid, retention purge, summary reconciled exactly against both raw data and a seeded simulator run |
| 4 — Export + ad-spend/ROAS | **Complete (7/7)** — export, PDF, ad-spend provider, ROAS, credential pattern, full regression all done and green |
| 5 — Access-log simulator page (new, requested 2026-09-19) | Deferred — requirements captured in TASKS.md, explicitly to start only after Phase 4 |

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

### Request log now shows where visitors CAME FROM (2026-09-19)
The log recorded the URL a visitor landed on but not the referrer that sent them, which left it failing at its main job. Organic and referral arrivals normally land on a perfectly clean store URL — `/men.html` with no query string at all — so on screen they were indistinguishable from a direct visit. The referrer was sitting in `raw_payload`, a hidden JSON column nobody opens.

Added a `referrer_host` column, shown as **Came From** next to **Landed On** (the renamed Page URL).

**Hostname only, never the full referring URL**, per docs/SECURITY.md §6. A full referrer carries another site's search query or tracking token — `https://www.google.com/search?q=running+shoes` — which is not ours to keep, and the hostname is both all the classifier uses and all a merchant needs to see the source. This is deliberately stricter than the `page_url` column beside it, which does keep its query string, and the distinction is principled rather than accidental: our own URL is ours to store, another site's is not. Verified that no row contains any part of a referring search query.

The value comes from `TrafficResolver::extractHost()`, which was made public for this. Sharing the resolver's own helper rather than parsing the referrer again is the point: two implementations could drift, and the log would then be showing evidence for a decision that was never actually made.

Regex filtering now covers both URL columns rather than just `page_url`; they answer the same kinds of question. Verified: `^(www\.)?google\.com$` matches google exactly without matching a lookalike domain, `^(?!.*google).*$` inverts it, `t\.co|someblog` matches a set.

### Request log no longer records direct traffic (2026-09-19)
Direct arrivals are not what this module is for, and their rows were pure noise: the log records URLs so that a click id or utm tag the classifier does not recognise becomes visible, and a direct visit has no referrer and no campaign parameters **by definition** — its URL can never reveal a missing source.

Added a third logging level, `external_only`, now the shipped default: rejections, plus accepted **landings** that resolved to `paid`, `organic` or `referral`. Dropped are direct landings and all non-landing funnel events, the latter because their URL is the store's own page reached from the store's own pages.

Two properties worth keeping:

- **Rejections are logged regardless of origin.** A malformed request from a direct visit is still a malformed request, and debugging is the log's other job. Verified: a bad `event_type` posted from a plain `https://magento.test/` URL is still recorded with its reason.
- **Dropping a log row does not drop analytics.** The filter applies only to `ads_analytics_request_log`. Verified: the direct landing still created its `ads_analytics_visit` row with `traffic_type = direct`, and the internal `product_view` still created its funnel row. Visits, funnel events, attribution and the daily summary are untouched.

The row is still written optimistically before validation and deleted once the outcome and traffic type are known, which is the same mechanism `rejected_only` already used. Writing only after classification would lose the row for any event that crashes the consumer mid-validation, which is precisely the case the log exists for.

**Verified** with seven landings at `external_only`: paid, paid, organic and referral kept; direct and the internal product view dropped; the rejected event kept with its reason despite its URL being direct. 146 unit tests green, including a new `RequestLogConfigTest` pinning what each level keeps.

One test-only trap worth remembering: stubbing `getValue()` twice on a single shared `ScopeConfigInterface` mock silently keeps the FIRST configured return, so a test that builds configs at two levels asserts against the wrong one. The helper now creates a fresh mock per call.

### Request log now records the real URL, with regex filtering (2026-09-19)
The request log recorded `endpoint`, which is always `/V1/adsanalytics/event`, and the payload fields the module had already parsed. That made it useless for its most valuable purpose: seeing traffic the module does **not** handle. An unrecognised click-id or utm parameter appears nowhere — `TrafficResolver` keeps only what it understands, and `ads_analytics_visit.landing_page` stores the path with the query string thrown away.

Added `page_url` (full URL, query string and fragment kept, sent on every event by the beacon) and `traffic_type` (what the resolver actually decided, on accepted landings). Side by side they show misclassification directly: campaign parameters in the URL but a resolved type of `direct`, or an unknown `*clid=` parameter, means a source that needs adding to configuration.

Both are confined to this table on purpose. A query string is arbitrary caller-supplied text that can carry personal data such as a search term, so it stays on the debugging surface that already holds raw payloads and is purged on the 14-day window, and is never copied into the visit, funnel or summary tables.

**The `page_url` filter is a MySQL regular expression, not a LIKE.** A plain keyword still works as a substring match — a regex without metacharacters *is* a substring match — so the ordinary case costs nothing, but questions LIKE cannot express become possible. Only `page_url` is treated this way; every other column keeps literal LIKE behaviour, so a rejection reason containing a metacharacter still matches as text.

Two details in the filter worth not "simplifying" later:
- `Magento\Ui\Component\Filters\Type\Input` escapes `%` and `_` in the typed value and *then* wraps it in `%...%`. Both steps are undone, in that order, stripping exactly one leading and one trailing `%`. Stripping every leading `%` instead would turn a search for `%20` into a search for `20` — wrong results, no error. Unit tests in `Test/Unit/Model/ResourceModel/RequestLog/Grid/PatternExtractionTest.php` pin this.
- Validity is checked by asking MySQL (`SELECT ? REGEXP ?`), not `preg_match`. They are different engines, so a pattern PCRE accepts can still be rejected by the database, and the database is the only opinion that matters. An invalid pattern falls back to a literal match rather than erroring, because a half-typed expression is the normal state of a filter box; MySQL would otherwise raise 1139 and the grid would render as a failed request.

**Verified** against seven beacon-realistic landings. Classification came out `paid` / `paid` / `paid` / `referral` / `referral` / `organic` / `direct`, and the log immediately surfaced genuine gaps: `twclid` and `mc_cid` URLs landing as `referral`, and `li_fat_id` / `epik` classified paid only via their medium with no platform recognised. Filters behaved exactly as intended — `gclid` → 1, `[?&][a-z_]*clid=` → 2, `[?&]utm_medium=(cpc|paid)` → 3, `twclid|epik|li_fat_id|mc_cid` → 4, invalid `utm_(` → 0 rows and no error, `%20` → correctly matched a percent-encoded URL. Other columns unaffected. Reflection on `Api\Data\EventInterface` passes on all 30 methods, so the new accessor cannot 500 the webapi. 137 unit tests green.

### P4-T7 complete — final regression, and a real schema bug it caught (2026-09-20)
Full sign-off checklist for Phase 4: unit suite, integration suite (a genuinely fresh install, per P1-T10's disposable-DB approach — not just re-running against the long-lived dev DB), a `simulate_traffic.py` regression run, and static analysis across the whole module.

**The integration suite found a real bug the whole rest of this session had missed.** `page_url` (added at the request-log work) was declared `xsi:type="varchar" length="2048"`, but Magento's declarative-schema XSD caps `varchar` at 1024 characters — a real, enforced limit (`Setup/Declaration/Schema/etc/types/texts/varchar.xsd`), not a guideline. A fresh install rejects the schema outright: `[facet 'maxInclusive'] The value '2048' is greater than the maximum value allowed ('1024')`. This had been invisible all session because every verification since applied the schema to the SAME already-running dev database via repeated `setup:upgrade` calls, which does not re-validate the full XSD the way a clean `setup:install` does — the column was simply added to a live table, no questions asked. Only a fresh install, exactly what P1-T10's integration environment exists to exercise, could have caught it. **This is the argument for occasionally actually running that suite rather than treating it as done once at P1-T10 and never again.** Fixed to `xsi:type="text"` — unbounded, matching `raw_payload`'s own convention on the same table — applied to the live dev database (existing `page_url` data survived the type change intact) and confirmed with a second clean integration install.

**A second, unrelated infrastructure problem surfaced while chasing what looked like a simulator regression.** After this session's containers were restarted, `src/nginx.conf` turned out to be a zero-byte file — dated back to the project's initial setup, so this is not something any of this session's work caused, and not specific to this module. The running nginx process had apparently been serving from a config loaded before that state, and only needed to actually re-read the file (on this restart) to break: every subsequent request fell through to nginx's compiled-in default document root and 404'd with a plain nginx error page, not a Magento one. A 200-visitor simulator run against this state reported "200 events sent" in its own intent summary while every single HTTP request silently failed — the raw tables simply never grew, which is what actually surfaced the problem. Fixed the standard way: copied Magento's own shipped `nginx.conf.sample` over the empty file and reloaded nginx. **Worth remembering for this project specifically: if the site or any endpoint starts returning a bare nginx 404 page (not Magento's own error page) after a container restart, check whether `src/nginx.conf` is empty before suspecting anything Magento-side** — it apparently only gets read fresh on a config reload/restart, so it can sit broken for a long time unnoticed.

**Simulator regression, re-run correctly after the fix:** Transport reported 508/508 accepted, 0 failed. Reconciled a before/after summary delta against the simulator's printed SENT figures across all 8 slices: exact on 7, and the 8th (`organic`) was off by exactly 1 — traced to a manually-sent diagnostic curl request made while debugging the nginx issue, not a reconciliation defect. `summary = raw` held throughout (734/734 visits, 84/84 checkout_starts).

**Full checklist:**
- Unit suite: 211 tests, 381 assertions, green.
- Integration suite (P1-T10's disposable DB, genuinely fresh `setup:install`): all 32 tests green, on the second attempt after the schema fix and after adding `--disable-modules=Magento_TwoFactorAuth` to the integration config — a pre-existing, module-unrelated install-time DI failure in `Magento_TwoFactorAuth`'s own data patch, worked around the documented way (`setup:install --disable-modules`). Environment torn down afterward: database dropped, sandbox directory removed, `install-config-mysql.php` deleted.
- phpcs: 0 errors across the whole module.
- phpstan level 2: 0 real findings. 10 reported — 2 are the `ResultInterface`-typed `execute()` pattern already used by every controller in this module (a stock Magento idiom: the factory's declared return type is the interface, the runtime object is the concrete `Page` result with the methods actually being called), and 8 are phpstan lacking the integration test framework's autoload path (`Magento\TestFramework\Helper\Bootstrap` is only available under `dev/tests/integration`, not the main autoloader) — a scanning limitation, not a code defect.
- `docs/PROGRESS.md` updated in place (this entry).

**Not part of this task, still open:** the user is verifying the PDF/CSV/Excel downloads and the ROAS table in a browser themselves, by their own request, rather than this session attempting it again through a throwaway admin account.

### P4-T6 complete — credential storage, as a pattern with enforcement (2026-09-19)
SECURITY.md §10 requires provider API credentials to go through Magento's encrypted backend. This module has nothing to encrypt: `CsvAdSpendProvider` reads a file, and a live Google Ads / Meta provider is a client-project addition (no OAuth access here, as with Hyvä). So the task shipped **no credential field** — one in this module's own `system.xml` would be dead configuration nothing reads — and instead shipped the parts a provider developer needs, plus a way to keep them honest.

**`Model\Config\AdSpendCredentials`** reads and decrypts a credential. Magento's `Encrypted` backend model encrypts on save, but `ScopeConfigInterface::getValue()` hands the ciphertext straight back; nothing decrypts on read, so the obvious mistake is using the stored string as though it were the secret.

**It fails closed, and the reason is a trap in core.** `Encryptor::decrypt()` does not fail on a value that was never encrypted: a string with no colons is treated as the oldest legacy format and "decrypted" into junk, frequently non-empty. A secret stored in plain text — through a plain field, `config:set`, or a direct SQL insert — would therefore appear to work, or fail mysteriously, instead of being flagged. So the reader checks the value's shape (`^\d+:\d+:`, which is what `encrypt()` writes) BEFORE decrypting and refuses anything else with a log line naming the config path, never the value or the ciphertext. Unset returns `null` silently (an unconfigured provider is normal). A key that has been rotated or lost is the realistic way a correctly stored secret stops working; it returns `null` with an actionable message.

**A test found a real gap in the first draft.** The reader did not catch exceptions from `decrypt()`; the wrong-key case happens to return `''` from the real `Encryptor`, so the obvious test passed, but a decrypt that throws (a sodium error, malformed data) would have propagated into the middle of a spend report. Only a test with an encryptor that throws exposed it. Now contained.

**`Test\Unit\Config\SecretFieldPolicyTest` enforces the rule.** It fails the build if a field whose id or label looks like a secret is not `type="obscure"` with the `Encrypted` backend model, or has a `config.xml` default. Two properties worth keeping: it also flags an `obscure` field WITHOUT the backend model — masked in the browser but stored in clear, the half-measure that looks safe and is not — and it recognises a secret by its label when the id is innocuous. And because it passes today by finding nothing, which proves nothing, it also runs the checker against fixtures (one good field accepted, four bad ones caught, ordinary settings left alone) and asserts the real `system.xml` was actually parsed and non-empty, so a wrong path or changed structure cannot make it pass vacuously. It covers THIS module only; a provider module needs its own copy.

**Verified against a real database, not just mocks.** A throwaway `obscure` + `Encrypted` field was added to `system.xml`, saved through `Magento\Config\Model\Config` (the path the admin form uses), and inspected:
- `core_config_data` held `0:3:<ciphertext>` — no plaintext — and the reader returned the original secret.
- Re-saving the page with the masked `******` placeholder (what the browser posts when the field is untouched) left the stored value unchanged and the secret still readable.
- A plain-text value written directly into the table was refused (`null`), with a log line naming the path and containing neither secret (0 matches in `system.log`).

The probe field, its DB row and the log lines' subject were then removed, and `system.xml` was checked against a backup: identical hash, zero occurrences of "probe" left in it or in `core_config_data`.

Real `Encryptor` instances (not mocks) are used for the round-trip tests, including one asserting that what Magento actually writes matches the shape the reader accepts — so a future Magento changing its ciphertext format would fail a test rather than silently reject every legitimate secret. 17 new unit tests, 211 total green.

The recipe for a provider module — the `system.xml` snippet, the read call, and the `config:sensitive:set` alternative that keeps a secret out of the database entirely — is in docs/SECURITY.md §10.

### P4-T5 complete — ROAS per platform (2026-09-19)
Return on ad spend (revenue / spend) is now shown as a per-platform table on the report page and as a "Return on Ad Spend by Platform" section in the PDF, both fed by `Model\Roas\RoasReportBuilder` so they cannot disagree.

**Grain: one row per platform — the user's explicit instruction.** It also turned out to be the safe grain. Before being told, the plan had been platform + campaign; checking the data first showed why that needs care: spend is recorded per (date, platform, campaign) while a summary row is finer, and Meta already splits into facebook and instagram rows that share the same date, platform and campaign. A per-row ROAS would attach the same spend to both and count it twice. Rolling up to the platform avoids that, and also means a campaign spelled `Spring_Sale` in a spend file and `spring_sale` in the summary cannot cause a missed join. It is also why ROAS is NOT a column on the summary grid: spend comes from a provider rather than from the summary table, so it could not be sorted or filtered in SQL like the other derived columns, and at the grid's grain it would be wrong anyway.

**Three states, deliberately kept apart**, because collapsing any two of them produces a number that misleads:
- *Spend known and positive* → a real ROAS. That includes an exact **0.00x** — money went out and none came back, which is the most important result a merchant can see.
- *Spend known and zero* → "n/a". Dividing by zero is not an infinite return; it means nothing was spent.
- *Spend not known* → "no data", **never 0.00**. A ROAS of 0.00 would claim a platform's ads earned nothing, when the truth is that nobody has told us what they cost. In the seeded data `bing`, `reddit` and `tiktok` have no spend file, so they show "no data" while `meta` (spend 197.11, revenue 0) shows a genuine 0.00x.

**Rows come from the union of two sources**: platforms in the summary AND platforms with a registered provider (`AdSpendProviderPool::getPlatformCodes()`, added for this). Starting from the summary alone would omit a platform that spent money but drew no traffic — exactly the case a merchant most needs to see.

**The blended total leaves out revenue from platforms with no spend data.** Counting bing's 117.00 against only google's and meta's spend would divide all attributable revenue by part of the cost and inflate the headline precisely when the data is least complete. The page states how many platforms were excluded.

**A failing provider is logged and treated as "no data"** for that platform without blanking the rest — a real API-backed provider will eventually hit an expired token or a rate limit.

**Verified independently.** The builder's output was compared with a computation that shares none of its code — revenue straight from MySQL, spend by parsing the raw CSV files in Python (skipping malformed rows as the provider does): meta 197.11, google 185.59 (ROAS 0.6304), blended 382.70 spend / 117.00 revenue / 0.3057 — identical to the cent, and again on a 7-day range (google 1562.18, meta 1043.32) so multi-day summation is covered by real data as well as unit tests. The dashboard template was rendered through Magento's real layout, and the PDF's text extracted with `pdftotext`; it still fits one page. 15 new unit tests (194 total green), 0 phpcs errors; phpstan level 2 is clean on everything written here — its 5 remaining findings are in `BeaconConfig.php` and the two `Index` controllers, files this task did not change.

**Limits, stated on the page itself:** revenue is base currency and spend is whatever the provider reports, so they only compare on a single-currency store; and the report covers the whole dataset rather than following the grid's filters (the charts do; ROAS cannot, for the reason above). Not yet verified in a browser.

### Two real bugs from live browser use, both fixed (2026-09-19)
P4-T1/T2 were verified thoroughly at the PHP/DI level before shipping, but two things only surfaced when the user actually clicked the buttons in a browser — worth recording exactly why the earlier verification missed them.

**PDF: `execute()`'s return type was wrong.** Declared `: ResultInterface`, but the success path returns whatever `FileFactory::create()` hands back, which is a `ResponseInterface` (the PDF streamed as the response body), not a `Result` object — core's own equivalent (`Magento\Sales\Controller\Adminhtml\Order\PdfDocumentsMassAction::execute()`) sidesteps this by simply not declaring a return type. The mismatch produced a `TypeError` on the real request, which the browser only showed as `ERR_INVALID_RESPONSE` (a PHP fatal error mid-stream corrupts the HTTP response at the protocol level, so it never reaches Magento's own error page). Every one of the session's earlier verifications called `$generator->generate($report)` directly and inspected the `\Zend_Pdf` object or its `render()` bytes — none of them ever called `execute()` itself, so the return-type mismatch was invisible to all of them. Fixed to `ResponseInterface|ResultInterface`, matching what `FileFactory::create()` actually returns on every path.

**Excel/CSV export: the button never sent a `namespace` param at all.** `Magento_Ui`'s `<exportButton>` widget resolves its request params through a "selections" module, reached via a `selectProvider` expression that points at a component literally named `ids` in the same namespace — i.e., a `<selectionsColumn>`. Neither of this module's two listings had one (neither needed mass actions), so `this.selections()` in `export.js` returned `undefined`, and the whole params object — including `namespace` — was never built. The controller then called `UiComponentFactory::create(null)`, which PHP 8 turns into a fatal "Undefined array key \"attributes\"" via Magento's own error handler. **Every stock Magento grid that ships `<exportButton>` also ships `<selectionsColumn>`** — a `grep` across the whole vendor tree found zero counterexamples — so this was never a supported combination to begin with; export was working code driven by an incomplete listing.

Fixed by adding `<selectionsColumn name="ids">` to both listings (indexed on `summary_id` / `log_id`), WITHOUT a `<massaction>` block — that column alone provides the `getSelections()` the export button needs; `<massaction>` is only needed for actual bulk actions like delete, which this module has no use for. This does add row-selection checkboxes to both grids as a visible side effect (there is no way to wire the selections module without the column it lives on being real), but adds no bulk-action toolbar since none is configured.

**Why the earlier verification passed despite this bug.** It called `ConvertToXml`/`ConvertToCsv` directly after manually setting `$request->setParam('namespace', ...)` in the PHP script — which bypasses the exact mechanism (the button's `selectProvider`) that was broken, so the test exercised everything downstream of the bug without ever exercising the bug itself. Re-verified this time with the request built to match exactly what `export.js` actually sends (`namespace`, `excluded='false'` as a literal string, `filters=[]`, `search=''`) rather than a hand-picked minimal params set: CSV and XML both succeed on both listings with this realistic shape. General lesson for this module going forward: when a task is "wire up a Magento UI Component feature", verifying the underlying converter/service in isolation is necessary but not sufficient — the widget's own JS is what actually assembles the request, and that assembly logic needs at least one check against a real click before calling the feature done.

### P4-T1/T2 complete — Excel and PDF export (2026-09-19)
**P4-T1 needed no custom code.** Magento's stock `<listing>` UI Component already ships an `<exportButton>` backed by core's `GridToCsv`/`GridToXml` controllers — the "XML" export is real SpreadsheetML that Excel opens natively, generated by `Magento\Framework\Convert\Excel`, which is exactly what core's own admin grids use for "Export to Excel". Both `<exportButton>` was simply missing from this module's two listings; adding it was the entire task. It reads from the SAME data-provider collection the grid renders from, so every SQL-derived column (`product_views`, `view_rate`, `cart_rate`, `conversion_rate`, `revenue_per_visit`) is already in the export with zero extra wiring. Verified by driving `ConvertToXml` directly: well-formed XML, all 16 expected column headers present (by label, not by raw field name — a first check that grepped for the literal string `product_views` gave a false negative, since the header cell reads "Product Views"), 38 data rows.

**P4-T2 needed real code**, since stock Magento grids have no PDF export option. Built on `\Zend_Pdf` — the exact library `Magento_Sales` uses for order/invoice PDFs (ships as `vendor/magento/zend-pdf`, so no new composer dependency) — rather than reaching for a PDF library that isn't already a Magento dependency, per this project's standing preference for building against what's already available.

Split into two classes on purpose: `Model\Pdf\DashboardReportBuilder` does the SQL (reading only `ads_analytics_daily_summary`, CLAUDE.md #6) and returns a plain array; `Model\Pdf\DashboardPdfGenerator` only draws that array onto a page. Neither needs the other's internals to be tested or read. The report is a standalone one-page summary (funnel totals + view/cart/conversion rates, traffic-type breakdown, top 10 paid campaigns by revenue) for an optional `?from=&to=` range, rather than "print the currently filtered grid" — that question is already answered by P4-T1's export, and duplicating it as a harder-to-read PDF would add nothing. `Controller\Adminhtml\Report\Pdf` defaults an absent `from` to the earliest date with any data (so a first visit gets the whole history, not an arbitrary lookback that might be empty) and validates both dates — including the round-trip check `DateTime::createFromFormat` needs to actually catch `2026-02-30`, the same trap documented for `CsvAdSpendProvider::parseRow()` — before either reaches SQL, redirecting with a message on anything malformed rather than building a query from a bad string.

**A real bug, found by testing rather than by reading the code twice.** The first version of the builder wrote `->where('date BETWEEN ? AND ?', [$from, $to])`. Magento's `Select::where()` treats an array condition value as an IN-list expansion for a SINGLE `?` placeholder — it is not "one array element per `?` in the string" — so this silently produced `WHERE (date BETWEEN '2026-09-19', '2026-09-19' AND '2026-09-19', '2026-09-19')`, a SQL syntax error that only surfaced when the query actually ran. Fixed to two chained `->where('date >= ?', $from)->where('date <= ?', $to)` calls, which is the correct pattern for a range with `Select::where()`. Worth remembering for any future query built this way in this module.

**Verification beyond unit tests:** a full object-graph run through the real DI container (not manual `new`) produced a valid 3,153-byte single-page PDF; `pdftotext` extraction confirmed every number renders legibly and correctly (`506` visits, `468.00` revenue, per-traffic-type and per-campaign rows all present); a deliberately empty date range renders "No data for this period" instead of throwing `DivisionByZeroError` on the view/cart/conversion rate calculations; the Excel/XML export still produces correct output after the same DI recompile. HTTP-level click-through (login → click the button → file downloads) was **not** independently re-confirmed this session: a freshly `admin:user:create`-provisioned throwaway account got redirected to the dashboard on every admin page it tried, including core Magento's own Catalog > Products grid — an environment quirk in this docker-magento install unrelated to this module, not a defect in `Pdf.php`, which uses the identical `Action`/`ADMIN_RESOURCE`/route pattern as the already browser-verified `Index` controller. The throwaway account was deleted afterward.

Incidental cleanup while in this area: `Controller\Adminhtml\Report\Index`'s docblock and a `TODO(P3-T3)` comment still described the abandoned Dashboard/Grid tab design and an unimplemented layout handle, contradicting what PROGRESS.md has documented as the actual (and working) chart-above-grid design since P3-T3. Corrected to match reality and point at where the real explanation lives.

22 new unit tests (`DashboardReportBuilderTest`, `DashboardPdfGeneratorTest` — asserts on real rendered PDF bytes via a content-stream regex rather than mocking `\Zend_Pdf`'s drawing calls, since there's no interface to mock against and a mock would only prove methods were called, not that the numbers reached the page — and `PdfTest` for the controller's date-range validation, including an SQL-injection-shaped string in the invalid-date cases), 179 total green, 0 phpcs errors, 0 phpstan errors (level 2).

### P4-T3/T4 complete — the ad-spend extension point, proven with a real provider (2026-09-19)
`Api/AdSpendProviderInterface` and `Model\AdSpendProviderPool` already existed from the Phase 1 scaffold, with test coverage in place — P4-T3's remaining work was confirming they hold up as the contract P4-T4 builds against, which they did unchanged.

**P4-T4 built `Model\AdSpendProvider\CsvAdSpendProvider`**, reading `var/aavirbhava/adsanalytics/adspend/<platform_code>.csv` (columns: date, campaign, spend). Registered in this module's own `etc/di.xml` for both `google` and `meta`, against the **same class**. That is deliberate, not a shortcut: `platform_code` arrives as a `getSpend()` argument rather than a constructor one, so one generic, credential-free class can serve any number of platforms — adding CSV spend for a sixth platform is a `di.xml` line and a file drop, never a new PHP class. That is CLAUDE.md #2 ("no platform-specific code") demonstrated for spend data, not just asserted.

**Why CSV rather than a live Google Ads / Meta Ads API call.** Both require OAuth app registration and an approved developer account this environment does not have — the same reasoning already applied to Hyvä checkout (build the free path, document the extension point, see the 2026-09-19 resolved decision in CLAUDE.md). A real `GoogleAdsProvider` would implement the identical interface, call the platform's reporting API instead of reading a file, and store its credentials via `Magento\Config\Model\Config\Backend\Encrypted` per docs/SECURITY.md §10 (P4-T6). This class needs no credentials at all, which is also why it is safe to ship registered by default rather than commented out.

**Two failure modes handled deliberately, not incidentally:**
- **A missing file is "no data," not an error.** A platform can legitimately have no spend recorded yet (`bing`/`tiktok`/`reddit` have neither a registered provider nor a file, by design). P4-T5's ROAS view must render "no data" for a platform rather than an exception breaking the whole dashboard over one absent CSV.
- **A malformed row is skipped and logged, not thrown.** A merchant hand-editing a CSV will eventually introduce a typo — a non-numeric spend, an impossible calendar date, an empty campaign, a short row. `parseRow()` validates each field and logs a warning with the exact line number and reason, so one bad line cannot blank out the rest of a file with real numbers in it. Validating the date is subtler than it looks: `DateTime::createFromFormat('Y-m-d', ...)` is lenient about overflow — `2026-02-30` silently becomes March 2nd rather than failing — so catching an invalid date needs the round-trip `format() !== $rawDate` check, not just a non-`false` check on the parse.

**Verified**, with a deterministic seeded dataset (values derived from `crc32(date|campaign)`, not `rand()`, so re-seeding cannot rewrite history a prior run's verification depended on): `google.csv` — a full week, all 5 campaigns, no gaps, plus one deliberately malformed spend value; `meta.csv` — only 4 of 5 campaigns and a missing day, plus one deliberately malformed date; `bing`/`tiktok`/`reddit` — no file at all.
- Pool resolves `google`/`meta`; correctly throws `NoSuchEntityException` for an unregistered platform (`bing`).
- `getSpend()` returns 35 rows for google (36 minus the malformed one), correct per-campaign totals; 24 rows for meta reflecting both the excluded campaign and the missing day; a narrower 2-day range returns exactly the 10 rows it should.
- Date-range bounds are inclusive on both ends (unit test).
- A registered platform with no file at all (distinct from an unregistered platform) returns `[]`, not an exception.
- A path-traversal platform code (`../../../../etc/passwd`) resolves to "no file" rather than escaping `var/`.
- All 6 malformed-row cases (bad spend, negative spend, invalid calendar date, unparsable date, empty campaign, too few columns) are individually covered in unit tests, each asserting the surrounding good rows survive.
- 12 new unit tests, 156 total green, 0 phpcs errors.

Test data is seeded directly into `var/aavirbhava/adsanalytics/adspend/`, not shipped in the module — same convention as the traffic simulator's data being generated rather than fixtures committed to the repo.

### Three admin-UI fixes found by inspection (2026-09-19)

**1. A stray icon box before "Dashboard" in the Reports menu.** `Magento\Backend\Block\Menu` derives each item's CSS class from the part of the menu id after `::` — `item-` plus the lowercased name. The id `Aavirbhava_AdsAnalytics::dashboard` therefore rendered as `class="item-dashboard"`, which core's `_menu.less` styles with `> a:before { content: @icon-dashboard__content; }` for the main sidebar Dashboard entry. In the Reports mega-menu the Admin Icons font is not applied, so the glyph came out as an empty box. Renamed the MENU id to `Aavirbhava_AdsAnalytics::report_dashboard` (class `item-report-dashboard`, matching no core rule) and updated the matching `setActiveMenu()` call. The ACL resource is deliberately still `Aavirbhava_AdsAnalytics::dashboard`, so no role permissions changed. **Rule worth keeping: never end an admin menu id in a name core styles — `dashboard`, `sales`, `catalog`, `customer`, `marketing`, `content`, `report`, `stores`, `system`.**

**2. A new grid column appears at the far right regardless of its sortOrder.** `product_views` was given `sortOrder="75"`, between `visits` (70) and `add_to_carts` (80), but rendered last, after Conversion Rate. Magento persists each admin user's column order in `ui_bookmark` and merges the saved order over the XML, appending columns it has not seen before. The XML was correct; the stale bookmark was not. Cleared the rows for this listing, after which the order follows the XML. **Adding a column to an existing UI-Component listing needs `DELETE FROM ui_bookmark WHERE namespace = '<listing>'` (or each admin resetting the view), otherwise every user who has already opened that grid sees the new column stranded at the end.** A fresh admin account would never have shown the problem, which is what makes it easy to miss.

**3. The Request Log grid was empty — correctly.** `logging/logging_level` defaults to `rejected_only`, at which `RequestLogWriter::markAccepted()` deletes the optimistic pending row once the event turns out to be valid, so a run of entirely valid traffic leaves the table empty by design. Confirmed the path works by posting four deliberately malformed events: three were logged as `rejected` with accurate reasons (`unknown event_type`, `missing visitor_uuid`, `unknown click_id_param`) while the valid control was correctly not kept but did create its visit. Setting `logging_level` to `all` then filled the grid (65 rows, filterable).

**A genuine gap found while testing #3, still open.** RabbitMQ was not running, and this is completely invisible from the outside: `EventIngestService` swallows publish failures on purpose so a broker outage cannot break the storefront, the endpoint still returns HTTP 200, and the request log stays empty because the row is written by the CONSUMER, not the endpoint. Every signal a developer would reach for says "fine" while every event is being dropped. The only trace was `NOT_FOUND - no queue 'aavirbhava.adsanalytics.event' in vhost '/'` in `var/log/system.log`. Worth considering: an admin health indicator, or a counter incremented on publish failure. Note also that restarting the broker loses the queue unless the topology is redeclared — `bin/magento setup:upgrade` does it.

### product_views added to the report (2026-09-19, post-Phase-3)
Closes the gap flagged at P3-T6: `product_view` events were captured in `ads_analytics_funnel_event` but the summary table had no column for them, so the funnel's widest stage could never appear in a report. This required amending docs/SPECS.md §3, which had not listed the column.

**It counts visits that reached a product page, not raw product pageviews.** This is the only column in the table whose aggregation is not a plain sum of events, and the reason matters: the beacon fires `product_view` on every product page, so a visitor browsing eight products raises eight events. Summing those would put `product_views` above `visits`, mixing per-visitor and per-event units in what is meant to read as one funnel, and would make any rate over visits exceed 100%. Deduplicating per visit also makes the figure immune to a beacon that double-fires on a page. `DailySummaryAggregator::productViewFacts()` implements it by collapsing to one row per (visit, day) in a derived table before summing.

Also added `view_rate` to the grid (`100 * product_views / NULLIF(visits, 0)`), alongside the existing `cart_rate` and `conversion_rate`, and inserted a Product View stage into the dashboard funnel chart.

**One incidental fix.** `aggregate()` built its bind list by hand as six values for three fact sources, behind a `$params = array_merge($bind, $bind, $bind)` line that was dead code — `$bind` is keyed, so the merge collapsed back to two entries and the values actually bound came from a separate literal list. Adding a fourth fact source would have made that list wrong. It is now derived from the fact-source array itself, so it cannot drift: too few values is an immediate PDO error, but too many, or the right count in the wrong order, would be a silently wrong report.

**Verified:**
- Per-slice diff between `summary.product_views` and an independently recomputed distinct-visit count returns zero rows.
- The dedup is genuinely exercised, not vacuously true. On the first pass every visit had exactly one `product_view` (the simulator sends one per visitor), so distinct-visit and event counts were identical — the same trap as P3-T6's `add_to_carts`. 75 extra `product_view` events were seeded across 25 visits, taking the raw event count to 545 while `product_views` correctly stayed at 470.
- No slice has `product_views > visits`.
- 10 bounce visits (landed, never viewed a product) were seeded so the stage shows real drop-off rather than a flat 100%; the paid/google/spring_sale slice now reads 14 visits / 4 product views / 28.57% view rate.
- `add_to_carts`, `checkout_starts`, `orders` and `revenue` were unchanged by the migration (37 / 54 / 6 / 468.00), and re-running the aggregation reports "0 summary row operations", so idempotency holds.
- Both new columns resolve in the UI component; sorting and filtering work on them, since both are SQL-derived.
- 127 unit tests green, 0 phpcs errors.

### P3-T8 complete — simulator reconciliation, and three simulator fixes (2026-09-19)
The task is "run the simulator with a known seed and reconcile", but none of that worked as written, because of three defects already logged here as open:

**`--seed` was not reproducible.** Two causes. The script drew from the global `random` module inside a `ThreadPoolExecutor`, so the order of the draws depended on how the OS scheduled the workers and two runs with the same seed produced different visitor sets. And `uuid.uuid4()` reads from `os.urandom`, which `random.seed()` has no effect on, so visitor ids were never reproducible at all. Fixed by moving every random decision onto the main thread into a new `plan_visitor()` that runs to completion before any worker starts; workers (`send_visitor()`) now do nothing but I/O and contain no draws. UUIDs come from the run's own `random.Random` instance via `seeded_uuid()`. Dry-run mode also now runs serially — there is no network I/O to overlap, and concurrent `print()` calls interleave, which made the payload dump look non-deterministic when only the printing was.

**The printed revenue was fiction.** `order_placed` and `add_to_cart` are server-only; the public endpoint drops them so nobody can POST an `order_placed` carrying someone else's order id (docs/SECURITY.md §3). The script correctly skipped sending them but still printed generated order and revenue figures in the same table as the events it did send, which invites the reader to reconcile them against a dashboard that is in fact correct and conclude the module lost them. The summary is now split into **SENT** (visits, checkout_starts — reconcilable) and **NOT SENT** (add_to_cart intents, order intents, notional revenue — structurally impossible for this script to produce), with the reason stated inline.

**`datetime.utcnow()`** is deprecated from Python 3.12; replaced with a timezone-aware `now()`. Note the formatting had to change too: calling `.isoformat()` on a tz-aware value already appends `+00:00`, so the original `+ "Z"` would have produced `...+00:00Z`.

**Reconciliation, and it is exact.** Snapshotted the summary per slice, ran 150 seeded visitors, drained the queue, re-aggregated, and diffed. All 8 slices matched the script's printed SENT figures on both metrics with no discrepancy: direct 20/4, organic 54/5, paid:bing 5/0, paid:google 7/1, paid:meta 14/1, paid:reddit 7/1, paid:tiktok 13/0, referral 30/6 — total 150 visits and 18 checkout_starts. `add_to_carts`, `orders` and `revenue` stayed at 37 / 6 / 468.00 throughout, exactly as the NOT SENT block predicts. The summary still equals the raw tables (470 visits, 54 checkout_starts on both sides), and the admin grid renders all 38 slices with the derived ratio columns computing. Traffic classification was correct across every platform and every organic search engine, all resolved server-side from click-id params and referrers.

**What this does and does not sign off.** The simulator verifies the whole client-side path — beacon payload, endpoint, queue, consumer, classification, aggregation, grid — for visits and checkout stages. It cannot verify `add_to_carts`, `orders` or `revenue`, because it cannot raise server-only events. Those three columns are covered instead by the real browser checkout at P2-T6 and by the seeded server-side `add_to_cart` events at P3-T6.

### P3-T7 complete — retention purge (2026-09-19)
`Cron\PurgeOldData` was a TODO stub; the cron entry, the system.xml fields and the config.xml defaults already existed from the scaffold. Now implemented as `Model\Service\DataPurger` with `Model\Config\RetentionConfig`, plus `bin/magento aavirbhava:adsanalytics:purge` for running it on demand.

**Three decisions worth not reversing:**

*A retention window of 0 means disabled, not "delete everything".* The obvious implementation — cutoff = `now - N days` — turns a cleared admin field or a missing config row into a cutoff of `now`, and the next cron tick deletes every visit in the table, cascading into every funnel event and every order attribution. `RetentionConfig` therefore distinguishes "not configured" (null or empty → use the shipped 180/14 default) from "configured to 0" (→ disabled, caller skips the delete entirely), and clamps negatives to disabled too, since a negative window puts the cutoff in the future and matches every row ever written. The null check has to come *before* the int cast — casting first turns null into 0 and silently disables retention on any install whose config row has not been saved yet. Seven unit tests in `Test/Unit/Model/Config/RetentionConfigTest.php` pin this.

*Visits are purged on `last_seen_at`, funnel events on their own `created_at`.* `ads_analytics_visit` is one row per visitor, updated on every landing, so `first_seen_at` is when the person was first seen — deleting on it would destroy someone who is still actively shopping and take their in-flight funnel and order attribution with it through the FK cascade. `last_seen_at` means "no activity for N days", which with a 90-day cookie lifetime is genuinely dead data by the time it is deleted. But that alone would let a continuously active visitor retain every event they ever raised, so funnel events are purged independently on their own timestamp rather than being left to the cascade.

*Deletes are batched.* 5,000 rows per statement, capped at 1,000 batches per table per run. One unbounded DELETE against a large visit table holds locks for the whole statement, cascades into two child tables inside the same transaction, and can hit lock-wait timeout — wedging the cron group and blocking live ingest writes behind it. Each batch autocommits, so a mid-run failure leaves earlier batches purged and the next run resumes.

**A consequence of purging that needed a guard elsewhere.** `ads_analytics_daily_summary` is kept for ever while its source rows are not, and the aggregator's upsert *replaces* a slice's values rather than incrementing them. Re-aggregating a date older than the retention window therefore recomputes it from a raw table that no longer holds those rows and overwrites real history with zeros. The nightly cron sweeps only a 7-day lookback so it can never reach back that far, but `aggregate --from/--to` can — so that command now refuses pre-retention dates with exit code 1 unless `--force` is passed. It compares against `DataPurger::cutoff()` rather than recomputing a cutoff of its own, so the guard can never disagree with what the purge actually deleted.

**Schema change:** added a btree index on `ads_analytics_visit.last_seen_at`. `ads_analytics_funnel_event.created_at` and `ads_analytics_request_log.received_at` were already indexed, but the visit table was not, which would have made the nightly purge a full scan of the module's largest table.

**Verified** by seeding back-dated rows alongside the live reconciliation dataset and purging with a 30/7-day window:
- Dry-run counts (2 visits, 3 funnel events, 1 log row) matched the real purge exactly.
- The two 300-day-old visits were deleted; the deliberately *active* test visitor survived, but its 300-day-old `product_view` was deleted while its recent `add_to_cart` remained — proving the independent funnel purge.
- The FK cascade removed the old visit's `order_attribution` row.
- `ads_analytics_daily_summary` was untouched (32 slices before and after), and the 120-visit reconciliation dataset was intact afterwards.
- `--event-days=0 --log-days=0` reported "purge disabled", not a 120-row delete.
- Both cron jobs resolve through DI and execute: `aavirbhava_adsanalytics_aggregate_daily_summary` at `0 2 * * *`, `aavirbhava_adsanalytics_purge_old_data` at `30 2 * * *`.
- 127 unit tests green, 0 phpcs errors on the new files.

Note for running the suite: `dev/tests/unit/phpunit.xml.dist` fails on a missing `allure/allure.config.php`. A minimal scoped config at `var/phpunit-ads.xml` (outside the module, not shipped) pointing at `Test/Unit` runs it cleanly.

### P3-T6 complete — the summary reconciles exactly against raw data (2026-09-19)
Checked the cron's output by recomputing every figure from the raw tables with a query deliberately written differently from the aggregator's (joining `ads_analytics_visit` to `ads_analytics_funnel_event` and `ads_analytics_order_attribution` directly, rather than through the aggregator's three UNION'd fact sources), so a shared bug could not cancel itself out.

**Result: exact.** A per-slice diff across all 32 slices on visits / add_to_carts / checkout_starts / orders / revenue returns zero rows, in both directions — no summary row disagrees with raw, and no raw slice is missing a summary row. Totals: 120 visits, 37 add-to-carts, 17 checkout starts, 6 orders, 468.00 revenue. Per-day and per-traffic-type splits (paid 46 / organic 33 / referral 25 / direct 16) match the visit table exactly. Two further aggregation runs reported "0 summary row operations" and produced a byte-identical MD5 over every row, so the upsert is still converging rather than incrementing.

**Finding 1 — `add_to_carts` was reconciling vacuously.** It matched at 0 = 0 because the raw funnel table held no `add_to_cart` rows at all: the traffic simulator cannot produce them, since `add_to_cart` is in `EventIngestService::SERVER_ONLY_EVENT_TYPES` and the public endpoint drops it. That column's aggregation path had therefore never been exercised by any test. 37 events were seeded through the genuine `ingestFromServer()` → queue → `EventConsumer` path (17 for visitors that reached checkout, 20 deterministic cart abandoners chosen by `crc32(uuid) % 5`) and the column then reconciled exactly. **Lesson for future verification: a metric that reconciles at zero has not been verified.** Check that the raw side is non-empty before believing a match.

**Finding 2 — the funnel is not monotonic, and must not be rendered as drop-off percentages.** Real slices exist with `orders > checkout_starts` (for example `paid/google`: 3 visits, 0 add-to-carts, 1 order). This is not a join bug — the per-slice diff proves the aggregator reports the raw data faithfully. The cause is that `visits`, `add_to_carts` and `order_placed` are recorded server-side while `checkout_start` and the step events come from the storefront beacon, so an ad blocker, a consent gate or a dropped request loses the client-side stage while the server-side order still lands. `ads-dashboard-chart.js` already plots absolute counts rather than percentages of the previous stage, so this renders honestly; a comment now records why, because switching to drop-off percentages would show >100% or negative values on exactly these slices.

**Not a defect, but worth stating:** `ads_analytics_daily_summary` has no `product_views` column, so the 120 raw `product_view` events are captured but never aggregated and cannot appear in any report. This matches docs/SPECS.md §3, which lists the columns as `visits`, `add_to_carts`, `checkout_starts`, `orders`, `revenue` — so it is a spec decision, not drift. Flagged for a decision rather than changed unilaterally: adding the column would make the funnel's widest stage visible, at the cost of a schema change and a spec amendment.

### P3-T3/T4/T5 complete — the admin report page (2026-09-19)
Admin > Reports > Ads Analytics now renders a real report instead of a blank page.

- `Model\ResourceModel\DailySummary\Grid\Collection` is registered on `UiComponent\DataProvider\CollectionFactory` and derives three ratio columns in SQL rather than in PHP, so they stay sortable and filterable by the grid: `conversion_rate`, `cart_rate`, `revenue_per_visit`. Each divides by `NULLIF(visits, 0)`, which turns a zero-visit slice into NULL instead of a division-by-zero — MySQL would otherwise emit a warning and return NULL anyway, but only in non-strict mode, so relying on that would be install-dependent.
- `Model\Config\Source\TrafficType` builds the grid's traffic-type filter from `TrafficResolver`'s own constants plus `VisitManager::TRAFFIC_TYPE_UNKNOWN`, so the filter cannot drift away from what the classifier actually writes.
- Charts (`Block\Adminhtml\Dashboard`, `dashboard/overview.phtml`, `view/adminhtml/web/js/ads-dashboard-chart.js`) use core's bundled `chartJs` RequireJS alias. Do **not** add a module-local `paths: {chartjs: ...}` mapping — it shadows the core alias and loads a second copy.

**Deviation from the task wording, deliberately.** TASKS.md called for a "Dashboard tab" and a "Grid tab". The page instead stacks the charts above the grid on one page, because `ads-dashboard-chart.js` reads the grid's own data via `registry.get(config.listingProvider, ...)` and re-draws whenever the provider's data changes. That is what makes filtering the grid also filter the charts. Two tabs would need either a second data provider (duplicated queries, and the two views could disagree) or cross-tab plumbing for no user-visible gain. The reason is recorded in a comment in `view/adminhtml/layout/ads_analytics_report_index.xml` so it is not "fixed" later by someone reading only TASKS.md.

**Verified**, against the 32 summary rows the P3-T2 run produced:
- The page returns HTTP 200 (81,060 bytes), titled "Ads Analytics", with both canvases, the chart component and the listing component all present in the markup.
- The data source resolves through `getReport('aavirbhava_adsanalytics_summary_listing_data_source')` and returns 32 rows with the derived columns populated.
- Filters apply: `traffic_type=paid` returns 24 rows whose only distinct traffic type is `paid`; `campaign LIKE '%spring%'` returns 5; a `date >=` bound applies cleanly.
- Sorting works on a derived column (`ORDER BY conversion_rate DESC`), which is the thing computing the ratios in PHP would have broken.

One verification route did **not** work and is worth not retrying: fetching the grid's `mui/index/render` URL by hand returns the component's HTML shell, not JSON, because the data response depends on request particulars the UI Component's own JS sets. The same thing happened with the request-log grid at P3-T5b. Verify grid data through the collection in PHP instead.


### P3-T2 complete — the aggregation cron (2026-09-19)

`Model\Aggregation\DailySummaryAggregator` builds `ads_analytics_daily_summary` with a single idempotent statement: `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE col = VALUES(col)` over three UNION'd fact sources.

Design decisions worth keeping:
- **Visits bucket on `first_seen_at`, never `last_seen_at`.** first_seen_at is immutable, so a returning visitor cannot migrate between days. Using last_seen_at would make a re-run produce different numbers and destroy idempotency.
- **Orders and revenue come from `ads_analytics_order_attribution` and are grouped by THAT table's dimensions**, not the visit's. That is the point of storing the attribution model on the row: under first-touch, an order belongs to the campaign that acquired the visitor, which may differ from the visit's last touch. Expect a first-touch slice to show orders with zero visits — that is correct, not a bug.
- **Every dimension is COALESCEd to `'null_source'`.** The raw tables allow NULLs; the summary's unique key spans those columns and is NOT NULL, so an explicit NULL raises MySQL 1048 and kills the job.
- **The cron re-sweeps a lookback window (default 7 days, configurable)** rather than just yesterday, because events arrive through a queue — a visit at 23:59 may be consumed after the 02:00 run. Safe precisely because the upsert replaces rather than increments.
- Added `bin/magento aavirbhava:adsanalytics:aggregate [--days|--from|--to]`, without which the reports could only be populated by waiting for the nightly cron, making the dashboard untestable and backfilling impossible.

Verified against real seeded data (120 visits, 188 funnel events, 6 orders placed through the genuine quote pipeline): visits 120/120, checkout_starts 17/17, orders 6/6, revenue 468.00/468.00 — exact on every metric. Running it twice more reported `0 summary row operations` with totals unchanged.

Integration suite now **32 tests / 76 assertions** (6 new, covering the first_seen_at bucketing rule, NULL-sentinel handling, funnel attribution through the visit, idempotency across three runs, date-window scoping, and an empty window being a clean no-op). The integration install was stood up for the run and torn down again.

**Known simplification:** dates are bucketed in **UTC**, not store timezone. For a store far from UTC, "yesterday" in the report is a UTC day. Left deliberately un-half-solved — fixing it means applying the store offset consistently across all three fact queries, and it should be done before this drives financial reporting.

### P2-T6 complete — full funnel verified in a real browser, and the CSP trap (2026-09-19)

User-run walkthrough, third attempt, now recording the complete funnel:
`add_to_cart` -> `checkout_start` -> `checkout_step_shipping` -> `checkout_step_payment` -> `order_placed`, plus `product_view`.

**The blocker was Content Security Policy, and it cost two wrong diagnoses.** The beacon config was emitted as an inline `<script>`. Magento's CSP blocks inline scripts on the checkout page — `"Executing inline script violates the following Content Security Policy directive 'script-src ...' The action has been blocked."` — so `window.aavirbhavaAdsAnalytics` never existed there, the beacon returned at its first guard, and every checkout event vanished silently. Product pages worked throughout, because CSP is report-only there, which made the failure look like a checkout-hook problem.

Two implementations were built and discarded chasing that false lead: a jsLayout component under `checkout.root`, then a RequireJS mixin on `step-navigator`. Both were almost certainly correct; they simply had no beacon to call, and the adapter's own defensive `safeTrack()` swallowed the missing API exactly as designed. **The user's console screenshot found in one look what two rounds of server-side inspection could not** — when client-side behaviour is unexplained, ask for the browser console before theorising further.

Fixes that came out of it:
- **Config travels as a `data-config` attribute**, read by the EXTERNAL beacon file. CSP permits external scripts; only inline code is blocked. No CSP whitelist entry or inline-script hash was added on purpose — a hash would need regenerating on every config change (different store URL, different platform list) and would break silently.
- **Checkout steps are detected from `window.location.hash`**, which Magento's step-navigator sets to the step code. That is observable from vanilla JS, so the RequireJS/Knockout exception added to CLAUDE.md #4 for P2-T3 is no longer needed — the storefront is 100% vanilla again.
- **`onCheckoutPage()` now matches the path EXACTLY, not as a prefix.** The first successful run revealed a second bug: `/checkout/cart/` and `/checkout/onepage/success/` also match a `/checkout/` prefix, so viewing the cart and hitting the confirmation page each fired an extra `checkout_start` — inflating the top of the funnel and corrupting the one number the report exists to show.
- **Beacon debug logging added** (23 log points): store config toggle, default off, plus `?adsanalytics_debug=1` remembered in sessionStorage so it survives a whole multi-page walkthrough. It narrates the event-type decision and why, consent state, checkout detection, each hash step, and whether each request was sent, deduped or suppressed.

Worth knowing: **a `landing` writes no funnel row** — it creates or refreshes the visit. A first page view therefore appears in `ads_analytics_visit`, never in `ads_analytics_funnel_event`. The debug log now says so explicitly, because it reads as a missing event otherwise.

### P2-T7 complete — volume run, and a simulator correction (2026-09-19)

500 visitors -> **1240 events accepted, 0 failed, 1240 queued, drained to 0**. Exact match on everything the endpoint accepts: visits 500/500, checkout_start 60/60, all three checkout steps 60 each, product_view 500, **0 rejections**. Split: paid 213 / organic 130 / referral 83 / direct 74.

The first attempt reconciled badly and that turned out to be a genuine consequence of P2-T5, not a bug: **1350 accepted minus 1228 queued = 122, exactly the 120 `add_to_cart` plus 2 `order_placed` the server-only gate discarded.** The simulator was still POSTing event types the endpoint now refuses by design.

Rather than leave the tool quietly producing un-reconcilable numbers, it now knows about `SERVER_ONLY_EVENTS`, skips them, and prints `server-only, NOT sent: N` plus a warning that its add_to_cart/orders columns are funnel INTENT which will not appear in the database. Same principle as the earlier fix where it reported success while every request was failing: a test tool that misreports is worse than one that fails loudly.

Consequence worth remembering: **the simulator can no longer exercise the add-to-cart or order paths at all.** Those go through `AddToCartObserver` and `OrderPlaceAfterObserver` and need a real cart and a real order — which is exactly what P2-T6's manual walkthrough is for.

### P2-T2/T4/T5 complete — the funnel now reaches revenue (2026-09-19)

**P2-T2 (add-to-cart)** and **P2-T4 (order attribution)** both route through a new `ServerEventDispatcher` -> `EventIngestService`, rather than publishing to the topic directly. That matters: publishing directly would have bypassed the kill switch, sampling, rate limiting, the server-side ip/UA capture and the publish-failure containment, all of which an observer should inherit rather than reimplement.

**P2-T5 closed a real hole.** `order_placed` carries the order id and creates the attribution row, so accepting it over the public endpoint would have let anyone POST `{"event_type":"order_placed","entity_id":<someone else's order>}` and claim that order's revenue for their own visit. `order_placed` and `add_to_cart` are now server-only: the webapi `ingest()` drops them, and they reach the queue only through `ingestFromServer()`, which is deliberately absent from `Api\EventIngestInterface` so `etc/webapi.xml` cannot expose it. Verified live — forged POSTs of both types queued 0 messages.

**Three bugs found by running it, none visible on inspection:**

1. **`sales_order_place_after` is the wrong event.** docs/SPECS.md named it, but it is dispatched from inside `Order::place()` BEFORE the order is persisted, so `getId()` is null and the observer dropped every order silently. Now on `sales_model_service_quote_submit_success`, which fires right after `OrderManagement::place()` has saved it — and sits in `submitQuote()`, reached by both `placeOrder()` (frontend/REST) and the lower-level `submit()` integrations call.

2. **`AbstractDb::save()` silently wrote nothing.** `ads_analytics_order_attribution`'s PK is `order_id` — supplied by us, not auto-increment. With the default `_useIsObjectNew = false`, `isObjectNotNew()` is just "has an id", so Magento issued `UPDATE ... WHERE order_id = 6`, matched zero rows and returned successfully.

3. **Setting `_useIsObjectNew = true` then inserted `order_id = 0`**, because `saveNewObject()` unsets the id field before inserting (it assumes auto-increment). Both failure modes are silent. The writer now uses `insertOnDuplicate`, which sidesteps the whole class of problem and is exactly the semantics an at-least-once queue needs — a redelivery refreshes the row instead of duplicating or failing. Verified: two writes leave one row.

Verified end to end: a visitor landed from a Google ad (`first_campaign`), landed again from Instagram (`last_campaign`), then ordered. The visit kept `first_touch=google/first_campaign` and `last_touch=instagram/last_campaign`; the attribution row credited **instagram/meta** under the default `last_touch`. Switching the config to `first_touch` and placing another order credited **google/first_campaign** — and order 7 KEPT `last_touch`, because the model is stored on the row rather than re-read at report time.

Unit suite now **118 tests / 188 assertions**.

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
- `tools/simulate_traffic.py`: all three defects noted here previously (unsent `revenue`, non-reproducible `--seed`, deprecated `datetime.utcnow()`) were FIXED at P3-T8. What remains is a structural limit, not a bug: the script cannot produce `add_to_cart` or `order_placed`, because both are server-only, so it can never exercise the `add_to_carts`, `orders` or `revenue` columns. The printed summary now says so explicitly rather than implying otherwise.
- `order_attribution` has no FK to `sales_order`, and its CASCADE from `visit_id` means `PurgeOldData` deleting old visits would destroy attribution for live orders.
- `order_attribution.traffic_type` and `daily_summary.traffic_type` are NOT NULL with no default, unlike `visit.traffic_type`'s `'unknown'` — P2-T4/P3-T2 must set them explicitly.
- No idempotency anywhere in the consumer: AMQP is at-least-once, so a redelivered message writes a duplicate request-log row and funnel event.
