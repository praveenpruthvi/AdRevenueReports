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
 * CHECKOUT STEPS are not detected here — a checkout is theme-specific. This
 * file instead exposes a public API that any theme's adapter calls:
 *
 *     window.aavirbhavaAdsAnalytics.track('checkout_step_shipping');
 *
 * `track()` reuses this file's cookie, consent gating and payload envelope, so
 * an adapter never re-implements any of that and cannot drift from it. The
 * LUMA adapter ships in checkout-luma.js; see README.md for how to add a Hyvä
 * Checkout adapter without touching this file.
 */
(function () {
    'use strict';

    var config = window.aavirbhavaAdsAnalytics;
    if (!config || !config.endpoint) {
        return;
    }

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
        var flag = getCookie('user_allowed_save_cookie');
        return flag !== null && flag !== '0';
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
            }).catch(function () {
                // Swallowed on purpose — see below.
            });
        } catch (e) {
            // A tracking failure must never surface on the storefront.
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
        if (!eventType || !consentGiven()) {
            return false;
        }
        if (fired[eventType]) {
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
            return;
        }

        var params = new URLSearchParams(location.search);
        var clickId = readClickId(params);
        var utm = readUtmParams(params);
        var referrer = document.referrer || null;

        var visitor = resolveVisitorUuid();
        var visitorUuid = visitor.uuid;
        var isNewVisitor = visitor.isNew;

        // 'landing' whenever there is fresh attribution to record, or the
        // visitor is new. A returning visitor arriving on a NEW ad click must
        // also produce a landing, otherwise last-touch attribution would keep
        // crediting the campaign that first acquired them.
        var hasAttribution = clickId !== null || Object.keys(utm).length > 0;
        var eventType = (isNewVisitor || hasAttribution) ? 'landing' : 'product_view';

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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
