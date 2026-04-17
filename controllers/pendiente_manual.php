<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard.php';

const MANUAL_PENDING_CF_ANEXO = 4;
const MANUAL_PENDING_CF_CORREO = 8;

function manual_pending_flash_set(string $message): void {
    auth_start_session();
    $_SESSION['manual_pending_flash'] = $message;
}

function manual_pending_flash_consume(): ?string {
    auth_start_session();
    $message = $_SESSION['manual_pending_flash'] ?? null;
    unset($_SESSION['manual_pending_flash']);
    return is_string($message) && trim($message) !== '' ? $message : null;
}

function manual_pending_users(): array {
    $path = __DIR__ . '/../data/usuarios.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    $users = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $nombre = trim((string)($row['nombre'] ?? ''));
        if ($id === '' || $nombre === '') {
            continue;
        }
        $users[] = ['id' => $id, 'nombre' => $nombre];
    }
    usort($users, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));
    return $users;
}

function manual_pending_find_user_name(string $userId, array $users): string {
    foreach ($users as $user) {
        if ((string)($user['id'] ?? '') === $userId) {
            return trim((string)($user['nombre'] ?? ''));
        }
    }
    return '';
}

function manual_pending_load_config(): array {
    $cfg = load_platform_config();
    $cfg['project_id'] = $cfg['project_id'] ?? 48;
    $cfg['project_name'] = trim((string)($cfg['project_name'] ?? 'Backlog Mantencion TI'));
    $cfg['tracker_id'] = $cfg['tracker_id'] ?? 3;
    $cfg['priority_id'] = $cfg['priority_id'] ?? 2;
    $cfg['status_id'] = $cfg['status_id'] ?? 1;
    $cfg['trackers'] = is_array($cfg['trackers'] ?? null) ? $cfg['trackers'] : [];
    $cfg['prioridades'] = is_array($cfg['prioridades'] ?? null) ? $cfg['prioridades'] : [];
    $cfg['estados'] = is_array($cfg['estados'] ?? null) ? $cfg['estados'] : [];
    return $cfg;
}

function manual_pending_option_name(array $items, $id): string {
    foreach ($items as $item) {
        if ((string)($item['id'] ?? '') === (string)$id) {
            return trim((string)($item['nombre'] ?? ''));
        }
    }
    return '';
}

