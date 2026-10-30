<?php
/**
 * KAMPANYA MOTORU (sepet indirimleri, ücretsiz kargo, kupon, sahaf komisyonu)
 * Kampanyalar yönetim panelinden (p=Campaigns) yönetilir. Tutarlar HER ZAMAN sunucuda, veritabanındaki fiyatlarla hesaplanır.
 *
 * Türler:
 *  percent        Sepette % indirim (kapsamdaki kitaplara)
 *  amount         Sepette X TL indirim (kapsamdaki kitapların tutarı alt limiti geçerse)
 *  buy_x_pay_y    X Al Y Öde (ör. 2 al 1 öde, 3 al 2 öde) — en ucuz kitaplar bedava
 *  nth_percent    N. kitaba % indirim (ör. ikinci kitaba %50)
 *  free_shipping  Ücretsiz kargo (kapsamdaki kitaplardan alt tutar / adet şartıyla; ör. aynı sahaftan 3 kitap)
 *  commission     Sahaf komisyon indirimi (sepeti etkilemez; o tarihlerde satıcının komisyon oranını düşürür)
 */
if (!defined('SITE_URL')) exit;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, type TEXT NOT NULL, scope_type TEXT DEFAULT 'all', scope_ids TEXT, value REAL DEFAULT 0, buy_qty INTEGER DEFAULT 0, pay_qty INTEGER DEFAULT 0, min_amount REAL DEFAULT 0, min_qty INTEGER DEFAULT 0, coupon_code TEXT, max_uses INTEGER DEFAULT 0, used_count INTEGER DEFAULT 0, stackable INTEGER DEFAULT 0, priority INTEGER DEFAULT 0, show_badge INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1, starts_at TEXT, ends_at TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
    $add_cols = [
        'orders'      => ['discount_amount' => 'REAL DEFAULT 0', 'coupon_code' => 'TEXT', 'campaign_summary' => 'TEXT'],
        'order_items' => ['discount_amount' => 'REAL DEFAULT 0', 'commission_rate' => 'REAL', 'commission_amount' => 'REAL DEFAULT 0'],
        'stores'      => ['commission_rate' => 'REAL'],
    ];
    foreach ($add_cols as $tbl => $cols) {
        $have = array_column($pdo->query("PRAGMA table_info({$tbl})")->fetchAll(), 'name');
        foreach ($cols as $c => $t) {
            if (!in_array($c, $have, true)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN {$c} {$t};");
            }
        }
    }
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, type VARCHAR(30) NOT NULL, scope_type VARCHAR(20) DEFAULT 'all', scope_ids TEXT NULL, value DECIMAL(10,2) DEFAULT 0, buy_qty INT DEFAULT 0, pay_qty INT DEFAULT 0, min_amount DECIMAL(10,2) DEFAULT 0, min_qty INT DEFAULT 0, coupon_code VARCHAR(50) NULL, max_uses INT DEFAULT 0, used_count INT DEFAULT 0, stackable TINYINT DEFAULT 0, priority INT DEFAULT 0, show_badge TINYINT DEFAULT 1, is_active TINYINT DEFAULT 1, starts_at VARCHAR(20) NULL, ends_at VARCHAR(20) NULL, description TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $add_cols = [
        'orders'      => ['discount_amount' => 'DECIMAL(10,2) DEFAULT 0', 'coupon_code' => 'VARCHAR(50) NULL', 'campaign_summary' => 'TEXT NULL'],
        'order_items' => ['discount_amount' => 'DECIMAL(10,2) DEFAULT 0', 'commission_rate' => 'DECIMAL(5,2) NULL', 'commission_amount' => 'DECIMAL(10,2) DEFAULT 0'],
        'stores'      => ['commission_rate' => 'DECIMAL(5,2) NULL'],
    ];
    foreach ($add_cols as $tbl => $cols) {
        $have = array_column($pdo->query("SHOW COLUMNS FROM {$tbl}")->fetchAll(), 'Field');
        foreach ($cols as $c => $t) {
            if (!in_array($c, $have, true)) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN {$c} {$t};");
            }
        }
    }
}

