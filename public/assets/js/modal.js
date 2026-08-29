/**
 * Confirmation dialogs.
 *
 * The browser's own confirm() is a modal from another product: it cannot be
 * styled, it names the site rather than the thing being deleted, and it looks
 * identical whether you are discarding a draft or deleting a year of history.
 * This replaces it everywhere with a dialog that belongs to this interface and
 * says what is about to happen.
 *
 * Mark up an action that needs confirming and it works — no per-page wiring:
 *
 *   data-confirm="Delete Company website?"          the question
 *   data-confirm-detail="Its history goes with it." the consequence, optional
 *   data-confirm-label="Delete monitor"             the button, optional
 *   data-confirm-tone="danger"                      red button, optional
 *
 * On a <form> it covers any submit; on a button it covers that button, which
 * is what lets one form have both a Save and a Delete.
 *
 * The dialog's confirm button takes its look — and its icon — from the button
 * that was pressed, so the thing you press to finish the job is the same thing
 * you pressed to start it.
 */
(function () {
    'use strict';

    var dialog = null;
    var resolve = null;
    var bypass = false;

    function build() {
        if (dialog) return dialog;

        dialog = document.createElement('dialog');
        dialog.className = 'modal';
        dialog.setAttribute('data-confirm-dialog', '');
        dialog.innerHTML =
            '<form method="dialog" class="modal__inner">' +
                '<h2 class="modal__title"></h2>' +
                '<p class="modal__detail"></p>' +
                '<div class="modal__actions">' +
                    '<button class="btn" value="cancel" autofocus>Cancel</button>' +
                    '<button class="btn btn--primary" value="confirm"></button>' +
                '</div>' +
            '</form>';

        // Esc, the backdrop and either button all end up here.
        dialog.addEventListener('close', function () {
            var confirmed = dialog.returnValue === 'confirm';
            var settle = resolve;
            resolve = null;
            if (settle) settle(confirmed);
        });

        // A click on the backdrop counts as cancelling.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.returnValue = 'cancel';
                dialog.close();
            }
        });

        document.body.appendChild(dialog);
        return dialog;
    }

    /**
     * Ask the question. Resolves true when the person confirms.
     * @returns {Promise<boolean>}
     */
    function ask(options) {
        var title = options.title || 'Are you sure?';
        var detail = options.detail || '';
        var label = options.label || 'Confirm';
        var danger = options.tone === 'danger';
        var icon = options.icon || null;

        var element = build();

        // No <dialog> support: better a plain browser prompt than none at all.
        if (typeof element.showModal !== 'function') {
            return Promise.resolve(window.confirm(detail ? title + '\n\n' + detail : title));
        }

        element.querySelector('.modal__title').textContent = title;

        var detailNode = element.querySelector('.modal__detail');
        detailNode.textContent = detail;
        detailNode.hidden = detail === '';

        var confirmButton = element.querySelector('[value="confirm"]');
        confirmButton.className = 'btn ' + (danger ? 'btn--danger' : 'btn--primary');
        confirmButton.textContent = '';
        if (icon) {
            confirmButton.appendChild(icon.cloneNode(true));
        }
        confirmButton.appendChild(document.createTextNode(label));

        element.returnValue = 'cancel';

        return new Promise(function (settle) {
            resolve = settle;
            element.showModal();
        });
    }

    /**
     * Read the question off whichever element carries it. The look comes from
     * the button that was pressed — which, for a form-level confirmation, is
     * not the same element the question is written on.
     */
    function optionsFrom(element, trigger) {
        var button = trigger || element;

        return {
            title: element.getAttribute('data-confirm'),
            detail: element.getAttribute('data-confirm-detail') || '',
            label: element.getAttribute('data-confirm-label') || 'Confirm',
            tone: element.getAttribute('data-confirm-tone')
                || (button.classList && button.classList.contains('btn--danger') ? 'danger' : ''),
            icon: button.querySelector ? button.querySelector('svg') : null
        };
    }

    /* ── Buttons and links ──────────────────────────────────────────── */

    document.addEventListener('click', function (event) {
        if (bypass) return;

        var trigger = event.target.closest('[data-confirm]');
        if (!trigger || trigger.tagName === 'FORM') return;

        event.preventDefault();
        // The form's own submit handler must not also fire.
        event.stopPropagation();

        ask(optionsFrom(trigger)).then(function (confirmed) {
            if (!confirmed) return;

            // Replaying the click keeps whatever the button carries —
            // formaction, name and value included.
            bypass = true;
            trigger.click();
            bypass = false;
        });
    }, true);

    /* ── Forms ──────────────────────────────────────────────────────── */

    document.addEventListener('submit', function (event) {
        if (bypass) return;

        var form = event.target;
        if (!form.hasAttribute('data-confirm')) return;

        // A submit button with its own question was handled on click.
        if (event.submitter && event.submitter.hasAttribute('data-confirm')) return;

        event.preventDefault();
        var submitter = event.submitter;

        ask(optionsFrom(form, submitter)).then(function (confirmed) {
            if (!confirmed) return;

            bypass = true;
            if (submitter) {
                submitter.click();
            } else if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
            bypass = false;
        });
    });

    window.MonitorConfirm = ask;
})();
