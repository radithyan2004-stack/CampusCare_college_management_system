<?php
declare(strict_types=1);

function complaint_scope(array $user, string $alias = 'c'): array {
    if ($user['role'] === 'admin') {
        return ['1=1', []];
    }
    if ($user['role'] === 'student') {
        return [$alias . '.user_id=?', [(int)$user['id']]];
    }
    if ($user['role'] === 'teacher') {
        return [
            $alias . '.is_sensitive=0 AND (' . $alias . '.assigned_admin_id=? OR ' . $alias . '.department=?)',
            [(int)$user['id'], $user['department']],
        ];
    }
    return [$alias . '.is_sensitive=0', []];
}

function record_complaint_event(int $complaintId, ?int $actorId, string $eventType, ?string $details = null): void {
    $stmt = db()->prepare('INSERT INTO complaint_timeline (complaint_id,actor_id,event_type,details) VALUES (?,?,?,?)');
    $stmt->execute([$complaintId, $actorId, $eventType, $details]);
}

function can_view_complaint(array $user, array $complaint): bool {
    if (!empty($complaint['is_sensitive']) && $user['role'] !== 'admin'
        && (int)$complaint['user_id'] !== (int)$user['id']) {
        return false;
    }
    if ($user['role'] === 'admin' || (int)$complaint['user_id'] === (int)$user['id']) {
        return true;
    }
    if ($user['role'] === 'principal') {
        return empty($complaint['is_sensitive']);
    }
    if ($user['role'] === 'teacher') {
        return empty($complaint['is_sensitive'])
            && ((int)$complaint['assigned_admin_id'] === (int)$user['id']
                || (!empty($user['department']) && $user['department'] === $complaint['department']));
    }
    return false;
}

function normalize_complaint_uploads(array $files): array {
    global $config;
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }
    if (count($files['name']) > $config['upload_max_files_per_complaint']) {
        throw new RuntimeException('You can attach up to 3 files to a complaint.');
    }

    $uploads = [];
    foreach ($files['name'] as $index => $name) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('An attachment could not be uploaded. Please retry or remove it.');
        }
        $tmpName = $files['tmp_name'][$index] ?? '';
        $size = (int)($files['size'][$index] ?? 0);
        if (!is_string($tmpName) || $tmpName === '' || $size < 1 || $size > $config['upload_max_file_bytes']) {
            throw new RuntimeException('Each attachment must be smaller than 5 MB.');
        }
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('An attachment did not pass upload validation.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            throw new RuntimeException('Attachments must be PDF, JPEG, PNG, GIF, or WebP files.');
        }
        $uploads[] = [
            'tmp_name' => $tmpName,
            'original_name' => mb_substr(basename(str_replace('\\', '/', (string)$name)), 0, 255),
            'mime_type' => $mimeType,
            'extension' => $extensions[$mimeType],
            'file_size' => $size,
        ];
    }

    if (count($uploads) > $config['upload_max_files_per_complaint']) {
        throw new RuntimeException('You can attach up to 3 files to a complaint.');
    }
    return $uploads;
}

function store_complaint_upload(array $upload): string {
    global $config;
    $directory = $config['upload_directory'];
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private attachment storage could not be created.');
    }
    $privateRoot = realpath($directory);
    $publicRoot = realpath(dirname(__DIR__) . '/public');
    if ($privateRoot === false || $publicRoot === false
        || $privateRoot === $publicRoot
        || str_starts_with($privateRoot, $publicRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Attachment storage must be outside the public web directory.');
    }
    if (!chmod($privateRoot, 0700) || !is_writable($privateRoot)) {
        throw new RuntimeException('Private attachment storage must be writable and restricted to the application account.');
    }

    $storageName = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
    $destination = $privateRoot . DIRECTORY_SEPARATOR . $storageName;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('An attachment could not be saved securely.');
    }
    chmod($destination, 0600);
    return $storageName;
}

function remove_complaint_uploads(array $storageNames): void {
    global $config;
    foreach ($storageNames as $storageName) {
        if (!is_string($storageName) || basename($storageName) !== $storageName) {
            continue;
        }
        $path = $config['upload_directory'] . DIRECTORY_SEPARATOR . $storageName;
        if (is_file($path) && !unlink($path)) {
            error_log('Unable to remove orphaned complaint attachment: ' . $path);
        }
    }
}

function complaint_sla_state(array $complaint): array {
    $active = !in_array($complaint['status'], ['Resolved', 'Rejected'], true);
    $now = time();
    $responseDue = !empty($complaint['first_response_due_at']) ? strtotime($complaint['first_response_due_at']) : false;
    $resolutionDue = !empty($complaint['resolution_due_at']) ? strtotime($complaint['resolution_due_at']) : false;
    $responseOverdue = $active
        && empty($complaint['first_responded_at'])
        && $responseDue !== false
        && $responseDue < $now;
    $resolutionOverdue = $active
        && $resolutionDue !== false
        && $resolutionDue < $now;
    return [
        'response_overdue' => $responseOverdue,
        'resolution_overdue' => $resolutionOverdue,
        'overdue' => $responseOverdue || $resolutionOverdue,
    ];
}

function format_file_size(int $bytes): string {
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1) . ' MB'
        : number_format(max(1, (int)round($bytes / 1024))) . ' KB';
}

function query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    return '?' . http_build_query($query);
}
