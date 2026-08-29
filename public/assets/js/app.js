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

    // Show the fields that belong to the selected monitor type, and word the
    // shared target field for what that type actually wants.
    var TARGETS = {
        http: {
            label: 'URL',
            placeholder: 'https://example.com',
            hint: 'The full address to request, including https://.'
        },
        endpoint: {
            label: 'URL',
            placeholder: 'https://api.example.com/v1/health',
            hint: 'The endpoint to call. Its JSON is checked against the assertions below.'
        },
        ping: {
            label: 'Host',
            placeholder: 'example.com',
            hint: 'A host name or IP address, without http:// in front of it.'
        },
        port: {
            label: 'Host',
            placeholder: 'db.example.com',
            hint: 'The host to connect to. The port goes in the field next to it.'
        }
    };

    var typeSelect = document.querySelector('[data-type-select]');
    if (typeSelect) {
        var syncType = function () {
            var type = typeSelect.value;

            document.querySelectorAll('[data-type-fields]').forEach(function (block) {
                var types = block.getAttribute('data-type-fields').split(/\s+/);
                block.hidden = types.indexOf(type) === -1;
            });

            var copy = TARGETS[type] || TARGETS.http;
            var label = document.querySelector('[data-target-label]');
            var hint = document.querySelector('[data-target-hint]');
            var input = document.querySelector('[data-target-input]');

            if (label) label.textContent = copy.label;
            if (hint) hint.textContent = copy.hint;
            if (input) input.placeholder = copy.placeholder;
        };
        typeSelect.addEventListener('change', syncType);
        syncType();
    }

    // Assertion rows on the endpoint form.
    var assertionList = document.querySelector('[data-assertions]');
    if (assertionList) {
        document.querySelectorAll('[data-add-assertion]').forEach(function (button) {
            button.addEventListener('click', function () {
                var template = assertionList.querySelector('[data-assertion-row]');
                if (!template) return;

                var row = template.cloneNode(true);
                row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                row.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
                assertionList.appendChild(row);
                var first = row.querySelector('input');
                if (first) first.focus();
            });
        });

        assertionList.addEventListener('click', function (event) {
            if (!event.target.closest('[data-remove-assertion]')) return;

            var rows = assertionList.querySelectorAll('[data-assertion-row]');
            var row = event.target.closest('[data-assertion-row]');
            if (rows.length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
            }
        });
    }

    // A notification channel's rules only matter when the channel is on.
    document.querySelectorAll('[data-channel-toggle]').forEach(function (toggle) {
        var body = document.querySelector(toggle.getAttribute('data-channel-toggle'));
        if (!body) return;

        var sync = function () { body.hidden = !toggle.checked; };
        toggle.addEventListener('change', sync);
        sync();
    });

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

    // Tabs. The links are ordinary URLs and work without JavaScript; this
    // just spares a page load and keeps the address bar honest.
    var tabLinks = document.querySelectorAll('[data-tab-link]');
    if (tabLinks.length) {
        tabLinks.forEach(function (link) {
            link.addEventListener('click', function (event) {
                var name = link.getAttribute('data-tab-link');
                var panel = document.querySelector('[data-tab-panel="' + name + '"]');
                if (!panel) return;

                event.preventDefault();

                document.querySelectorAll('[data-tab-panel]').forEach(function (section) {
                    section.hidden = section.getAttribute('data-tab-panel') !== name;
                });
                tabLinks.forEach(function (other) {
                    other.setAttribute('aria-current', other === link ? 'page' : 'false');
                });

                // Not every embedding allows a history rewrite; the tab has
                // already switched either way.
                try {
                    history.replaceState(null, '', link.getAttribute('href'));
                } catch (error) {
                    /* ignore */
                }

                window.scrollTo({ top: 0 });
            });
        });
    }

    // Entra group picker: ask the directory for its groups so nobody has to
    // paste object ids, and keep whatever is already selected checked.
    var entraLoad = document.querySelector('[data-entra-load]');
    if (entraLoad) {
        var list = document.querySelector('[data-entra-groups]');
        var result = document.querySelector('[data-entra-result]');
        var empty = document.querySelector('[data-entra-empty]');

        entraLoad.addEventListener('click', function () {
            entraLoad.disabled = true;
            result.setAttribute('data-state', 'busy');
            result.textContent = 'Asking the directory…';

            fetch('/settings/entra/groups', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                if (!payload.ok) {
                    result.setAttribute('data-state', 'error');
                    result.textContent = payload.message || 'The directory could not be read.';
                    return;
                }

                var selected = {};
                list.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
                    if (input.checked) selected[input.value] = true;
                });

                list.innerHTML = payload.groups.map(function (group) {
                    var checked = selected[group.id] ? ' checked' : '';
                    var detail = group.description || group.id;
                    return '<label class="check">' +
                        '<input type="checkbox" name="entra_groups[]" value="' + escapeAttr(group.id) + '"' + checked + '>' +
                        '<span class="check__text">' + escapeHtml(group.name) +
                        '<small>' + escapeHtml(detail) + '</small></span></label>';
                }).join('');

                if (empty) empty.hidden = true;

                result.setAttribute('data-state', 'ok');
                result.textContent = payload.groups.length + ' group(s) in the directory. Tick the ones to mirror, then save.';
            }).catch(function () {
                result.setAttribute('data-state', 'error');
                result.textContent = 'The request failed. Reload the page and try again.';
            }).finally(function () {
                entraLoad.disabled = false;
            });
        });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : value;
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    window.MonitorRelative = relative;
})();
