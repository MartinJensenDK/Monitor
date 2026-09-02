/**
 * Live updates without reloading the page.
 *
 * Polls /api/live every few seconds with If-None-Match. A 304 costs the server
 * one status query and nothing else, polling pauses while the tab is hidden,
 * and errors back off instead of hammering. Everything it touches is marked
 * with a data-live-* attribute, so a page opts in by adding attributes.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-live-scope]');
    if (!root) return;

    var scope = root.getAttribute('data-live-scope');
    var interval = parseInt(root.getAttribute('data-live-interval') || '5000', 10);
    var etag = null;
    var timer = null;
    var failures = 0;

    function setStale(stale) {
        root.setAttribute('data-live-stale', String(stale));
    }

    function text(selector, value) {
        document.querySelectorAll(selector).forEach(function (element) {
            if (element.textContent !== value) element.textContent = value;
        });
    }

    function formatMs(ms) {
        if (ms === null || ms === undefined) return '—';
        return ms < 1000 ? ms + ' ms' : (ms / 1000).toFixed(2).replace(/0+$/, '').replace(/\.$/, '') + ' s';
    }

    function formatUptime(ratio) {
        if (ratio === null || ratio === undefined) return '—';
        var percent = ratio * 100;
        var decimals = percent >= 99.9 ? 2 : 1;
        return percent.toFixed(decimals) + '%';
    }

    function applyStatus(element, status) {
        if (!element) return;
        element.className = element.className.replace(/pill--\w+/g, '').trim() + ' pill pill--' + status;
        element.textContent = status;
    }

    /* ── Dashboard and monitor list ─────────────────────────────────── */

    function applyDashboard(data) {
        text('[data-live-count="up"]', String(data.counts.up));
        text('[data-live-count="down"]', String(data.counts.down));
        text('[data-live-count="degraded"]', String(data.counts.degraded));
        text('[data-live-count="total"]', String(data.counts.total));
        text('[data-live-fleet="uptime"]', formatUptime(data.fleet.uptime_24h));
        text('[data-live-fleet="latency"]', formatMs(data.fleet.latency));
        text('[data-live-fleet="incidents"]', String(data.fleet.open_incidents));

        var banner = document.querySelector('[data-live-readout]');
        if (banner) {
            var state = data.counts.down > 0 ? 'down' : (data.counts.degraded > 0 ? 'warn' : 'up');
            banner.className = 'readout readout--' + state;
            var label = banner.querySelector('[data-live-readout-text]');
            if (label) {
                label.textContent = data.counts.down > 0
                    ? data.counts.down + (data.counts.down === 1 ? ' monitor down' : ' monitors down')
                    : (data.counts.degraded > 0 ? data.counts.degraded + ' degraded' : 'All systems operational');
            }
        }

        data.monitors.forEach(function (monitor) {
            var row = document.querySelector('[data-monitor-id="' + monitor.id + '"]');
            if (!row) return;

            applyStatus(row.querySelector('[data-live-status]'), monitor.status);

            var latency = row.querySelector('[data-live-latency]');
            if (latency) latency.textContent = formatMs(monitor.response_ms);

            var uptime = row.querySelector('[data-live-uptime]');
            if (uptime) uptime.textContent = formatUptime(monitor.uptime_24h);

            var seen = row.querySelector('[data-live-seen]');
            if (seen && monitor.last_check_at) {
                var iso = monitor.last_check_at.replace(' ', 'T') + 'Z';
                seen.setAttribute('data-relative', iso);
                seen.textContent = window.MonitorRelative ? window.MonitorRelative(iso) : '';
            }

            var tape = row.querySelector('[data-tape]');
            if (tape && window.MonitorTape) {
                window.MonitorTape.paint(tape, monitor.tape, tape.getAttribute('data-tape') || 'row');
            }
        });

        // The map: a pin only ever carries a colour, so the class is the
        // whole update. Locations that came or went need a reload -- pins are
        // server-rendered, and a place is not added mid-poll.
        (data.locations || []).forEach(function (place) {
            var pin = document.querySelector('[data-location-id="' + place.id + '"]');
            if (pin) {
                pin.setAttribute('class', 'wmap__pin wmap__pin--' + place.status);
                var title = pin.querySelector('title');
                if (title) title.textContent = place.name + ' \u2014 ' + locationSummary(place);
            }

            var row = document.querySelector('[data-location-row="' + place.id + '"]');
            var badge = row && row.querySelector('[data-location-status]');
            if (badge) {
                badge.setAttribute('class', 'pill pill--' + place.status);
                badge.textContent = place.down > 0
                    ? place.down + ' down'
                    : (place.degraded > 0 ? place.degraded + ' degraded' : place.total + ' up');
            }
        });

        var fleetTape = document.querySelector('[data-fleet-tape]');
        if (fleetTape && window.MonitorTape) {
            var merged = [];
            data.monitors.forEach(function (m) { merged = merged.concat(m.tape); });
            merged.sort(function (a, b) { return a.checked_at < b.checked_at ? -1 : 1; });
            window.MonitorTape.paint(fleetTape, merged.slice(-160), 'fleet');
        }
    }

    function locationSummary(place) {
        if (place.down > 0) return place.down + ' of ' + place.total + ' down';
        if (place.degraded > 0) return place.degraded + ' of ' + place.total + ' degraded';
        return place.total > 0 ? 'all ' + place.total + ' up' : 'nothing watched yet';
    }

    /* ── Single monitor page ────────────────────────────────────────── */

    function applyMonitor(data) {
        var monitor = data.monitor;

        applyStatus(document.querySelector('[data-live-status]'), monitor.status);
        text('[data-live-latency]', formatMs(monitor.response_ms));
        text('[data-live-uptime="24h"]', formatUptime(monitor.uptime_24h));
        text('[data-live-uptime="7d"]', formatUptime(monitor.uptime_7d));
        text('[data-live-uptime="30d"]', formatUptime(monitor.uptime_30d));
        text('[data-live-avg]', formatMs(monitor.avg_ms_24h));
        text('[data-live-p95]', formatMs(monitor.p95_ms_24h));

        var seen = document.querySelector('[data-live-seen]');
        if (seen && monitor.last_check_at) {
            var iso = monitor.last_check_at.replace(' ', 'T') + 'Z';
            seen.setAttribute('data-relative', iso);
            seen.textContent = window.MonitorRelative ? window.MonitorRelative(iso) : '';
        }

        var tape = document.querySelector('[data-tape]');
        if (tape && window.MonitorTape) {
            window.MonitorTape.paint(tape, monitor.tape, tape.getAttribute('data-tape') || 'hero');
        }

        var tbody = document.querySelector('[data-live-checks]');
        if (tbody) {
            tbody.innerHTML = data.checks.map(function (check) {
                return '<tr>' +
                    '<td class="num">' + check.checked_at + '</td>' +
                    '<td><span class="pill pill--' + check.status + '">' + check.status + '</span></td>' +
                    '<td class="num table__right">' + formatMs(check.response_ms) + '</td>' +
                    '<td class="num">' + (check.http_code || '—') + '</td>' +
                    '<td class="muted">' + (check.error_message ? escapeHtml(check.error_message) : '') + '</td>' +
                    '</tr>';
            }).join('');
        }

        if (window.MonitorChart && window.MonitorChart.refresh) {
            window.MonitorChart.refresh();
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    /* ── Poller ─────────────────────────────────────────────────────── */

    function poll() {
        var headers = { 'X-Requested-With': 'fetch' };
        if (etag) headers['If-None-Match'] = etag;

        fetch('/api/live?scope=' + encodeURIComponent(scope), {
            headers: headers,
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.status === 304) {
                failures = 0;
                setStale(false);
                return null;
            }
            if (response.status === 401) {
                window.location.href = '/login';
                return null;
            }
            if (!response.ok) throw new Error('live request failed: ' + response.status);

            etag = response.headers.get('ETag');
            return response.json();
        }).then(function (data) {
            failures = 0;
            setStale(false);
            if (!data) return;

            if (scope.indexOf('monitor:') === 0) {
                applyMonitor(data);
            } else {
                applyDashboard(data);
            }
        }).catch(function () {
            failures++;
            setStale(true);
        }).finally(function () {
            schedule();
        });
    }

    function schedule() {
        clearTimeout(timer);
        if (document.hidden) return;

        // Back off after repeated failures rather than hammering a sick server.
        var delay = failures === 0 ? interval : Math.min(interval * Math.pow(2, failures), 60000);
        timer = setTimeout(poll, delay);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            poll();
        }
    });

    poll();
})();
