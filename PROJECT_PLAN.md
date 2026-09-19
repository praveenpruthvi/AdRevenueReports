# Project Plan — Aavirbhava_AdsAnalytics

## Goal
Track visits — paid and organic alike — and attribute checkout/order outcomes to traffic source, across multiple ad platforms and organic/referral/direct traffic, with an admin reporting UI (grids + charts + export) that can break out paid vs. organic. Composer-installable. Extensible to new ad platforms and search engines without core changes. Hyvä-compatible storefront.

## Traffic scope — v1
- **Paid**: Google Ads, Meta/Facebook, Instagram (tracked via Meta's `fbclid` + `utm_source`, not a separate click id), TikTok, Bing, Reddit.
- **Organic / direct / referral**: every visit gets a `traffic_type` (`paid`/`organic`/`direct`/`referral`), classified server-side from click-id/UTM presence and `document.referrer` against a configurable search-engine domain list. See `docs/SPECS.md` §2 for the full classification mechanism and precedence rules.

## Extensibility contract
New platforms (Pinterest, Snapchat, LinkedIn, etc.) must be addable by:
1. Adding a row to the click-id/platform config map (system.xml or config data), **no code change**, for basic visit attribution.
2. Optionally shipping a separate small module with a class implementing `Api/AdSpendProviderInterface`, registered via a `di.xml` virtual-type array entry, for ad-spend/ROAS import — again with zero edits to this module's core.

See `docs/SPECS.md` §Extensibility for the exact mechanism.

## Hyvä compatibility strategy
- Storefront tracking = one vanilla-JS beacon file, no RequireJS/Knockout dependency.
- Checkout-step tracking has two possible implementations gated by which checkout the storefront runs (Hyvä Checkout/Alpine.js vs. default LUMA/Knockout) — **open decision, see CLAUDE.md**.
- Admin UI is unaffected (Hyvä doesn't touch admin/Knockout there).

## Phases
1. **Scaffold + capture layer** — module skeleton, `ads_analytics_visit` table, vanilla-JS beacon, click-id/UTM capture, first-party cookie, async ingest endpoint + queue consumer.
2. **Funnel events + order attribution** — `ads_analytics_funnel_event`, `ads_analytics_order_attribution`, cart/checkout/order observers, checkout-step hook (Hyvä or LUMA per open decision).
3. **Aggregation + admin reporting** — cron rollup into `ads_analytics_daily_summary`, admin dashboard (funnel drop-off, source/medium/campaign breakdown), Chart.js visuals, filterable grid.
4. **Export + ad-spend/ROAS** — Excel/PDF export, optional `AdSpendProviderInterface` implementations for Google/Meta spend import, ROAS calculation.

## Out of scope (v1)
- Real-time reporting; multi-touch/linear attribution modeling beyond first-touch/last-touch.
- **Organic social and email are not their own tracked channels.** A visit from an Instagram post link (no `fbclid`, no `utm_*`) or an email link (no `utm_*`) lands in `referral` (or `direct`, if the client strips the referrer) — same as any other unrecognized inbound link — rather than being broken out as `organic_social` or `email`. Tagging those links with `utm_medium=email`/`utm_medium=social` gets them classified as `referral`'s `source`/`medium` at least being informative, but there's no dedicated channel or config list for either the way there is for ad platforms and search engines.
- Ad spend/ROAS (Phase 4) applies to `traffic_type=paid` only — organic/direct/referral have no spend concept to attribute.

## Where the detail lives
- Task-by-task breakdown, order, dependencies → `docs/TASKS.md`
- Current status → `docs/PROGRESS.md`
- Technical spec (schema, interfaces, config, contracts) → `docs/SPECS.md`
- Coding conventions for implementation sessions → `SKILL.md`
