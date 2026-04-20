'use strict';

// ── CSRF TOKEN ───────────────────────────────────────────────────────
// Populated during init() by calling the csrf_token endpoint.
// Kept in sync with the <meta name="csrf-token"> tag.
let csrfToken = '';

function _setCsrfToken(token) {
    csrfToken = token || '';
    const metaEl = document.querySelector('meta[name="csrf-token"]');
    if (metaEl) metaEl.setAttribute('content', csrfToken);
}

// ── API HELPER ───────────────────────────────────────────────────────
async function api(action, data) {
    const opts = { credentials: 'same-origin' };
    if (data !== undefined) {
        opts.method  = 'POST';
        opts.headers = {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        };
        opts.body    = JSON.stringify(data);
    }
    const res  = await fetch('php/api.php?action=' + action, opts);
    const json = await res.json();
    if (json.error) throw new Error(json.error);
    return json;
}

// Data is loaded from api.php — no hardcoded defaults needed.

// ── STATE ────────────────────────────────────────────────────────────
let products      = [];
let locations     = [];
let cart          = [];   // [{id, name, price, category, quantity, available}]
let blockedDates  = [];
let adminOrders   = [];
let adminReviewsList = [];
let adminUser     = null;
let currentUser   = null; // any logged-in user (customer or admin)

let currentFilter    = 'all';
let reviewFilter     = 'all';
let calYear, calMonth;
let editingProductId = null;
let editingLocId     = null;
let currentRating    = 0;
let verifiedOrder    = null;

