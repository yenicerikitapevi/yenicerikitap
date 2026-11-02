<?php
/**
 * SATICI PANELİ: Kitap ekle / düzenle (satici/kitap.php, düzenleme için ?id=)
 * Sahaf yalnızca kendi dükkânının (store_id) kitaplarını görür ve değiştirir.
 */
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['store_user'])) {
    redirect('login.php');
}
$store = db_fetch("SELECT * FROM stores WHERE id = ?", [(int)$_SESSION['store_user']['id']]);
if (!$store || !$store['is_active']) {
    unset($_SESSION['store_user']);
    redirect('login.php');
}
$store_id = (int)$store['id'];
if (empty($_SESSION['store_form_token'])) {
    $_SESSION['store_form_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['store_form_token'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$book = $id ? db_fetch("SELECT b.*, a.name as author_name, p.name as publisher_name FROM books b LEFT JOIN authors a ON a.id = b.author_id LEFT JOIN publishers p ON p.id = b.publisher_id WHERE b.id = ? AND b.store_id = ?", [$id, $store_id]) : null;
if ($id && !$book) {
    set_flash('danger', 'Kitap bulunamadı.');
    redirect('index.php');
}

$categories = array_values(array_filter(db_fetch_all("SELECT id, name FROM categories ORDER BY name ASC"), function ($c) { return !is_numeric_category_name($c['name']); }));
$conditions = array_keys(condition_guide());
$errors = [];

/** Yazar/yayınevi adını bulur, yoksa oluşturur */
function store_entity($table, $name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return null;
    $row = find_by_name($table, $name);
    if ($row) return (int)$row['id'];
    db_query("INSERT INTO {$table} (name, slug) VALUES (?, ?)", [$name, unique_slug($table, $name)]);
    return (int)db_last_id();
}

$f = [
    'title' => $book['title'] ?? '', 'author' => $book['author_name'] ?? '', 'publisher' => $book['publisher_name'] ?? '',
    'category_id' => (int)($book['category_id'] ?? 0), 'isbn' => $book['isbn'] ?? '', 'price' => $book['price'] ?? '', 'old_price' => $book['old_price'] ?? '',
    'stock' => $book ? (int)$book['stock'] : 1, 'pages' => $book['pages'] ?? '', 'year' => $book['year'] ?? '', 'summary' => $book['summary'] ?? '',
    'condition_text' => $book['condition_text'] ?? '', 'cover_type' => $book['cover_type'] ?? '', 'shelf_code' => $book['shelf_code'] ?? '',
    'language' => $book['language'] ?? '', 'translator' => $book['translator'] ?? '', 'publish_place' => $book['publish_place'] ?? '',
    'dimensions' => $book['dimensions'] ?? '', 'is_active' => $book ? (int)$book['is_active'] : 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $errors[] = 'Oturum süresi doldu. Lütfen formu tekrar gönderin.';
    }
    foreach ($f as $k => $_) {
        if ($k === 'is_active') continue;
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $f['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
    $price = (float)str_replace(',', '.', $f['price']);
    $old_price = $f['old_price'] !== '' ? (float)str_replace(',', '.', $f['old_price']) : null;
    $stock = max(0, (int)$f['stock']);
    $category_id = (int)$f['category_id'];
    $isbn = preg_replace('/[^\dXx-]/', '', $f['isbn']);

    if (mb_strlen($f['title'], 'UTF-8') < 2) $errors[] = 'Kitap adını yazın.';
    if ($f['author'] === '') $errors[] = 'Yazar adını yazın (bilinmiyorsa "Kolektif" yazabilirsiniz).';
    if ($price <= 0) $errors[] = 'Geçerli bir satış fiyatı girin.';
    if ($old_price !== null && $old_price <= $price) $old_price = null; // eski fiyat yalnızca indirim göstermek içindir
    if (!$category_id || !db_fetch("SELECT id FROM categories WHERE id = ?", [$category_id])) $errors[] = 'Kategori seçin.';
    if ($f['condition_text'] !== '' && !in_array($f['condition_text'], $conditions, true)) $f['condition_text'] = '';
    if ($isbn !== '') {
        $dup = db_fetch("SELECT id, store_id FROM books WHERE (isbn = ? OR REPLACE(isbn, '-', '') = ?) AND id != ?", [$isbn, str_replace('-', '', $isbn), $id]);
        if ($dup && (int)$dup['store_id'] === $store_id) {
            $errors[] = 'Bu ISBN ile dükkânınızda kayıtlı başka bir kitap var.';
        } elseif ($dup) {
            // ISBN sütunu tektir: aynı kitap başka satıcıda da varsa ISBN açıklamaya yazılır (NadirKitap aktarımıyla aynı yöntem)
            if (stripos($f['summary'], $isbn) === false) $f['summary'] = trim($f['summary'] . "\nISBN: " . $isbn);
            $isbn = '';
        }
    }

    $upload_error = '';
    $uploaded = $errors ? null : save_cover_upload($_FILES['cover_file'] ?? [], $upload_error);
    if ($upload_error !== '') $errors[] = $upload_error;

    if (!$errors) {
        $cover = $book['cover_image'] ?? 'book_placeholder.svg';
        if ($uploaded) {
            if (!empty($book['cover_image']) && strpos($book['cover_image'], 'cover_') === 0) @unlink(UPLOAD_DIR . '/' . basename($book['cover_image']));
            $cover = $uploaded;
        }
        $vals = [
            $f['title'], $isbn ?: null, store_entity('authors', $f['author']), store_entity('publishers', $f['publisher']), $category_id,
            $price, $old_price, $stock, (int)$f['pages'] ?: null, (int)$f['year'] ?: null, $f['summary'] ?: null, $cover, $f['is_active'],
            $f['shelf_code'] ?: null, $f['condition_text'] ?: null, $f['cover_type'] ?: null, $f['dimensions'] ?: null, $f['language'] ?: null,
            $f['translator'] ?: null, $f['publish_place'] ?: null,
        ];
        if ($book) {
            db_query("UPDATE books SET title = ?, isbn = ?, author_id = ?, publisher_id = ?, category_id = ?, price = ?, old_price = ?, stock = ?, pages = ?, year = ?, summary = ?, cover_image = ?, is_active = ?,
                      shelf_code = ?, condition_text = ?, cover_type = ?, dimensions = ?, language = ?, translator = ?, publish_place = ? WHERE id = ? AND store_id = ?", array_merge($vals, [$id, $store_id]));
            $book_id = $id;
            log_action('Sahaf Kitap Güncelledi', 'Products', $book_id, $store['name'] . ': ' . $f['title']);
            set_flash('success', '"' . $f['title'] . '" güncellendi.');
        } else {
            db_query("INSERT INTO books (title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active,
                      shelf_code, condition_text, cover_type, dimensions, language, translator, publish_place, store_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge($vals, [$store_id]));
            $book_id = (int)db_last_id();
            log_action('Sahaf Kitap Ekledi', 'Products', $book_id, $store['name'] . ': ' . $f['title']);
            set_flash('success', '"' . $f['title'] . '" eklendi. Sıradaki kitabı ekleyebilirsiniz.');
        }
        book_refresh_slug($book_id);
        book_touch($book_id);
        // Yeni kitaptan sonra boş form açılır (kategori ve kondisyon korunur); düzenlemeden sonra listeye dönülür
        redirect($book ? 'index.php' : 'kitap.php?kat=' . $category_id . '&kond=' . rawurlencode($f['condition_text']));
    }
}
if (!$book && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $f['category_id'] = (int)($_GET['kat'] ?? 0);
    $f['condition_text'] = in_array($_GET['kond'] ?? '', $conditions, true) ? $_GET['kond'] : '';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $book ? 'Kitabı Düzenle' : 'Yeni Kitap' ?> | <?= e($store['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="bg-dark text-white py-2 mb-4 shadow">
        <div class="container-xl d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <span class="bg-white rounded-3 p-1"><img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 30px;"></span>
                <div class="fw-bold"><i class="bi bi-shop text-danger me-1"></i><?= e($store['name']) ?></div>
            </div>
            <a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Kitaplarım</a>
        </div>
    </nav>
    <main class="container-xl pb-5" style="max-width: 1000px;">
        <?= get_flash() ?>
        <h1 class="h4 fw-bold mb-3"><i class="bi <?= $book ? 'bi-pencil-square' : 'bi-plus-circle' ?> text-danger me-2"></i><?= $book ? 'Kitabı Düzenle' : 'Yeni Kitap Ekle' ?></h1>
        <?php if ($errors): ?><div class="alert alert-danger fs-7"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
        <?php if ($book && !empty($book['nadirkitap_code'])): ?>
            <div class="alert alert-warning fs-7"><i class="bi bi-exclamation-triangle me-1"></i>Bu kitap NadirKitap'tan aktarıldı (NK #<?= e($book['nadirkitap_code']) ?>). NadirKitap ile eşitleme yaptığınızda fiyat ve stok oradaki bilgilerle yeniden güncellenebilir.</div>
        <?php endif; ?>

        <form method="POST" action="kitap.php<?= $book ? '?id=' . $id : '' ?>" enctype="multipart/form-data" class="card border-0 shadow-sm rounded-4 p-4">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-3">
                <div class="col-md-3 text-center">
                    <img src="../<?= e(cover_url($book['cover_image'] ?? '')) ?>" alt="Kapak" id="coverPreview" class="rounded shadow-sm bg-white mb-2" style="width: 140px; height: 210px; object-fit: cover;">
                    <input type="file" name="cover_file" id="coverFile" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                    <div class="text-muted fs-8 mt-1">JPG, PNG veya WEBP, en fazla 5 MB</div>
                </div>
                <div class="col-md-9">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label fw-semibold fs-7">Kitap Adı *</label><input type="text" name="title" class="form-control" value="<?= e($f['title']) ?>" required maxlength="250"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold fs-7">Yazar *</label><input type="text" name="author" class="form-control" value="<?= e($f['author']) ?>" required maxlength="120"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold fs-7">Yayınevi</label><input type="text" name="publisher" class="form-control" value="<?= e($f['publisher']) ?>" maxlength="120"></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Kategori *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- Kategori Seçiniz --</option>
                                <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$f['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label fw-semibold fs-7">ISBN</label><input type="text" name="isbn" class="form-control" value="<?= e($f['isbn']) ?>" maxlength="20" placeholder="Eski kitaplarda boş bırakılabilir"></div>
                    </div>
                </div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Satış Fiyatı (TL) *</label><input type="number" name="price" class="form-control" value="<?= e($f['price']) ?>" step="0.01" min="0.01" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Eski Fiyat (TL)</label><input type="number" name="old_price" class="form-control" value="<?= e($f['old_price']) ?>" step="0.01" min="0" placeholder="İndirim varsa"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Stok *</label><input type="number" name="stock" class="form-control" value="<?= e($f['stock']) ?>" min="0" required></div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold fs-7">Kondisyon</label>
                    <select name="condition_text" class="form-select">
                        <option value="">-- Seçiniz --</option>
                        <?php foreach ($conditions as $c): ?><option value="<?= e($c) ?>" <?= $f['condition_text'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Cilt Durumu</label><input type="text" name="cover_type" class="form-control" value="<?= e($f['cover_type']) ?>" placeholder="Karton Kapak, Ciltli"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Sayfa Sayısı</label><input type="number" name="pages" class="form-control" value="<?= e($f['pages']) ?>" min="0"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Basım Yılı</label><input type="number" name="year" class="form-control" value="<?= e($f['year']) ?>" min="1000" max="<?= date('Y') + 1 ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Raf Kodu <span class="text-muted fw-normal">(yalnızca siz görürsünüz)</span></label><input type="text" name="shelf_code" class="form-control" value="<?= e($f['shelf_code']) ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Dil</label><input type="text" name="language" class="form-control" value="<?= e($f['language']) ?>" placeholder="Türkçe"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Çeviren / Hazırlayan</label><input type="text" name="translator" class="form-control" value="<?= e($f['translator']) ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Yayın Yeri</label><input type="text" name="publish_place" class="form-control" value="<?= e($f['publish_place']) ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold fs-7">Ebat</label><input type="text" name="dimensions" class="form-control" value="<?= e($f['dimensions']) ?>" placeholder="13,5 x 21 cm"></div>
                <div class="col-12"><label class="form-label fw-semibold fs-7">Açıklama / Özet</label><textarea name="summary" class="form-control" rows="4" placeholder="Kitabın içeriği, baskı bilgisi, varsa kusurları..."><?= e($f['summary']) ?></textarea></div>
                <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" <?= $f['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold fs-7" for="isActive">Satışta (vitrinde görünsün)</label>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="index.php" class="btn btn-outline-secondary">Vazgeç</a>
                        <button class="btn btn-danger fw-bold px-4"><i class="bi bi-check-lg me-1"></i><?= $book ? 'Kaydet' : 'Kitabı Ekle' ?></button>
                    </div>
                </div>
            </div>
        </form>
        <div class="mt-4"><?= condition_guide_html($f['condition_text']) ?></div>
    </main>
    <script>
        document.getElementById('coverFile').addEventListener('change', function () {
            if (this.files[0]) document.getElementById('coverPreview').src = URL.createObjectURL(this.files[0]);
        });
    </script>
</body>
</html>
