<?php
/**
 * STOREFRONT: Sipariş Takip (siparis-takip.php)
 * Müşteri sipariş numarası + sipariş e-postasıyla siparişin durumunu, ürünleri ve kargo takibini görür.
 */
require_once __DIR__ . '/config.php';

$no    = strtoupper(trim(sanitize($_REQUEST['no'] ?? '')));
$email = trim(sanitize($_POST['email'] ?? ''));
$order = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = $no !== '' ? db_fetch("SELECT o.id, u.email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.order_number = ?", [$no]) : null;
    if ($row && strtolower(trim((string)$row['email'])) === strtolower($email)) { // e-postada Türkçe küçük harf (I→ı) kullanılmaz
        $order = order_load($row['id']);
    } else {
        sleep(1); // tahmin denemelerini yavaşlat
        $error = 'Bu sipariş numarası ve e-posta adresiyle eşleşen bir sipariş bulunamadı. Bilgileri sipariş e-postanızdan kontrol edin.';
    }
}

$steps = ['Ödeme Bekliyor' => 'Ödeme bekleniyor', 'Hazırlanıyor' => 'Hazırlanıyor', 'Kargolandı' => 'Kargoya verildi', 'Tamamlandı' => 'Teslim edildi'];
$step_keys = array_keys($steps);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head(['title' => seo_title('Sipariş Takip'), 'description' => seo_description('Sipariş numaranız ve e-posta adresinizle Yeniçeri Kitabevi siparişinizin durumunu ve kargo takip bilgisini görüntüleyin.'), 'canonical' => 'siparis-takip.php', 'robots' => 'noindex,follow']) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body class="bg-light">
    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="/" class="navbar-brand"><img src="/assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" width="164" height="48" style="height: 48px; width: auto;"></a>
            <a href="/" class="btn btn-outline-dark rounded-pill px-3 fw-bold"><i class="bi bi-house-door me-1"></i> Ana Sayfa</a>
        </div>
    </header>

    <main class="container py-5" style="max-width: 760px;">
        <h1 class="h3 fw-extrabold text-dark mb-1"><i class="bi bi-truck text-danger me-2"></i>Sipariş Takip</h1>
        <p class="text-muted fs-7 mb-4">Sipariş numaranızı ve siparişte kullandığınız e-posta adresini girin.</p>

        <?php if (!$order): ?>
            <?php if ($error): ?><div class="alert alert-danger fs-7"><?= e($error) ?></div><?php endif; ?>
            <form method="POST" action="siparis-takip.php" class="card border-0 shadow-sm rounded-4 p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7" for="tNo">Sipariş Numarası</label>
                        <input type="text" name="no" id="tNo" class="form-control text-uppercase" placeholder="YNC-20261030-ABC123" value="<?= e($no) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7" for="tMail">E-posta Adresi</label>
                        <input type="email" name="email" id="tMail" class="form-control" placeholder="ornek@eposta.com" value="<?= e($email) ?>" required autocomplete="email">
                    </div>
                </div>
                <button class="btn btn-danger fw-bold mt-3"><i class="bi bi-search me-1"></i> Siparişimi Göster</button>
            </form>
        <?php else:
            $cancel = $order['status'] === 'İptal';
            $cur = array_search($order['status'], $step_keys, true);
            $link = order_tracking_link($order);
            ?>
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                    <div><div class="text-muted fs-8">Sipariş No</div><div class="fw-bold"><?= e($order['order_number']) ?></div></div>
                    <div><div class="text-muted fs-8">Tarih</div><div class="fw-semibold"><?= e(date('d.m.Y H:i', strtotime($order['created_at']))) ?></div></div>
                    <div><div class="text-muted fs-8">Ödeme</div><div class="fw-semibold"><?= e($order['payment_method']) ?></div></div>
                    <div><div class="text-muted fs-8">Tutar</div><div class="fw-bold text-danger"><?= format_price($order['total_amount']) ?></div></div>
                </div>

                <?php if ($cancel): ?>
                    <div class="alert alert-danger mb-0"><i class="bi bi-x-circle-fill me-1"></i> Bu sipariş iptal edildi. Sorunuz varsa bizi arayın: <?= e(setting('site_phone')) ?></div>
                <?php else: ?>
                    <div class="d-flex justify-content-between position-relative my-2" aria-label="Sipariş durumu">
                        <?php foreach ($step_keys as $i => $k):
                            if ($k === 'Ödeme Bekliyor' && $order['payment_method'] === 'Kredi Kartı' && $cur !== 0) continue;
                            $done = $cur !== false && $i <= $cur; ?>
                            <div class="text-center flex-fill">
                                <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center mb-1 <?= $done ? 'bg-danger text-white' : 'bg-white border text-muted' ?>" style="width: 38px; height: 38px;">
                                    <i class="bi <?= ['bi-hourglass-split', 'bi-box-seam', 'bi-truck', 'bi-house-check'][$i] ?>"></i>
                                </div>
                                <div class="fs-8 <?= $done ? 'fw-bold text-dark' : 'text-muted' ?>"><?= e($steps[$k]) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (trim((string)$order['tracking_number']) !== ''): ?>
                    <div class="bg-light border rounded-3 p-3 mt-3">
                        <div class="fs-7"><?= order_carrier_name($order) !== '' && order_carrier_name($order) !== 'Diğer' ? 'Kargo firması: <b>' . e(order_carrier_name($order)) . '</b> • ' : '' ?>Takip numarası: <b class="font-monospace"><?= e($order['tracking_number']) ?></b></div>
                        <?php if ($link): ?>
                            <a href="<?= e($link) ?>" target="_blank" rel="noopener nofollow" class="btn btn-danger btn-sm mt-2"><i class="bi bi-box-arrow-up-right me-1"></i> Kargo firmasında takip et</a>
                        <?php endif; ?>
                    </div>
                <?php elseif (!$cancel && $order['status'] === 'Ödeme Bekliyor' && $order['payment_method'] !== 'Kredi Kartı'): ?>
                    <div class="alert alert-warning fs-7 mt-3 mb-0">Havale / EFT ödemenizi bekliyoruz. Açıklamaya sipariş numaranızı yazmayı unutmayın.<?= setting('bank_iban') ? ' IBAN: <b class="font-monospace">' . e(setting('bank_iban')) . '</b>' : '' ?></div>
                <?php elseif (!$cancel && $order['status'] !== 'Tamamlandı'): ?>
                    <div class="text-muted fs-7 mt-3">Siparişiniz kargoya verildiğinde takip numarası burada görünecek ve size e-posta gönderilecek.</div>
                <?php endif; ?>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                <h2 class="h6 fw-bold mb-3">Ürünler</h2>
                <?php foreach ($order['items'] as $it): ?>
                    <div class="d-flex justify-content-between fs-7 py-1 border-bottom"><span><?= e($it['title'] ?: 'Kitap #' . $it['book_id']) ?> <span class="text-muted">× <?= (int)$it['quantity'] ?></span></span><span class="fw-semibold"><?= format_price($it['total']) ?></span></div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between fs-7 pt-2"><span>Kargo</span><span><?= (float)$order['shipping_amount'] > 0 ? format_price($order['shipping_amount']) : 'Ücretsiz' ?></span></div>
                <div class="d-flex justify-content-between fw-bold pt-1"><span>Toplam</span><span class="text-danger"><?= format_price($order['total_amount']) ?></span></div>
                <div class="fs-8 text-muted mt-3"><b>Teslimat adresi:</b> <?= e($order['shipping_address']) ?></div>
            </div>
            <a href="siparis-takip.php" class="fs-7 text-danger">Başka bir sipariş sorgula</a>
        <?php endif; ?>
    </main>
</body>
</html>
