<?php
/**
 * ESKİ SİTE ADRESLERİ → 301 (Google'da kalan /urun/... ve /sayfa/... bağlantıları)
 * /urun/...  : kitap (id, slug, ad benzerliği) → olmazsa kategori → olmazsa ana sayfa
 * /sayfa/... : sabit sayfa anahtar kelimesiyle eşleştirilir → olmazsa ana sayfa
 */
require_once __DIR__ . '/config.php';

$type = $_GET['type'] ?? '';
$path = (string)($_GET['path'] ?? '');

if ($type === 'urun') {
    if ($b = legacy_find_book($path)) redirect_301(book_path($b));
    if ($c = legacy_find_category($path)) redirect_301(category_path($c));
    redirect_301('');
}

if ($type === 'sayfa') {
    $s = legacy_clean_slug($path);
    $map = [
        'iletisim' => 'contact.php', 'bize-ulasin' => 'contact.php', 'adres' => 'contact.php', 'hakkimizda' => 'contact.php', 'hakkinda' => 'contact.php', 'subeler' => 'contact.php',
        'mesafeli' => 'legal.php?p=mesafeli-satis', 'satis-sozlesmesi' => 'legal.php?p=mesafeli-satis', 'sozlesme' => 'legal.php?p=mesafeli-satis',
        'on-bilgilendirme' => 'legal.php?p=on-bilgilendirme',
        'teslimat' => 'legal.php?p=teslimat-iade', 'iade' => 'legal.php?p=teslimat-iade', 'kargo' => 'legal.php?p=teslimat-iade', 'iptal' => 'legal.php?p=teslimat-iade',
        'kvkk' => 'legal.php?p=kvkk', 'kisisel-veri' => 'legal.php?p=kvkk',
        'gizlilik' => 'legal.php?p=gizlilik', 'cerez' => 'legal.php?p=gizlilik',
        'blog' => 'blog.php', 'yazi' => 'blog.php',
        'sahaf' => 'magaza.php', 'magaza' => 'magaza.php', 'dukkan' => 'magaza.php',
    ];
    foreach ($map as $key => $target) {
        if ($s === $key || strpos('-' . $s . '-', '-' . $key . '-') !== false || strpos($s, $key) === 0) {
            redirect_301($target);
        }
    }
    redirect_301('');
}

require __DIR__ . '/404.php';
