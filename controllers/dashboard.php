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
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Dashboard/dashboard.php';
    header('Location: ' . $location);
    exit;
}

function dashboard_messages_file(): string {
    return __DIR__ . '/../data/mensaje.json';
}

function dashboard_core_compact_keys(): array {
    return [
        'id',
        'fuente',
        'fuente_id',
        'estado',
        'redmine_id',
        'procesado_ts',
        'hora_extra',
        'tiempo_estimado',
        'fecha_inicio',
        'fecha_fin',
        'asignado_a',
        'solicitante',
        'core_fecha_creacion',
        'core_tipo_solicitud',
        'core_establecimiento',
        'core_departamento',
        'core_estado',
        'core_usuario_asignado',
        'core_email',
        'core_telefono',
        'core_celular',
        'core_detalle_tipo_solicitud',
        'core_detalle_run',
        'core_detalle_nombre',
        'core_detalle_motivo',
        'core_detalle_establecimientos',
        'core_detalle_otros_permisos',
        'core_detalle_fecha_nacimiento',
        'core_detalle_email',
        'core_detalle_departamento',
        'core_detalle_cargo',
        'core_detalle_rol',
        'core_detalle_estado',
        'core_detalle_items',
    ];
}

function dashboard_core_build_subject(array $message): string {
    $establecimiento = trim((string)($message['core_establecimiento'] ?? ''));
    $departamento = trim((string)($message['core_departamento'] ?? ''));
    if (strtoupper($departamento) === 'N/A' || $departamento === $establecimiento) {
        $departamento = '';
    }
    $parts = array_values(array_filter([
        trim((string)($message['core_tipo_solicitud'] ?? '')),
        $establecimiento,
        $departamento,
    ], fn($value) => $value !== '' && strtoupper($value) !== 'N/A'));
    return implode(' / ', $parts);
}

function dashboard_core_build_description(array $message): string {
    $parts = array_filter([
        ($message['core_tipo_solicitud'] ?? '') !== '' ? 'Tipo de solicitud: ' . $message['core_tipo_solicitud'] : '',
        ($message['core_detalle_tipo_solicitud'] ?? '') !== '' ? 'Detalle tipo solicitud: ' . $message['core_detalle_tipo_solicitud'] : '',
        ($message['core_detalle_run'] ?? '') !== '' ? 'RUN: ' . $message['core_detalle_run'] : '',
        ($message['core_detalle_nombre'] ?? '') !== '' ? 'Nombre: ' . $message['core_detalle_nombre'] : '',
        ($message['core_detalle_motivo'] ?? '') !== '' ? 'Motivo: ' . $message['core_detalle_motivo'] : '',
        ($message['core_detalle_establecimientos'] ?? '') !== '' ? 'Establecimientos: ' . $message['core_detalle_establecimientos'] : '',
        ($message['core_detalle_otros_permisos'] ?? '') !== '' ? 'Otros permisos: ' . $message['core_detalle_otros_permisos'] : '',
        ($message['core_establecimiento'] ?? '') !== '' ? 'Establecimiento: ' . $message['core_establecimiento'] : '',
        dashboard_resolve_department_value($message) !== '' ? 'Departamento: ' . dashboard_resolve_department_value($message) : '',
        ($message['core_telefono'] ?? '') !== '' ? 'Telefono: ' . $message['core_telefono'] : '',
        ($message['core_celular'] ?? '') !== '' ? 'Celular: ' . $message['core_celular'] : '',
        ($message['core_estado'] ?? '') !== '' ? 'Estado CORE: ' . $message['core_estado'] : '',
        ($message['core_usuario_asignado'] ?? '') !== '' ? 'Usuario asignado CORE: ' . $message['core_usuario_asignado'] : '',
    ]);
    return implode("\n", $parts);
}

