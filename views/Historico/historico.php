<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/dashboard.php';
if (!auth_can('historico')) {
  header('Location: /redmine-mantencion/views/Dashboard/dashboard.php');
  exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

function historico_read_json_file(string $file): array {
  $raw = @file_get_contents($file);
  if (!is_string($raw) || $raw === '') return [];
  if (str_starts_with($raw, "\xEF\xBB\xBF")) {
    $raw = substr($raw, 3);
  }
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// --- Helpers para eliminar registros ---
function delete_reporte(string $base, string $id): bool {
  $changed = false;
  if (!is_dir($base)) return false;
  foreach (glob($base . '/*/*.json') as $file) {
    $data = historico_read_json_file($file);
    if (!$data) continue;
    $new = array_values(array_filter($data, fn($r) => !is_array($r) || ($r['id'] ?? '') !== $id));
    if (count($new) !== count($data)) {
      file_put_contents($file, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $changed = true;
    }
  }
  return $changed;
}

function delete_horas_extra(string $base, string $id): bool {
  $changed = false;
  if (!is_dir($base)) return false;
  foreach (glob($base . '/*/*.json') as $file) {
    $groups = historico_read_json_file($file);
    if (!$groups) continue;
    $newGroups = [];
    foreach ($groups as $g) {
      if (!isset($g['reports']) || !is_array($g['reports'])) continue;
      $reports = array_values(array_filter($g['reports'], fn($r) => !is_array($r) || ($r['id'] ?? '') !== $id));
      if (empty($reports)) continue;
      $g['reports'] = $reports;
      $newGroups[] = $g;
    }
    if ($newGroups !== $groups) {
      file_put_contents($file, json_encode($newGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      $changed = true;
    }
  }
  return $changed;
}

// --- Eliminar si se solicito ---
$alert = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['fuente']) && $_POST['action'] === 'delete') {
  $id = trim($_POST['id']);
  $src = $_POST['fuente'];
  $ok = false;
  if ($src === 'reportes') {
    $ok = delete_reporte(__DIR__ . '/../../data/reportes', $id);
  } elseif ($src === 'horas_extra') {
    $ok = delete_horas_extra(__DIR__ . '/../../data/horasExtras', $id);
  } elseif ($src === 'mensajes') {
    // no borramos los vigentes desde historico
    $ok = false;
  }
  $alert = $ok ? 'Reporte eliminado.' : 'No se pudo eliminar el registro.';
}

function norm_date(string $str): string {
  $str = trim($str);
  if ($str === '') return '';
  if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $str, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str)) return $str;
  return '';
}

function historico_matches_search(array $row, string $needle): bool {
  $needle = dashboard_normalize_text($needle);
  if ($needle === '') {
    return true;
  }

  $haystacks = [
    trim((string)($row['solicitante'] ?? '')),
    trim((string)($row['core_detalle_nombre'] ?? '')),
    trim((string)($row['core_detalle_run'] ?? '')),
  ];

  foreach ((array)($row['core_detalle_items'] ?? []) as $item) {
    if (!is_array($item)) {
      continue;
    }
    $haystacks[] = trim((string)($item['detalle_nombre'] ?? ''));
    $haystacks[] = trim((string)($item['detalle_run'] ?? ''));
  }

  foreach ($haystacks as $candidate) {
    $normalized = dashboard_normalize_text($candidate);
    if ($normalized !== '' && str_contains($normalized, $needle)) {
      return true;
    }
  }

  return false;
}

function load_reportes(string $base): array {
  $out = [];
  if (!is_dir($base)) return $out;
  foreach (glob($base . '/*/*.json') as $file) {
    $data = historico_read_json_file($file);
    if (!$data) continue;
    foreach ($data as $row) {
      if (!is_array($row)) continue;
      $row = dashboard_expand_message($row);
      $row['_fuente'] = 'reportes';
      $out[] = $row;
    }
  }
  return $out;
}

function load_horas_extras(string $base): array {
  $out = [];
  if (!is_dir($base)) return $out;
  foreach (glob($base . '/*/*.json') as $file) {
    $groups = historico_read_json_file($file);
    if (!$groups) continue;
    foreach ($groups as $g) {
      if (!isset($g['reports']) || !is_array($g['reports'])) continue;
      $fechaGrupo = $g['fecha'] ?? '';
      foreach ($g['reports'] as $rep) {
        if (!is_array($rep)) continue;
        $rep['fecha'] = $rep['fecha'] ?? $fechaGrupo;
        $rep['_fuente'] = 'horas_extra';
        $rep['hora_extra'] = 'SI';
        $out[] = $rep;
      }
    }
  }
  return $out;
}

function historico_record_matches_current_user(array $row, string $userId, array $userNames): bool {
  $assignedId = trim((string)($row['asignado_a'] ?? ''));
  if ($assignedId !== '' && $assignedId === $userId) {
    return true;
  }
  $candidates = [
    trim((string)($row['core_usuario_asignado'] ?? '')),
    trim((string)($row['asignado_nombre'] ?? '')),
  ];
  foreach ($userNames as $expected) {
    if ($expected === '') continue;
    foreach ($candidates as $candidate) {
      if ($candidate !== '' && dashboard_name_tokens_match($expected, $candidate)) {
        return true;
      }
    }
  }
  return false;
}

$f_desde     = norm_date($_GET['desde'] ?? '');
$f_hasta     = norm_date($_GET['hasta'] ?? '');
$f_usuario   = trim($_GET['usuario'] ?? '');
$f_categoria = strtolower(trim($_GET['categoria'] ?? ''));
$f_fuente    = $_GET['fuente'] ?? '';
$f_tipo      = dashboard_normalize_text(trim($_GET['tipo_solicitud'] ?? ''));
$f_busqueda  = trim($_GET['buscar'] ?? '');
$roles       = auth_load_roles();
$roleName    = auth_get_user_role();
$roleCfg     = $roles[$roleName] ?? [];
$scopePermitido = $roleCfg['historico_scope'] ?? 'asignados';
$scopeBloqueado = ($scopePermitido === 'asignados');
$showActions = auth_can('historico_acciones');
if (!$showActions && $roleName === 'gestor' && !array_key_exists('historico_acciones', $roleCfg)) {
  // compatibilidad con roles antiguos sin la clave
  $showActions = true;
}
$tableColspan = $showActions ? 9 : 8;
$f_scope = $_GET['mensajes_scope'] ?? ($scopePermitido === 'todos' ? 'todos' : 'asignados');
if (!in_array($f_scope, ['todos','asignados'], true)) $f_scope = 'asignados';
if ($scopePermitido === 'asignados') {
  $f_scope = 'asignados';
}
$userId = (string)auth_get_user_id();
$userNames = array_values(array_filter([
  trim((string)($_SESSION['user']['nombre'] ?? '')),
  trim((string)((auth_find_user_by_id($userId)['nombre'] ?? ''))),
], fn($value) => $value !== ''));

$items  = [];
$items  = array_merge($items, load_reportes(__DIR__ . '/../../data/reportes'));
$items  = array_merge($items, load_horas_extras(__DIR__ . '/../../data/horasExtras'));

$filtered = [];
foreach ($items as $row) {
  if (!is_array($row)) continue;
  if (strtolower(trim((string)($row['estado'] ?? ''))) !== 'procesado') continue;
  $fecha = norm_date($row['fecha'] ?? ($row['fecha_inicio'] ?? ''));
  if ($fecha === '') continue;
  if ($f_desde && $fecha < $f_desde) continue;
  if ($f_hasta && $fecha > $f_hasta) continue;
  if ($f_fuente && ($row['_fuente'] ?? '') !== $f_fuente) {
    continue;
  }
  $tipoSolicitud = dashboard_normalize_text((string)($row['core_tipo_solicitud'] ?? ($row['asunto'] ?? '')));
  if ($f_tipo !== '' && $tipoSolicitud !== $f_tipo) continue;
  if ($f_usuario !== '' && (string)($row['asignado_a'] ?? '') !== (string)$f_usuario) continue;
  if ($f_scope === 'asignados' && !historico_record_matches_current_user($row, $userId, $userNames)) continue;
  $cat = strtolower($row['categoria'] ?? '');
  if ($f_categoria !== '' && $cat !== $f_categoria) continue;
  if (!historico_matches_search($row, $f_busqueda)) continue;
  $row['_fecha_norm'] = $fecha;
  $filtered[] = $row;
}

usort($filtered, function ($a, $b) {
  return strcmp($b['_fecha_norm'] ?? '', $a['_fecha_norm'] ?? '');
});

$usuariosSel = [];
$catsSel     = [];
$tiposSel    = [];
foreach ($items as $r) {
  if (!is_array($r)) continue;
  $usuariosSel[(string)($r['asignado_a'] ?? '')] = $r['asignado_nombre'] ?? ($r['asignado_a'] ?? '');
  $catsSel[strtolower($r['categoria'] ?? '')]    = $r['categoria'] ?? '';
  $tipoLabel = trim((string)($r['core_tipo_solicitud'] ?? ($r['asunto'] ?? '')));
  $tipoKey = dashboard_normalize_text($tipoLabel);
  if ($tipoKey !== '' && $tipoLabel !== '') {
    $tiposSel[$tipoKey] = $tipoLabel;
  }
}
ksort($usuariosSel);
ksort($catsSel);
asort($tiposSel);
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Historico'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body class="bg-light">
<?php $activeNav = 'historico'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-archive';
    $heroTitle = 'Histórico';
    $heroSubtitle = 'Registros procesados archivados y horas extra.';
    include __DIR__ . '/../partials/hero.php';
  ?>

  <?php if ($alert): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      <?= $h($alert) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <form id="filter-form" class="card card-body shadow-sm mb-3" method="get" aria-live="polite">
    <div class="row g-3 align-items-end">
      <?php
        $filterFields = [
          ['label' => 'Desde', 'name' => 'desde', 'type' => 'date', 'value' => $f_desde, 'col' => 2, 'aria_label' => 'Fecha desde'],
          ['label' => 'Hasta', 'name' => 'hasta', 'type' => 'date', 'value' => $f_hasta, 'col' => 2, 'aria_label' => 'Fecha hasta'],
          ['label' => 'Fuente', 'name' => 'fuente', 'type' => 'select', 'options' => ['' => 'Todas', 'reportes' => 'Reportes', 'horas_extra' => 'Horas extra'], 'value' => $f_fuente, 'col' => 2],
          ['label' => 'Tipo solicitud', 'name' => 'tipo_solicitud', 'type' => 'select', 'options' => ['' => 'Todos'] + $tiposSel, 'value' => $f_tipo, 'col' => 3],
          ['label' => 'Buscar solicitante / nombre / rut', 'name' => 'buscar', 'type' => 'text', 'value' => $f_busqueda, 'col' => 3, 'aria_label' => 'Buscar por solicitante, nombre o rut'],
        ];
        if (!$scopeBloqueado) {
          $filterFields[] = [
            'label' => 'Asignado',
            'name' => 'usuario',
            'type' => 'select',
            'options' => ['' => 'Todos'] + $usuariosSel,
            'value' => $f_usuario,
            'col' => 2,
          ];
        }
        $filterFields[] = [
          'label' => 'Categoría',
          'name' => 'categoria',
          'type' => 'select',
          'options' => ['' => 'Todas'] + $catsSel,
          'value' => $f_categoria,
          'col' => 2,
        ];
      ?>
      <?php foreach ($filterFields as $field): ?>
        <?php include __DIR__ . '/../partials/filter-field.php'; ?>
      <?php endforeach; ?>
      <div class="col-md-2">
        <button
          type="submit"
          id="btn-apply"
          class="btn btn-primary w-100"
          data-bs-spinner="true"
          aria-label="Aplicar filtros"
          aria-pressed="false">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
      </div>
      <div class="col-md-2">
        <a
          class="btn btn-outline-secondary w-100"
          id="btn-clear"
          href="historico.php"
          aria-label="Limpiar filtros"
          aria-pressed="false">
          <i class="bi bi-x-circle"></i> Limpiar
        </a>
      </div>
    </div>
    <div id="filter-feedback" class="d-none mt-3 alert alert-info d-flex align-items-center" role="status" aria-live="polite">
      <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
      Aplicando filtros...
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body p-0 position-relative">
      <div class="table-responsive position-relative">
        <div id="table-loader" class="loader-overlay d-none" role="status" aria-live="polite">
          <div class="d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-lg text-primary" role="status" aria-hidden="true"></span>
            <strong>Cargando registros…</strong>
          </div>
        </div>
        <table class="table table-striped align-middle mb-0" role="grid" aria-label="Histórico de reportes" aria-busy="false">
          <thead class="table-light">
            <tr class="position-sticky top-0 bg-light">
              <th scope="col">Fecha</th>
              <th scope="col" class="text-truncate" style="max-width: 160px;">Solicitante</th>
              <th scope="col" class="text-truncate" style="max-width: 220px;">Tipo solicitud</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Establecimiento</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Departamento</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Asignado CORE</th>
              <th scope="col">Estado CORE</th>
              <th scope="col">Fuente</th>
              <th scope="col">Detalle</th>
              <?php if ($showActions): ?>
                <th scope="col">Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($filtered)): ?>
              <tr><td colspan="<?= $tableColspan ?>" class="text-center text-muted py-4">Sin registros para el criterio seleccionado.</td></tr>
            <?php else: ?>
              <?php foreach ($filtered as $row): ?>
                <?php
                  $previewRows = dashboard_detail_preview_rows($row);
                  $previewRowsJson = $h((string)json_encode(array_values($previewRows), JSON_UNESCAPED_UNICODE));
                  $previewColumnsJson = $h((string)json_encode(dashboard_core_detail_table_schema($row), JSON_UNESCAPED_UNICODE));
                  $detalleDescripcion = ($row['fuente'] ?? '') === 'manual'
                    ? trim((string)($row['descripcion'] ?? ''))
                    : '';
                ?>
                <tr>
                  <td><?= $h($row['_fecha_norm'] ?? '') ?></td>
                  <td class="text-truncate" style="max-width: 160px;" title="<?= $h($row['solicitante'] ?? '') ?>"><?= $h($row['solicitante'] ?? '') ?></td>
                  <td class="text-truncate" style="max-width: 220px;" title="<?= $h($row['core_tipo_solicitud'] ?? ($row['asunto'] ?? '')) ?>"><?= $h($row['core_tipo_solicitud'] ?? ($row['asunto'] ?? '')) ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '')) ?>"><?= $h($row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '')) ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?>"><?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? ''))) ?>"><?= $h($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? ''))) ?></td>
                  <td><?= $h($row['core_estado'] ?? '') ?></td>
                  <?php $fuenteLabel = $row['_fuente'] ?? ''; ?>
                  <td><span class="badge bg-secondary"><?= $h($fuenteLabel) ?></span></td>
                  <td>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary historico-detail-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#historicoDetalleModal"
                      data-preview_rows="<?= $previewRowsJson ?>"
                      data-preview_columns="<?= $previewColumnsJson ?>"
                      data-fuente="<?= $h($row['fuente'] ?? '') ?>"
                      data-core_tipo_solicitud="<?= $h($row['core_tipo_solicitud'] ?? '') ?>"
                      data-asunto="<?= $h($row['asunto'] ?? '') ?>"
                      data-solicitante="<?= $h($row['solicitante'] ?? '') ?>"
                      data-descripcion="<?= $h($detalleDescripcion) ?>"
                    >
                      <i class="bi bi-eye"></i>
                    </button>
                  </td>
                  <?php if ($showActions): ?>
                    <td>
                      <form method="post" class="m-0">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $h($row['id'] ?? '') ?>">
                        <input type="hidden" name="fuente" value="<?= $h($row['_fuente'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Eliminar este registro del hist&oacute;rico?')">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>


  <div class="modal fade" id="historicoDetalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Detalle histórico</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <div class="fw-semibold" id="historico-detalle-titulo"></div>
            <div class="text-muted small" id="historico-detalle-solicitante"></div>
          </div>
          <div id="historico-detalle-tabla-wrap" class="table-responsive border rounded">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light" id="historico-detalle-head"></thead>
              <tbody id="historico-detalle-body"></tbody>
            </table>
          </div>
          <div id="historico-detalle-descripcion-wrap" class="d-none">
            <label for="historico-detalle-descripcion" class="form-label fw-semibold">Descripción</label>
            <textarea id="historico-detalle-descripcion" class="form-control" rows="10" readonly></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('filter-form');
      const feedback = document.getElementById('filter-feedback');
      const table = document.querySelector('table[role=\"grid\"]');
      const loader = document.getElementById('table-loader');
      const btnApply = document.getElementById('btn-apply');
      const btnClear = document.getElementById('btn-clear');

      const setLoading = (state) => {
        if (feedback) feedback.classList.toggle('d-none', !state);
        if (loader) loader.classList.toggle('d-none', !state);
        if (table) table.setAttribute('aria-busy', state ? 'true' : 'false');
        if (btnApply) {
          btnApply.disabled = state;
          btnApply.setAttribute('aria-pressed', state ? 'true' : 'false');
        }
      };

      if (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          setLoading(true);
          setTimeout(() => form.submit(), 60);
        });
      }

      if (btnClear) {
        btnClear.addEventListener('click', function () {
          btnClear.setAttribute('aria-pressed', 'true');
        });
      }

      setLoading(false);

      const historicoDetalleModal = document.getElementById('historicoDetalleModal');
      if (historicoDetalleModal) {
        historicoDetalleModal.addEventListener('show.bs.modal', function (event) {
          const triggerBtn = event.relatedTarget;
          if (!triggerBtn) return;

          const titleEl = document.getElementById('historico-detalle-titulo');
          const subtitleEl = document.getElementById('historico-detalle-solicitante');
          const tableWrap = document.getElementById('historico-detalle-tabla-wrap');
          const tableHead = document.getElementById('historico-detalle-head');
          const tableBody = document.getElementById('historico-detalle-body');
          const descriptionWrap = document.getElementById('historico-detalle-descripcion-wrap');
          const descriptionField = document.getElementById('historico-detalle-descripcion');

          const escapeHtml = value => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');

          let rows = [];
          let columns = [];
          try {
            rows = JSON.parse(triggerBtn.getAttribute('data-preview_rows') || '[]');
          } catch (error) {
            rows = [];
          }
          try {
            columns = JSON.parse(triggerBtn.getAttribute('data-preview_columns') || '[]');
          } catch (error) {
            columns = [];
          }

          const fuente = (triggerBtn.getAttribute('data-fuente') || '').trim().toLowerCase();
          const asunto = triggerBtn.getAttribute('data-asunto') || triggerBtn.getAttribute('data-core_tipo_solicitud') || 'Detalle histórico';
          const solicitante = triggerBtn.getAttribute('data-solicitante') || '';
          const descripcion = triggerBtn.getAttribute('data-descripcion') || '';

          if (titleEl) titleEl.textContent = asunto;
          if (subtitleEl) subtitleEl.textContent = solicitante ? `Solicitante: ${solicitante}` : '';

          if (fuente === 'manual') {
            if (tableWrap) tableWrap.classList.add('d-none');
            if (descriptionWrap) descriptionWrap.classList.remove('d-none');
            if (descriptionField) descriptionField.value = descripcion;
            return;
          }

          if (descriptionWrap) descriptionWrap.classList.add('d-none');
          if (tableWrap) tableWrap.classList.remove('d-none');
          if (descriptionField) descriptionField.value = '';

          if (!Array.isArray(columns) || columns.length === 0) {
            columns = [{ label: 'Detalle', key: 'detalle_nombre' }];
          }
          if (tableHead) {
            tableHead.innerHTML = `<tr>${columns.map(col => `<th>${escapeHtml(col.label || '')}</th>`).join('')}</tr>`;
          }
          if (tableBody) {
            if (!Array.isArray(rows) || rows.length === 0) {
              tableBody.innerHTML = `<tr><td colspan="${columns.length}" class="text-center text-muted py-4">Sin detalle para mostrar.</td></tr>`;
            } else {
              tableBody.innerHTML = rows.map(row => `
                <tr>
                  ${columns.map(col => `<td>${escapeHtml(row[col.key] || '')}</td>`).join('')}
                </tr>
              `).join('');
            }
          }
        });
      }
    });
  </script>
<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
</div> <!-- #page-content -->
</body>
</html>
