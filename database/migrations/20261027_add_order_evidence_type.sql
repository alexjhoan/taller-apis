USE taller;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'order_evidence'
     AND COLUMN_NAME = 'evidence_type') = 0,
  'ALTER TABLE order_evidence
   ADD COLUMN evidence_type VARCHAR(20) NOT NULL DEFAULT ''work_progress''',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'order_evidence'
     AND INDEX_NAME = 'idx_order_evidence_type') = 0,
  'CREATE INDEX idx_order_evidence_type ON order_evidence (order_id, evidence_type)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
