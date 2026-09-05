/**
 * Three small jobs on the machine pages.
 *
 * Copying the install command, because the alternative is somebody
 * hand-transcribing a 48-character key; keeping "4m ago" honest on a list that
 * is often left open, which needs no network at all -- the timestamp is
 * already in the page; and the live log, which is the one that does.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------- copying ---

    function flash(button, message) {
        var label = button.getAttribute('data-copy-label');
        if (label === null) {
            button.setAttribute('data-copy-label', button.textContent.trim());
        }

        var original = button.innerHTML;
        button.textContent = message;
        button.disabled = true;

        window.setTimeout(function () {
            button.innerHTML = original;
            button.disabled = false;
        }, 1600);
    }

    /**
     * The clipboard API needs a secure context, which a self-hosted install on
     * plain HTTP is not. The old selection dance is the fallback, so the button
     * works either way rather than doing nothing on half the installs.
     */
    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            var worked = false;
            try {
                worked = document.execCommand('copy');
            } catch (error) {
                worked = false;
            }
            document.body.removeChild(area);

            if (worked) resolve(); else reject();
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (button === null) return;

        event.preventDefault();
        copy(button.getAttribute('data-copy')).then(
            function () { flash(button, 'Copied'); },
            function () { flash(button, 'Select it and copy'); }
        );
    });

    // ------------------------------------------------------------ freshness ---

    function ago(seconds) {
        if (seconds < 10) return 'just now';
        if (seconds < 60) return seconds + 's ago';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) {
            return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm ago';
        }
        return Math.floor(seconds / 86400) + 'd ago';
    }

    function refresh() {
        var now = Date.now();

        document.querySelectorAll('[data-since]').forEach(function (element) {
            var stamp = element.getAttribute('data-since');
            if (!stamp) return;

            // The server writes UTC without saying so, which every browser
            // would otherwise read as local time.
            var at = Date.parse(stamp.replace(' ', 'T') + 'Z');
            if (isNaN(at)) return;

            element.textContent = ago(Math.max(0, Math.round((now - at) / 1000)));
        });
    }

    if (document.querySelector('[data-since]')) {
        refresh();
        window.setInterval(refresh, 30000);
    }

    // ------------------------------------------------------------ the log ---
    //
    // What the agent is saying, while it says it. The panel is painted with
    // the tail of the log and a cursor; from there this asks only for what
    // comes after that cursor, so a page left open all afternoon costs one
    // small empty answer every twenty seconds.

    var BUSY_MS = 2000;
    var IDLE_MS = 20000;
    var MAX_LINES = 600;

    function startLog(panel) {
        var list = panel.querySelector('[data-log-lines]');
        var empty = panel.querySelector('.log__empty');
        var live = panel.querySelector('.log__live');
        var uuid = panel.getAttribute('data-log');
        var since = parseInt(panel.getAttribute('data-log-since'), 10) || 0;
        var busy = panel.getAttribute('data-log-busy') === '1';
        var timer = null;
        var failures = 0;

        // Only follow the tail if the reader is already at it. Someone who has
        // scrolled up to read something is reading it, and yanking them back
        // down every two seconds would make that impossible.
        function pinned() {
            return list.scrollTop + list.clientHeight >= list.scrollHeight - 24;
        }

        function append(lines) {
            var follow = pinned();

            lines.forEach(function (line) {
                var item = document.createElement('li');
                item.className = 'log__line log__line--' + line.level + ' log__line--new';

                var at = document.createElement('span');
                at.className = 'log__at';
                at.textContent = line.at;

                var text = document.createElement('span');
                text.className = 'log__text';
                text.textContent = line.message;

                item.appendChild(at);
                item.appendChild(text);
                list.appendChild(item);
            });

            while (list.children.length > MAX_LINES) {
                list.removeChild(list.firstElementChild);
            }

            if (empty) empty.hidden = true;
            if (follow) list.scrollTop = list.scrollHeight;
        }

        function schedule() {
            window.clearTimeout(timer);

            // A hidden tab is not being read. Nothing is lost by waiting: the
            // cursor picks up wherever it left off.
            if (document.hidden) return;

            var wait = busy ? BUSY_MS : IDLE_MS;
            if (failures > 0) wait = Math.min(wait * Math.pow(2, failures), 120000);

            timer = window.setTimeout(poll, wait);
        }

        function poll() {
            window.clearTimeout(timer);

            fetch('/api/devices/' + encodeURIComponent(uuid) + '/logs?since=' + since, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) throw new Error(response.status);
                return response.json();
            }).then(function (data) {
                failures = 0;
                if (data.lines && data.lines.length) append(data.lines);
                since = data.last || since;
                busy = !!data.busy;
                if (live) live.hidden = !busy;
                schedule();
            }).catch(function () {
                failures++;
                schedule();
            });
        }

        if (live) live.hidden = !busy;
        list.scrollTop = list.scrollHeight;

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) poll();
        });

        schedule();
    }

    document.querySelectorAll('[data-log]').forEach(startLog);
}());
