USE campuscare;

CREATE TABLE college_support_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(160) NOT NULL,
  status ENUM('Open','Answered') NOT NULL DEFAULT 'Open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_support_requests_user_updated (user_id,updated_at),
  INDEX idx_support_requests_status_updated (status,updated_at)
) ENGINE=InnoDB;

CREATE TABLE college_support_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_messages_request FOREIGN KEY (request_id) REFERENCES college_support_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_messages_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_support_messages_request_date (request_id,created_at)
) ENGINE=InnoDB;

ALTER TABLE notifications
  ADD COLUMN support_request_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_notifications_support_request FOREIGN KEY (support_request_id) REFERENCES college_support_requests(id) ON DELETE CASCADE;
