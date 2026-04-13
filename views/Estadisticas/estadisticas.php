<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine/login.php');
if (!auth_can('estadisticas')) {
  header('Location: /redmine/views/Dashboard/dashboard.php');
  exit;
}
// Si es gestor, solo ve sus propios reportes
$role = auth_get_user_role();
$currentUserId = auth_get_user_id();
if ($role === 'gestor') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['usuario'] = $currentUserId;
  } else {
    $_GET['usuario'] = $currentUserId;
  }
}
require_once __DIR__ . '/../../controllers/estadisticas.php';
$stats = handle_estadisticas();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
// valores de filtro (para mostrar rango aplicado)
$fmtDMY = function($dateStr) {
  $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
  return $dt ? $dt->format('d-m-Y') : $dateStr;
};
$desdeVal = $_POST['desde'] ?? $_GET['desde'] ?? '';
$hastaVal = $_POST['hasta'] ?? $_GET['hasta'] ?? '';
$periodoLabel = 'Todos';
if ($desdeVal && $hastaVal) {
  $periodoLabel = $fmtDMY($desdeVal) . ' a ' . $fmtDMY($hastaVal);
} elseif ($desdeVal || $hastaVal) {
  $periodoLabel = $fmtDMY($desdeVal ?: $hastaVal);
}
// Formato de fecha/hora Chile continental
$actualizadoTxt = '';
if (!empty($stats['actualizado'])) {
  try {
    $dt = new DateTime($stats['actualizado']);
    $dt->setTimezone(new DateTimeZone('America/Santiago'));
    $actualizadoTxt = $dt->format('d-m-Y H:i:s') . ' (Chile continental)';
  } catch (Exception $e) {
    $actualizadoTxt = $stats['actualizado'];
  }
}
$yearNow = date('Y');
$monthNow = (int)date('n');
$cats = [];
$units = [];
$users = [];
$selectedUserId = $_POST['usuario'] ?? $_GET['usuario'] ?? '';
$selectedUserLabel = '';
$catPath = __DIR__ . '/../../data/categorias.json';
$unitPath = __DIR__ . '/../../data/unidades.json';
$userPath = __DIR__ . '/../../data/usuarios.json';
if (file_exists($catPath)) {
  $parsed = json_decode(file_get_contents($catPath), true);
  if (is_array($parsed)) {
    foreach ($parsed as $c) {
      if (is_array($c) && isset($c['nombre'])) $cats[] = $c['nombre'];
    }
  }
}
if (file_exists($unitPath)) {
  $parsed = json_decode(file_get_contents($unitPath), true);
  if (is_array($parsed)) {
    foreach ($parsed as $u) {
      if (is_array($u) && isset($u['nombre'])) $units[] = $u['nombre'];
    }
  }
}
if (file_exists($userPath)) {
  $parsed = json_decode(file_get_contents($userPath), true);
  if (is_array($parsed)) {
    foreach ($parsed as $u) {
      if (!is_array($u)) continue;
      $id = $u['id'] ?? '';
      $nombre = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
      if ($id !== '') $users[$id] = $nombre ?: $id;
    }
  }
}
$selectedUserLabel = ($selectedUserId !== '' && isset($users[$selectedUserId]))
  ? (($users[$selectedUserId] ?? '') . ' (ID ' . $selectedUserId . ')')
  : '';
