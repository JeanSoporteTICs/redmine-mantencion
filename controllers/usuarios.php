<?php
require_once __DIR__ . '/auth.php';

function usuarios_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['usuarios_flash'] = $message;
}

function usuarios_consume_flash(): ?string {
    auth_start_session();
    $message = $_SESSION['usuarios_flash'] ?? null;
    unset($_SESSION['usuarios_flash']);
    return $message;
}

function usuarios_redirect_back(): void {
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Usuarios/usuarios.php';
    header('Location: ' . $location);
    exit;
}

$DATA_FILE = __DIR__ . '/../data/usuarios.json';
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';

function rut_base($rut) {
    $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
    if ($clean === '') return '';
    $clean = strtoupper($clean);
    return strlen($clean) > 1 ? substr($clean, 0, -1) : $clean;
}

function ensure_usr_file($path) {
    if (!file_exists($path)) {
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function ensure_user_fields(array &$item) {
    $defaults = [
        'id' => uniqid('', true),
        'rut_sin_dv' => '',
        'nombre' => '',
        'apellido' => '',
        'rut' => '',
        'numero_celular' => '',
        'estamento' => '',
        'api' => '',
        'rol' => 'usuario',
        'password' => '',
    ];
    foreach ($defaults as $key => $value) {
        if (!isset($item[$key])) {
            $item[$key] = $value;
        }
    }
    $nombre = trim((string)($item['nombre'] ?? ''));
    $apellido = trim((string)($item['apellido'] ?? ''));
    if ($apellido !== '' && stripos($nombre, $apellido) === false) {
        $item['nombre'] = trim($nombre . ' ' . $apellido);
    }
    $item['apellido'] = '';
    $item['numero_celular'] = '';
    $item['rut_sin_dv'] = '';
    $item['rut'] = '';
    $item['estamento'] = '';
}

function load_usuarios($path) {
    ensure_usr_file($path);
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) $data = [];
    $changed = false;
    foreach ($data as &$item) {
        $prev = $item;
        ensure_user_fields($item);
        if ($item !== $prev) $changed = true;
    }
    if ($changed) save_usuarios($path, $data);
    return $data;
}

function save_usuarios($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function find_user_index(array $rows, string $id): ?int {
    foreach ($rows as $idx => $row) {
        if ((string)($row['id'] ?? '') === (string)$id) return $idx;
    }
    return null;
}

function has_duplicate_id(array $rows, string $id): bool {
    foreach ($rows as $row) {
        if ((string)($row['id'] ?? '') === (string)$id) return true;
    }
    return false;
}

function has_duplicate_rut(array $rows, string $rutBase, string $excludeId = ''): bool {
    if ($rutBase === '') return false;
    foreach ($rows as $row) {
        if ($excludeId !== '' && (string)($row['id'] ?? '') === (string)$excludeId) {
            continue;
        }
        $rutExist = preg_replace('/[^0-9kK]/', '', $row['rut'] ?? '');
        if (rut_base($rutExist) === $rutBase) {
            return true;
        }
    }
    return false;
}

function sanitize_input(string $value): string {
    return trim(filter_var($value, FILTER_UNSAFE_RAW) ?? '');
}

function format_rut_value(string $rut): string {
    $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
    if ($clean === '') return '';
    $clean = strtoupper($clean);
    if (strlen($clean) < 2) return $clean;
    $body = substr($clean, 0, -1);
    $dv = substr($clean, -1);
    $body = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $body);
    return $body . '-' . $dv;
}

function usuarios_user_api_token(): string {
    if (!function_exists('auth_get_user_id')) {
        return '';
    }
    $userId = auth_get_user_id();
    if ($userId === '') {
        return '';
    }
    global $DATA_FILE;
    if (!file_exists($DATA_FILE)) {
        return '';
    }
    $users = json_decode(file_get_contents($DATA_FILE), true);
    if (!is_array($users)) {
        return '';
    }
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string)($user['id'] ?? '') === (string)$userId) {
            return trim((string)($user['api'] ?? ''));
        }
    }
    return '';
}

