<?php
/**
 * ADMIN MODULE: NadirKitap Kategori Adları (p=NkCategories)
 * Aktarım uygulaması kategoriyi yalnızca NadirKitap numarasıyla gönderir (62, 81 ...).
 * Bu sayfa her numaralı kategoriden bir kitabın NadirKitap sayfasını açar, sayfadaki kategori bağlantısından adı okur
 * ve ÖNERİ olarak listeler. Yönetici kontrol edip onaylamadan hiçbir kategori değişmez.
 * Onayda: aynı adlı kategori varsa onunla birleştirilir, yoksa numaralı kategori yeniden adlandırılır.
 * Numara eşleşmesi (nk_category_map) korunur; sonraki aktarımlar doğru kategoriye gider.
 */
if (!defined('SITE_URL')) exit;

$numeric = array_values(array_filter(
    db_fetch_all("SELECT c.id, c.name, COUNT(b.id) as cnt FROM categories c LEFT JOIN books b ON b.category_id = c.id GROUP BY c.id, c.name ORDER BY cnt DESC"),
    function ($c) { return is_numeric_category_name($c['name']); }
));
$proposals = $_SESSION['nk_cat_proposals'] ?? [];

// Tara: her numaralı kategoriden en fazla 2 kitabın NadirKitap sayfasını aç (tek seferde en fazla 20 kategori)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan'])) {
    @set_time_limit(240);
    $done = 0;
    foreach ($numeric as $c) {
        if ($done >= 20) break;
        if (!empty($proposals[$c['id']]['name'])) continue;
        $code = trim($c['name']);
        $urls = array_column(db_fetch_all("SELECT source_url FROM books WHERE category_id = ? AND source_url LIKE ? ORDER BY id DESC LIMIT 2", [$c['id'], '%nadirkitap.com%']), 'source_url');
        $found = ['', ''];
        $err = $urls ? '' : 'NadirKitap adresi olan kitap yok';
        foreach ($urls as $u) {
            $r = function_exists('nk_sync_curl_get') ? nk_sync_curl_get($u) : ['ok' => false, 'html' => '', 'error' => 'NadirKitap bağlantı fonksiyonu yok', 'code' => 0];
            if (!$r['ok']) { $err = 'Sayfa açılamadı (HTTP ' . (int)$r['code'] . ')'; continue; }
            $found = nk_category_name_from_html($r['html'], $code);
            if ($found[0] !== '') break;
            $err = 'Sayfada kategori adı bulunamadı';
        }
        $proposals[$c['id']] = ['name' => $found[0], 'confidence' => $found[1], 'error' => $found[0] === '' ? $err : '', 'url' => $urls[0] ?? ''];
        $done++;
        usleep(400000); // NadirKitap'ı yormamak için
    }
    $_SESSION['nk_cat_proposals'] = $proposals;
    $left = count(array_filter($numeric, function ($c) use ($proposals) { return !isset($proposals[$c['id']]); }));
    set_flash('info', $done . ' kategori tarandı.' . ($left ? " {$left} kategori kaldı; tekrar \"Tara\"ya basın." : ' Önerileri kontrol edip onaylayın.'));
    redirect('index.php?p=NkCategories');
}

// Onayla: işaretli önerileri uygula
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {
    global $pdo;
    $applied = 0;
    $pdo->beginTransaction();
    foreach ((array)($_POST['use'] ?? []) as $cid => $on) {
        $cid = (int)$cid;
        $name = trim(preg_replace('/\s+/u', ' ', sanitize($_POST['name'][$cid] ?? '')));
        $cat = db_fetch("SELECT id, name, slug FROM categories WHERE id = ?", [$cid]);
        if (!$cat || $name === '' || !is_numeric_category_name($cat['name']) || is_numeric_category_name($name)) continue;
        $target = find_by_name('categories', $name, $cid);
        if ($target) {
            // Aynı adlı kategori var: kitaplar ve numara eşleşmesi oraya taşınır
            db_query("UPDATE books SET category_id = ? WHERE category_id = ?", [$target['id'], $cid]);
            db_query("UPDATE nk_category_map SET category_id = ? WHERE category_id = ?", [$target['id'], $cid]);
            db_query("UPDATE categories SET parent_id = NULL WHERE parent_id = ?", [$cid]);
            db_query("DELETE FROM categories WHERE id = ?", [$cid]);
            remember_old_slug('category', $cat['slug'], $target['id']);
        } else {
            $slug = unique_slug('categories', $name, $cid);
            db_query("UPDATE categories SET name = ?, slug = ? WHERE id = ?", [$name, $slug, $cid]);
            if ($cat['slug'] !== $slug) remember_old_slug('category', $cat['slug'], $cid);
        }
        unset($proposals[$cid]);
        $applied++;
    }
    $pdo->commit();
    $_SESSION['nk_cat_proposals'] = $proposals;
    log_action('NadirKitap Kategorileri Adlandırıldı', 'Catalog', null, $applied . ' kategori');
    set_flash('success', $applied . ' kategori adlandırıldı / birleştirildi.');
    redirect('index.php?p=NkCategories');
}

