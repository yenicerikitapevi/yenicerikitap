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
    $carrier = array_key_exists($_POST['shipping_carrier'] ?? '', shipping_carriers()) ? $_POST['shipping_carrier'] : null;
    $tracking_url = trim((string)($_POST['tracking_url'] ?? ''));
    $tracking_url = preg_match('#^https://#i', $tracking_url) ? mb_substr($tracking_url, 0, 500, 'UTF-8') : null;
    $send_ship_mail = !empty($_POST['send_ship_mail']);

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

        db_query("UPDATE orders SET status = ?, tracking_number = ?, shipping_carrier = ?, tracking_url = ?, shipped_at = CASE WHEN ? = 'Kargolandı' AND shipped_at IS NULL THEN ? ELSE shipped_at END WHERE id = ?",
            [$new_status, $tracking_number, $carrier, $tracking_url, $new_status, date('Y-m-d H:i:s'), $order_id]);
        $pdo->commit();
        log_action('Sipariş Durumu Güncellendi', 'Orders', $order_id, "Sipariş {$ord['order_number']} durumu '{$new_status}' olarak değiştirildi. Kargo: " . (shipping_carriers()[$carrier]['name'] ?? '-') . " / {$tracking_number}");
        $msg = "Sipariş #{$ord['order_number']} durumu güncellendi.";
        // Kargolandı + takip numarası → müşteriye "kargoya verildi" e-postası (işaretliyse)
        if ($send_ship_mail && $new_status === 'Kargolandı' && $tracking_number !== '') {
            $sent = send_order_shipped($order_id, true);
            $msg .= $sent ? ' Müşteriye kargo e-postası gönderildi.' : ' Kargo e-postası gönderilemedi (Site Ayarları → E-posta kaydını kontrol edin).';
        }
        if (!empty($_POST['resend_confirm_mail'])) {
            mail_claim_release($order_id, 'mail_confirm_sent_at');
            $resent = send_order_confirmation($order_id);
            $msg .= $resent ? ' Sipariş e-postası müşteriye gönderildi.' : ' Sipariş e-postası gönderilemedi (Site Ayarları → E-posta kaydına bakın).';
            if (!$resent) $sent = false;
        }
        set_flash(($send_ship_mail && $new_status === 'Kargolandı' && $tracking_number !== '' && empty($sent)) || (isset($resent) && !$resent) ? 'warning' : 'success', $msg);
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
} else {
    $where .= " AND o.status != 'İptal'"; // iptal edilenler yalnızca İptal sekmesinde
}
$status_counts = array_column(db_fetch_all("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status"), 'cnt', 'status');

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
        <a class="nav-link <?= empty($status_filter) ? 'active bg-danger text-white' : 'text-secondary' ?> fw-semibold" href="index.php?p=Orders">Tüm Siparişler <span class="badge bg-light text-dark ms-1"><?= array_sum($status_counts) - (int)($status_counts['İptal'] ?? 0) ?></span></a>
    </li>
    <?php foreach ($order_statuses as $st => $st_class): ?>
        <li class="nav-item">
            <a class="nav-link <?= $status_filter === $st ? 'active ' . $st_class : 'text-secondary' ?> fw-semibold" href="index.php?p=Orders&status=<?= urlencode($st) ?>"><?= e($st) ?> <span class="badge bg-light text-dark ms-1"><?= (int)($status_counts[$st] ?? 0) ?></span></a>
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
                                    <?php if (!empty($o['tracking_number'])): ?>
                                        <small class="fw-mono text-secondary d-block"><?= e($o['tracking_number']) ?></small>
                                        <small class="text-muted fs-8"><?= e(order_carrier_name($o)) ?><?= !empty($o['mail_shipped_sent_at']) ? ' • <i class="bi bi-envelope-check text-success" title="Kargo e-postası gönderildi"></i>' : '' ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Girilmedi</small>
                                    <?php endif; ?>
                                    <?php if (!empty($o['mail_confirm_sent_at'])): ?><div class="fs-8 text-success"><i class="bi bi-envelope-check me-1"></i>Sipariş e-postası gitti</div><?php endif; ?>
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

                                                <div class="row g-2 mb-3">
                                                    <div class="col-sm-5">
                                                        <label class="form-label fw-semibold fs-7">Kargo Firması</label>
                                                        <select name="shipping_carrier" class="form-select">
                                                            <option value="">— Seçin —</option>
                                                            <?php foreach (shipping_carriers() as $ck => $cv): ?>
                                                                <option value="<?= $ck ?>" <?= ($o['shipping_carrier'] ?? '') === $ck ? 'selected' : '' ?>><?= e($cv['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-sm-7">
                                                        <label class="form-label fw-semibold fs-7">Kargo Takip Numarası</label>
                                                        <input type="text" name="tracking_number" class="form-control" value="<?= e($o['tracking_number'] ?? '') ?>" placeholder="Örn: 123456789012">
                                                    </div>
                                                    <div class="col-12">
                                                        <input type="url" name="tracking_url" class="form-control form-control-sm" value="<?= e($o['tracking_url'] ?? '') ?>" placeholder="İsteğe bağlı: kargo firmasının tam takip bağlantısı (https://...)">
                                                        <div class="form-text fs-8">Boş bırakılırsa firmaya göre otomatik oluşturulur. Müşteri bunu Sipariş Takip sayfasında ve e-postada görür.</div>
                                                    </div>
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="resend_confirm_mail" value="1" id="confMail_<?= $o['id'] ?>">
                                                    <label class="form-check-label fs-7" for="confMail_<?= $o['id'] ?>">"Siparişiniz alındı" e-postasını <?= !empty($o['mail_confirm_sent_at']) ? 'tekrar ' : '' ?>gönder<?= empty($o['mail_confirm_sent_at']) ? ' <span class="text-danger">(henüz gönderilmedi)</span>' : '' ?></label>
                                                </div>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="send_ship_mail" value="1" id="shipMail_<?= $o['id'] ?>" <?= empty($o['mail_shipped_sent_at']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fs-7" for="shipMail_<?= $o['id'] ?>">Durum <b>Kargolandı</b> ise müşteriye "kargoya verildi" e-postası gönder<?= !empty($o['mail_shipped_sent_at']) ? ' <span class="text-muted">(daha önce gönderildi: ' . e(date('d.m.Y H:i', strtotime($o['mail_shipped_sent_at']))) . ')</span>' : '' ?></label>
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
