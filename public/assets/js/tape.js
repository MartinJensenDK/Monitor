/**
 * The tape — client side.
 *
 * Mirrors App\Support\Tape so a strip rendered by PHP on first paint and one
 * repainted by the poller are pixel identical. Keep the two in step.
 */
(function () {
    'use strict';

    var VARIANTS = {
        row: { bar: 3, gap: 1, height: 26, slots: 48 },
        hero: { bar: 4, gap: 2, height: 64, slots: 96 },
        fleet: { bar: 2, gap: 1, height: 34, slots: 160 }
    };

    function ceiling(points) {
        var max = 0;
        points.forEach(function (p) {
            if (p.response_ms !== null && p.response_ms !== undefined) {
                max = Math.max(max, p.response_ms);
            }
        });

        var steps = [200, 500, 1000, 2000, 5000, 10000, 30000];
        for (var i = 0; i < steps.length; i++) {
            if (max <= steps[i]) return steps[i];
        }
        return Math.max(30000, max);
    }

    function scale(ms, top) {
        if (ms === null || ms === undefined || ms <= 0) return 0.15;
        var value = Math.log10(Math.max(1, ms)) / Math.log10(Math.max(10, top));
        return Math.max(0.08, Math.min(1, value));
    }

    function tooltip(point) {
        var parts = [point.checked_at + ' UTC', (point.status || '').toUpperCase()];
        parts.push(point.response_ms === null || point.response_ms === undefined ? '—' : point.response_ms + ' ms');
        if (point.error_message) parts.push(point.error_message);
        return parts.join(' · ');
    }

    function escapeAttr(value) {
        return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    /** Returns the SVG markup for a strip. */
    function render(points, variant, label, freshCount) {
        var spec = VARIANTS[variant] || VARIANTS.row;
        var width = spec.slots * (spec.bar + spec.gap) - spec.gap;
        var visible = points.slice(-spec.slots);
        var offset = spec.slots - visible.length;
        var top = ceiling(visible);
        var svg = '';

        svg += '<line class="tape-base" x1="0" y1="' + (spec.height - 0.5) + '" x2="' + width +
               '" y2="' + (spec.height - 0.5) + '"/>';

        for (var i = 0; i < offset; i++) {
            svg += '<rect class="tape-bar tape-bar--empty" x="' + (i * (spec.bar + spec.gap)) +
                   '" y="' + (spec.height - 2) + '" width="' + spec.bar + '" height="2" rx="0.5"/>';
        }

        visible.forEach(function (point, index) {
            var x = (offset + index) * (spec.bar + spec.gap);
            var status = point.status || 'down';
            var height = status === 'down'
                ? spec.height
                : Math.max(2, Math.round(scale(point.response_ms, top) * (spec.height - 3)) + 2);
            var fresh = freshCount && index >= visible.length - freshCount ? ' tape-bar--fresh' : '';

            svg += '<rect class="tape-bar tape-bar--' + status + fresh + '" x="' + x + '" y="' +
                   (spec.height - height) + '" width="' + spec.bar + '" height="' + height +
                   '" rx="' + (spec.bar > 2 ? 1 : 0.5) + '"><title>' + escapeAttr(tooltip(point)) +
                   '</title></rect>';
        });

        return '<svg class="tape tape--' + variant + '" viewBox="0 0 ' + width + ' ' + spec.height +
               '" preserveAspectRatio="none" role="img" aria-label="' + escapeAttr(label || 'Recent checks') +
               '">' + svg + '</svg>';
    }

    /** Repaint a container, animating only the bars that are actually new. */
    function paint(container, points, variant) {
        var previous = parseInt(container.getAttribute('data-tape-count') || '0', 10);
        var fresh = Math.max(0, Math.min(points.length - previous, 4));

        container.innerHTML = render(points, variant, container.getAttribute('data-tape-label'), fresh);
        container.setAttribute('data-tape-count', String(points.length));
    }

    window.MonitorTape = { render: render, paint: paint };
})();
