<?php

require_once __DIR__ . '/controllers/auth.php';

auth_start_session();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$timeout = auth_config_timeout();
$last = (int)($_SESSION['last_activity'] ?? 0);
if ($last > 0 && (time() - $last) > $timeout) {
    auth_logout();
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesion expirada'], JSON_UNESCAPED_UNICODE);
    exit;
}

auth_touch_activity();
echo json_encode([
    'ok' => true,
    'timeout' => $timeout,
    'remaining' => $timeout,
], JSON_UNESCAPED_UNICODE);
