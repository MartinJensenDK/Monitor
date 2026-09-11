<?php
/**
 * The machines themselves: as cards, as a list, or the empty state that says
 * why there are none.
 *
 * Rendered into the page and again into /api/devices/list, so what a page
 * left open receives is built by the template that drew it. Each of the three
 * alternatives carries data-devlist-body on its own root, so the page can
 * replace whichever is showing with whichever arrives -- a filter that matched
 * nothing can start matching something while somebody watches.
 *
 * @var string $kind
 * @var array<int,array<string,mixed>> $devices
 * @var string $view
 * @var array<string,int> $summary
 * @var bool $hasKeys
 * @var array<string,string> $listQuery
 */

use App\Core\View;
use App\Domain\Devices;

$isServers = $kind === Devices::KIND_SERVER;
$base = $isServers ? '/servers' : '/clients';
$noun = $isServers ? t('device.servers_lower') : t('device.clients_lower');
// Where a command asked for from a row comes back to: this list, as it is.
$returnTo = $base . '?' . http_build_query($listQuery + ['view' => 'list']);
?>
<?php if ($devices === []): ?>
    <div class="empty" data-devlist-body>
        <?php if ($summary['total'] === 0 && !$hasKeys): ?>
            <h3><?= e(t('device.empty_title', ['kind' => $noun])) ?></h3>
            <p>
                A machine gets here by running a small agent that reports in over HTTPS.
                Nothing is opened on the machine and nothing is installed on this server.
                Make an enrolment key, then run one command on the machine.
            </p>
            <?php if (can('devices.enroll')): ?>
                <a class="btn btn--primary" href="/devices/enrollment"><?= icon('key') ?><?= e(t('action.make_key')) ?></a>
            <?php else: ?>
                <p class="muted">Ask an administrator for an enrolment key.</p>
            <?php endif; ?>
        <?php elseif ($summary['total'] === 0): ?>
            <h3><?= e(t('device.empty_title', ['kind' => $noun])) ?></h3>
            <p>
                There is an enrolment key ready. Run the installer on a machine and it will
                appear here within a minute.
            </p>
            <?php if (can('devices.enroll')): ?>
                <a class="btn" href="/devices/enrollment"><?= icon('terminal') ?><?= e(t('action.show_install')) ?></a>
            <?php endif; ?>
        <?php else: ?>
            <h3><?= e(t('device.no_match_title')) ?></h3>
            <p><?= e(t('device.no_match_body')) ?></p>
            <a class="btn" href="<?= e($base) ?>"><?= e(t('action.clear_filters')) ?></a>
        <?php endif; ?>
    </div>
<?php elseif ($view === 'list'): ?>
    <div class="devrows" data-devlist-body>
        <div class="devrow devrow--head">
            <span class="eyebrow"><?= e(t('device.machine')) ?></span>
            <span class="eyebrow"><?= e(t('device.status')) ?></span>
            <span class="eyebrow"><?= e(t('device.operating_system')) ?></span>
            <span class="eyebrow"><?= e(t('device.agent')) ?></span>
            <span class="eyebrow" style="text-align:right;"><?= e(t('device.cpu')) ?></span>
            <span class="eyebrow" style="text-align:right;"><?= e(t('device.memory')) ?></span>
            <span class="eyebrow" style="text-align:right;"><?= e(t('device.storage')) ?></span>
            <span class="eyebrow"><?= e(t('device.waiting_head')) ?></span>
            <span class="eyebrow" style="text-align:right;"><?= e(t('device.last_seen')) ?></span>
            <span></span>
        </div>
        <?php foreach ($devices as $device): ?>
            <?= View::partial('partials/device-row', ['device' => $device, 'returnTo' => $returnTo]) ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="panel__body" data-devlist-body>
        <div class="devgrid">
            <?php foreach ($devices as $device): ?>
                <?= View::partial('partials/device-card', ['device' => $device]) ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
