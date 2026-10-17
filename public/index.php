<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$page = (string)($_GET['page'] ?? 'dashboard');
$publicPages = ['login','register'];
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $page === 'attachment') {
    $u = require_login();
    $attachmentId = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT a.storage_name,a.mime_type,c.* FROM complaint_attachments a JOIN complaints c ON c.id=a.complaint_id WHERE a.id=?');
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch();
    if (!$attachment || !can_view_complaint($u, $attachment)) {
        http_response_code(404);
        exit('Attachment not found or access denied.');
    }
    $storageName = (string)$attachment['storage_name'];
    $allowedMimeTypes = ['application/pdf','image/jpeg','image/png','image/gif','image/webp'];
    if (!preg_match('/\A[a-f0-9]{48}\.(pdf|jpg|png|gif|webp)\z/', $storageName)
        || !in_array($attachment['mime_type'], $allowedMimeTypes, true)) {
        error_log('Complaint attachment metadata failed validation.');
        http_response_code(404);
        exit('Attachment is no longer available.');
    }
    $root = realpath($config['upload_directory']);
    $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $storageName);
    if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        error_log('Complaint attachment is missing or outside private storage.');
        http_response_code(404);
        exit('Attachment is no longer available.');
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="complaint-attachment.' . pathinfo($storageName, PATHINFO_EXTENSION) . '"');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $found = $stmt->fetch();
        if ($found && password_verify($password, $found['password'])) {
            if ($found['status'] !== 'Approved' && $found['role'] !== 'admin') {
                flash('warning', 'Your account is ' . strtolower($found['status']) . '. Please contact the college administrator.');
                redirect('?page=login');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$found['id'];
            audit((int)$found['id'], 'login', 'user', (int)$found['id']);
            redirect('?page=dashboard');
        }
        flash('danger', 'Invalid email or password.');
        redirect('?page=login');
    }

    if ($action === 'register') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $roll = trim((string)($_POST['roll_number'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $year = (int)($_POST['year'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || $department === '') {
            flash('danger', 'Enter a name, valid email, department, and password of at least 10 characters.');
            redirect('?page=register');
        }
        try {
            $stmt = db()->prepare("INSERT INTO users (name,email,password,roll_number,department,year,role,status) VALUES (?,?,?,?,?,?,'student','Pending')");
            $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$roll !== '' ? $roll : null,$department,$year ?: null]);
            flash('success', 'Registration submitted. You can sign in after your account is approved.');
            redirect('?page=login');
        } catch (PDOException $e) {
            flash('danger', 'Could not register. The email or roll number may already be in use.');
            redirect('?page=register');
        }
    }

    if ($action === 'logout') {
        if ($u) audit((int)$u['id'], 'logout', 'user', (int)$u['id']);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        redirect('?page=login');
    }

    if ($action === 'create_complaint') {
        $u = require_roles(['student']);
        if ($u['status'] !== 'Approved') redirect('?page=dashboard');
        $subject = trim((string)($_POST['subject'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $priority = (string)($_POST['priority'] ?? 'Medium');
        if ($subject === '' || $description === '' || mb_strlen($subject) > 200 || !in_array($priority,['Low','Medium','High','Urgent'],true)) {
            flash('danger', 'Please enter a valid subject, description and priority.');
            redirect('?page=new_complaint');
        }
        $cat = db()->prepare('SELECT id,is_sensitive FROM complaint_categories WHERE id=? AND is_active=1');
        $cat->execute([$categoryId]);
        $category = $cat->fetch();
        if (!$category) {
            flash('danger', 'Choose a valid category.');
            redirect('?page=new_complaint');
        }
        try {
            $uploads = normalize_complaint_uploads($_FILES['attachments'] ?? []);
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
            redirect('?page=new_complaint');
        }

        $ticket = 'CC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $storedUploads = [];
        try {
            db()->beginTransaction();
            $stmt = db()->prepare('INSERT INTO complaints (ticket_no,user_id,category_id,subject,description,department,priority,is_sensitive,first_response_due_at,resolution_due_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $ticket, $u['id'], $categoryId, $subject, $description, $u['department'], $priority,
                (int)$category['is_sensitive'],
                date('Y-m-d H:i:s', time() + $config['sla_first_response_hours'] * 3600),
                date('Y-m-d H:i:s', time() + $config['sla_resolution_hours'] * 3600),
            ]);
            $cid = (int)db()->lastInsertId();
            record_complaint_event($cid, (int)$u['id'], 'submitted', 'Complaint submitted');
            foreach ($uploads as $upload) {
                $storageName = store_complaint_upload($upload);
                $storedUploads[] = $storageName;
                $stmt = db()->prepare('INSERT INTO complaint_attachments (complaint_id,uploaded_by,original_name,storage_name,mime_type,file_size) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$cid, $u['id'], $upload['original_name'], $storageName, $upload['mime_type'], $upload['file_size']]);
            }
            $staffQuery = db()->prepare("SELECT id FROM users WHERE role IN ('admin'" . ((int)$category['is_sensitive'] ? '' : ",'principal'") . ") AND status='Approved'");
            $staffQuery->execute();
            foreach ($staffQuery->fetchAll() as $staff) {
                notify_user((int)$staff['id'], $cid, 'New complaint', 'A new complaint ' . $ticket . ' was submitted.');
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            remove_complaint_uploads($storedUploads);
            if ($e instanceof RuntimeException) {
                flash('danger', $e->getMessage());
                redirect('?page=new_complaint');
            }
            throw $e;
        }
        audit((int)$u['id'], 'complaint_created', 'complaint', $cid, $ticket);
        flash('success', 'Complaint submitted. Your ticket number is ' . $ticket . '.');
        redirect('?page=complaints');
    }

    if ($action === 'update_complaint') {
        $u = require_roles(['teacher','principal','admin']);
        $cid = (int)($_POST['complaint_id'] ?? 0);
        $stmt = db()->prepare('SELECT c.* FROM complaints c WHERE c.id=?');
        $stmt->execute([$cid]); $c = $stmt->fetch();
        if (!$c || !can_view_complaint($u, $c) || (!empty($c['is_sensitive']) && $u['role'] !== 'admin')) {
            http_response_code(404); exit('Complaint not found or access denied.');
        }
        $status = (string)($_POST['status'] ?? '');
        $priority = (string)($_POST['priority'] ?? '');
        $assignee = (int)($_POST['assigned_admin_id'] ?? 0);
        $note = trim((string)($_POST['resolution_note'] ?? ''));
        if (!in_array($status,['Pending','In Progress','Resolved','Rejected'],true) || !in_array($priority,['Low','Medium','High','Urgent'],true) || mb_strlen($note) > 5000) {
            flash('danger','Invalid status or priority.'); redirect('?page=complaint&id='.$cid);
        }
        if ($u['role'] === 'teacher') {
            $assignee = (int)$u['id'];
        } elseif ($u['role'] === 'principal' && !array_key_exists('assigned_admin_id', $_POST)) {
            $assignee = (int)($c['assigned_admin_id'] ?? 0);
        } elseif ($assignee > 0) {
            $staffQuery = db()->prepare("SELECT id,role FROM users WHERE id=? AND status='Approved' AND role IN ('teacher','principal','admin')");
            $staffQuery->execute([$assignee]);
            $selectedStaff = $staffQuery->fetch();
            if (!$selectedStaff || ($u['role'] === 'principal' && $selectedStaff['role'] === 'admin')
                || (!empty($c['is_sensitive']) && $selectedStaff['role'] !== 'admin')) {
                flash('danger', 'Choose an active staff member you are allowed to assign.');
                redirect('?page=complaint&id='.$cid);
            }
        }
        $previousAssignee = (int)($c['assigned_admin_id'] ?? 0);
        $statusChanged = $status !== $c['status'];
        $assigneeChanged = $assignee !== $previousAssignee;
        $priorityChanged = $priority !== $c['priority'];
        $noteChanged = $note !== (string)($c['resolution_note'] ?? '');
        $reopened = in_array($c['status'], ['Resolved','Rejected'], true)
            && !in_array($status, ['Resolved','Rejected'], true);
        $resolvedAt = $status === 'Resolved'
            ? ($statusChanged ? date('Y-m-d H:i:s') : $c['resolved_at'])
            : null;
        $staffResponded = $u['role'] !== 'student' && $status !== 'Pending';
        $firstRespondedAt = $reopened
            ? ($staffResponded ? date('Y-m-d H:i:s') : null)
            : ($staffResponded && empty($c['first_responded_at']) ? date('Y-m-d H:i:s') : $c['first_responded_at']);
        $firstResponseDueAt = $reopened
            ? date('Y-m-d H:i:s', time() + $config['sla_first_response_hours'] * 3600)
            : $c['first_response_due_at'];
        $resolutionDueAt = $reopened
            ? date('Y-m-d H:i:s', time() + $config['sla_resolution_hours'] * 3600)
            : $c['resolution_due_at'];
        $stmt = db()->prepare('UPDATE complaints SET status=?,priority=?,assigned_admin_id=?,resolution_note=?,resolved_at=?,first_responded_at=?,first_response_due_at=?,resolution_due_at=? WHERE id=?');
        $stmt->execute([$status,$priority,$assignee ?: null,$note ?: null,$resolvedAt,$firstRespondedAt,$firstResponseDueAt,$resolutionDueAt,$cid]);
        if ($statusChanged) record_complaint_event($cid, (int)$u['id'], 'status', 'Status changed from ' . $c['status'] . ' to ' . $status);
        if ($assigneeChanged) {
            $assigneeNameQuery = db()->prepare('SELECT name FROM users WHERE id=?');
            $assigneeNameQuery->execute([$assignee]);
            $assigneeName = $assignee ? $assigneeNameQuery->fetchColumn() : false;
            record_complaint_event($cid, (int)$u['id'], 'assignment', $assigneeName ? 'Assigned to ' . $assigneeName : 'Assignment removed');
            if ($assignee && $assignee !== (int)$u['id']) {
                notify_user($assignee, $cid, 'Complaint assigned', 'Complaint ' . $c['ticket_no'] . ' has been assigned to you.');
            }
            notify_user((int)$c['user_id'], $cid, 'Complaint assigned', $assigneeName ? 'Your complaint was assigned to ' . $assigneeName . '.' : 'The assignment on your complaint was removed.');
        }
        if ($priorityChanged) record_complaint_event($cid, (int)$u['id'], 'priority', 'Priority changed from ' . $c['priority'] . ' to ' . $priority);
        if ($noteChanged) record_complaint_event($cid, (int)$u['id'], 'resolution_note', $note === '' ? 'Staff note removed' : 'Staff added or updated a resolution note');
        if ($statusChanged) {
            notify_user((int)$c['user_id'],$cid,'Complaint updated','Your complaint ' . $c['ticket_no'] . ' is now ' . $status . '.');
        }
        if ($statusChanged || $assigneeChanged || $priorityChanged || $noteChanged) {
            audit((int)$u['id'],'complaint_updated','complaint',$cid,'status='.$status);
            flash('success','Complaint updated.');
        } else {
            flash('info','No complaint changes to save.');
        }
        redirect('?page=complaint&id='.$cid);
    }

    if ($action === 'send_message') {
        $u = require_login();
        $cid = (int)($_POST['complaint_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        $stmt = db()->prepare('SELECT c.* FROM complaints c WHERE c.id=?');
        $stmt->execute([$cid]); $c = $stmt->fetch();
        if (!$c || !can_view_complaint($u,$c) || $message === '' || mb_strlen($message) > 5000) {
            flash('danger','Unable to send message. Check access and message length.');
            redirect('?page=complaint&id='.$cid);
        }
        $stmt = db()->prepare('INSERT INTO complaint_messages (complaint_id,user_id,message) VALUES (?,?,?)');
        $stmt->execute([$cid,$u['id'],$message]);
        record_complaint_event($cid, (int)$u['id'], 'message', 'A discussion reply was added');
        if ($u['role'] !== 'student' && empty($c['first_responded_at'])) {
            db()->prepare('UPDATE complaints SET first_responded_at=NOW() WHERE id=?')->execute([$cid]);
        }
        $recipient = (int)$c['user_id'] === (int)$u['id'] ? (int)($c['assigned_admin_id'] ?: 0) : (int)$c['user_id'];
        if ($recipient > 0 && $recipient !== (int)$u['id']) {
            notify_user($recipient,$cid,'New complaint message','There is a new message on ' . $c['ticket_no'] . '.');
        } elseif ((int)$c['user_id'] === (int)$u['id'] && !$recipient) {
            $staffQuery = db()->prepare("SELECT id FROM users WHERE status='Approved' AND role='admin'");
            $staffQuery->execute();
            foreach ($staffQuery->fetchAll() as $staff) {
                notify_user((int)$staff['id'], $cid, 'New complaint message', 'There is a new message on ' . $c['ticket_no'] . '.');
            }
        }
        audit((int)$u['id'],'message_sent','complaint',$cid);
        flash('success','Message sent.');
        redirect('?page=complaint&id='.$cid);
    }

    if ($action === 'submit_feedback') {
        $u = require_roles(['student']);
        $cid = (int)($_POST['complaint_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim((string)($_POST['comment'] ?? ''));
        $stmt = db()->prepare("SELECT id,ticket_no FROM complaints WHERE id=? AND user_id=? AND status='Resolved'");
        $stmt->execute([$cid, $u['id']]);
        $complaint = $stmt->fetch();
        if (!$complaint || $rating < 1 || $rating > 5 || mb_strlen($comment) > 1000) {
            flash('danger', 'Feedback is available for resolved complaints; choose a rating from 1 to 5.');
            redirect('?page=complaint&id=' . $cid);
        }
        $stmt = db()->prepare('INSERT INTO complaint_feedback (complaint_id,user_id,rating,comment) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment),updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([$cid, $u['id'], $rating, $comment ?: null]);
        record_complaint_event($cid, (int)$u['id'], 'feedback', 'Student submitted resolution feedback (' . $rating . '/5)');
        audit((int)$u['id'], 'feedback_submitted', 'complaint', $cid, 'rating=' . $rating);
        flash('success', 'Thank you for your feedback.');
        redirect('?page=complaint&id=' . $cid);
    }

    if ($action === 'update_profile') {
        $actor = require_login();
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $roll = trim((string)($_POST['roll_number'] ?? ''));
        $yearInput = trim((string)($_POST['year'] ?? ''));
        $year = $yearInput === '' ? null : (int)$yearInput;
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($email) > 190 || mb_strlen($department) > 100 || mb_strlen($roll) > 50
            || ($year !== null && ($year < 1 || $year > 6))) {
            flash('danger', 'Check your name, email, department, roll number, and year.');
            redirect('?page=profile');
        }
        try {
            $stmt = db()->prepare('UPDATE users SET name=?,email=?,department=?,roll_number=?,year=? WHERE id=?');
            $stmt->execute([$name, $email, $department ?: null, $roll ?: null, $year, $actor['id']]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            flash('danger', 'That email address or roll number is already in use.');
            redirect('?page=profile');
        }
        audit((int)$actor['id'], 'profile_updated', 'user', (int)$actor['id']);
        flash('success', 'Your profile has been updated.');
        redirect('?page=profile');
    }

    if ($action === 'change_password') {
        $actor = require_login();
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $stmt = db()->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$actor['id']]);
        $passwordHash = $stmt->fetchColumn();
        if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)
            || strlen($newPassword) < 12 || $newPassword !== $confirmPassword) {
            flash('danger', 'Verify your current password, then enter and confirm a new password of at least 12 characters.');
            redirect('?page=profile');
        }
        db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $actor['id']]);
        audit((int)$actor['id'], 'password_changed', 'user', (int)$actor['id']);
        flash('success', 'Your password has been changed.');
        redirect('?page=profile');
    }

    if ($action === 'user_status') {
        $actor = require_roles(['admin','principal']);
        $id = (int)($_POST['user_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status,['Pending','Approved','Rejected'],true)) {
            flash('danger','Invalid account status.'); redirect('?page=users');
        }
        $stmt = db()->prepare('SELECT id,role FROM users WHERE id=?');
        $stmt->execute([$id]); $target = $stmt->fetch();
        if (!$target || (int)$target['id'] === (int)$actor['id'] || ($actor['role'] === 'principal' && $target['role'] === 'admin')) {
            flash('danger','You cannot change this account.'); redirect('?page=users');
        }
        $stmt = db()->prepare('UPDATE users SET status=? WHERE id=?');
        $stmt->execute([$status,$id]);
        audit((int)$actor['id'],'user_status_changed','user',$id,'status='.$status);
        flash('success','Account status updated.');
        redirect('?page=users');
    }

    if ($action === 'assign_user') {
        $actor = require_roles(['admin','principal']);
        $id = (int)($_POST['user_id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');
        if (!in_array($role,['student','teacher','principal','admin'],true) || ($actor['role'] === 'principal' && $role === 'admin')) {
            flash('danger','Invalid role assignment.'); redirect('?page=users');
        }
        $stmt = db()->prepare('UPDATE users SET role=? WHERE id=? AND id<>?');
        $stmt->execute([$role,$id,$actor['id']]);
        audit((int)$actor['id'],'user_role_changed','user',$id,'role='.$role);
        flash('success','Role updated.');
        redirect('?page=users');
    }

    if ($action === 'mark_read') {
        $u = require_login();
        db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$u['id']]);
        redirect('?page=notifications');
    }

    if ($action === 'contact_college') {
        $actor = require_roles(['student']);
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 160 || $message === '' || mb_strlen($message) > 5000) {
            flash('danger', 'Enter a subject and a message of no more than 5,000 characters.');
            redirect('?page=contact');
        }
        db()->beginTransaction();
        try {
            $stmt = db()->prepare("INSERT INTO college_support_requests (user_id,subject,status) VALUES (?,?,'Open')");
            $stmt->execute([$actor['id'], $subject]);
            $requestId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO college_support_messages (request_id,user_id,message) VALUES (?,?,?)')->execute([$requestId,$actor['id'],$message]);
            $recipients = db()->query("SELECT id FROM users WHERE status='Approved' AND role IN ('admin','principal')")->fetchAll();
            $notification = db()->prepare('INSERT INTO notifications (user_id,support_request_id,title,message) VALUES (?,?,?,?)');
            foreach ($recipients as $recipient) {
                $notification->execute([(int)$recipient['id'],$requestId,'Student contacted the college',$actor['name'] . ' sent a message: ' . $subject]);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        audit((int)$actor['id'], 'college_contact_created', 'support_request', $requestId);
        flash('success', 'Your message was sent to the college team.');
        redirect('?page=contact&id=' . $requestId);
    }

    if ($action === 'reply_college_contact') {
        $actor = require_login();
        if (!in_array($actor['role'], ['student','principal','admin'], true)) {
            http_response_code(403);
            exit('You do not have permission to reply to college contact requests.');
        }
        $requestId = (int)($_POST['request_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 5000) {
            flash('danger', 'Enter a message of no more than 5,000 characters.');
            redirect(($actor['role'] === 'student' ? '?page=contact&id=' : '?page=college_inbox&id=') . $requestId);
        }
        $requestQuery = db()->prepare('SELECT id,user_id,subject FROM college_support_requests WHERE id=?');
        $requestQuery->execute([$requestId]);
        $request = $requestQuery->fetch();
        if (!$request || ($actor['role'] === 'student' && (int)$request['user_id'] !== (int)$actor['id'])) {
            http_response_code(404);
            exit('College contact request not found or access denied.');
        }
        $isStaff = in_array($actor['role'], ['principal','admin'], true);
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO college_support_messages (request_id,user_id,message) VALUES (?,?,?)')->execute([$requestId,$actor['id'],$message]);
            db()->prepare('UPDATE college_support_requests SET status=? WHERE id=?')->execute([$isStaff ? 'Answered' : 'Open',$requestId]);
            $recipientId = $isStaff ? (int)$request['user_id'] : null;
            if ($recipientId !== null && $recipientId !== (int)$actor['id']) {
                db()->prepare('INSERT INTO notifications (user_id,support_request_id,title,message) VALUES (?,?,?,?)')
                    ->execute([$recipientId,$requestId,'College team replied','There is a new reply to "' . $request['subject'] . '".']);
            } elseif (!$isStaff) {
                $recipients = db()->query("SELECT id FROM users WHERE status='Approved' AND role IN ('admin','principal')")->fetchAll();
                $notification = db()->prepare('INSERT INTO notifications (user_id,support_request_id,title,message) VALUES (?,?,?,?)');
                foreach ($recipients as $recipient) {
                    $notification->execute([(int)$recipient['id'],$requestId,'Student replied to the college','There is a new reply to "' . $request['subject'] . '".']);
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        audit((int)$actor['id'], 'college_contact_replied', 'support_request', $requestId);
        flash('success', $isStaff ? 'Your reply was sent to the student.' : 'Your reply was sent to the college team.');
        redirect(($isStaff ? '?page=college_inbox&id=' : '?page=contact&id=') . $requestId);
    }

    if ($action === 'add_category') {
        require_roles(['admin']);
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($name === '' || mb_strlen($name)>100) { flash('danger','Enter a category name.'); redirect('?page=categories'); }
        $sensitive = !empty($_POST['is_sensitive']) ? 1 : 0;
        try {
            db()->prepare('INSERT INTO complaint_categories (name,description,is_sensitive) VALUES (?,?,?)')->execute([$name,$desc ?: null,$sensitive]);
            flash('success','Category added.');
        } catch (PDOException $e) { flash('danger','Category already exists or could not be added.'); }
        redirect('?page=categories');
    }

    if ($action === 'toggle_category_sensitive') {
        require_roles(['admin']);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $sensitive = (int)($_POST['is_sensitive'] ?? -1);
        if ($sensitive !== 0 && $sensitive !== 1) {
            flash('danger', 'Invalid privacy setting.');
            redirect('?page=categories');
        }
        $stmt = db()->prepare('UPDATE complaint_categories SET is_sensitive=? WHERE id=?');
        $stmt->execute([$sensitive, $categoryId]);
        audit((int)$u['id'], 'category_sensitivity_changed', 'category', $categoryId, 'is_sensitive=' . $sensitive);
        flash('success', 'Category privacy setting updated. This affects new complaints only.');
        redirect('?page=categories');
    }
}

$u = current_user();
if (!$u && !in_array($page,$publicPages,true)) redirect('?page=login');
if ($u && in_array($page,$publicPages,true)) redirect('?page=dashboard');

$flash = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);
$appName = $config['app_name'] ?? 'CampusCare';
$unreadCount = 0;
if ($u) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $stmt->execute([$u['id']]);
    $unreadCount = (int)$stmt->fetchColumn();
}

function page_title(string $page): string {
    return match($page) {
        'login'=>'Sign in','register'=>'Create account','dashboard'=>'Dashboard',
        'new_complaint'=>'New complaint','complaints'=>'Complaints','complaint'=>'Complaint details',
        'users'=>'User management','notifications'=>'Notifications','reports'=>'Reports',
        'college_inbox'=>'College inbox','contact'=>'Contact the college',
        'categories'=>'Categories','audit'=>'Audit log','profile'=>'My profile','help'=>'Help & FAQ',default=>'CampusCare'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<script>
try {
    const theme = localStorage.getItem('campuscare-theme');
    if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
} catch (error) {
    console.warn('Unable to load the saved theme preference.', error);
}
</script>
<title><?= e(page_title($page)) ?> · <?= e($appName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js" defer></script>
</head>
<body class="<?= $u ? 'app-shell' : 'auth-shell' ?>">
<?php if ($u): ?>
<aside class="sidebar">
  <a class="brand" href="?page=dashboard"><span class="brand-mark">C</span><span>CampusCare<small>Student services portal</small></span></a>
  <div class="nav-label">WORKSPACE</div>
  <a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard">◫ <span>Dashboard</span></a>
  <a class="nav-link <?= in_array($page,['complaints','complaint'])?'active':'' ?>" href="?page=complaints">▤ <span>Complaints</span></a>
  <?php if ($u['role']==='student'): ?><a class="nav-link" href="?page=new_complaint">＋ <span>Submit complaint</span></a><?php endif; ?>
  <a class="nav-link <?= $page==='notifications'?'active':'' ?>" href="?page=notifications">♧ <span>Notifications</span><?php if($unreadCount):?><span class="nav-badge"><?= $unreadCount>99?'99+':$unreadCount ?></span><?php endif;?></a>
  <?php if($u['role']==='student'):?><a class="nav-link <?= $page==='contact'?'active':'' ?>" href="?page=contact">✉ <span>Contact college</span></a><?php endif;?>
  <a class="nav-link <?= $page==='help'?'active':'' ?>" href="?page=help">? <span>Help & FAQ</span></a>
  <?php if (in_array($u['role'],['admin','principal'],true)): ?>
    <div class="nav-label">ADMINISTRATION</div>
    <a class="nav-link <?= $page==='users'?'active':'' ?>" href="?page=users">♙ <span>User management</span></a>
    <a class="nav-link <?= $page==='reports'?'active':'' ?>" href="?page=reports">▥ <span>Reports & export</span></a>
    <a class="nav-link <?= $page==='college_inbox'?'active':'' ?>" href="?page=college_inbox">✉ <span>College inbox</span></a>
  <?php endif; ?>
  <?php if ($u['role']==='admin'): ?>
    <a class="nav-link <?= $page==='categories'?'active':'' ?>" href="?page=categories">▦ <span>Categories</span></a>
    <a class="nav-link <?= $page==='audit'?'active':'' ?>" href="?page=audit">◷ <span>Audit log</span></a>
  <?php endif; ?>
  <div class="sidebar-bottom">
    <div class="user-mini"><div class="avatar"><?= e(strtoupper(substr($u['name'],0,1))) ?></div><div><strong><?= e($u['name']) ?></strong><small><?= e(ucfirst($u['role'])) ?></small></div></div>
    <form method="post" class="logout-form"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="nav-link logout" type="submit">↪ <span>Sign out</span></button></form>
  </div>
</aside>
<main class="main-area">
<header class="topbar">
  <div><span class="eyebrow">CAMPUSCARE / <?= e(strtoupper(page_title($page))) ?></span><h1><?= e(page_title($page)) ?></h1></div>
  <div class="topbar-right">
    <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false"><span class="theme-toggle-icon" aria-hidden="true">☾</span><span class="theme-toggle-label">Dark mode</span></button>
    <span class="date-chip"><?= e(date('D, d M Y')) ?></span>
    <a class="avatar avatar-link" href="?page=profile" title="My profile"><?= e(strtoupper(substr($u['name'],0,1))) ?></a>
  </div>
</header>
<div class="content">
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<?php
if ($page === 'dashboard') {
    [$scope, $scopeParams] = complaint_scope($u);
    $q = db()->prepare("SELECT COUNT(*) total, SUM(c.status='Pending') pending, SUM(c.status='In Progress') progress, SUM(c.status='Resolved') resolved, SUM(c.status NOT IN ('Resolved','Rejected') AND ((c.first_responded_at IS NULL AND c.first_response_due_at<NOW()) OR c.resolution_due_at<NOW())) overdue FROM complaints c WHERE " . $scope);
    $q->execute($scopeParams);
    $stats=$q->fetch();
    $recentLimit = $u['role'] === 'student' ? 6 : 8;
    $sql="SELECT c.*,cat.name category,owner.name owner_name,assignee.name assignee_name FROM complaints c JOIN users owner ON owner.id=c.user_id LEFT JOIN complaint_categories cat ON cat.id=c.category_id LEFT JOIN users assignee ON assignee.id=c.assigned_admin_id WHERE " . $scope . " ORDER BY c.created_at DESC LIMIT " . $recentLimit;
    $q=db()->prepare($sql);
    $q->execute($scopeParams);
    $recent=$q->fetchAll();
    ?>
    <section class="welcome-row"><div><p class="muted">Welcome back, <?= e($u['name']) ?>.</p><h2><?= $u['role']==='student'?'Here’s the status of your requests.':'Here’s what needs your attention today.' ?></h2></div><?php if ($u['role']==='student'): ?><a class="btn btn-primary" href="?page=new_complaint">＋ New complaint</a><?php endif; ?></section>
    <?php if($u['role']==='student'):?><section class="college-card"><div class="college-card-mark" aria-hidden="true">✦</div><div class="college-card-content"><span class="college-kicker">YOUR CAMPUS · STUDENT SERVICES</span><h2><?=e($config['college_name'])?></h2><div class="college-facts"><span>⌖ <?=e($config['college_address'])?></span><span>◷ Office hours: <?=e($config['college_office_hours'])?></span></div></div><div class="college-card-actions"><a class="btn btn-college" href="https://www.google.com/maps/search/?api=1&amp;query=<?=rawurlencode($config['college_name'].' '.$config['college_address'])?>" target="_blank" rel="noopener noreferrer">Get directions ↗</a><a class="college-contact-link" href="?page=contact">Contact the college team →</a></div></section><?php endif;?>
    <div class="stats-grid">
      <div class="stat-card"><span class="stat-icon purple">▤</span><span class="stat-label">Total complaints</span><strong><?= (int)($stats['total']??0) ?></strong><small>All recorded requests</small></div>
      <div class="stat-card"><span class="stat-icon amber">◷</span><span class="stat-label">Pending</span><strong><?= (int)($stats['pending']??0) ?></strong><small>Awaiting review</small></div>
      <div class="stat-card"><span class="stat-icon blue">↻</span><span class="stat-label">In progress</span><strong><?= (int)($stats['progress']??0) ?></strong><small>Being handled</small></div>
      <div class="stat-card"><span class="stat-icon green">✓</span><span class="stat-label">Resolved</span><strong><?= (int)($stats['resolved']??0) ?></strong><small>Completed requests</small></div>
      <div class="stat-card"><span class="stat-icon amber">!</span><span class="stat-label">Overdue</span><strong><?= (int)($stats['overdue']??0) ?></strong><small>Past their response or resolution target</small></div>
    </div>
    <section class="panel"><div class="panel-head"><div><h2>Recent complaints</h2><p>Latest activity across your workspace</p></div><a class="text-link" href="?page=complaints">View all →</a></div>
    <?php if (!$recent): ?><div class="empty-state"><div class="empty-icon">▤</div><h3>No complaints yet</h3><p>When complaints are submitted, they will appear here.</p><?php if($u['role']==='student'):?><a class="btn btn-primary" href="?page=new_complaint">Submit your first complaint</a><?php endif;?></div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>Ticket / subject</th><?php if($u['role']!=='student'):?><th>Submitted by</th><?php endif;?><th>Category</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead><tbody>
    <?php foreach($recent as $r): ?><tr><td><a class="ticket-link" href="?page=complaint&id=<?= (int)$r['id'] ?>"><?= e($r['ticket_no']) ?><small><?= e($r['subject']) ?></small></a></td><?php if($u['role']!=='student'):?><td><?= e($r['owner_name']) ?></td><?php endif;?><td><?= e($r['category']??'—') ?></td><td><span class="priority priority-<?= strtolower(e($r['priority'])) ?>"><?= e($r['priority']) ?></span></td><td><span class="status status-<?= status_class($r['status']) ?>"><?= e($r['status']) ?></span></td><td><?= e(date('d M Y',strtotime($r['created_at']))) ?></td></tr><?php endforeach;?>
    </tbody></table></div><?php endif; ?></section>
    <?php if (in_array($u['role'], ['admin','principal'], true)): ?>
      <?php
      $workload = db()->query("SELECT staff.id,staff.name,staff.role,COUNT(c.id) open_cases FROM users staff LEFT JOIN complaints c ON c.assigned_admin_id=staff.id AND c.status NOT IN ('Resolved','Rejected')" . ($u['role'] === 'principal' ? ' AND c.is_sensitive=0' : '') . " WHERE staff.role IN ('teacher','principal','admin') AND staff.status='Approved' GROUP BY staff.id,staff.name,staff.role ORDER BY open_cases DESC,staff.name LIMIT 12")->fetchAll();
      ?>
      <section class="panel"><div class="panel-head"><div><h2>Staff workload</h2><p>Open cases assigned to each approved staff member.</p></div></div><?php if($workload):?><div class="workload-list"><?php foreach($workload as $staff):?><div class="workload-row"><span><?=e($staff['name'])?><small><?=e(ucfirst($staff['role']))?></small></span><strong><?= (int)$staff['open_cases'] ?> open</strong></div><?php endforeach;?></div><?php else:?><p class="muted">No approved staff accounts yet.</p><?php endif;?></section>
    <?php endif;?>
    <?php
} elseif ($page === 'login') { ?>
<div class="auth-card"><div class="auth-logo"><span class="brand-mark">C</span></div><p class="eyebrow">WELCOME BACK</p><h2>Sign in to CampusCare</h2><p class="muted">Access your college support workspace.</p><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Email address<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary btn-full" type="submit">Sign in →</button></form><p class="auth-foot">New student? <a href="?page=register">Create an account</a></p></div>
<?php } elseif ($page === 'register') { ?>
<div class="auth-card auth-card-wide"><div class="auth-logo"><span class="brand-mark">C</span></div><p class="eyebrow">STUDENT ONBOARDING</p><h2>Create your account</h2><p class="muted">Your account will be reviewed by the college team.</p><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="register"><div class="form-grid"><label>Full name<input name="name" maxlength="120" required></label><label>College email<input type="email" name="email" maxlength="190" required></label><label>Roll number (optional)<input name="roll_number" maxlength="50"></label><label>Department<input name="department" maxlength="100" required placeholder="e.g. BCA"></label><label>Year<select name="year"><option value="">Select year</option><option value="1">First year</option><option value="2">Second year</option><option value="3">Third year</option><option value="4">Fourth year</option></select></label><label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"><small>Use at least 10 characters.</small></label></div><button class="btn btn-primary btn-full" type="submit">Submit registration</button></form><p class="auth-foot">Already registered? <a href="?page=login">Sign in</a></p></div>
<?php } elseif ($page === 'new_complaint') {
    require_roles(['student']);
    $cats=db()->query('SELECT id,name,is_sensitive FROM complaint_categories WHERE is_active=1 ORDER BY name')->fetchAll(); ?>
<section class="panel form-panel"><div class="panel-head"><div><h2>Tell us what happened</h2><p>Include enough detail so the right team can help.</p></div><span class="required-note">* Required fields</span></div><form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="create_complaint"><label>Complaint subject *<input name="subject" maxlength="200" required placeholder="A short summary of the issue"></label><div class="form-grid"><label>Category *<select name="category_id" required><option value="">Select category</option><?php foreach($cats as $cat):?><option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?><?= $cat['is_sensitive']?' — private':'' ?></option><?php endforeach;?></select></label><label>Priority<select name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Urgent</option></select></label></div><label>Description *<textarea name="description" rows="7" required placeholder="Explain the issue, when it happened, and what outcome would help."></textarea></label><label>Evidence (optional)<input type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,application/pdf,image/jpeg,image/png,image/gif,image/webp" multiple><small>PDF or image files only; up to 3 files, 5 MB each. Attachments are private to people allowed to view this complaint.</small></label><div class="form-actions"><a class="btn btn-secondary" href="?page=dashboard">Cancel</a><button class="btn btn-primary" type="submit">Submit complaint →</button></div></form></section>
<?php } elseif ($page === 'complaints') {
    $qtext=trim((string)($_GET['q']??''));
    $statusFilter=(string)($_GET['status']??'');
    $priorityFilter=(string)($_GET['priority']??'');
    $categoryFilter=(int)($_GET['category']??0);
    $assigneeFilter=(int)($_GET['assignee']??0);
    $departmentFilter=trim((string)($_GET['department']??''));
    $fromDate=(string)($_GET['from']??'');
    $toDate=(string)($_GET['to']??'');
    $overdueOnly=($_GET['overdue']??'')==='1';
    [$scope,$params]=complaint_scope($u);
    $conditions=[$scope];
    if (in_array($statusFilter,['Pending','In Progress','Resolved','Rejected'],true)) {
        $conditions[]='c.status=?'; $params[]=$statusFilter;
    }
    if (in_array($priorityFilter,['Low','Medium','High','Urgent'],true)) {
        $conditions[]='c.priority=?'; $params[]=$priorityFilter;
    }
    if ($categoryFilter > 0) {
        $conditions[]='c.category_id=?'; $params[]=$categoryFilter;
    }
    if ($u['role'] !== 'student' && $assigneeFilter > 0) {
        $conditions[]='c.assigned_admin_id=?'; $params[]=$assigneeFilter;
    }
    if (in_array($u['role'],['admin','principal'],true) && $departmentFilter !== '') {
        $conditions[]='c.department=?'; $params[]=$departmentFilter;
    }
    if ($qtext !== '' && mb_strlen($qtext) <= 120) {
        $conditions[]='(c.ticket_no LIKE ? OR c.subject LIKE ? OR owner.name LIKE ?)';
        array_push($params,'%'.$qtext.'%','%'.$qtext.'%','%'.$qtext.'%');
    } else {
        $qtext='';
    }
    $dateError=false;
    foreach ([['key'=>'from','value'=>$fromDate,'operator'=>'>='],['key'=>'to','value'=>$toDate,'operator'=>'<']] as $dateFilter) {
        if ($dateFilter['value'] === '') continue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFilter['value']);
        if (!$date || $date->format('Y-m-d') !== $dateFilter['value']) {
            $dateError=true;
            if ($dateFilter['key']==='from') $fromDate='';
            else $toDate='';
            continue;
        }
        $conditions[]='c.created_at ' . $dateFilter['operator'] . ' ?';
        $params[]=$dateFilter['operator'] === '<' ? $date->modify('+1 day')->format('Y-m-d') : $date->format('Y-m-d');
    }
    if ($overdueOnly) {
        $conditions[]="c.status NOT IN ('Resolved','Rejected') AND ((c.first_responded_at IS NULL AND c.first_response_due_at<NOW()) OR c.resolution_due_at<NOW())";
    }
    $where=implode(' AND ',$conditions);
    $countQuery=db()->prepare('SELECT COUNT(*) FROM complaints c JOIN users owner ON owner.id=c.user_id WHERE ' . $where);
    $countQuery->execute($params);
    $total=(int)$countQuery->fetchColumn();
    $pageNumber=max(1,(int)($_GET['p']??1));
    $pageSize=20;
    $pageCount=max(1,(int)ceil($total/$pageSize));
    $pageNumber=min($pageNumber,$pageCount);
    $offset=($pageNumber-1)*$pageSize;
    $query=db()->prepare('SELECT c.*,cat.name category,owner.name owner_name,assignee.name assignee_name FROM complaints c JOIN users owner ON owner.id=c.user_id LEFT JOIN complaint_categories cat ON cat.id=c.category_id LEFT JOIN users assignee ON assignee.id=c.assigned_admin_id WHERE ' . $where . ' ORDER BY c.created_at DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset);
    $query->execute($params);
    $rows=$query->fetchAll();
    $categories=db()->query('SELECT id,name FROM complaint_categories WHERE is_active=1 ORDER BY name')->fetchAll();
    $assignees=[];
    $departments=[];
    if ($u['role'] !== 'student') {
        $assignees=db()->query("SELECT id,name FROM users WHERE status='Approved' AND role IN ('teacher','principal','admin') ORDER BY name")->fetchAll();
    }
    if (in_array($u['role'],['admin','principal'],true)) {
        $departmentSql='SELECT DISTINCT department FROM complaints WHERE department IS NOT NULL AND department<>""';
        if ($u['role']==='principal') $departmentSql.=' AND is_sensitive=0';
        $departmentSql.=' ORDER BY department';
        $departments=db()->query($departmentSql)->fetchAll(PDO::FETCH_COLUMN);
    }
    ?>
<section class="panel"><div class="panel-head"><div><h2>Complaint register</h2><p>Search, filter, and review cases you are allowed to access.</p></div><?php if($u['role']==='student'):?><a class="btn btn-primary" href="?page=new_complaint">＋ New complaint</a><?php endif;?></div>
<?php if($dateError):?><div class="alert alert-warning">One of the date filters was invalid and has been ignored.</div><?php endif;?>
<form class="filters filters-expanded" method="get">
  <input type="hidden" name="page" value="complaints">
  <input name="q" value="<?=e($qtext)?>" maxlength="120" placeholder="Search ticket, subject, or student">
  <select name="status"><option value="">All statuses</option><?php foreach(['Pending','In Progress','Resolved','Rejected'] as $option):?><option value="<?=e($option)?>" <?=$statusFilter===$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select>
  <select name="priority"><option value="">All priorities</option><?php foreach(['Low','Medium','High','Urgent'] as $option):?><option value="<?=e($option)?>" <?=$priorityFilter===$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select>
  <select name="category"><option value="">All categories</option><?php foreach($categories as $category):?><option value="<?=(int)$category['id']?>" <?=$categoryFilter===(int)$category['id']?'selected':''?>><?=e($category['name'])?></option><?php endforeach;?></select>
  <?php if($u['role']!=='student'):?><select name="assignee"><option value="">All assignees</option><?php foreach($assignees as $assignee):?><option value="<?=(int)$assignee['id']?>" <?=$assigneeFilter===(int)$assignee['id']?'selected':''?>><?=e($assignee['name'])?></option><?php endforeach;?></select><?php endif;?>
  <?php if($departments):?><select name="department"><option value="">All departments</option><?php foreach($departments as $department):?><option value="<?=e($department)?>" <?=$departmentFilter===$department?'selected':''?>><?=e($department)?></option><?php endforeach;?></select><?php endif;?>
  <label class="date-filter">From<input type="date" name="from" value="<?=e($fromDate)?>"></label>
  <label class="date-filter">To<input type="date" name="to" value="<?=e($toDate)?>"></label>
  <label class="check-filter"><input type="checkbox" name="overdue" value="1" <?=$overdueOnly?'checked':''?>> Overdue only</label>
  <button class="btn btn-secondary" type="submit">Apply filters</button>
  <a class="btn btn-secondary" href="?page=complaints">Clear</a>
</form>
<p class="muted results-count">Showing <?= $total ? $offset+1 : 0 ?>–<?= min($offset+$pageSize,$total) ?> of <?= $total ?> complaints</p>
<div class="table-wrap"><table><thead><tr><th>Ticket / subject</th><?php if($u['role']!=='student'):?><th>Student</th><?php endif;?><th>Category</th><th>Priority</th><th>Status / SLA</th><th>Assignee</th><th>Last updated</th></tr></thead><tbody>
<?php foreach($rows as $r):$sla=complaint_sla_state($r);?><tr><td><a class="ticket-link" href="?page=complaint&id=<?=(int)$r['id']?>"><?=e($r['ticket_no'])?><small><?=e($r['subject'])?></small></a><?php if($r['is_sensitive']):?><span class="status status-warning">Private</span><?php endif;?></td><?php if($u['role']!=='student'):?><td><?=e($r['owner_name'])?></td><?php endif;?><td><?=e($r['category']??'—')?></td><td><span class="priority priority-<?=strtolower(e($r['priority']))?>"><?=e($r['priority'])?></span></td><td><span class="status status-<?=status_class($r['status'])?>"><?=e($r['status'])?></span><?php if($sla['overdue']):?><small class="sla-overdue">Overdue</small><?php endif;?></td><td><?=e($r['assignee_name']??'Unassigned')?></td><td><?=e(date('d M Y',strtotime($r['updated_at'])))?></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if(!$rows):?><div class="empty-state"><h3>No matching complaints</h3><p>Try changing your filters or search.</p></div><?php endif;?>
<?php if($pageCount>1):?><nav class="pagination" aria-label="Complaint list pages"><?php if($pageNumber>1):?><a class="btn btn-secondary" href="<?=e(query_url(['p'=>$pageNumber-1]))?>">← Previous</a><?php endif;?><span>Page <?= $pageNumber ?> of <?= $pageCount ?></span><?php if($pageNumber<$pageCount):?><a class="btn btn-secondary" href="<?=e(query_url(['p'=>$pageNumber+1]))?>">Next →</a><?php endif;?></nav><?php endif;?>
</section>
<?php } elseif ($page === 'complaint') {
    $cid=(int)($_GET['id']??0);$q=db()->prepare('SELECT c.*,cat.name category,owner.name owner_name,owner.email owner_email,assignee.name assignee_name FROM complaints c JOIN users owner ON owner.id=c.user_id LEFT JOIN complaint_categories cat ON cat.id=c.category_id LEFT JOIN users assignee ON assignee.id=c.assigned_admin_id WHERE c.id=?');$q->execute([$cid]);$c=$q->fetch();
    if(!$c || !can_view_complaint($u,$c)) { http_response_code(404); echo '<div class="panel">Complaint not found or access denied.</div>'; }
    else {
    $q=db()->prepare('SELECT m.*,u.name,u.role FROM complaint_messages m JOIN users u ON u.id=m.user_id WHERE m.complaint_id=? ORDER BY m.created_at');$q->execute([$cid]);$messages=$q->fetchAll();
    $timelineQuery=db()->prepare('SELECT t.*,u.name actor_name FROM complaint_timeline t LEFT JOIN users u ON u.id=t.actor_id WHERE t.complaint_id=? ORDER BY t.created_at,t.id');$timelineQuery->execute([$cid]);$timeline=$timelineQuery->fetchAll();
    $attachmentQuery=db()->prepare('SELECT a.*,u.name uploader_name FROM complaint_attachments a JOIN users u ON u.id=a.uploaded_by WHERE a.complaint_id=? ORDER BY a.created_at');$attachmentQuery->execute([$cid]);$attachments=$attachmentQuery->fetchAll();
    $feedbackQuery=db()->prepare('SELECT * FROM complaint_feedback WHERE complaint_id=?');$feedbackQuery->execute([$cid]);$feedback=$feedbackQuery->fetch();
    $staff=[];
    if(in_array($u['role'],['principal','admin'],true)) {
        $staffRoles = !empty($c['is_sensitive'])
            ? "AND role='admin' "
            : ($u['role']==='principal' ? "AND role<>'admin' " : '');
        $staffQuery=db()->prepare("SELECT id,name,role FROM users WHERE role IN ('teacher','principal','admin') AND status='Approved' " . $staffRoles . 'ORDER BY name');
        $staffQuery->execute();$staff=$staffQuery->fetchAll();
    }
    $sla=complaint_sla_state($c);
    ?>
<div class="detail-grid"><div class="detail-main"><section class="panel"><div class="detail-ticket"><span class="ticket-code"><?= e($c['ticket_no']) ?></span><span class="status status-<?= status_class($c['status']) ?>"><?= e($c['status']) ?></span></div><h2 class="detail-title"><?= e($c['subject']) ?></h2><p class="muted">Submitted by <?= e($c['owner_name']) ?> · <?= e(date('d M Y, h:i A',strtotime($c['created_at']))) ?><?php if($c['is_sensitive']):?> · <span class="private-label">Private complaint</span><?php endif;?></p><div class="detail-description"><?= nl2br(e($c['description'])) ?></div><div class="detail-meta"><div><small>Category</small><strong><?= e($c['category']??'—') ?></strong></div><div><small>Priority</small><strong><?= e($c['priority']) ?></strong></div><div><small>Department</small><strong><?= e($c['department']??'—') ?></strong></div><div><small>Assigned to</small><strong><?= e($c['assignee_name']??'Not assigned') ?></strong></div></div><?php if($c['resolution_note']):?><div class="resolution-note"><strong>Resolution / staff note</strong><p><?= nl2br(e($c['resolution_note'])) ?></p></div><?php endif;?></section>
<?php if($attachments):?><section class="panel"><div class="panel-head"><div><h2>Attachments</h2><p>Private evidence attached to this case.</p></div></div><div class="attachment-list"><?php foreach($attachments as $attachment):?><a class="attachment-row" href="?page=attachment&id=<?=(int)$attachment['id']?>"><span>▧ <?=e($attachment['original_name'])?></span><small><?=e(format_file_size((int)$attachment['file_size']))?> · <?=e($attachment['uploader_name'])?></small></a><?php endforeach;?></div></section><?php endif;?>
<section class="panel"><div class="panel-head"><div><h2>Case timeline</h2><p>Status, ownership, and discussion activity.</p></div></div><ol class="timeline"><?php foreach($timeline as $event):?><li><span class="timeline-dot"></span><div><strong><?=e(ucfirst(str_replace('_',' ',$event['event_type'])))?></strong><p><?=e($event['details']??'')?></p><small><?=e($event['actor_name']??'System')?> · <?=e(date('d M Y, h:i A',strtotime($event['created_at'])))?></small></div></li><?php endforeach;?></ol></section>
<?php if($feedback && in_array($u['role'],['admin','principal'],true)):?><section class="panel"><div class="panel-head"><div><h2>Student feedback</h2><p>Submitted <?=e(date('d M Y',strtotime($feedback['updated_at'])))?></p></div><span class="role-tag"><?= (int)$feedback['rating'] ?>/5</span></div><?php if($feedback['comment']):?><p class="muted"><?=nl2br(e($feedback['comment']))?></p><?php else:?><p class="muted">No written comment was added.</p><?php endif;?></section><?php endif;?>
<section class="panel"><div class="panel-head"><div><h2>Discussion</h2><p>Keep all updates in one place.</p></div></div><div class="thread"><?php foreach($messages as $m):?><div class="message"><div class="message-head"><strong><?= e($m['name']) ?></strong><span><?= e(ucfirst($m['role'])) ?> · <?= e(date('d M, h:i A',strtotime($m['created_at']))) ?></span></div><p><?= nl2br(e($m['message'])) ?></p></div><?php endforeach;?><?php if(!$messages):?><p class="muted">No messages yet. Start the discussion below.</p><?php endif;?></div><form method="post" class="form-stack message-form"><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="complaint_id" value="<?= $cid ?>"><label>Write a message<textarea name="message" rows="3" maxlength="5000" required placeholder="Add a clarification or response…"></textarea></label><button class="btn btn-primary" type="submit">Send message</button></form></section></div>
<aside class="detail-side"><section class="panel"><h3>Complaint details</h3><div class="side-pair"><span>Ticket ID</span><strong><?= e($c['ticket_no']) ?></strong></div><div class="side-pair"><span>Created</span><strong><?= e(date('d M Y',strtotime($c['created_at']))) ?></strong></div><div class="side-pair"><span>Updated</span><strong><?= e(date('d M Y',strtotime($c['updated_at']))) ?></strong></div><div class="side-pair"><span>Response target</span><strong><?= $c['first_responded_at']?'Met':e($c['first_response_due_at']?date('d M Y',strtotime($c['first_response_due_at'])):'Not set') ?></strong></div><div class="side-pair"><span>Resolution target</span><strong><?= in_array($c['status'],['Resolved','Rejected'],true)?'Closed':e($c['resolution_due_at']?date('d M Y',strtotime($c['resolution_due_at'])):'Not set') ?></strong></div><?php if($sla['overdue']):?><p class="sla-overdue">This case is past its SLA target.</p><?php endif;?><?php if($u['role']!=='student' && (empty($c['is_sensitive']) || $u['role']==='admin')):?><hr><h3>Manage complaint</h3><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="update_complaint"><input type="hidden" name="complaint_id" value="<?= $cid ?>"><label>Status<select name="status"><?php foreach(['Pending','In Progress','Resolved','Rejected'] as $s):?><option <?= $c['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach;?></select></label><label>Priority<select name="priority"><?php foreach(['Low','Medium','High','Urgent'] as $s):?><option <?= $c['priority']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach;?></select></label><?php if($u['role']!=='teacher'):?><label>Assign staff<select name="assigned_admin_id"><option value="0">Unassigned</option><?php if($u['role']==='principal' && $c['assignee_name'] && (int)$c['assigned_admin_id']>0 && !array_filter($staff,static fn(array $member):bool => (int)$member['id']===(int)$c['assigned_admin_id'])):?><option value="<?=$c['assigned_admin_id']?>" selected disabled><?=e($c['assignee_name'].' (Admin)')?></option><?php endif;?><?php foreach($staff as $s):?><option value="<?= (int)$s['id'] ?>" <?= (int)$c['assigned_admin_id']===(int)$s['id']?'selected':'' ?>><?= e($s['name'].' ('.$s['role'].')') ?></option><?php endforeach;?></select></label><?php endif;?><label>Resolution note<textarea name="resolution_note" rows="3" maxlength="5000"><?= e($c['resolution_note']??'') ?></textarea></label><button class="btn btn-primary btn-full" type="submit">Save changes</button></form><?php endif;?></section>
<?php if($u['role']==='student' && (int)$c['user_id']===(int)$u['id'] && $c['status']==='Resolved'):?><section class="panel"><h3>Rate the resolution</h3><?php if($feedback):?><p class="muted">Your current rating: <?= (int)$feedback['rating'] ?>/5</p><?php endif;?><form method="post" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="submit_feedback"><input type="hidden" name="complaint_id" value="<?=$cid?>"><label>Rating<select name="rating" required><?php for($rating=5;$rating>=1;$rating--):?><option value="<?=$rating?>" <?=$feedback && (int)$feedback['rating']===$rating?'selected':''?>><?=$rating?> / 5</option><?php endfor;?></select></label><label>Comment (optional)<textarea name="comment" maxlength="1000"><?=e($feedback['comment']??'')?></textarea></label><button class="btn btn-secondary" type="submit">Save feedback</button></form></section><?php endif;?></aside></div>
<?php }} elseif ($page === 'users') {
    require_roles(['admin','principal']);
    $rows=db()->query('SELECT id,name,email,role,status,department,roll_number,created_at FROM users ORDER BY created_at DESC LIMIT 300')->fetchAll(); ?>
<section class="panel"><div class="panel-head"><div><h2>People & access</h2><p>Review registrations and manage campus roles.</p></div></div><div class="table-wrap"><table><thead><tr><th>Name / email</th><th>Department / roll</th><th>Role</th><th>Account status</th><th>Joined</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?= e($r['name']) ?></strong><small class="table-sub"><?= e($r['email']) ?></small></td><td><?= e($r['department']??'—') ?><small class="table-sub"><?= e($r['roll_number']??'') ?></small></td><td><span class="role-tag"><?= e(ucfirst($r['role'])) ?></span></td><td><span class="status status-<?= status_class($r['status']) ?>"><?= e($r['status']) ?></span></td><td><?= e(date('d M Y',strtotime($r['created_at']))) ?></td><td><div class="action-stack"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_status"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>"><select name="status" aria-label="Account status"><?php foreach(['Pending','Approved','Rejected'] as $s):?><option <?= $r['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach;?></select><button class="btn btn-small btn-secondary">Save</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_user"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>"><select name="role" aria-label="User role"><?php foreach(['student','teacher','principal','admin'] as $role): if($u['role']==='principal' && $role==='admin') continue;?><option <?= $r['role']===$role?'selected':'' ?>><?= e($role) ?></option><?php endforeach;?></select><button class="btn btn-small btn-secondary">Role</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section>
<?php } elseif ($page === 'notifications') {
    $q=db()->prepare('SELECT n.*,c.ticket_no FROM notifications n LEFT JOIN complaints c ON c.id=n.complaint_id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 100');$q->execute([$u['id']]);$rows=$q->fetchAll(); ?>
<section class="panel"><div class="panel-head"><div><h2>Your notifications</h2><p>Updates related to your account and complaints.</p></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_read"><button class="btn btn-secondary">Mark all read</button></form></div><?php foreach($rows as $n):$notificationHref=$n['support_request_id']?($u['role']==='student'?'?page=contact&id='.(int)$n['support_request_id']:'?page=college_inbox&id='.(int)$n['support_request_id']):($n['complaint_id']?'?page=complaint&id='.(int)$n['complaint_id']:'#');?><a class="notification-row <?= $n['is_read']?'':'unread' ?>" href="<?=e($notificationHref)?>"><span class="notification-dot"></span><span><strong><?= e($n['title']) ?></strong><p><?= e($n['message']) ?></p><small><?= e(date('d M Y, h:i A',strtotime($n['created_at']))) ?></small></span></a><?php endforeach;?><?php if(!$rows):?><div class="empty-state"><h3>You're all caught up</h3><p>New updates will appear here.</p></div><?php endif;?></section>
<?php } elseif ($page === 'contact') {
    require_roles(['student']);
    $requestId=(int)($_GET['id']??0);
    $requestsQuery=db()->prepare('SELECT id,subject,status,created_at,updated_at FROM college_support_requests WHERE user_id=? ORDER BY updated_at DESC LIMIT 50');
    $requestsQuery->execute([$u['id']]);$requests=$requestsQuery->fetchAll();
    $activeRequest=null;$messages=[];
    if($requestId>0) {
        $requestQuery=db()->prepare('SELECT id,subject,status,created_at,updated_at FROM college_support_requests WHERE id=? AND user_id=?');
        $requestQuery->execute([$requestId,$u['id']]);$activeRequest=$requestQuery->fetch();
        if(!$activeRequest) { http_response_code(404); }
        else {
            $messagesQuery=db()->prepare('SELECT m.*,sender.name,sender.role FROM college_support_messages m JOIN users sender ON sender.id=m.user_id WHERE m.request_id=? ORDER BY m.created_at,m.id');
            $messagesQuery->execute([$requestId]);$messages=$messagesQuery->fetchAll();
        }
    }
?>
<section class="college-hero"><span class="college-kicker">STUDENT SUPPORT</span><h2><?=e($config['college_name'])?></h2><p>Have a question about campus services? Send a private message to the college team and follow the reply here.</p><div class="college-facts"><span>⌖ <?=e($config['college_address'])?></span><span>◷ Office hours: <?=e($config['college_office_hours'])?></span><a href="https://www.google.com/maps/search/?api=1&amp;query=<?=rawurlencode($config['college_name'].' '.$config['college_address'])?>" target="_blank" rel="noopener noreferrer">Open campus map ↗</a></div></section>
<div class="detail-grid"><div class="detail-main">
<?php if($requestId>0 && $activeRequest):?><section class="panel"><div class="panel-head"><div><h2><?=e($activeRequest['subject'])?></h2><p>Started <?=e(date('d M Y, h:i A',strtotime($activeRequest['created_at'])))?></p></div><span class="status status-<?= $activeRequest['status']==='Answered'?'success':'warning'?>"><?=e($activeRequest['status'])?></span></div><div class="thread"><?php foreach($messages as $message):?><article class="message"><div class="message-head"><strong><?=e($message['name'])?> <span class="role-tag"><?=e(ucfirst($message['role']))?></span></strong><span><?=e(date('d M Y, h:i A',strtotime($message['created_at'])))?></span></div><p><?=nl2br(e($message['message']))?></p></article><?php endforeach;?></div><form method="post" class="form-stack message-form"><?=csrf_field()?><input type="hidden" name="action" value="reply_college_contact"><input type="hidden" name="request_id" value="<?=$requestId?>"><label>Reply to the college team<textarea name="message" rows="4" maxlength="5000" required placeholder="Add any details or follow-up questions…"></textarea></label><button class="btn btn-primary" type="submit">Send reply</button></form></section>
<?php elseif($requestId>0):?><section class="panel"><h2>Contact request not found</h2><p class="muted">This request may have been removed or does not belong to your account.</p></section>
<?php else:?><section class="panel"><div class="panel-head"><div><h2>Send a message</h2><p>Your message goes directly to the approved college administration team.</p></div></div><form method="post" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="contact_college"><label>Subject<input name="subject" maxlength="160" required placeholder="What can the college team help with?"></label><label>Message<textarea name="message" rows="6" maxlength="5000" required placeholder="Write your question or request…"></textarea></label><button class="btn btn-primary" type="submit">Send to college team →</button></form></section><?php endif;?></div>
<aside class="detail-side"><section class="panel"><div class="panel-head"><div><h2>Your conversations</h2><p>Private messages with the college.</p></div><?php if($requestId):?><a class="btn btn-small btn-secondary" href="?page=contact">New</a><?php endif;?></div><?php foreach($requests as $request):?><a class="support-request-row <?=$requestId===(int)$request['id']?'selected':''?>" href="?page=contact&id=<?=(int)$request['id']?>"><strong><?=e($request['subject'])?></strong><small><?=e(date('d M Y',strtotime($request['updated_at'])))?></small><span class="status status-<?=$request['status']==='Answered'?'success':'warning'?>"><?=e($request['status'])?></span></a><?php endforeach;?><?php if(!$requests):?><p class="muted">Your conversations will appear here after you contact the college.</p><?php endif;?></section></aside></div>
<?php } elseif ($page === 'college_inbox') {
    require_roles(['admin','principal']);
    $requestId=(int)($_GET['id']??0);
    $requestsQuery=db()->prepare('SELECT r.*,u.name student_name,u.email student_email FROM college_support_requests r JOIN users u ON u.id=r.user_id ORDER BY r.updated_at DESC LIMIT 100');
    $requestsQuery->execute();$requests=$requestsQuery->fetchAll();
    $activeRequest=null;$messages=[];
    if($requestId>0) {
        $requestQuery=db()->prepare('SELECT r.*,u.name student_name,u.email student_email FROM college_support_requests r JOIN users u ON u.id=r.user_id WHERE r.id=?');
        $requestQuery->execute([$requestId]);$activeRequest=$requestQuery->fetch();
        if(!$activeRequest) { http_response_code(404); }
        else {
            $messagesQuery=db()->prepare('SELECT m.*,sender.name,sender.role FROM college_support_messages m JOIN users sender ON sender.id=m.user_id WHERE m.request_id=? ORDER BY m.created_at,m.id');
            $messagesQuery->execute([$requestId]);$messages=$messagesQuery->fetchAll();
        }
    }
?>
<div class="detail-grid"><div class="detail-main"><?php if($activeRequest):?><section class="panel"><div class="panel-head"><div><h2><?=e($activeRequest['subject'])?></h2><p>From <?=e($activeRequest['student_name'])?> · <?=e($activeRequest['student_email'])?></p></div><span class="status status-<?=$activeRequest['status']==='Answered'?'success':'warning'?>"><?=e($activeRequest['status'])?></span></div><div class="thread"><?php foreach($messages as $message):?><article class="message"><div class="message-head"><strong><?=e($message['name'])?> <span class="role-tag"><?=e(ucfirst($message['role']))?></span></strong><span><?=e(date('d M Y, h:i A',strtotime($message['created_at'])))?></span></div><p><?=nl2br(e($message['message']))?></p></article><?php endforeach;?></div><form method="post" class="form-stack message-form"><?=csrf_field()?><input type="hidden" name="action" value="reply_college_contact"><input type="hidden" name="request_id" value="<?=$requestId?>"><label>Reply to student<textarea name="message" rows="4" maxlength="5000" required placeholder="Write a helpful response…"></textarea></label><button class="btn btn-primary" type="submit">Send reply</button></form></section><?php else:?><section class="panel"><h2>College contact inbox</h2><p class="muted">Select a conversation to review and reply.</p></section><?php endif;?></div><aside class="detail-side"><section class="panel"><div class="panel-head"><div><h2>Student messages</h2><p><?=count($requests)?> recent requests</p></div></div><?php foreach($requests as $request):?><a class="support-request-row <?=$requestId===(int)$request['id']?'selected':''?>" href="?page=college_inbox&id=<?=(int)$request['id']?>"><strong><?=e($request['subject'])?></strong><small><?=e($request['student_name'])?> · <?=e(date('d M Y',strtotime($request['updated_at'])))?></small><span class="status status-<?=$request['status']==='Answered'?'success':'warning'?>"><?=e($request['status'])?></span></a><?php endforeach;?><?php if(!$requests):?><p class="muted">No student messages yet.</p><?php endif;?></section></aside></div>
<?php } elseif ($page === 'reports') {
    require_roles(['admin','principal']);
    [$scope,$scopeParams]=complaint_scope($u);
    if (($_GET['export']??'')==='csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="campuscare-complaints-'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,['Ticket','Subject','Student','Department','Category','Priority','Status','Assigned staff','Created','Updated']);
        $rs=db()->prepare('SELECT c.ticket_no,c.subject,u.name,c.department,cat.name category,c.priority,c.status,a.name assignee,c.created_at,c.updated_at FROM complaints c JOIN users u ON u.id=c.user_id LEFT JOIN complaint_categories cat ON cat.id=c.category_id LEFT JOIN users a ON a.id=c.assigned_admin_id WHERE ' . $scope . ' ORDER BY c.created_at DESC');
        $rs->execute($scopeParams);
        while($r=$rs->fetch()) {
            // Prefix spreadsheet formula-like cells to reduce CSV formula injection risk.
            foreach($r as &$v) if(is_string($v) && preg_match('/^[=+\-@\t\r]/',$v)) $v="'".$v;
            unset($v); fputcsv($out,$r);
        }
        fclose($out); exit;
    }
    $countsQuery=db()->prepare('SELECT status,COUNT(*) total FROM complaints c WHERE ' . $scope . ' GROUP BY status');$countsQuery->execute($scopeParams);$counts=$countsQuery->fetchAll();
    $categoryQuery=db()->prepare('SELECT COALESCE(cat.name,"Uncategorized") category,COUNT(*) total FROM complaints c LEFT JOIN complaint_categories cat ON cat.id=c.category_id WHERE ' . $scope . ' GROUP BY category ORDER BY total DESC');$categoryQuery->execute($scopeParams);$byCat=$categoryQuery->fetchAll();
    $monthlyQuery=db()->prepare("SELECT DATE_FORMAT(c.created_at,'%Y-%m') month,COUNT(*) total FROM complaints c WHERE c.created_at>=DATE_SUB(CURRENT_DATE,INTERVAL 5 MONTH) AND " . $scope . ' GROUP BY month ORDER BY month');$monthlyQuery->execute($scopeParams);$monthly=$monthlyQuery->fetchAll();
    $maxCategory=max(1,...array_map(static fn(array $row): int => (int)$row['total'],$byCat));
    $maxMonthly=max(1,...array_map(static fn(array $row): int => (int)$row['total'],$monthly));
    $metricsQuery=db()->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR,c.created_at,c.resolved_at)) avg_resolution_hours,AVG(f.rating) avg_rating,COUNT(f.id) feedback_count,SUM(c.status NOT IN ('Resolved','Rejected') AND ((c.first_responded_at IS NULL AND c.first_response_due_at<NOW()) OR c.resolution_due_at<NOW())) overdue FROM complaints c LEFT JOIN complaint_feedback f ON f.complaint_id=c.id WHERE " . $scope);
    $metricsQuery->execute($scopeParams);$metrics=$metricsQuery->fetch(); ?>
<div class="stats-grid">
<?php foreach(['Pending','In Progress','Resolved','Rejected'] as $status):$count=0;foreach($counts as $row)if($row['status']===$status)$count=(int)$row['total'];?>
<div class="stat-card"><span class="stat-label"><?=e($status)?></span><strong><?=$count?></strong><small>Complaints</small></div><?php endforeach;?>
<div class="stat-card"><span class="stat-label">Overdue</span><strong><?=(int)($metrics['overdue']??0)?></strong><small>Past SLA targets</small></div>
<div class="stat-card"><span class="stat-label">Avg. rating</span><strong><?=$metrics['avg_rating']!==null?number_format((float)$metrics['avg_rating'],1).'/5':'—'?></strong><small><?=(int)$metrics['feedback_count']?> student ratings</small></div>
<div class="stat-card"><span class="stat-label">Avg. resolution</span><strong><?=$metrics['avg_resolution_hours']!==null?number_format((float)$metrics['avg_resolution_hours']/24,1).'d':'—'?></strong><small>Across resolved cases</small></div>
</div>
<section class="panel"><div class="panel-head"><div><h2>Complaint trends</h2><p>New cases created in the last six months.</p></div><a class="btn btn-primary" href="?page=reports&export=csv">↓ Export CSV</a></div><div class="chart-bars"><?php foreach($monthly as $month):$width=(int)round(((int)$month['total']/$maxMonthly)*100);?><div class="chart-row"><span><?=e(date('M Y',strtotime($month['month'].'-01')))?></span><div class="chart-track"><span style="width:<?=$width?>%"></span></div><strong><?=(int)$month['total']?></strong></div><?php endforeach;?><?php if(!$monthly):?><p class="muted">No report data yet.</p><?php endif;?></div></section>
<section class="panel"><div class="panel-head"><div><h2>Complaints by category</h2><p>Case volume by category.</p></div></div><div class="chart-bars"><?php foreach($byCat as $row):$width=(int)round(((int)$row['total']/$maxCategory)*100);?><div class="chart-row"><span><?=e($row['category'])?></span><div class="chart-track"><span style="width:<?=$width?>%"></span></div><strong><?=(int)$row['total']?></strong></div><?php endforeach;?><?php if(!$byCat):?><p class="muted">No report data yet.</p><?php endif;?></div></section>
<?php } elseif ($page === 'categories') {
    require_roles(['admin']);
    $rows=db()->query('SELECT * FROM complaint_categories ORDER BY name')->fetchAll(); ?>
<div class="detail-grid"><section class="panel"><div class="panel-head"><div><h2>Complaint categories</h2><p>Choose which categories need extra privacy.</p></div></div><?php foreach($rows as $r):?><div class="category-row"><div><strong><?=e($r['name'])?></strong><p><?=e($r['description']??'')?></p></div><div class="category-actions"><span class="status status-<?= $r['is_active']?'success':'muted' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle_category_sensitive"><input type="hidden" name="category_id" value="<?=(int)$r['id']?>"><input type="hidden" name="is_sensitive" value="<?=$r['is_sensitive']?0:1?>"><button class="btn btn-small <?=$r['is_sensitive']?'btn-primary':'btn-secondary'?>" type="submit"><?=$r['is_sensitive']?'Private':'Make private'?></button></form></div></div><?php endforeach;?></section><section class="panel"><h2>Add a category</h2><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="add_category"><label>Name<input name="name" maxlength="100" required></label><label>Description<textarea name="description" rows="4" maxlength="255"></textarea></label><label class="check-filter"><input type="checkbox" name="is_sensitive" value="1"> Treat new complaints in this category as private (admin and submitter only)</label><button class="btn btn-primary" type="submit">Add category</button></form></section></div>
<?php } elseif ($page === 'audit') {
    require_roles(['admin']);
    $rows=db()->query('SELECT l.*,u.name actor_name FROM audit_logs l LEFT JOIN users u ON u.id=l.actor_id ORDER BY l.created_at DESC LIMIT 300')->fetchAll(); ?>
<section class="panel"><div class="panel-head"><div><h2>Audit log</h2><p>Recent security-relevant actions.</p></div></div><div class="table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?= e(date('d M Y H:i',strtotime($r['created_at']))) ?></td><td><?= e($r['actor_name']??'System') ?></td><td><?= e($r['action']) ?></td><td><?= e(($r['entity_type']??'—').' #'.($r['entity_id']??'')) ?></td><td><?= e($r['details']??'') ?></td><td><?= e($r['ip_address']??'') ?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php } elseif ($page === 'profile') { ?>
<div class="detail-grid"><section class="panel"><div class="panel-head"><div><h2>Profile details</h2><p>Manage your contact and campus information.</p></div><span class="role-tag"><?=e(ucfirst($u['role']))?></span></div><form method="post" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="update_profile"><div class="form-grid"><label>Full name<input name="name" value="<?=e($u['name'])?>" maxlength="120" required></label><label>Email<input name="email" type="email" value="<?=e($u['email'])?>" maxlength="190" required></label><label>Department<input name="department" value="<?=e($u['department']??'')?>" maxlength="100"></label><label>Roll number<input name="roll_number" value="<?=e($u['roll_number']??'')?>" maxlength="50"></label><label>Year<input name="year" type="number" min="1" max="6" value="<?=e($u['year']??'')?>"></label></div><button class="btn btn-primary" type="submit">Save profile</button></form></section><section class="panel"><h2>Change password</h2><p class="muted">Use at least 12 characters and avoid reusing another password.</p><form method="post" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="change_password"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label><button class="btn btn-secondary" type="submit">Update password</button></form></section></div>
<?php } elseif ($page === 'help') { ?>
<section class="panel"><div class="panel-head"><div><h2>CampusCare help</h2><p>Guidance for submitting, managing, and following up on complaints.</p></div></div><div class="faq-list"><details><summary>How do I submit a complaint?</summary><p>Choose Submit complaint, pick a category, describe what happened, and attach optional evidence. You can follow updates from the complaint register.</p></details><details><summary>Who can see a private complaint?</summary><p>Private complaints are visible only to the person who submitted them and approved administrators. Teachers and principals cannot view private cases.</p></details><details><summary>What does an overdue flag mean?</summary><p>New complaints have a first-response target of <?= (int)$config['sla_first_response_hours'] ?> hours and a resolution target of <?= (int)$config['sla_resolution_hours'] ?> hours. These are calendar-hour targets; the dashboard flags active cases that pass either target.</p></details><details><summary>How do I add evidence?</summary><p>When submitting a case, attach up to three PDF or image files, each no larger than 5 MB. Files are kept in private storage and served only after checking your access to that complaint.</p></details><details><summary>How can I change my details or password?</summary><p>Open My profile from the avatar menu. Password changes require your current password.</p></details><details><summary>Can I receive email notifications?</summary><p>Email delivery is not enabled in this installation. Status updates and replies appear in the in-app notifications list.</p></details></div></section>
<?php } else { http_response_code(404); ?><section class="panel"><h2>Page not found</h2><p>The page you requested does not exist.</p><a class="btn btn-primary" href="?page=dashboard">Back to dashboard</a></section><?php } ?>
</div>
<footer class="footer">© <?= date('Y') ?> CampusCare <span>College complaint management</span></footer>
</main>
<?php else: ?>
<main class="auth-background">
<button class="theme-toggle auth-theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false"><span class="theme-toggle-icon" aria-hidden="true">☾</span><span class="theme-toggle-label">Dark mode</span></button>
<?php if ($flash): ?><div class="auth-alert alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<?php
// Reuse the auth page renderers from the shared page flow.
if ($page === 'login') { ?>
<div class="auth-card"><div class="auth-logo"><span class="brand-mark">C</span></div><p class="eyebrow">WELCOME BACK</p><h2>Sign in to CampusCare</h2><p class="muted">Access your college support workspace.</p><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Email address<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary btn-full" type="submit">Sign in →</button></form><p class="auth-foot">New student? <a href="?page=register">Create an account</a></p></div>
<?php } else { ?>
<div class="auth-card auth-card-wide"><div class="auth-logo"><span class="brand-mark">C</span></div><p class="eyebrow">STUDENT ONBOARDING</p><h2>Create your account</h2><p class="muted">Your account will be reviewed by the college team.</p><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="register"><div class="form-grid"><label>Full name<input name="name" maxlength="120" required></label><label>College email<input type="email" name="email" maxlength="190" required></label><label>Roll number (optional)<input name="roll_number" maxlength="50"></label><label>Department<input name="department" maxlength="100" required placeholder="e.g. BCA"></label><label>Year<select name="year"><option value="">Select year</option><option value="1">First year</option><option value="2">Second year</option><option value="3">Third year</option><option value="4">Fourth year</option></select></label><label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"><small>Use at least 10 characters.</small></label></div><button class="btn btn-primary btn-full" type="submit">Submit registration</button></form><p class="auth-foot">Already registered? <a href="?page=login">Sign in</a></p></div>
<?php } ?>
</main>
<?php endif; ?>
</body></html>
