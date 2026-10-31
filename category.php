<?php
/**
 * STOREFRONT: Category Listing Page (category.php)
 */
require_once __DIR__ . '/config.php';

$cat_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug   = trim((string)($_GET['slug'] ?? ''));
$query  = sanitize($_GET['q'] ?? '');
$type   = sanitize($_GET['type'] ?? 'all');
$category = null;

if ($slug !== '') {
    $category = db_fetch("SELECT * FROM categories WHERE slug = ?", [$slug]);
    if (!$category) {
        // Eski / değişmiş kategori adresi: kategoriye, olmazsa kitaba, o da olmazsa ana sayfaya kalıcı yönlendirme
        $moved = slug_redirect_target('category', $slug);
        $target = $moved ? db_fetch("SELECT id, slug FROM categories WHERE id = ?", [$moved]) : legacy_find_category($slug);
        if ($target) redirect_301(category_path($target));
        $book_target = legacy_find_book($slug);
        if ($book_target) redirect_301(book_path($book_target));
        redirect_301('');
    }
    $cat_id = (int)$category['id'];
} elseif ($cat_id > 0) {
    $category = db_fetch("SELECT * FROM categories WHERE id = ?", [$cat_id]);
    if (!$category) {
        require __DIR__ . '/404.php';
        exit;
    }
    if ($query === '') {
        // category.php?id=5 → /kategori/roman (sayfa numarası korunur)
        $page_q = (int)($_GET['page'] ?? 1) > 1 ? '?page=' . (int)$_GET['page'] : '';
        redirect_301(category_path($category) . $page_q);
    }
}

$where = ["b.is_active = 1"];
$params = [];

if ($cat_id > 0) {
    $where[] = "b.category_id = ?";
    $params[] = $cat_id;
}

if (!empty($query)) {
    if ($type === 'author') {
        $where[] = "a.name LIKE ?";
        $params[] = "%$query%";
    } elseif ($type === 'publisher') {
        $where[] = "p.name LIKE ?";
        $params[] = "%$query%";
    } elseif ($type === 'book') {
        $where[] = "b.title LIKE ?";
        $params[] = "%$query%";
    } else {
        $where[] = "(b.title LIKE ? OR a.name LIKE ? OR p.name LIKE ? OR b.isbn LIKE ?)";
        $params[] = "%$query%";
        $params[] = "%$query%";
        $params[] = "%$query%";
        $params[] = "%$query%";
    }
}

$where_sql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 36;
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
                       ORDER BY b.id DESC 
                       LIMIT {$per_page} OFFSET {$offset}", $params);

