<?php
// Endpoint mínimo para sincronizar categorías desde la API y volver a configuración.
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine/login.php');
require_once __DIR__ . '/../../controllers/categorias.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_validate')) csrf_validate();
    $action = $_POST['action'] ?? '';
    if ($action === 'sync_remote') {
        $res = sync_categorias_desde_api(__DIR__ . '/../../data/configuracion.json', __DIR__ . '/../../data/categorias.json');
        if (isset($res['error'])) {
            $msg = $res['error'];
        } else {
            $msg = 'Categorías actualizadas desde API (' . ($res['ok'] ?? 0) . ' registros)';
        }
    } else {
        $msg = 'Acción no válida.';
    }
} else {
    $msg = 'Método no permitido.';
}
header('Location: /redmine/views/Configuracion/configuracion.php?synccat=' . urlencode($msg));
exit;
