<?php
// ================================================================
// THE GOODY BASKET — API Backend
// All frontend fetch() calls route through this single file.
// ================================================================

session_start();
header('Content-Type: application/json');

// Only allow requests from your own domain in production
// header('Access-Control-Allow-Origin: https://yourdomain.com');

require_once 'config.php';


// ── Helpers ──────────────────────────────────────────────────────────

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            respond(['error' => 'Database connection failed.'], 500);
        }
    }
    return $pdo;
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function respondError($msg, $code = 400) {
    respond(['error' => $msg], $code);
}

function body() {
    static $parsed = null;
    if ($parsed === null) {
        $raw    = file_get_contents('php://input');
        $parsed = json_decode($raw, true) ?: [];
    }
    return $parsed;
}

function isAdmin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) respondError('Unauthorized.', 401);
}

function isLoggedIn() {
    return isset($_SESSION['user']);
}


// ── Router ───────────────────────────────────────────────────────────

$action = $_GET['action'] ?? (body()['action'] ?? '');

switch ($action) {

    // Auth
    case 'auth_register':        authRegister();       break;
    case 'auth_login':           authLogin();          break;
    case 'auth_logout':          authLogout();         break;
    case 'auth_me':              authMe();             break;
    case 'auth_change_password': authChangePassword(); break;

    // Products
    case 'products_list':   productsList();          break;
    case 'products_add':    requireAdmin(); productsAdd();    break;
    case 'products_edit':   requireAdmin(); productsEdit();   break;
    case 'products_delete': requireAdmin(); productsDelete(); break;
    case 'products_toggle': requireAdmin(); productsToggle(); break;

    // Orders
    case 'orders_place':  ordersPlace();        break;
    case 'orders_list':   requireAdmin(); ordersList();  break;
    case 'orders_lookup': ordersLookup();       break;
    case 'orders_status': requireAdmin(); ordersUpdateStatus(); break;
    case 'orders_mine':   ordersMine();         break;

    // Cart
    case 'cart_get':    cartGet();    break;
    case 'cart_add':    cartAdd();    break;
    case 'cart_update': cartUpdate(); break;
    case 'cart_remove': cartRemove(); break;
    case 'cart_clear':  cartClear();  break;

    // Locations
    case 'locations_list':   locationsList();            break;
    case 'locations_add':    requireAdmin(); locationsAdd();    break;
    case 'locations_edit':   requireAdmin(); locationsEdit();   break;
    case 'locations_delete': requireAdmin(); locationsDelete(); break;

    // Blocked dates
    case 'dates_list':   datesList();   break;
    case 'dates_toggle': requireAdmin(); datesToggle(); break;

    // Reviews
    case 'reviews_list':       reviewsList();       break;
    case 'reviews_list_admin': requireAdmin(); reviewsListAdmin(); break;
    case 'reviews_verify':     reviewsVerify();     break;
    case 'reviews_submit':     reviewsSubmit();     break;
    case 'reviews_approve':    requireAdmin(); reviewsApprove(); break;
    case 'reviews_reject':     requireAdmin(); reviewsReject();  break;

    // Settings
    case 'settings_get':    requireAdmin(); settingsGet();    break;
    case 'settings_save':   requireAdmin(); settingsSave();   break;
    case 'settings_public': settingsPublic(); break;  // Non-admin: emailjs config only

    default:
        respondError('Unknown action.', 404);
}


// ════════════════════════════════════════════════════════════════════
// AUTH
// ════════════════════════════════════════════════════════════════════

