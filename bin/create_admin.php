<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require __DIR__ . '/../app/bootstrap.php';

echo "CampusCare — Create first administrator\n";
echo "Admin accounts can access all records. Use a unique, strong password.\n";
$name = trim((string)readline('Full name: '));
$email = trim((string)readline('Email: '));
$password = (string)readline('Password (minimum 12 characters): ');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Invalid input. Name/email required and password must be at least 12 characters.\n");
    exit(1);
}
try {
    $stmt = db()->prepare("INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,'admin','Approved')");
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    echo "Administrator created successfully. Sign in at the application login page.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Could not create administrator. Check database setup and whether the email already exists.\n");
    exit(1);
}
