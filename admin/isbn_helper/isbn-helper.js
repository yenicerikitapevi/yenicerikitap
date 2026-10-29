/*!
 * ISBN Yardımcısı – "Yeni Kitap Ekle" formunun üstüne ISBN kutusu, barkod tarama ve fiyat karşılaştırma ekler.
 * Formu KAYDETMEZ; sadece alanları doldurur. Kaydet düğmesine siz basarsınız.
 *
 * İsteğe bağlı ayar (script satırından ÖNCE):
 *   <script>window.ISBN_HELPER_CONFIG = { autoNewCategory: false, defaultShowcases: ['Yeni Ürünler'] };</script>
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
    localScanner: BASE + 'html5-qrcode.min.js',
    cdnScanner: 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
    autoNewCategory: false,          // kategori eşleşmezse "+ Yeni kategori" otomatik seçilsin mi?
    autoNewAuthor: true,
    autoNewPublisher: true,
    defaultShowcases: ['Yeni Ürünler'],
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
  var GENERIC = ['yayinlari', 'yayinlar', 'yayincilik', 'yayinevi', 'yayin', 'kitaplari', 'kitabevi', 'kitap', 'basimevi', 'yayinciligi', 'ltd', 'sti', 'a', 's', 've', 'tic', 'san', 'dagitim'];
  function core(n) { return n.split(' ').filter(function (t) { return t && GENERIC.indexOf(t) < 0; }).join(' '); }
  function lev(a, b) {
    var m = a.length, n = b.length, d = [], i, j;
    for (i = 0; i <= m; i++) { d[i] = [i]; }
    for (j = 1; j <= n; j++) d[0][j] = j;
    for (i = 1; i <= m; i++) for (j = 1; j <= n; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return d[m][n];
  }
  function ratio(a, b) { var L = Math.max(a.length, b.length); return L ? 1 - lev(a, b) / L : 1; }

  /** Seçenek listesinden ada en uygun olanı bul (Python tarafındaki match_option ile aynı mantık). */
  function matchOption(sel, wanted) {
    var w = norm(wanted);
    if (!w || !sel) return null;
    var cands = [];
    Array.prototype.forEach.call(sel.options, function (o) {
      if (o.value === '' || o.value === '0' || o.value === '__new') return;
      cands.push({ value: o.value, text: o.text.trim(), n: norm(o.text) });
    });
    for (var i = 0; i < cands.length; i++) if (cands[i].n === w) return { value: cands[i].value, text: cands[i].text, score: 1 };
    var best = null;
    cands.forEach(function (c) {
      if (!c.n) return;
      var cw = core(w), cn = core(c.n), sc;
      if (cw && cn) {
        if (cw === cn) sc = 0.97;
        else if (cw.split(' ').indexOf(cn) >= 0 || cn.split(' ').indexOf(cw) >= 0) sc = 0.9 * Math.min(cw.length, cn.length) / Math.max(cw.length, cn.length) + 0.1;
        else sc = ratio(cw, cn);
      } else if (w.indexOf(c.n) >= 0 || c.n.indexOf(w) >= 0) sc = 0.9 * Math.min(w.length, c.n.length) / Math.max(w.length, c.n.length) + 0.1;
      else sc = ratio(w, c.n);
      if (!best || sc > best.score) best = { value: c.value, text: c.text, score: sc };
    });
    return best && best.score >= 0.85 ? best : null;
  }
  var CAT_HINTS = {
    'roman': 'edebiyat roman', 'edebiyat': 'edebiyat roman', 'hikaye': 'edebiyat roman', 'oyku': 'edebiyat roman', 'siir': 'edebiyat roman',
    'fantastik': 'edebiyat roman', 'polisiye': 'edebiyat roman', 'bilim kurgu': 'edebiyat roman', 'mizah': 'edebiyat roman', 'deneme': 'edebiyat roman',
    'felsefe': 'felsefe dusunce', 'psikoloji': 'felsefe dusunce', 'dusunce': 'felsefe dusunce', 'din': 'felsefe dusunce',
    'tarih': 'tarih arastirma', 'arastirma': 'tarih arastirma', 'biyografi': 'tarih arastirma', 'anilar': 'tarih arastirma',
    'cocuk': 'cocuk genclik', 'genclik': 'cocuk genclik', 'klasik': 'dunya klasikleri', 'klasikleri': 'dunya klasikleri',
    'defter': 'defter', 'ajanda': 'defter'
  };
  function matchCategory(sel, wanted) {
    var m = matchOption(sel, wanted), w = norm(wanted);
    if (m || !w) return m;
    var opts = Array.prototype.filter.call(sel.options, function (o) { return ['', '0', '__new'].indexOf(o.value) < 0; });
    var wt = w.split(' ');
    for (var i = 0; i < opts.length; i++) { // "Roman" ⊂ "Edebiyat & Roman"
      var ot = norm(opts[i].text).split(' ');
      if (wt.every(function (t) { return ot.indexOf(t) >= 0; })) return { value: opts[i].value, text: opts[i].text.trim(), score: 0.9 };
    }
    for (var key in CAT_HINTS) {
      if (key === w || wt.some(function (t) { return t === key || (key.length >= 4 && key.indexOf(' ') < 0 && t.indexOf(key) === 0); })) {
        for (var j = 0; j < opts.length; j++) if (norm(opts[j].text) === CAT_HINTS[key]) return { value: opts[j].value, text: opts[j].text.trim(), score: 0.85 };
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
      '<span style="font-weight:400;font-size:12px;color:#7c2d12">(bilgi + en ucuz fiyat + kapak; Kaydet\'e siz basarsınız)</span></div>' +
      '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' +
      '<input type="text" id="ih-isbn" inputmode="numeric" autocomplete="off" placeholder="ISBN (örn. 9789750719387)" class="form-control" style="max-width:260px">' +
      '<button type="button" id="ih-go" class="btn btn-danger">Doldur</button>' +
      '<button type="button" id="ih-cam" class="btn btn-outline-dark">📷 Barkod tara</button>' +
      '<label class="btn btn-outline-dark mb-0" style="cursor:pointer">🖼 Barkod fotoğrafı yükle<input type="file" id="ih-file" accept="image/*" hidden></label>' +
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
    ui.file.addEventListener('change', function () { if (ui.file.files[0]) decodeFile(ui.file.files[0]); ui.file.value = ''; });
    if (window.matchMedia && matchMedia('(pointer:fine)').matches) { try { ui.isbn.focus({ preventScroll: true }); } catch (e) {} }
  }

  function status(msg, kind) {
    var col = { ok: '#166534', err: '#b91c1c', warn: '#9a3412', info: '#334155' }[kind || 'info'];
    ui.status.innerHTML = '<span style="color:' + col + '">' + msg + '</span>';
  }
  function busy(on) { ui.go.disabled = on; ui.go.textContent = on ? 'Aranıyor…' : 'Doldur'; }

  /* ---------------- arama + doldurma ---------------- */
  var seq = 0;
  async function lookup(raw) {
    var isbn = normIsbn(raw);
    if (!isbn) { status('Geçersiz ISBN. 13 haneli (978…/979…) ya da 10 haneli ISBN girin.', 'err'); return; }
    ui.isbn.value = isbn;
    var my = ++seq;
    busy(true); ui.result.innerHTML = '';
    status('Kitap ve fiyatlar aranıyor… (BKM, Kitapsepeti, D&R, Kitap Ambarı, Kitapyurdu, Open Library) — 10-20 sn sürebilir.');
    var data;
    try {
      var res = await fetch(cfg.api + '?isbn=' + isbn, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      var txt = await res.text();
      try { data = JSON.parse(txt); } catch (e) { throw new Error(res.status === 403 || /login|password/i.test(txt) ? 'Oturum geçersiz görünüyor. Panele yeniden giriş yapıp sayfayı yenileyin.' : 'Sunucu beklenmeyen bir yanıt verdi (HTTP ' + res.status + ').'); }
      if (!res.ok && !data.error) throw new Error('HTTP ' + res.status);
    } catch (e) { if (my === seq) { busy(false); status('Hata: ' + esc(e.message), 'err'); } return; }
    if (my !== seq) return;
    busy(false);
    if (!data.ok) { status(esc(data.error || 'Kayıt bulunamadı.') + ' Alanları elle doldurabilirsiniz.', 'err'); renderSources(data); return; }
    lastData = data;
    var notes = await apply(data);
    var sum = '<b>' + esc(data.title) + '</b> doldurdum.';
    status(sum + (notes.length ? '<br>' + notes.join('<br>') : ''), notes.some(function (n) { return /dikkat/i.test(n); }) ? 'warn' : 'ok');
    renderPrices(data);
  }

  function chooseEntity(sel, newInputName, name, opts) {
    // opts: {match: fn, auto: bool, label: 'Yazar'}
    if (!sel || !name) return null;
    var m = opts.match(sel, name);
    if (m) { sel.value = m.value; fire(sel, 'change'); return '✔ ' + opts.label + ': mevcut kayıt seçildi → <b>' + esc(m.text) + '</b>'; }
    if (opts.auto) {
      sel.value = '__new'; fire(sel, 'change');
      var ni = field(newInputName);
      if (ni) { ni.value = name; fire(ni, 'input'); }
      return '➕ ' + opts.label + ': listede yok, <b>yeni kayıt</b> olarak eklenecek → <b>' + esc(name) + '</b> (dikkat: yazımı kontrol edin)';
    }
    return '⚠ ' + opts.label + ' eşleşmedi (“' + esc(name) + '”). <b>Dikkat:</b> listeden seçin ya da “+ Yeni … ekle”yi kullanın.';
  }

  async function apply(d) {
    var notes = [];
    setVal('title', trTitle(d.title));
    setVal('original_title', d.original_title);
    setVal('isbn', d.isbn);
    if (d.pages) setVal('pages', d.pages);
    if (d.year) setVal('year', d.year);
    if (d.summary) setVal('summary', d.summary);
    if (d.suggested_price != null) setVal('price', Number(d.suggested_price).toFixed(2));
    var op = field('old_price');
    if (op) { op.value = d.suggested_old_price != null ? Number(d.suggested_old_price).toFixed(2) : ''; fire(op, 'input'); }
    if (d.cheapest) notes.push('💰 Satış fiyatı: <b>' + tl(d.cheapest.price) + '</b> (en uygun: ' + esc(d.cheapest.store) + ')' + (d.suggested_old_price ? ', eski fiyat ' + tl(d.suggested_old_price) : ''));
    else notes.push('⚠ <b>Dikkat:</b> hiçbir mağazada fiyat bulunamadı, fiyatı elle girin.');

    var author = trTitle(d.author), pub = trTitle(d.publisher);
    var a = chooseEntity(field('author_id'), 'new_author_name', author, {
      label: 'Yazar', auto: cfg.autoNewAuthor,
      match: function (sel, n) { var m = matchOption(sel, n); if (m) return m; var parts = n.split(/\s*(?:,| ve | & |;)\s*/); for (var i = 0; parts.length > 1 && i < parts.length; i++) { m = matchOption(sel, parts[i]); if (m) return m; } return null; }
    });
    if (a) notes.push(a); else notes.push('⚠ <b>Dikkat:</b> yazar bulunamadı, elle seçin.');
    var p = chooseEntity(field('publisher_id'), 'new_publisher_name', pub, { label: 'Yayınevi', auto: cfg.autoNewPublisher, match: matchOption });
    if (p) notes.push(p); else notes.push('⚠ <b>Dikkat:</b> yayınevi bulunamadı, elle seçin.');
    var catName = trTitle(d.category), cs = field('category_id');
    if (cs) {
      var c = catName ? chooseEntity(cs, 'new_category_name', catName, { label: 'Kategori', auto: cfg.autoNewCategory, match: matchCategory }) : null;
      notes.push(c || '⚠ <b>Dikkat:</b> kategori bilgisi gelmedi, listeden seçin.');
    }

    (cfg.defaultShowcases || []).forEach(function (nm) {
      form.querySelectorAll('input[type=checkbox][name="showcases[]"]').forEach(function (cb) {
        var lab = form.querySelector('label[for="' + cb.id + '"]');
        if (lab && norm(lab.textContent).indexOf(norm(nm)) === 0 && !cb.checked) { cb.checked = true; fire(cb, 'change'); }
      });
    });

    if (cfg.fillCover) {
      var urls = (d.covers || []).map(function (c) { return c.url; });
      if (urls.length) notes.push(await fillCover(urls)); else notes.push('⚠ Kapak görseli bulunamadı (elle yükleyin).');
    }
    if (!d.summary) notes.push('ℹ Özet bulunamadı.');
    return notes;
  }

  async function fillCover(urls) {
    var input = field('cover_file');
    if (!input) return '';
    try {
      var qs = urls.slice(0, 6).map(function (u) { return 'u[]=' + encodeURIComponent(u); }).join('&');
      var res = await fetch(cfg.coverApi + '?' + qs, { credentials: 'same-origin' });
      if (!res.ok || !/image\//.test(res.headers.get('content-type') || '')) throw new Error('kapak indirilemedi');
      var blob = await res.blob();
      var file = new File([blob], 'kapak-' + (ui.isbn.value || 'kitap') + '.jpg', { type: 'image/jpeg' });
      var dt = new DataTransfer(); dt.items.add(file);
      input.files = dt.files;
      var pv = document.getElementById('coverPreview'); if (pv) pv.src = URL.createObjectURL(file);
      return '🖼 Kapak 600×900 olarak forma eklendi (' + Math.round(blob.size / 1024) + ' KB).';
    } catch (e) {
      return '⚠ <b>Dikkat:</b> kapak otomatik eklenemedi (' + esc(e.message) + '); elle yükleyin.';
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
    var html = '<div style="margin-top:10px;font-weight:600">Fiyat karşılaştırması</div>';
    html += rows ? '<div style="overflow-x:auto"><table class="table table-sm align-middle mb-0" style="font-size:14px;background:#fff"><thead><tr><th>Mağaza</th><th>Fiyat</th><th>Liste</th><th>Stok</th><th></th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div style="font-size:14px">Fiyat bulunamadı.</div>';
    ui.result.innerHTML = html;
    Array.prototype.forEach.call(ui.result.querySelectorAll('.ih-use'), function (b) {
      b.addEventListener('click', function () {
        var p = d.prices[+b.dataset.i];
        setVal('price', Number(p.price).toFixed(2));
        status('Satış fiyatı <b>' + tl(p.price) + '</b> (' + esc(p.store) + ') olarak ayarlandı.', 'ok');
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
  async function decodeFile(file) {
    status('Fotoğraftaki barkod okunuyor…');
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
      } catch (e) { status('Tarama kütüphanesi yüklenemedi: ' + esc(e.message), 'err'); return; }
    }
    if (!found) { status('Fotoğrafta geçerli bir ISBN barkodu okunamadı. Barkodu yakından, düz ve net çekip tekrar deneyin ya da ISBN\'i yazın.', 'err'); return; }
    ui.isbn.value = found; lookup(found);
  }

  /* ---------------- başlat ---------------- */
  function init() {
    form = findForm();
    if (!form || document.getElementById('ih-panel')) return;
    buildPanel();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.ISBNHelper = { lookup: lookup, normIsbn: normIsbn, matchOption: matchOption, matchCategory: matchCategory };
})();
