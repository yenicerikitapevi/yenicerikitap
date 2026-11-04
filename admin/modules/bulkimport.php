<?php
/**
 * ADMIN MODULE: Excel'den Toplu Ekle (p=BulkImport)
 * Yayınevi fiyat listesi gibi Excel/CSV dosyaları tarayıcıda okunur (sunucuda zip eklentisi gerekmez);
 * ISBN'li satırlar önizlenir, seçilenler tek tek admin/bulk_import.php'ye gönderilir (ISBN ile kapak ve bilgiler çekilir).
 */
if (!defined('SITE_URL')) exit;

if (empty($_SESSION['bulk_import_token'])) {
    $_SESSION['bulk_import_token'] = bin2hex(random_bytes(16));
}
$categories = db_fetch_all("SELECT id, name FROM categories ORDER BY name ASC");
$stores_all = db_fetch_all("SELECT id, name FROM stores WHERE approval_status = 'approved' OR approval_status IS NULL ORDER BY name ASC");
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel'den Toplu Kitap Ekle</h5>
        <p class="text-muted fs-7 mb-0">Yayınevi fiyat listesi gibi bir Excel (.xlsx, .xls) veya CSV dosyası seçin. ISBN/barkod içeren satırlar kitap olarak eklenir; kapak, yazar, yayınevi ve özet ISBN ile mağazalardan çekilir. Zaten kayıtlı ISBN'ler atlanır.</p>
    </div>
    <a href="index.php?p=Products" class="btn btn-outline-dark btn-sm"><i class="bi bi-arrow-left me-1"></i> Kitap Yönetimi</a>
</div>

