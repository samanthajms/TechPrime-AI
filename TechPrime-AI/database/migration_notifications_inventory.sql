-- Extend notifications for inventory alerts (is_read, type, link).
-- Safe to run multiple times.

SET @has_is_read := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'is_read'
);
SET @sql := IF(@has_is_read = 0,
  'ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER message',
  'SELECT ''is_read exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'type'
);
SET @sql := IF(@has_type = 0,
  'ALTER TABLE notifications ADD COLUMN type VARCHAR(40) NULL DEFAULT ''info'' AFTER is_read',
  'SELECT ''type exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_link := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'link'
);
SET @sql := IF(@has_link = 0,
  'ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL DEFAULT NULL AFTER type',
  'SELECT ''link exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
