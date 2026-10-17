USE campuscare;

ALTER TABLE complaint_categories
  ADD COLUMN is_sensitive TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE complaints
  ADD COLUMN is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN first_response_due_at TIMESTAMP NULL,
  ADD COLUMN resolution_due_at TIMESTAMP NULL,
  ADD COLUMN first_responded_at TIMESTAMP NULL,
  ADD INDEX idx_complaints_created (created_at),
  ADD INDEX idx_complaints_category_created (category_id,created_at);

UPDATE complaint_categories SET is_sensitive=1 WHERE name='Harassment / Safety';
UPDATE complaints c
JOIN complaint_categories cat ON cat.id=c.category_id
SET c.is_sensitive=1
WHERE cat.is_sensitive=1;

CREATE TABLE complaint_timeline (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  details VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_timeline_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_timeline_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_timeline_complaint_date (complaint_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE complaint_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_name VARCHAR(80) NOT NULL UNIQUE,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attachments_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
  INDEX idx_attachments_complaint (complaint_id)
) ENGINE=InnoDB;

CREATE TABLE complaint_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_feedback_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_feedback_rating (rating)
) ENGINE=InnoDB;
