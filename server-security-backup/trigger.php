<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'run-backup.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_array($config)) {
        throw new RuntimeException('Invalid config.');
    }

    $providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
    $expectedKey = isset($config['manual_trigger_key']) ? (string) $config['manual_trigger_key'] : '';

    if ($expectedKey === '' || $expectedKey === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') {
        throw new RuntimeException('Please set manual_trigger_key in config.php before using trigger.');
    }

    if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Forbidden: invalid key']);
        exit;
    }

    $allowedIps = isset($config['manual_allowed_ips']) && is_array($config['manual_allowed_ips'])
        ? $config['manual_allowed_ips']
        : [];

    if ($allowedIps !== []) {
        $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!in_array($remoteIp, $allowedIps, true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Forbidden: IP not allowed', 'ip' => $remoteIp]);
            exit;
        }
    }

    $manifest = PublicHtmlSecurityBackup::execute('manual');

    echo json_encode(
        [
            'ok' => true,
            'message' => 'Backup created successfully.',
            'run_folder' => $manifest['run_folder'] ?? null,
            'archives' => $manifest['archives'] ?? [],
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
