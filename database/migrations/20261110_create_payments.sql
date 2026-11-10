USE taller;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  labor_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  parts_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('cash', 'card', 'transfer', 'other') DEFAULT 'cash',
  reference VARCHAR(100) DEFAULT NULL COMMENT 'Número de transacción, autorización, etc.',
  notes TEXT DEFAULT NULL COMMENT 'Observaciones del cobro',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_payments_order_id (order_id),
  KEY idx_payments_created_at (created_at),
  CONSTRAINT fk_payments_order_id FOREIGN KEY (order_id) REFERENCES work_orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
