-- Migration: site_settings table for admin-configurable password complexity rules
-- Run once against your ias_ecommerce database.
-- Safe to re-run (uses IF NOT EXISTS / INSERT IGNORE).

CREATE TABLE IF NOT EXISTS `site_settings` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT         NOT NULL,
    `updated_by`    INT(11)      DEFAULT NULL,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`),
    KEY `fk_settings_admin` (`updated_by`),
    CONSTRAINT `fk_settings_admin` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default password complexity rules
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES
    ('pw_min_length',      '8'),
    ('pw_require_upper',   '1'),
    ('pw_require_lower',   '1'),
    ('pw_require_number',  '1'),
    ('pw_require_special', '1');
