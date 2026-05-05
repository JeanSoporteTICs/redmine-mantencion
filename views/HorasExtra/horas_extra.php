<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/dashboard.php';

if (!defined('HOURS_EXTRA_DIR')) {
    define('HOURS_EXTRA_DIR', __DIR__ . '/../../data/horasExtras');
}

function load_hours_extra_all(): array {
    $out = [];
    if (!is_dir(HOURS_EXTRA_DIR)) {
        return $out;
    }
    foreach (glob(HOURS_EXTRA_DIR . '/*/*.json') as $file) {
        $groups = json_decode(@file_get_contents($file), true);
        if (!is_array($groups)) {
            continue;
        }
        $firstGroup = [];
        if (!empty($groups)) {
            $firstCandidate = reset($groups);
            if (is_array($firstCandidate)) {
                $firstGroup = $firstCandidate;
            }
        }
        if (!empty($firstGroup) && !isset($firstGroup['reports'])) {
            $tmp = [];
            foreach ($groups as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $dateKey = $msg['fecha'] ?? $msg['fecha_inicio'] ?? '';
                if ($dateKey === '') {
                    continue;
                }
                if (!isset($tmp[$dateKey])) {
                    $tmp[$dateKey] = [
                        'fecha' => $dateKey,
                        'hora_inicio' => $msg['hora_inicio'] ?? $msg['hora'] ?? '',
                        'hora_fin' => $msg['hora_fin'] ?? $msg['hora'] ?? '',
                        'reports' => [],
                    ];
                }
                $tmp[$dateKey]['reports'][] = $msg;
            }
            $groups = array_values($tmp);
        }
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            if (!isset($g['reports']) || !is_array($g['reports'])) {
                continue;
            }
            $out[] = $g;
        }
    }
    return $out;
}

$activeNav = 'horas';
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
setlocale(LC_TIME, 'es_CL.UTF-8', 'es_ES.UTF-8', 'es_ES', 'Spanish');

$today = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
$mesActual = $today->format('n');
$selMes = array_key_exists('mes', $_GET) ? trim($_GET['mes']) : $mesActual;
$anioActual = $today->format('Y');
$selAnio = array_key_exists('anio', $_GET) ? trim($_GET['anio']) : $anioActual;
$flash = null;
$meses = [
    1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
    7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'
];
$dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];

function normalize_date_key($fecha) {
    $fecha = trim((string)$fecha);
    if ($fecha === '') return '';
    $fmts = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
    foreach ($fmts as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $fecha);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }
}

function deduplicate_groups_by_start_date(array $groups): array {
    $out = [];
    foreach ($groups as $group) {
        if (!is_array($group) || empty($group['reports']) || !is_array($group['reports'])) {
            continue;
        }
        $groupFecha = normalize_date_key($group['fecha'] ?? '');
        foreach ($group['reports'] as $report) {
            if (!is_array($report)) {
                continue;
            }
            $startDate = normalize_date_key($report['fecha_inicio'] ?? $report['fecha'] ?? $groupFecha);
            if ($startDate === '') {
                continue;
            }
            if (!isset($out[$startDate])) {
                $out[$startDate] = [
                    'fecha' => $startDate,
                    'hora_inicio' => $group['hora_inicio'] ?? '',
                    'hora_fin' => $group['hora_fin'] ?? '',
                    'reports' => [],
                    '__order' => [],
                ];
            }
            if ($groupFecha === $startDate) {
                if (!empty($group['hora_inicio'])) {
                    $out[$startDate]['hora_inicio'] = $group['hora_inicio'];
                }
                if (!empty($group['hora_fin'])) {
                    $out[$startDate]['hora_fin'] = $group['hora_fin'];
                }
            }
            $reportKey = $report['id'] ?? null;
            if ($reportKey === null) {
                $reportKey = ($report['numero'] ?? '') . '|' . ($report['hora'] ?? '') . '|' . ($report['asunto'] ?? '');
            }
            if ($reportKey === '') {
                continue;
            }
            if (!isset($out[$startDate]['reports'][$reportKey])) {
                $out[$startDate]['reports'][$reportKey] = $report;
                $out[$startDate]['__order'][] = $reportKey;
                continue;
            }
            foreach ($report as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $out[$startDate]['reports'][$reportKey][$key] = $value;
            }
        }
    }
    foreach ($out as &$entry) {
        $reports = [];
        foreach ($entry['__order'] as $key) {
            if (isset($entry['reports'][$key])) {
                $reports[] = $entry['reports'][$key];
            }
        }
        $entry['reports'] = $reports;
        unset($entry['__order']);
    }
    unset($entry);
    return array_values($out);
}

