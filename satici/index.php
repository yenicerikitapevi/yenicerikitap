<?php
/**
 * SATICI PANELİ: Sahaf yalnızca kendi dükkânını görür
 *  - Dükkânım: dükkân bilgileri, özet, kendi kitapları (arama + sayfalama)
 *  - Raporlar: kendi kitaplarının ve vitrin sayfasının ziyaret / satış raporu
 * Kitap ekleme/güncelleme aktarım uygulamasıyla yapılır; panel salt görüntülemedir.
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
$report_base     = 'index.php?tab=rapor&';
$tab             = ($_GET['tab'] ?? '') === 'rapor' ? 'rapor' : 'dukkan';

// NadirKitap Senkronizasyon İsteği (Tek tıkla satılanları düşür & yeni kitapları çek)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_nk') {
    $res = nk_sync_store($report_store_id, 10);
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
        </ul>

        <?php if ($tab === 'rapor'): ?>
            <?php include __DIR__ . '/../admin/modules/reports.php'; ?>
        <?php else: ?>
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
                            <h6 class="fw-bold text-dark mb-1">Satılan Kitapları Düşürme & Yeni Kitapları Çekme</h6>
                            <p class="text-muted fs-7 mb-0">
                                NadirKitap'ta satılan kitaplarınız otomatik tespit edilir ve bu sayfadan kaldırılır (stok sıfırlanır). Yeni eklenen kitaplar mağazanıza aktarılır.
                            </p>
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
                                    <i class="bi bi-lightning-charge-fill me-1"></i> NadirKitap ile Eşitle & Satılanları Düşür
                                </button>
                            </form>
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
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 fs-7">
                            <thead class="table-light fs-8"><tr><th class="ps-3">Kitap</th><th>Kategori / Raf</th><th class="text-end">Fiyat</th><th class="text-end">Stok</th><th class="text-end">Satılan</th><th>Durum</th><th class="pe-3"></th></tr></thead>
                            <tbody>
                                <?php if (!$my_books): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5"><?= $total === 0 && $q === '' && $status === '' ? 'Henüz kitabınız yok. Kitaplarınızı aktarım uygulamasıyla ekleyebilirsiniz.' : 'Aramaya uygun kitap bulunamadı.' ?></td></tr>
                                <?php endif; ?>
                                <?php foreach ($my_books as $b): ?>
                                    <tr>
                                        <td class="ps-3">
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
                                        <td class="text-end"><?= (int)$b['stock'] ?></td>
                                        <td class="text-end"><?= (int)$b['sold'] ?></td>
                                        <td><?= !$b['is_active'] ? '<span class="badge bg-secondary">Kaldırıldı</span>' : ((int)$b['stock'] > 0 ? '<span class="badge bg-success">Satışta</span>' : '<span class="badge bg-warning text-dark">Stok yok</span>') ?></td>
                                        <td class="pe-3 text-end"><?php if ($b['is_active']): ?><a href="../<?= e(book_path($b)) ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Sitede gör"><i class="bi bi-eye"></i></a><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
            <div class="text-muted fs-8 mt-3"><i class="bi bi-info-circle me-1"></i>Kitap ekleme, fiyat ve stok güncellemesi aktarım uygulamasıyla yapılır. Fiyatlarınızı yalnızca siz belirlersiniz.</div>
        <?php endif; ?>
    </main>
</body>
</html>
