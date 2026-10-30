<?php
/**
 * SEO ÇEKİRDEĞİ
 * - Okunabilir adresler: /kitap/{slug}, /kategori/{slug}, /yazar/{slug} (Türkçe karakterler dönüştürülür)
 * - Slug değişince eski slug saklanır (slug_redirects) ve eski adres 301 ile yeni adrese gider
 * - Sayfa başlığı (≤60), açıklama (140–160), canonical, Open Graph / Twitter Card, favicon ve JSON-LD üretimi
 * Kanonik alan adı CANONICAL_URL ortam değişkeninden okunur (varsayılan https://yenicerikitabevi.com).
 */
if (!defined('SITE_URL')) exit;

define('CANONICAL_URL', rtrim(getenv('CANONICAL_URL') ?: 'https://yenicerikitabevi.com', '/'));
define('SEO_BRAND', 'Yeniçeri Kitabevi');

// --- Şema: slug ve SEO alanları (mevcut veritabanlarına eklenir) ---
(function () {
    global $pdo;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $cols_of = function ($t) use ($pdo, $sqlite) {
        return $sqlite ? array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(), 'name') : array_column($pdo->query("SHOW COLUMNS FROM {$t}")->fetchAll(), 'Field');
    };
    $add = [
        'books'      => ['slug' => $sqlite ? 'TEXT' : 'VARCHAR(220) NULL', 'meta_description' => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL', 'updated_at' => $sqlite ? 'DATETIME' : 'DATETIME NULL'],
        'categories' => ['description' => $sqlite ? 'TEXT' : 'TEXT NULL', 'meta_description' => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL'],
        'authors'    => ['meta_description' => $sqlite ? 'TEXT' : 'VARCHAR(300) NULL'],
    ];
    foreach ($add as $t => $cols) {
        $have = $cols_of($t);
        foreach ($cols as $c => $type) {
            if (!in_array($c, $have, true)) {
                $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$c} {$type};");
            }
        }
    }
    if ($sqlite) {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_books_slug ON books(slug);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS slug_redirects (entity TEXT NOT NULL, slug TEXT NOT NULL, target_id INTEGER NOT NULL, PRIMARY KEY (entity, slug));");
    } else {
        try { $pdo->exec("CREATE UNIQUE INDEX idx_books_slug ON books(slug);"); } catch (Throwable $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS slug_redirects (entity VARCHAR(20) NOT NULL, slug VARCHAR(220) NOT NULL, target_id INT NOT NULL, PRIMARY KEY (entity, slug)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    }
    // Slug'ı olmayan kitaplar (yeni eklenen / aktarılan) için slug üret; her istekte en fazla 300 kayıt
    foreach (db_fetch_all("SELECT b.id, b.title, a.name as author_name FROM books b LEFT JOIN authors a ON a.id = b.author_id WHERE b.slug IS NULL OR b.slug = '' LIMIT 300") as $b) {
        db_query("UPDATE books SET slug = ? WHERE id = ?", [book_unique_slug($b['title'], $b['author_name'], $b['id']), $b['id']]);
    }
})();

/** "Çalıkuşu Reşat Nuri" → "calikusu-resat-nuri" */
function slugify_tr($text) {
    $s = strtr(tr_lower(html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e', 'è' => 'e', 'ä' => 'a', '&' => ' ve ']);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

function book_unique_slug($title, $author, $exclude_id = 0) {
    $base = slugify_tr(trim($title . ' ' . $author)) ?: 'kitap';
    $base = rtrim(substr($base, 0, 200), '-');
    $slug = $base;
    for ($i = 2; db_fetch("SELECT id FROM books WHERE slug = ? AND id != ?", [$slug, (int)$exclude_id]); $i++) {
        $slug = $base . '-' . $i;
    }
    return $slug;
}

/** Slug değişince eskisini sakla; eski adres yeni adrese 301 ile gider */
function remember_old_slug($entity, $old_slug, $target_id) {
    $old_slug = trim((string)$old_slug);
    if ($old_slug === '') return;
    db_query("DELETE FROM slug_redirects WHERE entity = ? AND slug = ?", [$entity, $old_slug]);
    db_query("INSERT INTO slug_redirects (entity, slug, target_id) VALUES (?, ?, ?)", [$entity, $old_slug, (int)$target_id]);
}

function slug_redirect_target($entity, $slug) {
    $r = db_fetch("SELECT target_id FROM slug_redirects WHERE entity = ? AND slug = ?", [$entity, (string)$slug]);
    return $r ? (int)$r['target_id'] : null;
}

/** Kitap adı / yazar değiştiyse slug'ı yenile (kitap kaydedildikten sonra çağrılır) */
function book_refresh_slug($book_id) {
    $b = db_fetch("SELECT b.id, b.title, b.slug, a.name as author_name FROM books b LEFT JOIN authors a ON a.id = b.author_id WHERE b.id = ?", [(int)$book_id]);
    if (!$b) return;
    $wanted = slugify_tr(trim($b['title'] . ' ' . $b['author_name']));
    if ($b['slug'] && preg_replace('/-\d+$/', '', $b['slug']) === $wanted) {
        return; // değişmemiş
    }
    $new = book_unique_slug($b['title'], $b['author_name'], $b['id']);
    if ($b['slug'] && $b['slug'] !== $new) {
        remember_old_slug('book', $b['slug'], $b['id']);
    }
    db_query("UPDATE books SET slug = ? WHERE id = ?", [$new, $b['id']]);
}

function book_touch($book_id) {
    db_query("UPDATE books SET updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), (int)$book_id]);
}

// --- Adresler (site köküne göre; sayfalarda <base href="/"> var) ---
function book_path($b) {
    return !empty($b['slug']) ? 'kitap/' . rawurlencode($b['slug']) : 'detail.php?id=' . (int)$b['id'];
}
function category_path($c) {
    return !empty($c['slug']) ? 'kategori/' . rawurlencode($c['slug']) : 'category.php?id=' . (int)$c['id'];
}
function author_path($a) {
    return !empty($a['slug']) ? 'yazar/' . rawurlencode($a['slug']) : 'category.php?type=author&q=' . rawurlencode($a['name'] ?? '');
}
function abs_url($path) {
    if (preg_match('#^https?://#i', (string)$path)) return $path;
    return CANONICAL_URL . '/' . ltrim((string)$path, '/');
}

/** Statik dosya + değişiklik zamanı (?v=) → uzun süre önbelleğe alınabilir */
function asset_v($path) {
    $file = __DIR__ . '/' . ltrim($path, '/');
    return '/' . ltrim($path, '/') . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Kapağın WebP sürümü (yalnızca sitede yüklü kapaklar; harici adresler olduğu gibi kalır) */
function cover_webp_url($cover) {
    $u = cover_url($cover);
    return (strpos($u, 'cover.php?f=') === 0 && function_exists('imagewebp') && !preg_match('/\.webp$/i', $u)) ? $u . '&fmt=webp' : null;
}

/** <picture> ile kapak: WebP + yedek, boyut ve lazy-load (ilk ekrandaki görsel için $lazy = false) */
function cover_picture($cover, $alt, $class = '', $w = 400, $h = 600, $lazy = true, $extra = '') {
    $src = cover_url($cover);
    $src_attr = preg_match('#^https?://#i', $src) ? $src : '/' . ltrim($src, '/');
    $img = '<img src="' . e($src_attr) . '" alt="' . e($alt) . '" width="' . (int)$w . '" height="' . (int)$h . '"'
        . ($class !== '' ? ' class="' . e($class) . '"' : '')
        . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"')
        . ($extra !== '' ? ' ' . $extra : '') . '>';
    $webp = cover_webp_url($cover);
    return $webp ? '<picture><source type="image/webp" srcset="/' . e($webp) . '">' . $img . '</picture>' : $img;
}

function cover_alt($b) {
    return trim(($b['title'] ?? '') . (!empty($b['author_name']) ? ' - ' . $b['author_name'] : '')) . ' kitap kapağı';
}

// --- Başlık ve açıklama ---
function seo_title($main, $secondary = '') {
    $suffix = ' | ' . SEO_BRAND;
    $max = 60;
    $main = trim(preg_replace('/\s+/u', ' ', (string)$main));
    if ($secondary !== '' && mb_strlen($main . ' - ' . $secondary . $suffix, 'UTF-8') <= $max) {
        return $main . ' - ' . $secondary . $suffix;
    }
    if (mb_strlen($main . $suffix, 'UTF-8') <= $max) {
        return $main . $suffix;
    }
    return rtrim(mb_substr($main, 0, $max - mb_strlen($suffix, 'UTF-8') - 1, 'UTF-8')) . '…' . $suffix;
}

/** Açıklamayı 140–160 karaktere getir: uzunsa kelime sınırında kesilir, kısaysa sığan tamamlayıcı cümleler eklenir */
function seo_description($text, $filler = '') {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $len = function ($x) { return mb_strlen($x, 'UTF-8'); };
    if ($len($t) > 160) {
        $cut = mb_substr($t, 0, 157, 'UTF-8');
        $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
        return rtrim(mb_substr($cut, 0, $sp > 110 ? $sp : 157, 'UTF-8'), " ,.;:-") . '…';
    }
    if ($t !== '' && !preg_match('/[.!?…]$/u', $t)) {
        $t .= '.';
    }
    $extras = array_filter([$filler, 'Yeniçeri Kitabevi\'nde uygun fiyat, hızlı kargo ve güvenli ödeme.', 'Ankara Etimesgut şubemiz ve online mağazamızda.', 'Binlerce kitap sizi bekliyor.', 'Hızlı kargo.']);
    foreach (array_unique($extras) as $x) {
        if ($len($t) >= 140) break;
        if ($len(trim($t . ' ' . $x)) <= 160) $t = trim($t . ' ' . $x);
    }
    return $t;
}

function book_meta_description($b) {
    if (trim((string)($b['meta_description'] ?? '')) !== '') {
        return seo_description($b['meta_description']);
    }
    $parts = trim($b['title']) . (!empty($b['author_name']) ? ', ' . $b['author_name'] : '')
        . (!empty($b['publisher_name']) ? ' - ' . $b['publisher_name'] . ' yayınları' : '')
        . ' ' . number_format((float)$b['price'], 2, ',', '.') . ' TL';
    $summary = trim((string)($b['summary'] ?? ''));
    if ($summary !== '') {
        $parts .= '. ' . $summary;
    }
    return seo_description($parts, (!empty($b['condition_text']) ? 'Kondisyon: ' . $b['condition_text'] . '. ' : '') . 'Yeniçeri Kitabevi\'nde hızlı kargo ve güvenli ödemeyle.');
}

// --- <head> çıktısı ---
/**
 * $o: title, description, canonical (site köküne göre yol ya da tam adres), image, type (website/product/profile),
 *     robots (ör. 'noindex,follow'), jsonld (dizi listesi), prev/next
 */
function seo_head(array $o) {
    $canonical = isset($o['canonical']) ? abs_url($o['canonical']) : null;
    $image = abs_url($o['image'] ?? 'assets/images/logo_horizontal.png');
    $out = [];
    $out[] = '<base href="/">';
    $out[] = '<title>' . e($o['title']) . '</title>';
    $out[] = '<meta name="description" content="' . e($o['description']) . '">';
    if (!empty($o['robots'])) {
        $out[] = '<meta name="robots" content="' . e($o['robots']) . '">';
    }
    if ($canonical) {
        $out[] = '<link rel="canonical" href="' . e($canonical) . '">';
    }
    foreach (['prev', 'next'] as $rel) {
        if (!empty($o[$rel])) $out[] = '<link rel="' . $rel . '" href="' . e(abs_url($o[$rel])) . '">';
    }
    $og = [
        'og:locale' => 'tr_TR', 'og:site_name' => SEO_BRAND, 'og:type' => $o['type'] ?? 'website',
        'og:title' => $o['og_title'] ?? $o['title'], 'og:description' => $o['description'], 'og:image' => $image,
    ];
    if ($canonical) $og['og:url'] = $canonical;
    if (!empty($o['image_alt'])) $og['og:image:alt'] = $o['image_alt'];
    foreach ($og as $k => $v) {
        $out[] = '<meta property="' . $k . '" content="' . e($v) . '">';
    }
    $out[] = '<meta name="twitter:card" content="summary_large_image">';
    $out[] = '<meta name="twitter:title" content="' . e($o['og_title'] ?? $o['title']) . '">';
    $out[] = '<meta name="twitter:description" content="' . e($o['description']) . '">';
    $out[] = '<meta name="twitter:image" content="' . e($image) . '">';
    $out[] = '<link rel="icon" href="/favicon.ico" sizes="any">';
    $out[] = '<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">';
    $out[] = '<link rel="apple-touch-icon" href="/apple-touch-icon.png">';
    $out[] = '<meta name="theme-color" content="#dc3545">';
    foreach ($o['jsonld'] ?? [] as $data) {
        $out[] = '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }
    return implode("\n    ", $out) . "\n";
}

// --- JSON-LD ---
function jsonld_breadcrumb(array $items) {
    $list = [];
    foreach (array_values($items) as $i => [$name, $path]) {
        $el = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
        if ($path !== null) $el['item'] = abs_url($path);
        $list[] = $el;
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Görünür breadcrumb (Bootstrap) — son öğe bağlantısız */
function breadcrumb_html(array $items) {
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb fs-7 mb-0">';
    $n = count($items);
    foreach (array_values($items) as $i => [$name, $path]) {
        $html .= $i === $n - 1 || $path === null
            ? '<li class="breadcrumb-item active" aria-current="page">' . e($name) . '</li>'
            : '<li class="breadcrumb-item"><a href="' . e($path === '' ? '/' : $path) . '" class="text-danger text-decoration-none">' . e($name) . '</a></li>';
    }
    return $html . '</ol></nav>';
}

function jsonld_organization() {
    $same = array_values(array_filter([setting('social_instagram'), setting('social_facebook'), setting('social_x'), setting('social_youtube')]));
    $org = ['@type' => 'Organization', '@id' => CANONICAL_URL . '/#organization', 'name' => SEO_BRAND, 'url' => CANONICAL_URL . '/', 'logo' => abs_url('assets/images/logo_horizontal.png')];
    if ($same) $org['sameAs'] = $same;
    $phone = setting('site_phone');
    if ($phone) $org['contactPoint'] = ['@type' => 'ContactPoint', 'telephone' => $phone, 'contactType' => 'customer service', 'areaServed' => 'TR', 'availableLanguage' => 'Turkish'];
    return $org;
}

function jsonld_home() {
    $store = [
        '@type' => 'BookStore', '@id' => CANONICAL_URL . '/#store', 'name' => SEO_BRAND, 'url' => CANONICAL_URL . '/',
        'image' => abs_url('assets/images/logo_horizontal.png'), 'logo' => abs_url('assets/images/logo_horizontal.png'),
        'telephone' => setting('site_phone'), 'email' => setting('site_email'), 'priceRange' => '₺₺',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => setting('site_address', 'İstasyon Mah. Hikmet Özer Cad. No:48/A'), 'addressLocality' => 'Etimesgut', 'addressRegion' => 'Ankara', 'addressCountry' => 'TR'],
        'parentOrganization' => ['@id' => CANONICAL_URL . '/#organization'],
    ];
    $site = [
        '@type' => 'WebSite', '@id' => CANONICAL_URL . '/#website', 'name' => SEO_BRAND, 'url' => CANONICAL_URL . '/', 'inLanguage' => 'tr-TR',
        'publisher' => ['@id' => CANONICAL_URL . '/#organization'],
        'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => CANONICAL_URL . '/category.php?q={search_term_string}'], 'query-input' => 'required name=search_term_string'],
    ];
    return ['@context' => 'https://schema.org', '@graph' => [jsonld_organization(), $store, $site]];
}

function jsonld_book($b) {
    $url = abs_url(book_path($b));
    $data = [
        '@context' => 'https://schema.org',
        '@type' => ['Book', 'Product'],
        '@id' => $url . '#product',
        'name' => $b['title'],
        'url' => $url,
        'image' => [abs_url(cover_url($b['cover_image'] ?? ''))],
        'description' => trim((string)($b['summary'] ?? '')) !== '' ? mb_substr(trim($b['summary']), 0, 5000, 'UTF-8') : book_meta_description($b),
        'sku' => 'YK-' . (int)$b['id'],
        'inLanguage' => 'tr',
    ];
    if (!empty($b['author_name'])) $data['author'] = ['@type' => 'Person', 'name' => $b['author_name'], 'url' => abs_url(author_path(['slug' => $b['author_slug'] ?? '', 'name' => $b['author_name']]))];
    if (!empty($b['isbn'])) { $data['isbn'] = $b['isbn']; $data['gtin13'] = strlen(preg_replace('/\D/', '', $b['isbn'])) === 13 ? preg_replace('/\D/', '', $b['isbn']) : null; }
    if (!empty($b['publisher_name'])) { $data['publisher'] = ['@type' => 'Organization', 'name' => $b['publisher_name']]; $data['brand'] = ['@type' => 'Brand', 'name' => $b['publisher_name']]; }
    if (!empty($b['pages'])) $data['numberOfPages'] = (int)$b['pages'];
    if (!empty($b['year'])) $data['datePublished'] = (string)(int)$b['year'];
    if (!empty($b['category_name'])) $data['genre'] = $b['category_name'];
    $data['offers'] = [
        '@type' => 'Offer',
        'url' => $url,
        'price' => number_format((float)$b['price'], 2, '.', ''),
        'priceCurrency' => 'TRY',
        'availability' => (int)$b['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'itemCondition' => !empty($b['store_id']) ? 'https://schema.org/UsedCondition' : 'https://schema.org/NewCondition',
        'seller' => ['@type' => 'Organization', 'name' => !empty($b['store_name']) ? $b['store_name'] : SEO_BRAND],
    ];
    return array_filter($data, function ($v) { return $v !== null && $v !== ''; });
}
