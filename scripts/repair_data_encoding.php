<?php

require_once __DIR__ . '/../controllers/dashboard.php';

$targets = [
    __DIR__ . '/../data/mensaje.json',
    __DIR__ . '/../data/reportes',
    __DIR__ . '/../data/horasExtras',
];

foreach ($targets as $target) {
    if (is_file($target)) {
        repair_json_file($target);
        continue;
    }
    if (!is_dir($target)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'json') {
            continue;
        }
        repair_json_file($fileInfo->getPathname());
    }
}

function repair_json_file(string $path): void
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return;
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return;
    }
    $repaired = dashboard_repair_structure_encoding($decoded);
    file_put_contents(
        $path,
        json_encode($repaired, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}
