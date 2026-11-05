<?php
/**
 * SATICI PANELİ: Sahaf yalnızca kendi dükkânını görür
 *  - Dükkânım: dükkân bilgileri, özet, kendi kitapları (arama + sayfalama)
 *  - Raporlar: kendi kitaplarının ve vitrin sayfasının ziyaret / satış raporu
 * Kitap ekleme/düzenleme: kitap.php (ya da aktarım uygulaması); listede toplu satışa alma / kaldırma / silme
 */
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['store_user'])) {
    redirect('login.php');
}
$store = db_fetch("SELECT * FROM stores WHERE id = ?", [(int)$_SESSION['store_user']['id']]);
if (!$store || !$store['is_active']) {
    unset($_SESSION['store_user']);
    redirect('login.php');
}

$report_store_id = (int)$store['id'];
if (empty($_SESSION['store_form_token'])) {
    $_SESSION['store_form_token'] = bin2hex(random_bytes(16));
}
$store_token = $_SESSION['store_form_token'];

// Listeden hızlı stok değişikliği (AJAX): yalnızca bu dükkânın kitabı
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock') {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int)($_POST['id'] ?? 0);
    $sval = (int)($_POST['stock'] ?? -1);
    if (!hash_equals($store_token, (string)($_POST['token'] ?? '')) || $sval < 0 || $sval > 100000
        || !db_fetch("SELECT id FROM books WHERE id = ? AND store_id = ?", [$sid, $report_store_id])) {
        echo json_encode(['ok' => false, 'error' => 'Stok kaydedilemedi, sayfayı yenileyin.']);
        exit;
    }
    db_query("UPDATE books SET stock = ? WHERE id = ? AND store_id = ?", [$sval, $sid, $report_store_id]);
    echo json_encode(['ok' => true, 'stock' => $sval]);
    exit;
}

// Toplu işlem: yalnızca bu dükkânın kitapları etkilenir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk' && hash_equals($store_token, (string)($_POST['token'] ?? ''))) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $op = $_POST['op'] ?? '';
    $mine = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $mine = array_map('intval', array_column(db_fetch_all("SELECT id FROM books WHERE store_id = ? AND id IN ({$in})", array_merge([$report_store_id], $ids)), 'id'));
    }
    if (!$mine) {
        set_flash('warning', 'Önce listeden kitap seçin.');
    } else {
        $min = implode(',', $mine);
        if ($op === 'activate') {
            db_query("UPDATE books SET is_active = 1 WHERE id IN ({$min}) AND price > 0");
            set_flash('success', count($mine) . ' kitap satışa alındı.');
        } elseif ($op === 'deactivate') {
            db_query("UPDATE books SET is_active = 0 WHERE id IN ({$min})");
            set_flash('success', count($mine) . ' kitap satıştan kaldırıldı.');
        } elseif ($op === 'delete') {
            // Siparişi olan kitap silinmez (sipariş geçmişi bozulmasın), satıştan kaldırılır
            $ordered = array_map('intval', array_column(db_fetch_all("SELECT DISTINCT book_id FROM order_items WHERE book_id IN ({$min})"), 'book_id'));
            $deletable = array_values(array_diff($mine, $ordered));
            if ($ordered) db_query("UPDATE books SET is_active = 0 WHERE id IN (" . implode(',', $ordered) . ")");
            if ($deletable) {
                $din = implode(',', $deletable);
                foreach (db_fetch_all("SELECT cover_image FROM books WHERE id IN ({$din})") as $cv) {
                    if (!empty($cv['cover_image']) && strpos($cv['cover_image'], 'cover_') === 0) @unlink(UPLOAD_DIR . '/' . basename($cv['cover_image']));
                }
                db_query("DELETE FROM book_showcase WHERE book_id IN ({$din})");
                db_query("DELETE FROM book_collection WHERE book_id IN ({$din})");
                db_query("DELETE FROM books WHERE id IN ({$din}) AND store_id = ?", [$report_store_id]);
            }
            log_action('Sahaf Kitap Sildi', 'Products', null, $store['name'] . ': ' . count($deletable) . ' silindi, ' . count($ordered) . ' satıştan kaldırıldı');
            set_flash('success', count($deletable) . ' kitap silindi.' . ($ordered ? ' Siparişi olan ' . count($ordered) . ' kitap silinmedi, satıştan kaldırıldı.' : ''));
        }
    }
    redirect('index.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', 'durum' => $_POST['durum'] ?? '', 'page' => $_POST['page'] ?? ''])));
}
$report_base     = 'index.php?tab=rapor&';
$tab             = in_array($_GET['tab'] ?? '', ['rapor', 'mesaj'], true) ? $_GET['tab'] : 'dukkan';

