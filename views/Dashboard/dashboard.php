<?php

require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/dashboard.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flashSession = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$toastSession = $_SESSION['dashboard_toast'] ?? null;
unset($_SESSION['dashboard_toast']);

list($messages, $flash, $securityLog) = handle_request();
if ($flashSession) {
    $flash = $flashSession;
}

$pendientes = array_filter($messages, fn($m) => strtolower($m['estado'] ?? '') === 'pendiente');

$procesados = array_filter($messages, fn($m) => strtolower($m['estado'] ?? '') === 'procesado');

$errores = array_filter($messages, fn($m) => strtolower($m['estado'] ?? '') === 'error');

$cfg = load_platform_config();
$coreDesde = $_GET['core_desde'] ?? date('Y-m-d');
$coreHasta = $_GET['core_hasta'] ?? date('Y-m-d');
$coreAssignedName = $_GET['core_assigned_name'] ?? dashboard_default_core_assigned_name();
$currentRole = auth_get_user_role();
$hasSavedCoreCredentials = dashboard_core_has_saved_credentials();
$maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();

function normalize_phone_key($value) {
    $digits = preg_replace('/\D/', '', $value ?? '');
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) > 9) {
        $digits = substr($digits, -9);
    }
    return $digits;
}

function normalize_rut_key($value) {
    $clean = strtoupper(preg_replace('/[^0-9kK]/', '', $value ?? ''));
    return $clean;
}

function resolve_assigned_name($value, $lookup) {
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return '';
    }
    if (isset($lookup[$value])) {
        return $lookup[$value];
    }
    $phoneKey = normalize_phone_key($value);
    if ($phoneKey !== '' && isset($lookup[$phoneKey])) {
        return $lookup[$phoneKey];
    }
    $rutKey = normalize_rut_key($value);
    if ($rutKey !== '' && isset($lookup[$rutKey])) {
        return $lookup[$rutKey];
    }
    return '';
}

$retencionHoras = get_retencion_horas();



$userOptions = [];
$userLookup = [];
$usersPath = __DIR__ . '/../../data/usuarios.json';
if (file_exists($usersPath)) {
    $rawUsers = file_get_contents($usersPath);
    $parsedUsers = json_decode($rawUsers, true);
    if (is_array($parsedUsers)) {
        foreach ($parsedUsers as $u) {
            if (!is_array($u) || empty($u['id'])) continue;
            $nombre = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
            $displayName = $nombre !== '' ? $nombre : $u['id'];
            $userOptions[] = [
                'id' => $u['id'],
                'nombre' => $displayName
            ];
            $userLookup[$u['id']] = $displayName;
            $phoneKey = normalize_phone_key($u['numero_celular'] ?? '');
            if ($phoneKey !== '') {
                $userLookup[$phoneKey] = $displayName;
            }
            $rutKey = normalize_rut_key($u['rut'] ?? '');
            if ($rutKey !== '') {
                $userLookup[$rutKey] = $displayName;
            }
        }
    }
}
$userMap = [];
if (count($userOptions) > 0) {
    $userMap = array_combine(array_column($userOptions, 'id'), array_column($userOptions, 'nombre'));
}


$catOptions = [];

$catPath = __DIR__ . '/../../data/categorias.json';

if (file_exists($catPath)) {

    $raw = file_get_contents($catPath);

    $parsed = json_decode($raw, true);

    if (is_array($parsed)) {

        foreach ($parsed as $c) {

            if (is_array($c) && isset($c['nombre'])) {

                $catOptions[] = $c['nombre'];

            }

        }

    }

}



$unitOptions = [];

$unitPath = __DIR__ . '/../../data/unidades.json';

if (file_exists($unitPath)) {

    $raw = file_get_contents($unitPath);

    $parsed = json_decode($raw, true);

    if (is_array($parsed)) {

        foreach ($parsed as $u) {

            if (is_array($u) && isset($u['nombre'])) {

                $unitOptions[] = $u['nombre'];

            }

        }

    }

}




