/**
 * Shell behaviour: theme switching, tabs, the UTC clock in the top bar, and the
 * small conveniences on forms. Confirmations live in modal.js.
 * No framework, no build step.
 */
(function () {
    'use strict';

    /* ── Theme ──────────────────────────────────────────────────────── */

    // Which icon shows is CSS's job; script only moves the attribute the CSS
    // reads, stores the choice, and renames the button after the outcome it
    // will produce next time.
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            var next = theme === 'dark' ? 'data-label-light' : 'data-label-dark';
            var label = button.getAttribute(next) || '';
            button.setAttribute('title', label);
            button.setAttribute('aria-label', label);
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-theme-toggle]');
        if (!button) return;

        event.preventDefault();
        // Nothing stamped means the page is following the system, which only
        // happens before a theme has ever been resolved; ask the browser what
        // that currently looks like so the first click flips what is on screen.
        var current = document.documentElement.getAttribute('data-theme');
        if (current !== 'light' && current !== 'dark') {
            current = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        var theme = current === 'dark' ? 'light' : 'dark';
        applyTheme(theme);
        document.cookie = 'monitor_theme=' + theme + ';path=/;max-age=31536000;samesite=lax';
    });

    /* ── Notifications ──────────────────────────────────────────────── */

    // Two kinds share one place at the top of the screen. A flash answers
    // something somebody just did and is gone in three seconds; a notice is a
    // standing fact about the page and stays until it is sent away.
    //
    // The server renders flashes into the page as it always has, and these are
    // lifted out of it. With script off they stay where they were rendered and
    // behave exactly as before, which is why they are not built here.

    var TOAST_MS = 3000;
    var toasts = null;
    var standing = null;
    var flashes = null;

    // Two groups in one place: what is true about this machine on top, side by
    // side, and what just happened underneath. The order is the reading order
    // -- a fact outlives the answer to a click, so it should not be pushed
    // around by one.
    function toastArea() {
        if (toasts && document.body.contains(toasts)) return toasts;

        toasts = document.createElement('div');
        toasts.className = 'toasts';
        toasts.setAttribute('aria-live', 'polite');

        standing = document.createElement('div');
        standing.className = 'toasts__standing';
        flashes = document.createElement('div');
        flashes.className = 'toasts__flash';

        toasts.appendChild(standing);
        toasts.appendChild(flashes);
        document.body.appendChild(toasts);

        return toasts;
    }

    function group(sticky) {
        toastArea();

        return sticky ? standing : flashes;
    }

    function dismiss(toast) {
        if (toast.dataset.going === '1') return;
        toast.dataset.going = '1';
        toast.classList.remove('toast--in');
        toast.classList.add('toast--out');
        window.setTimeout(function () { toast.remove(); }, 220);
    }

    function show(toast, sticky) {
        group(sticky).appendChild(toast);
        // Two frames, so the browser has painted the starting state and has
        // something to transition from.
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { toast.classList.add('toast--in'); });
        });

        if (!sticky) window.setTimeout(function () { dismiss(toast); }, TOAST_MS);
    }

    // The server's flashes, moved rather than rebuilt: whatever markup and
    // wording they were given is what appears. Only the ones the layout marked
    // as answers to a click -- a standing warning about the database, or about
    // a machine wanting a restart, is not something to take away after three
    // seconds.
    document.querySelectorAll('[data-flash-toast] .flash').forEach(function (flash) {
        var kind = (flash.className.match(/flash--([a-z]+)/) || [])[1] || '';
        var wrapper = flash.parentElement;

        flash.classList.add('toast');
        if (kind) flash.classList.add('toast--' + kind);
        flash.classList.remove('flash');
        if (kind) flash.classList.remove('flash--' + kind);

        show(flash, false);
        if (wrapper && wrapper.children.length === 0) wrapper.remove();
    });

    document.addEventListener('click', function (event) {
        var close = event.target.closest('.toast__close');
        if (!close) return;

        var toast = close.closest('.toast');
        if (!toast) return;

        if (toast.dataset.noticeKey) {
            // Sent away for this count, and only this count: when the machine
            // has a different number waiting it is news again. Nothing is
            // stored for zero, because zero never shows in the first place.
            try {
                window.localStorage.setItem(toast.dataset.noticeKey, toast.dataset.noticeValue || '');
            } catch (error) { /* private windows have no storage; it just comes back */ }
        }

        dismiss(toast);
    });

    // A standing fact about the page, dismissed against a value rather than
    // for good -- and able to change while the page is open, because the fact
    // can: an upgrade finishes and the number waiting is a different number.
    //
    // Named on the window because the page that watches a machine lives in
    // another file and has to be able to hand this the new state. One function,
    // rather than a second copy of the toast over there.
    var dismissLabel = 'Dismiss';

    function notice(spec) {
        var live = null;
        group(true).querySelectorAll('.toast').forEach(function (toast) {
            if (toast.dataset.noticeKey === spec.key) live = toast;
        });

        // Nothing waiting any more: take it off the screen, and forget the
        // dismissal so the next thing to arrive is news.
        if (!spec.value) {
            if (live) dismiss(live);
            try { window.localStorage.removeItem(spec.key); } catch (error) { /* nothing to forget */ }
            return;
        }

        if (live) {
            // Already up. Only the wording and the value it is dismissed
            // against need to move; putting it up again would make it flash.
            if (live.dataset.noticeValue === spec.value) return;
            live.dataset.noticeValue = spec.value;
            live.className = 'toast toast--wide toast--' + (spec.kind || 'warning') + ' toast--in';
            live.innerHTML = spec.html;
            live.appendChild(closeButton());
            return;
        }

        var seen = null;
        try { seen = window.localStorage.getItem(spec.key); } catch (error) { seen = null; }
        if (seen === spec.value) return;

        var toast = document.createElement('div');
        toast.className = 'toast toast--wide toast--' + (spec.kind || 'warning');
        toast.setAttribute('role', 'status');
        toast.dataset.noticeKey = spec.key;
        toast.dataset.noticeValue = spec.value;
        toast.innerHTML = spec.html;
        toast.appendChild(closeButton());

        show(toast, true);
    }

    function closeButton() {
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('aria-label', dismissLabel);
        close.textContent = '\u00d7';

        return close;
    }

    window.monitorNotice = notice;

    document.querySelectorAll('[data-notice]').forEach(function (source) {
        dismissLabel = source.getAttribute('data-notice-dismiss') || dismissLabel;
        notice({
            key: source.getAttribute('data-notice-key') || '',
            value: source.getAttribute('data-notice-value') || '',
            kind: source.getAttribute('data-notice') || 'warning',
            html: source.innerHTML
        });
    });

    /* ── How a list of machines is shown ────────────────────────────── */

    // The link already carries the choice in its address, so this page is
    // right either way; the cookie is only so the next one starts the same.
    // Which means it works with script switched off, minus the remembering.
    document.addEventListener('click', function (event) {
        var choice = event.target.closest('[data-view-choice]');
        if (!choice) return;

        var view = choice.getAttribute('data-view-choice');
        if (view !== 'cards' && view !== 'list') return;

        document.cookie = 'monitor_devices_view=' + view + ';path=/;max-age=31536000;samesite=lax';
    });

    /* ── Filters ────────────────────────────────────────────────────── */

    // A select that filters a list applies itself. The form keeps its own
    // submit button for anyone without JavaScript.
    document.addEventListener('change', function (event) {
        var control = event.target.closest('[data-autosubmit]');
        if (!control || !control.form) return;

        if (typeof control.form.requestSubmit === 'function') {
            control.form.requestSubmit();
        } else {
            control.form.submit();
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
        },
        keyword: {
            label: 'URL',
            placeholder: 'https://example.com/pricing',
            hint: 'The page whose wording is checked.'
        },
        api: {
            label: 'Base URL',
            placeholder: 'https://api.example.com',
            hint: 'Every step below is called against this address.'
        },
        ssl: {
            label: 'Host',
            placeholder: 'example.com',
            hint: 'The host whose certificate is read. No http:// in front of it.'
        },
        domain: {
            label: 'Domain',
            placeholder: 'example.com',
            hint: 'The registered domain, without a subdomain in front of it.'
        },
        dns: {
            label: 'Name',
            placeholder: 'example.com',
            hint: 'The name to look up. Subdomains and names like _dmarc.example.com are fine.'
        }
    };

    // Mirrors Monitors::minimumInterval(); the server enforces it either way.
    var MIN_INTERVALS = { domain: 3600 };

    // Some fields are only required once their monitor type is on screen: the
    // port of a port check, the words of a keyword check, the path of an API
    // step. The attribute has to come and go with the panel, because a browser
    // refuses to submit a form that is waiting on a field nobody can see.
    function syncRequired() {
        document.querySelectorAll('[data-required]').forEach(function (field) {
            field.required = !field.closest('[hidden]');
        });
    }

    syncRequired();

    // The type picker is a set of radio cards, so the chosen one has to be
    // asked for rather than read off a select's value.
    var typePicker = document.querySelector('[data-type-select]');
    if (typePicker) {
        var aboutLine = document.querySelector('[data-type-about]');
        var downLine = document.querySelector('[data-type-down]');

        var syncType = function () {
            var chosen = typePicker.querySelector('input[name="type"]:checked');
            var type = chosen ? chosen.value : 'http';

            // Each card carries what it watches and what counts as down; the
            // chosen one lends both to the space under the row.
            if (aboutLine && chosen) {
                aboutLine.textContent = chosen.getAttribute('data-about') || '';
            }

            if (downLine && chosen) {
                var down = chosen.getAttribute('data-down') || '';
                downLine.textContent = down;
                downLine.hidden = down === '';
            }

            document.querySelectorAll('[data-type-fields]').forEach(function (block) {
                var types = block.getAttribute('data-type-fields').split(/\s+/);
                block.hidden = types.indexOf(type) === -1;
            });

            syncRequired();

            var copy = TARGETS[type] || TARGETS.http;
            var label = document.querySelector('[data-target-label]');
            var hint = document.querySelector('[data-target-hint]');
            var input = document.querySelector('[data-target-input]');

            if (label) label.textContent = copy.label;
            if (hint) hint.textContent = copy.hint;
            if (input) input.placeholder = copy.placeholder;

            syncInterval(type);
        };

        // A registry lookup every 30 seconds would be rude and would tell you
        // nothing new, so the short intervals are closed off for those types.
        var intervalSelect = document.querySelector('[data-interval-select]');
        var syncInterval = function (type) {
            if (!intervalSelect) return;

            var minimum = MIN_INTERVALS[type] || 0;
            var current = parseInt(intervalSelect.value, 10) || 0;

            Array.prototype.forEach.call(intervalSelect.options, function (option) {
                option.disabled = parseInt(option.value, 10) < minimum;
            });

            if (current < minimum) {
                for (var i = 0; i < intervalSelect.options.length; i++) {
                    if (!intervalSelect.options[i].disabled) {
                        intervalSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        };
        typePicker.addEventListener('change', syncType);
        syncType();
    }

    // Sharing is required as well, but it is a row of radios per group rather
    // than one field, so nothing native can mark it. The list keeps a ring
    // around it until some group is given a level other than "no access".
    var access = document.querySelector('[data-access-required]');
    if (access) {
        var syncAccess = function () {
            var chosen = access.querySelector('input[type="radio"]:checked:not([value="none"])');
            access.classList.toggle('access--needed', !chosen);
        };

        access.addEventListener('change', syncAccess);
        syncAccess();
    }

    // Repeatable rows: endpoint assertions, and the assertions and captures
    // inside each step of an API check. One handler for all of them — a row is
    // cloned from the last one in its own list, so the names stay right.
    function emptyRow(row) {
        row.querySelectorAll('input, textarea').forEach(function (field) {
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = false;
            } else {
                field.value = '';
            }
        });
        row.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-add-row]');
        if (add) {
            var group = add.closest('[data-rows-group]');
            var list = group && group.querySelector('[data-rows]');
            if (!list) return;

            var rows = list.querySelectorAll('[data-row]');
            var last = rows[rows.length - 1];
            if (!last) return;

            var row = last.cloneNode(true);
            emptyRow(row);
            list.appendChild(row);

            var first = row.querySelector('input, textarea');
            if (first) first.focus();
            return;
        }

        var remove = event.target.closest('[data-remove-row]');
        if (!remove) return;

        var owner = remove.closest('[data-rows]');
        var target = remove.closest('[data-row]');
        if (!owner || !target) return;

        if (owner.querySelectorAll('[data-row]').length > 1) {
            target.remove();
        } else {
            emptyRow(target);
        }
    });

    // Steps of an API check. A step cannot be cloned like a row: its fields are
    // named step[3][path], so a new one is stamped out of a template with the
    // next free index written into it.
    var stepList = document.querySelector('[data-api-steps]');
    if (stepList) {
        var stepTemplate = document.querySelector('[data-step-template]');
        var nextStepIndex = stepList.querySelectorAll('[data-api-step]').length;

        var numberSteps = function () {
            var steps = stepList.querySelectorAll('[data-api-step]');
            steps.forEach(function (step, index) {
                var label = step.querySelector('[data-step-number]');
                if (label) label.textContent = 'Step ' + (index + 1);

                // The last step standing keeps its fields; it just cannot go.
                var remove = step.querySelector('[data-remove-step]');
                if (remove) remove.hidden = steps.length < 2;
            });
        };

        numberSteps();

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-add-step]')) {
                if (!stepTemplate) return;
                if (stepList.querySelectorAll('[data-api-step]').length >= 8) return;

                var holder = document.createElement('div');
                holder.innerHTML = stepTemplate.innerHTML.replace(/__i__/g, String(nextStepIndex++));

                var step = holder.querySelector('[data-api-step]');
                if (!step) return;

                stepList.appendChild(step);
                numberSteps();
                syncRequired();

                var first = step.querySelector('input');
                if (first) first.focus();
                return;
            }

            var removeStep = event.target.closest('[data-remove-step]');
            if (!removeStep) return;

            var step = removeStep.closest('[data-api-step]');
            if (!step || stepList.querySelectorAll('[data-api-step]').length < 2) return;

            step.remove();
            numberSteps();
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
