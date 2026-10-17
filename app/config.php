<?php
declare(strict_types=1);

/*
 * Local development settings.
 * For deployment, read credentials from environment variables instead.
 */
return [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'campuscare',
    'db_user' => getenv('DB_USER') ?: 'campuscare_app',
    'db_pass' => getenv('DB_PASS') ?: '',
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
