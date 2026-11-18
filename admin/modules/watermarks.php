<?php
if (!defined('SITE_URL')) exit;
require_once __DIR__ . '/../watermark_lib.php';
$_SESSION['watermark_csrf'] ??= bin2hex(random_bytes(32));
try {
    wm_init();
    $jobs = wm_exec('SELECT * FROM wm_jobs ORDER BY created_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>'; return;
}
?>
<p>Sunucudaki kapak ve ek fotoğraflar için önce temiz kopyalar hazırlanır. Önizlemeleri kontrol ettikten sonra yayımlayabilirsiniz. Dosyalar dışarıdaki bir yapay zekâ servisine gönderilmez.</p>
<div class="alert alert-info">Bu araç, mevcut temizleyicinin tanıdığı çapraz desen ve alt logo türlerini işler. Eşleşmeyen görseller atlanır. Filigranın örttüğü ayrıntılarda iz kalabilir; kusursuz sonuç garanti edilmez.</div>
<div class="card p-3 mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <button type="button" class="btn btn-outline-dark" id="wm-create">Görselleri tara ve yeni iş oluştur</button>
        <label for="wm-job">İş:</label>
        <select class="form-select w-auto" id="wm-job">
            <?php foreach ($jobs as $j): ?><option value="<?= e($j['id']) ?>"><?= e($j['created_at']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-primary" data-wm="prepare" data-limit="6">6 örnek hazırla</button>
        <button type="button" class="btn btn-outline-primary" data-wm="prepare">Tüm önizlemeleri hazırla</button>
        <button type="button" class="btn btn-success" data-wm="apply">Hazır kopyaları yayımla</button>
        <button type="button" class="btn btn-outline-danger" data-wm="restore">Bu işte yayımlananları geri al</button>
        <button type="button" class="btn btn-secondary" id="wm-stop" disabled>Duraklat</button>
    </div>
    <small class="text-muted mt-2">İşlem bu sayfa açıkken ilerler (her adımda birkaç görsel). Duraklatma, çalışan görsel tamamlandığında etkili olur. Sayfayı yeniden açıp devam edebilirsiniz. Yayımlanan görselin eski dosyası silinir; orijinalin yedeği işlem klasöründe kalır (geri almak için). Diskte 300 MB'tan az yer kalırsa işlem durur.</small>
</div>
<p id="wm-message" role="status" aria-live="polite"></p>
<p id="wm-counts"></p>
<div id="wm-items" class="row g-3"></div>
<div class="d-flex align-items-center gap-3 mt-3">
    <button type="button" class="btn btn-outline-secondary" id="wm-prev">Önceki</button>
    <span id="wm-page"></span>
    <button type="button" class="btn btn-outline-secondary" id="wm-next">Sonraki</button>
</div>
<script type="application/json" id="wm-config"><?= json_encode(['csrf' => $_SESSION['watermark_csrf']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/js/watermarks.js" defer></script>
