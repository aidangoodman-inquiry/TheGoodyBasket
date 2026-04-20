# The Goody Basket — Technical Reference
> **RULE: Change, test, repeat until valid. Follow this rule for every change made to this project.**
> ⚠️ **TODO: Hardcoded production DB credentials in `php/prod_config.php` — migrate to .env or external config before going live.**

## Directory Structure

```
TheGoodyBasket/
├── .git/
├── .claude/
│   ├── launch.json                # Python http.server @8080
│   └── settings.local.json
├── php/
│   ├── api.php                    # Single-endpoint API router (~850 lines)
│   ├── config.php                 # DB creds: localhost/root (dev)
│   ├── prod_config.php            # DB creds: prod-iad-vux... (prod)
│   ├── setup_admin.php            # One-time admin setup (DELETE AFTER USE)
│   └── phpinfo.php
├── scripts/
│   └── app.js                     # ~1120 lines: frontend state, cart, calendar, reviews, admin
├── styles.css                     # ~497 lines: CSS custom properties, responsive
├── index.html                     # ~575 lines: dual-view SPA (customer + admin)
├── schema.sql                     # MySQL schema (9 tables)
├── qa_test.php                    # Automated QA suite (88 tests, 14 sections) — dev only
├── start_xampp.bat                # Helper: starts Apache + MySQL via net start
├── Hangyaboly.ttf                 # Custom font (sans-serif)
├── Golden.ttf                     # Custom font (serif heading)
├── TGB Logo.png
└── TGB Logo.jpeg
```

## Tech Stack

| Layer | Technologies |
|-------|-------------|
| Frontend | HTML5, CSS3, Vanilla JS (ES6+) |
| Backend | PHP 7.4+ (PDO), MySQL 5.7+ |
| Fonts | Local .ttf (Golden serif, Hangyaboly sans) |
| Email | EmailJS Browser SDK (CDN, client-side) |
| Server | Apache (XAMPP local), port 8080 (Python http.server) |
| CSS | Custom properties, Flexbox, Grid, mobile-first |

## Server Setup

**Local Dev:** host=localhost, db=thegoodybasket, user=root, pass=(blank), config=php/config.php
**Production:** host=prod-iad-vux-proxysql-pod1001-lb, db=thegoodybasket, user=agoodman54dbhost, pass=Agoodman54!, config=php/prod_config.php
**Dev Server:** .claude/launch.json → Python http.server @8080
**API:** All requests → /php/api.php?action=<name>
**No .htaccess detected** — static file serving, no mod_rewrite

## Database Schema (MySQL)

| Table | Key Cols | Purpose |
|-------|----------|---------|
| `accounts` | id, email, password_hash, role | admin/customer logins |
| `products` | id, name, price, category, description, available, allergens×6 | sourdough catalog |
| `orders` | id, order_id(TGB-XXXX), customer_name, email, phone, pickup_date, location_id, status, total_cost, notes | order records |
| `order_items` | id, order_id, product_id, quantity, unit_cost | line items |
| `locations` | id, name, address, active | pickup spots |
| `carts` | id, account_id OR session_id | cart containers |
| `cart_items` | id, cart_id, product_id, quantity | cart line items |
| `blocked_dates` | id, date | admin-blocked pickup dates |
| `reviews` | id, order_id, display_name, rating(1-5), review_text, status(pending/approved) | customer reviews |
| `settings` | key, value | owner_email, EmailJS creds (k/v store) |

FKs: account_id, product_id, order_id (CASCADE/RESTRICT). Unique: email, order_id, date, cart(account_id\|session_id).

## HTML Structure

### Customer View (`#customer-view`)
- `#customer-nav` — sticky, logo, links (Breads/Reviews/Story/Track/Order), mobile hamburger, cart badge
- `#hero` — gradient bg, logo, tagline, CTA btns, feature chips
- `#about` — story paragraphs, center text
- `#reviews` — avg rating block, review card grid, Leave Review CTA
- `#products` — grid (auto-fill minmax 260px), product cards (icon, badge, name, price, allergens, add-to-cart)
- `#order` — form: name/email/phone, cart display, date calendar picker, location dropdown, notes, total box
- `#footer` — brand, links, copyright, admin link

