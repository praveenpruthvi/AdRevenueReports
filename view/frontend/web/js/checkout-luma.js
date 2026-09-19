/**
 * LUMA checkout-step adapter (P2-T3).
 *
 * This is the ONLY file in the module that uses RequireJS/Knockout, and it is
 * loaded ONLY on the default LUMA checkout page (see
 * view/frontend/layout/checkout_index_index.xml). CLAUDE.md #4 keeps the
 * storefront beacon free of those dependencies so the module stays
 * Hyvä-compatible; a checkout is unavoidably theme-specific, so the rule is
 * that the SHARED beacon stays vanilla and each theme gets a thin adapter
 * built on that theme's own stack. A Hyvä store simply never loads this file.
 *
 * The adapter contains no tracking logic of its own. It observes LUMA's step
 * navigator and calls the beacon's public API, which owns the cookie, the
 * consent gate, the payload envelope and per-page deduplication:
 *
 *     window.aavirbhavaAdsAnalytics.track('checkout_step_shipping');
 *
 * See README.md "Adding a Hyvä Checkout adapter" to do the same for Hyvä —
 * it requires no changes to this file or to any PHP class.
 *
 * A NOTE ON LUMA'S STEPS: default LUMA registers exactly two —
 * 'shipping' and 'payment' (titled "Review & Payments"). There is no separate
 * review step, so `checkout_step_review` from docs/SPECS.md §6 is never
 * emitted here. Hyvä Checkout does split review out, which is precisely why
 * the step-code mapping below belongs in a per-theme adapter rather than in
 * shared code.
 */
define([
    'ko',
    'Magento_Checkout/js/model/step-navigator'
], function (ko, stepNavigator) {
    'use strict';

    /** LUMA step code -> docs/SPECS.md §6 event type. */
    var STEP_EVENT_MAP = {
        shipping: 'checkout_step_shipping',
        payment: 'checkout_step_payment'
    };

    function beacon() {
        return window.aavirbhavaAdsAnalytics;
    }

    /**
     * Tracking must never break checkout. Any failure here — a missing
     * beacon because tracking is disabled, a consent gate, an unexpected
     * step shape — is swallowed: a lost analytics event is acceptable, a
     * broken checkout is not (CLAUDE.md #3).
     */
    function safeTrack(eventType) {
        try {
            var api = beacon();
            if (api && typeof api.track === 'function') {
                api.track(eventType);
            }
        } catch (e) {
            // Intentionally silent.
        }
    }

    return function () {
        // Reaching the checkout page at all is the start of checkout. The
        // beacon dedupes, so this is safe even if the page re-initialises.
        safeTrack('checkout_start');

        /**
         * step-navigator exposes `steps` as a Knockout observableArray, and
         * each step carries its own `isVisible` observable. Subscribing to
         * the array alone is not enough: the steps register once, then
         * toggle visibility as the customer moves. So subscribe to each
         * step's visibility, and re-subscribe when the array changes because
         * a payment method or third-party module can register a step late.
         */
        var subscribed = {};

        function watchSteps() {
            stepNavigator.steps().forEach(function (step) {
                var code = step.code;
                if (!STEP_EVENT_MAP[code] || subscribed[code]) {
                    return;
                }
                subscribed[code] = true;

                if (typeof step.isVisible !== 'function') {
                    return;
                }
                // Fire for a step that is already active when we attach.
                if (step.isVisible()) {
                    safeTrack(STEP_EVENT_MAP[code]);
                }
                step.isVisible.subscribe(function (visible) {
                    if (visible) {
                        safeTrack(STEP_EVENT_MAP[code]);
                    }
                });
            });
        }

        watchSteps();
        stepNavigator.steps.subscribe(watchSteps);
    };
});
