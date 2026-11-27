#!/usr/bin/env php
<?php
/**
 * Cron-based pull deploy for STAGING excel2.bcsrp.ir only (PHP CLI, no Composer).
 *
 * Architecture:
 *   GitHub main → CI gates → GitHub Release Assets → this CLI (cPanel cron) →
 *   Controlled Sync → health check → rollback on failure.
 *
 * Hard rules:
 *   - Staging only: excel2.bcsrp → /home3/zzflgmfd/excel2.bcsrp/
 *   - Refuse production excel.bcsrp
 *   - Never execute downloaded code before Release validation
 *   - Never overwrite app/config.php, storage user data, env, API keys
 *   - Backup must succeed before any dest mutation
 *   - flock against concurrent cron
 *   - Migrations NOT run; Worker update PARTIAL if infra missing
 *   - Bootstrap lives OUTSIDE docroot: /home3/zzflgmfd/erpsmart-deploy/
 *
 * Modes: --dry-run | --apply | --status | --rollback | --selftest
 *
 * PHP 8.1+ compatible. Does NOT change ERP app PHP requirements.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only — refuse web SAPI\n");
    exit(99);
}

$here = __DIR__;
$libCandidates = [
    $here . '/lib',
    dirname($here) . '/lib',
    (getenv('ERPSMART_DEPLOY_BOOTSTRAP') ?: '') . '/lib',
];
$libDir = null;
foreach ($libCandidates as $cand) {
    if ($cand !== '/lib' && is_file($cand . '/erpsmart_controlled_sync.php')) {
        $libDir = $cand;
        break;
    }
}
if ($libDir === null) {
    fwrite(STDERR, "Missing lib/erpsmart_controlled_sync.php\n");
    exit(2);
}
require_once $libDir . '/erpsmart_controlled_sync.php';
require_once $libDir . '/erpsmart_staging_health.php';

const ERPSMART_STAGING_HOST = 'excel2.bcsrp.ir';
const ERPSMART_STAGING_URL = 'https://excel2.bcsrp.ir/';
const ERPSMART_DEFAULT_BOOTSTRAP = '/home3/zzflgmfd/erpsmart-deploy';
const ERPSMART_DEFAULT_REPO = 'AmirAbedini78/excel-dev';
const ERPSMART_RELEASE_TAG_PREFIX = 'staging-excel2-';
const ERPSMART_UA = 'ErpSmart-StagingCronDeploy-PHP/1.0';
const ERPSMART_MAX_ZIP_BYTES = 200_000_000;
const ERPSMART_KEEP_BACKUPS = 5;
const ERPSMART_LOG_MAX_BYTES = 5_000_000;
const ERPSMART_MIN_FREE_BYTES = 100_000_000;

final class DeployError extends RuntimeException
{
    /** Machine-readable deploy refusal code (not Exception::$code). */
    public string $deployCode;
    public string $detail;

    public function __construct(string $deployCode, string $detail = '')
    {
        parent::__construct($deployCode);
        $this->deployCode = $deployCode;
        $this->detail = $detail;
    }
}

function erpsmart_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function erpsmart_log(string $bootstrap, string $msg): void
{
    $line = erpsmart_utc() . ' ' . $msg;
    echo $line . PHP_EOL;
    $logPath = rtrim($bootstrap, '/\\') . '/logs/cron_deploy.log';
    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    erpsmart_rotate_log($logPath);
}

function erpsmart_rotate_log(string $logPath): void
{
    if (!is_file($logPath) || filesize($logPath) < ERPSMART_LOG_MAX_BYTES) {
        return;
    }
    $rotated = $logPath . '.' . gmdate('YmdHis');
    @rename($logPath, $rotated);
    // Keep last 3 rotated logs
    $dir = dirname($logPath);
    $files = glob($dir . '/cron_deploy.log.*') ?: [];
    rsort($files);
    foreach (array_slice($files, 3) as $old) {
        @unlink($old);
    }
}

/** @return array<string,mixed> */
function erpsmart_load_config(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new DeployError('config_unreadable', $path);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new DeployError('config_invalid', 'not_object');
    }
    return $data;
}

/** @return array<string,mixed> */
function erpsmart_load_state(string $bootstrap): array
{
    $p = rtrim($bootstrap, '/\\') . '/state/state.json';
    if (!is_file($p)) {
        return ['published_sha' => null, 'last_release_tag' => null, 'history' => []];
    }
    $data = json_decode((string) file_get_contents($p), true);
    return is_array($data) ? $data : ['published_sha' => null, 'last_release_tag' => null, 'history' => []];
}

/** @param array<string,mixed> $state */
function erpsmart_save_state(string $bootstrap, array $state): void
{
    $dir = rtrim($bootstrap, '/\\') . '/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $p = $dir . '/state.json';
    $tmp = $p . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    rename($tmp, $p);
}

final class DeployLock
{
    private string $path;
    /** @var resource|null */
    private $fh = null;

    public function __construct(string $bootstrap)
    {
        $dir = rtrim($bootstrap, '/\\') . '/locks';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->path = $dir . '/deploy.lock';
    }

    public function acquire(): void
    {
        if (is_file($this->path)) {
            $age = time() - (int) filemtime($this->path);
            if ($age < 2700) {
                // Try non-blocking flock on existing; if held → concurrent
                $probe = @fopen($this->path, 'c+');
                if ($probe !== false) {
                    if (!flock($probe, LOCK_EX | LOCK_NB)) {
                        fclose($probe);
                        throw new DeployError('concurrent_lock', $this->path);
                    }
                    // Stale unlocked file — steal
                    flock($probe, LOCK_UN);
                    fclose($probe);
                } else {
                    throw new DeployError('concurrent_lock', $this->path);
                }
            }
        }
        $fh = fopen($this->path, 'c+');
        if ($fh === false) {
            throw new DeployError('lock_open_failed', $this->path);
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new DeployError('concurrent_lock', $this->path);
        }
        ftruncate($fh, 0);
        fwrite($fh, json_encode(['pid' => getmypid(), 'at' => erpsmart_utc()]) . "\n");
        fflush($fh);
        $this->fh = $fh;
    }

