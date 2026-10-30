<?php
/**
 * ADMIN MODULE: Alt Dükkanlar / Satıcılar (p=Stores)
 */
if (!defined('SITE_URL')) exit;

$action = sanitize($_GET['action'] ?? 'list');
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'delete' && $edit_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $store = db_fetch("SELECT * FROM stores WHERE id = ?", [$edit_id]);
    if ($store) {
        $delete_books = !empty($_POST['delete_store_books']);
        if ($delete_books) {
            $s_books = db_fetch_all("SELECT id FROM books WHERE store_id = ?", [$edit_id]);
            foreach ($s_books as $sb) {
                $bid = (int)$sb['id'];
                db_query("DELETE FROM book_showcase WHERE book_id = ?", [$bid]);
                db_query("DELETE FROM book_collection WHERE book_id = ?", [$bid]);
            }
            db_query("DELETE FROM books WHERE store_id = ?", [$edit_id]);
        } else {
            db_query("UPDATE books SET store_id = NULL WHERE store_id = ?", [$edit_id]);
        }
        db_query("DELETE FROM stores WHERE id = ?", [$edit_id]);
        log_action('Alt Dükkan Silindi', 'Stores', $edit_id, "Alt dükkan silindi: " . $store['name']);
        set_flash('success', '"' . $store['name'] . '" alt dükkanı silindi.');
    }
    redirect('index.php?p=Stores');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_store'])) {
    $name        = sanitize($_POST['name'] ?? '');
    $username    = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', trim($_POST['username'] ?? '')));
    $password    = trim($_POST['password'] ?? '');
    $nk_input    = trim($_POST['nadirkitap_input'] ?? '');
    $city        = sanitize($_POST['city'] ?? '');
    $phone       = sanitize($_POST['phone'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $username === '') {
        set_flash('danger', 'Dükkan Adı ve Kullanıcı Adı alanları zorunludur.');
        redirect('index.php?p=Stores&action=' . ($edit_id > 0 ? 'edit&id=' . $edit_id : 'add'));
    }

    $dup_user = db_fetch("SELECT id FROM stores WHERE LOWER(username) = LOWER(?) AND id != ?", [$username, $edit_id]);
    if ($dup_user) {
        set_flash('danger', "Bu kullanıcı adı ({$username}) başka bir alt dükkanda kullanılıyor.");
        redirect('index.php?p=Stores&action=' . ($edit_id > 0 ? 'edit&id=' . $edit_id : 'add'));
    }

    $nk_code = extract_nadirkitap_code($nk_input);
    $nk_url  = (stripos($nk_input, 'http') === 0) ? $nk_input : ($nk_code !== '' ? "https://www.nadirkitap.com/kitapara.php?satici={$nk_code}" : '');

    $slug = unique_slug('stores', $name, $edit_id);

    if ($edit_id > 0) {
        $existing = db_fetch("SELECT * FROM stores WHERE id = ?", [$edit_id]);
        if (!$existing) {
            redirect('index.php?p=Stores');
        }
        $pass_hash  = $existing['password_hash'];
        $plain_hint = $existing['plain_hint'];
        if ($password !== '') {
            $pass_hash  = password_hash($password, PASSWORD_DEFAULT);
            $plain_hint = $password;
        }
        db_query(
            "UPDATE stores SET name = ?, slug = ?, username = ?, password_hash = ?, plain_hint = ?, nadirkitap_code = ?, nadirkitap_url = ?, city = ?, phone = ?, description = ?, is_active = ? WHERE id = ?",
            [$name, $slug, $username, $pass_hash, $plain_hint, $nk_code ?: null, $nk_url ?: null, $city, $phone, $description, $is_active, $edit_id]
        );
        log_action('Alt Dükkan Güncellendi', 'Stores', $edit_id, "Alt dükkan güncellendi: {$name} ({$username})");
        set_flash('success', '"' . $name . '" alt dükkan bilgileri güncellendi.');
    } else {
        if ($password === '') {
            set_flash('danger', 'Yeni alt dükkan oluştururken şifre belirlemelisiniz.');
            redirect('index.php?p=Stores&action=add');
        }
        $pass_hash = password_hash($password, PASSWORD_DEFAULT);
        db_query(
            "INSERT INTO stores (name, slug, username, password_hash, plain_hint, nadirkitap_code, nadirkitap_url, city, phone, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$name, $slug, $username, $pass_hash, $password, $nk_code ?: null, $nk_url ?: null, $city, $phone, $description, $is_active]
        );
        $new_id = (int)db_last_id();
        log_action('Yeni Alt Dükkan Açıldı', 'Stores', $new_id, "Alt dükkan oluşturuldu: {$name} (Kullanıcı: {$username})");
        set_flash('success', '"' . $name . '" alt dükkanı başarıyla açıldı! Kullanıcı Adı: ' . $username);
    }

    redirect('index.php?p=Stores');
}

