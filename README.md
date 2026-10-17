# CampusCare — Complaint Management System (PHP + MySQL)

A complete role-based college complaint management starter project.

## Roles
- Student: register, submit complaints, track status, exchange messages, view notifications.
- Teacher: review assigned/department complaints, reply, update status.
- Principal: institution-wide oversight, assign complaints, review reports, manage staff access.
- Admin: full access, account approvals, categories, complaints, audit log.

## Requirements
- PHP 8.1+ (8.2+ recommended)
- MySQL 8+ or MariaDB 10.5+
- PHP extensions: pdo_mysql, mbstring, fileinfo
- A web server or PHP built-in server for local development

## 1. Create the database
Run `database/schema.sql` in MySQL Workbench, phpMyAdmin, Adminer, or:
```bash
mysql -u root -p < database/schema.sql
```

For an existing CampusCare database, take a backup and apply the one-time migration:
```bash
mysql -u root -p < database/migrations/001_add_case_features.sql
mysql -u root -p < database/migrations/002_add_college_contact.sql
```

## 2. Configure database credentials
Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` in the environment where PHP runs, or update the corresponding settings in `app/config.php`. The default database user is `campuscare_app` and the password is blank, so configure credentials before starting the application. For local MySQL, use host `127.0.0.1`, port `3306`, and database `campuscare`. Do not use the MySQL root account in production; create a restricted database user.

For a local shell session:
```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=campuscare
export DB_USER=campuscare_app
export DB_PASS='your-local-database-password'
```

## 3. Create the first administrator
From the project root:
```bash
php bin/create_admin.php
```
This prompts for the admin's name, email and password and inserts a verified admin account. Do not expose this script through the web server.

## 4. Run locally
From the project root:
```bash
php -S 127.0.0.1:8000 -t public
```
Open `http://127.0.0.1:8000`.

If your PHP installation is not in PATH, start the built-in server using the full path to your PHP executable. Make sure MySQL is running first.

For attachment uploads, configure PHP with `file_uploads=On`, `upload_max_filesize=5M`, and `post_max_size=20M`. Uploaded files are stored in `storage/private-uploads`, outside `public/`; set `UPLOAD_DIR` to a private writable directory if needed. Do not put that directory under the web document root. Files are limited to three PDF/JPEG/PNG/GIF/WebP attachments per complaint, up to 5 MB each.

SLA deadlines default to 48 hours for first staff response and 240 hours for resolution (calendar hours). Override them with `SLA_FIRST_RESPONSE_HOURS` and `SLA_RESOLUTION_HOURS`. Existing complaints keep their current state and are not assigned new historical deadlines by the migration.

The student dashboard shows the campus information panel. Set `COLLEGE_NAME`, `COLLEGE_ADDRESS`, and `COLLEGE_OFFICE_HOURS` to customize it. These default to SAS SNDP Yogam College, Konni, Pathanamthitta, Keralam, and 10:30 to 3. Students can open campus directions or contact approved administrators through the in-app College Contact inbox; staff replies arrive in the student's notifications. No email or telephone contact was provided, so the UI does not invent or publish one.

## 5. First login
Sign in with the admin account created in step 3. From User Management, create/approve teachers and other staff. Public registration creates student accounts in `Pending` status; students cannot submit complaints until approved.

## Included features
- Secure password hashing and PDO prepared statements
- CSRF token checks for POST requests
- Role-based access checks
- Student account approval
- Complaint submission, categories, priority, status, assignment
- Teacher/principal/admin workflow
- Complaint discussion thread
- Notifications
- Search and status filtering
- CSV report export
- Audit log for key actions
- Responsive dashboard with summary metrics
- Responsive light and dark themes, with a saved preference and system-theme default
- Accessible focus states, subtle button feedback, and reduced-motion support
- Complaint history timeline, staff assignment, and open-case workload summaries
- Configurable response/resolution SLA targets, overdue indicators, and resolution feedback
- Complaint search, category/priority/status/date/department/assignee filters, and pagination
- Private complaint categories (visible only to the submitter and administrators)
- Private evidence uploads delivered only after complaint-level access checks
- Unread in-app notification count, help/FAQ, editable profile, and password changes
- Black-and-gold dark theme and coordinated ivory-and-gold light theme
- College information/directions panel, student-to-college contact conversations, staff inbox, and reply notifications
- Reports with monthly/category charts, average resolution time, student ratings, overdue counts, and access-scoped CSV export

## Important production checklist
This is a functional college-project starter, not a security certification. Before public deployment:
- Use HTTPS and secure cookie settings.
- Move secrets to environment variables and restrict DB permissions.
- Disable display_errors; configure error logs.
- Add rate limiting / login throttling and email verification.
- Configure backups, retention, privacy notices and access policies.
- Review role/department visibility rules with your institution.
- Configure private attachment storage with restrictive permissions and include it in encrypted backups and retention policies.
- Email notifications are not enabled; this installation uses in-app notifications only.
- Private complaints are visible only to their submitter and administrators; treat administrator access as sensitive and audit it.
- Test on your target PHP/MySQL versions and run security tests.
