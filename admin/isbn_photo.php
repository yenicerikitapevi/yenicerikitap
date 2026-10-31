<?php
/**
 * ISBN Yardımcısı – fotoğraftan kitap tanıma (yalnızca giriş yapmış yönetici).
 * POST photo=<resim>  →  JSON
 *   { ok, ai, isbn, isbn_candidates[], fields{title, author, publisher, translator, year, original_title},
 *     image_kind, cover_data (600x900 JPEG, data: URL), note }
 * Barkod okunamadığında panel bu adresi çağırır. Fotoğraftaki yazılar Claude ile okunur;
 * ISBN görünmüyorsa ad + yazar ile Google Books / Open Library'den ISBN aranır.
 * Anahtar yoksa (ANTHROPIC_API_KEY) yalnızca fotoğraf 600x900 kapağa çevrilir.
 */
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(60);
require __DIR__ . '/isbn_helper/lib.php';
try {
    require_once __DIR__ . '/../config.php'; // oturum
} catch (Throwable $e) {
}
ih_require_admin();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ih_set_deadline(52);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
    ih_json(['ok' => false, 'error' => 'Fotoğraf gönderilmedi.'], 400);
}
if ((int)$_FILES['photo']['size'] > 8 * 1024 * 1024) {
    ih_json(['ok' => false, 'error' => 'Fotoğraf 8 MB\'tan büyük olamaz.'], 400);
}
$bytes = (string)file_get_contents($_FILES['photo']['tmp_name']);
$info = @getimagesizefromstring($bytes);
if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
    ih_json(['ok' => false, 'error' => 'Yalnızca JPEG, PNG ya da WEBP fotoğraf yüklenebilir.'], 400);
}
if (!function_exists('imagecreatefromstring')) {
    ih_json(['ok' => false, 'error' => 'Sunucuda GD eklentisi kapalı.'], 500);
}
$im = ih_load_image($bytes);
if (!$im) {
    ih_json(['ok' => false, 'error' => 'Fotoğraf açılamadı.'], 400);
}

// Claude'a gidecek kopya: uzun kenar en fazla 1568 px (hız ve maliyet)
$w = imagesx($im);
$h = imagesy($im);
$scale = min(1, 1568 / max($w, $h));
$send = $im;
if ($scale < 1) {
    $send = imagecreatetruecolor((int)round($w * $scale), (int)round($h * $scale));
    imagecopyresampled($send, $im, 0, 0, 0, 0, imagesx($send), imagesy($send), $w, $h);
}
ob_start();
imagejpeg($send, null, 85);
$jpeg = ob_get_clean();

/** Fotoğrafın (ya da kapak bölgesinin) 600x900 JPEG'i, data: URL */
function ih_photo_cover($im, $box)
{
    $w = imagesx($im);
    $h = imagesy($im);
    $src = $im;
    if (is_array($box) && isset($box['x'], $box['y'], $box['w'], $box['h'])) {
        $x = max(0, min(1, (float)$box['x']));
        $y = max(0, min(1, (float)$box['y']));
        $bw = max(0, min(1 - $x, (float)$box['w']));
        $bh = max(0, min(1 - $y, (float)$box['h']));
        if ($bw > 0.2 && $bh > 0.2) {
            $cw = max(1, (int)round($bw * $w));
            $ch = max(1, (int)round($bh * $h));
            $src = imagecreatetruecolor($cw, $ch);
            imagecopy($src, $im, 0, 0, (int)round($x * $w), (int)round($y * $h), $cw, $ch);
        }
    }
    $out = ih_to_600x900($src);
    ob_start();
    imagejpeg($out, null, 86);
    return 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());
}

$cfg = ih_config();
$key = (string)($cfg['anthropic_api_key'] ?? '');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if ($key === '' || !is_file($autoload)) {
    ih_json([
        'ok' => false, 'ai' => false, 'cover_data' => ih_photo_cover($im, null),
        'error' => $key === ''
            ? 'Fotoğraftan tanıma kapalı: sunucuda ANTHROPIC_API_KEY tanımlı değil. Barkod okunamadı; ISBN\'i elle yazın. Fotoğraf kapak olarak kullanılabilir.'
            : 'Fotoğraftan tanıma kurulumu eksik (vendor klasörü yok). Fotoğraf kapak olarak kullanılabilir.',
    ]);
}
require_once $autoload;