$store_data = null;
if ($action === 'edit' && $edit_id > 0) {
    $store_data = db_fetch("SELECT * FROM stores WHERE id = ?", [$edit_id]);
}

$stores_list = db_fetch_all("SELECT s.*, (SELECT COUNT(*) FROM books b WHERE b.store_id = s.id) as book_count FROM stores s ORDER BY s.id DESC");
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-shop text-danger me-2"></i>Alt Dükkanlar (Satıcı & Sahaf Yönetimi)</h5>
        <p class="text-muted fs-7 mb-0">Kullanıcılara kullanıcı adı ve şifre tanımlayarak alt dükkan açabilir, NadirKitap mağazalarından çekilen kitapların doğrudan kendi alt dükkanlarına eklenmesini sağlayabilirsiniz. Satıcılar aynı bilgilerle <a href="../satici/" target="_blank">/satici/</a> adresinden kendi ziyaret ve satış raporlarını görür.</p>
    </div>
    <?php if ($action === 'list'): ?>
        <div class="d-flex gap-2">
            <a href="../magaza.php" target="_blank" class="btn btn-outline-dark font-semibold rounded-3">
                <i class="bi bi-box-arrow-up-right me-1"></i> Tüm Alt Dükkanlar Sayfası
            </a>
            <a href="index.php?p=Stores&action=add" class="btn btn-danger font-semibold rounded-3 shadow">
                <i class="bi bi-plus-lg me-1"></i> Yeni Alt Dükkan Aç
            </a>
        </div>
    <?php else: ?>
        <a href="index.php?p=Stores" class="btn btn-outline-secondary font-semibold rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Alt Dükkan Listesine Dön
        </a>
    <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom p-4">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-shop-window text-danger me-2"></i>
                <?= $edit_id > 0 ? 'Alt Dükkan Bilgilerini & Şifresini Düzenle' : 'Yeni Alt Dükkan (Satıcı Hesabı) Aç' ?>
            </h5>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="">
                <input type="hidden" name="save_store" value="1">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Alt Dükkan / Sahaf Adı *</label>
                        <input type="text" name="name" class="form-control" placeholder="Örn: Umay Sahaf" value="<?= e($store_data['name'] ?? '') ?>" required>
                        <small class="text-muted">Sitede müşterilere görünecek mağaza adı.</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold fs-7">Şehir</label>
                        <input type="text" name="city" class="form-control" placeholder="Örn: Ankara" value="<?= e($store_data['city'] ?? '') ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold fs-7">Telefon / İletişim</label>
                        <input type="text" name="phone" class="form-control" placeholder="Örn: 0555 000 00 00" value="<?= e($store_data['phone'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <label class="form-label fw-bold text-dark fs-7"><i class="bi bi-person-badge text-danger me-1"></i> Uygulama Giriş Kullanıcı Adı *</label>
                            <input type="text" name="username" class="form-control" placeholder="Örn: umaysahaf" value="<?= e($store_data['username'] ?? '') ?>" required>
                            <small class="text-muted d-block mt-1">Satıcının masaüstü aktarım uygulamasında giriş yapacağı kullanıcı adı (boşluksuz).</small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <label class="form-label fw-bold text-dark fs-7">
                                <i class="bi bi-key-fill text-danger me-1"></i> Uygulama Giriş Şifresi <?= $edit_id > 0 ? '(Değiştirmek için doldurun)' : '*' ?>
                            </label>
                            <input type="text" name="password" class="form-control" placeholder="<?= $edit_id > 0 ? 'Mevcut: ' . e($store_data['plain_hint'] ?? '***') : 'Örn: umay348635' ?>" value="<?= $edit_id === 0 ? e($store_data['plain_hint'] ?? '') : '' ?>" <?= $edit_id > 0 ? '' : 'required' ?>>
                            <small class="text-muted d-block mt-1">Satıcıya vereceğiniz şifre. Masaüstü uygulamasında bu şifre ile oturum açar.</small>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold fs-7">Varsayılan NadirKitap Mağaza Bağlantısı (URL) veya Kodu (Opsiyonel)</label>
                        <input type="text" name="nadirkitap_input" class="form-control" placeholder="Örn: https://www.nadirkitap.com/umay-sahaf-sahaf348635.html veya 348635" value="<?= e($store_data['nadirkitap_url'] ?: ($store_data['nadirkitap_code'] ?? '')) ?>">
                        <small class="text-muted">Buraya yazabilir veya boş bırakabilirsiniz; kullanıcı masaüstü uygulamasında kendi NadirKitap bağlantısını ya da kodunu girdiğinde otomatik güncellenir.</small>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold fs-7">Alt Dükkan Açıklaması / Hakkında Yazısı</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Dükkan sayfasında üst kısımda yer alacak tanıtım yazısı..."><?= e($store_data['description'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-12">
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= ($store_data['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold fs-7" for="is_active">Alt Dükkan Aktif Olsun (Uygulamadan kitap aktarabilsin ve vitrinde görünsün)</label>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top d-flex gap-2">
                    <button type="submit" class="btn btn-danger font-semibold px-4 py-2 shadow">
                        <i class="bi bi-check-circle me-1"></i> Alt Dükkanı Kaydet
                    </button>
                    <a href="index.php?p=Stores" class="btn btn-light border px-4 py-2">İptal</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Alt Dükkan (Vitrin)</th>
                            <th>Uygulama Giriş Bilgileri</th>
                            <th>NadirKitap Kodu / Linki</th>
                            <th>Kitap Sayısı</th>
                            <th>Durum</th>
                            <th class="text-end pe-4">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stores_list)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">Henüz tanımlı alt dükkan bulunmuyor.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stores_list as $s): ?>
                                <tr>
                                    <td class="ps-4 text-muted fs-7">#<?= $s['id'] ?></td>
                                    <td>
                                        <div class="fw-bold text-dark fs-7"><?= e($s['name']) ?></div>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <a href="../magaza.php?s=<?= urlencode($s['slug']) ?>" target="_blank" class="badge bg-danger bg-opacity-10 text-danger text-decoration-none">
                                                <i class="bi bi-box-arrow-up-right me-1"></i>magaza.php?s=<?= e($s['slug']) ?>
                                            </a>
                                            <?php if (!empty($s['city'])): ?>
                                                <small class="text-muted fs-8"><i class="bi bi-geo-alt"></i> <?= e($s['city']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fs-7">
                                            <span class="text-muted">Kullanıcı:</span> <code class="fw-bold text-dark"><?= e($s['username']) ?></code>
                                        </div>
                                        <div class="fs-7">
                                            <span class="text-muted">Şifre:</span> <code class="fw-bold text-primary"><?= e($s['plain_hint'] ?: 'Belirlendi') ?></code>
                                        </div>
                                        <a href="../satici/" target="_blank" class="fs-8 text-muted"><i class="bi bi-graph-up me-1"></i>Satıcı paneli: /satici/</a>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['nadirkitap_code'])): ?>
                                            <span class="badge bg-dark text-white">NK Kod: <?= e($s['nadirkitap_code']) ?></span>
                                            <?php if (!empty($s['nadirkitap_url'])): ?>
                                                <a href="<?= e($s['nadirkitap_url']) ?>" target="_blank" class="d-block fs-8 text-muted text-truncate mt-1" style="max-width: 220px;">
                                                    <?= e($s['nadirkitap_url']) ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted fs-8">Uygulamadan girilecek</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?p=Products&store_id=<?= $s['id'] ?>" class="badge bg-primary bg-opacity-10 text-primary text-decoration-none fs-7 px-3 py-2">
                                            <i class="bi bi-book me-1"></i> <?= (int)$s['book_count'] ?> Kitap
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($s['is_active']): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pasif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="../magaza.php?s=<?= urlencode($s['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-dark me-1" title="Vitrin Sayfasını Gör">
                                            <i class="bi bi-shop"></i>
                                        </a>
                                        <a href="index.php?p=Products&store_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Kitapları Görüntüle / Düzenle">
                                            <i class="bi bi-book"></i>
                                        </a>
                                        <a href="index.php?p=Stores&action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Düzenle / Şifre Değiştir">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <form method="POST" action="index.php?p=Stores&action=delete&id=<?= $s['id'] ?>" class="d-inline" onsubmit="return confirm('Bu alt dükkanı silmek istediğinize emin misiniz?');">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash-fill"></i></button>
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