    public function release(): void
    {
        if ($this->fh !== null) {
            flock($this->fh, LOCK_UN);
            fclose($this->fh);
            $this->fh = null;
        }
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}

/** @return array<string,mixed>|list<mixed> */
function erpsmart_https_json(string $url, float $timeout = 60.0)
{
    $raw = erpsmart_https_body($url, $timeout, 5_000_000);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new DeployError('json_decode_failed', $url);
    }
    return $data;
}

function erpsmart_https_body(string $url, float $timeout, int $maxBytes): string
{
    if (!function_exists('curl_init')) {
        throw new DeployError('curl_missing');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new DeployError('curl_init_failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => (int) ceil($timeout),
        CURLOPT_CONNECTTIMEOUT => min(30, (int) ceil($timeout)),
        CURLOPT_USERAGENT => ERPSMART_UA,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new DeployError('https_failed', substr($err, 0, 200));
    }
    if ($status < 200 || $status >= 300) {
        throw new DeployError('https_status', (string) $status);
    }
    if (strlen((string) $raw) > $maxBytes) {
        throw new DeployError('response_too_large', (string) strlen((string) $raw));
    }
    return (string) $raw;
}

function erpsmart_https_download(string $url, string $dest, float $timeout = 300.0, int $maxBytes = ERPSMART_MAX_ZIP_BYTES): int
{
    $parent = dirname($dest);
    if (!is_dir($parent)) {
        mkdir($parent, 0755, true);
    }
    $part = $dest . '.part';
    if (is_file($part)) {
        @unlink($part);
    }
    if (!function_exists('curl_init')) {
        throw new DeployError('curl_missing');
    }
    $fh = fopen($part, 'wb');
    if ($fh === false) {
        throw new DeployError('download_open_failed', $part);
    }
    $written = 0;
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fh);
        @unlink($part);
        throw new DeployError('curl_init_failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => (int) ceil($timeout),
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => ERPSMART_UA,
        CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($maxBytes): int {
            if ($dlNow > $maxBytes) {
                return 1; // abort
            }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fflush($fh);
    $written = (int) filesize($part);
    fclose($fh);
    if ($ok === false || $status < 200 || $status >= 300 || $written > $maxBytes) {
        @unlink($part);
        if ($written > $maxBytes) {
            throw new DeployError('download_too_large', (string) $written);
        }
        throw new DeployError('download_failed', $err !== '' ? $err : ('status=' . $status));
    }
    if (!rename($part, $dest)) {
        @unlink($part);
        throw new DeployError('download_rename_failed');
    }
    return $written;
}

function erpsmart_verify_dest_identity(string $dest): void
{
    $refuse = ErpsmartControlledSync::refuseNonStagingDest($dest);
    if ($refuse !== null) {
        throw new DeployError($refuse, $dest);
    }
    $real = realpath($dest) ?: $dest;
    $destS = rtrim(str_replace('\\', '/', $real), '/');
    $canonical = rtrim(ErpsmartControlledSync::CANONICAL_DEST_HINT, '/');
    if ($destS !== $canonical && getenv('ERPSMART_CRON_ALLOW_FIXTURE') !== '1') {
        if (!str_ends_with($destS, '/excel2.bcsrp') && !str_contains($destS, 'excel2.bcsrp')) {
            throw new DeployError('refused_non_canonical_dest', $destS);
        }
        if (str_contains($destS, 'excel.bcsrp') && !str_contains($destS, 'excel2.bcsrp')) {
            throw new DeployError('refused_production_dest', $destS);
        }
    }
    $identity = ErpsmartControlledSync::verifyDestinationIdentity($dest, true);
    if (empty($identity['ok'])) {
        throw new DeployError('identity_marker_failed', (string) ($identity['reason'] ?? ''));
    }
}

function erpsmart_parse_sha256_sidecar(string $text, string $expectedName): string
{
    $lines = preg_split('/\R/', trim($text)) ?: [];
    $line = trim($lines[0] ?? '');
    $parts = preg_split('/\s+/', $line) ?: [];
    if ($parts === [] || $parts[0] === '') {
        throw new DeployError('checksum_sidecar_empty');
    }
    $digest = strtolower($parts[0]);
    if (strlen($digest) !== 64 || !ctype_xdigit($digest)) {
        throw new DeployError('checksum_sidecar_invalid', substr($digest, 0, 20));
    }
    if (isset($parts[1]) && basename($parts[count($parts) - 1]) !== $expectedName) {
        throw new DeployError('checksum_name_mismatch', $parts[count($parts) - 1]);
    }
    return $digest;
}

/**
 * Extract ZIP with ZIP Slip / symlink / absolute-path refusal.
 * @return list<string>
 */
function erpsmart_safe_extract_zip(string $zipPath, string $destDir): array
{
    if (is_dir($destDir)) {
        erpsmart_rm_tree($destDir);
    }
    if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new DeployError('extract_mkdir_failed');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new DeployError('zip_open_failed', $zipPath);
    }
    $extracted = [];
    $destReal = realpath($destDir);
    if ($destReal === false) {
        $zip->close();
        throw new DeployError('extract_realpath_failed');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }
        $name = str_replace('\\', '/', $stat['name']);
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }
        if (str_starts_with($name, '/') || str_starts_with($name, '../') || str_contains('/' . $name . '/', '/../')) {
            $zip->close();
            throw new DeployError('zip_slip_refused', $name);
        }
        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            $zip->close();
            throw new DeployError('zip_absolute_path_refused', $name);
        }
        // Refuse Unix symlink members (external attrs high bits 0o120000)
        $external = (int) ($stat['external_attributes'] ?? 0);
        $mode = ($external >> 16) & 0170000;
        if ($mode === 0120000 || str_ends_with(strtolower($name), '.lnk')) {
            $zip->close();
            throw new DeployError('zip_symlink_refused', $name);
        }
        $target = $destDir . '/' . $name;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            $zip->close();
            throw new DeployError('extract_parent_failed', $name);
        }
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $zip->close();
            throw new DeployError('zip_read_failed', $name);
        }
        if (file_put_contents($target, $content) === false) {
            $zip->close();
            throw new DeployError('extract_write_failed', $name);
        }
        $resolved = realpath($target);
        if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $destReal))) {
            @unlink($target);
            $zip->close();
            throw new DeployError('zip_slip_escape', $name);
        }
        $extracted[] = $name;
    }
    $zip->close();
    return $extracted;
}

