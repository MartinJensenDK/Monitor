/**
 * Response-time charts, drawn with uPlot.
 *
 * Colours come from the CSS custom properties, so the chart follows the theme
 * without a second palette to keep in sync. Outages are painted as vertical
 * bands behind the line — the shape of the downtime is the point of the chart.
 */
(function () {
    'use strict';

    if (typeof uPlot === 'undefined') return;

    var instances = [];

    function token(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return value || fallback;
    }

    function palette() {
        return {
            accent: token('--accent', '#0e7c8c'),
            ink: token('--ink', '#12181d'),
            muted: token('--ink-muted', '#616d78'),
            line: token('--line', '#d3d8dd'),
            down: token('--down', '#be3a2b'),
            warn: token('--warn', '#b5730a')
        };
    }

    function rgba(hex, alpha) {
        var value = hex.replace('#', '');
        if (value.length === 3) {
            value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
        }
        var int = parseInt(value, 16);
        return 'rgba(' + ((int >> 16) & 255) + ',' + ((int >> 8) & 255) + ',' + (int & 255) + ',' + alpha + ')';
    }

    /** Paints incident windows and the failure ratio behind the series. */
    function bandsPlugin(getIncidents) {
        return {
            hooks: {
                drawClear: function (u) {
                    var colors = palette();
                    var ctx = u.ctx;
                    var incidents = getIncidents() || [];

                    ctx.save();
                    incidents.forEach(function (incident) {
                        var from = incident.started_at;
                        var to = incident.resolved_at || (Date.now() / 1000);
                        if (to < u.scales.x.min || from > u.scales.x.max) return;

                        var x0 = u.valToPos(Math.max(from, u.scales.x.min), 'x', true);
                        var x1 = u.valToPos(Math.min(to, u.scales.x.max), 'x', true);

                        ctx.fillStyle = rgba(colors.down, 0.13);
                        ctx.fillRect(x0, u.bbox.top, Math.max(2, x1 - x0), u.bbox.height);
                    });
                    ctx.restore();
                }
            }
        };
    }

    function build(element, payload, incidents) {
        var colors = palette();
        var series = payload.series || payload;
        var data = [
            series.t,
            series.avg,
            series.p95 || series.avg.map(function () { return null; })
        ];

        var opts = {
            width: element.clientWidth || 600,
            height: element.classList.contains('chart--tall') ? 280 : 200,
            padding: [12, 8, 0, 0],
            cursor: { y: false, points: { size: 6 } },
            legend: { live: true },
            scales: { x: { time: true } },
            axes: [
                {
                    stroke: colors.muted,
                    grid: { stroke: rgba(colors.line, 0.8), width: 1 },
                    ticks: { stroke: rgba(colors.line, 0.8), width: 1 },
                    font: '11px "IBM Plex Mono", monospace'
                },
                {
                    stroke: colors.muted,
                    grid: { stroke: rgba(colors.line, 0.6), width: 1 },
                    ticks: { show: false },
                    font: '11px "IBM Plex Mono", monospace',
                    size: 46,
                    values: function (u, ticks) {
                        return ticks.map(function (v) { return v >= 1000 ? (v / 1000) + 's' : v + 'ms'; });
                    }
                }
            ],
            series: [
                { value: '{YYYY}-{MM}-{DD} {HH}:{mm}' },
                {
                    label: 'avg',
                    stroke: colors.accent,
                    width: 1.75,
                    fill: rgba(colors.accent, 0.12),
                    points: { show: false },
                    value: function (u, v) { return v === null ? '—' : Math.round(v) + ' ms'; }
                },
                {
                    label: 'p95',
                    stroke: rgba(colors.warn, 0.9),
                    width: 1,
                    dash: [4, 3],
                    points: { show: false },
                    value: function (u, v) { return v === null ? '—' : Math.round(v) + ' ms'; }
                }
            ],
            plugins: [bandsPlugin(function () { return incidents; })]
        };

        return new uPlot(opts, data, element);
    }

    function mount(element) {
        var url = element.getAttribute('data-chart-url');
        var inline = element.getAttribute('data-chart-data');
        var entry = { element: element, url: url, plot: null, incidents: [] };

        function draw(payload) {
            entry.incidents = payload.incidents || [];
            if (entry.plot) {
                entry.plot.destroy();
                element.innerHTML = '';
            }
            entry.plot = build(element, payload, entry.incidents);
        }

        if (inline) {
            draw(JSON.parse(inline));
        } else if (url) {
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(draw)
                .catch(function () {
                    element.innerHTML = '<p class="muted">The chart data could not be loaded.</p>';
                });
        }

        entry.reload = function () {
            if (!entry.url) return;
            fetch(entry.url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(draw)
                .catch(function () {});
        };

        instances.push(entry);
    }

    document.querySelectorAll('[data-chart]').forEach(mount);

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            instances.forEach(function (entry) {
                if (entry.plot) entry.plot.setSize({ width: entry.element.clientWidth, height: entry.plot.height });
            });
        }, 150);
    });

    // Repaint when the theme changes so the chart follows the tokens.
    new MutationObserver(function () {
        instances.forEach(function (entry) { entry.reload(); });
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    window.MonitorChart = {
        refresh: function () {
            instances.forEach(function (entry) { entry.reload(); });
        }
    };
})();