function campaign_types() {
    return [
        'buy_x_pay_y'   => 'X Al Y Öde (ör. 2 al 1 öde)',
        'nth_percent'   => 'N. Kitaba % İndirim (ör. ikinciye %50)',
        'percent'       => 'Sepette % İndirim',
        'amount'        => 'Sepette TL İndirim',
        'free_shipping' => 'Ücretsiz Kargo',
        'commission'    => 'Sahaf Komisyon İndirimi',
    ];
}

function campaign_scope_types() {
    return ['all' => 'Tüm kitaplar', 'store' => 'Seçili alt dükkanlar (sahaflar)', 'main_store' => 'Yalnızca Yeniçeri (ana mağaza) kitapları', 'category' => 'Seçili kategoriler', 'publisher' => 'Seçili yayınevleri', 'author' => 'Seçili yazarlar', 'books' => 'Seçili kitaplar'];
}

/** Türkiye saatiyle şu an (kampanya başlangıç/bitiş karşılaştırması için) */
function campaign_now() {
    return (new DateTime('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d H:i');
}

/**
 * Şu an geçerli kampanyalar (aktif, tarih aralığında, kullanım limiti dolmamış). Kuponlu olanlar yalnızca kod eşleşirse döner.
 */
function campaigns_active($coupon = '') {
    static $all = null;
    if ($all === null) {
        $now = campaign_now();
        try {
            $all = db_fetch_all("SELECT * FROM campaigns WHERE is_active = 1
                AND (starts_at IS NULL OR starts_at = '' OR starts_at <= ?) AND (ends_at IS NULL OR ends_at = '' OR ends_at >= ?)
                AND (max_uses = 0 OR used_count < max_uses)
                ORDER BY priority DESC, id ASC", [$now, $now]);
        } catch (Throwable $e) {
            $all = [];
        }
    }
    $coupon = mb_strtoupper(trim((string)$coupon), 'UTF-8');
    return array_values(array_filter($all, function ($c) use ($coupon) {
        $code = mb_strtoupper(trim((string)$c['coupon_code']), 'UTF-8');
        return $code === '' || ($coupon !== '' && $code === $coupon);
    }));
}

/** Kitap kampanyanın kapsamında mı? $book: store_id, category_id, author_id, publisher_id, id */
function campaign_matches($c, $book) {
    $ids = array_filter(array_map('intval', explode(',', (string)$c['scope_ids'])));
    switch ($c['scope_type']) {
        case 'store':      return in_array((int)$book['store_id'], $ids, true);
        case 'main_store': return empty($book['store_id']);
        case 'category':   return in_array((int)$book['category_id'], $ids, true);
        case 'publisher':  return in_array((int)$book['publisher_id'], $ids, true);
        case 'author':     return in_array((int)$book['author_id'], $ids, true);
        case 'books':      return in_array((int)$book['id'], $ids, true);
        default:           return true;
    }
}

/**
 * Sepeti veritabanından kurar: [kitap_id => adet] → satırlar + hata mesajları (satışta olmayan / stok yetersiz)
 */
function cart_build_lines(array $requested) {
    $lines = [];
    $errors = [];
    foreach ($requested as $b_id => $qty) {
        $book = db_fetch("SELECT id, title, price, stock, store_id, category_id, author_id, publisher_id FROM books WHERE id = ? AND is_active = 1", [(int)$b_id]);
        if (!$book) {
            $errors[] = 'Sepetinizdeki bir kitap artık satışta değil. Lütfen sepetinizi güncelleyin.';
            continue;
        }
        if ($qty > (int)$book['stock']) {
            $errors[] = '"' . $book['title'] . '" için stokta yalnızca ' . max(0, (int)$book['stock']) . ' adet var.';
            continue;
        }
        $price = (float)$book['price'];
        $lines[] = $book + ['qty' => (int)$qty, 'price' => $price, 'total' => round($price * $qty, 2), 'discount' => 0.0];
    }
    return [$lines, $errors];
}

/**
 * Kampanyaları uygular. $lines: cart_build_lines çıktısı (discount alanı doldurulur).
 * Dönüş: subtotal, discount, net, shipping, total, applied [[id, name, amount]], free_shipping_by, coupon_status
 */
function campaign_apply(array &$lines, $coupon = '') {
    $coupon = trim((string)$coupon);
    $campaigns = campaigns_active($coupon);
    $applied = [];
    $touched = []; // indirim almış satırlar (birleştirilemez kampanyalar bunları atlar)
    $coupon_used = false;

    foreach ($campaigns as $c) {
        if (!in_array($c['type'], ['percent', 'amount', 'buy_x_pay_y', 'nth_percent'], true)) {
            continue;
        }
        $elig = [];
        foreach ($lines as $i => $l) {
            if (campaign_matches($c, $l) && ($c['stackable'] || !isset($touched[$i]))) {
                $elig[] = $i;
            }
        }
        if (!$elig) {
            continue;
        }
        $elig_total = 0;
        $elig_qty = 0;
        foreach ($elig as $i) {
            $elig_total += $lines[$i]['total'] - $lines[$i]['discount'];
            $elig_qty += $lines[$i]['qty'];
        }
        if ($elig_total < (float)$c['min_amount'] || $elig_qty < (int)$c['min_qty']) {
            continue;
        }

        $disc = array_fill_keys($elig, 0.0);
        $grouped = [];
        $value = (float)$c['value'];
        if ($c['type'] === 'percent') {
            foreach ($elig as $i) {
                $disc[$i] = ($lines[$i]['total'] - $lines[$i]['discount']) * min(100, $value) / 100;
            }
        } elseif ($c['type'] === 'amount') {
            $amt = min($value, $elig_total);
            foreach ($elig as $i) {
                $disc[$i] = $elig_total > 0 ? $amt * ($lines[$i]['total'] - $lines[$i]['discount']) / $elig_total : 0;
            }
        } else {
            // Adetleri tek tek aç, pahalıdan ucuza sırala; her grupta en ucuz(lar) indirim alır
            $units = [];
            foreach ($elig as $i) {
                $unit_net = ($lines[$i]['total'] - $lines[$i]['discount']) / $lines[$i]['qty'];
                for ($k = 0; $k < $lines[$i]['qty']; $k++) {
                    $units[] = [$i, $unit_net];
                }
            }
            usort($units, function ($a, $b) { return $b[1] <=> $a[1]; });
            $n = max(2, (int)$c['buy_qty']);
            foreach (array_chunk($units, $n) as $group) {
                if (count($group) < $n) {
                    break;
                }
                // Gruba giren (parası ödenen) kitaplar da bu kampanyayı kullanmış sayılır
                foreach ($group as $u) {
                    $grouped[$u[0]] = true;
                }
                if ($c['type'] === 'buy_x_pay_y') {
                    $free = max(0, $n - max(1, (int)$c['pay_qty']));
                    foreach (array_slice($group, $n - $free) as $u) {
                        $disc[$u[0]] += $u[1];
                    }
                } else {
                    $u = $group[$n - 1];
                    $disc[$u[0]] += $u[1] * min(100, $value) / 100;
                }
            }
        }

        $sum = 0;
        foreach ($disc as $i => $d) {
            $d = round(min($d, $lines[$i]['total'] - $lines[$i]['discount']), 2);
            if ($d > 0) {
                $lines[$i]['discount'] += $d;
                $touched[$i] = true;
                $sum += $d;
            }
        }
        if ($sum > 0) {
            $touched += $grouped;
            $applied[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'amount' => round($sum, 2)];
            if (trim((string)$c['coupon_code']) !== '') {
                $coupon_used = true;
            }
        }
    }

    $subtotal = 0;
    $discount = 0;
    foreach ($lines as &$l) {
        $l['discount'] = round($l['discount'], 2);
        $l['net'] = round($l['total'] - $l['discount'], 2);
        $subtotal += $l['total'];
        $discount += $l['discount'];
    }
    unset($l);
    $net = round($subtotal - $discount, 2);

    // Kargo: önce normal limit (indirimli tutara göre), sonra ücretsiz kargo kampanyaları
    $shipping = $lines ? shipping_cost($net) : 0.0;
    $free_by = null;
    if ($shipping > 0) {
        foreach ($campaigns as $c) {
            if ($c['type'] !== 'free_shipping') {
                continue;
            }
            // Sahaf kapsamlı kampanyada şart her sahaf için ayrı aranır ("aynı sahaftan 3 kitap")
            $groups = [];
            foreach ($lines as $l) {
                if (campaign_matches($c, $l)) {
                    $g = $c['scope_type'] === 'store' ? (int)$l['store_id'] : 0;
                    $groups[$g]['total'] = ($groups[$g]['total'] ?? 0) + $l['net'];
                    $groups[$g]['qty'] = ($groups[$g]['qty'] ?? 0) + $l['qty'];
                }
            }
            $ok = false;
            foreach ($groups as $g) {
                if ($g['total'] >= (float)$c['min_amount'] && $g['qty'] >= max(1, (int)$c['min_qty'])) {
                    $ok = true;
                }
            }
            if ($ok) {
                $free_by = ['id' => (int)$c['id'], 'name' => $c['name'], 'amount' => $shipping, 'shipping' => true];
                $shipping = 0.0;
                if (trim((string)$c['coupon_code']) !== '') {
                    $coupon_used = true;
                }
                break;
            }
        }
    }

    $coupon_status = '';
    if ($coupon !== '') {
        $coupon_status = $coupon_used ? 'ok' : (campaign_coupon_exists($coupon) ? 'not_applicable' : 'invalid');
    }

    return [
        'subtotal'      => round($subtotal, 2),
        'discount'      => round($discount, 2),
        'net'           => $net,
        'shipping'      => round($shipping, 2),
        'total'         => round($net + $shipping, 2),
        'applied'       => $applied,
        'free_shipping' => $free_by,
        'coupon_status' => $coupon_status,
    ];
}

function campaign_coupon_exists($coupon) {
    foreach (campaigns_active($coupon) as $c) {
        if (mb_strtoupper(trim((string)$c['coupon_code']), 'UTF-8') === mb_strtoupper(trim($coupon), 'UTF-8')) {
            return true;
        }
    }
    return false;
}

/**
 * Sahafın geçerli komisyon oranı (%): aktif komisyon kampanyalarının en düşüğü, yoksa dükkânın oranı, yoksa varsayılan
 */
function store_commission_rate($store_id) {
    static $cache = [];
    $store_id = (int)$store_id;
    if ($store_id <= 0) {
        return 0.0;
    }
    if (!isset($cache[$store_id])) {
        $store = db_fetch("SELECT id, commission_rate FROM stores WHERE id = ?", [$store_id]);
        $rate = ($store && $store['commission_rate'] !== null && $store['commission_rate'] !== '') ? (float)$store['commission_rate'] : (float)setting('default_commission_rate', '0');
        foreach (campaigns_active() as $c) {
            if ($c['type'] === 'commission' && campaign_matches($c, ['id' => 0, 'store_id' => $store_id, 'category_id' => 0, 'author_id' => 0, 'publisher_id' => 0])) {
                $rate = min($rate, max(0, (float)$c['value']));
            }
        }
        $cache[$store_id] = $rate;
    }
    return $cache[$store_id];
}

/**
 * Kitap kartı / detay sayfasında gösterilecek kampanya etiketleri (kuponsuz, etiket gösterimi açık olanlar)
 */
function campaign_badges($book) {
    $out = [];
    foreach (campaigns_active() as $c) {
        if (!$c['show_badge'] || $c['type'] === 'commission' || !campaign_matches($c, $book)) {
            continue;
        }
        $v = rtrim(rtrim(number_format((float)$c['value'], 2, ',', ''), '0'), ',');
        switch ($c['type']) {
            case 'buy_x_pay_y':   $label = (int)$c['buy_qty'] . ' Al ' . (int)$c['pay_qty'] . ' Öde'; break;
            case 'nth_percent':   $label = ((int)$c['buy_qty'] === 2 ? 'İkinciye' : (int)$c['buy_qty'] . '. kitaba') . ' %' . $v; break;
            case 'percent':       $label = 'Sepette %' . $v; break;
            case 'amount':        $label = 'Sepette ' . $v . ' TL İndirim'; break;
            case 'free_shipping': $label = 'Kargo Bedava'; break;
            default:              $label = $c['name'];
        }
        $out[] = ['label' => $label, 'title' => $c['name'] . ($c['description'] ? ' — ' . $c['description'] : '')];
    }
    return $out;
}

function campaign_badges_html($book, $class = '') {
    $html = '';
    foreach (campaign_badges($book) as $b) {
        $html .= '<span class="badge bg-warning text-dark me-1 mb-1 ' . e($class) . '" title="' . e($b['title']) . '"><i class="bi bi-gift-fill me-1"></i>' . e($b['label']) . '</span>';
    }
    return $html;
}
