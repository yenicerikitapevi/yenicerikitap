<?php
/**
 * KARGO ADRES ETİKETİ: yazdırılabilir gönderici/alıcı etiketi (10x15 cm termal ya da A4).
 *  - Yönetim: admin/etiket.php (gönderici Yeniçeri Kitabevi; ?store=ID ile sahaf adına)
 *  - Sahaf:   satici/etiket.php (gönderici sahafın dükkânı, yalnızca kendi kitapları)
 * Kargo firmasıyla bağlantı yoktur: etiket pakete yapıştırılır, takip barkodunu kargo şubesi basar.
 */
if (!defined('SITE_URL')) exit;

/** Code 128-B desen genişlikleri (değer 0..106; 104 = Start B, 106 = Stop) */
function label_code128_patterns() {
    return ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'];
}

/** Metni Code 128-B değerlerine çevirir (başlangıç, veri, sağlama, bitiş). Yazdırılamayan karakterler atlanır. */
function label_code128_values($text) {
    $vals = [104];
    foreach (str_split((string)$text) as $ch) {
        $o = ord($ch);
        if ($o >= 32 && $o <= 127) $vals[] = $o - 32;
    }
    $sum = 104;
    for ($i = 1; $i < count($vals); $i++) $sum += $i * $vals[$i];
    $vals[] = $sum % 103;
    $vals[] = 106;
    return $vals;
}

/** Code 128-B barkodunu SVG olarak döndürür (sessiz bölge dahil) */
function label_barcode_svg($text, $height = 60) {
    $pat = label_code128_patterns();
    $x = 10; // sol sessiz bölge (10 modül)
    $rects = '';
    foreach (label_code128_values($text) as $v) {
        $bar = true;
        foreach (str_split($pat[$v]) as $w) {
            $w = (int)$w;
            if ($bar) $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . (int)$height . '"/>';
            $x += $w;
            $bar = !$bar;
        }
    }
    $width = $x + 10;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . (int)$height . '" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="' . e($text) . '"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
}

/** Gönderici: null → Yeniçeri Kitabevi, aksi halde sahaf dükkânı */
function label_sender($store_id = null) {
    if ($store_id) {
        $s = db_fetch("SELECT * FROM stores WHERE id = ?", [(int)$store_id]);
        if ($s) {
            $addr = trim((string)($s['address'] ?? ''));
            $city = trim((string)($s['city'] ?? ''));
            if ($city !== '' && mb_stripos($addr, $city, 0, 'UTF-8') === false) $addr = trim($addr . ($addr !== '' ? "\n" : '') . $city);
            return ['name' => $s['name'], 'sub' => trim((string)($s['company_title'] ?? '')) !== '' && $s['company_title'] !== $s['name'] ? $s['company_title'] : APP_NAME . ' satıcısı',
                'address' => $addr, 'phone' => trim((string)($s['phone'] ?? ''))];
        }
    }
    return ['name' => APP_NAME, 'sub' => '', 'address' => setting('site_address', ''), 'phone' => setting('site_phone', '')];
}

/**
 * Etiket verisi: $store_id null → Yeniçeri'nin kendi kitapları, aksi halde o sahafın kitapları.
 * Dönüş null: sipariş yok ya da bu gönderene ait kitap yok.
 */
function label_data($order_id, $store_id = null) {
    $o = db_fetch("SELECT o.*, u.name AS customer_name, u.phone AS customer_phone FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?", [(int)$order_id]);
    if (!$o) return null;
    $sql = "SELECT oi.book_id, oi.quantity, b.title, b.shelf_code, b.isbn FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?";
    if ($store_id) {
        $items = db_fetch_all($sql . " AND b.store_id = ? ORDER BY oi.id", [(int)$order_id, (int)$store_id]);
        if (!$items) return null;
    } else {
        $items = db_fetch_all($sql . " AND (b.id IS NULL OR b.store_id IS NULL) ORDER BY oi.id", [(int)$order_id]);
        if (!$items) return null; // Yeniçeri'nin kitabı yok: her sahafın paketi ayrı etikettir (?store=ID)
    }
    $total_items = (int)(db_fetch("SELECT COUNT(*) AS c FROM order_items WHERE order_id = ?", [(int)$order_id])['c'] ?? 0);
    $carrier = shipping_carriers()[$o['shipping_carrier'] ?? ''] ?? null;
    return [
        'order' => $o,
        'sender' => label_sender($store_id),
        'items' => $items,
        'partial' => count($items) < $total_items, // karma sipariş: bu paket siparişin bir kısmı
        'carrier' => $carrier ? $carrier['name'] : '',
    ];
}

