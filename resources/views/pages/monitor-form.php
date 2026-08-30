<?php
/**
 * @var array<string,mixed>|null $monitor
 * @var array<string,mixed> $config
 * @var array<int,array<string,mixed>> $assignable
 * @var array<int,string> $assigned   group id => access
 */

use App\Checks\ApiChecker;
use App\Checks\DnsChecker;
use App\Checks\DnsResolver;
use App\Checks\DomainChecker;
use App\Checks\EndpointChecker;
use App\Checks\KeywordChecker;
use App\Checks\PingChecker;
use App\Checks\PingTransport;
use App\Checks\SslChecker;
use App\Domain\Monitors;
use App\Notifications\Channels;

$isEdit = $monitor !== null;
$action = $isEdit ? '/monitors/' . (int) $monitor['id'] : '/monitors';

$value = static function (string $key, string $default = '') use ($monitor, $config): string {
    $old = old($key);
    if ($old !== '') {
        return $old;
    }
    if ($monitor !== null && array_key_exists($key, $monitor) && $monitor[$key] !== null) {
        return (string) $monitor[$key];
    }
    if (array_key_exists($key, $config) && $config[$key] !== null && !is_array($config[$key])) {
        return (string) $config[$key];
    }

    return $default;
};

$checked = static function (string $key, bool $default) use ($monitor, $config): bool {
    if ($monitor === null) {
        return $default;
    }
    if (array_key_exists($key, $monitor)) {
        return (bool) $monitor[$key];
    }

    return (bool) ($config[$key] ?? $default);
};

$headersText = '';
foreach ((array) ($config['headers'] ?? []) as $name => $headerValue) {
    $headersText .= $name . ': ' . $headerValue . "\n";
}

/** A list stored in config, shown one per line in a textarea. */
$lines = static function (string $key) use ($config): string {
    $old = old($key);
    if ($old !== '') {
        return $old;
    }

    $stored = $config[$key] ?? [];

    return implode("\n", is_array($stored) ? $stored : (preg_split('/\R/', (string) $stored) ?: []));
};

/** Config keys whose form field is named differently, so old() still wins. */
$setting = static function (string $field, string $key, string $default) use ($config): string {
    $old = old($field);
    if ($old !== '') {
        return $old;
    }

    return array_key_exists($key, $config) && !is_array($config[$key]) ? (string) $config[$key] : $default;
};

$steps = ApiChecker::steps($config);
if ($steps === []) {
    $steps = [['name' => '', 'method' => 'GET', 'path' => '', 'expected_status' => '200-299', 'headers' => [], 'body' => '', 'assertions' => [], 'captures' => []]];
}

/**
 * One step of an API check. Rendered for each saved step and once more into a
 * <template>, where __i__ stands in for the index the browser will assign.
 *
 * @param array<string,mixed> $step
 */
