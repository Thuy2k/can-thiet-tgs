<?php

declare(strict_types=1);

final class PublicHtmlSecurityBackup
{
    /** Absolute path to the current run's log file, set inside execute(). */
    private static $logFile = '';

    private static function log(string $msg): void
    {
        if (self::$logFile === '') {
            return;
        }
        file_put_contents(
            self::$logFile,
            date('[Y-m-d H:i:s] ') . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function execute(string $mode = 'manual'): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is not available.');
        }

        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '-1');

        // Prevent duplicate runs (browser refresh, double-click, etc.)
        $lockFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'tgs_backup_' . substr(md5(__FILE__), 0, 8) . '.lock';

        if (is_file($lockFile)) {
            $pid = (int) @file_get_contents($lockFile);
            $age = time() - (int) filemtime($lockFile);
            $phpAlive = $pid > 0
                && file_exists('/proc/' . $pid)
                && stripos((string) @file_get_contents('/proc/' . $pid . '/cmdline'), 'php') !== false;
            if ($phpAlive && $age < 300) {
                throw new RuntimeException('Another backup is already running (PID ' . $pid . ', age ' . $age . 's). Please wait.');
            }
            @unlink($lockFile);
        }
        file_put_contents($lockFile, (string) getmypid());
        register_shutdown_function(static function () use ($lockFile): void {
            @unlink($lockFile);
        });

        $config = self::loadConfig();
        $publicHtml = self::resolvePublicHtmlPath($config);
        $storageRoot = self::resolveStorageRoot($config, $publicHtml);

        self::ensureDirectory($storageRoot);
        if (!is_writable($storageRoot)) {
            throw new RuntimeException('Backup storage is not writable: ' . $storageRoot);
        }

        if (!empty($config['write_deny_access_files'])) {
            self::writeDenyAccessFiles($storageRoot);
        }

        $dateFolder = $storageRoot . DIRECTORY_SEPARATOR . date('Y-m-d');
        self::ensureDirectory($dateFolder);

        if (!empty($config['write_deny_access_files'])) {
            self::writeDenyAccessFiles($dateFolder);
        }

        $runFolder = $dateFolder . DIRECTORY_SEPARATOR . self::buildRunFolderName($mode);
        self::ensureDirectory($runFolder);

        // Start logging into the run folder so errors are visible
        self::$logFile = $runFolder . DIRECTORY_SEPARATOR . 'backup.log';
        self::log('=== Backup started (mode=' . $mode . ') ===');
        self::log('PHP ' . PHP_VERSION . ' | SAPI=' . PHP_SAPI . ' | memory_limit=' . ini_get('memory_limit'));
        self::log('public_html=' . $publicHtml);

