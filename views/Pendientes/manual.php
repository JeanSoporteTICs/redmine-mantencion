<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
if (!auth_can('simulador')) {
    header('Location: /redmine-mantencion/views/Dashboard/dashboard.php');
    exit;
}
require_once __DIR__ . '/../../controllers/pendiente_manual.php';

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
[$cfg, $users, $categorias, $form, $flash, $error] = handle_manual_pending();
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Pendiente Manual'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body>
<?php $activeNav = 'manual'; include __DIR__ . '/../partials/navbar.php'; ?>
<div id="page-content">
  <style>
    .manual-card { border: 0; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08); }
    .manual-grid label { font-weight: 600; color: #334155; }
    .manual-grid .form-control, .manual-grid .form-select { border-color: #d7deea; }
    .manual-grid .field-label { min-width: 120px; text-align: right; padding-top: 6px; font-weight: 700; color: #334155; }
    .manual-grid .field-row { display: grid; grid-template-columns: 120px minmax(0, 1fr) 120px minmax(0, 1fr); gap: 12px; align-items: start; }
    .manual-grid .field-row.single { grid-template-columns: 120px minmax(0, 1fr); }
    .manual-grid .field-row.three { grid-template-columns: 120px minmax(0, 1fr) 120px minmax(0, 1fr) 120px minmax(0, 1fr); }
    .manual-grid .toolbar { border: 1px solid #d7deea; border-bottom: 0; border-radius: .5rem .5rem 0 0; background: #f8fafc; padding: .45rem .65rem; color: #64748b; font-size: .875rem; }
    .manual-grid textarea.editor { min-height: 200px; border-top-left-radius: 0; border-top-right-radius: 0; }
    .manual-grid .helper-link { font-size: .9rem; text-decoration: none; }
    @media (max-width: 992px) {
      .manual-grid .field-row,
      .manual-grid .field-row.single,
      .manual-grid .field-row.three { grid-template-columns: 1fr; }
      .manual-grid .field-label { min-width: 0; text-align: left; padding-top: 0; }
    }
  </style>
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-pencil-square';
      $heroTitle = 'Pendiente Manual';
      $heroSubtitle = 'Formulario manual con la misma estructura operativa del ticket en Redmine.';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($flash): ?>
      <div class="alert alert-success"><?= $h($flash) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= $h($error) ?></div>
    <?php endif; ?>

    <div class="card manual-card">
      <div class="card-body manual-grid">
        <form method="post" class="d-flex flex-column gap-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="project_id" value="<?= $h($form['project_id'] ?? $cfg['project_id'] ?? 48) ?>">

          <div class="field-row single">
            <div class="field-label">Proyecto *</div>
            <div>
              <select class="form-select" disabled>
                <option selected>&raquo; <?= $h($cfg['project_name'] ?? 'Backlog Mantención TI') ?></option>
              </select>
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Tipo *</div>
            <div>
              <select name="tracker_id" class="form-select" required>
                <?php foreach (($cfg['trackers'] ?? []) as $tracker): ?>
                  <option value="<?= $h($tracker['id'] ?? '') ?>" <?= (string)($form['tracker_id'] ?? '') === (string)($tracker['id'] ?? '') ? 'selected' : '' ?>>
                    <?= $h($tracker['nombre'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div></div>
            <div></div>
          </div>

          <div class="field-row single">
            <div class="field-label">Asunto *</div>
            <div><input name="asunto" class="form-control" value="<?= $h($form['asunto'] ?? '') ?>" required></div>
          </div>

          <div class="field-row single">
            <div class="field-label">Descripción</div>
            <div>
              <div class="toolbar">Modificar | Previsualizar</div>
              <textarea name="descripcion" class="form-control editor"><?= $h($form['descripcion'] ?? '') ?></textarea>
              <div class="form-text">Campo opcional.</div>
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Estado *</div>
            <div>
              <select name="status_id" class="form-select">
                <?php foreach (($cfg['estados'] ?? []) as $estado): ?>
                  <option value="<?= $h($estado['id'] ?? '') ?>" <?= (string)($form['status_id'] ?? '') === (string)($estado['id'] ?? '') ? 'selected' : '' ?>>
                    <?= $h($estado['nombre'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-label">Fecha de inicio</div>
            <div>
              <input type="date" name="fecha_inicio" class="form-control" value="<?= $h(manual_pending_date_for_input($form['fecha_inicio'] ?? '')) ?>">
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Prioridad *</div>
            <div>
              <select name="priority_id" class="form-select">
                <?php foreach (($cfg['prioridades'] ?? []) as $prioridad): ?>
                  <option value="<?= $h($prioridad['id'] ?? '') ?>" <?= (string)($form['priority_id'] ?? '') === (string)($prioridad['id'] ?? '') ? 'selected' : '' ?>>
                    <?= $h($prioridad['nombre'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-label">Fecha fin</div>
            <div>
              <input type="date" name="fecha_fin" class="form-control" value="<?= $h(manual_pending_date_for_input($form['fecha_fin'] ?? '')) ?>">
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Asignado a</div>
            <div>
              <div class="d-flex align-items-center gap-2">
                <select name="asignado_a" id="asignado_a" class="form-select">
                  <option value=""></option>
                  <?php foreach ($users as $user): ?>
                    <option value="<?= $h($user['id']) ?>" <?= (string)($form['asignado_a'] ?? '') === (string)$user['id'] ? 'selected' : '' ?>>
                      <?= $h($user['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="assign-me">Assign to me</button>
              </div>
            </div>
            <div class="field-label">Tiempo estimado</div>
            <div>
              <div class="input-group">
                <input name="tiempo_estimado" class="form-control" value="<?= $h($form['tiempo_estimado'] ?? '') ?>">
                <span class="input-group-text">Horas</span>
              </div>
            </div>
          </div>

          <div class="field-row">
            <div class="field-label">Categoría</div>
            <div>
              <input name="categoria" list="categoria-options" class="form-control" placeholder="Escribe o selecciona una categoría" value="<?= $h($form['categoria'] ?? '') ?>">
              <datalist id="categoria-options">
                <?php foreach ($categorias as $categoria): ?>
                  <?php $nombreCategoria = trim((string)($categoria['nombre'] ?? '')); ?>
                  <option value="<?= $h($nombreCategoria) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
            <div></div>
            <div></div>
          </div>

          <div class="field-row single">
            <div class="field-label">Solicitante</div>
            <div><input name="solicitante" class="form-control" placeholder="Persona que solicita la actividad" value="<?= $h($form['solicitante'] ?? '') ?>" required></div>
          </div>

          <div class="field-row">
            <div class="field-label">Anexo</div>
            <div><input name="anexo" class="form-control" placeholder="numero telefónico de contacto" value="<?= $h($form['anexo'] ?? '') ?>"></div>
            <div class="field-label">Correo</div>
            <div><input name="core_email" class="form-control" placeholder="Correo electrónico" value="<?= $h($form['core_email'] ?? '') ?>"></div>
          </div>

          <div class="field-row">
            <div class="field-label">Unidad</div>
            <div><input name="unidad" class="form-control" placeholder="Lugar donde realizar la actividad" value="<?= $h($form['unidad'] ?? '') ?>"></div>
            <div class="field-label">Hora Extra *</div>
            <div>
              <select name="hora_extra" class="form-select">
                <option value="0" <?= ($form['hora_extra'] ?? '0') === '0' ? 'selected' : '' ?>>No</option>
                <option value="1" <?= ($form['hora_extra'] ?? '0') === '1' ? 'selected' : '' ?>>Sí</option>
              </select>
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 pt-2">
            <a class="btn btn-outline-secondary" href="/redmine-mantencion/views/Dashboard/dashboard.php">Volver a Reportes</a>
            <button class="btn btn-primary" type="submit">Crear pendiente</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
  (() => {
    const assignMeBtn = document.getElementById('assign-me');
    const assignedSelect = document.getElementById('asignado_a');
    const currentUserId = <?= json_encode((string)(auth_get_user_id() ?? '')) ?>;
    if (assignMeBtn && assignedSelect) {
      assignMeBtn.addEventListener('click', () => {
        assignedSelect.value = currentUserId;
      });
    }
  })();
</script>
</body>
</html>
