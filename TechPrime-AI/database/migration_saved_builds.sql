-- Saved PC builds for Tech & Match (per client user).
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS saved_builds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    build_name VARCHAR(150) NOT NULL,
    components_json MEDIUMTEXT NOT NULL,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    component_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_saved_builds_user (user_id),
    CONSTRAINT fk_saved_builds_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
