-- Optional: store customer phone on users for registration / profile.
-- Safe to run multiple times (checks information_schema).

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'phone'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL DEFAULT NULL AFTER address',
  'SELECT ''users.phone already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
