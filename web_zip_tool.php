<?php
declare(strict_types=1);

session_start();
set_time_limit(0);

/*
 * Web ZIP Tool (single file)
 * - Upload ZIP and extract
 * - Extract existing ZIP
 * - Create ZIP from folder and download
 *
 * IMPORTANT:
 * 1) Change APP_PASSWORD before uploading to production.
 * 2) Delete this file after use.
 */

const APP_PASSWORD = 'Thuy!@#';

$baseDir = realpath(__DIR__);
if ($baseDir === false) {
    http_response_code(500);
    exit('Cannot resolve base directory.');
}

$message = '';
$error = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['zip_tool_auth']) && $_SESSION['zip_tool_auth'] === true;
}

function normalizeRelPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return trim($path, '/');
}

function assertSafeRelPath(string $path): void
{
    if ($path === '') {
        return;
    }

    if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(?:/|$)#', $path)) {
        throw new RuntimeException('Unsafe path detected.');
    }
}

function resolveExistingPath(string $baseDir, string $relPath, bool $mustBeDir = false): string
{
    $relPath = normalizeRelPath($relPath);
    assertSafeRelPath($relPath);

    $candidate = $relPath === '' ? $baseDir : $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $real = realpath($candidate);

    if ($real === false) {
        throw new RuntimeException('Path does not exist: ' . $relPath);
    }

    if (!str_starts_with($real, $baseDir)) {
        throw new RuntimeException('Path is outside allowed base directory.');
    }

    if ($mustBeDir && !is_dir($real)) {
        throw new RuntimeException('Expected a directory: ' . $relPath);
    }

    return $real;
}

function buildNewFilePath(string $baseDir, string $relPath): string
{
    $relPath = normalizeRelPath($relPath);
    assertSafeRelPath($relPath);

    if ($relPath === '') {
        throw new RuntimeException('Output file name is required.');
    }

    $dir = dirname($relPath);
    $file = basename($relPath);

    if ($file === '' || $file === '.' || $file === '..') {
        throw new RuntimeException('Invalid output file name.');
    }

    if (!preg_match('/\.zip$/i', $file)) {
        throw new RuntimeException('Output file must end with .zip');
    }

    $absDir = $dir === '.' ? $baseDir : resolveExistingPath($baseDir, $dir, true);
    return $absDir . DIRECTORY_SEPARATOR . $file;
}

function extractZipFile(string $zipAbsPath, string $targetAbsPath): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not available on this server.');
    }

    $zip = new ZipArchive();
    $open = $zip->open($zipAbsPath);
    if ($open !== true) {
        throw new RuntimeException('Cannot open ZIP file. Code: ' . (string) $open);
    }

    if (!$zip->extractTo($targetAbsPath)) {
        $zip->close();
        throw new RuntimeException('Failed to extract ZIP.');
    }

    $zip->close();
}

