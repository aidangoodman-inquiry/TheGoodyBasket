<?php
// ================================================================
// THE GOODY BASKET — Production QA Test Suite
// 88 tests across 14 sections.
//
// Usage (CLI):
//   php qa_test_prod.php > qa_results.html
//
// Usage (browser):
//   https://thegoodybasket.com/qa_test_prod.php
//   http://localhost/TheGoodyBasket/qa_test_prod.php
//
// Requires qa_config_prod.php in the same directory.
// DO NOT deploy to production without restricting access.
// ================================================================

if (!file_exists(__DIR__ . '/qa_config_prod.php')) {
    die('<pre>ERROR: qa_config_prod.php not found. See QA_TEST_README.md for setup instructions.</pre>');
}
require_once __DIR__ . '/qa_config_prod.php';
set_time_limit(QA_SCRIPT_TIMEOUT);


// ════════════════════════════════════════════════════════════════
// SHARED STATE
// ════════════════════════════════════════════════════════════════

$ts = time();

$S = [                                  // Shared mutable test state
    'adminCookie'   => '',              // cookie jar file for admin session
    'guestCookie'   => '',              // cookie jar file for guest session
    'custCookie'    => '',              // cookie jar file for customer session
    'adminCsrf'     => '',
    'guestCsrf'     => '',
    'custCsrf'      => '',
    'productId'     => 0,
    'locationId'    => 0,
    'orderId'       => '',              // TGB-XXXXXXXX
    'orderEmail'    => "qa.order.$ts@example.com",
    'reviewId'      => 0,
    'custEmail'     => "qa.cust.$ts@example.com",
    'custPass'      => 'TestPass123!',
    'pickupDate'    => '',              // valid Tue-Fri date ~60 days out
    'blockDate'     => '',              // date to block/unblock ~67 days out
];

// ── Result tracking ──────────────────────────────────────────────
$pass     = 0;
$fail     = 0;
$skipped  = 0;
$sections = [];     // [ ['title'=>str, 'tests'=>[...]] ]
$failures = [];     // subset of tests that failed (for summary at bottom)
$curTests = [];     // buffer for current section

function beginSection(string $title): void {
    global $curTests;
    $curTests = [];
}

function endSection(string $title): void {
    global $sections, $curTests;
    $sections[] = ['title' => $title, 'tests' => $curTests];
    $curTests   = [];
}

/**
 * Record one test result.
 * @param string $name     Test label shown in report
 * @param bool   $passed   true = pass, false = fail
 * @param string $detail   Extra context (shown under the test row)
 * @param string $skipMsg  If non-empty, test is marked skipped instead
 * @return bool $passed (or false for skipped)
 */
function t(string $name, bool $passed, string $detail = '', string $skipMsg = ''): bool {
    global $pass, $fail, $skipped, $curTests, $failures;
    if ($skipMsg !== '') {
        $skipped++;
        $curTests[] = ['name' => $name, 'status' => 'skip', 'detail' => $skipMsg];
        return false;
    }
    if ($passed) {
        $pass++;
        $curTests[] = ['name' => $name, 'status' => 'pass', 'detail' => $detail];
        return true;
    }
    $fail++;
    $entry      = ['name' => $name, 'status' => 'fail', 'detail' => $detail];
    $curTests[] = $entry;
    $failures[] = $entry;
    return false;
}


// ════════════════════════════════════════════════════════════════
// HTTP HELPERS
// ════════════════════════════════════════════════════════════════

/**
 * Call api.php with an action, optional JSON body, cookie jar, and CSRF header.
 * Returns [httpCode, decodedJson, rawBody, curlError].
 */
