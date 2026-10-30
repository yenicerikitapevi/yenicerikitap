<?php
/**
 * STOREFRONT: Alt Dükkan (Satıcı / Sahaf Mağazası) Vitrin Sayfası (magaza.php)
 */
require_once __DIR__ . '/config.php';

$slug     = sanitize($_GET['s'] ?? '');
$store_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$store = null;
if ($slug !== '') {
    $store = db_fetch("SELECT * FROM stores WHERE slug = ? AND is_active = 1", [$slug]);
} elseif ($store_id > 0) {
    $store = db_fetch("SELECT * FROM stores WHERE id = ? AND is_active = 1", [$store_id]);
}

$categories = db_fetch_all("SELECT * FROM categories ORDER BY name ASC");

// Eğer belirli bir alt dükkan seçilmişse o dükkanın kitaplarını filtrele ve listele
if ($store) {
    $sid    = (int)$store['id'];
    $cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
    $query  = sanitize($_GET['q'] ?? '');
    $sort   = sanitize($_GET['sort'] ?? 'newest');

    $where  = ["b.is_active = 1", "b.store_id = ?"];
    $params = [$sid];

    if ($cat_id > 0) {
        $where[]  = "b.category_id = ?";
        $params[] = $cat_id;
    }

    if ($query !== '') {
        $where[]  = "(b.title LIKE ? OR a.name LIKE ? OR p.name LIKE ? OR b.isbn LIKE ?)";
        array_push($params, "%$query%", "%$query%", "%$query%", "%$query%");
    }

    $where_sql = implode(' AND ', $where);

    $order_sql = "b.id DESC";
    if ($sort === 'price_asc') {
        $order_sql = "b.price ASC, b.id DESC";
    } elseif ($sort === 'price_desc') {
        $order_sql = "b.price DESC, b.id DESC";
    } elseif ($sort === 'title_asc') {
        $order_sql = "b.title ASC";
    }

    $page        = max(1, (int)($_GET['page'] ?? 1));
    $per_page    = 36;
    $total_books = (int)(db_fetch("SELECT COUNT(*) as cnt 
                                   FROM books b 
                                   LEFT JOIN authors a ON b.author_id = a.id 
                                   LEFT JOIN publishers p ON b.publisher_id = p.id 
                                   WHERE {$where_sql}", $params)['cnt'] ?? 0);
    $total_pages = max(1, (int)ceil($total_books / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name, c.name as category_name, s.name as store_name, s.slug as store_slug 
                           FROM books b 
                           LEFT JOIN authors a ON b.author_id = a.id 
                           LEFT JOIN publishers p ON b.publisher_id = p.id 
                           LEFT JOIN categories c ON b.category_id = c.id 
                           LEFT JOIN stores s ON b.store_id = s.id 
                           WHERE {$where_sql} 
                           ORDER BY {$order_sql} 
                           LIMIT {$per_page} OFFSET {$offset}", $params);

    // Bu alt dükkanda kitabı bulunan kategorileri ve adetlerini çek
    $store_categories = db_fetch_all("SELECT c.id, c.name, COUNT(b.id) as book_cnt 
                                      FROM categories c 
                                      JOIN books b ON b.category_id = c.id 
                                      WHERE b.store_id = ? AND b.is_active = 1 
                                      GROUP BY c.id, c.name 
                                      ORDER BY book_cnt DESC, c.name ASC", [$sid]);
} else {
    $all_stores = db_fetch_all("SELECT s.*, (SELECT COUNT(*) FROM books b WHERE b.store_id = s.id AND b.is_active = 1) as book_count 
                                FROM stores s 
                                WHERE s.is_active = 1 
                                ORDER BY s.id ASC");
}
track_view('store', null, $store['id'] ?? null);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    if ($store) {
        $m_head = ['title' => seo_title($store['name'] . ' - Sahaf'), 'description' => seo_description(trim(($store['description'] ?: $store['name'] . ' sahaf kitapları') . ($store['city'] ? ', ' . $store['city'] : '') . '. İkinci el ve nadir kitaplar Yeniçeri Kitabevi\'nde.')), 'canonical' => 'magaza.php?s=' . rawurlencode($store['slug']) . ((int)($_GET['page'] ?? 1) > 1 ? '&page=' . (int)$_GET['page'] : '')];
        if (($_GET['q'] ?? '') !== '' || !empty($_GET['cat']) || !empty($_GET['sort'])) $m_head['robots'] = 'noindex,follow';
        $m_head['jsonld'] = [jsonld_breadcrumb([['Ana Sayfa', ''], ['Sahaflar', 'magaza.php'], [$store['name'], null]])];
    } else {
        $m_head = ['title' => seo_title('Alt Dükkanlar & Sahaflar'), 'description' => seo_description('Yeniçeri Kitabevi\'ndeki sahaflar: ikinci el, nadir ve eski basım kitaplar tek adreste. Sahaf vitrinlerini keşfedin.'), 'canonical' => 'magaza.php', 'jsonld' => [jsonld_breadcrumb([['Ana Sayfa', ''], ['Sahaflar', null]])]];
    }
    echo seo_head($m_head);
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body>
    <div class="top-bar py-2 border-bottom border-dark">
        <div class="container d-flex align-items-center justify-content-between">
            <div><i class="bi bi-shop text-danger me-1"></i> Yeniçeri Kitabevi — Sahaf & Alt Dükkan Platformu</div>
            <div class="d-flex gap-3">
                <a href="magaza.php" class="text-white text-decoration-none"><i class="bi bi-grid me-1"></i> Tüm Alt Dükkanlar</a>
                <a href="index.php" class="text-white text-decoration-none"><i class="bi bi-house-door me-1"></i> Anasayfa</a>
            </div>
        </div>
    </div>

    <header class="bg-white py-3 border-bottom shadow-sm sticky-top">
        <div class="container d-flex align-items-center justify-content-between gap-3">
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center">
                <img src="assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 48px; max-width: 220px; object-fit: contain;">
            </a>
            <?php if ($store): ?>
                <form action="magaza.php" method="GET" class="flex-grow-1 d-none d-md-block" style="max-width: 520px;">
                    <input type="hidden" name="s" value="<?= e($store['slug']) ?>">
                    <div class="input-group search-bar-group">
                        <input type="text" name="q" class="form-control" value="<?= e($query) ?>" placeholder="<?= e($store['name']) ?> dükkanında kitap veya yazar ara...">
                        <button type="submit" class="btn btn-search"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            <?php endif; ?>
            <button class="btn btn-outline-dark rounded-pill px-3 py-2 fw-bold" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer">
                <i class="bi bi-bag-handle-fill text-danger me-1"></i> Sepetim <span class="badge bg-danger rounded-pill cart-count-badge">0</span>
            </button>
        </div>
    </header>

    <?php if ($store): ?>
        <!-- ALT DÜKKAN VİTRİN BAŞLIĞI -->
        <section class="py-4 bg-dark text-white border-bottom border-danger border-3">
            <div class="container">
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-danger text-white rounded-4 d-flex align-items-center justify-content-center shadow" style="width: 68px; height: 68px; font-size: 2rem;">
                                <i class="bi bi-shop"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="badge bg-danger text-white rounded-pill px-3 py-1">Onaylı Alt Dükkan</span>
                                    <?php if (!empty($store['city'])): ?>
                                        <span class="badge bg-secondary bg-opacity-50 text-light rounded-pill px-3 py-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= e($store['city']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <h1 class="h2 fw-extrabold text-white mt-2 mb-1"><?= e($store['name']) ?></h1>
                                <?php if (!empty($store['description'])): ?>
                                    <p class="text-light opacity-75 fs-7 mb-0"><?= e($store['description']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="d-inline-block bg-black bg-opacity-50 border border-secondary rounded-4 px-4 py-3 text-center">
                            <div class="display-6 fw-extrabold text-danger lh-1"><?= number_format($total_books, 0, ',', '.') ?></div>
                            <small class="text-light opacity-75 fw-semibold">Satıştaki Eser</small>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="container py-5">
            <div class="row g-4">
                <div class="col-lg-3">
                    <div class="card border-0 shadow-sm rounded-4 p-4 sticky-top" style="top: 100px;">
                        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-funnel-fill text-danger me-1"></i> Dükkan İçi Filtre</h5>

                        <form method="GET" action="magaza.php" class="mb-4">
                            <input type="hidden" name="s" value="<?= e($store['slug']) ?>">
                            <?php if ($cat_id > 0): ?>
                                <input type="hidden" name="cat" value="<?= $cat_id ?>">
                            <?php endif; ?>
                            <div class="mb-2">
                                <label class="form-label fs-7 fw-semibold">Bu Dükkanda Ara</label>
                                <input type="text" name="q" class="form-control" value="<?= e($query) ?>" placeholder="Kitap, yazar, yayınevi...">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fs-7 fw-semibold">Sıralama</label>
                                <select name="sort" class="form-select form-select-sm">
                                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>En Yeni Eklenenler</option>
                                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Fiyat (Artan)</option>
                                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Fiyat (Azalan)</option>
                                    <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : '' ?>>Kitap Adı (A-Z)</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-danger w-100 btn-sm font-semibold">Uygula</button>
                            <?php if ($query !== '' || $cat_id > 0 || $sort !== 'newest'): ?>
                                <a href="magaza.php?s=<?= urlencode($store['slug']) ?>" class="btn btn-outline-secondary w-100 btn-sm mt-2">Filtreyi Temizle</a>
                            <?php endif; ?>
                        </form>

                        <?php if (!empty($store_categories)): ?>
                            <div>
                                <h6 class="fw-bold fs-7 text-uppercase text-secondary mb-2">Dükkan Kategorileri</h6>
                                <div class="list-group list-group-flush fs-7" style="max-height: 360px; overflow-y: auto;">
                                    <a href="magaza.php?s=<?= urlencode($store['slug']) ?>" class="list-group-item px-0 py-1.5 border-0 d-flex justify-content-between align-items-center text-decoration-none <?= $cat_id === 0 ? 'fw-bold text-danger' : 'text-dark' ?>">
                                        <span>Tüm Eserler</span>
                                    </a>
                                    <?php foreach ($store_categories as $sc_cat): ?>
                                        <a href="magaza.php?s=<?= urlencode($store['slug']) ?>&cat=<?= $sc_cat['id'] ?>" class="list-group-item px-0 py-1.5 border-0 d-flex justify-content-between align-items-center text-decoration-none <?= $cat_id === (int)$sc_cat['id'] ? 'fw-bold text-danger' : 'text-secondary' ?>">
                                            <span class="text-truncate me-2"><?= e($sc_cat['name']) ?></span>
                                            <span class="badge bg-light text-dark border"><?= (int)$sc_cat['book_cnt'] ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-9">
                    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                        <h4 class="fw-extrabold text-dark mb-0">
                            <?= e($store['name']) ?> Vitrin Ürünleri
                            <?php if ($query !== ''): ?>
                                <small class="text-muted fs-6">("<?= e($query) ?>" araması)</small>
                            <?php endif; ?>
                        </h4>
                        <span class="badge bg-light text-dark border px-3 py-2 fs-7"><?= $total_books ?> Eser Listeleniyor</span>
                    </div>

                    <?php if (empty($books)): ?>
                        <div class="text-center py-5 bg-white rounded-4 border shadow-sm">
                            <i class="bi bi-book display-1 text-muted opacity-25 d-block mb-3"></i>
                            <h5>Bu alt dükkanda aramanıza uygun kitap bulunamadı.</h5>
                            <p class="text-muted fs-7">Henüz kitap aktarımı tamamlanmamış olabilir veya filtreyi temizleyerek tüm kitaplara göz atabilirsiniz.</p>
                            <a href="magaza.php?s=<?= urlencode($store['slug']) ?>" class="btn btn-danger font-semibold">Tüm Dükkan Kitaplarını Gör</a>
                        </div>
                    <?php else: ?>
                        <div class="row g-3 row-cols-2 row-cols-md-3">
                            <?php foreach ($books as $b): ?>
                                <div class="col">
                                    <?php include __DIR__ . '/_book_card.php'; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <?php
                            $base_params = $_GET;
                            unset($base_params['page']);
                            $build_page_url = function($p_num) use ($base_params) {
                                $base_params['page'] = $p_num;
                                return 'magaza.php?' . http_build_query($base_params);
                            };
                            ?>
                            <nav aria-label="Sayfalama" class="mt-4 pt-3">
                                <ul class="pagination justify-content-center flex-wrap gap-1">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link rounded-3 text-dark" href="<?= e($build_page_url($page - 1)) ?>">&laquo; Önceki</a>
                                    </li>
                                    <?php
                                    $start_p = max(1, $page - 3);
                                    $end_p = min($total_pages, $page + 3);
                                    if ($start_p > 1) {
                                        echo '<li class="page-item"><a class="page-link rounded-3 text-dark" href="' . e($build_page_url(1)) . '">1</a></li>';
                                        if ($start_p > 2) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>';
                                    }
                                    for ($i = $start_p; $i <= $end_p; $i++):
                                    ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link rounded-3 <?= $i === $page ? 'bg-danger border-danger text-white' : 'text-dark' ?>" href="<?= e($build_page_url($i)) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor;
                                    if ($end_p < $total_pages) {
                                        if ($end_p < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>';
                                        echo '<li class="page-item"><a class="page-link rounded-3 text-dark" href="' . e($build_page_url($total_pages)) . '">' . $total_pages . '</a></li>';
                                    }
                                    ?>
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link rounded-3 text-dark" href="<?= e($build_page_url($page + 1)) ?>">Sonraki &raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- TÜM ALT DÜKKANLAR LİSTESİ -->
        <div class="container py-5">
            <div class="text-center max-w-xl mx-auto mb-5">
                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 font-bold mb-2"><i class="bi bi-shop me-1"></i> Sahaf & Satıcı Mağazaları</span>
                <h1 class="fw-extrabold text-dark">Alt Dükkanlar & Sahaflar</h1>
                <p class="text-muted">Yeniçeri Kitabevi bünyesinde satış yapan anlaşmalı sahaflar ve özel koleksiyon dükkanları.</p>
            </div>

            <div class="row g-4">
                <?php foreach ($all_stores as $st): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card border-0 shadow-sm rounded-4 h-100 p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="bg-danger bg-opacity-10 text-danger rounded-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; font-size: 1.5rem;">
                                        <i class="bi bi-shop"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold text-dark mb-0"><?= e($st['name']) ?></h5>
                                        <?php if (!empty($st['city'])): ?>
                                            <small class="text-muted"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= e($st['city']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-secondary fs-7 mb-3"><?= e($st['description'] ?: 'Nadir ve ikinci el kitap koleksiyonu.') ?></p>
                            </div>
                            <div class="d-flex align-items-center justify-content-between pt-3 border-top">
                                <span class="badge bg-light text-dark border px-3 py-2"><i class="bi bi-book text-danger me-1"></i> <?= (int)$st['book_count'] ?> Eser</span>
                                <a href="magaza.php?s=<?= urlencode($st['slug']) ?>" class="btn btn-danger btn-sm rounded-pill px-3 fw-bold">
                                    Dükkanı Ziyaret Et <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="offcanvas offcanvas-end rounded-start-4" tabindex="-1" id="cartDrawer">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title fw-bold"><i class="bi bi-bag-fill text-danger me-2"></i> Alışveriş Sepetim</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body p-4 d-flex flex-column">
            <div id="cartDrawerList" class="flex-grow-1"></div>
            <div class="border-top pt-3 mt-auto">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="fw-bold text-dark">Toplam Tutar:</span>
                    <span class="fs-4 fw-extrabold text-danger" id="cartDrawerTotal">₺0,00</span>
                </div>
                <a href="checkout.php" class="btn btn-danger w-100 py-2.5 fw-bold rounded-3 shadow">Sepeti Onayla ve Öde <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= asset_v('assets/js/app.js') ?>"></script>
    <?= track_script() ?>
</body>
</html>
