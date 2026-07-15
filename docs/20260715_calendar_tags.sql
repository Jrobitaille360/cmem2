CREATE TABLE calendar_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  calendar_id INT NOT NULL,
  name VARCHAR(191) NOT NULL,
  color VARCHAR(20) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_calendar_tag_name (calendar_id, name),
  FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
