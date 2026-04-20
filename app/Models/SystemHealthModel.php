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
        ];

        foreach ($paths as $path) {
            $name = basename($path);
            if (!file_exists($path)) {
                $status['ok'] = false;
                $status['checks'][$name] = 'missing';
                continue;
            }

            $contents = file_get_contents($path);
            $status['checks'][$name] = $contents !== false ? 'ok' : 'read_fail';
            if ($contents === false) {
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
            } else {
                $status['checks'][$name] = 'ok';
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