### Admin View (`#admin-view`, hidden by default)
- `#admin-header` — brand + "Admin" pill, logout/view-site btns
- Tabs (6): Orders | Calendar | Products | Locations | Reviews | Settings
  - **Orders**: stats grid (total/pending/confirmed/today/revenue), filterable orders table
  - **Calendar**: month nav, 7-col grid, date toggle (blocked↔available), loaves booked indicator
  - **Products**: cards (edit/delete icons, availability toggle)
  - **Locations**: list (edit/delete icons)
  - **Reviews**: cards (approve/delete), filter (all/pending/approved)
  - **Settings**: password change, EmailJS config

### Modals
- `#admin-login-modal` — email/password, error display
- `#success-modal` — order confirmation (order ID)
- `#product-modal` — add/edit product (name, category, price, desc, allergens×6, available)
- `#location-modal` — add/edit location (name, address)
- `#review-modal` — submit review (name, rating stars, text, order verify)
- `#lookup-modal` — order tracking (orderID, email, result card)

### CSS Classes (BEM-ish)
`.btn` `.btn-primary` `.btn-outline` `.btn-sm` `.btn-block` / `.product-card` `.product-icon` `.badge` `.badge-regular` `.badge-specialty` / `.cart-item` `.cart-item-info` `.qty-btn` `.cart-remove-btn` / `.cal-day` `.cal-blocked` `.cal-has-orders` `.cal-today` `.cal-past` `.cal-restricted` / `.review-card` `.review-admin-card` `.star-btn.lit` / `.form-group` `.form-row` `.form-error` `.field-note` `.field-note-muted` / `.toggle` `.toggle-wrap` `.checkbox-grid`

## CSS Architecture

### Root Variables (~24 props)
```css
--ivory:#FAF7F0  --pale-brown:#D1B080  --warm-brown:#D1B080  --dark-brown:#745D3A
--cream:#ECDEC9  --border:#D9C9A8  --white:#FFFFFF  --success:#5C8A5C  --error:#A84444
--font-serif:'Golden',Georgia,serif  --font-sans:'Hangyaboly',Arial,sans-serif
--shadow:0 4px 20px rgba(116,93,58,0.12)  --shadow-lg:0 8px 40px rgba(116,93,58,0.20)
--radius:12px  --radius-sm:6px
```

### Key Patterns
- **Layout:** Flexbox (nav, buttons, carts), CSS Grid (products 260px minmax, calendar 7-col)
- **Cards:** border 1.5px solid var(--border), box-shadow, border-radius var(--radius)
- **Buttons:** inline-flex, align-center, gap 8px, padding 12px 28px, border-radius 40px
- **Forms:** input/select/textarea width 100%, padding 12px 16px, border 1.5px, focus: border-color pale-brown
- **Calendar:** 7-col grid, gap 4-6px, .cal-day aspect-ratio 1, cursor pointer
- **Admin Table:** th uppercase 11px, td 14px, hover bg #FDFAF5
- **Modal:** fixed inset 0, bg rgba(61,43,31,0.6), backdrop-filter blur(4px), max-width 420px

### Responsive (@media 720px)
- form-row: 1fr (was 2 cols); nav-links: hidden, hamburger: flex; hero-buttons: column; footer-top: column; stats-grid: 2 cols; order-form-container: padding 28px 18px; Modal: width 100%

### Font Faces
```css
@font-face { font-family:'Golden'; src:url('golden.regular.ttf') }
@font-face { font-family:'Hangyaboly'; src:url('Hangyaboly.ttf') }
```

### Notable Techniques
Backdrop-filter blur on nav & modals; aspect-ratio for calendar squares; repeating-linear-gradient for .cal-restricted stripes; transition: all 0.2s; box-shadow depth layering.

## JavaScript (app.js — 1120 lines)

