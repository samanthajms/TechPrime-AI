USE ias_ecommerce;

ALTER TABLE users MODIFY COLUMN role ENUM('admin','seller','client','courier') DEFAULT 'client';

ALTER TABLE orders ADD COLUMN shipping_address TEXT NULL;
ALTER TABLE orders ADD COLUMN customer_phone VARCHAR(50) NULL;

CREATE TABLE IF NOT EXISTS shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    courier_id INT NULL,
    carrier ENUM('JNT','LBC','NinjaVan','FlashExpress') DEFAULT 'JNT',
    shipment_status ENUM('pending','processing','shipped','out_for_delivery','delivered') DEFAULT 'pending',
    tracking_number VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ship_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_ship_courier FOREIGN KEY (courier_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_shipments_courier ON shipments(courier_id);
CREATE INDEX idx_shipments_order ON shipments(order_id);