// Müşteri sorusuna yanıt (yalnızca bu dükkânın soruları)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bq_reply') {
    if (!hash_equals($store_token, (string)($_POST['token'] ?? ''))) {
        set_flash('warning', 'Oturum süresi doldu, tekrar deneyin.');
    } else {
        [$ok, $msg] = bq_reply((int)($_POST['qid'] ?? 0), $report_store_id, $_POST['reply'] ?? '');
        set_flash($ok ? 'success' : 'danger', $msg);
    }
    redirect('index.php?tab=mesaj');
}
$bq_unread = bq_unread_count($report_store_id);

// NadirKitap Senkronizasyon İsteği (Tek tıkla satılanları düşür & yeni kitapları çek)
// Önce son tam aktarımlara bakılır (NadirKitap sunucudan taramayı engelliyor); drop_unseen: görünmeyenler listesini onaylayarak düşür
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['sync_nk', 'drop_unseen'], true)) {
    if ($_POST['action'] === 'drop_unseen' && !hash_equals($store_token, (string)($_POST['token'] ?? ''))) {
        set_flash('warning', 'Oturum süresi doldu, tekrar deneyin.');
        redirect('index.php');
    }
    $res = nk_sold_sync($report_store_id, $_POST['action'] === 'drop_unseen');
    if (!empty($res['success'])) {
        set_flash('success', $res['message']);
    } else {
        set_flash('warning', $res['message']);
    }
    redirect('index.php');
}

// NadirKitap Mağaza Kodu / Link Güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_nk_url') {
    $new_input = trim(sanitize($_POST['nadirkitap_input'] ?? ''));
    $extracted_code = extract_nadirkitap_code($new_input);
    $new_url = (stripos($new_input, 'http') === 0) ? $new_input : ($extracted_code ? "https://www.nadirkitap.com/kitapara.php?satici={$extracted_code}" : '');
    db_query("UPDATE stores SET nadirkitap_code = ?, nadirkitap_url = ? WHERE id = ?", [
        $extracted_code ?: null,
        $new_url ?: null,
        $report_store_id
    ]);
    set_flash('success', 'NadirKitap mağaza bağlantınız güncellendi.');
    redirect('index.php');
}

