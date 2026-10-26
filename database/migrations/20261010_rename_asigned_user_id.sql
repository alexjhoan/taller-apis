USE taller;

SET @old_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'work_orders'
    AND COLUMN_NAME = 'asigned_user_id'
);
SET @new_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'work_orders'
    AND COLUMN_NAME = 'assigned_user_id'
);

SET @migration_sql = IF(
  @old_column_exists = 1 AND @new_column_exists = 0,
  'ALTER TABLE work_orders CHANGE COLUMN `asigned_user_id` `assigned_user_id` INT(10) NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
