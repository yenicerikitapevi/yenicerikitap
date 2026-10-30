<?php
/**
 * RAPORLAR: trafik (görüntülenme, ziyaretçi, giriş/çıkış, kalma süresi), sepete ekleme ve satışlar.
 * Yönetim panelinde (p=Reports) tüm site için; Satıcı Panelinde (satici/index.php) $report_store_id ile yalnızca o dükkân için açılır.
 */
if (!defined('SITE_URL')) exit;

$is_seller   = isset($report_store_id);
$report_base = $report_base ?? 'index.php?p=Reports&';
$scope_store = $is_seller ? (int)$report_store_id : (isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0);

// Tarih aralığı Türkiye saatiyle seçilir; veritabanı UTC tutar (Türkiye sabit UTC+3)
$tz_tr  = new DateTimeZone('Europe/Istanbul');
$period = sanitize($_GET['period'] ?? '30');
$to_d   = new DateTime('today', $tz_tr);
$from_d = (clone $to_d)->modify('-29 days');
if (in_array($period, ['1', '7', '30', '90', '365'], true)) {
    $from_d = (clone $to_d)->modify('-' . ((int)$period - 1) . ' days');
} elseif ($period === 'custom') {
    $f = DateTime::createFromFormat('!Y-m-d', $_GET['from'] ?? '', $tz_tr);
    $t = DateTime::createFromFormat('!Y-m-d', $_GET['to'] ?? '', $tz_tr);
    if ($f && $t && $f <= $t) {
        $from_d = $f;
        $to_d   = $t;
    }
}
$utc      = new DateTimeZone('UTC');
$from_utc = (clone $from_d)->setTimezone($utc)->format('Y-m-d H:i:s');
$to_utc   = (clone $to_d)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

$is_sqlite = $GLOBALS['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$day_expr  = function ($col) use ($is_sqlite) {
    return $is_sqlite ? "date({$col}, '+3 hours')" : "DATE(DATE_ADD({$col}, INTERVAL 3 HOUR))";
};

// Olay filtresi (dükkân seçiliyse yalnızca o dükkânın kitap sayfaları ve vitrin sayfası)
$ev_where  = "e.created_at >= ? AND e.created_at < ?";
$ev_params = [$from_utc, $to_utc];
if ($scope_store > 0) {
    $ev_where .= " AND e.store_id = ?";
    $ev_params[] = $scope_store;
}
// Satış filtresi (ödemesi alınmış siparişler)
$sale_where  = "o.created_at >= ? AND o.created_at < ? AND o.status NOT IN ('İptal', 'Ödeme Bekliyor')";
$sale_params = [$from_utc, $to_utc];
if ($scope_store > 0) {
    $sale_where .= " AND b.store_id = ?";
    $sale_params[] = $scope_store;
}