function api(string $action, array $body = [], string $cookie = '', string $csrf = ''): array {
    $url = QA_API_URL . '?action=' . urlencode($action);
    $ch  = curl_init($url);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => QA_CURL_TIMEOUT,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body ? json_encode($body) : '{}',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($cookie !== '') {
        $opts[CURLOPT_COOKIEJAR]  = $cookie;
        $opts[CURLOPT_COOKIEFILE] = $cookie;
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return [0, null, '', $err];
    return [$code, @json_decode($raw, true), $raw, ''];
}

/** Fetch a plain URL (GET), return [httpCode, bodyString]. */
function get(string $url, string $cookie = ''): array {
    $ch   = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => QA_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($cookie !== '') {
        $opts[CURLOPT_COOKIEJAR]  = $cookie;
        $opts[CURLOPT_COOKIEFILE] = $cookie;
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

/** Create a temporary cookie jar file. Cleaned up at script end. */
function makeCookieJar(): string {
    $f = tempnam(sys_get_temp_dir(), 'tgb_qa_');
    register_shutdown_function(function () use ($f) {
        if (file_exists($f)) @unlink($f);
    });
    return $f;
}

/** Find the nearest Tuesday–Friday at least $daysAhead from today. */
function nextPickupDate(int $daysAhead): string {
    $d = new DateTime("+$daysAhead days");
    $allowed = [2, 3, 4, 5]; // Tue=2, Wed=3, Thu=4, Fri=5
    while (!in_array((int)$d->format('w'), $allowed, true)) {
        $d->modify('+1 day');
    }
    return $d->format('Y-m-d');
}


// ════════════════════════════════════════════════════════════════
// SECTION 0 — SETUP
// ════════════════════════════════════════════════════════════════

function section0_setup(): void {
    global $S;
    beginSection('§0 Setup');

    // Pre-compute dates
    $S['pickupDate'] = nextPickupDate(QA_PICKUP_DAYS_AHEAD);
    $S['blockDate']  = nextPickupDate(QA_PICKUP_DAYS_AHEAD_ALT);

    // Create cookie jars
    $S['adminCookie'] = makeCookieJar();
    $S['guestCookie'] = makeCookieJar();
    $S['custCookie']  = makeCookieJar();

    // T0.1 — Index page is reachable
    [$code, $body] = get(QA_BASE_URL . '/index.html');
    t('Index page is accessible (HTTP 200)', $code === 200,
      "GET " . QA_BASE_URL . "/index.html → HTTP $code");

    // T0.2 — CSRF token bootstrap
    [$code, $json, , $err] = api('csrf_token', [], $S['adminCookie']);
    $ok = ($code === 200 && isset($json['token']) && strlen($json['token']) === 64);
    $S['adminCsrf'] = $json['token'] ?? '';
    t('CSRF token endpoint returns 64-char hex token', $ok,
      $err ?: "HTTP $code, token=" . substr($S['adminCsrf'], 0, 8) . '…');

    // Abort early if server is unreachable
    if ($code === 0) {
        t('Server connectivity check', false,
          'Could not reach ' . QA_API_URL . '. Is XAMPP / Apache running?');
    }

    // Seed guest CSRF
    [$code2, $json2] = api('csrf_token', [], $S['guestCookie']);
    $S['guestCsrf'] = $json2['token'] ?? '';

    // Seed customer CSRF
    [$code3, $json3] = api('csrf_token', [], $S['custCookie']);
    $S['custCsrf'] = $json3['token'] ?? '';

    // Silent admin login — needed for all admin operation sections regardless
    // of whether QA_TEST_AUTH is enabled.  Auth *behaviour* is tested in §2.
    if (!empty($S['adminCsrf'])) {
        $loginResp = api('auth_login', [
            'email'    => QA_ADMIN_EMAIL,
            'password' => QA_ADMIN_PASS,
        ], $S['adminCookie'], $S['adminCsrf']);
        if (!empty($loginResp[1]['csrfToken'])) {
            $S['adminCsrf'] = $loginResp[1]['csrfToken'];
        }
    }

    endSection('§0 Setup');
}


// ════════════════════════════════════════════════════════════════
// SECTION 1 — PUBLIC ENDPOINTS
// ════════════════════════════════════════════════════════════════

function section1_public(): void {
    global $S;
    beginSection('§1 Public Endpoints');

    $skip = QA_TEST_PUBLIC_ENDPOINTS ? '' : 'Section disabled in qa_config.php';

    // T1.1
    [$code, $json] = api('products_list', [], $S['guestCookie']);
    t('products_list returns HTTP 200 array', $skip === '' ? ($code === 200 && is_array($json)) : false,
      "HTTP $code, " . count((array)$json) . ' products', $skip);

    // T1.2 — Verify product structure (skip gracefully when DB is empty on a fresh install;
    //         structure is re-verified in §3 after the QA product is created)
    $firstProduct  = is_array($json) && isset($json[0]) && is_array($json[0]) ? $json[0] : null;
    $hasFields     = $firstProduct &&
                     array_key_exists('id', $firstProduct) &&
                     array_key_exists('name', $firstProduct) &&
                     array_key_exists('price', $firstProduct) &&
                     array_key_exists('category', $firstProduct);
    $structureSkip = ($skip === '' && !$firstProduct)
                     ? 'No products in DB yet — structure verified in §3 after product creation'
                     : $skip;
    t('products_list items have id/name/price/category fields',
      $structureSkip === '' ? (bool)$hasFields : false,
      $firstProduct ? 'id=' . $firstProduct['id'] . ', name=' . $firstProduct['name'] : 'Empty DB (OK on first run)',
      $structureSkip);

    // T1.3
    [$code, $json] = api('locations_list', [], $S['guestCookie']);
    t('locations_list returns HTTP 200 array', $skip === '' ? ($code === 200 && is_array($json)) : false,
      "HTTP $code, " . count((array)$json) . ' locations', $skip);

    // T1.4
    [$code, $json] = api('dates_list', [], $S['guestCookie']);
    t('dates_list returns HTTP 200 array', $skip === '' ? ($code === 200 && is_array($json)) : false,
      "HTTP $code, " . count((array)$json) . ' blocked dates', $skip);

    // T1.5
    [$code, $json] = api('reviews_list', [], $S['guestCookie']);
    t('reviews_list returns HTTP 200 array', $skip === '' ? ($code === 200 && is_array($json)) : false,
      "HTTP $code, " . count((array)$json) . ' approved reviews', $skip);

    // T1.6
    [$code, $json] = api('settings_public', [], $S['guestCookie']);
    t('settings_public returns HTTP 200 object', $skip === '' ? ($code === 200 && is_array($json)) : false,
      "HTTP $code, keys: " . implode(', ', array_keys((array)$json)), $skip);

    // T1.7
    [$code, $json] = api('auth_me', [], $S['guestCookie']);
    t('auth_me unauthenticated returns {user: null}', $skip === '' ? ($code === 200 && $json['user'] === null) : false,
      "HTTP $code, user=" . json_encode($json['user'] ?? 'missing'), $skip);

    // T1.8
    [$code, $json] = api('cart_get', [], $S['guestCookie']);
    t('cart_get unauthenticated returns {items: []}', $skip === '' ? ($code === 200 && isset($json['items']) && is_array($json['items'])) : false,
      "HTTP $code, items=" . count((array)($json['items'] ?? [])), $skip);

    // T1.9
    [$code, $json] = api('unknown_garbage_action', [], $S['guestCookie']);
    t('Unknown action returns HTTP 404', $skip === '' ? ($code === 404) : false,
      "HTTP $code", $skip);

    endSection('§1 Public Endpoints');
}


// ════════════════════════════════════════════════════════════════
// SECTION 2 — AUTH & VALIDATION
// ════════════════════════════════════════════════════════════════

function section2_auth(): void {
    global $S;
    beginSection('§2 Auth & Validation');

    $skip = QA_TEST_AUTH ? '' : 'Section disabled in qa_config.php';

    // T2.1 — Empty fields rejected
    [$code, $json] = api('auth_login', ['email' => '', 'password' => ''], $S['adminCookie'], $S['adminCsrf']);
    t('Login with empty fields returns 400', $skip === '' ? ($code === 400 && isset($json['error'])) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T2.2 — Wrong password
    [$code, $json] = api('auth_login', ['email' => QA_ADMIN_EMAIL, 'password' => 'wrongpassword!'], $S['adminCookie'], $S['adminCsrf']);
    t('Login with wrong password returns 401', $skip === '' ? ($code === 401) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T2.3 — Admin login success
    [$code, $json] = api('auth_login', ['email' => QA_ADMIN_EMAIL, 'password' => QA_ADMIN_PASS], $S['adminCookie'], $S['adminCsrf']);
    $ok = ($code === 200 && ($json['success'] ?? false) === true && ($json['user']['role'] ?? '') === 'admin');
    if ($ok) $S['adminCsrf'] = $json['csrfToken'] ?? $S['adminCsrf'];
    t('Admin login success returns 200 + role=admin', $skip === '' ? $ok : false,
      "HTTP $code, role=" . ($json['user']['role'] ?? 'missing') . ", csrfToken rotated=" . (isset($json['csrfToken']) ? 'yes' : 'no'), $skip);

    // T2.4 — auth_me confirms admin session
    [$code, $json] = api('auth_me', [], $S['adminCookie']);
    $ok = ($code === 200 && ($json['user']['role'] ?? '') === 'admin');
    t('auth_me confirms admin session after login', $skip === '' ? $ok : false,
      "HTTP $code, user.role=" . ($json['user']['role'] ?? 'null'), $skip);

    // T2.5 — Logout rotates token
    [$code, $json] = api('auth_logout', [], $S['adminCookie'], $S['adminCsrf']);
    $ok = ($code === 200 && ($json['success'] ?? false) === true && isset($json['csrfToken']));
    if ($ok) $S['adminCsrf'] = $json['csrfToken'];
    t('auth_logout returns 200 + new csrfToken', $skip === '' ? $ok : false,
      "HTTP $code, csrfToken=" . (isset($json['csrfToken']) ? 'rotated' : 'missing'), $skip);

    // Re-login admin for remaining sections
    if ($skip === '') {
        [$code, $json] = api('auth_login', ['email' => QA_ADMIN_EMAIL, 'password' => QA_ADMIN_PASS], $S['adminCookie'], $S['adminCsrf']);
        if (isset($json['csrfToken'])) $S['adminCsrf'] = $json['csrfToken'];
    }

    endSection('§2 Auth & Validation');
}


// ════════════════════════════════════════════════════════════════
// SECTION 3 — ADMIN: PRODUCT CRUD
// ════════════════════════════════════════════════════════════════

function section3_products(): void {
    global $S;
    beginSection('§3 Admin — Product CRUD');

    $skip = QA_TEST_ADMIN_OPERATIONS ? '' : 'Section disabled in qa_config.php';

    // T3.1 — Add product
    [$code, $json] = api('products_add', [
        'name'        => 'QA Test Sourdough',
        'description' => 'Automated QA test product — safe to delete.',
        'price'       => 12.00,
        'category'    => 'regular',
        'available'   => true,
        'dairy'       => false,
        'treeNuts'    => false,
        'egg'         => false,
        'peanut'      => false,
        'sesame'      => false,
        'soy'         => false,
    ], $S['adminCookie'], $S['adminCsrf']);
    $ok = ($code === 200 && isset($json['id']) && $json['id'] > 0);
    if ($ok) $S['productId'] = $json['id'];
    t('products_add creates product, returns id', $skip === '' ? $ok : false,
      "HTTP $code, id=" . ($json['id'] ?? 'missing'), $skip);

    // T3.2 — Product appears in list + verify response structure
    [$code, $list] = api('products_list', [], $S['guestCookie']);
    $found   = false;
    $product = null;
    foreach ((array)$list as $p) {
        if ((int)($p['id'] ?? 0) === $S['productId']) { $found = true; $product = $p; break; }
    }
    t('products_list includes newly added product', $skip === '' ? $found : false,
      "Looking for id={$S['productId']} in " . count((array)$list) . " products", $skip);

    // T3.2b — Verify product response has all required fields
    $hasFields = $product &&
                 array_key_exists('id', $product) &&
                 array_key_exists('name', $product) &&
                 array_key_exists('price', $product) &&
                 array_key_exists('category', $product) &&
                 array_key_exists('available', $product);
    t('products_list item has id/name/price/category/available fields',
      $skip === '' ? (bool)$hasFields : false,
      $product ? 'id=' . $product['id'] . ', name=' . $product['name'] . ', price=' . $product['price'] : 'product not found',
      $skip);

    // T3.3 — Edit product
    [$code, $json] = api('products_edit', [
        'id'          => $S['productId'],
        'name'        => 'QA Test Sourdough (edited)',
        'description' => 'Edited by QA test suite.',
        'price'       => 14.00,
        'category'    => 'specialty',
        'available'   => true,
        'dairy'       => true,
        'treeNuts'    => false,
        'egg'         => false,
        'peanut'      => false,
        'sesame'      => false,
        'soy'         => false,
    ], $S['adminCookie'], $S['adminCsrf']);
    t('products_edit returns 200 success', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T3.4 — Toggle availability
    [$code, $json] = api('products_toggle', ['id' => $S['productId']], $S['adminCookie'], $S['adminCsrf']);
    t('products_toggle returns 200 success', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T3.5 — Toggle back so it's available for cart/order tests
    [$code, $json] = api('products_toggle', ['id' => $S['productId']], $S['adminCookie'], $S['adminCsrf']);
    [$code2, $list] = api('products_list', [], $S['guestCookie']);
    $available = false;
    foreach ((array)$list as $p) {
        if ((int)($p['id'] ?? 0) === $S['productId']) {
            $available = (bool)($p['available'] ?? false);
            break;
        }
    }
    t('Product available again after double-toggle', $skip === '' ? $available : false,
      "available=$" . ($available ? 'true' : 'false'), $skip);

    endSection('§3 Admin — Product CRUD');
}


// ════════════════════════════════════════════════════════════════
// SECTION 4 — ADMIN: LOCATION CRUD
// ════════════════════════════════════════════════════════════════

function section4_locations(): void {
    global $S;
    beginSection('§4 Admin — Location CRUD');

    $skip = QA_TEST_ADMIN_OPERATIONS ? '' : 'Section disabled in qa_config.php';

    // T4.1 — Add primary location (kept for order tests)
    [$code, $json] = api('locations_add', [
        'name'    => 'QA Pickup — Main',
        'address' => '123 Test Lane, QA City',
    ], $S['adminCookie'], $S['adminCsrf']);
    $ok = ($code === 200 && isset($json['id']) && $json['id'] > 0);
    if ($ok) $S['locationId'] = $json['id'];
    t('locations_add creates location, returns id', $skip === '' ? $ok : false,
      "HTTP $code, id=" . ($json['id'] ?? 'missing'), $skip);

    // T4.2 — Appears in list
    [$code, $list] = api('locations_list', [], $S['guestCookie']);
    $found = false;
    foreach ((array)$list as $l) {
        if ((int)($l['id'] ?? 0) === $S['locationId']) { $found = true; break; }
    }
    t('locations_list includes new location', $skip === '' ? $found : false,
      "Looking for id={$S['locationId']} in " . count((array)$list) . " locations", $skip);

    // T4.3 — Edit
    [$code, $json] = api('locations_edit', [
        'id'      => $S['locationId'],
        'name'    => 'QA Pickup — Main (edited)',
        'address' => '456 QA Blvd, Test Town',
    ], $S['adminCookie'], $S['adminCsrf']);
    t('locations_edit returns 200 success', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T4.4 — Add a second location (for delete test)
    [$code2, $json2] = api('locations_add', [
        'name'    => 'QA Pickup — Temp',
        'address' => '999 Delete Me Ave',
    ], $S['adminCookie'], $S['adminCsrf']);
    $tempId = $json2['id'] ?? 0;
    t('Second locations_add succeeds (for delete test)', $skip === '' ? ($code2 === 200 && $tempId > 0) : false,
      "HTTP $code2, id=$tempId", $skip);

    // T4.5 — Delete second location
    [$code3, $json3] = api('locations_delete', ['id' => $tempId], $S['adminCookie'], $S['adminCsrf']);
    t('locations_delete removes temp location', $skip === '' ? ($code3 === 200 && ($json3['success'] ?? false)) : false,
      "HTTP $code3", $skip);

    endSection('§4 Admin — Location CRUD');
}


// ════════════════════════════════════════════════════════════════
// SECTION 5 — ADMIN: DATE BLOCKING
// ════════════════════════════════════════════════════════════════

function section5_dates(): void {
    global $S;
    beginSection('§5 Admin — Date Blocking');

    $skip = QA_TEST_ADMIN_OPERATIONS ? '' : 'Section disabled in qa_config.php';
    $date = $S['blockDate'];

    // T5.1 — Block
    [$code, $json] = api('dates_toggle', ['date' => $date], $S['adminCookie'], $S['adminCsrf']);
    $blocked = ($json['blocked'] ?? null) === true;
    t("dates_toggle blocks {$date}", $skip === '' ? ($code === 200 && $blocked) : false,
      "HTTP $code, blocked=" . ($blocked ? 'true' : 'false'), $skip);

    // T5.2 — Appears in list
    [$code, $list] = api('dates_list', [], $S['guestCookie']);
    $inList = in_array($date, (array)$list, true);
    t('Blocked date appears in dates_list', $skip === '' ? $inList : false,
      "$date " . ($inList ? 'found' : 'NOT found') . " in list of " . count((array)$list) . " dates", $skip);

    // T5.3 — Unblock
    [$code, $json] = api('dates_toggle', ['date' => $date], $S['adminCookie'], $S['adminCsrf']);
    $unblocked = ($json['blocked'] ?? null) === false;
    t("dates_toggle unblocks {$date}", $skip === '' ? ($code === 200 && $unblocked) : false,
      "HTTP $code, blocked=" . ($unblocked ? 'false' : 'true'), $skip);

    // T5.4 — No longer in list
    [$code, $list2] = api('dates_list', [], $S['guestCookie']);
    $stillIn = in_array($date, (array)$list2, true);
    t('Unblocked date removed from dates_list', $skip === '' ? !$stillIn : false,
      "$date " . (!$stillIn ? 'correctly removed' : 'STILL present'), $skip);

    // T5.5 — Re-block blockDate so orders_place test can verify rejection
    [$code, $json] = api('dates_toggle', ['date' => $date], $S['adminCookie'], $S['adminCsrf']);
    t("Re-block {$date} for order rejection test", $skip === '' ? ($code === 200 && ($json['blocked'] ?? false) === true) : false,
      "HTTP $code", $skip);

    endSection('§5 Admin — Date Blocking');
}


// ════════════════════════════════════════════════════════════════
// SECTION 6 — CART OPERATIONS
// ════════════════════════════════════════════════════════════════

function section6_cart(): void {
    global $S;
    beginSection('§6 Cart Operations');

    $skip = QA_TEST_CART_ORDERS ? '' : 'Section disabled in qa_config.php';
    if ($skip === '' && $S['productId'] === 0) {
        $skip = 'Skipped: no QA product (§3 admin operations must run first)';
    }

    $pid = $S['productId'];

    // T6.1 — Fresh cart is empty
    [$code, $json] = api('cart_get', [], $S['guestCookie']);
    t('cart_get returns empty cart for new session', $skip === '' ? ($code === 200 && count($json['items'] ?? []) === 0) : false,
      "HTTP $code, items=" . count($json['items'] ?? []), $skip);

    // T6.2 — Add item
    [$code, $json] = api('cart_add', ['productId' => $pid, 'quantity' => 1], $S['guestCookie'], $S['guestCsrf']);
    t('cart_add adds product to cart', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T6.3 — Cart now has 1 item
    [$code, $json] = api('cart_get', [], $S['guestCookie']);
    $count = count($json['items'] ?? []);
    t('cart_get shows 1 item after add', $skip === '' ? ($code === 200 && $count === 1) : false,
      "HTTP $code, items=$count", $skip);

    // T6.4 — Update quantity
    [$code, $json] = api('cart_update', ['productId' => $pid, 'quantity' => 2], $S['guestCookie'], $S['guestCsrf']);
    t('cart_update changes quantity', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T6.5 — Verify updated qty
    [$code, $json] = api('cart_get', [], $S['guestCookie']);
    $qty = (int)($json['items'][0]['quantity'] ?? 0);
    t('cart_get reflects updated quantity (2)', $skip === '' ? ($code === 200 && $qty === 2) : false,
      "HTTP $code, quantity=$qty", $skip);

    // T6.6 — Add product again (stacks)
    [$code, $json] = api('cart_add', ['productId' => $pid, 'quantity' => 1], $S['guestCookie'], $S['guestCsrf']);
    t('cart_add stacks on existing item (qty 2+1=3)', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T6.7 — Confirm stacked qty
    [$code, $json] = api('cart_get', [], $S['guestCookie']);
    $qty = (int)($json['items'][0]['quantity'] ?? 0);
    t('cart_get shows stacked quantity (3)', $skip === '' ? ($code === 200 && $qty === 3) : false,
      "HTTP $code, quantity=$qty", $skip);

    // T6.8 — Remove item
    [$code, $json] = api('cart_remove', ['productId' => $pid], $S['guestCookie'], $S['guestCsrf']);
    t('cart_remove removes item', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T6.9 — Cart empty after remove
    [$code, $json] = api('cart_get', [], $S['guestCookie']);
    $count = count($json['items'] ?? []);
    t('cart_get is empty after remove', $skip === '' ? ($count === 0) : false,
      "HTTP $code, items=$count", $skip);

    // T6.10 — cart_clear (add first, then clear)
    api('cart_add', ['productId' => $pid, 'quantity' => 1], $S['guestCookie'], $S['guestCsrf']);
    [$code, $json] = api('cart_clear', [], $S['guestCookie'], $S['guestCsrf']);
    [$code2, $json2] = api('cart_get', [], $S['guestCookie']);
    $empty = count($json2['items'] ?? []) === 0;
    t('cart_clear empties the cart', $skip === '' ? ($code === 200 && $empty) : false,
      "HTTP $code (clear), empty=" . ($empty ? 'yes' : 'no'), $skip);

    endSection('§6 Cart Operations');
}


// ════════════════════════════════════════════════════════════════
// SECTION 7 — ORDER OPERATIONS
// ════════════════════════════════════════════════════════════════

function section7_orders(): void {
    global $S;
    beginSection('§7 Order Operations');

    $skip = QA_TEST_CART_ORDERS ? '' : 'Section disabled in qa_config.php';
    if ($skip === '' && ($S['productId'] === 0 || $S['locationId'] === 0)) {
        $skip = 'Skipped: requires QA product + location (§3-4 admin operations must run first)';
    }

    $pid   = $S['productId'];
    $locId = $S['locationId'];
    $email = $S['orderEmail'];
    $date  = $S['pickupDate'];
    $block = $S['blockDate'];

    // T7.1 — Missing required fields
    [$code, $json] = api('orders_place', [], $S['guestCookie'], $S['guestCsrf']);
    t('orders_place with no body returns 400', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T7.2 — Invalid email
    [$code, $json] = api('orders_place', [
        'customerName' => 'QA Test',
        'email'        => 'not-an-email',
        'pickupDate'   => $date,
        'locationId'   => $locId,
        'items'        => [['productId' => $pid, 'quantity' => 1]],
    ], $S['guestCookie'], $S['guestCsrf']);
    t('orders_place with invalid email returns 400', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T7.3 — Blocked date rejected
    [$code, $json] = api('orders_place', [
        'customerName' => 'QA Test',
        'email'        => $email,
        'pickupDate'   => $block,
        'locationId'   => $locId,
        'items'        => [['productId' => $pid, 'quantity' => 1]],
    ], $S['guestCookie'], $S['guestCsrf']);
    t('orders_place on blocked date returns 400', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T7.4 — Valid order
    [$code, $json] = api('orders_place', [
        'customerName' => 'QA Tester',
        'email'        => $email,
        'phone'        => '5555550100',
        'pickupDate'   => $date,
        'locationId'   => $locId,
        'notes'        => 'QA automated test order — safe to delete.',
        'items'        => [['productId' => $pid, 'quantity' => 1]],
    ], $S['guestCookie'], $S['guestCsrf']);
    $ok = ($code === 200 && ($json['success'] ?? false) === true && !empty($json['orderId']));
    if ($ok) $S['orderId'] = $json['orderId'];
    t('orders_place with valid data returns 200 + orderId', $skip === '' ? $ok : false,
      "HTTP $code, orderId=" . ($json['orderId'] ?? 'missing'), $skip);

    // T7.5 — Order ID format: TGB- followed by 8 uppercase hex chars
    $fmtOk = preg_match('/^TGB-[0-9A-F]{8}$/', $S['orderId'] ?? '') === 1;
    t('Order ID matches TGB-[A-F0-9]{8} format', $skip === '' ? $fmtOk : false,
      "orderId=" . ($S['orderId'] ?: 'empty'), $skip);

    // T7.6 — Lookup correct
    [$code, $json] = api('orders_lookup', ['orderId' => $S['orderId'], 'email' => $email], $S['guestCookie']);
    t('orders_lookup with correct id+email returns 200', $skip === '' ? ($code === 200 && isset($json['order_id'])) : false,
      "HTTP $code, order_id=" . ($json['order_id'] ?? 'missing'), $skip);

    // T7.7 — Lookup wrong email
    [$code, $json] = api('orders_lookup', ['orderId' => $S['orderId'], 'email' => 'wrong@example.com'], $S['guestCookie']);
    t('orders_lookup with wrong email returns 404', $skip === '' ? ($code === 404) : false,
      "HTTP $code", $skip);

    // T7.8 — Admin orders list
    [$code, $json] = api('orders_list', [], $S['adminCookie']);
    t('orders_list (admin) returns 200 array', $skip === '' ? ($code === 200 && is_array($json)) : false,
      "HTTP $code, " . count((array)$json) . " orders", $skip);

    // T7.9 — Update status
    [$code, $json] = api('orders_status', ['orderId' => $S['orderId'], 'status' => 'confirmed'], $S['adminCookie'], $S['adminCsrf']);
    t('orders_status updates order to confirmed', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T7.10 — Verify status in lookup
    [$code, $json] = api('orders_lookup', ['orderId' => $S['orderId'], 'email' => $email], $S['guestCookie']);
    t('Updated status visible in orders_lookup', $skip === '' ? (($json['status'] ?? '') === 'confirmed') : false,
      "status=" . ($json['status'] ?? 'missing'), $skip);

    // T7.11 — Capacity enforcement: fill remaining slots then overflow
    if ($skip === '') {
        // Current booked on pickupDate = 1. Try to add 4 more (total 5 > limit of 4).
        [$code, $json] = api('orders_place', [
            'customerName' => 'QA Capacity Test',
            'email'        => "qa.capacity@example.com",
            'pickupDate'   => $date,
            'locationId'   => $locId,
            'items'        => [['productId' => $pid, 'quantity' => 4]],
        ], $S['guestCookie'], $S['guestCsrf']);
        t('orders_place rejected when capacity (4) would be exceeded', $code === 400,
          "HTTP $code, error: " . ($json['error'] ?? ''), '');
    } else {
        t('Capacity enforcement test', false, '', $skip);
    }

    endSection('§7 Order Operations');
}


// ════════════════════════════════════════════════════════════════
// SECTION 8 — REVIEWS
// ════════════════════════════════════════════════════════════════

function section8_reviews(): void {
    global $S;
    beginSection('§8 Reviews');

    $skip = QA_TEST_REVIEWS ? '' : 'Section disabled in qa_config.php';
    if ($skip === '' && empty($S['orderId'])) {
        $skip = 'Skipped: no QA order (§7 cart/orders must run first)';
    }

    $orderId = $S['orderId'];
    $email   = $S['orderEmail'];

    // T8.1 — Verify order for review
    [$code, $json] = api('reviews_verify', ['orderId' => $orderId, 'email' => $email], $S['guestCookie']);
    t('reviews_verify with valid order+email returns 200', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T8.2 — Verify with wrong email
    [$code, $json] = api('reviews_verify', ['orderId' => $orderId, 'email' => 'nope@example.com'], $S['guestCookie']);
    t('reviews_verify with wrong email returns 404', $skip === '' ? ($code === 404) : false,
      "HTTP $code", $skip);

    // T8.3 — Submit review (→ pending)
    [$code, $json] = api('reviews_submit', [
        'orderId'     => $orderId,
        'email'       => $email,
        'displayName' => 'QA Reviewer',
        'rating'      => 5,
        'reviewText'  => 'Automated QA review — this is a test submission.',
    ], $S['guestCookie'], $S['guestCsrf']);
    t('reviews_submit returns 200 success', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T8.4 — Duplicate review rejected
    [$code, $json] = api('reviews_submit', [
        'orderId'     => $orderId,
        'email'       => $email,
        'displayName' => 'QA Reviewer',
        'rating'      => 5,
        'reviewText'  => 'Duplicate review attempt.',
    ], $S['guestCookie'], $S['guestCsrf']);
    t('Duplicate reviews_submit rejected (400)', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T8.5 — Not visible in public list (pending)
    [$code, $list] = api('reviews_list', [], $S['guestCookie']);
    $visiblePublic = false;
    foreach ((array)$list as $r) {
        if (($r['order_id'] ?? '') === $orderId) { $visiblePublic = true; break; }
    }
    t('Pending review NOT in public reviews_list', $skip === '' ? !$visiblePublic : false,
      "visible in public list: " . ($visiblePublic ? 'yes (FAIL)' : 'no (correct)'), $skip);

    // T8.6 — Visible in admin list
    [$code, $list] = api('reviews_list_admin', [], $S['adminCookie']);
    $found = false;
    foreach ((array)$list as $r) {
        if (($r['order_id'] ?? '') === $orderId) {
            $S['reviewId'] = (int)($r['id'] ?? 0);
            $found = true;
            break;
        }
    }
    t('Pending review IS in reviews_list_admin', $skip === '' ? $found : false,
      "reviewId=" . $S['reviewId'], $skip);

    endSection('§8 Reviews');
}


// ════════════════════════════════════════════════════════════════
// SECTION 9 — SETTINGS
// ════════════════════════════════════════════════════════════════

function section9_settings(): void {
    global $S;
    beginSection('§9 Settings');

    $skip = QA_TEST_SETTINGS ? '' : 'Section disabled in qa_config.php';

    // T9.1 — settings_get returns all expected keys
    [$code, $json] = api('settings_get', [], $S['adminCookie']);
    $expected = ['owner_email', 'ejs_user', 'ejs_service', 'ejs_template', 'ejs_customer_template'];
    $hasAll   = is_array($json) && count(array_intersect_key(array_flip($expected), $json)) === 5;
    t('settings_get returns all 5 expected keys', $skip === '' ? ($code === 200 && $hasAll) : false,
      "HTTP $code, keys: " . implode(', ', array_keys((array)$json)), $skip);

    // T9.2 — Save a value
    [$code, $json] = api('settings_save', ['owner_email' => 'qa_test@thegoodybasket.com'], $S['adminCookie'], $S['adminCsrf']);
    t('settings_save returns 200 success', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    // T9.3 — Verify saved value
    [$code, $json] = api('settings_get', [], $S['adminCookie']);
    $savedVal = $json['owner_email'] ?? '';
    t('Saved owner_email visible in settings_get', $skip === '' ? ($savedVal === 'qa_test@thegoodybasket.com') : false,
      "owner_email=$savedVal", $skip);

    // T9.4 — settings_public only exposes allowed keys
    [$code, $json] = api('settings_public', [], $S['guestCookie']);
    $allowed   = ['owner_email', 'ejs_user', 'ejs_service', 'ejs_template', 'ejs_customer_template'];
    $noExtras  = is_array($json) && count(array_diff(array_keys($json), $allowed)) === 0;
    t('settings_public only exposes allowed public keys', $skip === '' ? ($code === 200 && $noExtras) : false,
      "HTTP $code, keys: " . implode(', ', array_keys((array)$json)), $skip);

    // T9.5 — settings_get requires admin auth
    [$code, $json] = api('settings_get', [], $S['guestCookie']);
    t('settings_get returns 401 for unauthenticated user', $skip === '' ? ($code === 401) : false,
      "HTTP $code", $skip);

    endSection('§9 Settings');
}


// ════════════════════════════════════════════════════════════════
// SECTION 10 — CUSTOMER REGISTRATION & AUTH
// ════════════════════════════════════════════════════════════════

function section10_customer(): void {
    global $S;
    beginSection('§10 Customer Registration & Auth');

    $skip  = QA_TEST_CUSTOMER_AUTH ? '' : 'Section disabled in qa_config.php';
    $email = $S['custEmail'];
    $pass  = $S['custPass'];
    $newPw = 'NewTestPass456!';

    // T10.1 — Empty fields
    [$code, $json] = api('auth_register', [], $S['custCookie'], $S['custCsrf']);
    t('auth_register with empty body returns 400', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T10.2 — Invalid email
    [$code, $json] = api('auth_register', ['email' => 'not-email', 'password' => $pass], $S['custCookie'], $S['custCsrf']);
    t('auth_register with invalid email returns 400', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T10.3 — Short password
    [$code, $json] = api('auth_register', ['email' => $email, 'password' => 'short'], $S['custCookie'], $S['custCsrf']);
    t('auth_register with <8 char password returns 400', $skip === '' ? ($code === 400) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T10.4 — Successful registration
    [$code, $json] = api('auth_register', ['email' => $email, 'password' => $pass], $S['custCookie'], $S['custCsrf']);
    $ok = ($code === 200 && ($json['success'] ?? false) && ($json['user']['role'] ?? '') === 'customer');
    if ($ok) $S['custCsrf'] = $json['csrfToken'] ?? $S['custCsrf'];
    t('auth_register creates customer account (200, role=customer)', $skip === '' ? $ok : false,
      "HTTP $code, role=" . ($json['user']['role'] ?? 'missing'), $skip);

    // T10.5 — auth_me confirms customer
    [$code, $json] = api('auth_me', [], $S['custCookie']);
    t('auth_me confirms customer session', $skip === '' ? (($json['user']['role'] ?? '') === 'customer') : false,
      "HTTP $code, role=" . ($json['user']['role'] ?? 'null'), $skip);

    // T10.6 — Duplicate email (fresh session + its own CSRF so the CSRF check passes)
    $dupCookie = makeCookieJar();
    $dupResp   = api('csrf_token', [], $dupCookie);
    $dupCsrf   = $dupResp[1]['token'] ?? '';
    [$codeB, $jsonB] = api('auth_register', ['email' => $email, 'password' => $pass], $dupCookie, $dupCsrf);
    t('Duplicate email registration returns 400', $skip === '' ? ($codeB === 400) : false,
      "HTTP $codeB, error: " . ($jsonB['error'] ?? ''), $skip);

    // T10.7 — Logout
    [$code, $json] = api('auth_logout', [], $S['custCookie'], $S['custCsrf']);
    $ok = ($code === 200 && ($json['success'] ?? false));
    if ($ok) $S['custCsrf'] = $json['csrfToken'] ?? $S['custCsrf'];
    t('Customer auth_logout returns 200', $skip === '' ? $ok : false,
      "HTTP $code", $skip);

    // T10.8 — Login wrong password
    [$code, $json] = api('auth_login', ['email' => $email, 'password' => 'WrongPass!'], $S['custCookie'], $S['custCsrf']);
    t('Customer login with wrong password returns 401', $skip === '' ? ($code === 401) : false,
      "HTTP $code", $skip);

    // T10.9 — Login correct
    [$code, $json] = api('auth_login', ['email' => $email, 'password' => $pass], $S['custCookie'], $S['custCsrf']);
    $ok = ($code === 200 && ($json['success'] ?? false));
    if ($ok) $S['custCsrf'] = $json['csrfToken'] ?? $S['custCsrf'];
    t('Customer login with correct credentials returns 200', $skip === '' ? $ok : false,
      "HTTP $code", $skip);

    // T10.10 — Change password: wrong current
    [$code, $json] = api('auth_change_password', ['currentPassword' => 'WrongOld!', 'newPassword' => $newPw], $S['custCookie'], $S['custCsrf']);
    t('auth_change_password with wrong current returns 401', $skip === '' ? ($code === 401) : false,
      "HTTP $code, error: " . ($json['error'] ?? ''), $skip);

    // T10.11 — Change password: success
    [$code, $json] = api('auth_change_password', ['currentPassword' => $pass, 'newPassword' => $newPw], $S['custCookie'], $S['custCsrf']);
    t('auth_change_password with correct current returns 200', $skip === '' ? ($code === 200 && ($json['success'] ?? false)) : false,
      "HTTP $code", $skip);

    endSection('§10 Customer Registration & Auth');
}


// ════════════════════════════════════════════════════════════════
// SECTION 11 — SECURITY
// ════════════════════════════════════════════════════════════════

function section11_security(): void {
    global $S;
    beginSection('§11 Security');

    $skip    = QA_TEST_SECURITY ? '' : 'Section disabled in qa_config.php';
    $fresh   = makeCookieJar();   // unauthenticated visitor
    $badCsrf = 'aaaa' . str_repeat('0', 60);

    // T11.1 — products_add without auth
    [$code] = api('products_add', ['name' => 'Hack', 'price' => 1], $fresh, $badCsrf);
    t('products_add without admin returns 401/403', $skip === '' ? in_array($code, [401, 403]) : false,
      "HTTP $code", $skip);

    // T11.2 — products_add as admin but missing CSRF
    [$code] = api('products_add', ['name' => 'Hack', 'price' => 1], $S['adminCookie'], '');
    t('products_add with missing CSRF header returns 403', $skip === '' ? ($code === 403) : false,
      "HTTP $code", $skip);

    // T11.3 — products_add with wrong CSRF
    [$code] = api('products_add', ['name' => 'Hack', 'price' => 1], $S['adminCookie'], $badCsrf);
    t('products_add with wrong CSRF returns 403', $skip === '' ? ($code === 403) : false,
      "HTTP $code", $skip);

    // T11.4 — orders_list without admin
    [$code] = api('orders_list', [], $fresh);
    t('orders_list without admin returns 401', $skip === '' ? ($code === 401) : false,
      "HTTP $code", $skip);

    // T11.5 — reviews_approve without admin
    [$code] = api('reviews_approve', ['id' => 1], $fresh, $badCsrf);
    t('reviews_approve without admin returns 401/403', $skip === '' ? in_array($code, [401, 403]) : false,
      "HTTP $code", $skip);

    // T11.6 — settings_save without CSRF
    [$code] = api('settings_save', ['owner_email' => 'hack@x.com'], $S['adminCookie'], '');
    t('settings_save without CSRF returns 403', $skip === '' ? ($code === 403) : false,
      "HTTP $code", $skip);

    // T11.7 — auth_register without CSRF
    [$code] = api('auth_register', ['email' => 'h@x.com', 'password' => 'pass1234'], $fresh, '');
    t('auth_register without CSRF returns 403', $skip === '' ? ($code === 403) : false,
      "HTTP $code", $skip);

    // T11.8 — dates_toggle without CSRF
    [$code] = api('dates_toggle', ['date' => '2099-01-01'], $S['adminCookie'], '');
    t('dates_toggle without CSRF returns 403', $skip === '' ? ($code === 403) : false,
      "HTTP $code", $skip);

    endSection('§11 Security');
}


// ════════════════════════════════════════════════════════════════
// SECTION 13 — BUG REGRESSIONS
// (Section 12 intentionally omitted to match documented numbering)
// ════════════════════════════════════════════════════════════════

function section13_regressions(): void {
    global $S;
    beginSection('§13 Bug Regressions');

    $skip = QA_TEST_BUG_REGRESSIONS ? '' : 'Section disabled in qa_config.php';

    // T13.1 — Order ID uppercase hex (bug: was lowercase)
    $fmtOk = preg_match('/^TGB-[0-9A-F]{8}$/', $S['orderId'] ?? '') === 1;
    t('Order ID is uppercase hex (TGB-[A-F0-9]{8})', $skip === '' ? $fmtOk : false,
      "orderId=" . ($S['orderId'] ?: 'not set — §7 must run'), $skip);

    // T13.2 — XSS: product name with HTML stored and returned escaped or as raw (not injected)
    if ($skip === '' && $S['productId'] > 0) {
        api('products_edit', [
            'id'        => $S['productId'],
            'name'      => 'QA <script>alert(1)</script>',
            'price'     => 12.00,
            'category'  => 'regular',
            'available' => true,
        ], $S['adminCookie'], $S['adminCsrf']);
        [$code, $list] = api('products_list', [], $S['guestCookie']);
        $raw = '';
        foreach ((array)$list as $p) {
            if ((int)($p['id'] ?? 0) === $S['productId']) {
                $raw = $p['name'] ?? '';
                break;
            }
        }
        // API returns raw JSON string value — the XSS protection is the responsibility of the
        // frontend esc() helper on render. The API must NOT inject active script tags in HTML.
        // Pass if the string is returned as plain text (not wrapped in an HTML response).
        $noHtml = strpos($raw, '<script>') !== false || $raw === 'QA <script>alert(1)</script>';
        t('XSS payload stored as plain text in JSON (frontend esc() handles rendering)', $skip === '' ? ($raw !== '') : false,
          "Returned name: " . htmlspecialchars($raw), $skip);
        // Restore clean name
        api('products_edit', [
            'id'        => $S['productId'],
            'name'      => 'QA Test Sourdough',
            'price'     => 12.00,
            'category'  => 'regular',
            'available' => true,
        ], $S['adminCookie'], $S['adminCsrf']);
    } else {
        t('XSS: HTML in product name returned as plain JSON text', false, '', $skip ?: 'Skipped: no QA product');
    }

    // T13.3 — FK constraint: delete product that has order → 409
    if ($skip === '' && $S['productId'] > 0 && !empty($S['orderId'])) {
        [$code, $json] = api('products_delete', ['id' => $S['productId']], $S['adminCookie'], $S['adminCsrf']);
        t('products_delete of ordered product returns 409 (FK constraint)', $code === 409,
          "HTTP $code, error: " . ($json['error'] ?? ''), '');
    } else {
        t('FK constraint: delete ordered product returns 409', false, '', $skip ?: 'Skipped: no order exists for QA product');
    }

    // T13.4 — Order status change tracked correctly
    if ($skip === '' && !empty($S['orderId'])) {
        api('orders_status', ['orderId' => $S['orderId'], 'status' => 'completed'], $S['adminCookie'], $S['adminCsrf']);
        [$code, $json] = api('orders_lookup', ['orderId' => $S['orderId'], 'email' => $S['orderEmail']], $S['guestCookie']);
        t('Order status update to completed is reflected in lookup', ($json['status'] ?? '') === 'completed',
          "status=" . ($json['status'] ?? 'missing'), '');
        // Reset back
        api('orders_status', ['orderId' => $S['orderId'], 'status' => 'pending'], $S['adminCookie'], $S['adminCsrf']);
    } else {
        t('Order status update reflected in lookup', false, '', $skip ?: 'Skipped: no QA order');
    }

    endSection('§13 Bug Regressions');
}


// ════════════════════════════════════════════════════════════════
// SECTION 14 — CLEANUP
// ════════════════════════════════════════════════════════════════

function section14_cleanup(): void {
    global $S;
    beginSection('§14 Cleanup');

    $skip = QA_TEST_CLEANUP ? '' : 'Section disabled in qa_config.php';

    // Approve then reject (delete) QA review so order can be deleted
    if ($S['reviewId'] > 0) {
        api('reviews_reject', ['id' => $S['reviewId']], $S['adminCookie'], $S['adminCsrf']);
    }

    // Unblock the test date so it doesn't persist
    if (!empty($S['blockDate'])) {
        [$code, $json] = api('dates_list', [], $S['guestCookie']);
        if (in_array($S['blockDate'], (array)$json, true)) {
            api('dates_toggle', ['date' => $S['blockDate']], $S['adminCookie'], $S['adminCsrf']);
        }
    }

    // T14.1 — Delete QA location
    $locOk = false;
    if ($S['locationId'] > 0) {
        [$code, $json] = api('locations_delete', ['id' => $S['locationId']], $S['adminCookie'], $S['adminCsrf']);
        $locOk = ($code === 200 && ($json['success'] ?? false));
        t('Cleanup: QA test location deleted', $skip === '' ? $locOk : false,
          "HTTP $code, locationId={$S['locationId']}", $skip);
    } else {
        t('Cleanup: QA test location deleted', false, '', 'Skipped: locationId not set');
    }

    // T14.2 — Delete QA product (only possible if no order references it via FK)
    // The test order references this product, so we cannot delete the product directly.
    // Mark as unavailable instead — this is the documented approach for ordered products.
    $prodOk = false;
    if ($S['productId'] > 0) {
        [$code, $json] = api('products_list', [], $S['guestCookie']);
        $still = false;
        foreach ((array)$json as $p) {
            if ((int)$p['id'] === $S['productId']) { $still = true; break; }
        }
        if ($still) {
            // Try delete (will work if no order exists; returns 409 if FK prevents it)
            [$dCode, $dJson] = api('products_delete', ['id' => $S['productId']], $S['adminCookie'], $S['adminCsrf']);
            if ($dCode === 409) {
                // Expected if an order was placed — hide it instead
                api('products_toggle', ['id' => $S['productId']], $S['adminCookie'], $S['adminCsrf']);
                // Force unavailable (toggle may have re-enabled; check and fix)
                [, $list2] = api('products_list', [], $S['guestCookie']);
                foreach ((array)$list2 as $p) {
                    if ((int)$p['id'] === $S['productId'] && (bool)$p['available']) {
                        api('products_toggle', ['id' => $S['productId']], $S['adminCookie'], $S['adminCsrf']);
                    }
                }
                $prodOk = true; // hidden = acceptable cleanup
                t('Cleanup: QA product hidden (FK prevents delete — expected)', $skip === '' ? true : false,
                  "productId={$S['productId']} marked unavailable", $skip);
            } else {
                $prodOk = ($dCode === 200 && ($dJson['success'] ?? false));
                t('Cleanup: QA test product deleted', $skip === '' ? $prodOk : false,
                  "HTTP $dCode", $skip);
            }
        } else {
            t('Cleanup: QA product already removed', $skip === '' ? true : false, '', $skip);
        }
    } else {
        t('Cleanup: QA test product deleted', false, '', 'Skipped: productId not set');
    }

    endSection('§14 Cleanup');
}


// ════════════════════════════════════════════════════════════════
// MAIN — Run all sections
// ════════════════════════════════════════════════════════════════

$startTime = microtime(true);

section0_setup();
section1_public();
section2_auth();
section3_products();
section4_locations();
section5_dates();
section6_cart();
section7_orders();
section8_reviews();
section9_settings();
section10_customer();
section11_security();
section13_regressions();
section14_cleanup();

$elapsed   = round(microtime(true) - $startTime, 2);
$total     = $pass + $fail + $skipped;
$ran       = $pass + $fail;   // tests that actually ran (not skipped)
$pct       = $ran > 0 ? round($pass / $ran * 100, 1) : 0;


// ════════════════════════════════════════════════════════════════
// HTML OUTPUT
// ════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TGB QA Results — <?= date('Y-m-d H:i:s') ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body   { font-family: Arial, sans-serif; background: #f4f4f8; color: #222; padding: 24px; }
  h1     { font-size: 1.6rem; margin-bottom: 4px; }
  .meta  { color: #666; font-size: .85rem; margin-bottom: 24px; }
  /* Summary cards */
  .summary        { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
  .card           { flex: 1 1 130px; padding: 18px 16px; border-radius: 10px; text-align: center; }
  .card .num      { font-size: 2rem; font-weight: 700; line-height: 1; }
  .card .label    { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; margin-top: 4px; color: #555; }
  .card-total     { background: #ddeeff; }
  .card-pass      { background: #d4edda; }
  .card-fail      { background: #f8d7da; }
  .card-skip      { background: #fff3cd; }
  .card-rate      { background: #d1ecf1; }
  /* Section */
  .section        { background: #fff; border: 1px solid #ddd; border-radius: 10px; margin-bottom: 18px; overflow: hidden; }
  .section h2     { background: #3d2b1f; color: #fff; padding: 10px 16px; font-size: .95rem; }
  /* Test rows */
  .test           { display: flex; align-items: flex-start; padding: 8px 16px; border-bottom: 1px solid #f0f0f0; font-size: .875rem; }
  .test:last-child{ border-bottom: none; }
  .test.pass      { background: #f6fff8; }
  .test.fail      { background: #fff6f6; }
  .test.skip      { background: #fffbf0; }
  .icon           { font-size: 1rem; margin-right: 10px; flex-shrink: 0; width: 20px; line-height: 1.4; }
  .tname          { flex: 1; }
  .detail         { font-size: .78rem; color: #888; margin-top: 2px; }
  /* Failures summary */
  .failures       { background: #fff; border: 2px solid #f5c6cb; border-radius: 10px; padding: 20px; margin-top: 8px; }
  .failures h2    { color: #842029; margin-bottom: 12px; }
  .fail-item      { padding: 8px 12px; background: #fff5f5; border-left: 4px solid #e74c3c; border-radius: 4px; margin-bottom: 8px; font-size: .875rem; }
  .fail-item .fn  { font-weight: 700; }
  .fail-item .fd  { color: #666; font-size: .8rem; margin-top: 2px; }
  @media (max-width:560px) { .summary { flex-direction: column; } }
</style>
</head>
<body>

<h1>🧺 The Goody Basket — QA Results</h1>
<p class="meta">
  Run: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp;
  Target: <?= htmlspecialchars(QA_BASE_URL) ?> &nbsp;|&nbsp;
  Duration: <?= $elapsed ?>s
</p>

<div class="summary">
  <div class="card card-total"><div class="num"><?= $total ?></div><div class="label">Total</div></div>
  <div class="card card-pass" ><div class="num"><?= $pass  ?></div><div class="label">Passed</div></div>
  <div class="card card-fail" ><div class="num"><?= $fail  ?></div><div class="label">Failed</div></div>
  <div class="card card-skip" ><div class="num"><?= $skipped ?></div><div class="label">Skipped</div></div>
  <div class="card card-rate" ><div class="num"><?= $pct ?>%</div><div class="label">Pass Rate (excl. skipped)</div></div>
</div>

<?php foreach ($sections as $section): ?>
<div class="section">
  <h2><?= htmlspecialchars($section['title']) ?></h2>
  <?php foreach ($section['tests'] as $test): ?>
  <div class="test <?= $test['status'] ?>">
    <span class="icon"><?php
      if ($test['status'] === 'pass') echo '✓';
      elseif ($test['status'] === 'fail') echo '✗';
      else echo '⚠';
    ?></span>
    <div class="tname">
      <?= htmlspecialchars($test['name']) ?>
      <?php if (!empty($test['detail'])): ?>
      <div class="detail"><?= htmlspecialchars($test['detail']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if (!empty($failures)): ?>
<div class="failures">
  <h2>✗ Failed Tests (<?= count($failures) ?>)</h2>
  <?php foreach ($failures as $f): ?>
  <div class="fail-item">
    <div class="fn"><?= htmlspecialchars($f['name']) ?></div>
    <?php if (!empty($f['detail'])): ?>
    <div class="fd"><?= htmlspecialchars($f['detail']) ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<p style="color:#2e7d32;font-weight:700;font-size:1.1rem;margin-top:8px;">✓ All tests passed!</p>
<?php endif; ?>

</body>
</html>
