<?php
/**
 * ADMIN MODULE: Alt Dükkanlar / Satıcılar (p=Stores)
 */
if (!defined('SITE_URL')) exit;

$action = sanitize($_GET['action'] ?? 'list');
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'sync' && $edit_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = nk_sync_store($edit_id, 40);
    if (!empty($res['success'])) {
        set_flash('success', $res['message']);
    } else {
        set_flash('warning', $res['message']);
    }
    redirect('index.php?p=Stores');
}

// Sahaf üyelik başvurusunu onayla / reddet
if (($action === 'approve' || $action === 'reject') && $edit_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $store = db_fetch("SELECT * FROM stores WHERE id = ?", [$edit_id]);
    if ($store) {
        $login_url = SITE_URL . '/satici/login.php';
        if ($action === 'approve') {
            db_query("UPDATE stores SET approval_status = 'approved', is_active = 1, reviewed_at = ?, rejection_reason = NULL WHERE id = ?", [date('Y-m-d H:i:s'), $edit_id]);
            log_action('Sahaf Başvurusu Onaylandı', 'Stores', $edit_id, "Onaylanan: {$store['name']} ({$store['username']})");
            $mail_ok = !empty($store['email']) && send_mail(
                $store['email'],
                'Satıcı başvurunuz onaylandı | ' . APP_NAME,
                mail_layout('Başvurunuz onaylandı', '<p>Merhaba ' . e($store['contact_name'] ?: $store['name']) . ',</p><p><b>' . e($store['name']) . '</b> dükkânınız açıldı. Kullanıcı adınız <b>' . e($store['username']) . '</b>; başvuruda belirlediğiniz şifreyle satıcı paneline ve kitap aktarım uygulamasına giriş yapabilirsiniz.</p>' . mail_button($login_url, 'Satıcı Paneline Giriş')),
                "Merhaba,\n\n{$store['name']} dükkânınız açıldı. Kullanıcı adınız: {$store['username']}\nGiriş: {$login_url}",
                'seller_approved'
            );
            set_flash('success', '"' . $store['name'] . '" başvurusu onaylandı ve dükkân açıldı.' . ($mail_ok ? ' Sahafa e-posta gönderildi.' : ' E-posta gönderilemedi; sahafı ayrıca bilgilendirin.'));
        } else {
            $reason = sanitize(trim($_POST['rejection_reason'] ?? ''));
            db_query("UPDATE stores SET approval_status = 'rejected', is_active = 0, reviewed_at = ?, rejection_reason = ? WHERE id = ?", [date('Y-m-d H:i:s'), $reason ?: null, $edit_id]);
            log_action('Sahaf Başvurusu Reddedildi', 'Stores', $edit_id, "Reddedilen: {$store['name']}" . ($reason ? " - Gerekçe: {$reason}" : ''));
            $mail_ok = !empty($store['email']) && send_mail(
                $store['email'],
                'Satıcı başvurunuz hakkında | ' . APP_NAME,
                mail_layout('Başvurunuz onaylanmadı', '<p>Merhaba ' . e($store['contact_name'] ?: $store['name']) . ',</p><p><b>' . e($store['name']) . '</b> için yaptığınız satıcı başvurusu şu an için onaylanmadı.</p>' . ($reason ? '<p>Gerekçe: ' . e($reason) . '</p>' : '') . '<p>Sorularınız için bu e-postayı yanıtlayabilirsiniz.</p>'),
                "Merhaba,\n\n{$store['name']} için satıcı başvurunuz onaylanmadı." . ($reason ? "\nGerekçe: {$reason}" : ''),
                'seller_rejected'
            );
            set_flash('warning', '"' . $store['name'] . '" başvurusu reddedildi.' . ($mail_ok ? ' Sahafa e-posta gönderildi.' : ''));
        }
    }
    redirect('index.php?p=Stores');
}

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
    // Boş bırakılırsa Kampanyalar sayfasındaki varsayılan komisyon oranı geçerlidir
    $commission  = trim(str_replace(',', '.', $_POST['commission_rate'] ?? ''));
    $commission  = $commission === '' ? null : min(100, max(0, (float)$commission));
    $company = [
        'company_type'  => sanitize($_POST['company_type'] ?? ''),
        'company_title' => sanitize($_POST['company_title'] ?? ''),
        'contact_name'  => sanitize($_POST['contact_name'] ?? ''),
        'email'         => filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
        'tax_office'    => sanitize($_POST['tax_office'] ?? ''),
        'tax_number'    => preg_replace('/\D/', '', $_POST['tax_number'] ?? ''),
        'address'       => sanitize($_POST['address'] ?? ''),
        'iban'          => strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? '')),
    ];
    $company = array_map(fn($v) => ($v === '' ? null : $v), $company);

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
            "UPDATE stores SET name = ?, slug = ?, username = ?, password_hash = ?, plain_hint = ?, nadirkitap_code = ?, nadirkitap_url = ?, city = ?, phone = ?, description = ?, is_active = ?, commission_rate = ? WHERE id = ?",
            [$name, $slug, $username, $pass_hash, $plain_hint, $nk_code ?: null, $nk_url ?: null, $city, $phone, $description, $is_active, $commission, $edit_id]
        );
        db_query(
            "UPDATE stores SET company_type = ?, company_title = ?, contact_name = ?, email = ?, tax_office = ?, tax_number = ?, address = ?, iban = ? WHERE id = ?",
            array_merge(array_values($company), [$edit_id])
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
            "INSERT INTO stores (name, slug, username, password_hash, plain_hint, nadirkitap_code, nadirkitap_url, city, phone, description, is_active, commission_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$name, $slug, $username, $pass_hash, $password, $nk_code ?: null, $nk_url ?: null, $city, $phone, $description, $is_active, $commission]
        );
        $new_id = (int)db_last_id();
        db_query(
            "UPDATE stores SET company_type = ?, company_title = ?, contact_name = ?, email = ?, tax_office = ?, tax_number = ?, address = ?, iban = ?, approval_status = 'approved' WHERE id = ?",
            array_merge(array_values($company), [$new_id])
        );
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
$pending_applications = array_values(array_filter($stores_list, fn($s) => ($s['approval_status'] ?? 'approved') === 'pending'));
$company_type_labels = ['sahis' => 'Şahıs Şirketi', 'limited' => 'Limited Şirket', 'anonim' => 'Anonim Şirket', 'diger' => 'Diğer'];
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

                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Komisyon Oranı (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="commission_rate" class="form-control" placeholder="Varsayılan: %<?= e(setting('default_commission_rate', '0')) ?>" value="<?= e($store_data['commission_rate'] ?? '') ?>">
                        <small class="text-muted">Satışın indirim sonrası tutarından alınır. Boş = varsayılan oran (Kampanyalar sayfası).</small>
                    </div>

                    <div class="col-12">
                        <div class="fw-bold text-dark fs-7 border-bottom pb-1 mt-2"><i class="bi bi-building text-danger me-1"></i> Firma Bilgileri (Fatura ve Hakediş)</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold fs-7">Firma Türü</label>
                        <select name="company_type" class="form-select">
                            <option value="">-</option>
                            <?php foreach ($company_type_labels as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= ($store_data['company_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold fs-7">Ticari Unvan</label>
                        <input type="text" name="company_title" class="form-control" value="<?= e($store_data['company_title'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Yetkili Ad Soyad</label>
                        <input type="text" name="contact_name" class="form-control" value="<?= e($store_data['contact_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Vergi Dairesi</label>
                        <input type="text" name="tax_office" class="form-control" value="<?= e($store_data['tax_office'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">Vergi No / TC Kimlik No</label>
                        <input type="text" name="tax_number" class="form-control" inputmode="numeric" value="<?= e($store_data['tax_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7">E-posta</label>
                        <input type="email" name="email" class="form-control" value="<?= e($store_data['email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">IBAN</label>
                        <input type="text" name="iban" class="form-control font-monospace" value="<?= e($store_data['iban'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7">Açık Adres</label>
                        <input type="text" name="address" class="form-control" value="<?= e($store_data['address'] ?? '') ?>">
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
    <?php if (!empty($pending_applications)): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 border-start border-warning border-4">
            <div class="card-header bg-warning bg-opacity-10 border-0 p-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-hourglass-split text-warning me-2"></i>Onay Bekleyen Sahaf Başvuruları (<?= count($pending_applications) ?>)</h6>
            </div>
            <div class="card-body p-3">
                <?php foreach ($pending_applications as $a): ?>
                    <div class="border rounded-3 p-3 mb-3 bg-white">
                        <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                            <div>
                                <div class="fw-bold text-dark"><?= e($a['name']) ?> <span class="text-muted fw-normal fs-7">· <?= e($a['company_title'] ?? '') ?></span></div>
                                <div class="text-muted fs-8">Başvuru: <?= e($a['applied_at'] ?? $a['created_at']) ?> · Kullanıcı adı: <code><?= e($a['username']) ?></code></div>
                            </div>
                            <div class="d-flex gap-2 align-items-start">
                                <form method="POST" action="index.php?p=Stores&action=approve&id=<?= (int)$a['id'] ?>" onsubmit="return confirm('Başvuru onaylansın ve dükkân açılsın mı?');">
                                    <button type="submit" class="btn btn-sm btn-success fw-semibold"><i class="bi bi-check-lg me-1"></i>Onayla</button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger fw-semibold" onclick="this.nextElementSibling.classList.toggle('d-none')"><i class="bi bi-x-lg me-1"></i>Reddet</button>
                                <div class="d-none">
                                    <form method="POST" action="index.php?p=Stores&action=reject&id=<?= (int)$a['id'] ?>" class="d-flex gap-2">
                                        <input type="text" name="rejection_reason" class="form-control form-control-sm" placeholder="Gerekçe (sahafa iletilir)" style="min-width: 220px;">
                                        <button type="submit" class="btn btn-sm btn-danger">Gönder</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 fs-7">
                            <div class="col-md-4"><span class="text-muted">Firma türü:</span> <?= e($company_type_labels[$a['company_type'] ?? ''] ?? '-') ?></div>
                            <div class="col-md-4"><span class="text-muted">Vergi dairesi / no:</span> <?= e($a['tax_office'] ?? '-') ?> / <code><?= e($a['tax_number'] ?? '-') ?></code></div>
                            <div class="col-md-4"><span class="text-muted">Yetkili:</span> <?= e($a['contact_name'] ?? '-') ?></div>
                            <div class="col-md-4"><span class="text-muted">Telefon:</span> <?= e($a['phone'] ?? '-') ?></div>
                            <div class="col-md-4"><span class="text-muted">E-posta:</span> <?= e($a['email'] ?? '-') ?></div>
                            <div class="col-md-4"><span class="text-muted">IBAN:</span> <code><?= e($a['iban'] ?? '-') ?></code></div>
                            <div class="col-md-8"><span class="text-muted">Adres:</span> <?= e($a['address'] ?? '-') ?> / <?= e($a['city'] ?? '') ?></div>
                            <div class="col-md-4"><span class="text-muted">NadirKitap:</span> <?= !empty($a['nadirkitap_url']) ? '<a href="' . e($a['nadirkitap_url']) . '" target="_blank" rel="noopener">' . e($a['nadirkitap_code'] ?: 'bağlantı') . '</a>' : '-' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

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
                                        <?php if (($s['approval_status'] ?? 'approved') === 'pending'): ?>
                                            <span class="badge bg-warning text-dark">Onay Bekliyor</span>
                                        <?php elseif (($s['approval_status'] ?? 'approved') === 'rejected'): ?>
                                            <span class="badge bg-danger" title="<?= e($s['rejection_reason'] ?? '') ?>">Reddedildi</span>
                                        <?php elseif ($s['is_active']): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pasif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <form method="POST" action="index.php?p=Stores&action=sync&id=<?= $s['id'] ?>" class="d-inline" onsubmit="var btn=this.querySelector('button'); btn.disabled=true; btn.innerHTML='<span class=\'spinner-border spinner-border-sm\'></span>';">
                                            <button type="submit" class="btn btn-sm btn-outline-warning me-1" title="NadirKitap ile Eşitle & Satılanları Düşür"><i class="bi bi-lightning-charge-fill"></i></button>
                                        </form>
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
