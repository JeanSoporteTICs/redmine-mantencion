<?php
// CRUD para prioridades usando solo data/configuracion.json
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';

function prio_load_cfg() {
    if (!file_exists($GLOBALS['CONFIG_FILE'])) return [];
    $data = json_decode(file_get_contents($GLOBALS['CONFIG_FILE']), true);
    if (!is_array($data)) $data = [];
    if (!isset($data['prioridades']) || !is_array($data['prioridades'])) $data['prioridades'] = [];
    return $data;
}

function prio_save_cfg($cfg) {
    file_put_contents($GLOBALS['CONFIG_FILE'], json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function handle_prioridades() {
    $cfg = prio_load_cfg();
    $prioridades = $cfg['prioridades'] ?? [];
    $flash = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $def = isset($_POST['default']);
            if ($id !== '' && $nombre !== '') {
                if ($def) {
                    foreach ($prioridades as &$p) $p['default'] = false;
                    $cfg['priority_id'] = $id;
                }
                $prioridades[] = [
                    'id' => is_numeric($id) ? (int)$id : $id,
                    'nombre' => $nombre,
                    'default' => $def
                ];
                $cfg['prioridades'] = $prioridades;
                prio_save_cfg($cfg);
                $flash = 'Prioridad creada';
            }
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($prioridades as &$p) {
                if ((string)$p['id'] === (string)$id) {
                    $p['nombre'] = trim($_POST['nombre'] ?? $p['nombre']);
                    $p['default'] = isset($_POST['default']);
                    break;
                }
            }
            if (isset($_POST['default'])) {
                foreach ($prioridades as &$p) {
                    $p['default'] = ((string)$p['id'] === (string)$id);
                }
                $cfg['priority_id'] = $id;
            }
            $cfg['prioridades'] = $prioridades;
            prio_save_cfg($cfg);
            $flash = 'Prioridad actualizada';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $prioridades = array_values(array_filter($prioridades, fn($p) => (string)$p['id'] !== (string)$id));
            if (isset($cfg['priority_id']) && (string)$cfg['priority_id'] === (string)$id) {
                $cfg['priority_id'] = $prioridades[0]['id'] ?? null;
                if (!empty($prioridades)) $prioridades[0]['default'] = true;
            }
            $cfg['prioridades'] = $prioridades;
            prio_save_cfg($cfg);
            $flash = 'Prioridad eliminada';
        }
    }

    return [$prioridades, $flash];
}
?>
