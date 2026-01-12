CREATE TABLE IF NOT EXISTS customer_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  first_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NULL,
  show_name TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  moderated_at DATETIME NULL,
  moderated_by BIGINT UNSIGNED NULL,
  admin_note VARCHAR(255) NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  source_page VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_reviews_status_created (status, created_at),
  INDEX idx_customer_reviews_ip_hash_created (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