/** @param array<string,mixed> $publicMeta */
function erpsmart_verify_extracted_manifest(string $pkgDir, array $publicMeta): void
{
    $manifest = $publicMeta['file_manifest'] ?? [];
    $filesSha = $publicMeta['files_sha256'] ?? [];
    $inZipMeta = $pkgDir . '/ERPSMART_STAGING_RELEASE.json';
    if (is_file($inZipMeta)) {
        $inner = json_decode((string) file_get_contents($inZipMeta), true);
        if (is_array($inner)) {
            if (!empty($inner['git_sha']) && !empty($publicMeta['git_sha'])
                && $inner['git_sha'] !== $publicMeta['git_sha']) {
                throw new DeployError('inner_meta_sha_mismatch');
            }
            if (!empty($inner['files_sha256']) && is_array($inner['files_sha256'])) {
                $filesSha = $inner['files_sha256'];
            }
            if (!empty($inner['file_manifest']) && is_array($inner['file_manifest'])) {
                $manifest = $inner['file_manifest'];
            } elseif (is_array($filesSha) && $filesSha !== []) {
                $manifest = array_keys($filesSha);
            }
        }
    }
    if ((!is_array($manifest) || $manifest === []) && is_array($filesSha) && $filesSha !== []) {
        $manifest = array_keys($filesSha);
        sort($manifest);
    }
    if (!is_array($manifest) || $manifest === []) {
        throw new DeployError('manifest_empty');
    }
    $missing = [];
    $mismatch = [];
    foreach ($manifest as $rel) {
        $rel = (string) $rel;
        if (str_ends_with($rel, '.zip') || str_ends_with($rel, '.sha256')) {
            continue;
        }
        $base = basename($rel);
        if (in_array($base, ['ERPSMART_STAGING_RELEASE.json', 'ERPSMART_STAGING_RELEASE_PUBLIC.json'], true)) {
            if ($base === 'ERPSMART_STAGING_RELEASE.json' && !is_file($pkgDir . '/' . $rel)
                && !is_file($pkgDir . '/ERPSMART_STAGING_RELEASE.json')) {
                $missing[] = $rel;
            }
            continue;
        }
        $p = $pkgDir . '/' . $rel;
        if (!is_file($p)) {
            $missing[] = $rel;
            continue;
        }
        if (is_array($filesSha) && isset($filesSha[$rel])) {
            $got = ErpsmartControlledSync::sha256File($p);
            if ($got !== $filesSha[$rel]) {
                $mismatch[] = $rel;
            }
        }
    }
    if ($missing !== []) {
        throw new DeployError('manifest_missing_files', implode(',', array_slice($missing, 0, 10)));
    }
    if ($mismatch !== []) {
        throw new DeployError('manifest_checksum_mismatch', implode(',', array_slice($mismatch, 0, 10)));
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pkgDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $rel = strtolower(ErpsmartControlledSync::posixRel($file->getPathname(), $pkgDir));
        $name = strtolower($file->getFilename());
        if ($name === 'config.php' && str_starts_with($rel, 'app/')) {
            throw new DeployError('package_contains_config_php');
        }
        if (str_starts_with($name, '.env') && !str_ends_with($name, '.example')) {
            throw new DeployError('package_contains_env', $rel);
        }
    }
}

/** @param array<string,mixed> $meta */
function erpsmart_gates_ok(array $meta): bool
{
    $gates = $meta['gates'] ?? [];
    if (!is_array($gates)) {
        return false;
    }
    return strtolower((string) ($gates['release-gate'] ?? '')) === 'success'
        && strtolower((string) ($gates['rc1-mysql-php'] ?? '')) === 'success';
}

/** @param array<string,mixed> $meta */
function erpsmart_release_belongs_to_main(array $meta, string $tag, string $sha): bool
{
    if (!empty($meta['branch_required']) && $meta['branch_required'] !== 'main') {
        return false;
    }
    if ($sha === '' || strlen($sha) < 12) {
        return false;
    }
    if (str_starts_with($tag, ERPSMART_RELEASE_TAG_PREFIX)) {
        $tagSha = substr($tag, strlen(ERPSMART_RELEASE_TAG_PREFIX));
        if (!(str_starts_with($sha, $tagSha) || str_starts_with($tagSha, substr($sha, 0, 12)))) {
            return false;
        }
    }
    return true;
}

/** @return list<array<string,mixed>> */
function erpsmart_list_releases(string $repo): array
{
    $url = 'https://api.github.com/repos/' . $repo . '/releases?per_page=20';
    $data = erpsmart_https_json($url);
    if ($data !== [] && !array_is_list($data)) {
        throw new DeployError('releases_api_invalid', isset($data['message']) ? (string) $data['message'] : 'not_list');
    }
    /** @var list<array<string,mixed>> $data */
    return $data;
}

/**
 * @param list<array<string,mixed>> $releases
 * @param array<string,mixed> $state
 * @return array<string,mixed>|null
 */
function erpsmart_pick_latest_valid_release(array $releases, array $state): ?array
{
    foreach ($releases as $rel) {
        if (!empty($rel['draft']) || !empty($rel['prerelease'])) {
            continue;
        }
        $tag = (string) ($rel['tag_name'] ?? '');
        if (!str_starts_with($tag, ERPSMART_RELEASE_TAG_PREFIX)) {
            continue;
        }
        $assets = [];
        foreach (($rel['assets'] ?? []) as $a) {
            if (!empty($a['name'])) {
                $assets[$a['name']] = $a;
            }
        }
        $hasZip = false;
        foreach (array_keys($assets) as $n) {
            if (str_ends_with((string) $n, '.zip')) {
                $hasZip = true;
                break;
            }
        }
        if (!$hasZip) {
            continue;
        }
        if (!isset($assets['ERPSMART_STAGING_RELEASE_PUBLIC.json'])
            && !isset($assets['ERPSMART_STAGING_RELEASE.json'])) {
            continue;
        }
        return $rel;
    }
    return null;
}

