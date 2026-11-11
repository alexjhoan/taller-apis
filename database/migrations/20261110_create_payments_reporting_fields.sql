USE taller;

SET @payments_created_at_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payments'
    AND INDEX_NAME = 'idx_payments_created_at'
);
SET @payments_created_at_index_sql = IF(
  @payments_created_at_index_exists = 0,
  'ALTER TABLE payments ADD KEY idx_payments_created_at (created_at)',
  'SELECT 1'
);
PREPARE payments_created_at_index_stmt FROM @payments_created_at_index_sql;
EXECUTE payments_created_at_index_stmt;
DEALLOCATE PREPARE payments_created_at_index_stmt;

SET @payments_method_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payments'
    AND INDEX_NAME = 'idx_payments_payment_method'
);
SET @payments_method_index_sql = IF(
  @payments_method_index_exists = 0,
  'ALTER TABLE payments ADD KEY idx_payments_payment_method (payment_method)',
  'SELECT 1'
);
PREPARE payments_method_index_stmt FROM @payments_method_index_sql;
EXECUTE payments_method_index_stmt;
DEALLOCATE PREPARE payments_method_index_stmt;
