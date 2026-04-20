<?php
// ================================================================
// THE GOODY BASKET — QA Test Configuration (PRODUCTION)
// ⚠️  DO NOT commit this file to version control.
// ⚠️  This file is for production testing at thegoodybasket.com only.
//     For local XAMPP testing, use qa_config.php at the project root.
// ================================================================

// ── Server ───────────────────────────────────────────────────────
define('QA_BASE_URL', 'https://thegoodybasket.com');
define('QA_API_URL',  QA_BASE_URL . '/php/api.php');

// ── Admin Credentials ────────────────────────────────────────────
// Must match the admin account on the production server.
define('QA_ADMIN_EMAIL', 'thegoodybasket@outlook.com');
define('QA_ADMIN_PASS',  'Tr0pic@lSunset#2024');

// ── Test Category Flags ──────────────────────────────────────────
// Set any to false to skip that section entirely.
define('QA_TEST_PUBLIC_ENDPOINTS',  true);   // §1  Products, locations, dates
define('QA_TEST_AUTH',              true);   // §2  Login, validation
define('QA_TEST_ADMIN_OPERATIONS',  true);   // §3-5 Product/location/date CRUD
define('QA_TEST_CART_ORDERS',       true);   // §6-7 Cart & order placement
define('QA_TEST_REVIEWS',           true);   // §8  Review submission & approval
define('QA_TEST_SETTINGS',          true);   // §9  Settings get/save
define('QA_TEST_CUSTOMER_AUTH',     true);   // §10 Customer registration & auth
define('QA_TEST_SECURITY',          true);   // §11 401/403/CSRF enforcement
define('QA_TEST_BUG_REGRESSIONS',   true);   // §13 Regression checks
define('QA_TEST_CLEANUP',           true);   // §14 Remove all test data

// ── Test Data ────────────────────────────────────────────────────
// Days ahead to use for test order pickup dates.
// Use values large enough to avoid real order conflicts.
define('QA_PICKUP_DAYS_AHEAD',     60);   // Primary test order date
define('QA_PICKUP_DAYS_AHEAD_ALT', 67);   // Date used for block/unblock tests

// ── Timeouts ─────────────────────────────────────────────────────
define('QA_CURL_TIMEOUT',   15);    // Per API call (seconds) — higher for prod latency
define('QA_SCRIPT_TIMEOUT', 180);   // Total script run time (seconds)
