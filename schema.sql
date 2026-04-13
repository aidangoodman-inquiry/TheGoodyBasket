-- ================================================================
-- THE GOODY BASKET — Database Schema
-- Run the entire contents of this file in phpMyAdmin:
--   Database > SQL tab > paste > Go
-- Safe to re-run (uses IF NOT EXISTS / INSERT IGNORE).
-- ================================================================

-- Accounts (admin + customer logins)
CREATE TABLE IF NOT EXISTS accounts (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(191)     NOT NULL UNIQUE,
    password_hash VARCHAR(255)     NOT NULL,
    role          ENUM('admin','customer') NOT NULL DEFAULT 'customer',
    created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products (loaves for sale)
CREATE TABLE IF NOT EXISTS products (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Name        VARCHAR(191)    NOT NULL,
    Description TEXT,
    Cost        DECIMAL(8,2)    NOT NULL DEFAULT 0.00,
    category    ENUM('regular','specialty') NOT NULL DEFAULT 'regular',
    available   TINYINT(1)      NOT NULL DEFAULT 1,
    Dairy       TINYINT(1)      NOT NULL DEFAULT 0,
    TreeNuts    TINYINT(1)      NOT NULL DEFAULT 0,
    Egg         TINYINT(1)      NOT NULL DEFAULT 0,
    Peanut      TINYINT(1)      NOT NULL DEFAULT 0,
    Sesame      TINYINT(1)      NOT NULL DEFAULT 0,
    Soy         TINYINT(1)      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pickup locations
CREATE TABLE IF NOT EXISTS locations (
    id      INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(191)  NOT NULL,
    address VARCHAR(255)  NOT NULL,
    active  TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders
CREATE TABLE IF NOT EXISTS orders (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    order_id         VARCHAR(20)   NOT NULL UNIQUE,   -- e.g. TGB-A1B2C3D4
    account_id       INT UNSIGNED  NULL,
    email            VARCHAR(191)  NOT NULL,
    customer_name    VARCHAR(191)  NOT NULL,
    phone            VARCHAR(30)   NULL,
    total_cost       DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    pickup_date      DATE          NOT NULL,
    location_id      INT UNSIGNED  NOT NULL,
    location_name    VARCHAR(191)  NOT NULL,
    location_address VARCHAR(255)  NULL,
    notes            TEXT          NULL,
    status           ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_account
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Line items belonging to an order
CREATE TABLE IF NOT EXISTS order_items (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    order_id   INT UNSIGNED   NOT NULL,   -- references orders.id (the auto-increment PK)
    product_id INT UNSIGNED   NOT NULL,
    quantity   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    item_cost  DECIMAL(8,2)   NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shopping carts (one per logged-in user OR per guest session)
CREATE TABLE IF NOT EXISTS carts (
    id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED  NULL UNIQUE,
    session_id VARCHAR(64)   NULL UNIQUE,
    CONSTRAINT fk_carts_account
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Items inside a cart
CREATE TABLE IF NOT EXISTS cart_items (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cart_id    INT UNSIGNED    NOT NULL,
    product_id INT UNSIGNED    NOT NULL,
    quantity   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cart_product (cart_id, product_id),
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id)    REFERENCES carts(id)    ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dates blocked from customer ordering
CREATE TABLE IF NOT EXISTS blocked_dates (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date DATE         NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer reviews (linked to orders by the TGB-XXXXXXXX string)
CREATE TABLE IF NOT EXISTS reviews (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    order_id     VARCHAR(20)   NOT NULL,
    display_name VARCHAR(100)  NOT NULL,
    rating       TINYINT UNSIGNED NOT NULL,
    review_text  TEXT          NOT NULL,
    status       ENUM('pending','approved') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Key-value store for admin-configurable settings
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings rows (safe to re-run)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('owner_email',           ''),
    ('ejs_user',              ''),
    ('ejs_service',           ''),
    ('ejs_template',          ''),
    ('ejs_customer_template', '');
