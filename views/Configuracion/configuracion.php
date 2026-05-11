<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root','administrador','gestor'], '/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/configuracion.php';
require_once __DIR__ . '/../../controllers/categorias.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
$maintenanceFlash = handle_maintenance_request();
[$cfg, $flash, $opts] = handle_configuracion();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$role = auth_get_user_role();
$onlyCatalogs = ($role === 'administrador');
$csrf = csrf_token();
$maintenanceMode = maintenance_mode_enabled();
$maintenanceSettings = maintenance_mode_settings();

$rolesFile = __DIR__ . '/../../data/roles.json';
$rolesData = auth_load_roles();
$rolesData = is_array($rolesData) ? $rolesData : [];
$usuariosFile = __DIR__ . '/../../data/usuarios.json';
$usuariosData = json_decode(@file_get_contents($usuariosFile), true);
if (!is_array($usuariosData)) $usuariosData = [];
$usuariosIndex = [];
foreach ($usuariosData as $u) {
  if (is_array($u) && isset($u['id'])) {
    $usuariosIndex[(string)$u['id']] = $u;
  }
}

$ensureRolePermission = function (string $role, string $key, $value) use (&$rolesData): void {
  if (!isset($rolesData[$role]) || !is_array($rolesData[$role])) return;
  if (!array_key_exists($key, $rolesData[$role])) {
    $rolesData[$role][$key] = $value;
  }
};
$ensureRolePermission('root', 'procedimientos', true);
$ensureRolePermission('root', 'procedimientos_editar', true);
$ensureRolePermission('gestor', 'procedimientos', true);
$ensureRolePermission('gestor', 'procedimientos_editar', true);
$ensureRolePermission('administrador', 'procedimientos', true);
$ensureRolePermission('administrador', 'procedimientos_editar', true);
$ensureRolePermission('usuario', 'procedimientos', true);
$ensureRolePermission('usuario', 'procedimientos_editar', false);
foreach (array_keys($rolesData) as $roleName) {
  $ensureRolePermission((string)$roleName, 'procedimientos', true);
  $ensureRolePermission((string)$roleName, 'procedimientos_editar', in_array((string)$roleName, ['root', 'gestor', 'administrador'], true));
}
$categoriasData = [];
$categoriasFile = __DIR__ . '/../../data/categorias.json';
if (file_exists($categoriasFile)) {
  $categoriasData = json_decode(file_get_contents($categoriasFile), true);
  if (!is_array($categoriasData)) $categoriasData = [];
}
if (empty($rolesData)) {
  $rolesData = [
    'root' => [
      'all' => true,
      'mensajes' => 'todos',
      'mensajes_acceso' => true,
      'horas_extra' => 'todos',
      'historico' => true,
      'historico_scope' => 'todos',
      'historico_acciones' => true,
      'procedimientos' => true,
      'procedimientos_editar' => true,
      'configuracion' => true,
      'estadisticas' => true,
      'usuarios' => true,
      'categorias' => true,
      'simulador' => true,
      'cfg_conexion' => true,
      'cfg_proyecto' => true,
      'cfg_retencion' => true,
      'cfg_sesion' => true,
        'cfg_trackers' => true,
        'cfg_prioridades' => true,
        'cfg_estados' => true,
        'cfg_roles' => true,
        'cfg_usuarios' => true,
        'actividad' => true,
      'actividad' => true,
    ],
    'gestor' => [
      'mensajes' => 'asignados',
      'mensajes_acceso' => true,
      'horas_extra' => 'asignados',
      'historico' => true,
      'historico_scope' => 'asignados',
      'historico_acciones' => true,
      'procedimientos' => true,
      'procedimientos_editar' => true,
      'configuracion' => true,
      'estadisticas' => true,
      'usuarios' => true,
      'categorias' => true,
      'simulador' => true,
      'cfg_conexion' => true,
      'cfg_proyecto' => true,
      'cfg_retencion' => true,
      'cfg_sesion' => true,
      'cfg_trackers' => true,
      'cfg_prioridades' => true,
      'cfg_estados' => true,
      'cfg_roles' => true,
      'cfg_usuarios' => true,
    ],
    'administrador' => [
      'mensajes' => 'todos',
      'mensajes_acceso' => true,
      'horas_extra' => 'todos',
      'historico' => false,
      'historico_scope' => 'asignados',
      'historico_acciones' => false,
      'procedimientos' => true,
      'procedimientos_editar' => true,
      'configuracion' => true,
      'estadisticas' => true,
      'usuarios' => false,
      'categorias' => true,
      'simulador' => true,
      'cfg_conexion' => true,
      'cfg_proyecto' => true,
      'cfg_retencion' => true,
      'cfg_sesion' => true,
      'cfg_trackers' => true,
      'cfg_prioridades' => true,
      'cfg_estados' => true,
      'cfg_roles' => false,
      'cfg_usuarios' => false,
      'actividad' => false,
      'actividad' => true,
    ],
    'usuario' => [
      'mensajes' => 'asignados',
      'mensajes_acceso' => true,
      'horas_extra' => 'asignados',
      'historico' => false,
      'historico_scope' => 'asignados',
      'historico_acciones' => false,
      'procedimientos' => true,
      'procedimientos_editar' => false,
      'configuracion' => false,
      'estadisticas' => false,
      'usuarios' => false,
      'categorias' => false,
      'simulador' => true,
      'cfg_conexion' => false,
      'cfg_proyecto' => false,
      'cfg_retencion' => false,
      'cfg_sesion' => false,
      'cfg_trackers' => false,
      'cfg_prioridades' => false,
      'cfg_estados' => false,
      'cfg_roles' => false,
      'cfg_usuarios' => false,
    ],
  ];
}