function sanitize_time_value($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        $hh = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        if (!isset($m[3]) || $m[3] === '') {
            return "$hh:$mm";
        }
        $ss = str_pad($m[3], 2, '0', STR_PAD_LEFT);
        return "$hh:$mm:$ss";
    }
    return $value;
}

function update_hours_by_date($fecha, $horaIni, $horaFin) {
    if ($fecha === '') return false;
    $fechaKey = normalize_date_key($fecha);
    $horaIni = sanitize_time_value($horaIni);
    $horaFin = sanitize_time_value($horaFin);
    $updated = false;

    $years = glob(HOURS_EXTRA_DIR . '/*', GLOB_ONLYDIR);
    foreach ($years as $yearDir) {
        $files = glob($yearDir . '/*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) continue;

            if (!isset($data[0]['reports'])) {
                $tmp = [];
                foreach ($data as $row) {
                    if (!is_array($row)) continue;
                    $fb = $row['fecha_inicio'] ?? ($row['fecha'] ?? '');
                    $fbKey = normalize_date_key($fb);
                    if ($fbKey === '') continue;
                    if (!isset($tmp[$fbKey])) {
                        $tmp[$fbKey] = [
                            'fecha' => $fbKey,
                            'hora_inicio' => $row['hora'] ?? '',
                            'hora_fin' => $row['fecha_fin'] ?? '',
                            'reports' => [],
                        ];
                    }
                    $tmp[$fbKey]['reports'][] = $row;
                }
                $data = array_values($tmp);
            }

            $changed = false;
            foreach ($data as &$g) {
                $gKey = normalize_date_key($g['fecha'] ?? '');
                if (!is_array($g) || $gKey !== $fechaKey) continue;
                if ($horaIni !== '') { $g['hora_inicio'] = $horaIni; $changed = true; }
                if ($horaFin !== '') { $g['hora_fin'] = $horaFin; $changed = true; }
            }
            unset($g);

            if ($changed) {
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $updated = true;
            }
        }
    }
    return $updated;
}

$grupos = load_hours_extra_all();
$grupos = deduplicate_groups_by_start_date($grupos);
// Filtrar por usuario para roles usuario/administrador/gestor
$role = auth_get_user_role();
$uid = auth_get_user_id();
if (in_array($role, ['usuario','administrador','gestor'], true) && $uid !== '') {
    $grupos = array_values(array_filter(array_map(function($g) use ($uid) {
        if (!is_array($g)) return null;
        if (isset($g['reports']) && is_array($g['reports'])) {
            $g['reports'] = array_values(array_filter($g['reports'], fn($r) => (string)($r['asignado_a'] ?? '') === (string)$uid));
            return count($g['reports']) > 0 ? $g : null;
        }
        return ((string)($g['asignado_a'] ?? '') === (string)$uid) ? $g : null;
    }, $grupos), fn($g) => $g !== null));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_extra') {
    if (function_exists('csrf_validate')) csrf_validate();
    $fecha = trim($_POST['fecha'] ?? '');
    $horaIni = trim($_POST['hora_ini'] ?? '');
    $horaFin = trim($_POST['hora_fin'] ?? '');
    if (update_hours_by_date($fecha, $horaIni, $horaFin)) {
        $flash = 'Horas actualizadas';
    } else {
        $flash = 'No se encontraron registros para esa fecha';
    }
    $grupos = deduplicate_groups_by_start_date(load_hours_extra_all());
    if ($fecha !== '' && $selMes === '' && $selAnio === '') {
        $dtTmp = DateTime::createFromFormat('Y-m-d', $fecha) ?: DateTime::createFromFormat('d-m-Y', $fecha);
        if ($dtTmp instanceof DateTime) {
            $selMes = $dtTmp->format('n');
            $selAnio = $dtTmp->format('Y');
        }
    }
}

