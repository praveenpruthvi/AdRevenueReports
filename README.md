# Aavirbhava_AdsAnalytics

Magento 2 module: tracks paid-ad visits, checkout-funnel progression, and order
attribution across ad platforms, with async ingest, cron aggregation, and
admin reporting (dashboard + grid + export).

**Start here:** `CLAUDE.md` (standing rules for any coding session on this
module), then `PROJECT_PLAN.md` (phases/scope), then `docs/TASKS.md` (ordered,
granular task list — this is what tells you what to build next).

## What's in this scaffold vs. what's still to build
This is a **structural scaffold**, not a finished module. Directory layout,
config XML, table schema, service interfaces, and class skeletons are in
place and wired together (`etc/di.xml`, `etc/webapi.xml`, `etc/queue_*.xml`,
`etc/db_schema.xml`, `etc/crontab.xml`, `etc/events.xml`). Method bodies in
most PHP classes are stubs with `// TODO(P#-T#): ...` comments pointing at the
exact task in `docs/TASKS.md` that fills them in. Work through Phase 1 first,
in task order — nothing in Phase 1 is blocked.

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

## Checkout-step tracking: LUMA now, Hyvä as a drop-in

Checkout is the one genuinely theme-specific part of this module, so it is
built as a **shared core plus a thin per-theme adapter**:

| Layer | File | Stack |
|---|---|---|
| Shared beacon (all themes) | `view/frontend/web/js/ads-analytics-beacon.js` | Vanilla JS |
| LUMA checkout adapter | `view/frontend/web/js/checkout-luma.js` | RequireJS + Knockout |
| Hyvä checkout adapter | *not shipped — see below* | Alpine.js |

**Only the LUMA adapter ships.** Hyvä Checkout is a paid product, so this
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

2. **Load it only on Hyvä checkout**, the same way the LUMA adapter is scoped
   to `checkout_index_index.xml`. Nothing else in the module needs to know it
   exists.

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

## Docs map
- `CLAUDE.md` — standing architectural rules
- `PROJECT_PLAN.md` — phases, platform scope, extensibility/Hyvä strategy
- `docs/SPECS.md` — schema, config, interfaces, payload contracts
- `docs/TASKS.md` — ordered task list (source of truth for "what's next")
- `docs/PROGRESS.md` — current status snapshot
- `docs/SECURITY.md` — threat model, validation, retention, ACL
- `docs/TESTING.md` — test strategy + how to use `tools/simulate_traffic.py`
- `SKILL.md` — coding conventions for implementation sessions