function usuarios_members_url_from_config(): string {
    global $CONFIG_FILE;
    $cfg = file_exists($CONFIG_FILE) ? json_decode(file_get_contents($CONFIG_FILE), true) : [];
    if (is_array($cfg)) {
        $custom = trim((string)($cfg['users_members_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $platformUrl = trim((string)($cfg['platform_url'] ?? ''));
        if ($platformUrl !== '' && preg_match('#/issues\.json$#', $platformUrl)) {
            return preg_replace('#/issues\.json$#', '/settings/members', $platformUrl);
        }
    }
    return 'https://coresalud.cl/gp/projects/backlog-mantencion-ti/settings/members';
}

function usuarios_members_api_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#/settings/members/?$#', $url)) {
        return preg_replace('#/settings/members/?$#', '/memberships.json', $url);
    }
    if (preg_match('#/issues\.json$#', $url)) {
        return preg_replace('#/issues\.json$#', '/memberships.json', $url);
    }
    return $url;
}

function usuarios_split_name(string $fullName): array {
    $fullName = trim($fullName);
    if ($fullName === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $fullName);
    if (!$parts || count($parts) === 1) {
        return [$fullName, ''];
    }
    $first = array_shift($parts);
    return [trim($first . ' ' . implode(' ', $parts)), ''];
}

function usuarios_sync_remote(array &$rows): array {
    global $CONFIG_FILE, $DATA_FILE;
    $cfg = file_exists($CONFIG_FILE) ? json_decode(file_get_contents($CONFIG_FILE), true) : [];
    $apiKey = is_array($cfg) ? trim((string)($cfg['platform_token'] ?? '')) : '';
    if ($apiKey === '') {
        $apiKey = usuarios_user_api_token();
    }
    if ($apiKey === '') {
        return ['error' => 'Falta token API para importar usuarios.'];
    }
    $url = usuarios_members_api_url(usuarios_members_url_from_config());
    if ($url === '') {
        return ['error' => 'Falta URL de miembros para importar usuarios.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Redmine-API-Key: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => 'No se pudo conectar para importar usuarios: ' . $err];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        return ['error' => 'HTTP ' . $code . ' al consultar members.'];
    }
    $json = json_decode($resp, true);
    $memberships = is_array($json['memberships'] ?? null) ? $json['memberships'] : [];
    if (empty($memberships)) {
        return ['error' => 'La respuesta no contiene memberships validos.'];
    }
    $indexed = [];
    foreach ($rows as $idx => $row) {
        if (is_array($row) && isset($row['id'])) {
            $indexed[(string)$row['id']] = $idx;
        }
    }
    $created = 0;
    $updated = 0;
    foreach ($memberships as $membership) {
        if (!is_array($membership)) {
            continue;
        }
        $user = $membership['user'] ?? null;
        if (!is_array($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        $name = trim((string)($user['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        if (isset($indexed[$id])) {
            $idx = $indexed[$id];
            $currentName = trim((string)($rows[$idx]['nombre'] ?? ''));
            if ($currentName !== $name) {
                $rows[$idx]['nombre'] = $name;
                $rows[$idx]['apellido'] = '';
                $rows[$idx]['rut_sin_dv'] = '';
                $rows[$idx]['rut'] = '';
                $rows[$idx]['estamento'] = '';
                $updated++;
            }
            continue;
        }
        [$nombre, $apellido] = usuarios_split_name($name);
        $rows[] = [
            'id' => $id,
            'rut_sin_dv' => '',
            'nombre' => $nombre !== '' ? $name : $name,
            'apellido' => $apellido,
            'rut' => '',
            'numero_celular' => '',
            'estamento' => '',
            'api' => '',
            'rol' => 'usuario',
            'password' => '',
            'permisos' => [],
        ];
        $indexed[$id] = count($rows) - 1;
        $created++;
    }
    save_usuarios($DATA_FILE, $rows);
    return ['ok' => true, 'created' => $created, 'updated' => $updated];
}

function handle_usuarios() {
    global $DATA_FILE;
    $rows = load_usuarios($DATA_FILE);
    $flash = usuarios_consume_flash();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $action = $_POST['action'] ?? '';
        $id_input = sanitize_input($_POST['id_manual'] ?? '');

        if ($action === 'create') {
            if ($id_input !== '' && has_duplicate_id($rows, $id_input)) {
                return [$rows, 'Error: el ID ya existe'];
            }
            $pwd = sanitize_input($_POST['password'] ?? '');
            $pwd2 = sanitize_input($_POST['password_confirm'] ?? '');
            if ($pwd !== $pwd2) {
                return [$rows, 'Error: las contraseñas no coinciden'];
            }
            $hash = $pwd !== '' ? password_hash($pwd, PASSWORD_DEFAULT) : '';
            $assignedRole = sanitize_input($_POST['rol'] ?? 'usuario');
            $rolePerms = [];
            if (function_exists('auth_load_roles')) {
                $roles = auth_load_roles();
                $cfg = $roles[$assignedRole] ?? [];
                if (is_array($cfg)) {
                    $rolePerms = $cfg;
                }
            }
            $requiredName = sanitize_input($_POST['nombre'] ?? '');
            if ($requiredName === '') {
                return [$rows, 'Error: el nombre es obligatorio'];
            }
            $rows[] = [
                'id' => $id_input !== '' ? $id_input : uniqid('', true),
                'rut_sin_dv' => '',
                'nombre' => $requiredName,
                'apellido' => '',
                'rut' => '',
                'numero_celular' => '',
                'estamento' => '',
                'rol' => $assignedRole,
                'api' => sanitize_input($_POST['api'] ?? ''),
                'password' => $hash,
                'permisos' => $rolePerms,
            ];
            save_usuarios($DATA_FILE, $rows);
            usuarios_set_flash('Usuario creado');
            usuarios_redirect_back();
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            $index = find_user_index($rows, $id);
            if ($index === null) return [$rows, 'Error: usuario no encontrado'];
            $current = &$rows[$index];
            $pwd = sanitize_input($_POST['password'] ?? '');
            $pwd2 = sanitize_input($_POST['password_confirm'] ?? '');
            if ($pwd !== '' || $pwd2 !== '') {
                if ($pwd !== $pwd2) {
                    return [$rows, 'Error: las contraseñas no coinciden'];
                }
                $current['password'] = password_hash($pwd, PASSWORD_DEFAULT);
            }
            $requiredNameUp = sanitize_input($_POST['nombre'] ?? $current['nombre']);
            if ($requiredNameUp === '') {
                return [$rows, 'Error: el nombre es obligatorio'];
            }
            $current['rut_sin_dv'] = '';
            $current['nombre'] = $requiredNameUp;
            $current['apellido'] = '';
            $current['rut'] = '';
            $current['numero_celular'] = '';
            $current['estamento'] = '';
            $current['rol'] = sanitize_input($_POST['rol'] ?? ($current['rol'] ?? 'usuario'));
            $current['api'] = sanitize_input($_POST['api'] ?? $current['api']);
            save_usuarios($DATA_FILE, $rows);
            usuarios_set_flash('Usuario actualizado');
            usuarios_redirect_back();
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $rows = array_values(array_filter($rows, fn($r) => (string)($r['id'] ?? '') !== (string)$id));
            save_usuarios($DATA_FILE, $rows);
            usuarios_set_flash('Usuario eliminado');
            usuarios_redirect_back();
        } elseif ($action === 'sync_remote') {
            $res = usuarios_sync_remote($rows);
            if (isset($res['error'])) {
                return [$rows, $res['error']];
            }
            usuarios_set_flash('Usuarios importados. Nuevos: ' . (int)($res['created'] ?? 0) . ' | actualizados: ' . (int)($res['updated'] ?? 0));
            usuarios_redirect_back();
        }
    }
    return [$rows, $flash];
}
