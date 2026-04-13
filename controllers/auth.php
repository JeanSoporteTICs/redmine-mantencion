<?php
// Autenticacion simple usando data/usuarios.json
require_once __DIR__ . '/logger.php';

function auth_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_config_timeout() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = 300; // valor por defecto
    $file = __DIR__ . '/../data/configuracion.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data) && isset($data['session_timeout'])) {
            $cache = max(60, (int)$data['session_timeout']);
        }
    }
    return $cache;
}

function auth_touch_activity() {
    auth_start_session();
    $_SESSION['last_activity'] = time();
}

function auth_norm_key($v) {
    // deja solo letras/numeros en minusculas (para rut o id)
    return strtolower(preg_replace('/[^0-9a-z]/i', '', (string)$v));
}

function auth_users_file() {
    return __DIR__ . '/../data/usuarios.json';
}

function auth_find_user($username) {
    $file = auth_users_file();
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;
    foreach ($data as $u) {
        if (!is_array($u)) continue;
        // se permite iniciar sesion con id o rut (con o sin dv)
        $cand = [];
        $cand[] = $u['id'] ?? null;
        $cand[] = $u['rut'] ?? null;
        $cand[] = $u['rut_sin_dv'] ?? null;
        // derivar rut sin dv si viene en rut
        if (!empty($u['rut'])) {
            $clean = auth_norm_key($u['rut']);
            if (strlen($clean) > 1) {
                $cand[] = substr($clean, 0, -1); // sin dv
                $cand[] = $clean; // con dv
            }
        }
        $cand = array_filter($cand, fn($v) => $v !== null && $v !== '');
        $userKey = auth_norm_key($username);
        foreach ($cand as $c) {
            if ($userKey === auth_norm_key($c)) return $u;
        }
    }
    return null;
}

function auth_find_user_by_id($id) {
    $file = auth_users_file();
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;
    foreach ($data as $u) {
        if (!is_array($u)) continue;
        if ((string)($u['id'] ?? '') === (string)$id) return $u;
    }
    return null;
}

function auth_login($username, $password) {
    auth_start_session();
    $user = auth_find_user($username);
    // usamos campo api como contrasena; si existe 'password' tambien lo aceptamos
    $apiField = $user['api'] ?? null;
    $passField = $user['password'] ?? null;
    $ok = false;
    if ($user) {
        // Si password estÃ¡ hasheado, usamos password_verify; si no, comparamos directo
        if (!empty($passField) && strlen($passField) > 20) {
            $ok = password_verify($password, $passField);
        } elseif ($passField !== null && $passField === $password) {
            $ok = true;
        } elseif ($apiField !== null && $apiField === $password) {
            $ok = true;
        }
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'] ?? '',
            'nombre' => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')),
            'rut' => $user['rut'] ?? '',
            'rol' => $user['rol'] ?? 'usuario',
        ];
        auth_touch_activity();
        log_security_event('LOGIN_SUCCESS', sprintf('User %s (%s)', $_SESSION['user']['nombre'], $username));
        return true;
    }
    log_security_event('LOGIN_FAILURE', sprintf('Intento con "%s"', $username));
    return false;
}

function auth_logout() {
    auth_start_session();
    // limpiar variables y cookie de sesion
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function auth_require_login($redirect = '/redmine/login.php') {
    auth_start_session();
    $timeout = auth_config_timeout();
    $last = $_SESSION['last_activity'] ?? 0;
    if ($last && (time() - $last) > $timeout) {
        log_security_event('CSRF_ALERT', sprintf('Token=%s session=%s', $token ?: 'NULL', $sess ?: 'NULL'));
        auth_logout();
    }
    if (empty($_SESSION['user'])) {
        header('Location: ' . $redirect);
        exit;
    }
    auth_touch_activity();
}

function auth_get_user_role() {
    auth_start_session();
    return $_SESSION['user']['rol'] ?? 'usuario';
}

// ----------------- Roles y permisos -----------------
function auth_roles_file() {
    return __DIR__ . '/../data/roles.json';
}

function auth_load_roles() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $file = auth_roles_file();
    if (!file_exists($file)) {
        return $cache = [];
    }
    $data = json_decode(file_get_contents($file), true);
    return $cache = is_array($data) ? $data : [];
}

function auth_get_role_config(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $roles = auth_load_roles();
    $role = auth_get_user_role();
    return $cache = $roles[$role] ?? [];
}

function auth_get_user_override_permissions(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $userId = auth_get_user_id();
    if ($userId === '') {
        return $cache = [];
    }
    $user = auth_find_user_by_id($userId);
    if (!is_array($user)) {
        return $cache = [];
    }
    return $cache = is_array($user['permisos'] ?? null) ? $user['permisos'] : [];
}

function auth_user_has_all_permissions(): bool {
    if (auth_get_user_role() === 'root') {
        return true;
    }
    $override = auth_get_user_override_permissions();
    if (!empty($override['all'])) {
        return true;
    }
    $roleCfg = auth_get_role_config();
    return !empty($roleCfg['all']);
}

function auth_get_permission_value(string $permiso) {
    $override = auth_get_user_override_permissions();
    if (array_key_exists($permiso, $override)) {
        return $override[$permiso];
    }
    $roleCfg = auth_get_role_config();
    return $roleCfg[$permiso] ?? null;
}

function auth_can($permiso) {
    if (auth_user_has_all_permissions()) {
        return true;
    }
    $value = auth_get_permission_value($permiso);
    if (is_array($value)) {
        return count($value) > 0;
    }
    return !empty($value);
}

function auth_get_user_id() {
    auth_start_session();
    return $_SESSION['user']['id'] ?? '';
}

// CSRF helpers
function csrf_token() {
    auth_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate() {
    auth_start_session();
    // Acepta token por POST o cabecera X-CSRF-Token (p.ej. AJAX)
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $token = trim($token);
    $sess  = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$sess || !hash_equals($sess, $token)) {
        // Cierra sesiÃ³n para evitar estados inconsistentes y redirige a login
        auth_logout();
        header('Location: /redmine/login.php?err=csrf');
        exit;
    }
}

function auth_require_role(array $rolesAllowed, $redirect = '/redmine/login.php') {
    auth_require_login($redirect);
    $role = auth_get_user_role();
    // rol gestor hereda permisos de root
    if ($role === 'gestor' && in_array('root', $rolesAllowed, true)) {
        return;
    }
    if (!in_array($role, $rolesAllowed, true)) {
        header('Location: /redmine/views/Dashboard/dashboard.php');
        exit;
    }
}


