<?php
/**
 * ADMIN MODULE: Members (p=Members)
 */
if (!defined('SITE_URL')) exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'], $_POST['id'])) {
    $user_id = (int)$_POST['id'];
    $u = db_fetch("SELECT name, status FROM users WHERE id = ? AND role = 'customer'", [$user_id]);
    if ($u) {
        $new_status = ($u['status'] === 'active') ? 'inactive' : 'active';
        db_query("UPDATE users SET status = ? WHERE id = ?", [$new_status, $user_id]);
        log_action('Müşteri Durum Değişikliği', 'Members', $user_id, "{$u['name']} isimli müşterinin durumu {$new_status} yapıldı.");
        set_flash('success', "Müşteri durumu '{$new_status}' olarak güncellendi.");
    }
    redirect('index.php?p=Members');
}

$search = sanitize($_GET['q'] ?? '');
$where = "role = 'customer'";
$params = [];

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$customers = db_fetch_all("SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) as order_count, (SELECT SUM(total_amount) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('İptal', 'Ödeme Bekliyor')) as total_spent FROM users u WHERE {$where} ORDER BY u.id DESC", $params);
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Müşteri & Üye Yönetimi</h5>
        <p class="text-muted fs-7 mb-0">Kayıtlı müşterileri görüntüleyebilir, hesaplarını aktif/pasif yapabilirsiniz.</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white border-0 p-4">
        <form method="GET" action="" class="row g-2">
            <input type="hidden" name="p" value="Members">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control bg-light border-start-0" placeholder="Müşteri adı, e-posta veya telefon..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-dark w-100 fw-semibold">Ara</button>
            </div>
            <?php if (!empty($search)): ?>
                <div class="col-md-1">
                    <a href="index.php?p=Members" class="btn btn-outline-secondary w-100"><i class="bi bi-x-lg"></i></a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light fs-7">
                    <tr>
                        <th class="ps-4">Müşteri Bilgileri</th>
                        <th>İletişim</th>
                        <th>Adres</th>
                        <th>Sipariş Sayısı</th>
                        <th>Toplam Harcama</th>
                        <th>Durum</th>
                        <th class="text-end pe-4">Eylem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">Kayıtlı müşteri bulunamadı.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark fs-7"><?= e($c['name']) ?></div>
                                    <small class="text-muted fs-8">Kayıt: <?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="fs-7"><i class="bi bi-envelope me-1 text-danger"></i> <?= e($c['email']) ?></div>
                                    <small class="text-muted fs-8"><i class="bi bi-telephone me-1"></i> <?= e($c['phone'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <small class="text-secondary fs-8" style="max-width: 200px; display: block;"><?= e($c['address'] ?? 'Adres girilmemiş') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fs-8"><?= $c['order_count'] ?> Sipariş</span>
                                </td>
                                <td>
                                    <div class="fw-bold text-danger fs-7"><?= format_price($c['total_spent'] ?? 0) ?></div>
                                </td>
                                <td>
                                    <?php if ($c['status'] === 'active'): ?>
                                        <span class="badge bg-success fs-8">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger fs-8">Pasif / Engelli</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <form method="POST" action="index.php?p=Members" class="d-inline" onsubmit="return confirm('Müşteri durumunu değiştirmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="toggle_status" value="1">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $c['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                            <?= $c['status'] === 'active' ? '<i class="bi bi-slash-circle me-1"></i> Pasif Yap' : '<i class="bi bi-check-circle me-1"></i> Aktif Yap' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