if (isset($_GET['reset'])) {
    unset($_SESSION['nk_cat_proposals']);
    redirect('index.php?p=NkCategories');
}
$existing_names = array_column(array_filter(db_fetch_all("SELECT name FROM categories ORDER BY name"), function ($c) { return !is_numeric_category_name($c['name']); }), 'name');
?>

<div class="mb-4">
    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-tags text-danger me-2"></i>NadirKitap Kategori Adları</h5>
    <p class="text-muted fs-7 mb-0">Aktarım uygulaması kategoriyi yalnızca NadirKitap numarasıyla gönderiyor. <b>Tara</b>, her numaralı kategoriden bir kitabın NadirKitap sayfasını açıp kategori adını okur ve öneri olarak listeler. <b>Siz onaylamadan hiçbir kategori değişmez.</b> Aynı adlı kategori varsa onunla birleştirilir; numara hatırlanır ve sonraki aktarımlar doğru kategoriye gider.</p>
</div>

<?php if (!$numeric): ?>
    <div class="alert alert-success">Numarayla görünen kategori kalmadı.</div>
<?php else: ?>
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <form method="POST" action="" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = 'Taranıyor… (1-2 dk sürebilir)';">
            <input type="hidden" name="scan" value="1">
            <button class="btn btn-danger fw-semibold"><i class="bi bi-search me-1"></i> NadirKitap'tan Adları Bul (<?= count($numeric) ?> kategori)</button>
        </form>
        <?php if ($proposals): ?><a href="index.php?p=NkCategories&reset=1" class="btn btn-outline-secondary">Önerileri sıfırla</a><?php endif; ?>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="apply" value="1">
        <datalist id="catNames"><?php foreach ($existing_names as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0 fs-7">
                    <thead class="table-light fs-8"><tr><th class="ps-3" style="width: 40px;"></th><th>Numara</th><th class="text-end">Kitap</th><th>Örnek kitaplar</th><th style="min-width: 260px;">Önerilen / yazılacak ad</th><th class="pe-3">Durum</th></tr></thead>
                    <tbody>
                        <?php foreach ($numeric as $c): $p = $proposals[$c['id']] ?? null; $samples = array_column(db_fetch_all("SELECT title FROM books WHERE category_id = ? LIMIT 3", [$c['id']]), 'title'); ?>
                            <tr>
                                <td class="ps-3"><input type="checkbox" class="form-check-input" name="use[<?= $c['id'] ?>]" value="1" <?= $p && $p['name'] !== '' && $p['confidence'] === 'yüksek' ? 'checked' : '' ?>></td>
                                <td class="fw-bold"><?= e($c['name']) ?></td>
                                <td class="text-end"><?= (int)$c['cnt'] ?></td>
                                <td class="fs-8 text-muted"><?= e(implode(' • ', $samples)) ?></td>
                                <td><input type="text" name="name[<?= $c['id'] ?>]" class="form-control form-control-sm" list="catNames" value="<?= e($p['name'] ?? '') ?>" placeholder="Kategori adı yazın veya listeden seçin"></td>
                                <td class="pe-3 fs-8">
                                    <?php if (!$p): ?><span class="text-muted">Taranmadı</span>
                                    <?php elseif ($p['name'] !== ''): ?><span class="badge <?= $p['confidence'] === 'yüksek' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $p['confidence'] === 'yüksek' ? 'Bulundu' : 'Tahmini — kontrol edin' ?></span>
                                    <?php else: ?><span class="text-danger"><?= e($p['error']) ?></span><?php endif; ?>
                                    <?php if (!empty($p['url'])): ?><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener" class="ms-1" title="NadirKitap sayfası"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-muted">İşaretli satırlar uygulanır. Adı elle de yazabilir ya da mevcut bir kategoriyi (ör. Edebiyat & Roman) seçerek birleştirebilirsiniz.</small>
                <button class="btn btn-dark fw-semibold" onclick="return confirm('İşaretli kategoriler adlandırılacak / birleştirilecek. Devam edilsin mi?');"><i class="bi bi-check2-all me-1"></i> İşaretlileri Uygula</button>
            </div>
        </div>
    </form>
<?php endif; ?>
