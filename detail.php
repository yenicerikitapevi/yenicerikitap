<?php
/**
 * STOREFRONT: Book Detail Page (detail.php)
 */
require_once __DIR__ . '/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$book = db_fetch("SELECT b.*, a.name as author_name, a.bio as author_bio, p.name as publisher_name, c.name as category_name, s.name as store_name, s.slug as store_slug 
                  FROM books b 
                  LEFT JOIN authors a ON b.author_id = a.id 
                  LEFT JOIN publishers p ON b.publisher_id = p.id 
                  LEFT JOIN categories c ON b.category_id = c.id 
                  LEFT JOIN stores s ON b.store_id = s.id 
                  WHERE b.id = ? AND b.is_active = 1", [$id]);

if (!$book) {
    header("Location: index.php");
    exit;
}

$related_books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name 
                               FROM books b 
                               LEFT JOIN authors a ON b.author_id = a.id 
                               LEFT JOIN publishers p ON b.publisher_id = p.id 
                               WHERE b.category_id = ? AND b.id != ? AND b.is_active = 1 
                               LIMIT 4", [$book['category_id'], $id]);

$img_src = cover_url($book['cover_image'] ?? '');
track_view('detail', $book['id'], $book['store_id'] ?? null);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($book['title']) ?> - <?= e($book['author_name']) ?> | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="top-bar py-2 border-bottom border-dark">
        <div class="container d-flex align-items-center justify-content-between">
            <div><i class="bi bi-geo-alt-fill text-danger me-1"></i> Etimesgut / Ankara</div>
            <div><a href="index.php" class="text-white text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Mağazaya Dön</a></div>
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
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb fs-7">
                <li class="breadcrumb-item"><a href="index.php">Anasayfa</a></li>
                <li class="breadcrumb-item"><a href="category.php?id=<?= $book['category_id'] ?>"><?= e($book['category_name']) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($book['title']) ?></li>
            </ol>
        </nav>

        <?php
        $gallery_images = [$img_src];
        if (!empty($book['extra_images'])) {
            $decoded_imgs = json_decode($book['extra_images'], true);
            if (is_array($decoded_imgs)) {
                $gallery_images = [];
                foreach ($decoded_imgs as $g_img) {
                    $gallery_images[] = cover_url($g_img);
                }
            }
        }
        ?>
        <div class="row g-5">
            <div class="col-md-5 col-lg-4">
                <div class="bg-white p-4 rounded-4 shadow-sm text-center border">
                    <img src="<?= e($img_src) ?>" id="mainBookCover" alt="<?= e($book['title']) ?>" class="img-fluid rounded-3 shadow" style="max-height: 420px; object-fit: cover;" onerror="this.src='assets/images/book_placeholder.svg'">
                    <?php if (count($gallery_images) > 1): ?>
                        <div class="d-flex justify-content-center gap-2 mt-3 flex-wrap">
                            <?php foreach ($gallery_images as $g_url): ?>
                                <img src="<?= e($g_url) ?>" alt="" class="rounded border p-1 bg-light" style="width: 58px; height: 76px; object-fit: cover; cursor: pointer;" onclick="document.getElementById('mainBookCover').src = this.src;">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-7 col-lg-8">
                <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill font-bold mb-2"><?= e($book['category_name']) ?></span>
                <h1 class="fw-extrabold text-dark mb-2"><?= e($book['title']) ?></h1>
                
                <div class="fs-6 text-muted mb-3">
                    Yazar: <a href="category.php?q=<?= urlencode($book['author_name']) ?>&type=author" class="fw-bold text-dark text-decoration-none"><?= e($book['author_name']) ?></a> 
                    <span class="mx-2">•</span> 
                    Yayınevi: <span class="fw-semibold text-secondary"><?= e($book['publisher_name']) ?></span>
                    <?php if (!empty($book['store_name'])): ?>
                        <span class="mx-2">•</span>
                        Satıcı Dükkan: <a href="magaza.php?s=<?= urlencode($book['store_slug'] ?? '') ?>" class="badge bg-dark text-white text-decoration-none px-2 py-1"><i class="bi bi-shop text-danger me-1"></i><?= e($book['store_name']) ?></a>
                    <?php endif; ?>
                </div>

                <div class="d-flex align-items-baseline gap-3 my-4 p-3 bg-white rounded-3 border d-inline-flex">
                    <span class="display-6 fw-extrabold text-danger"><?= format_price($book['price']) ?></span>
                    <?php if ($book['old_price']): ?>
                        <span class="fs-5 text-muted text-decoration-line-through"><?= format_price($book['old_price']) ?></span>
                    <?php endif; ?>
                    <?php if ((int)$book['stock'] > 0): ?>
                        <span class="badge bg-success bg-opacity-10 text-success ms-2"><i class="bi bi-check-circle-fill me-1"></i> Stokta Var</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-2"><i class="bi bi-x-circle-fill me-1"></i> Tükendi</span>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <?php if ((int)$book['stock'] > 0): ?>
                        <button class="btn btn-danger btn-lg px-5 py-3 rounded-3 font-bold shadow btn-add-cart"
                                data-id="<?= $book['id'] ?>"
                                data-title="<?= e($book['title']) ?>"
                                data-price="<?= $book['price'] ?>"
                                data-image="<?= e($img_src) ?>">
                            <i class="bi bi-bag-plus-fill me-2 fs-5"></i> Sepete Ekle
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg px-5 py-3 rounded-3 font-bold" disabled>Stokta Yok</button>
                    <?php endif; ?>
                </div>

                <div class="bg-white p-4 rounded-4 border shadow-sm mb-4">
                    <h5 class="fw-bold text-dark mb-3">Kitap Özeti & Künye</h5>
                    <?php if (!empty($book['summary'])): ?>
                        <p class="text-secondary leading-relaxed mb-4"><?= nl2br(e($book['summary'])) ?></p>
                    <?php endif; ?>

                    <div class="row g-3 fs-7 border-top pt-3">
                        <div class="col-6 col-sm-3"><strong>ISBN:</strong> <?= e($book['isbn'] ?: '-') ?></div>
                        <div class="col-6 col-sm-3"><strong>Sayfa Sayısı:</strong> <?= e($book['pages'] ?: '-') ?></div>
                        <div class="col-6 col-sm-3"><strong>Basım Yılı:</strong> <?= e($book['year'] ?: '-') ?></div>
                        <?php if (!empty($book['condition_text'])): ?>
                            <div class="col-6 col-sm-3"><strong>Kondisyon:</strong> <?= e($book['condition_text']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($book['cover_type'])): ?>
                            <div class="col-6 col-sm-3"><strong>Cilt Durumu:</strong> <?= e($book['cover_type']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($book['dimensions'])): ?>
                            <div class="col-6 col-sm-3"><strong>Ebat:</strong> <?= e($book['dimensions']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($book['language'])): ?>
                            <div class="col-6 col-sm-3"><strong>Dil:</strong> <?= e($book['language']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($book['translator'])): ?>
                            <div class="col-6 col-sm-3"><strong>Çeviren / Haz.:</strong> <?= e($book['translator']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($book['publish_place'])): ?>
                            <div class="col-6 col-sm-3"><strong>Yayın Yeri:</strong> <?= e($book['publish_place']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($book['original_title'])): ?>
                            <div class="col-6 col-sm-3"><strong>Orijinal Adı:</strong> <?= e($book['original_title']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($book['author_bio'])): ?>
                    <div class="bg-light p-4 rounded-4 border">
                        <h6 class="fw-bold text-dark mb-2">Yazar Hakkında: <?= e($book['author_name']) ?></h6>
                        <p class="text-muted fs-7 mb-0"><?= e($book['author_bio']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($related_books)): ?>
            <div class="mt-5 pt-4 border-top">
                <h3 class="section-title">Benzer Kitap Önerileri</h3>
                <div class="row g-3 row-cols-2 row-cols-md-4">
                    <?php foreach ($related_books as $b): ?>
                        <div class="col">
                            <?php include __DIR__ . '/_book_card.php'; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
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
    <script src="assets/js/app.js"></script>
    <?= track_script() ?>
</body>
</html>
