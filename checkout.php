<?php
/**
 * STOREFRONT: Shopping Cart & Checkout Page (checkout.php)
 */
require_once __DIR__ . '/config.php';

$order_created = false;
$new_order_number = '';
$order_total = 0;
$errors = [];
$form = ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];
$paytr_token = '';
$payment_result = $_GET['odeme'] ?? '';

// Kartla ödeme yalnızca PayTR mağaza bilgileri girildiyse sunulur
$card_enabled = paytr_enabled();
$payment_method = $card_enabled ? 'Kredi Kartı' : 'EFT / Havale';

paytr_expire_pending_orders();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_checkout'])) {
    foreach ($form as $key => $_) {
        $form[$key] = sanitize($_POST[$key] ?? '');
    }
    $payment_method = ($card_enabled && ($_POST['payment_method'] ?? '') === 'Kredi Kartı') ? 'Kredi Kartı' : 'EFT / Havale';

    if ($form['name'] === '' || $form['phone'] === '' || $form['address'] === '') {
        $errors[] = 'Lütfen ad soyad, telefon ve teslimat adresi alanlarını doldurun.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Lütfen geçerli bir e-posta adresi girin.';
    }
    if (empty($_POST['accept_terms'])) {
        $errors[] = 'Siparişi tamamlamak için Ön Bilgilendirme Formu ve Mesafeli Satış Sözleşmesi\'ni onaylamanız gerekir.';
    }

    // Sepetteki fiyatlara güvenilmez: yalnızca kitap ID ve adet alınır, fiyat ve stok veritabanından okunur
    $requested = [];
    $cart_items = json_decode($_POST['cart_data'] ?? '[]', true);
    if (is_array($cart_items)) {
        foreach ($cart_items as $item) {
            $b_id = (int)($item['id'] ?? 0);
            $qty  = (int)($item['qty'] ?? 0);
            if ($b_id > 0 && $qty > 0) {
                $requested[$b_id] = ($requested[$b_id] ?? 0) + $qty;
            }
        }
    }
    if (empty($requested)) {
        $errors[] = 'Sepetiniz boş.';
    }

    if (empty($errors)) {
        global $pdo;
        try {
            $pdo->beginTransaction();

            $lines = [];
            $subtotal = 0;
            foreach ($requested as $b_id => $qty) {
                $book = db_fetch("SELECT id, title, price, stock FROM books WHERE id = ? AND is_active = 1", [$b_id]);
                if (!$book) {
                    $errors[] = 'Sepetinizdeki bir kitap artık satışta değil. Lütfen sepetinizi güncelleyin.';
                    continue;
                }
                if ($qty > (int)$book['stock']) {
                    $errors[] = '"' . $book['title'] . '" için stokta yalnızca ' . max(0, (int)$book['stock']) . ' adet var.';
                    continue;
                }
                $price = (float)$book['price'];
                $lines[] = ['id' => $b_id, 'title' => $book['title'], 'qty' => $qty, 'price' => $price, 'total' => $price * $qty];
                $subtotal += $price * $qty;
            }

            $user = db_fetch("SELECT id, status FROM users WHERE email = ?", [$form['email']]);
            if ($user && $user['status'] !== 'active') {
                $errors[] = 'Bu e-posta adresiyle şu an sipariş verilemiyor. Lütfen bizimle iletişime geçin.';
            }

            if (!empty($errors)) {
                $pdo->rollBack();
            } else {
                if (!$user) {
                    // Müşteri hesabıyla giriş özelliği yok; tahmin edilemeyen rastgele bir parola atanır
                    $pass_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    db_query("INSERT INTO users (name, email, password_hash, role, status, phone, address) VALUES (?, ?, ?, 'customer', 'active', ?, ?)",
                        [$form['name'], $form['email'], $pass_hash, $form['phone'], $form['address']]);
                    $user_id = db_last_id();
                } else {
                    $user_id = $user['id'];
                }

                $shipping = shipping_cost($subtotal);
                $order_total = $subtotal + $shipping;

                // Benzersiz sipariş numarası (çakışma ihtimaline karşı kontrol edilir)
                do {
                    $new_order_number = 'YNC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                } while (db_fetch("SELECT id FROM orders WHERE order_number = ?", [$new_order_number]));

                db_query("INSERT INTO orders (order_number, user_id, total_amount, shipping_amount, status, payment_method, shipping_address) VALUES (?, ?, ?, ?, 'Ödeme Bekliyor', ?, ?)",
                    [$new_order_number, $user_id, $order_total, $shipping, $payment_method, $form['address']]);
                $order_id = db_last_id();

                foreach ($lines as $line) {
                    db_query("INSERT INTO order_items (order_id, book_id, quantity, price, total) VALUES (?, ?, ?, ?, ?)",
                        [$order_id, $line['id'], $line['qty'], $line['price'], $line['total']]);
                    // Aynı anda gelen siparişlerde stok eksiye düşmesin
                    $updated = db_query("UPDATE books SET stock = stock - ? WHERE id = ? AND stock >= ?", [$line['qty'], $line['id'], $line['qty']]);
                    if ($updated->rowCount() !== 1) {
                        throw new RuntimeException('Stok yetersiz: kitap #' . $line['id']);
                    }
                }

                $gateway_msg = ($payment_method === 'Kredi Kartı') ? 'PayTR ödemesi bekleniyor (merchant_oid: ' . paytr_merchant_oid($new_order_number) . ')' : 'Havale/EFT bekleniyor';
                db_query("INSERT INTO transactions (order_id, transaction_type, amount, status, gateway_response) VALUES (?, ?, ?, 'Bekliyor', ?)",
                    [$order_id, $payment_method, $order_total, $gateway_msg]);

                $pdo->commit();

                if ($payment_method === 'Kredi Kartı') {
                    // Stok sipariş için ayrıldı; ödeme sonucu PayTR bildirimiyle (paytr_callback.php) kesinleşir
                    $basket = [];
                    foreach ($lines as $line) {
                        $basket[] = [$line['title'], $line['price'], $line['qty']];
                    }
                    if ($shipping > 0) {
                        $basket[] = ['Kargo Ücreti', $shipping, 1];
                    }

                    $res = paytr_get_token([
                        'merchant_oid' => paytr_merchant_oid($new_order_number),
                        'email'        => $form['email'],
                        'total'        => $order_total,
                        'user_name'    => $form['name'],
                        'user_address' => $form['address'],
                        'user_phone'   => $form['phone'],
                        'basket'       => $basket,
                    ]);

                    if ($res['ok']) {
                        $paytr_token = $res['token'];
                    } else {
                        error_log('[PayTR] ' . $res['error']);
                        $pdo->beginTransaction();
                        paytr_cancel_order($order_id, $res['error']);
                        $pdo->commit();
                        $errors[] = 'Kartla ödeme sayfası şu an açılamadı. Lütfen birkaç dakika sonra tekrar deneyin veya Havale / EFT ile ödeyin.';
                    }
                } else {
                    $order_created = true;
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Yeniceri] Siparis olusturulamadi: ' . $ex->getMessage());
            $errors[] = 'Siparişiniz şu an oluşturulamadı. Lütfen birkaç dakika sonra tekrar deneyin.';
        }
    }
}

$bank_name    = setting('bank_name');
$bank_holder  = setting('bank_account_name');
$bank_iban    = setting('bank_iban');
track_view('checkout');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sipariş ve Ödeme | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center">
                <img src="assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 48px; max-width: 220px; object-fit: contain;">
            </a>
            <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill font-bold"><i class="bi bi-shield-lock-fill me-1"></i> 256-Bit SSL Güvenli Ödeme</span>
        </div>
    </header>

    <div class="container py-5">
        <?php if ($payment_result === 'basarili'): ?>
            <div class="max-w-xl mx-auto bg-white p-5 rounded-4 shadow text-center border">
                <div class="d-inline-flex align-items-center justify-content-center bg-danger text-white rounded-circle mb-3" style="width: 70px; height: 70px;">
                    <i class="bi bi-check-lg display-4"></i>
                </div>
                <h2 class="fw-extrabold text-dark mb-2">Ödemeniz Alındı!</h2>
                <p class="fs-7 text-secondary mb-4">Siparişiniz kargoya hazırlanacaktır. Sorularınız için bize <strong><?= e(setting('site_phone', '0312 245 00 00')) ?></strong> numarasından ulaşabilirsiniz.</p>

                <a href="index.php" class="btn btn-danger font-bold px-4 py-2 rounded-pill">Alışverişe Devam Et</a>
                <script>localStorage.removeItem('yeniceri_cart');</script>
            </div>
        <?php elseif ($paytr_token !== ''): ?>
            <h3 class="fw-extrabold text-dark mb-2"><i class="bi bi-credit-card-2-front text-danger me-2"></i> Kart ile Güvenli Ödeme</h3>
            <p class="text-muted mb-4">Sipariş Numaranız: <strong class="text-danger"><?= e($new_order_number) ?></strong> &middot; Ödenecek Tutar: <strong class="text-danger"><?= format_price($order_total) ?></strong></p>
            <div class="card border-0 shadow-sm rounded-4 p-2 p-md-4">
                <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
                <iframe src="<?= e(PAYTR_IFRAME_URL . $paytr_token) ?>" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
                <script>iFrameResize({}, '#paytriframe');</script>
            </div>
        <?php elseif ($order_created): ?>
            <div class="max-w-xl mx-auto bg-white p-5 rounded-4 shadow text-center border">
                <div class="d-inline-flex align-items-center justify-content-center bg-danger text-white rounded-circle mb-3" style="width: 70px; height: 70px;">
                    <i class="bi bi-check-lg display-4"></i>
                </div>
                <h2 class="fw-extrabold text-dark mb-2">Siparişiniz Alındı!</h2>
                <p class="text-muted">Sipariş Numaranız: <strong class="text-danger"><?= e($new_order_number) ?></strong></p>

                <div class="text-start bg-light border rounded-3 p-4 my-4">
                    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-bank me-1 text-danger"></i> Havale / EFT Bilgileri</h6>
                    <p class="fs-7 mb-2">Ödenecek Tutar: <strong class="text-danger"><?= format_price($order_total) ?></strong></p>
                    <?php if ($bank_iban !== ''): ?>
                        <?php if ($bank_name !== ''): ?><p class="fs-7 mb-1">Banka: <strong><?= e($bank_name) ?></strong></p><?php endif; ?>
                        <?php if ($bank_holder !== ''): ?><p class="fs-7 mb-1">Alıcı: <strong><?= e($bank_holder) ?></strong></p><?php endif; ?>
                        <p class="fs-7 mb-3">IBAN: <strong class="font-monospace"><?= e($bank_iban) ?></strong></p>
                    <?php else: ?>
                        <p class="fs-7 mb-3">IBAN bilgisi için lütfen bizimle iletişime geçin: <strong><?= e(setting('site_phone', '0312 245 00 00')) ?></strong></p>
                    <?php endif; ?>
                    <p class="fs-8 text-secondary mb-0">Lütfen açıklama kısmına sipariş numaranızı (<strong><?= e($new_order_number) ?></strong>) yazın. Ödemeniz onaylandıktan sonra siparişiniz kargoya hazırlanacaktır.</p>
                </div>

                <a href="index.php" class="btn btn-danger font-bold px-4 py-2 rounded-pill">Alışverişe Devam Et</a>
                <script>localStorage.removeItem('yeniceri_cart');</script>
            </div>
        <?php else: ?>
            <h3 class="fw-extrabold text-dark mb-4"><i class="bi bi-bag-check-fill text-danger me-2"></i> Siparişi Tamamla</h3>

            <?php if ($payment_result === 'basarisiz'): ?>
                <div class="alert alert-danger">Kartla ödemeniz tamamlanamadı ve kartınızdan ücret çekilmedi. Bilgilerinizi kontrol edip tekrar deneyebilir veya Havale / EFT ile ödeyebilirsiniz.</div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                        <h5 class="fw-bold text-dark mb-3"><i class="bi bi-person-lines-fill text-danger me-2"></i> Teslimat Bilgileri</h5>
                        
                        <form method="POST" id="checkoutForm">
                            <input type="hidden" name="complete_checkout" value="1">
                            <input type="hidden" name="cart_data" id="cartDataInput">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold fs-7">Adınız Soyadınız *</label>
                                    <input type="text" name="name" class="form-control" placeholder="Ad Soyad" value="<?= e($form['name']) ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold fs-7">E-Posta Adresi *</label>
                                    <input type="email" name="email" class="form-control" placeholder="ornek@eposta.com" value="<?= e($form['email']) ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold fs-7">Telefon Numarası *</label>
                                    <input type="tel" name="phone" class="form-control" placeholder="05xx xxx xx xx" value="<?= e($form['phone']) ?>" required>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label fw-semibold fs-7">Teslimat Adresi *</label>
                                    <textarea name="address" class="form-control" rows="3" placeholder="Mahalle, cadde/sokak, bina no, daire, ilçe / il" required><?= e($form['address']) ?></textarea>
                                </div>
                            </div>

                            <h5 class="fw-bold text-dark mt-4 mb-3"><i class="bi bi-wallet2 text-danger me-2"></i> Ödeme Yöntemi</h5>

                            <?php if ($card_enabled): ?>
                                <label class="d-block border rounded-3 p-3 mb-2 bg-light" for="pmCard">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="radio" name="payment_method" id="pmCard" value="Kredi Kartı" <?= $payment_method === 'Kredi Kartı' ? 'checked' : '' ?>>
                                        <span class="fw-semibold"><i class="bi bi-credit-card me-1"></i> Kredi / Banka Kartı</span>
                                        <div class="fs-7 text-secondary mt-1">PayTR güvenli ödeme altyapısıyla ödenir; kart bilgileriniz sitemizde saklanmaz.</div>
                                    </div>
                                </label>
                                <label class="d-block border rounded-3 p-3 mb-4 bg-light" for="pmEft">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="radio" name="payment_method" id="pmEft" value="EFT / Havale" <?= $payment_method === 'EFT / Havale' ? 'checked' : '' ?>>
                                        <span class="fw-semibold"><i class="bi bi-bank me-1"></i> Banka Havalesi / EFT</span>
                                        <div class="fs-7 text-secondary mt-1">Siparişi onayladıktan sonra IBAN bilgilerimiz gösterilir. Ödemeniz ulaştığında siparişiniz kargoya hazırlanır.</div>
                                    </div>
                                </label>
                            <?php else: ?>
                                <div class="border rounded-3 p-3 mb-4 bg-light">
                                    <div class="fw-semibold"><i class="bi bi-check-circle-fill text-success me-1"></i> Banka Havalesi / EFT</div>
                                    <div class="fs-7 text-secondary mt-1">Siparişi onayladıktan sonra IBAN bilgilerimiz gösterilir. Ödemeniz ulaştığında siparişiniz kargoya hazırlanır.</div>
                                </div>
                            <?php endif; ?>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="acceptTerms" required>
                                <label class="form-check-label fs-7" for="acceptTerms">
                                    <a href="legal.php?p=on-bilgilendirme" target="_blank">Ön Bilgilendirme Formu</a>'nu ve
                                    <a href="legal.php?p=mesafeli-satis" target="_blank">Mesafeli Satış Sözleşmesi</a>'ni okudum, kabul ediyorum.
                                </label>
                            </div>
                            <p class="fs-8 text-muted mb-3">Kişisel verileriniz <a href="legal.php?p=kvkk" target="_blank">KVKK Aydınlatma Metni</a> kapsamında işlenir.</p>

                            <button type="submit" class="btn btn-danger btn-lg w-100 fw-bold py-3 rounded-3 shadow" id="btnSubmitOrder">
                                <i class="bi bi-lock-fill me-1"></i> Siparişi Onayla
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4 sticky-top" style="top: 100px;">
                        <h5 class="fw-bold text-dark mb-3">Sipariş Özeti</h5>
                        <div id="checkoutSummaryList" class="mb-3"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
        const SHIPPING_FREE_LIMIT = <?= json_encode((float)setting('shipping_free_limit', '350')) ?>;
        const FLAT_SHIPPING_COST = <?= json_encode((float)setting('flat_shipping_cost', '29.90')) ?>;

        // Güncel fiyat ve stoklar: sepette eski fiyat kalmışsa düzeltilir, satıştan kalkan kitaplar çıkarılır
        const CATALOG = <?= json_encode(array_column(db_fetch_all("SELECT id, title, price, stock FROM books WHERE is_active = 1"), null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        document.addEventListener('DOMContentLoaded', () => {
            const cart = syncCartWithCatalog(CATALOG);
            const cartInput = document.getElementById('cartDataInput');
            if (cartInput) cartInput.value = JSON.stringify(cart);

            const form = document.getElementById('checkoutForm');
            if (form) {
                // Çift tıklamada iki sipariş oluşmasın
                form.addEventListener('submit', () => {
                    document.getElementById('btnSubmitOrder').disabled = true;
                });
            }

            const container = document.getElementById('checkoutSummaryList');
            if (container) {
                if (cart.length === 0) {
                    container.innerHTML = '<div class="alert alert-warning">Sepetiniz boş!</div>';
                    document.getElementById('btnSubmitOrder').disabled = true;
                    return;
                }
                let html = '';
                let total = 0;
                cart.forEach(item => {
                    total += (item.price * item.qty);
                    html += `<div class="d-flex justify-content-between fs-7 mb-2">
                                <span>${escapeHtml(item.title)} (x${item.qty})</span>
                                <span class="fw-bold">₺${(item.price * item.qty).toFixed(2)}</span>
                            </div>`;
                });
                const shipping = total >= SHIPPING_FREE_LIMIT ? 0 : FLAT_SHIPPING_COST;
                html += `<hr><div class="d-flex justify-content-between fs-7 mb-1"><span>Kargo Ücreti:</span><span>₺${shipping.toFixed(2)}</span></div>`;
                html += `<div class="d-flex justify-content-between fs-5 fw-bold text-danger mt-2"><span>Toplam:</span><span>₺${(total + shipping).toFixed(2)}</span></div>`;
                container.innerHTML = html;
            }
        });
    </script>
    <?= track_script() ?>
</body>
</html>
