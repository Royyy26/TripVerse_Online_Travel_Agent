<?php require_once __DIR__ . '/../_lang.php'; ?>
<div class="hotel-history-container">
    <div class="history-title">
        <span><?= te('Hotel Terakhir Dilihat') ?></span>
        <!-- OPSIONAL: Tombol "Hapus semua" (bisa diuncomment jika mau) -->
        <?php /* if (!empty($hotel_history)): ?>
            <button class="btn-clear" onclick="if(confirm('<?= t('Hapus semua riwayat hotel?') ?>')) window.location.href='hotel.php?clear_hotel_history=1'">
                <?= te('Hapus semua') ?>
            </button>
        <?php endif; */ ?>
    </div>

    <?php if (empty($hotel_history)): ?>
        <div class="text-muted"><?= te('Belum ada riwayat hotel') ?></div>
    <?php else: ?>
        <div class="hotel-history-list">
            <?php foreach ($hotel_history as $hotel): ?>
                <?php if (!empty($hotel['hotel_id'])): ?>
                    <div class="history-hotel-card">
                        <!-- ... konten hotel ... -->

                        <div class="hotel-history-actions">
                            <div class="hotel-history-time">
                                <?= date('d M', strtotime($hotel['waktu'])) ?>
                            </div>
                            <!-- Tombol X untuk hapus SATU hotel -->
                            <button class="btn-delete-hotel"
                                    onclick="deleteHotelHistory('<?= $hotel['hotel_id'] ?>', event)"
                                    title="<?= te('Hapus dari riwayat') ?>">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <a href="hotel.php?hotel_clicked=<?= $hotel['hotel_id'] ?>"
                           class="stretched-link"></a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function deleteHotelHistory(hotelId, event) {
    event.preventDefault();
    event.stopPropagation();

    if (confirm('<?= t('Hapus hotel ini dari riwayat?') ?>')) {
        // Panggil handler delete SINGLE hotel
        window.location.href = 'hotel.php?delete_hotel=' + hotelId;
    }
}
</script>