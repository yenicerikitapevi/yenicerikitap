<?php
/**
 * STOREFRONT: Book Detail Page (detail.php)
 */
require_once __DIR__ . '/config.php';

$book_sql = "SELECT b.*, a.name as author_name, a.bio as author_bio, a.slug as author_slug, p.name as publisher_name, c.name as category_name, c.slug as category_slug, s.name as store_name, s.slug as store_slug, s.city as store_city 
                  FROM books b 
                  LEFT JOIN authors a ON b.author_id = a.id 
                  LEFT JOIN publishers p ON b.publisher_id = p.id 
                  LEFT JOIN categories c ON b.category_id = c.id 
                  LEFT JOIN stores s ON b.store_id = s.id 
                  WHERE b.is_active = 1 AND ";
$slug = trim((string)($_GET['slug'] ?? ''));
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$book = $slug !== '' ? db_fetch($book_sql . "b.slug = ?", [$slug]) : null;

// Eski adresler kalıcı olarak okunabilir adrese gider: detail.php?id=13 ve değişmiş eski slug'lar
if (!$book) {
    $target = $slug !== '' ? slug_redirect_target('book', $slug) : ($id > 0 ? $id : null);
    $old = $target ? db_fetch($book_sql . "b.id = ?", [$target]) : null;
    if ($old) {
        header('Location: ' . abs_url(book_path($old)), true, 301);
        exit;
    }
    require __DIR__ . '/404.php';
    exit;
}
$id = (int)$book['id'];

$related_books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name 
                               FROM books b 
                               LEFT JOIN authors a ON b.author_id = a.id 
                               LEFT JOIN publishers p ON b.publisher_id = p.id 
                               WHERE b.category_id = ? AND b.id != ? AND b.author_id != ? AND b.is_active = 1 
                               ORDER BY b.id DESC LIMIT 4", [$book['category_id'], $id, $book['author_id']]);
// Aynı yazarın diğer kitapları (iç bağlantılar)
$author_books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name 
                               FROM books b 
                               LEFT JOIN authors a ON b.author_id = a.id 
                               LEFT JOIN publishers p ON b.publisher_id = p.id 
                               WHERE b.author_id = ? AND b.id != ? AND b.is_active = 1 
                               ORDER BY b.id DESC LIMIT 4", [$book['author_id'], $id]);

$crumbs = [['Ana Sayfa', ''], [$book['category_name'] ?: 'Kitaplar', $book['category_slug'] && !is_numeric_category_name($book['category_name']) ? category_path(['id' => $book['category_id'], 'slug' => $book['category_slug']]) : 'category.php'], [$book['title'], null]];

