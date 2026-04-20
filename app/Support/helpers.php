<?php

namespace App\Support;

function app_config(?string $key = null, $default = null) {
    static $config;

    if ($config === null) {
        $config = require APP_BASE_PATH . '/config/app.php';
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function app_base_path(string $path = ''): string
{
    $base = APP_BASE_PATH;
    return $path === '' ? $base : $base . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

function app_base_url(string $path = ''): string
{
    $baseUrl = rtrim((string) app_config('base_url', ''), '/');
    if ($path === '') {
        return $baseUrl;
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function ensure_path(string $path, bool $isDir = false): void
{
    if ($isDir) {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        return;
    }

    if (!file_exists($path)) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function bootstrap_app(): void
{
    ensure_path(app_base_path('data'), true);
    ensure_path(app_base_path('data/logs'), true);
    ensure_path(app_base_path('data/mensaje.json'));
    ensure_path(app_base_path('data/usuarios.json'));
    ensure_path(app_base_path('data/configuracion.json'));
    ensure_path(app_base_path('data/horasExtras'), true);
    ensure_path(app_base_path('data/reportes'), true);

    $logFile = app_base_path('data/logs/php-error.log');
    ini_set('log_errors', '1');
    ini_set('error_log', $logFile);
    if (!file_exists($logFile)) {
        @file_put_contents($logFile, '');
    }
}