$flashRoles = null;
$flashUsuarios = null;
$openRolesModal = false;
$openUsersModal = false;
$selectedRoleSel = $_POST['role_select'] ?? 'gestor';
$newRoleName = trim($_POST['new_role'] ?? '');
$selectedRole = $newRoleName !== '' ? $newRoleName : $selectedRoleSel;
$selectedUser = $_POST['user_select'] ?? '';
$canManageRoles = auth_can('cfg_roles');
$canManageUsers = auth_can('cfg_usuarios');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($maintenanceMode && !in_array($action, ['maintenance_settings', 'maintenance_export'], true)) {
    if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  }
  if ($action === 'load_role' && $canManageRoles) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedRole = trim($_POST['role_select'] ?? $selectedRole);
    $flash = null;
    $flashRoles = null;
    $openRolesModal = true;
  } elseif ($action === 'save_roles' && $canManageRoles) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedRole = $newRoleName !== '' ? $newRoleName : trim($_POST['role_select'] ?? $selectedRole);
    if ($selectedRole !== '') {
      if (!isset($rolesData[$selectedRole])) $rolesData[$selectedRole] = [];
      if ($selectedRole === 'root') {
        $rolesData['root'] = [
          'all' => true,
          'mensajes' => 'todos',
          'mensajes_acceso' => true,
          'horas_extra' => 'todos',
          'historico' => true,
          'historico_scope' => ($_POST['historico_scope'] ?? 'todos'),
          'historico_acciones' => isset($_POST['perm_historico_acciones']),
          'procedimientos' => true,
          'procedimientos_editar' => true,
          'configuracion' => true,
          'estadisticas' => true,
          'usuarios' => true,
          'categorias' => true,
          'simulador' => true,
          'cfg_conexion' => true,
          'cfg_proyecto' => true,
          'cfg_retencion' => true,
          'cfg_sesion' => true,
          'cfg_trackers' => true,
          'cfg_prioridades' => true,
          'cfg_estados' => true,
          'cfg_roles' => true,
          'cfg_usuarios' => true,
          'actividad' => true,
        ];
      } else {
        $roleCanViewHistorico = isset($_POST['perm_historico']);
        $roleCanViewProcedimientos = isset($_POST['perm_procedimientos']);
        $rolesData[$selectedRole] = [
          'mensajes' => ($_POST['mensajes_scope'] ?? 'asignados'),
          'mensajes_acceso' => isset($_POST['perm_mensajes']),
          'horas_extra' => isset($_POST['perm_horas_extra']) ? ($_POST['horas_scope'] ?? 'asignados') : '',
          'historico' => $roleCanViewHistorico,
          'historico_scope' => ($_POST['historico_scope'] ?? 'asignados'),
          'historico_acciones' => $roleCanViewHistorico && isset($_POST['perm_historico_acciones']),
          'procedimientos' => $roleCanViewProcedimientos,
          'procedimientos_editar' => $roleCanViewProcedimientos && isset($_POST['perm_procedimientos_editar']),
          'configuracion' => isset($_POST['perm_configuracion']),
          'estadisticas' => isset($_POST['perm_estadisticas']),
          'usuarios' => isset($_POST['perm_usuarios']),
          'categorias' => isset($_POST['perm_categorias']),
          'simulador' => isset($_POST['perm_simulador']),
          'cfg_conexion' => isset($_POST['perm_cfg_conexion']),
          'cfg_proyecto' => isset($_POST['perm_cfg_proyecto']),
          'cfg_retencion' => isset($_POST['perm_cfg_retencion']),
          'cfg_sesion' => isset($_POST['perm_cfg_sesion']),
          'cfg_trackers' => isset($_POST['perm_cfg_trackers']),
          'cfg_prioridades' => isset($_POST['perm_cfg_prioridades']),
          'cfg_estados' => isset($_POST['perm_cfg_estados']),
          'cfg_roles' => isset($_POST['perm_cfg_roles']),
          'cfg_usuarios' => isset($_POST['perm_cfg_usuarios']),
          'actividad' => isset($_POST['perm_actividad']),
        ];
      }
      storage_write_json($rolesFile, $rolesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      $flashRoles = 'Permisos actualizados para el rol "' . $h($selectedRole) . '"';
      $openRolesModal = true;
    }
  }
  if ($action === 'load_user_perms' && $canManageUsers) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedUser = trim($_POST['user_select'] ?? $selectedUser);
    $flash = null;
    $flashUsuarios = null;
    $openUsersModal = true;
  } elseif ($action === 'save_user_perms' && $canManageUsers) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedUser = trim($_POST['user_select'] ?? $selectedUser);
    if ($selectedUser !== '' && isset($usuariosIndex[$selectedUser])) {
      $newUserRole = trim($_POST['u_role'] ?? '');
      if ($newUserRole !== '') {
        foreach ($usuariosData as &$u) {
          if ((string)($u['id'] ?? '') === $selectedUser) {
            $u['rol'] = $newUserRole;
            $usuariosIndex[$selectedUser]['rol'] = $newUserRole;
            break;
          }
        }
        unset($u);
      }
      $userCanViewHistorico = isset($_POST['u_perm_historico']);
      $userCanViewProcedimientos = isset($_POST['u_perm_procedimientos']);
      $cfgUser = [
        'mensajes' => ($_POST['u_mensajes_scope'] ?? 'asignados'),
        'mensajes_acceso' => isset($_POST['u_perm_mensajes']),
        'horas_extra' => isset($_POST['u_perm_horas_extra']) ? ($_POST['u_horas_scope'] ?? 'asignados') : '',
        'historico' => $userCanViewHistorico,
        'historico_acciones' => $userCanViewHistorico && isset($_POST['u_perm_historico_acciones']),
        'historico_scope' => ($_POST['u_historico_scope'] ?? 'asignados'),
        'procedimientos' => $userCanViewProcedimientos,
        'procedimientos_editar' => $userCanViewProcedimientos && isset($_POST['u_perm_procedimientos_editar']),
        'configuracion' => isset($_POST['u_perm_configuracion']),
        'estadisticas' => isset($_POST['u_perm_estadisticas']),
        'usuarios' => isset($_POST['u_perm_usuarios']),
        'categorias' => isset($_POST['u_perm_categorias']),
        'simulador' => isset($_POST['u_perm_simulador']),
        'cfg_conexion' => isset($_POST['u_perm_cfg_conexion']),
        'cfg_proyecto' => isset($_POST['u_perm_cfg_proyecto']),
        'cfg_retencion' => isset($_POST['u_perm_cfg_retencion']),
        'cfg_sesion' => isset($_POST['u_perm_cfg_sesion']),
        'cfg_trackers' => isset($_POST['u_perm_cfg_trackers']),
        'cfg_prioridades' => isset($_POST['u_perm_cfg_prioridades']),
        'cfg_estados' => isset($_POST['u_perm_cfg_estados']),
        'cfg_roles' => isset($_POST['u_perm_cfg_roles']),
        'cfg_usuarios' => isset($_POST['u_perm_cfg_usuarios']),
        'actividad' => isset($_POST['u_perm_actividad']),
      ];
      foreach ($usuariosData as &$u) {
        if ((string)($u['id'] ?? '') === $selectedUser) {
          $u['permisos'] = $cfgUser;
          break;
        }
      }
      unset($u);
      $usuariosIndex[$selectedUser]['permisos'] = $cfgUser;
      $usuariosIndex[$selectedUser]['rol'] = $newUserRole !== '' ? $newUserRole : ($usuariosIndex[$selectedUser]['rol'] ?? '');
      $selUserData = $usuariosIndex[$selectedUser];
      $selUserRole = $selUserData['rol'] ?? $selUserRole;
      $selUserPerms = $cfgUser;
      storage_write_json($usuariosFile, $usuariosData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      $flashUsuarios = 'Permisos actualizados para el usuario ID ' . $h($selectedUser);
      $openUsersModal = true;
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_remote') {
  if (function_exists('csrf_validate')) csrf_validate();
  if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  $res = sync_categorias_desde_api(__DIR__ . '/../../data/configuracion.json', __DIR__ . '/../../data/categorias.json');
  $msg = isset($res['error']) ? $res['error'] : ('Categorías sincronizadas (' . ($res['ok'] ?? 0) . ' registros).');
  header('Location: /redmine-mantencion/views/Configuracion/configuracion.php?synccat=' . urlencode($msg));
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Configuracion'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
    /* Fallback modal styles si Bootstrap no carga */
    .modal {
      display: none;
      position: fixed;
      z-index: 1055;
      inset: 0;
      align-items: center;
      justify-content: center;
      background: rgba(0,0,0,0.5);
      overflow: hidden;
    }
    .modal.show { display: flex; }
    .modal-dialog {
      margin: 0 auto;
      max-height: calc(100vh - 30px);
    }
    .modal-dialog.modal-dialog-top { margin-top: 12px; }
    .modal-dialog-scrollable,
    .modal-dialog-scrollable .modal-content {
      max-height: calc(100vh - 60px);
    }
    .modal-dialog-scrollable .modal-body,
    .modal-body.modal-body-tight {
      max-height: calc(100vh - 200px);
      overflow-y: auto;
    }
    .modal-backdrop { display: block; }
    .modal-dialog-scrollable .modal-body {
      max-height: calc(100vh - 220px);
      overflow-y: auto;
    }
    /* Botones de config */
    .cfg-grid { row-gap: 20px; }
    .cfg-btn {
      padding: 22px 22px;
      font-size: 1.05rem;
      width: 100%;
      border-radius: 16px;
      background: #fff;
      color: #1f2937;
      box-shadow: 0 10px 28px rgba(17, 24, 39, 0.08);
      border: 1px solid rgba(0,0,0,0.03);
      transition: all .18s ease;
      min-height: 88px;
    }
    .cfg-btn i { font-size: 1.35rem; }
    .cfg-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 16px 36px rgba(53,88,230,0.18);
      color: var(--sb-primary);
    }
    .cfg-btn span { font-weight: 600; letter-spacing: 0.01em; }
    .cfg-btn .bi-arrow-right-short { font-size: 1.4rem; }
    .cfg-section { background: #fff; border-radius: 18px; box-shadow: 0 12px 30px rgba(17,24,39,0.08); padding: 18px 20px; }
    .cfg-section h5 { font-weight: 700; }
    .form-check { margin-bottom: 8px; }
    .permission-section-help {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      color: #475569;
      font-size: .88rem;
      margin: -6px 0 14px;
      padding: 10px 12px;
      border: 1px solid #dbeafe;
      border-radius: 12px;
      background: #eff6ff;
    }
    .permission-section-help::before {
      content: "i";
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: #2563eb;
      color: #fff;
      font-size: .75rem;
      font-weight: 800;
      flex: 0 0 auto;
      margin-top: 1px;
    }
    .permission-related {
      position: relative;
      padding-bottom: 0 !important;
    }
    .permission-child {
      position: relative;
      min-height: 29px !important;
      width: 75%;
      margin-top: -4px !important;
      margin-left: auto !important;
      border-color: #dbeafe !important;
      background: #f8fbff !important;
      box-shadow: inset 3px 0 0 #93c5fd;
    }
    .permission-child::before {
      content: "";
      position: absolute;
      left: -25%;
      top: -14px;
      width: 25%;
      height: 24px;
      border-left: 2px solid #bfdbfe;
      border-bottom: 2px solid #bfdbfe;
      border-bottom-left-radius: 8px;
      pointer-events: none;
    }
    .permission-tag {
      display: inline-flex;
      align-items: center;
      margin-left: 6px;
      padding: 1px 5px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
      font-size: .58rem;
      font-weight: 800;
      line-height: 1;
      vertical-align: middle;
    }
    .permission-note {
      display: block;
      margin-top: 1px;
      color: #64748b;
      font-size: .58rem;
      font-weight: 500;
      line-height: 1.25;
    }
    .permission-child .form-check-label {
      display: block;
    }
    .permission-child .form-check-input:disabled + .form-check-label,
    .permission-child .form-check-input:disabled ~ .form-check-label {
      color: #9ca3af !important;
    }
    .permission-child .form-check-input:disabled ~ .form-check-label .permission-tag {
      background: #f3f4f6;
      color: #9ca3af;
    }
    .permission-child .form-check-input:disabled ~ .form-check-label .permission-note {
      color: #9ca3af;
    }
    .user-picker {
      position: relative;
    }
    .user-picker-results {
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 6px);
      max-height: 260px;
      overflow-y: auto;
      border: 1px solid #dbeafe;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 18px 40px rgba(15, 23, 42, .16);
      z-index: 1056;
    }
    .user-picker-option {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 12px;
      border: 0;
      border-bottom: 1px solid #eef2f7;
      background: #fff;
      color: #1f2937;
      text-align: left;
      font-weight: 700;
    }
    .user-picker-option:hover,
    .user-picker-option.is-active {
      background: #eff6ff;
      color: #1d4ed8;
    }
    .user-picker-option:last-child {
      border-bottom: 0;
    }
    .user-picker-id {
      flex: 0 0 auto;
      padding: 2px 8px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
      font-size: .76rem;
      font-weight: 800;
    }
    .user-picker-empty {
      padding: 12px;
      color: #64748b;
      font-size: .9rem;
    }
    .roles-modal-shell { display: grid; gap: 18px; }
    :is(#rolesModal, #usuariosModal) .modal-content {
      border: 0;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 24px 70px rgba(15, 23, 42, .18);
    }
    :is(#rolesModal, #usuariosModal) .modal-header {
      background: linear-gradient(135deg, #f8fbff, #eef6ff);
      border-bottom: 1px solid #dbeafe;
      padding: 18px 22px;
    }
    :is(#rolesModal, #usuariosModal) .modal-title {
      font-weight: 800;
      color: #111827;
    }
    :is(#rolesModal, #usuariosModal) .modal-body {
      background: #f6f8fb;
      padding: 18px;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-8,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12 {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 16px;
      box-shadow: 0 10px 26px rgba(15, 23, 42, .05);
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-8 {
      width: 100%;
      display: block;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6 .form-label,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-8 .form-label,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12 > .form-label {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
      font-weight: 800;
      color: #111827;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6 .form-label::before,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-8 .form-label::before,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12 > .form-label::before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #2563eb;
      box-shadow: 0 0 0 4px #dbeafe;
      flex: 0 0 auto;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6 .form-text,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-8 .form-text {
      margin-bottom: 0;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6 input[name="new_role"] {
      margin-top: 10px !important;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(2) .row {
      row-gap: 12px;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(2) .col-md-4 {
      padding: 12px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #f8fafc;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(2) .small {
      font-weight: 800;
      color: #374151;
    }
    :is(#rolesModal, #usuariosModal) .permission-scope-section .row {
      row-gap: 12px;
    }
    :is(#rolesModal, #usuariosModal) .permission-scope-section .col-md-4 {
      padding: 12px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #f8fafc;
    }
    :is(#rolesModal, #usuariosModal) .permission-scope-section .small {
      font-weight: 800;
      color: #374151;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .col-md-4,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .col-md-4 {
      padding: 0 6px;
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .col-md-4 {
      padding: 0 6px;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check {
      min-height: 56px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin: 0 0 10px;
      padding: 12px 14px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #fff;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check {
      min-height: 56px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin: 0 0 10px;
      padding: 12px 14px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      background: #fff;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check:has(.form-check-input:checked),
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check:has(.form-check-input:checked) {
      border-color: #93c5fd;
      background: #f8fbff;
      box-shadow: 0 8px 20px rgba(37, 99, 235, .08);
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check:has(.form-check-input:checked) {
      border-color: #93c5fd;
      background: #f8fbff;
      box-shadow: 0 8px 20px rgba(37, 99, 235, .08);
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-input,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-input {
      order: 2;
      margin-left: 12px;
      width: 2.7rem;
      height: 1.45rem;
      margin-top: 0;
      margin-right: 0;
      border: 1px solid #cbd5e1;
      border-radius: 999px;
      background-color: #cbd5e1;
      background-image: none;
      cursor: pointer;
      position: relative;
      flex: 0 0 auto;
      transition: background-color .18s ease, border-color .18s ease, box-shadow .18s ease;
      appearance: none;
      -webkit-appearance: none;
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check-input {
      order: 2;
      margin-left: 12px;
      width: 2.7rem;
      height: 1.45rem;
      margin-top: 0;
      margin-right: 0;
      border: 1px solid #cbd5e1;
      border-radius: 999px;
      background-color: #cbd5e1;
      background-image: none;
      cursor: pointer;
      position: relative;
      flex: 0 0 auto;
      transition: background-color .18s ease, border-color .18s ease, box-shadow .18s ease;
      appearance: none;
      -webkit-appearance: none;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-input::before,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-input::before {
      content: "";
      position: absolute;
      top: 2px;
      left: 2px;
      width: 1.05rem;
      height: 1.05rem;
      border-radius: 999px;
      background: #fff;
      box-shadow: 0 2px 6px rgba(15, 23, 42, .25);
      transition: transform .18s ease;
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check-input::before {
      content: "";
      position: absolute;
      top: 2px;
      left: 2px;
      width: 1.05rem;
      height: 1.05rem;
      border-radius: 999px;
      background: #fff;
      box-shadow: 0 2px 6px rgba(15, 23, 42, .25);
      transition: transform .18s ease;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-input:checked,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-input:checked {
      border-color: #2563eb;
      background-color: #2563eb;
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check-input:checked {
      border-color: #2563eb;
      background-color: #2563eb;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-input:checked::before,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-input:checked::before {
      transform: translateX(1.22rem);
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check-input:checked::before {
      transform: translateX(1.22rem);
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-input:focus,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-input:focus {
      box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .18);
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-input:disabled,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-input:disabled {
      border-color: #e5e7eb;
      background-color: #e5e7eb;
      cursor: not-allowed;
      opacity: 1;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(3) .form-check-label,
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-12:nth-of-type(4) .form-check-label {
      order: 1;
      flex: 1 1 auto;
      font-weight: 700;
      color: #1f2937;
      line-height: 1.2;
    }
    :is(#rolesModal, #usuariosModal) .permission-grid-section .form-check-label {
      order: 1;
      flex: 1 1 auto;
      font-weight: 700;
      color: #1f2937;
      line-height: 1.2;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell .form-check.ms-3 {
      background: #f9fafb;
      padding: 5px 8px;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell .permission-child .form-check-input {
      width: 1.5rem;
      height: .8rem;
      margin-left: 6px;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell .permission-child .form-check-input::before {
      top: 1px;
      left: 1px;
      width: .58rem;
      height: .58rem;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell .permission-child .form-check-input:checked::before {
      transform: translateX(.68rem);
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell .permission-child .form-check-label {
      font-size: .62rem;
    }
    :is(#rolesModal, #usuariosModal) .roles-modal-shell > .text-end {
      position: sticky;
      bottom: -18px;
      margin: 0 -16px -16px;
      padding: 16px;
      background: linear-gradient(180deg, rgba(255,255,255,0), #fff 35%);
      border: 0;
      box-shadow: none;
      z-index: 3;
    }
    :is(#rolesModal, #usuariosModal) #btn-save-roles,
    :is(#rolesModal, #usuariosModal) #btn-save-user-perms {
      min-width: 180px;
      font-weight: 800;
    }
    @media (max-width: 767.98px) {
      :is(#rolesModal, #usuariosModal) .roles-modal-shell > .col-md-6 input[name="new_role"] { margin-top: 10px !important; }
    }
    .table thead th { font-weight: 700; text-transform: uppercase; font-size: .78rem; letter-spacing: .02em; }
  </style>
  <style>
    .cfg-hero { background: linear-gradient(120deg, rgba(78,115,223,0.12), rgba(28,200,138,0.12)); border-radius: 20px; padding: 24px; box-shadow: 0 14px 36px rgba(0,0,0,0.06); }
    .cfg-btn { padding: 24px 26px; font-size: 1.05rem; width: 100%; border-radius: 18px; background: #fff; color: #1f2937; box-shadow: 0 12px 30px rgba(0,0,0,0.06); border: 1px solid rgba(0,0,0,0.03); transition: all .18s ease; min-height: 88px; }
    .cfg-btn i { font-size: 1.35rem; }
    .cfg-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 16px 36px rgba(53,88,230,0.18);
      color: var(--sb-primary);
    }
    .cfg-btn span { font-weight: 600; letter-spacing: 0.01em; }
    .cfg-btn .bi-arrow-right-short { font-size: 1.4rem; }
    .table thead th { font-weight: 700; text-transform: uppercase; font-size: .78rem; letter-spacing: .02em; }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'configuracion'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-gear-wide-connected';
    $heroTitle = 'Configuración de Redmine';
    $heroSubtitle = 'Administra conexión, proyecto, tiempos y listas maestras.';
    $heroExtras = '';
    if ($flash) {
      $heroExtras .= '<div class="alert alert-success py-2 px-3 mb-0" id="flash-msg">' . $h($flash) . '</div>';
    }
    if ($flashRoles) {
      $heroExtras .= ($heroExtras ? '<div class="mt-2"></div>' : '') . '<div class="alert alert-success py-2 px-3 mb-0 mt-2" id="flash-roles">' . $h($flashRoles) . '</div>';
    }
    if ($maintenanceFlash) {
      $heroExtras .= ($heroExtras ? '<div class="mt-2"></div>' : '') . '<div class="alert alert-info py-2 px-3 mb-0 mt-2" id="flash-maintenance">' . $h($maintenanceFlash) . '</div>';
    }
    include __DIR__ . '/../partials/hero.php';
  ?>

  <div class="row g-3 cfg-grid">
    <?php if (!$onlyCatalogs): ?>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#ConexionModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-link-45deg text-primary"></i>Conexión</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#proyectoModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-folder2-open text-warning"></i>Proyecto</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#RetencionModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-stopwatch text-danger"></i>Retencion</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#SesionModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-shield-lock text-info"></i>Sesion</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#trackersModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-pin-angle text-primary"></i>Trackers</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#prioridadesModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-lightning-charge text-warning"></i>Prioridades</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#estadosModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-file-earmark-text text-success"></i>Estados</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <?php endif; ?>

    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#categoriasModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-tags text-success"></i>Categor&iacute;as</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <?php if (!$onlyCatalogs): ?>
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#maintenanceModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-tools text-secondary"></i>Mantenci&oacute;n</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
    <?php endif; ?>
<?php if ($canManageRoles || $canManageUsers): ?>
  <?php if ($canManageRoles): ?>
  <div class="col-12 col-md-6 col-lg-4">
    <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#rolesModal" id="btn-roles-modal">
      <span class="d-flex align-items-center gap-2"><i class="bi bi-shield-lock-fill text-danger"></i>Roles y permisos</span>
      <i class="bi bi-arrow-right-short"></i>
    </button>
  </div>
  <?php endif; ?>
  <?php if ($canManageUsers): ?>
  <div class="col-12 col-md-6 col-lg-4">
    <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#usuariosModal" id="btn-usuarios-modal">
      <span class="d-flex align-items-center gap-2"><i class="bi bi-people text-primary"></i>Usuarios y permisos</span>
      <i class="bi bi-arrow-right-short"></i>
    </button>
  </div>
  <?php endif; ?>
<?php endif; ?>
  </div>

</div>

<!-- Modal Mantención -->
<div class="modal fade" id="maintenanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Mantenci&oacute;n</h5>
          <div class="text-muted small">Exporta o importa respaldos operativos en formato JSON.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="p-3 border rounded-4 bg-white mb-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="maintenance_settings">
          <div class="row g-3 align-items-end">
            <div class="col-md-5">
              <label class="form-label">Estado de mantenci&oacute;n</label>
              <div class="form-check form-switch maintenance-mode-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" value="1" id="maintenance-mode-check" <?= !empty($maintenanceSettings['enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="maintenance-mode-check">Mantenci&oacute;n activa</label>
              </div>
            </div>
            <div class="col-md-5">
              <label class="form-label">Hora estimada de t&eacute;rmino</label>
              <input type="datetime-local" name="maintenance_until" class="form-control" value="<?= $h($maintenanceSettings['until'] ?? '') ?>">
            </div>
            <div class="col-md-2">
              <button class="btn btn-primary w-100" type="submit">Guardar</button>
            </div>
          </div>
          <div class="form-text mt-2">Cuando est&aacute; activa, la plataforma queda en solo lectura y solo este modal permite cambiar la mantenci&oacute;n.</div>
        </form>
        <div class="alert alert-warning small">
          La importaci&oacute;n sobrescribe los archivos de las secciones seleccionadas. Antes de importar se genera backup autom&aacute;tico de los JSON reemplazados.
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <form method="post" class="h-100 p-3 border rounded-4 bg-white">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="maintenance_export">
              <h6 class="fw-bold mb-2"><i class="bi bi-download text-primary"></i> Exportar</h6>
              <p class="text-muted small">Descarga un paquete comprimido con un manifest y archivos JSON separados por ruta.</p>
              <?php foreach (maintenance_sections() as $key => $section): ?>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="maintenance_sections[]" value="<?= $h($key) ?>" id="export-<?= $h($key) ?>" checked>
                  <label class="form-check-label" for="export-<?= $h($key) ?>"><?= $h($section['label']) ?></label>
                </div>
              <?php endforeach; ?>
              <button class="btn btn-primary w-100 mt-3" type="submit">
                <i class="bi bi-file-earmark-arrow-down"></i> Exportar respaldo
              </button>
            </form>
          </div>
          <div class="col-md-6">
            <form method="post" enctype="multipart/form-data" class="h-100 p-3 border rounded-4 bg-white" data-app-confirm="Importar el respaldo sobrescribira las secciones seleccionadas. Continuar?">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="maintenance_import">
              <h6 class="fw-bold mb-2"><i class="bi bi-upload text-success"></i> Importar</h6>
              <p class="text-muted small">Carga un respaldo exportado desde esta plataforma.</p>
              <div class="mb-3">
                <label class="form-label">Archivo de respaldo</label>
                <input type="file" class="form-control" name="maintenance_file" accept="application/json,.json,.zip,.tar,.tgz,.gz" required>
                <div class="form-text">Acepta el paquete comprimido exportado o el JSON antiguo.</div>
              </div>
              <?php foreach (maintenance_sections() as $key => $section): ?>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="maintenance_sections[]" value="<?= $h($key) ?>" id="import-<?= $h($key) ?>" checked>
                  <label class="form-check-label" for="import-<?= $h($key) ?>"><?= $h($section['label']) ?></label>
                </div>
              <?php endforeach; ?>
              <button class="btn btn-success w-100 mt-3" type="submit">
                <i class="bi bi-file-earmark-arrow-up"></i> Importar respaldo
              </button>
            </form>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php $renderOptionsTable = function($id, $title, $items, $type, $h) use ($csrf) { ?>
<div class="modal fade" id="<?= $h($id) ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= $h($title) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-2 mb-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
          <input type="hidden" name="opt_action" value="create">
          <div class="col-md-3"><input name="opt_id" class="form-control form-control-sm" placeholder="ID" required></div>
          <div class="col-md-7"><input name="opt_nombre" class="form-control form-control-sm" placeholder="Nombre" required></div>
          <div class="col-md-2 d-flex align-items-center gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="opt_default" id="optdef-<?= $h($type) ?>">
              <label class="form-check-label" for="optdef-<?= $h($type) ?>">Default</label>
            </div>
            <button class="btn btn-primary btn-sm">Agregar</button>
          </div>
        </form>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light"><tr><th>ID</th><th>Nombre</th><th>Default</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($items as $index => $o): ?>
              <?php $rowFormId = $type . '-edit-' . $index . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($o['id'] ?? '')); ?>
              <?php $deleteFormId = $type . '-delete-' . $index . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($o['id'] ?? '')); ?>
              <tr>
                  <td style="width:120px">
                    <input form="<?= $h($rowFormId) ?>" name="opt_id" class="form-control form-control-sm" value="<?= $h($o['id']) ?>">
                  </td>
                  <td>
                    <input form="<?= $h($rowFormId) ?>" name="opt_nombre" class="form-control form-control-sm" value="<?= $h($o['nombre']) ?>">
                  </td>
                  <td class="text-center">
                    <input form="<?= $h($rowFormId) ?>" class="form-check-input" type="checkbox" name="opt_default" <?= !empty($o['default']) ? 'checked' : '' ?>>
                  </td>
                  <td class="d-flex gap-2">
                    <form method="post" id="<?= $h($rowFormId) ?>" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
                      <input type="hidden" name="opt_action" value="update">
                      <input type="hidden" name="opt_id_original" value="<?= $h($o['id']) ?>">
                      <button class="btn btn-success btn-sm" type="submit">Guardar</button>
                    </form>
                    <form method="post" id="<?= $h($deleteFormId) ?>" data-app-confirm="Eliminar?" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
                      <input type="hidden" name="opt_action" value="delete">
                      <input type="hidden" name="opt_id_original" value="<?= $h($o['id']) ?>">
                      <button class="btn btn-danger btn-sm" type="submit">Eliminar</button>
                    </form>
                  </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php }; ?>

<!-- Modales base -->
<?php $renderOptionsTable('trackersModal', 'Trackers', $opts['trackers'], 'trackers', $h); ?>
<?php $renderOptionsTable('prioridadesModal', 'Prioridades', $opts['prioridades'], 'prioridades', $h); ?>
<?php $renderOptionsTable('estadosModal', 'Estados', $opts['estados'], 'estados', $h); ?>

<!-- Modal Categorías -->
<div class="modal fade" id="categoriasModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-top">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Categor&iacute;as (solo lectura)</h5>
          <div class="text-muted small">Sincronizadas desde Redmine. Soporta `issue_categories.json` y `settings/categories`.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body modal-body-tight">
        <div id="cat-sync-msg" class="alert alert-success d-none"></div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:260px;">
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;"><i class="bi bi-search"></i></div>
            <input id="cat-filter" class="form-control" placeholder="Buscar categor&iacute;a (ID o nombre)">
            <span class="badge bg-light text-dark border">Total: <?= $h(is_array($categoriasData) ? count($categoriasData) : 0) ?></span>
          </div>
          <div class="d-flex gap-2">
            <form action="../Configuracion/configuracion.php" method="post" class="m-0 d-inline" id="sync-cat-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="sync_remote">
              <button class="btn btn-primary btn-icon" type="submit" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>><i class="bi bi-arrow-repeat"></i> Actualizar desde API</button>
            </form>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-striped align-middle" id="cat-table">
            <thead class="table-light"><tr><th style="width:120px;">ID</th><th>Nombre</th></tr></thead>
            <tbody>
              <?php if ($categoriasData): foreach ($categoriasData as $c): ?>
                <tr>
                  <td class="text-muted"><?= $h($c['id'] ?? '') ?></td>
                  <td><?= $h($c['nombre'] ?? '') ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="2" class="text-center text-muted">A&uacute;n no hay datos sincronizados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Conexión -->
<div class="modal fade" id="ConexionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Conexión API</h5>
          <div class="text-muted small">URL y token para enviar tickets a Redmine</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" onclick="(function(){var el=document.getElementById('rolesModal');if(!el)return;el.classList.remove('show');el.style.display='none';el.setAttribute('aria-hidden','true');var bd=document.getElementById('backdrop-roles');if(bd)bd.remove();document.body.classList.remove('modal-open');})();"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12">
            <label class="form-label">URL administrador CORE</label>
            <input name="core_admin_url" class="form-control" value="<?= $h($cfg['core_admin_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">URL histórico CORE</label>
            <input name="core_historico_url" class="form-control" value="<?= $h($cfg['core_historico_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas') ?>">
            <div class="form-text">Usa la URL del apartado hist&oacute;rico desde donde se listar&aacute;n las solicitudes filtradas.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Intervalo sync CORE (min)</label>
            <input type="number" min="1" name="core_sync_minutes" class="form-control" value="<?= $h($cfg['core_sync_minutes'] ?? 2) ?>">
          </div>
          <div class="col-md-8">
            <label class="form-label">Estado sincronizaci&oacute;n CORE</label>
            <input class="form-control" value="<?= $h(($cfg['core_last_sync'] ?? '') !== '' ? ('Ultima: ' . $cfg['core_last_sync']) . (($cfg['core_last_error'] ?? '') !== '' ? ' | Error: ' . $cfg['core_last_error'] : '') : 'Sin sincronizaciones registradas') ?>" readonly>
          </div>
          <div class="col-12">
            <label class="form-label">URL OnlyOffice Document Server</label>
            <input name="onlyoffice_url" class="form-control" value="<?= $h($cfg['onlyoffice_url'] ?? '') ?>" placeholder="Ej: https://office.midominio.cl">
            <div class="form-text">Debe ser accesible desde los usuarios y desde este servidor.</div>
          </div>
          <div class="col-12">
            <label class="form-label">URL de esta plataforma para OnlyOffice</label>
            <input name="onlyoffice_app_url" class="form-control" value="<?= $h($cfg['onlyoffice_app_url'] ?? '') ?>" placeholder="Ej: http://10.63.123.250/redmine-mantencion">
            <div class="form-text">Debe ser accesible desde el contenedor OnlyOffice para descargar archivos y guardar cambios.</div>
          </div>
          <div class="col-12">
            <label class="form-label">JWT secret OnlyOffice</label>
            <input name="onlyoffice_jwt_secret" class="form-control" value="" autocomplete="off" placeholder="<?= !empty($cfg['onlyoffice_jwt_secret']) ? 'JWT configurado. Escribe uno nuevo para reemplazarlo.' : 'Opcional si tu Document Server no usa JWT' ?>">
            <div class="form-text">Si OnlyOffice tiene JWT activo, esta clave debe ser la misma del Document Server.</div>
          </div>
          <div class="col-12">
            <label class="form-label">URL issues.json</label>
            <input name="platform_url" class="form-control" value="<?= $h($cfg['platform_url'] ?? '') ?>" required>
            <div class="form-text">Se usa como base para sincronizar categor&iacute;as.</div>
          </div>
          <div class="col-12">
            <label class="form-label">URL categor&iacute;as (opcional)</label>
            <input name="categories_url" class="form-control" value="<?= $h($cfg['categories_url'] ?? '') ?>" placeholder="Ej: https://tu-host/projects/xxx/settings/categories">
            <div class="form-text">Puede ser `issue_categories.json` o la nueva ruta `settings/categories`.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Token API (X-Redmine-API-Key)</label>
            <input name="platform_token" class="form-control" value="" autocomplete="off" placeholder="<?= !empty($cfg['platform_token']) ? 'Token configurado. Escribe uno nuevo para reemplazarlo.' : 'Opcional, puede venir del usuario asignado' ?>">
            <div class="form-text">Por seguridad no se muestra el token guardado. Deja este campo vacio para conservarlo.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Solicitante (ID)</label>
            <input name="cf_solicitante" class="form-control" value="<?= $h($cfg['cf_solicitante'] ?? '') ?>" placeholder="Ej: 3">
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Unidad (ID)</label>
            <input name="cf_unidad" class="form-control" value="<?= $h($cfg['cf_unidad'] ?? '') ?>" placeholder="Ej: 5">
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Unidad solicitante (ID)</label>
            <input name="cf_unidad_solicitante" class="form-control" value="<?= $h($cfg['cf_unidad_solicitante'] ?? '') ?>" placeholder="Ej: 11">
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Horas extra (ID)</label>
            <input name="cf_hora_extra" class="form-control" value="<?= $h($cfg['cf_hora_extra'] ?? '') ?>" placeholder="Ej: 12">
          </div>
          <div class="col-md-4">
            <label class="form-label">Tiempo estimado HE</label>
            <input name="hora_extra_tiempo_estimado" class="form-control" value="<?= $h($cfg['hora_extra_tiempo_estimado'] ?? '1') ?>" placeholder="Ej: 1">
            <div class="form-text">Valor aplicado al marcar hora extra desde el dashboard.</div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Proyecto -->
<div class="modal fade" id="proyectoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Proyecto</h5>
          <div class="text-muted small">Proyecto, campos y estado inicial por defecto</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" onclick="(function(){var el=document.getElementById('usuariosModal');if(!el)return;el.classList.remove('show');el.style.display='none';el.setAttribute('aria-hidden','true');var bd=document.getElementById('backdrop-usuarios');if(bd)bd.remove();document.body.classList.remove('modal-open');})();"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12">
            <label class="form-label">Project ID</label>
            <input name="project_id" class="form-control" value="<?= $h($cfg['project_id'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Nombre del proyecto</label>
            <input name="project_name" class="form-control" value="<?= $h($cfg['project_name'] ?? '') ?>" placeholder="Solo referencia visual">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Retencion -->
<div class="modal fade" id="RetencionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Retencion de procesados</h5>
          <div class="text-muted small">Define cuántas horas se conservan los mensajes procesados</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12">
            <label class="form-label">Horas antes de borrar procesados</label>
            <input type="number" min="1" name="retencion_horas" class="form-control" value="<?= $h($cfg['retencion_horas'] ?? 24) ?>">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Sesion -->
<div class="modal fade" id="SesionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Tiempo de sesión</h5>
          <div class="text-muted small">Segundos de inactividad antes de cerrar sesión</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12">
            <label class="form-label">Segundos de inactividad antes de cerrar Sesion</label>
            <input type="number" min="60" step="30" name="session_timeout" class="form-control" value="<?= $h($cfg['session_timeout'] ?? 300) ?>">
            <div class="form-text">Mnimo 60 segundos.</div>
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($canManageRoles || $canManageUsers):
  $rolesList = array_keys($rolesData ?: []);
  sort($rolesList);
  if ($canManageRoles):
    $selCfg = $rolesData[$selectedRole] ?? [];
    if (!isset($selCfg['mensajes_acceso'])) $selCfg['mensajes_acceso'] = true;
    $scopeHist = $selCfg['historico_scope'] ?? 'asignados';
    $scopeMsg = $selCfg['mensajes'] ?? 'asignados';
    $scopeHoras = $selCfg['horas_extra'] ?? 'asignados';
  endif;
  if ($canManageUsers):
    // Datos para modal de usuarios
    $usersList = [];
    foreach ($usuariosData as $u) {
      if (!isset($u['id'])) continue;
      $label = ($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '') . ' (ID ' . $u['id'] . ')';
      $usersList[(string)$u['id']] = trim($label);
    }
    ksort($usersList);
    $selUserData = $selectedUser !== '' && isset($usuariosIndex[$selectedUser]) ? $usuariosIndex[$selectedUser] : null;
    $selUserRole = $selUserData['rol'] ?? '';
    $selUserPerms = $selUserData['permisos'] ?? null;
    $roleDefaults = $selUserRole && isset($rolesData[$selUserRole]) ? $rolesData[$selUserRole] : [];
    $uCfg = is_array($selUserPerms) ? array_replace($roleDefaults, $selUserPerms) : $roleDefaults;
    $uScopeMsg = $uCfg['mensajes'] ?? 'asignados';
    $uScopeHoras = $uCfg['horas_extra'] ?? 'asignados';
    $uScopeHist = $uCfg['historico_scope'] ?? 'asignados';
    $uHistAcciones = !empty($uCfg['historico_acciones']);
    $uHas = fn($k) => !empty($uCfg[$k]);
  endif;
?>
<?php if ($canManageUsers): ?>
<!-- Modal Usuarios y permisos -->
<div class="modal fade" id="usuariosModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Usuarios y permisos</h5>
          <div class="text-muted small">Rol asignado, accesos y alcances por usuario</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-3 roles-modal-shell">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" id="user-action" value="save_user_perms">
          <div class="col-md-8">
            <label class="form-label">Usuario</label>
            <input type="hidden" name="user_select" id="user-select" value="<?= $h($selectedUser) ?>">
            <div class="user-picker" id="user-picker">
              <input
                type="search"
                class="form-control"
                id="user-search"
                autocomplete="off"
                placeholder="Buscar por nombre o ID"
                value="<?= $selectedUser !== '' && isset($usersList[$selectedUser]) ? $h($usersList[$selectedUser]) : '' ?>"
              >
              <div class="user-picker-results d-none" id="user-search-results" role="listbox"></div>
            </div>
            <div class="form-text">Escribe nombre o código de usuario y selecciona un resultado.</div>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div>
              <div class="form-label">Rol asignado</div>
              <span class="badge bg-info-subtle text-primary" id="user-role-badge"><?= $selUserRole !== '' ? $h($selUserRole) : 'N/D' ?></span>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cambiar rol</label>
            <select name="u_role" class="form-select" id="u-role">
              <option value="">(mantener)</option>
              <?php foreach ($rolesList as $r): ?>
                <option value="<?= $h($r) ?>" <?= $selUserRole === $r ? 'selected' : '' ?>><?= ucfirst($h($r)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 permission-scope-section">
            <label class="form-label mb-1">Alcance</label>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small mb-1">Reportes</label>
                <select name="u_mensajes_scope" class="form-select">
                  <option value="todos" <?= $uScopeMsg === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $uScopeMsg === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Horas extra</label>
                <select name="u_horas_scope" class="form-select">
                  <option value="todos" <?= $uScopeHoras === 'todos' ? 'selected' : '' ?>>Ver todas</option>
                  <option value="asignados" <?= $uScopeHoras === 'asignados' ? 'selected' : '' ?>>Solo asignadas</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Hist&oacute;rico</label>
                <select name="u_historico_scope" class="form-select">
                  <option value="todos" <?= $uScopeHist === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $uScopeHist === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
            </div>
          </div>
          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Accesos a vistas</label>
            <div class="permission-section-help">Activa primero el permiso para ver la vista. Los permisos marcados como adicionales habilitan acciones dentro de esa vista.</div>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_mensajes" id="u_perm_mensajes_chk" <?= $uHas('mensajes_acceso') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_mensajes_chk">Reportes</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_horas_extra" id="u_perm_horas_extra_chk" <?= !empty($uCfg['horas_extra']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_horas_extra_chk">Horas extra</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_estadisticas" id="u_perm_estadisticas_chk" <?= $uHas('estadisticas') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_estadisticas_chk">Estad&iacute;sticas</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_usuarios" id="u_perm_usuarios_chk" <?= $uHas('usuarios') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_usuarios_chk">Usuarios</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_categorias" id="u_perm_categorias_chk" <?= $uHas('categorias') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_categorias_chk">Categor&iacute;as</label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_historico" id="u_perm_historico_chk" <?= $uHas('historico') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_historico_chk">Hist&oacute;rico</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="u_perm_historico_acciones" id="u_perm_historico_acciones_chk" <?= $uHistAcciones ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_historico_acciones_chk">Ver acciones en hist&oacute;rico <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Hist&oacute;rico.</span></label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_procedimientos" id="u_perm_procedimientos_chk" <?= $uHas('procedimientos') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_procedimientos_chk">Ver procedimientos</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="u_perm_procedimientos_editar" id="u_perm_procedimientos_editar_chk" <?= $uHas('procedimientos_editar') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_procedimientos_editar_chk">Editar y eliminar procedimientos <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Ver procedimientos.</span></label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_simulador" id="u_perm_simulador_chk" <?= $uHas('simulador') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_simulador_chk">Ingresar pendiente manual</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_actividad" id="u_perm_actividad_chk" <?= $uHas('actividad') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_actividad_chk">Actividad reciente</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_configuracion" id="u_perm_configuracion_chk" <?= $uHas('configuracion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_configuracion_chk">Configuraci&oacute;n</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Permisos de configuraci&oacute;n</label>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_conexion" id="u_perm_cfg_conexion" <?= $uHas('cfg_conexion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_conexion">Conexi&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_proyecto" id="u_perm_cfg_proyecto" <?= $uHas('cfg_proyecto') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_proyecto">Proyecto</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_retencion" id="u_perm_cfg_retencion" <?= $uHas('cfg_retencion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_retencion">Retenci&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_sesion" id="u_perm_cfg_sesion" <?= $uHas('cfg_sesion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_sesion">Sesi&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_trackers" id="u_perm_cfg_trackers" <?= $uHas('cfg_trackers') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_trackers">Trackers</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_prioridades" id="u_perm_cfg_prioridades" <?= $uHas('cfg_prioridades') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_prioridades">Prioridades</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_estados" id="u_perm_cfg_estados" <?= $uHas('cfg_estados') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_estados">Estados</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_roles" id="u_perm_cfg_roles" <?= $uHas('cfg_roles') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_roles">Roles y permisos</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_usuarios" id="u_perm_cfg_usuarios" <?= $uHas('cfg_usuarios') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_usuarios">Usuarios y permisos</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 text-end">
            <button class="btn btn-primary" type="submit" id="btn-save-user-perms">Guardar permisos</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canManageRoles): ?>
<div class="modal fade" id="rolesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Roles y permisos</h5>
          <div class="text-muted small">Define accesos y alcances por rol</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" class="row g-3 roles-modal-shell">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" id="roles-action" value="save_roles">
          <div class="col-md-6">
            <label class="form-label">Rol</label>
            <select name="role_select" class="form-select" id="role-select">
              <?php foreach ($rolesList as $r): ?>
                <option value="<?= $h($r) ?>" <?= $selectedRole === $r ? 'selected' : '' ?>><?= ucfirst($h($r)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Para crear un rol nuevo, escribe el nombre y guarda.</div>
            <input type="text" class="form-control mt-2" name="new_role" placeholder="Nuevo rol (opcional)" value="">
          </div>
          <div class="col-12 permission-scope-section">
            <label class="form-label mb-1">Alcance</label>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small mb-1">Reportes</label>
                <select name="mensajes_scope" class="form-select">
                  <option value="todos" <?= $scopeMsg === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $scopeMsg === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Horas extra</label>
                <select name="horas_scope" class="form-select">
                  <option value="todos" <?= $scopeHoras === 'todos' ? 'selected' : '' ?>>Ver todas</option>
                  <option value="asignados" <?= $scopeHoras === 'asignados' ? 'selected' : '' ?>>Solo asignadas</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Hist&oacute;rico</label>
                <select name="historico_scope" class="form-select">
                  <option value="todos" <?= $scopeHist === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $scopeHist === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
            </div>
          </div>
          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Accesos a vistas</label>
            <div class="permission-section-help">Activa primero el permiso para ver la vista. Los permisos marcados como adicionales habilitan acciones dentro de esa vista.</div>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_mensajes" id="permMsg" <?= !empty($selCfg['mensajes_acceso']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permMsg">Reportes</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_horas_extra" id="permHorasExtra" <?= !empty($selCfg['horas_extra']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHorasExtra">Horas extra</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_estadisticas" id="permEst" <?= !empty($selCfg['estadisticas']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permEst">Estad&iacute;sticas</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_usuarios" id="permUsr" <?= !empty($selCfg['usuarios']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permUsr">Usuarios</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_categorias" id="permCat" <?= !empty($selCfg['categorias']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCat">Categorias</label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_historico" id="permHist" <?= !empty($selCfg['historico']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHist">Hist&oacute;rico</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="perm_historico_acciones" id="permHistAcc" <?= !empty($selCfg['historico_acciones']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHistAcc">Ver acciones en hist&oacute;rico <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Hist&oacute;rico.</span></label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_procedimientos" id="permProc" <?= !empty($selCfg['procedimientos']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permProc">Ver procedimientos</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="perm_procedimientos_editar" id="permProcEdit" <?= !empty($selCfg['procedimientos_editar']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permProcEdit">Editar y eliminar procedimientos <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Ver procedimientos.</span></label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_simulador" id="permSim" <?= !empty($selCfg['simulador']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permSim">Ingresar pendiente manual</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_actividad" id="permActividad" <?= !empty($selCfg['actividad']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permActividad">Actividad reciente</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_configuracion" id="permCfg" <?= !empty($selCfg['configuracion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfg">Configuraci&oacute;n</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Permisos de configuraci&oacute;n</label>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_conexion" id="permCfgConexion" <?= !empty($selCfg['cfg_conexion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgConexion">Conexi&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_proyecto" id="permCfgProyecto" <?= !empty($selCfg['cfg_proyecto']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgProyecto">Proyecto</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_retencion" id="permCfgRetencion" <?= !empty($selCfg['cfg_retencion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgRetencion">Retenci&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_sesion" id="permCfgSesion" <?= !empty($selCfg['cfg_sesion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgSesion">Sesi&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_trackers" id="permCfgTrackers" <?= !empty($selCfg['cfg_trackers']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgTrackers">Trackers</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_prioridades" id="permCfgPrioridades" <?= !empty($selCfg['cfg_prioridades']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgPrioridades">Prioridades</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_estados" id="permCfgEstados" <?= !empty($selCfg['cfg_estados']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgEstados">Estados</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_roles" id="permCfgRoles" <?= !empty($selCfg['cfg_roles']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgRoles">Roles y permisos</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_usuarios" id="permCfgUsuarios" <?= !empty($selCfg['cfg_usuarios']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgUsuarios">Usuarios y permisos</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 text-end">
            <button class="btn btn-primary" type="submit" id="btn-save-roles">Guardar permisos</button>
         
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const configMaintenanceMode = <?= $maintenanceMode ? 'true' : 'false' ?>;
  if (configMaintenanceMode) {
    document.querySelectorAll('form').forEach(form => {
      const actionInput = form.querySelector('[name="action"]');
      const action = actionInput ? actionInput.value : '';
      const allowed = form.closest('#maintenanceModal') && ['maintenance_settings', 'maintenance_export'].includes(action);
      if (!allowed) {
        form.querySelectorAll('input, select, textarea, button').forEach(control => {
          const isModalClose = control.matches('[data-bs-dismiss="modal"]');
          const isSearchField = control.matches('#user-search, #cat-filter');
          const isLoadOnlyAction = action === 'load_role' || action === 'load_user_perms';
          if (isModalClose || isSearchField || isLoadOnlyAction) {
            return;
          }
          control.disabled = true;
          control.title = 'Plataforma en mantencion';
        });
      }
    });
  }
  const maintenanceModeCheck = document.getElementById('maintenance-mode-check');
  const maintenanceUntilInput = document.querySelector('input[name="maintenance_until"]');
  if (maintenanceModeCheck && maintenanceUntilInput) {
    maintenanceModeCheck.addEventListener('change', () => {
      if (!maintenanceModeCheck.checked) {
        maintenanceUntilInput.value = '';
      }
    });
  }
  const getModal = (id) => {
    const el = document.getElementById(id);
    if (!el || !window.bootstrap || !window.bootstrap.Modal) return null;
    return window.bootstrap.Modal.getOrCreateInstance(el);
  };
  const rolesEl = document.getElementById('rolesModal');
  const usuariosEl = document.getElementById('usuariosModal');
  const rolesModal = getModal('rolesModal');
  const usuariosModal = getModal('usuariosModal');
  // Filtros en tablas de categorias
  const catFilter = document.getElementById('cat-filter');
  const catTable = document.getElementById('cat-table');
  if (catFilter && catTable) {
    catFilter.addEventListener('input', () => {
      const term = catFilter.value.toLowerCase();
      catTable.querySelectorAll('tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  }
  // Mitigar warning de aria-hidden al cerrar: limpiar foco antes de ocultar
  [rolesEl, usuariosEl].forEach(el => {
    if (!el || !window.bootstrap || !window.bootstrap.Modal) return;
    el.addEventListener('hide.bs.modal', () => {
      if (document.activeElement) document.activeElement.blur();
    });
  });

  // Auto abrir si corresponde
  <?php if (!empty($openRolesModal) && $openRolesModal): ?>
    if (rolesModal) rolesModal.show();
  <?php endif; ?>
  <?php if (!empty($openUsersModal) && $openUsersModal): ?>
    if (usuariosModal) usuariosModal.show();
  <?php endif; ?>

  // Recarga en cambios de selects
  const selRole = document.getElementById('role-select');
  const actRole = document.getElementById('roles-action');
  const formRole = document.querySelector('#rolesModal form');
  const depInputs = ['permCat'].map(id => document.getElementById(id));
  const cfgConexion = document.getElementById('permCfg');
  const bindPermissionDependency = (parentId, childId) => {
    const parent = document.getElementById(parentId);
    const child = document.getElementById(childId);
    if (!parent || !child) return;
    const sync = () => {
      if (!parent.checked) {
        child.checked = false;
      }
      child.disabled = !parent.checked;
      const wrapper = child.closest('.permission-child');
      if (wrapper) {
        wrapper.classList.toggle('d-none', !parent.checked);
      }
    };
    parent.addEventListener('change', sync);
    sync();
  };
  bindPermissionDependency('permHist', 'permHistAcc');
  bindPermissionDependency('permProc', 'permProcEdit');
  bindPermissionDependency('u_perm_historico_chk', 'u_perm_historico_acciones_chk');
  bindPermissionDependency('u_perm_procedimientos_chk', 'u_perm_procedimientos_editar_chk');
  if (selRole && actRole && formRole) {
    selRole.addEventListener('change', () => {
      actRole.value = 'load_role';
      formRole.submit();
    });
    const btnSaveRoles = document.getElementById('btn-save-roles');
    if (btnSaveRoles) {
      btnSaveRoles.addEventListener('click', () => {
        actRole.value = 'save_roles';
      });
    }
    if (rolesEl) {
      rolesEl.addEventListener('shown.bs.modal', () => {
        actRole.value = 'save_roles';
      });
    }
  }
  const ensureCfgConexion = () => {
    if (!cfgConexion) return;
    const anyCatUni = depInputs.some(el => el && el.checked);
    if (anyCatUni) cfgConexion.checked = true;
  };
  depInputs.forEach(el => { if (el) el.addEventListener('change', ensureCfgConexion); });
  ensureCfgConexion();
  // Si viene mensaje de sincronización de cat: mostrar en modal y reabrirlo
  const params = new URLSearchParams(window.location.search);
  if (params.has('synccat')) {
    const msg = decodeURIComponent(params.get('synccat'));
    const msgEl = document.getElementById('cat-sync-msg');
    if (msgEl) {
      msgEl.textContent = msg;
      msgEl.classList.remove('d-none');
    }
    const catModalEl = document.getElementById('categoriasModal');
    if (catModalEl && window.bootstrap) {
      const catModal = new bootstrap.Modal(catModalEl);
      catModal.show();
    }
    // limpiar query para no repetir en siguientes navegaciones
    params.delete('synccat');
    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', newUrl);
  }
  const selUser = document.getElementById('user-select');
  const userSearch = document.getElementById('user-search');
  const userResults = document.getElementById('user-search-results');
  const actUser = document.getElementById('user-action');
  const formUser = document.querySelector('#usuariosModal form');
  const usersForPicker = [
    <?php if ($canManageUsers): ?>
      <?php foreach ($usersList as $uid => $uname): ?>
        { id: <?= json_encode((string)$uid, JSON_UNESCAPED_UNICODE) ?>, label: <?= json_encode((string)$uname, JSON_UNESCAPED_UNICODE) ?> },
      <?php endforeach; ?>
    <?php endif; ?>
  ];
  const normalizePickerText = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
  const hideUserResults = () => {
    if (userResults) userResults.classList.add('d-none');
  };
  const renderUserResults = () => {
    if (!userSearch || !userResults) return;
    const term = normalizePickerText(userSearch.value.trim());
    userResults.innerHTML = '';
    if (term.length === 0) {
      hideUserResults();
      return;
    }
    const matches = usersForPicker
      .filter(user => normalizePickerText(user.label).includes(term) || normalizePickerText(user.id).includes(term))
      .slice(0, 20);
    if (matches.length === 0) {
      userResults.innerHTML = '<div class="user-picker-empty">Sin usuarios encontrados</div>';
      userResults.classList.remove('d-none');
      return;
    }
    matches.forEach(user => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'user-picker-option';
      btn.innerHTML = `<span>${user.label}</span><span class="user-picker-id">ID ${user.id}</span>`;
      btn.addEventListener('click', () => {
        selUser.value = user.id;
        userSearch.value = user.label;
        hideUserResults();
        if (actUser && formUser) {
          actUser.value = 'load_user_perms';
          formUser.submit();
        }
      });
      userResults.appendChild(btn);
    });
    userResults.classList.remove('d-none');
  };
  if (userSearch && userResults && selUser) {
    userSearch.addEventListener('input', () => {
      selUser.value = '';
      renderUserResults();
    });
    userSearch.addEventListener('focus', renderUserResults);
    document.addEventListener('click', (event) => {
      if (!event.target.closest('#user-picker')) {
        hideUserResults();
      }
    });
  }
  if (selUser && actUser && formUser) {
    formUser.addEventListener('submit', () => {
      actUser.value = 'save_user_perms';
    });
  }
});
</script>
</div> <!-- #page-content -->
</body>
</html>