function authRegister() {
    $b        = body();
    $email    = trim($b['email']    ?? '');
    $password = trim($b['password'] ?? '');

    if (!$email || !$password)
        respondError('Email and password are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respondError('Please enter a valid email address.');
    if (strlen($password) < 8)
        respondError('Password must be at least 8 characters.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM accounts WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) respondError('An account with this email already exists.');

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO accounts (email, password_hash, role) VALUES (?, ?, "customer")')
       ->execute([$email, $hash]);
    $id = (int)$db->lastInsertId();

    $_SESSION['user'] = ['id' => $id, 'email' => $email, 'role' => 'customer'];
    respond(['success' => true, 'user' => $_SESSION['user']]);
}

function authLogin() {
    $b        = body();
    $email    = trim($b['email']    ?? '');
    $password = trim($b['password'] ?? '');

    if (!$email || !$password) respondError('Email and password are required.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, email, password_hash, role FROM accounts WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        respondError('Incorrect email or password.', 401);

    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    respond(['success' => true, 'user' => $_SESSION['user']]);
}

function authLogout() {
    session_unset();
    session_destroy();
    respond(['success' => true]);
}

function authMe() {
    respond(['user' => $_SESSION['user'] ?? null]);
}

function authChangePassword() {
    if (!isLoggedIn()) respondError('Not logged in.', 401);

    $b       = body();
    $current = $b['currentPassword'] ?? '';
    $newPw   = $b['newPassword']     ?? '';

    if (!$current || !$newPw) respondError('Both current and new password are required.');
    if (strlen($newPw) < 8)   respondError('New password must be at least 8 characters.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT password_hash FROM accounts WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $row  = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash']))
        respondError('Current password is incorrect.', 401);

    $hash = password_hash($newPw, PASSWORD_BCRYPT);
    $db->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')
       ->execute([$hash, $_SESSION['user']['id']]);

    respond(['success' => true]);
}


// ════════════════════════════════════════════════════════════════════
// PRODUCTS
// ════════════════════════════════════════════════════════════════════

function productsList() {
    $db   = getDB();
    $rows = $db->query(
        'SELECT id, Name AS name, Description AS description, Cost AS price,
                category, available,
                Dairy AS dairy, TreeNuts AS treeNuts, Egg AS egg,
                Peanut AS peanut, Sesame AS sesame, Soy AS soy
         FROM products
         ORDER BY category ASC, id ASC'
    )->fetchAll();

    foreach ($rows as &$p) {
        $p['id']        = (int)$p['id'];
        $p['price']     = (float)$p['price'];
        $p['available'] = (bool)$p['available'];
        $p['dairy']     = (bool)$p['dairy'];
        $p['treeNuts']  = (bool)$p['treeNuts'];
        $p['egg']       = (bool)$p['egg'];
        $p['peanut']    = (bool)$p['peanut'];
        $p['sesame']    = (bool)$p['sesame'];
        $p['soy']       = (bool)$p['soy'];
    }
    respond($rows);
}

function productsAdd() {
    $b        = body();
    $name     = trim($b['name']        ?? '');
    $desc     = trim($b['description'] ?? '');
    $price    = (float)($b['price']    ?? 0);
    $category = in_array($b['category'] ?? '', ['regular','specialty'])
                    ? $b['category'] : 'regular';
    $available = !empty($b['available']);

    if (!$name || $price <= 0) respondError('Name and price are required.');

    $db = getDB();
    $db->prepare(
        'INSERT INTO products
            (Name, Description, Cost, category, available,
             Dairy, TreeNuts, Egg, Peanut, Sesame, Soy)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $name, $desc, $price, $category, $available,
        !empty($b['dairy']),  !empty($b['treeNuts']),
        !empty($b['egg']),    !empty($b['peanut']),
        !empty($b['sesame']), !empty($b['soy']),
    ]);
    respond(['success' => true, 'id' => (int)$db->lastInsertId()]);
}

function productsEdit() {
    $b        = body();
    $id       = (int)($b['id']         ?? 0);
    $name     = trim($b['name']        ?? '');
    $desc     = trim($b['description'] ?? '');
    $price    = (float)($b['price']    ?? 0);
    $category = in_array($b['category'] ?? '', ['regular','specialty'])
                    ? $b['category'] : 'regular';
    $available = !empty($b['available']);

    if (!$id || !$name || $price <= 0) respondError('ID, name, and price are required.');

    getDB()->prepare(
        'UPDATE products
         SET Name=?, Description=?, Cost=?, category=?, available=?,
             Dairy=?, TreeNuts=?, Egg=?, Peanut=?, Sesame=?, Soy=?
         WHERE id=?'
    )->execute([
        $name, $desc, $price, $category, $available,
        !empty($b['dairy']),  !empty($b['treeNuts']),
        !empty($b['egg']),    !empty($b['peanut']),
        !empty($b['sesame']), !empty($b['soy']),
        $id,
    ]);
    respond(['success' => true]);
}

function productsDelete() {
    $id = (int)(body()['id'] ?? 0);
    if (!$id) respondError('Product ID required.');
    getDB()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    respond(['success' => true]);
}