### Global State
```js
let products=[];      // [{id,name,price,category,description,available,allergens×6}]
let locations=[];     // [{id,name,address,active}]
let cart=[];          // [{id,name,price,category,quantity,available}]
let blockedDates=[];  // ['YYYY-MM-DD',...]
let adminOrders=[];   // [{id,order_id,customer_name,email,phone,total_cost,items[],status,pickup_date}]
let adminReviewsList=[];
let adminUser=null;   // {id,email,role}
let currentFilter='all'; let reviewFilter='all';
let editingProductId=null; let editingLocId=null;
let currentRating=0; let verifiedOrder=null;
```

### API Layer
```js
async function api(action, data)
// POST /php/api.php?action=<action>, JSON body, parse response, throw on error field
```

### Customer: Cart
- `loadCart()` — fetch cart_get, render
- `addToCart(id)` — POST cart_add, scroll #order on first item
- `removeFromCart(id)` — POST cart_remove
- `changeQty(id, delta)` — POST cart_update/remove
- `renderCart()` — items with qty controls, subtotals, total box
- `updateCartBadge()` — update nav badge count

### Customer: Date Picker Calendar
- `setupDateInput()` — init custCalYear/custCalMonth
- `custCalPrev/Next()` — month nav, re-render
- `renderCustCalendar()` — DOM: empty days, date squares; classes: cal-past (≤today), cal-restricted (not Tue-Fri), cal-blocked, cal-today, cust-cal-selected-day
- `selectCustDate(dateStr)` — set #pickup-date value, show label
- Rules: ALLOWED_DAYS=[2,3,4,5] (Tue-Fri), MAX_LOAVES_PER_DAY=4

### Customer: Order Form
- submit listener → validate (required fields, email format, date available, cart not empty) → POST orders_place → clear form, show #success-modal, call sendEmail()
- `sendEmail()` — fetch settings_public for EmailJS creds, send params: customer_name/email/phone/order_items/total/pickup_date/location/notes/order_id; two templates (owner + customer receipt)

### Customer: Reviews
- `openReviewModal()` — clear form, reset currentRating
- `setRating(n)` — currentRating=n, updateStarPicker()
- `updateStarPicker(n)` — toggle .lit on star-btns data-v<=n
- `verifyOrderForReview()` — POST reviews_verify (orderId, email) → set verifiedOrder, show verify-strip
- `submitReview()` — validate (name, rating, text, verified) → POST reviews_submit
- `renderReviews(revs)` — avg rating + review cards (display_name, stars, text, verified pill)

### Customer: Order Lookup
- `lookupOrder()` — POST orders_lookup (orderId, email) → render lookup-order-card (status, items, total, notes)

### Admin: Auth
- `doAdminLogin()` — POST auth_login → check role==='admin' → set adminUser, show admin view
- `adminLogout()` — POST auth_logout → switch to customer
- On init: POST auth_me → restore session if admin

### Admin: View Switching
- `showAdminView()` — hide customer, show admin, init calendar, render tabs
- `switchToCustomer()` — hide admin, show customer, reload cart/products/locations

### Admin: Tabs
- `showTab(tab, btn)` — toggle visibility, active btn, call render for tab (async)

### Admin: Dashboard
- `renderDashboard()` — fetch orders_list; stats: total/pending/confirmed/today pickups (excludes cancelled)/revenue; orders table by filter; status select → POST orders_status
- `filterOrders(filter, btn)` — currentFilter=filter, re-render
- `updateOrderStatus(orderId, selectEl)` — POST orders_status
- Today's Pickups stat: `adminOrders.filter(o => o.pickup_date===today && o.status!=='cancelled').length`

### Admin: Calendar
- `initCalendar()` — set calYear/calMonth to now
- `renderCalendar()` — fetch dates_list + orders_list; grid with loaves booked (n/MAX); classes: cal-restricted/blocked/has-orders/today/past; hover tooltip
- `toggleDate(dateStr)` — POST dates_toggle

