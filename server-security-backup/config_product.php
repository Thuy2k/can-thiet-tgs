<?php
declare(strict_types=1);

return [
    'public_html' => null,
    'backup_storage' => dirname(__DIR__) . '/.tgs_secure_backups',
    'manual_trigger_key' => 'abc',
    'manual_allowed_ips' => [],
    'write_deny_access_files' => true,
];