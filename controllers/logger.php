<?php
function security_log_file() {
    return __DIR__ . '/../data/security.log';
}

function log_security_event(string $tag, string $details) {
    $file = security_log_file();
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))
        ->format('d-m-Y H:i:s');
    $line = sprintf("[%s] %s - %s\n", $timestamp, $tag, $details);
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
