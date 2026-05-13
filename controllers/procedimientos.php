<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/maintenance.php';

function procedures_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['procedures_flash'] = $message;
}

function procedures_storage_dir(): string {
    return __DIR__ . '/../data/procedimientos';
}

function procedures_images_dir(): string {
    return procedures_storage_dir() . '/imagenes';
}

function procedures_documents_dir(): string {
    return procedures_storage_dir() . '/documentos';
}

function procedures_legacy_data_file(): string {
    return __DIR__ . '/../data/procedimientos.json';
}

function procedures_data_file(): string {
    return procedures_storage_dir() . '/index.json';
}

function procedures_ensure_storage(): void {
    $legacyFile = procedures_legacy_data_file();
    $file = procedures_data_file();
    $dir = procedures_storage_dir();
    $imagesDir = procedures_images_dir();
    $documentsDir = procedures_documents_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (file_exists($legacyFile) && !file_exists($file)) {
        @rename($legacyFile, $file);
    }
    if (!is_dir($imagesDir)) {
        mkdir($imagesDir, 0777, true);
    }
    if (!is_dir($documentsDir)) {
        mkdir($documentsDir, 0777, true);
    }
    if (!file_exists($file)) {
        storage_write_json($file, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, false);
    }
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        procedures_migrate_embedded_images();
    }
}

