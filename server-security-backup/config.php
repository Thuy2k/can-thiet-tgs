<?php

declare(strict_types=1);

return [
    // Absolute path to public_html. Keep null to auto-detect from this script location.
    'public_html' => null,

    // Absolute path to backup storage. Keep null to store one level above public_html.
    // Example: /home/username/tgs_secure_backups
    'backup_storage' => null,

    // Secret key for manual URL trigger.
    'manual_trigger_key' => 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY',

    // Optional IP whitelist for manual trigger. Keep empty to allow any IP.
    'manual_allowed_ips' => [],

    // Keep a marker file to prevent directory listing if backup_storage is inside web root.
    'write_deny_access_files' => true,
];
