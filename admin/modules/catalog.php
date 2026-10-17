<?php
/**
 * ADMIN MODULE: Catalog (p=Catalog) - Yazar, Yayınevi ve Kategori yönetimi
 */
if (!defined('SITE_URL')) exit;

$catalog_types = [
    'authors'    => ['label' => 'Yazarlar',    'single' => 'Yazar',    'column' => 'author_id',    'icon' => 'bi-person-lines-fill'],
    'publishers' => ['label' => 'Yayınevleri', 'single' => 'Yayınevi', 'column' => 'publisher_id', 'icon' => 'bi-building'],
    'categories' => ['label' => 'Kategoriler', 'single' => 'Kategori', 'column' => 'category_id',  'icon' => 'bi-tags'],
];

// Sitedeki kategori menüsünde gösterilen simgeler
$category_icons = [
    'bi-book' => 'Kitap', 'bi-book-half' => 'Açık Kitap', 'bi-journal-bookmark' => 'Ayraçlı Defter', 'bi-hourglass-split' => 'Kum Saati',
    'bi-lightbulb' => 'Ampul', 'bi-balloon' => 'Balon', 'bi-heart' => 'Kalp', 'bi-globe' => 'Dünya', 'bi-palette' => 'Palet',
    'bi-music-note-beamed' => 'Nota', 'bi-mortarboard' => 'Kep', 'bi-briefcase' => 'Çanta', 'bi-cup-hot' => 'Fincan',
    'bi-flower1' => 'Çiçek', 'bi-moon-stars' => 'Ay ve Yıldız', 'bi-translate' => 'Dil', 'bi-pencil' => 'Kalem', 'bi-star' => 'Yıldız',
];

$tab = $_GET['tab'] ?? 'authors';
if (!isset($catalog_types[$tab])) {
    $tab = 'authors';
}
$type = $catalog_types[$tab];
$column = $type['column'];
$base_url = 'index.php?p=Catalog&tab=' . $tab;

