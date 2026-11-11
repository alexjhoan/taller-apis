USE taller;

SET @payments_paid_at_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = 'paid_at'
);
SET @payments_paid_at_sql = IF(
  @payments_paid_at_exists = 0,
  'ALTER TABLE payments ADD COLUMN paid_at DATETIME NULL COMMENT ''Cuándo se registró el pago'' AFTER notes',
  'SELECT 1'
);
PREPARE payments_paid_at_stmt FROM @payments_paid_at_sql;
EXECUTE payments_paid_at_stmt;
DEALLOCATE PREPARE payments_paid_at_stmt;

UPDATE payments SET paid_at = created_at WHERE paid_at IS NULL;
ALTER TABLE payments MODIFY COLUMN paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  COMMENT 'Cuándo se registró el pago';

SET @payments_paid_at_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payments'
    AND INDEX_NAME = 'idx_payments_paid_at'
);
SET @payments_paid_at_index_sql = IF(
  @payments_paid_at_index_exists = 0,
  'ALTER TABLE payments ADD KEY idx_payments_paid_at (paid_at)',
  'SELECT 1'
);
PREPARE payments_paid_at_index_stmt FROM @payments_paid_at_index_sql;
EXECUTE payments_paid_at_index_stmt;
DEALLOCATE PREPARE payments_paid_at_index_stmt;

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
