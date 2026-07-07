CREATE DATABASE ias_ecommerce;
USE ias_ecommerce;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    surname VARCHAR(100),
    age INT,
    address TEXT,
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255),
    role ENUM('admin','seller','client','courier') DEFAULT 'client',
    is_verified TINYINT DEFAULT 0,
    is_locked TINYINT DEFAULT 0,
    failed_attempts INT DEFAULT 0,
    -- TOTP (Google Authenticator) — replaces mfa_code / mfa_expiry
    totp_secret VARCHAR(64) NULL,
    totp_enabled TINYINT DEFAULT 0,
    activation_token VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    shop_description TEXT NULL
);

CREATE TABLE locked_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    reason TEXT,
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    description TEXT,
    price DECIMAL(10,2),
    image VARCHAR(255),
    category VARCHAR(100),
    seller_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    product_id INT,
    quantity INT DEFAULT 1
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    shipping_address TEXT,
    customer_phone VARCHAR(50),
    total DECIMAL(10,2),
    status ENUM('to_pay','to_ship','to_receive','to_review') DEFAULT 'to_pay',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    courier_id INT NULL,
    carrier ENUM('JNT','LBC','NinjaVan','FlashExpress') DEFAULT 'JNT',
    shipment_status ENUM('pending','processing','shipped','out_for_delivery','delivered') DEFAULT 'pending',
    tracking_number VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (courier_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    product_id INT,
    quantity INT
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
