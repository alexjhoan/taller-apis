USE taller;

-- Add targeted alert delivery and per-user read receipts.

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'alerts'
     AND COLUMN_NAME = 'targeted') = 0,
  'ALTER TABLE alerts ADD COLUMN targeted TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS alert_recipients (
  alert_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NULL,
  PRIMARY KEY (alert_id, user_id),
  INDEX idx_alert_recipients_user_read (user_id, read_at, alert_id),
  CONSTRAINT fk_alert_recipients_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_alert_recipients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
