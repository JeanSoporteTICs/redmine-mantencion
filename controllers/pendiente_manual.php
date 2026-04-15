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
    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
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
        'fecha_inicio' => trim((string)($input['fecha_inicio'] ?? '')),
        'fecha_fin' => trim((string)($input['fecha_fin'] ?? '')),
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
                $form[$key] = isset($_POST[$key]) ? '1' : '0';
                continue;
            }
            $form[$key] = trim((string)($_POST[$key] ?? $value));
        }
        $form['core_usuario_asignado'] = manual_pending_find_user_name((string)($form['asignado_a'] ?? ''), $users);
        $form['core_tipo_solicitud'] = manual_pending_option_name($cfg['trackers'] ?? [], $form['tracker_id'] ?? '');
        $form['core_establecimiento'] = trim((string)($form['unidad'] ?? ''));
        $form['core_departamento'] = trim((string)($form['categoria'] ?? ''));
        $form['core_telefono'] = trim((string)($form['anexo'] ?? ''));

        if ($form['asunto'] === '' || $form['descripcion'] === '' || $form['solicitante'] === '') {
            $error = 'Asunto, descripción y solicitante son obligatorios.';
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
