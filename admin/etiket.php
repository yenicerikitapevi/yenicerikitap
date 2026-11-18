<?php
/** Kargo adres etiketi (yönetim): gönderici Yeniçeri Kitabevi; ?store=ID ile o sahaf adına */
require_once __DIR__ . '/../config.php';
auth_check('admin');

$store_id = (int)($_GET['store'] ?? 0) ?: null;
$labels = [];
$skipped = [];
foreach (label_requested_ids() as $oid) {
    $d = label_data($oid, $store_id);
    if ($d) {
        $labels[] = $d;
    } else {
        $skipped[] = '#' . $oid;
    }
}
label_render_page($labels, $_GET['boyut'] ?? '10x15', $skipped, 'index.php?p=Orders');
