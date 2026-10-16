<?php
/**
 * ADMIN MODULE: Products (p=Products)
 */
if (!defined('SITE_URL')) exit;

$action = sanitize($_GET['action'] ?? 'list');
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$authors = db_fetch_all("SELECT * FROM authors ORDER BY name ASC");
$publishers = db_fetch_all("SELECT * FROM publishers ORDER BY name ASC");
$categories = db_fetch_all("SELECT * FROM categories ORDER BY name ASC");
$showcases = db_fetch_all("SELECT * FROM showcases ORDER BY id ASC");
$collections = db_fetch_all("SELECT * FROM collections ORDER BY id ASC");

if ($action === 'delete' && $edit_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $book = db_fetch("SELECT title FROM books WHERE id = ?", [$edit_id]);
    if ($book) {
        $has_orders = db_fetch("SELECT COUNT(*) as cnt FROM order_items WHERE book_id = ?", [$edit_id])['cnt'] ?? 0;
        if ($has_orders > 0) {
            // Siparişlerde geçen kitap silinirse eski faturalarda kitap adı kaybolur; bunun yerine satıştan kaldır
            db_query("UPDATE books SET is_active = 0 WHERE id = ?", [$edit_id]);
            log_action('Kitap Pasife Alındı', 'Products', $edit_id, "Siparişlerde geçtiği için silinmedi, pasife alındı: " . $book['title']);
            set_flash('warning', '"' . $book['title'] . '" geçmiş siparişlerde yer aldığı için silinmedi, satıştan kaldırıldı (Pasif).');
        } else {
            db_query("DELETE FROM book_showcase WHERE book_id = ?", [$edit_id]);
            db_query("DELETE FROM book_collection WHERE book_id = ?", [$edit_id]);
            db_query("DELETE FROM books WHERE id = ?", [$edit_id]);
            log_action('Kitap Silindi', 'Products', $edit_id, "Kitap silindi: " . $book['title']);
            set_flash('success', '"' . $book['title'] . '" kitabı silindi.');
        }
    }
    redirect('index.php?p=Products');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_book'])) {
    $title          = sanitize($_POST['title']);
    $original_title = sanitize($_POST['original_title'] ?? '');
    $isbn           = sanitize($_POST['isbn'] ?? '');
    $author_id      = (int)$_POST['author_id'];
    $publisher_id   = (int)$_POST['publisher_id'];
    $category_id    = (int)$_POST['category_id'];
    $price          = (float)$_POST['price'];
    $old_price      = !empty($_POST['old_price']) ? (float)$_POST['old_price'] : null;
    $stock          = (int)$_POST['stock'];
    $pages          = !empty($_POST['pages']) ? (int)$_POST['pages'] : null;
    $year           = !empty($_POST['year']) ? (int)$_POST['year'] : null;
    $summary        = sanitize($_POST['summary'] ?? '');
    $is_active      = isset($_POST['is_active']) ? 1 : 0;
    // Formda kapak alanı yok; düzenlemede mevcut kapak korunur
    $existing_cover = $edit_id > 0 ? (db_fetch("SELECT cover_image FROM books WHERE id = ?", [$edit_id])['cover_image'] ?? null) : null;
    $cover_image    = sanitize($_POST['cover_image'] ?? ($existing_cover ?: 'book_placeholder.svg'));

    $selected_showcases   = $_POST['showcases'] ?? [];
    $selected_collections = $_POST['collections'] ?? [];

    if ($edit_id > 0) {
        $sql = "UPDATE books SET title = ?, original_title = ?, isbn = ?, author_id = ?, publisher_id = ?, category_id = ?, price = ?, old_price = ?, stock = ?, pages = ?, year = ?, summary = ?, cover_image = ?, is_active = ? WHERE id = ?";
        db_query($sql, [$title, $original_title, $isbn, $author_id, $publisher_id, $category_id, $price, $old_price, $stock, $pages, $year, $summary, $cover_image, $is_active, $edit_id]);
        $book_id = $edit_id;
        log_action('Kitap Düzenlendi', 'Products', $book_id, "Kitap güncellendi: {$title}");
        set_flash('success', 'Kitap bilgileri güncellendi.');
    } else {
        $sql = "INSERT INTO books (title, original_title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        db_query($sql, [$title, $original_title, $isbn, $author_id, $publisher_id, $category_id, $price, $old_price, $stock, $pages, $year, $summary, $cover_image, $is_active]);
        $book_id = db_last_id();
        log_action('Yeni Kitap Eklendi', 'Products', $book_id, "Yeni kitap oluşturuldu: {$title}");
        set_flash('success', 'Yeni kitap kataloğa eklendi.');
    }

    db_query("DELETE FROM book_showcase WHERE book_id = ?", [$book_id]);
    foreach ($selected_showcases as $sc_id) {
        db_query("INSERT INTO book_showcase (book_id, showcase_id) VALUES (?, ?)", [$book_id, (int)$sc_id]);
    }

    db_query("DELETE FROM book_collection WHERE book_id = ?", [$book_id]);
    foreach ($selected_collections as $col_id) {
        db_query("INSERT INTO book_collection (book_id, collection_id) VALUES (?, ?)", [$book_id, (int)$col_id]);
    }

    redirect('index.php?p=Products');
}

