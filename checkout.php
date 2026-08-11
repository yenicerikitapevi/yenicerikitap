<?php
/**
 * STOREFRONT: Shopping Cart & Checkout Page (checkout.php)
 */
require_once __DIR__ . '/config.php';

$order_created = false;
$new_order_number = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_checkout'])) {
    $name    = sanitize($_POST['name']);
    $email   = sanitize($_POST['email']);
    $phone   = sanitize($_POST['phone']);
    $address = sanitize($_POST['address']);
    $payment_method = sanitize($_POST['payment_method']);
    
    $cart_json = $_POST['cart_data'] ?? '[]';
    $cart_items = json_decode($cart_json, true) ?? [];

    if (!empty($cart_items)) {
        $user = db_fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if (!$user) {
            $pass_hash = password_hash('123456', PASSWORD_DEFAULT);
            db_query("INSERT INTO users (name, email, password_hash, role, status, phone, address) VALUES (?, ?, ?, 'customer', 'active', ?, ?)", [$name, $email, $pass_hash, $phone, $address]);
            $user_id = db_last_id();
        } else {
            $user_id = $user['id'];
        }

        $subtotal = 0;
        foreach ($cart_items as $item) {
            $subtotal += ($item['price'] * $item['qty']);
        }
        $shipping = ($subtotal >= 350) ? 0 : 29.90;
        $total_amount = $subtotal + $shipping;

        $new_order_number = 'YNC-' . date('Ymd') . '-' . rand(100, 999);

        db_query("INSERT INTO orders (order_number, user_id, total_amount, shipping_amount, status, payment_method, shipping_address) VALUES (?, ?, ?, ?, 'Hazırlanıyor', ?, ?)", 
            [$new_order_number, $user_id, $total_amount, $shipping, $payment_method, $address]);
        $order_id = db_last_id();

        foreach ($cart_items as $item) {
            $b_id = (int)$item['id'];
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            $item_total = $price * $qty;

            db_query("INSERT INTO order_items (order_id, book_id, quantity, price, total) VALUES (?, ?, ?, ?, ?)",
                [$order_id, $b_id, $qty, $price, $item_total]);

            db_query("UPDATE books SET stock = stock - ? WHERE id = ?", [$qty, $b_id]);
        }

        $tx_status = ($payment_method === 'Kredi Kartı') ? 'Onaylandı' : 'Bekliyor';
        $gateway_msg = ($payment_method === 'Kredi Kartı') ? 'POS Onayı Alındı - REF: ' . rand(100000, 999999) : 'Ziraat Bankası Havale Bekleniyor';
        db_query("INSERT INTO transactions (order_id, transaction_type, amount, status, gateway_response) VALUES (?, ?, ?, ?, ?)",
            [$order_id, $payment_method, $total_amount, $tx_status, $gateway_msg]);

        $order_created = true;
    }
}
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
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center gap-2">
                <i class="bi bi-book-half fs-2 text-danger"></i>
                <span class="fw-extrabold fs-4">YENİÇERİ KİTABEVİ</span>
            </a>
            <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill font-bold"><i class="bi bi-shield-lock-fill me-1"></i> 256-Bit SSL Güvenli Ödeme</span>
        </div>
    </header>

    <div class="container py-5">
        <?php if ($order_created): ?>
            <div class="max-w-xl mx-auto bg-white p-5 rounded-4 shadow text-center border">
                <div class="d-inline-flex align-items-center justify-content-center bg-danger text-white rounded-circle mb-3" style="width: 70px; height: 70px;">
                    <i class="bi bi-check-lg display-4"></i>
                </div>
                <h2 class="fw-extrabold text-dark mb-2">Siparişiniz Başarıyla Alındı!</h2>
                <p class="text-muted">Sipariş Numaranız: <strong class="text-danger"><?= e($new_order_number) ?></strong></p>
                <p class="fs-7 text-secondary mb-4">Sipariş detaylarınız kayıtlı e-posta adresinize gönderilmiştir. Ankara / Etimesgut depomuzdan kargonuz hazırlanmaktadır.</p>
                
                <a href="index.php" class="btn btn-danger font-bold px-4 py-2 rounded-pill">Alışverişe Devam Et</a>
                <script>localStorage.removeItem('yeniceri_cart');</script>
            </div>
        <?php else: ?>
            <h3 class="fw-extrabold text-dark mb-4"><i class="bi bi-bag-check-fill text-danger me-2"></i> Siparişi Tamamla</h3>

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
                                    <input type="text" name="name" class="form-control" placeholder="Ahmet Yılmaz" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold fs-7">E-Posta Adresi *</label>
                                    <input type="email" name="email" class="form-control" placeholder="ahmet@example.com" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold fs-7">Telefon Numarası *</label>
                                    <input type="text" name="phone" class="form-control" placeholder="0555 111 22 33" required>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label fw-semibold fs-7">Teslimat Adresi *</label>
                                    <textarea name="address" class="form-control" rows="3" required>İstasyon Mah. Akasya Cad. No:12 Etimesgut / Ankara</textarea>
                                </div>
                            </div>

                            <h5 class="fw-bold text-dark mt-4 mb-3"><i class="bi bi-credit-card-2-front text-danger me-2"></i> Ödeme Yöntemi</h5>
                            
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="payment_method" id="pm_cc" value="Kredi Kartı" checked>
                                <label class="form-check-label fw-semibold" for="pm_cc">Kredi / Banka Kartı ile Ödeme (Anında Onay)</label>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="radio" name="payment_method" id="pm_eft" value="EFT / Havale">
                                <label class="form-check-label fw-semibold" for="pm_eft">Banka Havalesi / EFT (Ziraat Bankası)</label>
                            </div>

                            <button type="submit" class="btn btn-danger btn-lg w-100 fw-bold py-3 rounded-3 shadow" id="btnSubmitOrder">
                                <i class="bi bi-lock-fill me-1"></i> Siparişi Onayla ve Öde
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
        document.addEventListener('DOMContentLoaded', () => {
            const cart = getCart();
            document.getElementById('cartDataInput').value = JSON.stringify(cart);
            
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
                                <span>${item.title} (x${item.qty})</span>
                                <span class="fw-bold">₺${(item.price * item.qty).toFixed(2)}</span>
                            </div>`;
                });
                const shipping = total >= 350 ? 0 : 29.90;
                html += `<hr><div class="d-flex justify-content-between fs-7 mb-1"><span>Kargo Ücreti:</span><span>₺${shipping.toFixed(2)}</span></div>`;
                html += `<div class="d-flex justify-content-between fs-5 fw-bold text-danger mt-2"><span>Toplam:</span><span>₺${(total + shipping).toFixed(2)}</span></div>`;
                container.innerHTML = html;
            }
        });
    </script>
</body>
</html>