        // Capture fatal errors (OOM, etc.) that kill the process
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                PublicHtmlSecurityBackup::log('FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
            }
        });

        $stamp = date('Ymd-His');
        $outputs = [];

        $wpIncludesPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-includes';
        $wpAdminPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-admin';
        $wpContentPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-content';
        $wpPluginsPath = $wpContentPath . DIRECTORY_SEPARATOR . 'plugins';

        if (is_dir($wpIncludesPath)) {
            self::log('Zipping wp-includes... raw_size=' . self::dirSize($wpIncludesPath) . ' bytes');
            $outputs[] = self::zipDirectory(
                $wpIncludesPath,
                $runFolder . DIRECTORY_SEPARATOR . 'wp-includes-backup-' . $stamp . '.zip',
                []
            );
            self::log('wp-includes done. size=' . ($outputs[count($outputs)-1]['size_bytes'] ?? 0));
        }

        if (is_dir($wpAdminPath)) {
            self::log('Zipping wp-admin... raw_size=' . self::dirSize($wpAdminPath) . ' bytes');
            $outputs[] = self::zipDirectory(
                $wpAdminPath,
                $runFolder . DIRECTORY_SEPARATOR . 'wp-admin-backup-' . $stamp . '.zip',
                []
            );
            self::log('wp-admin done. size=' . ($outputs[count($outputs)-1]['size_bytes'] ?? 0));
        }

        if (is_dir($wpContentPath)) {
            $exclusions = [];
            if (is_dir($wpPluginsPath)) {
                $exclusions[] = self::normalizePath($wpPluginsPath);
            }
            self::log('Zipping wp-content (excluding plugins)... raw_size=' . self::dirSize($wpContentPath, [$wpPluginsPath]) . ' bytes, mem=' . memory_get_usage(true));
            $outputs[] = self::zipDirectory(
                $wpContentPath,
                $runFolder . DIRECTORY_SEPARATOR . 'wp-content-backup-' . $stamp . '.zip',
                $exclusions
            );
            self::log('wp-content done. size=' . ($outputs[count($outputs)-1]['size_bytes'] ?? 0) . ' mem=' . memory_get_usage(true));
        }

        self::log('Zipping root PHP files...');
        $outputs[] = self::zipRootFiles(
            $publicHtml,
            $runFolder . DIRECTORY_SEPARATOR . 'public-html-root-files-' . $stamp . '.zip'
        );
        self::log('Root files done. size=' . ($outputs[count($outputs)-1]['size_bytes'] ?? 0));

        $manifest = [
            'mode' => $mode,
            'generated_at' => date('c'),
            'public_html' => $publicHtml,
            'storage_root' => $storageRoot,
            'run_folder' => $runFolder,
            'archives' => $outputs,
        ];

        file_put_contents(
            $runFolder . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        self::log('=== Backup completed successfully ===');

        return $manifest;
    }

    // ── Step-based execution ──────────────────────────────────────────────────
    // Splits backup into 4 HTTP requests so each completes within PHP-FPM's
    // request_terminate_timeout. trigger.php auto-chains via meta-refresh.
    // ─────────────────────────────────────────────────────────────────────────

    /** Path of the temp JSON file used to pass state between HTTP steps. */
    public static function stateFile(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'tgs_backup_state_' . substr(md5(__FILE__), 0, 12) . '.json';
    }

    /**
     * Execute one step of the backup. trigger.php chains steps 1→2→3→4.
     *   Step 1 – init run folder + zip wp-includes  (slow; gets its own window)
     *   Step 2 – zip wp-admin
     *   Step 3 – zip wp-content (excluding plugins)
     *   Step 4 – zip root files + write manifest + clean up state
     */
    public static function executeStep(int $step, string $mode = 'manual'): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is not available.');
        }

        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '-1');

        $stateFile = self::stateFile();

        // ── Step 1: init + zip wp-includes ───────────────────────────────────
        if ($step === 1) {
            if (is_file($stateFile) && (time() - (int) filemtime($stateFile)) < 600) {
                throw new RuntimeException(
                    'Another backup is still in progress (state age '
                    . (time() - (int) filemtime($stateFile))
                    . 's). Add ?reset=1 to force-clear, then restart.'
                );
            }
            @unlink($stateFile);

            $config      = self::loadConfig();
            $publicHtml  = self::resolvePublicHtmlPath($config);
            $storageRoot = self::resolveStorageRoot($config, $publicHtml);

            self::ensureDirectory($storageRoot);
            if (!is_writable($storageRoot)) {
                throw new RuntimeException('Backup storage is not writable: ' . $storageRoot);
            }
            if (!empty($config['write_deny_access_files'])) {
                self::writeDenyAccessFiles($storageRoot);
            }

            $dateFolder = $storageRoot . DIRECTORY_SEPARATOR . date('Y-m-d');
            self::ensureDirectory($dateFolder);
            if (!empty($config['write_deny_access_files'])) {
                self::writeDenyAccessFiles($dateFolder);
            }

            $runFolder = $dateFolder . DIRECTORY_SEPARATOR . self::buildRunFolderName($mode);
            self::ensureDirectory($runFolder);
            $stamp = date('Ymd-His');

            self::$logFile = $runFolder . DIRECTORY_SEPARATOR . 'backup.log';
            self::log('=== Backup started (mode=' . $mode . ', step-based) ===');
            self::log('PHP ' . PHP_VERSION . ' | SAPI=' . PHP_SAPI . ' | memory_limit=' . ini_get('memory_limit'));
            self::log('public_html=' . $publicHtml);
            self::registerFatalLogger();

            $archives       = [];
            $wpIncludesPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-includes';
            if (is_dir($wpIncludesPath)) {
                self::log('Step 1: Zipping wp-includes... raw_size=' . self::dirSize($wpIncludesPath) . ' bytes');
                $r = self::zipDirectory(
                    $wpIncludesPath,
                    $runFolder . DIRECTORY_SEPARATOR . 'wp-includes-backup-' . $stamp . '.zip',
                    []
                );
                $archives[] = $r;
                self::log('Step 1 done. zip_size=' . $r['size_bytes']);
            }

            file_put_contents($stateFile, json_encode([
                'mode'         => $mode,
                'public_html'  => $publicHtml,
                'storage_root' => $storageRoot,
                'run_folder'   => $runFolder,
                'stamp'        => $stamp,
                'archives'     => $archives,
            ], JSON_UNESCAPED_SLASHES));

            return ['step' => 1, 'next_step' => 2, 'run_folder' => $runFolder];
        }

        // ── Steps 2–4: restore persisted state ───────────────────────────────
        if (!is_file($stateFile)) {
            throw new RuntimeException(
                'No active backup state found. Please start from step=1.'
            );
        }
        $raw   = file_get_contents($stateFile);
        $state = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            throw new RuntimeException(
                'Corrupt backup state. Add ?reset=1 to clear it, then restart from step=1.'
            );
        }

        $runFolder  = (string) $state['run_folder'];
        $publicHtml = (string) $state['public_html'];
        $stamp      = (string) $state['stamp'];
        $archives   = isset($state['archives']) && is_array($state['archives']) ? $state['archives'] : [];

        self::$logFile = $runFolder . DIRECTORY_SEPARATOR . 'backup.log';
        self::registerFatalLogger();

        // ── Step 2: zip wp-admin ──────────────────────────────────────────────
        if ($step === 2) {
            $wpAdminPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-admin';
            if (is_dir($wpAdminPath)) {
                self::log('Step 2: Zipping wp-admin... raw_size=' . self::dirSize($wpAdminPath) . ' bytes');
                $r = self::zipDirectory(
                    $wpAdminPath,
                    $runFolder . DIRECTORY_SEPARATOR . 'wp-admin-backup-' . $stamp . '.zip',
                    []
                );
                $archives[] = $r;
                self::log('Step 2 done. zip_size=' . $r['size_bytes']);
            }
            $state['archives'] = $archives;
            file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));
            return ['step' => 2, 'next_step' => 3, 'run_folder' => $runFolder];
        }

        // ── Step 3: zip wp-content (excluding plugins) ────────────────────────
        if ($step === 3) {
            $wpContentPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-content';
            $wpPluginsPath = $wpContentPath . DIRECTORY_SEPARATOR . 'plugins';
            if (is_dir($wpContentPath)) {
                $exclusions = is_dir($wpPluginsPath) ? [self::normalizePath($wpPluginsPath)] : [];
                self::log('Step 3: Zipping wp-content (excl plugins)... raw_size='
                    . self::dirSize($wpContentPath, [$wpPluginsPath]) . ' bytes, mem=' . memory_get_usage(true));
                $r = self::zipDirectory(
                    $wpContentPath,
                    $runFolder . DIRECTORY_SEPARATOR . 'wp-content-backup-' . $stamp . '.zip',
                    $exclusions
                );
                $archives[] = $r;
                self::log('Step 3 done. zip_size=' . $r['size_bytes'] . ' mem=' . memory_get_usage(true));
            }
            $state['archives'] = $archives;
            file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));
            return ['step' => 3, 'next_step' => 4, 'run_folder' => $runFolder];
        }

        // ── Step 4: zip root files + manifest + cleanup ───────────────────────
        if ($step === 4) {
            self::log('Step 4: Zipping root PHP files...');
            $r = self::zipRootFiles(
                $publicHtml,
                $runFolder . DIRECTORY_SEPARATOR . 'public-html-root-files-' . $stamp . '.zip'
            );
            $archives[] = $r;
            self::log('Step 4 done. zip_size=' . $r['size_bytes']);

            $manifest = [
                'mode'         => (string) ($state['mode'] ?? $mode),
                'generated_at' => date('c'),
                'public_html'  => $publicHtml,
                'storage_root' => (string) ($state['storage_root'] ?? ''),
                'run_folder'   => $runFolder,
                'archives'     => $archives,
            ];
            file_put_contents(
                $runFolder . DIRECTORY_SEPARATOR . 'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            @unlink($stateFile);
            self::log('=== Backup completed successfully ===');

            return ['step' => 4, 'next_step' => null, 'run_folder' => $runFolder, 'manifest' => $manifest];
        }

        throw new RuntimeException('Invalid step: ' . $step . '. Valid values: 1–4.');
    }

    /** Registers a shutdown function that logs PHP fatal errors to backup.log. */
    private static function registerFatalLogger(): void
    {
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                PublicHtmlSecurityBackup::log(
                    'FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']
                );
            }
        });
    }

    private static function loadConfig(): array
    {
        $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($configFile)) {
            throw new RuntimeException('Missing config file: ' . $configFile);
        }

        $config = require $configFile;
        if (!is_array($config)) {
            throw new RuntimeException('Config file must return an array.');
        }

        return $config;
    }

    private static function resolvePublicHtmlPath(array $config): string
    {
        $configured = isset($config['public_html']) ? trim((string) $config['public_html']) : '';
        $candidate = $configured !== '' ? $configured : dirname(__DIR__);
        $resolved = realpath($candidate);

        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('public_html path is invalid: ' . $candidate);
        }

        return self::normalizePath($resolved);
    }

    private static function resolveStorageRoot(array $config, string $publicHtml): string
    {
        $configured = isset($config['backup_storage']) ? trim((string) $config['backup_storage']) : '';
        if ($configured !== '') {
            return self::normalizePath($configured);
        }

        $parent = dirname($publicHtml);
        return self::normalizePath($parent . DIRECTORY_SEPARATOR . 'tgs_secure_backups');
    }

    private static function buildRunFolderName(string $mode): string
    {
        $safeMode = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($mode)) ?: 'manual';
        $random = bin2hex(random_bytes(3));
        return sprintf('%s-scan-%s-%s', $safeMode, date('H-i-s'), $random);
    }

    private static function zipDirectory(string $sourceDir, string $zipPath, array $excludedAbsolutePaths): array
    {
        $sourceDir = self::normalizePath($sourceDir);
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('Cannot create zip: ' . $zipPath);
        }

        $baseName = basename($sourceDir);
        $excluded = array_map([self::class, 'normalizePath'], $excludedAbsolutePaths);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = self::normalizePath($item->getPathname());

            if (self::isExcluded($path, $excluded)) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($sourceDir)), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            $zipRelative = $baseName . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                $zip->addEmptyDir(str_replace('\\', '/', $zipRelative));
                continue;
            }

            if ($item->isFile()) {
                $zip->addFile($path, str_replace('\\', '/', $zipRelative));
            }
        }

        $zip->close();

        return [
            'type' => 'directory',
            'source' => $sourceDir,
            'zip' => $zipPath,
            'size_bytes' => is_file($zipPath) ? filesize($zipPath) : 0,
        ];
    }

    private static function zipRootFiles(string $publicHtml, string $zipPath): array
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('Cannot create zip: ' . $zipPath);
        }

        $iterator = new FilesystemIterator($publicHtml, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $filePath = self::normalizePath($item->getPathname());
            $zip->addFile($filePath, $item->getFilename());
        }

        $zip->close();

        return [
            'type' => 'root_files',
            'source' => $publicHtml,
            'zip' => $zipPath,
            'size_bytes' => is_file($zipPath) ? filesize($zipPath) : 0,
        ];
    }

    private static function isExcluded(string $path, array $excludedAbsolutePaths): bool
    {
        foreach ($excludedAbsolutePaths as $excluded) {
            if ($path === $excluded) {
                return true;
            }

            if (strpos($path, $excluded . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
    }

    private static function writeDenyAccessFiles(string $directory): void
    {
        $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        $indexPhp = $directory . DIRECTORY_SEPARATOR . 'index.php';

        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        if (!is_file($indexPhp)) {
            file_put_contents($indexPhp, "<?php\nhttp_response_code(403);\nexit;\n");
        }
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /** Returns total size in bytes of all files in $dir, excluding $excludeDirs. */
    private static function dirSize(string $dir, array $excludeDirs = []): int
    {
        $total = 0;
        $excludeDirs = array_map([self::class, 'normalizePath'], $excludeDirs);
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                $p = self::normalizePath($file->getPathname());
                foreach ($excludeDirs as $ex) {
                    if ($p === $ex || strpos($p, $ex . DIRECTORY_SEPARATOR) === 0) {
                        continue 2;
                    }
                }
                if ($file->isFile()) {
                    $total += $file->getSize();
                }
            }
        } catch (Throwable $e) {
            // ignore permission errors
        }
        return $total;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $options = getopt('', ['mode::']);
        $mode = isset($options['mode']) && is_string($options['mode']) ? $options['mode'] : 'daily';
        $manifest = PublicHtmlSecurityBackup::execute($mode);
        echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, '[backup-error] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