$userNameMap = $users;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Estad&iacute;sticas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine/assets/theme.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .timeline-box {
      border-radius: 12px;
      padding: 12px 16px;
      border: 1px solid #d1d5db;
      background: #f9fafc;
      box-shadow: inset 0 1px 0 #fff, 0 8px 16px rgba(0,0,0,0.04);
    }
    .timeline-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.9rem;
      color: #1f2937;
      font-weight: 700;
      border-bottom: 1px solid #d1d5db;
      padding-bottom: 6px;
      margin-bottom: 10px;
    }
    .timeline-months {
      display: grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 4px;
      margin-bottom: 8px;
    }
    .timeline-months button {
      border: 1px solid #d1d5db;
      background: #f1f2f5;
      padding: 6px 4px;
      font-size: 0.78rem;
      color: #374151;
      border-radius: 6px;
      transition: all .15s ease;
    }
    .timeline-months button:hover {
      background: #e8edff;
      border-color: #4e73df;
      color: #1f2937;
    }
    .timeline-months button.active,
    .timeline-months button.range {
      background: linear-gradient(180deg, #5a7be7, #3f62d4);
      color: #fff;
      border-color: #3558c4;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.35);
    }
    .timeline-months button.range-edge {
      box-shadow: 0 0 0 2px rgba(78,115,223,0.3);
    }
    .timeline-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.82rem;
      color: #6b7280;
    }
    .stat-card {
      border-left: 0.35rem solid var(--sb-primary);
      border-radius: 14px;
      box-shadow: 0 10px 28px rgba(0,0,0,0.07);
    }
    .stat-icon {
      width: 48px; height: 48px;
      border-radius: 12px;
      display: inline-flex; align-items: center; justify-content: center;
      background: rgba(78,115,223,0.12);
      color: var(--sb-primary);
      font-size: 1.35rem;
    }
  .chart-card {
    height: 360px;
    display: flex;
    flex-direction: column;
  }
  .chart-card canvas {
    flex: 1;
    min-height: 0;
    max-height: 380px;
    height: 320px !important;
    width: 100% !important;
  }