/** @param array<string,array<string,mixed>> $assets */
function erpsmart_asset_url(array $assets, string $name): string
{
    if (!isset($assets[$name])) {
        throw new DeployError('asset_missing', $name);
    }
    $url = $assets[$name]['browser_download_url'] ?? $assets[$name]['url'] ?? null;
    if (!$url) {
        throw new DeployError('asset_url_missing', $name);
    }
    return (string) $url;
}

/**
 * @param array<string,array<string,mixed>> $assets
 * @return array{0:string,1:array<string,mixed>}
 */
function erpsmart_find_zip_asset(array $assets): array
{
    foreach ($assets as $name => $a) {
        if (str_ends_with($name, '.zip') && str_starts_with($name, 'erpsmart-staging-php-')) {
            return [$name, $a];
        }
    }
    throw new DeployError('zip_asset_missing');
}

/**
 * @param list<string> $rels
 */
function erpsmart_backup_dest(string $dest, string $backupRoot, array $rels): string
{
    $stamp = gmdate('Ymd\THis\Z');
    $bak = rtrim($backupRoot, '/\\') . '/_erpsmart_staging_code_bak_' . $stamp;
    if (!is_dir($bak) && !mkdir($bak, 0755, true) && !is_dir($bak)) {
        throw new DeployError('backup_mkdir_failed');
    }
    $copied = 0;
    foreach ($rels as $rel) {
        $src = $dest . '/' . $rel;
        if (!is_file($src)) {
            continue;
        }
        $target = $bak . '/' . $rel;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new DeployError('backup_parent_failed', $rel);
        }
        if (!copy($src, $target)) {
            throw new DeployError('backup_copy_failed', $rel);
        }
        $copied++;
    }
    $marker = $bak . '/BACKUP_OK.json';
    file_put_contents($marker, json_encode([
        'ok' => true,
        'files' => $copied,
        'at' => erpsmart_utc(),
        'dest' => $dest,
    ], JSON_PRETTY_PRINT) . "\n");
    if (!is_file($marker)) {
        throw new DeployError('backup_marker_missing');
    }
    return $bak;
}

function erpsmart_restore_backup(string $bak, string $dest): int
{
    $restored = 0;
    if (!is_dir($bak)) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($bak, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && $file->getFilename() !== 'BACKUP_OK.json') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    foreach ($files as $path) {
        $rel = ErpsmartControlledSync::posixRel($path, $bak);
        $target = $dest . '/' . $rel;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        copy($path, $target);
        $restored++;
    }
    return $restored;
}

function erpsmart_local_verify_published_sha(string $dest, string $expectedSha): bool
{
    $meta = rtrim($dest, '/\\') . '/ERPSMART_STAGING_RELEASE.json';
    if (!is_file($meta)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($meta), true);
    return is_array($data) && (($data['git_sha'] ?? null) === $expectedSha);
}

function erpsmart_prune_backups(string $backupRoot, int $keep = ERPSMART_KEEP_BACKUPS): void
{
    $pattern = rtrim($backupRoot, '/\\') . '/_erpsmart_staging_code_bak_*';
    $dirs = glob($pattern, GLOB_ONLYDIR) ?: [];
    rsort($dirs);
    foreach (array_slice($dirs, $keep) as $old) {
        erpsmart_rm_tree($old);
    }
}

function erpsmart_assert_disk_space(string $path, int $minFree = ERPSMART_MIN_FREE_BYTES): void
{
    $free = @disk_free_space($path);
    if ($free !== false && $free < $minFree) {
        throw new DeployError('disk_space_low', (string) $free);
    }
}

function erpsmart_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
}

/** @return array<string,mixed> */
function erpsmart_optional_worker_update(string $bootstrap, string $sha, bool $dryRun): array
{
    $workerDest = getenv('ERPSMART_STAGING_WORKER_DEST')
        ?: (ERPSMART_DEFAULT_BOOTSTRAP . '/worker');
    $src = rtrim($bootstrap, '/\\') . '/worker_src';
    if (!is_dir($src) || !file_exists($workerDest)) {
        return [
            'status' => 'PARTIAL',
            'reason' => 'worker_infra_missing',
            'note' => 'PHP package deployed; Worker runs on owner Windows or separate path — see docs/ai/agentic-next/21-STAGING-CRON-PULL-EXCEL2.md',
            'git_sha' => $sha,
            'windows_owner_path' => 'engine/ on developer machine (Python Worker)',
            'update_hint' => 'git checkout ' . $sha . ' && sync engine/ preserving .env* / config.json',
        ];
    }
    // Minimal safe sync: copy non-secret files only when worker_src prepared on host.
    $copied = 0;
    $skipped = 0;
    $preserve = [
        'config.json' => true, 'config.cloud.json' => true, 'config.gemini.json' => true,
        '.env' => true, '.env.local' => true, '.env.cursor' => true,
        '.env.gemini' => true, '.env.xai' => true, '.env.grok' => true,
    ];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $rel = ErpsmartControlledSync::posixRel($file->getPathname(), $src);
        $parts = explode('/', $rel);
        if (in_array('data', $parts, true) || in_array('.git', $parts, true)
            || in_array('__pycache__', $parts, true) || in_array('.venv', $parts, true)) {
            continue;
        }
        $name = basename($rel);
        $destF = rtrim($workerDest, '/\\') . '/' . $rel;
        if (isset($preserve[$name]) || (str_starts_with($name, '.env') && !str_ends_with($name, '.example'))) {
            if (is_file($destF) || (str_starts_with($name, '.env') && !str_ends_with($name, '.example'))) {
                $skipped++;
                continue;
            }
        }
        if ($dryRun) {
            $copied++;
            continue;
        }
        $parent = dirname($destF);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        copy($file->getPathname(), $destF);
        $copied++;
    }
    return [
        'status' => $dryRun ? 'DRY_RUN' : 'OK',
        'sync' => ['copied' => $copied, 'skipped_preserve' => $skipped, 'dry_run' => $dryRun],
        'git_sha' => $sha,
    ];
}

