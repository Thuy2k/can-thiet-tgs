<?php

declare(strict_types=1);

final class PublicHtmlSecurityBackup
{
    public static function execute(string $mode = 'manual'): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is not available.');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

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

        $stamp = date('Ymd-His');
        $outputs = [];

        $wpIncludesPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-includes';
        $wpAdminPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-admin';
        $wpContentPath = $publicHtml . DIRECTORY_SEPARATOR . 'wp-content';
        $wpPluginsPath = $wpContentPath . DIRECTORY_SEPARATOR . 'plugins';

        if (is_dir($wpIncludesPath)) {
            $outputs[] = self::zipDirectory(
                $wpIncludesPath,
                $runFolder . DIRECTORY_SEPARATOR . 'wp-includes-backup-' . $stamp . '.zip',
                []
            );
        }

        if (is_dir($wpAdminPath)) {
            $outputs[] = self::zipDirectory(
                $wpAdminPath,
                $runFolder . DIRECTORY_SEPARATOR . 'wp-admin-backup-' . $stamp . '.zip',
                []
            );
        }

        if (is_dir($wpContentPath)) {
            $exclusions = [];
            if (is_dir($wpPluginsPath)) {
                $exclusions[] = self::normalizePath($wpPluginsPath);
            }

            $outputs[] = self::zipDirectory(
                $wpContentPath,
                $runFolder . DIRECTORY_SEPARATOR . 'wp-content-backup-' . $stamp . '.zip',
                $exclusions
            );
        }

        $outputs[] = self::zipRootFiles(
            $publicHtml,
            $runFolder . DIRECTORY_SEPARATOR . 'public-html-root-files-' . $stamp . '.zip'
        );

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

        return $manifest;
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
