CREATE DATABASE IF NOT EXISTS campuscare
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campuscare;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  roll_number VARCHAR(50) NULL UNIQUE,
  department VARCHAR(100) NULL,
  year TINYINT UNSIGNED NULL,
  role ENUM('student','teacher','principal','admin') NOT NULL DEFAULT 'student',
  status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_status (role,status),
  INDEX idx_users_department (department)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaint_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(24) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  assigned_admin_id BIGINT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  subject VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  department VARCHAR(100) NULL,
  priority ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
  status ENUM('Pending','In Progress','Resolved','Rejected') NOT NULL DEFAULT 'Pending',
  resolution_note TEXT NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
  first_response_due_at TIMESTAMP NULL,
  resolution_due_at TIMESTAMP NULL,
  first_responded_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  CONSTRAINT fk_complaints_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_complaints_assignee FOREIGN KEY (assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_complaints_category FOREIGN KEY (category_id) REFERENCES complaint_categories(id) ON DELETE SET NULL,
  INDEX idx_complaints_status (status),
  INDEX idx_complaints_user_created (user_id,created_at),
  INDEX idx_complaints_department (department),
  INDEX idx_complaints_assignee (assigned_admin_id),
  INDEX idx_complaints_created (created_at),
  INDEX idx_complaints_category_created (category_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaint_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_messages_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_messages_complaint_date (complaint_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaint_timeline (
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

CREATE TABLE IF NOT EXISTS complaint_attachments (
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

CREATE TABLE IF NOT EXISTS complaint_feedback (
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

CREATE TABLE IF NOT EXISTS college_support_requests (
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

CREATE TABLE IF NOT EXISTS college_support_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_messages_request FOREIGN KEY (request_id) REFERENCES college_support_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_messages_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_support_messages_request_date (request_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  complaint_id BIGINT UNSIGNED NULL,
  support_request_id BIGINT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  message VARCHAR(500) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL,
  CONSTRAINT fk_notifications_support_request FOREIGN KEY (support_request_id) REFERENCES college_support_requests(id) ON DELETE CASCADE,
  INDEX idx_notifications_user_read (user_id,is_read)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details VARCHAR(1000) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_actor (actor_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO complaint_categories (name,description) VALUES
('Academic','Classes, exams, marks and academic administration'),
('Facilities','Classrooms, labs, furniture and campus infrastructure'),
('IT Support','Wi-Fi, systems, portals and technical issues'),
('Library','Library access, books and study resources'),
('Hostel','Hostel facilities and accommodation'),
('Transport','College transport and parking'),
('Harassment / Safety','Sensitive concerns requiring appropriate handling'),
('Other','Any issue not covered by the listed categories');

UPDATE complaint_categories SET is_sensitive=1 WHERE name='Harassment / Safety';