### Admin: Products
- `renderAdminProducts()` — fetch products_list, display cards
- `openAddProduct()` / `openEditProduct(id)` — populate modal
- `saveProduct()` — POST products_add or products_edit (name, category, price, desc, available, allergens×6)
- `deleteProduct(id)` — confirm → POST products_delete
- `toggleAvailable(id, available)` — POST products_toggle

### Admin: Locations
- `renderAdminLocations()` — fetch locations_list
- `saveLocation()` — POST locations_add or locations_edit (name, address)
- `deleteLocation(id)` — confirm → POST locations_delete

### Admin: Settings
- `loadAdminSettings()` — fetch settings_get, populate form (owner_email, ejs_user, ejs_service, ejs_template, ejs_customer_template)
- `savePassword()` — POST auth_change_password (currentPassword, newPassword; min 8 chars, match confirm)
- `saveEmailSettings()` — POST settings_save

### Admin: Reviews
- `renderAdminReviews()` — fetch reviews_list_admin; filter all/pending/approved; cards with approve/delete
- `approveReview(id)` — POST reviews_approve
- `deleteReview(id)` — confirm → POST reviews_reject

### Utilities
```js
el(id)               // document.getElementById
esc(str)             // XSS escape: &, <, >, ", ' → HTML entities; use on ALL innerHTML injections
todayStr()           // 'YYYY-MM-DD'
toISODate(y,m,d)     // 'YYYY-MM-DD'
formatDate(str)      // 'Jan 1, 2026'
fmtCurrency(n)       // '$12.34'
showToast(msg)       // display toast 3.2s, translateY
starsHtml(n, size)   // return stars HTML
cartQty(productId)   // qty in cart
isAvailableDay(str)  // check ALLOWED_DAYS
sBg/sBorder/sColor(status) // status-specific colors
```

### Event Listeners
- Phone input: auto-format (XXX) XXX-XXXX, prevent non-digit
- Review form: clear verifiedOrder on orderId/email change
- Hamburger: toggle .open, close on anchor click, aria-expanded/hidden
- Order form: submit → validate → POST

### Init (IIFE)
```js
(async function init() {
  // 1. Parallel fetch: products, locations, dates, reviews
  // 2. renderProducts, renderLocationDropdown, renderReviews
  // 3. loadCart, setupPhoneInput, setupDateInput, initCalendar
  // 4. POST auth_me → restore admin session
  // 5. Add review verification input listeners
})();
```

## PHP Backend (api.php — ~850 lines)

### Router
Single-file: `$_GET['action']` → switch; all POST/GET-compatible; response: JSON.

### Auth Endpoints
| Action | Auth | Purpose |
|--------|------|---------|
| auth_register | - | Create customer account |
| auth_login | - | Login, set $_SESSION['user'] |
| auth_logout | - | session_unset/destroy |
| auth_me | - | Get current user from session |
| auth_change_password | ✓ | Change password (requires current) |

### Products Endpoints
| Action | Auth | Purpose |
|--------|------|---------|
| products_list | - | All products (public) |
| products_add | ✓ | Create product |
| products_edit | ✓ | Update product |
| products_delete | ✓ | Delete product |
| products_toggle | ✓ | Toggle available flag |

### Orders Endpoints
| Action | Auth | Purpose |
|--------|------|---------|
| orders_place | - | Validate + insert order, check capacity |
| orders_list | ✓ | All orders (admin) |
| orders_lookup | - | Customer lookup (orderId, email) |
| orders_status | ✓ | Update order status |
| orders_mine | ✓ | User's orders |

### Cart Endpoints
| Action | Auth | Purpose |
|--------|------|---------|
| cart_get | - | Fetch cart (session or account) |
| cart_add | - | Add item |
| cart_update | - | Update quantity |
| cart_remove | - | Remove item |
| cart_clear | - | Clear entire cart |

**Cart Strategy:** Logged-in → carts.account_id; Guest → carts.session_id (bin2hex random_bytes, stored in $_SESSION['guest_cart_sid'])

### Locations Endpoints
| Action | Auth | Purpose |
|--------|------|---------|
| locations_list | - | Active locations |
| locations_add | ✓ | Create |
| locations_edit | ✓ | Update |
| locations_delete | ✓ | Delete |