$aniosDisponibles = [];
foreach ($grupos as $g) {
    $fechaBase = $g['fecha'] ?? '';
    if ($fechaBase) {
        $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
        if ($dt instanceof DateTime) {
            $aniosDisponibles[$dt->format('Y')] = true;
        }
    }
}
$aniosDisponibles = array_keys($aniosDisponibles);
$aniosDisponibles[] = $anioActual;
if ($selAnio !== '') {
    $aniosDisponibles[] = $selAnio;
}
$aniosDisponibles = array_values(array_unique(array_map('strval', $aniosDisponibles)));
$aniosDisponibles ? sort($aniosDisponibles, SORT_NUMERIC) : [];

$grupos = array_values(array_filter($grupos, function ($g) use ($selMes, $selAnio) {
    $fechaBase = $g['fecha'] ?? '';
    if ($fechaBase) {
        $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
        if ($dt instanceof DateTime) {
            $mesNum = (int)$dt->format('n');
            $anioNum = $dt->format('Y');
            if ($selMes !== '' && (int)$selMes !== $mesNum) return false;
            if ($selAnio !== '' && $selAnio !== $anioNum) return false;
        }
    }
    return true;
}));

usort($grupos, function ($a, $b) {
    $fa = normalize_date_key($a['fecha'] ?? '');
    $fb = normalize_date_key($b['fecha'] ?? '');
    if ($fa === $fb) {
        return 0;
    }
    if ($fa === '') {
        return 1;
    }
    if ($fb === '') {
        return -1;
    }
    return $fa <=> $fb; // mostrar primero las fechas más antiguas
});

