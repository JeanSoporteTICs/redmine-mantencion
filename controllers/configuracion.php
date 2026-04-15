<?php
require_once __DIR__ . '/auth.php';
// Mantenedor de configuración de envío a Redmine (incluye opciones de tracker/prioridad/estado)
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';

function ensure_config_file($path) {
    if (!file_exists($path)) {
        $default = [
            'platform_url' => 'https://coresalud.cl/gp/projects/backlog-mantencion-ti/issues.json',
            'platform_token' => '',
            'source_mode' => 'core',
            'core_enabled' => true,
            'core_admin_url' => 'https://www.hbvaldivia.cl/core/solicitudes/administrador',
            'core_historico_url' => 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas',
            'core_sync_minutes' => 2,
            'core_last_sync' => '',
            'core_last_error' => '',
            'project_id' => 48,
            'project_name' => 'Backlog Mantencion TI',
            'tracker_id' => 1,
            'priority_id' => 2,
            'cf_solicitante' => 3,
            'cf_unidad' => 5,
            'cf_unidad_solicitante' => 11,
            'cf_hora_extra' => 12,
            'categories_url' => 'https://coresalud.cl/gp/projects/backlog-mantencion-ti/settings/categories',
            'unidades_url' => null,
            'status_id' => 1,
            'retencion_horas' => 24,
            'session_timeout' => 300,
            'trackers' => [
                ['id' => 1, 'nombre' => 'Errores', 'default' => true],
                ['id' => 3, 'nombre' => 'Soporte', 'default' => false],
                ['id' => 10, 'nombre' => 'Servidores', 'default' => false],
                ['id' => 14, 'nombre' => 'Estadisticas', 'default' => false],
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
    if (!array_key_exists('cf_solicitante', $data) || $data['cf_solicitante'] === null || $data['cf_solicitante'] === '') {
        $data['cf_solicitante'] = 3;
    }
    if (!array_key_exists('cf_unidad', $data) || $data['cf_unidad'] === null || $data['cf_unidad'] === '') {
        $data['cf_unidad'] = 5;
    }
    if (!array_key_exists('cf_unidad_solicitante', $data)) {
        $data['cf_unidad_solicitante'] = 11;
    }
    if (!array_key_exists('cf_hora_extra', $data)) {
        $data['cf_hora_extra'] = 12;
    }
    if (!array_key_exists('source_mode', $data)) $data['source_mode'] = 'core';
    if (!array_key_exists('core_enabled', $data)) $data['core_enabled'] = true;
    if (!array_key_exists('core_admin_url', $data)) $data['core_admin_url'] = 'https://www.hbvaldivia.cl/core/solicitudes/administrador';
    if (!array_key_exists('core_historico_url', $data)) $data['core_historico_url'] = 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas';
    if (!array_key_exists('core_sync_minutes', $data)) $data['core_sync_minutes'] = 2;
    if (!array_key_exists('core_last_sync', $data)) $data['core_last_sync'] = '';
    if (!array_key_exists('core_last_error', $data)) $data['core_last_error'] = '';
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
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Configuracion/configuracion.php';
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
        $cfg['source_mode'] = 'core';
        $cfg['core_enabled'] = true;
        $cfg['core_admin_url'] = trim($_POST['core_admin_url'] ?? ($cfg['core_admin_url'] ?? ''));
        $cfg['core_historico_url'] = trim($_POST['core_historico_url'] ?? ($cfg['core_historico_url'] ?? ($cfg['core_admin_url'] ?? '')));
        $cfg['core_sync_minutes'] = max(1, (int)($_POST['core_sync_minutes'] ?? ($cfg['core_sync_minutes'] ?? 2)));
        unset($cfg['core_login_user'], $cfg['core_login_pass']);
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
            $normalizeId = static function ($value): string {
                return trim((string)$value);
            };
            $findOptionIndex = static function (array $items, string $id) use ($normalizeId): ?int {
                foreach ($items as $index => $item) {
                    if ($normalizeId($item['id'] ?? '') === $normalizeId($id)) {
                        return $index;
                    }
                }
                return null;
            };
            if ($optAction === 'create') {
                $id = trim($_POST['opt_id'] ?? '');
                $nombre = trim($_POST['opt_nombre'] ?? '');
                if ($id !== '' && $nombre !== '') {
                    if ($findOptionIndex($cfg[$optType], $id) === null) {
                        if (isset($_POST['opt_default'])) {
                            foreach ($cfg[$optType] as &$o) { $o['default'] = false; }
                            unset($o);
                        }
                        $cfg[$optType][] = ['id' => is_numeric($id)?(int)$id:$id, 'nombre' => $nombre, 'default' => isset($_POST['opt_default'])];
                    }
                }
            } elseif ($optAction === 'update') {
                $originalId = trim((string)($_POST['opt_id_original'] ?? $_POST['opt_id'] ?? ''));
                $newId = trim((string)($_POST['opt_id'] ?? ''));
                $newNombre = trim((string)($_POST['opt_nombre'] ?? ''));
                $targetIndex = $findOptionIndex($cfg[$optType], $originalId);
                if ($targetIndex !== null && $newId !== '' && $newNombre !== '') {
                    $conflictIndex = $findOptionIndex($cfg[$optType], $newId);
                    if ($conflictIndex === null || $conflictIndex === $targetIndex) {
                        $cfg[$optType][$targetIndex]['id'] = is_numeric($newId) ? (int)$newId : $newId;
                        $cfg[$optType][$targetIndex]['nombre'] = $newNombre;
                        $cfg[$optType][$targetIndex]['default'] = isset($_POST['opt_default']);
                    }
                }
                if (isset($_POST['opt_default'])) {
                    foreach ($cfg[$optType] as &$o) {
                        $o['default'] = ($normalizeId($o['id'] ?? '') === $normalizeId($newId));
                    }
                    unset($o);
                }
            } elseif ($optAction === 'delete') {
                $id = trim((string)($_POST['opt_id_original'] ?? $_POST['opt_id'] ?? ''));
                $deletedWasDefault = false;
                foreach ($cfg[$optType] as $o) {
                    if ($normalizeId($o['id'] ?? '') === $normalizeId($id) && !empty($o['default'])) {
                        $deletedWasDefault = true;
                        break;
                    }
                }
                $cfg[$optType] = array_values(array_filter($cfg[$optType], fn($o) => $normalizeId($o['id'] ?? '') !== $normalizeId($id)));
                if ($deletedWasDefault && !empty($cfg[$optType])) {
                    $cfg[$optType][0]['default'] = true;
                }
            } elseif ($optAction === 'set_default') {
                $id = $_POST['opt_id'] ?? '';
                foreach ($cfg[$optType] as &$o) {
                    $o['default'] = ((string)$o['id'] === (string)$id);
                }
                unset($o);
            }
            // actualizar configuracion con default
            foreach (['trackers'=>'tracker_id','prioridades'=>'priority_id','estados'=>'status_id'] as $k=>$confKey) {
                if ($optType === $k) {
                    $cfg[$confKey] = null;
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
