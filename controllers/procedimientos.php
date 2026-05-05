<?php

require_once __DIR__ . '/auth.php';

function procedures_storage_dir(): string {
    return __DIR__ . '/../data/procedimientos';
}

function procedures_images_dir(): string {
    return procedures_storage_dir() . '/imagenes';
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
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (file_exists($legacyFile) && !file_exists($file)) {
        @rename($legacyFile, $file);
    }
    if (!is_dir($imagesDir)) {
        mkdir($imagesDir, 0777, true);
    }
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        @file_put_contents(
            procedures_data_file(),
            json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    return $items;
}

function procedures_write_all(array $items): bool {
    procedures_ensure_storage();
    $payload = array_values(array_map('procedures_normalize_record', $items));
    return file_put_contents(
        procedures_data_file(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function procedures_normalize_record(array $row): array {
    $now = date('c');
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') {
        $id = procedures_generate_id();
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
        'share_token' => $shareToken,
        'title' => trim((string)($row['title'] ?? 'Sin título')),
        'summary' => '',
        'content_html' => $content,
        'page_size' => $pageSize,
        'created_at' => trim((string)($row['created_at'] ?? $now)),
        'updated_at' => trim((string)($row['updated_at'] ?? $now)),
        'author_id' => trim((string)($row['author_id'] ?? auth_get_user_id())),
        'author_name' => trim((string)($row['author_name'] ?? ($_SESSION['user']['nombre'] ?? ''))),
    ];
}

function procedures_generate_id(): string {
    return 'proc-' . bin2hex(random_bytes(6));
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
        file_put_contents($file, json_encode($migrated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

function procedures_empty_form(): array {
    return [
        'id' => '',
        'title' => '',
        'content_html' => '',
        'page_size' => 'letter',
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

        $action = trim((string)($_POST['action'] ?? 'save'));
        $selectedId = trim((string)($_POST['id'] ?? ''));

        if ($action === 'delete') {
            if ($selectedId === '') {
                $error = 'No se encontró el procedimiento a eliminar.';
            } else {
                $items = array_values(array_filter($items, static fn(array $item): bool => (string)($item['id'] ?? '') !== $selectedId));
                procedures_delete_record_images($selectedId);
                if (procedures_write_all($items)) {
                    $_SESSION['procedures_flash'] = 'Procedimiento eliminado.';
                    header('Location: /redmine-mantencion/views/Procedimientos/procedimientos.php');
                    exit;
                }
                $error = 'No se pudo eliminar el procedimiento.';
            }
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            $content = procedures_sanitize_html((string)($_POST['content_html'] ?? ''));
            $pageSize = strtolower(trim((string)($_POST['page_size'] ?? 'letter')));
            if (!in_array($pageSize, ['a4', 'letter', 'oficio'], true)) {
                $pageSize = 'a4';
            }

            $form = [
                'id' => $selectedId,
                'title' => $title,
                'content_html' => $content,
                'page_size' => $pageSize,
            ];

            if ($title === '') {
                $error = 'El título es obligatorio.';
            } elseif (trim(strip_tags($content)) === '' && !str_contains($content, '<img')) {
                $error = 'Agrega contenido o una imagen antes de guardar.';
            } else {
                $now = date('c');
                $existing = $selectedId !== '' ? procedures_find_by_id($items, $selectedId) : null;
                $record = procedures_normalize_record([
                    'id' => $selectedId !== '' ? $selectedId : procedures_generate_id(),
                    'title' => $title,
                    'content_html' => $content,
                    'page_size' => $pageSize,
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
                'title' => $selected['title'],
                'content_html' => $selected['content_html'],
                'page_size' => $selected['page_size'] ?? 'letter',
            ];
        }
    }

    return [$items, $form, is_string($flash) ? $flash : null, $error, $selectedId];
}