$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['image_kind', 'isbn', 'title', 'author', 'publisher', 'translator', 'year', 'original_title', 'cover_box'],
    'properties' => [
        'image_kind' => ['type' => 'string', 'enum' => ['front_cover', 'back_cover', 'title_page', 'copyright_page', 'spine', 'not_a_book']],
        'isbn' => ['type' => ['string', 'null'], 'description' => 'Fotoğrafta görünen ISBN (yalnızca rakamlar ve X). Görünmüyorsa null.'],
        'title' => ['type' => ['string', 'null']],
        'author' => ['type' => ['string', 'null']],
        'publisher' => ['type' => ['string', 'null']],
        'translator' => ['type' => ['string', 'null']],
        'year' => ['type' => ['integer', 'null']],
        'original_title' => ['type' => ['string', 'null']],
        'cover_box' => [
            'type' => ['object', 'null'],
            'description' => 'Ön kapak fotoğrafıysa kapağın fotoğraftaki yeri, 0-1 arası oranlarla. Değilse null.',
            'additionalProperties' => false,
            'required' => ['x', 'y', 'w', 'h'],
            'properties' => ['x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'w' => ['type' => 'number'], 'h' => ['type' => 'number']],
        ],
    ],
];
$prompt = "Bu fotoğraf bir sahafın satışa koyacağı (çoğunlukla Türkçe) bir kitaba ait. Fotoğrafta YAZAN bilgileri çıkar.\n"
    . "- Yalnızca fotoğrafta okunabilen bilgileri yaz; tahmin etme, okunamayan alanı null bırak.\n"
    . "- Adları fotoğraftaki yazımıyla, Türkçe karakterleri koruyarak yaz; tamamı büyük harfse normal yazıma çevir (ör. SABAHATTİN ALİ → Sabahattin Ali).\n"
    . "- Kitap adına alt başlık eklenmez; seri adı, \"roman\", \"öykü\" gibi tür etiketleri ad değildir.\n"
    . "- Çevirmen \"Çeviren\" / \"Türkçesi\" ifadesinin yanındaki addır. Özgün ad yalnızca yazılıysa doldurulur.\n"
    . "- Yıl, baskı yılıdır (künyede görünüyorsa).";

$t0 = microtime(true);
try {
    $client = new \Anthropic\Client(apiKey: $key);
    $message = $client->messages->create(
        model: (string)($cfg['vision_model'] ?? 'claude-opus-5-5'),
        maxTokens: 4000,
        messages: [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
        outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        requestOptions: [
            'maxRetries' => 1,
            'transporter' => new \GuzzleHttp\Client(['timeout' => 35, 'connect_timeout' => 8]),
        ],
    );
} catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
    $why = (int)$e->status === 401 ? 'API anahtarı geçersiz' : ((int)$e->status === 429 ? 'istek sınırı doldu, biraz sonra deneyin' : 'Claude hizmeti hata verdi');
    ih_json(['ok' => false, 'ai' => true, 'cover_data' => ih_photo_cover($im, null), 'error' => 'Fotoğraf okunamadı: ' . $why . '. ISBN\'i elle yazabilirsiniz.' . (!empty($cfg['debug']) ? ' ' . $e->getMessage() : '')]);
} catch (Throwable $e) {
    ih_json(['ok' => false, 'ai' => true, 'cover_data' => ih_photo_cover($im, null), 'error' => 'Fotoğraf okunamadı (bağlantı/zaman aşımı). ISBN\'i elle yazabilirsiniz.' . (!empty($cfg['debug']) ? ' ' . $e->getMessage() : '')]);
}

if ($message->stopReason === 'refusal') {
    ih_json(['ok' => false, 'ai' => true, 'cover_data' => ih_photo_cover($im, null), 'error' => 'Fotoğraf işlenemedi.']);
}
$text = '';
foreach ($message->content as $block) {
    if ($block->type === 'text') {
        $text .= $block->text;
    }
}
$d = json_decode($text, true);
if (!is_array($d)) {
    ih_json(['ok' => false, 'ai' => true, 'cover_data' => ih_photo_cover($im, null), 'error' => 'Fotoğraftaki yazılar çözümlenemedi.']);
}
if (($d['image_kind'] ?? '') === 'not_a_book') {
    ih_json(['ok' => false, 'ai' => true, 'error' => 'Fotoğrafta kitap görülemedi. Kapağı ya da arka kapaktaki barkodu yakından çekin.']);
}

