<?php
/**
 * Language switcher with its default wrapper. Include in a navbar:
 *     <?php include __DIR__ . '/_lang_switch.php'; ?>
 */
require_once __DIR__ . '/_lang.php';
?>
<div class="tv-lang" role="group" aria-label="<?= te('Bahasa') ?>">
    <?php include __DIR__ . '/_lang_switch_inner.php'; ?>
</div>