// --- Özet kartları ---
$summary = db_fetch("SELECT
        SUM(CASE WHEN e.event_type = 'view' THEN 1 ELSE 0 END) as views,
        COUNT(DISTINCT CASE WHEN e.event_type = 'view' THEN e.visitor_hash END) as visitors,
        COUNT(DISTINCT CASE WHEN e.event_type = 'view' THEN e.session_hash END) as sessions,
        AVG(CASE WHEN e.event_type = 'view' AND e.duration_sec > 0 THEN e.duration_sec END) as avg_sec,
        SUM(CASE WHEN e.event_type = 'cart_add' THEN 1 ELSE 0 END) as cart_adds
    FROM analytics_events e WHERE {$ev_where}", $ev_params);
$sales = db_fetch("SELECT COALESCE(SUM(oi.quantity), 0) as qty, COALESCE(SUM(oi.total), 0) as revenue, COUNT(DISTINCT o.id) as orders,
        COALESCE(SUM(oi.discount_amount), 0) as discount, COALESCE(SUM(oi.commission_amount), 0) as commission
    FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN books b ON oi.book_id = b.id
    WHERE {$sale_where}", $sale_params);

// --- Günlük grafik ---
$daily = [];
for ($d = clone $from_d; $d <= $to_d; $d->modify('+1 day')) {
    $daily[$d->format('Y-m-d')] = ['views' => 0, 'visitors' => 0, 'cart' => 0, 'sales' => 0];
}
foreach (db_fetch_all("SELECT {$day_expr('e.created_at')} as d,
        SUM(CASE WHEN e.event_type = 'view' THEN 1 ELSE 0 END) as views,
        COUNT(DISTINCT CASE WHEN e.event_type = 'view' THEN e.visitor_hash END) as visitors,
        SUM(CASE WHEN e.event_type = 'cart_add' THEN 1 ELSE 0 END) as cart
    FROM analytics_events e WHERE {$ev_where} GROUP BY d", $ev_params) as $r) {
    if (isset($daily[$r['d']])) {
        $daily[$r['d']] = array_merge($daily[$r['d']], ['views' => (int)$r['views'], 'visitors' => (int)$r['visitors'], 'cart' => (int)$r['cart']]);
    }
}
foreach (db_fetch_all("SELECT {$day_expr('o.created_at')} as d, SUM(oi.quantity) as qty
    FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN books b ON oi.book_id = b.id
    WHERE {$sale_where} GROUP BY d", $sale_params) as $r) {
    if (isset($daily[$r['d']])) {
        $daily[$r['d']]['sales'] = (int)$r['qty'];
    }
}

// --- Giriş (ziyaretin ilk sayfası) ve çıkış (son sayfası) ---
$entry_exit = function ($agg) use ($from_utc, $to_utc) {
    return db_fetch_all("SELECT e.page_type, e.book_id, e.store_id, COUNT(*) as cnt FROM analytics_events e
        JOIN (SELECT {$agg}(id) as xid FROM analytics_events WHERE event_type = 'view' AND created_at >= ? AND created_at < ? GROUP BY session_hash) x ON e.id = x.xid
        GROUP BY e.page_type, e.book_id, e.store_id", [$from_utc, $to_utc]);
};
$entries = $entry_exit('MIN');
$exits   = $entry_exit('MAX');

// --- Kitap bazında tablo ---
$books_report = [];
$book_row = function ($id) use (&$books_report) {
    if (!isset($books_report[$id])) {
        $books_report[$id] = ['views' => 0, 'visitors' => 0, 'avg_sec' => 0, 'entries' => 0, 'exits' => 0, 'cart' => 0, 'qty' => 0, 'revenue' => 0];
    }
};
foreach (db_fetch_all("SELECT e.book_id,
        SUM(CASE WHEN e.event_type = 'view' THEN 1 ELSE 0 END) as views,
        COUNT(DISTINCT CASE WHEN e.event_type = 'view' THEN e.visitor_hash END) as visitors,
        AVG(CASE WHEN e.event_type = 'view' AND e.duration_sec > 0 THEN e.duration_sec END) as avg_sec,
        SUM(CASE WHEN e.event_type = 'cart_add' THEN 1 ELSE 0 END) as cart
    FROM analytics_events e WHERE {$ev_where} AND e.book_id IS NOT NULL GROUP BY e.book_id", $ev_params) as $r) {
    $book_row($r['book_id']);
    $books_report[$r['book_id']] = array_merge($books_report[$r['book_id']], ['views' => (int)$r['views'], 'visitors' => (int)$r['visitors'], 'avg_sec' => (float)$r['avg_sec'], 'cart' => (int)$r['cart']]);
}
foreach (db_fetch_all("SELECT oi.book_id, SUM(oi.quantity) as qty, SUM(oi.total) as revenue
    FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN books b ON oi.book_id = b.id
    WHERE {$sale_where} GROUP BY oi.book_id", $sale_params) as $r) {
    $book_row($r['book_id']);
    $books_report[$r['book_id']]['qty'] = (int)$r['qty'];
    $books_report[$r['book_id']]['revenue'] = (float)$r['revenue'];
}
foreach (['entries' => $entries, 'exits' => $exits] as $key => $rows) {
    foreach ($rows as $r) {
        if ($r['book_id'] && isset($books_report[$r['book_id']])) {
            $books_report[$r['book_id']][$key] += (int)$r['cnt'];
        }
    }
}
uasort($books_report, function ($a, $b) {
    return [$b['views'], $b['qty']] <=> [$a['views'], $a['qty']];
});
$books_report = array_slice($books_report, 0, 100, true);
$book_info = [];
if ($books_report) {
    $ids = array_map('intval', array_keys($books_report));
    foreach (db_fetch_all("SELECT b.id, b.title, b.cover_image, a.name as author_name, s.name as store_name FROM books b
        LEFT JOIN authors a ON b.author_id = a.id LEFT JOIN stores s ON b.store_id = s.id
        WHERE b.id IN (" . implode(',', $ids) . ")") as $r) {
        $book_info[$r['id']] = $r;
    }
}

// --- Sayfa türleri (yalnızca yönetici, dükkân seçili değilken) ---
$page_labels = ['home' => 'Ana Sayfa', 'category' => 'Kategori / Arama', 'detail' => 'Kitap Detay', 'store' => 'Alt Dükkan Vitrini', 'checkout' => 'Sepet / Ödeme', 'blog' => 'Blog', 'contact' => 'İletişim', 'legal' => 'Yasal Sayfalar', 'author' => 'Yazar Sayfası'];
$page_report = [];
if (!$is_seller && $scope_store === 0) {
    foreach (db_fetch_all("SELECT e.page_type, COUNT(*) as views, COUNT(DISTINCT e.visitor_hash) as visitors, AVG(CASE WHEN e.duration_sec > 0 THEN e.duration_sec END) as avg_sec
        FROM analytics_events e WHERE {$ev_where} AND e.event_type = 'view' GROUP BY e.page_type ORDER BY views DESC", $ev_params) as $r) {
        $page_report[$r['page_type']] = $r + ['entries' => 0, 'exits' => 0];
    }
    foreach (['entries' => $entries, 'exits' => $exits] as $key => $rows) {
        foreach ($rows as $r) {
            if (isset($page_report[$r['page_type']])) {
                $page_report[$r['page_type']][$key] += (int)$r['cnt'];
            }
        }
    }
}

// --- Dükkân vitrin sayfası (satıcı veya dükkân seçiliyse) ---
$store_page = null;
if ($scope_store > 0) {
    $store_page = db_fetch("SELECT COUNT(*) as views, COUNT(DISTINCT e.visitor_hash) as visitors FROM analytics_events e WHERE {$ev_where} AND e.event_type = 'view' AND e.page_type = 'store'", $ev_params);
    $store_page['entries'] = 0;
    foreach ($entries as $r) {
        if ($r['page_type'] === 'store' && (int)$r['store_id'] === $scope_store) {
            $store_page['entries'] += (int)$r['cnt'];
        }
    }
}

// --- Trafik kaynakları ---
$referrers = db_fetch_all("SELECT COALESCE(e.referrer_host, '') as host, COUNT(*) as cnt FROM analytics_events e
    WHERE {$ev_where} AND e.event_type = 'view' GROUP BY host ORDER BY cnt DESC LIMIT 15", $ev_params);

// --- Alt dükkân karşılaştırması (yalnızca yönetici) ---
$stores_report = [];
if (!$is_seller && $scope_store === 0) {
    $stores_report[0] = ['name' => 'Yeniçeri (Ana Mağaza)', 'views' => 0, 'visitors' => 0, 'cart' => 0, 'qty' => 0, 'revenue' => 0, 'commission' => 0, 'books' => 0];
    foreach (db_fetch_all("SELECT id, name, (SELECT COUNT(*) FROM books WHERE store_id = stores.id AND is_active = 1) as books FROM stores ORDER BY name") as $s) {
        $stores_report[$s['id']] = ['name' => $s['name'], 'views' => 0, 'visitors' => 0, 'cart' => 0, 'qty' => 0, 'revenue' => 0, 'commission' => 0, 'books' => (int)$s['books']];
    }
    $stores_report[0]['books'] = (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE store_id IS NULL AND is_active = 1")['cnt'] ?? 0);
    foreach (db_fetch_all("SELECT COALESCE(e.store_id, 0) as sid,
            SUM(CASE WHEN e.event_type = 'view' THEN 1 ELSE 0 END) as views,
            COUNT(DISTINCT CASE WHEN e.event_type = 'view' THEN e.visitor_hash END) as visitors,
            SUM(CASE WHEN e.event_type = 'cart_add' THEN 1 ELSE 0 END) as cart
        FROM analytics_events e WHERE {$ev_where} AND (e.book_id IS NOT NULL OR e.page_type = 'store') GROUP BY sid", $ev_params) as $r) {
        if (isset($stores_report[$r['sid']])) {
            $stores_report[$r['sid']] = array_merge($stores_report[$r['sid']], ['views' => (int)$r['views'], 'visitors' => (int)$r['visitors'], 'cart' => (int)$r['cart']]);
        }
    }
    foreach (db_fetch_all("SELECT COALESCE(b.store_id, 0) as sid, SUM(oi.quantity) as qty, SUM(oi.total) as revenue, SUM(oi.commission_amount) as commission
        FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN books b ON oi.book_id = b.id
        WHERE {$sale_where} GROUP BY sid", $sale_params) as $r) {
        if (isset($stores_report[$r['sid']])) {
            $stores_report[$r['sid']]['qty'] = (int)$r['qty'];
            $stores_report[$r['sid']]['revenue'] = (float)$r['revenue'];
            $stores_report[$r['sid']]['commission'] = (float)$r['commission'];
        }
    }
}

$stores_all_r = $is_seller ? [] : db_fetch_all("SELECT id, name FROM stores ORDER BY name ASC");

$fmt_sec = function ($s) {
    $s = (int)round((float)$s);
    if ($s <= 0) return '-';
    return $s >= 60 ? floor($s / 60) . ' dk ' . ($s % 60) . ' sn' : $s . ' sn';
};
$pct = function ($a, $b) {
    return $b > 0 ? number_format($a / $b * 100, 1, ',', '.') . '%' : '-';
};
$num = function ($n) {
    return number_format((float)$n, 0, ',', '.');
};
$period_labels = ['1' => 'Bugün', '7' => 'Son 7 gün', '30' => 'Son 30 gün', '90' => 'Son 90 gün', '365' => 'Son 1 yıl'];
?>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="" class="row g-2 align-items-end">
            <?php foreach (array_filter(explode('&', parse_url($report_base, PHP_URL_QUERY) ?? '')) as $kv): [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); ?>
                <input type="hidden" name="<?= e(urldecode($k)) ?>" value="<?= e(urldecode($v)) ?>">
            <?php endforeach; ?>
            <div class="col-md-3">
                <label class="form-label fs-7 fw-semibold mb-1">Dönem</label>
                <select name="period" class="form-select" onchange="document.getElementById('customRange').classList.toggle('d-none', this.value !== 'custom')">
                    <?php foreach ($period_labels as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $period === (string)$k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                    <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Tarih aralığı seç…</option>
                </select>
            </div>
            <div class="col-md-4 <?= $period === 'custom' ? '' : 'd-none' ?>" id="customRange">
                <div class="d-flex gap-2">
                    <div class="flex-fill"><label class="form-label fs-7 fw-semibold mb-1">Başlangıç</label><input type="date" name="from" class="form-control" value="<?= $from_d->format('Y-m-d') ?>"></div>
                    <div class="flex-fill"><label class="form-label fs-7 fw-semibold mb-1">Bitiş</label><input type="date" name="to" class="form-control" value="<?= $to_d->format('Y-m-d') ?>"></div>
                </div>
            </div>
            <?php if (!$is_seller): ?>
                <div class="col-md-3">
                    <label class="form-label fs-7 fw-semibold mb-1">Dükkân</label>
                    <select name="store_id" class="form-select">
                        <option value="0">Tüm Site</option>
                        <?php foreach ($stores_all_r as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= $scope_store === (int)$st['id'] ? 'selected' : '' ?>>Alt Dükkan: <?= e($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-dark w-100 fw-semibold">Göster</button>
            </div>
        </form>
        <div class="text-muted fs-8 mt-2">
            <?= $from_d->format('d.m.Y') ?> – <?= $to_d->format('d.m.Y') ?> (Türkiye saati). Yönetici/satıcı oturumları ve arama motoru botları sayılmaz.
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Görüntülenme', $num($summary['views'] ?? 0), 'bi-eye', 'Sayfa açılış sayısı'],
        ['Tekil Ziyaretçi', $num($summary['visitors'] ?? 0), 'bi-people', 'Farklı kişi (tarayıcı)'],
        ['Ziyaret (Oturum)', $num($summary['sessions'] ?? 0), 'bi-door-open', '30 dk hareketsizlikle biter'],
        ['Ort. Kalma Süresi', $fmt_sec($summary['avg_sec'] ?? 0), 'bi-stopwatch', 'Sayfa başına'],
        ['Sepete Ekleme', $num($summary['cart_adds'] ?? 0), 'bi-cart-plus', 'Dönüşüm: ' . $pct($summary['cart_adds'] ?? 0, $summary['views'] ?? 0)],
        ['Satılan Kitap', $num($sales['qty']), 'bi-bag-check', $num($sales['orders']) . ' sipariş'],
        ['Ciro', format_price($sales['revenue']), 'bi-cash-stack', $sales['discount'] > 0 ? 'Kampanya indirimi sonrası (' . format_price($sales['discount']) . ' indirim)' : 'Ödemesi alınan siparişler'],
    ];
    if ($scope_store > 0) {
        $cards[] = ['Komisyon', format_price($sales['commission']), 'bi-percent', 'Şu anki oran: %' . rtrim(rtrim(number_format(store_commission_rate($scope_store), 2, ',', ''), '0'), ',')];
        $cards[] = ['Net Hakediş', format_price($sales['revenue'] - $sales['commission']), 'bi-wallet2', 'Ciro − komisyon'];
    }
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="bg-white rounded-4 shadow-sm p-3 h-100">
                <div class="text-muted fs-8 fw-semibold text-uppercase"><i class="bi <?= $c[2] ?> text-danger me-1"></i><?= $c[0] ?></div>
                <div class="fs-4 fw-extrabold text-dark mt-1"><?= $c[1] ?></div>
                <div class="text-muted fs-8"><?= $c[3] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold mb-0">Günlük Trafik ve Satış</h6></div>
    <div class="card-body p-3"><div style="height: 280px;"><canvas id="dailyChart"></canvas></div></div>
</div>

<?php if ($store_page): ?>
    <div class="alert alert-light border rounded-4 mb-4 fs-7">
        <i class="bi bi-shop text-danger me-1"></i> <b>Dükkân vitrin sayfası:</b>
        <?= $num($store_page['views']) ?> görüntülenme, <?= $num($store_page['visitors']) ?> tekil ziyaretçi, <?= $num($store_page['entries']) ?> ziyaret siteye bu sayfadan girdi.
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 p-3 pb-0">
        <h6 class="fw-bold mb-1">Kitap Bazında Rapor</h6>
        <div class="text-muted fs-8"><b>Giriş:</b> ziyaretin bu kitapla başladığı, <b>Çıkış:</b> ziyaretin bu kitapta bittiği (siteden ayrıldığı) sayısı. En çok görüntülenen 100 kitap.</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 fs-7">
                <thead class="table-light fs-8">
                    <tr>
                        <th class="ps-3">Kitap</th>
                        <th class="text-end">Görüntülenme</th>
                        <th class="text-end">Tekil</th>
                        <th class="text-end">Ort. Süre</th>
                        <th class="text-end">Giriş</th>
                        <th class="text-end">Çıkış</th>
                        <th class="text-end">Sepete Ekl.</th>
                        <th class="text-end">Satış</th>
                        <th class="text-end">Dönüşüm</th>
                        <th class="text-end pe-3">Ciro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$books_report): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Bu dönemde kayıt yok. Ziyaretler kaydedilmeye başladıktan sonra burada görünür.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($books_report as $bid => $r): $bi = $book_info[$bid] ?? null; ?>
                        <tr>
                            <td class="ps-3">
                                <div class="d-flex align-items-center gap-2">
                                    <img src="../<?= e(cover_url($bi['cover_image'] ?? '')) ?>" alt="" style="width: 28px; height: 40px; object-fit: cover;" class="rounded border bg-white" loading="lazy">
                                    <div>
                                        <a href="../detail.php?id=<?= (int)$bid ?>" target="_blank" class="fw-semibold text-dark text-decoration-none"><?= e($bi['title'] ?? ('#' . $bid . ' (silinmiş)')) ?></a>
                                        <div class="text-muted fs-8">
                                            <?= e($bi['author_name'] ?? '') ?>
                                            <?php if (!$is_seller && !empty($bi['store_name'])): ?> • <span class="text-danger"><i class="bi bi-shop"></i> <?= e($bi['store_name']) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-end fw-bold"><?= $num($r['views']) ?></td>
                            <td class="text-end"><?= $num($r['visitors']) ?></td>
                            <td class="text-end"><?= $fmt_sec($r['avg_sec']) ?></td>
                            <td class="text-end"><?= $num($r['entries']) ?></td>
                            <td class="text-end"><?= $num($r['exits']) ?></td>
                            <td class="text-end"><?= $num($r['cart']) ?></td>
                            <td class="text-end fw-bold"><?= $num($r['qty']) ?></td>
                            <td class="text-end"><?= $pct($r['qty'], $r['visitors']) ?></td>
                            <td class="text-end pe-3"><?= $r['revenue'] > 0 ? format_price($r['revenue']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php if ($page_report): ?>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold mb-0">Sayfa Türleri (Giriş / Çıkış)</h6></div>
                <div class="card-body p-0">
                    <table class="table align-middle mb-0 fs-7">
                        <thead class="table-light fs-8"><tr><th class="ps-3">Sayfa</th><th class="text-end">Görüntülenme</th><th class="text-end">Tekil</th><th class="text-end">Ort. Süre</th><th class="text-end">Giriş</th><th class="text-end pe-3">Çıkış</th></tr></thead>
                        <tbody>
                            <?php foreach ($page_report as $type => $r): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= e($page_labels[$type] ?? $type) ?></td>
                                    <td class="text-end"><?= $num($r['views']) ?></td>
                                    <td class="text-end"><?= $num($r['visitors']) ?></td>
                                    <td class="text-end"><?= $fmt_sec($r['avg_sec']) ?></td>
                                    <td class="text-end"><?= $num($r['entries']) ?></td>
                                    <td class="text-end pe-3"><?= $num($r['exits']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="<?= $page_report ? 'col-lg-5' : 'col-lg-6' ?>">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold mb-0">Trafik Kaynakları</h6></div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0 fs-7">
                    <thead class="table-light fs-8"><tr><th class="ps-3">Kaynak</th><th class="text-end pe-3">Görüntülenme</th></tr></thead>
                    <tbody>
                        <?php if (!$referrers): ?><tr><td colspan="2" class="text-center text-muted py-3">Kayıt yok</td></tr><?php endif; ?>
                        <?php foreach ($referrers as $r): ?>
                            <tr>
                                <td class="ps-3"><?= $r['host'] === '' ? '<span class="text-muted">Doğrudan / site içi</span>' : e($r['host']) ?></td>
                                <td class="text-end pe-3"><?= $num($r['cnt']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($stores_report): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold mb-0">Alt Dükkân Karşılaştırması</h6></div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0 fs-7">
                <thead class="table-light fs-8"><tr><th class="ps-3">Dükkân</th><th class="text-end">Aktif Kitap</th><th class="text-end">Görüntülenme</th><th class="text-end">Tekil</th><th class="text-end">Sepete Ekl.</th><th class="text-end">Satış</th><th class="text-end">Ciro</th><th class="text-end pe-3">Komisyon</th></tr></thead>
                <tbody>
                    <?php foreach ($stores_report as $sid => $r): ?>
                        <tr>
                            <td class="ps-3 fw-semibold">
                                <?php if ($sid > 0): ?><a href="<?= e($report_base) ?>store_id=<?= $sid ?>&period=<?= e($period) ?>&from=<?= $from_d->format('Y-m-d') ?>&to=<?= $to_d->format('Y-m-d') ?>" class="text-dark"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif; ?>
                            </td>
                            <td class="text-end"><?= $num($r['books']) ?></td>
                            <td class="text-end"><?= $num($r['views']) ?></td>
                            <td class="text-end"><?= $num($r['visitors']) ?></td>
                            <td class="text-end"><?= $num($r['cart']) ?></td>
                            <td class="text-end fw-bold"><?= $num($r['qty']) ?></td>
                            <td class="text-end"><?= $r['revenue'] > 0 ? format_price($r['revenue']) : '-' ?></td>
                            <td class="text-end pe-3"><?= $r['commission'] > 0 ? format_price($r['commission']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) return;
    var d = <?= json_encode(array_values($daily)) ?>;
    var labels = <?= json_encode(array_map(function ($k) { return date('d.m', strtotime($k)); }, array_keys($daily))) ?>;
    new Chart(document.getElementById('dailyChart'), {
        data: {
            labels: labels,
            datasets: [
                { type: 'line', label: 'Görüntülenme', data: d.map(function (x) { return x.views; }), borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.08)', fill: true, tension: .3, yAxisID: 'y' },
                { type: 'line', label: 'Tekil Ziyaretçi', data: d.map(function (x) { return x.visitors; }), borderColor: '#212529', tension: .3, yAxisID: 'y' },
                { type: 'bar', label: 'Sepete Ekleme', data: d.map(function (x) { return x.cart; }), backgroundColor: 'rgba(255,193,7,.6)', yAxisID: 'y2' },
                { type: 'bar', label: 'Satılan Kitap', data: d.map(function (x) { return x.sales; }), backgroundColor: 'rgba(25,135,84,.7)', yAxisID: 'y2' }
            ]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Trafik' } },
                y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 }, title: { display: true, text: 'Sepet / Satış' } }
            },
            plugins: { legend: { position: 'bottom' } }
        }
    });
})();
</script>