/**
 * @param array<string,mixed> $cfg
 * @return array<string,mixed>
 */
function erpsmart_run_cycle(array $cfg, bool $apply, bool $dryRun, string $bootstrap): array
{
    $repo = (string) ($cfg['github_repo'] ?? ERPSMART_DEFAULT_REPO);
    $dest = (string) ($cfg['dest'] ?? rtrim(ErpsmartControlledSync::CANONICAL_DEST_HINT, '/'));
    $healthUrl = (string) ($cfg['health_url'] ?? ERPSMART_STAGING_URL);
    erpsmart_verify_dest_identity($dest);
    erpsmart_assert_disk_space($bootstrap);

    $state = erpsmart_load_state($bootstrap);
    $releases = erpsmart_list_releases($repo);
    $rel = erpsmart_pick_latest_valid_release($releases, $state);
    if ($rel === null) {
        return [
            'ok' => true,
            'status' => 'NO_RELEASE',
            'action' => 'noop',
            'published_sha' => $state['published_sha'] ?? null,
        ];
    }

    $tag = (string) ($rel['tag_name'] ?? '');
    $assets = [];
    foreach (($rel['assets'] ?? []) as $a) {
        if (!empty($a['name'])) {
            $assets[$a['name']] = $a;
        }
    }
    $work = rtrim($bootstrap, '/\\') . '/work';
    if (!is_dir($work)) {
        mkdir($work, 0755, true);
    }

    $metaName = isset($assets['ERPSMART_STAGING_RELEASE_PUBLIC.json'])
        ? 'ERPSMART_STAGING_RELEASE_PUBLIC.json'
        : 'ERPSMART_STAGING_RELEASE.json';
    $metaPath = $work . '/' . $metaName;
    erpsmart_https_download(erpsmart_asset_url($assets, $metaName), $metaPath, 60.0, 5_000_000);
    $publicMeta = json_decode((string) file_get_contents($metaPath), true);
    if (!is_array($publicMeta)) {
        throw new DeployError('release_meta_invalid');
    }
    $sha = (string) ($publicMeta['git_sha'] ?? '');
    if ($sha === '') {
        throw new DeployError('release_meta_missing_sha');
    }
    if (!erpsmart_gates_ok($publicMeta)) {
        throw new DeployError('gates_not_success', json_encode($publicMeta['gates'] ?? null) ?: '');
    }
    if (!erpsmart_release_belongs_to_main($publicMeta, $tag, $sha)) {
        throw new DeployError('release_not_on_main', $tag);
    }
    $targetHost = $publicMeta['target_host'] ?? null;
    if ($targetHost !== null && $targetHost !== ERPSMART_STAGING_HOST) {
        throw new DeployError('release_wrong_host', (string) $targetHost);
    }

    $published = $state['published_sha'] ?? null;
    if ($published && $published === $sha) {
        return [
            'ok' => true,
            'status' => 'UP_TO_DATE',
            'action' => 'noop',
            'published_sha' => $published,
            'release_tag' => $tag,
        ];
    }
    if ($published && strlen((string) $published) >= 12 && strlen($sha) >= 12) {
        $lastAt = $state['last_success_at'] ?? null;
        $relAt = $rel['published_at'] ?? $rel['created_at'] ?? null;
        if ($lastAt && $relAt && $relAt < $lastAt && $sha !== $published) {
            throw new DeployError('stale_release_rejected', $sha . '@' . $relAt . '<' . $lastAt);
        }
    }

    [$zipName] = erpsmart_find_zip_asset($assets);
    $expectedZipSha = strtolower((string) ($publicMeta['package_zip_sha256'] ?? ''));
    $shaName = $zipName . '.sha256';
    if (isset($assets[$shaName])) {
        $shaPath = $work . '/' . $shaName;
        erpsmart_https_download(erpsmart_asset_url($assets, $shaName), $shaPath, 60.0, 10_000);
        $expectedZipSha = erpsmart_parse_sha256_sidecar((string) file_get_contents($shaPath), $zipName);
    }
    if ($expectedZipSha === '') {
        throw new DeployError('missing_zip_checksum');
    }

    $zipPath = $work . '/' . $zipName;
    erpsmart_https_download(erpsmart_asset_url($assets, $zipName), $zipPath);
    $got = ErpsmartControlledSync::sha256File($zipPath);
    if ($got !== $expectedZipSha) {
        throw new DeployError('zip_checksum_mismatch', 'got=' . $got . ' expected=' . $expectedZipSha);
    }

    $extractDir = $work . '/extract_' . substr($sha, 0, 12);
    $extracted = erpsmart_safe_extract_zip($zipPath, $extractDir);
    erpsmart_verify_extracted_manifest($extractDir, $publicMeta);

    $ops = ErpsmartControlledSync::buildPlan($extractDir, $dest);
    $toWrite = [];
    foreach ($ops as $o) {
        if (in_array($o['op'], ['copy', 'scaffold_only'], true)) {
            $toWrite[] = $o['rel'];
        }
    }
    $report = [
        'ok' => true,
        'status' => 'PLANNED',
        'git_sha' => $sha,
        'release_tag' => $tag,
        'extracted_files' => count($extracted),
        'ops_copy' => count($toWrite),
        'dry_run' => $dryRun || !$apply,
        'dest' => $dest,
        'runtime' => 'php' . PHP_VERSION,
    ];

    if ($dryRun || !$apply) {
        $report['status'] = 'DRY_RUN';
        $report['worker'] = erpsmart_optional_worker_update($bootstrap, $sha, true);
        $report['migrations'] = 'NOT_RUN';
        return $report;
    }

    $beforeCfg = ErpsmartControlledSync::snapshotConfig($dest);
    $beforeStorage = ErpsmartControlledSync::snapshotStorage($dest);
    $backupRoot = (string) ($cfg['backup_root'] ?? (rtrim($bootstrap, '/\\') . '/backups'));
    if (!is_dir($backupRoot)) {
        mkdir($backupRoot, 0755, true);
    }
    try {
        $bak = erpsmart_backup_dest($dest, $backupRoot, $toWrite);
    } catch (Throwable $e) {
        throw new DeployError('backup_failed_no_changes', substr($e->getMessage(), 0, 200));
    }
    if (!is_file($bak . '/BACKUP_OK.json')) {
        throw new DeployError('backup_failed_no_changes', 'marker_missing');
    }
    $report['backup_dir'] = $bak;

    $applyInfo = ErpsmartControlledSync::applyPlan($extractDir, $dest, $ops, null);
    $report['apply'] = $applyInfo;

    $afterCfg = ErpsmartControlledSync::snapshotConfig($dest);
    if ($beforeCfg !== null && $beforeCfg !== $afterCfg) {
        erpsmart_restore_backup($bak, $dest);
        throw new DeployError('config_mutated_rolled_back');
    }
    $storageCmp = ErpsmartControlledSync::manifestCompareStorage($dest, $beforeStorage);
    if (empty($storageCmp['ok'])) {
        erpsmart_restore_backup($bak, $dest);
        throw new DeployError('storage_mutated_rolled_back', substr(json_encode($storageCmp) ?: '', 0, 300));
    }
    if (!erpsmart_local_verify_published_sha($dest, $sha)) {
        erpsmart_restore_backup($bak, $dest);
        throw new DeployError('published_sha_mismatch_rolled_back');
    }

    $page = ErpsmartStagingHealth::probe($healthUrl);
    $relProbe = ErpsmartStagingHealth::probeRelease($healthUrl, $sha);
    $healthOk = !empty($page['ok']) && !empty($relProbe['ok']);
    $report['health'] = [
        'page' => [
            'ok' => $page['ok'] ?? null,
            'status' => $page['status'] ?? null,
            'host' => $page['host'] ?? null,
        ],
        'release' => $relProbe,
    ];
    if (!$healthOk) {
        erpsmart_restore_backup($bak, $dest);
        throw new DeployError('health_failed_rolled_back', substr(json_encode($report['health']) ?: '', 0, 300));
    }

    $state['published_sha'] = $sha;
    $state['last_release_tag'] = $tag;
    $state['last_success_at'] = $rel['published_at'] ?? erpsmart_utc();
    $history = is_array($state['history'] ?? null) ? $state['history'] : [];
    array_unshift($history, ['sha' => $sha, 'tag' => $tag, 'at' => erpsmart_utc()]);
    $state['history'] = array_slice($history, 0, 20);
    erpsmart_save_state($bootstrap, $state);
    erpsmart_prune_backups($backupRoot);

    $report['status'] = 'APPLIED';
    $report['published_sha'] = $sha;
    $report['worker'] = erpsmart_optional_worker_update($bootstrap, $sha, false);
    $report['migrations'] = 'NOT_RUN';
    return $report;
}