function productsToggle() {
    $id = (int)(body()['id'] ?? 0);
    if (!$id) respondError('Product ID required.');
    getDB()->prepare('UPDATE products SET available = NOT available WHERE id = ?')
           ->execute([$id]);
    respond(['success' => true]);
}


// ════════════════════════════════════════════════════════════════════
// ORDERS
// ════════════════════════════════════════════════════════════════════

function ordersPlace() {
    $b          = body();
    $name       = trim($b['customerName'] ?? '');
    $email      = trim($b['email']        ?? '');
    $phone      = trim($b['phone']        ?? '');
    $pickupDate = $b['pickupDate']        ?? '';
    $locationId = (int)($b['locationId'] ?? 0);
    $notes      = trim($b['notes']        ?? '');
    $items      = $b['items']             ?? [];

    if (!$name || !$email || !$pickupDate || !$locationId || empty($items))
        respondError('Please fill in all required fields.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respondError('Please enter a valid email address.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickupDate))
        respondError('Invalid pickup date format.');

    $db = getDB();

    // Verify location
    $stmt = $db->prepare('SELECT id, name, address FROM locations WHERE id = ? AND active = 1');
    $stmt->execute([$locationId]);
    $location = $stmt->fetch();
    if (!$location) respondError('Invalid pickup location.');

    // Check blocked date
    $stmt = $db->prepare('SELECT id FROM blocked_dates WHERE date = ?');
    $stmt->execute([$pickupDate]);
    if ($stmt->fetch()) respondError('This date is unavailable. Please choose a different date.');

    // Check capacity (max 4 loaves per day across all orders)
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(oi.quantity),0) AS booked
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.pickup_date = ? AND o.status != 'cancelled'"
    );
    $stmt->execute([$pickupDate]);
    $booked = (int)$stmt->fetchColumn();

    // Validate items and calculate total
    $total          = 0;
    $validatedItems = [];
    foreach ($items as $item) {
        $productId = (int)($item['productId'] ?? 0);
        $quantity  = (int)($item['quantity']  ?? 0);
        if (!$productId || $quantity <= 0) continue;

        $stmt = $db->prepare('SELECT id, Name, Cost FROM products WHERE id = ? AND available = 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) respondError('Product #' . $productId . ' is not available.');

        $lineTotal        = round((float)$product['Cost'] * $quantity, 2);
        $total           += $lineTotal;
        $validatedItems[] = [
            'productId'   => $productId,
            'productName' => $product['Name'],
            'quantity'    => $quantity,
            'price'       => (float)$product['Cost'],
            'lineTotal'   => $lineTotal,
        ];
    }
    if (empty($validatedItems)) respondError('No valid items in order.');

    $requestedLoaves = array_sum(array_column($validatedItems, 'quantity'));
    $maxPerDay       = 4;
    if ($booked + $requestedLoaves > $maxPerDay)
        respondError('Only ' . ($maxPerDay - $booked) . ' loaf(ves) remaining on this date.');

    // Generate order ID
    $orderId   = 'TGB-' . strtoupper(substr(uniqid('', true), -8));
    $accountId = isLoggedIn() ? $_SESSION['user']['id'] : null;

    // Insert order
    $db->prepare(
        'INSERT INTO orders
            (order_id, account_id, email, customer_name, phone,
             total_cost, pickup_date, location_id, location_name,
             location_address, notes, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $orderId, $accountId, $email, $name, $phone,
        $total, $pickupDate, $locationId,
        $location['name'], $location['address'],
        $notes, 'pending',
    ]);
    $dbOrderId = (int)$db->lastInsertId();

    // Insert order items
    $itemStmt = $db->prepare(
        'INSERT INTO order_items (order_id, product_id, quantity, item_cost)
         VALUES (?,?,?,?)'
    );
    foreach ($validatedItems as $item) {
        $itemStmt->execute([$dbOrderId, $item['productId'], $item['quantity'], $item['lineTotal']]);
    }

    // Clear DB cart if logged in
    if ($accountId) {
        $stmt = $db->prepare('SELECT id FROM carts WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $cart = $stmt->fetch();
        if ($cart) {
            $db->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cart['id']]);
        }
    }

    respond(['success' => true, 'orderId' => $orderId, 'total' => $total]);
}

