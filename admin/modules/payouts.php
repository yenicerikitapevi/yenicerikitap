<?php
/**
 * ADMIN MODULE: Hakediş Ödemeleri (p=Payouts)
 * Sahafların ödemeye hazır hakedişleri; yönetici bankadan havaleyi yapıp "Ödeme yaptım" ile kaydeder.
 */
if (!defined('SITE_URL')) exit;

if (empty($_SESSION['payout_token'])) $_SESSION['payout_token'] = bin2hex(random_bytes(16));
$po_token = $_SESSION['payout_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($po_token, (string)($_POST['token'] ?? ''))) {
        set_flash('warning', 'Oturum süresi doldu, tekrar deneyin.');
    } elseif (($_POST['action'] ?? '') === 'pay') {
        [$ok, $msg] = po_record((int)($_POST['store_id'] ?? 0), (float)($_POST['expected'] ?? 0), trim((string)($_POST['reference'] ?? '')), $_SESSION['admin_user']['name'] ?? '');
        set_flash($ok ? 'success' : 'danger', $msg);
    } elseif (($_POST['action'] ?? '') === 'undo') {
        [$ok, $msg] = po_undo((int)($_POST['payout_id'] ?? 0));
        set_flash($ok ? 'success' : 'danger', $msg);
    } elseif (($_POST['action'] ?? '') === 'weekday') {
        $d = min(7, max(1, (int)($_POST['weekday'] ?? 1)));
        if (db_fetch("SELECT id FROM settings WHERE setting_key = 'payout_weekday'")) {
            db_query("UPDATE settings SET setting_value = ? WHERE setting_key = 'payout_weekday'", [(string)$d]);
        } else {
            db_query("INSERT INTO settings (setting_key, setting_value) VALUES ('payout_weekday', ?)", [(string)$d]);
        }
        set_flash('success', 'Haftalık ödeme günü: ' . po_weekday_name($d) . '.');
    }
    redirect('index.php?p=Payouts');
}

