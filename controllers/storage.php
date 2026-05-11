<?php

if (!function_exists('storage_base_path')) {
    function storage_base_path(string $path = ''): string {
        $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);
        return $path === '' ? $base : $base . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    function storage_data_path(string $path = ''): string {
        return storage_base_path('data' . ($path === '' ? '' : '/' . ltrim(str_replace('\\', '/', $path), '/')));
    }

    function storage_ensure_dir(string $dir): void {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    function storage_json_flags(): int {
        return JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    }

    function storage_relative_data_path(string $path): ?string {
        $dataRoot = realpath(storage_data_path());
        $dir = realpath(dirname($path));
        if ($dataRoot === false || $dir === false) {
            return null;
        }
        $full = $dir . DIRECTORY_SEPARATOR . basename($path);
        $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        $fullNorm = str_replace('\\', '/', $full);
        if ($fullNorm !== $dataRoot && strpos($fullNorm, $dataRoot . '/') !== 0) {
            return null;
        }
        $rel = ltrim(substr($fullNorm, strlen($dataRoot)), '/');
        return $rel === '' ? null : $rel;
    }

    function storage_backup_file(string $path): void {
        static $done = [];
        if (!is_file($path) || filesize($path) === 0) {
            return;
        }
        $rel = storage_relative_data_path($path);
        if ($rel === null || strpos($rel, 'backups/') === 0 || strpos($rel, 'logs/') === 0) {
            return;
        }
        $real = realpath($path);
        if ($real === false || isset($done[$real])) {
            return;
        }
        $done[$real] = true;

        $dest = storage_data_path('backups/on-write/' . date('Y-m-d') . '/' . $rel . '.' . date('His') . '.bak');
        storage_ensure_dir(dirname($dest));
        @copy($path, $dest);
    }

    function storage_write_file_locked(string $path, string $contents, int $flags = 0, bool $backup = true): bool {
        storage_ensure_dir(dirname($path));
        $append = (bool)($flags & FILE_APPEND);
        if (!$append && $backup) {
            storage_backup_file($path);
        }
        $handle = @fopen($path, $append ? 'ab' : 'c+b');
        if (!$handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }
        if (!$append) {
            ftruncate($handle, 0);
            rewind($handle);
        }
        $ok = fwrite($handle, $contents) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $ok;
    }

    function storage_write_json(string $path, $data, ?int $flags = null, bool $backup = true): bool {
        $json = json_encode($data, $flags ?? storage_json_flags());
        if ($json === false) {
            return false;
        }
        return storage_write_file_locked($path, $json, 0, $backup);
    }

    function storage_append_line(string $path, string $line): bool {
        return storage_write_file_locked($path, rtrim($line, "\r\n") . PHP_EOL, FILE_APPEND, false);
    }

    function storage_truncate_file(string $path): bool {
        return storage_write_file_locked($path, '', 0, true);
    }

    function storage_copy_recursive(string $source, string $dest): void {
        if (is_file($source)) {
            storage_ensure_dir(dirname($dest));
            @copy($source, $dest);
            return;
        }
        if (!is_dir($source)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                storage_ensure_dir($target);
            } elseif ($item->isFile()) {
                storage_ensure_dir(dirname($target));
                @copy($item->getPathname(), $target);
            }
        }
    }

    function storage_prune_backups(int $retentionDays = 30): void {
        $root = storage_data_path('backups');
        if (!is_dir($root)) {
            return;
        }
        $limit = time() - max(1, $retentionDays) * 86400;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getMTime() < $limit) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }

    function storage_run_auto_backup(?array $paths = null): void {
        if (getenv('APP_BACKUP_ENABLED') === '0') {
            return;
        }
        $today = date('Y-m-d');
        $marker = storage_data_path('backups/.last_auto_backup');
        storage_ensure_dir(dirname($marker));
        $handle = @fopen($marker, 'c+b');
        if (!$handle) {
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }
        $current = trim(stream_get_contents($handle));
        if ($current === $today) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $paths = $paths ?? [
            'mensaje.json',
            'usuarios.json',
            'roles.json',
            'configuracion.json',
            'categorias.json',
            'procedimientos',
            'reportes',
            'horasExtras',
        ];
        $destRoot = storage_data_path('backups/auto/' . $today);
        foreach ($paths as $rel) {
            $source = storage_data_path($rel);
            if (file_exists($source)) {
                storage_copy_recursive($source, $destRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
            }
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $today);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        storage_prune_backups((int)(getenv('APP_BACKUP_RETENTION_DAYS') ?: 30));
    }
}
