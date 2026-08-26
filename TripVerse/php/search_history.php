<?php require_once __DIR__ . '/_lang.php'; ?>
<!-- Riwayat Pencarian Kota -->
<div class="city-history-title">
    <?= te('Riwayat Pencarian') ?>
    <?php if (!empty($history)): ?>
        <button class="btn-clear-history" onclick="if(confirm('<?= t('Hapus semua riwayat?') ?>')) window.location.href = 'hotel.php?clear_history=1'">
            <?= te('Hapus Semua') ?>
        </button>
    <?php endif; ?>
</div>

<?php if (empty($history)): ?>
    <div class="text-muted"><?= te('Belum ada riwayat pencarian.') ?></div>
<?php else: ?>
    <?php foreach ($history as $item):
        $kota = $item['kota'] ?? '';
        $info = $item['info'] ?? t('Terakhir dicari');
        if ($kota === '') continue;
    ?>
        <div class="city-history-item" onclick="selectDestination('<?= $kota ?>')">
            <div class="fw-bold"><?= $kota ?></div>
            <div class="text-muted small"><?= $info ?></div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Riwayat Hotel -->
<div class="hotel-history-title mt-4">
    <?= te('Hotel Terakhir Dilihat') ?>
</div>

<?php
// Akses session hotel_history
$hotel_history = $_SESSION['hotel_history'] ?? [];
?>

<?php if (empty($hotel_history)): ?>
    <div class="text-muted small"><?= te('Belum ada riwayat hotel.') ?></div>
<?php else: ?>
    <?php foreach ($hotel_history as $hotel): ?>
        <div class="city-history-item" 
             onclick="window.location.href='hotel.php?hotel_clicked=<?= $hotel['hotel_id'] ?>'">
            <div class="d-flex align-items-center">
                <img src="../img/<?= htmlspecialchars($hotel['foto']) ?>" 
                     style="width: 30px; height: 30px; border-radius: 4px; object-fit: cover; margin-right: 10px;">
                <div style="flex: 1;">
                    <div class="fw-bold small"><?= htmlspecialchars($hotel['nama_hotel']) ?></div>
                    <div class="text-muted x-small"><?= htmlspecialchars($hotel['kota']) ?></div>
                </div>
                <div class="text-muted x-small"><?= date('d M', strtotime($hotel['waktu'])) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>