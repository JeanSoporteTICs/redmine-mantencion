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
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine/views/Usuarios/usuarios.php';
    header('Location: ' . $location);
    exit;
}
// CRUD básico para usuarios usando data/usuarios.json
$DATA_FILE = __DIR__ . '/../data/usuarios.json';

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

function normalize_phone(string $value): string {
    $digits = preg_replace('/[^0-9]/', '', $value ?? '');
    if ($digits === '') return '';
    if (strlen($digits) === 9 && strpos($digits, '9') === 0) {
        return '+56' . $digits;
    }
    if (strlen($digits) === 11 && strpos($digits, '56') === 0) {
        return '+' . $digits;
    }
    if (strpos($digits, '569') === 0 && strlen($digits) === 11) {
        return '+' . $digits;
    }
    return '+' . ltrim($digits, '0');
}

function has_duplicate_phone(array $rows, string $phone, string $excludeId = ''): bool {
    if ($phone === '') return false;
    foreach ($rows as $row) {
        if ($excludeId !== '' && (string)($row['id'] ?? '') === (string)$excludeId) {
            continue;
        }
        $existing = normalize_phone($row['numero_celular'] ?? '');
        if ($existing === $phone && $existing !== '') return true;
    }
    return false;
}

function sanitize_input(string $value): string {
    return trim(filter_var($value, FILTER_UNSAFE_RAW) ?? '');
}

function sanitize_phone(string $value): string {
    return preg_replace('/[^0-9+]/', '', $value ?? '');
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

function handle_usuarios() {
    global $DATA_FILE;
    $rows = load_usuarios($DATA_FILE);
    $flash = usuarios_consume_flash();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $action = $_POST['action'] ?? '';
        $rut_input = preg_replace('/[^0-9kK]/', '', $_POST['rut'] ?? '');
        $rut_sin_dv = rut_base($rut_input);
        $id_input = sanitize_input($_POST['rut_sin_dv'] ?? '');
        $phone_input = sanitize_phone($_POST['numero_celular'] ?? '');
        $phone_base = normalize_phone($phone_input);

        if ($action === 'create') {
            if ($rut_input !== '' && $rut_sin_dv === '') {
                return [$rows, 'Error: RUT inválido'];
            }
            if ($hasDupId = ($id_input !== '' && has_duplicate_id($rows, $id_input))) {
                return [$rows, 'Error: el ID ya existe'];
            }
            if (has_duplicate_rut($rows, $rut_sin_dv)) {
                return [$rows, 'Error: el RUT ya existe'];
            }
            if (has_duplicate_phone($rows, $phone_base)) {
                return [$rows, 'Error: el celular ya existe'];
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
            $requiredLast = sanitize_input($_POST['apellido'] ?? '');
            if ($requiredName === '' || $requiredLast === '') {
                return [$rows, 'Error: nombre y apellido son obligatorios'];
            }
            if ($phone_base === '') {
                return [$rows, 'Error: el celular debe contener dígitos válidos'];
            }
            $rows[] = [
                'id' => $id_input !== '' ? $id_input : ($rut_sin_dv ?: uniqid('', true)),
                'rut_sin_dv' => $rut_sin_dv,
                'nombre' => $requiredName,
                'apellido' => $requiredLast,
                'rut' => format_rut_value($rut_input),
                'numero_celular' => $phone_base,
                'estamento' => sanitize_input($_POST['estamento'] ?? ''),
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
            $rut_input = $rut_input ?: ($current['rut'] ?? '');
            $rut_sin_dv = rut_base($rut_input) ?: ($current['rut_sin_dv'] ?? '');
            if (has_duplicate_rut($rows, $rut_sin_dv, $id)) {
                return [$rows, 'Error: el RUT ya existe'];
            }
            if (has_duplicate_phone($rows, $phone_base, $id)) {
                return [$rows, 'Error: el celular ya existe'];
            }
            $pwd = sanitize_input($_POST['password'] ?? '');
            $pwd2 = sanitize_input($_POST['password_confirm'] ?? '');
            if ($pwd !== '' || $pwd2 !== '') {
                if ($pwd !== $pwd2) {
                    return [$rows, 'Error: las contraseñas no coinciden'];
                }
                $current['password'] = password_hash($pwd, PASSWORD_DEFAULT);
            }
            $requiredNameUp = sanitize_input($_POST['nombre'] ?? $current['nombre']);
            $requiredLastUp = sanitize_input($_POST['apellido'] ?? $current['apellido']);
            if ($requiredNameUp === '' || $requiredLastUp === '') {
                return [$rows, 'Error: nombre y apellido son obligatorios'];
            }
            if ($phone_base === '') {
                return [$rows, 'Error: el celular debe contener dígitos válidos'];
            }
            $current['rut_sin_dv'] = $rut_sin_dv;
            $current['nombre'] = $requiredNameUp;
            $current['apellido'] = $requiredLastUp;
            $current['rut'] = format_rut_value($rut_input);
            $current['numero_celular'] = $phone_base;
            $current['estamento'] = sanitize_input($_POST['estamento'] ?? $current['estamento']);
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
        }
    }
    return [$rows, $flash];
}
