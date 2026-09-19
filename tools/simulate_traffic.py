#!/usr/bin/env python3
r"""
simulate_traffic.py — synthetic traffic generator for Aavirbhava_AdsAnalytics.

Simulates visitors arriving via paid ads, organic search, referral links, or
direct navigation, and walking (some distance into) the funnel: landing ->
product_view -> add_to_cart -> checkout_start -> checkout_step_shipping ->
checkout_step_payment -> checkout_step_review -> order_placed. Posts each
event to the module's ingest endpoint, matching the payload schema in
docs/SPECS.md section 6 (server-side classifies traffic_type via
Model\Service\TrafficResolver — this script only sends the raw signals:
click-id/utm params for paid, a search-engine or other referrer for
organic/referral, nothing for direct). Adjust BOTH places together if the
schema changes.

Usage:
    pip install requests
    python3 simulate_traffic.py --base-url https://magento.test --visitors 500 --conversion-rate 0.03

Options:
    --base-url          Required. Store base URL, e.g. https://magento.test
    --endpoint            REST path (default: /rest/V1/adsanalytics/event)
    --visitors            Number of simulated visitors (default: 100)
    --platforms            Comma-separated subset of: google,meta,bing,tiktok,reddit
                           (default: all five; "instagram" is generated as a
                           meta visit with utm_source=instagram, per SPECS.md)
    --paid-share           Fraction of visitors that arrive via a paid click-id (default: 0.4)
    --organic-share        Fraction that arrive via a search-engine referrer, no params (default: 0.3)
    --referral-share       Fraction that arrive via a non-search referrer, no params (default: 0.15)
                           (remainder is direct — no referrer, no params)
    --conversion-rate      Fraction of visitors that complete an order (default: 0.03)
    --add-to-cart-rate     Fraction of visitors that add to cart (default: 0.25)
    --checkout-start-rate  Fraction of cart-adders that start checkout (default: 0.5)
    --concurrency          Parallel worker threads (default: 10)
    --delay                Seconds to sleep between a visitor's own events (default: 0.05)
    --seed                 Random seed. Runs with the same seed generate an identical
                           dataset, including visitor UUIDs (default: none)
    --dry-run              Print payloads instead of sending them

At the end, prints what was generated, broken out by traffic_type (and by
platform within paid), for reconciliation against the admin dashboard/grid in
Phase 3 verification.

WHAT THIS SCRIPT CAN AND CANNOT VERIFY
--------------------------------------
The summary is printed in two blocks, and the distinction matters.

SENT: landing (one visit per visitor_uuid), product_view, checkout_start and
the checkout step events. These reach the ingest endpoint and must reconcile
exactly against ads_analytics_daily_summary.

NOT SENT: add_to_cart and order_placed. Both are server-only — the module
raises them from its own observers, and the public endpoint deliberately
drops them so that nobody can POST an order_placed carrying someone else's
order id and claim that order's revenue (docs/SECURITY.md section 3). This
script therefore cannot produce them, and the "revenue" it prints corresponds
to no order in the database. Earlier versions printed those figures in the
same table as the sent ones, which made the summary look un-reconcilable
against a dashboard that was in fact correct. The daily summary's
add_to_carts, orders and revenue columns have to be verified with a real
add-to-cart and a real checkout instead.
"""

import argparse
import concurrent.futures
import datetime
import random
import sys
import threading
import time
import uuid

try:
    import requests
except ImportError:
    print("This script requires the 'requests' package: pip install requests", file=sys.stderr)
    sys.exit(1)

CLICK_ID_PARAM = {
    "google": "gclid",
    "meta": "fbclid",
    "bing": "msclkid",
    "tiktok": "ttclid",
    "reddit": "rdt_cid",
}

SEARCH_ENGINE_REFERRERS = {
    "google": "https://www.google.com/search?q=example",
    "bing": "https://www.bing.com/search?q=example",
    "yahoo": "https://search.yahoo.com/search?p=example",
    "duckduckgo": "https://duckduckgo.com/?q=example",
}

REFERRAL_DOMAINS = [
    "https://someblog.example.com/best-products",
    "https://partnersite.example.net/deals",
    "https://forum.example.org/thread/123",
]

CAMPAIGNS = ["spring_sale", "brand_awareness", "retargeting", "new_arrivals", "clearance"]


def seeded_uuid(rng):
    """
    A UUID4-shaped value drawn from the run's seeded RNG.

    uuid.uuid4() reads from os.urandom and is therefore NOT affected by
    random.seed(), which is what silently made --seed produce a different
    visitor set on every run even though every other decision was seeded.
    """
    return str(uuid.UUID(int=rng.getrandbits(128), version=4))