<div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-lg-4">
            <label class="form-label fw-semibold fs-7" for="biFile">Dosya</label>
            <input type="file" id="biFile" class="form-control" accept=".xlsx,.xls,.csv,.ods">
        </div>
        <div class="col-lg-3">
            <label class="form-label fw-semibold fs-7" for="biCat">Kategori</label>
            <select id="biCat" class="form-select">
                <option value="auto" selected>Otomatik (ISBN bilgisinden / kitap adından)</option>
                <option value="0">-- Kategorisiz --</option>
                <?php foreach ($categories as $c): if (is_numeric_category_name($c['name'])) continue; ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3">
            <label class="form-label fw-semibold fs-7" for="biPub">Yayınevi <span class="text-muted fw-normal">(boşsa ISBN aramasından)</span></label>
            <input type="text" id="biPub" class="form-control" placeholder="Örn: Hız Yayınları">
        </div>
        <div class="col-lg-2">
            <label class="form-label fw-semibold fs-7" for="biStock">Stok</label>
            <input type="number" id="biStock" class="form-control" value="1" min="0">
        </div>
        <div class="col-lg-4">
            <label class="form-label fw-semibold fs-7" for="biStore">Satıcı</label>
            <select id="biStore" class="form-select">
                <option value="0">-- Ana Mağaza (Yeniçeri Kitabevi) --</option>
                <?php foreach ($stores_all as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-8 d-flex flex-wrap gap-4 fs-7">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="biLookup" checked><label class="form-check-label" for="biLookup">ISBN ile kapak, yazar ve özet çek <span class="text-muted">(yavaş: kitap başına birkaç saniye)</span></label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="biActive" checked><label class="form-check-label" for="biActive">Sitede görünsün</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="biUpdPrice"><label class="form-check-label" for="biUpdPrice">Zaten kayıtlı kitapların fiyatını Excel'deki fiyatla güncelle</label></div>
            <div class="w-100 text-muted fs-8">Aynı dosyayı tekrar yüklerseniz kayıtlı ISBN'ler <b>tekrar eklenmez</b> ("zaten kayıtlı" yazar); yalnızca yeni ISBN'ler eklenir. Kutu işaretliyse kayıtlı kitapların yalnızca fiyatı güncellenir (stok, bilgiler ve sahaf kitapları değişmez).</div>
        </div>
    </div>
</div>

<div id="biPreview" class="card border-0 shadow-sm rounded-4 p-4 d-none">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div class="fs-7" id="biSummary"></div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success fw-bold" id="biStart"><i class="bi bi-cloud-upload me-1"></i> Seçilenleri Ekle</button>
            <button type="button" class="btn btn-outline-danger d-none" id="biStop">Durdur</button>
        </div>
    </div>
    <div class="progress mb-3 d-none" id="biProgWrap" style="height: 8px;"><div class="progress-bar bg-success" id="biProg" style="width: 0%"></div></div>
    <div class="table-responsive" style="max-height: 65vh;">
        <table class="table table-sm align-middle fs-7 mb-0">
            <thead class="table-light" style="position: sticky; top: 0;"><tr>
                <th><input type="checkbox" class="form-check-input" id="biAll" checked></th>
                <th>ISBN</th><th>Kitap Adı</th><th>Yazar</th><th class="text-end">Fiyat</th><th>Grup / Not</th><th>Durum</th>
            </tr></thead>
            <tbody id="biBody"></tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
    const TOKEN = <?= json_encode($_SESSION['bulk_import_token']) ?>;
    const $ = id => document.getElementById(id);
    let rows = [], stop = false;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    function isbn13(s) {
        s = String(s || '').replace(/[\s\-]/g, '');
        if (/^\d{13}$/.test(s)) { let t = 0; for (let i = 0; i < 12; i++) t += +s[i] * (i % 2 ? 3 : 1); return (10 - t % 10) % 10 === +s[12] ? s : null; }
        if (/^\d{9}[\dXx]$/.test(s)) { let t = 0; for (let i = 0; i < 10; i++) t += (10 - i) * (/x/i.test(s[i]) ? 10 : +s[i]); if (t % 11) return null; const c = '978' + s.slice(0, 9); let u = 0; for (let i = 0; i < 12; i++) u += +c[i] * (i % 2 ? 3 : 1); return c + (10 - u % 10) % 10; }
        return null;
    }
    const num = v => { const s = String(v ?? '').replace(/[^\d,.]/g, ''); if (!s) return NaN; return parseFloat(s.includes(',') && !s.includes('.') ? s.replace(',', '.') : s.replace(/,(?=\d{3}\b)/g, '')); };

    /** Satırları tarar: başlık satırından sütunları, ISBN'siz tek hücreli satırlardan grup adını bulur */
    function parse(wb) {
        const out = [];
        let skipped = 0;
        wb.SheetNames.forEach(sn => {
            const data = XLSX.utils.sheet_to_json(wb.Sheets[sn], {header: 1, raw: false, defval: ''});
            let col = {}, group = '';
            data.forEach(r => {
                const cells = r.map(c => String(c).trim());
                const filled = cells.filter(Boolean);
                if (!filled.length) return;
                let isbnIdx = cells.findIndex(c => isbn13(c));
                if (isbnIdx < 0) {
                    // Başlık satırı mı?
                    const up = cells.map(c => c.toLocaleUpperCase('tr'));
                    if (up.some(c => /ISBN|BARKOD/.test(c)) || up.some(c => /F[İI]YAT/.test(c))) {
                        col = {};
                        up.forEach((c, i) => {
                            if (/ISBN|BARKOD/.test(c)) col.isbn = i;
                            else if (/YAZAR/.test(c)) col.author = i;
                            else if (/YAYINEV/.test(c)) col.pub = i;
                            else if (/F[İI]YAT/.test(c) && col.price === undefined) col.price = i;
                            else if (/STOK|DURUM/.test(c)) col.note = i;
                            else if (/ADI|ADI|K[İI]TAP|DERS|ÜRÜN|URUN/.test(c) && col.title === undefined) col.title = i;
                        });
                        if (filled.length <= 3 && !up.some(c => /ISBN|BARKOD/.test(c)) && col.price !== undefined && filled[0] && !/F[İI]YAT/.test(filled[0].toLocaleUpperCase('tr'))) group = filled[0];
                    } else if (filled.length === 1 || (filled.length <= 2 && filled.every(c => isNaN(num(c)) || num(c) === 0))) {
                        group = filled[0];
                        if (filled.length === 2 && cells.some(c => c && !isNaN(num(c)))) skipped++;
                    } else {
                        skipped++;
                    }
                    return;
                }
                const isbn = isbn13(cells[isbnIdx]);
                let title = col.title !== undefined && col.title !== isbnIdx ? cells[col.title] : '';
                if (!title) title = cells.filter((c, i) => i !== isbnIdx && c && !/^[d.,s₺TL%]+$/i.test(c)).sort((a, b) => b.length - a.length)[0] || '';
                let price = col.price !== undefined ? num(cells[col.price]) : NaN;
                if (isNaN(price)) { const ns = cells.map((c, i) => i !== isbnIdx && /^[\d.,\s₺TL]+$/i.test(c) ? num(c) : NaN).filter(n => !isNaN(n)); price = ns.length ? ns[ns.length - 1] : 0; }
                out.push({
                    isbn, title, price: price || 0,
                    author: col.author !== undefined ? cells[col.author] : '',
                    pub: col.pub !== undefined ? cells[col.pub] : '',
                    note: [group, col.note !== undefined ? cells[col.note] : '', col.price !== undefined && cells[col.price] && isNaN(num(cells[col.price])) ? 'Fiyat: ' + cells[col.price] : ''].filter(Boolean).join(' • ') + (wb.SheetNames.length > 1 ? ' [' + sn + ']' : ''),
                });
            });
        });
        // Aynı ISBN dosyada iki kez geçiyorsa bir kez eklenir
        const seen = new Set();
        return {rows: out.filter(r => !seen.has(r.isbn) && seen.add(r.isbn)), skipped};
    }

    function render(skipped) {
        $('biBody').innerHTML = rows.map((r, i) => `<tr data-i="${i}">
            <td><input type="checkbox" class="form-check-input bi-row" checked></td>
            <td class="font-monospace">${r.isbn}</td><td>${esc(r.title)}</td><td>${esc(r.author)}</td>
            <td class="text-end fw-semibold">${r.price ? r.price.toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺' : '<span class="text-danger" title="Fiyatsız kitap pasif eklenir">yok → pasif</span>'}</td>
            <td class="text-muted">${esc(r.note)}</td><td class="bi-st text-muted">bekliyor</td></tr>`).join('');
        $('biSummary').innerHTML = `<b>${rows.length}</b> ISBN'li satır bulundu` + (skipped ? ` • ISBN'i olmayan <b>${skipped}</b> satır atlandı (fasikül vb.)` : '') + '. Kontrol edip eklemek istemediklerinizin işaretini kaldırın.';
        $('biPreview').classList.remove('d-none');
    }

    $('biFile').addEventListener('change', async e => {
        const f = e.target.files[0];
        if (!f) return;
        try {
            const wb = XLSX.read(await f.arrayBuffer(), {type: 'array'});
            const res = parse(wb);
            rows = res.rows;
            const n = f.name.toLocaleUpperCase('tr');
            if (!$('biPub').value) $('biPub').value = /HIZ/.test(n) ? 'Hız Yayınları' : /FENOMEN/.test(n) ? 'Fenomen Yayıncılık' : '';
            render(res.skipped);
        } catch (err) {
            alert('Dosya okunamadı: ' + err.message);
        }
    });
    $('biAll').addEventListener('change', e => document.querySelectorAll('.bi-row').forEach(c => c.checked = e.target.checked));
    $('biStop').addEventListener('click', () => { stop = true; $('biStop').disabled = true; });

    async function send(i) {
        const r = rows[i], tr = document.querySelector(`tr[data-i="${i}"]`), st = tr.querySelector('.bi-st');
        st.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary"></span> ekleniyor…';
        const fd = new FormData();
        Object.entries({token: TOKEN, isbn: r.isbn, title: r.title, price: r.price, author: r.author, publisher: r.pub || $('biPub').value.trim(),
            category_id: $('biCat').value, note: r.note, store_id: $('biStore').value, stock: $('biStock').value, is_active: $('biActive').checked ? 1 : 0, lookup: $('biLookup').checked ? 1 : 0, update_price: $('biUpdPrice').checked ? 1 : 0})
            .forEach(([k, v]) => fd.append(k, v));
        try {
            const res = await fetch('bulk_import.php', {method: 'POST', body: fd, credentials: 'same-origin'});
            const j = await res.json();
            const cls = j.status === 'added' ? 'text-success' : j.status === 'exists' ? 'text-secondary' : 'text-danger';
            const link = j.id ? ` <a href="index.php?p=Products&action=edit&id=${j.id}" target="_blank">#${j.id}</a>` : '';
            st.innerHTML = `<span class="${cls}">${esc(j.message || j.status)}</span>${link}`;
            if (j.status === 'added' && j.title) tr.children[2].textContent = j.title;
            if (j.status === 'added' || j.status === 'exists') tr.querySelector('.bi-row').checked = false;
            return j.status;
        } catch (err) {
            st.innerHTML = '<span class="text-danger">bağlantı hatası</span>';
            return 'error';
        }
    }

    $('biStart').addEventListener('click', async () => {
        const todo = [...document.querySelectorAll('#biBody tr')].filter(tr => tr.querySelector('.bi-row').checked).map(tr => +tr.dataset.i);
        if (!todo.length) return alert('Seçili satır yok.');
        if (!confirm(todo.length + ' kitap eklenecek (stok: ' + $('biStock').value + '). Devam edilsin mi?')) return;
        stop = false;
        $('biStart').disabled = true; $('biStop').classList.remove('d-none'); $('biStop').disabled = false; $('biProgWrap').classList.remove('d-none');
        const count = {added: 0, exists: 0, error: 0};
        let done = 0, next = 0;
        const worker = async () => {
            while (!stop && next < todo.length) {
                const s = await send(todo[next++]);
                count[s in count ? s : 'error']++;
                $('biProg').style.width = (++done / todo.length * 100) + '%';
            }
        };
        await Promise.all([worker(), worker()]); // aynı anda 2 kitap: mağazalar yorulmasın
        $('biStart').disabled = false; $('biStop').classList.add('d-none');
        $('biSummary').innerHTML = `<b class="text-success">${count.added} eklendi</b> • ${count.exists} zaten kayıtlıydı • <span class="text-danger">${count.error} hata</span>` + (stop ? ' • <b>durduruldu</b>' : '') + '. Hatalı satırları tekrar seçip yeniden deneyebilirsiniz.';
    });
})();
</script>
