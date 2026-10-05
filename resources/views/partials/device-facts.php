<?php
/**
 * What the machine is, as it last described itself.
 *
 * Sent by the live channel as well as painted into the page: a machine that
 * upgrades its kernel, changes address or replaces its own agent is describing
 * something else the moment it next reports, and none of that is worth a
 * reload to find out.
 *
 * @var array<string,mixed> $device
 */

use App\Core\View;
use App\Domain\UpdatePolicies;

// The update policy this machine follows, and when it next acts. Said only
// when there is one: "no policy" on every machine is noise.
$policyLine = null;
$policyId = (int) ($device['update_policy_id'] ?? 0);
if ($policyId > 0 && UpdatePolicies::isReady() && ($policy = UpdatePolicies::find($policyId)) !== null) {
    $tz = UpdatePolicies::timezone();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $dayNames = UpdatePolicies::dayNames();
    $next = [];
    if ((int) $policy['enabled'] === 1) {
        foreach (['check' => t('policy.check'), 'install' => t('policy.install')] as $half => $label) {
            if ((int) $policy[$half . '_enabled'] !== 1) {
                continue;
            }
            $at = UpdatePolicies::nextOccurrence((int) $policy[$half . '_days'], (string) $policy[$half . '_time'], $now, $tz);
            if ($at !== null) {
                $local = $at->setTimezone($tz);
                $next[] = $label . ' ' . $dayNames[(int) $local->format('N')] . ' ' . $local->format('H:i');
            }
        }
    }
    $policyLine = [
        'id' => $policyId,
        'name' => (string) $policy['name'],
        'detail' => (int) $policy['enabled'] === 1
            ? t('policy.next', ['when' => implode(', ', $next)])
            : t('policy.off'),
    ];
}

$facts = array_filter([
    t('device.operating_system') => trim((string) ($device['os_name'] ?? '') . ' ' . (string) ($device['os_version'] ?? '')),
    t('device.kernel') => (string) ($device['kernel'] ?? ''),
    t('device.architecture') => (string) ($device['arch'] ?? ''),
    t('device.processor') => trim((string) ($device['cpu_model'] ?? '') . ((int) ($device['cpu_cores'] ?? 0) > 0 ? ' · ' . $device['cpu_cores'] . ' cores' : '')),
    t('device.memory') => format_bytes($device['memory_bytes'] === null ? null : (int) $device['memory_bytes']),
    t('device.hardware') => trim((string) ($device['manufacturer'] ?? '') . ' ' . (string) ($device['model'] ?? '')),
    t('device.serial') => (string) ($device['serial_number'] ?? ''),
    t('device.virtualisation') => (string) ($device['virtualisation'] ?? ''),
    t('device.hostname') => (string) $device['hostname'],
    t('device.address') => (string) ($device['primary_ip'] ?? ''),
    t('device.seen_from') => (string) ($device['report_ip'] ?? ''),
], static fn ($value): bool => trim((string) $value) !== '' && $value !== '—');
?>
<dl class="facts">
    <?php foreach ($facts as $label => $value): ?>
        <dt><?= e((string) $label) ?></dt>
        <dd class="truncate" title="<?= e((string) $value) ?>"><?= e((string) $value) ?></dd>
    <?php endforeach; ?>
    <dt><?= e(t('device.agent')) ?></dt>
    <dd><?= View::partial('partials/device-agent', ['device' => $device]) ?></dd>
    <?php if ($policyLine !== null): ?>
        <dt><?= e(t('policy.follows')) ?></dt>
        <dd>
            <?php if (can('devices.command_changes')): ?>
                <a href="/devices/updates/<?= (int) $policyLine['id'] ?>"><?= e($policyLine['name']) ?></a>
            <?php else: ?>
                <?= e($policyLine['name']) ?>
            <?php endif; ?>
            <span class="muted block"><?= e($policyLine['detail']) ?></span>
        </dd>
    <?php endif; ?>
    <dt><?= e(t('device.enrolled')) ?></dt>
    <dd><?= e(local_time((string) $device['enrolled_at'], 'Y-m-d H:i')) ?></dd>
    <dt><?= e(t('device.token')) ?></dt>
    <dd class="num"><?= e((string) $device['token_hint']) ?>…</dd>
</dl>
<?php if (!empty($device['notes'])): ?>
    <p class="muted"><?= e((string) $device['notes']) ?></p>
<?php endif; ?>
