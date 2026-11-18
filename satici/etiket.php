<?php
/** Kargo adres etiketi (sahaf): gönderici sahafın dükkânı, yalnızca kendi kitapları ve ödemesi alınmış siparişler */
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['store_user'])) {
    redirect('login.php');
}
$store = db_fetch("SELECT * FROM stores WHERE id = ?", [(int)$_SESSION['store_user']['id']]);
if (!$store || !$store['is_active']) {
    unset($_SESSION['store_user']);
    redirect('login.php');
}

$labels = [];
$skipped = [];
foreach (label_requested_ids() as $oid) {
    $d = label_data($oid, (int)$store['id']);
    if ($d && in_array($d['order']['status'], so_paid_statuses(), true)) {
        $labels[] = $d;
    } else {
        $skipped[] = $d ? $d['order']['order_number'] : '#' . $oid;
    }
}
label_render_page($labels, $_GET['boyut'] ?? '10x15', $skipped, 'index.php?tab=siparis');
