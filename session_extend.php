<?php
require_once __DIR__ . '/controllers/auth.php';

auth_start_session();
header('Content-Type: application/json');

if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'No autenticado']);
    exit;
}

$pwd = $_POST['password'] ?? '';
$user = auth_find_user_by_id($_SESSION['user']['id']);
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Usuario no encontrado']);
    exit;
}

$apiField = $user['api'] ?? null;
$passField = $user['password'] ?? null;
$ok = false;
if ($passField) {
    // si está hasheada, usar password_verify
    if (strlen($passField) > 20 && password_verify($pwd, $passField)) {
        $ok = true;
    } elseif ($passField === $pwd) {
        $ok = true;
    }
}
if (!$ok && $apiField !== null && $apiField === $pwd) {
    $ok = true;
}

if ($ok) {
    auth_touch_activity();
    $timeout = auth_config_timeout();
    echo json_encode([
        'ok' => true,
        'msg' => 'Sesion extendida',
        'timeout' => $timeout,
        'remaining' => $timeout
    ]);
    exit;
}

http_response_code(401);
echo json_encode(['ok' => false, 'msg' => 'Contrasena incorrecta']);
exit;
?>
