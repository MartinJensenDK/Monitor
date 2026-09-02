<?php
/**
 * A place, and where on Earth it is.
 *
 * @var array<string,mixed>|null $location
 * @var array<int,array<string,mixed>> $monitors
 */

use App\Core\View;
use App\Support\Icons;

$isEdit = $location !== null;
$action = $isEdit ? '/locations/' . (int) $location['id'] : '/locations';

$value = static function (string $key, string $default = '') use ($location): string {
    $old = old($key);
    if ($old !== '') {
        return $old;
    }
    if ($location !== null && $location[$key] !== null) {
        return (string) $location[$key];
    }

    return $default;
};

// DECIMAL(9,6) comes back as 55.676100. Six decimals is the room the column
// keeps, not something anyone typed, so the field shows what was meant.
$trim = static fn (string $number): string => str_contains($number, '.')
    ? rtrim(rtrim($number, '0'), '.')
    : $number;

// A new location starts on the map somewhere rather than nowhere: 0,0 is in
// the Atlantic and reads as a bug. Greenwich at 51.5 is a recognisable
// "you have not chosen yet".
$lat = $trim($value('latitude', '51.4779'));
$lng = $trim($value('longitude', '-0.0015'));
?>
<form method="post" action="<?= e($action) ?>" class="stack">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head">
            <h2><?= $isEdit ? 'Edit location' : 'What is this place called?' ?></h2>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div class="field field--wide field--narrow">
                    <label class="field__label" for="name"><?= e(t('location.name')) ?></label>
                    <input class="input" id="name" name="name" required maxlength="120"
                           value="<?= e($value('name')) ?>" placeholder="Copenhagen DC1">
                    <span class="field__hint"><?= e(t('location.name_hint')) ?></span>
                </div>

                <div class="field field--wide">
                    <label class="field__label" for="address"><?= e(t('location.address')) ?></label>
                    <input class="input" id="address" name="address" maxlength="255"
                           value="<?= e($value('address')) ?>" placeholder="Ejby Industrivej 34, 2600 Glostrup">
                    <span class="field__hint"><?= e(t('location.address_hint')) ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2><?= e(t('location.where_title')) ?></h2>
        </div>
        <div class="panel__body">
            <p class="field__hint" style="margin-bottom:12px;"><?= e(t('location.pick_hint')) ?></p>

            <?= View::partial('partials/world-map', [
                'pins' => [['id' => 0, 'name' => '', 'latitude' => (float) $lat, 'longitude' => (float) $lng]],
                'picker' => true,
                'label' => 'Click the map to place this location',
            ]) ?>

            <div class="form-grid" style="margin-top:14px;">
                <div class="field">
                    <label class="field__label" for="latitude"><?= e(t('location.latitude')) ?></label>
                    <input class="input num" id="latitude" name="latitude" required
                           inputmode="decimal" data-wmap-lat value="<?= e($lat) ?>">
                    <span class="field__hint">-90 to 90. North is positive.</span>
                </div>
                <div class="field">
                    <label class="field__label" for="longitude"><?= e(t('location.longitude')) ?></label>
                    <input class="input num" id="longitude" name="longitude" required
                           inputmode="decimal" data-wmap-lng value="<?= e($lng) ?>">
                    <span class="field__hint">-180 to 180. East is positive.</span>
                </div>
            </div>
        </div>
    </section>

    <?php if ($isEdit): ?>
        <section class="panel">
            <div class="panel__head">
                <h2><?= e(t('location.whats_here')) ?></h2>
            </div>
            <?php if ($monitors === []): ?>
                <div class="panel__body">
                    <p class="muted mt-0"><?= e(t('location.nothing_here')) ?></p>
                </div>
            <?php else: ?>
                <div class="rows">
                    <?php foreach ($monitors as $monitor): ?>
                        <div class="row" style="grid-template-columns: minmax(0,1fr) auto;">
                            <div class="truncate">
                                <span class="row__type"><?= icon(Icons::forMonitorType((string) $monitor['type'])) ?></span>
                                <a class="row__name truncate" href="/monitors/<?= (int) $monitor['id'] ?>">
                                    <?= e((string) $monitor['name']) ?>
                                </a>
                            </div>
                            <span class="pill pill--<?= e((string) ($monitor['status'] ?? 'pending')) ?>">
                                <?= e(t('status.' . (string) ($monitor['status'] ?? 'pending'))) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="form-actions">
        <a class="btn btn--ghost" href="/locations"><?= e(t('action.cancel')) ?></a>
        <div class="btn-row">
            <?php if ($isEdit): ?>
                <button class="btn btn--danger" type="submit" form="delete-location"><?= icon('trash') ?><?= e(t('action.delete')) ?></button>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit">
                <?= icon('check') ?><?= $isEdit ? e(t('action.save')) : 'Create location' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <form id="delete-location" method="post" action="/locations/<?= (int) $location['id'] ?>/delete"
          data-confirm="Delete <?= e((string) $location['name']) ?>?"
          data-confirm-detail="The monitors pinned here keep their history and their checks. They just leave the map."
          data-confirm-label="Delete location"
          data-confirm-tone="danger">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>