function manual_pending_normalize_option_id($value, array $items, $fallback = ''): string {
    $candidate = trim((string)$value);
    if ($candidate !== '') {
        foreach ($items as $item) {
            if ((string)($item['id'] ?? '') === $candidate) {
                return $candidate;
            }
        }
    }
    $fallback = trim((string)$fallback);
    if ($fallback !== '') {
        foreach ($items as $item) {
            if ((string)($item['id'] ?? '') === $fallback) {
                return $fallback;
            }
        }
    }
    foreach ($items as $item) {
        $id = trim((string)($item['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }
    }
    return '';
}

function manual_pending_normalize_user_id($value, array $users): string {
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    foreach ($users as $user) {
        if ((string)($user['id'] ?? '') === $candidate) {
            return $candidate;
        }
    }
    return '';
}

function manual_pending_normalize_category_name($value, array $categories): string {
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    foreach ($categories as $category) {
        $name = trim((string)($category['nombre'] ?? ''));
        if ($name !== '' && strcasecmp($name, $candidate) === 0) {
            return $name;
        }
    }
    return '';
}

function manual_pending_parse_display_date($value): ?DateTimeImmutable {
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return null;
    }
    if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $candidate)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('d-m-Y', $candidate);
    if (!($dt instanceof DateTimeImmutable)) {
        return null;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        return null;
    }
    if ($dt->format('d-m-Y') !== $candidate) {
        return null;
    }
    return $dt;
}

function manual_pending_normalize_date($value): string {
    $dt = manual_pending_parse_display_date($value);
    return $dt ? $dt->format('d-m-Y') : '';
}

function manual_pending_normalize_hours($value): string {
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    $normalized = str_replace(',', '.', $candidate);
    if (!is_numeric($normalized)) {
        return '';
    }
    $hours = (float)$normalized;
    if ($hours < 0) {
        return '';
    }
    return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
}

function manual_pending_normalize_email($value): string {
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
}

function manual_pending_category_options(): array {
    $path = __DIR__ . '/../data/categorias.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function manual_pending_default_form(array $cfg, array $users): array {
    $currentUserId = (string)(auth_get_user_id() ?? '');
    $currentUserName = $currentUserId !== '' ? manual_pending_find_user_name($currentUserId, $users) : '';
    $today = (new DateTimeImmutable('now'))->format('d-m-Y');
    return [
        'project_id' => (string)($cfg['project_id'] ?? 48),
        'tracker_id' => (string)($cfg['tracker_id'] ?? 3),
        'asunto' => '',
        'descripcion' => '',
        'status_id' => (string)($cfg['status_id'] ?? 1),
        'priority_id' => (string)($cfg['priority_id'] ?? 2),
        'fecha_inicio' => $today,
        'fecha_fin' => '',
        'tiempo_estimado' => '',
        'asignado_a' => $currentUserId,
        'categoria' => '',
        'solicitante' => '',
        'anexo' => '',
        'unidad' => '',
        'core_email' => '',
        'hora_extra' => '0',
        'core_usuario_asignado' => $currentUserName,
        'core_estado' => 'Manual',
        'core_tipo_solicitud' => manual_pending_option_name($cfg['trackers'] ?? [], $cfg['tracker_id'] ?? 3),
        'core_establecimiento' => '',
        'core_departamento' => '',
        'core_telefono' => '',
        'core_celular' => '',
        'core_detalle_tipo_solicitud' => '',
        'core_detalle_run' => '',
        'core_detalle_nombre' => '',
        'core_detalle_motivo' => '',
        'core_detalle_establecimientos' => '',
        'core_detalle_otros_permisos' => '',
    ];
}

function manual_pending_build_record(array $input, array $cfg, array $users): array {
    $now = new DateTimeImmutable('now');
    $trackerName = manual_pending_option_name($cfg['trackers'] ?? [], $input['tracker_id'] ?? '');
    $priorityName = manual_pending_option_name($cfg['prioridades'] ?? [], $input['priority_id'] ?? '');
    $statusName = manual_pending_option_name($cfg['estados'] ?? [], $input['status_id'] ?? '');
    $assignedId = trim((string)($input['asignado_a'] ?? ''));
    $assignedName = $assignedId !== '' ? manual_pending_find_user_name($assignedId, $users) : '';
    $anexo = trim((string)($input['anexo'] ?? ''));
    $correo = trim((string)($input['core_email'] ?? ''));
    $unidad = trim((string)($input['unidad'] ?? ''));
    $categoria = trim((string)($input['categoria'] ?? ''));
    $solicitante = trim((string)($input['solicitante'] ?? ''));
    $asunto = trim((string)($input['asunto'] ?? ''));
    $descripcion = trim((string)($input['descripcion'] ?? ''));
    return [
        'id' => 'manual-' . uniqid('', true),
        'fuente' => 'manual',
        'fuente_id' => sha1(implode('|', ['manual', $asunto, $solicitante, $unidad, $anexo, $correo, $now->format(DateTimeInterface::ATOM)])),
        'numero' => dashboard_normalize_phone($anexo),
        'mensaje' => $asunto,
        'descripcion' => $descripcion,
        'fecha' => $now->format('d-m-Y'),
        'hora' => $now->format('H:i'),
        'fecha_inicio' => manual_pending_normalize_date($input['fecha_inicio'] ?? ''),
        'fecha_fin' => manual_pending_normalize_date($input['fecha_fin'] ?? ''),
        'tipo' => $trackerName !== '' ? $trackerName : 'Soporte',
        'tipo_id' => trim((string)($input['tracker_id'] ?? '')),
        'prioridad' => $priorityName !== '' ? $priorityName : 'Normal',
        'priority_id' => trim((string)($input['priority_id'] ?? '')),
        'status_id' => trim((string)($input['status_id'] ?? '')),
        'project_id' => trim((string)($input['project_id'] ?? ($cfg['project_id'] ?? 48))),
        'estado' => 'pendiente',
        'estado_redmine' => $statusName,
        'hora_extra' => !empty($input['hora_extra']) ? '1' : '0',
        'tiempo_estimado' => trim((string)($input['tiempo_estimado'] ?? '')),
        'categoria' => $categoria,
        'unidad' => $unidad,
        'unidad_solicitante' => $unidad,
        'solicitante' => $solicitante,
        'asunto' => $asunto,
        'asignado_a' => $assignedId,
        'asignado_nombre' => $assignedName,
        'anexo' => $anexo,
        'redmine_id' => '',
        'procesado_ts' => '',
        'core_fecha_creacion' => $now->format('d-m-Y H:i'),
        'core_tipo_solicitud' => $trackerName,
        'core_establecimiento' => $unidad,
        'core_departamento' => $categoria,
        'core_estado' => 'Manual',
        'core_usuario_asignado' => $assignedName,
        'core_email' => $correo,
        'core_telefono' => $anexo,
        'core_celular' => '',
        'core_detalle_tipo_solicitud' => '',
        'core_detalle_run' => '',
        'core_detalle_nombre' => '',
        'core_detalle_motivo' => '',
        'core_detalle_establecimientos' => '',
        'core_detalle_otros_permisos' => '',
    ];
}

function handle_manual_pending(): array {
    $cfg = manual_pending_load_config();
    $users = manual_pending_users();
    $categorias = manual_pending_category_options();
    $form = manual_pending_default_form($cfg, $users);
    $flash = manual_pending_flash_consume();
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) {
            csrf_validate();
        }
        foreach ($form as $key => $value) {
            if ($key === 'hora_extra') {
                $form[$key] = trim((string)($_POST[$key] ?? '0'));
                continue;
            }
            $form[$key] = trim((string)($_POST[$key] ?? $value));
        }
        $form['project_id'] = (string)($cfg['project_id'] ?? 48);
        $form['tracker_id'] = manual_pending_normalize_option_id($form['tracker_id'] ?? '', $cfg['trackers'] ?? [], $cfg['tracker_id'] ?? 3);
        $form['status_id'] = manual_pending_normalize_option_id($form['status_id'] ?? '', $cfg['estados'] ?? [], $cfg['status_id'] ?? 1);
        $form['priority_id'] = manual_pending_normalize_option_id($form['priority_id'] ?? '', $cfg['prioridades'] ?? [], $cfg['priority_id'] ?? 2);
        $form['asignado_a'] = manual_pending_normalize_user_id($form['asignado_a'] ?? '', $users);
        $form['categoria'] = manual_pending_normalize_category_name($form['categoria'] ?? '', $categorias);
        $form['fecha_inicio'] = manual_pending_normalize_date($form['fecha_inicio'] ?? '');
        $form['fecha_fin'] = manual_pending_normalize_date($form['fecha_fin'] ?? '');
        $form['tiempo_estimado'] = manual_pending_normalize_hours($form['tiempo_estimado'] ?? '');
        $form['core_email'] = manual_pending_normalize_email($form['core_email'] ?? '');
        $form['hora_extra'] = ($form['hora_extra'] ?? '0') === '1' ? '1' : '0';
        $form['core_usuario_asignado'] = manual_pending_find_user_name((string)($form['asignado_a'] ?? ''), $users);
        $form['core_tipo_solicitud'] = manual_pending_option_name($cfg['trackers'] ?? [], $form['tracker_id'] ?? '');
        $form['core_establecimiento'] = trim((string)($form['unidad'] ?? ''));
        $form['core_departamento'] = trim((string)($form['categoria'] ?? ''));
        $form['core_telefono'] = trim((string)($form['anexo'] ?? ''));

        if ($form['asunto'] === '' || $form['descripcion'] === '' || $form['solicitante'] === '') {
            $error = 'Asunto, descripción y solicitante son obligatorios.';
        } elseif (trim((string)($_POST['fecha_inicio'] ?? '')) !== '' && $form['fecha_inicio'] === '') {
            $error = 'La fecha de inicio debe estar en formato dd-mm-aaaa.';
        } elseif (trim((string)($_POST['fecha_fin'] ?? '')) !== '' && $form['fecha_fin'] === '') {
            $error = 'La fecha fin debe estar en formato dd-mm-aaaa.';
        } else {
            $messages = load_messages();
            $messages[] = manual_pending_build_record($form, $cfg, $users);
            save_messages($messages);
            manual_pending_flash_set('Pendiente manual creado correctamente.');
            header('Location: /redmine-mantencion/views/Pendientes/manual.php');
            exit;
        }
    }

    return [$cfg, $users, $categorias, $form, $flash, $error];
}