/* Ajustes de graficos en modal */
#chart-usuarios-modal,
#chart-fechas-modal {
  width: 100% !important;
  height: 360px !important;
  }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'estadisticas'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
  <div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-graph-up-arrow';
    $heroTitle = 'Estadísticas';
    $heroSubtitle = 'Resumen y detalle de reportes';
    $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white">Rango: ' . $h($periodoLabel) . '</span>'
      . '<span class="badge bg-white bg-opacity-25 text-white border border-white">Actualizado: ' . $h($actualizadoTxt) . '</span>';
    include __DIR__ . '/../partials/hero.php';
  ?>

  <div class="row g-3 mb-4">
    <div class="col-lg-8 col-md-6">
      <div class="card p-3 chart-card" id="card-fechas" role="button" data-bs-toggle="modal" data-bs-target="#modalChartFechas">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi bi-graph-up text-primary"></i><span class="fw-semibold">Reportes por fecha</span>
        </div>
        <div id="no-data-fechas" class="text-muted small d-none">Sin datos en el rango seleccionado.</div>
        <canvas id="chart-fechas" height="140"></canvas>
      </div>
    </div>
    <div class="col-lg-4 col-md-6">
      <div class="card p-3 chart-card" id="card-usuarios" role="button" data-bs-toggle="modal" data-bs-target="#modalChartUsuarios">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi bi-pie-chart text-success"></i><span class="fw-semibold">Reportes por usuario</span>
        </div>
        <div id="no-data-usuarios" class="text-muted small d-none">Sin datos en el rango seleccionado.</div>
        <canvas id="chart-usuarios" height="220"></canvas>
      </div>
    </div>
  </div>
  <div class="card mb-3 p-3">
    <form id="stats-form" method="post" class="row g-3 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <div class="col-12">
        <div class="timeline-box">
          <div class="timeline-header">
            <span>Fecha</span>
            <span class="text-uppercase text-muted" style="font-size:0.8rem;">Meses</span>
          </div>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="text-muted" style="font-size:0.8rem;">Trimestre <?= ceil($monthNow/3) ?> <?= $h($yearNow) ?></div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('month')">Mes actual</button>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('year')">Año actual</button>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('30d')">Últimos 30 días</button>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('today')">Hoy</button>
            </div>
          </div>
          <div class="timeline-months" id="month-range">
            <?php
              $meses = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEPT','OCT','NOV','DIC'];
              foreach ($meses as $idx => $m):
                $active = ($idx+1) === $monthNow ? 'active' : '';
            ?>
              <button type="button" class="<?= $active ?>" data-month="<?= $idx+1 ?>" onclick="selectMonthRange(<?= $idx+1 ?>)"><?= $h($m) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="d-flex gap-2 flex-wrap mb-2">
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= $h($desdeVal) ?>" style="max-width:180px;">
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= $h($hastaVal) ?>" style="max-width:180px;">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPeriodo('clear')">Limpiar</button>
          </div>
          <div class="timeline-footer">
            <span>Inicio</span>
            <span>Hoy</span>
            <span>Fin</span>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Periodo (YYYY-MM o YYYY)</label>
        <input type="text" name="periodo" class="form-control" placeholder="2025-12 o 2025" value="<?= $h($_POST['periodo'] ?? '') ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Usuario asignado</label>
        <input list="dl-users" name="usuario" class="form-control" placeholder="Buscar usuario" value="<?= $h($_POST['usuario'] ?? '') ?>">
        <datalist id="dl-users">
          <option value="">(Todos)</option>
          <?php foreach ($users as $id=>$name): ?>
            <option value="<?= $h($id) ?>"><?= $h($name) ?> (ID <?= $h($id) ?>)</option>
          <?php endforeach; ?>
        </datalist>
        <?php if ($selectedUserLabel): ?>
          <div class="form-text">Seleccionado: <?= $h($selectedUserLabel) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Categoría</label>
        <input list="dl-cats" name="categoria" class="form-control" placeholder="Buscar categoría" value="<?= $h($_POST['categoria'] ?? '') ?>">
        <datalist id="dl-cats">
          <option value="">(Todas)</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= $h($c) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Unidad</label>
        <input list="dl-units" name="unidad" class="form-control" placeholder="Buscar unidad" value="<?= $h($_POST['unidad'] ?? '') ?>">
        <datalist id="dl-units">
          <option value="">(Todas)</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= $h($u) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary btn-icon"><i class="bi bi-funnel"></i> Aplicar filtros</button>
      </div>
      <div class="col-md-3">
        <a class="btn btn-outline-secondary w-100" href="estadisticas.php"><i class="bi bi-x-circle"></i> Limpiar</a>
      </div>
    </form>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon"><i class="bi bi-collection"></i></div>
          <span class="fw-semibold text-muted">Total reportes</span>
        </div>
        <div class="display-6"><?= $h($stats['total'] ?? 0) ?></div>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#1cc88a;" role="button" data-bs-toggle="modal" data-bs-target="#modalUsuarios">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon" style="background:rgba(28,200,138,0.12);color:#1cc88a;"><i class="bi bi-person-badge"></i></div>
          <span class="fw-semibold text-muted">Por usuario</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceUsuarios = array_slice($stats['por_usuario'] ?? [], 0, 2, true); ?>
          <?php foreach ($sliceUsuarios as $u => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($userNameMap[$u] ?? $u) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceUsuarios)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#f6c23e;" role="button" data-bs-toggle="modal" data-bs-target="#modalCategorias">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon" style="background:rgba(246,194,62,0.12);color:#f6c23e;"><i class="bi bi-tags"></i></div>
          <span class="fw-semibold text-muted">Por categoría</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceCats = array_slice($stats['por_categoria'] ?? [], 0, 2, true); ?>
          <?php foreach ($sliceCats as $cat => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($cat) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceCats)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#36b9cc;" role="button" data-bs-toggle="modal" data-bs-target="#modalUnidades">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon" style="background:rgba(54,185,204,0.12);color:#36b9cc;"><i class="bi bi-building"></i></div>
          <span class="fw-semibold text-muted">Por unidad</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceUnits = array_slice($stats['por_unidad'] ?? [], 0, 2, true); ?>
          <?php foreach ($sliceUnits as $unit => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($unit) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceUnits)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#e74a3b;" role="button" data-bs-toggle="modal" data-bs-target="#modalEstado">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="stat-icon" style="background:rgba(231,74,59,0.12);color:#e74a3b;"><i class="bi bi-flag"></i></div>
          <span class="fw-semibold text-muted">Por estado</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceEst = array_slice($stats['por_estado'] ?? [], 0, 3, true); ?>
          <?php foreach ($sliceEst as $est => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($est) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceEst)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
  <!-- Modal Usuarios -->
  <div class="modal fade" id="modalUsuarios" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por usuario (<?= array_sum($stats['por_usuario'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php $idx=0; foreach (($stats['msgs_por_usuario'] ?? []) as $u => $lista): $idx++; $collapseId = 'u-'.$idx; ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $collapseId ?>" role="button" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                  <strong><?= $h($userNameMap[$u] ?? $u) ?></strong>
                  <span class="badge bg-primary"><?= count($lista) ?></span>
                </div>
                <div class="collapse mt-2" id="<?= $collapseId ?>">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($lista as $msg): ?>
                      <li class="list-group-item">
                        <div class="fw-semibold"><?= $h($msg['asunto'] ?? '') ?></div>
                        <small class="text-muted"><?= $h($msg['fecha'] ?? '') ?> <?= !empty($msg['redmine_id']) ? '- Ticket ' . $h($msg['redmine_id']) : '' ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['msgs_por_usuario'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Categorias -->
  <div class="modal fade" id="modalCategorias" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por categor&iacute;a (<?= array_sum($stats['por_categoria'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php $idx=0; foreach (($stats['msgs_por_categoria'] ?? []) as $cat => $lista): $idx++; $collapseId = 'c-'.$idx; ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $collapseId ?>" role="button" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                  <strong><?= $h($cat) ?></strong>
                  <span class="badge bg-primary"><?= count($lista) ?></span>
                </div>
                <div class="collapse mt-2" id="<?= $collapseId ?>">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($lista as $msg): ?>
                      <li class="list-group-item">
                        <div class="fw-semibold"><?= $h($msg['asunto'] ?? '') ?></div>
                        <small class="text-muted"><?= $h($msg['fecha'] ?? '') ?> <?= !empty($msg['redmine_id']) ? '- Ticket ' . $h($msg['redmine_id']) : '' ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['msgs_por_categoria'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Unidades -->
  <div class="modal fade" id="modalUnidades" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por unidad (<?= array_sum($stats['por_unidad'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php $idx=0; foreach (($stats['msgs_por_unidad'] ?? []) as $unit => $lista): $idx++; $collapseId = 'u2-'.$idx; ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $collapseId ?>" role="button" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                  <strong><?= $h($unit) ?></strong>
                  <span class="badge bg-primary"><?= count($lista) ?></span>
                </div>
                <div class="collapse mt-2" id="<?= $collapseId ?>">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($lista as $msg): ?>
                      <li class="list-group-item">
                        <div class="fw-semibold"><?= $h($msg['asunto'] ?? '') ?></div>
                        <small class="text-muted"><?= $h($msg['fecha'] ?? '') ?> <?= !empty($msg['redmine_id']) ? '- Ticket ' . $h($msg['redmine_id']) : '' ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['msgs_por_unidad'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>


  <!-- Modal Estado -->
  <div class="modal fade" id="modalEstado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por estado (<?= array_sum($stats['por_estado'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php foreach (($stats['por_estado'] ?? []) as $est => $c): ?>
              <li class="list-group-item d-flex justify-content-between">
                <span><?= $h($est) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['por_estado'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <!-- Modal grafico fechas -->
  <div class="modal fade" id="modalChartFechas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por fecha (detalle)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <canvas id="chart-fechas-modal" height="300" style="max-height:360px; width:100%;"></canvas>
          </div>
          <div class="mt-2">
            <?php
              $porFecha = $stats['por_fecha'] ?? [];
              ksort($porFecha);
              $years = [];
              $mesNombres = [
                '01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
                '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
                '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'
              ];
              foreach ($porFecha as $f => $c) {
                $parts = explode('-', $f);
                $y = $parts[0] ?? 'sin_anio';
                $m = $parts[1] ?? '';
                $ym = $y . '-' . $m;
                $years[$y][$ym][$f] = $c;
              }
              $yearIdx = 0;
            ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($years as $y => $meses): $yearIdx++; $yId = 'y-'.$yearIdx; ?>
                <li class="list-group-item">
                  <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $yId ?>" role="button" aria-expanded="false" aria-controls="<?= $yId ?>">
                    <strong><?= $h($y) ?></strong>
                    <span class="badge bg-primary"><?= array_sum(array_map('array_sum', $meses)) ?></span>
                  </div>
                  <div class="collapse mt-2" id="<?= $yId ?>">
                    <ul class="list-group list-group-flush">
                      <?php $mIdx = 0; foreach ($meses as $ym => $dias): $mIdx++; $mId = $yId.'-m-'.$mIdx;
                        $mesNum = explode('-', $ym)[1] ?? '';
                        $mesLabel = $mesNombres[$mesNum] ?? $ym;
                      ?>
                        <li class="list-group-item">
                          <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $mId ?>" role="button" aria-expanded="false" aria-controls="<?= $mId ?>">
                            <span><?= $h($mesLabel) ?></span>
                            <span class="badge bg-secondary"><?= array_sum($dias) ?></span>
                          </div>
                          <div class="collapse mt-2" id="<?= $mId ?>">
                            <ul class="list-group list-group-flush">
                              <?php foreach ($dias as $f => $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                  <span><?= $h($f) ?></span><span class="badge bg-primary"><?= $h($c) ?></span>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </li>
              <?php endforeach; ?>
              <?php if (empty($years)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal grafico usuarios -->
  <div class="modal fade" id="modalChartUsuarios" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por usuario (detalle)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <canvas id="chart-usuarios-modal" height="300" style="max-height:360px; width:100%;"></canvas>
          </div>
          <div class="mt-2">
            <ul class="list-group list-group-flush">
              <?php foreach (($stats['por_usuario'] ?? []) as $u => $c): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <span><?= $h($userNameMap[$u] ?? $u) ?></span><span class="badge bg-primary"><?= $h($c) ?></span>
                </li>
              <?php endforeach; ?>
              <?php if (empty($stats['por_usuario'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setPeriodo(mode) {
  const desde = document.querySelector('input[name="desde"]');
  const hasta = document.querySelector('input[name="hasta"]');
  const today = new Date();
  const pad = (n) => n.toString().padStart(2,'0');
  if (mode === 'month') {
    const y = today.getFullYear();
    const m = pad(today.getMonth() + 1);
    if (desde) desde.value = `${y}-${m}-01`;
    if (hasta) hasta.value = `${y}-${m}-31`;
  } else if (mode === 'year') {
    const y = today.getFullYear();
    if (desde) desde.value = `${y}-01-01`;
    if (hasta) hasta.value = `${y}-12-31`;
  } else if (mode === '30d') {
    const past = new Date(today.getTime() - 29*24*60*60*1000);
    if (desde) desde.value = `${past.getFullYear()}-${pad(past.getMonth()+1)}-${pad(past.getDate())}`;
    if (hasta) hasta.value = `${today.getFullYear()}-${pad(today.getMonth()+1)}-${pad(today.getDate())}`;
  } else if (mode === 'today') {
    const y = today.getFullYear();
    const m = pad(today.getMonth() + 1);
    const d = pad(today.getDate());
    if (desde) desde.value = `${y}-${m}-${d}`;
    if (hasta) hasta.value = `${y}-${m}-${d}`;
  } else if (mode === 'clear') {
    if (desde) desde.value = '';
    if (hasta) hasta.value = '';
    rangeStart = null; rangeEnd = null;
    document.querySelectorAll('.timeline-months button').forEach(btn => btn.classList.remove('active','range','range-edge'));
  }
  const form = document.getElementById('stats-form');
  if (form) form.submit();
}

let rangeStart = null;
let rangeEnd = null;

function highlightMonths(startM, endM) {
  const allButtons = document.querySelectorAll('.timeline-months button');
  allButtons.forEach(btn => {
    btn.classList.remove('active','range','range-edge');
    const val = parseInt(btn.getAttribute('data-month'),10);
    if (startM !== null && endM !== null) {
      if (val === startM || val === endM) btn.classList.add('range-edge');
      if (val >= startM && val <= endM) btn.classList.add('range');
    } else if (startM !== null && endM === null) {
      if (val === startM) btn.classList.add('active','range-edge');
    }
  });
}

function selectMonthRange(m) {
  if (rangeStart === null || (rangeStart !== null && rangeEnd !== null)) {
    rangeStart = m; rangeEnd = null;
  } else {
    rangeEnd = m;
    if (rangeEnd < rangeStart) {
      const tmp = rangeStart; rangeStart = rangeEnd; rangeEnd = tmp;
    }
  }
  const allButtons = document.querySelectorAll('.timeline-months button');
  highlightMonths(rangeStart, rangeEnd);
  if (rangeStart !== null && rangeEnd !== null) {
    const year = new Date().getFullYear();
    const pad = (n) => n.toString().padStart(2,'0');
    const desde = document.querySelector('input[name="desde"]');
    const hasta = document.querySelector('input[name="hasta"]');
    if (desde) desde.value = `${year}-${pad(rangeStart)}-01`;
    if (hasta) hasta.value = `${year}-${pad(rangeEnd)}-31`;
    const form = document.getElementById('stats-form');
    if (form) form.submit();
  } else {
    const btn = Array.from(allButtons).find(b => b.classList.contains('range-edge'));
    if (btn && rangeStart !== null) {
      const year = new Date().getFullYear();
      const pad = (n) => n.toString().padStart(2,'0');
      const desde = document.querySelector('input[name="desde"]');
      const hasta = document.querySelector('input[name="hasta"]');
      if (desde) desde.value = `${year}-${pad(rangeStart)}-01`;
      if (hasta) hasta.value = `${year}-${pad(rangeStart)}-31`;
    }
  }
}

function applyInitialMonthSelection() {
  const parseDateVal = (str) => {
    if (!str) return null;
    if (/^\\d{2}-\\d{2}-\\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-');
      return new Date(`${y}-${m}-${d}`);
    }
    return new Date(str);
  };
  const desdeRaw = document.querySelector('input[name="desde"]')?.value;
  const hastaRaw = document.querySelector('input[name="hasta"]')?.value;
  const d1 = parseDateVal(desdeRaw);
  const d2 = parseDateVal(hastaRaw);
  if (d1 && d2 && !isNaN(d1) && !isNaN(d2) && d1.getFullYear() === d2.getFullYear()) {
    const m1 = d1.getMonth() + 1;
    const m2 = d2.getMonth() + 1;
    rangeStart = Math.min(m1, m2);
    rangeEnd = Math.max(m1, m2);
    highlightMonths(rangeStart, rangeEnd);
    return;
  }
  highlightMonths(null, null);
}

document.addEventListener('DOMContentLoaded', applyInitialMonthSelection);
</script>
<script>
  // Carga diferida de Chart.js si no está presente
  function loadChartLibrary(callback) {
    if (typeof Chart !== 'undefined') {
      callback();
      return;
    }
    const existing = document.querySelector('script[data-chartjs-inline]');
    if (existing) {
      existing.addEventListener('load', () => callback());
      return;
    }
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    s.async = true;
    s.setAttribute('data-chartjs-inline', '1');
    s.onload = () => callback();
    s.onerror = () => console.error('No se pudo cargar Chart.js');
    document.head.appendChild(s);
  }
</script>
<script>
  loadChartLibrary(function(){
    let chartFechasMain = null;
    let chartUsuariosMain = null;
    const dataPorFecha = <?= json_encode($stats['por_fecha'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const dataPorFechaMes = <?= json_encode($stats['por_fecha_mes'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const dataPorUsuario = <?= json_encode($stats['por_usuario'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const userNameMap = <?= json_encode($userNameMap ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const ensureCanvasHeight = (canvas, px = 320, containerPx = 360) => {
      if (!canvas) return;
      canvas.style.height = `${px}px`;
      canvas.height = px;
      canvas.style.maxHeight = `${containerPx}px`;
      if (canvas.parentElement) canvas.parentElement.style.height = `${containerPx}px`;
    };
    const buildSeries = (data) => {
      const entries = Object.entries(data ?? {}).map(([k,v]) => [k, Number(v ?? 0)]);
      entries.sort((a,b)=> (a[0] > b[0] ? 1 : -1));
      const months = new Map();
      entries.forEach(([dateStr, val]) => {
        const m = dateStr.slice(0,7); // yyyy-mm
        months.set(m, (months.get(m) ?? 0) + val);
      });
      if (months.size > 6) {
        const mLabels = Array.from(months.keys()).sort();
        const mValues = mLabels.map(m => months.get(m) ?? 0);
        return { labels: mLabels, values: mValues };
      }
      return {
        labels: entries.map(e => e[0]),
        values: entries.map(e => e[1])
      };
    };
    const { labels, values } = buildSeries(dataPorFecha);

    const ctx = document.getElementById('chart-fechas');
    if (ctx) {
      const emptyMsg = document.getElementById('no-data-fechas');
      if (!labels.length) {
        if (emptyMsg) emptyMsg.classList.remove('d-none');
        ctx.classList.add('d-none');
      } else {
        if (emptyMsg) emptyMsg.classList.add('d-none');
        ctx.classList.remove('d-none');
        const chartLabels = labels.length ? labels : ['Sin datos'];
        const chartValues = labels.length ? values : [0];
        ensureCanvasHeight(ctx, 320, 360);
        if (chartFechasMain) {
          chartFechasMain.destroy();
        }
        chartFechasMain = new Chart(ctx, {
          type: 'line',
          data: {
            labels: chartLabels,
            datasets: [{
              label: 'Reportes',
              data: chartValues,
              borderColor: '#4e73df',
              backgroundColor: "transparent",
              borderWidth: 2.5,
              tension: 0.35,
              pointRadius: 4,
              pointHoverRadius: 6,
              pointHitRadius: 10,
              fill: false
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false, axis: 'x' },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  title: (ctx) => (ctx[0]?.label ?? ''),
                  label: (ctx) => `Reportes: ${ctx.parsed.y ?? 0}`
                }
              }
            },
            scales: {
              x: { ticks: { autoSkip: true, maxTicksLimit: 10 } },
              y: {
                beginAtZero: true,
                title: { display: true, text: 'Cantidad' },
                ticks: { precision: 0, stepSize: 1 }
              }
            }
          }
        });
      }
    }
    const ctxUsers = document.getElementById('chart-usuarios');
    if (ctxUsers) {
      const emptyMsgU = document.getElementById('no-data-usuarios');
      const userIds = Object.keys(dataPorUsuario);
      const userValues = userIds.map(k => dataPorUsuario[k]);
      const userLabels = userIds.map(id => userNameMap[id] ?? id);
      if (!userLabels.length) {
        if (emptyMsgU) emptyMsgU.classList.remove('d-none');
        ctxUsers.classList.add('d-none');
        return;
      } else {
        if (emptyMsgU) emptyMsgU.classList.add('d-none');
        ctxUsers.classList.remove('d-none');
      }
      const colors = ['#4e73df','#5a8dee','#6fa3ff','#8cb5ff','#aec9ff','#c7d9ff','#dee8ff'];
      const borders = colors.map(c => c);
      ensureCanvasHeight(ctxUsers, 280, 320);
      if (chartUsuariosMain) {
        chartUsuariosMain.destroy();
      }
      chartUsuariosMain = new Chart(ctxUsers, {
        type: 'doughnut',
        data: {
          labels: userLabels,
          datasets: [{
            data: userValues,
            backgroundColor: userLabels.map((_,i)=> colors[i % colors.length]),
            borderColor: userLabels.map((_,i)=> borders[i % borders.length]),
            hoverOffset: 6,
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }
  });
</script>
<script>
  loadChartLibrary(function() {
    const dataPorFecha = <?= json_encode($stats['por_fecha'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const dataPorUsuario = <?= json_encode($stats['por_usuario'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const userNameMap = <?= json_encode($userNameMap ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const ensureCanvasHeight = (canvas, px = 360, containerPx = 380) => {
      if (!canvas) return;
      canvas.style.height = `${px}px`;
      canvas.height = px;
      canvas.style.maxHeight = `${containerPx}px`;
      if (canvas.parentElement) canvas.parentElement.style.height = `${containerPx}px`;
    };
    let chartFechasModal = null;
    let chartUsuariosModal = null;
    const buildSeries = (data) => {
      const entries = Object.entries(data ?? {}).map(([k,v]) => [k, Number(v ?? 0)]);
      entries.sort((a,b)=> (a[0] > b[0] ? 1 : -1));
      const months = new Map();
      entries.forEach(([dateStr, val]) => {
        const m = dateStr.slice(0,7); // yyyy-mm
        months.set(m, (months.get(m) ?? 0) + val);
      });
      if (months.size > 6) {
        const mLabels = Array.from(months.keys()).sort();
        const mValues = mLabels.map(m => months.get(m) ?? 0);
        return { labels: mLabels, values: mValues };
      }
      return {
        labels: entries.map(e => e[0]),
        values: entries.map(e => e[1])
      };
    };

    function renderFechasModal() {
      const canvas = document.getElementById('chart-fechas-modal');
      if (!canvas) return;
      ensureCanvasHeight(canvas, 360, 400);
      const { labels, values } = buildSeries(dataPorFecha);
      if (chartFechasModal) {
        chartFechasModal.destroy();
        chartFechasModal = null;
      }
      if (!labels.length) return;
      chartFechasModal = new Chart(canvas, {
        type: 'line',
        data: { labels, datasets: [{ data: values, borderColor: '#4e73df', backgroundColor: 'transparent', borderWidth: 2.5, tension: 0.35, pointRadius: 4, pointHoverRadius: 6, pointHitRadius: 10, fill: false }]},
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'nearest', intersect: false, axis: 'x' },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                title: (ctx) => (ctx[0]?.label ?? ''),
                label: (ctx) => `Reportes: ${ctx.parsed.y ?? 0}`
              }
            }
          },
          scales: {
            x: { ticks: { autoSkip: true, maxTicksLimit: 12 } },
            y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } }
          }
        }
      });
    }

    function renderUsuariosModal() {
      const canvas = document.getElementById('chart-usuarios-modal');
      if (!canvas) return;
      ensureCanvasHeight(canvas, 340, 380);
      const ids = Object.keys(dataPorUsuario);
      const values = ids.map(k => dataPorUsuario[k]);
      const labels = ids.map(id => userNameMap[id] ?? id);
      if (chartUsuariosModal) {
        chartUsuariosModal.destroy();
        chartUsuariosModal = null;
      }
      if (!labels.length) return;
      const colors = ['#4e73df','#5a8dee','#6fa3ff','#8cb5ff','#aec9ff','#c7d9ff','#dee8ff'];
      const borders = colors.map(c => c);
      chartUsuariosModal = new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: labels.map((_,i)=> colors[i % colors.length]),
            borderColor: labels.map((_,i)=> borders[i % borders.length]),
            hoverOffset: 6,
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }

    const modalFechas = document.getElementById('modalChartFechas');
    if (modalFechas) {
      modalFechas.addEventListener('shown.bs.modal', renderFechasModal);
    }
    const modalUsuarios = document.getElementById('modalChartUsuarios');
    if (modalUsuarios) {
      modalUsuarios.addEventListener('shown.bs.modal', renderUsuariosModal);
    }
  });
</script>
</div> <!-- #page-content -->
</body>
</html>




