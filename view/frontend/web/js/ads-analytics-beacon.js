/**
 * Aavirbhava_AdsAnalytics storefront beacon (P1-T7).
 *
 * Plain vanilla JS — NO RequireJS/Knockout/jQuery dependency, so this loads
 * identically on Hyvä and LUMA themes (CLAUDE.md constraint #4).
 *
 * This file forwards RAW SIGNALS and classifies nothing. It reads the query
 * string and document.referrer and sends them as-is; the server
 * (Model\Service\TrafficResolver, via the queue consumer) derives
 * traffic_type, platform_code, source and medium. Classification logic lives
 * in exactly one place, and it is not here — see docs/SPECS.md §2/§6.
 *
 * It also knows nothing about any ad platform. The click-id parameter names
 * it looks for arrive in window.aavirbhavaAdsAnalytics from the config
 * (Block\Frontend\BeaconConfig); adding Pinterest never means editing this
 * file (CLAUDE.md #2).
 *
 * TODO(P1-T8): cookie-consent integration beyond Magento's own
 * cookie-restriction mode, which is already honoured below. A third-party
 * consent module needs its own hook — confirm which one the store runs.
 *
 * CHECKOUT STEPS are detected here, still without RequireJS: Magento's
 * step-navigator drives the LUMA checkout by setting window.location.hash to
 * the active step code, so a plain `hashchange` listener sees every
 * transition. The hash -> event map arrives in the config from
 * Block\Frontend\BeaconConfig, so no step name is hardcoded in this file.
 *
 * An earlier attempt hooked LUMA's Knockout step-navigator instead — first as
 * a jsLayout component, then as a RequireJS mixin. The config was correct and
 * the files served, but no checkout event ever fired in a real browser. The
 * hash is simpler, observable from vanilla JS, and means CLAUDE.md #4 needs
 * no checkout exception at all.
 *
 * A checkout that does NOT use hash routing (Hyvä Checkout, for one) should
 * call the public API directly instead:
 *
 *     window.aavirbhavaAdsAnalytics.track('checkout_step_shipping');
 *
 * `track()` reuses this file's cookie, consent gating and payload envelope, so
 * an adapter never re-implements any of that and cannot drift from it. See
 * README.md for how to add a Hyvä Checkout adapter.
 */