def build_visitor(traffic_type, rng, platform=None):
    visitor = {
        "visitor_uuid": seeded_uuid(rng),
        "traffic_type": traffic_type,  # kept client-side for stats only; NOT sent in the payload
        "platform_code": None,
        "click_id_param": None,
        "click_id_value": None,
        "utm_source": None,
        "utm_medium": None,
        "utm_campaign": None,
        "referrer": None,
        "landing_page": "/catalog/product/view",
    }

    if traffic_type == "paid":
        visitor["platform_code"] = platform
        visitor["click_id_param"] = CLICK_ID_PARAM[platform]
        visitor["click_id_value"] = seeded_uuid(rng).replace("-", "")[:16]
        visitor["utm_source"] = "instagram" if platform == "meta" and rng.random() < 0.4 else platform
        visitor["utm_medium"] = "cpc"
        visitor["utm_campaign"] = rng.choice(CAMPAIGNS)
    elif traffic_type == "organic":
        engine = rng.choice(sorted(SEARCH_ENGINE_REFERRERS))
        visitor["referrer"] = SEARCH_ENGINE_REFERRERS[engine]
        visitor["_expected_source"] = engine
    elif traffic_type == "referral":
        visitor["referrer"] = rng.choice(REFERRAL_DOMAINS)
    # direct: nothing to set — no referrer, no params

    return visitor


