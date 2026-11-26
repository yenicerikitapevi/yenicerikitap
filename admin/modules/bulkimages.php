<?php
/**
 * ADMIN MODULE: Toplu Görsel Yükleme (p=BulkImages)
 * Bilgisayardaki fotoğraf klasörü seçilir; dosya adındaki NadirKitap kodu ya da ISBN'e göre kitaplara eşlenir,
 * önizlemeden sonra tarayıcı fotoğrafları küçük gruplar hâlinde yükler (admin/bulk_images_api.php).
 */
if (!defined('SITE_URL')) exit;

if (empty($_SESSION['bulk_images_token'])) $_SESSION['bulk_images_token'] = bin2hex(random_bytes(16));
$bimg_token = $_SESSION['bulk_images_token'];
$bimg_stores = db_fetch_all("SELECT id, name FROM stores ORDER BY name");
$bimg_runs = db_fetch_all("SELECT run_id, COUNT(*) AS books, MIN(created_at) AS started, MAX(created_by) AS who, SUM(CASE WHEN undone_at IS NULL THEN 1 ELSE 0 END) AS active FROM bulk_image_log GROUP BY run_id ORDER BY MIN(id) DESC LIMIT 10");
?>
<div class="mb-4">
    <h5 class="fw-bold text-dark mb-1">Toplu Görsel Yükleme</h5>
    <p class="text-muted fs-7 mb-0" style="max-width: 820px;">
        Bilgisayarınızdaki fotoğraf klasörünü seçin; her fotoğraf <b>dosya adındaki NadirKitap kodu ya da ISBN'e</b> göre kitabına eşlenir
        (ör. <code>5296984.jpg</code>, <code>5296984_2.jpg</code>, <code>9789753420012-arka.jpg</code> ya da <code>5296984/1.jpg</code> gibi kod adlı alt klasörler).
        Aynı kitabın fotoğrafları dosya adı sırasıyla dizilir; ilki kapak olur (en fazla 8). Önce eşleşme özeti gösterilir, onaylayınca yüklenir.
        Zip açmanıza gerek yok, klasörü doğrudan seçin.
    </p>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label fw-semibold fs-7" for="bimgDir">Fotoğraf klasörü</label>
                <input type="file" id="bimgDir" class="form-control" webkitdirectory directory multiple>
                <div class="form-text fs-8">Klasörü seçin (alt klasörler dahil). Tarayıcı "yükle" onayı sorabilir.</div>
            </div>
            <div class="col-lg-3">
                <label class="form-label fw-semibold fs-7" for="bimgScope">Hangi kitaplar</label>
                <select id="bimgScope" class="form-select">
                    <option value="yeniceri">Yeniçeri Kitabevi kitapları</option>
                    <?php foreach ($bimg_stores as $s): ?><option value="store:<?= (int)$s['id'] ?>"><?= e($s['name']) ?> (sahaf)</option><?php endforeach; ?>
                    <option value="all">Tüm kitaplar</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label fw-semibold fs-7" for="bimgMode">Mevcut görseller</label>
                <select id="bimgMode" class="form-select">
                    <option value="replace">Yeni fotoğraflarla değiştir</option>
                    <option value="empty">Yalnızca görseli olmayan kitaplara ekle</option>
                </select>
            </div>
            <div class="col-lg-2">
                <button type="button" id="bimgMatch" class="btn btn-dark w-100 fw-semibold" disabled><i class="bi bi-search me-1"></i> Eşleştir</button>
            </div>
        </div>
        <div id="bimgPreview" class="mt-4" hidden></div>
        <div id="bimgProgress" class="mt-3" hidden>
            <div class="progress" style="height: 22px;"><div class="progress-bar bg-danger fw-semibold" id="bimgBar" style="width: 0%">0%</div></div>
            <div class="fs-7 mt-2" id="bimgStatus"></div>
            <ul class="fs-8 text-danger mt-2 mb-0" id="bimgErrors"></ul>
        </div>
    </div>
</div>

<?php if ($bimg_runs): ?>
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white p-3 fw-bold">Son yüklemeler</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 fs-7">
            <thead class="table-light"><tr><th class="ps-3">Tarih</th><th>Kitap</th><th>Yapan</th><th class="text-end pe-3"></th></tr></thead>
            <tbody>
            <?php foreach ($bimg_runs as $r): ?>
                <tr>
                    <td class="ps-3"><?= date('d.m.Y H:i', strtotime($r['started'])) ?></td>
                    <td><?= (int)$r['books'] ?> kitap</td>
                    <td><?= e($r['who'] ?: '-') ?></td>
                    <td class="text-end pe-3">
                        <?php if (!(int)$r['active']): ?>
                            <span class="text-muted fs-8">Geri alındı</span>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary bimg-undo" data-run="<?= e($r['run_id']) ?>">Geri al</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white fs-8 text-muted">Geri al: o yüklemede değişen kitapların eski görselleri geri gelir. Eski görseller 30 gün saklanır.</div>
