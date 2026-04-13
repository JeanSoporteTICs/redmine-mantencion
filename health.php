<?php
header('Content-Type: application/json; charset=utf-8');

$paths = [
    __DIR__ . '/data/mensaje.json',
    __DIR__ . '/data/usuarios.json',
    __DIR__ . '/data/configuracion.json',
];

$status = [
    'ok' => true,
    'checks' => [],
];

foreach ($paths as $p) {
    $name = basename($p);
    if (!file_exists($p)) {
        $status['ok'] = false;
        $status['checks'][$name] = 'missing';
        continue;
    }
    $contents = file_get_contents($p);
    $status['checks'][$name] = $contents !== false ? 'ok' : 'read_fail';
    if ($contents === false) $status['ok'] = false;
}

// valida directorios de salida
$dirs = [
    __DIR__ . '/data/horasExtras',
    __DIR__ . '/data/reportes',
    __DIR__ . '/data/logs',
];
foreach ($dirs as $d) {
    $name = basename($d);
    if (!is_dir($d)) {
        $status['ok'] = false;
        $status['checks'][$name] = 'missing_dir';
    } else {
        $status['checks'][$name] = 'ok';
    }
}

// verificar error_log
$logFile = ini_get('error_log');
if ($logFile) {
    if (is_writable($logFile) || (!file_exists($logFile) && is_writable(dirname($logFile)))) {
        $status['checks']['error_log'] = 'ok';
    } else {
        $status['checks']['error_log'] = 'not_writable';
        $status['ok'] = false;
    }
}

http_response_code($status['ok'] ? 200 : 500);
echo json_encode($status, JSON_UNESCAPED_UNICODE);
?>
