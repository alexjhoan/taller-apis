USE taller;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'alert_recipients'
     AND COLUMN_NAME = 'updated_by') = 0,
  'ALTER TABLE alert_recipients ADD COLUMN updated_by TEXT NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