$next_payday = po_next_payday();
$stores_po = [];
foreach (db_fetch_all("SELECT id, name, email, iban, phone FROM stores ORDER BY name") as $s) {
    $sum = po_summary($s['id']);
    if (!$sum['rows'] && $sum['paid'] <= 0) continue;
    $stores_po[] = $s + ['sum' => $sum];
}
usort($stores_po, function ($a, $b) { return $b['sum']['ready'] <=> $a['sum']['ready']; });
$total_ready = array_sum(array_map(function ($s) { return max(0, $s['sum']['ready']); }, $stores_po));
$history = po_history(null, 30);
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Sahaf Hakediş Ödemeleri</h5>
        <p class="text-muted fs-7 mb-0" style="max-width: 760px;">
            Hakediş = sahafın kitap satış tutarı − komisyon + kargo payı (kargoyu sahaf gönderir; karma siparişte kargo ücreti paket sayısına bölünür).
            Sipariş kargolandıktan <?= PAYOUT_HOLD_DAYS ?> gün sonra (cayma/iade süresi) ödemeye açılır. Havaleyi bankadan yapıp burada <b>“Ödeme yaptım”</b> ile kaydedin;
            satıcıya dökümlü e-posta gider. Ödendikten sonra iptal edilen siparişin tutarı sonraki ödemeden kendiliğinden düşülür.
        </p>
    </div>
    <form method="POST" class="d-flex align-items-center gap-2 bg-white border rounded-3 p-2">
        <input type="hidden" name="token" value="<?= e($po_token) ?>">
        <input type="hidden" name="action" value="weekday">
        <label class="fs-8 text-muted text-nowrap" for="poDay">Haftalık ödeme günü</label>
        <select name="weekday" id="poDay" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php for ($d = 1; $d <= 7; $d++): ?><option value="<?= $d ?>" <?= po_weekday() === $d ? 'selected' : '' ?>><?= po_weekday_name($d) ?></option><?php endfor; ?>
        </select>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="bg-white rounded-4 shadow-sm p-3 h-100"><div class="text-muted fs-8 fw-semibold">ÖDEMEYE HAZIR (TOPLAM)</div><div class="fs-4 fw-bold text-danger"><?= format_price($total_ready) ?></div><div class="fs-8 text-muted"><?= count(array_filter($stores_po, function ($s) { return $s['sum']['ready'] > 0; })) ?> satıcı</div></div></div>
    <div class="col-md-4"><div class="bg-white rounded-4 shadow-sm p-3 h-100"><div class="text-muted fs-8 fw-semibold">SONRAKİ ÖDEME GÜNÜ</div><div class="fs-4 fw-bold"><?= date('d.m.Y', strtotime($next_payday)) ?></div><div class="fs-8 text-muted"><?= po_weekday_name() ?><?= $next_payday === date('Y-m-d') ? ' — bugün' : '' ?></div></div></div>
    <div class="col-md-4"><div class="bg-white rounded-4 shadow-sm p-3 h-100"><div class="text-muted fs-8 fw-semibold">VADESİ BEKLENEN</div><div class="fs-4 fw-bold"><?= format_price(array_sum(array_map(function ($s) { return $s['sum']['pending']; }, $stores_po))) ?></div><div class="fs-8 text-muted">kargolanmamış ya da <?= PAYOUT_HOLD_DAYS ?> günü dolmamış</div></div></div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white p-3 fw-bold">Satıcılar</div>
    <?php if (!$stores_po): ?>
        <div class="p-5 text-center text-muted">Henüz sahaf satışı yok.</div>
    <?php endif; ?>
    <div class="list-group list-group-flush">
        <?php foreach ($stores_po as $s): $sum = $s['sum']; ?>
            <details class="list-group-item p-3" <?= $sum['ready'] > 0 ? '' : '' ?>>
                <summary class="d-flex flex-wrap justify-content-between align-items-center gap-2" style="cursor: pointer; list-style: none;">
                    <span><b><?= e($s['name']) ?></b>
                        <span class="text-muted fs-8 ms-2"><?= $s['iban'] ? '<span class="font-monospace">' . e(po_format_iban($s['iban'])) . '</span>' : '<span class="text-danger">IBAN kayıtlı değil</span>' ?></span></span>
                    <span class="fs-7 d-flex gap-3 align-items-center">
                        <span>Hazır: <b class="<?= $sum['ready'] > 0 ? 'text-danger' : ($sum['ready'] < 0 ? 'text-warning-emphasis' : 'text-muted') ?>"><?= format_price($sum['ready']) ?></b></span>
                        <span class="text-muted">Vadede: <?= format_price($sum['pending']) ?></span>
                        <span class="text-muted">Ödenen: <?= format_price($sum['paid']) ?></span>
                        <span class="btn btn-sm btn-outline-dark py-0">Döküm</span>
                    </span>
                </summary>
                <div class="mt-3">
                    <div class="table-responsive">
                        <table class="table table-sm fs-8 align-middle mb-2">
                            <thead class="table-light"><tr><th>Sipariş</th><th>Durum</th><th class="text-end">Kitap</th><th class="text-end">Komisyon</th><th class="text-end">Kargo payı</th><th class="text-end">Hakediş</th><th class="text-end">Ödenen</th><th class="text-end">Ödenecek</th><th>Vade</th></tr></thead>
                            <tbody>
                            <?php foreach ($sum['rows'] as $r): ?>
                                <tr class="<?= $r['ready'] ? ($r['delta'] < 0 ? 'table-warning' : 'table-success') : '' ?>">
                                    <td class="font-monospace"><?= e($r['order_number']) ?></td>
                                    <td><?= e($r['status']) ?></td>
                                    <td class="text-end"><?= format_price($r['items_total']) ?></td>
                                    <td class="text-end"><?= po_money(-$r['commission']) ?></td>
                                    <td class="text-end"><?= format_price($r['shipping']) ?><?= $r['parcels'] > 1 ? ' <span class="text-muted">(1/' . (int)$r['parcels'] . ')</span>' : '' ?></td>
                                    <td class="text-end"><?= format_price($r['due']) ?></td>
                                    <td class="text-end"><?= po_money($r['paid']) ?></td>
                                    <td class="text-end fw-bold"><?= po_money($r['delta']) ?></td>
                                    <td><?= e($r['state']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($sum['ready'] > 0): ?>
                        <form method="POST" class="row g-2 align-items-end" onsubmit="return confirm('<?= e($s['name']) ?> için <?= e(format_price($sum['ready'])) ?> havale yapıldı olarak kaydedilsin mi?');">
                            <input type="hidden" name="token" value="<?= e($po_token) ?>">
                            <input type="hidden" name="action" value="pay">
                            <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="expected" value="<?= e(number_format($sum['ready'], 2, '.', '')) ?>">
                            <div class="col-md-6">
                                <label class="form-label fs-8 mb-1">Havale açıklaması / dekont no (isteğe bağlı)</label>
                                <input type="text" name="reference" class="form-control form-control-sm" maxlength="255" placeholder="ör. Hakediş <?= date('d.m.Y') ?>">
                            </div>
                            <div class="col-md-6">
                                <button class="btn btn-danger btn-sm fw-semibold"><i class="bi bi-check2-circle me-1"></i> <?= e(format_price($sum['ready'])) ?> havaleyi yaptım, kaydet</button>
                            </div>
                        </form>
                    <?php elseif ($sum['ready'] < 0): ?>
                        <div class="alert alert-warning fs-8 mb-0">Ödendikten sonra iptal edilen sipariş nedeniyle satıcının <?= format_price(-$sum['ready']) ?> borcu var; yeni hakedişlerinden düşülecek.</div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white p-3 fw-bold">Ödeme Geçmişi</div>
    <?php if (!$history): ?>
        <div class="p-4 text-center text-muted fs-7">Henüz ödeme kaydı yok.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 fs-7">
                <thead class="table-light"><tr><th class="ps-3">Tarih</th><th>Satıcı</th><th class="text-end">Tutar</th><th>IBAN</th><th>Açıklama</th><th class="text-center">Sipariş</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td class="ps-3"><?= date('d.m.Y H:i', strtotime($h['paid_at'])) ?></td>
                        <td><?= e($h['store_name'] ?? '#' . $h['store_id']) ?></td>
                        <td class="text-end fw-bold"><?= format_price($h['amount']) ?></td>
                        <td class="font-monospace fs-8"><?= e(po_format_iban($h['iban'])) ?></td>
                        <td><?= e($h['reference'] ?: '-') ?><?= $h['created_by'] ? '<div class="text-muted fs-8">' . e($h['created_by']) . '</div>' : '' ?></td>
                        <td class="text-center"><?= (int)$h['line_count'] ?></td>
                        <td class="text-end pe-3">
                            <form method="POST" onsubmit="return confirm('Bu ödeme kaydı silinsin mi? Tutar yeniden ödenecekler listesine döner. Yalnızca yanlışlıkla kaydedilmiş ödemeler için kullanın.');">
                                <input type="hidden" name="token" value="<?= e($po_token) ?>">
                                <input type="hidden" name="action" value="undo">
                                <input type="hidden" name="payout_id" value="<?= (int)$h['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary py-0" title="Yanlış kaydı geri al">Geri al</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
