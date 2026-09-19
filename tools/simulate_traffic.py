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
    --seed                 Random seed for reproducible runs (default: none)
    --dry-run              Print payloads instead of sending them

At the end, prints a summary of exactly what was generated, broken out by
traffic_type (and by platform within paid) — visitor counts, add-to-carts,
checkout-starts, orders, "revenue" — so you can reconcile it against the
admin dashboard/grid for Phase 3 verification.
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


def build_visitor(traffic_type, platform=None):
    visitor = {
        "visitor_uuid": str(uuid.uuid4()),
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
        visitor["click_id_value"] = uuid.uuid4().hex[:16]
        visitor["utm_source"] = "instagram" if platform == "meta" and random.random() < 0.4 else platform
        visitor["utm_medium"] = "cpc"
        visitor["utm_campaign"] = random.choice(CAMPAIGNS)
    elif traffic_type == "organic":
        engine = random.choice(list(SEARCH_ENGINE_REFERRERS))
        visitor["referrer"] = SEARCH_ENGINE_REFERRERS[engine]
        visitor["_expected_source"] = engine
    elif traffic_type == "referral":
        visitor["referrer"] = random.choice(REFERRAL_DOMAINS)
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
        "timestamp": datetime.datetime.utcnow().isoformat() + "Z",
    }


def simulate_one_visitor(args, session, url, traffic_type, platform, stats_lock, stats, first_error):
    visitor = build_visitor(traffic_type, platform)
    events = ["landing", "product_view"]

    add_to_cart = random.random() < args.add_to_cart_rate
    checkout_start = add_to_cart and random.random() < args.checkout_start_rate
    order_placed = checkout_start and random.random() < args.conversion_rate

    if add_to_cart:
        events.append("add_to_cart")
    if checkout_start:
        events += ["checkout_start", "checkout_step_shipping", "checkout_step_payment", "checkout_step_review"]
    if order_placed:
        events.append("order_placed")

    revenue = round(random.uniform(25, 300), 2) if order_placed else 0.0
    sent_ok = 0
    sent_failed = 0

    for event_type in events:
        payload = build_payload(visitor, event_type, entity_id=random.randint(1, 500))
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

    key = f"paid:{platform}" if traffic_type == "paid" else traffic_type
    with stats_lock:
        stats.setdefault(key, {"visitors": 0, "add_to_cart": 0, "checkout_start": 0, "orders": 0, "revenue": 0.0})
        s = stats[key]
        s["visitors"] += 1
        s["add_to_cart"] += int(add_to_cart)
        s["checkout_start"] += int(checkout_start)
        s["orders"] += int(order_placed)
        s["revenue"] += revenue
        stats.setdefault("__transport__", {"ok": 0, "failed": 0})
        stats["__transport__"]["ok"] += sent_ok
        stats["__transport__"]["failed"] += sent_failed


def pick_traffic_type(args):
    r = random.random()
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

    if args.seed is not None:
        random.seed(args.seed)

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

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futures = []
        for _ in range(args.visitors):
            traffic_type = pick_traffic_type(args)
            platform = random.choice(platforms) if traffic_type == "paid" else None
            futures.append(pool.submit(simulate_one_visitor, args, session, url, traffic_type, platform, stats_lock, stats, first_error))
        for f in concurrent.futures.as_completed(futures):
            f.result()

    transport = stats.pop("__transport__", {"ok": 0, "failed": 0})

    # Report what actually landed BEFORE the intent summary. Previously this
    # script printed a full breakdown even when every single request had
    # failed, which is worse than useless: you would reconcile it against an
    # empty dashboard and blame the module.
    print("\n=== Transport ===")
    print(f"  events accepted (HTTP 200): {transport['ok']}")
    print(f"  events failed:              {transport['failed']}")
    if transport["failed"]:
        print(f"  first error: {first_error[0]}")
        if transport["ok"] == 0:
            print("\n  *** EVERY REQUEST FAILED — the breakdown below is what this script")
            print("  *** INTENDED to send, not what the store received. Do not reconcile")
            print("  *** it against the dashboard until transport succeeds.")
            print("  *** For a local docker-magento store, try --insecure.")

    print("\n=== Simulation summary (reconcile against admin dashboard/grid) ===")
    total = {"visitors": 0, "add_to_cart": 0, "checkout_start": 0, "orders": 0, "revenue": 0.0}
    for key, s in sorted(stats.items()):
        print(f"{key:16s}  visitors={s['visitors']:4d}  add_to_cart={s['add_to_cart']:4d}  "
              f"checkout_start={s['checkout_start']:4d}  orders={s['orders']:3d}  revenue=${s['revenue']:.2f}")
        for k in total:
            total[k] += s[k]
    print(f"{'TOTAL':16s}  visitors={total['visitors']:4d}  add_to_cart={total['add_to_cart']:4d}  "
          f"checkout_start={total['checkout_start']:4d}  orders={total['orders']:3d}  revenue=${total['revenue']:.2f}")
    print("\nNote: 'paid:<platform>' rows should reconcile against traffic_type=paid rows in the")
    print("admin dashboard/grid, grouped further by platform_code; 'organic'/'referral'/'direct'")
    print("rows should reconcile against their respective traffic_type in the same reports.")


if __name__ == "__main__":
    main()
