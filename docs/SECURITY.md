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
- `referrer` (used for organic/referral classification, docs/SPECS.md §2) is truncated to **hostname only** before storage — never the full URL. This applies to `ads_analytics_request_log.referrer_host` as well, which is populated from `TrafficResolver::extractHost()` for exactly this reason. Note the contrast with `page_url` on that same table, which DOES keep its query string: the store's own URL is the store's to log, another site's is not. A full referrer URL can carry a search query, an internal tracking token, or other sensitive path/query data that isn't ours to keep; the hostname alone is all classification needs.
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

**Ad-spend CSV upload** (`Controller\Adminhtml\AdSpend\Upload`) is gated on `Aavirbhava_AdsAnalytics::config`, not the dashboard's own resource — uploading spend data changes what the ROAS report shows, which is closer to "can change this module's configuration" than "can view its reports". `Block\Adminhtml\Dashboard::canManageAdSpendData()` checks the same resource before rendering the upload form at all, so an admin who cannot submit it never sees it.

Upload validation, in order: PHP's own upload-error code; `is_uploaded_file()` on the temp path (refuses a forged path masquerading as an upload — the one check with no application-level fallback, since it is enforcing a fact about how the file arrived, not about its content); size capped at 5 MiB; `.csv` extension; then the file is parsed through `Model\AdSpendProvider\AdSpendCsvFile` (the same class the read path uses) and rejected outright if it yields zero valid rows, so a wrong-format upload cannot silently replace a platform's working spend data with an empty file. The destination path is built from the platform code the same safe-charset way `AdSpendCsvFile::pathFor()` already does for reads, and the actual write goes through `Filesystem\Directory\Write::writeFile()` on a relative path (which validates internally that the path cannot escape its own directory) rather than an absolute path built by hand.

## 10. Secrets
Any ad-spend provider API credentials (Phase 4 — Google Ads/Meta Ads APIs) must be stored via Magento's encrypted config backend (`Magento\Config\Model\Config\Backend\Encrypted`), never plain text in `system.xml` defaults or DB.

## 10. Secrets
Any ad-spend provider API credentials (Phase 4 — Google Ads/Meta Ads APIs) must be stored via Magento's encrypted config backend (`Magento\Config\Model\Config\Backend\Encrypted`), never plain text in `system.xml` defaults or DB.

**Implemented at P4-T6.** This module ships **no** credential field, because the only provider it includes, `CsvAdSpendProvider`, reads a file and needs none. A provider that calls a real platform API is added by a client project, which declares its own fields and reads them back through `Model\Config\AdSpendCredentials`. Adding a field to this module's own `system.xml` just to have one would be dead configuration with nothing reading it.

**Adding a credential to a provider module.** In the provider module's own `etc/adminhtml/system.xml`, extend this module's section (Magento merges sections by id):

```xml
<config>
    <system>
        <section id="aavirbhava_adsanalytics">
            <group id="google_ads" translate="label" sortOrder="200" showInDefault="1">
                <label>Google Ads</label>
                <field id="client_secret" translate="label" type="obscure" sortOrder="10" showInDefault="1">
                    <label>Client Secret</label>
                    <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
                </field>
            </group>
        </section>
    </system>
</config>
```

Both halves are required, and neither is enough alone: `type="obscure"` masks the value in the admin form, while the `Encrypted` backend model is what actually encrypts it before it reaches `core_config_data`. A field that is masked but not encrypted looks safe in the browser and is stored in clear. Never put a value for such a field in `config.xml`.

Read it with `AdSpendCredentials::get('aavirbhava_adsanalytics/google_ads/client_secret')`. Do not call `ScopeConfigInterface::getValue()` and use the result: it returns the ciphertext, because nothing decrypts on read.

**The reader fails closed.** It returns `null` (and logs the config path — never the value) when the credential is unset, was stored unencrypted, or cannot be decrypted. The "stored unencrypted" check exists because `Encryptor::decrypt()` does *not* fail on plain text: a value with no colons is treated as the legacy Blowfish format and "decrypted" into junk, often non-empty. A secret placed in the database directly, or through `config:set`, would otherwise appear to work or fail mysteriously; refusing it forces the mistake to be fixed. A rotated or lost crypt key makes a correctly stored secret undecryptable, and surfaces the same way, with a log line telling the operator to re-enter it.

**Enforced by a test.** `Test/Unit/Config/SecretFieldPolicyTest` scans this module's `system.xml` and `config.xml` and fails the build if any field whose id or label looks like a secret (`secret`, `token`, `password`, `api key`, `private key`, `credential`, `access key`) is not `type="obscure"` with the `Encrypted` backend model, or has a default value. It is a policy check on THIS module only; a provider module needs the same test of its own.

**Alternative: keep the secret out of the database entirely.** `bin/magento config:sensitive:set <path> <value>` stores it in `env.php` rather than `core_config_data`. That path must be declared as sensitive in a `di.xml` argument to `Magento\Config\Model\Config\TypePool`, and a value set that way is read back with the ordinary config API rather than `AdSpendCredentials`, since it is not encrypted with the crypt key. Suitable for deployments that inject secrets from an environment or vault.