// ── UTILITIES ────────────────────────────────────────────────────────
function todayStr() { return new Date().toISOString().split('T')[0]; }
function toISODate(y,m,d) { return `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`; }
function formatDate(str) {
    if (!str) return '';
    const [y,m,d] = str.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[+m-1]} ${+d}, ${y}`;
}
function fmtCurrency(n) { return '$' + Number(n).toFixed(2); }

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3200);
}

function el(id) { return document.getElementById(id); }

// ── XSS ESCAPE ───────────────────────────────────────────────────────
// Always use esc() when inserting any DB-sourced string into innerHTML.
function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;');
}

// ── ORDER RULES ──────────────────────────────────────────────────────
const ALLOWED_DAYS       = [2, 3, 4, 5]; // Tue–Fri (0=Sun)
const MAX_LOAVES_PER_DAY = 4;

function isAvailableDay(dateStr) {
    const [y,m,d] = dateStr.split('-').map(Number);
    return ALLOWED_DAYS.includes(new Date(y, m-1, d).getDay());
}

// ── CLOSE MODALS ON BACKDROP ────────────────────────────────────────
['admin-login-modal','success-modal','product-modal','location-modal','review-modal','lookup-modal','signin-modal','register-modal'].forEach(id => {
    el(id).addEventListener('click', e => {
        if (e.target === el(id)) {
            if (id === 'admin-login-modal') closeAdminLogin();
            else if (id === 'success-modal')  closeSuccessModal();
            else if (id === 'product-modal')  closeProductModal();
            else if (id === 'location-modal') closeLocationModal();
            else if (id === 'review-modal')   closeReviewModal();
            else if (id === 'lookup-modal')   closeLookupModal();
            else if (id === 'signin-modal')   closeSignInModal();
            else if (id === 'register-modal') closeRegisterModal();
        }
    });
});

// ── CUSTOMER: PRODUCTS ───────────────────────────────────────────────
function cartQty(productId) {
    const item = cart.find(i => i.id === productId);
    return item ? item.quantity : 0;
}

function renderProducts() {
    const visibleProducts = products.filter(p => p.available);
    if (!visibleProducts.length) {
        el('products-grid').innerHTML = `
            <div class="empty-state" style="grid-column:1/-1; text-align:center;">
                <div class="empty-icon">🥖</div>
                <p>There are no products available right now. Please check back soon.</p>
            </div>`;
        return;
    }

    el('products-grid').innerHTML = visibleProducts.map(p => {
        const qty    = cartQty(p.id);
        const inCart = qty > 0;
        const cartBtn = inCart
            ? `<button class="in-cart-btn" onclick="addToCart(${p.id})">✓ In Cart (${qty})</button>`
            : `<button class="add-to-cart-btn" onclick="addToCart(${p.id})">Add to Cart</button>`;

        const allergens = [];
        if (p.dairy)     allergens.push('Dairy');
        if (p.treeNuts)  allergens.push('Tree nuts');
        if (p.egg)       allergens.push('Egg');
        if (p.peanut)    allergens.push('Peanut');
        if (p.sesame)    allergens.push('Sesame');
        if (p.soy)       allergens.push('Soy');

        return `
        <div class="product-card">
            <div class="product-icon">${p.category === 'specialty' ? '✨' : '🍞'}</div>
            <span class="badge badge-${p.category}">${p.category}</span>
            <h3>${esc(p.name)}</h3>
            <p>${esc(p.description || '')}</p>
            ${allergens.length ? `<div class="product-allergens">Contains: ${allergens.join(', ')}</div>` : ''}
            <div class="product-footer">
                <div class="product-price">${fmtCurrency(p.price)}</div>
                ${cartBtn}
            </div>
        </div>`;
    }).join('');
}

// ── CUSTOMER: CART ────────────────────────────────────────────────────
async function loadCart() {
    try {
        const data = await api('cart_get');
        cart = data.items || [];
    } catch(e) { cart = []; }
    renderProducts();
    renderCart();
    updateCartBadge();
}

async function addToCart(id) {
    try {
        await api('cart_add', { productId: id, quantity: 1 });
        await loadCart();
        const total = cart.reduce((s,i) => s+i.quantity, 0);
        if (total === 1) el('order').scrollIntoView({ behavior: 'smooth' });
        else showToast('Added to cart ✓');
    } catch(e) { showToast(e.message || 'Could not add to cart.'); }
}

async function removeFromCart(id) {
    try {
        await api('cart_remove', { productId: id });
        await loadCart();
    } catch(e) { showToast(e.message || 'Could not remove item.'); }
}

async function changeQty(id, delta) {
    const newQty = cartQty(id) + delta;
    try {
        if (newQty <= 0) await api('cart_remove', { productId: id });
        else             await api('cart_update', { productId: id, quantity: newQty });
        await loadCart();
    } catch(e) { showToast(e.message || 'Could not update cart.'); }
}

function renderCart() {
    const container = el('cart-container');
    const totalBox  = el('order-total-box');
    const cartItems = cart.filter(i => i.quantity > 0);

    if (!cartItems.length) {
        container.innerHTML = `
            <div class="cart-empty">
                <div class="cart-empty-icon">🧺</div>
                <p>Your cart is empty</p>
                <a href="#products" class="cart-browse-link">Browse our breads ↑</a>
            </div>`;
        totalBox.style.display = 'none';
        return;
    }

    container.innerHTML = `<div class="cart-list">${cartItems.map(p => `
        <div class="cart-item">
            <div class="cart-item-info">
                <div class="cart-item-name">${esc(p.name)}</div>
                <div class="cart-item-meta">
                    <span class="badge badge-${p.category}">${p.category}</span>
                    <span>${fmtCurrency(p.price)} each</span>
                </div>
            </div>
            <div class="cart-item-controls">
                <button type="button" class="qty-btn" onclick="changeQty(${p.id},-1)">−</button>
                <span class="qty-display">${p.quantity}</span>
                <button type="button" class="qty-btn" onclick="changeQty(${p.id},1)">+</button>
            </div>
            <div class="cart-item-subtotal">${fmtCurrency(p.price * p.quantity)}</div>
            <button type="button" class="cart-remove-btn" onclick="removeFromCart(${p.id})" title="Remove">×</button>
        </div>`).join('')}
    </div>`;

    totalBox.style.display = 'flex';
    el('order-total').textContent = fmtCurrency(cart.reduce((s,i) => s + i.price * i.quantity, 0));
}

function updateCartBadge() {
    const total = cart.reduce((s,i) => s+i.quantity, 0);
    ['nav-cart-badge', 'nav-cart-badge-mobile'].forEach(id => {
        const badge = document.getElementById(id);
        if (!badge) return;
        badge.textContent = total;
        badge.classList.toggle('hidden', total === 0);
    });
}

function renderLocationDropdown() {
    el('pickup-location').innerHTML = '<option value="">Select a location...</option>' +
        locations.filter(l => l.active).map(l => `<option value="${l.id}">${esc(l.name)} – ${esc(l.address)}</option>`).join('');
}

function setupPhoneInput() {
    const inp = el('cust-phone');
    inp.addEventListener('input', function(e) {
        // Strip everything except digits
        let digits = inp.value.replace(/\D/g, '').slice(0, 10);
        let formatted = '';
        if (digits.length === 0) {
            formatted = '';
        } else if (digits.length <= 3) {
            formatted = '(' + digits;
        } else if (digits.length <= 6) {
            formatted = '(' + digits.slice(0,3) + ') ' + digits.slice(3);
        } else {
            formatted = '(' + digits.slice(0,3) + ') ' + digits.slice(3,6) + '-' + digits.slice(6);
        }
        inp.value = formatted;
    });
    // Prevent non-numeric keys (allow control keys)
    inp.addEventListener('keydown', function(e) {
        const allowed = ['Backspace','Delete','Tab','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'];
        if (!allowed.includes(e.key) && !/^\d$/.test(e.key)) e.preventDefault();
    });
}

// ── CUSTOMER CALENDAR ────────────────────────────────────────────────
let custCalYear, custCalMonth, custCalSelected = null;
const CUST_CAL_MONTHS = ['January','February','March','April','May','June',
                         'July','August','September','October','November','December'];

function setupDateInput() {
    const now = new Date();
    custCalYear  = now.getFullYear();
    custCalMonth = now.getMonth();
    renderCustCalendar();
}

function custCalPrev() {
    const now = new Date();
    if (custCalYear === now.getFullYear() && custCalMonth === now.getMonth()) return;
    custCalMonth--;
    if (custCalMonth < 0) { custCalMonth = 11; custCalYear--; }
    renderCustCalendar();
}

function custCalNext() {
    custCalMonth++;
    if (custCalMonth > 11) { custCalMonth = 0; custCalYear++; }
    renderCustCalendar();
}

function renderCustCalendar() {
    const labelEl = document.getElementById('cust-cal-month');
    if (labelEl) labelEl.textContent = CUST_CAL_MONTHS[custCalMonth] + ' ' + custCalYear;

    const today      = todayStr();
    const tomorrow   = (() => { const d = new Date(); d.setDate(d.getDate()+1); return d.toISOString().split('T')[0]; })();
    const firstDay   = new Date(custCalYear, custCalMonth, 1).getDay();
    const daysInMonth = new Date(custCalYear, custCalMonth+1, 0).getDate();

    let html = '';
    for (let i = 0; i < firstDay; i++) html += '<div class="cal-day cal-empty"></div>';

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr      = toISODate(custCalYear, custCalMonth, d);
        const isPast       = dateStr < tomorrow;
        const isRestricted = !isAvailableDay(dateStr);
        const isBlocked    = blockedDates.includes(dateStr);
        const isToday      = dateStr === today;
        const isSelected   = dateStr === custCalSelected;
        const selectable   = !isPast && !isRestricted && !isBlocked;

        let cls = 'cal-day';
        if (isPast)          cls += ' cal-past';
        else if (isRestricted) cls += ' cal-restricted';
        else if (isBlocked)  cls += ' cal-blocked';
        if (isToday)         cls += ' cal-today';
        if (isSelected)      cls += ' cust-cal-selected-day';

        const onClick = selectable ? `onclick="selectCustDate('${dateStr}')"` : '';
        html += `<div class="${cls}" ${onClick}>${d}</div>`;
    }

    const daysEl = document.getElementById('cust-cal-days');
    if (daysEl) daysEl.innerHTML = html;
}

function selectCustDate(dateStr) {
    custCalSelected = dateStr;
    el('pickup-date').value = dateStr;
    const label = document.getElementById('cust-cal-selected-label');
    label.textContent = '✓ ' + formatDate(dateStr) + ' selected';
    label.classList.remove('hidden');
    el('date-warning').classList.add('hidden');
    renderCustCalendar();
}

el('order-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const name   = el('cust-name').value.trim();
    const email  = el('cust-email').value.trim();
    const phone  = el('cust-phone').value.trim();
    const date   = el('pickup-date').value;
    const locId  = parseInt(el('pickup-location').value) || 0;
    const notes  = el('order-notes').value.trim();
    const errEl  = el('form-error');

    if (!name || !email || !date || !locId) {
        errEl.textContent = 'Please fill in all required fields (name, email, date, and location).';
        errEl.classList.remove('hidden'); return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errEl.textContent = 'Please enter a valid email address.';
        errEl.classList.remove('hidden'); return;
    }
    if (!isAvailableDay(date)) {
        errEl.textContent = 'We only offer pickup Tuesday through Friday.';
        errEl.classList.remove('hidden'); return;
    }
    if (!cart.length) {
        errEl.textContent = 'Please add at least one item to your order.';
        errEl.classList.remove('hidden'); return;
    }
    errEl.classList.add('hidden');

    const cartSnapshot = cart.map(i => ({ productId: i.id, quantity: i.quantity, name: i.name, price: i.price }));
    const items = cartSnapshot.map(i => ({ productId: i.productId, quantity: i.quantity }));
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled = true; btn.textContent = 'Placing order\u2026';

    try {
        const res = await api('orders_place', { customerName:name, email, phone, pickupDate:date, locationId:locId, notes, items });
        sendEmail(res, name, email, phone, date, locId, notes, cartSnapshot);
        await loadCart();
        el('order-form').reset();
        custCalSelected = null;
        document.getElementById('cust-cal-selected-label').classList.add('hidden');
        renderCustCalendar();
        el('date-warning').classList.add('hidden');
        el('success-order-id').textContent = 'Order ' + res.orderId;
        el('success-modal').classList.remove('hidden');
    } catch(err) {
        errEl.textContent = err.message || 'Something went wrong. Please try again.';
        errEl.classList.remove('hidden');
    }
    btn.disabled = false; btn.textContent = 'Place Order \u2192';
});

// ── EMAIL (EMAILJS) ──────────────────────────────────────────────────
async function sendEmail(res, name, email, phone, date, locId, notes, cartSnapshot) {
    try {
        const s = await api('settings_public');
        if (!s.ejs_user || !s.ejs_service || !s.ejs_template) return;
        emailjs.init(s.ejs_user);
        const loc = locations.find(l => l.id === locId);
        const itemsText = cartSnapshot.map(i => `${i.quantity}\u00d7 ${i.name} (${fmtCurrency(i.price * i.quantity)})`).join('\n');
        const params = {
            customer_name:   name,
            customer_email:  email,
            customer_phone:  phone || 'Not provided',
            order_items:     itemsText,
            order_total:     fmtCurrency(res.total),
            pickup_date:     formatDate(date),
            pickup_location: loc ? loc.name + ' \u2013 ' + loc.address : '',
            order_notes:     notes || 'None',
            order_id:        res.orderId,
        };
        if (s.owner_email) emailjs.send(s.ejs_service, s.ejs_template, { ...params, to_email: s.owner_email });
        const custTpl = s.ejs_customer_template || s.ejs_template;
        emailjs.send(s.ejs_service, custTpl, { ...params, to_email: email });
    } catch(err) { /* fail silently */ }
}

// ── SUCCESS MODAL ────────────────────────────────────────────────────
function closeSuccessModal() {
    el('success-modal').classList.add('hidden');
    el('order').scrollIntoView({ behavior:'smooth' });
}

// ── ADMIN LOGIN ──────────────────────────────────────────────────────
function openAdminLogin() {
    el('admin-login-modal').classList.remove('hidden');
    setTimeout(() => el('admin-email-input').focus(), 80);
}
function closeAdminLogin() {
    el('admin-login-modal').classList.add('hidden');
    el('admin-email-input').value = '';
    el('admin-pw-input').value = '';
    el('login-error').classList.add('hidden');
}
async function doAdminLogin() {
    const email = el('admin-email-input').value.trim();
    const pw    = el('admin-pw-input').value;
    const errEl = el('login-error');
    try {
        const res = await api('auth_login', { email, password: pw });
        if (res.user.role !== 'admin') throw new Error('Not an admin account.');
        // Server rotates session ID + CSRF token on login — keep in sync
        if (res.csrfToken) _setCsrfToken(res.csrfToken);
        adminUser   = res.user;
        currentUser = res.user;
        closeAdminLogin();
        updateAuthNav();
        showAdminView();
    } catch(e) {
        errEl.textContent = e.message || 'Incorrect email or password.';
        errEl.classList.remove('hidden');
        el('admin-pw-input').value = '';
    }
}
async function adminLogout() { await signOut(); }

async function signOut() {
    try {
        const res = await api('auth_logout', {});
        if (res.csrfToken) _setCsrfToken(res.csrfToken);
    } catch(e) {}
    currentUser = null;
    adminUser   = null;
    updateAuthNav();
    // If admin view is visible, return to customer view
    if (!el('admin-view').classList.contains('hidden')) {
        switchToCustomer();
    }
}

// ── AUTH NAV UPDATE ───────────────────────────────────────────────────
function updateAuthNav() {
    const loggedIn = !!currentUser;
    const isAdmin  = !!(currentUser && currentUser.role === 'admin');

    // Desktop nav
    const $si   = el('nav-signin-btn');
    const $reg  = el('nav-register-btn');
    const $so   = el('nav-signout-btn');
    const $adm  = el('nav-admin-toggle');
    if ($si)  $si.classList.toggle('hidden',  loggedIn);
    if ($reg) $reg.classList.toggle('hidden', loggedIn);
    if ($so)  $so.classList.toggle('hidden',  !loggedIn);
    if ($adm) $adm.classList.toggle('hidden', !isAdmin);

    // Mobile nav
    const $msi  = el('nav-mobile-signin');
    const $mreg = el('nav-mobile-register');
    const $mso  = el('nav-mobile-signout');
    const $madm = el('nav-mobile-admin-toggle');
    if ($msi)  $msi.classList.toggle('hidden',  loggedIn);
    if ($mreg) $mreg.classList.toggle('hidden', loggedIn);
    if ($mso)  $mso.classList.toggle('hidden',  !loggedIn);
    if ($madm) $madm.classList.toggle('hidden', !isAdmin);
}

// ── SIGN IN MODAL ─────────────────────────────────────────────────────
function openSignInModal() {
    el('si-email').value    = '';
    el('si-password').value = '';
    el('si-error').classList.add('hidden');
    el('signin-modal').classList.remove('hidden');
    setTimeout(() => el('si-email').focus(), 80);
}
function closeSignInModal() { el('signin-modal').classList.add('hidden'); }

async function doSignIn() {
    const email = el('si-email').value.trim();
    const pw    = el('si-password').value;
    const errEl = el('si-error');
    if (!email || !pw) {
        errEl.textContent = 'Please enter your email and password.';
        errEl.classList.remove('hidden'); return;
    }
    try {
        const res = await api('auth_login', { email, password: pw });
        if (res.csrfToken) _setCsrfToken(res.csrfToken);
        currentUser = res.user;
        if (res.user.role === 'admin') adminUser = res.user;
        closeSignInModal();
        updateAuthNav();
        if (res.user.role === 'admin') {
            showAdminView();
        } else {
            showToast('Signed in ✓');
            await loadCart();
        }
    } catch(e) {
        errEl.textContent = e.message || 'Incorrect email or password.';
        errEl.classList.remove('hidden');
        el('si-password').value = '';
    }
}

// ── CREATE ACCOUNT MODAL ──────────────────────────────────────────────
function openCreateAccountModal() {
    el('reg-email').value    = '';
    el('reg-password').value = '';
    el('reg-confirm').value  = '';
    el('reg-error').classList.add('hidden');
    el('register-modal').classList.remove('hidden');
    setTimeout(() => el('reg-email').focus(), 80);
}
function closeRegisterModal() { el('register-modal').classList.add('hidden'); }

async function doRegister() {
    const email = el('reg-email').value.trim();
    const pw1   = el('reg-password').value;
    const pw2   = el('reg-confirm').value;
    const errEl = el('reg-error');
    if (!email || !pw1 || !pw2) {
        errEl.textContent = 'Please fill in all fields.';
        errEl.classList.remove('hidden'); return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errEl.textContent = 'Please enter a valid email address.';
        errEl.classList.remove('hidden'); return;
    }
    if (pw1 !== pw2) {
        errEl.textContent = 'Passwords do not match.';
        errEl.classList.remove('hidden'); return;
    }
    if (pw1.length < 8) {
        errEl.textContent = 'Password must be at least 8 characters.';
        errEl.classList.remove('hidden'); return;
    }
    try {
        const res = await api('auth_register', { email, password: pw1 });
        if (res.csrfToken) _setCsrfToken(res.csrfToken);
        currentUser = res.user;
        closeRegisterModal();
        updateAuthNav();
        showToast('Account created! Welcome to The Goody Basket 🎉');
        await loadCart();
    } catch(e) {
        errEl.textContent = e.message || 'Could not create account. Email may already be in use.';
        errEl.classList.remove('hidden');
    }
}

// ── VIEW SWITCHING ────────────────────────────────────────────────────
async function showAdminView() {
    el('customer-view').classList.add('hidden');
    el('admin-view').classList.remove('hidden');
    initCalendar();
    await Promise.all([
        renderDashboard(),
        renderAdminProducts(),
        renderAdminLocations(),
        renderAdminReviews(),
        loadAdminSettings(),
    ]);
}
async function switchToCustomer() {
    el('admin-view').classList.add('hidden');
    el('customer-view').classList.remove('hidden');
    await loadCart();
    renderProducts();
    renderLocationDropdown();
}

// ── ADMIN TABS ────────────────────────────────────────────────────────
async function showTab(tab, btn) {
    ['dashboard','calendar','products','locations','reviews','settings'].forEach(t => {
        el('tab-'+t).classList.add('hidden');
    });
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
    el('tab-'+tab).classList.remove('hidden');
    if (btn) btn.classList.add('active');
    if (tab === 'dashboard') await renderDashboard();
    if (tab === 'calendar')  await renderCalendar();
    if (tab === 'products')  await renderAdminProducts();
    if (tab === 'locations') await renderAdminLocations();
    if (tab === 'reviews')   await renderAdminReviews();
    if (tab === 'settings')  await loadAdminSettings();
}

// ── ADMIN: DASHBOARD ──────────────────────────────────────────────────
async function renderDashboard() {
    try { adminOrders = await api('orders_list'); } catch(e) { adminOrders = []; }
    const today     = todayStr();
    const pending   = adminOrders.filter(o=>o.status==='pending').length;
    const confirmed = adminOrders.filter(o=>o.status==='confirmed').length;
    const todayPU   = adminOrders.filter(o=>o.pickup_date===today && o.status!=='cancelled').length;
    const revenue   = adminOrders.filter(o=>o.status!=='cancelled').reduce((s,o)=>s+(+o.total_cost),0);

    el('stats-grid').innerHTML = `
        <div class="stat-card"><div class="stat-label">Total Orders</div><div class="stat-value">${adminOrders.length}</div><div class="stat-sub">All time</div></div>
        <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value">${pending}</div><div class="stat-sub">Need confirmation</div></div>
        <div class="stat-card"><div class="stat-label">Confirmed</div><div class="stat-value">${confirmed}</div><div class="stat-sub">Ready to bake</div></div>
        <div class="stat-card"><div class="stat-label">Today's Pickups</div><div class="stat-value">${todayPU}</div><div class="stat-sub">Due today</div></div>
        <div class="stat-card"><div class="stat-label">Total Revenue</div><div class="stat-value" style="font-size:26px;">${fmtCurrency(revenue)}</div><div class="stat-sub">Excl. cancelled</div></div>`;
    renderOrdersTable(currentFilter);
}

function filterOrders(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    renderOrdersTable(filter);
}

function renderOrdersTable(filter) {
    let orders = adminOrders.slice();
    if (filter !== 'all') orders = orders.filter(o=>o.status===filter);
    orders.sort((a,b) => new Date(b.created_at)-new Date(a.created_at));

    if (!orders.length) {
        el('orders-body').innerHTML = `<div class="empty-state"><div class="empty-icon">📋</div><p>No orders to show.</p></div>`;
        return;
    }
    el('orders-body').innerHTML = `<div style="overflow-x:auto;"><table>
        <thead><tr>
            <th>Order ID</th><th>Customer</th><th>Items</th>
            <th>Total</th><th>Pickup Date</th><th>Location</th><th>Status</th>
        </tr></thead>
        <tbody>${orders.map(o=>`
        <tr>
            <td style="font-family:monospace;font-size:12px;white-space:nowrap;">${esc(o.order_id)}</td>
            <td>
                <strong>${esc(o.customer_name)}</strong><br>
                <span style="font-size:12px;color:#6B5744;">${esc(o.email)}</span>
                ${o.phone?`<br><span style="font-size:12px;color:#6B5744;">${esc(o.phone)}</span>`:''}
            </td>
            <td style="font-size:13px;min-width:160px;">
                ${(o.items||[]).map(i=>`${i.quantity}\u00d7 ${esc(i.productName)}`).join('<br>')}
                ${o.notes?`<br><em style="color:#aaa;font-size:12px;">"${esc(o.notes)}"</em>`:''}
            </td>
            <td><strong>${fmtCurrency(o.total_cost)}</strong></td>
            <td style="white-space:nowrap;">${formatDate(o.pickup_date)}</td>
            <td style="font-size:13px;">${esc(o.location_name)}</td>
            <td>
                <select class="status-select"
                    style="background:${sBg(o.status)};color:${sColor(o.status)};border-color:${sBorder(o.status)};"
                    onchange="updateOrderStatus('${o.order_id}',this)">
                    <option value="pending"   ${o.status==='pending'  ?'selected':''}>Pending</option>
                    <option value="confirmed" ${o.status==='confirmed'?'selected':''}>Confirmed</option>
                    <option value="completed" ${o.status==='completed'?'selected':''}>Completed</option>
                    <option value="cancelled" ${o.status==='cancelled'?'selected':''}>Cancelled</option>
                </select>
            </td>
        </tr>`).join('')}
        </tbody>
    </table></div>`;
}

function sBg(s)     { return {pending:'#FFF3CD',confirmed:'#D1ECF1',completed:'#D4EDDA',cancelled:'#F8D7DA'}[s]||'#fff'; }
function sBorder(s) { return {pending:'#FFEAA7',confirmed:'#BEE5EB',completed:'#C3E6CB',cancelled:'#F5C6CB'}[s]||'#ddd'; }
function sColor(s)  { return {pending:'#856404',confirmed:'#0C5460',completed:'#155724',cancelled:'#721C24'}[s]||'#333'; }

async function updateOrderStatus(orderId, selectEl) {
    const status = selectEl.value;
    try {
        await api('orders_status', { orderId, status });
        showToast('Order status updated \u2713');
        await renderDashboard();
    } catch(e) { showToast(e.message || 'Could not update status.'); }
}

// ── ADMIN: CALENDAR ──────────────────────────────────────────────────
function initCalendar() {
    const now = new Date();
    calYear  = now.getFullYear();
    calMonth = now.getMonth();
}

function calPrev() { calMonth--; if(calMonth<0){calMonth=11;calYear--;} renderCalendar(); }
function calNext() { calMonth++; if(calMonth>11){calMonth=0;calYear++;} renderCalendar(); }

async function renderCalendar() {
    const MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
    el('cal-month-label').textContent = MONTHS[calMonth] + ' ' + calYear;

    try {
        blockedDates = await api('dates_list');
        if (!adminOrders.length) adminOrders = await api('orders_list');
    } catch(e) {}

    const today = todayStr();
    const loavesByDate = {};
    adminOrders.filter(o=>o.status!=='cancelled').forEach(o=>{
        const lv = (o.items||[]).reduce((s,i) => s+(+i.quantity), 0);
        loavesByDate[o.pickup_date] = (loavesByDate[o.pickup_date]||0) + lv;
    });

    const firstDay    = new Date(calYear, calMonth, 1).getDay();
    const daysInMonth = new Date(calYear, calMonth+1, 0).getDate();
    let html = '';
    for (let i=0; i<firstDay; i++) html += '<div class="cal-day cal-empty"></div>';

    for (let d=1; d<=daysInMonth; d++) {
        const dateStr      = toISODate(calYear, calMonth, d);
        const isRestricted = !isAvailableDay(dateStr);
        const isBlocked    = blockedDates.includes(dateStr);
        const isPast       = dateStr < today;
        const isToday      = dateStr === today;
        const loaves       = loavesByDate[dateStr] || 0;
        const isFull       = loaves >= MAX_LOAVES_PER_DAY;

        let cls = 'cal-day';
        if (isRestricted)    cls += ' cal-restricted';
        else if (isBlocked)  cls += ' cal-blocked';
        else if (loaves > 0) cls += ' cal-has-orders';
        if (isToday) cls += ' cal-today';
        if (isPast)  cls += ' cal-past';

        const clickable  = !isPast && !isRestricted;
        const onClick    = clickable ? `onclick="toggleDate('${dateStr}')"` : '';
        const titleParts = [dateStr];
        if (isRestricted)  titleParts.push('No pickup (Tue\u2013Fri only)');
        else if (isBlocked) titleParts.push('BLOCKED');
        if (loaves > 0) titleParts.push(`${loaves}/${MAX_LOAVES_PER_DAY} loaves booked`);
        if (isFull && !isRestricted && !isBlocked) titleParts.push('FULLY BOOKED');

        html += `<div class="${cls}" ${onClick} title="${titleParts.join(' \u2014 ')}">
            ${d}
            ${isBlocked && !isRestricted ? `<div class="cal-blocked-x">\u2715</div>` : ''}
            ${loaves > 0 && !isRestricted ? `<div class="cal-order-dot">${loaves}/${MAX_LOAVES_PER_DAY}</div>` : ''}
        </div>`;
    }
    el('cal-days').innerHTML = html;
}

async function toggleDate(dateStr) {
    try {
        const res = await api('dates_toggle', { date: dateStr });
        blockedDates = await api('dates_list');
        showToast(res.blocked ? '\ud83d\udcc5 Date blocked: ' + formatDate(dateStr) : '\u2713 Date unblocked: ' + formatDate(dateStr));
        renderCalendar();
    } catch(e) { showToast(e.message || 'Could not toggle date.'); }
}

// ── ADMIN: PRODUCTS ───────────────────────────────────────────────────
async function renderAdminProducts() {
    try { products = await api('products_list'); } catch(e) {}
    if (!products.length) {
        el('products-admin-grid').innerHTML = `<div class="empty-state"><div class="empty-icon">🍞</div><p>No products yet. Add one above.</p></div>`;
        return;
    }
    el('products-admin-grid').innerHTML = products.map(p => `
        <div class="prod-admin-card">
            <div class="prod-admin-top">
                <div>
                    <h4>${esc(p.name)}</h4>
                    <span class="badge badge-${p.category}" style="margin-top:4px;display:inline-block;">${p.category}</span>
                </div>
                <div class="icon-btns">
                    <button class="icon-btn" onclick="openEditProduct(${p.id})" title="Edit">\u270f\ufe0f</button>
                    <button class="icon-btn del" onclick="deleteProduct(${p.id})" title="Delete">\ud83d\uddd1\ufe0f</button>
                </div>
            </div>
            <p class="prod-desc">${esc(p.description||'')}</p>
            <div class="prod-admin-footer">
                <div class="prod-price">${fmtCurrency(p.price)}</div>
                <label class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" ${p.available?'checked':''} onchange="toggleAvailable(${p.id},this.checked)">
                        <div class="toggle-slider"></div>
                    </label>
                    <span>${p.available ? 'Visible' : 'Hidden'}</span>
                </label>
            </div>
        </div>`).join('');
}

function openAddProduct() {
    editingProductId = null;
    el('product-modal-title').textContent = 'Add Product';
    el('prod-edit-id').value = '';
    el('prod-name').value = '';
    el('prod-category').value = 'regular';
    el('prod-price').value = '';
    el('prod-desc').value = '';
    el('prod-available').checked = true;
    el('prod-dairy').checked = false;
    el('prod-treenuts').checked = false;
    el('prod-egg').checked = false;
    el('prod-peanut').checked = false;
    el('prod-sesame').checked = false;
    el('prod-soy').checked = false;
    el('product-modal').classList.remove('hidden');
}

function openEditProduct(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    editingProductId = id;
    el('product-modal-title').textContent = 'Edit Product';
    el('prod-edit-id').value = id;
    el('prod-name').value = p.name;
    el('prod-category').value = p.category;
    el('prod-price').value = p.price;
    el('prod-desc').value = p.description || '';
    el('prod-available').checked = !!p.available;
    el('prod-dairy').checked = !!p.dairy;
    el('prod-treenuts').checked = !!p.treeNuts;
    el('prod-egg').checked = !!p.egg;
    el('prod-peanut').checked = !!p.peanut;
    el('prod-sesame').checked = !!p.sesame;
    el('prod-soy').checked = !!p.soy;
    el('product-modal').classList.remove('hidden');
}

function closeProductModal() { el('product-modal').classList.add('hidden'); }

async function saveProduct() {
    const name      = el('prod-name').value.trim();
    const category  = el('prod-category').value;
    const price     = parseFloat(el('prod-price').value);
    const desc      = el('prod-desc').value.trim();
    const available = el('prod-available').checked;
    const dairy     = el('prod-dairy').checked;
    const treeNuts  = el('prod-treenuts').checked;
    const egg       = el('prod-egg').checked;
    const peanut    = el('prod-peanut').checked;
    const sesame    = el('prod-sesame').checked;
    const soy       = el('prod-soy').checked;
    if (!name || isNaN(price) || price <= 0) { showToast('Please enter a name and valid price.'); return; }
    try {
        const payload = { name, category, price, description: desc, available, dairy, treeNuts, egg, peanut, sesame, soy };
        if (editingProductId) {
            payload.id = editingProductId;
            await api('products_edit', payload);
        } else {
            await api('products_add', payload);
        }
        closeProductModal();
        await renderAdminProducts();
        renderProducts();
        showToast(editingProductId ? 'Product updated \u2713' : 'Product added \u2713');
    } catch(e) { showToast(e.message || 'Could not save product.'); }
}

async function deleteProduct(id) {
    if (!confirm('Delete this product? This cannot be undone.')) return;
    try {
        await api('products_delete', { id });
        await renderAdminProducts();
        renderProducts();
        showToast('Product deleted.');
    } catch(e) { showToast(e.message || 'Could not delete product.'); }
}

async function toggleAvailable(id, available) {
    try {
        await api('products_toggle', { id });
        await renderAdminProducts();
        renderProducts();
        showToast(available ? '\u2713 Product visible to customers.' : 'Product hidden from customers.');
    } catch(e) { showToast(e.message || 'Could not toggle product.'); }
}

// ── ADMIN: LOCATIONS ─────────────────────────────────────────────────
async function renderAdminLocations() {
    try { locations = await api('locations_list'); } catch(e) {}
    if (!locations.length) {
        el('locations-list').innerHTML = `<div class="empty-state"><div class="empty-icon">\ud83d\udccd</div><p>No locations yet. Add one above.</p></div>`;
        return;
    }
    el('locations-list').innerHTML = locations.map(l=>`
        <div class="location-card">
            <div class="loc-info">
                <h4>${esc(l.name)}</h4>
                <p>${esc(l.address)}</p>
            </div>
            <div class="icon-btns">
                <button class="icon-btn" onclick="openEditLocation(${l.id})" title="Edit">\u270f\ufe0f</button>
                <button class="icon-btn del" onclick="deleteLocation(${l.id})" title="Delete">\ud83d\uddd1\ufe0f</button>
            </div>
        </div>`).join('');
}

function openAddLocation() {
    editingLocId = null;
    el('location-modal-title').textContent = 'Add Location';
    el('loc-edit-id').value = ''; el('loc-name').value = ''; el('loc-address').value = '';
    el('location-modal').classList.remove('hidden');
}

function openEditLocation(id) {
    const l = locations.find(x => x.id === id);
    if (!l) return;
    editingLocId = id;
    el('location-modal-title').textContent = 'Edit Location';
    el('loc-edit-id').value = id; el('loc-name').value = l.name; el('loc-address').value = l.address;
    el('location-modal').classList.remove('hidden');
}

function closeLocationModal() { el('location-modal').classList.add('hidden'); }

async function saveLocation() {
    const name    = el('loc-name').value.trim();
    const address = el('loc-address').value.trim();
    if (!name || !address) { showToast('Please fill in both fields.'); return; }
    try {
        if (editingLocId) await api('locations_edit', { id: editingLocId, name, address });
        else              await api('locations_add',  { name, address });
        closeLocationModal();
        await renderAdminLocations();
        renderLocationDropdown();
        showToast(editingLocId ? 'Location updated \u2713' : 'Location added \u2713');
    } catch(e) { showToast(e.message || 'Could not save location.'); }
}

async function deleteLocation(id) {
    if (!confirm('Delete this location?')) return;
    try {
        await api('locations_delete', { id });
        await renderAdminLocations();
        renderLocationDropdown();
        showToast('Location deleted.');
    } catch(e) { showToast(e.message || 'Could not delete location.'); }
}

// ── ADMIN: SETTINGS ──────────────────────────────────────────────────
async function loadAdminSettings() {
    try {
        const s = await api('settings_get');
        el('owner-email').value           = s.owner_email           || '';
        el('ejs-user').value              = s.ejs_user              || '';
        el('ejs-service').value           = s.ejs_service           || '';
        el('ejs-template').value          = s.ejs_template          || '';
        el('ejs-customer-template').value = s.ejs_customer_template || '';
    } catch(e) { showToast('Could not load settings.'); }
}

async function savePassword() {
    const current = el('current-password').value;
    const pw1     = el('new-password').value;
    const pw2     = el('confirm-password').value;
    if (!current)       { showToast('Enter your current password.'); return; }
    if (!pw1)           { showToast('Enter a new password.'); return; }
    if (pw1 !== pw2)    { showToast('Passwords do not match.'); return; }
    if (pw1.length < 8) { showToast('Password must be at least 8 characters.'); return; }
    try {
        await api('auth_change_password', { currentPassword: current, newPassword: pw1 });
        el('current-password').value = '';
        el('new-password').value = '';
        el('confirm-password').value = '';
        showToast('Password updated \u2713');
    } catch(e) { showToast(e.message || 'Could not update password.'); }
}

async function saveEmailSettings() {
    try {
        await api('settings_save', {
            owner_email:           el('owner-email').value.trim(),
            ejs_user:              el('ejs-user').value.trim(),
            ejs_service:           el('ejs-service').value.trim(),
            ejs_template:          el('ejs-template').value.trim(),
            ejs_customer_template: el('ejs-customer-template').value.trim(),
        });
        showToast('Email settings saved \u2713');
    } catch(e) { showToast(e.message || 'Could not save settings.'); }
}

// ── CUSTOMER: REVIEWS ────────────────────────────────────────────────
function starsHtml(n, size) {
    return Array.from({length:5}, (_,i) =>
        `<span style="color:${i<n?'var(--pale-brown)':'#DDD4C1'};font-size:${size||17}px;">\u2605</span>`
    ).join('');
}

function renderReviews(revs) {
    const metaEl = el('reviews-meta');
    const gridEl = el('reviews-grid');
    if (!revs || !revs.length) {
        metaEl.innerHTML = '';
        gridEl.innerHTML = `<div class="reviews-empty" style="grid-column:1/-1;">
            <p style="font-size:15px;color:#6B5744;">No reviews yet \u2014 be the first to share your experience!</p>
        </div>`;
        return;
    }
    const avg = revs.reduce((s,r) => s+r.rating, 0) / revs.length;
    metaEl.innerHTML = `
        <div class="reviews-avg-block">
            <div class="reviews-avg-num">${avg.toFixed(1)}</div>
            <div class="reviews-avg-stars">${starsHtml(Math.round(avg), 22)}</div>
            <div class="reviews-avg-count">${revs.length} review${revs.length===1?'':'s'}</div>
        </div>`;
    gridEl.innerHTML = revs.map(r => `
        <div class="review-card">
            <div class="review-card-top">
                <div class="review-display-name">${esc(r.display_name)}</div>
                <div class="review-date">${formatDate((r.created_at||'').split(' ')[0])}</div>
            </div>
            <div class="review-stars">${starsHtml(r.rating, 17)}</div>
            <div class="review-text">${esc(r.review_text)}</div>
            <div class="review-order-pill">
                <strong>\u2713 Verified Order</strong>
                ${r.pickup_date ? formatDate(r.pickup_date) + ' \u00b7 ' : ''}${esc(r.location_name || '')}
            </div>
        </div>`).join('');
}

function openReviewModal() {
    currentRating = 0;
    verifiedOrder = null;
    el('rv-name').value = '';
    el('rv-text').value = '';
    el('rv-order-id').value = '';
    el('rv-order-email').value = '';
    el('rv-verify-result').innerHTML = '';
    el('rv-verify-result').classList.add('hidden');
    el('rv-error').classList.add('hidden');
    updateStarPicker(0);
    el('review-modal').classList.remove('hidden');
}

function closeReviewModal() { el('review-modal').classList.add('hidden'); }

function setRating(n) { currentRating = n; updateStarPicker(n); }

function updateStarPicker(n) {
    document.querySelectorAll('.star-btn').forEach(b => {
        b.classList.toggle('lit', parseInt(b.dataset.v) <= n);
    });
}

async function verifyOrderForReview() {
    const orderId = el('rv-order-id').value.trim().toUpperCase();
    const email   = el('rv-order-email').value.trim();
    const resEl   = el('rv-verify-result');
    if (!orderId || !email) {
        resEl.className = 'verify-strip error';
        resEl.innerHTML = 'Please enter both an Order ID and the email used for that order.';
        resEl.classList.remove('hidden'); return;
    }
    try {
        const data = await api('reviews_verify', { orderId, email });
        verifiedOrder = data.order;
        resEl.className = 'verify-strip';
        resEl.innerHTML = `<strong>\u2713 Order Verified</strong> ${formatDate(data.order.pickup_date)} &nbsp;\u00b7&nbsp; ${esc(data.order.location_name)}`;
        resEl.classList.remove('hidden');
    } catch(e) {
        verifiedOrder = null;
        resEl.className = 'verify-strip error';
        resEl.innerHTML = '\u26a0\ufe0f ' + (e.message || 'Order not found.');
        resEl.classList.remove('hidden');
    }
}

async function submitReview() {
    const name  = el('rv-name').value.trim();
    const text  = el('rv-text').value.trim();
    const errEl = el('rv-error');
    if (!name)          { errEl.textContent='Please enter a display name.'; errEl.classList.remove('hidden'); return; }
    if (!currentRating) { errEl.textContent='Please choose a star rating.'; errEl.classList.remove('hidden'); return; }
    if (!text)          { errEl.textContent='Please write a review.'; errEl.classList.remove('hidden'); return; }
    if (!verifiedOrder) { errEl.textContent='Please verify your order before submitting.'; errEl.classList.remove('hidden'); return; }
    errEl.classList.add('hidden');
    try {
        await api('reviews_submit', {
            orderId:     verifiedOrder.order_id,
            email:       el('rv-order-email').value.trim(),
            displayName: name,
            rating:      currentRating,
            reviewText:  text,
        });
        closeReviewModal();
        showToast('Review submitted! It will appear after approval. Thank you \ud83d\ude4f');
    } catch(e) {
        errEl.textContent = e.message || 'Could not submit review.';
        errEl.classList.remove('hidden');
    }
}

// ── ORDER LOOKUP ──────────────────────────────────────────────────────
function openLookupModal() {
    el('lk-order-id').value = '';
    el('lk-email').value = '';
    el('lk-result').innerHTML = '';
    el('lookup-modal').classList.remove('hidden');
    setTimeout(() => el('lk-order-id').focus(), 80);
}
function closeLookupModal() { el('lookup-modal').classList.add('hidden'); }

async function lookupOrder() {
    const orderId = el('lk-order-id').value.trim().toUpperCase();
    const email   = el('lk-email').value.trim();
    const resEl   = el('lk-result');
    if (!orderId || !email) {
        resEl.innerHTML = `<div class="verify-strip error" style="margin-top:16px;">Please enter both your Order ID and email address.</div>`;
        return;
    }
    try {
        const order = await api('orders_lookup', { orderId, email });
        const sc = {pending:{bg:'#FFF3CD',color:'#856404'},confirmed:{bg:'#D1ECF1',color:'#0C5460'},completed:{bg:'#D4EDDA',color:'#155724'},cancelled:{bg:'#F8D7DA',color:'#721C24'}}[order.status]||{bg:'#eee',color:'#333'};
        resEl.innerHTML = `
            <div class="lookup-order-card">
                <h4>Order ${order.order_id}</h4>
                <div class="lookup-row"><span class="lookup-row-label">Status</span>
                    <span style="background:${sc.bg};color:${sc.color};font-weight:700;font-size:13px;padding:3px 12px;border-radius:20px;">${order.status.charAt(0).toUpperCase()+order.status.slice(1)}</span>
                </div>
                <div class="lookup-row"><span class="lookup-row-label">Pickup Date</span><span>${formatDate(order.pickup_date)}</span></div>
                <div class="lookup-row"><span class="lookup-row-label">Location</span><span>${esc(order.location_name)}</span></div>
                <div class="lookup-row"><span class="lookup-row-label">Items</span>
                    <span style="text-align:right;">${(order.items||[]).map(i=>`${i.quantity}\u00d7 ${esc(i.productName)}`).join('<br>')}</span>
                </div>
                <div class="lookup-row"><span class="lookup-row-label">Order Total</span><strong>${fmtCurrency(order.total_cost)}</strong></div>
                ${order.notes?`<div class="lookup-row"><span class="lookup-row-label">Notes</span><span style="font-style:italic;">${esc(order.notes)}</span></div>`:''}
            </div>`;
    } catch(e) {
        resEl.innerHTML = `<div class="verify-strip error" style="margin-top:16px;">\u26a0\ufe0f ${e.message||'Order not found. Check your Order ID and email.'}</div>`;
    }
}

// ── ADMIN: REVIEWS ────────────────────────────────────────────────────
function filterReviews(f, btn) {
    reviewFilter = f;
    document.querySelectorAll('#tab-reviews .filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderAdminReviews();
}

async function renderAdminReviews() {
    try { adminReviewsList = await api('reviews_list_admin'); } catch(e) { adminReviewsList = []; }
    const pending  = adminReviewsList.filter(r=>r.status==='pending').length;
    const approved = adminReviewsList.filter(r=>r.status==='approved').length;
    el('reviews-admin-counts').textContent = `${pending} pending \u00b7 ${approved} approved`;

    let list = adminReviewsList.slice();
    if (reviewFilter === 'pending')  list = list.filter(r=>r.status==='pending');
    if (reviewFilter === 'approved') list = list.filter(r=>r.status==='approved');
    list.sort((a,b) => new Date(b.created_at)-new Date(a.created_at));

    const listEl = el('reviews-admin-list');
    if (!list.length) {
        listEl.innerHTML = `<div class="empty-state"><div class="empty-icon">\u2b50</div><p>No reviews to show.</p></div>`;
        return;
    }
    listEl.innerHTML = list.map(r => `
        <div class="review-admin-card">
            <div class="review-admin-top">
                <div>
                    <div class="review-admin-name">${esc(r.display_name)}
                        <span class="${r.status==='approved'?'approved-badge':'pending-badge'}" style="margin-left:8px;">${r.status==='approved'?'Approved':'Pending'}</span>
                    </div>
                    <div class="review-admin-meta">${starsHtml(r.rating,14)} &nbsp;\u00b7&nbsp; ${formatDate((r.created_at||'').split(' ')[0])}</div>
                </div>
                <div class="review-admin-actions">
                    ${r.status!=='approved'
                        ? `<button class="btn btn-outline btn-sm" style="color:var(--success);border-color:var(--success);" onclick="approveReview(${r.id})">Approve</button>`
                        : `<button class="btn btn-outline btn-sm" onclick="deleteReview(${r.id})">Delete</button>`}
                    <button class="icon-btn del" onclick="deleteReview(${r.id})" title="Delete">\ud83d\uddd1\ufe0f</button>
                </div>
            </div>
            <div class="review-admin-text">"${esc(r.review_text)}"</div>
            <div class="review-admin-order">\u2713 Order: ${esc(r.order_id)} \u00b7 ${formatDate(r.pickup_date)} \u00b7 ${esc(r.location_name||'')}</div>
        </div>`).join('');
}

async function approveReview(id) {
    try {
        await api('reviews_approve', { id });
        await renderAdminReviews();
        const revs = await api('reviews_list');
        renderReviews(revs);
        showToast('Review approved \u2713');
    } catch(e) { showToast(e.message||'Could not approve.'); }
}

async function deleteReview(id) {
    if (!confirm('Permanently delete this review?')) return;
    try {
        await api('reviews_reject', { id });
        await renderAdminReviews();
        showToast('Review deleted.');
    } catch(e) { showToast(e.message||'Could not delete.'); }
}

// ── INIT ──────────────────────────────────────────────────────────────
(async function init() {
    // ── Step 1: Bootstrap CSRF token ────────────────────────────────
    // Must happen before any state-changing POST request is made.
    try {
        const csrf = await api('csrf_token');
        if (!csrf.token) throw new Error('No token in response');
        _setCsrfToken(csrf.token);
    } catch(e) {
        showToast('Security token failed to load — please refresh the page before placing an order.');
        console.error('CSRF bootstrap failed:', e);
    }

    // ── Step 2: Load public page data in parallel ────────────────────
    try {
        const [prods, locs, dates, revs] = await Promise.all([
            api('products_list'),
            api('locations_list'),
            api('dates_list'),
            api('reviews_list'),
        ]);
        products     = prods;
        locations    = locs;
        blockedDates = dates;
        renderProducts();
        renderLocationDropdown();
        renderReviews(revs);
    } catch(e) { showToast('Error loading page data. Please refresh.'); }

    await loadCart();
    setupPhoneInput();
    setupDateInput();
    initCalendar();

    // Restore session if already logged in (customer or admin, e.g. page refresh)
    try {
        const me = await api('auth_me');
        if (me.user) {
            currentUser = me.user;
            if (me.user.role === 'admin') adminUser = me.user;
        }
    } catch(e) {}
    updateAuthNav();

    ['rv-order-id','rv-order-email'].forEach(id => {
        el(id).addEventListener('input', () => {
            verifiedOrder = null;
            el('rv-verify-result').classList.add('hidden');
        });
    });

})();  // end init

// Mobile hamburger menu — runs immediately (script is at bottom of body)
(function initHamburger() {
    const hamburger = document.getElementById('nav-hamburger');
    const mobileMenu = document.getElementById('nav-mobile-menu');
    if (!hamburger || !mobileMenu) return;
    function closeMobileMenu() {
        hamburger.classList.remove('open');
        hamburger.setAttribute('aria-expanded', 'false');
        mobileMenu.classList.remove('open');
        mobileMenu.setAttribute('aria-hidden', 'true');
    }
    hamburger.addEventListener('click', () => {
        const isOpen = mobileMenu.classList.contains('open');
        if (isOpen) { closeMobileMenu(); }
        else {
            hamburger.classList.add('open');
            hamburger.setAttribute('aria-expanded', 'true');
            mobileMenu.classList.add('open');
            mobileMenu.setAttribute('aria-hidden', 'false');
        }
    });
    mobileMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMobileMenu));
}());
