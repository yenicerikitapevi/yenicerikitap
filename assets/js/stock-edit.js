/* Listede hızlı stok değişikliği: .js-stock kutusu değişince kaydeder (admin ve satıcı paneli) */
(function () {
  function save(inp) {
    var v = inp.value.trim();
    if (v === inp.dataset.saved) return;
    if (!/^\d+$/.test(v)) { inp.value = inp.dataset.saved; return; }
    var fd = new FormData();
    fd.append('action', 'stock'); fd.append('id', inp.dataset.id); fd.append('stock', v);
    if (inp.dataset.token) fd.append('token', inp.dataset.token);
    inp.disabled = true;
    fetch(inp.dataset.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Kaydedilemedi');
        inp.dataset.saved = String(j.stock);
        inp.classList.toggle('border-danger', j.stock < 1); inp.classList.toggle('text-danger', j.stock < 1);
        inp.classList.add('border-success'); setTimeout(function () { inp.classList.remove('border-success'); }, 1200);
      })
      .catch(function (e) { inp.value = inp.dataset.saved; alert('Stok kaydedilemedi: ' + e.message); })
      .finally(function () { inp.disabled = false; });
  }
  document.querySelectorAll('.js-stock').forEach(function (inp) {
    inp.dataset.saved = inp.value;
    inp.addEventListener('change', function () { save(inp); });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
  });
})();
