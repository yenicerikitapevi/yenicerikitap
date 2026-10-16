<?php
require_once __DIR__ . '/../config.php';

auth_check('admin');

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order = db_fetch("SELECT o.*, u.name as customer_name, u.email as customer_email, u.phone as customer_phone 
                  FROM orders o 
                  LEFT JOIN users u ON o.user_id = u.id 
                  WHERE o.id = ?", [$order_id]);

if (!$order) {
    die("Sipariş bulunamadı!");
}

$items = db_fetch_all("SELECT oi.*, b.title, b.isbn 
                      FROM order_items oi 
                      LEFT JOIN books b ON oi.book_id = b.id 
                      WHERE oi.order_id = ?", [$order_id]);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Fatura #<?= e($order['order_number']) ?> | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #fff; color: #000; font-size: 14px; }
        .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0,0,0,.15); }
        @media print {
            .no-print { display: none !important; }
            .invoice-box { border: 0; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="text-end mb-3 no-print">
            <button onclick="window.print()" class="btn btn-danger fw-bold"><i class="bi bi-printer"></i> Faturayı Yazdır</button>
        </div>

        <div class="invoice-box">
            <div class="row align-items-center mb-4">
                <div class="col-6">
                    <img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 48px; object-fit: contain;" class="mb-2">
                    <p class="text-muted mb-0"><?= e(setting('site_address', 'İstasyon Mah. Hikmet Özer Cad. No:48/A Etimesgut / Ankara')) ?><br>Tel: <?= e(setting('site_phone', '0312 245 00 00')) ?></p>
                </div>
                <div class="col-6 text-end">
                    <h4 class="fw-bold">SİPARİŞ FATURASI</h4>
                    <div class="fw-mono">Fatura No: #<?= e($order['order_number']) ?></div>
                    <div class="text-muted">Tarih: <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></div>
                </div>
            </div>

            <hr>

            <div class="row mb-4">
                <div class="col-6">
                    <h6 class="fw-bold">Müşteri Bilgileri:</h6>
                    <div><strong><?= e($order['customer_name']) ?></strong></div>
                    <div><?= e($order['customer_email']) ?></div>
                    <div><?= e($order['customer_phone'] ?? '-') ?></div>
                </div>
                <div class="col-6 text-end">
                    <h6 class="fw-bold">Teslimat Adresi:</h6>
                    <div><?= e($order['shipping_address']) ?></div>
                    <div class="mt-2"><strong>Ödeme Tipi:</strong> <?= e($order['payment_method']) ?></div>
                </div>
            </div>

            <table class="table table-bordered mb-4">
                <thead class="table-light">
                    <tr>
                        <th>Ürün / Kitap Adı</th>
                        <th>ISBN</th>
                        <th class="text-center">Adet</th>
                        <th class="text-end">Birim Fiyat</th>
                        <th class="text-end">Toplam</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item['title']) ?></td>
                            <td><?= e($item['isbn'] ?? '-') ?></td>
                            <td class="text-center"><?= $item['quantity'] ?></td>
                            <td class="text-end"><?= format_price($item['price']) ?></td>
                            <td class="text-end"><?= format_price($item['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="row">
                <div class="col-6">
                    <p class="text-muted fs-7">Yeniçeri Kitabevi'ni tercih ettiğiniz için teşekkür ederiz.<br>Ankara / Etimesgut şubemizden sevgilerle.</p>
                </div>
                <div class="col-6 text-end">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td>Ara Toplam:</td>
                            <td class="fw-bold"><?= format_price($order['total_amount'] - $order['shipping_amount']) ?></td>
                        </tr>
                        <tr>
                            <td>Kargo Ücreti:</td>
                            <td class="fw-bold"><?= format_price($order['shipping_amount']) ?></td>
                        </tr>
                        <tr class="border-top">
                            <td class="fs-5 fw-bold">Genel Toplam:</td>
                            <td class="fs-5 fw-bold text-danger"><?= format_price($order['total_amount']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
