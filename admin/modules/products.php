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
$stores_all = db_fetch_all("SELECT id, name FROM stores ORDER BY name ASC");

/**
 * Formda "+ Yeni ... ekle" seçildiyse yazarı/yayınevini/kategoriyi oluşturur (aynı isim varsa onu kullanır); kaydın ID'sini döndürür
 */
function resolve_or_create($table, $selected, $new_name) {
    if ($selected !== '__new') {
        return (int)$selected;
    }
    $name = trim(preg_replace('/\s+/u', ' ', $new_name));
    if ($name === '') {
        return 0;
    }
    $existing = find_by_name($table, $name);
    if ($existing) {
        return (int)$existing['id'];
    }
    $slug = unique_slug($table, $name);

    db_query("INSERT INTO {$table} (name, slug) VALUES (?, ?)", [$name, $slug]);
    $id = (int)db_last_id();
    $labels = ['authors' => 'Yeni Yazar Eklendi', 'publishers' => 'Yeni Yayınevi Eklendi', 'categories' => 'Yeni Kategori Eklendi'];
    log_action($labels[$table], 'Catalog', $id, $name);
    return $id;
}
$collections = db_fetch_all("SELECT * FROM collections ORDER BY id ASC");

if ($action === 'delete' && $edit_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $book = db_fetch("SELECT title, cover_image FROM books WHERE id = ?", [$edit_id]);
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
            if (!empty($book['cover_image']) && strpos($book['cover_image'], 'cover_') === 0) {
                @unlink(UPLOAD_DIR . '/' . basename($book['cover_image']));
            }
            log_action('Kitap Silindi', 'Products', $edit_id, "Kitap silindi: " . $book['title']);
            set_flash('success', '"' . $book['title'] . '" kitabı silindi.');
        }
    }
    redirect('index.php?p=Products');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_book'])) {
    $title          = sanitize($_POST['title']);
    $original_title = sanitize($_POST['original_title'] ?? '');
    $isbn           = sanitize($_POST['isbn'] ?? '') ?: null; // boş ISBN NULL olmalı, yoksa ikinci ISBN'siz kitapta benzersizlik hatası olur
    $author_id      = resolve_or_create('authors', $_POST['author_id'] ?? '', sanitize($_POST['new_author_name'] ?? ''));
    $publisher_id   = resolve_or_create('publishers', $_POST['publisher_id'] ?? '', sanitize($_POST['new_publisher_name'] ?? ''));
    $category_id    = resolve_or_create('categories', $_POST['category_id'] ?? '', sanitize($_POST['new_category_name'] ?? ''));
    $price          = (float)$_POST['price'];
    $old_price      = !empty($_POST['old_price']) ? (float)$_POST['old_price'] : null;
    $stock          = (int)$_POST['stock'];
    $pages          = !empty($_POST['pages']) ? (int)$_POST['pages'] : null;
    $year           = !empty($_POST['year']) ? (int)$_POST['year'] : null;
    $summary        = sanitize($_POST['summary'] ?? '');
    $shelf_code     = sanitize($_POST['shelf_code'] ?? '') ?: null;
    $condition_text = sanitize($_POST['condition_text'] ?? '') ?: null;
    $cover_type     = sanitize($_POST['cover_type'] ?? '') ?: null;
    $dimensions     = sanitize($_POST['dimensions'] ?? '') ?: null;
    $language       = sanitize($_POST['language'] ?? '') ?: null;
    $translator     = sanitize($_POST['translator'] ?? '') ?: null;
    $publish_place  = sanitize($_POST['publish_place'] ?? '') ?: null;
    $store_id       = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;
    $is_active      = isset($_POST['is_active']) ? 1 : 0;

    // Alt dükkan (sahaf) kitaplarının fiyatını satıcı belirler; panelden düzenlenirken mevcut fiyat korunur
    $locked_book = $edit_id > 0 ? db_fetch("SELECT price, old_price, store_id FROM books WHERE id = ?", [$edit_id]) : null;
    if ($locked_book && !empty($locked_book['store_id'])) {
        $price     = (float)$locked_book['price'];
        $old_price = $locked_book['old_price'] !== null ? (float)$locked_book['old_price'] : null;
    }

    if ($author_id <= 0 || $publisher_id <= 0 || $category_id <= 0) {
        set_flash('danger', 'Lütfen yazar, yayınevi ve kategori seçin ya da yeni adını yazın. Kitap kaydedilmedi.');
        redirect('index.php?p=Products&action=' . ($edit_id > 0 ? 'edit&id=' . $edit_id : 'add'));
    }

    $dup = $isbn !== null ? db_fetch("SELECT id FROM books WHERE isbn = ? AND id != ?", [$isbn, $edit_id]) : null;
    if ($dup) {
        set_flash('danger', "Bu ISBN ({$isbn}) başka bir kitapta (#{$dup['id']}) kayıtlı. Kitap kaydedilmedi.");
        redirect('index.php?p=Products&action=' . ($edit_id > 0 ? 'edit&id=' . $edit_id : 'add'));
    }

    // Yeni kapak yüklenmediyse mevcut kapak korunur; "kapağı kaldır" seçildiyse varsayılan görsele döner
    $existing_cover = $edit_id > 0 ? (db_fetch("SELECT cover_image FROM books WHERE id = ?", [$edit_id])['cover_image'] ?? null) : null;
    $cover_image    = $existing_cover ?: 'book_placeholder.svg';
    if (!empty($_POST['remove_cover'])) {
        $cover_image = 'book_placeholder.svg';
    }
    $upload_error = '';
    $uploaded = save_cover_upload($_FILES['cover_file'] ?? [], $upload_error);
    if ($uploaded) {
        $cover_image = $uploaded;
    }
    // Eski yüklenmiş kapak artık kullanılmıyorsa diskten silinir
    if ($existing_cover && $existing_cover !== $cover_image && strpos($existing_cover, 'cover_') === 0 && is_file(UPLOAD_DIR . '/' . basename($existing_cover))) {
        @unlink(UPLOAD_DIR . '/' . basename($existing_cover));
    }

    $selected_showcases   = $_POST['showcases'] ?? [];
    $selected_collections = $_POST['collections'] ?? [];

    if ($edit_id > 0) {
        $sql = "UPDATE books SET title = ?, original_title = ?, isbn = ?, author_id = ?, publisher_id = ?, category_id = ?, price = ?, old_price = ?, stock = ?, pages = ?, year = ?, summary = ?, cover_image = ?, is_active = ?, store_id = ?, shelf_code = ?, condition_text = ?, cover_type = ?, dimensions = ?, language = ?, translator = ?, publish_place = ? WHERE id = ?";
        db_query($sql, [$title, $original_title, $isbn, $author_id, $publisher_id, $category_id, $price, $old_price, $stock, $pages, $year, $summary, $cover_image, $is_active, $store_id, $shelf_code, $condition_text, $cover_type, $dimensions, $language, $translator, $publish_place, $edit_id]);
        $book_id = $edit_id;
        log_action('Kitap Düzenlendi', 'Products', $book_id, "Kitap güncellendi: {$title}");
        set_flash('success', 'Kitap bilgileri güncellendi.');
    } else {
        $sql = "INSERT INTO books (title, original_title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active, store_id, shelf_code, condition_text, cover_type, dimensions, language, translator, publish_place) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        db_query($sql, [$title, $original_title, $isbn, $author_id, $publisher_id, $category_id, $price, $old_price, $stock, $pages, $year, $summary, $cover_image, $is_active, $store_id, $shelf_code, $condition_text, $cover_type, $dimensions, $language, $translator, $publish_place]);
        $book_id = db_last_id();
        log_action('Yeni Kitap Eklendi', 'Products', $book_id, "Yeni kitap oluşturuldu: {$title}");
        set_flash('success', 'Yeni kitap kataloğa eklendi.');
    }
    if ($upload_error !== '') {
        set_flash('warning', 'Kitap kaydedildi ancak kapak resmi yüklenemedi: ' . $upload_error);
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
$price_locked = false;
$current_showcases = [];
$current_collections = [];

if (($action === 'add' || $action === 'edit') && $edit_id > 0) {
    $book_data = db_fetch("SELECT * FROM books WHERE id = ?", [$edit_id]);
    if ($book_data) {
        $price_locked = !empty($book_data['store_id']);
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
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="save_book" value="1">

                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold fs-7">Kapak Resmi</label>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 border">
                            <img src="../<?= e(cover_url($book_data['cover_image'] ?? '')) ?>" alt="Kapak" id="coverPreview" style="width: 80px; height: 115px; object-fit: cover;" class="rounded shadow-sm bg-white">
                            <div class="flex-grow-1">
                                <input type="file" name="cover_file" class="form-control" accept="image/jpeg,image/png,image/webp"
                                       onchange="if (this.files[0]) document.getElementById('coverPreview').src = URL.createObjectURL(this.files[0]);">
                                <small class="text-muted d-block mt-1">JPG, PNG veya WEBP, en fazla 5 MB. Önerilen oran 2:3 (ör. 600×900 piksel).</small>
                                <?php if (!empty($book_data['cover_image']) && $book_data['cover_image'] !== 'book_placeholder.svg'): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="remove_cover" value="1" id="removeCover">
                                        <label class="form-check-label fs-7" for="removeCover">Mevcut kapağı kaldır</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

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
                        <select name="author_id" class="form-select js-new-toggle" data-target="newAuthorName" required>
                            <option value="">-- Yazar Seçiniz --</option>
                            <?php foreach ($authors as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= ($book_data['author_id'] ?? 0) == $a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__new">+ Yeni yazar ekle…</option>
                        </select>
                        <input type="text" name="new_author_name" id="newAuthorName" class="form-control mt-2 d-none" placeholder="Yeni yazarın adı soyadı" maxlength="120">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Yayınevi *</label>
                        <select name="publisher_id" class="form-select js-new-toggle" data-target="newPublisherName" required>
                            <option value="">-- Yayınevi Seçiniz --</option>
                            <?php foreach ($publishers as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($book_data['publisher_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__new">+ Yeni yayınevi ekle…</option>
                        </select>
                        <input type="text" name="new_publisher_name" id="newPublisherName" class="form-control mt-2 d-none" placeholder="Yeni yayınevinin adı" maxlength="120">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Kategori *</label>
                        <select name="category_id" class="form-select js-new-toggle" data-target="newCategoryName" required>
                            <option value="">-- Kategori Seçiniz --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($book_data['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__new">+ Yeni kategori ekle…</option>
                        </select>
                        <input type="text" name="new_category_name" id="newCategoryName" class="form-control mt-2 d-none" placeholder="Yeni kategorinin adı" maxlength="120">
                        <div class="form-text"><a href="index.php?p=Catalog" target="_blank">Yazar, yayınevi ve kategorileri düzenle</a></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Satış Fiyatı (TL) *<?= $price_locked ? ' <i class="bi bi-lock-fill text-secondary"></i>' : '' ?></label>
                        <input type="number" step="0.01" name="price" class="form-control" value="<?= $book_data['price'] ?? '' ?>" required <?= $price_locked ? 'readonly' : '' ?>>
                        <?php if ($price_locked): ?>
                            <small class="text-muted">Alt dükkan kitabı: fiyatı satıcı belirler, panelden değiştirilemez.</small>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Eski Fiyat (İndirimsiz - TL)<?= $price_locked ? ' <i class="bi bi-lock-fill text-secondary"></i>' : '' ?></label>
                        <input type="number" step="0.01" name="old_price" class="form-control" value="<?= $book_data['old_price'] ?? '' ?>" <?= $price_locked ? 'readonly' : '' ?>>
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
                        <input type="number" name="year" class="form-control" value="<?= $book_data['year'] ?? '' ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Raf Kodu (Sadece Admin Görür)</label>
                        <input type="text" name="shelf_code" class="form-control" placeholder="Örn: 29/4kara, j6" value="<?= e($book_data['shelf_code'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Kondisyon</label>
                        <input type="text" name="condition_text" class="form-control" placeholder="Örn: Çok İyi, Yeni Gibi" value="<?= e($book_data['condition_text'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Cilt Durumu</label>
                        <input type="text" name="cover_type" class="form-control" placeholder="Örn: Karton Kapak, Ciltli" value="<?= e($book_data['cover_type'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Ebat</label>
                        <input type="text" name="dimensions" class="form-control" placeholder="Örn: 14 x 20 cm" value="<?= e($book_data['dimensions'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Dil</label>
                        <input type="text" name="language" class="form-control" placeholder="Örn: Türkçe" value="<?= e($book_data['language'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Çeviren / Hazırlayan</label>
                        <input type="text" name="translator" class="form-control" value="<?= e($book_data['translator'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Yayın Yeri</label>
                        <input type="text" name="publish_place" class="form-control" value="<?= e($book_data['publish_place'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Alt Dükkan (Satıcı)</label>
                        <select name="store_id" class="form-select">
                            <option value="">-- Ana Mağaza (Yeniçeri Kitabevi) --</option>
                            <?php foreach ($stores_all as $st): ?>
                                <option value="<?= $st['id'] ?>" <?= ($book_data['store_id'] ?? 0) == $st['id'] ? 'selected' : '' ?>>
                                    <?= e($st['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold fs-7">Kitap Özeti / Açıklama</label>
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
            <script>
                // "+ Yeni ... ekle" seçilince isim kutusu açılır ve zorunlu olur
                document.querySelectorAll('.js-new-toggle').forEach(select => {
                    const input = document.getElementById(select.dataset.target);
                    const update = () => {
                        const isNew = select.value === '__new';
                        input.classList.toggle('d-none', !isNew);
                        input.required = isNew;
                        if (isNew) input.focus();
                    };
                    select.addEventListener('change', update);
                });
            </script>
            <?php if ($action === 'add'): ?>
                <script src="isbn_helper/isbn-helper.js?v=1" defer></script>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <?php
    $search = sanitize($_GET['q'] ?? '');
    $cat_filter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $store_filter = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(b.title LIKE ? OR b.isbn LIKE ? OR a.name LIKE ? OR p.name LIKE ? OR b.shelf_code LIKE ? OR b.nadirkitap_code LIKE ?)";
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%");
    }
    if ($cat_filter > 0) {
        $where[] = "b.category_id = ?";
        $params[] = $cat_filter;
    }
    if ($store_filter > 0) {
        $where[] = "b.store_id = ?";
        $params[] = $store_filter;
    }

    $where_sql = implode(' AND ', $where);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 50;
    $total_books = (int)(db_fetch("SELECT COUNT(*) as cnt 
                                   FROM books b 
                                   LEFT JOIN authors a ON b.author_id = a.id 
                                   LEFT JOIN publishers p ON b.publisher_id = p.id 
                                   WHERE {$where_sql}", $params)['cnt'] ?? 0);
    $total_pages = max(1, (int)ceil($total_books / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $books_list = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name, c.name as category_name, s.name as store_name, s.slug as store_slug 
                                FROM books b 
                                LEFT JOIN authors a ON b.author_id = a.id 
                                LEFT JOIN publishers p ON b.publisher_id = p.id 
                                LEFT JOIN categories c ON b.category_id = c.id 
                                LEFT JOIN stores s ON b.store_id = s.id 
                                WHERE {$where_sql}
                                ORDER BY b.id DESC
                                LIMIT {$per_page} OFFSET {$offset}", $params);
    ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 p-4">
            <form method="GET" action="" class="row g-2 align-items-center">
                <input type="hidden" name="p" value="Products">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control bg-light border-start-0" placeholder="Kitap adı, Raf Kodu, ISBN, NK kodu..." value="<?= e($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="store_id" class="form-select bg-light">
                        <option value="0">Tüm Dükkanlar</option>
                        <?php foreach ($stores_all as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= $store_filter == $st['id'] ? 'selected' : '' ?>>Alt Dükkan: <?= e($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="category_id" class="form-select bg-light">
                        <option value="0">Tüm Kategoriler (<?= $total_books ?> Eser)</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat_filter == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 fw-semibold">Filtrele</button>
                    <?php if (!empty($search) || $cat_filter > 0 || $store_filter > 0): ?>
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
                            <th>Kategori / Raf</th>
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
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="../<?= e(cover_url($b['cover_image'] ?? '')) ?>" alt="" style="width: 36px; height: 52px; object-fit: cover;" class="rounded border bg-white" loading="lazy">
                                            <div>
                                                <div class="fw-bold text-dark fs-7"><?= e($b['title']) ?></div>
                                                <small class="text-muted fs-8">
                                                    ISBN: <?= e($b['isbn'] ?: '-') ?>
                                                    <?php if (!empty($b['nadirkitap_code'])): ?>
                                                        • NK: #<?= e($b['nadirkitap_code']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fs-7 font-semibold"><?= e($b['author_name']) ?></div>
                                        <small class="text-secondary fs-8"><?= e($b['publisher_name']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border fs-8"><?= e($b['category_name']) ?></span>
                                        <?php if (!empty($b['store_name'])): ?>
                                            <div class="mt-1"><span class="badge bg-danger bg-opacity-10 text-danger fs-8"><i class="bi bi-shop me-1"></i><?= e($b['store_name']) ?></span></div>
                                        <?php endif; ?>
                                        <?php if (!empty($b['shelf_code'])): ?>
                                            <div class="mt-1"><span class="badge bg-warning text-dark fs-8"><i class="bi bi-archive-fill me-1"></i>Raf: <?= e($b['shelf_code']) ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-extrabold text-danger fs-7"><?= format_price($b['price']) ?><?php if (!empty($b['store_id'])): ?> <i class="bi bi-lock-fill text-secondary fs-8" title="Alt dükkan fiyatı, panelden değiştirilemez"></i><?php endif; ?></div>
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
            <?php if ($total_pages > 1): ?>
                <?php
                $base_params = $_GET;
                unset($base_params['page']);
                $build_p_url = function($p_num) use ($base_params) {
                    $base_params['page'] = $p_num;
                    return 'index.php?' . http_build_query($base_params);
                };
                ?>
                <div class="p-3 border-top bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <small class="text-muted">Sayfa <?= $page ?> / <?= $total_pages ?> (Toplam <?= $total_books ?> kitap)</small>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($build_p_url($page - 1)) ?>">&laquo; Önceki</a>
                        </li>
                        <?php
                        $sp = max(1, $page - 3);
                        $ep = min($total_pages, $page + 3);
                        for ($i = $sp; $i <= $ep; $i++):
                        ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($build_p_url($i)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($build_p_url($page + 1)) ?>">Sonraki &raquo;</a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
