<?php
/**
 * Controlled Sync security contracts for STAGING excel2 only (PHP 8.1+).
 * Port of scripts/agentic_controlled_cpanel_sync.py — no Composer.
 * Never overwrite app/config.php, storage user data, env, or unknown dest files.
 */
declare(strict_types=1);

final class ErpsmartControlledSync
{
    public const CANONICAL_DEST_HINT = '/home3/zzflgmfd/excel2.bcsrp/';
    public const IDENTITY_MARKER = '.erpsmart_deploy_identity';
    public const EXPECTED_MARKER = 'excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/';

    /** @var list<string> */
    public const ROOT_FILE_ALLOWLIST = [
        '.htaccess',
        'index.php',
        'openapi-v2.json',
        'openapi.json',
        'API_V2_SAAS_FA.md',
        'api_tokens.php',
        'api_v2.php',
        'v5_api.php',
        'accounting_api.php',
        'file_download.php',
        'v4_files.php',
        'api_docs.php',
        'api_token_admin.php',
        'api_v1.php',
        'io.php',
        'v3_2_api.php',
        'cron.php',
        'api.php',
        'ai_api.php',
        'ai_live.php',
        'copilot_api.php',
        'mcp_api.php',
        'migrate_v2.php',
        'README_FA.md',
        'INSTALL_CPanel_FA.md',
        'DEPLOY_GIT_CPanel_FA.md',
        'ERPSMART_STAGING_RELEASE.json',
    ];

    /** @var array<string, true> */
    public const ROOT_FILE_DENYLIST = [
        'install.php' => true,
    ];

    /** @var list<string> */
    public const DIR_ALLOWLIST = ['app', 'assets', 'sample_import'];

    /** @var array<string, true> */
    public const PRESERVE_EXACT = [
        'app/config.php' => true,
    ];

    /** @var list<string> */
    public const STORAGE_SCAFFOLD_ALLOWLIST = [
        'storage/.htaccess',
        'storage/cache/.htaccess',
        'storage/uploads/.htaccess',
        'storage/exports/.gitkeep',
        'storage/imports/.gitkeep',
        'storage/logs/.gitkeep',
        'storage/maintenance/.gitkeep',
        'storage/maintenance/.gitignore',
    ];

    /** @var array<string, true> */
    private const SKIP_NAME_PARTS = [
        '.git' => true,
        '__pycache__' => true,
        '.venv' => true,
        'node_modules' => true,
    ];

    public static function refuseNonStagingDest(string $dest): ?string
    {
        $destS = rtrim(str_replace('\\', '/', $dest), '/');
        if (str_contains($destS, '/home3/zzflgmfd/excel.bcsrp')
            && !str_contains($destS, '/home3/zzflgmfd/excel2.bcsrp')) {
            return 'refused_production_web_root';
        }
        if ((str_ends_with($destS, '/excel.bcsrp') || str_contains($destS . '/', '/excel.bcsrp/'))
            && !str_contains($destS, 'excel2.bcsrp')) {
            return 'refused_production_path_fragment';
        }
        $canonical = rtrim(self::CANONICAL_DEST_HINT, '/');
        if (str_contains($destS, 'zzflgmfd') && !str_contains($destS, $canonical)) {
            return 'refused_non_canonical_staging_dest';
        }
        return null;
    }

