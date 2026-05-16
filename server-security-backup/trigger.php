<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'run-backup.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
try {
    $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_array($config)) {
        throw new RuntimeException('Config must return an array.');
    }
} catch (Throwable $cfgEx) {
    http_response_code(500);
    exit('Config error: ' . htmlspecialchars($cfgEx->getMessage()));
}

$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$expectedKey = isset($config['manual_trigger_key']) ? (string) $config['manual_trigger_key'] : '';

if ($expectedKey === '' || $expectedKey === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') {
    http_response_code(500);
    exit('Please set manual_trigger_key in config.php before using trigger.');
}

if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(403);
    exit('Forbidden: invalid key.');
}

$allowedIps = isset($config['manual_allowed_ips']) && is_array($config['manual_allowed_ips'])
    ? $config['manual_allowed_ips']
    : [];

if ($allowedIps !== []) {
    $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (!in_array($remoteIp, $allowedIps, true)) {
        http_response_code(403);
        exit('Forbidden: IP ' . htmlspecialchars($remoteIp) . ' not allowed.');
    }
}

$keyParam = urlencode($providedKey);

// ── Reset stale state ─────────────────────────────────────────────────────────
if (isset($_GET['reset'])) {
    $sf = PublicHtmlSecurityBackup::stateFile();
    if (is_file($sf)) {
        @unlink($sf);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>State cleared</title></head><body style="font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:24px">';
    echo '<p style="color:#4ec9b0">State cleared.</p>';
    echo '<p><a style="color:#9cdcfe" href="?key=' . $keyParam . '">Start fresh backup</a></p>';
    echo '</body></html>';
    exit;
}

// ── Run step ──────────────────────────────────────────────────────────────────
$step   = isset($_GET['step']) ? max(1, min(4, (int) $_GET['step'])) : 1;
$labels = [
    1 => 'wp-includes',
    2 => 'wp-admin',
    3 => 'wp-content (excl. plugins)',
    4 => 'root files + manifest',
];

header('Content-Type: text/html; charset=utf-8');

try {
    $result    = PublicHtmlSecurityBackup::executeStep($step);
    $nextStep  = $result['next_step'];
    $runFolder = $result['run_folder'];
    $nextUrl   = $nextStep !== null ? '?key=' . $keyParam . '&step=' . $nextStep : null;

    echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
    echo '<title>Backup – Step ' . $step . '/4</title>';
    echo '<style>';
    echo 'body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:24px}';
    echo 'h2{color:#569cd6}code{background:#2d2d2d;padding:2px 6px;border-radius:3px}';
    echo '.ok{color:#4ec9b0}.err{color:#f44747}a{color:#9cdcfe}';
    echo 'table{border-collapse:collapse;margin-top:12px}th,td{border:1px solid #555;padding:6px 14px;text-align:left}';
    echo '</style>';
    if ($nextUrl !== null) {
        echo '<meta http-equiv="refresh" content="1;url=' . htmlspecialchars($nextUrl, ENT_QUOTES) . '">';
    }
    echo '</head><body>';
    echo '<h2>WordPress Backup</h2>';

    if ($nextUrl !== null) {
        echo '<p class="ok">&#10004; Step ' . $step . '/4 &mdash; <strong>'
            . htmlspecialchars($labels[$step] ?? (string) $step)
            . '</strong> &mdash; done.</p>';
        echo '<p>Auto-starting step ' . $nextStep . '/4'
            . ' (<em>' . htmlspecialchars($labels[$nextStep] ?? '') . '</em>)'
            . ' in 1 second&hellip;</p>';
        echo '<p><a href="' . htmlspecialchars($nextUrl, ENT_QUOTES) . '">Click here if not redirected</a></p>';
    } else {
        $manifest = $result['manifest'] ?? [];
        echo '<p class="ok">&#10004; All 4 steps complete &mdash; Backup finished!</p>';
        if (!empty($manifest['archives'])) {
            echo '<table><tr><th>Archive</th><th>Compressed size</th></tr>';
            foreach ($manifest['archives'] as $a) {
                $name = htmlspecialchars(basename((string) ($a['zip'] ?? '')));
                $sz   = isset($a['size_bytes']) ? number_format((int) $a['size_bytes']) . ' B' : '?';
                echo '<tr><td>' . $name . '</td><td>' . $sz . '</td></tr>';
            }
            echo '</table>';
        }
        echo '<p>Run folder: <code>' . htmlspecialchars($runFolder) . '</code></p>';
        echo '<p><a href="?key=' . $keyParam . '">Run another backup</a></p>';
    }

    echo '<hr><small>Check <code>backup.log</code> in the run folder for details.</small>';
    echo '</body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Backup Error</title>';
    echo '<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:24px}a{color:#9cdcfe}</style>';
    echo '</head><body>';
    echo '<p style="color:#f44747">&#10008; Error in step ' . $step . ': '
        . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="?key=' . $keyParam . '&step=1">Restart from step 1</a></p>';
    echo '<p><a href="?key=' . $keyParam . '&reset=1">Clear state &amp; restart</a></p>';
    echo '</body></html>';
}
