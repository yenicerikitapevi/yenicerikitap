(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const csrf = JSON.parse($('wm-config').textContent).csrf;
    const labels = {pending: 'Bekliyor', ready: 'Önizleme hazır', applied: 'Yayımlandı', skipped: 'Atlandı', error: 'Hata', restored: 'Geri alındı', final: 'Yayımlandı (yedek silindi)'};
    let running = false, stopping = false, offset = 0, total = 0;
    const text = (tag, value, cls = '') => { const el = document.createElement(tag); el.textContent = value; el.className = cls; return el; };
    function busy(value) {
        running = value;
        document.querySelectorAll('[data-wm], #wm-create, #wm-job, #wm-prev, #wm-next').forEach(el => { el.disabled = value; });
        $('wm-stop').disabled = !value;
    }
    async function request(op, item, batch) {
        const params = new URLSearchParams({job: $('wm-job').value || '', offset: String(offset)});
        const opts = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        if (op) {
            opts.method = 'POST';
            opts.body = new URLSearchParams({job: $('wm-job').value || '', op, csrf, ...(item ? {item} : {}), ...(batch ? {limit: String(batch)} : {})});
        }
        const response = await fetch('watermark_api.php?' + params, opts);
        if (response.redirected) throw new Error('Oturum sona erdi. Yeniden giriş yapın.');
        const data = await response.json();
        if (!response.ok || data.error) throw new Error(data.error || 'İşlem tamamlanamadı.');
        return data;
    }
    function render(data) {
        total = data.total;
        if (typeof data.disk === 'number' && $('wm-disk')) $('wm-disk').textContent = 'İşlem klasörü (yedekler ve önizlemeler): ' + (data.disk / 1048576).toFixed(1) + ' MB';
        $('wm-counts').textContent = `Toplam ${total} görsel. ` + Object.entries(data.counts).map(([k, n]) => `${labels[k] || k}: ${n}`).join(' · ');
        $('wm-page').textContent = total ? `${offset + 1}–${Math.min(offset + 12, total)} / ${total}` : 'Görsel bulunamadı';
        $('wm-items').replaceChildren();
        for (const item of data.items) {
            const col = text('div', '', 'col-md-6 col-xl-4');
            const card = text('div', '', 'card p-3 h-100'); col.append(card);
            card.append(text('strong', `${item.seq}. ${item.source_name}`, 'text-break'));
            card.append(text('span', labels[item.status] || item.status, 'my-2'));
            if (['ready', 'applied', 'restored'].includes(item.status)) {
                const row = text('div', '', 'd-flex gap-2');
                for (const [kind, caption] of [['original', 'Önce'], ['preview', 'Sonra']]) {
                    const box = text('div', '', 'w-50'); box.append(text('small', caption));
                    const img = document.createElement('img');
                    img.src = `watermark_api.php?image=${encodeURIComponent(item.id)}&kind=${kind}`;
                    img.alt = caption; img.loading = 'lazy'; img.className = 'w-100';
                    img.style = 'height:240px;object-fit:contain'; box.append(img); row.append(box);
                }
                card.append(row);
            }
            if (item.error) {
                card.append(text('p', item.error, 'text-danger small mt-2'));
                if (item.status !== 'skipped') {
                    const retry = text('button', 'Hatayı giderdim, yeniden dene', 'btn btn-sm btn-outline-warning mt-2');
                    retry.type = 'button'; retry.disabled = running;
                    retry.addEventListener('click', () => run('retry', 1, item.id)); card.append(retry);
                }
            }
            if (['ready', 'applied'].includes(item.status) && !item.error) {
                const btn = text('button', item.status === 'ready' ? 'Bu görseli yayımla' : 'Bu görseli geri al', 'btn btn-sm btn-outline-dark mt-2');
                btn.type = 'button'; btn.disabled = running;
                btn.addEventListener('click', () => run(item.status === 'ready' ? 'apply' : 'restore', 1, item.id));
                card.append(btn);
            }
            $('wm-items').append(col);
        }
    }
    async function refresh() { if ($('wm-job').value) render(await request()); }
    async function run(op, limit = Infinity, item) {
        if (running) return;
        if (op === 'apply' && !confirm('Hazır temiz kopyalar sitede yayımlansın mı? Kontrol etmediğiniz önizlemeleri önce inceleyin.')) return;
        if (op === 'purge' && !confirm('Bu işin yedekleri ve önizlemeleri silinsin mi? Yayımlanan görseller sitede kalır ama artık GERİ ALINAMAZ. Yayımlanmamış önizlemeler yeniden hazırlanmayı bekler.')) return;
        if (op === 'restore' && !confirm('Seçilen görseller yedeklerine geri döndürülsün mü? Sonradan değişen kitaplar korunur.')) return;
        stopping = false; busy(true);
        try {
            if (op === 'purge') {
                const data = await request(op); render(data);
                $('wm-message').textContent = 'Yedekler silindi: ' + ((data.freed || 0) / 1048576).toFixed(1) + ' MB yer açıldı.';
                return;
            }
            if (op === 'create') {
                offset = 0;
                const data = await request(op);
                const option = document.createElement('option'); option.value = data.job; option.textContent = 'Yeni iş';
                $('wm-job').prepend(option); $('wm-job').value = data.job; render(data);
            } else {
                // Her istek sunucuda birkaç görsel işler (en çok ~20 sn); "6 örnek" tek istekte 6 görsel
                for (let n = 0; n < limit && !stopping; ) {
                    $('wm-message').textContent = 'İşlem sürüyor…';
                    const batch = item ? 1 : Math.min(50, limit - n);
                    const data = await request(op, item, Number.isFinite(batch) ? batch : 50); render(data);
                    n += Math.max(1, data.processed || 0);
                    if (!data.remaining || item) break;
                }
            }
            $('wm-message').textContent = stopping ? 'Duraklatıldı. Kaldığınız yerden devam edebilirsiniz.' : 'İşlem tamamlandı. Sonuç ve hata sayılarını kontrol edin.';
        } catch (error) { $('wm-message').textContent = error.message; }
        finally { busy(false); await refresh().catch(() => {}); }
    }
    $('wm-create').addEventListener('click', () => run('create'));
    $('wm-stop').addEventListener('click', () => { stopping = true; $('wm-message').textContent = 'Çalışan görsel tamamlanınca duraklatılacak…'; });
    document.querySelectorAll('[data-wm]').forEach(btn => btn.addEventListener('click', () => run(btn.dataset.wm, Number(btn.dataset.limit) || Infinity)));
    $('wm-job').addEventListener('change', () => { offset = 0; refresh().catch(e => { $('wm-message').textContent = e.message; }); });
    for (const [id, step] of [['wm-prev', -12], ['wm-next', 12]]) $(id).addEventListener('click', () => {
        offset = Math.max(0, Math.min(Math.max(0, Math.ceil(total / 12) - 1) * 12, offset + step));
        refresh().catch(e => { $('wm-message').textContent = e.message; });
    });
    refresh().catch(e => { $('wm-message').textContent = e.message; });
})();
