<?php
/**
 * ADMIN MODULE: Kampanyalar (p=Campaigns) — sepet indirimleri, ücretsiz kargo, kuponlar ve sahaf komisyon indirimleri
 */
if (!defined('SITE_URL')) exit;

$action = sanitize($_GET['action'] ?? 'list');
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$types = campaign_types();
$scopes = campaign_scope_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_commission_default'])) {
    $rate = number_format(min(100, max(0, (float)str_replace(',', '.', $_POST['default_commission_rate'] ?? '0'))), 2, '.', '');
    if (db_fetch("SELECT id FROM settings WHERE setting_key = 'default_commission_rate'")) {
        db_query("UPDATE settings SET setting_value = ? WHERE setting_key = 'default_commission_rate'", [$rate]);
    } else {
        db_query("INSERT INTO settings (setting_key, setting_value) VALUES ('default_commission_rate', ?)", [$rate]);
    }
    log_action('Varsayılan Komisyon Güncellendi', 'Campaigns', null, "Yeni oran: %{$rate}");
    set_flash('success', "Varsayılan sahaf komisyonu %{$rate} olarak kaydedildi.");
    redirect('index.php?p=Campaigns');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['toggle', 'delete'], true) && $edit_id > 0) {
    $c = db_fetch("SELECT * FROM campaigns WHERE id = ?", [$edit_id]);
    if ($c) {
        if ($action === 'toggle') {
            db_query("UPDATE campaigns SET is_active = ? WHERE id = ?", [$c['is_active'] ? 0 : 1, $edit_id]);
            log_action($c['is_active'] ? 'Kampanya Durduruldu' : 'Kampanya Başlatıldı', 'Campaigns', $edit_id, $c['name']);
        } else {
            db_query("DELETE FROM campaigns WHERE id = ?", [$edit_id]);
            log_action('Kampanya Silindi', 'Campaigns', $edit_id, $c['name']);
            set_flash('success', '"' . $c['name'] . '" kampanyası silindi.');
        }
    }
    redirect('index.php?p=Campaigns');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_campaign'])) {
    $type = array_key_exists($_POST['type'] ?? '', $types) ? $_POST['type'] : 'percent';
    $scope_type = array_key_exists($_POST['scope_type'] ?? '', $scopes) ? $_POST['scope_type'] : 'all';
    if ($type === 'commission' && !in_array($scope_type, ['all', 'store'], true)) {
        $scope_type = 'store';
    }
    $scope_ids = '';
    if (in_array($scope_type, ['store', 'category', 'publisher', 'author'], true)) {
        $scope_ids = implode(',', array_filter(array_map('intval', (array)($_POST['scope_ids_' . $scope_type] ?? []))));
    } elseif ($scope_type === 'books') {
        // Kitap ID'leri veya ISBN'ler (virgül / boşluk / satır ile ayrılmış)
        $ids = [];
        foreach (preg_split('/[\s,;]+/', (string)($_POST['scope_books'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $row = strlen($tok) >= 10 ? db_fetch("SELECT id FROM books WHERE isbn = ?", [$tok]) : db_fetch("SELECT id FROM books WHERE id = ?", [(int)$tok]);
            if ($row) {
                $ids[] = (int)$row['id'];
            }
        }
        $scope_ids = implode(',', array_unique($ids));
    }
    $to_dt = function ($v) {
        $v = trim((string)$v);
        $d = $v !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $v) : false;
        return $d ? $d->format('Y-m-d H:i') : null;
    };
    $data = [
        'name'        => sanitize($_POST['name'] ?? ''),
        'type'        => $type,
        'scope_type'  => $scope_type,
        'scope_ids'   => $scope_ids,
        'value'       => max(0, (float)str_replace(',', '.', $_POST['value'] ?? '0')),
        'buy_qty'     => max(0, (int)($_POST['buy_qty'] ?? 0)),
        'pay_qty'     => max(0, (int)($_POST['pay_qty'] ?? 0)),
        'min_amount'  => max(0, (float)str_replace(',', '.', $_POST['min_amount'] ?? '0')),
        'min_qty'     => max(0, (int)($_POST['min_qty'] ?? 0)),
        'coupon_code' => mb_strtoupper(preg_replace('/[^A-Za-z0-9ÇĞİÖŞÜçğıöşü_-]/u', '', $_POST['coupon_code'] ?? ''), 'UTF-8') ?: null,
        'max_uses'    => max(0, (int)($_POST['max_uses'] ?? 0)),
        'stackable'   => isset($_POST['stackable']) ? 1 : 0,
        'priority'    => (int)($_POST['priority'] ?? 0),
        'show_badge'  => isset($_POST['show_badge']) ? 1 : 0,
        'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        'starts_at'   => $to_dt($_POST['starts_at'] ?? ''),
        'ends_at'     => $to_dt($_POST['ends_at'] ?? ''),
        'description' => sanitize($_POST['description'] ?? ''),
    ];

    $err = '';
    if ($data['name'] === '') {
        $err = 'Kampanya adı zorunludur.';
    } elseif ($type === 'buy_x_pay_y' && ($data['buy_qty'] < 2 || $data['pay_qty'] < 1 || $data['pay_qty'] >= $data['buy_qty'])) {
        $err = 'X Al Y Öde için "Al" en az 2, "Öde" en az 1 ve "Al"dan küçük olmalı (ör. 2 al 1 öde, 3 al 2 öde).';
    } elseif ($type === 'nth_percent' && ($data['buy_qty'] < 2 || $data['value'] <= 0 || $data['value'] > 100)) {
        $err = 'N. kitaba indirim için N en az 2, indirim %1–100 olmalı.';
    } elseif ($type === 'percent' && ($data['value'] <= 0 || $data['value'] > 100)) {
        $err = 'İndirim oranı %1–100 arasında olmalı.';
    } elseif ($type === 'amount' && $data['value'] <= 0) {
        $err = 'İndirim tutarı 0\'dan büyük olmalı.';
    } elseif ($type === 'commission' && $data['value'] > 100) {
        $err = 'Komisyon oranı %0–100 arasında olmalı.';
    } elseif ($scope_type !== 'all' && $scope_type !== 'main_store' && $scope_ids === '') {
        $err = 'Kapsam için en az bir seçim yapın (ya da "Tüm kitaplar"ı seçin).';
    } elseif ($data['starts_at'] && $data['ends_at'] && $data['starts_at'] > $data['ends_at']) {
        $err = 'Bitiş tarihi başlangıçtan önce olamaz.';
    } elseif ($data['coupon_code'] && db_fetch("SELECT id FROM campaigns WHERE UPPER(coupon_code) = ? AND id != ?", [$data['coupon_code'], $edit_id])) {
        $err = 'Bu kupon kodu başka bir kampanyada kullanılıyor.';
    }
    if ($err !== '') {
        set_flash('danger', $err);
        redirect('index.php?p=Campaigns&action=' . ($edit_id > 0 ? 'edit&id=' . $edit_id : 'add'));
    }

    $cols = array_keys($data);
    if ($edit_id > 0) {
        db_query("UPDATE campaigns SET " . implode(' = ?, ', $cols) . " = ? WHERE id = ?", array_merge(array_values($data), [$edit_id]));
        log_action('Kampanya Güncellendi', 'Campaigns', $edit_id, $data['name']);
        set_flash('success', '"' . $data['name'] . '" kampanyası güncellendi.');
    } else {
        db_query("INSERT INTO campaigns (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($data));
        log_action('Yeni Kampanya', 'Campaigns', db_last_id(), $data['name']);
        set_flash('success', '"' . $data['name'] . '" kampanyası oluşturuldu.');
    }
    redirect('index.php?p=Campaigns');
}

$stores_l = db_fetch_all("SELECT id, name, commission_rate FROM stores ORDER BY name");
$cats_l   = db_fetch_all("SELECT id, name FROM categories ORDER BY name");
$pubs_l   = db_fetch_all("SELECT id, name FROM publishers ORDER BY name");
$auths_l  = db_fetch_all("SELECT id, name FROM authors ORDER BY name");
$scope_lists = ['store' => $stores_l, 'category' => $cats_l, 'publisher' => $pubs_l, 'author' => $auths_l];

// Hazır şablonlar: kitap sitelerinde ve sahaflarda sık görülen kampanyalar
$templates = [
    '2al1' => ['name' => '2 Al 1 Öde', 'type' => 'buy_x_pay_y', 'buy_qty' => 2, 'pay_qty' => 1, 'description' => 'Seçili kitaplarda 2 kitap alana en ucuzu bedava'],
    '3al2' => ['name' => '3 Al 2 Öde', 'type' => 'buy_x_pay_y', 'buy_qty' => 3, 'pay_qty' => 2, 'description' => '3 kitap alana en ucuzu bedava'],
    'ikinci50' => ['name' => 'İkinci Kitaba %50', 'type' => 'nth_percent', 'buy_qty' => 2, 'value' => 50, 'description' => 'Sepetteki her ikinci kitap yarı fiyatına'],
    'yuzde10' => ['name' => 'Sepette %10 İndirim', 'type' => 'percent', 'value' => 10, 'description' => ''],
    'tlindirim' => ['name' => '500 TL\'ye 50 TL İndirim', 'type' => 'amount', 'value' => 50, 'min_amount' => 500, 'description' => '500 TL ve üzeri alışverişte'],
    'sahafkargo' => ['name' => 'Aynı Sahaftan 3 Kitaba Kargo Bedava', 'type' => 'free_shipping', 'scope_type' => 'store', 'min_qty' => 3, 'description' => 'Aynı sahaftan 3 veya daha fazla kitap alana ücretsiz kargo'],
    'seciliKargo' => ['name' => 'Seçili Kitaplarda Kargo Bedava', 'type' => 'free_shipping', 'scope_type' => 'books', 'min_qty' => 1, 'description' => ''],
    'kupon' => ['name' => 'Hoş Geldin Kuponu', 'type' => 'percent', 'value' => 10, 'coupon_code' => 'HOSGELDIN', 'max_uses' => 100, 'stackable' => 1, 'description' => 'İlk alışverişe özel kupon; diğer kampanyalarla birleşir'],
    'komisyon' => ['name' => 'Sahaf Komisyon İndirimi', 'type' => 'commission', 'scope_type' => 'store', 'value' => 5, 'description' => 'Kampanya süresince komisyon oranı'],
];

$c_data = null;
if ($action === 'edit' && $edit_id > 0) {
    $c_data = db_fetch("SELECT * FROM campaigns WHERE id = ?", [$edit_id]);
} elseif ($action === 'add') {
    $c_data = array_merge(['name' => '', 'type' => 'buy_x_pay_y', 'scope_type' => 'all', 'scope_ids' => '', 'value' => 0, 'buy_qty' => 2, 'pay_qty' => 1, 'min_amount' => 0, 'min_qty' => 0, 'coupon_code' => '', 'max_uses' => 0, 'stackable' => 0, 'priority' => 0, 'show_badge' => 1, 'is_active' => 1, 'starts_at' => '', 'ends_at' => '', 'description' => '', 'used_count' => 0], $templates[$_GET['tpl'] ?? ''] ?? []);
}

$campaign_list = db_fetch_all("SELECT * FROM campaigns ORDER BY is_active DESC, priority DESC, id DESC");
$now = campaign_now();
$status_of = function ($c) use ($now) {
    if (!$c['is_active']) return ['Durduruldu', 'secondary'];
    if ($c['starts_at'] && $c['starts_at'] > $now) return ['Başlamadı', 'info'];
    if ($c['ends_at'] && $c['ends_at'] < $now) return ['Süresi Doldu', 'dark'];
    if ($c['max_uses'] > 0 && $c['used_count'] >= $c['max_uses']) return ['Limit Doldu', 'dark'];
    return ['Yayında', 'success'];
};
$describe = function ($c) {
    $v = rtrim(rtrim(number_format((float)$c['value'], 2, ',', '.'), '0'), ',');
    switch ($c['type']) {
        case 'buy_x_pay_y':   $t = (int)$c['buy_qty'] . ' al ' . (int)$c['pay_qty'] . ' öde'; break;
        case 'nth_percent':   $t = (int)$c['buy_qty'] . '. kitaba %' . $v; break;
        case 'percent':       $t = '%' . $v . ' indirim'; break;
        case 'amount':        $t = $v . ' TL indirim'; break;
        case 'free_shipping': $t = 'Ücretsiz kargo'; break;
        case 'commission':    $t = 'Komisyon %' . $v; break;
        default:              $t = $c['type'];
    }
    $cond = [];
    if ((float)$c['min_amount'] > 0) $cond[] = 'en az ' . format_price($c['min_amount']);
    if ((int)$c['min_qty'] > 0) $cond[] = 'en az ' . (int)$c['min_qty'] . ' kitap';
    return $t . ($cond ? ' (' . implode(', ', $cond) . ')' : '');
};
$scope_text = function ($c) use ($scopes, $scope_lists) {
    $ids = array_filter(array_map('intval', explode(',', (string)$c['scope_ids'])));
    if (isset($scope_lists[$c['scope_type']]) && $ids) {
        $names = [];
        foreach ($scope_lists[$c['scope_type']] as $r) {
            if (in_array((int)$r['id'], $ids, true)) $names[] = $r['name'];
        }
        return implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) : '');
    }
    if ($c['scope_type'] === 'books') return count($ids) . ' kitap';
    return $scopes[$c['scope_type']] ?? 'Tüm kitaplar';
};
$dt_local = function ($v) {
    return $v ? str_replace(' ', 'T', substr($v, 0, 16)) : '';
};
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-gift text-danger me-2"></i>Sepet Kampanyaları</h5>
        <p class="text-muted fs-7 mb-0">İndirimler ödeme adımında otomatik uygulanır; tutarlar her zaman sunucuda hesaplanır. Kuponlu kampanyalar yalnızca müşteri kodu girerse geçerlidir.</p>
    </div>
    <?php if ($action === 'list'): ?>
        <a href="index.php?p=Campaigns&action=add" class="btn btn-danger font-semibold rounded-3 shadow"><i class="bi bi-plus-lg me-1"></i> Yeni Kampanya</a>
    <?php else: ?>
        <a href="index.php?p=Campaigns" class="btn btn-outline-secondary font-semibold rounded-3"><i class="bi bi-arrow-left me-1"></i> Kampanya Listesine Dön</a>
    <?php endif; ?>
</div>

<?php if ($c_data !== null && ($action === 'add' || $action === 'edit')): ?>
    <?php if ($action === 'add'): ?>
        <div class="mb-3">
            <div class="fs-7 fw-semibold mb-2 text-muted">Hazır şablonla başla:</div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($templates as $k => $t): ?>
                    <a href="index.php?p=Campaigns&action=add&tpl=<?= $k ?>" class="btn btn-sm <?= ($_GET['tpl'] ?? '') === $k ? 'btn-dark' : 'btn-outline-dark' ?>"><?= e($t['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form method="POST" action="" id="campaignForm">
                <input type="hidden" name="save_campaign" value="1">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Kampanya Adı * <small class="text-muted fw-normal">(müşteri sepette bunu görür)</small></label>
                        <input type="text" name="name" class="form-control" value="<?= e($c_data['name']) ?>" required maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Kampanya Türü *</label>
                        <select name="type" id="cType" class="form-select">
                            <?php foreach ($types as $k => $label): ?>
                                <option value="<?= $k ?>" <?= $c_data['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12"><div class="p-3 bg-light border rounded-3 fs-7" id="cHelp"></div></div>

                    <div class="col-md-3 f-buy">
                        <label class="form-label fw-semibold fs-7" id="lblBuy">Al (adet)</label>
                        <input type="number" name="buy_qty" class="form-control" min="0" value="<?= (int)$c_data['buy_qty'] ?>">
                    </div>
                    <div class="col-md-3 f-pay">
                        <label class="form-label fw-semibold fs-7">Öde (adet)</label>
                        <input type="number" name="pay_qty" class="form-control" min="0" value="<?= (int)$c_data['pay_qty'] ?>">
                    </div>
                    <div class="col-md-3 f-value">
                        <label class="form-label fw-semibold fs-7" id="lblValue">Değer</label>
                        <input type="number" step="0.01" name="value" class="form-control" min="0" value="<?= e((float)$c_data['value']) ?>">
                    </div>
                    <div class="col-md-3 f-min">
                        <label class="form-label fw-semibold fs-7">En Az Tutar (TL)</label>
                        <input type="number" step="0.01" name="min_amount" class="form-control" min="0" value="<?= e((float)$c_data['min_amount']) ?>">
                        <small class="text-muted fs-8">Kapsamdaki kitapların toplamı. 0 = şart yok</small>
                    </div>
                    <div class="col-md-3 f-min">
                        <label class="form-label fw-semibold fs-7">En Az Kitap Adedi</label>
                        <input type="number" name="min_qty" class="form-control" min="0" value="<?= (int)$c_data['min_qty'] ?>">
                        <small class="text-muted fs-8">0 = şart yok</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Kapsam (hangi kitaplar?)</label>
                        <select name="scope_type" id="cScope" class="form-select">
                            <?php foreach ($scopes as $k => $label): ?>
                                <option value="<?= $k ?>" <?= $c_data['scope_type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <?php $sel_ids = array_filter(array_map('intval', explode(',', (string)$c_data['scope_ids']))); ?>
                        <?php foreach ($scope_lists as $k => $list): ?>
                            <div class="scope-box d-none" data-scope="<?= $k ?>">
                                <label class="form-label fw-semibold fs-7">Seçim <small class="text-muted fw-normal">(Ctrl/Cmd ile birden fazla)</small></label>
                                <select name="scope_ids_<?= $k ?>[]" class="form-select" multiple size="6">
                                    <?php foreach ($list as $r): ?>
                                        <option value="<?= $r['id'] ?>" <?= $c_data['scope_type'] === $k && in_array((int)$r['id'], $sel_ids, true) ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                        <div class="scope-box d-none" data-scope="books">
                            <label class="form-label fw-semibold fs-7">Kitap ID veya ISBN listesi</label>
                            <textarea name="scope_books" class="form-control" rows="3" placeholder="Ör: 12, 45, 9789750719387"><?= $c_data['scope_type'] === 'books' ? e(implode(', ', $sel_ids)) : '' ?></textarea>
                            <small class="text-muted fs-8">Kitap ID'leri Kitap Yönetimi listesinde (#12) görünür.</small>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold fs-7">Başlangıç</label>
                        <input type="datetime-local" name="starts_at" class="form-control" value="<?= e($dt_local($c_data['starts_at'])) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold fs-7">Bitiş</label>
                        <input type="datetime-local" name="ends_at" class="form-control" value="<?= e($dt_local($c_data['ends_at'])) ?>">
                        <small class="text-muted fs-8">Boş = süresiz (Türkiye saati)</small>
                    </div>
                    <div class="col-md-3 f-coupon">
                        <label class="form-label fw-semibold fs-7">Kupon Kodu</label>
                        <input type="text" name="coupon_code" class="form-control text-uppercase" value="<?= e($c_data['coupon_code']) ?>" maxlength="50" placeholder="Boş = herkese otomatik">
                    </div>
                    <div class="col-md-3 f-coupon">
                        <label class="form-label fw-semibold fs-7">Kullanım Limiti</label>
                        <input type="number" name="max_uses" class="form-control" min="0" value="<?= (int)$c_data['max_uses'] ?>">
                        <small class="text-muted fs-8">0 = sınırsız<?= $action === 'edit' ? ' • Kullanılan: ' . (int)$c_data['used_count'] : '' ?></small>
                    </div>

                    <div class="col-md-9">
                        <label class="form-label fw-semibold fs-7">Açıklama <small class="text-muted fw-normal">(sepette ve etiket üzerinde gösterilir)</small></label>
                        <input type="text" name="description" class="form-control" value="<?= e($c_data['description']) ?>" maxlength="250">
                    </div>
                    <div class="col-md-3 f-cart">
                        <label class="form-label fw-semibold fs-7">Öncelik</label>
                        <input type="number" name="priority" class="form-control" value="<?= (int)$c_data['priority'] ?>">
                        <small class="text-muted fs-8">Büyük olan önce uygulanır</small>
                    </div>

                    <div class="col-12 d-flex flex-wrap gap-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="cActive" value="1" <?= $c_data['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold fs-7" for="cActive">Kampanya aktif</label>
                        </div>
                        <div class="form-check form-switch f-cart">
                            <input class="form-check-input" type="checkbox" name="stackable" id="cStack" value="1" <?= $c_data['stackable'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold fs-7" for="cStack">Diğer indirimlerle birleşebilir</label>
                        </div>
                        <div class="form-check form-switch f-badge">
                            <input class="form-check-input" type="checkbox" name="show_badge" id="cBadge" value="1" <?= $c_data['show_badge'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold fs-7" for="cBadge">Kitap kartlarında etiket göster</label>
                        </div>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-top d-flex gap-2">
                    <button type="submit" class="btn btn-danger font-semibold px-4 py-2 shadow"><i class="bi bi-check-circle me-1"></i> Kampanyayı Kaydet</button>
                    <a href="index.php?p=Campaigns" class="btn btn-light border px-4 py-2">İptal</a>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var help = {
            buy_x_pay_y: ['<b>X Al Y Öde:</b> Kapsamdaki kitaplar pahalıdan ucuza dizilir, her X kitaplık grupta en ucuz (X−Y) kitap bedava olur. Ör: 2 al 1 öde, 3 al 2 öde.', ['buy', 'pay', 'min', 'cart', 'coupon', 'badge'], 'Al (adet)', ''],
            nth_percent: ['<b>N. kitaba indirim:</b> Kapsamdaki kitaplar pahalıdan ucuza dizilir, her N kitaplık grubun en ucuzuna % indirim uygulanır. Ör: ikinci kitaba %50 (N=2).', ['buy', 'value', 'min', 'cart', 'coupon', 'badge'], 'N (kaçıncı kitap)', 'İndirim (%)'],
            percent: ['<b>Sepette % indirim:</b> Kapsamdaki kitapların tutarından yüzde düşülür. En az tutar/adet şartı koyabilirsiniz.', ['value', 'min', 'cart', 'coupon', 'badge'], '', 'İndirim (%)'],
            amount: ['<b>Sepette TL indirim:</b> Kapsamdaki kitapların toplamı "En Az Tutar"ı geçerse bu tutar düşülür (kitaplara oransal dağıtılır). Ör: 500 TL\'ye 50 TL.', ['value', 'min', 'cart', 'coupon', 'badge'], '', 'İndirim (TL)'],
            free_shipping: ['<b>Ücretsiz kargo:</b> Sepette kapsamdaki kitaplar şartı sağlarsa kargo ücretsiz olur. Ör: kapsam = sahaf, en az adet = 3 → "aynı sahaftan 3 kitaba kargo bedava". Seçili kitaplarda: kapsam = seçili kitaplar, en az adet = 1.', ['min', 'coupon', 'badge'], '', ''],
            commission: ['<b>Sahaf komisyon indirimi:</b> Sepeti etkilemez. Kampanya tarihleri boyunca seçili sahafların satışlarından alınan komisyon oranı bu değere düşer (dükkânın normal oranından düşükse). Satıcı panelinde ve raporlarda görünür.', ['value'], '', 'Komisyon Oranı (%)']
        };
        var type = document.getElementById('cType'), scope = document.getElementById('cScope');
        function sync() {
            var h = help[type.value];
            document.getElementById('cHelp').innerHTML = h[0];
            ['buy', 'pay', 'value', 'min', 'cart', 'coupon', 'badge'].forEach(function (k) {
                document.querySelectorAll('.f-' + k).forEach(function (el) { el.classList.toggle('d-none', h[1].indexOf(k) < 0); });
            });
            if (h[2]) document.getElementById('lblBuy').textContent = h[2];
            if (h[3]) document.getElementById('lblValue').textContent = h[3];
            Array.prototype.forEach.call(scope.options, function (o) {
                o.disabled = type.value === 'commission' && ['all', 'store'].indexOf(o.value) < 0;
            });
            if (scope.selectedOptions[0] && scope.selectedOptions[0].disabled) scope.value = 'store';
            document.querySelectorAll('.scope-box').forEach(function (b) { b.classList.toggle('d-none', b.dataset.scope !== scope.value); });
        }
        type.addEventListener('change', sync);
        scope.addEventListener('change', sync);
        sync();
    })();
    </script>
<?php else: ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 fs-7">
                            <thead class="table-light fs-8">
                                <tr><th class="ps-3">Kampanya</th><th>Kural</th><th>Kapsam</th><th>Tarih</th><th class="text-end">Kullanım</th><th>Durum</th><th class="text-end pe-3">İşlemler</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$campaign_list): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5">Henüz kampanya yok. "Yeni Kampanya" ile hazır şablonlardan başlayabilirsiniz.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($campaign_list as $c): [$st, $stc] = $status_of($c); ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold"><?= e($c['name']) ?></div>
                                            <div class="text-muted fs-8"><?= e($types[$c['type']] ?? $c['type']) ?><?php if ($c['coupon_code']): ?> • <span class="badge bg-dark">Kupon: <?= e($c['coupon_code']) ?></span><?php endif; ?></div>
                                        </td>
                                        <td><?= e($describe($c)) ?></td>
                                        <td class="fs-8"><?= e($scope_text($c)) ?></td>
                                        <td class="fs-8 text-muted">
                                            <?= $c['starts_at'] ? date('d.m.Y H:i', strtotime($c['starts_at'])) : 'Hemen' ?><br>
                                            <?= $c['ends_at'] ? '→ ' . date('d.m.Y H:i', strtotime($c['ends_at'])) : '→ Süresiz' ?>
                                        </td>
                                        <td class="text-end"><?= (int)$c['used_count'] ?><?= $c['max_uses'] > 0 ? ' / ' . (int)$c['max_uses'] : '' ?></td>
                                        <td><span class="badge bg-<?= $stc ?>"><?= $st ?></span></td>
                                        <td class="text-end pe-3 text-nowrap">
                                            <form method="POST" action="index.php?p=Campaigns&action=toggle&id=<?= $c['id'] ?>" class="d-inline">
                                                <button class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $c['is_active'] ? 'Durdur' : 'Başlat' ?>"><i class="bi <?= $c['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
                                            </form>
                                            <a href="index.php?p=Campaigns&action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Düzenle"><i class="bi bi-pencil-fill"></i></a>
                                            <form method="POST" action="index.php?p=Campaigns&action=delete&id=<?= $c['id'] ?>" class="d-inline" onsubmit="return confirm('Bu kampanyayı silmek istediğinize emin misiniz?');">
                                                <button class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash-fill"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-2"><i class="bi bi-percent text-danger me-1"></i> Sahaf Komisyonları</h6>
                    <p class="text-muted fs-8 mb-3">Sahaf kitaplarının her satışından, indirim sonrası tutar üzerinden alınan pay. Dükkâna özel oran Alt Dükkanlar sayfasından girilir; boşsa varsayılan geçerlidir. Komisyon indirimi kampanyası bu oranı geçici olarak düşürür.</p>
                    <form method="POST" action="" class="d-flex gap-2 mb-3">
                        <input type="hidden" name="save_commission_default" value="1">
                        <div class="input-group">
                            <span class="input-group-text fs-7">Varsayılan %</span>
                            <input type="number" step="0.01" min="0" max="100" name="default_commission_rate" class="form-control" value="<?= e(setting('default_commission_rate', '0')) ?>">
                        </div>
                        <button class="btn btn-dark">Kaydet</button>
                    </form>
                    <table class="table table-sm fs-8 mb-0">
                        <thead><tr><th>Sahaf</th><th class="text-end">Normal</th><th class="text-end">Şu an</th></tr></thead>
                        <tbody>
                            <?php foreach ($stores_l as $st): $normal = $st['commission_rate'] !== null && $st['commission_rate'] !== '' ? (float)$st['commission_rate'] : (float)setting('default_commission_rate', '0'); $cur = store_commission_rate($st['id']); ?>
                                <tr>
                                    <td><?= e($st['name']) ?></td>
                                    <td class="text-end">%<?= rtrim(rtrim(number_format($normal, 2, ',', ''), '0'), ',') ?></td>
                                    <td class="text-end <?= $cur < $normal ? 'text-success fw-bold' : '' ?>">%<?= rtrim(rtrim(number_format($cur, 2, ',', ''), '0'), ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 fs-8 text-muted">
                    <h6 class="fw-bold text-dark mb-2"><i class="bi bi-info-circle text-danger me-1"></i> Nasıl uygulanır?</h6>
                    <ul class="ps-3 mb-0">
                        <li>İndirim kampanyaları öncelik sırasıyla uygulanır. Bir kitap bir indirim aldıysa, "birleşebilir" işaretli olmayan diğer kampanyalar o kitaba uygulanmaz.</li>
                        <li>Ücretsiz kargo, indirim sonrası tutara göre değerlendirilir.</li>
                        <li>İndirimli sipariş satırları, faturada ve raporlarda net tutarla görünür; sahaf komisyonu net tutar üzerinden hesaplanır.</li>
                        <li>Kupon kodlu kampanyalar müşteri ödeme sayfasında kodu girerse uygulanır.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
