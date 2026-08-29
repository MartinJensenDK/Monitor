<?php
/**
 * @var array<string,mixed>|null $monitor
 * @var array<string,mixed> $config
 * @var array<int,array<string,mixed>> $assignable
 * @var array<int,string> $assigned   group id => access
 */

use App\Domain\Monitors;

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
                    <select class="select" id="type" name="type" data-type-select>
                        <?php foreach (Monitors::TYPES as $type): ?>
                            <option value="<?= e($type) ?>"
                                    <?= $value('type', 'http') === $type ? 'selected' : '' ?>
                                    <?= in_array($type, Monitors::AVAILABLE_TYPES, true) ? '' : 'disabled' ?>>
                                <?= e(t('monitor.type_' . $type)) ?><?= in_array($type, Monitors::AVAILABLE_TYPES, true) ? '' : ' — ' . t('monitor.coming_soon') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field field--wide">
                    <label class="field__label" for="target"><?= e(t('monitor.target')) ?></label>
                    <input class="input input--mono" id="target" name="target" required
                           value="<?= e($value('target')) ?>" placeholder="https://example.com/health">
                    <span class="field__hint">The full address to request, including https://.</span>
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
                    <select class="select" id="interval_seconds" name="interval_seconds">
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

    <section class="panel" data-type-fields="http">
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

                <div class="field">
                    <label class="field__label" for="keyword">Keyword on the page</label>
                    <input class="input" id="keyword" name="keyword" value="<?= e($value('keyword')) ?>"
                           placeholder="Leave empty to skip">
                </div>

                <div class="field">
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

    <div class="form-actions">
        <a class="btn btn--ghost" href="<?= $isEdit ? '/monitors/' . (int) $monitor['id'] : '/monitors' ?>"><?= e(t('action.cancel')) ?></a>
        <div class="btn-row">
            <button class="btn btn--primary" type="submit">
                <?= icon('check') ?><?= $isEdit ? e(t('action.save')) : 'Create monitor' ?>
            </button>
        </div>
    </div>
</form>
