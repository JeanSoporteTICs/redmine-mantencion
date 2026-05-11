<?php

require_once __DIR__ . '/storage.php';

function core_credentials_key(): string {
    $envKey = trim((string)(getenv('CORE_CREDENTIAL_KEY') ?: getenv('APP_KEY') ?: ''));
    if ($envKey !== '') {
        return hash('sha256', $envKey, true);
    }
    $keyFile = __DIR__ . '/../data/app.key';
    if (is_file($keyFile)) {
        $stored = trim((string)file_get_contents($keyFile));
        if ($stored !== '') {
            return hash('sha256', $stored, true);
        }
    }
    $generated = bin2hex(random_bytes(32));
    storage_write_file_locked($keyFile, $generated, 0, false);
    return hash('sha256', $generated, true);
}

function core_credentials_encrypt(string $plain): string {
    $plain = trim($plain);
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return '';
    }
    $iv = random_bytes(16);
    $key = core_credentials_key();
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return '';
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
    return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($cipher) . ':' . base64_encode($mac);
}

function core_credentials_decrypt(string $payload): string {
    $payload = trim($payload);
    if ($payload === '' || !str_starts_with($payload, 'enc:v1:') || !function_exists('openssl_decrypt')) {
        return '';
    }
    $parts = explode(':', $payload, 5);
    if (count($parts) !== 5) {
        return '';
    }
    $iv = base64_decode($parts[2], true);
    $cipher = base64_decode($parts[3], true);
    $mac = base64_decode($parts[4], true);
    if ($iv === false || $cipher === false || $mac === false) {
        return '';
    }
    $key = core_credentials_key();
    $expected = hash_hmac('sha256', $iv . $cipher, $key, true);
    if (!hash_equals($expected, $mac)) {
        return '';
    }
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : trim($plain);
}

function core_credentials_users_file(): string {
    return __DIR__ . '/../data/usuarios.json';
}

function core_credentials_load_users(): array {
    $file = core_credentials_users_file();
    $rows = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    return is_array($rows) ? $rows : [];
}

function core_credentials_save_users(array $rows): bool {
    return storage_write_json(core_credentials_users_file(), array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function core_credentials_for_user(string $userId): array {
    if ($userId === '') {
        return ['user' => '', 'pass' => ''];
    }
    foreach (core_credentials_load_users() as $row) {
        if (!is_array($row) || (string)($row['id'] ?? '') !== $userId) {
            continue;
        }
        return [
            'user' => trim((string)($row['core_user'] ?? '')),
            'pass' => core_credentials_decrypt((string)($row['core_pass_enc'] ?? '')),
        ];
    }
    return ['user' => '', 'pass' => ''];
}

function core_credentials_has_saved(string $userId): bool {
    $credentials = core_credentials_for_user($userId);
    return trim((string)$credentials['user']) !== '' && trim((string)$credentials['pass']) !== '';
}

function core_credentials_save_for_user(string $userId, string $coreUser, string $corePass): bool {
    $userId = trim($userId);
    $coreUser = trim($coreUser);
    $corePass = trim($corePass);
    if ($userId === '' || $coreUser === '' || $corePass === '') {
        return false;
    }
    $rows = core_credentials_load_users();
    foreach ($rows as &$row) {
        if (!is_array($row) || (string)($row['id'] ?? '') !== $userId) {
            continue;
        }
        $row['core_user'] = $coreUser;
        $row['core_pass_enc'] = core_credentials_encrypt($corePass);
        unset($row['core_pass']);
        return core_credentials_save_users($rows);
    }
    unset($row);
    return false;
}

function core_credentials_clear_for_user(string $userId): bool {
    $rows = core_credentials_load_users();
    $changed = false;
    foreach ($rows as &$row) {
        if (!is_array($row) || (string)($row['id'] ?? '') !== $userId) {
            continue;
        }
        $row['core_user'] = '';
        $row['core_pass_enc'] = '';
        unset($row['core_pass']);
        $changed = true;
        break;
    }
    unset($row);
    return $changed ? core_credentials_save_users($rows) : true;
}
