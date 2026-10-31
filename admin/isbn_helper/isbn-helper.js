/*!
 * ISBN Yardımcısı – "Yeni Kitap Ekle" formunun üstüne ISBN kutusu, barkod tarama, fotoğraftan tanıma
 * ve fiyat karşılaştırma ekler. Varsayılan olarak formu KAYDETMEZ; yalnızca alanları doldurur.
 * Ayarlar → "Fotoğraftan sonra otomatik kaydet" açıksa ve ad, yazar, yayınevi, fiyat bulunduysa
 * fotoğrafla eklenen kitap 3 sn sonra kaydedilir (iptal edilebilir).
 *
 * İsteğe bağlı ayar (script satırından ÖNCE):
 *   <script>window.ISBN_HELPER_CONFIG = { showcaseIds: ['3'] };</script>
 */
(function () {
  'use strict';
  if (window.__ISBN_HELPER__) return;
  window.__ISBN_HELPER__ = true;

  var me = document.currentScript;
  if (!me) {
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) { if (/isbn-helper\.js/.test(all[i].src)) { me = all[i]; break; } }
  }
  var BASE = me ? new URL('.', me.src).href : '/admin/isbn_helper/';
  var cfg = Object.assign({
    api: new URL('../isbn_lookup.php', BASE).href,
    coverApi: new URL('../isbn_cover.php', BASE).href,
    photoApi: new URL('../isbn_photo.php', BASE).href,
    localScanner: BASE + 'html5-qrcode.min.js',
    cdnScanner: 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
    autoNewAuthor: true,             // yazar listede birebir yoksa "+ Yeni yazar" olarak eklenir
    autoNewPublisher: true,          // yayınevi listede birebir yoksa "+ Yeni yayınevi" olarak eklenir
    showcaseIds: ['3'],              // varsayılan işaretlenecek vitrin(ler)in numarası
    fillCover: true
  }, window.ISBN_HELPER_CONFIG || {});

  /* ---------------- yardımcılar ---------------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function field(name) { return form ? form.elements[name] : null; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function tl(n) { return n == null ? '' : Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TL'; }

  function isbn13ok(s) { if (!/^\d{13}$/.test(s)) return false; var t = 0; for (var i = 0; i < 12; i++) t += +s[i] * (i % 2 ? 3 : 1); return (10 - t % 10) % 10 === +s[12]; }
  function isbn10ok(s) { if (!/^\d{9}[\dXx]$/.test(s)) return false; var t = 0; for (var i = 0; i < 10; i++) t += (10 - i) * (/x/i.test(s[i]) ? 10 : +s[i]); return t % 11 === 0; }
  function normIsbn(raw) {
    var s = String(raw || '').replace(/[\s\-]/g, '');
    if (isbn13ok(s)) return s;
    if (isbn10ok(s)) { var c = '978' + s.slice(0, 9), t = 0; for (var i = 0; i < 12; i++) t += +c[i] * (i % 2 ? 3 : 1); return c + (10 - t % 10) % 10; }
    return null;
  }

  var TR_MAP = { 'İ': 'i', 'I': 'i', 'ı': 'i', 'Ş': 's', 'ş': 's', 'Ğ': 'g', 'ğ': 'g', 'Ü': 'u', 'ü': 'u', 'Ö': 'o', 'ö': 'o', 'Ç': 'c', 'ç': 'c', 'Â': 'a', 'â': 'a', 'Î': 'i', 'î': 'i', 'Û': 'u', 'û': 'u' };
  function norm(s) {
    s = String(s || '').replace(/[İIıŞşĞğÜüÖöÇçÂâÎîÛû]/g, function (c) { return TR_MAP[c]; }).toLowerCase();
    s = s.normalize ? s.normalize('NFKD').replace(/[\u0300-\u036f]/g, '') : s;
    return s.replace(/[^a-z0-9]+/g, ' ').trim();
  }
  function realOptions(sel) {
    return Array.prototype.filter.call(sel.options, function (o) {
      return ['', '0', '__new'].indexOf(o.value) < 0 && !/^\s*\d+\s*$/.test(o.text); // numaralı (NadirKitap) kategoriler hariç
    });
  }
  /** Listede BİREBİR aynı ad (büyük/küçük harf, Türkçe karakter ve noktalama farkı yok sayılır). Benzer adlar eşleşmez. */
  function matchOption(sel, wanted) {
    var w = norm(wanted);
    if (!w || !sel) return null;
    var opts = realOptions(sel);
    for (var i = 0; i < opts.length; i++) if (norm(opts[i].text) === w) return { value: opts[i].value, text: opts[i].text.trim() };
    return null;
  }
  /** Kategori için yalnızca ÖNERİ (otomatik seçilmez): "Roman" → "Edebiyat & Roman" gibi */
  var CAT_HINTS = {
    'roman': 'edebiyat roman', 'edebiyat': 'edebiyat roman', 'hikaye': 'edebiyat roman', 'oyku': 'edebiyat roman', 'siir': 'edebiyat roman',
    'fantastik': 'edebiyat roman', 'polisiye': 'edebiyat roman', 'bilim kurgu': 'edebiyat roman', 'mizah': 'edebiyat roman', 'deneme': 'edebiyat roman',
    'felsefe': 'felsefe dusunce', 'psikoloji': 'felsefe dusunce', 'dusunce': 'felsefe dusunce', 'din': 'felsefe dusunce',
    'tarih': 'tarih arastirma', 'arastirma': 'tarih arastirma', 'biyografi': 'tarih arastirma', 'anilar': 'tarih arastirma',
    'cocuk': 'cocuk genclik', 'genclik': 'cocuk genclik', 'klasik': 'dunya klasikleri', 'klasikleri': 'dunya klasikleri',
    'defter': 'defter', 'ajanda': 'defter'
  };
  function suggestCategory(sel, wanted) {
    var w = norm(wanted);
    if (!w || !sel) return null;
    var opts = realOptions(sel), wt = w.split(' ');
    for (var i = 0; i < opts.length; i++) {
      var ot = norm(opts[i].text).split(' ');
      if (wt.every(function (t) { return ot.indexOf(t) >= 0; })) return { value: opts[i].value, text: opts[i].text.trim() };
    }
    for (var key in CAT_HINTS) {
      if (key === w || wt.some(function (t) { return t === key || (key.length >= 4 && key.indexOf(' ') < 0 && t.indexOf(key) === 0); })) {
        for (var j = 0; j < opts.length; j++) if (norm(opts[j].text) === CAT_HINTS[key]) return { value: opts[j].value, text: opts[j].text.trim() };
      }
    }
    return null;
  }

  function trTitle(s) {
    s = String(s || '').trim();
    var letters = s.replace(/[^A-Za-zÇĞİÖŞÜçğıöşü]/g, '');
    if (letters.length > 1 && letters === letters.toLocaleUpperCase('tr')) {
      return s.toLocaleLowerCase('tr').replace(/(^|[\s\-'’(])(\S)/g, function (m, a, b) { return a + b.toLocaleUpperCase('tr'); });
    }
    return s;
  }

  function fire(el, type) { el.dispatchEvent(new Event(type, { bubbles: true })); }
  function setVal(name, v) {
    var el = field(name);
    if (!el || v == null || v === '') return false;
    el.value = v;
    fire(el, 'input'); fire(el, 'change');
    return true;
  }

  /* Emin olunmayan alanlar: sarı zemin + kaynak etiketi. Alan elle değiştirilince işaret kalkar. */
  var FIELD_LABELS = { title: 'Kitap adı', original_title: 'Özgün ad', author_id: 'Yazar', publisher_id: 'Yayınevi', category_id: 'Kategori', pages: 'Sayfa', year: 'Yıl', summary: 'Özet', translator: 'Çevirmen', price: 'Fiyat', isbn: 'ISBN' };
  function clearMarks() {
    if (!form) return;
    form.querySelectorAll('.ih-unsure').forEach(function (el) { el.classList.remove('ih-unsure'); el.style.backgroundColor = ''; });
    form.querySelectorAll('.ih-src').forEach(function (el) { el.remove(); });
  }
  function mark(name, unsure, text) {
    var el = field(name);
    if (!el || el.length && !el.tagName) return;
    var old = el.parentNode.querySelector('.ih-src[data-for="' + name + '"]');
    if (old) old.remove();
    if (unsure) { el.classList.add('ih-unsure'); el.style.backgroundColor = '#fef9c3'; }
    if (!text) return;
    var b = document.createElement('small');
    b.className = 'ih-src d-block mt-1';
    b.dataset.for = name;
    b.style.cssText = 'font-size:11px;color:' + (unsure ? '#854d0e' : '#64748b');
    b.innerHTML = (unsure ? '⚠ ' : '') + text;
    el.insertAdjacentElement('afterend', b);
    var off = function () { el.classList.remove('ih-unsure'); el.style.backgroundColor = ''; el.removeEventListener('input', off); };
    if (unsure) el.addEventListener('input', off);
  }
  /** Sunucunun field_meta bilgisinden: tek kaynak (ya da kaynaklar arasında uyuşmazlık) → sarı */
  function markFromMeta(d, name, key) {
    var m = d.field_meta && d.field_meta[key || name];
    if (!m) return;
    var unsure = m.agree < 2;
    mark(name, unsure, 'kaynak: ' + esc(m.source) + (m.agree >= 2 ? ' (' + m.agree + ' kaynak aynı)' : ' — tek kaynak, kontrol edin'));
  }

  /* ---------------- panel ---------------- */
  var form = null, panel = null, ui = {}, lastData = null, scanner = null, scanning = false;

  function findForm() {
    var f = $('input[name="save_book"]');
    if (f && f.form) return f.form;
    var c = $('input[name="cover_file"]');
    return c ? c.form : null;
  }

  function buildPanel() {
    panel = document.createElement('div');
    panel.id = 'ih-panel';
    panel.className = 'border rounded-3 p-3 mb-3';
    panel.style.cssText = 'background:#fff7ed;border:1px solid #fdba74!important;border-radius:12px;';
    panel.innerHTML =
      '<div style="font-weight:700;margin-bottom:8px">📚 ISBN ile otomatik doldur ' +
      '<span style="font-weight:400;font-size:12px;color:#7c2d12">(barkod, fotoğraf ya da ISBN → bilgi, ikinci el/yeni fiyat, kapak; sarı alanları kontrol edin)</span></div>' +
      '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' +
      '<input type="text" id="ih-isbn" inputmode="numeric" autocomplete="off" placeholder="ISBN (örn. 9789750719387)" class="form-control" style="max-width:260px">' +
      '<button type="button" id="ih-go" class="btn btn-danger">Doldur</button>' +
      '<button type="button" id="ih-cam" class="btn btn-outline-dark">📷 Barkod tara</button>' +
      '<label class="btn btn-dark mb-0" style="cursor:pointer" title="Barkodu ya da kapağı çekin; barkod okunamazsa kapaktaki yazılar okunur">📸 Fotoğraf çek / yükle<input type="file" id="ih-file" accept="image/*" capture="environment" hidden></label>' +
      '</div>' +
      '<div id="ih-reader" style="max-width:440px;margin-top:10px;display:none"></div>' +
      '<div id="ih-reader-file" style="display:none"></div>' +
      '<div id="ih-status" style="margin-top:8px;font-size:14px" aria-live="polite"></div>' +
      '<div id="ih-result"></div>';
    form.insertBefore(panel, form.firstChild);
    ui.isbn = $('#ih-isbn', panel); ui.go = $('#ih-go', panel); ui.cam = $('#ih-cam', panel); ui.file = $('#ih-file', panel);
    ui.reader = $('#ih-reader', panel); ui.status = $('#ih-status', panel); ui.result = $('#ih-result', panel);

    ui.go.addEventListener('click', function () { lookup(ui.isbn.value); });
    ui.isbn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); lookup(ui.isbn.value); } });
    ui.isbn.addEventListener('paste', function () { setTimeout(function () { if (normIsbn(ui.isbn.value)) lookup(ui.isbn.value); }, 0); });
    ui.cam.addEventListener('click', toggleCamera);
    ui.file.addEventListener('change', function () { if (ui.file.files[0]) fromPhoto(ui.file.files[0]); ui.file.value = ''; });
    if (window.matchMedia && matchMedia('(pointer:fine)').matches) { try { ui.isbn.focus({ preventScroll: true }); } catch (e) {} }
  }

  function status(msg, kind) {
    var col = { ok: '#166534', err: '#b91c1c', warn: '#9a3412', info: '#334155' }[kind || 'info'];
    ui.status.innerHTML = '<span style="color:' + col + '">' + msg + '</span>';
  }
  function busy(on) { ui.go.disabled = on; ui.go.textContent = on ? 'Aranıyor…' : 'Doldur'; }

  /* ---------------- arama + doldurma ---------------- */
  var seq = 0;
  async function lookup(raw, opt) {
    opt = opt || {};
    cancelAutosave();
    var isbn = normIsbn(raw);
    if (!isbn) { status('Geçersiz ISBN. 13 haneli (978…/979…) ya da 10 haneli ISBN girin.', 'err'); return; }
    ui.isbn.value = isbn;
    var my = ++seq;
    busy(true); ui.result.innerHTML = ''; clearMarks();
    status('Kitap ve fiyatlar aranıyor… (Nadir Kitap, Kitantik, BKM, Kitapsepeti, D&R, Kitap Ambarı, Kitapyurdu, Open Library) — 10-30 sn sürebilir.');
    var data;
    try {
      var res = await fetch(cfg.api + '?isbn=' + isbn, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      var txt = await res.text();
      try { data = JSON.parse(txt); } catch (e) { throw new Error(res.status === 403 || /login|password/i.test(txt) ? 'Oturum geçersiz görünüyor. Panele yeniden giriş yapıp sayfayı yenileyin.' : 'Sunucu beklenmeyen bir yanıt verdi (HTTP ' + res.status + ').'); }
      if (!res.ok && !data.error) throw new Error('HTTP ' + res.status);
    } catch (e) { if (my === seq) { busy(false); status('Hata: ' + esc(e.message), 'err'); } return; }
    if (my !== seq) return;
    busy(false);
    if (!data.ok) {
      if (opt.ai && opt.ai.fields) { // fotoğraftan okunan bilgilerle devam
        var n2 = await applyPhotoFields(opt.ai, isbn);
        status(esc(data.error || 'Kayıt bulunamadı.') + ' Fotoğraftan okunan bilgiler yazıldı (sarı alanları kontrol edin).<br>' + n2.join('<br>'), 'warn');
      } else {
        status(esc(data.error || 'Kayıt bulunamadı.') + ' Alanları elle doldurabilirsiniz.', 'err');
        if (opt.photoCover) offerPhotoCover(opt.photoCover, true);
      }
      showExisting(data.existing);
      renderSources(data); return;
    }
    lastData = data;
    var notes = await apply(data, opt);
    var sum = '<b>' + esc(data.title) + '</b> doldurdum.';
    status(sum + (notes.length ? '<br>' + notes.join('<br>') : ''), notes.some(function (n) { return /dikkat/i.test(n); }) ? 'warn' : 'ok');
    renderPrices(data);
    showExisting(data.existing);
    if (opt.fromPhoto) maybeAutosave(data);
  }

  /* ---------------- aynı ISBN kayıtlıysa ---------------- */
  function showExisting(ex) {
    var box = document.getElementById('ih-existing');
    if (box) box.remove();
    if (!ex) return;
    box = document.createElement('div');
    box.id = 'ih-existing';
    box.className = 'alert alert-danger py-2 mt-2 mb-0';
    box.innerHTML = '⛔ <b>Bu ISBN zaten kayıtlı:</b> #' + ex.id + ' ' + esc(ex.title) +
      ' — <a href="' + esc(ex.edit_url) + '">Düzenle</a>' + (ex.url ? ' · <a href="' + esc(ex.url) + '" target="_blank" rel="noopener">Sitede gör ↗</a>' : '') +
      '<br><small>Aynı ISBN ile ikinci kayıt oluşturulmaz; Kaydet\'e basarsanız sistem reddeder.</small>';
    ui.status.insertAdjacentElement('afterend', box);
  }

  /* ---------------- otomatik kaydet (yalnızca fotoğraf akışında, ayardan açılır) ---------------- */
  var helperCfg = { autosave: false, ai_enabled: false }, autoTimer = null;
  function loadHelperConfig() {
    fetch(cfg.api + '?config=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) helperCfg = j; })
      .catch(function () {});
  }
  function cancelAutosave() {
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    var b = document.getElementById('ih-auto'); if (b) b.remove();
  }
  function maybeAutosave(d) {
    if (!helperCfg.autosave) return;
    var missing = [];
    if (!(field('title') || {}).value) missing.push('kitap adı');
    var a = field('author_id'), p = field('publisher_id');
    if (!a || !a.value || (a.value === '__new' && !(field('new_author_name') || {}).value)) missing.push('yazar');
    if (!p || !p.value || (p.value === '__new' && !(field('new_publisher_name') || {}).value)) missing.push('yayınevi');
    if (!(field('price') || {}).value) missing.push('fiyat');
    var c = field('category_id');
    if (c && c.required && (!c.value || (c.value === '__new' && !(field('new_category_name') || {}).value))) missing.push('kategori (formda zorunlu)');
    var box = document.createElement('div');
    box.id = 'ih-auto';
    box.className = 'alert py-2 mt-2 mb-0 ' + (missing.length || d.existing ? 'alert-secondary' : 'alert-success');
    if (d.existing) { box.innerHTML = 'Otomatik kayıt yapılmadı: bu ISBN zaten kayıtlı.'; ui.result.insertAdjacentElement('beforebegin', box); return; }
    if (missing.length) { box.innerHTML = 'Otomatik kayıt yapılmadı, eksik: <b>' + missing.join(', ') + '</b>. Tamamlayıp Kaydet\'e basın.'; ui.result.insertAdjacentElement('beforebegin', box); return; }
    var left = 3;
    box.innerHTML = '💾 <span id="ih-auto-t">' + left + '</span> sn sonra otomatik kaydedilecek. <button type="button" class="btn btn-sm btn-outline-dark ms-2">İptal</button>';
    ui.result.insertAdjacentElement('beforebegin', box);
    box.querySelector('button').addEventListener('click', function () { cancelAutosave(); status('Otomatik kayıt iptal edildi. Kontrol edip Kaydet\'e basın.', 'info'); });
    autoTimer = setInterval(function () {
      left--;
      var t = document.getElementById('ih-auto-t'); if (t) t.textContent = left;
      if (left > 0) return;
      cancelAutosave();
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    }, 1000);
  }

  /** Yazar/yayınevi: listede birebir aynı ad varsa o seçilir, yoksa "+ Yeni" ile eklenir (benzer adlar eşleştirilmez). */
  function chooseEntity(sel, newInputName, name, opts) {
    if (!sel || !name) return null;
    var m = opts.match(sel, name);
    if (m) { sel.value = m.value; fire(sel, 'change'); return '✔ ' + opts.label + ': mevcut kayıt seçildi → <b>' + esc(m.text) + '</b>'; }
    if (opts.auto) {
      sel.value = '__new'; fire(sel, 'change');
      var ni = field(newInputName);
      if (ni) { ni.value = name; fire(ni, 'input'); }
      mark(newInputName, true, 'listede birebir yok, yeni kayıt olarak eklenecek — yazımı kontrol edin');
      return '➕ ' + opts.label + ': listede birebir yok, <b>yeni kayıt</b> olarak eklenecek → <b>' + esc(name) + '</b> (dikkat: yazımı kontrol edin)';
    }
    return '⚠ ' + opts.label + ' eşleşmedi (“' + esc(name) + '”). <b>Dikkat:</b> listeden seçin ya da “+ Yeni … ekle”yi kullanın.';
  }
  function matchAuthor(sel, n) {
    var m = matchOption(sel, n); if (m) return m;
    var parts = n.split(/\s*(?:,| ve | & |;)\s*/);
    for (var i = 0; parts.length > 1 && i < parts.length; i++) { m = matchOption(sel, parts[i]); if (m) return m; }
    return null;
  }

  /** Kategori: yalnızca birebir aynı ad seçilir; değilse boş bırakılır, öneri düğmesi gösterilir */
  function chooseCategory(catName, srcText) {
    var cs = field('category_id');
    if (!cs) return '';
    if (!catName) { mark('category_id', true, 'kategori bilgisi gelmedi — listeden seçin'); return '⚠ <b>Dikkat:</b> kategori bilgisi gelmedi, listeden seçin.'; }
    var m = matchOption(cs, catName);
    if (m) { cs.value = m.value; fire(cs, 'change'); mark('category_id', false, srcText || ''); return '✔ Kategori: <b>' + esc(m.text) + '</b>'; }
    cs.value = ''; fire(cs, 'change');
    var sug = suggestCategory(cs, catName);
    mark('category_id', true, 'kaynakta “' + esc(catName) + '” yazıyor; listede birebir yok' + (sug ? ' — <a href="#" class="ih-cat-sug" data-v="' + esc(sug.value) + '">“' + esc(sug.text) + '” seç</a>' : ''));
    var link = cs.parentNode.querySelector('.ih-cat-sug');
    if (link) link.addEventListener('click', function (e) { e.preventDefault(); cs.value = link.dataset.v; fire(cs, 'change'); mark('category_id', false, 'öneri seçildi'); });
    return '⚠ <b>Dikkat:</b> kategori boş bırakıldı (kaynak: “' + esc(catName) + '”' + (sug ? ', öneri: ' + esc(sug.text) : '') + '). Listeden seçin.';
  }

  function checkShowcases() {
    (cfg.showcaseIds || []).forEach(function (id) {
      var cb = form.querySelector('input[type=checkbox][name="showcases[]"][value="' + id + '"]');
      if (cb && !cb.checked) { cb.checked = true; fire(cb, 'change'); }
    });
  }

  async function apply(d, opt) {
    opt = opt || {};
    var notes = [];
    // Fotoğraftan okunan bilgiler: mağazalarda bulunamayan alanları tamamlar (sarı işaretlenir)
    var aiF = (opt.ai && opt.ai.fields) || {}, aiUsed = {};
    ['title', 'author', 'publisher', 'original_title', 'year'].forEach(function (k) {
      var weak = !d[k] || (k === 'title' && d.field_meta && d.field_meta.title && d.field_meta.title.agree === 0);
      if (aiF[k] && weak) { d[k] = aiF[k]; aiUsed[k] = true; if (d.field_meta) delete d.field_meta[k]; }
    });
    setVal('title', trTitle(d.title)); markFromMeta(d, 'title');
    if (setVal('original_title', d.original_title)) markFromMeta(d, 'original_title');
    setVal('isbn', d.isbn);
    if (d.pages && setVal('pages', d.pages)) markFromMeta(d, 'pages');
    if (d.year && setVal('year', d.year)) markFromMeta(d, 'year');
    if (d.summary && setVal('summary', d.summary)) markFromMeta(d, 'summary');
    var tr = opt.ai && opt.ai.fields && opt.ai.fields.translator;
    if (tr && field('translator') && !field('translator').value) { setVal('translator', trTitle(tr)); mark('translator', true, 'fotoğraftan okundu'); }
    var pf = field('price'), op = field('old_price');
    if (d.suggested_price != null && pf && !pf.readOnly) {
      setVal('price', Number(d.suggested_price).toFixed(2));
      mark('price', false, 'kaynak: ' + esc(d.suggested_source || ''));
    }
    if (op && !op.readOnly) {
      op.value = d.suggested_old_price != null ? Number(d.suggested_old_price).toFixed(2) : ''; fire(op, 'input');
      if (d.suggested_old_price != null) mark('old_price', false, 'kaynak: ' + esc(d.old_price_source || 'liste fiyatı'));
    }
    if (d.suggested_price != null) notes.push('💰 Satış fiyatı: <b>' + tl(d.suggested_price) + '</b> (' + esc(d.suggested_source || '') + ')' + (d.suggested_old_price ? ', eski fiyat ' + tl(d.suggested_old_price) : ''));
    else notes.push('⚠ <b>Dikkat:</b> hiçbir sitede fiyat bulunamadı, fiyatı elle girin.');

    var a = chooseEntity(field('author_id'), 'new_author_name', trTitle(d.author), { label: 'Yazar', auto: cfg.autoNewAuthor, match: matchAuthor });
    notes.push(a || '⚠ <b>Dikkat:</b> yazar bulunamadı, elle seçin.');
    if (a) markFromMeta(d, field('author_id').value === '__new' ? 'new_author_name' : 'author_id', 'author');
    var p = chooseEntity(field('publisher_id'), 'new_publisher_name', trTitle(d.publisher), { label: 'Yayınevi', auto: cfg.autoNewPublisher, match: matchOption });
    notes.push(p || '⚠ <b>Dikkat:</b> yayınevi bulunamadı, elle seçin.');
    if (p) markFromMeta(d, field('publisher_id').value === '__new' ? 'new_publisher_name' : 'publisher_id', 'publisher');
    var cm = d.field_meta && d.field_meta.category;
    notes.push(chooseCategory(trTitle(d.category), cm ? 'kaynak: ' + esc(cm.source) : ''));
    checkShowcases();
    var AIMAP = { title: 'title', original_title: 'original_title', year: 'year',
      author: field('author_id') && field('author_id').value === '__new' ? 'new_author_name' : 'author_id',
      publisher: field('publisher_id') && field('publisher_id').value === '__new' ? 'new_publisher_name' : 'publisher_id' };
    Object.keys(aiUsed).forEach(function (k) { mark(AIMAP[k], true, 'fotoğraftan okundu — kontrol edin'); });

    if (cfg.fillCover) {
      var urls = (d.covers || []).map(function (c) { return c.url; });
      var cn = urls.length ? await fillCover(urls) : null;
      if (cn && cn.ok) { notes.push(cn.msg); if (opt.photoCover) offerPhotoCover(opt.photoCover, false); }
      else if (opt.photoCover) { notes.push(await usePhotoCover(opt.photoCover)); }
      else notes.push(cn ? cn.msg : '⚠ Kapak görseli bulunamadı (elle yükleyin).');
    }
    if (!d.summary) notes.push('ℹ Özet bulunamadı.');
    return notes;
  }

  /** ISBN bulunamadığında fotoğraftan okunan alanlar (hepsi sarı) */
  async function applyPhotoFields(ai, isbn) {
    var f = ai.fields || {}, notes = [];
    var src = 'fotoğraftan okundu — kontrol edin';
    if (setVal('title', trTitle(f.title))) mark('title', true, src);
    if (setVal('original_title', f.original_title)) mark('original_title', true, src);
    if (setVal('translator', trTitle(f.translator))) mark('translator', true, src);
    if (f.year && setVal('year', f.year)) mark('year', true, src);
    if (isbn) setVal('isbn', isbn);
    var a = chooseEntity(field('author_id'), 'new_author_name', trTitle(f.author), { label: 'Yazar', auto: cfg.autoNewAuthor, match: matchAuthor });
    if (a) { notes.push(a); mark(field('author_id').value === '__new' ? 'new_author_name' : 'author_id', true, src); }
    var p = chooseEntity(field('publisher_id'), 'new_publisher_name', trTitle(f.publisher), { label: 'Yayınevi', auto: cfg.autoNewPublisher, match: matchOption });
    if (p) { notes.push(p); mark(field('publisher_id').value === '__new' ? 'new_publisher_name' : 'publisher_id', true, src); }
    notes.push(chooseCategory('', ''));
    checkShowcases();
    if (ai.cover_data) notes.push(await usePhotoCover(ai.cover_data));
    notes.push('⚠ <b>Dikkat:</b> fiyat bulunamadı, elle girin.');
    return notes;
  }

  /* ---------------- kapak ---------------- */
  function setCoverFile(blob, name) {
    var input = field('cover_file');
    if (!input) return false;
    var file = new File([blob], name, { type: 'image/jpeg' });
    var dt = new DataTransfer(); dt.items.add(file);
    input.files = dt.files;
    var pv = document.getElementById('coverPreview'); if (pv) pv.src = URL.createObjectURL(file);
    return true;
  }
  async function usePhotoCover(dataUrl) {
    try {
      var blob = await (await fetch(dataUrl)).blob();
      setCoverFile(blob, 'kapak-foto-' + (ui.isbn.value || 'kitap') + '.jpg');
      return '🖼 Kapak olarak <b>kendi fotoğrafınız</b> (600×900) eklendi.';
    } catch (e) { return '⚠ Fotoğraf kapak olarak eklenemedi.'; }
  }
  /** İnternetten kapak kullanıldıysa yedek olarak "Kendi fotoğrafımı kullan" düğmesi */
  function offerPhotoCover(dataUrl, primary) {
    var old = document.getElementById('ih-photo-cover'); if (old) old.remove();
    var input = field('cover_file');
    if (!input || !dataUrl) return;
    var b = document.createElement('button');
    b.type = 'button'; b.id = 'ih-photo-cover';
    b.className = 'btn btn-sm ' + (primary ? 'btn-dark' : 'btn-outline-secondary') + ' mt-2';
    b.innerHTML = '<img src="' + dataUrl + '" style="height:36px;border-radius:3px;margin-right:6px">Kendi fotoğrafımı kapak yap';
    b.addEventListener('click', async function () { status(await usePhotoCover(dataUrl), 'ok'); });
    input.insertAdjacentElement('afterend', b);
  }

  async function fillCover(urls) {
    if (!field('cover_file')) return null;
    try {
      var qs = urls.slice(0, 6).map(function (u) { return 'u[]=' + encodeURIComponent(u); }).join('&');
      var res = await fetch(cfg.coverApi + '?' + qs, { credentials: 'same-origin' });
      if (!res.ok || !/image\//.test(res.headers.get('content-type') || '')) throw new Error('kapak indirilemedi');
      var blob = await res.blob();
      setCoverFile(blob, 'kapak-' + (ui.isbn.value || 'kitap') + '.jpg');
      return { ok: true, msg: '🖼 Kapak 600×900 olarak forma eklendi (' + Math.round(blob.size / 1024) + ' KB).' };
    } catch (e) {
      return { ok: false, msg: '⚠ <b>Dikkat:</b> kapak otomatik eklenemedi (' + esc(e.message) + '); elle yükleyin.' };
    }
  }

  function renderSources(d) {
    if (!d || !d.sources) return;
    ui.result.innerHTML = '<details style="margin-top:8px;font-size:13px"><summary>Kaynak durumu</summary>' + d.sources.map(function (s) {
      return '<div>' + (s.ok ? '✅' : '❌') + ' ' + esc(s.store) + (s.error ? ' – ' + esc(s.error) : '') + '</div>';
    }).join('') + '</details>';
  }

  function renderPrices(d) {
    var rows = (d.prices || []).map(function (p, i) {
      return '<tr' + (p.cheapest ? ' style="background:#dcfce7"' : '') + '>' +
        '<td>' + esc(p.store) + (p.cheapest ? ' <b>★ en uygun</b>' : '') + '</td>' +
        '<td><b>' + tl(p.price) + '</b></td><td>' + (p.list_price ? '<s>' + tl(p.list_price) + '</s>' : '') + '</td>' +
        '<td>' + (p.in_stock ? 'Stokta' : '<span style="color:#b91c1c">Stok yok</span>') + '</td>' +
        '<td>' + (p.url ? '<a href="' + esc(p.url) + '" target="_blank" rel="noopener">Sayfa ↗</a>' : '') + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger ih-use" data-i="' + i + '">Bu fiyatı kullan</button></td></tr>';
    }).join('');
    var lrows = (d.listings || []).map(function (l, i) {
      return '<tr' + (i === 0 ? ' style="background:#dcfce7"' : '') + '>' +
        '<td>' + esc(l.store) + (i === 0 ? ' <b>★ en uygun</b>' : '') + '</td><td>' + esc(l.seller || '—') + '</td><td>' + esc(l.condition || '—') + '</td>' +
        '<td><b>' + tl(l.price) + '</b></td><td>' + (l.shipping == null ? '—' : (l.shipping ? tl(l.shipping) : 'Ücretsiz')) + '</td>' +
        '<td>' + (l.url ? '<a href="' + esc(l.url) + '" target="_blank" rel="noopener">İlan ↗</a>' : '') + (l.matched_by && l.matched_by !== 'ISBN' ? ' <small title="ISBN ile bulunamadı; ad ve yazarla eşleşti">(ad ile)</small>' : '') + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger ih-use-l" data-i="' + i + '">Bu fiyatı kullan</button></td></tr>';
    }).join('');
    var html = '<div style="margin-top:10px;font-weight:600">İkinci el ilanlar (Nadir Kitap, Kitantik) — fiyat önceliği</div>';
    html += lrows ? '<div style="overflow-x:auto"><table class="table table-sm align-middle mb-0" style="font-size:14px;background:#fff"><thead><tr><th>Site</th><th>Satıcı</th><th>Durum</th><th>Fiyat</th><th>Kargo</th><th></th><th></th></tr></thead><tbody>' + lrows + '</tbody></table></div>' : '<div style="font-size:14px">İlan bulunamadı; fiyat yeni kitap mağazalarından önerildi.</div>';
    html += '<div style="margin-top:10px;font-weight:600">Yeni kitap mağazaları</div>';
    html += rows ? '<div style="overflow-x:auto"><table class="table table-sm align-middle mb-0" style="font-size:14px;background:#fff"><thead><tr><th>Mağaza</th><th>Fiyat</th><th>Liste</th><th>Stok</th><th></th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div style="font-size:14px">Fiyat bulunamadı.</div>';
    ui.result.innerHTML = html;
    Array.prototype.forEach.call(ui.result.querySelectorAll('.ih-use, .ih-use-l'), function (b) {
      b.addEventListener('click', function () {
        var p = b.classList.contains('ih-use-l') ? d.listings[+b.dataset.i] : d.prices[+b.dataset.i];
        var src = esc(p.store) + (p.seller ? ' · ' + esc(p.seller) : '');
        setVal('price', Number(p.price).toFixed(2));
        mark('price', false, 'kaynak: ' + src);
        status('Satış fiyatı <b>' + tl(p.price) + '</b> (' + src + ') olarak ayarlandı.', 'ok');
      });
    });
    renderSourcesAppend(d);
  }
  function renderSourcesAppend(d) {
    var el = document.createElement('div');
    el.innerHTML = '<details style="margin-top:8px;font-size:13px"><summary>Kaynak durumu</summary>' + (d.sources || []).map(function (s) {
      return '<div>' + (s.ok ? '✅' : '❌') + ' ' + esc(s.store) + (s.error ? ' – ' + esc(s.error) : '') + '</div>';
    }).join('') + '</details>';
    ui.result.appendChild(el);
  }

  /* ---------------- barkod: kamera ve fotoğraf ---------------- */
  var libPromise = null;
  function loadScript(src) {
    return new Promise(function (ok, fail) { var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = function () { fail(new Error('yüklenemedi')); }; document.head.appendChild(s); });
  }
  function loadScanner() {
    if (window.Html5Qrcode) return Promise.resolve();
    if (!libPromise) libPromise = loadScript(cfg.localScanner).catch(function () { return loadScript(cfg.cdnScanner); });
    return libPromise.then(function () { if (!window.Html5Qrcode) throw new Error('tarama kütüphanesi yüklenemedi'); });
  }
  function scanFormats() { var F = window.Html5QrcodeSupportedFormats; return F ? [F.EAN_13, F.EAN_8] : undefined; }

  async function toggleCamera() {
    if (scanning) { await stopCamera(); return; }
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      status('Kamera yalnızca https:// adresinde çalışır (ve tarayıcıda kamera izni verilmelidir). Bunun yerine “Barkod fotoğrafı yükle”yi kullanabilirsiniz.', 'err'); return;
    }
    try {
      status('Kamera açılıyor…'); await loadScanner();
      ui.reader.style.display = 'block';
      scanner = new Html5Qrcode('ih-reader', { formatsToSupport: scanFormats(), useBarCodeDetectorIfSupported: true, verbose: false });
      scanning = true; ui.cam.textContent = '✖ Kamerayı kapat';
      await scanner.start({ facingMode: 'environment' }, { fps: 10, videoConstraints: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }, qrbox: function (w, h) { return { width: Math.floor(Math.min(w * 0.9, 460)), height: Math.floor(Math.min(h * 0.6, 200)) }; } }, function (text) {
        var isbn = normIsbn(text);
        if (!isbn) return;                          // fiyat/başka barkodları yok say
        stopCamera().then(function () { ui.isbn.value = isbn; lookup(isbn); });
      }, function () {});
      status('Barkodu (kitabın arkasındaki 978… ile başlayan) kameraya gösterin.');
    } catch (e) {
      scanning = false; ui.cam.textContent = '📷 Barkod tara'; ui.reader.style.display = 'none';
      status('Kamera açılamadı: ' + esc(e && e.message ? e.message : e) + '. İzin verdiğinizden emin olun ya da fotoğraf yükleyin.', 'err');
    }
  }
  async function stopCamera() {
    try { if (scanner) { await scanner.stop(); scanner.clear(); } } catch (e) {}
    scanner = null; scanning = false; ui.cam.textContent = '📷 Barkod tara'; ui.reader.style.display = 'none';
  }

  function resizeFile(file, maxW) {
    return new Promise(function (ok, fail) {
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var k = Math.min(1, maxW / img.width), c = document.createElement('canvas');
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) { URL.revokeObjectURL(url); b ? ok(new File([b], 'b.png', { type: 'image/png' })) : fail(); }, 'image/png');
      };
      img.onerror = function () { fail(new Error('görsel okunamadı')); };
      img.src = url;
    });
  }
  /** Fotoğraftaki ISBN barkodu (yoksa null) */
  async function findBarcode(file) {
    var found = null;
    try {                                              // 1) tarayıcının yerleşik okuyucusu varsa
      if ('BarcodeDetector' in window) {
        var det = new BarcodeDetector({ formats: ['ean_13', 'ean_8'] });
        var bmp = await createImageBitmap(file);
        var r = await det.detect(bmp);
        for (var i = 0; i < r.length && !found; i++) found = normIsbn(r[i].rawValue);
      }
    } catch (e) {}
    if (!found) {
      try {                                            // 2) html5-qrcode (önce orijinal, sonra küçültülmüş)
        await loadScanner();
        var q = new Html5Qrcode('ih-reader-file', { formatsToSupport: scanFormats(), useBarCodeDetectorIfSupported: false, verbose: false });
        var tries = [file];
        try { tries.push(await resizeFile(file, 1000)); tries.push(await resizeFile(file, 600)); } catch (e) {}
        for (var t = 0; t < tries.length && !found; t++) {
          try { found = normIsbn(await q.scanFile(tries[t], false)); } catch (e) {}
        }
        try { q.clear(); } catch (e) {}
      } catch (e) {}
    }
    return found;
  }

  /** Telefonla fotoğraf: önce barkod; yoksa sunucuda yazılar okunur (Claude), ISBN bulunursa normal arama */
  async function fromPhoto(file) {
    cancelAutosave();
    var my = ++seq;
    busy(true); ui.result.innerHTML = '';
    status('Fotoğraftaki barkod aranıyor…');
    var isbn = await findBarcode(file);
    if (my !== seq) return;
    if (isbn) { busy(false); ui.isbn.value = isbn; lookup(isbn, { fromPhoto: true }); return; }
    status('Barkod okunamadı; kapaktaki yazılar okunuyor (10-20 sn)…');
    var ai;
    try {
      var small = await resizeJpeg(file, 2000);
      var fd = new FormData(); fd.append('photo', small, 'foto.jpg');
      var res = await fetch(cfg.photoApi, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      var txt = await res.text();
      try { ai = JSON.parse(txt); } catch (e) { throw new Error(res.status === 403 ? 'Oturum geçersiz; panele yeniden giriş yapın.' : 'Sunucu beklenmeyen bir yanıt verdi (HTTP ' + res.status + ').'); }
    } catch (e) { if (my === seq) { busy(false); status('Hata: ' + esc(e.message), 'err'); } return; }
    if (my !== seq) return;
    busy(false);
    if (!ai.ok) {
      status(esc(ai.error || 'Fotoğraf okunamadı.'), 'err');
      if (ai.cover_data) offerPhotoCover(ai.cover_data, true);
      return;
    }
    if (ai.isbn) {
      ui.isbn.value = ai.isbn;
      lookup(ai.isbn, { fromPhoto: true, ai: ai, photoCover: ai.cover_data });
      return;
    }
    clearMarks();
    var notes = await applyPhotoFields(ai, null);
    status('ISBN fotoğrafta görünmüyor; okunan bilgiler yazıldı (sarı alanları kontrol edin).<br>' + notes.join('<br>'), 'warn');
    renderCandidates(ai);
  }
  /** ISBN adayları (ad + yazar ile bulunan baskılar) */
  function renderCandidates(ai) {
    var c = ai.isbn_candidates || [];
    if (!c.length) { ui.result.innerHTML = '<div style="font-size:13px;margin-top:6px">Bu ad ve yazarla kayıtlı bir baskı bulunamadı. Kitabın künye sayfasını (ISBN yazan sayfa) çekip tekrar deneyebilirsiniz.</div>'; return; }
    ui.result.innerHTML = '<div style="margin-top:10px;font-weight:600">Olası baskılar — doğru olanı seçin</div>' +
      '<div style="display:flex;flex-direction:column;gap:4px;margin-top:4px">' + c.map(function (x, i) {
        return '<div><button type="button" class="btn btn-sm btn-outline-dark ih-cand" data-i="' + i + '">' + esc(x.isbn) + '</button> ' +
          esc(x.title) + (x.publisher ? ' — ' + esc(x.publisher) : '') + ' <small class="text-muted">(' + esc(x.source) + ')</small></div>';
      }).join('') + '</div>';
    ui.result.querySelectorAll('.ih-cand').forEach(function (b) {
      b.addEventListener('click', function () { var x = c[+b.dataset.i]; ui.isbn.value = x.isbn; lookup(x.isbn, { fromPhoto: true, ai: ai, photoCover: ai.cover_data }); });
    });
  }
  function resizeJpeg(file, maxSide) {
    return new Promise(function (ok) {
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var k = Math.min(1, maxSide / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) { URL.revokeObjectURL(url); ok(b || file); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { ok(file); };
      img.src = url;
    });
  }

  /* ---------------- başlat ---------------- */
  function init() {
    form = findForm();
    if (!form || document.getElementById('ih-panel')) return;
    buildPanel();
    loadHelperConfig();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.ISBNHelper = { lookup: lookup, normIsbn: normIsbn, matchOption: matchOption, suggestCategory: suggestCategory, fromPhoto: fromPhoto };
})();
