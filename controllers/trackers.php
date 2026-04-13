<?php
// CRUD para trackers usando solo data/configuracion.json
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';

function trk_load_cfg() {
    if (!file_exists($GLOBALS['CONFIG_FILE'])) return [];
    $data = json_decode(file_get_contents($GLOBALS['CONFIG_FILE']), true);
    if (!is_array($data)) $data = [];
    if (!isset($data['trackers']) || !is_array($data['trackers'])) $data['trackers'] = [];
    return $data;
}

function trk_save_cfg($cfg) {
    file_put_contents($GLOBALS['CONFIG_FILE'], json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function handle_trackers() {
    $cfg = trk_load_cfg();
    $trackers = $cfg['trackers'] ?? [];
    $flash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $def = isset($_POST['default']);
            if ($id !== '' && $nombre !== '') {
                if ($def) {
                    foreach ($trackers as &$t) $t['default'] = false;
                    $cfg['tracker_id'] = $id;
                }
                $trackers[] = ['id' => is_numeric($id)?(int)$id:$id, 'nombre' => $nombre, 'default' => $def];
                $cfg['trackers'] = $trackers;
                trk_save_cfg($cfg);
                $flash = 'Tracker creado';
            }
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($trackers as &$t) {
                if ((string)$t['id'] === (string)$id) {
                    $t['nombre'] = trim($_POST['nombre'] ?? $t['nombre']);
                    $t['default'] = isset($_POST['default']);
                    break;
                }
            }
            if (isset($_POST['default'])) {
                foreach ($trackers as &$t) {
                    $t['default'] = ((string)$t['id'] === (string)$id);
                }
                $cfg['tracker_id'] = $id;
            }
            $cfg['trackers'] = $trackers;
            trk_save_cfg($cfg);
            $flash = 'Tracker actualizado';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $trackers = array_values(array_filter($trackers, fn($t) => (string)$t['id'] !== (string)$id));
            if (isset($cfg['tracker_id']) && (string)$cfg['tracker_id'] === (string)$id) {
                $cfg['tracker_id'] = $trackers[0]['id'] ?? null;
                if (!empty($trackers)) $trackers[0]['default'] = true;
            }
            $cfg['trackers'] = $trackers;
            trk_save_cfg($cfg);
            $flash = 'Tracker eliminado';
        }
    }
    return [$trackers, $flash];
}
?>
