/**
 * Shell behaviour: theme switching, destructive-action confirmation, the UTC
 * clock in the top bar, and form conveniences. No framework, no build step.
 */
(function () {
    'use strict';

    /* ── Theme ──────────────────────────────────────────────────────── */

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-option]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-theme-option') === theme));
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-theme-option]');
        if (!button) return;

        event.preventDefault();
        var theme = button.getAttribute('data-theme-option');
        applyTheme(theme);
        document.cookie = 'monitor_theme=' + theme + ';path=/;max-age=31536000;samesite=lax';
    });

    /* ── Confirmations ──────────────────────────────────────────────── */

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /* ── UTC clock ──────────────────────────────────────────────────── */

    var clock = document.querySelector('[data-clock]');
    if (clock) {
        var tick = function () {
            var now = new Date();
            clock.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        };
        tick();
        setInterval(tick, 1000);
    }

    /* ── Relative timestamps ────────────────────────────────────────── */

    function relative(iso) {
        var seconds = Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 1000));
        if (seconds < 10) return 'just now';
        if (seconds < 60) return seconds + 's ago';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    function refreshRelative() {
        document.querySelectorAll('[data-relative]').forEach(function (element) {
            var iso = element.getAttribute('data-relative');
            if (iso) element.textContent = relative(iso);
        });
    }

    refreshRelative();
    setInterval(refreshRelative, 15000);

    /* ── Forms ──────────────────────────────────────────────────────── */

    // Show the fields that belong to the selected monitor type.
    var typeSelect = document.querySelector('[data-type-select]');
    if (typeSelect) {
        var syncType = function () {
            document.querySelectorAll('[data-type-fields]').forEach(function (block) {
                block.hidden = block.getAttribute('data-type-fields') !== typeSelect.value;
            });
        };
        typeSelect.addEventListener('change', syncType);
        syncType();
    }

    // Reveal optional blocks (advanced settings, authentication).
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-toggle]');
        if (!toggle) return;

        var target = document.querySelector(toggle.getAttribute('data-toggle'));
        if (!target) return;

        event.preventDefault();
        target.hidden = !target.hidden;
        toggle.setAttribute('aria-expanded', String(!target.hidden));
    });

    window.MonitorRelative = relative;
})();
