USE taller;

CREATE TABLE IF NOT EXISTS order_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  user_id INT NOT NULL,
  user_name VARCHAR(255),
  user_role VARCHAR(50),
  changes JSON,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order_id (order_id)
) ENGINE=InnoDB;