function zipDirectory(string $sourceDir, string $outputZipPath): int
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not available on this server.');
    }

    $zip = new ZipArchive();
    $open = $zip->open($outputZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($open !== true) {
        throw new RuntimeException('Cannot create ZIP file. Code: ' . (string) $open);
    }

    $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
    $baseLen = strlen($sourceDir) + 1;
    $count = 0;

    $dirIterator = new RecursiveDirectoryIterator(
        $sourceDir,
        FilesystemIterator::SKIP_DOTS
    );
    $iterator = new RecursiveIteratorIterator(
        $dirIterator,
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();

        if ($itemPath === $outputZipPath) {
            continue;
        }

        $localName = substr($itemPath, $baseLen);
        if ($localName === false || $localName === '') {
            continue;
        }

        $localName = str_replace('\\', '/', $localName);

        if ($item->isDir()) {
            if (!$zip->addEmptyDir($localName)) {
                throw new RuntimeException('Failed to add folder into ZIP: ' . $localName);
            }
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        if (!$zip->addFile($itemPath, $localName)) {
            throw new RuntimeException('Failed to add file into ZIP: ' . $localName);
        }

        $count++;
    }

    $zip->close();
    return $count;
}

function listZipFiles(string $baseDir): array
{
    $result = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $name = $file->getFilename();
        if (!preg_match('/\.zip$/i', $name)) {
            continue;
        }

        $abs = $file->getRealPath();
        if ($abs === false) {
            continue;
        }

        if (str_contains($abs, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($baseDir))), '/');
        $result[] = $rel;
    }

    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_POST['login_password'])) {
    $incoming = (string) $_POST['login_password'];
    if (hash_equals(APP_PASSWORD, $incoming)) {
        $_SESSION['zip_tool_auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error = 'Wrong password.';
}

if (isLoggedIn() && isset($_GET['download'])) {
    try {
        $downloadRel = normalizeRelPath((string) $_GET['download']);
        assertSafeRelPath($downloadRel);
        $abs = resolveExistingPath($baseDir, $downloadRel, false);

        if (!is_file($abs)) {
            throw new RuntimeException('Requested file is not a regular file.');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
        header('Content-Length: ' . (string) filesize($abs));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($abs);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isLoggedIn() && isset($_POST['action'])) {
    try {
        $action = (string) $_POST['action'];

        if ($action === 'upload_extract') {
            if (!isset($_FILES['zip_upload']) || !is_array($_FILES['zip_upload'])) {
                throw new RuntimeException('No upload received.');
            }

            $upload = $_FILES['zip_upload'];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed with code: ' . (string) ($upload['error'] ?? -1));
            }

            $origName = (string) ($upload['name'] ?? 'upload.zip');
            if (!preg_match('/\.zip$/i', $origName)) {
                throw new RuntimeException('Only .zip uploads are allowed.');
            }

            $targetRel = normalizeRelPath((string) ($_POST['upload_target_dir'] ?? ''));
            $targetDir = $targetRel === '' ? $baseDir : resolveExistingPath($baseDir, $targetRel, true);

            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($origName)) ?: ('upload_' . date('Ymd_His') . '.zip');
            $destZip = $targetDir . DIRECTORY_SEPARATOR . $safeName;

            if (!move_uploaded_file((string) $upload['tmp_name'], $destZip)) {
                throw new RuntimeException('Cannot move uploaded file.');
            }

            $doExtract = isset($_POST['extract_after_upload']) && $_POST['extract_after_upload'] === '1';
            if ($doExtract) {
                extractZipFile($destZip, $targetDir);
                $message = 'Uploaded and extracted: ' . $safeName;
            } else {
                $message = 'Uploaded only: ' . $safeName;
            }
        }

        if ($action === 'extract_existing') {
            $zipRel = normalizeRelPath((string) ($_POST['existing_zip_rel'] ?? ''));
            if ($zipRel === '') {
                throw new RuntimeException('ZIP path is required.');
            }

            $zipAbs = resolveExistingPath($baseDir, $zipRel, false);
            if (!is_file($zipAbs) || !preg_match('/\.zip$/i', $zipAbs)) {
                throw new RuntimeException('Invalid ZIP path.');
            }

            $targetRel = normalizeRelPath((string) ($_POST['extract_target_dir'] ?? ''));
            $targetAbs = $targetRel === '' ? dirname($zipAbs) : resolveExistingPath($baseDir, $targetRel, true);

            extractZipFile($zipAbs, $targetAbs);
            $message = 'Extracted: ' . $zipRel;
        }

        if ($action === 'create_zip') {
            $sourceRel = normalizeRelPath((string) ($_POST['source_dir_rel'] ?? ''));
            if ($sourceRel === '') {
                throw new RuntimeException('Source folder is required.');
            }

            $outputRel = normalizeRelPath((string) ($_POST['output_zip_rel'] ?? ''));
            if ($outputRel === '') {
                $outputRel = 'backup_' . date('Ymd_His') . '.zip';
            }

            $sourceAbs = resolveExistingPath($baseDir, $sourceRel, true);
            $outputAbs = buildNewFilePath($baseDir, $outputRel);

            $count = zipDirectory($sourceAbs, $outputAbs);
            $message = 'ZIP created: ' . $outputRel . ' (' . $count . ' files).';
        }

        if ($action === 'delete_file') {
            $deleteRel = normalizeRelPath((string) ($_POST['delete_rel'] ?? ''));
            if ($deleteRel === '') {
                throw new RuntimeException('File path is required for delete.');
            }

            $deleteAbs = resolveExistingPath($baseDir, $deleteRel, false);
            if (!is_file($deleteAbs)) {
                throw new RuntimeException('Target is not a file.');
            }

            if (!unlink($deleteAbs)) {
                throw new RuntimeException('Cannot delete file.');
            }

            $message = 'Deleted: ' . $deleteRel;
        }

        if ($action === 'delete_self') {
            $self = __FILE__;
            if (!unlink($self)) {
                throw new RuntimeException('Could not delete this script.');
            }
            echo 'Script deleted. You can close this page.';
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$zipFiles = isLoggedIn() ? listZipFiles($baseDir) : [];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Web ZIP Tool</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #111827;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --ok: #16a34a;
            --err: #dc2626;
            --border: #334155;
            --accent: #0ea5e9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(1200px 800px at 20% -10%, #1e293b 0%, var(--bg) 60%);
            color: var(--text);
            font-family: Segoe UI, Tahoma, sans-serif;
            line-height: 1.45;
        }
        .wrap {
            max-width: 980px;
            margin: 24px auto;
            padding: 0 16px 24px;
        }
        .head {
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .title {
            margin: 0;
            font-size: 24px;
        }
        .muted { color: var(--muted); font-size: 13px; }
        .card {
            background: linear-gradient(180deg, #111827, #0b1220);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin: 12px 0;
        }
        h2 {
            margin: 0 0 10px;
            font-size: 18px;
        }
        form {
            display: grid;
            gap: 10px;
        }
        label {
            font-size: 13px;
            color: var(--muted);
        }
        input[type="text"],
        input[type="password"],
        input[type="file"],
        select {
            width: 100%;
            background: #0b1220;
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
        }
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .btn {
            background: linear-gradient(180deg, #1d4ed8, #1e40af);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
        }
        .btn.secondary { background: linear-gradient(180deg, #334155, #1e293b); }
        .btn.danger { background: linear-gradient(180deg, #b91c1c, #7f1d1d); }
        .msg, .err {
            border-radius: 8px;
            padding: 10px;
            margin: 8px 0;
            font-size: 14px;
        }
        .msg { background: rgba(22, 163, 74, 0.16); border: 1px solid rgba(22, 163, 74, 0.45); }
        .err { background: rgba(220, 38, 38, 0.16); border: 1px solid rgba(220, 38, 38, 0.45); }
        .links a { color: #7dd3fc; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
        code {
            background: #0b1220;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 2px 6px;
        }
        @media (max-width: 720px) {
            .row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div>
            <h1 class="title">Web ZIP Tool</h1>
            <div class="muted">Base directory: <code><?= h($baseDir) ?></code></div>
        </div>
        <?php if (isLoggedIn()): ?>
            <a class="btn secondary" href="?logout=1">Logout</a>
        <?php endif; ?>
    </div>

    <?php if ($message !== ''): ?>
        <div class="msg"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!isLoggedIn()): ?>
        <div class="card">
            <h2>Login</h2>
            <form method="post">
                <label>Password</label>
                <input type="password" name="login_password" required>
                <button class="btn" type="submit">Sign in</button>
            </form>
            <div class="muted" style="margin-top:8px;">
                Open this file in an editor and change <code>APP_PASSWORD</code> before production use.
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>1) Upload ZIP (optional extract)</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_extract">
                <label>ZIP file</label>
                <input type="file" name="zip_upload" accept=".zip" required>

                <label>Target folder (relative to base, empty = current)</label>
                <input type="text" name="upload_target_dir" placeholder="wp-content/uploads">

                <label><input type="checkbox" name="extract_after_upload" value="1" checked> Extract right after upload</label>
                <button class="btn" type="submit">Upload</button>
            </form>
        </div>

        <div class="card">
            <h2>2) Extract existing ZIP</h2>
            <form method="post">
                <input type="hidden" name="action" value="extract_existing">
                <label>Select ZIP file</label>
                <select name="existing_zip_rel">
                    <option value="">-- choose zip --</option>
                    <?php foreach ($zipFiles as $zipRel): ?>
                        <option value="<?= h($zipRel) ?>"><?= h($zipRel) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Extract to folder (relative, empty = same folder as zip)</label>
                <input type="text" name="extract_target_dir" placeholder="wp-content">

                <button class="btn" type="submit">Extract</button>
            </form>
        </div>

        <div class="card">
            <h2>3) Zip folder to file</h2>
            <form method="post">
                <input type="hidden" name="action" value="create_zip">

                <div class="row">
                    <div>
                        <label>Source folder (relative)</label>
                        <input type="text" name="source_dir_rel" placeholder="wp-content" required>
                    </div>
                    <div>
                        <label>Output ZIP path (relative)</label>
                        <input type="text" name="output_zip_rel" placeholder="wp-content-backup.zip">
                    </div>
                </div>

                <button class="btn" type="submit">Create ZIP</button>
            </form>

            <div class="links" style="margin-top:10px;">
                <strong>Found ZIP files:</strong>
                <?php if (count($zipFiles) === 0): ?>
                    <div class="muted">No zip files found yet.</div>
                <?php else: ?>
                    <ul>
                        <?php foreach ($zipFiles as $z): ?>
                            <li>
                                <a href="?download=<?= urlencode($z) ?>"><?= h($z) ?></a>
                                <form method="post" style="display:inline; margin-left:8px;">
                                    <input type="hidden" name="action" value="delete_file">
                                    <input type="hidden" name="delete_rel" value="<?= h($z) ?>">
                                    <button type="submit" class="btn secondary" style="padding:4px 8px;">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>4) Cleanup</h2>
            <form method="post" onsubmit="return confirm('Delete this tool file now?');">
                <input type="hidden" name="action" value="delete_self">
                <button class="btn danger" type="submit">Delete this script</button>
            </form>
            <div class="muted" style="margin-top:8px;">
                Security best practice: remove this script after you finish zip/unzip tasks.
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>