(function () {
    'use strict';

    /**
     * Configuration arrives as a data attribute, NOT as an inline
     * <script> assigning a global. Magento's CSP blocks inline scripts on the
     * checkout page, which silently left window.aavirbhavaAdsAnalytics
     * undefined there and killed every checkout event. This file is an
     * external script, which CSP permits, so reading the attribute here works
     * under both report-only and restrict mode.
     */
    function readConfig() {
        var el = document.getElementById('aavirbhava-adsanalytics-config'),
            raw = el && el.getAttribute('data-config');

        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    var config = readConfig();
    if (!config || !config.endpoint) {
        return;
    }

    // Published from here — an external script CAN set a global; only inline
    // code is blocked. This keeps the documented public API intact, so a Hyvä
    // adapter still calls window.aavirbhavaAdsAnalytics.track(...).
    window.aavirbhavaAdsAnalytics = config;

    var DEBUG_PARAM = 'adsanalytics_debug',
        DEBUG_STORAGE_KEY = 'aavirbhava_adsanalytics_debug';

    /**
     * Debug logging is off unless the store config enables it OR the visitor
     * opts in with ?adsanalytics_debug=1.
     *
     * The URL opt-in is remembered in sessionStorage so it survives
     * navigation — a funnel walkthrough spans landing, cart and several
     * checkout steps, and having to re-add the parameter on every hop would
     * make it useless for exactly the case it exists for. sessionStorage is
     * wrapped because it throws in some privacy modes.
     */
    function debugEnabled() {
        var fromUrl = new URLSearchParams(location.search).get(DEBUG_PARAM);

        try {
            if (fromUrl !== null) {
                if (fromUrl === '0') {
                    sessionStorage.removeItem(DEBUG_STORAGE_KEY);
                    return false;
                }
                sessionStorage.setItem(DEBUG_STORAGE_KEY, '1');
                return true;
            }
            if (sessionStorage.getItem(DEBUG_STORAGE_KEY) === '1') {
                return true;
            }
        } catch (e) {
            // Storage unavailable — fall through to the config flag.
            if (fromUrl !== null) {
                return fromUrl !== '0';
            }
        }

        return !!config.debug;
    }

    var debug = debugEnabled();

    function log(message, detail) {
        if (!debug || !window.console) {
            return;
        }
        if (detail !== undefined) {
            console.log('%c[AdsAnalytics]%c ' + message,
                'color:#e85d24;font-weight:bold', 'color:inherit', detail);
        } else {
            console.log('%c[AdsAnalytics]%c ' + message,
                'color:#e85d24;font-weight:bold', 'color:inherit');
        }
    }

    log('beacon loaded. config:', config);

    var UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign'];

    function getCookie(name) {
        var match = document.cookie.match(
            new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 864e5).toUTCString();
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
    }

    /**
     * Correlation id only — never used for anything security-sensitive, so
     * Math.random is adequate where crypto is unavailable. crypto.randomUUID
     * is preferred when present simply because it is collision-safer.
     */
    function uuidv4() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    /**
     * Magento sets user_allowed_save_cookie when the visitor accepts, under
     * cookie-restriction mode. If that mode is off the store has decided
     * consent is not required here, and we proceed.
     */
    function consentGiven() {
        if (!config.requireCookieConsent) {
            return true;
        }
        var flag = getCookie('user_allowed_save_cookie'),
            given = flag !== null && flag !== '0';

        log('cookie-restriction mode is ON; consent ' + (given ? 'GIVEN' : 'NOT given')
            + ' (user_allowed_save_cookie=' + flag + ')');

        return given;
    }

    /** @return {Object} raw utm_* values present in the URL, untouched */
    function readUtmParams(params) {
        var out = {};
        UTM_PARAMS.forEach(function (key) {
            var value = params.get(key);
            if (value) {
                out[key] = value;
            }
        });
        return out;
    }

    /**
     * Finds whichever configured click-id parameter is present. Returns the
     * NAME and VALUE only — which platform it belongs to is the server's
     * business, and the server validates the name against its own map before
     * trusting it (docs/SECURITY.md §2).
     */
    function readClickId(params) {
        var names = config.clickIdParams || [];
        for (var i = 0; i < names.length; i++) {
            var value = params.get(names[i]);
            if (value) {
                return { click_id_param: names[i], click_id_value: value };
            }
        }
        return null;
    }

    function sendEvent(payload) {
        log('POST ' + payload.event_type + ' ->', payload);
        try {
            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // The webapi expects the body wrapped in the service method's
                // parameter name (etc/webapi.xml -> ingest($event)); posting
                // it flat returns HTTP 400 '"%fieldName" is required'.
                body: JSON.stringify({ event: payload }),
                // Survives the page being unloaded by a click-through, which
                // is exactly when a landing event would otherwise be lost.
                keepalive: true,
                credentials: 'same-origin'
            }).then(function (response) {
                log(payload.event_type + ' -> HTTP ' + response.status
                    + (response.status === 200 ? ' (accepted)' : ' (NOT accepted)'));
            }).catch(function (error) {
                // Swallowed on purpose — a tracking failure must never surface
                // on the storefront. Logged so a walkthrough can still see it.
                log(payload.event_type + ' -> request FAILED', error && error.message);
            });
        } catch (e) {
            log(payload.event_type + ' -> threw before sending', e && e.message);
        }
    }

    /**
     * Resolves the visitor id, creating and persisting one if this is a first
     * visit. Shared by init() and track() so a checkout-step event and a
     * landing always agree on who the visitor is.
     */
    function resolveVisitorUuid() {
        var visitorUuid = getCookie(config.cookieName);
        var isNew = !visitorUuid;
        if (isNew) {
            visitorUuid = uuidv4();
        }
        // Re-set on every call so the lifetime slides forward for an active
        // visitor rather than expiring mid-journey.
        setCookie(config.cookieName, visitorUuid, config.cookieLifetimeDays);

        return { uuid: visitorUuid, isNew: isNew };
    }

    /**
     * Fired event types, so a repeated call is a no-op. Checkout frameworks
     * re-evaluate their view models constantly — LUMA's step navigator alone
     * would otherwise emit the same step event dozens of times per page.
     */
    var fired = {};

    /**
     * PUBLIC API for theme-specific checkout adapters.
     *
     * @param {String} eventType one of docs/SPECS.md §6's event types
     * @param {Object} [extra]   optional extra payload fields (e.g. entity_id)
     * @returns {Boolean} whether the event was sent
     */
    function track(eventType, extra) {
        if (!eventType) {
            log('track() called with no event type — ignored');
            return false;
        }
        if (!consentGiven()) {
            log('track("' + eventType + '") SUPPRESSED — consent not given');
            return false;
        }
        if (fired[eventType]) {
            log('track("' + eventType + '") DEDUPED — already sent on this page');
            return false;
        }
        fired[eventType] = true;

        var payload = {
            visitor_uuid: resolveVisitorUuid().uuid,
            event_type: eventType,
            timestamp: new Date().toISOString()
        };
        if (extra) {
            Object.keys(extra).forEach(function (key) {
                if (extra[key] !== null && extra[key] !== undefined) {
                    payload[key] = extra[key];
                }
            });
        }

        sendEvent(payload);

        return true;
    }

    // Exposed before init() runs so an adapter loading on the same page can
    // call it regardless of ordering.
    config.track = track;

    function init() {
        if (!consentGiven()) {
            log('init() aborted — consent not given');
            return;
        }

        var params = new URLSearchParams(location.search);
        var clickId = readClickId(params);
        var utm = readUtmParams(params);
        var referrer = document.referrer || null;

        var visitor = resolveVisitorUuid();
        var visitorUuid = visitor.uuid;
        var isNewVisitor = visitor.isNew;

        log('visitor ' + (isNewVisitor ? 'NEW (cookie just created)' : 'returning (cookie found)')
            + ': ' + visitorUuid);
        log('url signals', {
            path: location.pathname,
            clickId: clickId,
            utm: utm,
            referrer: referrer
        });

        // 'landing' whenever there is fresh attribution to record, or the
        // visitor is new. A returning visitor arriving on a NEW ad click must
        // also produce a landing, otherwise last-touch attribution would keep
        // crediting the campaign that first acquired them.
        var hasAttribution = clickId !== null || Object.keys(utm).length > 0;
        var eventType = (isNewVisitor || hasAttribution) ? 'landing' : 'product_view';

        // Spelled out because this trips people up: a LANDING does not create
        // a funnel-event row. It creates or refreshes the visit itself, which
        // is why a first page view shows up in ads_analytics_visit and not in
        // ads_analytics_funnel_event.
        log('event type = ' + eventType + ' because '
            + (isNewVisitor ? 'this is a new visitor' : 'visitor is known')
            + ' and attribution params are ' + (hasAttribution ? 'present' : 'absent')
            + (eventType === 'landing'
                ? '  -> writes/updates ads_analytics_visit, NOT a funnel row'
                : '  -> writes an ads_analytics_funnel_event row'));

        var payload = {
            visitor_uuid: visitorUuid,
            event_type: eventType,
            landing_page: location.pathname,
            timestamp: new Date().toISOString()
        };

        if (clickId) {
            payload.click_id_param = clickId.click_id_param;
            payload.click_id_value = clickId.click_id_value;
        }
        Object.keys(utm).forEach(function (key) {
            payload[key] = utm[key];
        });

        // Only sent on a landing: the server truncates it to a hostname
        // before storage (docs/SECURITY.md §6), and it is only meaningful for
        // classifying how the visit started. Sending it on every internal
        // page view would just be the store's own URL.
        if (referrer && eventType === 'landing') {
            payload.referrer = referrer;
        }

        fired[eventType] = true;
        sendEvent(payload);
    }

    /**
     * True when this page is the checkout. Compared against the path the
     * server resolved, so a store that has moved or renamed its checkout URL
     * still matches.
     */
    /**
     * EXACT path match, not a prefix.
     *
     * A prefix match on "/checkout/" also matches "/checkout/cart/" and
     * "/checkout/onepage/success/", so viewing the cart and landing on the
     * order-confirmation page each fired a second checkout_start. That
     * inflates the top of the funnel and makes the drop-off rate between
     * checkout_start and order_placed meaningless — the one number this
     * report exists to show.
     *
     * The LUMA checkout keeps pathname at "/checkout/" throughout, because
     * its steps are hash routes, so an exact match still catches every step.
     */
    function normalisePath(path) {
        return ('/' + String(path || '').replace(/^\/+|\/+$/g, '') + '/').toLowerCase();
    }

    function onCheckoutPage() {
        var match = !!config.checkoutPath
            && normalisePath(location.pathname) === normalisePath(config.checkoutPath);

        log('checkout page? ' + (match ? 'YES' : 'no')
            + ' (path "' + location.pathname + '" vs configured "' + config.checkoutPath + '")'
            + (!match && config.checkoutPath
                && normalisePath(location.pathname).indexOf(normalisePath(config.checkoutPath)) === 0
                ? ' — under /checkout/ but NOT the checkout itself (cart or success page), so no checkout_start'
                : ''));

        return match;
    }

    /** Maps the current URL hash to a step event, if it is one we know. */
    function trackStepFromHash() {
        var map = config.checkoutStepEvents || {},
            code = (location.hash || '').replace(/^#/, '').split('?')[0];

        if (!code) {
            log('hash is empty — no checkout step to report yet');
            return;
        }
        if (!map[code]) {
            log('hash "#' + code + '" is not a tracked step', { knownSteps: Object.keys(map) });
            return;
        }

        log('checkout step detected from hash "#' + code + '" -> ' + map[code]);
        track(map[code]);
    }

    function initCheckoutTracking() {
        if (!onCheckoutPage()) {
            return;
        }

        log('checkout tracking active; watching hashchange', {
            steps: config.checkoutStepEvents
        });

        // Reaching the checkout page at all starts the checkout. track()
        // dedupes, so a re-entry or a hash bounce cannot double-count it.
        track('checkout_start');

        // The step may already be in the URL on load (a refresh, or a direct
        // link to #payment), so check once before listening.
        trackStepFromHash();
        window.addEventListener('hashchange', trackStepFromHash);
    }

    function start() {
        log('start() — readyState=' + document.readyState);
        init();
        initCheckoutTracking();
        log('start() complete. events sent on this page:', Object.keys(fired));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
