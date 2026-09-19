---
name: aavirbhava-ads-analytics
description: Coding conventions and patterns for building/extending the Aavirbhava_AdsAnalytics Magento 2 module — schema, extensibility, async event capture, Hyvä-safe frontend. Use for any task touching this module.
---

# Aavirbhava_AdsAnalytics — build conventions

Before writing code, read `docs/SPECS.md` (contracts/schema) and `docs/TASKS.md` (what task, what order). This file is *how* to build it, not *what* to build.

## Schema
- Declarative schema only: `etc/db_schema.xml` + `Setup/Patch/Data` classes. Never `InstallSchema.php`/`UpgradeSchema.php`/`InstallData.php` — those are deprecated patterns, don't reintroduce them even if you've seen them in older modules.
- Every new table needs an explicit primary key and indexes on any column used in a WHERE/JOIN in reporting queries (`visit_id`, `date`, `platform_code`, `campaign`).

## Extensibility — the one rule that matters most
Nothing in this module's core classes should ever contain a platform name (`google`, `meta`, `tiktok`, etc.) in a conditional. Platform behavior is either:
1. A row in the click-id/platform config map (`system.xml` / config table) — for visit attribution, or
2. A `di.xml` virtual-type array entry pointing at an `AdSpendProviderInterface` implementation — for spend/ROAS.

If a task seems to require a `switch ($platform)` in a core class, stop and restructure it as one of the two above instead.

## Async-first on the customer-facing path
- No `ResourceModel::save()` calls triggered directly from a storefront-facing controller, observer on a customer request, or frontend JS response handler.
- Frontend events → REST endpoint → MessageQueue → consumer → DB write. This is not optional for performance reasons (checkout must not regress) and is required even in local/dev — don't special-case it away "for now."

## Frontend (Hyvä constraint)
- No RequireJS, no Knockout, no jQuery in anything shipped to the storefront `<head>`/`<body>`. Vanilla JS only for the beacon.
- Checkout-step hooks: check `CLAUDE.md` §Open decisions for which checkout (Hyvä/Alpine vs. LUMA/Knockout) is confirmed before writing P2-T3. Don't guess or implement both speculatively — ask if it's still unresolved.

## Admin
- Admin UI Components (Knockout-based grids/forms) are fine — Hyvä doesn't apply to admin.
- Follow the same tab pattern (Dashboard tab / Grid tab) and Chart.js-via-core's-bundled-copy approach used in `Aavirbhava_SalesAnalytics`, for visual/UX consistency across the two modules.

## Docs discipline
- Every completed task: flip its status in `docs/TASKS.md` and update the snapshot in `docs/PROGRESS.md` **in place** — don't regenerate either file.
- Task prompts you write for future sessions should stay token-efficient: only include the verification/inspection ceremony a task actually needs, not a maximal template every time.