/** @return array{ok:bool,checks:array<string,bool>} */
function erpsmart_selftest(string $work): array
{
    $checks = [];
    if (is_dir($work)) {
        erpsmart_rm_tree($work);
    }
    mkdir($work, 0755, true);
    $bootstrap = $work . '/bootstrap';
    $dest = $work . '/dest';
    foreach (['logs', 'state', 'locks', 'backups', 'work'] as $d) {
        mkdir($bootstrap . '/' . $d, 0755, true);
    }

    putenv('ERPSMART_CRON_ALLOW_FIXTURE=1');
    $prod = $work . '/excel.bcsrp';
    mkdir($prod, 0755, true);
    file_put_contents($prod . '/' . ErpsmartControlledSync::IDENTITY_MARKER, "excel.bcsrp:/home3/zzflgmfd/excel.bcsrp/\n");
    try {
        erpsmart_verify_dest_identity($prod);
        $checks['refuse_prod_identity'] = false;
    } catch (DeployError $e) {
        $checks['refuse_prod_identity'] = in_array($e->deployCode, [
            'refused_production_web_root',
            'refused_production_dest',
            'refused_production_path_fragment',
            'identity_marker_failed',
            'refused_non_canonical_dest',
            'refused_non_canonical_staging_dest',
        ], true);
    }

    mkdir($dest, 0755, true);
    file_put_contents($dest . '/' . ErpsmartControlledSync::IDENTITY_MARKER, ErpsmartControlledSync::EXPECTED_MARKER . "\n");
    mkdir($dest . '/app', 0755, true);
    file_put_contents($dest . '/app/config.php', "SECRET_KEEP\n");
    mkdir($dest . '/storage/uploads', 0755, true);
    file_put_contents($dest . '/storage/uploads/u.bin', "UPLOAD\n");
    file_put_contents($dest . '/index.php', "OLD\n");

    $badZip = $work . '/bad.zip';
    $z = new ZipArchive();
    $z->open($badZip, ZipArchive::CREATE);
    $z->addFromString('../escape.php', 'x');
    $z->close();
    try {
        erpsmart_safe_extract_zip($badZip, $work . '/extract_bad');
        $checks['zip_slip'] = false;
    } catch (DeployError $e) {
        $checks['zip_slip'] = ($e->deployCode === 'zip_slip_refused');
    }

    $pkg = $work . '/pkg';
    mkdir($pkg, 0755, true);
    file_put_contents($pkg . '/index.php', "NEW\n");
    $meta = [
        'git_sha' => 'abc123deadbeefcafebabe0123456789abcdef01',
        'file_manifest' => ['index.php', 'ERPSMART_STAGING_RELEASE.json'],
        'files_sha256' => ['index.php' => ErpsmartControlledSync::sha256File($pkg . '/index.php')],
    ];
    file_put_contents($pkg . '/ERPSMART_STAGING_RELEASE.json', json_encode($meta, JSON_PRETTY_PRINT) . "\n");
    $goodZip = $work . '/good.zip';
    $z = new ZipArchive();
    $z->open($goodZip, ZipArchive::CREATE);
    $z->addFile($pkg . '/index.php', 'index.php');
    $z->addFile($pkg . '/ERPSMART_STAGING_RELEASE.json', 'ERPSMART_STAGING_RELEASE.json');
    $z->close();
    $extract = $work . '/extract_good';
    erpsmart_safe_extract_zip($goodZip, $extract);
    erpsmart_verify_extracted_manifest($extract, ['git_sha' => $meta['git_sha']]);

    $ops = ErpsmartControlledSync::buildPlan($extract, $dest);
    $toWrite = [];
    foreach ($ops as $o) {
        if (in_array($o['op'], ['copy', 'scaffold_only'], true)) {
            $toWrite[] = $o['rel'];
        }
    }
    $bak = erpsmart_backup_dest($dest, $bootstrap . '/backups', $toWrite);
    ErpsmartControlledSync::applyPlan($extract, $dest, $ops, null);
    $checks['config_preserved'] = file_get_contents($dest . '/app/config.php') === "SECRET_KEEP\n";
    $checks['upload_preserved'] = file_get_contents($dest . '/storage/uploads/u.bin') === "UPLOAD\n";
    $checks['index_updated'] = file_get_contents($dest . '/index.php') === "NEW\n";
    $checks['backup_ok'] = is_file($bak . '/BACKUP_OK.json');

    $lock1 = new DeployLock($bootstrap);
    $lock1->acquire();
    try {
        (new DeployLock($bootstrap))->acquire();
        $checks['concurrent_lock'] = false;
    } catch (DeployError $e) {
        $checks['concurrent_lock'] = ($e->deployCode === 'concurrent_lock');
    }
    $lock1->release();

    try {
        erpsmart_parse_sha256_sidecar("deadbeef  good.zip\n", 'good.zip');
        $checks['bad_checksum_format'] = false;
    } catch (DeployError $e) {
        $checks['bad_checksum_format'] = true;
    }
    $goodDigest = ErpsmartControlledSync::sha256File($goodZip);
    $parsed = erpsmart_parse_sha256_sidecar($goodDigest . "  good.zip\n", 'good.zip');
    $checks['checksum_parse'] = ($parsed === $goodDigest);

    $checks['gates_reject'] = !erpsmart_gates_ok([
        'gates' => ['release-gate' => 'failure', 'rc1-mysql-php' => 'success'],
    ]);
    $checks['gates_accept'] = erpsmart_gates_ok([
        'gates' => ['release-gate' => 'success', 'rc1-mysql-php' => 'success'],
    ]);
    $checks['main_tag_ok'] = erpsmart_release_belongs_to_main(
        ['branch_required' => 'main'],
        ERPSMART_RELEASE_TAG_PREFIX . $meta['git_sha'],
        $meta['git_sha']
    );
    $checks['stale_tag_reject'] = !erpsmart_release_belongs_to_main(
        ['branch_required' => 'main'],
        ERPSMART_RELEASE_TAG_PREFIX . 'aaaaaaaaaaaa',
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    );

    file_put_contents($dest . '/index.php', "BROKEN\n");
    erpsmart_restore_backup($bak, $dest);
    $checks['rollback_restores'] = file_get_contents($dest . '/index.php') === "OLD\n";

    $health = ErpsmartStagingHealth::selftest();
    $checks['health_selftest'] = !empty($health['ok']);

    // Interrupted download leaves no final file
    $dlDest = $work . '/out.bin';
    try {
        // Invalid host should fail without leaving final
        erpsmart_https_download('https://127.0.0.1:1/nope', $dlDest, 2.0, 1000);
        $checks['interrupted_download'] = false;
    } catch (DeployError $e) {
        $checks['interrupted_download'] = !is_file($dlDest) && !is_file($dlDest . '.part');
    }

    // Backup fail → dest unchanged
    $bakAsFile = $work . '/bak_as_file';
    file_put_contents($bakAsFile, "not_a_dir\n");
    file_put_contents($dest . '/keep.php', "KEEP\n");
    try {
        erpsmart_backup_dest($dest, $bakAsFile, ['keep.php']);
        $checks['backup_fail_no_change'] = false;
    } catch (Throwable $e) {
        $checks['backup_fail_no_change'] = file_get_contents($dest . '/keep.php') === "KEEP\n";
    }

    // Empty manifest refuse
    try {
        erpsmart_verify_extracted_manifest($pkg, ['git_sha' => 'x', 'file_manifest' => []]);
        $checks['empty_manifest'] = false;
    } catch (DeployError $e) {
        $checks['empty_manifest'] = ($e->deployCode === 'manifest_empty');
    }

    $ok = !in_array(false, $checks, true);
    return ['ok' => $ok, 'checks' => $checks, 'php' => PHP_VERSION];
}