$fields = [];
foreach (['title', 'author', 'publisher', 'translator', 'original_title'] as $f) {
    $v = isset($d[$f]) && is_string($d[$f]) ? trim(preg_replace('/\s+/u', ' ', $d[$f])) : '';
    if ($v !== '') $fields[$f] = $v;
}
if (!empty($d['year']) && (int)$d['year'] >= 1500 && (int)$d['year'] <= (int)date('Y') + 1) {
    $fields['year'] = (int)$d['year'];
}

// ISBN: fotoğrafta varsa doğrula; yoksa ad + yazar ile ara
$isbn = !empty($d['isbn']) ? ih_normalize_isbn((string)$d['isbn']) : null;
$cands = [];
$isbn_from = $isbn ? 'fotoğraf' : null;
if (!$isbn && !empty($fields['title']) && ih_time_left() > 8) {
    $q = 'intitle:' . $fields['title'] . (!empty($fields['author']) ? ' inauthor:' . $fields['author'] : '');
    $gb = 'https://www.googleapis.com/books/v1/volumes?maxResults=10&q=' . rawurlencode($q) . (!empty($cfg['google_books_key']) ? '&key=' . rawurlencode($cfg['google_books_key']) : '');
    $ol = 'https://openlibrary.org/search.json?limit=10&fields=title,author_name,publisher,isbn&title=' . rawurlencode($fields['title']) . (!empty($fields['author']) ? '&author=' . rawurlencode($fields['author']) : '');
    $rs = ih_fetch_many(['gb' => $gb, 'ol' => $ol]);
    $want_pub = !empty($fields['publisher']) ? ih_norm($fields['publisher']) : '';
    $add = function ($isbn13, $title, $pub, $src) use (&$cands, $want_pub) {
        $isbn13 = ih_normalize_isbn($isbn13);
        if (!$isbn13 || isset($cands[$isbn13])) return;
        $score = 0;
        if ($want_pub && $pub && (strpos(ih_norm($pub), $want_pub) !== false || strpos($want_pub, ih_norm($pub)) !== false)) $score += 2;
        if (strpos($isbn13, '978975') === 0 || strpos($isbn13, '978605') === 0 || strpos($isbn13, '978625') === 0) $score += 1; // Türkiye
        $cands[$isbn13] = ['isbn' => $isbn13, 'title' => $title, 'publisher' => $pub, 'source' => $src, 'score' => $score];
    };
    if (!empty($rs['gb']) && $rs['gb']['code'] === 200) {
        $j = json_decode($rs['gb']['body'], true);
        foreach ((array)($j['items'] ?? []) as $it) {
            $v = $it['volumeInfo'] ?? [];
            foreach ((array)($v['industryIdentifiers'] ?? []) as $id) {
                if (($id['type'] ?? '') === 'ISBN_13' || ($id['type'] ?? '') === 'ISBN_10') {
                    $add($id['identifier'], $v['title'] ?? '', $v['publisher'] ?? '', 'Google Books');
                }
            }
        }
    }
    if (!empty($rs['ol']) && $rs['ol']['code'] === 200) {
        $j = json_decode($rs['ol']['body'], true);
        foreach ((array)($j['docs'] ?? []) as $doc) {
            foreach (array_slice((array)($doc['isbn'] ?? []), 0, 6) as $i) {
                $add($i, $doc['title'] ?? '', isset($doc['publisher'][0]) ? $doc['publisher'][0] : '', 'Open Library');
            }
        }
    }
    $cands = array_values($cands);
    usort($cands, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $cands = array_slice($cands, 0, 8);
    // Yayınevi de tutan tek aday varsa onu kullan; değilse seçimi kullanıcıya bırak
    $strong = array_values(array_filter($cands, function ($c) { return $c['score'] >= 2; }));
    if (count($strong) === 1) {
        $isbn = $strong[0]['isbn'];
        $isbn_from = $strong[0]['source'] . ' (ad + yazar + yayınevi)';
    }
}

$is_front = ($d['image_kind'] ?? '') === 'front_cover';
ih_json([
    'ok' => true,
    'ai' => true,
    'image_kind' => $d['image_kind'] ?? null,
    'isbn' => $isbn,
    'isbn_from' => $isbn_from,
    'isbn_candidates' => $cands,
    'fields' => $fields,
    'cover_data' => $is_front ? ih_photo_cover($im, $d['cover_box'] ?? null) : null,
    'took_ms' => (int)round((microtime(true) - $t0) * 1000),
]);