$img_src = cover_url($book['cover_image'] ?? '');
track_view('detail', $book['id'], $book['store_id'] ?? null);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head([
        'title'       => seo_title($book['title'], (string)$book['author_name']),
        'description' => book_meta_description($book),
        'canonical'   => book_path($book),
        'image'       => cover_url($book['cover_image'] ?? ''),
        'image_alt'   => cover_alt($book),
        'type'        => 'product',
        'jsonld'      => [jsonld_book($book), jsonld_breadcrumb($crumbs)],
    ]) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
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
        <div class="mb-4"><?= breadcrumb_html($crumbs) ?></div>

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
        <?php
        $in_stock = (int)$book['stock'] > 0;
        $disc_pct = (!empty($book['old_price']) && $book['old_price'] > $book['price']) ? (int)round(($book['old_price'] - $book['price']) / $book['old_price'] * 100) : 0;
        $free_limit = (float)setting('shipping_free_limit', '350');
        $ship_min = min(array_column(shipping_options(), 'price'));
        $is_sahaf = !empty($book['store_name']);
        $seller_books = $is_sahaf ? (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE store_id = ? AND is_active = 1", [$book['store_id']])['cnt'] ?? 0) : 0;
        $specs = array_filter([
            'ISBN' => $book['isbn'], 'Yazar' => $book['author_name'], 'Yayınevi' => $book['publisher_name'], 'Kategori' => is_numeric_category_name($book['category_name']) ? '' : $book['category_name'],
            'Orijinal Adı' => $book['original_title'], 'Çeviren / Hazırlayan' => $book['translator'], 'Sayfa Sayısı' => $book['pages'], 'Basım Yılı' => $book['year'],
            'Yayın Yeri' => $book['publish_place'], 'Dil' => $book['language'], 'Cilt' => $book['cover_type'], 'Ebat' => $book['dimensions'], 'Kondisyon' => $book['condition_text'],
        ], function ($v) { return trim((string)$v) !== '' && $v !== '0'; });
        ?>
        <div class="row g-4 product-top">
            <!-- Kapak ve galeri -->
            <div class="col-md-5 col-lg-3">
                <div class="bg-white p-3 rounded-4 shadow-sm text-center border position-relative">
                    <?php if ($disc_pct > 0): ?><span class="badge bg-danger position-absolute top-0 start-0 m-2 fs-7">%<?= $disc_pct ?> indirim</span><?php endif; ?>
                    <?= cover_picture($book['cover_image'] ?? '', cover_alt($book), 'img-fluid rounded-3 shadow-sm', 400, 600, false, 'id="mainBookCover" style="max-height: 420px; width: auto; object-fit: cover;" onerror="this.src=\'/assets/images/book_placeholder.svg\'"') ?>
                    <?php if (count($gallery_images) > 1): ?>
                        <div class="d-flex justify-content-center gap-2 mt-3 flex-wrap">
                            <?php foreach ($gallery_images as $g_url): ?>
                                <img src="<?= e($g_url) ?>" alt="<?= e($book['title']) ?> - ek görsel" width="52" height="70" loading="lazy" class="rounded border p-1 bg-light" style="width: 52px; height: 70px; object-fit: cover; cursor: pointer;" onclick="var m = document.getElementById('mainBookCover'); m.src = this.src; if (m.parentNode.tagName === 'PICTURE') { m.parentNode.querySelector('source').srcset = this.src; }">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Başlık ve özet bilgiler -->
            <div class="col-md-7 col-lg-5">
                <?php if (!is_numeric_category_name($book['category_name'])): ?><span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill fw-semibold mb-2"><?= e($book['category_name']) ?></span><?php endif; ?>
                <h1 class="h2 fw-extrabold text-dark mb-2"><?= e($book['title']) ?></h1>
                <div class="fs-6 mb-3">
                    <a href="<?= e(author_path(['slug' => $book['author_slug'], 'name' => $book['author_name']])) ?>" class="fw-bold text-danger text-decoration-none"><?= e($book['author_name']) ?></a>
                    <span class="text-muted mx-1">•</span><span class="text-secondary"><?= e($book['publisher_name']) ?></span>
                </div>
                <?php $badges = campaign_badges_html($book, 'fs-7 px-3 py-2'); if ($badges !== ''): ?>
                    <div class="mb-3"><?= $badges ?></div>
                <?php endif; ?>
                <div class="row g-2 fs-7 mb-3">
                    <?php foreach (array_slice(array_intersect_key($specs, array_flip(['ISBN', 'Sayfa Sayısı', 'Basım Yılı', 'Cilt', 'Kondisyon', 'Dil'])), 0, 6) as $k => $v): ?>
                        <div class="col-6"><span class="text-muted"><?= e($k) ?>:</span> <span class="fw-semibold"><?= e($v) ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($book['summary'])): ?>
                    <p class="text-secondary fs-7 mb-1"><?= e(mb_strimwidth(trim($book['summary']), 0, 260, '…', 'UTF-8')) ?></p>
                    <a href="#urunSekmeler" class="fs-7 text-danger fw-semibold text-decoration-none" onclick="var t = document.querySelector('[data-bs-target=&quot;#tabDesc&quot;]'); if (t && window.bootstrap) bootstrap.Tab.getOrCreateInstance(t).show();">Devamını oku <i class="bi bi-chevron-down"></i></a>
                <?php endif; ?>
            </div>

            <!-- Fiyat / sepet kutusu ve satıcı -->
            <div class="col-lg-4">
                <div class="buy-box">
                    <div class="bg-white border rounded-4 shadow-sm p-4 mb-3">
                        <?php if ($disc_pct > 0): ?>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="text-muted text-decoration-line-through"><?= format_price($book['old_price']) ?></span>
                                <span class="badge bg-danger">%<?= $disc_pct ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="display-6 fw-extrabold text-danger mb-2"><?= format_price($book['price']) ?></div>
                        <div class="fs-7 mb-3">
                            <?php if ($in_stock): ?>
                                <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Stokta<?= (int)$book['stock'] <= 3 ? ' — son ' . (int)$book['stock'] . ' adet' : '' ?></span>
                            <?php else: ?>
                                <span class="text-secondary fw-semibold"><i class="bi bi-x-circle-fill me-1"></i>Tükendi</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($in_stock): ?>
                            <div class="d-flex gap-2 mb-2">
                                <div class="input-group buy-qty">
                                    <button class="btn btn-outline-secondary" type="button" onclick="var q = document.getElementById('buyQty'); q.value = Math.max(1, (parseInt(q.value, 10) || 1) - 1);" aria-label="Azalt">−</button>
                                    <input type="number" id="buyQty" class="form-control text-center px-1" value="1" min="1" max="<?= (int)$book['stock'] ?>" aria-label="Adet">
                                    <button class="btn btn-outline-secondary" type="button" onclick="var q = document.getElementById('buyQty'); q.value = Math.min(<?= (int)$book['stock'] ?>, (parseInt(q.value, 10) || 1) + 1);" aria-label="Artır">+</button>
                                </div>
                                <button class="btn btn-danger flex-grow-1 fw-bold btn-add-cart" data-qty-input="#buyQty"
                                        data-id="<?= $book['id'] ?>" data-title="<?= e($book['title']) ?>" data-price="<?= $book['price'] ?>" data-image="<?= e($img_src) ?>">
                                    <i class="bi bi-bag-plus-fill me-1"></i> Sepete Ekle
                                </button>
                            </div>
                            <button class="btn btn-dark w-100 fw-bold btn-add-cart" data-qty-input="#buyQty" data-go="checkout"
                                    data-id="<?= $book['id'] ?>" data-title="<?= e($book['title']) ?>" data-price="<?= $book['price'] ?>" data-image="<?= e($img_src) ?>">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Hemen Al
                            </button>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100 fw-bold" disabled>Stokta Yok</button>
                        <?php endif; ?>
                        <ul class="list-unstyled fs-8 text-secondary mt-3 mb-0">
                            <li class="mb-1"><i class="bi bi-truck text-danger me-1"></i><?= $book['price'] >= $free_limit ? '<b class="text-success">Kargo bedava</b>' : format_price($free_limit) . ' ve üzeri kargo bedava' . ($ship_min > 0 ? ' (kargo ' . format_price($ship_min) . '\'den başlayan)' : '') ?></li>
                            <li class="mb-1"><i class="bi bi-box-seam text-danger me-1"></i><?= $is_sahaf ? 'Sahaf tarafından hazırlanıp kargolanır' : '1-3 iş günü içinde kargoda' ?></li>
                            <li><i class="bi bi-shield-check text-danger me-1"></i>Güvenli ödeme • 14 gün içinde iade</li>
                        </ul>
                    </div>

                    <div class="bg-white border rounded-4 shadow-sm p-3">
                        <div class="text-muted fs-8 text-uppercase fw-semibold mb-1">Satıcı</div>
                        <?php if ($is_sahaf): ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold"><i class="bi bi-shop text-danger me-1"></i><?= e($book['store_name']) ?></div>
                                    <div class="fs-8 text-muted"><?= $book['store_city'] ? e($book['store_city']) . ' • ' : '' ?><?= $seller_books ?> kitap</div>
                                </div>
                                <a href="magaza.php?s=<?= urlencode($book['store_slug'] ?? '') ?>" class="btn btn-sm btn-outline-dark rounded-pill">Mağazaya Git</a>
                            </div>
                        <?php else: ?>
                            <div class="fw-bold"><i class="bi bi-patch-check-fill text-danger me-1"></i>Yeniçeri Kitabevi</div>
                            <div class="fs-8 text-muted">Etimesgut / Ankara • Kitap kendi stoğumuzdan gönderilir</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sekmeler -->
        <div class="bg-white border rounded-4 shadow-sm mt-4" id="urunSekmeler">
            <ul class="nav nav-tabs px-3 pt-2 product-tabs" role="tablist">
                <li class="nav-item"><button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#tabDesc" type="button" role="tab">Açıklama</button></li>
                <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tabSpecs" type="button" role="tab">Kitap Bilgileri</button></li>
                <?php if (!empty($book['author_bio'])): ?><li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tabAuthor" type="button" role="tab">Yazar Hakkında</button></li><?php endif; ?>
                <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tabShip" type="button" role="tab">Kargo & İade</button></li>
            </ul>
            <div class="tab-content p-4">
                <div class="tab-pane fade show active" id="tabDesc" role="tabpanel">
                    <?php if (!empty($book['summary'])): ?>
                        <div class="text-secondary"><?= nl2br(e($book['summary'])) ?></div>
                    <?php else: ?>
                        <p class="text-muted mb-0"><?= e(book_meta_description($book)) ?></p>
                    <?php endif; ?>
                </div>
                <div class="tab-pane fade" id="tabSpecs" role="tabpanel">
                    <table class="table table-sm fs-7 mb-0" style="max-width: 640px;">
                        <tbody>
                            <?php foreach ($specs as $k => $v): ?>
                                <tr><th class="text-muted fw-semibold" style="width: 40%;"><?= e($k) ?></th><td><?= e($v) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($book['author_bio'])): ?>
                    <div class="tab-pane fade" id="tabAuthor" role="tabpanel">
                        <h2 class="h6 fw-bold"><?= e($book['author_name']) ?></h2>
                        <p class="text-secondary fs-7"><?= nl2br(e($book['author_bio'])) ?></p>
                        <a href="<?= e(author_path(['slug' => $book['author_slug'], 'name' => $book['author_name']])) ?>" class="fs-7 text-danger fw-semibold">Yazarın tüm kitapları <i class="bi bi-arrow-right"></i></a>
                    </div>
                <?php endif; ?>
                <div class="tab-pane fade" id="tabShip" role="tabpanel">
                    <ul class="fs-7 text-secondary mb-2">
                        <li><?= format_price($free_limit) ?> ve üzeri siparişlerde kargo ücretsizdir. Kargo firmasını ödeme adımında seçebilirsiniz.</li>
                        <li>Siparişiniz kargoya verildiğinde takip numarası e-postanıza gönderilir; durumunu <a href="siparis-takip.php" class="text-danger">Sipariş Takip</a> sayfasından izleyebilirsiniz.</li>
                        <li>Teslimattan itibaren 14 gün içinde cayma hakkınızı kullanabilirsiniz.<?= $is_sahaf ? ' İkinci el kitaplarda kondisyon bilgisi açıklamada belirtilir.' : '' ?></li>
                    </ul>
                    <a href="legal.php?p=teslimat-iade" class="fs-7 text-danger">Teslimat, iptal ve iade koşullarının tamamı</a>
                </div>
            </div>
        </div>

        <?php if (!empty($author_books)): ?>
            <div class="mt-5 pt-4 border-top">
                <h2 class="section-title h3">Yazarın Diğer Kitapları: <a href="<?= e(author_path(['slug' => $book['author_slug'], 'name' => $book['author_name']])) ?>" class="text-danger text-decoration-none"><?= e($book['author_name']) ?></a></h2>
                <div class="row g-3 row-cols-2 row-cols-md-4">
                    <?php foreach ($author_books as $b): ?>
                        <div class="col">
                            <?php include __DIR__ . '/_book_card.php'; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($related_books)): ?>
            <div class="mt-5 pt-4 border-top">
                <h2 class="section-title h3">Aynı Kategoriden Benzer Kitaplar</h2>
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
    <script src="<?= asset_v('assets/js/app.js') ?>"></script>
    <?= track_script() ?>
</body>
</html>
