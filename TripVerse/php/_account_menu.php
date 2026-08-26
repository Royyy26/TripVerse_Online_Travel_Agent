<?php
/**
 * Shared account menu for the customer navbar.
 *
 * Every customer page previously rendered its own variant of this control:
 * some had a full avatar + name + email dropdown, most had a plain text
 * greeting that could not be clicked at all, and one had nothing. This is
 * the single component they all use now.
 *
 * Expects an active session. Pulls the display name / e-mail / photo itself
 * so a page only needs: <?php include __DIR__ . '/_account_menu.php'; ?>
 */

require_once __DIR__ . '/_lang.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_user'])) {
    return;
}

// Session values are the baseline, so the control still renders correctly
// even if the database is unreachable.
$tvAccountName  = $_SESSION['username'] ?? 'Akun Saya';
$tvAccountEmail = $_SESSION['email'] ?? '';
$tvAccountPhoto = '';

if (!function_exists('tv_fetch_account_row')) {
    /**
     * Read the display fields for one user, returning null on any failure.
     *
     * Pages include this file at very different points in their lifecycle --
     * payment.php, for example, has already called $conn->close() by the time
     * the navbar renders -- so every failure mode (closed handle, dropped
     * connection, bad credentials) has to degrade quietly instead of throwing.
     */
    function tv_fetch_account_row($db, $idUser)
    {
        try {
            $stmt = $db->prepare(
                "SELECT first_name, last_name, username, email, profile_picture
                 FROM user WHERE id_user = ? LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('s', $idUser);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

$tvRow = null;

// Prefer a connection the including page already opened.
if (isset($conn) && $conn instanceof mysqli) {
    $tvRow = tv_fetch_account_row($conn, $_SESSION['id_user']);
}

// Otherwise (or if that handle was already closed) open a short-lived one.
if ($tvRow === null) {
    try {
        $tvHadConn = isset($conn) && $conn instanceof mysqli;
        require_once __DIR__ . '/db_config.php';
        $tvRow = tv_fetch_account_row($conn, $_SESSION['id_user']);
        if (!$tvHadConn) {
            $conn->close();
        }
    } catch (\Throwable $e) {
        $tvRow = null;
    }
}

if ($tvRow) {
    $tvFull = trim(($tvRow['first_name'] ?? '') . ' ' . ($tvRow['last_name'] ?? ''));
    $tvAccountName  = $tvFull !== '' ? $tvFull : ($tvRow['username'] ?? $tvAccountName);
    $tvAccountEmail = $tvRow['email'] ?? $tvAccountEmail;
    if (!empty($tvRow['profile_picture'])) {
        $tvAccountPhoto = '../uploads/' . basename($tvRow['profile_picture']);
    }
}

if ($tvAccountPhoto === '') {
    $tvAccountPhoto = '../images/default.jpg';
}

$tvInitial = mb_strtoupper(mb_substr(trim($tvAccountName), 0, 1));
?>
<?php
/*
 * The menu is driven by js/tv-modern.js rather than Bootstrap's dropdown
 * data-API: these pages load jQuery 3.4 and a Bootstrap-4 era plugin
 * (tempusdominus) alongside Bootstrap 5, and the competing handlers
 * toggled the menu twice on every click, so it never stayed open.
 */
?>
<div class="tv-account" data-tv-account>
    <button class="tv-account-toggle" type="button" id="tvAccountToggle"
        aria-expanded="false" aria-haspopup="true" aria-controls="tvAccountMenu"
        aria-label="<?= te('Menu akun') ?>">
        <span class="tv-account-avatar">
            <img src="<?= htmlspecialchars($tvAccountPhoto, ENT_QUOTES, 'UTF-8') ?>"
                alt="" onerror="this.style.display='none'">
            <span class="tv-account-initial"><?= htmlspecialchars($tvInitial, ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <span class="tv-account-meta">
            <span class="tv-account-name"><?= htmlspecialchars($tvAccountName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="tv-account-email"><?= htmlspecialchars($tvAccountEmail, ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <i class="fas fa-chevron-down tv-account-caret" aria-hidden="true"></i>
    </button>

    <ul class="tv-account-menu" id="tvAccountMenu" role="menu" aria-labelledby="tvAccountToggle">
        <li class="tv-account-head">
            <span class="tv-account-avatar tv-account-avatar-lg">
                <img src="<?= htmlspecialchars($tvAccountPhoto, ENT_QUOTES, 'UTF-8') ?>"
                    alt="" onerror="this.style.display='none'">
                <span class="tv-account-initial"><?= htmlspecialchars($tvInitial, ENT_QUOTES, 'UTF-8') ?></span>
            </span>
            <span class="tv-account-head-text">
                <span class="tv-account-name"><?= htmlspecialchars($tvAccountName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="tv-account-email"><?= htmlspecialchars($tvAccountEmail, ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </li>
        <li><hr class="tv-account-sep"></li>
        <li>
            <a class="tv-account-item" role="menuitem" href="profile_customer.php">
                <i class="fas fa-user"></i><span><?= te('Profil Saya') ?></span>
            </a>
        </li>
        <li>
            <a class="tv-account-item" role="menuitem" href="history.php">
                <i class="fas fa-receipt"></i><span><?= te('Pesanan Saya') ?></span>
            </a>
        </li>
        <li>
            <a class="tv-account-item" role="menuitem" href="purchase_history.php">
                <i class="fas fa-shopping-bag"></i><span><?= te('Daftar Pembelian') ?></span>
            </a>
        </li>
        <li><hr class="tv-account-sep"></li>
        <li>
            <a class="tv-account-item tv-account-logout" role="menuitem" href="logout.php">
                <i class="fas fa-sign-out-alt"></i><span><?= te('Logout') ?></span>
            </a>
        </li>
    </ul>
</div>