function ordersList() {
    $db    = getDB();
    $rows  = $db->query(
        'SELECT o.*
         FROM orders o
         ORDER BY o.created_at DESC'
    )->fetchAll();

    $itemStmt = $db->prepare(
        'SELECT oi.product_id, oi.quantity, oi.item_cost,
                p.Name AS productName
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?'
    );
    foreach ($rows as &$o) {
        $itemStmt->execute([$o['id']]);
        $o['items']      = $itemStmt->fetchAll();
        $o['total_cost'] = (float)$o['total_cost'];
        foreach ($o['items'] as &$i) {
            $i['quantity']  = (int)$i['quantity'];
            $i['item_cost'] = (float)$i['item_cost'];
        }
    }
    respond($rows);
}

function ordersLookup() {
    $b       = body();
    $orderId = strtoupper(trim($b['orderId'] ?? ''));
    $email   = strtolower(trim($b['email']   ?? ''));

    if (!$orderId || !$email) respondError('Order ID and email are required.');

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM orders WHERE order_id = ? AND LOWER(email) = ?'
    );
    $stmt->execute([$orderId, $email]);
    $order = $stmt->fetch();
    if (!$order) respondError('Order not found. Please check your Order ID and email.', 404);

    $stmt = $db->prepare(
        'SELECT oi.quantity, oi.item_cost, p.Name AS productName
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?'
    );
    $stmt->execute([$order['id']]);
    $order['items']      = $stmt->fetchAll();
    $order['total_cost'] = (float)$order['total_cost'];
    respond($order);
}

function ordersUpdateStatus() {
    $b       = body();
    $orderId = trim($b['orderId'] ?? '');
    $status  = trim($b['status']  ?? '');

    $valid = ['pending','confirmed','completed','cancelled'];
    if (!$orderId || !in_array($status, $valid))
        respondError('Valid order ID and status are required.');

    getDB()->prepare('UPDATE orders SET status = ? WHERE order_id = ?')
           ->execute([$status, $orderId]);
    respond(['success' => true]);
}

function ordersMine() {
    if (!isLoggedIn()) respondError('Not logged in.', 401);

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM orders WHERE account_id = ? ORDER BY created_at DESC');
    $stmt->execute([$_SESSION['user']['id']]);
    $rows = $stmt->fetchAll();

    $itemStmt = $db->prepare(
        'SELECT oi.quantity, oi.item_cost, p.Name AS productName
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?'
    );
    foreach ($rows as &$o) {
        $itemStmt->execute([$o['id']]);
        $o['items']      = $itemStmt->fetchAll();
        $o['total_cost'] = (float)$o['total_cost'];
    }
    respond($rows);
}


// ════════════════════════════════════════════════════════════════════
// CART
// ════════════════════════════════════════════════════════════════════

function getOrCreateCartId($db) {
    if (isLoggedIn()) {
        $stmt = $db->prepare('SELECT id FROM carts WHERE account_id = ?');
        $stmt->execute([$_SESSION['user']['id']]);
        $cart = $stmt->fetch();
        if (!$cart) {
            $db->prepare('INSERT INTO carts (account_id) VALUES (?)')->execute([$_SESSION['user']['id']]);
            return (int)$db->lastInsertId();
        }
        return (int)$cart['id'];
    }

    // Guest — use PHP session
    if (empty($_SESSION['guest_cart_sid'])) {
        $_SESSION['guest_cart_sid'] = bin2hex(random_bytes(16));
    }
    $sid  = $_SESSION['guest_cart_sid'];
    $stmt = $db->prepare('SELECT id FROM carts WHERE session_id = ?');
    $stmt->execute([$sid]);
    $cart = $stmt->fetch();
    if (!$cart) {
        $db->prepare('INSERT INTO carts (session_id) VALUES (?)')->execute([$sid]);
        return (int)$db->lastInsertId();
    }
    return (int)$cart['id'];
}

function cartGet() {
    $db     = getDB();
    $cartId = getOrCreateCartId($db);
    $stmt   = $db->prepare(
        'SELECT ci.product_id AS id, ci.quantity,
                p.Name AS name, p.Cost AS price, p.category, p.available
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         WHERE ci.cart_id = ?'
    );
    $stmt->execute([$cartId]);
    $items = $stmt->fetchAll();
    foreach ($items as &$i) {
        $i['id']        = (int)$i['id'];
        $i['price']     = (float)$i['price'];
        $i['quantity']  = (int)$i['quantity'];
        $i['available'] = (bool)$i['available'];
    }
    respond(['items' => $items]);
}

