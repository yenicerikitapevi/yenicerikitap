<?php
/**
 * ADMIN MODULE: Messages (p=Messages) - İletişim formundan gelen mesajlar
 */
if (!defined('SITE_URL')) exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_id'])) {
    $message_id = (int)$_POST['message_id'];
    if (isset($_POST['mark_read'])) {
        db_query("UPDATE contact_messages SET is_read = 1 WHERE id = ?", [$message_id]);
        set_flash('success', 'Mesaj okundu olarak işaretlendi.');
    } elseif (isset($_POST['delete_message'])) {
        db_query("DELETE FROM contact_messages WHERE id = ?", [$message_id]);
        log_action('Mesaj Silindi', 'Messages', $message_id, 'İletişim mesajı silindi.');
        set_flash('success', 'Mesaj silindi.');
    }
    redirect('index.php?p=Messages');
}

$messages = db_fetch_all("SELECT * FROM contact_messages ORDER BY is_read ASC, id DESC LIMIT 200");
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">İletişim Formu Mesajları</h5>
        <p class="text-muted fs-7 mb-0">Sitedeki iletişim sayfasından gönderilen mesajlar. Yanıtlamak için e-posta adresine tıklayın.</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <?php if (empty($messages)): ?>
            <div class="text-center py-5 text-muted">Henüz mesaj yok.</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($messages as $m): ?>
                    <div class="list-group-item p-4 <?= $m['is_read'] ? '' : 'bg-light' ?>">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-bold text-dark">
                                    <?php if (!$m['is_read']): ?><span class="badge bg-danger me-1">Yeni</span><?php endif; ?>
                                    <?= e($m['subject']) ?>
                                </div>
                                <small class="text-muted">
                                    <?= e($m['name']) ?> •
                                    <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . $m['subject']) ?>" class="text-danger"><?= e($m['email']) ?></a> •
                                    <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?>
                                </small>
                            </div>
                            <div class="d-flex gap-1">
                                <?php if (!$m['is_read']): ?>
                                    <form method="POST" action="index.php?p=Messages">
                                        <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
                                        <button type="submit" name="mark_read" value="1" class="btn btn-sm btn-outline-success" title="Okundu"><i class="bi bi-check2"></i></button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" action="index.php?p=Messages" onsubmit="return confirm('Bu mesajı silmek istediğinize emin misiniz?');">
                                    <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
                                    <button type="submit" name="delete_message" value="1" class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <p class="mt-3 mb-0 text-secondary" style="white-space: pre-wrap;"><?= e($m['message']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
