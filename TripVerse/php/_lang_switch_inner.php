<?php
/**
 * The language options only, without the wrapper element — for places that
 * need to supply their own container classes (e.g. the floating switcher on
 * the auth pages). Use _lang_switch.php when you want the wrapper too.
 */
require_once __DIR__ . '/_lang.php';
$tvCurrent = tv_lang();
foreach (TV_LANGS as $code => $label): ?>
    <a class="tv-lang-opt <?= $code === $tvCurrent ? 'is-active' : '' ?>"
       href="<?= tv_lang_url($code) ?>"
       hreflang="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
       title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
       <?= $code === $tvCurrent ? 'aria-current="true"' : '' ?>><?= strtoupper(htmlspecialchars($code, ENT_QUOTES, 'UTF-8')) ?></a>
<?php endforeach; ?>
