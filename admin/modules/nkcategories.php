<?php
/**
 * ADMIN MODULE: NadirKitap Kategori Adları (p=NkCategories)
 * Aktarım uygulaması kategoriyi yalnızca NadirKitap numarasıyla gönderir (62, 81 ...).
 * Önerilen adlar NadirKitap kategori tablosundan (_nk_categories.php) gelir; NadirKitap sunucudan gelen istekleri
 * engellediği için sayfa açıp okuma yapılmaz. Yönetici kontrol edip onaylamadan hiçbir kategori değişmez.
 * Onayda: aynı adlı kategori varsa onunla birleştirilir, yoksa numaralı kategori yeniden adlandırılır ve NadirKitap'taki
 * üst kategorisinin altına alınır (menü alt kategorilerle dolmasın). Numara eşleşmesi korunur.
 */
if (!defined('SITE_URL')) exit;

$numeric = array_values(array_filter(
    db_fetch_all("SELECT c.id, c.name, COUNT(b.id) as cnt FROM categories c LEFT JOIN books b ON b.category_id = c.id GROUP BY c.id, c.name ORDER BY cnt DESC"),
    function ($c) { return is_numeric_category_name($c['name']); }
));
$nk_table = nk_category_table();
$proposals = [];
foreach ($numeric as $c) {
    $code = trim($c['name']);
    if (isset($nk_table[$code])) {
        $parent = $nk_table[$code][1] !== null ? ($nk_table[$nk_table[$code][1]][0] ?? '') : '';
        $proposals[$c['id']] = ['name' => $nk_table[$code][0], 'confidence' => 'yüksek', 'parent' => $parent, 'error' => '', 'url' => ''];
    } else {
        $proposals[$c['id']] = ['name' => '', 'confidence' => '', 'parent' => '', 'error' => 'NadirKitap tablosunda yok, adı elle yazın', 'url' => ''];
    }
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
            db_query("UPDATE categories SET parent_id = ? WHERE parent_id = ?", [$target['id'], $cid]); // alt kategoriler birleşilen kategoriye geçer
            db_query("DELETE FROM categories WHERE id = ?", [$cid]);
            remember_old_slug('category', $cat['slug'], $target['id']);
        } else {
            $slug = unique_slug('categories', $name, $cid);
            // NadirKitap'ta üst kategorisi varsa onun altına alınır (önerilen ad kullanıldıysa)
            $code = trim($cat['name']);
            $parent_code = isset($nk_table[$code]) && $nk_table[$code][0] === $name ? $nk_table[$code][1] : null;
            $parent_id = $parent_code !== null ? nk_category_ensure($parent_code) : null;
            if ($parent_id === $cid) $parent_id = null;
            db_query("UPDATE categories SET name = ?, slug = ?" . ($parent_id ? ", parent_id = " . (int)$parent_id : '') . " WHERE id = ?", [$name, $slug, $cid]);
            if ($cat['slug'] !== $slug) remember_old_slug('category', $cat['slug'], $cid);
        }
        $applied++;
    }
    $pdo->commit();
    log_action('NadirKitap Kategorileri Adlandırıldı', 'Catalog', null, $applied . ' kategori');
    set_flash('success', $applied . ' kategori adlandırıldı / birleştirildi.');
    redirect('index.php?p=NkCategories');
}

$existing_names = array_column(array_filter(db_fetch_all("SELECT name FROM categories ORDER BY name"), function ($c) { return !is_numeric_category_name($c['name']); }), 'name');
?>

<div class="mb-4">
    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-tags text-danger me-2"></i>NadirKitap Kategori Adları</h5>
    <p class="text-muted fs-7 mb-0">Aktarım uygulaması kategoriyi yalnızca NadirKitap numarasıyla gönderiyor. Önerilen adlar NadirKitap'ın kategori listesinden (<?= count($nk_table) ?> kategori) gelir. <b>Siz onaylamadan hiçbir kategori değişmez.</b> Aynı adlı kategori varsa onunla birleştirilir; yoksa kategori yeniden adlandırılıp NadirKitap'taki üst kategorisinin altına alınır (ör. Roman → Edebiyat). Numara hatırlanır, sonraki aktarımlar doğru kategoriye gider; yeni numaralar artık doğrudan adıyla oluşur.</p>
</div>

<?php if (!$numeric): ?>
    <div class="alert alert-success">Numarayla görünen kategori kalmadı.</div>
<?php else: ?>

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
                                    <?php if ($p['name'] !== ''): ?><span class="badge bg-success">NadirKitap'ta bulundu</span><?= $p['parent'] !== '' ? ' <span class="text-muted">üst: ' . e($p['parent']) . '</span>' : '' ?>
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