function fmt_fecha($fecha) {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha) ?: DateTime::createFromFormat('d-m-Y', $fecha);
    return $dt ? $dt->format('d-m-Y') : $fecha;
}
function minutos_diff($ini, $fin) {
    if (!$ini || !$fin) return null;
    $d1 = DateTime::createFromFormat('H:i', $ini) ?: DateTime::createFromFormat('H:i:s', $ini);
    $d2 = DateTime::createFromFormat('H:i', $fin) ?: DateTime::createFromFormat('H:i:s', $fin);
    if (!$d1 || !$d2 || $d2 <= $d1) return null;
    return (int)round(($d2->getTimestamp() - $d1->getTimestamp()) / 60);
}
function hhmm($mins) {
    if ($mins === null) return '';
    $hh = str_pad((string)floor($mins / 60), 2, '0', STR_PAD_LEFT);
    $mm = str_pad((string)($mins % 60), 2, '0', STR_PAD_LEFT);
    return "$hh:$mm";
}
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Horas extra'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
  
    .group-row { background:#eef2ff; border-top:2px solid #d6d9f5; border-bottom:2px solid #d6d9f5; }
    .table thead th { white-space: nowrap; }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'horas'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-alarm';
    $heroTitle = 'Horas extra';
    $heroSubtitle = 'Reportes con hora extra agrupados por fecha';
    $heroExtras = '';
    if ($selMes || $selAnio) {
      $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white">Filtrado: ' . ($selMes ? ucfirst($meses[(int)$selMes] ?? $selMes) : 'Todos los meses') . ' ' . ($selAnio ?: '') . '</span>';
    }
    include __DIR__ . '/../partials/hero.php';
  ?>

  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body row g-3 align-items-end">
      <div class="col-md-4 col-lg-3">
        <label class="form-label">Mes</label>
        <select name="mes" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($meses as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($selMes !== '' && (int)$selMes === $k) ? 'selected' : '' ?>><?= ucwords($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3">
        <label class="form-label">A&ntilde;o</label>
        <select name="anio" class="form-select">
          <option value="" <?= $selAnio === '' ? 'selected' : '' ?>>Todos</option>
          <?php foreach ($aniosDisponibles as $an): ?>
            <option value="<?= $h($an) ?>" <?= ($selAnio !== '' && (string)$selAnio === (string)$an) ? 'selected' : '' ?>><?= $h($an) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3 d-flex gap-2">
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn btn-outline-secondary" href="?"><i class="bi bi-x-circle"></i> Limpiar</a>
      </div>
    </div>
  </form>

  <?php if ($flash): ?>
    <div class="alert alert-success"><?= $h($flash) ?></div>
  <?php endif; ?>

  <?php
    $totalHorasTablaMins = 0;
    $totalHorasTabla = '';
  ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-table text-primary"></i>
          <span class="fw-semibold">Listado</span>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" id="copy-table-btn" aria-label="Copiar tabla">
          <i class="bi bi-clipboard"></i> Copiar tabla
        </button>
      </div>

      <?php if (empty($grupos)): ?>
        <div class="alert alert-info">No se encontraron registros para esa fecha</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="extras-table" data-total-hours="">
            <thead class="table-light">
              <tr>
                <th>Fecha</th>
                <th>Detalle</th>
                <th>N&deg; Ticket</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grupos as $g):
                $fechaKey = $g['fecha'] ?? '';
                $dt = DateTime::createFromFormat('Y-m-d', $fechaKey) ?: DateTime::createFromFormat('d-m-Y', $fechaKey);
                $mesNum = $dt ? (int)$dt->format('n') : null;
                $anioNum = $dt ? $dt->format('Y') : '';
                $diaNum = $dt ? (int)$dt->format('d') : '';
                $mesTxt = $mesNum ? ucfirst($meses[$mesNum] ?? '') : '';
                $diaNombre = $dt ? ucfirst($dias[(int)$dt->format('w')] ?? '') : '';
                $horaIni = $g['hora_inicio'] ?? '';
                $horaFin = $g['hora_fin'] ?? '';
                $minsGrupo = minutos_diff($horaIni, $horaFin);
                if ($minsGrupo !== null) $totalHorasTablaMins += $minsGrupo;
                $totalGrupo = hhmm($minsGrupo);
              ?>
                <tr class="group-row" data-fecha="<?= $h(fmt_fecha($fechaKey)) ?>" data-horaini="<?= $h($horaIni) ?>" data-horafin="<?= $h($horaFin) ?>" data-total="<?= $h($totalGrupo) ?>">
                  <td colspan="3">
                    <div class="d-flex justify-content-between align-items-center w-100">
                      <span><strong><?= $h(fmt_fecha($fechaKey)) ?></strong> &middot; Hora inicio: <?= $h($horaIni) ?> | Hora término: <?= $h($horaFin) ?><?= $totalGrupo ? ' | Total de horas: ' . $h($totalGrupo) : '' ?></span>
                      <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal" data-fecha="<?= $h(fmt_fecha($fechaKey)) ?>" data-horaini="<?= $h($horaIni) ?>" data-horafin="<?= $h($horaFin) ?>"><i class="bi bi-pencil-square"></i> Editar horas</button>
                    </div>
                  </td>
                </tr>
                <?php if (isset($g['reports']) && is_array($g['reports'])):
                  foreach ($g['reports'] as $r):
                    $detalleFecha = $r['fecha_inicio'] ?? $r['fecha'] ?? $fechaKey;
                  ?>
                  <tr class="detail-row" data-detalle="<?= $h($r['asunto'] ?? '') ?>" data-ticket="<?= $h($r['redmine_id'] ?? '') ?>">
                    <td><?= $h(fmt_fecha($detalleFecha)) ?></td>
                    <td style="white-space:pre-line;"><?= $h($r['asunto'] ?? '') ?></td>
                    <td class="text-center"><?= $h($r['redmine_id'] ?? '') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              <?php endforeach; ?>
            </tbody>
            <?php $totalHorasTabla = hhmm($totalHorasTablaMins); ?>
            <tfoot>
              <tr>
                <th colspan="3">Total de horas: <?= $h($totalHorasTabla ?: '00:00') ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
        <script>document.getElementById('extras-table').dataset.totalHours = '<?= $h($totalHorasTabla ?: '') ?>';</script>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal editar horas -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar horas por fecha</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="update_extra">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Fecha</label>
            <input type="text" class="form-control" name="fecha" id="md-fecha" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Hora de inicio</label>
            <input type="time" class="form-control" name="hora_ini" id="md-hora-ini" step="1">
          </div>
          <div class="mb-3">
            <label class="form-label">Hora de t&eacute;rmino</label>
            <input type="time" class="form-control" name="hora_fin" id="md-hora-fin" step="1">
          </div>
          <div class="mb-2 text-muted small" id="md-total-horas"></div>
          <p class="text-muted small mb-0">Las horas se aplican a todos los reportes de esa fecha.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
const editModal = document.getElementById('editModal');
const totalHorasEl = document.getElementById('md-total-horas');
const horaIniInput = document.getElementById('md-hora-ini');
const horaFinInput = document.getElementById('md-hora-fin');

function parseTimeInput(value) {
  if (!value) return null;
  const parts = value.split(':');
  if (parts.length === 3) {
    const date = new Date(`1970-01-01T${value}`);
    return isNaN(date) ? null : date;
  }
  if (parts.length === 2) {
    const date = new Date(`1970-01-01T${value}:00`);
    return isNaN(date) ? null : date;
  }
  return null;
}

function updateTotalHorasPreview() {
  if (!totalHorasEl || !horaIniInput || !horaFinInput) return;
  const d1 = parseTimeInput(horaIniInput.value);
  const d2 = parseTimeInput(horaFinInput.value);
  if (d1 && d2 && d2 > d1) {
    const diffMs = d2 - d1;
    const mins = Math.floor(diffMs / 60000);
    const hh = String(Math.floor(mins / 60)).padStart(2,'0');
    const mm = String(mins % 60).padStart(2,'0');
    totalHorasEl.textContent = `Total de horas: ${hh}:${mm}`;
    return;
  }
  totalHorasEl.textContent = '';
}

if (editModal) {
  editModal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) return;
    const setVal = (id, attr) => {
      const el = document.getElementById(id);
      if (el) el.value = btn.getAttribute(attr) || '';
    };
    setVal('md-fecha', 'data-fecha');
    setVal('md-hora-ini', 'data-horaini');
    setVal('md-hora-fin', 'data-horafin');
    updateTotalHorasPreview();
  });
}
if (horaIniInput) horaIniInput.addEventListener('input', updateTotalHorasPreview);
if (horaFinInput) horaFinInput.addEventListener('input', updateTotalHorasPreview);

