<?php
/**
 * What a machine has been asked to do, and how it went.
 *
 * Its own file because it is rendered from two places: once into the page, and
 * again into the answer the live log already asks for, so the panel can keep
 * up without the page being reloaded. One template, so the two cannot drift --
 * which they would, because the interesting part is the pill, the relative
 * time and the cancel form, and all three are easy to get subtly wrong twice.
 *
 * @var array<int,array<string,mixed>> $commands
 * @var string $uuid
 */

use App\Domain\DeviceCommands;
?>
<?php if ($commands === []): ?>
    <p class="muted mt-0"><?= e(t('device.commands_none_yet')) ?></p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--compact">
            <tbody>
                <?php foreach ($commands as $command): ?>
                    <tr>
                        <td>
                            <strong><?= e(DeviceCommands::label((string) $command['command'])) ?></strong>
                            <span class="muted block">
                                <?php // data-since keeps "4m ago" honest on a page left open, the
                                      // same way the machine lists do. ?>
                                <span data-since="<?= e((string) $command['requested_at']) ?>"><?= e(format_since((string) $command['requested_at'])) ?></span>
                                <?= empty($command['requested_by_name']) ? '' : ' · ' . e((string) $command['requested_by_name']) ?>
                            </span>
                            <?php if (!empty($command['error'])): ?>
                                <span class="muted block truncate" title="<?= e((string) $command['error']) ?>"><?= e((string) $command['error']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="table__right">
                            <?php $state = (string) $command['status']; ?>
                            <span class="pill pill--<?= $state === 'done' ? 'up' : ($state === 'failed' ? 'down' : ($state === 'queued' || $state === 'claimed' ? 'pending' : 'paused')) ?>">
                                <?= e($state) ?>
                            </span>
                            <?php if ($state === 'queued'): ?>
                                <form method="post" action="/devices/<?= e($uuid) ?>/commands/<?= (int) $command['id'] ?>/cancel">
                                    <?= csrf_field() ?>
                                    <button class="btn btn--sm btn--ghost" type="submit"><?= e(t('action.cancel')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
