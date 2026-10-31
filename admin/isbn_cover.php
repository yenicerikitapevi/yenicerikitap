<?php
/**
 * ISBN Yardımcısı – kapak vekili.
 * /admin/isbn_cover.php?u[]=<görsel adresi>&u[]=<alternatif>   → 600x900 JPEG
 * Birden çok adres verilirse öncelik sırasındaki ilk uygun (≥250×350, kitap oranında) kapak seçilir; yoksa en iyisi.
 * Tarayıcının CORS kısıtına takılmadan görseli forma dosya olarak koymak için kullanılır.
 */
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(40);
require __DIR__ . '/isbn_helper/lib.php';

ih_require_admin();

if (!function_exists('imagecreatefromstring') || !function_exists('curl_init')) {
    ih_json(['ok' => false, 'error' => 'Sunucuda php-gd veya php-curl eklentisi kapalı.'], 500);
}
$urls = [];
$u = isset($_GET['u']) ? $_GET['u'] : (isset($_GET['url']) ? $_GET['url'] : []);
foreach ((array)$u as $x) {
    $x = trim((string)$x);
    if ($x !== '' && ih_public_url($x)) {
        $urls[] = $x;
    }
}
$urls = array_slice(array_values(array_unique($urls)), 0, 6);
if (!$urls) {
    ih_json(['ok' => false, 'error' => 'Geçerli kapak adresi yok.'], 400);
}
$reqs = [];
foreach ($urls as $i => $x) {
    $p = parse_url($x);
    $reqs['c' . $i] = ['url' => $x, 'headers' => ['Referer: ' . $p['scheme'] . '://' . $p['host'] . '/', 'Accept: image/*,*/*;q=0.8']];
}
$got = ih_fetch_many($reqs);
$best = null;
$bestScore = -1;
foreach ($urls as $i => $x) {
    $r = $got['c' . $i];
    if ($r['code'] !== 200 || strlen($r['body']) > 15 * 1024 * 1024) {
        continue;
    }
    $im = ih_load_image($r['body']);
    if (!$im) {
        continue;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 150 || $h < 200) {
        $score = $w * $h * 0.1;
    } else {
        $ratio = $w / $h;
        $score = $w * $h * (($ratio >= 0.45 && $ratio <= 0.85) ? 1.0 : 0.35);
    }
    // Adresler öncelik sırasında gelir (önce mağazalar, en son Open Library / Google).
    // Yeterince büyük ve kitap oranında ilk kapak seçilir: büyük ama eski baskı kapağı güncel mağaza kapağının önüne geçmesin.
    $usable = $w >= 250 && $h >= 350 && ($w / $h) >= 0.45 && ($w / $h) <= 0.85;
    if ($usable) {
        if ($best) {
            imagedestroy($best['im']);
        }
        $best = ['im' => $im, 'url' => $x, 'w' => $w, 'h' => $h];
        break;
    }
    // Uygun kapak yoksa en iyi puanlı yedek olarak tutulur
    if ($score > $bestScore) {
        if ($best) {
            imagedestroy($best['im']);
        }
        $best = ['im' => $im, 'url' => $x, 'w' => $w, 'h' => $h];
        $bestScore = $score;
    } else {
        imagedestroy($im);
    }
}
if (!$best) {
    ih_json(['ok' => false, 'error' => 'Kapak indirilemedi.'], 502);
}
$out = ih_to_600x900($best['im']);
$q = 90;
do {
    ob_start();
    imagejpeg($out, null, $q);
    $jpg = ob_get_clean();
    $q -= 10;
} while (strlen($jpg) > 4.5 * 1024 * 1024 && $q >= 50);

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($jpg));
header('Cache-Control: private, max-age=300');
header('X-Cover-Source: ' . $best['url']);
header('X-Cover-Original: ' . $best['w'] . 'x' . $best['h']);
echo $jpg;
