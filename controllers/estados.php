<?php
// CRUD para estados (status_id) usando solo data/configuracion.json
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';

function est_load_cfg() {
    if (!file_exists($GLOBALS['CONFIG_FILE'])) return [];
    $data = json_decode(file_get_contents($GLOBALS['CONFIG_FILE']), true);
    if (!is_array($data)) $data = [];
    if (!isset($data['estados']) || !is_array($data['estados'])) $data['estados'] = [];
    return $data;
}

function est_save_cfg($cfg) {
    file_put_contents($GLOBALS['CONFIG_FILE'], json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function handle_estados() {
    $cfg = est_load_cfg();
    $estados = $cfg['estados'] ?? [];
    $flash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $def = isset($_POST['default']);
            if ($id !== '' && $nombre !== '') {
                if ($def) {
                    foreach ($estados as &$e) $e['default'] = false;
                    $cfg['status_id'] = $id;
                }
                $estados[] = ['id' => is_numeric($id) ? (int)$id : $id, 'nombre' => $nombre, 'default' => $def];
                $cfg['estados'] = $estados;
                est_save_cfg($cfg);
                $flash = 'Estado creado';
            }
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($estados as &$e) {
                if ((string)$e['id'] === (string)$id) {
                    $e['nombre'] = trim($_POST['nombre'] ?? $e['nombre']);
                    $e['default'] = isset($_POST['default']);
                    break;
                }
            }
            if (isset($_POST['default'])) {
                foreach ($estados as &$e) {
                    $e['default'] = ((string)$e['id'] === (string)$id);
                }
                $cfg['status_id'] = $id;
            }
            $cfg['estados'] = $estados;
            est_save_cfg($cfg);
            $flash = 'Estado actualizado';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $estados = array_values(array_filter($estados, fn($e) => (string)$e['id'] !== (string)$id));
            if (isset($cfg['status_id']) && (string)$cfg['status_id'] === (string)$id) {
                $cfg['status_id'] = $estados[0]['id'] ?? null;
                if (!empty($estados)) {
                    $estados[0]['default'] = true;
                }
            }
            $cfg['estados'] = $estados;
            est_save_cfg($cfg);
            $flash = 'Estado eliminado';
        }
    }
    return [$estados, $flash];
}
?>