// Copiar tabla con formato similar al ejemplo (compatible con Excel)
const copyBtn = document.getElementById('copy-table-btn');
if (copyBtn) {
  copyBtn.addEventListener('click', () => {
    const table = document.getElementById('extras-table');
    if (!table) return;
    const groupRows = Array.from(table.querySelectorAll('tbody tr.group-row'));
    if (!groupRows.length) {
      window.appModal?.show({
        title: 'Sin datos',
        message: 'No hay datos para copiar.',
        tone: 'warning'
      });
      return;
    }

    const totalHorasTabla = table.dataset.totalHours || '';
    const grupos = [];
    groupRows.forEach(grp => {
      const fecha = grp.dataset.fecha || '';
      const ini = grp.dataset.horaini || '';
      const fin = grp.dataset.horafin || '';
      const total = grp.dataset.total || '';
      const detalles = [];
      let node = grp.nextElementSibling;
      while (node && !node.classList.contains('group-row')) {
        if (node.dataset) detalles.push({ detalle: node.dataset.detalle || '', ticket: node.dataset.ticket || '' });
        node = node.nextElementSibling;
      }
      grupos.push({ fecha, ini, fin, total, detalles });
    });

    const html = [];
    html.push('<table border="1" style="border-collapse:collapse;border:1px solid #000;font-family:Arial,sans-serif;font-size:12px;">');
    html.push('<tr style="background:#d9d9d9;font-weight:bold;"><th style="border:1px solid #000;padding:6px;">Fecha</th><th style="border:1px solid #000;padding:6px;">Detalle</th><th style="border:1px solid #000;padding:6px;width:120px;">N° Ticket</th></tr>');
    grupos.forEach(g => {
      const header = `${g.fecha} · Hora inicio: ${g.ini} | Hora término: ${g.fin}${g.total ? ' | Total de horas: ' + g.total : ''}`;
      html.push(`<tr style=\"background:#cfe2ff;\"><td colspan=\"3\" style=\"border:1px solid #000;padding:6px;\">${header}</td></tr>`);
      g.detalles.forEach(d => {
        html.push(`<tr><td style=\"border:1px solid #000;padding:6px;\"></td><td style=\"border:1px solid #000;padding:6px;white-space:pre-line;\">${d.detalle}</td><td style=\"border:1px solid #000;padding:6px;text-align:center;\">${d.ticket}</td></tr>`);
      });
    });
    html.push(`<tr><td colspan=\"3\" style=\"border:1px solid #000;padding:6px;font-weight:bold;\">Total de horas extra realizadas${totalHorasTabla ? ': ' + totalHorasTabla : ''}</td></tr>`);
    html.push('</table>');
    const textPlain = grupos.map(g => {
      const header = `${g.fecha} · Hora inicio: ${g.ini} | Hora término: ${g.fin}${g.total ? ' | Total de horas: ' + g.total : ''}`;
      const dets = g.detalles.map(d => `\t${d.detalle}\t${d.ticket}`).join('\n');
      return header + '\n' + dets;
    }).join('\n');
    const finalPlain = textPlain + `\nTotal de horas extra realizadas${totalHorasTabla ? ': ' + totalHorasTabla : ''}`;
    const fallbackCopyPlain = (txt) => {
      const ta = document.createElement('textarea');
      ta.value = txt;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    };

    const fallbackCopyHtml = (htmlStr, plainStr) => {
      const container = document.createElement('div');
      container.innerHTML = htmlStr;
      container.style.position = 'fixed';
      container.style.pointerEvents = 'none';
      container.style.opacity = '0';
      document.body.appendChild(container);
      const range = document.createRange();
      range.selectNodeContents(container);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      const ok = document.execCommand('copy');
      sel.removeAllRanges();
      document.body.removeChild(container);
      if (!ok) fallbackCopyPlain(plainStr);
    };

    const htmlString = html.join('');

    if (navigator.clipboard && navigator.clipboard.write) {
      const item = new ClipboardItem({
        'text/html': new Blob([htmlString], { type: 'text/html' }),
        'text/plain': new Blob([finalPlain], { type: 'text/plain' })
      });
      navigator.clipboard.write([item]).then(
        () => window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }),
        () => { fallbackCopyHtml(htmlString, finalPlain); window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }); }
      );
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(finalPlain).then(
        () => window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }),
        () => { fallbackCopyHtml(htmlString, finalPlain); window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }); }
      );
    } else {
      fallbackCopyHtml(htmlString, finalPlain);
      window.appModal?.show({
        title: 'Tabla copiada',
        message: 'Tabla copiada al portapapeles.',
        tone: 'success'
      });
    }
  });
}
</script>
</div> <!-- #page-content -->
</body>
</html>