/** Bir ya da birden çok etiketi tam sayfa olarak basar. $size: '10x15' | 'a4' */
function label_render_page(array $labels, $size, $skipped = [], $back_url = '') {
    $size = $size === 'a4' ? 'a4' : '10x15';
    $q = $_GET;
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kargo Etiketi<?= count($labels) === 1 ? ' ' . e($labels[0]['order']['order_number']) : ' (' . count($labels) . ')' ?></title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000; }
    .bar { position: sticky; top: 0; z-index: 2; background: #212529; color: #fff; padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 14px; }
    .bar a, .bar button { color: #fff; background: #495057; border: 0; border-radius: 6px; padding: 7px 12px; font-size: 14px; text-decoration: none; cursor: pointer; }
    .bar .on { background: #dc3545; font-weight: bold; }
    .bar .print { background: #dc3545; font-weight: bold; margin-left: auto; }
    .warn { background: #fff3cd; color: #664d03; padding: 8px 16px; font-size: 13px; }
    .sheet { background: #fff; margin: 16px auto; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
    .s-10x15 .sheet { width: 100mm; height: 150mm; padding: 3mm; }
    .s-a4 .sheet { width: 210mm; min-height: 297mm; padding: 10mm; }
    .label { width: 100%; height: 100%; border: 2px solid #000; display: flex; flex-direction: column; font-size: 10pt; overflow: hidden; }
    .s-a4 .label { width: 100mm; height: 150mm; outline: 1px dashed #999; outline-offset: 3mm; }
    .sec { padding: 2mm 3mm; border-bottom: 2px solid #000; }
    .k { font-size: 7pt; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #333; }
    .from .n { font-weight: bold; font-size: 10pt; }
    .from .a { font-size: 8pt; white-space: pre-line; line-height: 1.25; }
    .to { flex: 1 1 auto; min-height: 0; overflow: hidden; } /* uzun adres barkodu taşırmasın: alıcı alanı küçülür */
    .to.long .n { font-size: 12pt; }
    .to.long .a { font-size: 9.5pt; line-height: 1.2; }
    .to.long .p { font-size: 10pt; }
    .sec:not(.to), .bc, .items { flex-shrink: 0; }
    .to .n { font-weight: bold; font-size: 15pt; line-height: 1.15; margin: 1mm 0; }
    .to .a { font-size: 12pt; white-space: pre-line; line-height: 1.3; }
    .to .p { font-size: 12pt; font-weight: bold; margin-top: 1.5mm; }
    .meta { display: flex; justify-content: space-between; gap: 2mm; font-size: 9pt; }
    .meta b { font-size: 11pt; }
    .bc { padding: 2mm 3mm 1mm; text-align: center; border-bottom: 2px solid #000; }
    .bc svg { width: 100%; height: 14mm; display: block; }
    .bc .t { font-family: "Courier New", monospace; font-weight: bold; font-size: 11pt; letter-spacing: 1px; }
    .items { padding: 1.5mm 3mm; font-size: 7.5pt; line-height: 1.3; }
    .items .r { font-weight: bold; }
    .pack { margin-top: 14mm; font-size: 11pt; }
    .pack table { width: 100%; border-collapse: collapse; }
    .pack th, .pack td { border-bottom: 1px solid #ccc; padding: 5px 4px; text-align: left; }
    .pack h3 { margin: 0 0 6px; font-size: 14pt; }
    @media print {
        body { background: #fff; }
        .bar, .warn { display: none !important; }
        .sheet { margin: 0; box-shadow: none; page-break-after: always; break-after: page; }
        .sheet:last-child { page-break-after: auto; break-after: auto; }
        .s-a4 .label { outline: 1px dashed #999; }
    }
    @page { size: <?= $size === 'a4' ? 'A4' : '100mm 150mm' ?>; margin: 0; }
</style>
</head>
<body class="s-<?= $size ?>">
<div class="bar">
    <?php if ($back_url !== ''): ?><a href="<?= e($back_url) ?>">← Geri</a><?php endif; ?>
    <span>Boyut:</span>
    <a href="<?= e('?' . http_build_query(array_merge($q, ['boyut' => '10x15']))) ?>" class="<?= $size === '10x15' ? 'on' : '' ?>">10×15 cm termal</a>
    <a href="<?= e('?' . http_build_query(array_merge($q, ['boyut' => 'a4']))) ?>" class="<?= $size === 'a4' ? 'on' : '' ?>">A4 (kes-yapıştır + hazırlama listesi)</a>
    <button type="button" class="print" onclick="window.print()">Yazdır</button>
</div>
<?php if ($skipped): ?><div class="warn">Etiketi basılmayan siparişler: <?= e(implode(', ', $skipped)) ?> (ödemesi alınmamış, iptal edilmiş ya da size ait kitap yok).</div><?php endif; ?>
<?php if (!$labels): ?><div class="warn">Yazdırılacak etiket yok.</div><?php endif; ?>
<?php foreach ($labels as $L):
    $o = $L['order'];
    $s = $L['sender'];
    $max = $size === 'a4' ? 4 : 6;
    $shown = array_slice($L['items'], 0, $max);
    $qty = array_sum(array_map(function ($it) { return (int)$it['quantity']; }, $L['items'])); ?>
    <div class="sheet">
        <div class="label">
            <div class="sec from">
                <div class="k">Gönderen</div>
                <div class="n"><?= e($s['name']) ?></div>
                <?php if ($s['sub'] !== ''): ?><div class="a"><?= e($s['sub']) ?></div><?php endif; ?>
                <div class="a"><?= e($s['address']) ?><?= $s['phone'] !== '' ? "\nTel: " . e($s['phone']) : '' ?></div>
            </div>
            <?php $addr_len = mb_strlen(trim((string)$o['shipping_address']) . (string)$o['customer_name'], 'UTF-8') + 40 * max(0, count($shown) - 3); ?>
            <div class="sec to<?= $addr_len > 170 ? ' long' : '' ?>">
                <div class="k">Alıcı</div>
                <div class="n"><?= e(trim((string)$o['customer_name']) ?: '—') ?></div>
                <div class="a"><?= e(trim((string)$o['shipping_address']) ?: '—') ?></div>
                <?php if (trim((string)$o['customer_phone']) !== ''): ?><div class="p">Tel: <?= e($o['customer_phone']) ?></div><?php endif; ?>
            </div>
            <div class="sec meta">
                <div><span class="k">Sipariş</span><br><b><?= e($o['order_number']) ?></b></div>
                <div><span class="k">Kargo</span><br><b><?= e($L['carrier'] !== '' ? $L['carrier'] : '—') ?></b></div>
                <div><span class="k">Adet</span><br><b><?= $qty ?><?= $L['partial'] ? ' (kısmi)' : '' ?></b></div>
            </div>
            <div class="bc">
                <?= label_barcode_svg($o['order_number']) ?>
                <div class="t"><?= e($o['order_number']) ?></div>
            </div>
            <div class="items">
                <?php foreach ($shown as $it): ?>
                    <div><?= trim((string)$it['shelf_code']) !== '' ? '<span class="r">[' . e($it['shelf_code']) . ']</span> ' : '' ?><?= e(mb_strimwidth($it['title'] ?: 'Kitap #' . $it['book_id'], 0, 60, '…', 'UTF-8')) ?><?= (int)$it['quantity'] > 1 ? ' ×' . (int)$it['quantity'] : '' ?></div>
                <?php endforeach; ?>
                <?php if (count($L['items']) > $max): ?><div>+ <?= count($L['items']) - $max ?> kitap daha</div><?php endif; ?>
            </div>
        </div>
        <?php if ($size === 'a4'): ?>
            <div class="pack">
                <h3>Hazırlama Listesi — <?= e($o['order_number']) ?></h3>
                <?php if ($L['partial']): ?><p style="font-size:10pt;margin:0 0 6px">Bu paket siparişin bir kısmıdır; diğer kitaplar ayrı paketle gönderilir.</p><?php endif; ?>
                <table>
                    <thead><tr><th>Raf</th><th>Kitap</th><th>ISBN</th><th style="text-align:right">Adet</th><th>✓</th></tr></thead>
                    <tbody>
                    <?php foreach ($L['items'] as $it): ?>
                        <tr><td><b><?= e($it['shelf_code'] ?? '') ?></b></td><td><?= e($it['title'] ?: 'Kitap #' . $it['book_id']) ?></td><td><?= e($it['isbn'] ?? '') ?></td><td style="text-align:right"><?= (int)$it['quantity'] ?></td><td>☐</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** ?id=5 ya da ?ids=5,6,7 → sipariş id listesi (en fazla 100) */
function label_requested_ids() {
    $raw = (string)($_GET['ids'] ?? ($_GET['id'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v > 0; })));
    return array_slice($ids, 0, 100);
}