function cartAdd() {
    $b         = body();
    $productId = (int)($b['productId'] ?? 0);
    $qty       = (int)($b['quantity']  ?? 1);
    if (!$productId || $qty <= 0) respondError('Invalid product or quantity.');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM products WHERE id = ? AND available = 1');
    $stmt->execute([$productId]);
    if (!$stmt->fetch()) respondError('Product not available.');

    $cartId = getOrCreateCartId($db);

    $stmt = $db->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ?');
    $stmt->execute([$cartId, $productId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $db->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?')
           ->execute([$existing['quantity'] + $qty, $existing['id']]);
    } else {
        $db->prepare('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)')
           ->execute([$cartId, $productId, $qty]);
    }
    respond(['success' => true]);
}

function cartUpdate() {
    $b         = body();
    $productId = (int)($b['productId'] ?? 0);
    $qty       = (int)($b['quantity']  ?? 0);
    if (!$productId) respondError('Invalid product.');

    $db     = getDB();
    $cartId = getOrCreateCartId($db);

    if ($qty <= 0) {
        $db->prepare('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?')
           ->execute([$cartId, $productId]);
    } else {
        $db->prepare('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ?')
           ->execute([$qty, $cartId, $productId]);
    }
    respond(['success' => true]);
}

function cartRemove() {
    $productId = (int)(body()['productId'] ?? 0);
    if (!$productId) respondError('Invalid product.');
    $db     = getDB();
    $cartId = getOrCreateCartId($db);
    $db->prepare('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?')
       ->execute([$cartId, $productId]);
    respond(['success' => true]);
}

function cartClear() {
    $db     = getDB();
    $cartId = getOrCreateCartId($db);
    $db->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);
    respond(['success' => true]);
}


// ════════════════════════════════════════════════════════════════════
// LOCATIONS
// ════════════════════════════════════════════════════════════════════

function locationsList() {
    $rows = getDB()->query(
        'SELECT id, name, address, active FROM locations ORDER BY id ASC'
    )->fetchAll();
    foreach ($rows as &$l) {
        $l['id']     = (int)$l['id'];
        $l['active'] = (bool)$l['active'];
    }
    respond($rows);
}

function locationsAdd() {
    $b       = body();
    $name    = trim($b['name']    ?? '');
    $address = trim($b['address'] ?? '');
    if (!$name || !$address) respondError('Name and address are required.');
    $db = getDB();
    $db->prepare('INSERT INTO locations (name, address, active) VALUES (?,?,1)')
       ->execute([$name, $address]);
    respond(['success' => true, 'id' => (int)$db->lastInsertId()]);
}

function locationsEdit() {
    $b       = body();
    $id      = (int)($b['id']      ?? 0);
    $name    = trim($b['name']    ?? '');
    $address = trim($b['address'] ?? '');
    if (!$id || !$name || !$address) respondError('ID, name, and address are required.');
    getDB()->prepare('UPDATE locations SET name=?, address=? WHERE id=?')
           ->execute([$name, $address, $id]);
    respond(['success' => true]);
}

function locationsDelete() {
    $id = (int)(body()['id'] ?? 0);
    if (!$id) respondError('Location ID required.');
    getDB()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
    respond(['success' => true]);
}


// ════════════════════════════════════════════════════════════════════
// BLOCKED DATES
// ════════════════════════════════════════════════════════════════════

function datesList() {
    $rows = getDB()->query('SELECT date FROM blocked_dates')->fetchAll();
    respond(array_column($rows, 'date'));
}

