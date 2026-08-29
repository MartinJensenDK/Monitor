<?php
/** @var array<int,array<string,mixed>> $entries */
?>
<section class="panel">
    <div class="panel__head">
        <div>
            <p class="eyebrow">Audit</p>
            <h2>Who changed what</h2>
        </div>
    </div>

    <?php if ($entries === []): ?>
        <div class="empty"><h3>Nothing logged yet</h3><p>Changes to monitors, people, groups and settings show up here.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th><th>From</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td class="num nowrap"><?= e(local_time((string) $entry['created_at'], 'M j, H:i:s')) ?></td>
                            <td><?= e((string) ($entry['user_label'] ?? 'system')) ?></td>
                            <td><span class="pill pill--plain"><?= e((string) $entry['action']) ?></span></td>
                            <td class="muted"><?= e((string) ($entry['summary'] ?? '')) ?></td>
                            <td class="num muted"><?= e((string) ($entry['ip'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
