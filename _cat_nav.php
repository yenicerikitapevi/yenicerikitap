<?php
/**
 * Üst kategori menüsü (ana sayfa ve diğer vitrin sayfaları).
 * Tek satır: en çok kitabı olan kategoriler + sağda "Tüm Kategoriler" açılır listesi (arama kutulu).
 * Kullanım: $nav_active = 'home' | kategori id'si | ''; include __DIR__ . '/_cat_nav.php';
 */
if (!defined('SITE_URL')) exit;

$nav_active = isset($nav_active) ? (string)$nav_active : '';
[$nav_top, $nav_all] = nav_menu_categories($nav_active, 8);
?>
    <nav class="cat-nav">
        <div class="container d-flex align-items-stretch">
            <ul class="nav flex-nowrap cat-nav-scroll">
                <li class="nav-item">
                    <a class="nav-link <?= $nav_active === 'home' ? 'active' : '' ?>" href="index.php"><i class="bi bi-house-door-fill me-1"></i> Anasayfa</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold" href="magaza.php"><i class="bi bi-shop me-1 text-danger"></i> Alt Dükkanlar (Sahaflar)</a>
                </li>
                <?php foreach ($nav_top as $ncat): ?>
                    <li class="nav-item cat-nav-cat">
                        <a class="nav-link <?= $nav_active !== '' && $nav_active === (string)$ncat['id'] ? 'active' : '' ?>" href="<?= e(category_path($ncat)) ?>">
                            <i class="bi <?= e(($ncat['icon'] ?? '') ?: 'bi-book') ?> me-1 text-danger"></i> <?= e($ncat['name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item">
                    <a class="nav-link" href="contact.php"><i class="bi bi-envelope-fill me-1"></i> İletişim</a>
                </li>
            </ul>
            <?php if (count($nav_all) > count($nav_top)): ?>
            <div class="dropdown cat-nav-all">
                <button class="nav-link dropdown-toggle h-100" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                    <i class="bi bi-grid-3x3-gap-fill me-1 text-danger"></i><span class="d-none d-sm-inline">Tüm Kategoriler</span><span class="d-sm-none">Tümü</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end cat-mega shadow-lg p-3">
                    <input type="search" class="form-control form-control-sm mb-3 cat-mega-filter" placeholder="Kategori ara (<?= count($nav_all) ?> kategori)" aria-label="Kategori ara">
                    <div class="cat-mega-grid">
                        <?php foreach ($nav_all as $ncat): ?>
                            <a class="cat-mega-link <?= $nav_active !== '' && $nav_active === (string)$ncat['id'] ? 'active' : '' ?>" href="<?= e(category_path($ncat)) ?>" data-name="<?= e(mb_strtolower(strtr((string)$ncat['name'], ['İ' => 'i', 'I' => 'ı']), 'UTF-8')) ?>">
                                <?= e($ncat['name']) ?><?php if ($ncat['book_count'] > 0): ?> <span class="cat-mega-count"><?= number_format($ncat['book_count'], 0, ',', '.') ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted fs-7 cat-mega-empty d-none">Eşleşen kategori yok.</div>
                </div>
            </div>
            <script>
            (function () {
                var box = document.currentScript.previousElementSibling;
                var input = box.querySelector('.cat-mega-filter');
                var links = box.querySelectorAll('.cat-mega-link');
                var empty = box.querySelector('.cat-mega-empty');
                function norm(s) { return s.replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr').trim(); }
                input.addEventListener('input', function () {
                    var q = norm(input.value), shown = 0;
                    links.forEach(function (a) {
                        var ok = q === '' || a.getAttribute('data-name').indexOf(q) !== -1;
                        a.classList.toggle('d-none', !ok);
                        if (ok) shown++;
                    });
                    empty.classList.toggle('d-none', shown > 0);
                });
                box.addEventListener('shown.bs.dropdown', function () { input.focus(); });
            })();
            </script>
            <?php endif; ?>
            <script>
            // ("Tüm Kategoriler" düğmesinden sonra çalışır, ölçüm onun yeriyle yapılır) Geniş ekranda sığmayan kategoriler gizlenir (hepsi "Tüm Kategoriler"de); telefonda menü yana kaydırılır
            (function () {
                var ul = document.currentScript.parentNode.querySelector('.cat-nav-scroll');
                function fit() {
                    var cats = ul.querySelectorAll('.cat-nav-cat');
                    cats.forEach(function (li) { li.classList.remove('d-none'); });
                    if (window.innerWidth < 768) return;
                    for (var i = cats.length - 1; i >= 0 && ul.scrollWidth > ul.clientWidth + 1; i--) {
                        if (cats[i].querySelector('.active')) continue;
                        cats[i].classList.add('d-none');
                    }
                }
                fit();
                var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(fit, 100); });
                window.addEventListener('load', fit);
                if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
            })();
            </script>
        </div>
    </nav>
