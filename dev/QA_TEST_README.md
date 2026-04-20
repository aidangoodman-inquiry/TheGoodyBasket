# TheGoodyBasket Production QA Test Suite

## Overview

This is a **production-ready QA test suite** configured to test your live website at **https://thegoodybasket.com**.

The suite includes **88 comprehensive tests** covering:
- Public endpoints (products, locations, dates, reviews, settings)
- Authentication (admin login, customer registration, password changes)
- Admin operations (product/location/date CRUD)
- Shopping cart and order placement
- Review system (submission, approval, moderation)
- Security enforcement (401/403, CSRF protection)
- Bug regression tests
- Automatic cleanup

---

## Files

| File | Purpose |
|------|---------|
| `qa_config.php` | **Secure configuration** with admin credentials and settings |
| `qa_test_prod.php` | **Main test suite** for production website |
| `QA_TEST_README.md` | This file |

---

## ⚠️ Security Setup

### 1. Update Admin Credentials

Open `qa_config.php` and update your admin credentials:

```php
define('QA_ADMIN_EMAIL', 'your_admin@email.com');
define('QA_ADMIN_PASS',  'your_admin_password');
```

### 2. Protect the Config File

**Add this to your `.gitignore`** to prevent credentials from being committed:

```
qa_config.php
```

### 3. Deployment Note

When deploying to production:
- Upload `qa_config.php` separately (don't commit to version control)
- Ensure proper file permissions: `chmod 600 qa_config.php`
- Consider moving credentials to environment variables in the future

---

## Usage

### Option A: Run Locally (Recommended for Testing)

1. Ensure `qa_config.php` is in the same directory as `qa_test_prod.php`
2. Open your terminal and run:
   ```bash
   php qa_test_prod.php > qa_results.html
   ```
3. Open `qa_results.html` in your browser to view results

### Option B: Run on Your Server

1. Upload both files to your web server (same directory):
   - `qa_test_prod.php`
   - `qa_config.php`

2. Visit in your browser:
   ```
   https://thegoodybasket.com/qa_test_prod.php
   ```

3. Results display in your browser as an interactive HTML report

### Option C: Scheduled Testing

Create a cron job to run tests automatically:

```bash
# Run tests daily at 2 AM (adjust as needed)
0 2 * * * php /path/to/qa_test_prod.php > /var/log/qa_results_$(date +\%Y\%m\%d).html
```

---

## Configuration Options

Edit `qa_config.php` to customize test behavior:

### Test Categories

Enable/disable specific test suites:

```php
define('QA_TEST_PUBLIC_ENDPOINTS', true);    // Products, locations, dates
define('QA_TEST_AUTH', true);                 // Login, registration
define('QA_TEST_ADMIN_OPERATIONS', true);    // CRUD operations
define('QA_TEST_CART_ORDERS', true);         // Shopping & orders
define('QA_TEST_REVIEWS', true);             // Review system
define('QA_TEST_SECURITY', true);            // 401/403/CSRF checks
define('QA_TEST_BUG_REGRESSIONS', true);     // Bug fixes
define('QA_TEST_CLEANUP', true);             // Remove test data
```

### Test Data

Adjust how far ahead test orders are placed (to avoid capacity conflicts):

```php
define('QA_PICKUP_DAYS_AHEAD', 60);          // 60 days out
define('QA_PICKUP_DAYS_AHEAD_ALT', 67);      // For date blocking
```

### Timeouts

For slower servers or networks:

```php
define('QA_CURL_TIMEOUT', 10);               // Per API call
define('QA_SCRIPT_TIMEOUT', 120);            // Total run time
```

---

## Understanding Results

### Summary Cards

- **Total**: All tests run
- **Passed**: Tests that succeeded (✓)
- **Failed**: Tests that failed (✗)
- **Skipped**: Tests that were skipped (⚠)
- **Pass Rate**: Percentage of tests that passed

### Color Coding

- **Green** (✓): Test passed
- **Red** (✗): Test failed
- **Orange** (⚠): Test skipped

### Failed Tests Summary

If any tests fail, a summary section appears listing each failure with details to investigate.

---

## Common Issues

### "qa_config.php not found"

**Solution**: Ensure both files are in the same directory:
- `qa_test_prod.php`
- `qa_config.php`

### Tests timeout or run slowly

**Solution**: Increase timeouts in `qa_config.php`:
```php
define('QA_CURL_TIMEOUT', 15);   // Increase from 10
define('QA_SCRIPT_TIMEOUT', 180); // Increase from 120
```

### "cURL Error: SSL certificate problem"

**Possible causes**:
1. Your SSL certificate may have expired
2. DNS may not be resolving correctly
3. Firewall may be blocking HTTPS

**Solution**: Check your SSL certificate and verify HTTPS works in a browser first.

### Tests create test data that won't clean up

**Solution**: Tests are designed to clean up automatically, but if cleanup fails:
1. Manually delete any products starting with "QA Test"
2. Manually delete any locations starting with "QA Pickup"
3. Check if cleanup tests passed (section 14)

---

## Test Coverage

### 0. Setup (2 tests)
- CSRF token generation
- Index page accessibility

### 1. Public Endpoints (8 tests)
- Product listing
- Location listing
- Blocked dates listing
- Review listing
- Settings retrieval
- Unauthenticated user info
- Cart access (unauthenticated)

### 2. Auth & Validation (4 tests)
- Login field validation
- Wrong password rejection
- Admin login success
- Admin session verification

### 3-5. Admin Operations (15 tests)
- Product CRUD
- Location CRUD
- Date blocking/unblocking

### 6-7. Cart & Orders (20 tests)
- Cart add/update/remove
- Order placement with validation
- Order capacity checking
- Order status updates

### 8-9. Reviews & Settings (11 tests)
- Review submission and approval
- Admin review moderation
- Settings management

### 10. Customer Registration (11 tests)
- Customer account creation
- Password validation
- Password changes
- Authentication flows

### 11. Security (8 tests)
- 401 Unauthorized checks
- 403 Forbidden (CSRF)
- Access control enforcement

### 13. Bug Regression (4 tests)
- Order ID format validation
- XSS protection verification
- Foreign key constraint handling
- Order status tracking

### 14. Cleanup (2 tests)
- Test data removal
- Location removal

---

## Maintenance

### Updating Credentials

If you change your admin password:

```php
// qa_config.php
define('QA_ADMIN_PASS',  'your_new_password');
```

### Reviewing Test Results

Save results for comparison over time:

```bash
# Save timestamped results
php qa_test_prod.php > qa_results_$(date +%Y%m%d_%H%M%S).html
```

### Adjusting for Seasonal Tests

If you run tests monthly, update `QA_PICKUP_DAYS_AHEAD` to avoid conflicts:

```php
define('QA_PICKUP_DAYS_AHEAD', 90);  // Run months ahead during peak season
```

---

## Support

If tests fail:

1. **Check the failed test details** in the report
2. **Verify the API endpoint** is working (test manually in browser)
3. **Check Network Solutions hosting** status
4. **Review error messages** for SQL, validation, or permission errors

---

## Technical Notes

- Tests use **persistent cURL sessions** with cookies to maintain authentication
- Each test **creates and cleans up its own data** to avoid conflicts
- **Rate limiting** is cleared between test runs to prevent blocking
- Tests **verify CSRF token rotation** for security
- All **API responses are validated** for correct HTTP codes and data structures

---

**Created**: 2026-04-19  
**Production Target**: https://thegoodybasket.com  
**Configuration**: Secure with `qa_config.php`
