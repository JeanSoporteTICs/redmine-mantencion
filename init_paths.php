<?php
// Asegura la existencia de rutas de datos clave.
function ensure_path($path, $isDir = false) {
    if ($isDir) {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return;
    }
    if (!file_exists($path)) {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

ensure_path(__DIR__ . '/data', true);
ensure_path(__DIR__ . '/data/logs', true);
ensure_path(__DIR__ . '/data/mensaje.json');
ensure_path(__DIR__ . '/data/usuarios.json');
ensure_path(__DIR__ . '/data/configuracion.json');
ensure_path(__DIR__ . '/data/horasExtras', true);
ensure_path(__DIR__ . '/data/reportes', true);
ensure_path(__DIR__ . '/data/logs', true);

// Configurar error_log dedicado
$logFile = __DIR__ . '/data/logs/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
if (!file_exists($logFile)) {
    @file_put_contents($logFile, '');
}

