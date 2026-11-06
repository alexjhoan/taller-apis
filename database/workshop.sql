CREATE DATABASE IF NOT EXISTS taller CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE taller;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  nickname VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NULL,
  role VARCHAR(40) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  user_token VARCHAR(512) NULL,
  exp_token INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_token CHAR(48) NOT NULL UNIQUE,
  customer_name VARCHAR(160) NOT NULL,
  customer_phone VARCHAR(40) NOT NULL,
  customer_email VARCHAR(190) NULL,
  vehicle_plate VARCHAR(30) NOT NULL,
  vehicle_make VARCHAR(80) NOT NULL,
  vehicle_model VARCHAR(80) NOT NULL,
  vehicle_year SMALLINT UNSIGNED NULL,
  vehicle_color VARCHAR(30) NULL,
  mileage INT UNSIGNED NOT NULL,
  fuel_level VARCHAR(40) NOT NULL,
  failure_description TEXT NOT NULL,
  parts JSON NULL,
  checklist JSON NULL,
  images TEXT NULL,
  videos TEXT NULL,
  status VARCHAR(40) NULL,
  assigned_user_id TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  withdrawal_comment TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_orders_status (status),
  INDEX idx_orders_plate (vehicle_plate)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_evidence (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NULL,
  evidence_type VARCHAR(20) NOT NULL DEFAULT 'work_progress',
  file_url VARCHAR(500) NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_evidence_order FOREIGN KEY (order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_evidence_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
  INDEX idx_order_evidence_type (order_id, evidence_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS budget_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL,
  supplier_cost DECIMAL(12,2) NULL,
  customer_decision ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_budget_order FOREIGN KEY (order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  INDEX idx_budget_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO app_settings (setting_key, setting_value) VALUES ('mechanic_cost_visibility', '0') ON DUPLICATE KEY UPDATE setting_key = setting_key;

CREATE TABLE IF NOT EXISTS versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_endpoint (endpoint_hash),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  type VARCHAR(80) NOT NULL,
  message VARCHAR(500) NOT NULL,
  read_at DATETIME NULL,
  updated_by TEXT NULL,
  targeted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alert_order FOREIGN KEY (order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  INDEX idx_alerts_unread (read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alert_recipients (
  alert_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NULL,
  updated_by TEXT NULL,
  PRIMARY KEY (alert_id, user_id),
  INDEX idx_alert_recipients_user_read (user_id, read_at, alert_id),
  CONSTRAINT fk_alert_recipients_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_alert_recipients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Generar un hash con password_hash() y reemplazar este valor antes de usar en producción.
-- INSERT INTO users (first_name, last_name, email, password, role) VALUES ('Admin', 'Principal', 'admin@example.com', '$2y$10$REPLACE_WITH_PASSWORD_HASH', 'admin');