function dashboard_resolve_department_value(array $message): string {
    $departamento = trim((string)($message['core_departamento'] ?? $message['unidad'] ?? ''));
    $establecimiento = trim((string)($message['core_establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
    if ($departamento === '' || strtoupper($departamento) === 'N/A') {
        return $establecimiento !== '' ? $establecimiento : '';
    }
    return $departamento;
}

function dashboard_normalize_email(?string $value): string {
    $email = trim((string)$value);
    if ($email === '') {
        return '';
    }
    return strtolower($email);
}

function dashboard_core_normalize_detail_row(array $item, array $message = []): array {
    $tipo = trim((string)($item['detalle_tipo_solicitud'] ?? $item['tipo solicitud'] ?? $item['tipo_solicitud'] ?? $item['tipo de solicitud'] ?? ''));
    $run = trim((string)($item['detalle_run'] ?? $item['run'] ?? $item['rut'] ?? ''));
    $nombre = trim((string)($item['detalle_nombre'] ?? $item['nombre'] ?? ''));
    $motivo = trim((string)($item['detalle_motivo'] ?? $item['motivo'] ?? ''));
    $establecimientos = trim((string)($item['detalle_establecimientos'] ?? $item['establecimientos'] ?? $item['establecimiento'] ?? ''));
    $otrosPermisos = trim((string)($item['detalle_otros_permisos'] ?? $item['otros permisos'] ?? $item['otros_permisos'] ?? ''));
    $fechaNacimiento = trim((string)($item['detalle_fecha_nacimiento'] ?? $item['fecha de nacimiento'] ?? $item['fecha_nacimiento'] ?? $item['fec_nacimiento'] ?? ''));
    $email = dashboard_normalize_email($item['detalle_email'] ?? $item['email'] ?? $item['correo'] ?? '');
    $departamento = trim((string)($item['detalle_departamento'] ?? $item['departamento'] ?? ''));
    $cargo = trim((string)($item['detalle_cargo'] ?? $item['cargo'] ?? ''));
    $rol = trim((string)($item['detalle_rol'] ?? $item['rol'] ?? ''));
    $estado = trim((string)($item['detalle_estado'] ?? $item['estado'] ?? ''));
    if ($tipo === '') {
        $tipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    }
    if ($nombre === '') {
        $nombre = trim((string)($message['core_detalle_nombre'] ?? $message['solicitante'] ?? ''));
    }
    return [
        'detalle_tipo_solicitud' => $tipo,
        'detalle_run' => $run,
        'detalle_nombre' => $nombre,
        'detalle_motivo' => $motivo,
        'detalle_establecimientos' => $establecimientos,
        'detalle_otros_permisos' => $otrosPermisos,
        'detalle_fecha_nacimiento' => $fechaNacimiento,
        'detalle_email' => $email,
        'detalle_departamento' => $departamento,
        'detalle_cargo' => $cargo,
        'detalle_rol' => $rol,
        'detalle_estado' => $estado,
    ];
}

function dashboard_core_is_creation_request(array $message): bool {
    $tipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    return dashboard_normalize_text($tipo) === 'creacion de usuario';
}

function dashboard_core_is_add_establishment_request(array $message): bool {
    $tipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    return dashboard_normalize_text($tipo) === 'agregar establecimiento';
}

function dashboard_core_detail_table_schema(array $message): array {
    if (dashboard_core_is_creation_request($message)) {
        return [
            ['label' => 'Tipo Solicitud', 'key' => 'detalle_tipo_solicitud'],
            ['label' => 'RUN', 'key' => 'detalle_run'],
            ['label' => 'Nombre', 'key' => 'detalle_nombre'],
            ['label' => 'Fecha de nacimiento', 'key' => 'detalle_fecha_nacimiento'],
            ['label' => 'Email', 'key' => 'detalle_email'],
            ['label' => 'Departamento', 'key' => 'detalle_departamento'],
            ['label' => 'Cargo', 'key' => 'detalle_cargo'],
            ['label' => 'Rol', 'key' => 'detalle_rol'],
            ['label' => 'Estado', 'key' => 'detalle_estado'],
        ];
    }
    if (dashboard_core_is_add_establishment_request($message)) {
        return [
            ['label' => 'Tipo solicitud', 'key' => 'detalle_tipo_solicitud'],
            ['label' => 'RUN', 'key' => 'detalle_run'],
            ['label' => 'Nombre', 'key' => 'detalle_nombre'],
            ['label' => 'Motivo', 'key' => 'detalle_motivo'],
            ['label' => 'Establecimientos', 'key' => 'detalle_establecimientos'],
            ['label' => 'Otros permisos', 'key' => 'detalle_otros_permisos'],
        ];
    }
    return [
        ['label' => 'Tipo solicitud', 'key' => 'detalle_tipo_solicitud'],
        ['label' => 'RUN', 'key' => 'detalle_run'],
        ['label' => 'Nombre', 'key' => 'detalle_nombre'],
        ['label' => 'Motivo', 'key' => 'detalle_motivo'],
        ['label' => 'Otros permisos', 'key' => 'detalle_otros_permisos'],
    ];
}

function dashboard_resolve_unit_value(array $message): string {
    $unidad = dashboard_resolve_department_value($message);
    $establecimiento = trim((string)($message['core_establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
    if ($unidad === '' || strtoupper($unidad) === 'N/A') {
        return $establecimiento !== '' ? $establecimiento : 'N/A';
    }
    return $unidad;
}

function dashboard_build_redmine_core_description(array $message): string {
    $rows = [];
    foreach ((array)($message['core_detalle_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rows[] = dashboard_core_normalize_detail_row($item, $message);
    }
    if (empty($rows)) {
        $rows[] = dashboard_core_normalize_detail_row([
            'detalle_tipo_solicitud' => trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? '')),
            'detalle_run' => trim((string)($message['core_detalle_run'] ?? '')),
            'detalle_nombre' => trim((string)($message['core_detalle_nombre'] ?? ($message['solicitante'] ?? ''))),
            'detalle_motivo' => trim((string)($message['core_detalle_motivo'] ?? '')),
            'detalle_otros_permisos' => trim((string)($message['core_detalle_otros_permisos'] ?? '')),
            'detalle_fecha_nacimiento' => trim((string)($message['core_detalle_fecha_nacimiento'] ?? '')),
            'detalle_email' => dashboard_normalize_email($message['core_detalle_email'] ?? ''),
            'detalle_departamento' => trim((string)($message['core_detalle_departamento'] ?? '')),
            'detalle_cargo' => trim((string)($message['core_detalle_cargo'] ?? '')),
            'detalle_rol' => trim((string)($message['core_detalle_rol'] ?? '')),
            'detalle_estado' => trim((string)($message['core_detalle_estado'] ?? '')),
        ], $message);
    }
    $rows = array_values(array_filter($rows, function (array $row): bool {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }));
    if (empty($rows)) {
        return '';
    }
    $schema = dashboard_core_detail_table_schema($message);
    $header = '|_. ' . implode('|_. ', array_map(fn($column) => $column['label'], $schema)) . '|';
    $lines = [$header];
    foreach ($rows as $row) {
        $values = array_map(
            fn($value) => str_replace(["\r", "\n", '|'], [' ', ' ', '/'], trim((string)$value)),
            array_map(fn($column) => $row[$column['key']] ?? '', $schema)
        );
        $lines[] = '|' . implode('|', $values) . '|';
    }
    return implode("\n", $lines);
}

function dashboard_expand_message(array $message): array {
    if (($message['fuente'] ?? '') !== 'core') {
        return $message;
    }
    [$fecha, $hora] = dashboard_core_parse_datetime((string)($message['core_fecha_creacion'] ?? ''));
    $numero = dashboard_normalize_phone((string)(($message['core_celular'] ?? '') !== '' ? ($message['core_celular'] ?? '') : ($message['core_telefono'] ?? '')));
    $subject = dashboard_core_build_subject($message);
    $message['numero'] = $numero;
    $message['mensaje'] = trim((string)($message['core_tipo_solicitud'] ?? ''));
    $message['descripcion'] = dashboard_core_build_description($message);
    $message['fecha'] = $fecha;
    $message['hora'] = $hora;
    $message['fecha_inicio'] = trim((string)($message['fecha_inicio'] ?? '')) !== '' ? $message['fecha_inicio'] : $fecha;
    $message['fecha_fin'] = trim((string)($message['fecha_fin'] ?? '')) !== '' ? $message['fecha_fin'] : $fecha;
    $message['tipo'] = 'Soporte';
    $message['prioridad'] = 'NORMAL';
    if (trim((string)($message['categoria'] ?? '')) !== '') {
        $message['categoria'] = $message['categoria'];
    } else {
        $message['categoria'] = dashboard_core_resolve_category(
            trim((string)($message['core_tipo_solicitud'] ?? '')),
            dashboard_catalog_names(__DIR__ . '/../data/categorias.json')
        );
    }
    $message['unidad'] = dashboard_resolve_unit_value($message);
    $message['unidad_solicitante'] = trim((string)($message['core_establecimiento'] ?? ''));
    $message['asunto'] = $subject;
    $message['asignado_nombre'] = trim((string)($message['core_usuario_asignado'] ?? ''));
    $message['hora_extra'] = trim((string)($message['hora_extra'] ?? '')) !== '' ? $message['hora_extra'] : '0';
    $message['tiempo_estimado'] = trim((string)($message['tiempo_estimado'] ?? ''));
    $message['core_detalle_items'] = array_values(array_filter(array_map(
        fn($item) => is_array($item) ? dashboard_core_normalize_detail_row($item, $message) : null,
        (array)($message['core_detalle_items'] ?? [])
    )));
    $message['core_detalle_fecha_nacimiento'] = trim((string)($message['core_detalle_fecha_nacimiento'] ?? ''));
    $message['core_detalle_email'] = dashboard_normalize_email($message['core_detalle_email'] ?? '');
    $message['core_detalle_departamento'] = trim((string)($message['core_detalle_departamento'] ?? ''));
    $message['core_detalle_cargo'] = trim((string)($message['core_detalle_cargo'] ?? ''));
    $message['core_detalle_rol'] = trim((string)($message['core_detalle_rol'] ?? ''));
    $message['core_detalle_estado'] = trim((string)($message['core_detalle_estado'] ?? ''));
    $message['redmine_id'] = trim((string)($message['redmine_id'] ?? ''));
    $message['procesado_ts'] = trim((string)($message['procesado_ts'] ?? ''));
    return $message;
}

function dashboard_compact_message(array $message): array {
    if (($message['fuente'] ?? '') !== 'core') {
        return $message;
    }
    $compact = [];
    foreach (dashboard_core_compact_keys() as $key) {
        if (array_key_exists($key, $message)) {
            $compact[$key] = $message[$key];
        }
    }
    $compact['fuente'] = 'core';
    $compact['estado'] = trim((string)($compact['estado'] ?? '')) !== '' ? $compact['estado'] : 'pendiente';
    $compact['hora_extra'] = trim((string)($compact['hora_extra'] ?? '')) !== '' ? $compact['hora_extra'] : '0';
    $compact['tiempo_estimado'] = trim((string)($compact['tiempo_estimado'] ?? ''));
    $compact['redmine_id'] = trim((string)($compact['redmine_id'] ?? ''));
    $compact['procesado_ts'] = trim((string)($compact['procesado_ts'] ?? ''));
    return $compact;
}

function load_messages(): array {
    $file = dashboard_messages_file();
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    return array_values(array_map(fn($message) => is_array($message) ? dashboard_expand_message($message) : [], $data));
}

function save_messages(array $messages): void {
    $file = dashboard_messages_file();
    $compact = array_values(array_map(fn($message) => is_array($message) ? dashboard_compact_message($message) : [], $messages));
    file_put_contents($file, json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function load_platform_config(): array {
    $path = __DIR__ . '/../data/configuracion.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function save_platform_config(array $cfg): void {
    $path = __DIR__ . '/../data/configuracion.json';
    file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function dashboard_normalize_text(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim($value ?? '');
}

function dashboard_normalize_phone(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 9 && $digits[0] === '9') {
        return '+56' . $digits;
    }
    if (str_starts_with($digits, '56')) {
        return '+' . $digits;
    }
    return '+' . $digits;
}

function dashboard_catalog_names(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    $names = [];
    foreach ($data as $row) {
        if (is_array($row) && isset($row['nombre'])) {
            $names[] = (string)$row['nombre'];
        }
    }
    return $names;
}

function dashboard_infer_catalog_match(string $value, array $catalog, string $fallback = ''): string {
    $needle = dashboard_normalize_text($value);
    if ($needle === '') {
        return $fallback;
    }
    foreach ($catalog as $candidate) {
        $normalized = dashboard_normalize_text((string)$candidate);
        if ($normalized !== '' && ($normalized === $needle || str_contains($needle, $normalized) || str_contains($normalized, $needle))) {
            return (string)$candidate;
        }
    }
    return $fallback;
}

function dashboard_core_category_aliases(): array {
    return [
        'modificar usuario' => 'Modificar Perfil CORE',
    ];
}

function dashboard_core_resolve_category(string $tipoSolicitud, array $catalog): string {
    $tipoSolicitud = trim($tipoSolicitud);
    if ($tipoSolicitud === '') {
        return 'Equipos';
    }
    $normalizedType = dashboard_normalize_text($tipoSolicitud);
    foreach ($catalog as $candidate) {
        $normalizedCandidate = dashboard_normalize_text((string)$candidate);
        if ($normalizedCandidate === '') {
            continue;
        }
        if ($normalizedCandidate === $normalizedType) {
            return (string)$candidate;
        }
        if (str_contains($normalizedCandidate, $normalizedType) || str_contains($normalizedType, $normalizedCandidate)) {
            return (string)$candidate;
        }
    }
    $aliases = dashboard_core_category_aliases();
    if (isset($aliases[$normalizedType])) {
        return $aliases[$normalizedType];
    }
    return dashboard_infer_catalog_match($tipoSolicitud, $catalog, 'Equipos');
}

function dashboard_load_user_maps(): array {
    $path = __DIR__ . '/../data/usuarios.json';
    $result = ['phone' => [], 'name' => []];
    if (!file_exists($path)) {
        return $result;
    }
    $users = json_decode((string)file_get_contents($path), true);
    if (!is_array($users)) {
        return $result;
    }
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $phone = dashboard_normalize_phone((string)($user['numero_celular'] ?? ''));
        if ($phone !== '') {
            $result['phone'][$phone] = $user;
        }
        $fullName = trim((string)($user['nombre'] ?? ''));
        $fullNameKey = dashboard_normalize_text($fullName);
        if ($fullNameKey !== '') {
            $result['name'][$fullNameKey] = $user;
        }
    }
    return $result;
}

function dashboard_core_is_configured(array $cfg): bool {
    return !empty($cfg['core_enabled'])
        && trim((string)($cfg['core_admin_url'] ?? '')) !== '';
}

function dashboard_should_auto_sync_core(array $cfg): bool {
    return false;
}

function dashboard_core_runtime_credentials(array $input = []): array {
    return [
        'user' => trim((string)($input['user'] ?? '')),
        'pass' => trim((string)($input['pass'] ?? '')),
    ];
}

function dashboard_core_has_runtime_credentials(array $credentials): bool {
    return trim((string)($credentials['user'] ?? '')) !== ''
        && trim((string)($credentials['pass'] ?? '')) !== '';
}

function dashboard_core_curl(string $url, array $options = []): array {
    $ch = curl_init($url);
    $default = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'HBV Redmine Sync/1.0',
    ];
    curl_setopt_array($ch, $options + $default);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [
        'body' => $body === false ? '' : (string)$body,
        'error' => $error,
        'http_code' => $httpCode,
        'effective_url' => $effectiveUrl,
    ];
}

function dashboard_core_parse_login_form(string $html, string $baseUrl): array {
    $form = [
        'action' => $baseUrl,
        'csrf_token' => '',
        'has_login_form' => false,
        'fields' => [],
    ];
    if ($html === '') {
        return $form;
    }
    if (preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER)) {
        foreach ($forms as $match) {
            $attrs = $match[1] ?? '';
            $inner = $match[2] ?? '';
            if (!str_contains($inner, 'name="login_string"') || !str_contains($inner, 'name="login_pass"')) {
                continue;
            }
            $form['has_login_form'] = true;
            $action = '';
            if (preg_match('/action\s*=\s*"([^"]+)"/i', $attrs, $actionMatch)) {
                $action = trim($actionMatch[1]);
            }
            if ($action !== '') {
                if (preg_match('~^https?://~i', $action)) {
                    $form['action'] = $action;
                } else {
                    $parts = parse_url($baseUrl);
                    $scheme = $parts['scheme'] ?? 'https';
                    $host = $parts['host'] ?? '';
                    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                    $prefix = $scheme . '://' . $host . $port;
                    $form['action'] = str_starts_with($action, '/') ? $prefix . $action : rtrim(dirname($baseUrl), '/') . '/' . ltrim($action, '/');
                }
            }
            if (preg_match_all('/<input\b([^>]*)>/is', $inner, $inputMatches, PREG_SET_ORDER)) {
                foreach ($inputMatches as $inputMatch) {
                    $inputAttrs = $inputMatch[1] ?? '';
                    if (!preg_match('/name\s*=\s*"([^"]+)"/i', $inputAttrs, $nameMatch)) {
                        continue;
                    }
                    $fieldName = trim($nameMatch[1]);
                    if ($fieldName === '') {
                        continue;
                    }
                    $fieldValue = '';
                    if (preg_match('/value\s*=\s*"([^"]*)"/i', $inputAttrs, $valueMatch)) {
                        $fieldValue = $valueMatch[1];
                    }
                    $form['fields'][$fieldName] = $fieldValue;
                }
            }
            if (isset($form['fields']['csrf_token'])) {
                $form['csrf_token'] = (string)$form['fields']['csrf_token'];
            }
            break;
        }
    }
    return $form;
}

function dashboard_core_extract_rows(string $html): array {
    $requiredHeaders = [
        'solicitante',
        'fecha de creacion',
        'tipo de solicitud',
        'establecimiento',
        'departamento',
        'telefono',
        'celular',
        'email',
        'estado',
        'usuario asignado',
    ];
    $rows = [];
    if ($html === '') {
        return $rows;
    }
    if (!preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $html, $tables, PREG_SET_ORDER)) {
        return $rows;
    }
    foreach ($tables as $tableMatch) {
        $tableHtml = $tableMatch[1] ?? '';
        if (!preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $tableHtml, $headerMatches)) {
            continue;
        }
        $headers = [];
        foreach (($headerMatches[1] ?? []) as $headerHtml) {
            $headers[] = trim(html_entity_decode(strip_tags($headerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (empty($headers)) {
            continue;
        }
        $normalizedHeaders = array_map('dashboard_normalize_text', $headers);
        $missing = array_diff($requiredHeaders, $normalizedHeaders);
        if (!empty($missing)) {
            continue;
        }
        if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($rowMatches as $rowIndex => $trMatch) {
            if ($rowIndex === 0) {
                continue;
            }
            $rowHtml = $trMatch[1] ?? '';
            if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches)) {
                continue;
            }
            $cells = $cellMatches[1] ?? [];
            if (count($cells) < count($headers)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $headerText) {
                $key = dashboard_normalize_text((string)$headerText);
                $row[$key] = trim(html_entity_decode(strip_tags($cells[$index] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (($row['solicitante'] ?? '') === '') {
                continue;
            }
            $rows[] = $row;
        }
        if (!empty($rows)) {
            return $rows;
        }
    }
    return $rows;
}

function dashboard_core_extract_detail_table_rows(string $html): array {
    $requiredHeaders = [
        'tipo solicitud',
        'run',
        'nombre',
        'motivo',
        'otros permisos',
    ];
    $rows = [];
    if ($html === '') {
        return $rows;
    }
    if (!preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $html, $tables, PREG_SET_ORDER)) {
        return $rows;
    }
    foreach ($tables as $tableMatch) {
        $tableHtml = $tableMatch[1] ?? '';
        if (!preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $tableHtml, $headerMatches)) {
            continue;
        }
        $headers = [];
        foreach (($headerMatches[1] ?? []) as $headerHtml) {
            $headers[] = dashboard_normalize_text(trim(html_entity_decode(strip_tags($headerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        if (empty($headers)) {
            continue;
        }
        if (!empty(array_diff($requiredHeaders, $headers))) {
            continue;
        }
        if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($rowMatches as $rowIndex => $trMatch) {
            if ($rowIndex === 0) {
                continue;
            }
            $rowHtml = $trMatch[1] ?? '';
            if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches)) {
                continue;
            }
            $cells = $cellMatches[1] ?? [];
            if (count($cells) < count($headers)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $headerText) {
                $row[$headerText] = trim(html_entity_decode(strip_tags($cells[$index] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $normalized = dashboard_core_normalize_detail_row($row);
            $hasValue = false;
            foreach ($normalized as $value) {
                if (trim((string)$value) !== '' && trim((string)$value) !== '-') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $rows[] = $normalized;
            }
        }
        if (!empty($rows)) {
            return $rows;
        }
    }
    return $rows;
}

function dashboard_array_is_list(array $value): bool {
    $index = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $index) {
            return false;
        }
        $index++;
    }
    return true;
}

function dashboard_name_tokens_match(string $expected, string $candidate): bool {
    $expected = dashboard_normalize_text($expected);
    $candidate = dashboard_normalize_text($candidate);
    if ($expected === '' || $candidate === '') {
        return false;
    }
    if ($expected === $candidate || str_contains($candidate, $expected) || str_contains($expected, $candidate)) {
        return true;
    }
    $expectedTokens = array_values(array_filter(explode(' ', $expected)));
    $candidateTokens = array_values(array_filter(explode(' ', $candidate)));
    if (empty($expectedTokens) || empty($candidateTokens)) {
        return false;
    }
    foreach ($expectedTokens as $token) {
        if (!in_array($token, $candidateTokens, true)) {
            return false;
        }
    }
    return true;
}

function dashboard_core_pick_first_value(array $item, array $keys): string {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $value = trim((string)($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function dashboard_core_pick_first_recursive(array $item, array $keys): string {
    $direct = dashboard_core_pick_first_value($item, $keys);
    if ($direct !== '') {
        return $direct;
    }
    foreach ($item as $value) {
        if (!is_array($value)) {
            continue;
        }
        $found = dashboard_core_pick_first_recursive($value, $keys);
        if ($found !== '') {
            return $found;
        }
    }
    return '';
}

function dashboard_core_collect_recursive_strings(mixed $value): array {
    $strings = [];
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '') {
            $strings[] = $trimmed;
        }
        return $strings;
    }
    if (!is_array($value)) {
        return $strings;
    }
    foreach ($value as $child) {
        foreach (dashboard_core_collect_recursive_strings($child) as $item) {
            $strings[] = $item;
        }
    }
    return $strings;
}

function dashboard_core_extract_detail_fields(array $item): array {
    $details = [
        'detalle_tipo_solicitud' => dashboard_core_pick_first_recursive($item, ['detalle_tipo_solicitud', 'tipo_solicitud_detalle', 'detalle_tipo', 'detalle_tipo_sol']),
        'detalle_run' => dashboard_core_pick_first_recursive($item, ['run', 'rut', 'detalle_run', 'detalle_rut']),
        'detalle_nombre' => dashboard_core_pick_first_recursive($item, ['nombre', 'detalle_nombre', 'nombre_usuario', 'usuario_nombre']),
        'detalle_motivo' => dashboard_core_pick_first_recursive($item, ['motivo', 'detalle_motivo']),
        'detalle_establecimientos' => dashboard_core_pick_first_recursive($item, ['establecimientos', 'detalle_establecimientos', 'detalle_estab']),
        'detalle_otros_permisos' => dashboard_core_pick_first_recursive($item, ['otros_permisos', 'detalle_otros_permisos', 'permisos_adicionales']),
        'detalle_fecha_nacimiento' => dashboard_core_pick_first_recursive($item, ['fecha_nacimiento', 'fec_nacimiento', 'detalle_fecha_nacimiento']),
        'detalle_email' => dashboard_normalize_email(dashboard_core_pick_first_recursive($item, ['email', 'correo', 'detalle_email'])),
        'detalle_departamento' => dashboard_core_pick_first_recursive($item, ['departamento', 'detalle_departamento']),
        'detalle_cargo' => dashboard_core_pick_first_recursive($item, ['cargo', 'detalle_cargo']),
        'detalle_rol' => dashboard_core_pick_first_recursive($item, ['rol', 'detalle_rol']),
        'detalle_estado' => dashboard_core_pick_first_recursive($item, ['estado', 'detalle_estado']),
    ];
    $blob = dashboard_core_pick_first_recursive($item, ['detalle', 'detalle_solicitud', 'descripcion', 'observacion', 'observaciones']);
    if ($blob !== '') {
        $normalizedBlob = preg_replace("/\r\n?/", "\n", html_entity_decode(strip_tags($blob), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $patterns = [
            'detalle_tipo_solicitud' => ['tipo solicitud', 'tipo de solicitud'],
            'detalle_run' => ['run', 'rut'],
            'detalle_nombre' => ['nombre'],
            'detalle_motivo' => ['motivo'],
            'detalle_establecimientos' => ['establecimientos', 'establecimiento'],
            'detalle_otros_permisos' => ['otros permisos', 'permisos'],
            'detalle_fecha_nacimiento' => ['fecha de nacimiento', 'fecha nacimiento'],
            'detalle_email' => ['email', 'correo'],
            'detalle_departamento' => ['departamento'],
            'detalle_cargo' => ['cargo'],
            'detalle_rol' => ['rol'],
            'detalle_estado' => ['estado'],
        ];
        foreach ($patterns as $field => $labels) {
            if ($details[$field] !== '') {
                continue;
            }
            foreach ($labels as $label) {
                $regex = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)(?=\n\s*[A-Za-zÁÉÍÓÚáéíóúÑñ ]+\s*:|$)/isu';
                if (preg_match($regex, $normalizedBlob, $match)) {
                    $details[$field] = trim($match[1]);
                    break;
                }
            }
        }
    }
    return $details;
}

function dashboard_core_detail_defaults(): array {
    return [
        'detalle_tipo_solicitud' => '',
        'detalle_run' => '',
        'detalle_nombre' => '',
        'detalle_motivo' => '',
        'detalle_establecimientos' => '',
        'detalle_otros_permisos' => '',
        'detalle_fecha_nacimiento' => '',
        'detalle_email' => '',
        'detalle_departamento' => '',
        'detalle_cargo' => '',
        'detalle_rol' => '',
        'detalle_estado' => '',
        'detalle_items' => [],
    ];
}

function dashboard_core_merge_detail_fields(array $base, array $extra): array {
    foreach (dashboard_core_detail_defaults() as $key => $default) {
        if ($key === 'detalle_items') {
            $baseItems = [];
            foreach ((array)($base[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $baseItems[] = dashboard_core_normalize_detail_row($item);
                }
            }
            if (empty($baseItems)) {
                foreach ((array)($extra[$key] ?? []) as $item) {
                    if (is_array($item)) {
                        $baseItems[] = dashboard_core_normalize_detail_row($item);
                    }
                }
            }
            $base[$key] = $baseItems;
            continue;
        }
        if (trim((string)($base[$key] ?? '')) === '' && trim((string)($extra[$key] ?? '')) !== '') {
            $base[$key] = trim((string)$extra[$key]);
        }
    }
    return $base;
}

function dashboard_core_detail_slug(string $tipoSolicitud): string {
    $tipo = dashboard_normalize_text($tipoSolicitud);
    if ($tipo === '') {
        return '';
    }
    $tokens = array_values(array_filter(explode(' ', $tipo), fn($token) => $token !== 'de'));
    return implode('_', $tokens);
}

function dashboard_core_detail_url(string $baseUrl, array $row): string {
    $solicitudId = trim((string)($row['id_solicitud_core'] ?? $row['id'] ?? ''));
    $tipoSolicitud = trim((string)($row['tipo de solicitud'] ?? ''));
    $slug = dashboard_core_detail_slug($tipoSolicitud);
    if ($solicitudId === '' || $slug === '') {
        return '';
    }
    $baseUrl = rtrim($baseUrl, '/');
    if ($baseUrl === '') {
        return '';
    }
    return $baseUrl . '/obtener_detalle_' . $slug . '/' . rawurlencode($solicitudId);
}

function dashboard_core_extract_detail_from_body(string $body): array {
    $details = dashboard_core_detail_defaults();
    $json = json_decode($body, true);
    if (is_array($json)) {
        $jsonItems = dashboard_array_is_list($json) ? $json : [$json];
        $normalizedItems = [];
        foreach ($jsonItems as $jsonItem) {
            if (!is_array($jsonItem)) {
                continue;
            }
            $normalizedRow = dashboard_core_normalize_detail_row($jsonItem);
            $hasValue = false;
            foreach ($normalizedRow as $value) {
                if (trim((string)$value) !== '' && trim((string)$value) !== '-') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $normalizedItems[] = $normalizedRow;
            }
        }
        if (!empty($normalizedItems)) {
            $details['detalle_items'] = $normalizedItems;
            $details = dashboard_core_merge_detail_fields($details, $normalizedItems[0]);
        }
        $details = dashboard_core_merge_detail_fields($details, dashboard_core_extract_detail_fields($json));
        foreach (dashboard_core_collect_recursive_strings($json) as $candidateHtml) {
            $detailItems = dashboard_core_extract_detail_table_rows($candidateHtml);
            if (!empty($detailItems)) {
                $details['detalle_items'] = $detailItems;
                $details = dashboard_core_merge_detail_fields($details, $detailItems[0]);
                break;
            }
        }
    }
    if (empty($details['detalle_items'])) {
        $detailItems = dashboard_core_extract_detail_table_rows($body);
    } else {
        $detailItems = [];
    }
    if (!empty($detailItems)) {
        $details['detalle_items'] = $detailItems;
        $details = dashboard_core_merge_detail_fields($details, $detailItems[0]);
    }
    $normalizedBody = preg_replace("/\r\n?/", "\n", html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($normalizedBody === null) {
        $normalizedBody = '';
    }
    $patterns = [
        'detalle_tipo_solicitud' => ['tipo solicitud', 'tipo de solicitud'],
        'detalle_run' => ['run', 'rut'],
        'detalle_nombre' => ['nombre'],
        'detalle_motivo' => ['motivo'],
        'detalle_establecimientos' => ['establecimientos', 'establecimiento'],
        'detalle_otros_permisos' => ['otros permisos', 'permisos'],
    ];
    foreach ($patterns as $field => $labels) {
        if (trim((string)$details[$field]) !== '') {
            continue;
        }
        foreach ($labels as $label) {
            $regex = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)(?=\n\s*[A-Za-zÁÉÍÓÚáéíóúÑñ ]+\s*:|$)/isu';
            if (preg_match($regex, $normalizedBody, $match)) {
                $details[$field] = trim($match[1]);
                break;
            }
        }
    }
    return $details;
}

function dashboard_core_enrich_rows_with_detail(array $rows, string $baseUrl, string $cookieJar, array $requestHeaders): array {
    $startedAt = microtime(true);
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((microtime(true) - $startedAt) >= 45) {
            break;
        }
        $detailUrl = dashboard_core_detail_url($baseUrl, $row);
        if ($detailUrl === '') {
            continue;
        }
        $detailResponse = dashboard_core_curl($detailUrl, [
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);
        if (($detailResponse['error'] ?? '') !== '' || (int)($detailResponse['http_code'] ?? 0) >= 400) {
            continue;
        }
        $detailFields = dashboard_core_extract_detail_from_body((string)($detailResponse['body'] ?? ''));
        $rows[$index] = dashboard_core_merge_detail_fields($row, $detailFields);
    }
    return $rows;
}

function dashboard_core_source_row_matches_filters(array $row, array $filters = []): bool {
    $desde = trim((string)($filters['desde'] ?? ''));
    $hasta = trim((string)($filters['hasta'] ?? ''));
    $assigned = trim((string)($filters['assigned'] ?? ''));
    $fecha = parse_issue_date((string)($row['fecha de creacion'] ?? ''));
    if ($desde !== '' && $fecha !== null && $fecha < $desde) {
        return false;
    }
    if ($hasta !== '' && $fecha !== null && $fecha > $hasta) {
        return false;
    }
    if ($assigned !== '') {
        $candidate = trim((string)($row['usuario asignado'] ?? ''));
        if ($candidate === '' || !dashboard_name_tokens_match($assigned, $candidate)) {
            return false;
        }
    }
    return true;
}

function dashboard_core_extract_json_rows(string $body): array {
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return [];
    }
    $items = dashboard_array_is_list($payload) ? $payload : [$payload];
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = [
            'id' => dashboard_core_pick_first_recursive($item, ['id']),
            'id_solicitud_core' => dashboard_core_pick_first_recursive($item, ['id']),
            'solicitante' => dashboard_core_pick_first_recursive($item, ['solicitante']),
            'fecha de creacion' => dashboard_core_pick_first_recursive($item, ['fec_creacion', 'fecha_creacion']),
            'tipo de solicitud' => dashboard_core_pick_first_recursive($item, ['tipo_sol', 'tipo_solicitud']),
            'establecimiento' => dashboard_core_pick_first_recursive($item, ['estab', 'establecimiento']),
            'departamento' => dashboard_core_pick_first_recursive($item, ['departamento']),
            'telefono' => dashboard_core_pick_first_recursive($item, ['fono', 'telefono']),
            'celular' => dashboard_core_pick_first_recursive($item, ['celular']),
            'email' => dashboard_core_pick_first_recursive($item, ['correo', 'email']),
            'estado' => dashboard_core_pick_first_recursive($item, ['estado']),
            'usuario asignado' => dashboard_core_pick_first_recursive($item, ['usuario_asignado', 'asignado']),
        ];
        $row = array_merge($row, dashboard_core_extract_detail_fields($item));
        if ($row['solicitante'] === '' && $row['tipo de solicitud'] === '' && $row['establecimiento'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function dashboard_core_parse_datetime(string $value): array {
    $value = trim($value);
    if ($value === '') {
        $now = new DateTimeImmutable();
        return [$now->format('d-m-Y'), $now->format('H:i:s')];
    }
    $formats = ['d-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return [$dt->format('d-m-Y'), $dt->format('H:i:s')];
        }
    }
    $ts = strtotime($value);
    if ($ts !== false) {
        $dt = (new DateTimeImmutable())->setTimestamp($ts);
        return [$dt->format('d-m-Y'), $dt->format('H:i:s')];
    }
    $now = new DateTimeImmutable();
    return [$now->format('d-m-Y'), $now->format('H:i:s')];
}

function dashboard_core_build_message(array $row, array $catalogs, array $users): array {
    [$fecha, $hora] = dashboard_core_parse_datetime((string)($row['fecha de creacion'] ?? ''));
    $solicitante = trim((string)($row['solicitante'] ?? ''));
    $tipoSolicitud = trim((string)($row['tipo de solicitud'] ?? ''));
    $establecimiento = trim((string)($row['establecimiento'] ?? ''));
    $departamento = trim((string)($row['departamento'] ?? ''));
    $telefono = trim((string)($row['telefono'] ?? ''));
    $celular = trim((string)($row['celular'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $estadoCore = trim((string)($row['estado'] ?? ''));
    $usuarioAsignado = trim((string)($row['usuario asignado'] ?? ''));
    $detalleTipoSolicitud = trim((string)($row['detalle_tipo_solicitud'] ?? ''));
    $detalleRun = trim((string)($row['detalle_run'] ?? ''));
    $detalleNombre = trim((string)($row['detalle_nombre'] ?? ''));
    $detalleMotivo = trim((string)($row['detalle_motivo'] ?? ''));
    $detalleEstablecimientos = trim((string)($row['detalle_establecimientos'] ?? ''));
    $detalleOtrosPermisos = trim((string)($row['detalle_otros_permisos'] ?? ''));
    $detalleFechaNacimiento = trim((string)($row['detalle_fecha_nacimiento'] ?? ''));
    $detalleEmail = dashboard_normalize_email($row['detalle_email'] ?? '');
    $detalleDepartamento = trim((string)($row['detalle_departamento'] ?? ''));
    $detalleCargo = trim((string)($row['detalle_cargo'] ?? ''));
    $detalleRol = trim((string)($row['detalle_rol'] ?? ''));
    $detalleEstado = trim((string)($row['detalle_estado'] ?? ''));
    $detalleItems = [];
    foreach ((array)($row['detalle_items'] ?? []) as $detailItem) {
        if (!is_array($detailItem)) {
            continue;
        }
        $detalleItems[] = dashboard_core_normalize_detail_row($detailItem, [
            'core_tipo_solicitud' => $tipoSolicitud,
            'solicitante' => $solicitante,
        ]);
    }
    $numero = dashboard_normalize_phone($celular !== '' ? $celular : $telefono);
    $descripcion = implode("\n", array_filter([
        'Tipo de solicitud: ' . $tipoSolicitud,
        $detalleTipoSolicitud !== '' ? 'Detalle tipo solicitud: ' . $detalleTipoSolicitud : '',
        $detalleRun !== '' ? 'RUN: ' . $detalleRun : '',
        $detalleNombre !== '' ? 'Nombre: ' . $detalleNombre : '',
        $detalleMotivo !== '' ? 'Motivo: ' . $detalleMotivo : '',
        $detalleEstablecimientos !== '' ? 'Establecimientos: ' . $detalleEstablecimientos : '',
        $detalleOtrosPermisos !== '' ? 'Otros permisos: ' . $detalleOtrosPermisos : '',
        'Establecimiento: ' . $establecimiento,
        'Departamento: ' . $departamento,
        $telefono !== '' ? 'Telefono: ' . $telefono : '',
        $celular !== '' ? 'Celular: ' . $celular : '',
        $email !== '' ? 'Email: ' . $email : '',
        $estadoCore !== '' ? 'Estado CORE: ' . $estadoCore : '',
        $usuarioAsignado !== '' ? 'Usuario asignado CORE: ' . $usuarioAsignado : '',
    ]));
    $categoria = dashboard_core_resolve_category($tipoSolicitud, $catalogs['categorias'] ?? []);
    $unidad = $departamento !== '' ? $departamento : ($establecimiento !== '' ? $establecimiento : 'HBV');
    $unidadSolicitante = dashboard_infer_catalog_match(trim($departamento . ' ' . $establecimiento), $catalogs['unidades'] ?? [], $establecimiento !== '' ? $establecimiento : 'HBV');
    $sourceKey = sha1(implode('|', [
        $solicitante,
        $fecha,
        $hora,
        $tipoSolicitud,
        $establecimiento,
        $departamento,
        $telefono,
        $celular,
        $email,
    ]));
    $assignedUser = null;
    if ($numero !== '' && isset($users['phone'][$numero])) {
        $assignedUser = $users['phone'][$numero];
    }
    $assignedByNameKey = dashboard_normalize_text($usuarioAsignado);
    if ($assignedUser === null && $assignedByNameKey !== '' && isset($users['name'][$assignedByNameKey])) {
        $assignedUser = $users['name'][$assignedByNameKey];
    }
    return [
        'id' => 'core-' . substr($sourceKey, 0, 20),
        'fuente' => 'core',
        'fuente_id' => $sourceKey,
        'numero' => $numero,
        'mensaje' => $tipoSolicitud,
        'descripcion' => $descripcion,
        'fecha' => $fecha,
        'hora' => $hora,
        'fecha_inicio' => $fecha,
        'fecha_fin' => $fecha,
        'tipo' => 'Soporte',
        'prioridad' => 'NORMAL',
        'estado' => 'pendiente',
        'hora_extra' => '0',
        'tiempo_estimado' => '',
        'categoria' => $categoria,
        'unidad' => $unidad,
        'unidad_solicitante' => $unidadSolicitante,
        'solicitante' => $solicitante,
        'asunto' => trim($tipoSolicitud . ' / ' . $unidad),
        'asignado_a' => (string)($assignedUser['id'] ?? ''),
        'asignado_nombre' => $usuarioAsignado !== '' ? $usuarioAsignado : trim((string)($assignedUser['nombre'] ?? '')),
        'core_fecha_creacion' => trim((string)($row['fecha de creacion'] ?? '')),
        'core_tipo_solicitud' => $tipoSolicitud,
        'core_establecimiento' => $establecimiento,
        'core_departamento' => $departamento,
        'core_estado' => $estadoCore,
        'core_usuario_asignado' => $usuarioAsignado,
        'core_email' => dashboard_normalize_email($email),
        'core_telefono' => $telefono,
        'core_celular' => $celular,
        'core_detalle_tipo_solicitud' => $detalleTipoSolicitud,
        'core_detalle_run' => $detalleRun,
        'core_detalle_nombre' => $detalleNombre,
        'core_detalle_motivo' => $detalleMotivo,
        'core_detalle_establecimientos' => $detalleEstablecimientos,
        'core_detalle_otros_permisos' => $detalleOtrosPermisos,
        'core_detalle_fecha_nacimiento' => $detalleFechaNacimiento,
        'core_detalle_email' => $detalleEmail,
        'core_detalle_departamento' => $detalleDepartamento,
        'core_detalle_cargo' => $detalleCargo,
        'core_detalle_rol' => $detalleRol,
        'core_detalle_estado' => $detalleEstado,
        'core_detalle_items' => $detalleItems,
    ];
}

function dashboard_core_row_matches_filters(array $message, array $filters = []): bool {
    $desde = trim((string)($filters['desde'] ?? ''));
    $hasta = trim((string)($filters['hasta'] ?? ''));
    $assigned = trim((string)($filters['assigned'] ?? ''));
    $fecha = parse_issue_date((string)($message['core_fecha_creacion'] ?? $message['fecha'] ?? ''));
    if ($desde !== '' && $fecha !== null && $fecha < $desde) {
        return false;
    }
    if ($hasta !== '' && $fecha !== null && $fecha > $hasta) {
        return false;
    }
    if ($assigned !== '') {
        $candidate = trim((string)($message['core_usuario_asignado'] ?? $message['asignado_nombre'] ?? ''));
        if ($candidate === '' || !dashboard_name_tokens_match($assigned, $candidate)) {
            return false;
        }
    }
    return true;
}

function dashboard_core_candidate_urls(string $sourceUrl): array {
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '') {
        return [];
    }
    $candidates = [$sourceUrl];
    $patterns = [
        'obtener_solicitudes_asignadas',
        'obtener_solicitudes_historicas',
        'obtener_solicitudes',
    ];
    foreach ($patterns as $from) {
        foreach ($patterns as $to) {
            if ($from === $to || !str_contains($sourceUrl, $from)) {
                continue;
            }
            $candidates[] = str_replace($from, $to, $sourceUrl);
        }
    }
    return array_values(array_unique(array_filter($candidates)));
}

function dashboard_archived_source_ids(string $baseDir): array {
    $sourceIds = [];
    if (!is_dir($baseDir)) {
        return $sourceIds;
    }
    foreach (glob(rtrim($baseDir, '/\\') . '/*/*.json') ?: [] as $file) {
        $rows = json_decode(@file_get_contents($file), true);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceId = trim((string)($row['fuente_id'] ?? ''));
            if ($sourceId !== '') {
                $sourceIds[$sourceId] = true;
            }
        }
    }
    return $sourceIds;
}

function dashboard_sync_core_source(array &$messages, string $sourceUrl, array $filters = [], bool $force = false, ?string $loginUrl = null, array $credentials = []): array {
    $cfg = load_platform_config();
    if (!$force && !dashboard_should_auto_sync_core($cfg)) {
        return ['skipped' => true, 'imported' => 0, 'updated' => 0, 'error' => ''];
    }
    if (!dashboard_core_is_configured($cfg)) {
        return ['skipped' => true, 'imported' => 0, 'updated' => 0, 'error' => 'Configura URL, usuario y contraseña de CORE para sincronizar.'];
    }
    $credentials = dashboard_core_runtime_credentials($credentials);
    if (!dashboard_core_has_runtime_credentials($credentials)) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'Debes ingresar credenciales de CORE para esta consulta.'];
    }
    $cookieJar = tempnam(sys_get_temp_dir(), 'core_sync_');
    if ($cookieJar === false) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo crear un archivo temporal para la sesión CORE.'];
    }
    $sourceUrl = trim($sourceUrl);
    $loginUrl = trim((string)($loginUrl ?? ''));
    if ($sourceUrl === '') {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'Falta configurar la URL de origen de CORE.'];
    }
    if ($loginUrl === '') {
        $loginUrl = $sourceUrl;
    }
    $loginPage = dashboard_core_curl($loginUrl, [
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
    ]);
    if ($loginPage['error'] !== '') {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo abrir CORE: ' . $loginPage['error']];
    }
    $formBaseUrl = trim((string)($loginPage['effective_url'] ?? '')) !== ''
        ? (string)$loginPage['effective_url']
        : $loginUrl;
    $form = dashboard_core_parse_login_form($loginPage['body'], $formBaseUrl);
    if (!$form['has_login_form']) {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se encontró el formulario de acceso de CORE.'];
    }
    $payloadFields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    $payloadFields['csrf_token'] = $form['csrf_token'];
    $payloadFields['login_string'] = (string)$credentials['user'];
    $payloadFields['login_pass'] = (string)$credentials['pass'];
    if (!array_key_exists('submit', $payloadFields) || trim((string)$payloadFields['submit']) === '') {
        $payloadFields['submit'] = 'Ingresar';
    }
    $payload = http_build_query($payloadFields);
    $login = dashboard_core_curl($form['action'], [
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if ($login['error'] !== '') {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo autenticar en CORE: ' . $login['error']];
    }
    $rows = [];
    $page = ['body' => '', 'error' => '', 'http_code' => 0, 'effective_url' => ''];
    $requestHeaders = [
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
    ];
    if ($loginUrl !== '') {
        $requestHeaders[] = 'Referer: ' . $loginUrl;
    }
    foreach (dashboard_core_candidate_urls($sourceUrl) as $candidateUrl) {
        $page = dashboard_core_curl($candidateUrl, [
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);
        if ($page['error'] !== '') {
            continue;
        }
        $pageNorm = dashboard_normalize_text($page['body']);
        if (str_contains($pageNorm, 'iniciar sesion en core') || str_contains($pageNorm, 'usuario rut sin digito verificador o email')) {
            continue;
        }
        $rows = dashboard_core_extract_json_rows($page['body']);
        if (empty($rows)) {
            $rows = dashboard_core_extract_rows($page['body']);
        }
        if (!empty($rows)) {
            $sourceUrl = $candidateUrl;
            break;
        }
    }
    if (!empty($rows)) {
        $rowsForDetail = [];
        foreach ($rows as $detailIndex => $detailRow) {
            if (is_array($detailRow) && dashboard_core_source_row_matches_filters($detailRow, $filters)) {
                $rowsForDetail[$detailIndex] = $detailRow;
            }
        }
        if (!empty($rowsForDetail)) {
            $detailBaseUrl = rtrim((string)($loginUrl !== '' ? $loginUrl : $sourceUrl), '/');
            $detailRows = dashboard_core_enrich_rows_with_detail($rowsForDetail, $detailBaseUrl, $cookieJar, $requestHeaders);
            foreach ($detailRows as $detailIndex => $detailRow) {
                $rows[$detailIndex] = $detailRow;
            }
        }
    }
    @unlink($cookieJar);
    if ($page['error'] !== '') {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo cargar la tabla de CORE: ' . $page['error']];
    }
    $pageNorm = dashboard_normalize_text($page['body']);
    if (str_contains($pageNorm, 'iniciar sesion en core') || str_contains($pageNorm, 'usuario rut sin digito verificador o email')) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'CORE rechazó las credenciales configuradas.'];
    }
    if (empty($rows)) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se encontró la tabla de solicitudes en CORE.'];
    }
    $catalogs = [
        'categorias' => dashboard_catalog_names(__DIR__ . '/../data/categorias.json'),
        'unidades' => dashboard_catalog_names(__DIR__ . '/../data/unidades.json'),
    ];
    $users = dashboard_load_user_maps();
    $existingBySource = [];
    foreach ($messages as $index => $message) {
        $sourceId = trim((string)($message['fuente_id'] ?? ''));
        if ($sourceId !== '') {
            $existingBySource[$sourceId] = $index;
        }
    }
    $archivedBySource = dashboard_archived_source_ids(retention_archive_base());
    $imported = 0;
    $updated = 0;
    foreach ($rows as $row) {
        $message = dashboard_core_build_message($row, $catalogs, $users);
        if (!dashboard_core_row_matches_filters($message, $filters)) {
            continue;
        }
        $sourceId = $message['fuente_id'];
        if (isset($existingBySource[$sourceId])) {
            $idx = $existingBySource[$sourceId];
            $currentState = strtolower((string)($messages[$idx]['estado'] ?? 'pendiente'));
            $preserve = $messages[$idx];
            $message['estado'] = in_array($currentState, ['procesado', 'error'], true) ? $currentState : 'pendiente';
            $message['redmine_id'] = $preserve['redmine_id'] ?? ($message['redmine_id'] ?? '');
            $message['procesado_ts'] = $preserve['procesado_ts'] ?? ($message['procesado_ts'] ?? '');
            $message['id'] = $preserve['id'] ?? $message['id'];
            $messages[$idx] = array_merge($preserve, $message);
            $updated++;
            continue;
        }
        if (isset($archivedBySource[$sourceId])) {
            continue;
        }
        $messages[] = $message;
        $existingBySource[$sourceId] = count($messages) - 1;
        $imported++;
    }
    $cfg['core_last_sync'] = (new DateTimeImmutable())->format(DateTime::ATOM);
    $cfg['core_last_error'] = '';
    save_platform_config($cfg);
    if ($imported > 0 || $updated > 0) {
        save_messages($messages);
    }
    return ['skipped' => false, 'imported' => $imported, 'updated' => $updated, 'error' => ''];
}

function dashboard_sync_core(array &$messages, bool $force = false, array $credentials = []): array {
    $cfg = load_platform_config();
    $adminUrl = (string)($cfg['core_admin_url'] ?? '');
    return dashboard_sync_core_source($messages, $adminUrl, [], $force, $adminUrl, $credentials);
}

function dashboard_sync_core_history(array &$messages, array $filters = [], bool $force = true, array $credentials = []): array {
    $cfg = load_platform_config();
    $adminUrl = (string)($cfg['core_admin_url'] ?? '');
    $sourceUrl = (string)($cfg['core_historico_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas');
    if (str_contains($sourceUrl, 'obtener_solicitudes_asignadas')) {
        $sourceUrl = str_replace('obtener_solicitudes_asignadas', 'obtener_solicitudes_historicas', $sourceUrl);
    } elseif (str_ends_with(rtrim($sourceUrl, '/'), '/obtener_solicitudes')) {
        $sourceUrl = str_replace('/obtener_solicitudes', '/obtener_solicitudes_historicas', $sourceUrl);
    }
    return dashboard_sync_core_source($messages, $sourceUrl, $filters, $force, $adminUrl, $credentials);
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
    if (($message['fuente'] ?? '') === 'manual') {
        $issue = [
            'project_id' => (int)($message['project_id'] ?? ($cfg['project_id'] ?? 48)),
            'subject' => trim((string)($message['asunto'] ?? $message['mensaje'] ?? '')),
            'description' => trim((string)($message['descripcion'] ?? '')),
            'tracker_id' => (int)($message['tipo_id'] ?? ($cfg['tracker_id'] ?? 3)),
            'priority_id' => (int)($message['priority_id'] ?? ($cfg['priority_id'] ?? 2)),
            'status_id' => (int)($message['status_id'] ?? ($cfg['status_id'] ?? 1)),
            'is_private' => false,
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
        $categoria = strtoupper(trim((string)($message['categoria'] ?? '')));
        if ($categoria !== '' && isset($catMap[$categoria])) {
            $issue['category_id'] = (int)$catMap[$categoria];
        }
        $customFields = [];
        foreach (['cf_solicitante','cf_unidad','cf_unidad_solicitante'] as $cfKey) {
            $cfId = $cfg[$cfKey] ?? null;
            if (($cfId === null || $cfId === '') && $cfKey === 'cf_solicitante') $cfId = 3;
            if (($cfId === null || $cfId === '') && $cfKey === 'cf_unidad') $cfId = 5;
            if (!$cfId) {
                continue;
            }
            $value = '';
            switch ($cfKey) {
                case 'cf_solicitante':
                    $value = trim((string)($message['solicitante'] ?? ''));
                    break;
                case 'cf_unidad':
                    $value = dashboard_resolve_unit_value($message);
                    break;
                case 'cf_unidad_solicitante':
                    $value = strtoupper(trim((string)($message['unidad_solicitante'] ?? $message['unidad'] ?? '')));
                    break;
            }
            if ($value === '' && $cfKey === 'cf_unidad_solicitante' && $unitMap) {
                $derived = strtoupper(trim((string)($message['unidad_solicitante'] ?? $message['unidad'] ?? '')));
                if (isset($unitMap[$derived])) {
                    $value = $unitMap[$derived];
                }
            }
            if ($value !== '') {
                $customFields[] = ['id' => $cfId, 'value' => $value];
            }
        }
        if (trim((string)($message['anexo'] ?? '')) !== '') {
            $customFields[] = ['id' => 4, 'value' => trim((string)$message['anexo'])];
        }
        $normalizedEmail = dashboard_normalize_email($message['core_email'] ?? '');
        if ($normalizedEmail !== '') {
            $customFields[] = ['id' => 8, 'value' => $normalizedEmail];
        }
        $cfHoraExtra = $cfg['cf_hora_extra'] ?? null;
        if ($cfHoraExtra === null || $cfHoraExtra === '') {
            $cfHoraExtra = 12;
        }
        if ($cfHoraExtra) {
            $customFields[] = ['id' => $cfHoraExtra, 'value' => normalize_hour_extra_value($message['hora_extra'] ?? '')];
        }
        if (!empty($customFields)) {
            $issue['custom_fields'] = $customFields;
        }
        return $issue;
    }

    $coreTipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    $coreEstablecimiento = trim((string)($message['core_establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
    $coreDepartamento = trim((string)($message['core_departamento'] ?? $message['unidad'] ?? ''));
    if (strtoupper($coreDepartamento) === 'N/A' || $coreDepartamento === $coreEstablecimiento) {
        $coreDepartamento = '';
    }
    $coreEmail = dashboard_normalize_email($message['core_email'] ?? '');
    $subjectParts = array_values(array_filter(
        [$coreTipo, $coreEstablecimiento, $coreDepartamento],
        fn($v) => ($value = trim((string)$v)) !== '' && strtoupper($value) !== 'N/A'
    ));
    $subject = implode(' / ', $subjectParts);
    $description = dashboard_build_redmine_core_description($message);
    $issue = [
        'project_id' => (int)($cfg['project_id'] ?? 48),
        'subject' => $subject !== '' ? $subject : trim((string)($message['asunto'] ?? $message['mensaje'] ?? '')),
        'description' => $description,
        'tracker_id' => (int)($cfg['tracker_id'] ?? 1),
        'priority_id' => (int)($cfg['priority_id'] ?? 2),
        'status_id' => (int)($cfg['status_id'] ?? 1),
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
    $asignado = trim((string)(auth_get_user_id() ?: ($message['asignado_a'] ?? '')));
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
        if (($cfId === null || $cfId === '') && $cfKey === 'cf_solicitante') $cfId = 3;
        if (($cfId === null || $cfId === '') && $cfKey === 'cf_unidad') $cfId = 5;
        if (!$cfId) continue;
        $value = '';
        switch ($cfKey) {
            case 'cf_solicitante':
                $value = $message['solicitante'] ?? '';
                break;
            case 'cf_unidad':
                $value = dashboard_resolve_unit_value($message);
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
    if ($coreEmail !== '') {
        $customFields[] = ['id' => 8, 'value' => $coreEmail];
    }
    $cfHoraExtra = $cfg['cf_hora_extra'] ?? null;
    if ($cfHoraExtra === null || $cfHoraExtra === '') {
        $cfHoraExtra = 12;
    }
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

function redmine_api_issues_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('~\/issues(?:\.json)?(?:\?.*)?$~i', $url) === 1) {
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '');
        if ($path !== '' && !str_ends_with(strtolower($path), '.json')) {
            $path .= '.json';
        }
        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }
            $rebuilt .= '@';
        }
        $rebuilt .= (string)($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $path;
        if (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        return $rebuilt;
    }
    return rtrim($url, '/') . '/issues.json';
}

function send_redmine_issue(array $issue, array $cfg, string $userToken = ''): array {
    $url = redmine_api_issues_url((string)($cfg['platform_url'] ?? ''));
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

function retention_archive_month_name(DateTimeInterface $dt): string {
    static $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    return $meses[(int)$dt->format('n')] ?? $dt->format('m');
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
    $destFile = $yearDir . '/' . retention_archive_month_name($dt) . '.json';
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
    auth_start_session();
    $candidateNames = [];
    $sessionUserName = trim((string)($_SESSION['user']['nombre'] ?? ''));
    if ($sessionUserName !== '') {
        $candidateNames[] = $sessionUserName;
    }
    if (function_exists('auth_find_user_by_id')) {
        $user = auth_find_user_by_id($userId);
        $storedName = trim((string)($user['nombre'] ?? ''));
        if ($storedName !== '') {
            $candidateNames[] = $storedName;
        }
    }
    $candidateNames = array_values(array_unique(array_filter($candidateNames)));
    return array_values(array_filter($messages, function ($row) use ($userId, $candidateNames) {
        if (!is_array($row)) {
            return false;
        }
        if ((string)($row['asignado_a'] ?? '') === $userId) {
            return true;
        }
        $assignedName = (string)($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ''));
        if (trim($assignedName) === '') {
            return false;
        }
        foreach ($candidateNames as $candidateName) {
            if (dashboard_name_tokens_match($candidateName, $assignedName)) {
                return true;
            }
        }
        return false;
    }));
}

function dashboard_default_core_assigned_name(): string {
    if (function_exists('auth_get_user_role') && auth_get_user_role() === 'root') {
        return '';
    }
    $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
    if ($userId === '') {
        return '';
    }
    $user = function_exists('auth_find_user_by_id') ? auth_find_user_by_id($userId) : null;
    if (!is_array($user)) {
        return '';
    }
    return trim((string)($user['nombre'] ?? ''));
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
                    'fecha','hora','numero','descripcion','core_email'
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
            case 'import_core_history':
                $desde = trim((string)($_POST['core_desde'] ?? ''));
                $hasta = trim((string)($_POST['core_hasta'] ?? ''));
                $assigned = trim((string)($_POST['core_assigned_name'] ?? ''));
                $coreUser = trim((string)($_POST['core_runtime_user'] ?? ''));
                $corePass = trim((string)($_POST['core_runtime_pass'] ?? ''));
                if ($assigned === '') {
                    $assigned = dashboard_default_core_assigned_name();
                }
                $result = dashboard_sync_core_history($messages, [
                    'desde' => $desde,
                    'hasta' => $hasta,
                    'assigned' => $assigned,
                ], true, [
                    'user' => $coreUser,
                    'pass' => $corePass,
                ]);
                if (!empty($result['error'])) {
                    $flashMsg = $result['error'];
                } else {
                    $flashMsg = 'Importación CORE completada. Nuevos: ' . (int)($result['imported'] ?? 0) . ' | actualizados: ' . (int)($result['updated'] ?? 0);
                }
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
            case 'delete_selected':
                $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                $ids = array_values(array_filter(array_map('trim', $ids)));
                if (empty($ids)) {
                    $flashMsg = 'No habia mensajes seleccionados para eliminar.';
                    break;
                }
                $before = count($messages);
                $messages = array_values(array_filter($messages, fn($m) => !in_array(($m['id'] ?? ''), $ids, true)));
                $deleted = $before - count($messages);
                if ($deleted > 0) {
                    save_messages($messages);
                    $flashMsg = $deleted . ' mensaje(s) eliminados.';
                } else {
                    $flashMsg = 'No se encontraron mensajes seleccionados para eliminar.';
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