def build_payload(visitor, event_type, entity_id=None):
    is_landing = event_type == "landing"
    return {
        "visitor_uuid": visitor["visitor_uuid"],
        "event_type": event_type,
        "platform_code": visitor["platform_code"] if is_landing else None,
        "click_id_param": visitor["click_id_param"] if is_landing else None,
        "click_id_value": visitor["click_id_value"] if is_landing else None,
        "utm_source": visitor["utm_source"] if is_landing else None,
        "utm_medium": visitor["utm_medium"] if is_landing else None,
        "utm_campaign": visitor["utm_campaign"] if is_landing else None,
        "referrer": visitor["referrer"] if is_landing else None,
        "landing_page": visitor["landing_page"] if is_landing else None,
        "entity_id": entity_id,
        # datetime.utcnow() is deprecated from Python 3.12. Note the explicit
        # strftime: calling .isoformat() on a tz-aware value already appends
        # "+00:00", so the old "+ Z" would have produced "...+00:00Z".
        "timestamp": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


# Event types the public ingest endpoint deliberately REFUSES.
#
# add_to_cart and order_placed are raised server-side by this module's own
# observers (Observer/AddToCartObserver, Observer/OrderPlaceAfterObserver) and
# are dropped if they arrive over the REST endpoint — otherwise anyone could
# POST {"event_type":"order_placed","entity_id":<someone else's order>} and
# claim that order's revenue for their own visit (docs/SECURITY.md §3).
#
# This script therefore cannot simulate them. Sending them anyway would make
# the summary un-reconcilable: they would be counted here and silently
# discarded by the server. Exercise those two paths with a real add-to-cart
# and a real order instead.
SERVER_ONLY_EVENTS = {"add_to_cart", "order_placed"}


def plan_visitor(args, rng, platforms):
    """
    Decides EVERYTHING about one visitor up front, on the main thread.

    Every random draw for the whole run happens here, in a single
    deterministic sequence, before any worker thread starts. That is what
    makes --seed actually reproducible: the previous version drew from the
    global `random` module inside the thread pool, so the order of the draws
    depended on how the OS happened to schedule the workers and two runs with
    the same seed produced different data. Workers now only perform I/O.
    """
    traffic_type = pick_traffic_type(args, rng)
    platform = rng.choice(platforms) if traffic_type == "paid" else None
    visitor = build_visitor(traffic_type, rng, platform)

    events = ["landing", "product_view"]
    add_to_cart = rng.random() < args.add_to_cart_rate
    checkout_start = add_to_cart and rng.random() < args.checkout_start_rate
    order_placed = checkout_start and rng.random() < args.conversion_rate

    if add_to_cart:
        events.append("add_to_cart")
    if checkout_start:
        events += ["checkout_start", "checkout_step_shipping", "checkout_step_payment", "checkout_step_review"]
    if order_placed:
        events.append("order_placed")

    return {
        "visitor": visitor,
        "traffic_type": traffic_type,
        "platform": platform,
        "events": events,
        # Drawn here rather than in the worker so the payloads themselves are
        # reproducible too, not just the counts.
        "entity_ids": [rng.randint(1, 500) for _ in events],
        "add_to_cart": add_to_cart,
        "checkout_start": checkout_start,
        "order_placed": order_placed,
        "revenue": round(rng.uniform(25, 300), 2) if order_placed else 0.0,
    }


def send_visitor(args, session, url, plan, stats_lock, stats, first_error):
    """
    Pure I/O: posts one visitor's already-decided events. Contains no random
    draws, so running this concurrently cannot affect the generated dataset.
    """
    visitor = plan["visitor"]
    sent_ok = 0
    sent_failed = 0
    skipped_server_only = 0

    for event_type, entity_id in zip(plan["events"], plan["entity_ids"]):
        if event_type in SERVER_ONLY_EVENTS:
            skipped_server_only += 1
            continue
        payload = build_payload(visitor, event_type, entity_id=entity_id)
        if args.dry_run:
            print({"event": payload})
        else:
            try:
                # Magento's webapi requires the body wrapped in the service
                # method's parameter name (etc/webapi.xml -> ingest($event)),
                # NOT the bare payload. Posting it flat returns
                # HTTP 400 '"%fieldName" is required' with fieldName=event.
                resp = session.post(url, json={"event": payload}, timeout=5)
                if resp.status_code == 200:
                    sent_ok += 1
                else:
                    sent_failed += 1
                    if first_error[0] is None:
                        first_error[0] = f"HTTP {resp.status_code}: {resp.text[:200]}"
            except requests.RequestException as exc:
                sent_failed += 1
                if first_error[0] is None:
                    first_error[0] = f"{type(exc).__name__}: {exc}"
        if args.delay:
            time.sleep(args.delay)

    with stats_lock:
        stats.setdefault("__transport__", {"ok": 0, "failed": 0, "skipped": 0})
        stats["__transport__"]["ok"] += sent_ok
        stats["__transport__"]["failed"] += sent_failed
        stats["__transport__"]["skipped"] += skipped_server_only


def tally(plans):
    """
    Builds the printed breakdown from the PLANS, not from what the threads
    did, so the summary is a deterministic function of the seed alone.
    """
    stats = {}
    for plan in plans:
        key = f"paid:{plan['platform']}" if plan["traffic_type"] == "paid" else plan["traffic_type"]
        s = stats.setdefault(key, {"visitors": 0, "add_to_cart": 0, "checkout_start": 0, "orders": 0, "revenue": 0.0})
        s["visitors"] += 1
        s["add_to_cart"] += int(plan["add_to_cart"])
        s["checkout_start"] += int(plan["checkout_start"])
        s["orders"] += int(plan["order_placed"])
        s["revenue"] += plan["revenue"]
    return stats


def pick_traffic_type(args, rng):
    r = rng.random()
    if r < args.paid_share:
        return "paid"
    r -= args.paid_share
    if r < args.organic_share:
        return "organic"
    r -= args.organic_share
    if r < args.referral_share:
        return "referral"
    return "direct"


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--endpoint", default="/rest/V1/adsanalytics/event")
    parser.add_argument("--visitors", type=int, default=100)
    parser.add_argument("--platforms", default="google,meta,bing,tiktok,reddit")
    parser.add_argument("--paid-share", type=float, default=0.4)
    parser.add_argument("--organic-share", type=float, default=0.3)
    parser.add_argument("--referral-share", type=float, default=0.15)
    parser.add_argument("--conversion-rate", type=float, default=0.03)
    parser.add_argument("--add-to-cart-rate", type=float, default=0.25)
    parser.add_argument("--checkout-start-rate", type=float, default=0.5)
    parser.add_argument("--concurrency", type=int, default=10)
    parser.add_argument("--delay", type=float, default=0.05)
    parser.add_argument("--seed", type=int, default=None)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--insecure", action="store_true",
                        help="Skip TLS verification. Needed for a local docker-magento store, whose "
                             "cert is self-signed and does not chain to the project rootCA.pem unless "
                             "bin/setup-domain has been run. Local dev only — never point this at a "
                             "real store with --insecure.")
    args = parser.parse_args()

    if args.paid_share + args.organic_share + args.referral_share > 1.0:
        print("paid-share + organic-share + referral-share must be <= 1.0 (remainder is direct)", file=sys.stderr)
        sys.exit(1)

    # One RNG instance for the whole run, rather than seeding the global
    # `random` module: nothing else can perturb its sequence, and every draw
    # is made on the main thread in plan_visitor() below.
    rng = random.Random(args.seed)

    platforms = [p.strip() for p in args.platforms.split(",") if p.strip()]
    for p in platforms:
        if p not in CLICK_ID_PARAM:
            print(f"Unknown platform '{p}'. Valid: {list(CLICK_ID_PARAM)}", file=sys.stderr)
            sys.exit(1)

    url = args.base_url.rstrip("/") + args.endpoint
    session = requests.Session()
    if args.insecure:
        session.verify = False
        import urllib3
        urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

    stats_lock = threading.Lock()
    stats = {}
    first_error = [None]

    # Generate the entire dataset first, deterministically, then send it.
    plans = [plan_visitor(args, rng, platforms) for _ in range(args.visitors)]

    if args.dry_run:
        # Serial on purpose. There is no network I/O to overlap, and print()
        # from several threads interleaves: two payload lines collide and the
        # dump comes out with one line merged, which looks like the generator
        # being non-deterministic when it is only the printing that is.
        for plan in plans:
            send_visitor(args, session, url, plan, stats_lock, stats, first_error)
    else:
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
            futures = [
                pool.submit(send_visitor, args, session, url, plan, stats_lock, stats, first_error)
                for plan in plans
            ]
            for f in concurrent.futures.as_completed(futures):
                f.result()

    transport = stats.pop("__transport__", {"ok": 0, "failed": 0, "skipped": 0})

    # Report what actually landed BEFORE the intent summary. Previously this
    # script printed a full breakdown even when every single request had
    # failed, which is worse than useless: you would reconcile it against an
    # empty dashboard and blame the module.
    print("\n=== Transport ===")
    print(f"  events accepted (HTTP 200): {transport['ok']}")
    print(f"  events failed:              {transport['failed']}")
    if transport.get("skipped"):
        print(f"  server-only, NOT sent:      {transport['skipped']}"
              f"  (add_to_cart / order_placed — raised by observers, not the endpoint)")
        print("     -> the add_to_cart and orders columns below are funnel INTENT;")
        print("        they will NOT appear in the database from this run.")
    if transport["failed"]:
        print(f"  first error: {first_error[0]}")
        if transport["ok"] == 0:
            print("\n  *** EVERY REQUEST FAILED — the breakdown below is what this script")
            print("  *** INTENDED to send, not what the store received. Do not reconcile")
            print("  *** it against the dashboard until transport succeeds.")
            print("  *** For a local docker-magento store, try --insecure.")

    stats = tally(plans)

    # The breakdown is split in two on purpose. Only the first block can be
    # reconciled against the admin reports; the second describes events this
    # script is structurally incapable of producing, and printing them in one
    # undifferentiated table (as this script used to) invites exactly the
    # wrong conclusion — that the module dropped them.
    print("\n=== SENT — reconcile these against the admin dashboard/grid ===")
    print(f"{'slice':16s}  {'visits':>7s}  {'checkout_starts':>16s}")
    total_visits = total_cs = 0
    for key, s_ in sorted(stats.items()):
        print(f"{key:16s}  {s_['visitors']:7d}  {s_['checkout_start']:16d}")
        total_visits += s_["visitors"]
        total_cs += s_["checkout_start"]
    print(f"{'TOTAL':16s}  {total_visits:7d}  {total_cs:16d}")
    print("  visits          -> ads_analytics_daily_summary.visits (one visit row per visitor_uuid)")
    print("  checkout_starts -> ads_analytics_daily_summary.checkout_starts")
    print("  'paid:<platform>' rows reconcile against traffic_type=paid grouped by platform_code;")
    print("  organic/referral/direct reconcile against their own traffic_type.")
    print("  product_view events are sent and stored in ads_analytics_funnel_event, but the")
    print("  summary table has no product_views column, so they do not appear in reports.")

    print("\n=== NOT SENT — simulated intent only, will NOT appear anywhere ===")
    total_atc = sum(s_["add_to_cart"] for s_ in stats.values())
    total_orders = sum(s_["orders"] for s_ in stats.values())
    total_revenue = sum(s_["revenue"] for s_ in stats.values())
    print(f"  add_to_cart intents: {total_atc}")
    print(f"  order intents:       {total_orders}")
    print(f"  notional revenue:    ${total_revenue:.2f}")
    print("  add_to_cart and order_placed are server-only (docs/SECURITY.md section 3): the public")
    print("  endpoint drops them, so this script cannot create them and the revenue figure above")
    print("  corresponds to no order in the database. The daily summary's add_to_carts, orders and")
    print("  revenue columns must be verified with a real add-to-cart and a real checkout instead.")
    print("  Expect those three columns to read 0 for the traffic this run generated.")


if __name__ == "__main__":
    main()