</div>
<?php endif; ?>

<script>
(function () {
    var API = 'bulk_images_api.php', TOKEN = <?= json_encode($bimg_token) ?>;
    var dir = document.getElementById('bimgDir'), btn = document.getElementById('bimgMatch');
    var prev = document.getElementById('bimgPreview'), prog = document.getElementById('bimgProgress');
    var bar = document.getElementById('bimgBar'), status = document.getElementById('bimgStatus'), errs = document.getElementById('bimgErrors');
    var files = [], plan = null, running = false;
    function esc(s) { var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
    function post(fd) {
        fd.append('token', TOKEN);
        return fetch(API, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) {
            return r.json().catch(function () { throw new Error('Sunucu yanıtı okunamadı (HTTP ' + r.status + ')'); });
        });
    }
    dir.addEventListener('change', function () {
        files = Array.prototype.filter.call(dir.files, function (f) { return /\.(jpe?g|png|webp)$/i.test(f.name); });
        btn.disabled = !files.length;
        prev.hidden = true; prog.hidden = true; plan = null;
        status.textContent = '';
        if (dir.files.length) { prev.hidden = false; prev.innerHTML = '<div class="text-muted fs-7">' + files.length + ' fotoğraf seçildi (' + dir.files.length + ' dosya). "Eşleştir"e basın.</div>'; }
    });
    btn.addEventListener('click', function () {
        if (!files.length || running) return;
        var fd = new FormData();
        fd.append('action', 'match');
        fd.append('scope', document.getElementById('bimgScope').value);
        fd.append('mode', document.getElementById('bimgMode').value);
        fd.append('names', JSON.stringify(files.map(function (f) { return f.webkitRelativePath || f.name; })));
        btn.disabled = true;
        prev.hidden = false; prev.innerHTML = '<div class="text-muted fs-7"><span class="spinner-border spinner-border-sm me-1"></span> Eşleştiriliyor…</div>';
        post(fd).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { prev.innerHTML = '<div class="alert alert-danger fs-7 mb-0">' + esc(r.message) + '</div>'; return; }
            plan = r.plan;
            var mb = 0; plan.forEach(function (p) { mb += files[p.i].size; }); mb = mb / 1048576;
            var h = '<div class="row g-3 mb-3">'
                + '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="fs-8 text-muted fw-semibold">EŞLEŞEN KİTAP</div><div class="fs-4 fw-bold text-success">' + r.books + '</div></div></div>'
                + '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="fs-8 text-muted fw-semibold">YÜKLENECEK</div><div class="fs-4 fw-bold">' + plan.length + ' fotoğraf</div><div class="fs-8 text-muted">' + r.books_planned + ' kitap · ' + mb.toFixed(0) + ' MB</div></div></div>'
                + '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="fs-8 text-muted fw-semibold">EŞLEŞMEYEN</div><div class="fs-4 fw-bold ' + (r.unmatched ? 'text-danger' : '') + '">' + r.unmatched + '</div><div class="fs-8 text-muted">fotoğraf</div></div></div>'
                + '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="fs-8 text-muted fw-semibold">BOŞ DİSK</div><div class="fs-4 fw-bold">' + (r.free_mb / 1024).toFixed(1) + ' GB</div></div></div></div>';
            if (r.skipped_has) h += '<div class="fs-7 text-muted mb-2">Görseli olduğu için atlanan kitap: ' + r.skipped_has + '</div>';
            if (r.over) h += '<div class="fs-7 text-muted mb-2">8\'den fazla fotoğrafı olan kitaplarda fazladan ' + r.over + ' fotoğraf alınmadı.</div>';
            if (mb > r.free_mb - 300) h += '<div class="alert alert-warning fs-7">Diskte yeterli yer olmayabilir; yükleme yer bitince durur. Önce Disk Kullanımı sayfasından yer açın.</div>';
            if (r.sample.length) {
                h += '<div class="fs-7 fw-semibold mb-1">Örnek eşleşmeler</div><ul class="fs-8 mb-3">';
                r.sample.forEach(function (s) { h += '<li>' + esc(s.title) + ' <span class="text-muted">(' + esc(s.code || '') + ') — ' + s.n + ' fotoğraf' + (s.has ? ', şu an görseli var' : '') + '</span></li>'; });
                h += '</ul>';
            }
            if (r.unmatched_sample.length) {
                h += '<details class="fs-8 mb-3"><summary class="text-danger">Eşleşmeyen dosyalardan örnekler (' + r.unmatched + ')</summary><ul class="mb-0 mt-1">';
                r.unmatched_sample.forEach(function (n) { h += '<li class="font-monospace">' + esc(n) + '</li>'; });
                h += '</ul><div class="text-muted mt-1">Dosya adında bu kapsamdaki bir kitabın NadirKitap kodu ya da ISBN\'i yok. Kapsamı "Tüm kitaplar" yapmayı deneyin.</div></details>';
            }
            if (plan.length) {
                var replace = document.getElementById('bimgMode').value === 'replace';
                h += '<button type="button" id="bimgStart" class="btn btn-danger fw-semibold"><i class="bi bi-cloud-upload me-1"></i> ' + plan.length + ' fotoğrafı yükle</button>'
                    + (replace ? ' <span class="fs-8 text-muted ms-2">Eşleşen kitapların mevcut görselleri bu fotoğraflarla değiştirilir (geri alınabilir).</span>' : '');
            } else {
                h += '<div class="alert alert-secondary fs-7 mb-0">Yüklenecek fotoğraf yok.</div>';
            }
            prev.innerHTML = h;
            var st = document.getElementById('bimgStart');
            if (st) st.addEventListener('click', function () { start(st); });
        }).catch(function (e) { btn.disabled = false; prev.innerHTML = '<div class="alert alert-danger fs-7 mb-0">' + esc(e.message) + '</div>'; });
    });
    function start(st) {
        if (running || !plan || !plan.length) return;
        running = true; st.disabled = true; btn.disabled = true; dir.disabled = true;
        prog.hidden = false; errs.innerHTML = '';
        var run = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
        var mode = document.getElementById('bimgMode').value;
        var idx = 0, saved = 0, books = 0, failed = 0, retries = 0;
        window.onbeforeunload = function () { return 'Yükleme sürüyor'; };
        function batch() {
            if (idx >= plan.length) return finish(true, '');
            var fd = new FormData(), bytes = 0, n = 0, end = idx;
            fd.append('action', 'upload'); fd.append('run', run); fd.append('mode', mode);
            while (end < plan.length && n < 25 && (n === 0 || bytes + files[plan[end].i].size < 20 * 1048576)) {
                var p = plan[end], f = files[p.i];
                fd.append('files[]', f, f.name); fd.append('book[]', p.book); fd.append('seq[]', p.seq);
                bytes += f.size; n++; end++;
            }
            post(fd).then(function (r) {
                if (!r.ok) {
                    if (r.stop) return finish(false, r.message);
                    throw new Error(r.message || 'Hata');
                }
                retries = 0;
                saved += r.saved; books += r.books;
                (r.errors || []).forEach(function (m) { failed++; if (errs.children.length < 50) { var li = document.createElement('li'); li.textContent = m; errs.appendChild(li); } });
                idx = end;
                var pc = Math.round(idx * 100 / plan.length);
                bar.style.width = pc + '%'; bar.textContent = pc + '%';
                status.textContent = idx + ' / ' + plan.length + ' fotoğraf gönderildi · ' + saved + ' kaydedildi · ' + (r.free_mb / 1024).toFixed(1) + ' GB boş disk';
                batch();
            }).catch(function (e) {
                if (++retries <= 3) { status.textContent = 'Bağlantı hatası, yeniden deneniyor (' + retries + '/3)…'; return setTimeout(batch, 3000 * retries); }
                finish(false, e.message + ' — "Eşleştir" ile tekrar başlatabilirsiniz; yüklenenler korunur.');
            });
        }
        function finish(ok, msg) {
            running = false; window.onbeforeunload = null; dir.disabled = false; btn.disabled = false;
            status.innerHTML = (ok ? '<b class="text-success">Tamamlandı.</b> ' : '<b class="text-danger">Durdu:</b> ' + esc(msg) + ' ') + saved + ' fotoğraf kaydedildi' + (failed ? ', ' + failed + ' dosya hatalı' : '') + '. <a href="index.php?p=BulkImages">Sayfayı yenile</a>';
        }
        batch();
    }
    document.querySelectorAll('.bimg-undo').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!confirm('Bu yüklemede değişen kitapların görselleri eski hâline döndürülsün mü?')) return;
            var fd = new FormData(); fd.append('action', 'undo'); fd.append('run', b.dataset.run);
            b.disabled = true;
            post(fd).then(function (r) { alert(r.message); location.reload(); }).catch(function (e) { alert(e.message); b.disabled = false; });
        });
    });
})();
</script>
