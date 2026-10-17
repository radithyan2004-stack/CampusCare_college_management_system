<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('campuscare_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'], $config['db_port'], $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

require_once __DIR__ . '/complaint_helpers.php';

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function verify_csrf(): void {
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session token expired. Please go back, refresh the page and try again.');
    }
}
function current_user(): ?array {
    static $loaded = false;
    static $user = null;
    if ($loaded) return $user;
    $loaded = true;
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id,name,email,role,status,department,year,roll_number FROM users WHERE id=?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if (!$user || ($user['status'] !== 'Approved' && $user['role'] !== 'admin')) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}
function require_login(): array {
    $u = current_user();
    if (!$u) redirect('?page=login');
    return $u;
}
function require_roles(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $u;
}
function flash(string $type, string $message): void {
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}
function audit(int $actorId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (actor_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$actorId, $action, $entityType, $entityId, $details, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
function notify_user(int $userId, ?int $complaintId, string $title, string $message): void {
    $stmt = db()->prepare('INSERT INTO notifications (user_id,complaint_id,title,message) VALUES (?,?,?,?)');
    $stmt->execute([$userId, $complaintId, $title, $message]);
}
function status_class(string $status): string {
    return match ($status) {
        'Resolved', 'Approved' => 'success',
        'In Progress' => 'info',
        'Rejected' => 'danger',
        'Pending' => 'warning',
        default => 'muted',
    };
}