### Dates/Reviews/Settings Endpoints
| Action | Auth | Purpose |
|--------|------|---------|
| dates_list | - | All blocked dates |
| dates_toggle | ✓ | Block/unblock date |
| reviews_list | - | Approved reviews (public) |
| reviews_list_admin | ✓ | All reviews |
| reviews_verify | - | Verify order for review |
| reviews_submit | - | Submit review (→ pending) |
| reviews_approve | ✓ | Mark approved |
| reviews_reject | ✓ | Delete review |
| settings_get | ✓ | All settings (admin) |
| settings_save | ✓ | Save EmailJS/email settings |
| settings_public | - | EmailJS creds only (for frontend) |

### Business Logic

**orders_place:** validate (name/email/date/location), check blocked_dates, check capacity (sum(qty) for date < 4), validate items (product exists+available, recalculate price from DB), generate orderId="TGB-"+strtoupper(bin2hex(random_bytes(4))) (e.g. TGB-A1B2C3D4 — true 8-char uppercase hex), insert order+order_items, clear cart if logged in, return orderId+total.

**productsDelete:** wrapped in try/catch; if PDOException SQLSTATE 23000 (FK constraint), returns HTTP 409 with a user-friendly message instead of crashing. Use the availability toggle to hide products that have existing orders.

**reviews_verify:** check order exists (orderId + LOWER(email)), check no existing review for order_id (prevent duplicates), return order details.

**reviewsSubmit:** verify order matches, rating 1-5, insert review status='pending'.

### Helper Functions
```php
getDB()           // singleton PDO (static), charset utf8mb4, ERRMODE EXCEPTION, EMULATE_PREPARES false
respond($data, $code)      // JSON + exit
respondError($msg, $code)  // {error:msg} + exit
body()            // parse JSON from php://input
isAdmin()         // $_SESSION['user']['role']==='admin'
requireAdmin()    // throw 401 if not admin
isLoggedIn()      // check $_SESSION['user']
```

All queries use PDO prepared statements. fetchAll() → assoc arrays.

### Session
`session_start()` at top; `$_SESSION['user']={id,email,role}`; `$_SESSION['guest_cart_sid']`; session_unset/destroy on logout.

## Form Handling

### Order Form (#order-form)
Fields: customer-name(text,req), customer-email(email,req), customer-phone(tel), pickup-date(hidden, set by calendar), pickup-location(select,req), order-notes(textarea)
Validation (JS): required fields, email regex, cart not empty, date available (Tue-Fri, not blocked), min 1 loaf
Action: POST /php/api.php?action=orders_place (via JS, not HTML action attr)
Server re-validates: price from DB (not cart), capacity check

### Admin: Product Form
Fields: prod-name(text), prod-category(select:regular|specialty), prod-price(number), prod-desc(textarea), prod-available(checkbox), allergens(6× checkboxes:dairy/treenuts/egg/peanut/sesame/soy)
Action: products_add or products_edit

### Admin: Location Form
Fields: loc-name(text), loc-address(text) — both required
Action: locations_add or locations_edit

### Customer: Review Form
Fields: rv-name(text), rv-text(textarea), rv-order-id(text,uppercase), rv-order-email(email), rating(star picker 1-5)
Verification step required before submit. Action: reviews_verify → reviews_submit

### Admin: Settings Form
Fields: owner-email(email), ejs-user(text), ejs-service(text), ejs-template(text), ejs-customer-template(text)
Action: settings_save — EmailJS keys optional

## Third-Party Dependencies

| Type | Source | Purpose |
|------|--------|---------|
| EmailJS | `https://cdn.jsdelivr.net/npm/@emailjs/browser@3/dist/email.min.js` | Client-side order notifications |
| Fonts | Local .ttf files | Golden (serif), Hangyaboly (sans) |

No npm, no composer, no build tool. No other CDN links.

### EmailJS Integration
Public key, service ID, template IDs stored in settings table (settings_public endpoint). On order success: send owner notification + customer receipt. Params: customer_name, email, phone, order_items(formatted string), order_total, pickup_date, pickup_location, order_notes, order_id, to_email.