function datesToggle() {
    $date = body()['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        respondError('A valid date is required.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM blocked_dates WHERE date = ?');
    $stmt->execute([$date]);

    if ($stmt->fetch()) {
        $db->prepare('DELETE FROM blocked_dates WHERE date = ?')->execute([$date]);
        respond(['blocked' => false]);
    } else {
        $db->prepare('INSERT INTO blocked_dates (date) VALUES (?)')->execute([$date]);
        respond(['blocked' => true]);
    }
}


// ════════════════════════════════════════════════════════════════════
// REVIEWS
// ════════════════════════════════════════════════════════════════════

function reviewsList() {
    $rows = getDB()->query(
        'SELECT r.id, r.display_name, r.rating, r.review_text, r.created_at,
                o.order_id, o.pickup_date, o.location_name
         FROM reviews r
         JOIN orders o ON o.order_id = r.order_id
         WHERE r.status = "approved"
         ORDER BY r.created_at DESC'
    )->fetchAll();
    foreach ($rows as &$r) $r['rating'] = (int)$r['rating'];
    respond($rows);
}

function reviewsListAdmin() {
    $rows = getDB()->query(
        'SELECT r.*, o.order_id AS order_ref, o.pickup_date, o.location_name
         FROM reviews r
         JOIN orders o ON o.order_id = r.order_id
         ORDER BY r.created_at DESC'
    )->fetchAll();
    foreach ($rows as &$r) $r['rating'] = (int)$r['rating'];
    respond($rows);
}

function reviewsVerify() {
    $b       = body();
    $orderId = strtoupper(trim($b['orderId'] ?? ''));
    $email   = strtolower(trim($b['email']   ?? ''));
    if (!$orderId || !$email) respondError('Order ID and email are required.');

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT order_id, customer_name, pickup_date, location_name
         FROM orders WHERE order_id = ? AND LOWER(email) = ?'
    );
    $stmt->execute([$orderId, $email]);
    $order = $stmt->fetch();
    if (!$order) respondError('Order not found. Please check your Order ID and email.', 404);

    // Duplicate check
    $stmt = $db->prepare('SELECT id FROM reviews WHERE order_id = ?');
    $stmt->execute([$orderId]);
    if ($stmt->fetch()) respondError('A review has already been submitted for this order.');

    respond(['success' => true, 'order' => $order]);
}

function reviewsSubmit() {
    $b           = body();
    $orderId     = strtoupper(trim($b['orderId']     ?? ''));
    $email       = strtolower(trim($b['email']       ?? ''));
    $displayName = trim($b['displayName']            ?? '');
    $rating      = (int)($b['rating']               ?? 0);
    $reviewText  = trim($b['reviewText']             ?? '');

    if (!$orderId || !$email || !$displayName || !$rating || !$reviewText)
        respondError('All fields are required.');
    if ($rating < 1 || $rating > 5)
        respondError('Rating must be between 1 and 5.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT order_id FROM orders WHERE order_id = ? AND LOWER(email) = ?');
    $stmt->execute([$orderId, $email]);
    if (!$stmt->fetch()) respondError('Could not verify your order.', 404);

    $stmt = $db->prepare('SELECT id FROM reviews WHERE order_id = ?');
    $stmt->execute([$orderId]);
    if ($stmt->fetch()) respondError('A review has already been submitted for this order.');

    $db->prepare(
        'INSERT INTO reviews (order_id, display_name, rating, review_text, status)
         VALUES (?,?,?,?,"pending")'
    )->execute([$orderId, $displayName, $rating, $reviewText]);
    respond(['success' => true]);
}

function reviewsApprove() {
    $id = (int)(body()['id'] ?? 0);
    if (!$id) respondError('Review ID required.');
    getDB()->prepare('UPDATE reviews SET status = "approved" WHERE id = ?')->execute([$id]);
    respond(['success' => true]);
}

function reviewsReject() {
    $id = (int)(body()['id'] ?? 0);
    if (!$id) respondError('Review ID required.');
    getDB()->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
    respond(['success' => true]);
}


// ════════════════════════════════════════════════════════════════════
// SETTINGS
// ════════════════════════════════════════════════════════════════════

function settingsGet() {
    $rows     = getDB()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    respond($settings);
}

function settingsPublic() {
    // Exposes only EmailJS config — not sensitive admin data
    $allowed = ['owner_email','ejs_user','ejs_service','ejs_template','ejs_customer_template'];
    $rows = getDB()->query(
        "SELECT setting_key, setting_value FROM settings
         WHERE setting_key IN ('owner_email','ejs_user','ejs_service','ejs_template','ejs_customer_template')"
    )->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    respond($settings);
}

function settingsSave() {
    $b       = body();
    $allowed = ['owner_email','ejs_user','ejs_service','ejs_template','ejs_customer_template'];
    $db      = getDB();
    $stmt    = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($allowed as $key) {
        if (array_key_exists($key, $b)) {
            $stmt->execute([$key, $b[$key]]);
        }
    }
    respond(['success' => true]);
}
