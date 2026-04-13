<?php

require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine/login.php');
require_once __DIR__ . '/../../controllers/dashboard.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flashSession = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

list($messages, $flash, $securityLog) = handle_request();
if ($flashSession) {
    $flash = $flashSession;
}

$pendientes = array_filter($messages, fn($m) => strtolower($m['estado'] ?? '') === 'pendiente');

$procesados = array_filter($messages, fn($m) => strtolower($m['estado'] ?? '') === 'procesado');

$errores = array_filter($messages, fn($m) => strtolower($m['estado'] ?? '') === 'error');

$cfg = load_platform_config();

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
$logsByMessage = [];
$logPath = __DIR__ . '/../../data/envio_errores.log';
if (file_exists($logPath)) {
    foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) continue;
        $mid = $decoded['message_id'] ?? '';
        if ($mid === '') continue;
        $logsByMessage[$mid][] = $line;
    }
}
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

  <meta charset="utf-8">

  <meta name="viewport" content="width=device-width, initial-scale=1">

<title>Reportes</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine/assets/theme.css" rel="stylesheet">

</head>

<body class="bg-light">

<?php $activeNav = 'mensajes'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">

  <?php
    $heroIcon = 'bi-speedometer2';
    $heroTitle = 'Reportes';
    $heroSubtitle = 'Panel de estados locales (pendiente / procesado / error)';
    $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-clock-history"></i> Retención automática: ' . $h($retencionHoras) . ' h</span>'
      . '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-arrow-repeat"></i> Estado Redmine: ' . $h($estadoRedmineNombre ?: 'No definido') . '</span>';
    include __DIR__ . '/../partials/hero.php';
  ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <ul class="nav nav-pills mb-0" id="status-filters">
      <li class="nav-item"><button class="nav-link active" data-filter="pendiente" type="button">Pendientes (<?= count($pendientes) ?>)</button></li>
      <li class="nav-item"><button class="nav-link" data-filter="procesado" type="button">Procesados (<?= count($procesados) ?>)</button></li>
      <li class="nav-item"><button class="nav-link" data-filter="error" type="button">Errores (<?= count($errores) ?>)</button></li>
    </ul>
    <div class="d-flex gap-2 align-items-center">
      <button type="button" id="process-btn" class="btn btn-success btn-sm btn-icon d-none">
        <i class="bi bi-check2-circle"></i> Enviar reportes a Redmine
      </button>
      <button type="button" id="archive-btn" class="btn btn-warning btn-sm btn-icon d-none">
        <i class="bi bi-archive"></i> Archivar
      </button>
      <button type="button" id="reset-errors-btn" class="btn btn-secondary btn-sm btn-icon d-none">
        <i class="bi bi-arrow-counterclockwise"></i> Reintentar errores (marcar pendientes)
      </button>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success" id="flash-msg"><?= $h($flash) ?></div>
  <?php endif; ?>



  <div class="card">

    <div class="card-body">

      <div class="table-responsive">

        <table class="table table-striped align-middle w-100">

          <thead class="table-light position-sticky top-0" style="z-index:1;">

            <tr>

              <th style="width:40px;"><input type="checkbox" id="sel-all-top"></th>
              <th style="width:100px;">Redmine ID</th>

              <th>Asunto</th>

              <th>Categorías</th>

              <th>Asignado a</th>

              <th>Unidad</th>

              <th>Unidad Solicitante</th>

              <th>Fecha Inicio</th>

              <th>Estado</th>

              <th style="width:200px;">Acciones</th>

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

              <td><?= $h($asunto) ?></td>

              <td><?= $h($m['categoria'] ?? '') ?></td>

              <td><?= $h($displayAsignado) ?></td>

              <td><?= $h($m['unidad'] ?? '') ?></td>

              <td><?= $h($m['unidad_solicitante'] ?? '') ?></td>

              <td><?= $h($m['fecha_inicio'] ?? '') ?></td>

              <?php
                $badge = $estado === 'pendiente' ? 'warning' : ($estado === 'procesado' ? 'success' : 'danger');
              ?>
              <td><span class="badge bg-<?= $badge ?> text-dark"><?= $h($m['estado'] ?? '') ?></span></td>

              <td class="d-flex gap-1 flex-wrap">

                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#detalleModal"

                  data-id="<?= $h($m['id'] ?? '') ?>"

                  data-tipo="<?= $h($m['tipo'] ?? '') ?>"

                  data-estado="<?= $h($m['estado'] ?? '') ?>"

                  data-asunto="<?= $h($asunto) ?>"

                  data-prioridad="<?= $h($m['prioridad'] ?? '') ?>"

                  data-categoria="<?= $h($m['categoria'] ?? '') ?>"
                  data-descripcion="<?= $h($m['descripcion'] ?? '') ?>"

                  data-asignado_a="<?= $h($m['asignado_a'] ?? '') ?>"
                  data-asignado_nombre="<?= $h($asignadoNombre) ?>"

                  data-solicitante="<?= $h($m['solicitante'] ?? '') ?>"

                  data-unidad="<?= $h($m['unidad'] ?? '') ?>"

                  data-unidad_solicitante="<?= $h($m['unidad_solicitante'] ?? '') ?>"

                  data-hora_extra="<?= $h($m['hora_extra'] ?? '') ?>"

                  data-fecha_inicio="<?= $h($m['fecha_inicio'] ?? '') ?>"

                  data-fecha_fin="<?= $h($m['fecha_fin'] ?? '') ?>"

                  data-tiempo_estimado="<?= $h($m['tiempo_estimado'] ?? '') ?>"

                  data-fecha="<?= $h($m['fecha'] ?? '') ?>"

                  data-hora="<?= $h($m['hora'] ?? '') ?>"

                  data-numero="<?= $h($m['numero'] ?? '') ?>"

                >Detalle / Editar</button>
                <?php if (strtolower($m['estado'] ?? '') === 'error'): ?>
                  <?php
                    $logText = '';
                    if (!empty($m['id']) && isset($logsByMessage[$m['id']])) {
                        $logText = implode("\n", $logsByMessage[$m['id']]);
                    }
                  ?>
                  <button type="button" class="btn btn-sm btn-outline-danger log-btn" data-log="<?= $h($logText) ?>" data-bs-toggle="modal" data-bs-target="#logModal">Log</button>
                <?php endif; ?>

                <form method="post" onsubmit="return confirm('Eliminar este mensaje?')">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= $h($m['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-sm btn-danger">Eliminar</button>
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

            <div class="col-md-3"><label class="form-label">Unidad</label><input name="unidad" id="md-unidad" class="form-control" list="unit-list"></div>

            <div class="col-md-3"><label class="form-label">Unidad Solicitante</label><input name="unidad_solicitante" id="md-unidad_solicitante" class="form-control" list="unit-list"></div>

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

            <div class="col-md-3"><label class="form-label">Fecha Inicio</label><input name="fecha_inicio" id="md-fecha_inicio" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Fecha Fin</label><input name="fecha_fin" id="md-fecha_fin" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Tiempo Estimado</label><input name="tiempo_estimado" id="md-tiempo_estimado" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Fecha</label><input name="fecha" id="md-fecha" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Hora</label><input name="hora" id="md-hora" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Número</label><input name="numero" id="md-numero" class="form-control"></div>

            <div class="col-12"><label class="form-label">Mensaje</label><textarea name="descripcion" id="md-mensaje" class="form-control" rows="2"></textarea></div>

          </div>

        </div>

        <div class="modal-footer">

          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>

          <button type="submit" class="btn btn-success">Guardar cambios</button>

        </div>

      </form>

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



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

  const detalleModal = document.getElementById('detalleModal');

  detalleModal.addEventListener('show.bs.modal', event => {

  const btn = event.relatedTarget;

  const set = (id, key) => {

    const el = document.getElementById(id);

    if (el) el.value = btn.getAttribute(key) || '';

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

  set('md-unidad', 'data-unidad');

  set('md-unidad_solicitante', 'data-unidad_solicitante');

  const horaSel = document.getElementById('md-hora_extra');
  if (horaSel) {
    const hv = (btn.getAttribute('data-hora_extra') || '').toLowerCase();
    horaSel.value = (hv === 'si' || hv === 's\\u00ed' || hv === '1' || hv === 'true') ? '1' : '0';
  }

  set('md-fecha_inicio', 'data-fecha_inicio');

  set('md-fecha_fin', 'data-fecha_fin');

  set('md-tiempo_estimado', 'data-tiempo_estimado');

  set('md-fecha', 'data-fecha');

  set('md-hora', 'data-hora');

  set('md-numero', 'data-numero');

  set('md-mensaje', 'data-descripcion');



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



function setAllChecks(checked) {

  document.querySelectorAll('.msg-check').forEach(cb => { cb.checked = checked; });

}

const selAllTop = document.getElementById('sel-all-top');

if (selAllTop) {

  selAllTop.addEventListener('change', () => setAllChecks(selAllTop.checked));

}

const selAllBtn = document.getElementById('sel-all-btn');

if (selAllBtn) {

  selAllBtn.addEventListener('click', () => {

    const boxes = document.querySelectorAll('.msg-check');

    const allChecked = Array.from(boxes).every(cb => cb.checked);

    setAllChecks(!allChecked);

    if (selAllTop) selAllTop.checked = !allChecked;

  });

}

const processForm = document.getElementById('process-form');

const processAction = document.getElementById('process-action');
const processIds = document.getElementById('process-ids');

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

      alert('Selecciona al menos un mensaje para procesar.');

    }

  });

}

const filterNav = document.getElementById('status-filters');

function filterRows(filter) {
  document.querySelectorAll('table tbody tr').forEach(tr => {
    const status = (tr.getAttribute('data-status') || '').toLowerCase();
    tr.style.display = (filter === 'all' || status === filter) ? '' : 'none';
  });
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
}

if (filterNav) {

  const initialFilter = filterNav.querySelector('.nav-link.active')?.getAttribute('data-filter') || 'pendiente';
  filterRows(initialFilter);
  applyFilterButtons(initialFilter);

  filterNav.addEventListener('click', (e) => {

    const btn = e.target.closest('[data-filter]');

    if (!btn) return;

    e.preventDefault();

    const filter = btn.getAttribute('data-filter');

    filterNav.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));

    btn.classList.add('active');

    filterRows(filter);
    applyFilterButtons(filter);

  });

}

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

</script>

</div> <!-- #page-content -->
</body>

</html>