$tipoOptions = [];
$prioridadOptions = [];
$estadoOptions = ['pendiente', 'procesado', 'error']; // estados locales (dashboard)
$estadoRedmineId = null;
$estadoRedmineNombre = null;
$logsByMessage = load_redmine_logs_by_message();
$cfgPath = __DIR__ . '/../../data/configuracion.json';
if (file_exists($cfgPath)) {
    $cfgData = json_decode(file_get_contents($cfgPath), true);
    if (is_array($cfgData)) {
        foreach (($cfgData['trackers'] ?? []) as $t) {
            if (is_array($t) && isset($t['nombre'])) {
                $tipoOptions[] = $t['nombre'];
            }
        }
        foreach (($cfgData['prioridades'] ?? []) as $pOpt) {
            if (is_array($pOpt) && isset($pOpt['nombre'])) {
                $prioridadOptions[] = $pOpt['nombre'];
            }
        }
        // Estado de Redmine configurado
        $estadoRedmineId = $cfgData['status_id'] ?? null;
        if ($estadoRedmineId) {
            foreach (($cfgData['estados'] ?? []) as $eOpt) {
                if (is_array($eOpt) && isset($eOpt['id']) && (int)$eOpt['id'] === (int)$estadoRedmineId) {
                    $estadoRedmineNombre = $eOpt['nombre'] ?? null;
                    break;
                }
            }
        }
        // estados de Redmine se usan para configurar status_id, no para el flujo local del dashboard
    }
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();

?>

<!DOCTYPE html>

<html lang="es">

<head>

  <?php $pageTitle = 'Reportes'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>

</head>

<body class="bg-light">

<?php $activeNav = 'mensajes'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<style>
  .dashboard-shell { display: grid; gap: 1.25rem; }
  .dashboard-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; width: 100%; }
  .dashboard-stat {
    position: relative;
    padding: 1.2rem 1.35rem;
    min-height: 128px;
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,255,.88));
    border: 1px solid rgba(15, 23, 42, .08);
    box-shadow: 0 20px 40px rgba(15, 23, 42, .08);
    overflow: hidden;
  }
  .dashboard-stat[data-filter] { cursor: pointer; }
  .dashboard-stat[data-filter].is-active {
    border-color: rgba(37, 99, 235, .28);
    box-shadow: 0 26px 54px rgba(37, 99, 235, .18);
    transform: translateY(-2px);
  }
  .dashboard-stat::after {
    content: '';
    position: absolute;
    width: 88px;
    height: 88px;
    border-radius: 50%;
    right: -24px;
    top: -24px;
    background: rgba(255,255,255,.75);
  }
  .dashboard-stat__top { display: flex; justify-content: flex-start; align-items: center; gap: 1rem; margin-bottom: 0; position: relative; z-index: 1; }
  .dashboard-stat__icon {
    width: 72px;
    height: 72px;
    border-radius: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.7rem;
    box-shadow: 0 14px 28px rgba(15, 23, 42, .14);
    flex: 0 0 auto;
  }
  .dashboard-stat__value { font-size: 2.2rem; font-weight: 700; line-height: 1; margin-bottom: .3rem; position: relative; z-index: 1; }
  .dashboard-stat__label { color: var(--text-muted); font-weight: 600; font-size: 1rem; position: relative; z-index: 1; }
  .dashboard-stat__content { display: flex; flex-direction: column; justify-content: center; }
  .dashboard-stat--pending .dashboard-stat__icon { background: linear-gradient(135deg, #f59e0b, #f97316); }
  .dashboard-stat--processed .dashboard-stat__icon { background: linear-gradient(135deg, #10b981, #22c55e); }
  .dashboard-stat--error .dashboard-stat__icon { background: linear-gradient(135deg, #ef4444, #fb7185); }
  .dashboard-stat--total .dashboard-stat__icon { background: linear-gradient(135deg, #0ea5e9, #8b5cf6); }
  .dashboard-panel { padding: 1.15rem; border-radius: 24px; background: rgba(255,255,255,.88); border: 1px solid rgba(255,255,255,.6); box-shadow: 0 22px 55px rgba(15, 23, 42, .09); }
  .dashboard-panel__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
  .dashboard-panel__title { margin: 0; font-size: 1.05rem; font-weight: 700; }
  .dashboard-panel__desc { margin: .25rem 0 0; color: var(--text-muted); font-size: .92rem; }
  .dashboard-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
  .dashboard-toolbar__actions { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
  .dashboard-selection,
  .dashboard-table-count {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem .9rem;
    border-radius: 999px;
    font-weight: 700;
  }
  .dashboard-selection { background: rgba(15,23,42,.06); color: var(--text-primary); }
  .dashboard-table-count { background: rgba(56,189,248,.12); color: #0f4c81; }
  .dashboard-import-grid { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: end; }
  .dashboard-import-button { min-width: 220px; min-height: 52px; font-weight: 700; }
  .dashboard-table-card .card-body { padding: 0; }
  .dashboard-table-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.05rem 1.2rem 0; }
  .dashboard-table-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; }
  .dashboard-table-subtitle { color: var(--text-muted); font-size: .9rem; margin-top: .2rem; }
  .dashboard-table { margin-top: 1rem; }
  .dashboard-table__subject { font-weight: 600; color: var(--text-primary); max-width: 460px; min-width: 280px; }
  .dashboard-table__meta { display: block; color: var(--text-muted); font-size: .78rem; margin-top: .2rem; }
  .dashboard-status-icon {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: .9rem;
    box-shadow: 0 10px 22px rgba(15, 23, 42, .12);
  }
  .dashboard-status-icon--pending { background: linear-gradient(135deg, #f59e0b, #f97316); }
  .dashboard-status-icon--processed { background: linear-gradient(135deg, #10b981, #22c55e); }
  .dashboard-status-icon--error { background: linear-gradient(135deg, #ef4444, #fb7185); }
  .dashboard-row-actions { display: flex; flex-wrap: nowrap; align-items: center; gap: .35rem; white-space: nowrap; }
  .dashboard-row-actions form { margin: 0; display: inline-flex; }
  .dashboard-row-actions .btn { min-height: 30px; width: 30px; padding: 0; border-radius: 10px; font-size: .9rem; line-height: 1; display: inline-flex; align-items: center; justify-content: center; }
  .dashboard-row-actions .btn i { margin-right: 0; }
  .dashboard-row-actions .btn-hora-extra--on { color: #fff; background: linear-gradient(135deg, #16a34a, #22c55e); border-color: transparent; }
  .dashboard-row-actions .btn-hora-extra--off { color: #64748b; background: #f8fafc; border-color: #cbd5e1; }
  .dashboard-row-actions .btn-hora-extra--off:hover { color: #0f172a; background: #e0f2fe; border-color: #7dd3fc; }
  .dashboard-toast {
    position: fixed;
    right: 22px;
    bottom: 22px;
    z-index: 1080;
    min-width: 260px;
    max-width: min(360px, calc(100vw - 32px));
    padding: .9rem 1rem;
    border-radius: 16px;
    color: #fff;
    background: linear-gradient(135deg, #0f172a, #2563eb);
    box-shadow: 0 18px 45px rgba(15, 23, 42, .28);
    display: flex;
    align-items: center;
    gap: .65rem;
    font-weight: 700;
    opacity: 1;
    transform: translateY(0);
    transition: opacity .22s ease, transform .22s ease;
  }
  .dashboard-toast.is-hiding {
    opacity: 0;
    transform: translateY(10px);
  }
  .dashboard-scroll-top {
    position: fixed;
    right: 22px;
    bottom: 22px;
    width: 56px;
    height: 56px;
    border: 0;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #fff;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    box-shadow: 0 18px 36px rgba(37, 99, 235, .28);
    opacity: 0;
    visibility: hidden;
    transform: translateY(14px);
    transition: opacity .2s ease, transform .2s ease, visibility .2s ease;
    z-index: 1040;
  }
  .dashboard-scroll-top.is-visible {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
  }
  #detalleModal .modal-content { max-height: 90vh; }
  #detalleModal .modal-body { overflow-y: auto; }
  #detalleModal .modal-footer { position: sticky; bottom: 0; background: #fff; border-top: 1px solid rgba(15, 23, 42, .08); z-index: 2; }
  #detallePreviewModal .detail-preview-wrap { max-height: 70vh; overflow: auto; }
  .core-import-overlay {
    position: fixed;
    inset: 0;
    z-index: 2050;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background: rgba(15, 23, 42, .48);
    backdrop-filter: blur(7px);
  }
  .core-import-overlay.is-visible { display: flex; }
  .core-import-card {
    width: min(520px, 100%);
    border-radius: 24px;
    padding: 1.35rem;
    background: rgba(255,255,255,.96);
    border: 1px solid rgba(255,255,255,.78);
    box-shadow: 0 28px 70px rgba(15, 23, 42, .28);
  }
  .core-import-card__media {
    display: flex;
    justify-content: center;
    margin-bottom: .95rem;
  }
  .core-import-card__gif {
    width: min(220px, 70vw);
    max-height: 150px;
    object-fit: contain;
  }
  .core-import-card__header { display: flex; gap: .9rem; align-items: center; margin-bottom: 1rem; }
  .core-import-card__icon {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.25rem;
    background: linear-gradient(135deg, #38bdf8, #8b5cf6);
    box-shadow: 0 16px 30px rgba(59, 130, 246, .28);
  }
  .core-import-card__title { margin: 0; font-size: 1.1rem; font-weight: 800; color: #0f172a; }
  .core-import-card__text { margin: .15rem 0 0; color: #64748b; font-size: .92rem; }
  .core-import-progress {
    height: 12px;
    border-radius: 999px;
    overflow: hidden;
    background: #e5edf7;
  }
  .core-import-progress__bar {
    width: 0%;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #38bdf8, #2563eb, #8b5cf6);
    transition: width .35s ease;
  }
  .core-import-card__meta {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-top: .7rem;
    color: #475569;
    font-size: .85rem;
    font-weight: 700;
  }
  @media (max-width: 1200px) { .dashboard-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  @media (max-width: 991px) {
    .dashboard-import-grid { grid-template-columns: 1fr; }
    .dashboard-import-button { width: 100%; min-width: 0; }
  }
  @media (max-width: 767px) {
    .dashboard-stats { grid-template-columns: 1fr; }
    .dashboard-toolbar, .dashboard-panel__header, .dashboard-table-header { flex-direction: column; align-items: stretch; }
    .dashboard-toolbar__actions { width: 100%; }
    .dashboard-toolbar__actions .btn { flex: 1 1 100%; }
  }
</style>
<div class="container-fluid py-4">
<div class="dashboard-shell">

  <?php
    $heroIcon = 'bi-speedometer2';
    $heroTitle = 'Reportes';
    $heroSubtitle = 'Panel de estados locales';
    $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-clock-history"></i> Retención automática: ' . $h($retencionHoras) . ' h</span>'
      . '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-arrow-repeat"></i> Estado Redmine: ' . $h($estadoRedmineNombre ?: 'No definido') . '</span>';
    include __DIR__ . '/../partials/hero.php';
  ?>

  <?php if ($flash): ?>
    <div class="alert alert-success" id="flash-msg"><?= $h($flash) ?></div>
  <?php endif; ?>
  <?php if ($toastSession): ?>
    <div class="dashboard-toast" id="dashboard-toast" role="status" aria-live="polite">
      <i class="bi bi-check2-circle"></i>
      <span><?= $h($toastSession) ?></span>
    </div>
  <?php endif; ?>

  <form method="post" class="dashboard-panel" id="core-import-form">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="action" value="import_core_history">
    <input type="hidden" name="core_runtime_user" id="core-runtime-user-hidden" value="">
    <input type="hidden" name="core_runtime_pass" id="core-runtime-pass-hidden" value="">
    <input type="hidden" name="core_remember_credentials" id="core-remember-hidden" value="0">
    <div class="dashboard-panel__header">
      <div>
        <h3 class="dashboard-panel__title">Consulta rápida a CORE</h3>
        <p class="dashboard-panel__desc">Trae solicitudes por rango de fechas y usuario asignado con un flujo más claro.</p>
      </div>
    </div>
    <div class="dashboard-import-grid">
      <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">CORE desde</label>
        <input type="date" name="core_desde" class="form-control" value="<?= $h($coreDesde) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">CORE hasta</label>
        <input type="date" name="core_hasta" class="form-control" value="<?= $h($coreHasta) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Asignado CORE</label>
        <?php if ($currentRole === 'root'): ?>
          <select name="core_assigned_name" class="form-select">
            <option value="">Todos</option>
            <?php foreach ($userOptions as $userOption): ?>
              <?php $optionName = trim((string)($userOption['nombre'] ?? '')); ?>
              <?php if ($optionName === '') continue; ?>
              <option value="<?= $h($optionName) ?>" <?= $optionName === (string)$coreAssignedName ? 'selected' : '' ?>>
                <?= $h($optionName) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="core_assigned_name" class="form-control" value="<?= $h($coreAssignedName) ?>" placeholder="Opcional" readonly>
        <?php endif; ?>
      </div>
      </div>
      <button type="<?= $hasSavedCoreCredentials ? 'submit' : 'button' ?>" class="btn btn-primary dashboard-import-button" <?= $hasSavedCoreCredentials ? '' : 'data-bs-toggle="modal" data-bs-target="#coreCredentialsModal"' ?> <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>
        <i class="bi bi-cloud-download"></i> Importar desde CORE
      </button>
    </div>
  </form>



  <div class="dashboard-stats" id="status-filters">
    <section class="dashboard-stat dashboard-stat--pending is-active" data-filter="pendiente" role="button" tabindex="0">
      <div class="dashboard-stat__top">
        <span class="dashboard-stat__icon"><i class="bi bi-hourglass-split"></i></span>
        <div class="dashboard-stat__content">
          <div class="dashboard-stat__value"><?= count($pendientes) ?></div>
          <div class="dashboard-stat__label">Pendientes por revisar</div>
        </div>
      </div>
    </section>
    <section class="dashboard-stat dashboard-stat--processed" data-filter="procesado" role="button" tabindex="0">
      <div class="dashboard-stat__top">
        <span class="dashboard-stat__icon"><i class="bi bi-check2-circle"></i></span>
        <div class="dashboard-stat__content">
          <div class="dashboard-stat__value"><?= count($procesados) ?></div>
          <div class="dashboard-stat__label">Procesados correctamente</div>
        </div>
      </div>
    </section>
    <section class="dashboard-stat dashboard-stat--error" data-filter="error" role="button" tabindex="0">
      <div class="dashboard-stat__top">
        <span class="dashboard-stat__icon"><i class="bi bi-exclamation-octagon"></i></span>
        <div class="dashboard-stat__content">
          <div class="dashboard-stat__value"><?= count($errores) ?></div>
          <div class="dashboard-stat__label">Errores pendientes</div>
        </div>
      </div>
    </section>
  </div>

  <div class="card dashboard-table-card">

    <div class="card-body">
      <div class="dashboard-table-header">
        <div>
          <h3>Solicitudes activas</h3>
          <div class="dashboard-table-subtitle">Gestiona la cola actual con mejor visibilidad del estado y de las acciones disponibles.</div>
        </div>
        <div class="dashboard-table-count"><i class="bi bi-table"></i> Filas visibles: <span id="visible-count">0</span></div>
      </div>

      <div class="dashboard-toolbar px-3 pt-3">
        <div class="dashboard-toolbar__actions">
          <span class="dashboard-selection"><i class="bi bi-check2-square"></i> Seleccionados: <strong id="selection-count">0</strong></span>
          <button type="button" id="process-btn" class="btn btn-success btn-icon d-none" disabled <?= $maintenanceMode ? 'title="Plataforma en mantencion"' : '' ?>>
            <i class="bi bi-check2-circle"></i> Enviar reportes a Redmine
          </button>
          <button type="button" id="archive-btn" class="btn btn-warning btn-icon d-none" disabled <?= $maintenanceMode ? 'title="Plataforma en mantencion"' : '' ?>>
            <i class="bi bi-archive"></i> Archivar
          </button>
          <button type="button" id="delete-selected-btn" class="btn btn-danger btn-icon" disabled <?= $maintenanceMode ? 'title="Plataforma en mantencion"' : '' ?>>
            <i class="bi bi-trash3"></i> Eliminar seleccionados
          </button>
          <button type="button" id="reset-errors-btn" class="btn btn-secondary btn-icon d-none" disabled <?= $maintenanceMode ? 'title="Plataforma en mantencion"' : '' ?>>
            <i class="bi bi-arrow-counterclockwise"></i> Reintentar errores (marcar pendientes)
          </button>
        </div>
      </div>

      <div class="table-responsive">

        <table class="table table-striped align-middle w-100 dashboard-table">

          <thead class="table-light position-sticky top-0" style="z-index:1;">

            <tr>

              <th style="width:40px;"><input type="checkbox" id="sel-all-top"></th>
              <th style="width:100px;">Redmine ID</th>

              <th style="min-width:340px;">Asunto</th>

              <th>Solicitante</th>

              <th>Fecha creación</th>

              <th>Tipo solicitud</th>

              <th>Establecimiento</th>

              <th>Departamento</th>

              <th>Asignado CORE</th>

              <th>Estado local</th>

              <th style="width:170px;">Acciones</th>

            </tr>

          </thead>

          <tbody>

          <?php foreach ($messages as $m): ?>

            <?php
              $asunto = ($m['asunto'] ?? '') ?: ($m['mensaje'] ?? '');
              $estado = strtolower($m['estado'] ?? '');
              $idAsignado = $m['asignado_a'] ?? '';
              $assignFromMap = $userMap[$idAsignado] ?? '';
              $asignadoNombre = $m['asignado_nombre'] ?? $assignFromMap ?: $idAsignado;
              $displayAsignado = $asignadoNombre;
              $displayDepartamento = dashboard_resolve_department_value($m);
            ?>

            <tr
              data-status="<?= $h($estado) ?>"
              data-cat="<?= $h(strtolower($m['categoria'] ?? '')) ?>"
              data-unit="<?= $h(strtolower($m['unidad'] ?? '')) ?>"
              data-user="<?= $h(strtolower($asignadoNombre)) ?>"
              data-horaextra="<?= $h(strtolower($m['hora_extra'] ?? '')) ?>"
              data-text="<?= $h(strtolower($asunto . ' ' . ($m['solicitante'] ?? '') . ' ' . ($m['numero'] ?? ''))) ?>"
            >

              <td><input type="checkbox" class="msg-check" value="<?= $h($m['id'] ?? '') ?>"></td>
              <td><?= $h($m['redmine_id'] ?? '') ?></td>

              <td>
                <div class="dashboard-table__subject"><?= $h($asunto) ?></div>
              </td>

              <td><?= $h($m['solicitante'] ?? '') ?></td>

              <td><?= $h($m['core_fecha_creacion'] ?? (($m['fecha'] ?? '') . ' ' . ($m['hora'] ?? ''))) ?></td>

              <td><?= $h($m['core_tipo_solicitud'] ?? $asunto) ?></td>

              <td><?= $h($m['core_establecimiento'] ?? ($m['unidad_solicitante'] ?? '')) ?></td>

              <td><?= $h($displayDepartamento) ?></td>

              <td><?= $h($m['core_usuario_asignado'] ?? $displayAsignado) ?></td>

              <?php
                $statusIconClass = $estado === 'pendiente' ? 'dashboard-status-icon--pending' : ($estado === 'procesado' ? 'dashboard-status-icon--processed' : 'dashboard-status-icon--error');
                $statusIcon = $estado === 'pendiente' ? 'bi-hourglass-split' : ($estado === 'procesado' ? 'bi-check2' : 'bi-exclamation-lg');
              ?>
              <td>
                <span class="dashboard-status-icon <?= $statusIconClass ?> action-tooltip" data-bs-placement="top" title="<?= $h(ucfirst($m['estado'] ?? '')) ?>">
                  <i class="bi <?= $statusIcon ?>"></i>
                </span>
              </td>

              <td>
                <div class="dashboard-row-actions">

                <?php
                  $previewRows = dashboard_detail_preview_rows($m);
                  $previewRowsJson = $h((string)json_encode(array_values($previewRows), JSON_UNESCAPED_UNICODE));
                  $previewColumnsJson = $h((string)json_encode(dashboard_core_detail_table_schema($m), JSON_UNESCAPED_UNICODE));
                ?>
                <button type="button" class="btn btn-sm btn-outline-primary action-tooltip" data-bs-toggle="modal" data-bs-target="#detalleModal" data-bs-placement="top" title="Detalle"

                  data-id="<?= $h($m['id'] ?? '') ?>"

                  data-fuente="<?= $h($m['fuente'] ?? '') ?>"

                  data-tipo="<?= $h($m['tipo'] ?? '') ?>"

                  data-estado="<?= $h($m['estado'] ?? '') ?>"

                  data-asunto="<?= $h($asunto) ?>"

                  data-prioridad="<?= $h($m['prioridad'] ?? '') ?>"

                  data-categoria="<?= $h($m['categoria'] ?? '') ?>"

                  data-asignado_a="<?= $h($m['asignado_a'] ?? '') ?>"
                  data-asignado_nombre="<?= $h($asignadoNombre) ?>"

                  data-solicitante="<?= $h($m['solicitante'] ?? '') ?>"

                  data-establecimiento="<?= $h($m['core_establecimiento'] ?? ($m['unidad_solicitante'] ?? '')) ?>"

                  data-departamento="<?= $h($displayDepartamento) ?>"

                  data-hora_extra="<?= $h($m['hora_extra'] ?? '') ?>"

                  data-fecha_inicio="<?= $h($m['fecha_inicio'] ?? '') ?>"

                  data-fecha_fin="<?= $h($m['fecha_fin'] ?? '') ?>"

                  data-tiempo_estimado="<?= $h($m['tiempo_estimado'] ?? '') ?>"

                  data-fecha="<?= $h($m['fecha'] ?? '') ?>"

                  data-hora="<?= $h($m['hora'] ?? '') ?>"

                  data-numero="<?= $h($m['numero'] ?? '') ?>"
                  data-descripcion="<?= $h($m['descripcion'] ?? '') ?>"
                  data-core_fecha_creacion="<?= $h($m['core_fecha_creacion'] ?? '') ?>"
                  data-core_tipo_solicitud="<?= $h($m['core_tipo_solicitud'] ?? '') ?>"
                  data-core_establecimiento="<?= $h($m['core_establecimiento'] ?? '') ?>"
                  data-core_departamento="<?= $h($m['core_departamento'] ?? '') ?>"
                  data-core_usuario_asignado="<?= $h($m['core_usuario_asignado'] ?? '') ?>"
                  data-core_estado="<?= $h($m['core_estado'] ?? '') ?>"
                  data-core_telefono="<?= $h($m['core_telefono'] ?? '') ?>"
                  data-core_celular="<?= $h($m['core_celular'] ?? '') ?>"
                  data-core_email="<?= $h($m['core_email'] ?? '') ?>"
                  data-preview_rows="<?= $previewRowsJson ?>"
                  data-preview_columns="<?= $previewColumnsJson ?>"

                ><i class="bi bi-pencil-square"></i></button>
                <?php
                  $hasHoraExtra = function_exists('normalize_hour_extra_value') && normalize_hour_extra_value($m['hora_extra'] ?? '') === '1';
                ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= $h($m['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="toggle_hora_extra">
                  <button
                    class="btn btn-sm action-tooltip <?= $hasHoraExtra ? 'btn-hora-extra--on' : 'btn-hora-extra--off' ?>"
                    type="submit"
                    data-bs-placement="top"
                    title="<?= $maintenanceMode ? 'Plataforma en mantencion' : ($hasHoraExtra ? 'Hora extra: Si. Cambiar a No' : 'Hora extra: No. Cambiar a Si') ?>"
                    <?= $maintenanceMode ? 'disabled' : '' ?>
                  >
                    <i class="bi <?= $hasHoraExtra ? 'bi-clock-fill' : 'bi-clock' ?>"></i>
                  </button>
                </form>
                <?php if (strtolower($m['estado'] ?? '') === 'error'): ?>
                  <?php
                    $logText = '';
                    if (!empty($m['id']) && isset($logsByMessage[$m['id']])) {
                        $logText = (string)$logsByMessage[$m['id']];
                    }
                  ?>
                  <button type="button" class="btn btn-sm btn-outline-danger log-btn action-tooltip" data-log="<?= $h($logText) ?>" data-bs-toggle="modal" data-bs-target="#logModal" data-bs-placement="top" title="Log"><i class="bi bi-journal-text"></i></button>
                <?php endif; ?>

                <form method="post" data-app-confirm="Eliminar este mensaje?">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= $h($m['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-sm btn-danger action-tooltip" type="submit" data-bs-placement="top" title="<?= $maintenanceMode ? 'Plataforma en mantencion' : 'Eliminar' ?>" <?= $maintenanceMode ? 'disabled' : '' ?>><i class="bi bi-trash3"></i></button>
                </form>
                </div>
              </td>

            </tr>

          <?php endforeach; ?>

          </tbody>

        </table>

      </div>

    </div>

  </div>

</div>



<div class="core-import-overlay" id="core-import-overlay" role="status" aria-live="polite" aria-hidden="true">
  <div class="core-import-card">
    <div class="core-import-card__media">
      <img class="core-import-card__gif" src="/redmine-mantencion/assets/img/animacion-carga.gif" alt="">
    </div>
    <div class="core-import-card__header">
      <div class="core-import-card__icon">
        <i class="bi bi-cloud-download"></i>
      </div>
      <div>
        <h3 class="core-import-card__title">Importando desde CORE</h3>
        <p class="core-import-card__text" id="core-import-progress-text">Conectando con CORE...</p>
      </div>
    </div>
    <div class="core-import-progress" aria-label="Progreso de importacion">
      <div class="core-import-progress__bar" id="core-import-progress-bar"></div>
    </div>
    <div class="core-import-card__meta">
      <span id="core-import-progress-step">Preparando consulta</span>
      <span id="core-import-progress-percent">0%</span>
    </div>
  </div>
</div>

  <form id="process-form" method="post" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="action" id="process-action" value="process_selected">
    <input type="hidden" name="ids" id="process-ids">
  </form>

<datalist id="cat-list">

  <?php foreach ($catOptions as $c): ?>

    <option value="<?= $h($c) ?>"></option>

  <?php endforeach; ?>

</datalist>



<datalist id="unit-list">

  <?php foreach ($unitOptions as $u): ?>

    <option value="<?= $h($u) ?>"></option>

  <?php endforeach; ?>

</datalist>

<datalist id="tipo-list">
  <?php foreach ($tipoOptions as $t): ?>
    <option value="<?= $h($t) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="prioridad-list">
  <?php foreach ($prioridadOptions as $p): ?>
    <option value="<?= $h($p) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="estado-list">
  <?php foreach ($estadoOptions as $e): ?>
    <option value="<?= $h($e) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="user-list">
  <?php foreach ($userOptions as $u): ?>
    <option value="<?= $h($u['nombre']) ?>" data-id="<?= $h($u['id']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<datalist id="estado-error-list">
  <option value="pendiente"></option>
</datalist>



<div class="modal fade" id="coreCredentialsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Credenciales CORE</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Usuario CORE</label>
            <input type="text" class="form-control" id="core-runtime-user-input" placeholder="RUT sin DV o email" autocomplete="username">
          </div>
          <div class="col-12">
            <label class="form-label">Contraseña CORE</label>
            <input type="password" class="form-control" id="core-runtime-pass-input" placeholder="Solo se usa para esta consulta" autocomplete="current-password">
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="core-remember-input">
              <label class="form-check-label" for="core-remember-input">Recordar credenciales CORE para mi usuario</label>
            </div>
            <div class="form-text">La contraseña se guarda cifrada y no se volverá a mostrar en pantalla.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary" form="core-import-form">Consultar CORE</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="detalleModal" tabindex="-1" aria-hidden="true">

  <div class="modal-dialog modal-xl modal-dialog-scrollable">

    <div class="modal-content">

      <form method="post" action="../Dashboard/dashboard.php">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

        <div class="modal-header">

          <h5 class="modal-title">Detalle / Editar</h5>

          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

        </div>

        <div class="modal-body">

          <input type="hidden" name="id" id="md-id">

          <input type="hidden" name="action" value="update">

          <div class="row g-3">

            <div class="col-md-3"><label class="form-label">Tipo</label><input name="tipo" id="md-tipo" class="form-control" list="tipo-list"></div>

            <div class="col-md-3 position-relative">
              <label class="form-label">Estado</label>
              <input name="estado_display" id="md-estado" class="form-control" list="estado-list" placeholder="pendiente/procesado/error">
              <input type="hidden" name="estado" id="md-estado-hidden" value="pendiente">
              <div class="form-text" id="estado-help"></div>
            </div>

            <div class="col-md-6"><label class="form-label">Asunto</label><input name="asunto" id="md-asunto" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Prioridad</label><input name="prioridad" id="md-prioridad" class="form-control" list="prioridad-list"></div>

            <div class="col-md-3"><label class="form-label">Categorías</label><input name="categoria" id="md-categoria" class="form-control" list="cat-list"></div>

            <div class="col-md-3">
              <label class="form-label">Asignado a</label>
              <input id="md-asignado-display" class="form-control" list="user-list" placeholder="Buscar por nombre" autocomplete="off">
              <input type="hidden" name="asignado_a" id="md-asignado-hidden">
              <div class="form-text" id="md-asignado-help"></div>
            </div>

            <div class="col-md-3"><label class="form-label">Solicitante</label><input name="solicitante" id="md-solicitante" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Establecimiento</label><input name="establecimiento" id="md-establecimiento" class="form-control"></div>
 
            <div class="col-md-3"><label class="form-label">Departamento</label><input name="departamento" id="md-departamento" class="form-control"></div>

            <?php if ($estadoRedmineId): ?>
            <div class="col-md-3">
              <label class="form-label">Estado Redmine (solo lectura)</label>
              <input class="form-control" value="<?= $h($estadoRedmineNombre ?: ('ID ' . $estadoRedmineId)) ?>" disabled>
            </div>
            <?php endif; ?>

            <div class="col-md-3">
              <label class="form-label">Hora extra</label>
              <select name="hora_extra" id="md-hora_extra" class="form-select">
                <option value="0" selected>No</option>
                <option value="1">Sí</option>
              </select>
            </div>

            <div class="col-md-3"><label class="form-label">Fecha Inicio</label><input type="date" name="fecha_inicio" id="md-fecha_inicio" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Fecha Fin</label><input type="date" name="fecha_fin" id="md-fecha_fin" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Tiempo Estimado</label><input name="tiempo_estimado" id="md-tiempo_estimado" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Fecha</label><input type="date" name="fecha" id="md-fecha" class="form-control"></div>

            <div class="col-md-2"><label class="form-label">Hora</label><input name="hora" id="md-hora" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Número</label><input name="numero" id="md-numero" class="form-control"></div>

            <div class="col-md-5"><label class="form-label">Correo</label><input name="core_email" id="md-core_email" class="form-control" type="email"></div>

            <div class="col-12 d-none" id="md-descripcion-wrap">
              <label class="form-label d-block">Descripcion</label>
              <input type="hidden" name="descripcion" id="md-descripcion">
              <button type="button" class="btn btn-outline-secondary" id="open-descripcion-modal-btn" data-bs-toggle="modal" data-bs-target="#descripcionModal">
                <i class="bi bi-text-paragraph"></i> Editar descripcion
              </button>
            </div>

            <div class="col-12">
              <label class="form-label d-block">Vista previa de la tabla</label>
              <button type="button" class="btn btn-outline-primary" id="open-preview-modal-btn" data-bs-toggle="modal" data-bs-target="#detallePreviewModal">
                <i class="bi bi-table"></i> Ver tabla
              </button>
            </div>

          </div>

        </div>

        <div class="modal-footer">

          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>

          <button type="submit" class="btn btn-success" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>Guardar cambios</button>

        </div>

      </form>

    </div>

  </div>

</div>

<div class="modal fade" id="detallePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Vista previa de la tabla</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive border rounded detail-preview-wrap">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light" id="md-preview-head">
              <tr>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody id="md-preview-body">
              <tr>
                <td colspan="1" class="text-muted text-center">Sin detalle para previsualizar.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="descripcionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar descripcion</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="md-descripcion-editor">Descripcion</label>
        <textarea id="md-descripcion-editor" class="form-control" rows="10"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-success" id="save-descripcion-btn" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>Guardar descripcion</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="logModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log de errores (envío plataforma)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="small bg-light p-3 border rounded" style="white-space: pre-wrap;" id="logModalContent"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar eliminación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Se eliminarán <strong id="delete-selected-count">0</strong> mensaje(s) seleccionados. Esta acción no se puede deshacer.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="confirm-delete-selected-btn">
          <i class="bi bi-trash3"></i> Eliminar seleccionados
        </button>
      </div>
    </div>
  </div>
 </div>

<button type="button" class="dashboard-scroll-top" id="dashboard-scroll-top" aria-label="Volver arriba" title="Volver arriba">
  <i class="bi bi-arrow-up"></i>
</button>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>

<script>

  const dashboardMaintenanceMode = <?= $maintenanceMode ? 'true' : 'false' ?>;

  document.querySelectorAll('.action-tooltip').forEach(el => {
    new bootstrap.Tooltip(el);
  });

  const detalleModal = document.getElementById('detalleModal');
  const detallePreviewModal = document.getElementById('detallePreviewModal');
  let currentPreviewRows = [];
  let currentPreviewColumns = [];
  const openPreviewModalBtn = document.getElementById('open-preview-modal-btn');
  const descripcionModal = document.getElementById('descripcionModal');
  const descripcionEditor = document.getElementById('md-descripcion-editor');
  const descripcionHidden = document.getElementById('md-descripcion');
  const saveDescripcionBtn = document.getElementById('save-descripcion-btn');
  let reopenDetalleModalAfterPreview = false;
  let reopenDetalleModalAfterDescripcion = false;

  detalleModal.addEventListener('show.bs.modal', event => {

  const btn = event.relatedTarget;

  const normalizeDateForInput = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    const match = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
    if (match) {
      return `${match[3]}-${match[2]}-${match[1]}`;
    }
    return '';
  };

  const set = (id, key) => {

    const el = document.getElementById(id);

    if (el) el.value = btn.getAttribute(key) || '';

  };

  const setDate = (id, key) => {
    const el = document.getElementById(id);
    if (el) el.value = normalizeDateForInput(btn.getAttribute(key) || '');
  };

  set('md-id', 'data-id');

  set('md-tipo', 'data-tipo');

  set('md-estado', 'data-estado');

  set('md-asunto', 'data-asunto');

  set('md-prioridad', 'data-prioridad');

  set('md-categoria', 'data-categoria');

  const asignadoDisplay = document.getElementById('md-asignado-display');
  const asignadoHidden = document.getElementById('md-asignado-hidden');
  const userList = document.getElementById('user-list');
  const findUserIdByName = value => {
    if (!userList || !value) return '';
    const option = Array.from(userList.options).find(opt => opt.value === value);
    return option ? (option.getAttribute('data-id') || '') : '';
  };
  const syncAsignadoHidden = value => {
    if (!asignadoHidden) return;
    const foundId = findUserIdByName(value);
    asignadoHidden.value = foundId || (btn.getAttribute('data-asignado_a') || '');
  };
  if (asignadoDisplay) {
    asignadoDisplay.value = btn.getAttribute('data-asignado_nombre') || '';
    syncAsignadoHidden(asignadoDisplay.value);
    if (!asignadoDisplay.dataset.listenerAttached) {
      asignadoDisplay.dataset.listenerAttached = '1';
      asignadoDisplay.addEventListener('input', () => syncAsignadoHidden(asignadoDisplay.value));
    }
  }
  if (asignadoHidden && !asignadoDisplay) {
    asignadoHidden.value = btn.getAttribute('data-asignado_a') || '';
  }

  set('md-solicitante', 'data-solicitante');

  set('md-establecimiento', 'data-establecimiento');
 
  set('md-departamento', 'data-departamento');

  const horaSel = document.getElementById('md-hora_extra');
  if (horaSel) {
    const hv = (btn.getAttribute('data-hora_extra') || '').toLowerCase();
    horaSel.value = (hv === 'si' || hv === 's\\u00ed' || hv === '1' || hv === 'true') ? '1' : '0';
  }

  setDate('md-fecha_inicio', 'data-fecha_inicio');

  setDate('md-fecha_fin', 'data-fecha_fin');

  set('md-tiempo_estimado', 'data-tiempo_estimado');

  setDate('md-fecha', 'data-fecha');

  set('md-hora', 'data-hora');

  set('md-numero', 'data-numero');

  set('md-descripcion', 'data-descripcion');

  set('md-core_email', 'data-core_email');

  const descripcionWrap = document.getElementById('md-descripcion-wrap');
  if (descripcionWrap) {
    descripcionWrap.classList.toggle('d-none', (btn.getAttribute('data-fuente') || '') !== 'manual');
  }

  const previewHead = document.getElementById('md-preview-head');
  const previewBody = document.getElementById('md-preview-body');
  if (previewBody && previewHead) {
    let previewRows = [];
    let previewColumns = [];
    try {
      previewRows = JSON.parse(btn.getAttribute('data-preview_rows') || '[]');
    } catch (error) {
      previewRows = [];
    }
    try {
      previewColumns = JSON.parse(btn.getAttribute('data-preview_columns') || '[]');
    } catch (error) {
      previewColumns = [];
    }
    const escapeHtml = value => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
    const normalizeText = value => String(value ?? '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
    const resolveDefaultColumns = currentBtn => {
      const fuente = (currentBtn?.getAttribute('data-fuente') || '').trim().toLowerCase();
      if (fuente === 'manual') {
        return [
          { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
          { label: 'Solicitante', key: 'detalle_solicitante' },
          { label: 'Categoria', key: 'detalle_categoria' },
          { label: 'Unidad', key: 'detalle_unidad' },
          { label: 'Descripcion', key: 'detalle_descripcion' }
        ];
      }
      const tipoCore = normalizeText(currentBtn?.getAttribute('data-core_tipo_solicitud') || currentBtn?.getAttribute('data-asunto') || '');
      if (tipoCore === 'creacion de usuario' || tipoCore === 'creacion usuario' || (tipoCore.includes('creacion') && tipoCore.includes('usuario'))) {
        return [
          { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
          { label: 'RUN', key: 'detalle_run' },
          { label: 'Nombre', key: 'detalle_nombre' },
          { label: 'Fecha de nacimiento', key: 'detalle_fecha_nacimiento' },
          { label: 'Email', key: 'detalle_email' },
          { label: 'Departamento', key: 'detalle_departamento' },
          { label: 'Cargo', key: 'detalle_cargo' },
          { label: 'Rol', key: 'detalle_rol' }
        ];
      }
      if (tipoCore === 'agregar establecimiento') {
        return [
          { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
          { label: 'RUN', key: 'detalle_run' },
          { label: 'Nombre', key: 'detalle_nombre' },
          { label: 'Motivo', key: 'detalle_motivo' },
          { label: 'Establecimientos', key: 'detalle_establecimientos' },
          { label: 'Otros permisos', key: 'detalle_otros_permisos' }
        ];
      }
      return [
        { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
        { label: 'RUN', key: 'detalle_run' },
        { label: 'Nombre', key: 'detalle_nombre' },
        { label: 'Motivo', key: 'detalle_motivo' },
        { label: 'Otros permisos', key: 'detalle_otros_permisos' }
      ];
    };
    const forcedColumns = resolveDefaultColumns(btn);
    const forcedType = normalizeText(btn?.getAttribute('data-core_tipo_solicitud') || btn?.getAttribute('data-asunto') || '');
    const shouldForceColumns =
      forcedType === 'creacion de usuario' ||
      forcedType === 'creacion usuario' ||
      forcedType === 'modificar usuario' ||
      forcedType === 'agregar establecimiento';
    const columns = shouldForceColumns
      ? forcedColumns
      : (Array.isArray(previewColumns) && previewColumns.length ? previewColumns : forcedColumns);
    currentPreviewRows = Array.isArray(previewRows) ? previewRows : [];
    currentPreviewColumns = Array.isArray(columns) ? columns : [];
    if (openPreviewModalBtn) {
      openPreviewModalBtn.setAttribute('data-preview_rows', JSON.stringify(currentPreviewRows));
      openPreviewModalBtn.setAttribute('data-preview_columns', JSON.stringify(currentPreviewColumns));
      openPreviewModalBtn.setAttribute('data-core_tipo_solicitud', btn.getAttribute('data-core_tipo_solicitud') || '');
      openPreviewModalBtn.setAttribute('data-fuente', btn.getAttribute('data-fuente') || '');
      openPreviewModalBtn.setAttribute('data-asunto', btn.getAttribute('data-asunto') || '');
    }
    previewHead.innerHTML = `<tr>${columns.map(col => `<th>${escapeHtml(col.label || '')}</th>`).join('')}</tr>`;
    if (!Array.isArray(previewRows) || previewRows.length === 0) {
      previewBody.innerHTML = `<tr><td colspan="${columns.length}" class="text-muted text-center">Sin detalle para previsualizar.</td></tr>`;
    } else {
      previewBody.innerHTML = previewRows.map(row => `
        <tr>
          ${columns.map(col => `<td>${escapeHtml(row[col.key] || '')}</td>`).join('')}
        </tr>
      `).join('');
    }
  }

  const estadoInput = document.getElementById('md-estado');
  const estadoHelp = document.getElementById('estado-help');
  const estadoActual = (btn.getAttribute('data-estado') || '').toLowerCase();
  const estadoHidden = document.getElementById('md-estado-hidden');
  if (estadoInput) {
    estadoInput.disabled = false;
    estadoInput.setAttribute('list', 'estado-list');
    if (estadoHelp) estadoHelp.textContent = '';
    if (estadoActual === 'pendiente' || estadoActual === 'procesado') {
      estadoInput.disabled = true;
      if (estadoHelp) estadoHelp.textContent = 'No se puede cambiar este estado.';
    } else if (estadoActual === 'error') {
      estadoInput.setAttribute('list', 'estado-error-list');
      if (estadoHelp) estadoHelp.textContent = 'Solo puede cambiar a pendiente.';
    }
    if (estadoHidden) {
      estadoHidden.value = estadoInput.value || estadoActual || 'pendiente';
    }
    estadoInput.addEventListener('input', () => {
      if (estadoHidden) estadoHidden.value = estadoInput.value;
    });
  }

  const asignadoHelp = document.getElementById('md-asignado-help');
  if (asignadoHelp) {
    const nombre = btn.getAttribute('data-asignado_nombre') || '';
    asignadoHelp.textContent = nombre ? `Actual: ${nombre}` : '';
  }

});

  if (detallePreviewModal) {
    detallePreviewModal.addEventListener('show.bs.modal', event => {
      const triggerBtn = event.relatedTarget || openPreviewModalBtn;
      if (triggerBtn && previewBody && previewHead) {
        const escapeHtml = value => String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
        let rows = currentPreviewRows;
        let columns = currentPreviewColumns;
        try {
          rows = JSON.parse(triggerBtn.getAttribute('data-preview_rows') || '[]');
        } catch (error) {
          rows = currentPreviewRows;
        }
        try {
          columns = JSON.parse(triggerBtn.getAttribute('data-preview_columns') || '[]');
        } catch (error) {
          columns = currentPreviewColumns;
        }
        const normalizeText = value => String(value ?? '')
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, ' ')
          .trim();
        const tipoCore = normalizeText(triggerBtn.getAttribute('data-core_tipo_solicitud') || triggerBtn.getAttribute('data-asunto') || '');
        if (tipoCore === 'creacion de usuario' || tipoCore === 'creacion usuario') {
          columns = [
            { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
            { label: 'RUN', key: 'detalle_run' },
            { label: 'Nombre', key: 'detalle_nombre' },
            { label: 'Fecha de nacimiento', key: 'detalle_fecha_nacimiento' },
            { label: 'Email', key: 'detalle_email' },
            { label: 'Departamento', key: 'detalle_departamento' },
            { label: 'Cargo', key: 'detalle_cargo' },
            { label: 'Rol', key: 'detalle_rol' }
          ];
        } else if (tipoCore === 'modificar usuario') {
          columns = [
            { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
            { label: 'RUN', key: 'detalle_run' },
            { label: 'Nombre', key: 'detalle_nombre' },
            { label: 'Motivo', key: 'detalle_motivo' },
            { label: 'Otros permisos', key: 'detalle_otros_permisos' }
          ];
        } else if (tipoCore === 'agregar establecimiento') {
          columns = [
            { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
            { label: 'RUN', key: 'detalle_run' },
            { label: 'Nombre', key: 'detalle_nombre' },
            { label: 'Motivo', key: 'detalle_motivo' },
            { label: 'Establecimientos', key: 'detalle_establecimientos' },
            { label: 'Otros permisos', key: 'detalle_otros_permisos' }
          ];
        }
        previewHead.innerHTML = `<tr>${columns.map(col => `<th>${escapeHtml(col.label || '')}</th>`).join('')}</tr>`;
        if (!Array.isArray(rows) || rows.length === 0) {
          previewBody.innerHTML = `<tr><td colspan="${columns.length}" class="text-muted text-center">Sin detalle para previsualizar.</td></tr>`;
        } else {
          previewBody.innerHTML = rows.map(row => `
            <tr>
              ${columns.map(col => `<td>${escapeHtml(row[col.key] || '')}</td>`).join('')}
            </tr>
          `).join('');
        }
      }
      reopenDetalleModalAfterPreview = true;
    });
    detallePreviewModal.addEventListener('hidden.bs.modal', () => {
      if (!reopenDetalleModalAfterPreview || !detalleModal) {
        return;
      }
      reopenDetalleModalAfterPreview = false;
      const modal = bootstrap.Modal.getOrCreateInstance(detalleModal);
      modal.show();
    });
  }

  if (descripcionModal) {
    descripcionModal.addEventListener('show.bs.modal', () => {
      reopenDetalleModalAfterDescripcion = true;
      if (descripcionEditor) {
        descripcionEditor.value = descripcionHidden ? (descripcionHidden.value || '') : '';
      }
    });
    descripcionModal.addEventListener('hidden.bs.modal', () => {
      if (!reopenDetalleModalAfterDescripcion || !detalleModal) {
        return;
      }
      reopenDetalleModalAfterDescripcion = false;
      const modal = bootstrap.Modal.getOrCreateInstance(detalleModal);
      modal.show();
    });
  }

  if (saveDescripcionBtn) {
    saveDescripcionBtn.addEventListener('click', () => {
      if (descripcionHidden && descripcionEditor) {
        descripcionHidden.value = descripcionEditor.value || '';
      }
      if (descripcionModal) {
        const modal = bootstrap.Modal.getOrCreateInstance(descripcionModal);
        modal.hide();
      }
    });
  }



function setAllChecks(checked) {

  document.querySelectorAll('.msg-check').forEach(cb => { cb.checked = checked; });

}

function getVisibleRows() {
  return Array.from(document.querySelectorAll('table tbody tr')).filter(tr => tr.style.display !== 'none');
}

function getSelectedVisibleChecks() {
  return Array.from(document.querySelectorAll('.msg-check')).filter(cb => {
    if (!cb.checked || !cb.value) return false;
    const row = cb.closest('tr');
    return !!row && row.style.display !== 'none';
  });
}

function refreshDashboardCounters() {
  const visibleCount = document.getElementById('visible-count');
  const selectionCount = document.getElementById('selection-count');
  const visibleRows = getVisibleRows();
  const selectedChecks = getSelectedVisibleChecks();
  if (visibleCount) visibleCount.textContent = String(visibleRows.length);
  if (selectionCount) selectionCount.textContent = String(selectedChecks.length);
  const processBtn = document.getElementById('process-btn');
  const archiveBtn = document.getElementById('archive-btn');
  const deleteSelectedBtn = document.getElementById('delete-selected-btn');
  const resetErrorsBtn = document.getElementById('reset-errors-btn');
  if (processBtn) processBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
  if (archiveBtn) archiveBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
  if (deleteSelectedBtn) deleteSelectedBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
  if (resetErrorsBtn) resetErrorsBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
}

const selAllTop = document.getElementById('sel-all-top');

if (selAllTop) {

  selAllTop.addEventListener('change', () => {
    setAllChecks(selAllTop.checked);
    refreshDashboardCounters();
  });

}

const selAllBtn = document.getElementById('sel-all-btn');

if (selAllBtn) {

  selAllBtn.addEventListener('click', () => {

    const boxes = document.querySelectorAll('.msg-check');

    const allChecked = Array.from(boxes).every(cb => cb.checked);

    setAllChecks(!allChecked);

    if (selAllTop) selAllTop.checked = !allChecked;
    refreshDashboardCounters();

  });

}

const processForm = document.getElementById('process-form');

const processAction = document.getElementById('process-action');
const processIds = document.getElementById('process-ids');
let redmineSubmitDelayDone = false;

if (processForm && processIds) {

  processForm.addEventListener('submit', (e) => {

    const ids = Array.from(document.querySelectorAll('.msg-check'))

      .filter(cb => {
        if (!cb.checked || !cb.value) return false;
        const row = cb.closest('tr');
        if (!row) return false;
        // Solo tomar los visibles (segun filtro activo)
        return row.style.display !== 'none';
      })

      .map(cb => cb.value);

    processIds.value = ids.join(',');

    if (ids.length === 0) {

      e.preventDefault();

      window.appModal?.show({
        title: 'Seleccion requerida',
        message: 'Selecciona al menos un mensaje para procesar.',
        tone: 'warning'
      });

    }
    if (!e.defaultPrevented && (processAction?.value || '') === 'process_selected' && !redmineSubmitDelayDone) {
      e.preventDefault();
      showDashboardProgress('redmine');
      redmineSubmitDelayDone = true;
      window.setTimeout(() => {
        processForm.requestSubmit();
      }, 3000);
    }

    refreshDashboardCounters();

  });

}

const filterNav = document.getElementById('status-filters');

function filterRows(filter) {
  document.querySelectorAll('table tbody tr').forEach(tr => {
    const status = (tr.getAttribute('data-status') || '').toLowerCase();
    tr.style.display = (filter === 'all' || status === filter) ? '' : 'none';
  });
  refreshDashboardCounters();
}

function applyFilterButtons(filter) {
  const processBtn = document.getElementById('process-btn');
  if (processBtn) {
    processBtn.classList.toggle('d-none', filter !== 'pendiente');
  }
  const archiveBtn = document.getElementById('archive-btn');
  if (archiveBtn) {
    const showArchive = (filter === 'procesado' || filter === 'pendiente');
    archiveBtn.classList.toggle('d-none', !showArchive);
  }
  const resetErrorsBtn = document.getElementById('reset-errors-btn');
  if (resetErrorsBtn) {
    resetErrorsBtn.classList.toggle('d-none', filter !== 'error');
  }
  refreshDashboardCounters();
}

if (filterNav) {

  const initialFilter = filterNav.querySelector('[data-filter].is-active')?.getAttribute('data-filter') || 'pendiente';
  filterRows(initialFilter);
  applyFilterButtons(initialFilter);

  filterNav.addEventListener('click', (e) => {

    const btn = e.target.closest('[data-filter]');

    if (!btn) return;

    e.preventDefault();

    const filter = btn.getAttribute('data-filter');

    filterNav.querySelectorAll('[data-filter]').forEach(link => link.classList.remove('is-active'));

    btn.classList.add('is-active');

    filterRows(filter);
    applyFilterButtons(filter);

  });

  filterNav.addEventListener('keydown', (e) => {
    const card = e.target.closest('[data-filter]');
    if (!card) return;
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    card.click();
  });

}

document.querySelectorAll('.msg-check').forEach(cb => {
  cb.addEventListener('change', refreshDashboardCounters);
});

const processBtn = document.getElementById('process-btn');
if (processBtn && processForm) {
  processBtn.addEventListener('click', () => {
    if (processAction) processAction.value = 'process_selected';
    processForm.requestSubmit();
  });
}

const archiveBtn = document.getElementById('archive-btn');
if (archiveBtn && processForm && processAction) {
  archiveBtn.addEventListener('click', () => {
    processAction.value = 'archive_selected';
    processForm.requestSubmit();
  });
}

const deleteSelectedBtn = document.getElementById('delete-selected-btn');
const deleteSelectedModalEl = document.getElementById('deleteSelectedModal');
const deleteSelectedCount = document.getElementById('delete-selected-count');
const confirmDeleteSelectedBtn = document.getElementById('confirm-delete-selected-btn');
const deleteSelectedModal = deleteSelectedModalEl ? new bootstrap.Modal(deleteSelectedModalEl) : null;
if (deleteSelectedBtn && processForm && processAction) {
  deleteSelectedBtn.addEventListener('click', () => {
    const selected = getSelectedVisibleChecks();
    if (selected.length === 0) {
      window.appModal?.show({
        title: 'Seleccion requerida',
        message: 'Selecciona al menos un mensaje para eliminar.',
        tone: 'warning'
      });
      return;
    }
    if (deleteSelectedCount) {
      deleteSelectedCount.textContent = String(selected.length);
    }
    deleteSelectedModal?.show();
  });
}

if (confirmDeleteSelectedBtn && processForm && processAction) {
  confirmDeleteSelectedBtn.addEventListener('click', () => {
    processAction.value = 'delete_selected';
    deleteSelectedModal?.hide();
    processForm.requestSubmit();
  });
}

const resetErrorsBtn = document.getElementById('reset-errors-btn');
if (resetErrorsBtn && processForm && processAction) {
  resetErrorsBtn.addEventListener('click', () => {
    processAction.value = 'reset_errors';
    processForm.requestSubmit();
  });
}

  const flash = document.getElementById('flash-msg');
if (flash) {
  setTimeout(() => {
    flash.classList.add('fade');
    flash.style.transition = 'opacity .5s';
    flash.style.opacity = '0';
    setTimeout(() => flash.remove(), 500);
  }, 5000);
}

const dashboardToast = document.getElementById('dashboard-toast');
if (dashboardToast) {
  setTimeout(() => {
    dashboardToast.classList.add('is-hiding');
    setTimeout(() => dashboardToast.remove(), 260);
  }, 2000);
}

const logModal = document.getElementById('logModal');

if (logModal) {

  logModal.addEventListener('show.bs.modal', event => {

    const btn = event.relatedTarget;

    const logText = btn ? (btn.getAttribute('data-log') || '') : '';

    const container = document.getElementById('logModalContent');

    if (container) {

      container.textContent = logText || 'Sin registros de error para este mensaje.';

    }

  });

}

const coreImportForm = document.getElementById('core-import-form');
const coreRuntimeUserInput = document.getElementById('core-runtime-user-input');
const coreRuntimePassInput = document.getElementById('core-runtime-pass-input');
const coreRuntimeUserHidden = document.getElementById('core-runtime-user-hidden');
const coreRuntimePassHidden = document.getElementById('core-runtime-pass-hidden');
const coreRememberInput = document.getElementById('core-remember-input');
const coreRememberHidden = document.getElementById('core-remember-hidden');
const coreCredentialsModal = document.getElementById('coreCredentialsModal');
const coreImportOverlay = document.getElementById('core-import-overlay');
const coreImportProgressBar = document.getElementById('core-import-progress-bar');
const coreImportProgressPercent = document.getElementById('core-import-progress-percent');
const coreImportProgressText = document.getElementById('core-import-progress-text');
const coreImportProgressStep = document.getElementById('core-import-progress-step');
const hasSavedCoreCredentials = <?= $hasSavedCoreCredentials ? 'true' : 'false' ?>;
let coreImportProgressTimer = null;

function showDashboardProgress(mode = 'core') {
  if (!coreImportOverlay || !coreImportProgressBar) return;
  const stepSets = {
    core: [
      { at: 8, text: 'Conectando con CORE...', step: 'Abriendo sesion' },
      { at: 24, text: 'Autenticando credenciales...', step: 'Validando acceso' },
      { at: 42, text: 'Consultando solicitudes...', step: 'Leyendo datos' },
      { at: 64, text: 'Procesando registros...', step: 'Normalizando solicitudes' },
      { at: 82, text: 'Guardando importacion...', step: 'Actualizando panel' },
      { at: 94, text: 'Finalizando...', step: 'Esperando respuesta' }
    ],
    redmine: [
      { at: 8, text: 'Preparando reportes...', step: 'Validando seleccion' },
      { at: 24, text: 'Conectando con Redmine...', step: 'Abriendo conexion' },
      { at: 46, text: 'Enviando reportes...', step: 'Creando tickets' },
      { at: 68, text: 'Confirmando respuestas...', step: 'Registrando resultados' },
      { at: 84, text: 'Actualizando estados locales...', step: 'Guardando cambios' },
      { at: 94, text: 'Finalizando...', step: 'Esperando respuesta' }
    ]
  };
  const titles = {
    core: 'Importando desde CORE',
    redmine: 'Enviando reportes a Redmine'
  };
  const steps = stepSets[mode] || stepSets.core;
  const title = coreImportOverlay.querySelector('.core-import-card__title');
  if (title) title.textContent = titles[mode] || titles.core;
  let progress = 0;
  let stepIndex = 0;
  const setProgress = value => {
    progress = Math.min(94, Math.max(progress, value));
    coreImportProgressBar.style.width = `${progress}%`;
    if (coreImportProgressPercent) coreImportProgressPercent.textContent = `${Math.round(progress)}%`;
  };
  const setStep = item => {
    if (coreImportProgressText) coreImportProgressText.textContent = item.text;
    if (coreImportProgressStep) coreImportProgressStep.textContent = item.step;
  };
  coreImportOverlay.classList.add('is-visible');
  coreImportOverlay.setAttribute('aria-hidden', 'false');
  setStep(steps[0]);
  setProgress(6);
  window.clearInterval(coreImportProgressTimer);
  coreImportProgressTimer = window.setInterval(() => {
    const target = steps[Math.min(stepIndex, steps.length - 1)];
    if (progress < target.at) {
      setProgress(progress + Math.max(1, (target.at - progress) * 0.18));
      return;
    }
    if (stepIndex < steps.length - 1) {
      stepIndex += 1;
      setStep(steps[stepIndex]);
      return;
    }
    setProgress(progress + 0.35);
  }, 420);
}

if (coreImportForm) {
  coreImportForm.addEventListener('submit', event => {
    if (coreRuntimeUserHidden) coreRuntimeUserHidden.value = coreRuntimeUserInput?.value || '';
    if (coreRuntimePassHidden) coreRuntimePassHidden.value = coreRuntimePassInput?.value || '';
    if (coreRememberHidden) coreRememberHidden.value = coreRememberInput?.checked ? '1' : '0';
    if (!hasSavedCoreCredentials && (!coreRuntimeUserHidden?.value.trim() || !coreRuntimePassHidden?.value.trim())) {
      event.preventDefault();
      window.appModal?.show({
        title: 'Credenciales requeridas',
        message: 'Debes ingresar usuario y contraseña de CORE.',
        tone: 'warning'
      });
      return;
    }
    showDashboardProgress('core');
  });
}

if (coreCredentialsModal) {
  coreCredentialsModal.addEventListener('hidden.bs.modal', () => {
    if (coreRuntimePassInput) coreRuntimePassInput.value = '';
    if (coreRuntimePassHidden) coreRuntimePassHidden.value = '';
  });
}

const scrollTopBtn = document.getElementById('dashboard-scroll-top');
if (scrollTopBtn) {
  const updateScrollTopVisibility = () => {
    const formRect = coreImportForm ? coreImportForm.getBoundingClientRect() : null;
    const shouldShow = !!formRect && formRect.bottom < 0;
    scrollTopBtn.classList.toggle('is-visible', shouldShow);
  };
  scrollTopBtn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  window.addEventListener('scroll', updateScrollTopVisibility, { passive: true });
  window.addEventListener('resize', updateScrollTopVisibility);
  updateScrollTopVisibility();
}

refreshDashboardCounters();

</script>

</div>
</div>
</div> <!-- #page-content -->
</body>

</html>