$book_count      = (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE store_id = ? AND is_active = 1", [$report_store_id])['cnt'] ?? 0);

if ($tab === 'dukkan') {
    $unseen = nk_unseen_candidates($report_store_id);
    $stats = db_fetch("SELECT COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active, SUM(CASE WHEN is_active = 1 AND stock > 0 THEN stock ELSE 0 END) as units,
                              SUM(CASE WHEN is_active = 1 AND stock > 0 THEN price * stock ELSE 0 END) as stock_value
                       FROM books WHERE store_id = ?", [$report_store_id]);
    // Son 30 gün: ödemesi alınmış siparişlerdeki kendi kitapları
    $sales30 = db_fetch("SELECT COALESCE(SUM(oi.quantity), 0) as qty, COALESCE(SUM(oi.total), 0) as revenue, COALESCE(SUM(oi.commission_amount), 0) as commission
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN books b ON b.id = oi.book_id
                         WHERE b.store_id = ? AND o.status NOT IN ('İptal', 'Ödeme Bekliyor') AND o.created_at >= ?", [$report_store_id, gmdate('Y-m-d H:i:s', strtotime('-30 days'))]);

    $q = trim(sanitize($_GET['q'] ?? ''));
    $status = in_array($_GET['durum'] ?? '', ['aktif', 'pasif', 'tukendi'], true) ? $_GET['durum'] : '';
    $where = ["b.store_id = ?"];
    $params = [$report_store_id];
    if ($q !== '') {
        $where[] = "(b.title LIKE ? OR a.name LIKE ? OR b.isbn LIKE ? OR b.shelf_code LIKE ?)";
        array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if ($status === 'aktif') $where[] = "b.is_active = 1 AND b.stock > 0";
    if ($status === 'pasif') $where[] = "b.is_active = 0";
    if ($status === 'tukendi') $where[] = "b.is_active = 1 AND b.stock <= 0";
    $wsql = implode(' AND ', $where);
    $per = 30;
    $total = (int)(db_fetch("SELECT COUNT(*) as cnt FROM books b LEFT JOIN authors a ON a.id = b.author_id WHERE {$wsql}", $params)['cnt'] ?? 0);
    $pages = max(1, (int)ceil($total / $per));
    $pg = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $my_books = db_fetch_all("SELECT b.*, a.name as author_name, c.name as category_name,
                                     (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.book_id = b.id AND o.status NOT IN ('İptal', 'Ödeme Bekliyor')) as sold
                              FROM books b LEFT JOIN authors a ON a.id = b.author_id LEFT JOIN categories c ON c.id = b.category_id
                              WHERE {$wsql} ORDER BY b.id DESC LIMIT {$per} OFFSET " . (($pg - 1) * $per), $params);
    $qs = function ($extra) use ($q, $status) {
        return 'index.php?' . http_build_query(array_filter(array_merge(['q' => $q, 'durum' => $status], $extra), function ($v) { return $v !== '' && $v !== null && $v !== 1; }));
    };
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Satıcı Paneli | <?= e($store['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="bg-dark text-white py-2 mb-4 shadow">
        <div class="container-xl d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <span class="bg-white rounded-3 p-1"><img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 30px;"></span>
                <div>
                    <div class="fw-bold"><i class="bi bi-shop text-danger me-1"></i><?= e($store['name']) ?></div>
                    <small class="text-secondary">Satıcı Paneli • <?= $book_count ?> aktif kitap</small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="../magaza.php?s=<?= urlencode($store['slug']) ?>" target="_blank" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Vitrinim</a>
                <a href="logout.php" class="btn btn-danger btn-sm"><i class="bi bi-power me-1"></i>Çıkış</a>
            </div>
        </div>
    </nav>
    <main class="container-xl pb-5">
        <?= get_flash() ?>
        <?php
        $my_campaigns = array_filter(campaigns_active(), function ($c) use ($report_store_id) {
            return in_array($c['scope_type'], ['all', 'store'], true) && campaign_matches($c, ['id' => 0, 'store_id' => $report_store_id, 'category_id' => 0, 'author_id' => 0, 'publisher_id' => 0]);
        });
        ?>
        <?php if ($my_campaigns): ?>
            <div class="alert alert-warning border rounded-4 fs-7 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-gift-fill me-1"></i> Dükkânınızda geçerli kampanyalar</div>
                <?php foreach ($my_campaigns as $c): ?>
                    <div>• <b><?= e($c['name']) ?></b><?= $c['description'] ? ' — ' . e($c['description']) : '' ?><?= $c['ends_at'] ? ' <span class="text-muted">(' . date('d.m.Y H:i', strtotime($c['ends_at'])) . ' tarihine kadar)</span>' : '' ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm border">
            <li class="nav-item"><a class="nav-link fw-semibold <?= $tab === 'dukkan' ? 'active bg-danger text-white' : 'text-secondary' ?>" href="index.php"><i class="bi bi-shop me-1"></i> Dükkânım</a></li>
            <li class="nav-item"><a class="nav-link fw-semibold <?= $tab === 'rapor' ? 'active bg-danger text-white' : 'text-secondary' ?>" href="index.php?tab=rapor"><i class="bi bi-graph-up-arrow me-1"></i> Ziyaret & Satış Raporu</a></li>
            <li class="nav-item"><a class="nav-link fw-semibold <?= $tab === 'mesaj' ? 'active bg-danger text-white' : 'text-secondary' ?>" href="index.php?tab=mesaj"><i class="bi bi-chat-dots me-1"></i> Mesajlar<?= $bq_unread ? ' <span class="badge bg-warning text-dark">' . $bq_unread . '</span>' : '' ?></a></li>
        </ul>

        <?php if ($tab === 'rapor'): ?>
            <?php include __DIR__ . '/../admin/modules/reports.php'; ?>
        <?php elseif ($tab === 'mesaj'): ?>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white p-3"><b>Müşteri Soruları</b> <span class="text-muted fs-8">— kitap sayfalarındaki “Satıcıya soru sor” ile gelir. Yanıtınız müşteriye e-postayla gider; müşterinin e-posta adresi gizlidir.</span></div>
                <?= bq_list_html(bq_list($report_store_id), 'index.php?tab=mesaj', $store_token) ?>
            </div>
        <?php else: ?>
            <!-- AKTARIM UYGULAMASI: indirme ve giriş bilgileri -->
            <?php $app_url = trim(setting('nk_app_download_url', '')) ?: 'https://github.com/yenicerikitapevi/yenicerikitap/releases/download/kitapekapp-v1.4.2/kitapekapp-1.4.2-dagitim.zip'; // Site Ayarları'nda adres girilmediyse GitHub sürümü ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
                <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div style="max-width: 760px;">
                        <h6 class="fw-bold text-dark mb-1"><i class="bi bi-pc-display text-danger me-1"></i> Aktarım Uygulaması (kitapekapp)</h6>
                        <p class="text-muted fs-7 mb-2">NadirKitap'taki kitaplarınızı bilgisayarınızdan dükkânınıza aktarır ve günceller. Windows bilgisayarda çalışır; Google Chrome kurulu olmalıdır.</p>
                        <ol class="fs-7 mb-0 ps-3">
                            <li>Uygulamayı indirin. Zip dosyasına <b>sağ tıklayıp "Tümünü ayıkla"</b> deyin ve bir klasöre (ör. Masaüstü) çıkarın; çıkan klasörden <b>kitapekapp-chrome.bat</b> ile açın.
                                <div class="text-danger fs-8 mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Uygulamayı zip'in içinden çift tıklayarak açmayın ve <b>kitapekapp.exe</b> dosyasının adını değiştirmeyin; ikisi de "Error opening … kitapekapp.cfg" hatası verir.</div></li>
                            <li>Giriş bilgileri: <b>Kullanıcı adı</b> <code><?= e($store['username']) ?></code> • <b>Şifre</b> bu panelin şifresi • <b>NadirKitap kodu</b> <code><?= e($store['nadirkitap_code'] ?: '—') ?></code></li>
                            <li>Açılan Chrome penceresinde NadirKitap güvenlik doğrulaması çıkarsa siz tamamlayın, aktarım kendiliğinden devam eder.</li>
                        </ol>
                    </div>
                    <div class="text-center">
                        <?php if ($app_url !== ''): ?>
                            <a href="<?= e($app_url) ?>" class="btn btn-dark fw-semibold px-4 py-2 rounded-3" target="_blank" rel="noopener"><i class="bi bi-download me-1"></i> Aktarım uygulamasını indir</a>
                            <div class="text-muted fs-8 mt-1">Windows • yaklaşık 380 MB</div>
                        <?php else: ?>
                            <div class="text-muted fs-7">İndirme bağlantısı henüz eklenmedi;<br>site yöneticisinden isteyin.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- NADİRKİTAP SENKRONİZASYON VE SATILANLARI DÜŞÜRME KARTI -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden border-start border-danger border-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div style="max-width: 720px;">
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <span class="badge bg-danger text-white rounded-pill px-3 py-1 fs-8"><i class="bi bi-arrow-repeat me-1"></i>NadirKitap Senkronizasyonu</span>
                                <?php if (!empty($store['nadirkitap_code'])): ?>
                                    <span class="badge bg-light text-dark border rounded-pill px-2 py-1 fs-8">NK Mağaza No: <?= e($store['nadirkitap_code']) ?></span>
                                <?php endif; ?>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">Satılan Kitapları Düşürme</h6>
                            <p class="text-muted fs-7 mb-2">
                                NadirKitap sunucumuzdan gelen taramayı engellediği için satılanlar <b>aktarım uygulamasıyla</b> tespit edilir:
                                uygulamada <b>"Daha önce aktarılmış kitapları da yeniden güncelle"</b> işaretli aktarım yapın (250'den fazla kitabınız varsa
                                sonraki sayfalardan devam ederek tüm mağazayı aktarın). Son <?= NK_RUN_WINDOW_HOURS ?> saatteki bu aktarımlarda görünmeyen kitaplar satılmış sayılır.
                            </p>
                            <div class="fs-7 <?= $unseen['complete'] ? 'text-success' : 'text-secondary' ?>"><i class="bi bi-info-circle me-1"></i><?= e($unseen['note']) ?></div>
                            <?php if ($unseen['candidates']): ?>
                                <details class="fs-7 mt-2">
                                    <summary class="fw-semibold text-danger">NadirKitap'ta görünmeyen <?= count($unseen['candidates']) ?> kitap (satılmış olabilir)</summary>
                                    <ul class="mb-0 mt-1" style="max-height: 220px; overflow: auto;">
                                        <?php foreach (array_slice($unseen['candidates'], 0, 200) as $cb): ?>
                                            <li><?= e($cb['title']) ?> <span class="text-muted">— NK #<?= e($cb['nadirkitap_code']) ?>, <?= format_price($cb['price']) ?></span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                            <?php if (!empty($store['last_sync_at'])): ?>
                                <small class="text-secondary d-block mt-2 fs-8">
                                    <i class="bi bi-clock-history text-danger me-1"></i>Son Eşitleme: <b><?= date('d.m.Y H:i', strtotime($store['last_sync_at'])) ?></b>
                                    <?= !empty($store['last_sync_result']) ? ' — ' . e($store['last_sync_result']) : '' ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div>
                            <form method="POST" action="" onsubmit="var btn=this.querySelector('button'); btn.disabled=true; btn.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span> Eşitleniyor...';">
                                <input type="hidden" name="action" value="sync_nk">
                                <button type="submit" class="btn btn-danger font-semibold px-4 py-2 rounded-3 shadow">
                                    <i class="bi bi-lightning-charge-fill me-1"></i> Satılanları Düşür
                                </button>
                            </form>
                            <?php if ($unseen['candidates'] && $unseen['complete'] && !$unseen['safe']): ?>
                                <form method="POST" action="" class="mt-2" onsubmit="return confirm('NadirKitap\'ta görünmeyen <?= count($unseen['candidates']) ?> kitap satıştan kaldırılsın mı? Listeyi kontrol ettiğinizden emin olun.');">
                                    <input type="hidden" name="action" value="drop_unseen">
                                    <input type="hidden" name="token" value="<?= e($store_token) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-check2-square me-1"></i> Listeyi kontrol ettim, <?= count($unseen['candidates']) ?> kitabı kaldır</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="bg-white rounded-4 shadow-sm p-4 h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h1 class="h5 fw-bold mb-0"><i class="bi bi-shop text-danger me-1"></i><?= e($store['name']) ?></h1>
                            <span class="badge bg-success">Aktif</span>
                        </div>
                        <?php if (!empty($store['description'])): ?><p class="text-muted fs-7"><?= nl2br(e($store['description'])) ?></p><?php endif; ?>
                        <div class="fs-7">
                            <?php if (!empty($store['city'])): ?><div><i class="bi bi-geo-alt text-danger me-1"></i><?= e($store['city']) ?></div><?php endif; ?>
                            <?php if (!empty($store['phone'])): ?><div><i class="bi bi-telephone text-danger me-1"></i><?= e($store['phone']) ?></div><?php endif; ?>
                            <?php if (!empty($store['nadirkitap_code'])): ?><div><i class="bi bi-link-45deg text-danger me-1"></i>NadirKitap kodu: <b><?= e($store['nadirkitap_code']) ?></b></div><?php endif; ?>
                            <div><i class="bi bi-percent text-danger me-1"></i>Komisyon oranı: <b>%<?= rtrim(rtrim(number_format(store_commission_rate($report_store_id), 2, ',', ''), '0'), ',') ?></b></div>
                            <div><i class="bi bi-person-badge text-danger me-1"></i>Kullanıcı adı: <code><?= e($store['username']) ?></code></div>
                        </div>
                        <a href="../magaza.php?s=<?= urlencode($store['slug']) ?>" target="_blank" class="btn btn-danger btn-sm mt-3"><i class="bi bi-box-arrow-up-right me-1"></i> Vitrin sayfamı aç</a>
                        <div class="text-muted fs-8 mt-2">Dükkân bilgilerinizin değişmesi için site yöneticisiyle iletişime geçin.</div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="row g-3">
                        <?php foreach ([
                            ['Satıştaki kitap', number_format((int)$stats['active'], 0, ',', '.'), 'bi-book', number_format((int)$stats['total'], 0, ',', '.') . ' kayıt toplam'],
                            ['Stoktaki adet', number_format((int)$stats['units'], 0, ',', '.'), 'bi-box-seam', 'Değeri ' . format_price($stats['stock_value'])],
                            ['Son 30 gün satış', number_format((int)$sales30['qty'], 0, ',', '.') . ' kitap', 'bi-bag-check', format_price($sales30['revenue']) . ' ciro'],
                            ['Son 30 gün hakediş', format_price($sales30['revenue'] - $sales30['commission']), 'bi-wallet2', 'Komisyon: ' . format_price($sales30['commission'])],
                        ] as $card): ?>
                            <div class="col-6">
                                <div class="bg-white rounded-4 shadow-sm p-3 h-100">
                                    <div class="text-muted fs-8 fw-semibold text-uppercase"><i class="bi <?= $card[2] ?> text-danger me-1"></i><?= $card[0] ?></div>
                                    <div class="fs-4 fw-extrabold mt-1"><?= $card[1] ?></div>
                                    <div class="text-muted fs-8"><?= $card[3] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h2 class="h5 fw-bold mb-0"><i class="bi bi-book text-danger me-1"></i> Kitaplarım</h2>
                <a href="kitap.php" class="btn btn-danger fw-bold rounded-3 shadow-sm"><i class="bi bi-plus-lg me-1"></i> Yeni Kitap Ekle</a>
            </div>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 p-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-center">
                        <div class="col-md-6"><input type="search" name="q" class="form-control" placeholder="Kitap adı, yazar, ISBN veya raf kodu..." value="<?= e($q) ?>"></div>
                        <div class="col-md-3">
                            <select name="durum" class="form-select">
                                <option value="">Tüm kitaplarım</option>
                                <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Satışta</option>
                                <option value="tukendi" <?= $status === 'tukendi' ? 'selected' : '' ?>>Stokta yok</option>
                                <option value="pasif" <?= $status === 'pasif' ? 'selected' : '' ?>>Satıştan kaldırılmış</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex gap-2"><button class="btn btn-dark w-100">Filtrele</button><?php if ($q !== '' || $status !== ''): ?><a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a><?php endif; ?></div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <form method="POST" action="index.php" id="bulkForm">
                    <input type="hidden" name="action" value="bulk">
                    <input type="hidden" name="token" value="<?= e($store_token) ?>">
                    <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="durum" value="<?= e($status) ?>"><input type="hidden" name="page" value="<?= (int)$pg ?>">
                    <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-light flex-wrap">
                        <span class="fs-8 text-muted">Seçilenleri:</span>
                        <select name="op" class="form-select form-select-sm" style="max-width: 200px;">
                            <option value="">-- İşlem seçin --</option>
                            <option value="activate">Satışa al</option>
                            <option value="deactivate">Satıştan kaldır</option>
                            <option value="delete">Sil</option>
                        </select>
                        <button class="btn btn-sm btn-dark">Uygula</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 fs-7">
                            <thead class="table-light fs-8"><tr><th class="ps-3" style="width: 30px;"><input type="checkbox" class="form-check-input" id="bkAll"></th><th>Kitap</th><th>Kategori / Raf</th><th class="text-end">Fiyat</th><th class="text-end">Stok</th><th class="text-end">Satılan</th><th>Durum</th><th class="pe-3"></th></tr></thead>
                            <tbody>
                                <?php if (!$my_books): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-5"><?= $total === 0 && $q === '' && $status === '' ? 'Henüz kitabınız yok. "Yeni Kitap Ekle" ile ya da aktarım uygulamasıyla ekleyebilirsiniz.' : 'Aramaya uygun kitap bulunamadı.' ?></td></tr>
                                <?php endif; ?>
                                <?php foreach ($my_books as $b): ?>
                                    <tr>
                                        <td class="ps-3"><input type="checkbox" class="form-check-input bk-row" name="ids[]" value="<?= (int)$b['id'] ?>"></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="../<?= e(cover_url($b['cover_image'] ?? '')) ?>" alt="" width="34" height="50" style="width: 34px; height: 50px; object-fit: cover;" class="rounded border bg-white" loading="lazy">
                                                <div>
                                                    <div class="fw-semibold"><?= e($b['title']) ?></div>
                                                    <div class="text-muted fs-8"><?= e($b['author_name']) ?><?= $b['isbn'] ? ' • ISBN ' . e($b['isbn']) : '' ?><?= $b['nadirkitap_code'] ? ' • NK #' . e($b['nadirkitap_code']) : '' ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="fs-8"><?= e(is_numeric_category_name($b['category_name']) ? '—' : $b['category_name']) ?><?= $b['shelf_code'] ? '<div><span class="badge bg-warning text-dark">Raf: ' . e($b['shelf_code']) . '</span></div>' : '' ?></td>
                                        <td class="text-end fw-bold text-danger"><?= format_price($b['price']) ?></td>
                                        <td class="text-end"><input type="number" min="0" max="100000" class="form-control form-control-sm js-stock text-end ms-auto" style="width: 76px;" value="<?= (int)$b['stock'] ?>" data-id="<?= (int)$b['id'] ?>" data-url="index.php" data-token="<?= e($store_token) ?>" title="Stoğu yazıp Enter'a basın; hemen kaydedilir"></td>
                                        <td class="text-end"><?= (int)$b['sold'] ?></td>
                                        <td><?= !$b['is_active'] ? '<span class="badge bg-secondary">Kaldırıldı</span>' : ((int)$b['stock'] > 0 ? '<span class="badge bg-success">Satışta</span>' : '<span class="badge bg-warning text-dark">Stok yok</span>') ?></td>
                                        <td class="pe-3 text-end text-nowrap"><a href="kitap.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-danger" title="Düzenle"><i class="bi bi-pencil"></i></a><?php if ($b['is_active']): ?> <a href="../<?= e(book_path($b)) ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Sitede gör"><i class="bi bi-eye"></i></a><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </form>
                    <?php if ($pages > 1): ?>
                        <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <small class="text-muted">Sayfa <?= $pg ?> / <?= $pages ?> (<?= $total ?> kitap)</small>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $pg <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($qs(['page' => $pg - 1])) ?>">&laquo;</a></li>
                                <?php for ($i = max(1, $pg - 3); $i <= min($pages, $pg + 3); $i++): ?>
                                    <li class="page-item <?= $i === $pg ? 'active' : '' ?>"><a class="page-link" href="<?= e($qs(['page' => $i])) ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?= $pg >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($qs(['page' => $pg + 1])) ?>">&raquo;</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-muted fs-8 mt-3"><i class="bi bi-info-circle me-1"></i>Kitaplarınızı buradan ekleyip düzenleyebilir ya da aktarım uygulamasıyla NadirKitap'tan aktarabilirsiniz. NadirKitap'tan gelen kitaplarda eşitleme fiyat ve stoğu yeniden güncelleyebilir. Fiyatlarınızı yalnızca siz belirlersiniz.</div>
            <script>
                document.getElementById('bkAll').addEventListener('change', function () {
                    document.querySelectorAll('.bk-row').forEach(c => c.checked = this.checked);
                });
                document.getElementById('bulkForm').addEventListener('submit', function (e) {
                    if (!this.querySelector('.bk-row:checked')) { alert('Önce kitap seçin.'); e.preventDefault(); return; }
                    if (!this.op.value) { alert('Bir işlem seçin.'); e.preventDefault(); return; }
                    if (this.op.value === 'delete' && !confirm('Seçilen kitaplar silinsin mi? Siparişi olan kitaplar silinmez, satıştan kaldırılır.')) e.preventDefault();
                });
            </script>
        <?php endif; ?>
    </main>
<script src="../assets/js/stock-edit.js?v=1" defer></script>
</body>
</html>
