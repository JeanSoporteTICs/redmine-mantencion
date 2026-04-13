<?php
require_once __DIR__ . '/logger.php';

function security_load_events(int $limit = 20): array {
    $file = security_log_file();
    if (!file_exists($file)) {
        return [];
    }
    $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $events = [];
    foreach ($lines as $line) {
        if (count($events) >= $limit) {
            break;
        }
        if (preg_match('/^\[([^]]+)\]\s+([A-Z_]+)\s+-\s+(.*)$/', $line, $m)) {
            $events[] = ['ts' => $m[1], 'tag' => $m[2], 'details' => $m[3]];
        } elseif ($line !== '') {
            $events[] = ['ts' => '', 'tag' => 'LOG', 'details' => $line];
        }
    }
    return $events;
}

function security_clear_events(): bool {
    $file = security_log_file();
    if (!file_exists($file)) {
        return true;
    }
    return file_put_contents($file, '') !== false;
}
