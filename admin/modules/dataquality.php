<?php
/**
 * ADMIN MODULE: Veri Kontrolü (p=DataQuality) — SALT OKUNUR.
 * Hiçbir kaydı değiştirmez; düzeltme önerilerini listeler. Düzeltmeler onaydan sonra ayrıca yapılır.
 */
if (!defined('SITE_URL')) exit;

$norm = function ($t) {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9çğıöşü ]/u', ' ', tr_lower((string)$t))));
};

$books = db_fetch_all("SELECT b.id, b.title, b.isbn, b.summary, b.cover_image, b.is_active, b.store_id, b.author_id, b.category_id, b.slug,
        a.name as author_name, p.name as publisher_name, c.name as category_name, s.name as store_name
    FROM books b LEFT JOIN authors a ON a.id = b.author_id LEFT JOIN publishers p ON p.id = b.publisher_id
    LEFT JOIN categories c ON c.id = b.category_id LEFT JOIN stores s ON s.id = b.store_id ORDER BY b.id");

// 1) Numarayla görünen kategoriler
$numeric_cats = [];
foreach (db_fetch_all("SELECT c.id, c.name, COUNT(b.id) as cnt FROM categories c LEFT JOIN books b ON b.category_id = c.id GROUP BY c.id, c.name ORDER BY c.name") as $c) {
    if (is_numeric_category_name($c['name'])) {
        $c['samples'] = array_column(db_fetch_all("SELECT title FROM books WHERE category_id = ? LIMIT 4", [$c['id']]), 'title');
        $numeric_cats[] = $c;
    }
}

// 2a) Bilinen eserlerde yazar uyuşmazlığı (yaygın Türk ve dünya klasikleri)
$known = [
    'çalıkuşu' => 'Reşat Nuri Güntekin', 'yaprak dökümü' => 'Reşat Nuri Güntekin', 'acımak' => 'Reşat Nuri Güntekin',
    'nutuk' => 'Mustafa Kemal Atatürk', 'kürk mantolu madonna' => 'Sabahattin Ali', 'içimizdeki şeytan' => 'Sabahattin Ali',
    'tutunamayanlar' => 'Oğuz Atay', 'saatleri ayarlama enstitüsü' => 'Ahmet Hamdi Tanpınar', 'huzur' => 'Ahmet Hamdi Tanpınar',
    'aşk ı memnu' => 'Halit Ziya Uşaklıgil', 'mai ve siyah' => 'Halit Ziya Uşaklıgil', 'sinekli bakkal' => 'Halide Edib Adıvar',
    'ince memed' => 'Yaşar Kemal', 'kuyucaklı yusuf' => 'Sabahattin Ali', 'yaban' => 'Yakup Kadri Karaosmanoğlu',
    'suç ve ceza' => 'Fyodor Dostoyevski', 'karamazov kardeşler' => 'Fyodor Dostoyevski', 'yeraltından notlar' => 'Fyodor Dostoyevski',
    'dönüşüm' => 'Franz Kafka', 'dava' => 'Franz Kafka', 'satranç' => 'Stefan Zweig', 'amok koşucusu' => 'Stefan Zweig',
    'savaş ve barış' => 'Lev Tolstoy', 'anna karenina' => 'Lev Tolstoy', 'sefiller' => 'Victor Hugo', '1984' => 'George Orwell',
    'hayvan çiftliği' => 'George Orwell', 'küçük prens' => 'Antoine de Saint-Exupéry', 'peter pan' => 'J. M. Barrie',
    'şeker portakalı' => 'José Mauro de Vasconcelos', 'simyacı' => 'Paulo Coelho', 'beyaz diş' => 'Jack London',
    'fareler ve insanlar' => 'John Steinbeck', 'yabancı' => 'Albert Camus', 'veba' => 'Albert Camus', 'don kişot' => 'Miguel de Cervantes',
    'yüzüklerin efendisi' => 'J.R.R. Tolkien', 'hobbit' => 'J.R.R. Tolkien', 'harry potter' => 'J.K. Rowling',
];
$surname = function ($name) use ($norm) {
    $parts = explode(' ', $norm(str_replace('.', ' ', $name)));
    return end($parts);
};
$author_mismatch = [];
foreach ($books as $b) {
    $nt = $norm($b['title']);
    foreach ($known as $title => $expected) {
        if ($nt === $norm($title) || strpos($nt . ' ', $norm($title) . ' ') === 0) {
            if ($surname($b['author_name'] ?? '') !== $surname($expected)) {
                $author_mismatch[] = $b + ['expected' => $expected];
            }
            break;
        }
    }
}
// 2b) Aynı kitap adı farklı yazarlarla kayıtlı
$by_title = [];
foreach ($books as $b) {
    $by_title[$norm($b['title'])][] = $b;
}
$title_author_conflicts = array_filter($by_title, function ($g) {
    return count(array_unique(array_column($g, 'author_id'))) > 1;
});

// 3) Mükerrer ürünler: aynı ad + aynı yazar (aynı dükkânda ise büyük ihtimalle kopya) ya da aynı ISBN
$dup_groups = [];
foreach ($books as $b) {
    $dup_groups[$norm($b['title']) . '|' . $b['author_id']][] = $b;
}
$dup_groups = array_filter($dup_groups, function ($g) { return count($g) > 1; });
$isbn_groups = [];
foreach ($books as $b) {
    $i = preg_replace('/[^0-9X]/i', '', (string)$b['isbn']);
    if (strlen($i) >= 10) $isbn_groups[$i][] = $b;
}
$isbn_groups = array_filter($isbn_groups, function ($g) { return count($g) > 1; });

// 4) Bozuk karakter (UTF-8 çift kodlama: "Ã§" = ç, "ÅŸ" = ş, "Ä±" = ı ...)
$moji_re = '/Ã.|Ä.|Å.|Ã|â€|Â|�/u';
$fix_moji = function ($s) {
    $f = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    return ($f !== false && mb_check_encoding($f, 'UTF-8') && $f !== $s) ? $f : null;
};
$mojibake = [];
$check_tables = [
    ['books', 'title'], ['books', 'summary'], ['authors', 'name'], ['publishers', 'name'], ['categories', 'name'],
];
foreach ($check_tables as [$tbl, $col]) {
    foreach (db_fetch_all("SELECT id, {$col} as val FROM {$tbl}") as $r) {
        if ($r['val'] !== null && preg_match($moji_re, $r['val'])) {
            $mojibake[] = ['table' => $tbl, 'col' => $col, 'id' => $r['id'], 'val' => $r['val'], 'fix' => $fix_moji($r['val'])];
        }
    }
}

// 5) Açıklaması (özeti) boş ürünler
$empty_summary = array_values(array_filter($books, function ($b) { return trim((string)$b['summary']) === ''; }));
$empty_active = count(array_filter($empty_summary, function ($b) { return $b['is_active']; }));

$edit_link = function ($id) { return 'index.php?p=Products&action=edit&id=' . (int)$id; };
$tabs = [
    'cats' => ['Numaralı Kategoriler', count($numeric_cats)],
    'authors' => ['Yazar Hataları', count($author_mismatch) + count($title_author_conflicts)],
    'dups' => ['Mükerrer Ürünler', count($dup_groups) + count($isbn_groups)],
    'moji' => ['Bozuk Karakter', count($mojibake)],
    'empty' => ['Açıklaması Boş', count($empty_summary)],
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'cats';
?>

<div class="mb-4">
    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-clipboard2-check text-danger me-2"></i>Veri Kontrolü</h5>
    <p class="text-muted fs-7 mb-0">Bu sayfa <b>hiçbir kaydı değiştirmez</b>; yalnızca sorunları ve düzeltme önerilerini listeler. Düzeltmeleri kitabın düzenleme sayfasından ya da onayınızdan sonra toplu olarak yapabilirsiniz.</p>
</div>

<ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm border flex-wrap">
    <?php foreach ($tabs as $k => [$label, $cnt]): ?>
        <li class="nav-item"><a class="nav-link fw-semibold <?= $tab === $k ? 'active bg-danger text-white' : 'text-secondary' ?>" href="index.php?p=DataQuality&tab=<?= $k ?>"><?= $label ?> <span class="badge <?= $tab === $k ? 'bg-white text-danger' : ($cnt ? 'bg-warning text-dark' : 'bg-light text-secondary') ?> ms-1"><?= $cnt ?></span></a></li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 fs-7">
            <?php if ($tab === 'cats'): ?>
                <thead class="table-light fs-8"><tr><th class="ps-3">Kategori</th><th class="text-end">Kitap</th><th>Örnek kitaplar (adını belirlemek için)</th><th class="pe-3"></th></tr></thead>
                <tbody>
                    <?php if (!$numeric_cats): ?><tr><td colspan="4" class="text-center text-muted py-4">Numarayla görünen kategori yok.</td></tr><?php endif; ?>
                    <?php foreach ($numeric_cats as $c): ?>
                        <tr><td class="ps-3 fw-bold">"<?= e($c['name']) ?>" <span class="text-muted fw-normal">(#<?= $c['id'] ?>)</span></td><td class="text-end"><?= (int)$c['cnt'] ?></td><td class="fs-8"><?= e(implode(' • ', $c['samples'])) ?></td>
                        <td class="pe-3 text-end"><a href="index.php?p=Catalog&tab=categories&edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">Adlandır / Birleştir</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            <?php elseif ($tab === 'authors'): ?>
                <thead class="table-light fs-8"><tr><th class="ps-3">Kitap</th><th>Kayıtlı yazar</th><th>Olması gereken (öneri)</th><th class="pe-3"></th></tr></thead>
                <tbody>
                    <?php if (!$author_mismatch && !$title_author_conflicts): ?><tr><td colspan="4" class="text-center text-muted py-4">Yazar hatası bulunamadı.</td></tr><?php endif; ?>
                    <?php foreach ($author_mismatch as $b): ?>
                        <tr><td class="ps-3"><b>#<?= $b['id'] ?></b> <?= e($b['title']) ?></td><td class="text-danger"><?= e($b['author_name']) ?></td><td class="text-success"><?= e($b['expected']) ?></td>
                        <td class="pe-3 text-end"><a href="<?= $edit_link($b['id']) ?>" class="btn btn-sm btn-outline-primary">Düzenle</a></td></tr>
                    <?php endforeach; ?>
                    <?php foreach ($title_author_conflicts as $g): ?>
                        <tr class="table-light"><td class="ps-3" colspan="4"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Aynı ad, farklı yazar: <b><?= e($g[0]['title']) ?></b> —
                            <?php foreach ($g as $b): ?><a href="<?= $edit_link($b['id']) ?>">#<?= $b['id'] ?> <?= e($b['author_name']) ?></a><?= $b !== end($g) ? ', ' : '' ?><?php endforeach; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            <?php elseif ($tab === 'dups'): ?>
                <thead class="table-light fs-8"><tr><th class="ps-3">Kitap / Yazar</th><th>Kayıtlar</th><th class="pe-3">Değerlendirme</th></tr></thead>
                <tbody>
                    <?php if (!$dup_groups && !$isbn_groups): ?><tr><td colspan="3" class="text-center text-muted py-4">Mükerrer ürün bulunamadı.</td></tr><?php endif; ?>
                    <?php foreach ($dup_groups as $g): $same_store = count(array_unique(array_map(function ($b) { return (int)$b['store_id']; }, $g))) < count($g); ?>
                        <tr><td class="ps-3"><b><?= e($g[0]['title']) ?></b><div class="text-muted fs-8"><?= e($g[0]['author_name']) ?></div></td>
                            <td><?php foreach ($g as $b): ?><div><a href="<?= $edit_link($b['id']) ?>">#<?= $b['id'] ?></a> <?= e($b['store_name'] ?: 'Ana mağaza') ?> • ISBN <?= e($b['isbn'] ?: '-') ?> • <?= $b['is_active'] ? 'aktif' : 'pasif' ?></div><?php endforeach; ?></td>
                            <td class="pe-3 fs-8"><?= $same_store ? '<span class="text-danger fw-bold">Aynı dükkânda: büyük ihtimalle kopya</span> — biri pasife alınabilir' : '<span class="text-muted">Farklı dükkânlarda (sahaf ilanı olabilir, normal)</span>' ?></td></tr>
                    <?php endforeach; ?>
                    <?php foreach ($isbn_groups as $isbn => $g): ?>
                        <tr><td class="ps-3">ISBN <b><?= e($isbn) ?></b></td><td><?php foreach ($g as $b): ?><div><a href="<?= $edit_link($b['id']) ?>">#<?= $b['id'] ?></a> <?= e($b['title']) ?> (<?= e($b['store_name'] ?: 'Ana mağaza') ?>)</div><?php endforeach; ?></td><td class="pe-3 fs-8">Aynı ISBN birden fazla kayıtta</td></tr>
                    <?php endforeach; ?>
                </tbody>
            <?php elseif ($tab === 'moji'): ?>
                <thead class="table-light fs-8"><tr><th class="ps-3">Kayıt</th><th>Şu an</th><th class="pe-3">Önerilen düzeltme</th></tr></thead>
                <tbody>
                    <?php if (!$mojibake): ?><tr><td colspan="3" class="text-center text-muted py-4">Bozuk karakterli kayıt bulunamadı.</td></tr><?php endif; ?>
                    <?php foreach ($mojibake as $m): ?>
                        <tr><td class="ps-3 fs-8"><?= e($m['table']) ?>.<?= e($m['col']) ?> #<?= $m['id'] ?></td><td class="text-danger"><?= e(mb_strimwidth($m['val'], 0, 120, '…', 'UTF-8')) ?></td><td class="pe-3 <?= $m['fix'] ? 'text-success' : 'text-muted' ?>"><?= $m['fix'] ? e(mb_strimwidth($m['fix'], 0, 120, '…', 'UTF-8')) : 'Otomatik öneri yok, elle düzeltin' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            <?php else: ?>
                <thead class="table-light fs-8"><tr><th class="ps-3">Kitap</th><th>Yazar</th><th>Dükkân</th><th>Durum</th><th class="pe-3"></th></tr></thead>
                <tbody>
                    <tr class="table-light"><td colspan="5" class="ps-3 fs-8"><?= count($empty_summary) ?> kitabın açıklaması boş (<?= $empty_active ?> tanesi satışta). Açıklama boşken Google açıklaması ad, yazar, yayınevi ve fiyattan otomatik üretilir; yine de 2–3 cümlelik özgün bir açıklama sıralamaya yardımcı olur.</td></tr>
                    <?php foreach ($empty_summary as $b): ?>
                        <tr><td class="ps-3"><b>#<?= $b['id'] ?></b> <?= e($b['title']) ?></td><td><?= e($b['author_name']) ?></td><td class="fs-8"><?= e($b['store_name'] ?: 'Ana mağaza') ?></td><td><?= $b['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?></td>
                        <td class="pe-3 text-end"><a href="<?= $edit_link($b['id']) ?>" class="btn btn-sm btn-outline-primary">Açıklama yaz</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            <?php endif; ?>
            </table>
        </div>
    </div>
</div>
