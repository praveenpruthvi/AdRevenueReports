# Testing — Aavirbhava_AdsAnalytics

Reference `docs/TASKS.md` task IDs when assigning test coverage — flip a task's status only after its verification step (already listed per phase) passes.

## 1. Unit tests (PHPUnit)
- Traffic resolver (§SPECS.md §2): correct `platform_code`/`traffic_type=paid` resolution for each configured click-id param and paid-medium, **case-insensitively** (e.g. `utm_medium=CPC` must still classify as paid); correct tagged-non-paid classification when `utm_source` and/or `utm_medium` is present but not paid (including `utm_medium`-only, with no `utm_source`); correct `campaign` resolution (from `utm_campaign`) across the paid and tagged-non-paid branches; correct `organic` classification against the search-engine domain list **at a label boundary, not a substring** (`notgoogle.com` and `evil-bing.com.attacker.net` must NOT match; `mail.google.com` — decide and test the intended behavior explicitly, don't leave it implicit); correct `referral` fallback for a non-search referrer; correct `direct` fallback with no referrer/params; correct precedence ordering per SPECS.md §2 (paid → tagged → organic → referral → direct); `source`/`medium`/`campaign` truncated to column width in the output; correct `facebook` vs `instagram` **source** disambiguation via `utm_source` — `platform_code` must stay `meta` in both cases, never become `instagram`.
- Attribution model logic: first-touch vs last-touch selection, config toggle respected.
- Cron aggregation math: given known raw rows, daily_summary output matches expected counts/revenue exactly.
- `AdSpendProviderPool`: resolves the correct provider by platform_code, handles missing provider gracefully.

## 2. Integration tests
- Schema installs cleanly on a fresh DB (`db_schema.xml` for all five tables).
- Observers fire and produce expected rows: `add_to_cart`, `order_placed` (with correct `ads_analytics_order_attribution` row).
- Ingest endpoint accepts a valid payload (per SPECS.md §6 schema) and enqueues correctly; consumer drains the queue and writes expected visit/funnel rows.
- Invalid payloads (unknown platform_code, oversized strings, missing required fields) are rejected without a 500 and without being queued.
- **Request log**: every request — accepted and rejected — produces exactly one `ads_analytics_request_log` row with the correct `validation_status`/`rejection_reason`; raw payload is stored verbatim (not mutated); `queue_message_id` is populated only for accepted requests; logging level config (`Off`/`Rejected only`/`All requests`) is respected.

## 3. Checkout flow QA (manual)
Gated by the checkout-type decision (CLAUDE.md §Open decisions). Full funnel walkthrough — landing with a click-id → product view → add to cart → checkout steps → order placed — confirm every `ads_analytics_funnel_event` row exists, in order, on the same `visit_id`, and the resulting order has a correct `ads_analytics_order_attribution` row.

## 4. Admin UI tests
- Dashboard chart renders and matches underlying summary data.
- Grid filters (day/week/month/year, custom range, platform, campaign) return correct filtered rows.
- Excel/PDF export content matches what the grid displays for the same filter state.
- Request Log grid: filters by status/date/visitor_uuid return correct rows; raw payload renders escaped (paste a payload containing `<script>` during test data setup and confirm it displays as inert text, not executed).

## 4b. Running the integration tests (P1-T10)

The integration suite needs its own Magento install in a **separate,
disposable database**. It is not configured in the repo by default; set it up,
run it, and tear it down again:

```bash
# 1. Config (not tracked — the .dist ships localhost/123123q, which is wrong
#    for this project). Use the compose service names, and take the
#    credentials from this project's env/db.env and env/rabbitmq.env rather
#    than hardcoding them here:
#      db-host=db, db-user=root, db-password=$MYSQL_ROOT_PASSWORD
#      opensearch-host=opensearch
#      amqp-host=rabbitmq, amqp-user/password from env/rabbitmq.env
cp dev/tests/integration/etc/install-config-mysql.php.dist \
   dev/tests/integration/etc/install-config-mysql.php   # then edit as above

# 2. Create the database. setup:install does NOT create it and fails with
#    "Database 'magento_integration_tests' does not exist" if you skip this.
bin/cli mysql -h db -u root -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS magento_integration_tests CHARACTER SET utf8mb4;"

# 3. Run. The FIRST run installs Magento into that database (slow); later
#    runs reuse it.
bin/dev-test-run integration ../../../app/code/Aavirbhava/AdsAnalytics/Test/Integration

# 4. Tear down and reclaim the space when finished.
bin/cli mysql -h db -u root -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE magento_integration_tests;"
rm -f dev/tests/integration/etc/install-config-mysql.php
rm -rf dev/tests/integration/tmp/sandbox-*
```

Do NOT pipe the run through `tail` while debugging a failed install: the real
error appears near the START of the output and everything after it is stack
trace, so `| tail -N` hides the only useful line.

What the suite covers, deliberately chosen as things unit tests *cannot*
assert: that `db_schema.xml` really produces the tables, NOT NULL sentinels
and the composite unique key; that an explicit NULL into a sentinel column is
rejected by MySQL; that the funnel-event FK refuses an orphan; and that the
shipped `config.xml` defaults classify paid/organic/direct correctly once
Magento loads them for real (unit tests mock that config).

## 5. Load / traffic simulation
Use `tools/simulate_traffic.py` to generate synthetic multi-platform traffic against the ingest endpoint, including a mix of paid, organic, referral and direct visits (not paid-only). Purposes:
- **Phase 1/2 smoke test**: confirm the endpoint + queue consumer handle volume without errors, and that the checkout-facing path shows no latency regression while the queue is under load.
- **Phase 3 data check**: generate a known volume/mix of traffic, then verify the dashboard/grid numbers reconcile exactly against what the simulator sent (it prints a summary of what it generated for this purpose).
- **Phase 4 regression**: re-run after export/ROAS work to confirm nothing upstream broke.

Usage:
```bash
pip install requests
python3 tools/simulate_traffic.py --base-url https://magento.test --visitors 500 --conversion-rate 0.03
```
See the script's header comment for the full option list (platform mix, concurrency, funnel drop-off rates, random seed for reproducibility).

**What the simulator can and cannot verify (established at P3-T8).** It prints its summary in two blocks and only the first is reconcilable:

- **SENT** — `landing` (one visit per `visitor_uuid`), `product_view`, `checkout_start` and the checkout step events. These must reconcile *exactly* against `ads_analytics_daily_summary`. Reconcile by snapshotting the summary per slice, running the simulator, draining the queue, re-aggregating, and diffing the before/after delta against the printed figures — not by comparing absolute totals, which include whatever data was already there.
- **NOT SENT** — `add_to_cart` and `order_placed`. Both are server-only and the public endpoint drops them (docs/SECURITY.md §3), so the simulator cannot create them and the "revenue" it prints corresponds to no order. Expect the summary's `add_to_carts`, `orders` and `revenue` columns to be **unchanged** by a simulator run; that is the correct result, not a lost event. Those columns are verified with a real add-to-cart and a real checkout instead.

`--seed` gives a byte-identical dataset across runs, including visitor UUIDs. Remember to drain the queue (`bin/magento queue:consumers:start AavirbhavaAdsAnalyticsEventConsumer`) and then run `bin/magento aavirbhava:adsanalytics:aggregate` before reconciling — the reports read only from the summary table, so nothing appears until both have run.

## 6. Sign-off checklist per phase
1. All unit/integration tests for the phase pass.
2. Manual verification task for the phase (already listed in `docs/TASKS.md`) completed.
3. For Phase 1/2/3: a `simulate_traffic.py` run completed with expected data showing up correctly downstream.
4. `docs/PROGRESS.md` updated in place to reflect phase completion.
