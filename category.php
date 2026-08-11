<?php
/**
 * STOREFRONT: Category Listing Page (category.php)
 */
require_once __DIR__ . '/config.php';

$cat_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$query  = sanitize($_GET['q'] ?? '');
$type   = sanitize($_GET['type'] ?? 'all');

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

$books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name, c.name as category_name 
                       FROM books b 
                       LEFT JOIN authors a ON b.author_id = a.id 
                       LEFT JOIN publishers p ON b.publisher_id = p.id 
                       LEFT JOIN categories c ON b.category_id = c.id 
                       WHERE {$where_sql} 
                       ORDER BY b.id DESC", $params);

$all_categories = db_fetch_all("SELECT * FROM categories ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitap Kataloğu | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
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
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center gap-2">
                <i class="bi bi-book-half fs-2 text-danger"></i>
                <span class="fw-extrabold fs-4">YENİÇERİ KİTABEVİ</span>
            </a>
            <button class="btn btn-outline-light text-white rounded-pill px-3 py-2 fw-bold" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer">
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
                                <a href="category.php?id=<?= $c['id'] ?>" class="list-group-item px-0 py-1 border-0 text-decoration-none <?= $cat_id === $c['id'] ? 'fw-bold text-danger' : 'text-secondary' ?>">
                                    <?= e($c['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h4 class="fw-extrabold text-dark mb-0">
                        <?php if (!empty($query)): ?>
                            "<?= e($query) ?>" Arama Sonuçları
                        <?php else: ?>
                            Kitap Kataloğu
                        <?php endif; ?>
                    </h4>
                    <span class="badge bg-light text-dark border px-3 py-2 fs-7"><?= count($books) ?> Eser Bulundu</span>
                </div>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
