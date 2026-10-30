<?php
/**
 * YENİÇERİ KİTABEVİ - MÜŞTERİ VİTRİNI (STOREFRONT) HOMEPAGE
 */
require_once __DIR__ . '/config.php';

$settings_rows = db_fetch_all("SELECT * FROM settings");
$site = [];
foreach ($settings_rows as $row) {
    $site[$row['setting_key']] = $row['setting_value'];
}

$categories = nav_categories();

$showcases = db_fetch_all("SELECT * FROM showcases ORDER BY id ASC");
$showcase_books = [];
foreach ($showcases as $sc) {
    $books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name, s.name as store_name, s.slug as store_slug 
                           FROM books b 
                           JOIN book_showcase bs ON b.id = bs.book_id 
                           LEFT JOIN authors a ON b.author_id = a.id 
                           LEFT JOIN publishers p ON b.publisher_id = p.id 
                           LEFT JOIN stores s ON b.store_id = s.id 
                           WHERE bs.showcase_id = ? AND b.is_active = 1 
                           ORDER BY b.id DESC LIMIT 8", [$sc['id']]);
    $showcase_books[$sc['id']] = $books;
}

$collections = db_fetch_all("SELECT * FROM collections ORDER BY id ASC");
$collection_books = [];
foreach ($collections as $col) {
    $c_books = db_fetch_all("SELECT b.*, a.name as author_name 
                             FROM books b 
                             JOIN book_collection bc ON b.id = bc.book_id 
                             LEFT JOIN authors a ON b.author_id = a.id 
                             WHERE bc.collection_id = ? AND b.is_active = 1 
                             LIMIT 4", [$col['id']]);
    $collection_books[$col['id']] = $c_books;
}
// Sağdaki öne çıkan kitap: "Seçtiklerimiz" vitrininden, yoksa en yeni indirimli, yoksa en yeni kitap (sabit örnek metin yerine gerçek ürün)
$featured_sql = "SELECT b.*, a.name as author_name FROM books b LEFT JOIN authors a ON a.id = b.author_id WHERE b.is_active = 1 AND b.stock > 0";
$featured = db_fetch($featured_sql . " AND b.id IN (SELECT book_id FROM book_showcase WHERE showcase_id = 5) ORDER BY b.id DESC LIMIT 1")
    ?: db_fetch($featured_sql . " AND b.old_price > b.price ORDER BY b.id DESC LIMIT 1")
    ?: db_fetch($featured_sql . " ORDER BY b.id DESC LIMIT 1");
// Kitabı olmayan koleksiyonlar gösterilmez
$collections = array_values(array_filter($collections, function ($col) use ($collection_books) { return !empty($collection_books[$col['id']]); }));
track_view('home');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head([
        'title'       => mb_strlen($site['site_title'] ?? '', 'UTF-8') > 0 && mb_strlen($site['site_title'], 'UTF-8') <= 60 ? $site['site_title'] : seo_title('Kitabın Güvenli ve Hızlı Adresi'),
        'description' => seo_description($site['site_description'] ?? 'Roman, tarih, dünya klasikleri, felsefe ve çocuk kitapları uygun fiyatlarla Yeniçeri Kitabevi\'nde.'),
        'canonical'   => '',
        'jsonld'      => [jsonld_home()],
    ]) ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body>

    <!-- 1. ÜST BİLGİ ÇUBUĞU -->
    <div class="top-bar py-2 border-bottom border-dark">
        <div class="container d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <span><i class="bi bi-geo-alt-fill text-danger me-1"></i> Etimesgut / Ankara</span>
                <span class="d-none d-md-inline"><i class="bi bi-telephone-fill text-danger me-1"></i> Müşteri Destek: <?= e($site['site_phone'] ?? '0312 245 00 00') ?></span>
                <span class="d-none d-lg-inline text-warning"><i class="bi bi-truck me-1"></i> <?= format_price($site['shipping_free_limit'] ?? 350) ?> ve Üzeri Ücretsiz Kargo!</span>
            </div>
            <div class="d-flex align-items-center gap-3 fs-7">
                <a href="legal.php?p=teslimat-iade">Teslimat Şartları</a>
                <a href="legal.php?p=gizlilik">Gizlilik Politikası</a>
                <a href="contact.php">İletişim</a>
                <a href="admin/login.php" class="text-danger font-semibold"><i class="bi bi-lock-fill"></i> Admin Girişi</a>
            </div>
        </div>
    </div>

    <!-- 2. ANA HEADER & ARAMA ÇUBUĞU & SEPET WIDGET -->
    <header class="bg-white py-3 border-bottom sticky-top shadow-sm">
        <div class="container">
            <div class="row align-items-center g-3">
                <div class="col-6 col-lg-3">
                    <a href="index.php" class="navbar-brand text-dark d-flex align-items-center">
                        <img src="assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 52px; max-width: 220px; object-fit: contain;">
                    </a>
                </div>

                <div class="col-12 col-lg-6 order-3 order-lg-2">
                    <form action="category.php" method="GET">
                        <div class="input-group search-bar-group">
                            <input type="text" name="q" class="form-control" placeholder="Kitap adı, Yazar veya Yayınevi ara..." required>
                            <select name="type" class="form-select" style="max-width: 150px;">
                                <option value="all">Tümünde</option>
                                <option value="book">Kitap Adı</option>
                                <option value="author">Yazar</option>
                                <option value="publisher">Yayınevi</option>
                            </select>
                            <button type="submit" class="btn btn-search">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="col-6 col-lg-3 text-end order-2 order-lg-3">
                    <div class="d-inline-flex align-items-center gap-2">
                        <button class="btn btn-outline-dark position-relative rounded-pill px-3 py-2 fw-bold d-flex align-items-center gap-2 border-secondary" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer">
                            <i class="bi bi-bag-handle-fill text-danger fs-5"></i>
                            <span class="d-none d-sm-inline">Sepetim</span>
                            <span class="badge bg-danger rounded-pill cart-count-badge">0</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- 3. KATEGORİ NAVİGASYON BARI -->
    <nav class="cat-nav">
        <div class="container">
            <ul class="nav">
                <li class="nav-item">
                    <a class="nav-link active" href="index.php"><i class="bi bi-house-door-fill me-1"></i> Anasayfa</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold" href="magaza.php"><i class="bi bi-shop me-1 text-danger"></i> Alt Dükkanlar (Sahaflar)</a>
                </li>
                <?php foreach ($categories as $cat): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(category_path($cat)) ?>">
                            <i class="bi <?= e($cat['icon']) ?> me-1 text-danger"></i> <?= e($cat['name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item">
                    <a class="nav-link" href="contact.php"><i class="bi bi-envelope-fill me-1"></i> İletişim</a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- 4. HERO BANNER -->
    <section class="py-4">
        <div class="container">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-8">
                    <div class="card border-0 rounded-4 overflow-hidden shadow-lg text-white h-100 position-relative" style="background: linear-gradient(135deg, #000000 0%, #1a1a24 100%); border: 2px solid #e61919 !important; min-height: 280px;">
                        <div class="card-body p-4 p-md-5 d-flex flex-column justify-content-center" style="max-width: 550px;">
                            <span class="badge bg-danger rounded-pill px-3 py-2 w-auto me-auto mb-3 fw-bold"><i class="bi bi-fire me-1"></i> Etimesgut Şubesi Özel</span>
                            <h1 class="display-6 fw-extrabold text-white mb-2">Okuma Tutkunlarına Özel Kırmızı & Siyah Fırsatlar!</h1>
                            <p class="text-light opacity-75 mb-4 fs-6">Klasik romanlar, tarih serileri ve dünya edebiyatının başyapıtlarında cazip fiyat avantajları.</p>
                            <div>
                                <a href="#showcase_1" class="btn btn-danger btn-lg rounded-pill px-4 fw-bold shadow-lg">
                                    <i class="bi bi-bag-fill me-1"></i> Vitrindekileri Keşfet
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 rounded-4 bg-dark text-white p-4 shadow-lg h-100 d-flex flex-column justify-content-between" style="border: 2px solid #e61919 !important;">
                        <div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="badge bg-danger text-white font-bold rounded-pill px-3 py-1">Günün Fırsatı</span>
                                <i class="bi bi-clock-history fs-4 text-danger"></i>
                            </div>
                            <?php if ($featured): ?>
                                <h2 class="h4 fw-bold mt-3 mb-1">Haftanın Seçilmiş Eseri</h2>
                                <a href="<?= e(book_path($featured)) ?>" class="text-white text-decoration-none d-block fw-semibold mb-1"><?= e($featured['title']) ?></a>
                                <p class="fs-7 text-secondary mb-0"><?= e($featured['author_name'] ?? '') ?></p>
                            <?php else: ?>
                                <h2 class="h4 fw-bold mt-3 mb-2">Sahaflarımızı Keşfedin</h2>
                                <p class="fs-7 text-secondary">İkinci el, nadir ve eski basım kitaplar sahaf vitrinlerinde.</p>
                            <?php endif; ?>
                        </div>
                        <div class="pt-3 border-top border-secondary">
                            <?php if ($featured): ?>
                                <span class="fs-4 fw-extrabold text-danger"><?= format_price($featured['price']) ?></span>
                                <?php if (!empty($featured['old_price']) && $featured['old_price'] > $featured['price']): ?>
                                    <span class="fs-7 text-muted text-decoration-line-through ms-2"><?= format_price($featured['old_price']) ?></span>
                                <?php endif; ?>
                                <a href="<?= e(book_path($featured)) ?>" class="btn btn-sm btn-danger rounded-pill px-3 ms-2">İncele</a>
                            <?php else: ?>
                                <a href="magaza.php" class="btn btn-sm btn-danger rounded-pill px-3">Sahaflar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. DİNAMİK ÜRÜN VİTRİNLERİ -->
    <?php foreach ($showcases as $sc): ?>
        <?php 
        $books = $showcase_books[$sc['id']] ?? []; 
        if (empty($books)) continue;
        ?>
        <section class="py-4" id="showcase_<?= $sc['id'] ?>">
            <div class="container">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h3 class="section-title">
                        <i class="bi <?= e($sc['icon']) ?> text-danger me-2"></i> <?= e($sc['title']) ?>
                    </h3>
                    <a href="category.php" class="btn btn-sm btn-outline-dark rounded-pill px-3 font-semibold">Tümünü Gör</a>
                </div>

                <?php if ($sc['type'] === 'scroll'): ?>
                    <div class="d-flex gap-3 overflow-x-auto pb-3 scrollbar-custom">
                        <?php foreach ($books as $b): ?>
                            <div style="min-width: 220px; max-width: 240px;">
                                <?php include __DIR__ . '/_book_card.php'; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="row g-3 row-cols-2 row-cols-md-3 row-cols-lg-4">
                        <?php foreach ($books as $b): ?>
                            <div class="col">
                                <?php include __DIR__ . '/_book_card.php'; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <!-- 6. TEMATİK KOLEKSİYONLAR -->
    <?php if ($collections): ?>
    <section class="py-5 bg-white border-top border-bottom">
        <div class="container">
            <div class="text-center max-w-xl mx-auto mb-5">
                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 font-bold mb-2">Özel Raf Seçkileri</span>
                <h2 class="fw-extrabold text-dark">Tematik Koleksiyonlar</h2>
                <p class="text-muted">Özel temalara, yazarlara ve serilere göre özenle kürate edilmiş kitap rafları.</p>
            </div>

            <div class="row g-4">
                <?php foreach ($collections as $col): ?>
                    <div class="col-md-6">
                        <div class="collection-card">
                            <span class="badge bg-danger text-white font-bold mb-2"><?= e($col['badge_text']) ?></span>
                            <h3 class="fw-bold text-white mb-2"><?= e($col['title']) ?></h3>
                            <p class="text-light opacity-75 fs-7 mb-4"><?= e($col['description']) ?></p>
                            
                            <div class="row g-2">
                                <?php 
                                $c_books = $collection_books[$col['id']] ?? []; 
                                foreach ($c_books as $cb):
                                ?>
                                    <div class="col-6 col-sm-3">
                                        <div class="bg-white p-2 rounded-3 text-dark text-center h-100 shadow-sm border border-danger">
                                            <div class="fw-bold fs-8 text-truncate mb-1"><?= e($cb['title']) ?></div>
                                            <div class="text-danger fw-extrabold fs-7"><?= format_price($cb['price']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php endif; ?>

    <!-- 8. FOOTER -->
    <footer>
        <div class="container">
            <div class="row g-4 pb-5 border-bottom border-secondary border-opacity-25">
                <div class="col-lg-4">
                    <div class="bg-white d-inline-block px-3 py-2 rounded-3 mb-3 shadow-sm">
                        <img src="assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 40px; object-fit: contain;">
                    </div>
                    <p class="fs-7 opacity-75 mb-3">Yeniçeri Kitabevi, binlerce edebi eser, tarih araştırması ve çocuk kitabı ile Ankara Etimesgut merkezli okurlarına hızlı ve güvenli hizmet vermektedir.</p>
                    <div class="d-flex gap-2">
                        <a href="<?= e(whatsapp_link($site['site_whatsapp'] ?? '05550000000')) ?>" target="_blank" rel="noopener" class="btn btn-danger btn-sm rounded-pill font-bold px-3">
                            <i class="bi bi-whatsapp me-1"></i> WhatsApp Sipariş
                        </a>
                    </div>
                </div>

                <div class="col-lg-2 col-6">
                    <h5>Hızlı Bağlantılar</h5>
                    <ul class="list-unstyled fs-7">
                        <li class="mb-2"><a href="index.php">Anasayfa</a></li>
                        <li class="mb-2"><a href="category.php">Tüm Kitaplar</a></li>
                        <li class="mb-2"><a href="contact.php">İletişim & Konum</a></li>
                    </ul>
                </div>

                <div class="col-lg-3 col-6">
                    <h5>Yasal & Kurumsal</h5>
                    <ul class="list-unstyled fs-7">
                        <li class="mb-2"><a href="legal.php?p=teslimat-iade">Teslimat & İade Şartları</a></li>
                        <li class="mb-2"><a href="legal.php?p=mesafeli-satis">Mesafeli Satış Sözleşmesi</a></li>
                        <li class="mb-2"><a href="legal.php?p=on-bilgilendirme">Ön Bilgilendirme Formu</a></li>
                        <li class="mb-2"><a href="legal.php?p=gizlilik">Gizlilik & Çerez Politikası</a></li>
                        <li class="mb-2"><a href="legal.php?p=kvkk">KVKK Aydınlatma Metni</a></li>
                        <li class="mb-2"><a href="admin/login.php">Yönetim Paneli Girişi</a></li>
                    </ul>
                </div>

                <div class="col-lg-3">
                    <h5>Mağaza Lokasyon Bilgisi</h5>
                    <div class="fs-7 opacity-75">
                        <p class="mb-2"><i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= e($site['site_address'] ?? 'İstasyon Mah. Hikmet Özer Cad. No:48/A Etimesgut / Ankara') ?></p>
                        <p class="mb-2"><i class="bi bi-telephone-fill text-danger me-1"></i> <?= e($site['site_phone'] ?? '0312 245 00 00') ?></p>
                        <p class="mb-0"><i class="bi bi-envelope-fill text-danger me-1"></i> <?= e($site['site_email'] ?? 'iletisim@yenicerikitabevi.com') ?></p>
                    </div>
                </div>
            </div>

            <div class="pt-4 text-center fs-7 opacity-50">
                &copy; <?= date('Y') ?> Yeniçeri Kitabevi. Tüm hakları saklıdır. Uçtan uca saf PHP e-ticaret platformu.
            </div>
        </div>
    </footer>

    <!-- 9. SEPET OFFCANVAS -->
    <div class="offcanvas offcanvas-end rounded-start-4" tabindex="-1" id="cartDrawer">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title fw-bold"><i class="bi bi-bag-fill text-danger me-2"></i> Alışveriş Sepetim</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-4 d-flex flex-column">
            <div id="cartDrawerList" class="flex-grow-1"></div>
            <div class="border-top pt-3 mt-auto">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="fw-bold text-dark">Toplam Tutar:</span>
                    <span class="fs-4 fw-extrabold text-danger" id="cartDrawerTotal">₺0,00</span>
                </div>
                <a href="checkout.php" class="btn btn-danger w-100 py-2.5 fw-bold rounded-3 shadow">
                    Sepeti Onayla ve Öde <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= asset_v('assets/js/app.js') ?>"></script>
    <?= track_script() ?>
</body>
</html>
