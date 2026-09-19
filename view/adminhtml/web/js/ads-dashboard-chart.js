/**
 * Draws the funnel drop-off and traffic-type charts above the Ads Analytics
 * grid (P3-T4).
 *
 * Both are driven by the SAME Ui Component data provider the grid reads
 * (config.listingProvider), not by a separate AJAX call. Whatever the admin
 * has the grid filtered or paged to is exactly what these charts show,
 * because both read the identical `data` observable on the identical
 * provider instance. A second fetch could disagree with the table on screen,
 * which would be worse than no chart at all.
 *
 * Chart.js comes from Magento core's own bundled copy via the 'chartJs'
 * RequireJS alias that Magento_Ui's base requirejs-config.js already defines
 * globally — the same alias Magento_Backend's admin dashboard uses. Do NOT
 * add a module-local `paths: {chartjs: ...}` entry to vendor a second copy:
 * 'chartjs' is a directory-prefix namespace that core resolves
 * 'chartjs/Chart.min' and friends against, and overriding it with a
 * single-file alias silently breaks other admin pages. (This is recorded in
 * Aavirbhava_SalesAnalytics, which hit exactly that during development.)
 *
 * KNOWN LIMITATION, stated on the page itself: like the grid, these see only
 * the CURRENT PAGE of results, not every row matching the active filters. A
 * deliberate v1 trade-off — the alternative is a second aggregate query that
 * can contradict the visible table.
 */
define([
    'jquery',
    'uiRegistry',
    'chartJs',
    'mage/translate'
], function ($, registry, Chart, $t) {
    'use strict';

    /**
     * Palette chosen so the funnel reads as one descending series and the
     * traffic-type doughnut stays distinguishable in greyscale print.
     */
    var FUNNEL_COLOUR = '#e85d24',
        TRAFFIC_COLOURS = ['#e85d24', '#2f7ed8', '#8bbc21', '#910000', '#9a9a9a'];

    return function (config) {
        var funnelChart = null,
            trafficChart = null;

        function el(id) {
            return document.getElementById(id);
        }

        function toggleEmpty(isEmpty) {
            $('.aavirbhava-ads-charts__empty').toggle(isEmpty);
            $('.aavirbhava-ads-charts__panel').toggle(!isEmpty);
        }

        /** Sums a numeric column across the rows currently in the grid. */
        function sum(rows, key) {
            return rows.reduce(function (total, row) {
                return total + (parseFloat(row[key]) || 0);
            }, 0);
        }

        /**
         * Plots the four stages as ABSOLUTE COUNTS, deliberately — not as
         * drop-off percentages of the previous stage.
         *
         * The funnel is not guaranteed to decline monotonically. `visits`,
         * `add_to_carts` and `orders` are recorded server-side, while
         * `checkout_starts` comes from the storefront beacon, so an ad
         * blocker, a consent gate or a bounced request can lose a
         * checkout_start while its order still lands. Verified at P3-T6:
         * real slices exist with orders > checkout_starts. Percentages of
         * the previous stage would render as >100% or negative drop-off on
         * exactly those slices.
         */
        function buildFunnel(rows) {
            return {
                labels: [
                    $t('Visits'),
                    $t('Product View'),
                    $t('Add to Cart'),
                    $t('Checkout'),
                    $t('Orders')
                ],
                values: [
                    sum(rows, 'visits'),
                    sum(rows, 'product_views'),
                    sum(rows, 'add_to_carts'),
                    sum(rows, 'checkout_starts'),
                    sum(rows, 'orders')
                ]
            };
        }

        function buildTrafficSplit(rows) {
            var byType = {};

            rows.forEach(function (row) {
                var type = row.traffic_type || 'unknown';

                byType[type] = (byType[type] || 0) + (parseFloat(row.visits) || 0);
            });

            return {
                labels: Object.keys(byType),
                values: Object.keys(byType).map(function (k) {
                    return byType[k];
                })
            };
        }

        function render(rows) {
            var funnel, traffic;

            if (!rows || !rows.length) {
                toggleEmpty(true);
                return;
            }
            toggleEmpty(false);

            funnel = buildFunnel(rows);
            traffic = buildTrafficSplit(rows);

            if (funnelChart) {
                funnelChart.destroy();
            }
            if (trafficChart) {
                trafficChart.destroy();
            }

            funnelChart = new Chart(el(config.funnelCanvas), {
                type: 'bar',
                data: {
                    labels: funnel.labels,
                    datasets: [{
                        label: $t('Count'),
                        data: funnel.values,
                        backgroundColor: FUNNEL_COLOUR
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });

            trafficChart = new Chart(el(config.trafficCanvas), {
                type: 'doughnut',
                data: {
                    labels: traffic.labels,
                    datasets: [{
                        data: traffic.values,
                        backgroundColor: TRAFFIC_COLOURS
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }

        /**
         * The provider is created asynchronously by the Ui Component layout,
         * so registry.get's callback form is used rather than a direct
         * lookup — a direct get() here races the grid's own bootstrap and
         * usually returns undefined.
         */
        registry.get(config.listingProvider, function (provider) {
            render(provider.data && provider.data.items ? provider.data.items : []);

            // Re-render whenever the grid reloads: filters, sorting, paging
            // and bookmarks all flow through this same observable.
            provider.data.subscribe
                ? provider.data.subscribe(function (data) {
                    render(data && data.items ? data.items : []);
                })
                : provider.on('reload', function () {
                    render(provider.data.items || []);
                });
        });
    };
});
