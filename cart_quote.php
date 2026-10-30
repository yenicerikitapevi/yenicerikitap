<?php
/**
 * Sepet özeti (ödeme sayfasındaki canlı özet için): kampanyalar, kargo ve kupon siparişteki hesapla aynı fonksiyonla hesaplanır.
 * Yalnızca bilgi verir; sipariş checkout.php'de yeniden hesaplanır.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$requested = [];
$items = json_decode($_POST['cart_data'] ?? '[]', true);
if (is_array($items)) {
    foreach (array_slice($items, 0, 200) as $item) {
        $b_id = (int)($item['id'] ?? 0);
        $qty  = (int)($item['qty'] ?? 0);
        if ($b_id > 0 && $qty > 0) {
            $requested[$b_id] = min(999, ($requested[$b_id] ?? 0) + $qty);
        }
    }
}

[$lines, $errors] = cart_build_lines($requested);
$quote = campaign_apply($lines, substr(trim($_POST['coupon_code'] ?? ''), 0, 50));

$offers = [];
foreach (campaigns_active() as $c) {
    if ($c['type'] !== 'commission' && $c['show_badge']) {
        $offers[] = $c['name'] . ($c['description'] ? ': ' . $c['description'] : '');
    }
}

echo json_encode($quote + [
    'lines'      => array_map(function ($l) {
        return ['id' => (int)$l['id'], 'title' => $l['title'], 'qty' => $l['qty'], 'price' => $l['price'], 'total' => $l['total'], 'discount' => $l['discount'], 'net' => $l['net']];
    }, $lines),
    'errors'     => $errors,
    'free_limit' => (float)setting('shipping_free_limit', '350'),
    'offers'     => array_slice($offers, 0, 6),
], JSON_UNESCAPED_UNICODE);
