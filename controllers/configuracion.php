<?php
require_once __DIR__ . '/auth.php';
// Mantenedor de configuración de envío a Redmine (incluye opciones de tracker/prioridad/estado)
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';

function ensure_config_file($path) {
    if (!file_exists($path)) {
        $default = [
            'platform_url' => 'https://coresalud.cl/gp/projects/backlog-soporte-ti/issues.json',
            'platform_token' => '',
            'project_id' => 47,
            'project_name' => '',
            'tracker_id' => 3,
            'priority_id' => 2,
            'cf_solicitante' => null,
            'cf_unidad' => null,
            'cf_unidad_solicitante' => 11,
            'cf_hora_extra' => 12,
            'categories_url' => null,
            'unidades_url' => null,
            'status_id' => 1,
            'retencion_horas' => 24,
            'session_timeout' => 300,
            'trackers' => [
                ['id' => 2, 'nombre' => 'Tareas', 'default' => false],
                ['id' => 3, 'nombre' => 'Soporte', 'default' => true],
                ['id' => 8, 'nombre' => 'Proyecto Soporte', 'default' => false],
            ],
            'prioridades' => [
                ['id' => 1, 'nombre' => 'Baja', 'default' => false],
                ['id' => 2, 'nombre' => 'Normal', 'default' => true],
                ['id' => 3, 'nombre' => 'Alta', 'default' => false],
                ['id' => 4, 'nombre' => 'Urgente', 'default' => false],
                ['id' => 5, 'nombre' => 'Inmediata', 'default' => false],
            ],
            'estados' => [
                ['id' => 1, 'nombre' => 'Nueva', 'default' => true],
                ['id' => 2, 'nombre' => 'En curso', 'default' => false],
                ['id' => 5, 'nombre' => 'Cerrada', 'default' => false],
                ['id' => 6, 'nombre' => 'Rechazada', 'default' => false],
            ],
        ];
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function load_config($path) {
    ensure_config_file($path);
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    if (!array_key_exists('categories_url', $data)) $data['categories_url'] = '';
    if (!array_key_exists('unidades_url', $data)) $data['unidades_url'] = '';
    if (!array_key_exists('cf_unidad_solicitante', $data)) {
        $data['cf_unidad_solicitante'] = 11;
    }
    if (!array_key_exists('cf_hora_extra', $data)) {
        $data['cf_hora_extra'] = 12;
    }
    foreach (['trackers','prioridades','estados'] as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) $data[$k] = [];
    }
    if (!isset($data['session_timeout'])) $data['session_timeout'] = 300;
    return $data;
}

function save_config($path, $cfg) {
    file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function config_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['config_flash'] = $message;
}

function config_consume_flash(): ?string {
    auth_start_session();
    $message = $_SESSION['config_flash'] ?? null;
    unset($_SESSION['config_flash']);
    return $message;
}

function config_redirect_back(): void {
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine/views/Configuracion/configuracion.php';
    header('Location: ' . $location);
    exit;
}

function handle_configuracion() {
    global $CONFIG_FILE;
    $cfg = load_config($CONFIG_FILE);
    $flash = config_consume_flash();
    $action = $_POST['action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
        if (function_exists('csrf_validate')) csrf_validate();
        $cfg['platform_url'] = trim($_POST['platform_url'] ?? $cfg['platform_url'] ?? '');
        $cfg['platform_token'] = trim($_POST['platform_token'] ?? $cfg['platform_token'] ?? '');
        $cfg['categories_url'] = trim($_POST['categories_url'] ?? ($cfg['categories_url'] ?? ''));
        $cfg['unidades_url'] = trim($_POST['unidades_url'] ?? ($cfg['unidades_url'] ?? ''));
        $cfg['project_id'] = is_numeric($_POST['project_id'] ?? '') ? (int)$_POST['project_id'] : ($_POST['project_id'] ?? $cfg['project_id'] ?? '');
        $cfg['project_name'] = trim($_POST['project_name'] ?? ($cfg['project_name'] ?? ''));
        $cfg['tracker_id'] = is_numeric($_POST['tracker_id'] ?? '') ? (int)$_POST['tracker_id'] : ($_POST['tracker_id'] ?? $cfg['tracker_id'] ?? null);
        $cfg['priority_id'] = is_numeric($_POST['priority_id'] ?? '') ? (int)$_POST['priority_id'] : ($_POST['priority_id'] ?? $cfg['priority_id'] ?? null);
        $cfg['cf_solicitante'] = ($_POST['cf_solicitante'] ?? '') === '' ? null : $_POST['cf_solicitante'];
        $cfg['cf_unidad'] = ($_POST['cf_unidad'] ?? '') === '' ? null : $_POST['cf_unidad'];
        $cfg['cf_unidad_solicitante'] = ($_POST['cf_unidad_solicitante'] ?? '') === '' ? null : $_POST['cf_unidad_solicitante'];
        $cfg['cf_hora_extra'] = ($_POST['cf_hora_extra'] ?? '') === '' ? null : $_POST['cf_hora_extra'];
        $cfg['status_id'] = is_numeric($_POST['status_id'] ?? '') ? (int)$_POST['status_id'] : ($cfg['status_id'] ?? 1);
        $cfg['retencion_horas'] = max(1, (int)($_POST['retencion_horas'] ?? ($cfg['retencion_horas'] ?? 24)));
        $cfg['session_timeout'] = max(60, (int)($_POST['session_timeout'] ?? ($cfg['session_timeout'] ?? 300)));
        // CRUD de opciones (trackers, prioridades, estados)
        $optType = $_POST['opt_type'] ?? '';
        $optAction = $_POST['opt_action'] ?? '';
        if ($optType && isset($cfg[$optType]) && is_array($cfg[$optType])) {
            if ($optAction === 'create') {
                $id = trim($_POST['opt_id'] ?? '');
                $nombre = trim($_POST['opt_nombre'] ?? '');
                if ($id !== '' && $nombre !== '') {
                    if (isset($_POST['opt_default'])) {
                        foreach ($cfg[$optType] as &$o) { $o['default'] = false; }
                    }
                    $cfg[$optType][] = ['id' => is_numeric($id)?(int)$id:$id, 'nombre' => $nombre, 'default' => isset($_POST['opt_default'])];
                }
            } elseif ($optAction === 'update') {
                $id = $_POST['opt_id'] ?? '';
                foreach ($cfg[$optType] as &$o) {
                    if ((string)$o['id'] === (string)$id) {
                        $o['nombre'] = trim($_POST['opt_nombre'] ?? $o['nombre']);
                        $o['default'] = isset($_POST['opt_default']);
                        break;
                    }
                }
                if (isset($_POST['opt_default'])) {
                    foreach ($cfg[$optType] as &$o) {
                        $o['default'] = ((string)$o['id'] === (string)$id);
                    }
                }
            } elseif ($optAction === 'delete') {
                $id = $_POST['opt_id'] ?? '';
                $cfg[$optType] = array_values(array_filter($cfg[$optType], fn($o) => (string)$o['id'] !== (string)$id));
            } elseif ($optAction === 'set_default') {
                $id = $_POST['opt_id'] ?? '';
                foreach ($cfg[$optType] as &$o) {
                    $o['default'] = ((string)$o['id'] === (string)$id);
                }
            }
            // actualizar configuracion con default
            foreach (['trackers'=>'tracker_id','prioridades'=>'priority_id','estados'=>'status_id'] as $k=>$confKey) {
                if ($optType === $k) {
                    foreach ($cfg[$k] as $o) {
                        if (!empty($o['default'])) {
                            $cfg[$confKey] = $o['id'];
                            break;
                        }
                    }
                }
            }
        }
        save_config($CONFIG_FILE, $cfg);
        config_set_flash('Configuración guardada');
        config_redirect_back();
    }
    $opts = [
        'trackers' => $cfg['trackers'] ?? [],
        'prioridades' => $cfg['prioridades'] ?? [],
        'estados' => $cfg['estados'] ?? [],
    ];
    return [$cfg, $flash, $opts];
}
?>
