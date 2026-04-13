<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root','administrador','gestor'], '/redmine/login.php');
require_once __DIR__ . '/../../controllers/configuracion.php';
[$cfg, $flash, $opts] = handle_configuracion();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$role = auth_get_user_role();
$onlyCatalogs = ($role === 'administrador');
$csrf = csrf_token();

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
$categoriasData = [];
$categoriasFile = __DIR__ . '/../../data/categorias.json';
if (file_exists($categoriasFile)) {
  $categoriasData = json_decode(file_get_contents($categoriasFile), true);
  if (!is_array($categoriasData)) $categoriasData = [];
}
$unidadesData = [];
$unidadesFile = __DIR__ . '/../../data/unidades.json';
if (file_exists($unidadesFile)) {
  $unidadesData = json_decode(file_get_contents($unidadesFile), true);
  if (!is_array($unidadesData)) $unidadesData = [];
}

// Utilidad para sincronizar unidades (CF 11) desde API
$syncUnidades = function() use ($cfg, $usuariosFile, $unidadesFile, $h) {
  $platformUrl = $cfg['platform_url'] ?? '';
  $apiKey = $cfg['platform_token'] ?? '';
  // token de usuario si existe (prioritario)
  $userToken = '';
  if (file_exists($usuariosFile) && function_exists('auth_get_user_id')) {
    $uid = auth_get_user_id();
    $users = json_decode(@file_get_contents($usuariosFile), true);
    if (is_array($users)) {
      foreach ($users as $u) {
        if (!is_array($u)) continue;
        if ((string)($u['id'] ?? '') === (string)$uid && !empty($u['api'])) {
          $userToken = $u['api'];
          break;
        }
      }
    }
  }
  if ($userToken) $apiKey = $userToken;
  $cfUrl = '';
  if (!empty($cfg['unidades_url'])) {
    $cfUrl = $cfg['unidades_url'];
  } elseif ($platformUrl) {
    $parts = parse_url($platformUrl);
    $prefix = '';
    if (!empty($parts['path']) && strpos($parts['path'], '/gp/') !== false) {
      $prefix = '/gp';
    }
    if (preg_match('#/issues(?:\\.json)?$#', $platformUrl)) {
      $cfUrl = preg_replace('#/projects/[^/]+/issues(?:\\.json)?$#', $prefix . '/custom_fields/11.json', $platformUrl);
    } elseif ($parts && !empty($parts['scheme']) && !empty($parts['host'])) {
      $port = isset($parts['port']) ? ':' . $parts['port'] : '';
      $cfUrl = $parts['scheme'] . '://' . $parts['host'] . $port . $prefix . '/custom_fields/11.json';
    }
  }
  if (!$cfUrl) return ['error' => 'Falta platform_url o unidades_url en configuración.'];
  if (!$apiKey) return ['error' => 'Falta token de API (usuario o plataforma).'];

  $ch = curl_init($cfUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'X-Redmine-API-Key: ' . $apiKey,
      'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['error' => "No se pudo conectar: $err (URL: $cfUrl)"];
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code >= 400) {
    return ['error' => "HTTP $code al consultar el campo personalizado. URL: $cfUrl"];
  }
  $json = json_decode($resp, true);
  // soportar respuesta /custom_fields/11.json y /custom_fields.json
  $values = [];
  if (isset($json['custom_field']['possible_values'])) {
    $values = $json['custom_field']['possible_values'];
  } elseif (isset($json['custom_fields']) && is_array($json['custom_fields'])) {
    foreach ($json['custom_fields'] as $cf) {
      if (!is_array($cf)) continue;
      if ((string)($cf['id'] ?? '') === '11' && isset($cf['possible_values'])) {
        $values = $cf['possible_values'];
        break;
      }
    }
  }
  if (!is_array($values)) return ['error' => 'La respuesta no contiene possible_values.'];
  $parsed = [];
  foreach ($values as $v) {
    if (is_array($v) && isset($v['value'])) {
      $parsed[] = ['id' => $v['value'], 'nombre' => $v['value']];
    } elseif (is_string($v)) {
      $parsed[] = ['id' => $v, 'nombre' => $v];
    }
  }
  file_put_contents($unidadesFile, json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  return ['ok' => count($parsed)];
};
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
      'configuracion' => true,
      'estadisticas' => true,
      'estadisticas_manual' => true,
      'usuarios' => true,
      'categorias' => true,
      'unidades' => true,
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
      'configuracion' => true,
      'estadisticas' => true,
      'estadisticas_manual' => true,
      'usuarios' => true,
      'categorias' => true,
      'unidades' => true,
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
      'configuracion' => true,
      'estadisticas' => true,
      'estadisticas_manual' => true,
      'usuarios' => false,
      'categorias' => true,
      'unidades' => true,
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
      'configuracion' => false,
      'estadisticas' => false,
      'estadisticas_manual' => false,
      'usuarios' => false,
      'categorias' => false,
      'unidades' => false,
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
          'configuracion' => true,
          'estadisticas' => true,
          'estadisticas_manual' => true,
          'usuarios' => true,
          'categorias' => true,
          'unidades' => true,
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
        $rolesData[$selectedRole] = [
          'mensajes' => ($_POST['mensajes_scope'] ?? 'asignados'),
          'mensajes_acceso' => isset($_POST['perm_mensajes']),
          'horas_extra' => isset($_POST['perm_horas_extra']) ? ($_POST['horas_scope'] ?? 'asignados') : '',
          'historico' => isset($_POST['perm_historico']),
          'historico_scope' => ($_POST['historico_scope'] ?? 'asignados'),
          'historico_acciones' => isset($_POST['perm_historico_acciones']),
          'configuracion' => isset($_POST['perm_configuracion']),
          'estadisticas' => isset($_POST['perm_estadisticas']),
          'estadisticas_manual' => isset($_POST['perm_estadisticas_manual']),
          'usuarios' => isset($_POST['perm_usuarios']),
          'categorias' => isset($_POST['perm_categorias']),
          'unidades' => isset($_POST['perm_unidades']),
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
      file_put_contents($rolesFile, json_encode($rolesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
      $cfgUser = [
        'mensajes' => ($_POST['u_mensajes_scope'] ?? 'asignados'),
        'mensajes_acceso' => isset($_POST['u_perm_mensajes']),
        'horas_extra' => isset($_POST['u_perm_horas_extra']) ? ($_POST['u_horas_scope'] ?? 'asignados') : '',
        'historico' => isset($_POST['u_perm_historico']),
        'historico_acciones' => isset($_POST['u_perm_historico_acciones']),
        'historico_scope' => ($_POST['u_historico_scope'] ?? 'asignados'),
        'configuracion' => isset($_POST['u_perm_configuracion']),
        'estadisticas' => isset($_POST['u_perm_estadisticas']),
        'estadisticas_manual' => isset($_POST['u_perm_estadisticas_manual']),
        'usuarios' => isset($_POST['u_perm_usuarios']),
        'categorias' => isset($_POST['u_perm_categorias']),
        'unidades' => isset($_POST['u_perm_unidades']),
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
      file_put_contents($usuariosFile, json_encode($usuariosData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $flashUsuarios = 'Permisos actualizados para el usuario ID ' . $h($selectedUser);
      $openUsersModal = true;
    }
  }
}
// Sync unidades desde modal (visible para roles con configuracion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_unidades') {
  if (function_exists('csrf_validate')) csrf_validate();
  $res = $syncUnidades();
  $msg = isset($res['error']) ? $res['error'] : ('Unidades sincronizadas (' . ($res['ok'] ?? 0) . ' registros).');
  header('Location: /redmine/views/Configuracion/configuracion.php?synuni=' . urlencode($msg));
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>configuracion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine/assets/theme.css" rel="stylesheet">
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
    .table thead th { font-weight: 700; text-transform: uppercase; font-size: .78rem; letter-spacing: .02em; }
  </style>
  <style>
    body { background: #f6f8fb; }
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
    .card { border: none; box-shadow: 0 12px 30px rgba(17,24,39,0.08); border-radius: 16px; }
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
    <div class="col-12 col-md-6 col-lg-4">
      <button class="cfg-btn d-flex align-items-center justify-content-between gap-2" type="button" data-bs-toggle="modal" data-bs-target="#unidadesModal">
        <span class="d-flex align-items-center gap-2"><i class="bi bi-building text-primary"></i>Unidades</span>
        <i class="bi bi-arrow-right-short"></i>
      </button>
    </div>
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
            <?php foreach ($items as $o): ?>
              <tr>
                <form method="post" class="d-flex align-items-center gap-2">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
                  <input type="hidden" name="opt_action" value="update">
                  <td style="width:120px"><input name="opt_id" class="form-control form-control-sm" value="<?= $h($o['id']) ?>" readonly></td>
                  <td><input name="opt_nombre" class="form-control form-control-sm" value="<?= $h($o['nombre']) ?>"></td>
                  <td class="text-center"><input class="form-check-input" type="checkbox" name="opt_default" <?= !empty($o['default']) ? 'checked' : '' ?>></td>
                  <td class="d-flex gap-2">
                    <button class="btn btn-success btn-sm">Guardar</button>
                </form>
                <form method="post" onsubmit="return confirm('Eliminar?')" class="m-0">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
                  <input type="hidden" name="opt_action" value="delete">
                  <input type="hidden" name="opt_id" value="<?= $h($o['id']) ?>">
                  <button class="btn btn-danger btn-sm">Eliminar</button>
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
          <div class="text-muted small">Sincronizadas desde Redmine (issue_categories.json). Usa el bot&oacute;n para actualizar.</div>
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
            <form action="../Categorias/categorias.php" method="post" class="m-0 d-inline" id="sync-cat-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="sync_remote">
              <button class="btn btn-primary btn-icon" type="submit"><i class="bi bi-arrow-repeat"></i> Actualizar desde API</button>
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

<!-- Modal Unidades -->
<div class="modal fade" id="unidadesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-top">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Unidades solicitantes (solo lectura)</h5>
          <div class="text-muted small">Sincronizadas desde custom_fields/11.json. Usa el bot&oacute;n para actualizar.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body modal-body-tight">
        <div id="uni-sync-msg" class="alert alert-success d-none"></div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:260px;">
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;"><i class="bi bi-search"></i></div>
            <input id="uni-filter" class="form-control" placeholder="Buscar unidad (ID o nombre)">
            <span class="badge bg-light text-dark border">Total: <?= $h(is_array($unidadesData) ? count($unidadesData) : 0) ?></span>
          </div>
          <div class="d-flex gap-2">
            <form action="../Configuracion/configuracion.php" method="post" class="m-0 d-inline" id="sync-uni-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="sync_unidades">
              <button class="btn btn-primary btn-icon" type="submit"><i class="bi bi-arrow-repeat"></i> Actualizar desde API</button>
            </form>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-striped align-middle" id="uni-table">
            <thead class="table-light"><tr><th style="width:140px;">ID</th><th>Nombre</th></tr></thead>
            <tbody>
              <?php if ($unidadesData): foreach ($unidadesData as $u): ?>
                <tr>
                  <td class="text-muted"><?= $h($u['id'] ?? '') ?></td>
                  <td><?= $h($u['nombre'] ?? '') ?></td>
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
            <label class="form-label">URL issues.json</label>
            <input name="platform_url" class="form-control" value="<?= $h($cfg['platform_url'] ?? '') ?>" required>
            <div class="form-text">Se usa como base para sincronizar categor&iacute;as (issue_categories.json) y unidades (custom_fields/11.json).</div>
          </div>
          <div class="col-12">
            <label class="form-label">URL categor&iacute;as (opcional)</label>
            <input name="categories_url" class="form-control" value="<?= $h($cfg['categories_url'] ?? '') ?>" placeholder="Ej: https://tu-host/projects/xxx/issue_categories.json">
            <div class="form-text">Si se deja vac&iacute;o, se deriva desde la URL base.</div>
          </div>
          <div class="col-12">
            <label class="form-label">URL unidades solicitantes (opcional)</label>
            <input name="unidades_url" class="form-control" value="<?= $h($cfg['unidades_url'] ?? '') ?>" placeholder="Ej: https://tu-host/custom_fields/11.json">
            <div class="form-text">Si se deja vac&iacute;o, se deriva desde la URL base.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Token API (X-Redmine-API-Key)</label>
            <input name="platform_token" class="form-control" value="<?= $h($cfg['platform_token'] ?? '') ?>" placeholder="Opcional, puede venir del usuario asignado">
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
    $uCfg = is_array($selUserPerms) ? $selUserPerms : $roleDefaults;
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
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" id="user-action" value="save_user_perms">
          <div class="col-md-8">
            <label class="form-label">Usuario</label>
            <select name="user_select" class="form-select" id="user-select">
              <option value="">Seleccione usuario</option>
              <?php foreach ($usersList as $uid => $uname): ?>
                <option value="<?= $h($uid) ?>" <?= $selectedUser === (string)$uid ? 'selected' : '' ?>><?= $h($uname) ?></option>
              <?php endforeach; ?>
            </select>
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
          <div class="col-12">
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
          <div class="col-12">
            <label class="form-label mb-1">Accesos a vistas</label>
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
                  <!-- Controla el acceso al panel manual de estadísticas -->
                  <input class="form-check-input" type="checkbox" name="u_perm_estadisticas_manual" id="u_perm_estadisticas_manual_chk" <?= $uHas('estadisticas_manual') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_estadisticas_manual_chk">Estad&iacute;sticas manuales (Redmine API)</label>
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
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_unidades" id="u_perm_unidades_chk" <?= $uHas('unidades') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_unidades_chk">Unidades</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_historico" id="u_perm_historico_chk" <?= $uHas('historico') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_historico_chk">Hist&oacute;rico</label>
                </div>
                <div class="form-check ms-3 mt-1">
                  <input class="form-check-input" type="checkbox" name="u_perm_historico_acciones" id="u_perm_historico_acciones_chk" <?= $uHistAcciones ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_historico_acciones_chk">Ver acciones en hist&oacute;rico</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_simulador" id="u_perm_simulador_chk" <?= $uHas('simulador') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_simulador_chk">Simular webhook</label>
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

          <div class="col-12">
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
        <form method="post" class="row g-3">
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
          <div class="col-12">
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
          <div class="col-12">
            <label class="form-label mb-1">Accesos a vistas</label>
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
                  <!-- Permite mostrar el enlace a estadísticas manuales en la barra -->
                  <input class="form-check-input" type="checkbox" name="perm_estadisticas_manual" id="permEstManual" <?= !empty($selCfg['estadisticas_manual']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permEstManual">Estad&iacute;sticas manuales (Redmine API)</label>
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
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_unidades" id="permUni" <?= !empty($selCfg['unidades']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permUni">Unidades</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_historico" id="permHist" <?= !empty($selCfg['historico']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHist">Hist&oacute;rico</label>
                </div>
                <div class="form-check ms-3 mt-1">
                  <input class="form-check-input" type="checkbox" name="perm_historico_acciones" id="permHistAcc" <?= !empty($selCfg['historico_acciones']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHistAcc">Ver acciones en hist&oacute;rico</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_simulador" id="permSim" <?= !empty($selCfg['simulador']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permSim">Simular webhook</label>
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

          <div class="col-12">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const getModal = (id) => {
    const el = document.getElementById(id);
    if (!el || !window.bootstrap || !window.bootstrap.Modal) return null;
    return window.bootstrap.Modal.getOrCreateInstance(el);
  };
  const rolesEl = document.getElementById('rolesModal');
  const usuariosEl = document.getElementById('usuariosModal');
  const rolesModal = getModal('rolesModal');
  const usuariosModal = getModal('usuariosModal');
  // Filtros en tablas de cat/unidades
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
  const uniFilter = document.getElementById('uni-filter');
  const uniTable = document.getElementById('uni-table');
  if (uniFilter && uniTable) {
    uniFilter.addEventListener('input', () => {
      const term = uniFilter.value.toLowerCase();
      uniTable.querySelectorAll('tbody tr').forEach(tr => {
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
  const depInputs = ['permCat','permUni'].map(id => document.getElementById(id));
  const cfgConexion = document.getElementById('permCfg');
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
  if (params.has('synuni')) {
    const msg = decodeURIComponent(params.get('synuni'));
    const msgEl = document.getElementById('uni-sync-msg');
    if (msgEl) {
      msgEl.textContent = msg;
      msgEl.classList.remove('d-none');
    }
    const uniModalEl = document.getElementById('unidadesModal');
    if (uniModalEl && window.bootstrap) {
      const uniModal = new bootstrap.Modal(uniModalEl);
      uniModal.show();
    }
    params.delete('synuni');
    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', newUrl);
  }

  const selUser = document.getElementById('user-select');
  const actUser = document.getElementById('user-action');
  const formUser = document.querySelector('#usuariosModal form');
  if (selUser && actUser && formUser) {
    selUser.addEventListener('change', () => {
      actUser.value = 'load_user_perms';
      formUser.submit();
    });
    formUser.addEventListener('submit', () => {
      actUser.value = 'save_user_perms';
    });
  }
});
</script>
</div> <!-- #page-content -->
</body>
</html>










