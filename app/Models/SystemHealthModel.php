<?php

namespace App\Models;

class SystemHealthModel
{
    public function status(): array
    {
        $paths = [
            APP_BASE_PATH . '/data/mensaje.json',
            APP_BASE_PATH . '/data/usuarios.json',
            APP_BASE_PATH . '/data/configuracion.json',
        ];

        $status = [
            'ok' => true,
            'checks' => [],
            'warnings' => [],
            'meta' => [
                'checked_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('c'),
                'php_version' => PHP_VERSION,
            ],
        ];

        foreach ($paths as $path) {
            $name = basename($path);
            if (!file_exists($path)) {
                $status['ok'] = false;
                $status['checks'][$name] = 'missing';
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                $status['checks'][$name] = 'read_fail';
                $status['ok'] = false;
                continue;
            }
            $decoded = json_decode($contents, true);
            $status['checks'][$name] = is_array($decoded) ? 'ok' : 'invalid_json';
            if (!is_array($decoded)) {
                $status['ok'] = false;
            }
            if (!is_writable($path)) {
                $status['checks'][$name . '_writable'] = 'not_writable';
                $status['ok'] = false;
            }
        }

        $dirs = [
            APP_BASE_PATH . '/data/horasExtras',
            APP_BASE_PATH . '/data/reportes',
            APP_BASE_PATH . '/data/logs',
        ];

        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (!is_dir($dir)) {
                $status['ok'] = false;
                $status['checks'][$name] = 'missing_dir';
            } elseif (!is_writable($dir)) {
                $status['ok'] = false;
                $status['checks'][$name] = 'not_writable';
            } else {
                $status['checks'][$name] = 'ok';
            }
        }

        $config = require APP_BASE_PATH . '/config/app.php';
        $status['meta']['app_env'] = $config['env'] ?? 'production';
        $extensions = $config['required_extensions'] ?? ['json', 'curl', 'mbstring', 'openssl', 'zip', 'xml'];
        foreach ($extensions as $extension) {
            $key = 'ext_' . $extension;
            if (extension_loaded($extension)) {
                $status['checks'][$key] = 'ok';
            } else {
                $status['checks'][$key] = 'missing';
                $status['ok'] = false;
            }
        }

        $backupRoot = APP_BASE_PATH . '/data/backups';
        if (is_dir($backupRoot) || is_writable(dirname($backupRoot))) {
            $status['checks']['backups'] = 'ok';
        } else {
            $status['checks']['backups'] = 'not_writable';
            $status['ok'] = false;
        }
        $lastBackupFile = APP_BASE_PATH . '/data/backups/.last_auto_backup';
        $lastBackup = is_file($lastBackupFile) ? trim((string)file_get_contents($lastBackupFile)) : '';
        if ($lastBackup === date('Y-m-d')) {
            $status['checks']['last_auto_backup'] = 'ok';
        } else {
            $status['checks']['last_auto_backup'] = $lastBackup !== '' ? 'stale:' . $lastBackup : 'missing';
            $status['warnings']['last_auto_backup'] = 'No hay backup automatico registrado para hoy.';
        }

        if (!empty($config['debug'])) {
            $status['warnings']['app_debug'] = 'APP_DEBUG esta activo; debe quedar apagado en produccion.';
            if (($config['env'] ?? 'production') === 'production') {
                $status['ok'] = false;
            }
        }

        $cfgPath = APP_BASE_PATH . '/data/configuracion.json';
        $cfg = is_file($cfgPath) ? json_decode((string)file_get_contents($cfgPath), true) : [];
        if (is_array($cfg)) {
            foreach (['platform_url', 'project_id', 'tracker_id', 'priority_id', 'status_id'] as $key) {
                if (($cfg[$key] ?? '') === '' || $cfg[$key] === null) {
                    $status['checks']['config_' . $key] = 'missing';
                    $status['ok'] = false;
                } else {
                    $status['checks']['config_' . $key] = 'ok';
                }
            }
            if (empty($cfg['platform_token'])) {
                $status['warnings']['platform_token'] = 'No hay token global configurado; se usara token por usuario si existe.';
            }
        }

        $logFile = ini_get('error_log');
        if ($logFile) {
            if (is_writable($logFile) || (!file_exists($logFile) && is_writable(dirname($logFile)))) {
                $status['checks']['error_log'] = 'ok';
            } else {
                $status['checks']['error_log'] = 'not_writable';
                $status['ok'] = false;
            }
        }

        return $status;
    }
}
