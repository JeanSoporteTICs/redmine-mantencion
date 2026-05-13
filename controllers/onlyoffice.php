<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/procedimientos.php';
require_once __DIR__ . '/storage.php';

function onlyoffice_config(): array {
    $file = __DIR__ . '/../data/configuracion.json';
    $cfg = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    return is_array($cfg) ? $cfg : [];
}

function onlyoffice_base_url(): string {
    $cfg = onlyoffice_config();
    $configuredUrl = rtrim(trim((string)($cfg['onlyoffice_app_url'] ?? '')), '/');
    if ($configuredUrl !== '') {
        return $configuredUrl;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/redmine-mantencion';
}

function onlyoffice_absolute_url(string $url): string {
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $base = onlyoffice_base_url();
    $baseParts = parse_url($base);
    $basePath = rtrim((string)($baseParts['path'] ?? ''), '/');
    if ($basePath !== '' && str_starts_with($url, $basePath . '/')) {
        $origin = (string)($baseParts['scheme'] ?? 'http') . '://' . (string)($baseParts['host'] ?? 'localhost');
        if (!empty($baseParts['port'])) {
            $origin .= ':' . (string)$baseParts['port'];
        }
        return $origin . $url;
    }
    return $base . '/' . ltrim($url, '/');
}

function onlyoffice_file_type(array $record): string {
    return procedures_file_extension((string)($record['file_name'] ?? $record['file_original_name'] ?? 'docx'));
}

function onlyoffice_document_type(string $fileType): string {
    if (in_array($fileType, ['xls', 'xlsx'], true)) {
        return 'cell';
    }
    if (in_array($fileType, ['ppt', 'pptx'], true)) {
        return 'slide';
    }
    return 'word';
}

function onlyoffice_document_key(array $record): string {
    $seed = implode('|', [
        (string)($record['id'] ?? ''),
        (string)($record['file_name'] ?? ''),
        (string)($record['updated_at'] ?? ''),
        (string)($record['file_size'] ?? ''),
    ]);
    return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', base64_encode(hash('sha256', $seed, true))) ?? '', 0, 40);
}

function onlyoffice_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function onlyoffice_jwt_encode(array $payload, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        onlyoffice_base64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
        onlyoffice_base64url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
    $segments[] = onlyoffice_base64url($signature);
    return implode('.', $segments);
}

function onlyoffice_base64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : '';
}

function onlyoffice_jwt_verify(string $token, string $secret): bool {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$header, $payload, $signature] = $parts;
    $expected = onlyoffice_base64url(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    return hash_equals($expected, $signature);
}

function onlyoffice_request_token(array $payload): string {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }
    return trim((string)($payload['token'] ?? ''));
}

function onlyoffice_editor_config(array $record, array $cfg, string $mode = 'edit'): array {
    $fileType = onlyoffice_file_type($record);
    $documentUrl = onlyoffice_absolute_url((string)($record['file_url'] ?? ''));
    $callbackUrl = onlyoffice_absolute_url('/controllers/onlyoffice.php?action=callback&id=' . rawurlencode((string)$record['id']));
    $sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
    $userId = trim((string)($sessionUser['id'] ?? 'public'));
    $userName = trim((string)($sessionUser['nombre'] ?? 'Invitado'));
    $canEdit = $mode !== 'view' && auth_can('procedimientos_editar');

    $config = [
        'document' => [
            'fileType' => $fileType,
            'key' => onlyoffice_document_key($record),
            'title' => (string)($record['file_original_name'] ?: $record['title']),
            'url' => $documentUrl,
            'permissions' => [
                'edit' => $canEdit,
                'comment' => $canEdit,
                'download' => true,
                'fillForms' => $canEdit,
                'modifyContentControl' => $canEdit,
                'modifyFilter' => $canEdit,
                'print' => true,
                'review' => $canEdit,
            ],
        ],
        'documentType' => onlyoffice_document_type($fileType),
        'editorConfig' => [
            'callbackUrl' => $callbackUrl,
            'coEditing' => [
                'mode' => 'fast',
                'change' => false,
            ],
            'lang' => 'es-CL',
            'region' => 'es-CL',
            'mode' => $canEdit ? 'edit' : 'view',
            'user' => [
                'id' => $userId !== '' ? $userId : 'public',
                'name' => $userName !== '' ? $userName : 'Invitado',
            ],
            'customization' => [
                'autosave' => false,
                'forcesave' => true,
            ],
        ],
        'height' => '100%',
        'width' => '100%',
    ];

    $secret = trim((string)($cfg['onlyoffice_jwt_secret'] ?? ''));
    if ($secret !== '') {
        $config['token'] = onlyoffice_jwt_encode($config, $secret);
    }
    return $config;
}

function onlyoffice_callback_response(int $error = 0): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

function onlyoffice_handle_callback(): void {
    $id = trim((string)($_GET['id'] ?? ''));
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if ($id === '' || !is_array($payload)) {
        onlyoffice_callback_response(1);
    }

    $cfg = onlyoffice_config();
    $secret = trim((string)($cfg['onlyoffice_jwt_secret'] ?? ''));
    if ($secret !== '' && !onlyoffice_jwt_verify(onlyoffice_request_token($payload), $secret)) {
        onlyoffice_callback_response(1);
    }

    $status = (int)($payload['status'] ?? 0);
    if (!in_array($status, [2, 6], true)) {
        onlyoffice_callback_response(0);
    }

    $downloadUrl = trim((string)($payload['url'] ?? ''));
    if ($downloadUrl === '') {
        onlyoffice_callback_response(1);
    }

    $items = procedures_read_all();
    $record = procedures_find_by_id($items, $id);
    if (!$record || empty($record['file_name'])) {
        onlyoffice_callback_response(1);
    }

    $target = procedures_documents_dir() . '/' . basename((string)$record['file_name']);
    $context = stream_context_create(['http' => ['timeout' => 30]]);
    $content = @file_get_contents($downloadUrl, false, $context);
    if (!is_string($content) || $content === '') {
        onlyoffice_callback_response(1);
    }
    storage_write_file_locked($target, $content, 0, true);

    foreach ($items as $index => $item) {
        if ((string)($item['id'] ?? '') === $id) {
            $items[$index]['file_size'] = filesize($target) ?: (int)($item['file_size'] ?? 0);
            $items[$index]['file_mime'] = procedures_detect_file_mime($target, (string)($item['file_mime'] ?? ''));
            $items[$index]['updated_at'] = date('c');
            if (!empty($item['draft_pending']) && $status === 6) {
                $items[$index]['draft_pending'] = false;
            }
            break;
        }
    }
    procedures_write_all($items);
    onlyoffice_callback_response(0);
}

if (($_GET['action'] ?? '') === 'callback') {
    onlyoffice_handle_callback();
}