$book_data = null;
$current_showcases = [];
$current_collections = [];

if (($action === 'add' || $action === 'edit') && $edit_id > 0) {
    $book_data = db_fetch("SELECT * FROM books WHERE id = ?", [$edit_id]);
    if ($book_data) {
        $sc_rows = db_fetch_all("SELECT showcase_id FROM book_showcase WHERE book_id = ?", [$edit_id]);
        $current_showcases = array_column($sc_rows, 'showcase_id');

        $col_rows = db_fetch_all("SELECT collection_id FROM book_collection WHERE book_id = ?", [$edit_id]);
        $current_collections = array_column($col_rows, 'collection_id');
    }
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Kitap Kataloğu ve Vitrin Yönetimi</h5>
        <p class="text-muted fs-7 mb-0">Kitapları ekleyebilir, stok/fiyat güncelleyebilir ve vitrin/koleksiyon etiketleri atayabilirsiniz.</p>
    </div>
    <?php if ($action === 'list'): ?>
        <a href="index.php?p=Products&action=add" class="btn btn-danger font-semibold rounded-3 shadow">
            <i class="bi bi-plus-lg me-1"></i> Yeni Kitap Ekle
        </a>
    <?php else: ?>
        <a href="index.php?p=Products" class="btn btn-outline-secondary font-semibold rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Kataloğa Dön
        </a>
    <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom p-4">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-pencil-square text-danger me-2"></i>
                <?= $edit_id > 0 ? 'Kitap Bilgilerini Düzenle' : 'Yeni Kitap Ekle' ?>
            </h5>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="">
                <input type="hidden" name="save_book" value="1">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Kitap Adı *</label>
                        <input type="text" name="title" class="form-control" value="<?= e($book_data['title'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Orijinal Adı</label>
                        <input type="text" name="original_title" class="form-control" value="<?= e($book_data['original_title'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">ISBN Numarası</label>
                        <input type="text" name="isbn" class="form-control" value="<?= e($book_data['isbn'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Yazar *</label>
                        <select name="author_id" class="form-select" required>
                            <option value="">-- Yazar Seçiniz --</option>
                            <?php foreach ($authors as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= ($book_data['author_id'] ?? 0) == $a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Yayınevi *</label>
                        <select name="publisher_id" class="form-select" required>
                            <option value="">-- Yayınevi Seçiniz --</option>
                            <?php foreach ($publishers as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($book_data['publisher_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Kategori *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">-- Kategori Seçiniz --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($book_data['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Satış Fiyatı (TL) *</label>
                        <input type="number" step="0.01" name="price" class="form-control" value="<?= $book_data['price'] ?? '' ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Eski Fiyat (İndirimsiz - TL)</label>
                        <input type="number" step="0.01" name="old_price" class="form-control" value="<?= $book_data['old_price'] ?? '' ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Stok Adedi *</label>
                        <input type="number" name="stock" class="form-control" value="<?= $book_data['stock'] ?? 50 ?>" required min="0">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Sayfa Sayısı</label>
                        <input type="number" name="pages" class="form-control" value="<?= $book_data['pages'] ?? '' ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Basım Yılı</label>
                        <input type="number" name="year" class="form-control" value="<?= $book_data['year'] ?? date('Y') ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold fs-7">Kitap Özeti</label>
                        <textarea name="summary" class="form-control" rows="4"><?= e($book_data['summary'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-6 border-end pr-md-4">
                        <label class="form-label fw-bold text-dark fs-7 d-block mb-2">
                            <i class="bi bi-stars text-danger me-1"></i> Vitrin Etiketleri
                        </label>
                        <div class="bg-light p-3 rounded-3 border">
                            <?php foreach ($showcases as $sc): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="showcases[]" value="<?= $sc['id'] ?>" id="sc_<?= $sc['id'] ?>" <?= in_array($sc['id'], $current_showcases) ? 'checked' : '' ?>>
                                    <label class="form-check-label fs-7 fw-semibold" for="sc_<?= $sc['id'] ?>">
                                        <?= e($sc['title']) ?> <small class="text-muted">(<?= e($sc['description']) ?>)</small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark fs-7 d-block mb-2">
                            <i class="bi bi-bookmarks-fill text-danger me-1"></i> Tematik Koleksiyonlar
                        </label>
                        <div class="bg-light p-3 rounded-3 border">
                            <?php foreach ($collections as $col): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="collections[]" value="<?= $col['id'] ?>" id="col_<?= $col['id'] ?>" <?= in_array($col['id'], $current_collections) ? 'checked' : '' ?>>
                                    <label class="form-check-label fs-7 fw-semibold" for="col_<?= $col['id'] ?>">
                                        <?= e($col['title']) ?> <span class="badge bg-danger text-white ms-1 fs-8"><?= e($col['badge_text']) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= ($book_data['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold fs-7" for="is_active">Kitap Vitrinde Aktif Satışta Olsun</label>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top d-flex gap-2">
                    <button type="submit" class="btn btn-danger font-semibold px-4 py-2 shadow">
                        <i class="bi bi-check-circle me-1"></i> Kaydet
                    </button>
                    <a href="index.php?p=Products" class="btn btn-light border px-4 py-2">İptal</a>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <?php
    $search = sanitize($_GET['q'] ?? '');
    $cat_filter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(b.title LIKE ? OR b.isbn LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($cat_filter > 0) {
        $where[] = "b.category_id = ?";
        $params[] = $cat_filter;
    }

    $where_sql = implode(' AND ', $where);

    $books_list = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name, c.name as category_name 
                                FROM books b 
                                LEFT JOIN authors a ON b.author_id = a.id 
                                LEFT JOIN publishers p ON b.publisher_id = p.id 
                                LEFT JOIN categories c ON b.category_id = c.id 
                                WHERE {$where_sql}
                                ORDER BY b.id DESC", $params);
    ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 p-4">
            <form method="GET" action="" class="row g-2 align-items-center">
                <input type="hidden" name="p" value="Products">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control bg-light border-start-0" placeholder="Kitap adı veya ISBN..." value="<?= e($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="category_id" class="form-select bg-light">
                        <option value="0">Tüm Kategoriler</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat_filter == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 fw-semibold">Filtrele</button>
                    <?php if (!empty($search) || $cat_filter > 0): ?>
                        <a href="index.php?p=Products" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Kitap Detayları</th>
                            <th>Yazar / Yayınevi</th>
                            <th>Kategori</th>
                            <th>Fiyat</th>
                            <th>Stok</th>
                            <th>Durum</th>
                            <th class="text-end pe-4">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($books_list)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">Arama kriterlerine uygun kitap bulunamadı.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($books_list as $b): ?>
                                <tr>
                                    <td class="ps-4 text-muted fs-7">#<?= $b['id'] ?></td>
                                    <td>
                                        <div class="fw-bold text-dark fs-7"><?= e($b['title']) ?></div>
                                        <small class="text-muted fs-8">ISBN: <?= e($b['isbn'] ?? '-') ?></small>
                                    </td>
                                    <td>
                                        <div class="fs-7 font-semibold"><?= e($b['author_name']) ?></div>
                                        <small class="text-secondary fs-8"><?= e($b['publisher_name']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border fs-8"><?= e($b['category_name']) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-extrabold text-danger fs-7"><?= format_price($b['price']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($b['stock'] < 10): ?>
                                            <span class="badge bg-danger text-white fs-8"><?= $b['stock'] ?> Adet</span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success fs-8"><?= $b['stock'] ?> Adet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($b['is_active']): ?>
                                            <span class="badge bg-success fs-8">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary fs-8">Pasif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="index.php?p=Products&action=edit&id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <form method="POST" action="index.php?p=Products&action=delete&id=<?= $b['id'] ?>" class="d-inline" onsubmit="return confirm('Bu kitabı silmek istediğinize emin misiniz?');">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