/** @return array<string,mixed> */
function erpsmart_status(string $bootstrap, array $cfg): array
{
    $state = erpsmart_load_state($bootstrap);
    $dest = (string) ($cfg['dest'] ?? rtrim(ErpsmartControlledSync::CANONICAL_DEST_HINT, '/'));
    $localMeta = null;
    $metaPath = rtrim($dest, '/\\') . '/ERPSMART_STAGING_RELEASE.json';
    if (is_file($metaPath)) {
        $localMeta = json_decode((string) file_get_contents($metaPath), true);
    }
    return [
        'ok' => true,
        'status' => 'STATUS',
        'published_sha' => $state['published_sha'] ?? null,
        'last_release_tag' => $state['last_release_tag'] ?? null,
        'last_success_at' => $state['last_success_at'] ?? null,
        'dest' => $dest,
        'dest_release_sha' => is_array($localMeta) ? ($localMeta['git_sha'] ?? null) : null,
        'enabled' => !empty($cfg['enabled']),
        'runtime' => 'php' . PHP_VERSION,
        'bootstrap' => $bootstrap,
        'history' => array_slice(is_array($state['history'] ?? null) ? $state['history'] : [], 0, 5),
        'note' => 'STAGING_DEPLOY_VERIFIED is never claimed from status alone',
    ];
}

/**
 * @param array<string,mixed> $cfg
 * @return array<string,mixed>
 */