$book_count = function ($id) use ($column) {
    return (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE {$column} = ?", [(int)$id])['cnt'] ?? 0);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $item = $id > 0 ? db_fetch("SELECT * FROM {$tab} WHERE id = ?", [$id]) : null;

    if (isset($_POST['save_item'])) {
        $name = trim(preg_replace('/\s+/u', ' ', sanitize($_POST['name'] ?? '')));
        $dup = $name !== '' ? find_by_name($tab, $name, $id) : null;

        if ($name === '') {
            set_flash('danger', $type['single'] . ' adı boş bırakılamaz.');
            redirect($base_url . ($id > 0 ? '&edit=' . $id : ''));
        } elseif ($dup) {
            set_flash('danger', '"' . $dup['name'] . '" adıyla zaten bir kayıt var. Aynı kayıtları tek kayıtta toplamak için listeden "Birleştir"i kullanın.');
            redirect($base_url . ($id > 0 ? '&edit=' . $id : ''));
        }

        $fields = ['name' => $name, 'slug' => unique_slug($tab, $name, $id)];
        if ($tab === 'authors') {
            $fields['bio'] = sanitize($_POST['bio'] ?? '');
        } elseif ($tab === 'categories') {
            $icon = $_POST['icon'] ?? 'bi-book';
            $fields['icon'] = isset($category_icons[$icon]) ? $icon : 'bi-book';
            // Yalnızca ana kategoriler üst kategori olabilir; böylece döngü oluşmaz
            $parent_id = (int)($_POST['parent_id'] ?? 0);
            $parent_ok = $parent_id > 0 && $parent_id !== $id && db_fetch("SELECT id FROM categories WHERE id = ? AND parent_id IS NULL", [$parent_id]);
            $has_children = $id > 0 && db_fetch("SELECT id FROM categories WHERE parent_id = ?", [$id]);
            $fields['parent_id'] = ($parent_ok && !$has_children) ? $parent_id : null;
        }

        if ($item) {
            $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($fields)));
            db_query("UPDATE {$tab} SET {$set} WHERE id = ?", array_merge(array_values($fields), [$id]));
            log_action($type['single'] . ' Güncellendi', 'Catalog', $id, ($item['name'] !== $name ? "{$item['name']} → " : '') . $name);
            set_flash('success', '"' . $name . '" güncellendi.');
        } else {
            $cols = implode(', ', array_keys($fields));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            db_query("INSERT INTO {$tab} ({$cols}) VALUES ({$marks})", array_values($fields));
            log_action('Yeni ' . $type['single'] . ' Eklendi', 'Catalog', db_last_id(), $name);
            set_flash('success', '"' . $name . '" eklendi.');
        }
        redirect($base_url);
    }

    if (isset($_POST['delete_item']) && $item) {
        $count = $book_count($id);
        $children = $tab === 'categories' ? (int)(db_fetch("SELECT COUNT(*) as cnt FROM categories WHERE parent_id = ?", [$id])['cnt'] ?? 0) : 0;
        // Kitabı olan kayıt silinmez: veritabanı ilişkisi kitapları da silebilir ya da kitaplar yazarsız kalır
        if ($count > 0) {
            set_flash('danger', '"' . $item['name'] . '" ' . $count . ' kitapta kullanıldığı için silinemez. Önce kitapları başka bir kayda taşıyın ya da "Birleştir"i kullanın.');
        } elseif ($children > 0) {
            set_flash('danger', '"' . $item['name'] . '" kategorisinin alt kategorileri olduğu için silinemez.');
        } else {
            db_query("DELETE FROM {$tab} WHERE id = ?", [$id]);
            log_action($type['single'] . ' Silindi', 'Catalog', $id, $item['name']);
            set_flash('success', '"' . $item['name'] . '" silindi.');
        }
        redirect($base_url);
    }

    if (isset($_POST['merge_item']) && $item) {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $target = ($target_id > 0 && $target_id !== $id) ? db_fetch("SELECT * FROM {$tab} WHERE id = ?", [$target_id]) : null;
        if (!$target) {
            set_flash('danger', 'Birleştirmek için listeden başka bir kayıt seçin.');
            redirect($base_url);
        }

        global $pdo;
        $pdo->beginTransaction();
        $moved = $book_count($id);
        db_query("UPDATE books SET {$column} = ? WHERE {$column} = ?", [$target_id, $id]);
        if ($tab === 'categories') {
            db_query("UPDATE categories SET parent_id = ? WHERE parent_id = ? AND id != ?", [$target_id, $id, $target_id]);
            db_query("UPDATE categories SET parent_id = NULL WHERE id = ? AND parent_id = ?", [$target_id, $id]);
        }
        if ($tab === 'authors' && trim((string)$target['bio']) === '' && trim((string)$item['bio']) !== '') {
            db_query("UPDATE authors SET bio = ? WHERE id = ?", [$item['bio'], $target_id]); // biyografi kaybolmasın
        }
        db_query("DELETE FROM {$tab} WHERE id = ?", [$id]);
        $pdo->commit();

        log_action($type['single'] . ' Birleştirildi', 'Catalog', $target_id, "{$item['name']} → {$target['name']} ({$moved} kitap taşındı)");
        set_flash('success', '"' . $item['name'] . '" kaydı "' . $target['name'] . '" ile birleştirildi; ' . $moved . ' kitap taşındı.');
        redirect($base_url);
    }

    redirect($base_url);
}

$extra_select = $tab === 'categories'
    ? ", (SELECT COUNT(*) FROM categories ch WHERE ch.parent_id = t.id) AS child_count, (SELECT pc.name FROM categories pc WHERE pc.id = t.parent_id) AS parent_name"
    : '';
$items = db_fetch_all("SELECT t.*, (SELECT COUNT(*) FROM books b WHERE b.{$column} = t.id) AS book_count {$extra_select} FROM {$tab} t ORDER BY t.name ASC");

