<?php
/**
 * ADMIN MODULE: Orders (p=Orders)
 */
if (!defined('SITE_URL')) exit;

$order_statuses = [
    'Ödeme Bekliyor' => 'bg-info text-dark',
    'Hazırlanıyor'   => 'bg-warning text-dark',
    'Kargolandı'     => 'bg-dark text-white',
    'Tamamlandı'     => 'bg-success text-white',
    'İptal'          => 'bg-danger text-white',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = sanitize($_POST['status'] ?? '');
    $tracking_number = sanitize($_POST['tracking_number'] ?? '');

    $ord = db_fetch("SELECT order_number, status FROM orders WHERE id = ?", [$order_id]);
    if ($ord && isset($order_statuses[$new_status])) {
        $old_status = $ord['status'];
        global $pdo;
        $pdo->beginTransaction();

        // İptal edilen siparişin kitapları stoğa geri döner; iptalden geri alınırsa tekrar düşülür
        if ($new_status === 'İptal' && $old_status !== 'İptal') {
            db_query("UPDATE books SET stock = stock + (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND book_id = books.id) WHERE id IN (SELECT book_id FROM order_items WHERE order_id = ?)", [$order_id, $order_id]);
            db_query("UPDATE transactions SET status = 'İptal' WHERE order_id = ?", [$order_id]);
        } elseif ($old_status === 'İptal' && $new_status !== 'İptal') {
            db_query("UPDATE books SET stock = stock - (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND book_id = books.id) WHERE id IN (SELECT book_id FROM order_items WHERE order_id = ?)", [$order_id, $order_id]);
            db_query("UPDATE transactions SET status = ? WHERE order_id = ?", [$new_status === 'Ödeme Bekliyor' ? 'Bekliyor' : 'Onaylandı', $order_id]);
        } elseif ($old_status === 'Ödeme Bekliyor' && $new_status !== 'Ödeme Bekliyor') {
            // Ödeme beklenen sipariş ilerletildiyse havale alınmış demektir
            db_query("UPDATE transactions SET status = 'Onaylandı', gateway_response = 'Havale/EFT ödemesi yönetici tarafından onaylandı' WHERE order_id = ?", [$order_id]);
        }

        db_query("UPDATE orders SET status = ?, tracking_number = ? WHERE id = ?", [$new_status, $tracking_number, $order_id]);
        $pdo->commit();
        log_action('Sipariş Durumu Güncellendi', 'Orders', $order_id, "Sipariş {$ord['order_number']} durumu '{$new_status}' olarak değiştirildi. Kargo Takip: {$tracking_number}");
        set_flash('success', "Sipariş #{$ord['order_number']} durumu güncellendi.");
    }
    redirect('index.php?p=Orders');
}

paytr_expire_pending_orders();

$status_filter = sanitize($_GET['status'] ?? '');
$where = "1=1";
$params = [];

if (!empty($status_filter)) {
    $where .= " AND o.status = ?";
    $params[] = $status_filter;
}

$orders = db_fetch_all("SELECT o.*, u.name as customer_name, u.email as customer_email, u.phone as customer_phone 
                        FROM orders o 
                        LEFT JOIN users u ON o.user_id = u.id 
                        WHERE {$where} 
                        ORDER BY o.created_at DESC", $params);
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Sipariş Operasyonları & Kargo Yönetimi</h5>
        <p class="text-muted fs-7 mb-0">Müşteri siparişlerini inceleyebilir, kargo takip numarası girip durumlarını güncelleyebilirsiniz.</p>
    </div>
</div>

<ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm border">
    <li class="nav-item">
        <a class="nav-link <?= empty($status_filter) ? 'active bg-danger text-white' : 'text-secondary' ?> fw-semibold" href="index.php?p=Orders">Tüm Siparişler</a>
    </li>
    <?php foreach ($order_statuses as $st => $st_class): ?>
        <li class="nav-item">
            <a class="nav-link <?= $status_filter === $st ? 'active ' . $st_class : 'text-secondary' ?> fw-semibold" href="index.php?p=Orders&status=<?= urlencode($st) ?>"><?= e($st) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light fs-7">
                    <tr>
                        <th class="ps-4">Sipariş No & Tarih</th>
                        <th>Müşteri</th>
                        <th>Ödeme Yöntemi</th>
                        <th>Tutar</th>
                        <th>Durum</th>
                        <th>Kargo Takip No</th>
                        <th class="text-end pe-4">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">Seçilen filtrelere ait sipariş bulunamadı.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark fs-7"><?= e($o['order_number']) ?></div>
                                    <small class="text-muted fs-8"><?= date('d.m.Y H:i', strtotime($o['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark fs-7"><?= e($o['customer_name']) ?></div>
                                    <small class="text-muted fs-8"><?= e($o['customer_phone'] ?? $o['customer_email']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fs-8"><i class="bi bi-credit-card me-1"></i> <?= e($o['payment_method']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-extrabold text-danger fs-7"><?= format_price($o['total_amount']) ?></div>
                                </td>
                                <td>
                                    <span class="badge <?= $order_statuses[$o['status']] ?? 'bg-secondary' ?> fs-8">
                                        <?= e($o['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="fw-mono text-secondary"><?= e($o['tracking_number'] ?? 'Girilmedi') ?></small>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-danger me-1" data-bs-toggle="modal" data-bs-target="#orderModal_<?= $o['id'] ?>">
                                        <i class="bi bi-gear-fill me-1"></i> Durum / Kargo
                                    </button>
                                    <a href="fatura.php?id=<?= $o['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark">
                                        <i class="bi bi-printer-fill me-1"></i> Fatura
                                    </a>
                                </td>
                            </tr>

                            <div class="modal fade" id="orderModal_<?= $o['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content rounded-4 border-0">
                                        <div class="modal-header border-bottom px-4">
                                            <h5 class="modal-title fw-bold">Sipariş Yönetimi #<?= e($o['order_number']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="">
                                            <div class="modal-body p-4">
                                                <input type="hidden" name="update_order_status" value="1">
                                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold fs-7">Sipariş Durumu</label>
                                                    <select name="status" class="form-select" required>
                                                        <?php foreach (array_keys($order_statuses) as $st): ?>
                                                            <option value="<?= e($st) ?>" <?= $o['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold fs-7">Kargo Takip Numarası</label>
                                                    <input type="text" name="tracking_number" class="form-control" value="<?= e($o['tracking_number'] ?? '') ?>" placeholder="Örn: TR9988776655">
                                                </div>

                                                <?php if ($o['status'] === 'Ödeme Bekliyor' && $o['payment_method'] === 'Kredi Kartı'): ?>
                                                    <div class="alert alert-info fs-8 py-2">Müşteri PayTR ödeme sayfasında. Ödeme sonucu otomatik işlenir; <?= PAYTR_EXPIRE_MINUTES ?> dakika içinde ödenmezse sipariş iptal edilip stok geri eklenir.</div>
                                                <?php elseif ($o['status'] === 'Ödeme Bekliyor'): ?>
                                                    <div class="alert alert-info fs-8 py-2">Havale/EFT ödemesi hesabınıza geçtiyse durumu <strong>Hazırlanıyor</strong> yapın; ödeme onaylandı olarak kaydedilir.</div>
                                                <?php endif; ?>

                                                <div class="p-3 bg-light rounded-3">
                                                    <div class="fw-semibold text-dark fs-7 mb-1">Teslimat Adresi:</div>
                                                    <div class="fs-8 text-secondary"><?= e($o['shipping_address']) ?></div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top px-4">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Kapat</button>
                                                <button type="submit" class="btn btn-danger font-semibold">Güncelle</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
