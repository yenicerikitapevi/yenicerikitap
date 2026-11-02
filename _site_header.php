<?php
/**
 * Vitrin sayfalarının ortak üst bölümü: bilgi çubuğu, arama + sepet, kategori menüsü (ana sayfadaki ile aynı).
 * Kullanım: $header_active = 'home' | kategori id'si; include __DIR__ . '/_site_header.php';
 */
if (!defined('SITE_URL')) exit;

$header_categories = isset($categories) && is_array($categories) ? $categories : nav_categories();
$header_active = $header_active ?? '';
?>
    <div class="top-bar py-2 border-bottom border-dark">
        <div class="container d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <span><i class="bi bi-geo-alt-fill text-danger me-1"></i> Etimesgut / Ankara</span>
                <span class="d-none d-md-inline"><i class="bi bi-telephone-fill text-danger me-1"></i> Müşteri Destek: <?= e(setting('site_phone', '0312 245 00 00')) ?></span>
                <span class="d-none d-lg-inline text-warning"><i class="bi bi-truck me-1"></i> <?= format_price(setting('shipping_free_limit', '350')) ?> ve Üzeri Ücretsiz Kargo!</span>
            </div>
            <div class="d-flex align-items-center gap-3 fs-7">
                <a href="siparis-takip.php"><i class="bi bi-truck me-1"></i>Sipariş Takip</a>
                <a href="legal.php?p=teslimat-iade" class="d-none d-md-inline">Teslimat Şartları</a>
                <a href="contact.php" class="d-none d-md-inline">İletişim</a>
                <?= customer_nav_link() ?>
                <?php if (isset($_SESSION['store_user'])): ?>
                    <a href="satici/" class="text-warning font-semibold"><i class="bi bi-shop"></i> Sahaf Paneli</a>
                <?php else: ?>
                    <a href="satici/login.php" class="text-warning font-semibold"><i class="bi bi-shop"></i> Sahaf Girişi</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
                            <input type="text" name="q" class="form-control" placeholder="Kitap adı, Yazar veya Yayınevi ara..." value="<?= e($_GET['q'] ?? '') ?>" required>
                            <select name="type" class="form-select" style="max-width: 150px;">
                                <option value="all">Tümünde</option>
                                <option value="book">Kitap Adı</option>
                                <option value="author">Yazar</option>
                                <option value="publisher">Yayınevi</option>
                            </select>
                            <button type="submit" class="btn btn-search" aria-label="Ara"><i class="bi bi-search"></i></button>
                        </div>
                    </form>
                </div>
                <div class="col-6 col-lg-3 text-end order-2 order-lg-3">
                    <button class="btn btn-outline-dark position-relative rounded-pill px-3 py-2 fw-bold d-inline-flex align-items-center gap-2 border-secondary" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer">
                        <i class="bi bi-bag-handle-fill text-danger fs-5"></i>
                        <span class="d-none d-sm-inline">Sepetim</span>
                        <span class="badge bg-danger rounded-pill cart-count-badge">0</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <nav class="cat-nav">
        <div class="container">
            <ul class="nav">
                <li class="nav-item">
                    <a class="nav-link <?= $header_active === 'home' ? 'active' : '' ?>" href="index.php"><i class="bi bi-house-door-fill me-1"></i> Anasayfa</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold" href="magaza.php"><i class="bi bi-shop me-1 text-danger"></i> Alt Dükkanlar (Sahaflar)</a>
                </li>
                <?php foreach ($header_categories as $hcat): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= (string)$header_active === (string)$hcat['id'] ? 'active' : '' ?>" href="<?= e(category_path($hcat)) ?>">
                            <i class="bi <?= e($hcat['icon'] ?? 'bi-book') ?> me-1 text-danger"></i> <?= e($hcat['name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item">
                    <a class="nav-link" href="contact.php"><i class="bi bi-envelope-fill me-1"></i> İletişim</a>
                </li>
            </ul>
        </div>
    </nav>