function procedures_read_all(): array {
    procedures_ensure_storage();
    $raw = @file_get_contents(procedures_data_file());
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $items = [];
    $changed = false;
    foreach ($data as $row) {
        if (is_array($row)) {
            $normalized = procedures_normalize_record($row);
            if ($normalized != $row) {
                $changed = true;
            }
            $items[] = $normalized;
        }
    }
    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    if ($changed) {
        storage_write_json(procedures_data_file(), array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $items;
}

function procedures_write_all(array $items): bool {
    procedures_ensure_storage();
    $payload = array_values(array_map('procedures_normalize_record', $items));
    return storage_write_json(procedures_data_file(), $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function procedures_normalize_record(array $row): array {
    $now = date('c');
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') {
        $id = procedures_generate_id();
    }
    $recordType = strtolower(trim((string)($row['record_type'] ?? $row['type'] ?? 'document')));
    if (!in_array($recordType, ['document', 'folder'], true)) {
        $recordType = 'document';
    }
    if ($recordType === 'folder' && !str_starts_with($id, 'folder-')) {
        $id = procedures_generate_folder_id();
    }
    $folderId = trim((string)($row['folder_id'] ?? ''));
    if ($recordType === 'folder') {
        $folderId = '';
    }
    $content = procedures_prepare_content_html((string)($row['content_html'] ?? ''), $id);
    $pageSize = strtolower(trim((string)($row['page_size'] ?? 'letter')));
    if (!in_array($pageSize, ['a4', 'letter', 'oficio'], true)) {
        $pageSize = 'letter';
    }
    $shareToken = trim((string)($row['share_token'] ?? ''));
    if ($shareToken === '') {
        $shareToken = bin2hex(random_bytes(16));
    }
    return [
        'id' => $id,
        'record_type' => $recordType,
        'folder_id' => $folderId,
        'share_token' => $shareToken,
        'title' => trim((string)($row['title'] ?? 'Sin título')),
        'summary' => '',
        'content_html' => $content,
        'page_size' => $pageSize,
        'file_name' => trim((string)($row['file_name'] ?? '')),
        'file_original_name' => trim((string)($row['file_original_name'] ?? '')),
        'file_mime' => trim((string)($row['file_mime'] ?? '')),
        'file_size' => max(0, (int)($row['file_size'] ?? 0)),
        'file_url' => trim((string)($row['file_url'] ?? '')),
        'uploaded_at' => trim((string)($row['uploaded_at'] ?? '')),
        'draft_pending' => !empty($row['draft_pending']),
        'created_at' => trim((string)($row['created_at'] ?? $now)),
        'updated_at' => trim((string)($row['updated_at'] ?? $now)),
        'author_id' => trim((string)($row['author_id'] ?? auth_get_user_id())),
        'author_name' => trim((string)($row['author_name'] ?? ($_SESSION['user']['nombre'] ?? ''))),
    ];
}

function procedures_generate_id(): string {
    return 'proc-' . bin2hex(random_bytes(6));
}

function procedures_generate_folder_id(): string {
    return 'folder-' . bin2hex(random_bytes(6));
}

function procedures_find_by_id(array $items, string $id): ?array {
    foreach ($items as $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

function procedures_find_by_share_token(array $items, string $token): ?array {
    if ($token === '') {
        return null;
    }
    foreach ($items as $item) {
        if (hash_equals((string)($item['share_token'] ?? ''), $token)) {
            return $item;
        }
    }
    return null;
}

function procedures_find_folder_by_id(array $items, string $id): ?array {
    if ($id === '') {
        return null;
    }
    foreach ($items as $item) {
        if ((string)($item['record_type'] ?? 'document') === 'folder' && (string)($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

function procedures_folder_exists(array $items, string $id): bool {
    return $id === '' || procedures_find_folder_by_id($items, $id) !== null;
}

function procedures_folders(array $items): array {
    $folders = array_values(array_filter($items, static fn(array $item): bool => (string)($item['record_type'] ?? 'document') === 'folder'));
    usort($folders, static fn(array $a, array $b): int => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return $folders;
}

function procedures_excerpt(string $html, int $limit = 180): string {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    if ($text === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $limit) {
        return $text;
    }
    $slice = function_exists('mb_substr')
        ? mb_substr($text, 0, $limit - 1, 'UTF-8')
        : substr($text, 0, $limit - 1);
    return rtrim($slice) . '…';
}

function procedures_prepare_content_html(string $html, string $recordId): string {
    $html = procedures_strip_editor_artifacts($html);
    $html = procedures_sanitize_html($html);
    $html = procedures_replace_embedded_images($html, $recordId);
    $html = procedures_strip_editor_artifacts($html);
    $html = procedures_sanitize_html($html);
    procedures_cleanup_unused_images($recordId, $html);
    return $html;
}

function procedures_strip_editor_artifacts(string $html): string {
    if (trim($html) === '') {
        return '';
    }

    $artifactClasses = [
        'proc-image-tools',
        'proc-image-resize',
        'proc-table-tools',
        'proc-table-resize',
        'proc-table-col-resize-handle',
        'proc-table-row-resize-handle',
        'proc-code-actions',
        'proc-code-resize',
        'proc-callout-actions',
        'proc-callout-resize',
        'proc-drop-indicator',
        'proc-drop-indicator-vertical',
        'proc-drop-placeholder',
    ];

    foreach ($artifactClasses as $class) {
        $classPattern = preg_quote($class, '#');
        $html = preg_replace(
            '#<([a-z0-9]+)\b[^>]*class=(["\'])(?=[^"\']*\b' . $classPattern . '\b)[^"\']*\2[^>]*>.*?</\1>#isu',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '#<([a-z0-9]+)\b[^>]*class=(["\'])(?=[^"\']*\b' . $classPattern . '\b)[^"\']*\2[^>]*/?>#isu',
            '',
            $html
        ) ?? $html;
    }

    $html = preg_replace('/\scontenteditable\s*=\s*([\'"]).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/\sdraggable\s*=\s*([\'"]).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/\sdata-(image-id|table-id|block-id|callout-id|raw-code|saved-code|saved-lang)\s*=\s*([\'"]).*?\2/isu', '', $html) ?? $html;
    $html = preg_replace_callback('/\sclass\s*=\s*([\'"])([^\'"]*)\1/isu', static function (array $matches): string {
        $classes = preg_split('/\s+/', trim((string)$matches[2])) ?: [];
        $classes = array_values(array_filter($classes, static fn(string $class): bool => !in_array($class, [
            'is-selected',
            'is-editing',
            'is-dragging',
            'is-drag-ghost',
            'proc-table-cell-selected',
            'd-none',
        ], true)));
        return empty($classes) ? '' : ' class=' . $matches[1] . implode(' ', $classes) . $matches[1];
    }, $html) ?? $html;

    return $html;
}

function procedures_sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select)([^>]*)/?>#is', '', $html) ?? $html;
    $html = preg_replace('/\son[a-z]+\s*=\s*([\'"]).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/isu', '', $html) ?? $html;
    $html = preg_replace('/\sstyle\s*=\s*([\'"])\s*[^\'"]*expression\s*\(.*?\)\s*\1/isu', '', $html) ?? $html;
    $html = preg_replace_callback('/\s(href|src)\s*=\s*([\'"])(.*?)\2/isu', static function (array $m): string {
        $attr = strtolower($m[1]);
        $quote = $m[2];
        $value = trim(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'));
        $lower = strtolower($value);
        $allowed = false;
        if ($attr === 'href') {
            $allowed = $value === '' || str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://') || str_starts_with($lower, 'mailto:') || str_starts_with($lower, '#');
        } else {
            $allowed = $value === ''
                || str_starts_with($lower, 'http://')
                || str_starts_with($lower, 'https://')
                || str_starts_with($lower, 'data:image/')
                || str_starts_with($lower, '/redmine-mantencion/data/procedimientos/imagenes/');
        }
        if (!$allowed) {
            return '';
        }
        return ' ' . $attr . '=' . $quote . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . $quote;
    }, $html) ?? $html;

    return $html;
}

function procedures_migrate_embedded_images(): void {
    $file = procedures_data_file();
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return;
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return;
    }
    $changed = false;
    $migrated = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = procedures_normalize_record($row);
        if (($row['content_html'] ?? '') !== $normalized['content_html']) {
            $changed = true;
        }
        $migrated[] = $normalized;
    }
    if ($changed) {
        storage_write_json($file, $migrated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function procedures_replace_embedded_images(string $html, string $recordId): string {
    if ($html === '' || !str_contains($html, 'data:image/')) {
        return $html;
    }
    return preg_replace_callback(
        '/<img\b([^>]*?)src=(["\'])(data:image\/([a-zA-Z0-9.+-]+);base64,([^"\']+))\2([^>]*)>/isu',
        static function (array $matches) use ($recordId): string {
            $mimeSubtype = strtolower((string)($matches[4] ?? 'png'));
            $base64 = preg_replace('/\s+/', '', (string)($matches[5] ?? ''));
            $binary = base64_decode($base64, true);
            if ($binary === false || $binary === '') {
                return $matches[0];
            }
            $extension = procedures_image_extension_from_mime($mimeSubtype);
            $fileName = $recordId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
            $absolutePath = procedures_images_dir() . '/' . $fileName;
            if (@file_put_contents($absolutePath, $binary) === false) {
                return $matches[0];
            }
            $relativeUrl = '/redmine-mantencion/data/procedimientos/imagenes/' . $fileName;
            $before = trim((string)($matches[1] ?? ''));
            $after = trim((string)($matches[6] ?? ''));
            $attrs = trim($before . ' src="' . htmlspecialchars($relativeUrl, ENT_QUOTES, 'UTF-8') . '" ' . $after);
            return '<img ' . $attrs . '>';
        },
        $html
    ) ?? $html;
}

function procedures_image_extension_from_mime(string $mimeSubtype): string {
    $mimeSubtype = strtolower(trim($mimeSubtype));
    return match ($mimeSubtype) {
        'jpeg', 'jpg' => 'jpg',
        'gif' => 'gif',
        'webp' => 'webp',
        'bmp' => 'bmp',
        'svg+xml' => 'svg',
        default => 'png',
    };
}

function procedures_extract_image_files(string $html, string $recordId): array {
    if ($html === '') {
        return [];
    }
    preg_match_all(
        '#/redmine-mantencion/data/procedimientos/imagenes/(' . preg_quote($recordId, '#') . '-[a-z0-9]+\.[a-z0-9]+)#i',
        $html,
        $matches
    );
    $files = array_values(array_unique($matches[1] ?? []));
    sort($files);
    return $files;
}

function procedures_cleanup_unused_images(string $recordId, string $html): void {
    $keep = procedures_extract_image_files($html, $recordId);
    $keepMap = array_fill_keys($keep, true);
    foreach (glob(procedures_images_dir() . '/' . $recordId . '-*.*') ?: [] as $path) {
        $name = basename($path);
        if (!isset($keepMap[$name])) {
            @unlink($path);
        }
    }
}

function procedures_delete_record_images(string $recordId): void {
    foreach (glob(procedures_images_dir() . '/' . $recordId . '-*.*') ?: [] as $path) {
        @unlink($path);
    }
}

function procedures_delete_record_file(array $record): void {
    $fileName = basename((string)($record['file_name'] ?? ''));
    if ($fileName === '') {
        return;
    }
    $path = procedures_documents_dir() . '/' . $fileName;
    if (is_file($path)) {
        @unlink($path);
    }
}

function procedures_file_extension(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function procedures_build_file_url(string $fileName): string {
    return '/redmine-mantencion/data/procedimientos/documentos/' . rawurlencode($fileName);
}

function procedures_detect_file_mime(string $path, string $fallback = ''): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }
    return $fallback;
}

function procedures_handle_uploaded_file(string $recordId, ?array $existing = null): array {
    $upload = $_FILES['procedure_file'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($existing && !empty($existing['file_name'])) {
            return [
                'ok' => true,
                'file' => [
                    'file_name' => (string)($existing['file_name'] ?? ''),
                    'file_original_name' => (string)($existing['file_original_name'] ?? ''),
                    'file_mime' => (string)($existing['file_mime'] ?? ''),
                    'file_size' => (int)($existing['file_size'] ?? 0),
                    'file_url' => (string)($existing['file_url'] ?? ''),
                    'uploaded_at' => (string)($existing['uploaded_at'] ?? ''),
                ],
            ];
        }
        return ['ok' => false, 'error' => 'Selecciona un archivo PDF u Office.'];
    }

    if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No se pudo recibir el archivo.'];
    }

    $originalName = trim((string)($upload['name'] ?? ''));
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $extension = procedures_file_extension($originalName);
    if (!in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
        return ['ok' => false, 'error' => 'Solo se permiten archivos PDF, Word, Excel o PowerPoint.'];
    }
    if (!is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'El archivo subido no es válido.'];
    }

    $safeName = $recordId . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = procedures_documents_dir() . '/' . $safeName;
    if (!move_uploaded_file($tmpName, $target)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    }

    if ($existing && !empty($existing['file_name'])) {
        procedures_delete_record_file($existing);
    }

    return [
        'ok' => true,
        'file' => [
            'file_name' => $safeName,
            'file_original_name' => $originalName,
            'file_mime' => procedures_detect_file_mime($target, (string)($upload['type'] ?? '')),
            'file_size' => (int)($upload['size'] ?? filesize($target)),
            'file_url' => procedures_build_file_url($safeName),
            'uploaded_at' => date('c'),
        ],
    ];
}

function procedures_blank_template_base64(string $type): string {
    if ($type === 'docx') {
        return 'UEsDBBQAAAAIACVLq1wouJFP4QAAAFwBAAARAAAAd29yZC9kb2N1bWVudC54bWxFUMtuwyAQ/BXEvVnHSqvIsp1bbq0qtf0AYjY2kmERbELTry84snyZ3dmZfUB7+rWzuGOIhlwn97tKCnQDaePGTv58n1+OUkRWTquZHHbygVGe+jY1moabRcciD3CxSZ2cmH0DEIcJrYo78uiydqVgFWcaRkgUtA80YIx5vp2hrqo3sMo4WUZeSD9K9AVCAe4/bngnsTRpY01eSC0UoWBYcLF7KBhx4M+l049ffyKVs/Z1fcivSs2U89djzuFpeFchV5l8rh+elmDGiTd6IWayG5/xuqqwrF73wXo8bB/T/wNQSwMEFAAAAAgAJUurXHluM9foAAAArQEAABMAAABbQ29udGVudF9UeXBlc10ueG1sfVDJTsMwEP0Va64oceCAEIrTA8sROJQPGNmTxKo3edzS/j1OW3pAhePMW/X61d47saPMNgYFt20HgoKOxoZJwef6tXkAwQWDQRcDKTgQw2ro14dELKo2sIK5lPQoJeuZPHIbE4WKjDF7LPXMk0yoNziRvOu6e6ljKBRKUxYPGPpnGnHrinjZ1/epRybHIJ5OxCVLAabkrMZScbkL5ldKc05oq/LI4dkmvqkEkFcTFuTvgLPuvQ6TrSHxgbm8oa8s+RWzkSbqra/K9n+bKz3jOFpNF/3ilnLUxFwX9669IB5t+Okvj3MP31BLAwQUAAAACAAlS6tcm/036q0AAAApAQAACwAAAF9yZWxzLy5yZWxzjc87DsIwDAbgq0TeaVoGhFDTLgipKyoHsBI3rWgeSsKjtycDA0UMjLZ/f5br9mlmdqcQJ2cFVEUJjKx0arJawKU/bfbAYkKrcHaWBCwUoW3qM82Y8kocJx9ZNmwUMKbkD5xHOZLBWDhPNk8GFwymXAbNPcorauLbstzx8GnA2mSdEhA6VQHrF0//2G4YJklHJ2+GbPpx4iuRZQyakoCHC4qrd7vILPCm5qsXmxdQSwECFAAUAAAACAAlS6tcKLiRT+EAAABcAQAAEQAAAAAAAAAAAAAAAAAAAAAAd29yZC9kb2N1bWVudC54bWxQSwECFAAUAAAACAAlS6tceW4z1+gAAACtAQAAEwAAAAAAAAAAAAAAAAAQAQAAW0NvbnRlbnRfVHlwZXNdLnhtbFBLAQIUABQAAAAIACVLq1yb/TfqrQAAACkBAAALAAAAAAAAAAAAAAAAACkCAABfcmVscy8ucmVsc1BLBQYAAAAAAwADALkAAAD/AgAAAAA=';
    }
    $templates = [
        'docx' => 'UEsDBBQAAAAIAHFKq1wouJFP4QAAAFwBAAARAAAAd29yZC9kb2N1bWVudC54bWxFUMtuwyAQ/BXEvVnHSqvIsp1bbq0qtf0AYjY2kmERbELTry84snyZ3dmZfUB7+rWzuGOIhlwn97tKCnQDaePGTv58n1+OUkRWTquZHHbygVGe+jY1moabRcciD3CxSZ2cmH0DEIcJrYo78uiydqVgFWcaRkgUtA80YIx5vp2hrqo3sMo4WUZeSD9K9AVCAe4/bngnsTRpY01eSC0UoWBYcLF7KBhx4M+l049ffyKVs/Z1fcivSs2U89djzuFpeFchV5l8rh+elmDGiTd6IWayG5/xuqqwrF73wXo8bB/T/wNQSwMEFAAAAAgAcUqrXHluM9foAAAArQEAABMAAABbQ29udGVudF9UeXBlc10ueG1sfVDJTsMwEP0Va64oceCAEIrTA8sROJQPGNmTxKo3edzS/j1OW3pAhePMW/X61d47saPMNgYFt20HgoKOxoZJwef6tXkAwQWDQRcDKTgQw2ro14dELKo2sIK5lPQoJeZPHIbE4WKjDF7LPXMk0yoNziRvOu6e6ljKBRKUxYPGPpnGnHrinjZ1/epRybHIJ5OxCVLAabkrMZScbkL5ldKc05oq/LI4dkmvqkEkFcTFuTvgLPuvQ6TrSHxgbm8oa8s+RWzkSbqra/K9n+bKz3jOFpNF/3ilnLUxFwX9669IB5t+Okvj3MP31BLAwQUAAAACABxSqtcm/036q0AAAApAQAACwAAAF9yZWxzLy5yZWxzjc87DsIwDAbgq0TeaVoGhFDTLgipKyoHsBI3rWgeSsKjtycDA0UMjLZ/f5br9mlmdqcQJ2cFVEUJjKx0arJawKU/bfbAYkKrcHaWBCwUoW3qM82Y8kocJx9ZNmwUMKbkD5xHOZLBWDhPNk8GFwymXAbNPcorauLbstzx8GnA2mSdEhA6VQHrF0//2G4YJklHJ2+GbPpx4iuRZQyakoCHC4qrd7vILPCm5qsXmxdQSwECFAAUAAAACABxSqtcKLiRT+EAAABcAQAAEQAAAAAAAAAAAAAAAAAAAAAAd29yZC9kb2N1bWVudC54bWxQSwECFAAUAAAACABxSqtceW4z1+gAAACtAQAAEwAAAAAAAAAAAAAAAAAQAQAAW0NvbnRlbnRfVHlwZXNdLnhtbFBLAQIUABQAAAAIAHFKq1yb/TfqrQAAACkBAAALAAAAAAAAAAAAAAAAACkCAABfcmVscy8ucmVsc1BLBQYAAAAAAwADALkAAAD/AgAAAAA=',
        'xlsx' => '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',
    ];
    return $templates[$type] ?? '';
}

function procedures_office_mime(string $type): string {
    return $type === 'xlsx'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
}

function procedures_create_blank_office_file(string $recordId, string $title, string $type): array {
    if (!in_array($type, ['docx', 'xlsx'], true)) {
        return ['ok' => false, 'error' => 'Tipo de documento no válido.'];
    }
    $binary = base64_decode(procedures_blank_template_base64($type), true);
    if (!is_string($binary) || $binary === '') {
        return ['ok' => false, 'error' => 'No se encontró la plantilla del documento.'];
    }
    $safeName = $recordId . '-' . bin2hex(random_bytes(4)) . '.' . $type;
    $target = procedures_documents_dir() . '/' . $safeName;
    if (file_put_contents($target, $binary, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'No se pudo crear el documento.'];
    }
    $originalTitle = preg_replace('/[\\\\\/:*?"<>|]+/', '-', trim($title));
    if ($originalTitle === '') {
        $originalTitle = $type === 'xlsx' ? 'Nueva planilla' : 'Nuevo documento';
    }
    return [
        'ok' => true,
        'file' => [
            'file_name' => $safeName,
            'file_original_name' => $originalTitle . '.' . $type,
            'file_mime' => procedures_office_mime($type),
            'file_size' => filesize($target) ?: strlen($binary),
            'file_url' => procedures_build_file_url($safeName),
            'uploaded_at' => date('c'),
        ],
    ];
}

function procedures_format_file_size(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
}

function procedures_file_kind(array $record): string {
    $extension = procedures_file_extension((string)($record['file_name'] ?? $record['file_original_name'] ?? ''));
    if ($extension === 'pdf') {
        return 'pdf';
    }
    if (in_array($extension, ['doc', 'docx'], true)) {
        return 'word';
    }
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return 'cell';
    }
    if (in_array($extension, ['ppt', 'pptx'], true)) {
        return 'slide';
    }
    return 'html';
}

function procedures_onlyoffice_supported(array $record): bool {
    return in_array(procedures_file_extension((string)($record['file_name'] ?? '')), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true);
}

function procedures_empty_form(): array {
    return [
        'id' => '',
        'folder_id' => '',
        'title' => '',
        'content_html' => '',
        'page_size' => 'letter',
        'file_name' => '',
        'file_original_name' => '',
        'file_mime' => '',
        'file_size' => 0,
        'file_url' => '',
        'uploaded_at' => '',
    ];
}

function procedures_handle_request(): array {
    auth_start_session();
    $flash = $_SESSION['procedures_flash'] ?? null;
    $error = null;
    unset($_SESSION['procedures_flash']);

    $items = procedures_read_all();
    $selectedId = trim((string)($_GET['id'] ?? ''));
    $form = procedures_empty_form();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (function_exists('csrf_validate')) {
            csrf_validate();
        }
        if (function_exists('maintenance_mode_block_if_enabled')) {
            maintenance_mode_block_if_enabled();
        }

        $action = trim((string)($_POST['action'] ?? 'save'));
        $selectedId = trim((string)($_POST['id'] ?? ''));
        $postedFolderId = trim((string)($_POST['folder_id'] ?? ''));
        if (!procedures_folder_exists($items, $postedFolderId)) {
            $postedFolderId = '';
        }

        if (!auth_can('procedimientos_editar')) {
            $error = 'No tienes permisos para editar procedimientos.';
        } elseif ($action === 'create_folder') {
            $title = trim((string)($_POST['folder_title'] ?? ''));
            if ($title === '') {
                $error = 'El nombre de la carpeta es obligatorio.';
            } else {
                $now = date('c');
                $folder = procedures_normalize_record([
                    'id' => procedures_generate_folder_id(),
                    'record_type' => 'folder',
                    'title' => $title,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'author_id' => auth_get_user_id(),
                    'author_name' => trim((string)($_SESSION['user']['nombre'] ?? '')),
                ]);
                $items[] = $folder;
                if (procedures_write_all($items)) {
                    $_SESSION['procedures_flash'] = 'Carpeta creada.';
                    header('Location: /redmine-mantencion/views/Procedimientos/procedimientos.php?folder=' . urlencode((string)$folder['id']));
                    exit;
                }
                $error = 'No se pudo crear la carpeta.';
            }
        } elseif ($action === 'move') {
            $targetId = trim((string)($_POST['target_id'] ?? ''));
            $destinationFolderId = trim((string)($_POST['destination_folder_id'] ?? ''));
            if (!procedures_folder_exists($items, $destinationFolderId)) {
                $destinationFolderId = '';
            }
            if ($targetId === '') {
                $error = 'No se encontró el documento a mover.';
            } else {
                $moved = false;
                foreach ($items as $idx => $item) {
                    if ((string)($item['id'] ?? '') === $targetId && (string)($item['record_type'] ?? 'document') !== 'folder') {
                        $items[$idx]['folder_id'] = $destinationFolderId;
                        $items[$idx]['updated_at'] = date('c');
                        $moved = true;
                        break;
                    }
                }
                if (!$moved) {
                    $error = 'No se pudo encontrar el documento para mover.';
                } elseif (procedures_write_all($items)) {
                    $_SESSION['procedures_flash'] = 'Documento movido.';
                    header('Location: /redmine-mantencion/views/Procedimientos/procedimientos.php' . ($destinationFolderId !== '' ? '?folder=' . urlencode($destinationFolderId) : ''));
                    exit;
                } else {
                    $error = 'No se pudo mover el documento.';
                }
            }
        } elseif ($action === 'delete') {
            if ($selectedId === '') {
                $error = 'No se encontró el procedimiento a eliminar.';
            } else {
                $existing = procedures_find_by_id($items, $selectedId);
                $items = array_values(array_filter($items, static fn(array $item): bool => (string)($item['id'] ?? '') !== $selectedId));
                procedures_delete_record_images($selectedId);
                if ($existing) {
                    procedures_delete_record_file($existing);
                }
                if (procedures_write_all($items)) {
                    $_SESSION['procedures_flash'] = 'Procedimiento eliminado.';
                    header('Location: /redmine-mantencion/views/Procedimientos/procedimientos.php');
                    exit;
                }
                $error = 'No se pudo eliminar el procedimiento.';
            }
        } elseif ($action === 'create_office') {
            $title = trim((string)($_POST['title'] ?? ''));
            $type = strtolower(trim((string)($_POST['document_type'] ?? 'docx')));
            if ($title === '') {
                $title = $type === 'xlsx' ? 'Nueva planilla' : 'Nuevo documento';
            }
            $now = date('c');
            $recordId = procedures_generate_id();
            $fileResult = procedures_create_blank_office_file($recordId, $title, $type);
            if (empty($fileResult['ok'])) {
                $error = (string)($fileResult['error'] ?? 'No se pudo crear el documento.');
            } else {
                $record = procedures_normalize_record([
                    'id' => $recordId,
                    'title' => $title,
                    'content_html' => '',
                    'page_size' => 'letter',
                    'folder_id' => $postedFolderId,
                    'file_name' => (string)($fileResult['file']['file_name'] ?? ''),
                    'file_original_name' => (string)($fileResult['file']['file_original_name'] ?? ''),
                    'file_mime' => (string)($fileResult['file']['file_mime'] ?? ''),
                    'file_size' => (int)($fileResult['file']['file_size'] ?? 0),
                    'file_url' => (string)($fileResult['file']['file_url'] ?? ''),
                    'uploaded_at' => (string)($fileResult['file']['uploaded_at'] ?? ''),
                    'draft_pending' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'author_id' => auth_get_user_id(),
                    'author_name' => trim((string)($_SESSION['user']['nombre'] ?? '')),
                ]);
                $items[] = $record;
                if (procedures_write_all($items)) {
                    header('Location: /redmine-mantencion/views/Procedimientos/onlyoffice.php?id=' . urlencode($record['id']) . '&mode=edit');
                    exit;
                }
                procedures_delete_record_file($record);
                $error = 'No se pudo guardar el documento creado.';
            }
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            $content = '';
            $pageSize = strtolower(trim((string)($_POST['page_size'] ?? 'letter')));
            if (!in_array($pageSize, ['a4', 'letter', 'oficio'], true)) {
                $pageSize = 'a4';
            }

            $form = [
                'id' => $selectedId,
                'folder_id' => $postedFolderId,
                'title' => $title,
                'content_html' => $content,
                'page_size' => $pageSize,
            ];

            $now = date('c');
            $existing = $selectedId !== '' ? procedures_find_by_id($items, $selectedId) : null;
            $recordId = $selectedId !== '' ? $selectedId : procedures_generate_id();
            $fileResult = procedures_handle_uploaded_file($recordId, $existing);
            if ($title === '' && !empty($fileResult['file']['file_original_name'])) {
                $title = pathinfo((string)$fileResult['file']['file_original_name'], PATHINFO_FILENAME);
                $form['title'] = $title;
            }

            if ($title === '') {
                $error = 'El título es obligatorio.';
            } elseif (empty($fileResult['ok'])) {
                $error = (string)($fileResult['error'] ?? 'No se pudo procesar el archivo.');
            } else {
                $record = procedures_normalize_record([
                    'id' => $recordId,
                    'title' => $title,
                    'content_html' => $content,
                    'page_size' => $pageSize,
                    'folder_id' => $postedFolderId !== '' ? $postedFolderId : (string)($existing['folder_id'] ?? ''),
                    'file_name' => (string)($fileResult['file']['file_name'] ?? ''),
                    'file_original_name' => (string)($fileResult['file']['file_original_name'] ?? ''),
                    'file_mime' => (string)($fileResult['file']['file_mime'] ?? ''),
                    'file_size' => (int)($fileResult['file']['file_size'] ?? 0),
                    'file_url' => (string)($fileResult['file']['file_url'] ?? ''),
                    'uploaded_at' => (string)($fileResult['file']['uploaded_at'] ?? ''),
                    'share_token' => $existing['share_token'] ?? '',
                    'created_at' => $existing['created_at'] ?? $now,
                    'updated_at' => $now,
                    'author_id' => $existing['author_id'] ?? auth_get_user_id(),
                    'author_name' => $existing['author_name'] ?? trim((string)($_SESSION['user']['nombre'] ?? '')),
                ]);

                $saved = false;
                foreach ($items as $idx => $item) {
                    if ((string)($item['id'] ?? '') === $record['id']) {
                        $items[$idx] = $record;
                        $saved = true;
                        break;
                    }
                }
                if (!$saved) {
                    $items[] = $record;
                }

                if (procedures_write_all($items)) {
                    $_SESSION['procedures_flash'] = $saved ? 'Procedimiento actualizado.' : 'Procedimiento creado.';
                    header('Location: /redmine-mantencion/views/Procedimientos/procedimientos.php?id=' . urlencode($record['id']));
                    exit;
                }

                $error = 'No se pudo guardar el procedimiento.';
            }
        }
    }

    $items = procedures_read_all();
    if ($selectedId !== '') {
        $selected = procedures_find_by_id($items, $selectedId);
        if ($selected) {
            $form = [
                'id' => $selected['id'],
                'folder_id' => $selected['folder_id'] ?? '',
                'title' => $selected['title'],
                'content_html' => $selected['content_html'],
                'page_size' => $selected['page_size'] ?? 'letter',
                'file_name' => $selected['file_name'] ?? '',
                'file_original_name' => $selected['file_original_name'] ?? '',
                'file_mime' => $selected['file_mime'] ?? '',
                'file_size' => $selected['file_size'] ?? 0,
                'file_url' => $selected['file_url'] ?? '',
                'uploaded_at' => $selected['uploaded_at'] ?? '',
            ];
        }
    }

    return [$items, $form, is_string($flash) ? $flash : null, $error, $selectedId];
}

