<?php
/**
 * SİTE HARİTASI (/sitemap.xml) — veritabanından üretilir.
 * Toplam adres SITEMAP_LIMIT'i aşınca otomatik olarak sitemap index'e geçer:
 *   /sitemap.xml → /sitemap-pages.xml, /sitemap-categories.xml, /sitemap-authors.xml, /sitemap-books-1.xml, -2 ...
 */
require_once __DIR__ . '/config.php';

define('SITEMAP_LIMIT', max(1, (int)(getenv('SITEMAP_LIMIT') ?: 40000))); // Google sınırı 50.000 adres / 50 MB

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=3600');

$iso = function ($dt) {
    $t = $dt ? strtotime($dt . ' UTC') : false;
    return $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : null;
};

function sitemap_parts() {
    global $iso;
    $parts = ['pages' => [], 'categories' => [], 'authors' => [], 'books' => []];
    $latest = db_fetch("SELECT MAX(COALESCE(updated_at, created_at)) as m FROM books WHERE is_active = 1")['m'] ?? null;

    $parts['pages'][] = ['', $latest];
    $parts['pages'][] = ['magaza.php', $latest];
    foreach (db_fetch_all("SELECT s.slug, MAX(COALESCE(b.updated_at, b.created_at)) as m FROM stores s JOIN books b ON b.store_id = s.id AND b.is_active = 1 WHERE s.is_active = 1 GROUP BY s.id, s.slug") as $s) {
        $parts['pages'][] = ['magaza.php?s=' . rawurlencode($s['slug']), $s['m']];
    }
    foreach (['blog.php', 'contact.php', 'legal.php?p=teslimat-iade', 'legal.php?p=mesafeli-satis', 'legal.php?p=on-bilgilendirme', 'legal.php?p=kvkk', 'legal.php?p=gizlilik'] as $p) {
        $f = __DIR__ . '/' . strtok($p, '?');
        $parts['pages'][] = [$p, is_file($f) ? gmdate('Y-m-d H:i:s', filemtime($f)) : null];
    }
    foreach (db_fetch_all("SELECT c.id, c.name, c.slug, MAX(COALESCE(b.updated_at, b.created_at)) as m FROM categories c JOIN books b ON b.category_id = c.id AND b.is_active = 1 GROUP BY c.id, c.name, c.slug") as $c) {
        if (!is_numeric_category_name($c['name'])) {
            $parts['categories'][] = [category_path($c), $c['m']];
        }
    }
    foreach (db_fetch_all("SELECT a.slug, a.name, MAX(COALESCE(b.updated_at, b.created_at)) as m FROM authors a JOIN books b ON b.author_id = a.id AND b.is_active = 1 GROUP BY a.id, a.slug, a.name") as $a) {
        $parts['authors'][] = [author_path($a), $a['m']];
    }
    foreach (db_fetch_all("SELECT id, slug, COALESCE(updated_at, created_at) as m FROM books WHERE is_active = 1 ORDER BY id") as $b) {
        $parts['books'][] = [book_path($b), $b['m']];
    }
    return $parts;
}

function sitemap_urlset(array $urls) {
    global $iso;
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as [$path, $mod]) {
        $x .= '  <url><loc>' . htmlspecialchars(abs_url($path), ENT_XML1) . '</loc>' . (($m = $iso($mod)) ? '<lastmod>' . $m . '</lastmod>' : '') . "</url>\n";
    }
    return $x . '</urlset>';
}

$parts = sitemap_parts();
$total = array_sum(array_map('count', $parts));
$part = $_GET['part'] ?? '';

if ($part === '' && $total <= SITEMAP_LIMIT) {
    echo sitemap_urlset(array_merge($parts['pages'], $parts['categories'], $parts['authors'], $parts['books']));
    exit;
}

if ($part === '') {
    // Sitemap index
    $files = [];
    foreach (['pages', 'categories', 'authors'] as $k) {
        if ($parts[$k]) $files[] = ['sitemap-' . $k . '.xml', max(array_column($parts[$k], 1))];
    }
    foreach (array_chunk($parts['books'], SITEMAP_LIMIT) as $i => $chunk) {
        $files[] = ['sitemap-books-' . ($i + 1) . '.xml', max(array_column($chunk, 1))];
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($files as [$f, $m]) {
        echo '  <sitemap><loc>' . htmlspecialchars(abs_url($f), ENT_XML1) . '</loc>' . (($mm = $iso($m)) ? '<lastmod>' . $mm . '</lastmod>' : '') . "</sitemap>\n";
    }
    echo '</sitemapindex>';
    exit;
}

if (!isset($parts[$part])) {
    http_response_code(404);
    exit;
}
$urls = $parts[$part];
if ($part === 'books') {
    $n = max(1, (int)($_GET['n'] ?? 1));
    $urls = array_slice($urls, ($n - 1) * SITEMAP_LIMIT, SITEMAP_LIMIT);
    if (!$urls) {
        http_response_code(404);
        exit;
    }
}
echo sitemap_urlset($urls);