function erpsmart_manual_rollback(string $bootstrap, array $cfg, ?string $backupDir): array
{
    $dest = (string) ($cfg['dest'] ?? rtrim(ErpsmartControlledSync::CANONICAL_DEST_HINT, '/'));
    erpsmart_verify_dest_identity($dest);
    $backupRoot = (string) ($cfg['backup_root'] ?? (rtrim($bootstrap, '/\\') . '/backups'));
    if ($backupDir === null || $backupDir === '') {
        $dirs = glob(rtrim($backupRoot, '/\\') . '/_erpsmart_staging_code_bak_*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        if ($dirs === []) {
            throw new DeployError('no_backup_available');
        }
        $backupDir = $dirs[0];
    }
    if (!is_dir($backupDir) || !is_file($backupDir . '/BACKUP_OK.json')) {
        throw new DeployError('backup_invalid', (string) $backupDir);
    }
    $beforeCfg = ErpsmartControlledSync::snapshotConfig($dest);
    $n = erpsmart_restore_backup($backupDir, $dest);
    $afterCfg = ErpsmartControlledSync::snapshotConfig($dest);
    if ($beforeCfg !== null && $afterCfg !== null && $beforeCfg !== $afterCfg) {
        // config should never be in backup write set normally; if changed, refuse claim
        throw new DeployError('rollback_config_changed_unexpected');
    }
    return [
        'ok' => true,
        'status' => 'ROLLED_BACK',
        'backup_dir' => $backupDir,
        'files_restored' => $n,
        'dest' => $dest,
        'migrations' => 'NOT_TOUCHED',
        'note' => 'Code rollback only — DB not restored',
    ];
}

function erpsmart_print_json(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function erpsmart_usage(): void
{
    $boot = ERPSMART_DEFAULT_BOOTSTRAP;
    echo <<<TXT
ErpSmart Staging Cron Deploy (PHP CLI) — excel2 only

Usage:
  php staging_cron_deploy.php --bootstrap PATH --config PATH [--dry-run|--apply|--status|--rollback|--selftest]

Cron (every 5 min):
  */5 * * * * cd {$boot} && /usr/bin/php {$boot}/bin/staging_cron_deploy.php --bootstrap {$boot} --config {$boot}/config.json --apply --i-understand-staging-risk >> {$boot}/logs/cron_deploy.log 2>&1

TXT;
}

// ---- CLI ----
$opts = getopt('', [
    'bootstrap:',
    'config:',
    'apply',
    'dry-run',
    'status',
    'rollback',
    'backup-dir:',
    'selftest',
    'selftest-work:',
    'print-cron-line',
    'i-understand-staging-risk',
    'help',
]);

if (isset($opts['help'])) {
    erpsmart_usage();
    exit(0);
}

$bootstrap = (string) ($opts['bootstrap'] ?? getenv('ERPSMART_DEPLOY_BOOTSTRAP') ?: ERPSMART_DEFAULT_BOOTSTRAP);

if (isset($opts['selftest'])) {
    $work = (string) ($opts['selftest-work'] ?? (sys_get_temp_dir() . '/staging_cron_selftest_php'));
    $result = erpsmart_selftest($work);
    erpsmart_print_json($result);
    exit(!empty($result['ok']) ? 0 : 1);
}

if (isset($opts['print-cron-line'])) {
    $phpBin = '/usr/bin/php';
    echo "*/5 * * * * cd {$bootstrap} && {$phpBin} {$bootstrap}/bin/staging_cron_deploy.php"
        . " --bootstrap {$bootstrap} --config {$bootstrap}/config.json --apply"
        . " --i-understand-staging-risk >> {$bootstrap}/logs/cron_deploy.log 2>&1\n";
    exit(0);
}

$configPath = (string) ($opts['config'] ?? ($bootstrap . '/config.json'));
if (!is_file($configPath)) {
    erpsmart_print_json(['ok' => false, 'error' => 'config_missing', 'path' => $configPath]);
    exit(2);
}
$cfg = erpsmart_load_config($configPath);

if (isset($opts['status'])) {
    erpsmart_print_json(erpsmart_status($bootstrap, $cfg));
    exit(0);
}

$lock = new DeployLock($bootstrap);
try {
    $lock->acquire();
} catch (DeployError $e) {
    erpsmart_print_json(['ok' => false, 'status' => 'REFUSED', 'error' => $e->deployCode, 'detail' => $e->detail]);
    exit(0); // concurrent: clean exit
}

try {
    if (isset($opts['rollback'])) {
        if (!isset($opts['i-understand-staging-risk'])) {
            erpsmart_print_json(['ok' => false, 'error' => 'rollback_requires_ack']);
            exit(3);
        }
        $report = erpsmart_manual_rollback($bootstrap, $cfg, $opts['backup-dir'] ?? null);
        erpsmart_log($bootstrap, 'OK ROLLBACK ' . ($report['backup_dir'] ?? ''));
        erpsmart_print_json($report);
        exit(0);
    }

    if (!empty($opts['apply']) && empty($cfg['enabled'])) {
        erpsmart_print_json(['ok' => true, 'status' => 'DISABLED', 'note' => 'set enabled=true in config.json']);
        exit(0);
    }

    $apply = isset($opts['apply']) && !isset($opts['dry-run']);
    if ($apply && !isset($opts['i-understand-staging-risk'])) {
        erpsmart_print_json(['ok' => false, 'error' => 'apply_requires_ack']);
        exit(3);
    }

    $report = erpsmart_run_cycle($cfg, $apply, isset($opts['dry-run']) || !$apply, $bootstrap);
    erpsmart_log($bootstrap, 'OK ' . ($report['status'] ?? '') . ' sha=' . ($report['git_sha'] ?? $report['published_sha'] ?? ''));
    erpsmart_print_json($report);
    exit(!empty($report['ok']) ? 0 : 1);
} catch (DeployError $e) {
    $report = ['ok' => false, 'status' => 'REFUSED', 'error' => $e->deployCode, 'detail' => $e->detail];
    erpsmart_log($bootstrap, 'ERROR ' . $e->deployCode . ' ' . $e->detail);
    erpsmart_print_json($report);
    exit(1);
} catch (Throwable $e) {
    $report = [
        'ok' => false,
        'status' => 'ERROR',
        'error' => get_class($e),
        'detail' => substr($e->getMessage(), 0, 200),
    ];
    erpsmart_log($bootstrap, 'ERROR ' . json_encode($report));
    erpsmart_print_json($report);
    exit(1);
} finally {
    $lock->release();
}