$tab_counts = [];
foreach (array_keys($catalog_types) as $tname) {
    $tab_counts[$tname] = (int)(db_fetch("SELECT COUNT(*) as cnt FROM {$tname}")['cnt'] ?? 0);
}

$edit_id = (int)($_GET['edit'] ?? 0);
$edit = $edit_id > 0 ? db_fetch("SELECT * FROM {$tab} WHERE id = ?", [$edit_id]) : null;
$parent_options = $tab === 'categories'
    ? db_fetch_all("SELECT id, name FROM categories WHERE parent_id IS NULL AND id != ? ORDER BY name ASC", [$edit['id'] ?? 0])
    : [];
$edit_has_children = $edit && $tab === 'categories' && db_fetch("SELECT id FROM categories WHERE parent_id = ?", [$edit['id']]);
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Yazar, Yayınevi & Kategori Yönetimi</h5>
        <p class="text-muted fs-7 mb-0">Kayıt ekleyebilir, isim düzeltebilir, aynı kaydın kopyalarını birleştirebilir ve kullanılmayan kayıtları silebilirsiniz.</p>
    </div>
</div>

<ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm border">
    <?php foreach ($catalog_types as $tname => $t): ?>
        <li class="nav-item">
            <a class="nav-link fw-semibold <?= $tab === $tname ? 'active bg-danger text-white' : 'text-secondary' ?>" href="index.php?p=Catalog&tab=<?= $tname ?>">
                <i class="bi <?= $t['icon'] ?> me-1"></i> <?= $t['label'] ?> <span class="badge <?= $tab === $tname ? 'bg-white text-danger' : 'bg-light text-secondary' ?> ms-1"><?= $tab_counts[$tname] ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom p-4">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi <?= $edit ? 'bi-pencil-square' : 'bi-plus-circle' ?> text-danger me-2"></i>
                    <?= $edit ? $type['single'] . ' Düzenle' : 'Yeni ' . $type['single'] . ' Ekle' ?>
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= e($base_url) ?>">
                    <input type="hidden" name="save_item" value="1">
                    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7"><?= $type['single'] ?> Adı *</label>
                        <input type="text" name="name" class="form-control" value="<?= e($edit['name'] ?? '') ?>" maxlength="120" required>
                    </div>

                    <?php if ($tab === 'authors'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7">Biyografi</label>
                            <textarea name="bio" class="form-control" rows="5" placeholder="Kitap sayfasında &quot;Yazar Hakkında&quot; bölümünde gösterilir."><?= e($edit['bio'] ?? '') ?></textarea>
                        </div>
                    <?php elseif ($tab === 'categories'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7">Menü Simgesi</label>
                            <select name="icon" class="form-select" id="categoryIcon">
                                <?php foreach ($category_icons as $icon => $icon_label): ?>
                                    <option value="<?= $icon ?>" <?= ($edit['icon'] ?? 'bi-book') === $icon ? 'selected' : '' ?>><?= e($icon_label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-2 fs-7 text-secondary">Önizleme: <i class="bi <?= e($edit['icon'] ?? 'bi-book') ?> text-danger fs-5 align-middle" id="categoryIconPreview"></i></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7">Üst Kategori</label>
                            <select name="parent_id" class="form-select" <?= $edit_has_children ? 'disabled' : '' ?>>
                                <option value="0">— Yok (ana kategori) —</option>
                                <?php foreach ($parent_options as $po): ?>
                                    <option value="<?= $po['id'] ?>" <?= (int)($edit['parent_id'] ?? 0) === (int)$po['id'] ? 'selected' : '' ?>><?= e($po['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($edit_has_children): ?><div class="form-text">Alt kategorileri olduğu için bu kategori ana kategori olarak kalır.</div><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger font-semibold px-4">
                            <i class="bi bi-check-circle me-1"></i> <?= $edit ? 'Kaydet' : 'Ekle' ?>
                        </button>
                        <?php if ($edit): ?>
                            <a href="<?= e($base_url) ?>" class="btn btn-light border">Vazgeç</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light fs-7">
                            <tr>
                                <th class="ps-4"><?= $type['single'] ?></th>
                                <th>Kitap</th>
                                <th class="pe-4 text-end">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="3" class="text-center py-5 text-muted">Henüz kayıt yok.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($items as $it): ?>
                                <?php
                                $books_link = $tab === 'categories'
                                    ? 'index.php?p=Products&category_id=' . $it['id']
                                    : 'index.php?p=Products&q=' . urlencode($it['name']);
                                $can_delete = (int)$it['book_count'] === 0 && (int)($it['child_count'] ?? 0) === 0;
                                ?>
                                <tr class="<?= $edit && (int)$edit['id'] === (int)$it['id'] ? 'table-warning' : '' ?>">
                                    <td class="ps-4">
                                        <div class="fw-semibold text-dark">
                                            <?php if ($tab === 'categories'): ?><i class="bi <?= e($it['icon']) ?> text-danger me-1"></i><?php endif; ?>
                                            <?= e($it['name']) ?>
                                        </div>
                                        <?php if ($tab === 'authors'): ?>
                                            <small class="text-muted fs-8"><?= $it['bio'] !== null && trim($it['bio']) !== '' ? e(mb_strimwidth($it['bio'], 0, 90, '…', 'UTF-8')) : '<em>Biyografi yok</em>' ?></small>
                                        <?php elseif ($tab === 'categories' && !empty($it['parent_name'])): ?>
                                            <small class="text-muted fs-8">Üst kategori: <?= e($it['parent_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$it['book_count'] > 0): ?>
                                            <a href="<?= e($books_link) ?>" class="badge bg-light text-dark border text-decoration-none fs-8"><?= (int)$it['book_count'] ?> kitap</a>
                                        <?php else: ?>
                                            <span class="text-muted fs-8">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= e($base_url . '&edit=' . $it['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Düzenle"><i class="bi bi-pencil"></i></a>

                                            <?php if (count($items) > 1): ?>
                                                <details class="position-relative d-inline-block">
                                                    <summary class="btn btn-sm btn-outline-primary" style="list-style: none;" title="Başka bir kayıtla birleştir"><i class="bi bi-intersect"></i></summary>
                                                    <form method="POST" action="<?= e($base_url) ?>" class="position-absolute end-0 mt-1 p-3 bg-white border rounded-3 shadow text-start" style="z-index: 10; width: 280px;"
                                                          onsubmit="return confirm('&quot;<?= e(addslashes($it['name'])) ?>&quot; kaydının kitapları seçtiğiniz kayda taşınacak ve bu kayıt silinecek. Emin misiniz?');">
                                                        <input type="hidden" name="merge_item" value="1">
                                                        <input type="hidden" name="id" value="<?= $it['id'] ?>">
                                                        <label class="form-label fs-8 fw-semibold mb-1">"<?= e($it['name']) ?>" şununla birleştirilsin:</label>
                                                        <select name="target_id" class="form-select form-select-sm mb-2" required>
                                                            <option value="">Seçiniz…</option>
                                                            <?php foreach ($items as $other): if ((int)$other['id'] === (int)$it['id']) continue; ?>
                                                                <option value="<?= $other['id'] ?>"><?= e($other['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-primary w-100">Birleştir</button>
                                                    </form>
                                                </details>
                                            <?php endif; ?>

                                            <?php if ($can_delete): ?>
                                                <form method="POST" action="<?= e($base_url) ?>" class="d-inline-block" onsubmit="return confirm('&quot;<?= e(addslashes($it['name'])) ?>&quot; silinsin mi?');">
                                                    <input type="hidden" name="delete_item" value="1">
                                                    <input type="hidden" name="id" value="<?= $it['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Kitapları veya alt kategorileri olduğu için silinemez"><i class="bi bi-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($tab === 'categories'): ?>
<script>
    document.getElementById('categoryIcon').addEventListener('change', e => {
        document.getElementById('categoryIconPreview').className = 'bi ' + e.target.value + ' text-danger fs-5 align-middle';
    });
</script>
<?php endif; ?>
