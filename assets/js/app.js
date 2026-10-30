document.addEventListener('DOMContentLoaded', () => {
    initCart();
    updateCartBadge();

    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-add-cart');
        if (btn) {
            e.preventDefault();
            const id = btn.getAttribute('data-id');
            const title = btn.getAttribute('data-title');
            const price = parseFloat(btn.getAttribute('data-price'));
            const image = btn.getAttribute('data-image');
            
            addToCart({ id, title, price, image, qty: 1 });
            showToast(`${title} sepetinize eklendi!`, 'success');
        }
    });
});

function getCart() {
    try {
        const cart = JSON.parse(localStorage.getItem('yeniceri_cart') || '[]');
        return Array.isArray(cart) ? cart : [];
    } catch (e) {
        return [];
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[ch]);
}

// Sepeti sunucudaki güncel fiyat/stok bilgisiyle eşitler
function syncCartWithCatalog(catalog) {
    const cart = [];
    getCart().forEach(item => {
        const book = catalog[item.id];
        if (!book || book.stock <= 0) return;
        cart.push({
            ...item,
            title: book.title,
            price: parseFloat(book.price),
            qty: Math.min(Math.max(1, parseInt(item.qty, 10) || 1), book.stock)
        });
    });
    saveCart(cart);
    return cart;
}

function saveCart(cart) {
    localStorage.setItem('yeniceri_cart', JSON.stringify(cart));
    updateCartBadge();
    renderCartModal();
}

function initCart() {
    renderCartModal();
}

function addToCart(item) {
    if (window.ykTrackCart) window.ykTrackCart(item.id);
    let cart = getCart();
    const existingIndex = cart.findIndex(i => i.id == item.id);
    if (existingIndex > -1) {
        cart[existingIndex].qty += item.qty;
    } else {
        cart.push(item);
    }
    saveCart(cart);
}

function updateQuantity(id, change) {
    let cart = getCart();
    const item = cart.find(i => i.id == id);
    if (item) {
        item.qty += change;
        if (item.qty <= 0) {
            cart = cart.filter(i => i.id != id);
        }
    }
    saveCart(cart);
}

function removeFromCart(id) {
    let cart = getCart().filter(i => i.id != id);
    saveCart(cart);
}

function updateCartBadge() {
    const cart = getCart();
    const totalCount = cart.reduce((sum, item) => sum + item.qty, 0);
    const badgeElements = document.querySelectorAll('.cart-count-badge');
    badgeElements.forEach(el => {
        el.textContent = totalCount;
    });
}

function renderCartModal() {
    const cartListContainer = document.getElementById('cartDrawerList');
    const cartTotalElement = document.getElementById('cartDrawerTotal');
    if (!cartListContainer) return;

    const cart = getCart();
    if (cart.length === 0) {
        cartListContainer.innerHTML = `
            <div class="text-center py-5 text-muted">
                <i class="bi bi-cart-x display-1 opacity-25"></i>
                <p class="mt-3">Sepetinizde henüz ürün bulunmuyor.</p>
            </div>`;
        if (cartTotalElement) cartTotalElement.textContent = '₺0,00';
        return;
    }

    let html = '';
    let grandTotal = 0;

    cart.forEach(item => {
        const itemTotal = item.price * item.qty;
        grandTotal += itemTotal;
        html += `
            <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                <div class="bg-light p-2 rounded me-3" style="width: 50px; height: 65px;">
                    <img src="${escapeHtml(item.image ? item.image : 'assets/images/book_placeholder.svg')}" class="w-100 h-100 object-fit-cover rounded" alt="${escapeHtml(item.title)}" onerror="this.src='assets/images/book_placeholder.svg'">
                </div>
                <div class="flex-grow-1 me-2">
                    <h6 class="mb-1 text-truncate" style="max-width: 160px;">${escapeHtml(item.title)}</h6>
                    <span class="text-danger fw-bold fs-7">₺${Number(item.price).toFixed(2)}</span>
                </div>
                <div class="d-flex align-items-center border rounded me-2">
                    <button class="btn btn-sm btn-light px-2" onclick="updateQuantity(${parseInt(item.id, 10)}, -1)">-</button>
                    <span class="px-2 fw-semibold fs-7">${parseInt(item.qty, 10)}</span>
                    <button class="btn btn-sm btn-light px-2" onclick="updateQuantity(${parseInt(item.id, 10)}, 1)">+</button>
                </div>
                <button class="btn btn-sm text-danger" onclick="removeFromCart(${parseInt(item.id, 10)})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>`;
    });

    cartListContainer.innerHTML = html;
    if (cartTotalElement) {
        cartTotalElement.textContent = '₺' + grandTotal.toFixed(2).replace('.', ',');
    }
}

function showToast(message, type = 'info') {
    if (typeof bootstrap === 'undefined') return; // Bootstrap CDN yüklenemezse sepet yine de çalışsın
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }

    const toastId = 'toast_' + Date.now();
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill text-danger fs-5"></i>
                    <span>${escapeHtml(message)}</span>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>`;
    container.insertAdjacentHTML('beforeend', toastHtml);

    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
    toast.show();
}
