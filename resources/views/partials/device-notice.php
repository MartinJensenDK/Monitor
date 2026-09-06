<?php
/**
 * The inside of one standing notice.
 *
 * Rendered into the page, and again into the answer the live channel gives, so
 * the wording cannot come out one way on load and another way an hour later.
 *
 * @var array{kind:string,icon:string,text:string} $notice
 */
?>
<?= icon((string) $notice['icon'], 'icon icon--sm') ?><span><?= e((string) $notice['text']) ?></span>