$all_categories = nav_categories();
track_view('category');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $page_suffix = $page > 1 ? '?page=' . $page : '';
    if ($query !== '') {
        $head = ['title' => seo_title('"' . $query . '" Arama Sonuçları'), 'description' => seo_description('"' . $query . '" araması için ' . $total_books . ' kitap bulundu.'), 'canonical' => 'category.php?q=' . rawurlencode($query), 'robots' => 'noindex,follow'];
        $crumbs = [['Ana Sayfa', ''], ['Arama: ' . $query, null]];
    } elseif ($category) {
        $cat_desc = trim((string)$category['meta_description']) !== '' ? $category['meta_description']
            : (trim((string)$category['description']) !== '' ? $category['description'] : $category['name'] . ' kitapları: ' . $total_books . ' eser, en uygun fiyatlarla.');
        $head = ['title' => seo_title($category['name'] . ' Kitapları'), 'description' => seo_description($cat_desc), 'canonical' => category_path($category) . $page_suffix];
        if (is_numeric_category_name($category['name'])) $head['robots'] = 'noindex,follow';
        $crumbs = [['Ana Sayfa', ''], [$category['name'], null]];
    } else {
        $head = ['title' => seo_title('Tüm Kitaplar' . ($page > 1 ? ' - Sayfa ' . $page : '')), 'description' => seo_description('Yeniçeri Kitabevi kitap kataloğu: roman, tarih, felsefe, dünya klasikleri ve çocuk kitapları dahil ' . $total_books . ' eser.'), 'canonical' => 'category.php' . $page_suffix];
        $crumbs = [['Ana Sayfa', ''], ['Tüm Kitaplar', null]];
    }
    if ($category && $page > 1) $head['title'] = seo_title($category['name'] . ' Kitapları - Sayfa ' . $page);
    $page_base = $query !== '' ? null : ($category ? category_path($category) : 'category.php');
    if ($page_base !== null) {
        if ($page > 1) $head['prev'] = $page_base . ($page > 2 ? '?page=' . ($page - 1) : '');
        if ($page < $total_pages) $head['next'] = $page_base . '?page=' . ($page + 1);
    }
    $head['jsonld'] = [jsonld_breadcrumb($crumbs)];
    echo seo_head($head);
    ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body>
    <div class="top-bar py-2 border-bottom border-dark">
        <div class="container d-flex align-items-center justify-content-between">
            <div><i class="bi bi-geo-alt-fill text-danger me-1"></i> Etimesgut / Ankara</div>
            <div><a href="index.php" class="text-white text-decoration-none"><i class="bi bi-house-door me-1"></i> Anasayfa</a></div>
        </div>
    </div>

    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center">
                <img src="assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 48px; max-width: 220px; object-fit: contain;">
            </a>
            <button class="btn btn-outline-dark rounded-pill px-3 py-2 fw-bold" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer">
                <i class="bi bi-bag-handle-fill text-danger me-1"></i> Sepetim <span class="badge bg-danger rounded-pill cart-count-badge">0</span>
            </button>
        </div>
    </header>

    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-4 sticky-top" style="top: 100px;">
                    <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-funnel-fill text-danger me-1"></i> Filtrele</h5>

                    <form method="GET" action="category.php" class="mb-4">
                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Kelime Ara</label>
                            <input type="text" name="q" class="form-control" value="<?= e($query) ?>" placeholder="Kitap veya yazar...">
                        </div>
                        <button type="submit" class="btn btn-danger w-100 btn-sm font-semibold">Sonuçları Getir</button>
                    </form>

                    <div class="mb-4">
                        <h6 class="fw-bold fs-7 text-uppercase text-secondary mb-2">Kategoriler</h6>
                        <div class="list-group list-group-flush fs-7">
                            <a href="category.php" class="list-group-item px-0 py-1 border-0 text-decoration-none <?= $cat_id === 0 ? 'fw-bold text-danger' : 'text-dark' ?>">Tüm Kategoriler</a>
                            <?php foreach ($all_categories as $c): ?>
                                <a href="<?= e(category_path($c)) ?>" class="list-group-item px-0 py-1 border-0 text-decoration-none <?= $cat_id === $c['id'] ? 'fw-bold text-danger' : 'text-secondary' ?>">
                                    <?= e($c['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="mb-2"><?= breadcrumb_html($crumbs) ?></div>
                <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
                    <h1 class="h4 fw-extrabold text-dark mb-0">
                        <?php if (!empty($query)): ?>
                            "<?= e($query) ?>" Arama Sonuçları
                        <?php elseif ($category): ?>
                            <?= e($category['name']) ?> Kitapları
                        <?php else: ?>
                            Tüm Kitaplar
                        <?php endif; ?>
                    </h1>
                    <span class="badge bg-light text-dark border px-3 py-2 fs-7"><?= $total_books ?> Eser Bulundu</span>
                </div>
                <?php if ($category && trim((string)$category['description']) !== '' && $page === 1 && $query === ''): ?>
                    <div class="bg-white border rounded-4 p-4 mb-4 fs-7 text-secondary category-intro">
                        <?php foreach (preg_split('/\n\s*\n/', trim($category['description'])) as $para): ?>
                            <p class="mb-2"><?= nl2br(e(trim($para))) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($books)): ?>
                    <div class="text-center py-5 bg-white rounded-4 border shadow-sm">
                        <i class="bi bi-search display-1 text-muted opacity-25 d-block mb-3"></i>
                        <h5>Aramanıza uygun kitap bulunamadı.</h5>
                        <p class="text-muted fs-7">Farklı anahtar kelimeler ile tekrar arama yapabilirsiniz.</p>
                        <a href="category.php" class="btn btn-danger font-semibold">Tüm Kataloğu Gör</a>
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
                        unset($base_params['page'], $base_params['slug'], $base_params['id']);
                        $page_path = $category && $query === '' ? category_path($category) : 'category.php';
                        $build_page_url = function($p_num) use ($base_params, $page_path) {
                            if ($p_num > 1) $base_params['page'] = $p_num;
                            return $page_path . ($base_params ? '?' . http_build_query($base_params) : '');
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