    public static function sha256File(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('sha256_failed:' . $path);
        }
        return $hash;
    }

    public static function posixRel(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
        return ltrim($path, '/');
    }

    public static function isSecretish(string $rel): bool
    {
        $low = strtolower(str_replace('\\', '/', $rel));
        if (isset(self::PRESERVE_EXACT[$low])) {
            return true;
        }
        $base = basename($low);
        if ($base === 'config.php' && str_starts_with($low, 'app/')) {
            return true;
        }
        $hints = [
            'config.php', '.env', 'config.json', 'config.cloud.json',
            'config.gemini.json', '.pem', '.key', '.pfx', '.sql',
        ];
        foreach ($hints as $hint) {
            if (!str_contains($low, $hint) || str_ends_with($low, '.htaccess')) {
                continue;
            }
            if (in_array($hint, ['.sql', '.pem', '.key', '.pfx', '.env'], true)) {
                return true;
            }
            if (str_starts_with($hint, 'config.') && str_contains($low, 'engine/')) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> absolute file paths */
    public static function iterDirFiles(string $srcRoot, string $relDir): array
    {
        $base = $srcRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $rel = self::posixRel($file->getPathname(), $srcRoot);
            $parts = explode('/', $rel);
            $skip = false;
            foreach ($parts as $part) {
                if (isset(self::SKIP_NAME_PARTS[$part])) {
                    $skip = true;
                    break;
                }
            }
            if ($skip || self::isSecretish($rel) || isset(self::PRESERVE_EXACT[$rel])) {
                continue;
            }
            $out[] = $file->getPathname();
        }
        sort($out);
        return $out;
    }

    /** @return array{ok:bool,reason:string,dest:string,marker_present:bool} */
    public static function verifyDestinationIdentity(string $dest, bool $requireMarker): array
    {
        $marker = rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . self::IDENTITY_MARKER;
        $info = [
            'dest' => realpath($dest) ?: $dest,
            'canonical_hint' => self::CANONICAL_DEST_HINT,
            'marker_path' => $marker,
            'marker_present' => is_file($marker),
            'ok' => false,
            'reason' => '',
        ];
        if (!file_exists($dest)) {
            $info['reason'] = 'dest_missing';
            return $info;
        }
        if (!is_dir($dest)) {
            $info['reason'] = 'dest_not_directory';
            return $info;
        }
        if ($requireMarker) {
            if (!is_file($marker)) {
                $info['reason'] = 'missing_deploy_identity_marker';
                return $info;
            }
            $body = trim((string) file_get_contents($marker));
            if ($body !== self::EXPECTED_MARKER) {
                $info['reason'] = 'identity_mismatch:got=' . $body;
                return $info;
            }
            $info['ok'] = true;
            $info['reason'] = 'identity_verified';
            return $info;
        }
        $info['ok'] = true;
        $info['reason'] = 'dry_run_identity_not_enforced';
        return $info;
    }

    /**
     * @return list<array{op:string,rel:string,src_sha256:?string,dst_sha256:?string,reason:string}>
     */
    public static function buildPlan(string $srcRoot, string $dest): array
    {
        $ops = [];
        foreach (array_keys(self::PRESERVE_EXACT) as $rel) {
            $src = $srcRoot . '/' . $rel;
            $dst = $dest . '/' . $rel;
            $ops[] = [
                'op' => 'skip_preserve',
                'rel' => $rel,
                'src_sha256' => is_file($src) ? self::sha256File($src) : null,
                'dst_sha256' => is_file($dst) ? self::sha256File($dst) : null,
                'reason' => 'never_overwrite_runtime_secret',
            ];
        }
        foreach (self::ROOT_FILE_ALLOWLIST as $rel) {
            if (isset(self::ROOT_FILE_DENYLIST[$rel])) {
                continue;
            }
            $src = $srcRoot . '/' . $rel;
            $dst = $dest . '/' . $rel;
            if (!is_file($src)) {
                $ops[] = [
                    'op' => 'skip_missing_src',
                    'rel' => $rel,
                    'src_sha256' => null,
                    'dst_sha256' => null,
                    'reason' => 'source_absent',
                ];
                continue;
            }
            $ops[] = [
                'op' => 'copy',
                'rel' => $rel,
                'src_sha256' => self::sha256File($src),
                'dst_sha256' => is_file($dst) ? self::sha256File($dst) : null,
                'reason' => 'root_allowlist',
            ];
        }
        foreach (self::DIR_ALLOWLIST as $d) {
            foreach (self::iterDirFiles($srcRoot, $d) as $srcPath) {
                $rel = self::posixRel($srcPath, $srcRoot);
                $dst = $dest . '/' . $rel;
                $ops[] = [
                    'op' => 'copy',
                    'rel' => $rel,
                    'src_sha256' => self::sha256File($srcPath),
                    'dst_sha256' => is_file($dst) ? self::sha256File($dst) : null,
                    'reason' => 'dir_allowlist:' . $d,
                ];
            }
        }
        foreach (self::STORAGE_SCAFFOLD_ALLOWLIST as $rel) {
            $src = $srcRoot . '/' . $rel;
            $dst = $dest . '/' . $rel;
            if (!is_file($src)) {
                $ops[] = [
                    'op' => 'skip_missing_src',
                    'rel' => $rel,
                    'src_sha256' => null,
                    'dst_sha256' => null,
                    'reason' => 'scaffold_src_absent',
                ];
                continue;
            }
            if (is_file($dst)) {
                $ops[] = [
                    'op' => 'skip_preserve',
                    'rel' => $rel,
                    'src_sha256' => self::sha256File($src),
                    'dst_sha256' => self::sha256File($dst),
                    'reason' => 'storage_scaffold_exists_preserve',
                ];
            } else {
                $ops[] = [
                    'op' => 'scaffold_only',
                    'rel' => $rel,
                    'src_sha256' => self::sha256File($src),
                    'dst_sha256' => null,
                    'reason' => 'storage_scaffold_missing',
                ];
            }
        }
        foreach (['storage/uploads', 'storage/logs', 'storage/cache', 'storage/exports', 'storage/imports'] as $tree) {
            $ops[] = [
                'op' => 'skip_preserve',
                'rel' => $tree,
                'src_sha256' => null,
                'dst_sha256' => null,
                'reason' => 'storage_user_data_byte_for_byte_out_of_scope',
            ];
        }
        return $ops;
    }

    /**
     * @param list<array{op:string,rel:string}> $ops
     * @return array{written:int,backup:?array,deleted:int}
     */
    public static function applyPlan(string $srcRoot, string $dest, array $ops, ?string $backupRoot = null): array
    {
        $toWrite = array_values(array_filter(
            $ops,
            static fn(array $o): bool => in_array($o['op'], ['copy', 'scaffold_only'], true)
        ));
        $backupInfo = null;
        if ($backupRoot !== null) {
            $rels = array_map(static fn(array $o): string => $o['rel'], $toWrite);
            $backupInfo = self::backupExisting($dest, $backupRoot, $rels);
        }
        $written = 0;
        foreach ($toWrite as $o) {
            $src = $srcRoot . '/' . $o['rel'];
            $dst = $dest . '/' . $o['rel'];
            $parent = dirname($dst);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('mkdir_failed:' . $parent);
            }
            if (!copy($src, $dst)) {
                throw new RuntimeException('copy_failed:' . $o['rel']);
            }
            $written++;
        }
        return ['written' => $written, 'backup' => $backupInfo, 'deleted' => 0];
    }

    /**
     * @param list<string> $rels
     * @return array{backup_dir:string,files:int}
     */
    public static function backupExisting(string $dest, string $backupRoot, array $rels): array
    {
        $stamp = gmdate('Ymd\THis\Z');
        $bak = rtrim($backupRoot, '/\\') . DIRECTORY_SEPARATOR . 'controlled_sync_backup_' . $stamp;
        if (!is_dir($bak) && !mkdir($bak, 0755, true) && !is_dir($bak)) {
            throw new RuntimeException('backup_mkdir_failed');
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
                throw new RuntimeException('backup_parent_failed');
            }
            if (!copy($src, $target)) {
                throw new RuntimeException('backup_copy_failed:' . $rel);
            }
            $copied++;
        }
        return ['backup_dir' => $bak, 'files' => $copied];
    }

    /** @return array<string,string> */
    public static function snapshotStorage(string $dest): array
    {
        $out = [];
        $storage = rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        foreach ($files as $path) {
            $out[self::posixRel($path, $dest)] = self::sha256File($path);
        }
        return $out;
    }

    public static function snapshotConfig(string $dest): ?string
    {
        $cfg = rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config.php';
        return is_file($cfg) ? self::sha256File($cfg) : null;
    }

    /**
     * @param array<string,string> $before
     * @return array{ok:bool,mutated:list<string>,deleted:list<string>,added:list<string>}
     */
    public static function manifestCompareStorage(string $dest, array $before): array
    {
        $after = self::snapshotStorage($dest);
        $mutated = [];
        foreach ($before as $k => $v) {
            if (($after[$k] ?? null) !== $v) {
                $mutated[] = $k;
            }
        }
        sort($mutated);
        $deleted = array_values(array_diff(array_keys($before), array_keys($after)));
        sort($deleted);
        $added = array_values(array_diff(array_keys($after), array_keys($before)));
        sort($added);
        $scaffoldOk = true;
        foreach ($added as $k) {
            if (!(str_ends_with($k, '/.htaccess')
                || str_ends_with($k, '/.gitkeep')
                || str_ends_with($k, '.gitignore'))) {
                $scaffoldOk = false;
                break;
            }
        }
        $existingPreserved = $mutated === [] && $deleted === [];
        return [
            'before_count' => count($before),
            'after_count' => count($after),
            'mutated' => $mutated,
            'deleted' => $deleted,
            'added' => $added,
            'existing_byte_for_byte_preserved' => $existingPreserved,
            'additive_scaffold_only' => $scaffoldOk,
            'byte_for_byte_preserved' => $existingPreserved,
            'ok' => $existingPreserved && $scaffoldOk,
        ];
    }
}
