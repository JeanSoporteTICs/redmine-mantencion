<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';

function dashboard_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['flash'] = $message;
}

function dashboard_consume_flash(): ?string {
    auth_start_session();
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function dashboard_redirect_back(): void {
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine/views/Dashboard/dashboard.php';
    header('Location: ' . $location);
    exit;
}

function dashboard_messages_file(): string {
    return __DIR__ . '/../data/mensaje.json';
}

function load_messages(): array {
    $file = dashboard_messages_file();
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_messages(array $messages): void {
    $file = dashboard_messages_file();
    file_put_contents($file, json_encode(array_values($messages), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function load_platform_config(): array {
    $path = __DIR__ . '/../data/configuracion.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function get_retencion_horas(int $default = 24): int {
    $cfg = load_platform_config();
    $value = isset($cfg['retencion_horas']) ? (int)$cfg['retencion_horas'] : $default;
    return max(1, $value);
}

function redmine_log_path(): string {
    return __DIR__ . '/../data/envio_errores.log';
}

function load_name_map(string $file, string $nameKey = 'nombre'): array {
    $out = [];
    if (!file_exists($file)) {
        return $out;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return $out;
    }
    foreach ($data as $entry) {
        if (!is_array($entry) || !isset($entry[$nameKey])) continue;
        $out[strtoupper(trim($entry[$nameKey]))] = $entry['id'] ?? $entry[$nameKey];
    }
    return $out;
}

function parse_issue_date(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
        }
    }
    $timestamp = strtotime($value);
    if ($timestamp !== false && $timestamp > 0) {
        return (new DateTimeImmutable())->setTimestamp($timestamp)->format('Y-m-d');
    }
    return null;
}

function build_redmine_issue_payload(array $message, array $cfg, array $catMap, array $unitMap): array {
    $issue = [
        'project_id' => (int)($cfg['project_id'] ?? 0),
        'subject' => trim($message['asunto'] ?? $message['descripcion'] ?? $message['mensaje'] ?? ''),
        'description' => trim($message['descripcion'] ?? $message['mensaje'] ?? ''),
        'tracker_id' => (int)($cfg['tracker_id'] ?? 0),
        'priority_id' => (int)($cfg['priority_id'] ?? 0),
        'status_id' => (int)($cfg['status_id'] ?? 0),
    ];
    $startDate = parse_issue_date($message['fecha_inicio'] ?? $message['fecha'] ?? '');
    $dueDate = parse_issue_date($message['fecha_fin'] ?? $message['fecha'] ?? $message['fecha_inicio'] ?? '');
    if ($startDate) {
        $issue['start_date'] = $startDate;
    }
    if ($dueDate) {
        $issue['due_date'] = $dueDate;
    }
    $est = trim((string)($message['tiempo_estimado'] ?? ''));
    if ($est !== '' && is_numeric($est)) {
        $issue['estimated_hours'] = (float)$est;
    }
    $asignado = trim((string)($message['asignado_a'] ?? ''));
    if ($asignado !== '') {
        $issue['assigned_to_id'] = $asignado;
    }
    $categoria = strtoupper(trim($message['categoria'] ?? ''));
    if ($categoria !== '' && isset($catMap[$categoria])) {
        $issue['category_id'] = (int)$catMap[$categoria];
    }
    $customFields = [];
    foreach (['cf_solicitante','cf_unidad','cf_unidad_solicitante'] as $cfKey) {
        $cfId = $cfg[$cfKey] ?? null;
        if (!$cfId) continue;
        $value = '';
        switch ($cfKey) {
            case 'cf_solicitante':
                $value = $message['solicitante'] ?? '';
                break;
            case 'cf_unidad':
                $value = trim($message['unidad'] ?? '');
                break;
            case 'cf_unidad_solicitante':
                $value = strtoupper(trim($message['unidad_solicitante'] ?? $message['unidad'] ?? ''));
                break;
        }
        if ($value === '' && $cfKey === 'cf_unidad_solicitante' && $unitMap) {
            $derived = strtoupper(trim($message['unidad_solicitante'] ?? $message['unidad'] ?? ''));
            if (isset($unitMap[$derived])) {
                $value = $unitMap[$derived];
            }
        }
        if ($value === '') continue;
        $customFields[] = ['id' => $cfId, 'value' => $value];
    }
    $cfHoraExtra = $cfg['cf_hora_extra'] ?? null;
    if ($cfHoraExtra) {
        $customFields[] = ['id' => $cfHoraExtra, 'value' => normalize_hour_extra_value($message['hora_extra'] ?? '')];
    }
    if (!empty($customFields)) {
        $issue['custom_fields'] = $customFields;
    }
    return $issue;
}

function normalize_hour_extra_value($value): string {
    $val = strtolower(trim((string)$value));
    $truthy = ['1','si','sí','s','true','yes'];
    return in_array($val, $truthy, true) ? '1' : '0';
}

function message_has_hora_extra(array $message): bool {
    return normalize_hour_extra_value($message['hora_extra'] ?? '') === '1';
}

function horas_extra_base_path(): string {
    return __DIR__ . '/../data/horasExtras';
}

function horas_extra_month_name(DateTimeInterface $dt): string {
    static $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    return $meses[(int)$dt->format('n')] ?? $dt->format('m');
}

function append_hours_extra_record(array $message): void {
    if (!message_has_hora_extra($message)) return;
    $dt = parse_message_timestamp($message) ?? new DateTimeImmutable();
    $year = $dt->format('Y');
    $baseDir = horas_extra_base_path();
    $yearDir = $baseDir . '/' . $year;
    ensure_dir($yearDir);
    $monthName = horas_extra_month_name($dt);
    $filePath = $yearDir . '/' . $monthName . '.json';
    $groups = [];
    if (file_exists($filePath)) {
        $parsed = json_decode(@file_get_contents($filePath), true);
        if (is_array($parsed)) {
            $groups = $parsed;
        }
    }
    foreach ($groups as $idx => $group) {
        if (!is_array($group)) {
            $groups[$idx] = ['fecha' => '', 'hora_inicio' => '', 'hora_fin' => '', 'reports' => []];
        } else {
            if (!isset($group['reports']) || !is_array($group['reports'])) {
                $group['reports'] = [];
            }
            $groups[$idx] = $group;
        }
    }
    $targetDate = $dt->format('Y-m-d');
    $horaIni = trim((string)($message['hora_inicio'] ?? $message['hora'] ?? ''));
    $horaFin = trim((string)($message['hora_fin'] ?? $message['hora'] ?? ''));
    $groupIndex = null;
    foreach ($groups as $idx => $group) {
        if (($group['fecha'] ?? '') === $targetDate) {
            $groupIndex = $idx;
            break;
        }
    }
    if ($groupIndex === null) {
        $groupIndex = count($groups);
        $groups[] = [
            'fecha' => $targetDate,
            'hora_inicio' => $horaIni,
            'hora_fin' => $horaFin,
            'reports' => [],
        ];
    } else {
        if ($horaIni !== '') {
            $groups[$groupIndex]['hora_inicio'] = $horaIni;
        }
        if ($horaFin !== '') {
            $groups[$groupIndex]['hora_fin'] = $horaFin;
        }
    }
    $reports = $groups[$groupIndex]['reports'];
    $messageId = $message['id'] ?? '';
    $reports = array_values(array_filter($reports, fn($row) => !is_array($row) || ($row['id'] ?? '') !== $messageId));
    $message['hora_extra'] = 'SI';
    $reports[] = $message;
    $groups[$groupIndex]['reports'] = $reports;
    file_put_contents($filePath, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function append_redmine_log(array $entry): void {
    $path = redmine_log_path();
    file_put_contents($path, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function load_user_api_token(?string $userId): string {
    $path = __DIR__ . '/../data/usuarios.json';
    if (!$userId || !file_exists($path)) {
        return '';
    }
    $users = json_decode(file_get_contents($path), true);
    if (!is_array($users)) {
        return '';
    }
    foreach ($users as $user) {
        if (!is_array($user)) continue;
        if ((string)($user['id'] ?? '') === (string)$userId) {
            return trim($user['api'] ?? '');
        }
    }
    return '';
}

function send_redmine_issue(array $issue, array $cfg, string $userToken = ''): array {
    $url = trim($cfg['platform_url'] ?? '');
    if ($url === '') {
        return ['http_code' => 0, 'body' => '', 'error' => 'URL no configurada'];
    }
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $token = $userToken !== '' ? $userToken : trim($cfg['platform_token'] ?? '');
    if ($token !== '') {
        $headers[] = 'X-Redmine-API-Key: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['issue' => $issue], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return ['http_code' => $httpCode, 'body' => $body ?? '', 'error' => $curlErr];
}

function send_selected_messages(array &$messages, array $ids, array $cfg, string $userToken): array {
    $catMap = load_name_map(__DIR__ . '/../data/categorias.json');
    $unitMap = load_name_map(__DIR__ . '/../data/unidades.json');
    $attempts = 0;
    $success = 0;
    $created = [];
    $errors = [];
    $ids = array_filter(array_map('trim', $ids));
    if (empty($ids)) {
        return ['success' => 0, 'errors' => ['No hay mensajes seleccionados'], 'attempts' => 0];
    }
    foreach ($messages as &$message) {
        if (!in_array(($message['id'] ?? ''), $ids, true)) {
            continue;
        }
        $attempts++;
        $issue = build_redmine_issue_payload($message, $cfg, $catMap, $unitMap);
        $result = send_redmine_issue($issue, $cfg, $userToken);
        $entry = [
            'ts' => (new DateTimeImmutable())->format(DateTime::ATOM),
            'http_code' => $result['http_code'],
            'error' => $result['error'] ?? '',
            'body' => $result['body'],
            'payload' => ['issue' => $issue],
            'message_id' => $message['id'] ?? '',
        ];
        append_redmine_log($entry);
        if ($result['http_code'] === 201) {
            $success++;
            $decoded = json_decode($result['body'] ?? '', true);
            $message['estado'] = 'procesado';
            $message['redmine_id'] = $decoded['issue']['id'] ?? $message['redmine_id'] ?? '';
            $message['procesado_ts'] = (new DateTimeImmutable())->format(DateTime::ATOM);
            if ($message['redmine_id']) {
                $created[] = (string)$message['redmine_id'];
            }
        } else {
            $message['estado'] = 'error';
            $message['procesado_ts'] = (new DateTimeImmutable())->format(DateTime::ATOM);
            $errors[] = sprintf('No se pudo enviar %s: %s', $message['id'] ?? 'sin-id', $result['error'] ?: $result['body']);
        }
        append_hours_extra_record($message);
    }
    unset($message);
    save_messages($messages);
    return [
        'success' => $success,
        'errors' => array_values(array_filter($errors)),
        'attempts' => $attempts,
        'redmine_ids' => $created,
    ];
}

function retention_archive_base(): string {
    return __DIR__ . '/../data/reportes';
}

function parse_message_timestamp(array $message): ?DateTimeImmutable {
    $processed = trim((string)($message['procesado_ts'] ?? ''));
    if ($processed !== '') {
        try {
            return new DateTimeImmutable($processed);
        } catch (Exception $e) {
            // fallback to other fields
        }
    }
    $dateParts = trim((string)($message['fecha'] ?? $message['fecha_inicio'] ?? ''));
    $timeParts = trim((string)($message['hora'] ?? $message['hora_inicio'] ?? ''));
    if ($dateParts === '') {
        return null;
    }
    $candidate = trim("$dateParts $timeParts");
    $formats = [
        'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i',
        'Y-m-d', 'd-m-Y', 'Y/m/d', 'd/m/Y'
    ];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $candidate);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    $timestamp = strtotime($candidate);
    if ($timestamp !== false && $timestamp > 0) {
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }
    return null;
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function archive_message_record(array $message): void {
    $dt = parse_message_timestamp($message) ?? new DateTimeImmutable();
    $yearDir = retention_archive_base() . '/' . $dt->format('Y');
    ensure_dir($yearDir);
    $destFile = $yearDir . '/retencion.json';
    $payload = json_decode(@file_get_contents($destFile), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $message['_archivado_por'] = 'retencion';
    $message['_archivado_en'] = $dt->format('c');
    $payload[] = $message;
    file_put_contents($destFile, json_encode(array_values($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function apply_retention_archive(array &$messages): bool {
    $threshold = (new DateTimeImmutable())->modify('-' . get_retencion_horas() . ' hours');
    $removed = [];
    foreach ($messages as $key => $message) {
        $estado = strtolower($message['estado'] ?? '');
        if ($estado === 'pendiente' || $estado === '' || $estado === 'error') {
            continue;
        }
        $ts = parse_message_timestamp($message);
        if ($ts === null || $ts > $threshold) {
            continue;
        }
        $removed[] = $message;
        unset($messages[$key]);
    }
    if (empty($removed)) {
        return false;
    }
    $messages = array_values($messages);
    foreach ($removed as $item) {
        archive_message_record($item);
    }
    return true;
}

function archive_selected_messages(array &$messages, array $ids): int {
    $ids = array_filter(array_map('trim', $ids));
    if (empty($ids)) {
        return 0;
    }
    $archived = 0;
    foreach ($messages as $key => $message) {
        if (!in_array(($message['id'] ?? ''), $ids, true)) {
            continue;
        }
        archive_message_record($message);
        unset($messages[$key]);
        $archived++;
    }
    if ($archived > 0) {
        $messages = array_values($messages);
        save_messages($messages);
    }
    return $archived;
}

function dashboard_messages_scope(): string {
    if (function_exists('auth_user_has_all_permissions') && auth_user_has_all_permissions()) {
        return 'todos';
    }
    $value = function_exists('auth_get_permission_value') ? auth_get_permission_value('mensajes') : null;
    $scope = strtolower(trim((string)$value));
    return $scope === 'todos' ? 'todos' : 'asignados';
}

function dashboard_filter_messages_by_scope(array $messages): array {
    if (dashboard_messages_scope() === 'todos') {
        return $messages;
    }
    $userId = (string)auth_get_user_id();
    if ($userId === '') {
        return [];
    }
    return array_values(array_filter($messages, fn ($row) => is_array($row) && (string)($row['asignado_a'] ?? '') === $userId));
}

function handle_request(): array {
    $messages = load_messages();
    $userId = auth_get_user_id();
    $userToken = load_user_api_token($userId);
    if (apply_retention_archive($messages)) {
        save_messages($messages);
    }
    $flash = dashboard_consume_flash();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate();
        $action = $_POST['action'] ?? '';
        $flashMsg = null;
        switch ($action) {
            case 'update':
                $id = $_POST['id'] ?? '';
                if ($id === '') {
                    $flashMsg = 'Falta el identificador del mensaje.';
                    break;
                }
                $updated = false;
                $fields = [
                    'tipo','estado','asunto','prioridad','categoria',
                    'asignado_a','solicitante','unidad','unidad_solicitante',
                    'hora_extra','fecha_inicio','fecha_fin','tiempo_estimado',
                    'fecha','hora','numero','descripcion'
                ];
                $updatedMessage = null;
                foreach ($messages as &$message) {
                    if (($message['id'] ?? '') !== $id) {
                        continue;
                    }
                    foreach ($fields as $field) {
                        if (isset($_POST[$field])) {
                            $message[$field] = $_POST[$field];
                        }
                    }
                    $updated = true;
                    $updatedMessage = $message;
                    break;
                }
                unset($message);
                if ($updated) {
                    save_messages($messages);
                    if (is_array($updatedMessage)) {
                        append_hours_extra_record($updatedMessage);
                    }
                    $flashMsg = 'Mensaje actualizado.';
                } else {
                    $flashMsg = 'No se encontró el mensaje.';
                }
                break;
            case 'delete':
                $id = $_POST['id'] ?? '';
                if ($id === '') {
                    $flashMsg = 'Identificador no válido.';
                    break;
                }
                $before = count($messages);
                $messages = array_values(array_filter($messages, fn($m) => ($m['id'] ?? '') !== $id));
                if ($before !== count($messages)) {
                    save_messages($messages);
                    $flashMsg = 'Mensaje eliminado.';
                } else {
                    $flashMsg = 'No se encontró el mensaje para eliminar.';
                }
                break;
            case 'process_selected':
                // se resuelve después del switch para incluir resultados del envío.
                break;
            case 'archive_selected':
                $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                $archived = archive_selected_messages($messages, $ids);
                if ($archived > 0) {
                    $flashMsg = $archived . ' tickets archivados.';
                } else {
                    $flashMsg = 'No había mensajes seleccionados para archivar.';
                }
                break;
            case 'reset_errors':
                $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                $ids = array_filter(array_map('trim', $ids));
                $updated = 0;
                foreach ($messages as &$message) {
                    if (!in_array(($message['id'] ?? ''), $ids, true)) {
                        continue;
                    }
                    if (strtolower($message['estado'] ?? '') !== 'error') {
                        continue;
                    }
                    $message['estado'] = 'pendiente';
                    unset($message['redmine_id']);
                    $message['procesado_ts'] = '';
                    $updated++;
                }
                unset($message);
                if ($updated > 0) {
                    save_messages($messages);
                    $flashMsg = $updated . ' error(es) marcados como pendientes.';
                } else {
                    $flashMsg = 'No se encontraron errores seleccionados.';
                }
                break;
            default:
                $flashMsg = 'Acción desconocida.';
                break;
        }
        if ($action === 'process_selected') {
            $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
            $result = send_selected_messages($messages, $ids, load_platform_config(), $userToken);
            $flashParts = [];
            if ($result['success'] > 0) {
                $flashParts[] = $result['success'] . ' ticket(s) enviados.';
            }
            if ($result['attempts'] > $result['success']) {
                $flashParts[] = 'Hubo fallas con ' . ($result['attempts'] - $result['success']) . ' ticket(s).';
            }
            if (empty($flashParts)) {
                $flashParts[] = 'No se enviaron tickets.';
            }
            if (!empty($result['errors'])) {
                $flashParts[] = implode(' ', $result['errors']);
            }
            if (!empty($result['redmine_ids'])) {
                $flashParts[] = 'Redmine ID(s): ' . implode(', ', $result['redmine_ids']);
            }
            $flashMsg = implode(' ', $flashParts);
        }
        dashboard_set_flash($flashMsg ?? '');
        dashboard_redirect_back();
    }
    $rawLog = security_load_events();
    $securityLog = array_filter($rawLog, fn($entry) => (($entry['tag'] ?? '') !== 'CSRF_ALERT'));
    if (empty($securityLog)) {
        $securityLog = array_filter($rawLog, fn($entry) => in_array(($entry['tag'] ?? ''), ['LOGIN_SUCCESS', 'LOG', 'AUTH_SUCCESS']));
    }
    $messages = dashboard_filter_messages_by_scope($messages);
    return [$messages, $flash, $securityLog];
}
