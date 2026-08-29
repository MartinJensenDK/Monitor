/**
 * Setup wizard behaviour. Without JavaScript every step is visible and the
 * form still submits — this only makes it one question at a time.
 */
(function () {
    'use strict';

    var wizard = document.querySelector('[data-wizard]');
    if (!wizard) return;

    var steps = Array.prototype.slice.call(wizard.querySelectorAll('[data-step]'));
    var markers = Array.prototype.slice.call(wizard.querySelectorAll('.steps__item'));
    var current = 0;
    var dbVerified = false;

    wizard.setAttribute('data-js', '1');

    function show(index) {
        current = Math.max(0, Math.min(index, steps.length - 1));

        steps.forEach(function (step, i) {
            step.setAttribute('data-active', String(i === current));
        });

        markers.forEach(function (marker, i) {
            marker.setAttribute('data-state', i < current ? 'done' : (i === current ? 'current' : ''));
        });

        wizard.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    // Steps only advance when the fields on them are valid.
    function stepIsValid(step) {
        var fields = step.querySelectorAll('input[required], select[required]');
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) {
                fields[i].reportValidity();
                return false;
            }
        }
        return true;
    }

    wizard.addEventListener('click', function (event) {
        if (event.target.closest('[data-next]')) {
            if (!stepIsValid(steps[current])) return;
            show(current + 1);
        }
        if (event.target.closest('[data-back]')) {
            show(current - 1);
        }
    });

    /* ── Same-host checkbox ─────────────────────────────────────────── */

    var sameHost = wizard.querySelector('[data-same-host]');
    var remoteFields = Array.prototype.slice.call(wizard.querySelectorAll('[data-remote-only]'));

    function syncHostFields() {
        remoteFields.forEach(function (field) {
            field.hidden = sameHost.checked;
        });
    }

    if (sameHost) {
        sameHost.addEventListener('change', function () {
            syncHostFields();
            invalidate();
        });
        syncHostFields();
    }

    /* ── Test connection ────────────────────────────────────────────── */

    var result = wizard.querySelector('[data-db-result]');
    var continueButton = wizard.querySelector('[data-requires-db]');

    function invalidate() {
        dbVerified = false;
        if (continueButton) continueButton.disabled = true;
        if (result) {
            result.removeAttribute('data-state');
            result.textContent = '';
        }
    }

    ['db_host', 'db_port', 'db_name', 'db_user', 'db_password'].forEach(function (name) {
        var field = wizard.querySelector('[name="' + name + '"]');
        if (field) field.addEventListener('input', invalidate);
    });

    var testButton = wizard.querySelector('[data-test-db]');
    if (testButton) {
        testButton.addEventListener('click', function () {
            var form = wizard.querySelector('[data-install-form]');
            var data = new FormData();

            data.append('_csrf', form.querySelector('[name="_csrf"]').value);
            data.append('same_host', sameHost && sameHost.checked ? '1' : '0');
            ['db_host', 'db_port', 'db_name', 'db_user', 'db_password'].forEach(function (name) {
                var field = form.querySelector('[name="' + name + '"]');
                if (field) data.append(name, field.value);
            });

            result.setAttribute('data-state', 'busy');
            result.textContent = 'Connecting…';
            testButton.disabled = true;

            fetch('/install/test-database', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' }
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                if (payload.ok) {
                    dbVerified = true;
                    result.setAttribute('data-state', 'ok');
                    result.textContent = payload.message + ' MySQL ' + payload.version + '.' +
                        (payload.tables > 0 ? ' The database already holds ' + payload.tables + ' table(s); existing tables are left alone.' : '');
                    if (continueButton) continueButton.disabled = false;
                } else {
                    dbVerified = false;
                    result.setAttribute('data-state', 'error');
                    result.textContent = payload.message || payload.error || 'The connection failed.';
                    if (continueButton) continueButton.disabled = true;
                }
            }).catch(function () {
                result.setAttribute('data-state', 'error');
                result.textContent = 'The test could not be run. Reload the page and try again.';
            }).finally(function () {
                testButton.disabled = false;
            });
        });
    }

    show(0);
})();
