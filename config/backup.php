<?php

return [
    'path' => env('BACKUP_PATH', PHP_OS_FAMILY === 'Windows' ? 'D:\\school-system-backups' : storage_path('app/private/backups')),
    'disk' => env('BACKUP_DISK', 'local'),
    'prefix' => trim(env('BACKUP_PREFIX', 'backups'), '/'),
];
