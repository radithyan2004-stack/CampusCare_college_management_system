<?php
declare(strict_types=1);

$localEnv = [];
$localEnvPath = dirname(__DIR__) . '/.env';
if (is_file($localEnvPath)) {
    $parsedEnv = parse_ini_file($localEnvPath, false, INI_SCANNER_RAW);
    if ($parsedEnv === false) {
        throw new RuntimeException('Unable to parse the project .env file.');
    }
    $localEnv = $parsedEnv;
}

$setting = static function (string $name, string $default) use ($localEnv): string {
    $environmentValue = getenv($name);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    $fileValue = $localEnv[$name] ?? null;
    return is_string($fileValue) ? $fileValue : $default;
};

return [
    'db_host' => $setting('DB_HOST', '127.0.0.1'),
    'db_port' => $setting('DB_PORT', '3306'),
    'db_name' => $setting('DB_NAME', 'campuscare'),
    'db_user' => $setting('DB_USER', 'campuscare_app'),
    'db_pass' => $setting('DB_PASS', ''),
    'app_name' => 'CampusCare',
    'timezone' => 'Asia/Kolkata',
    'sla_first_response_hours' => max(1, min(720, (int)(getenv('SLA_FIRST_RESPONSE_HOURS') ?: 48))),
    'sla_resolution_hours' => max(1, min(2160, (int)(getenv('SLA_RESOLUTION_HOURS') ?: 240))),
    'upload_directory' => getenv('UPLOAD_DIR') ?: dirname(__DIR__) . '/storage/private-uploads',
    'upload_max_file_bytes' => 5 * 1024 * 1024,
    'upload_max_files_per_complaint' => 3,
    'college_name' => getenv('COLLEGE_NAME') ?: 'SAS SNDP YOGAM COLLEGE',
    'college_address' => getenv('COLLEGE_ADDRESS') ?: 'Konni, Pathanamthitta, Keralam',
    'college_office_hours' => getenv('COLLEGE_OFFICE_HOURS') ?: '10:30 to 3',
];