## Key Constants

```js
ALLOWED_DAYS = [2,3,4,5]          // Tue–Fri pickup only
MAX_LOAVES_PER_DAY = 4            // Capacity limit per date
MIN_PASSWORD_LENGTH = 8
ORDER_ID_FORMAT = 'TGB-' + hex(8) // e.g. TGB-A1B2C3D4
```

## Security

✓ PASSWORD_BCRYPT
✓ requireAdmin() middleware (401 on all admin endpoints)
✓ PDO prepared statements (all queries)
✓ filter_var FILTER_VALIDATE_EMAIL
✓ Session-based auth
✓ Duplicate review prevention
✓ CSRF tokens — X-CSRF-Token header required for all state-changing POSTs; token bootstrapped via `?action=csrf_token`, rotated on login/logout; returns 403 if missing/invalid
✓ Rate limiting — sliding window, 60 req/min general, 10 req/min auth; localhost (127.0.0.1/::1) exempt; tracked in `rate_limits` MySQL table
✓ XSS escaping — `esc()` helper in app.js applied to all innerHTML injection points (product names, descriptions, order data, reviews, notes, location names)
✓ FK-safe product deletion — products_delete returns 409 + friendly message on SQLSTATE 23000 instead of crashing

⚠️ setup_admin.php exposes plaintext password in source (DELETE after use)
⚠️ prod_config.php has hardcoded DB creds (should use env vars)
✓ session_regenerate_id(true) on login, register, and logout (prevents session fixation)
⚠️ CORS not configured in api.php (configure for prod domain before launch)

## Notable Patterns

**Dual-View SPA:** Single index.html, toggle #customer-view/#admin-view via JS, no page reload on auth switch.
**Guest+LoggedIn Cart:** Both stored in DB (carts table), keyed by account_id or session_id.
**Calendar Rules:** Tue-Fri only, max 4 loaves/day, admin-blocked dates. UI: striped=restricted, pink=blocked, green=has-orders.
**Review Gating:** Order+email match required, one review per order, admin approval before public display.
**Client+Server Price Validation:** Cart total client-side, but server re-prices from DB on order placement.

## Missing / Notable Absences

❌ No .htaccess / mod_rewrite
❌ No build tool (webpack/vite/etc.)
❌ No package.json or composer.json
❌ No CI/CD
❌ No environment-based config switching (manual file swap between config.php / prod_config.php)
❌ No logging/error tracking
❌ No pagination (orders, reviews)
❌ No image uploads for products
❌ No payment processing (manual/cash only)
❌ No inventory tracking (capacity per day only)

## File Summary

| File | Size | Purpose |
|------|------|---------|
| index.html | 32 KB | Markup (dual view, modals, forms, calendar) |
| styles.css | 36 KB | Responsive design, CSS custom props, layout |
| scripts/app.js | 57 KB | Frontend state, API calls, DOM manipulation, esc() XSS helper |
| php/api.php | 38 KB | Backend router, all endpoints, business logic |
| php/config.php | 531 B | Dev DB credentials |
| php/prod_config.php | 577 B | Prod DB credentials |
| php/setup_admin.php | 3.6 KB | One-time admin setup (DELETE AFTER USE) |
| schema.sql | 6 KB | DB schema (9 tables) |
| qa_test.php | 47 KB | Automated QA suite — 88 tests, 14 sections (dev only, do not deploy) |
| start_xampp.bat | 152 B | Helper: `net start Apache2.4 && net start mysql` |

## Deployment Checklist

1. Upload files (exclude .git, .claude)
2. Create MySQL DB, import schema.sql
3. Update php/config.php or use prod_config.php
4. Run setup_admin.php → DELETE IT
5. Set Apache DocumentRoot to TheGoodyBasket/
6. Configure CORS header in api.php for prod domain
7. Set up HTTPS/SSL
8. Test: auth_login, orders_place, EmailJS (if configured)
9. Monitor error logs