$renderStep = static function (string $index, array $step) use ($isEdit): string {
    ob_start();
    $stepHeaders = '';
    foreach ((array) ($step['headers'] ?? []) as $name => $value) {
        $stepHeaders .= $name . ': ' . $value . "\n";
    }

    $assertions = (array) ($step['assertions'] ?? []);
    if ($assertions === []) {
        $assertions = [['path' => '', 'operator' => 'equals', 'value' => '']];
    }
    $captures = (array) ($step['captures'] ?? []);
    if ($captures === []) {
        $captures = [['name' => '', 'path' => '']];
    }
    $n = 'step[' . $index . ']';
    ?>
    <fieldset class="fieldset step" data-api-step>
        <legend>
            <span data-step-number>Step</span>
            <button class="btn btn--ghost btn--icon btn--sm" type="button" data-remove-step
                    aria-label="Remove this step"><?= icon('trash') ?></button>
        </legend>

        <div class="form-grid">
            <div class="field">
                <label class="field__label">What it does</label>
                <input class="input" name="<?= $n ?>[name]" maxlength="60" placeholder="Sign in"
                       value="<?= e((string) ($step['name'] ?? '')) ?>">
                <span class="field__hint">Named so a failure says which call broke.</span>
            </div>

            <div class="field">
                <label class="field__label">Method</label>
                <select class="select" name="<?= $n ?>[method]">
                    <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method): ?>
                        <option value="<?= $method ?>" <?= ($step['method'] ?? 'GET') === $method ? 'selected' : '' ?>><?= $method ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field field--wide">
                <label class="field__label">Path</label>
                <input class="input input--mono" name="<?= $n ?>[path]" placeholder="/v1/session"
                       value="<?= e((string) ($step['path'] ?? '')) ?>">
                <span class="field__hint">Added to the base URL above. A full <code>https://…</code> address is used as it stands.</span>
            </div>

            <div class="field">
                <label class="field__label">Expected status</label>
                <input class="input input--mono" name="<?= $n ?>[expected_status]" placeholder="200-299"
                       value="<?= e((string) ($step['expected_status'] ?? '200-299')) ?>">
            </div>

            <div class="field field--wide">
                <label class="field__label">Headers</label>
                <textarea class="textarea textarea--mono" name="<?= $n ?>[headers]" rows="2"
                          placeholder="Authorization: Bearer {{token}}"><?= e($stepHeaders) ?></textarea>
            </div>

            <div class="field field--wide">
                <label class="field__label">Body</label>
                <textarea class="textarea textarea--mono" name="<?= $n ?>[body]" rows="2"
                          placeholder='{"user":"monitor"}'><?= e((string) ($step['body'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="rows" data-rows-group>
            <p class="field__label">What the answer has to say</p>
            <div class="assertions" data-rows>
                <?php foreach ($assertions as $assertion): ?>
                    <div class="assertion" data-row>
                        <input class="input input--mono" name="<?= $n ?>[assert_path][]" placeholder="status"
                               value="<?= e((string) ($assertion['path'] ?? '')) ?>" aria-label="Path">
                        <select class="select" name="<?= $n ?>[assert_operator][]" aria-label="Comparison">
                            <?php foreach (EndpointChecker::OPERATORS as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= ($assertion['operator'] ?? 'equals') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="input input--mono" name="<?= $n ?>[assert_value][]" placeholder="ok"
                               value="<?= e((string) ($assertion['value'] ?? '')) ?>" aria-label="Value">
                        <button class="btn btn--ghost btn--icon" type="button" data-remove-row
                                aria-label="Remove this assertion"><?= icon('trash') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-0" style="margin-top:8px;">
                <button class="btn btn--sm btn--ghost" type="button" data-add-row><?= icon('plus') ?>Add an assertion</button>
            </p>
        </div>

        <div class="rows" data-rows-group>
            <p class="field__label">Carry a value into the next step</p>
            <div class="assertions" data-rows>
                <?php foreach ($captures as $capture): ?>
                    <div class="assertion assertion--capture" data-row>
                        <input class="input input--mono" name="<?= $n ?>[capture_name][]" placeholder="token"
                               value="<?= e((string) ($capture['name'] ?? '')) ?>" aria-label="Name">
                        <input class="input input--mono" name="<?= $n ?>[capture_path][]" placeholder="data.access_token"
                               value="<?= e((string) ($capture['path'] ?? '')) ?>" aria-label="Path in the response">
                        <button class="btn btn--ghost btn--icon" type="button" data-remove-row
                                aria-label="Remove this capture"><?= icon('trash') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-0" style="margin-top:8px;">
                <button class="btn btn--sm btn--ghost" type="button" data-add-row><?= icon('plus') ?>Capture a value</button>
            </p>
        </div>
    </fieldset>
    <?php

    return (string) ob_get_clean();
};
?>
<form method="post" action="<?= e($action) ?>" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head">
            <h2><?= $isEdit ? 'Edit monitor' : 'What should we watch?' ?></h2>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="name"><?= e(t('monitor.name')) ?></label>
                    <input class="input" id="name" name="name" required maxlength="120"
                           value="<?= e($value('name')) ?>" placeholder="Company website">
                    <span class="field__hint">What you will recognise it by on the dashboard.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="type"><?= e(t('monitor.type')) ?></label>
                    <?php $availableTypes = Monitors::available(); ?>
                    <select class="select" id="type" name="type" data-type-select>
                        <?php foreach (Monitors::TYPES as $type): ?>
                            <?php $usable = in_array($type, $availableTypes, true); ?>
                            <option value="<?= e($type) ?>"
                                    <?= $value('type', 'http') === $type ? 'selected' : '' ?>
                                    <?= $usable ? '' : 'disabled' ?>>
                                <?= e(t('monitor.type_' . $type)) ?><?= $usable ? '' : ' — ' . t('monitor.needs_migration') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (count($availableTypes) < count(Monitors::TYPES)): ?>
                        <span class="field__hint">
                            The greyed-out types are waiting on a database update. Run
                            <code>php bin/migrate.php</code> on the server to switch them on.
                        </span>
                    <?php endif; ?>
                </div>

                <div class="field field--wide">
                    <label class="field__label" for="target" data-target-label><?= e(t('monitor.target')) ?></label>
                    <div class="inline" style="align-items:flex-start;">
                        <input class="input input--mono" id="target" name="target" required data-target-input
                               value="<?= e($value('target')) ?>" placeholder="https://example.com">
                        <span data-type-fields="port" style="width:110px;flex:none;" hidden>
                            <input class="input num" name="port" type="number" min="1" max="65535"
                                   value="<?= e($value('port')) ?>" placeholder="Port" aria-label="Port">
                        </span>
                    </div>
                    <span class="field__hint" data-target-hint>The full address to request, including https://.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>Schedule</h2></div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="interval_seconds"><?= e(t('monitor.interval')) ?></label>
                    <select class="select" id="interval_seconds" name="interval_seconds" data-interval-select
                            data-min-interval="<?= (int) Monitors::minimumInterval($value('type', 'http')) ?>">
                        <?php foreach (Monitors::INTERVALS as $seconds): ?>
                            <option value="<?= $seconds ?>" <?= (int) $value('interval_seconds', '60') === $seconds ? 'selected' : '' ?>>
                                <?= e(format_duration($seconds)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="timeout_seconds"><?= e(t('monitor.timeout')) ?></label>
                    <input class="input num" id="timeout_seconds" name="timeout_seconds" type="number" min="1" max="120"
                           value="<?= e($value('timeout_seconds', '10')) ?>">
                    <span class="field__hint">Seconds to wait before calling the check failed.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="retries"><?= e(t('monitor.retries')) ?></label>
                    <input class="input num" id="retries" name="retries" type="number" min="0" max="5"
                           value="<?= e($value('retries', '2')) ?>">
                    <span class="field__hint">A failure is retried this many times before an incident opens.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="degraded_ms"><?= e(t('monitor.degraded_ms')) ?></label>
                    <input class="input num" id="degraded_ms" name="degraded_ms" type="number" min="0" max="120000" step="50"
                           value="<?= e($value('degraded_ms', '0')) ?>">
                    <span class="field__hint">Milliseconds. Slower than this counts as degraded, not down. 0 turns it off.</span>
                </div>

                <div class="field field--wide">
                    <label class="check">
                        <input type="checkbox" name="enabled" value="1" <?= $checked('enabled', true) ? 'checked' : '' ?>>
                        <span class="check__text">
                            <?= e(t('monitor.enabled')) ?>
                            <small>Uncheck to keep the monitor and its history but stop checking.</small>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="http keyword endpoint">
        <div class="panel__head">
            <h2>What counts as healthy</h2>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="method">Request method</label>
                    <select class="select" id="method" name="method">
                        <?php foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method): ?>
                            <option value="<?= $method ?>" <?= $value('method', 'GET') === $method ? 'selected' : '' ?>><?= $method ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="expected_status">Expected status</label>
                    <input class="input input--mono" id="expected_status" name="expected_status"
                           value="<?= e($value('expected_status', '200-299')) ?>" placeholder="200-299">
                    <span class="field__hint">A code, a range, or a list: <code>200</code>, <code>200-299</code>, <code>200,301</code>.</span>
                </div>

                <div class="field" data-type-fields="http endpoint">
                    <label class="field__label" for="keyword">Keyword on the page</label>
                    <input class="input" id="keyword" name="keyword" value="<?= e($value('keyword')) ?>"
                           placeholder="Leave empty to skip">
                    <span class="field__hint">For several words or phrases at once, use a keyword monitor.</span>
                </div>

                <div class="field" data-type-fields="http endpoint">
                    <label class="check">
                        <input type="checkbox" name="keyword_absent" value="1" <?= !empty($config['keyword_absent']) ? 'checked' : '' ?>>
                        <span class="check__text">
                            Fail when the keyword <em>is</em> found
                            <small>Useful for catching an error page that still returns 200.</small>
                        </span>
                    </label>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="follow_redirects" value="1" <?= $checked('follow_redirects', true) ? 'checked' : '' ?>>
                        <span class="check__text">Follow redirects<small>Up to five hops.</small></span>
                    </label>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="verify_ssl" value="1" <?= $checked('verify_ssl', true) ? 'checked' : '' ?>>
                        <span class="check__text">Verify the TLS certificate<small>Turn off only for self-signed hosts.</small></span>
                    </label>
                </div>
            </div>

            <p style="margin-top:16px;">
                <button class="btn btn--ghost btn--sm" type="button" data-toggle="#advanced" aria-expanded="false">
                    <?= icon('chevron-down') ?> Request details
                </button>
            </p>

            <div id="advanced" hidden>
                <div class="form-grid">
                    <div class="field field--wide">
                        <label class="field__label" for="headers">Request headers</label>
                        <textarea class="textarea textarea--mono" id="headers" name="headers"
                                  placeholder="Authorization: Bearer …&#10;Accept: application/json"><?= e($headersText) ?></textarea>
                        <span class="field__hint">One per line, as <code>Name: value</code>.</span>
                    </div>

                    <div class="field field--wide">
                        <label class="field__label" for="body">Request body</label>
                        <textarea class="textarea textarea--mono" id="body" name="body" placeholder='{"ping":true}'><?= e($value('body')) ?></textarea>
                        <span class="field__hint">Sent with POST, PUT and PATCH.</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="auth_username">Basic auth user</label>
                        <input class="input" id="auth_username" name="auth_username" autocomplete="off"
                               value="<?= e($value('auth_username')) ?>">
                    </div>

                    <div class="field">
                        <label class="field__label" for="auth_password">Basic auth password</label>
                        <input class="input" id="auth_password" name="auth_password" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit && !empty($config['auth_password']) ? 'Unchanged' : '' ?>">
                        <span class="field__hint">Stored encrypted. Leave empty to keep the current one.</span>
                    </div>

                    <div class="field field--wide">
                        <label class="field__label" for="user_agent">User agent</label>
                        <input class="input input--mono" id="user_agent" name="user_agent" value="<?= e($value('user_agent')) ?>"
                               placeholder="Monitor/1.0 (+uptime monitor)">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="endpoint" hidden>
        <div class="panel__head">
            <h2>What the JSON has to say</h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:14px;">
                "HTTP 200" is a weak promise for an API. Read a value out of the response and say what it should be.
                Use dots to go deeper — <code>data.queue.depth</code> — and a number for a position in a list:
                <code>items.0.status</code>.
            </p>

            <div data-rows-group>
                <div class="assertions" data-rows>
                    <?php
                    $assertionRows = $config['assertions'] ?? [];
                    if ($assertionRows === []) {
                        $assertionRows = [['path' => '', 'operator' => 'equals', 'value' => '']];
                    }
                    ?>
                    <?php foreach ($assertionRows as $row): ?>
                        <div class="assertion" data-row>
                            <input class="input input--mono" name="assert_path[]" placeholder="status"
                                   value="<?= e((string) ($row['path'] ?? '')) ?>" aria-label="Path">
                            <select class="select" name="assert_operator[]" aria-label="Comparison">
                                <?php foreach (EndpointChecker::OPERATORS as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= ($row['operator'] ?? 'equals') === $key ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input class="input input--mono" name="assert_value[]" placeholder="ok"
                                   value="<?= e((string) ($row['value'] ?? '')) ?>" aria-label="Value">
                            <button class="btn btn--ghost btn--icon" type="button" data-remove-row
                                    aria-label="Remove this assertion"><?= icon('trash') ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top:12px;">
                    <button class="btn btn--sm" type="button" data-add-row><?= icon('plus') ?>Add an assertion</button>
                </p>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="keyword" hidden>
        <div class="panel__head">
            <h2>The wording that has to be there</h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:14px;">
                A page that answers 200 while it says "We'll be right back" is down to everyone looking at it.
                Give the check the words that prove the page is really working — a price, a product name, the
                heading of the section that comes from the database.
            </p>

            <div class="form-grid">
                <div class="field field--wide">
                    <label class="field__label" for="keywords">Words and phrases</label>
                    <textarea class="textarea" id="keywords" name="keywords" rows="4"
                              placeholder="Add to basket&#10;Free delivery over 500 kr."><?= e($lines('keywords')) ?></textarea>
                    <span class="field__hint">One per line. A phrase may contain spaces and punctuation.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="keyword_mode">How many must match</label>
                    <select class="select" id="keyword_mode" name="keyword_mode">
                        <?php foreach (KeywordChecker::MODES as $mode => $label): ?>
                            <option value="<?= e($mode) ?>" <?= $setting('keyword_mode', 'keyword_mode', 'all') === $mode ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">"None of them" catches the error page instead of the working one.</span>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="strip_html" value="1" <?= $checked('strip_html', true) ? 'checked' : '' ?>>
                        <span class="check__text">
                            Read the page as a visitor sees it
                            <small>Ignores the markup, so a phrase split across tags still counts.</small>
                        </span>
                    </label>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="case_sensitive" value="1" <?= $checked('case_sensitive', false) ? 'checked' : '' ?>>
                        <span class="check__text">Match upper and lower case exactly</span>
                    </label>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="api" hidden>
        <div class="panel__head">
            <h2>The sequence of calls</h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:14px;">
                Most APIs cannot be checked with one request: you sign in, you are handed a token, and only then
                can you call the thing that matters. Each step runs in order against the base URL above. Capture a
                value out of one answer and write it into a later step as <code>{{name}}</code> — in the path, in a
                header, or in the body. The timeout is the budget for the whole sequence.
            </p>

            <div class="stack" data-api-steps>
                <?php foreach ($steps as $index => $step): ?>
                    <?= $renderStep((string) $index, $step) ?>
                <?php endforeach; ?>
            </div>

            <template data-step-template><?= $renderStep('__i__', ['name' => '', 'method' => 'GET', 'path' => '', 'expected_status' => '200-299', 'headers' => [], 'body' => '', 'assertions' => [], 'captures' => []]) ?></template>

            <p style="margin-top:14px;">
                <button class="btn btn--sm" type="button" data-add-step><?= icon('plus') ?>Add a step</button>
                <span class="field__hint" style="margin-left:10px;">Up to <?= ApiChecker::MAX_STEPS ?> steps.</span>
            </p>

            <div class="form-grid" style="margin-top:18px;">
                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="follow_redirects" value="1" <?= $checked('follow_redirects', true) ? 'checked' : '' ?>>
                        <span class="check__text">Follow redirects</span>
                    </label>
                </div>
                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="verify_ssl" value="1" <?= $checked('verify_ssl', true) ? 'checked' : '' ?>>
                        <span class="check__text">Verify the TLS certificate</span>
                    </label>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="ssl" hidden>
        <div class="panel__head">
            <h2>The certificate and its clock</h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:14px;">
                A website check notices a certificate once it has already broken the site. This one watches the
                weeks before that, and works on hosts that serve no website at all — a mail or database server on
                its own port.
            </p>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="ssl_port">Port</label>
                    <input class="input num" id="ssl_port" name="ssl_port" type="number" min="1" max="65535"
                           value="<?= e($setting('ssl_port', 'port', (string) SslChecker::DEFAULT_PORT)) ?>">
                    <span class="field__hint">443 for a website, 993 for IMAP, 587 for submission.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="sni_host">Name to ask for <span class="muted">(optional)</span></label>
                    <input class="input input--mono" id="sni_host" name="sni_host" value="<?= e($value('sni_host')) ?>"
                           placeholder="Same as the host">
                    <span class="field__hint">Set this when one address serves certificates for several names.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="warn_days">Warn this many days ahead</label>
                    <input class="input num" id="warn_days" name="warn_days" type="number" min="0" max="365"
                           value="<?= e($value('warn_days', (string) SslChecker::DEFAULT_WARN_DAYS)) ?>">
                    <span class="field__hint">The monitor turns amber, and stays up.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="critical_days">Fail this many days ahead</label>
                    <input class="input num" id="critical_days" name="critical_days" type="number" min="0" max="365"
                           value="<?= e($value('critical_days', (string) SslChecker::DEFAULT_CRITICAL_DAYS)) ?>">
                    <span class="field__hint">Last call: the monitor goes down while there is still time to renew.</span>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="verify_chain" value="1" <?= $checked('verify_chain', true) ? 'checked' : '' ?>>
                        <span class="check__text">
                            The chain must be trusted
                            <small>Catches a missing intermediate, which breaks some clients and not others.</small>
                        </span>
                    </label>
                </div>

                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="check_hostname" value="1" <?= $checked('check_hostname', true) ? 'checked' : '' ?>>
                        <span class="check__text">The certificate must cover this host</span>
                    </label>
                </div>

                <div class="field field--wide">
                    <label class="field__label" for="expected_issuer">Expected issuer <span class="muted">(optional)</span></label>
                    <input class="input" id="expected_issuer" name="expected_issuer" value="<?= e($value('expected_issuer')) ?>"
                           placeholder="Let's Encrypt">
                    <span class="field__hint">Fail if the certificate is suddenly signed by someone else.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="domain" hidden>
        <div class="panel__head">
            <h2>The registration</h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:14px;">
                The outage nobody sees coming: the site is fine, the certificate is fine, and the domain lapses
                because the renewal notice went to an inbox that no longer exists. Monitor reads the registry
                over RDAP, and over WHOIS for the registries that publish no RDAP — <code>.dk</code> among them.
            </p>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="domain_warn_days">Warn this many days ahead</label>
                    <input class="input num" id="domain_warn_days" name="warn_days" type="number" min="0" max="365"
                           value="<?= e($value('warn_days', (string) DomainChecker::DEFAULT_WARN_DAYS)) ?>">
                </div>

                <div class="field">
                    <label class="field__label" for="domain_critical_days">Fail this many days ahead</label>
                    <input class="input num" id="domain_critical_days" name="critical_days" type="number" min="0" max="365"
                           value="<?= e($value('critical_days', (string) DomainChecker::DEFAULT_CRITICAL_DAYS)) ?>">
                </div>

                <div class="field field--wide">
                    <label class="check">
                        <input type="checkbox" name="watch_status" value="1" <?= $checked('watch_status', true) ? 'checked' : '' ?>>
                        <span class="check__text">
                            Fail on a registry hold
                            <small>clientHold and serverHold stop a domain resolving hours before it expires.</small>
                        </span>
                    </label>
                </div>

                <div class="field">
                    <label class="field__label" for="expected_registrar">Expected registrar <span class="muted">(optional)</span></label>
                    <input class="input" id="expected_registrar" name="expected_registrar"
                           value="<?= e($value('expected_registrar')) ?>" placeholder="Ascio">
                    <span class="field__hint">A transfer you did not start is worth hearing about.</span>
                </div>

                <div class="field">
                    <label class="field__label" for="expected_nameservers">Expected nameservers <span class="muted">(optional)</span></label>
                    <textarea class="textarea textarea--mono" id="expected_nameservers" name="expected_nameservers" rows="3"
                              placeholder="ns1.example.net&#10;ns2.example.net"><?= e($lines('expected_nameservers')) ?></textarea>
                    <span class="field__hint">One per line. Each must still be in the registry's delegation.</span>
                </div>
            </div>

            <p class="field__hint" style="margin-top:14px;">
                Registries rate-limit lookups, so a domain is checked once an hour at most. Once a day is plenty.
            </p>
        </div>
    </section>

    <section class="panel" data-type-fields="dns" hidden>
        <div class="panel__head">
            <h2>The record, and who agrees on it</h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:14px;">
                Two questions: does the name still resolve, and does it still resolve to what you published?
                Name more than one resolver and Monitor also reports when they disagree — a record mid-propagation,
                or one resolver serving something stale.
            </p>

            <div class="form-grid">
                <div class="field">
                    <label class="field__label" for="record_type">Record type</label>
                    <select class="select" id="record_type" name="record_type">
                        <?php foreach (array_keys(DnsResolver::TYPES) as $recordType): ?>
                            <option value="<?= e($recordType) ?>" <?= $setting('record_type', 'record_type', 'A') === $recordType ? 'selected' : '' ?>>
                                <?= e($recordType) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="dns_mode">The expected values</label>
                    <select class="select" id="dns_mode" name="dns_mode">
                        <?php foreach (DnsChecker::MODES as $mode => $label): ?>
                            <option value="<?= e($mode) ?>" <?= $setting('dns_mode', 'dns_mode', 'contains') === $mode ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="expected_records">Expected values <span class="muted">(optional)</span></label>
                    <textarea class="textarea textarea--mono" id="expected_records" name="expected_records" rows="4"
                              placeholder="77.213.93.14"><?= e($lines('expected_records')) ?></textarea>
                    <span class="field__hint">
                        One per line. Leave empty to only require that the record exists. A value matches if it is
                        contained in the answer, so an MX matches on the host name alone.
                    </span>
                </div>

                <div class="field">
                    <label class="field__label" for="resolvers">Resolvers to ask <span class="muted">(optional)</span></label>
                    <textarea class="textarea textarea--mono" id="resolvers" name="resolvers" rows="4"
                              placeholder="1.1.1.1&#10;8.8.8.8"><?= e($lines('resolvers')) ?></textarea>
                    <span class="field__hint">
                        IP addresses, one per line. Empty asks this server's own resolver
                        (<?= e(implode(', ', array_slice(DnsResolver::systemResolvers(), 0, 2))) ?>).
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel" data-type-fields="ping" hidden>
        <div class="panel__head">
            <h2>How the ping is sent</h2>
        </div>
        <div class="panel__body">
            <p class="flash flash--<?= PingTransport::isIcmp($pingTransport) ? 'success' : 'warning' ?>" style="margin-bottom:16px;">
                <?= icon(PingTransport::isIcmp($pingTransport) ? 'check' : 'alert') ?>
                <span><?= e(PingTransport::describe($pingTransport)) ?></span>
            </p>

            <?php if (!PingTransport::isIcmp($pingTransport)): ?>
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="fallback_port">Fallback port</label>
                        <input class="input num" id="fallback_port" name="fallback_port" type="number" min="1" max="65535"
                               value="<?= e($value('fallback_port', (string) PingChecker::DEFAULT_FALLBACK_PORT)) ?>">
                        <span class="field__hint">The port to time a connection to. 443 or 80 suit most public hosts.</span>
                    </div>
                </div>
                <p class="field__hint" style="margin-top:12px;">
                    To send real ICMP instead, a server administrator runs
                    <code><?= e(PingTransport::enableHint()) ?></code> once. Monitor re-checks daily.
                </p>
            <?php else: ?>
                <input type="hidden" name="fallback_port"
                       value="<?= e($value('fallback_port', (string) PingChecker::DEFAULT_FALLBACK_PORT)) ?>">
            <?php endif; ?>
        </div>
    </section>

    <section class="panel" data-type-fields="port" hidden>
        <div class="panel__head">
            <h2>What the port should answer</h2>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field field--wide">
                    <label class="field__label" for="banner">Expected greeting <span class="muted">(optional)</span></label>
                    <input class="input input--mono" id="banner" name="banner" value="<?= e($value('banner')) ?>"
                           placeholder="SSH-2.0">
                    <span class="field__hint">
                        Some services announce themselves on connect. Fill this in and the check also fails when the
                        greeting changes — an open port is not always a working service.
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2>Who can see it</h2>
        </div>
        <div class="panel__body">
            <?php if ($assignable === []): ?>
                <p class="muted mt-0">
                    You are not in any group yet, so there is nobody to share this with.
                    <?= can('groups.manage') ? '<a href="/groups/new">Create a group</a> first.' : 'Ask an administrator to add you to a group.' ?>
                </p>
            <?php else: ?>
                <p class="field__hint" style="margin-bottom:12px;">
                    Groups decide who sees this monitor. <strong>Can edit</strong> also lets the group change it and
                    acknowledge its incidents — within what their role allows.
                </p>
                <div class="access">
                    <?php foreach ($assignable as $group): ?>
                        <?php $current = old('group_access_' . (int) $group['id']) ?: ($assigned[(int) $group['id']] ?? 'none'); ?>
                        <div class="access__row">
                            <div>
                                <span class="access__name">
                                    <?= ($group['source'] ?? 'local') === 'entra' ? icon('lock', 'icon') : '' ?>
                                    <?= e((string) $group['name']) ?>
                                </span>
                                <?php if (!empty($group['description'])): ?>
                                    <span class="access__meta"><?= e((string) $group['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="seg">
                                <?php foreach (['none' => t('access.none'), 'view' => t('access.view'), 'edit' => t('access.edit')] as $level => $label): ?>
                                    <label>
                                        <input class="visually-hidden" type="radio"
                                               name="group_access[<?= (int) $group['id'] ?>]"
                                               value="<?= e($level) ?>" <?= $current === $level ? 'checked' : '' ?>>
                                        <span class="btn btn--sm btn--ghost"><?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2>Who gets told</h2>
            <?php if (can('settings.manage')): ?>
                <a class="btn btn--sm" style="margin-left:auto;" href="/settings/channels"><?= icon('mail') ?>Manage channels</a>
            <?php endif; ?>
        </div>
        <div class="panel__body">
            <?php if ($channels === []): ?>
                <p class="muted mt-0">
                    No notification channels yet.
                    <?= can('settings.manage')
                        ? '<a href="/settings/channels">Add one</a> to start receiving email about this monitor.'
                        : 'Ask an administrator to add one.' ?>
                </p>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($channels as $channel): ?>
                        <?php
                        $channelId = (int) $channel['id'];
                        $rule = $rules[$channelId] ?? ($isEdit ? null : Channels::DEFAULT_RULES);
                        $on = $rule !== null;
                        $rule ??= Channels::DEFAULT_RULES;
                        $clock = static fn (?string $v): string => $v === null ? '' : substr($v, 0, 5);
                        ?>
                        <fieldset class="fieldset">
                            <legend><?= e((string) $channel['name']) ?></legend>

                            <label class="check">
                                <input type="checkbox" name="notify[<?= $channelId ?>][enabled]" value="1"
                                       <?= $on ? 'checked' : '' ?>
                                       data-channel-toggle="#channel-<?= $channelId ?>">
                                <span class="check__text">
                                    Send email to this channel
                                    <small><?= e(implode(', ', $channel['recipients']) ?: 'No recipients yet') ?></small>
                                </span>
                            </label>

                            <div id="channel-<?= $channelId ?>" style="margin-top:14px;" hidden>
                                <div class="form-grid">
                                    <label class="check">
                                        <input type="checkbox" name="notify[<?= $channelId ?>][notify_down]" value="1"
                                               <?= (int) $rule['notify_down'] === 1 ? 'checked' : '' ?>>
                                        <span class="check__text">When it goes down</span>
                                    </label>
                                    <label class="check">
                                        <input type="checkbox" name="notify[<?= $channelId ?>][notify_up]" value="1"
                                               <?= (int) $rule['notify_up'] === 1 ? 'checked' : '' ?>>
                                        <span class="check__text">When it recovers</span>
                                    </label>
                                    <label class="check">
                                        <input type="checkbox" name="notify[<?= $channelId ?>][notify_degraded]" value="1"
                                               <?= (int) $rule['notify_degraded'] === 1 ? 'checked' : '' ?>>
                                        <span class="check__text">When it turns slow<small>Needs a degraded threshold.</small></span>
                                    </label>
                                    <label class="check">
                                        <input type="checkbox" name="notify[<?= $channelId ?>][notify_cert_expiry]" value="1"
                                               <?= (int) $rule['notify_cert_expiry'] === 1 ? 'checked' : '' ?>>
                                        <span class="check__text">
                                            Before it expires
                                            <small>The TLS certificate, or the registration on a domain monitor.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="form-grid" style="margin-top:14px;">
                                    <div class="field">
                                        <label class="field__label">Wait for this many failures</label>
                                        <input class="input num" type="number" min="1" max="20"
                                               name="notify[<?= $channelId ?>][failure_threshold]"
                                               value="<?= (int) $rule['failure_threshold'] ?>">
                                        <span class="field__hint">Counted after retries, so 1 is the first confirmed failure.</span>
                                    </div>

                                    <div class="field">
                                        <label class="field__label">Repeat every</label>
                                        <input class="input num" type="number" min="0" max="1440" step="5"
                                               name="notify[<?= $channelId ?>][resend_after_minutes]"
                                               value="<?= (int) $rule['resend_after_minutes'] ?>">
                                        <span class="field__hint">Minutes, while it stays down. 0 sends once.</span>
                                    </div>

                                    <div class="field">
                                        <label class="field__label">Certificate warning</label>
                                        <input class="input num" type="number" min="1" max="90"
                                               name="notify[<?= $channelId ?>][cert_expiry_days]"
                                               value="<?= (int) $rule['cert_expiry_days'] ?>">
                                        <span class="field__hint">Days of notice before expiry.</span>
                                    </div>

                                    <div class="field">
                                        <label class="field__label">Stay quiet between</label>
                                        <div class="inline">
                                            <input class="input num" type="time"
                                                   name="notify[<?= $channelId ?>][quiet_hours_start]"
                                                   value="<?= e($clock($rule['quiet_hours_start'] ?? null)) ?>">
                                            <span class="muted">and</span>
                                            <input class="input num" type="time"
                                                   name="notify[<?= $channelId ?>][quiet_hours_end]"
                                                   value="<?= e($clock($rule['quiet_hours_end'] ?? null)) ?>">
                                        </div>
                                        <span class="field__hint">Site time. Leave empty to be told at any hour.</span>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn btn--ghost" href="<?= $isEdit ? '/monitors/' . (int) $monitor['id'] : '/monitors' ?>"><?= e(t('action.cancel')) ?></a>
        <div class="btn-row">
            <button class="btn btn--primary" type="submit">
                <?= icon('check') ?><?= $isEdit ? e(t('action.save')) : 'Create monitor' ?>
            </button>
        </div>
    </div>
</form>
