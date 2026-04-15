<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root', 'gestor'], '/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/usuarios.php';
list($usuarios, $flash) = handle_usuarios();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Usuarios</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine-mantencion/assets/theme.css" rel="stylesheet">
  <style>
    body { background: #f6f8fb; }
    body { margin: 0; padding-top: 0 !important; }
    .navbar { margin-top: 0 !important; margin-bottom: 0; }
    .card { border: none; box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    .btn-icon { display: inline-flex; align-items: center; gap: .35rem; }
    .table thead th { font-weight: 600; text-transform: uppercase; font-size: .78rem; letter-spacing: .02em; }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'usuarios'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-people';
    $heroTitle = 'Usuarios';
    $heroSubtitle = 'Gestion de usuarios y credenciales';
    $heroExtras = '';
    if ($flash) {
      $heroExtras = '<div class="alert alert-success py-2 px-3 mb-0" id="flash-msg"><i class="bi bi-info-circle"></i> ' . $h($flash) . '</div>';
    }
    include __DIR__ . '/../partials/hero.php';
  ?>

  <div class="card">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-3">
        <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:260px;">
          <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;">
            <i class="bi bi-search"></i>
          </div>
          <input id="user-search" class="form-control" placeholder="Buscar usuario" aria-label="Buscar usuario">
          <span class="badge bg-light text-dark border ms-2">Total: <?= count($usuarios) ?></span>
        </div>
        <div class="d-flex gap-2">
          <form method="post" class="m-0">
            <input type="hidden" name="action" value="sync_remote">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <button class="btn btn-outline-primary btn-icon" type="submit">
              <i class="bi bi-cloud-download"></i> Importar desde Redmine
            </button>
          </form>
          <button class="btn btn-primary btn-icon" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="bi bi-person-plus"></i> Nuevo usuario
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table id="user-table" class="table table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:90px;">ID</th>
              <th scope="col">Nombre</th>
              <th scope="col">Rol</th>
              <th scope="col">API</th>
              <th scope="col" style="width:240px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($usuarios as $u): ?>
            <tr>
              <td data-col="id"><?= $h($u['id'] ?? '') ?></td>
              <td data-col="nombre"><?= $h($u['nombre'] ?? '') ?></td>
              <td data-col="rol"><?= $h($u['rol'] ?? 'usuario') ?></td>
              <td data-col="api"><?= $h($u['api'] ?? '') ?></td>
              <td class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $h($u['id'] ?? '') ?>"
                  data-nombre="<?= $h($u['nombre'] ?? '') ?>"
                  data-rol="<?= $h($u['rol'] ?? 'usuario') ?>"
                  data-api="<?= $h($u['api'] ?? '') ?>"
                  aria-label="Editar usuario">
                  <i class="bi bi-pencil-square"></i> Editar
                </button>
                <form method="post" onsubmit="return confirm('&iquest;Eliminar este usuario?')" class="m-0">
                  <input type="hidden" name="id" value="<?= $h($u['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <button class="btn btn-sm btn-outline-danger btn-icon" aria-label="Eliminar usuario"><i class="bi bi-trash"></i> Eliminar</button>
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

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Editar usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="id" id="em-id">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">ID</label><input name="id_display" id="em-id-display" class="form-control" readonly></div>
            <div class="col-md-8"><label class="form-label">Nombre completo</label><input name="nombre" id="em-nombre" class="form-control" required></div>
            <div class="col-md-4">
              <label class="form-label">Rol</label>
              <select name="rol" id="em-rol" class="form-select">
                <option value="usuario">Usuario</option>
                <option value="administrador">Administrador</option>
                <option value="gestor">Gestor</option>
                <option value="root">Root</option>
              </select>
            </div>
            <div class="col-md-8"><label class="form-label">API</label><input name="api" id="em-api" class="form-control" placeholder="API"></div>
            <div class="col-md-6"><label class="form-label">Nueva contrasena</label><input type="password" name="password" id="em-password" class="form-control" placeholder="Opcional"></div>
            <div class="col-md-6"><label class="form-label">Repetir contrasena</label><input type="password" name="password_confirm" id="em-password_confirm" class="form-control" placeholder="Opcional"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn btn-primary">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Crear usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">ID (manual)</label><input name="id_manual" id="new-id" class="form-control" placeholder="ID" aria-label="ID"></div>
            <div class="col-md-8"><label class="form-label">Nombre completo</label><input name="nombre" class="form-control" placeholder="Nombre completo" required></div>
            <div class="col-md-4">
              <label class="form-label">Rol</label>
              <select name="rol" class="form-select">
                <option value="usuario" selected>Usuario</option>
                <option value="administrador">Administrador</option>
                <option value="gestor">Gestor</option>
                <option value="root">Root</option>
              </select>
            </div>
            <div class="col-md-8"><label class="form-label">API</label><input name="api" class="form-control" placeholder="API"></div>
            <div class="col-md-6"><label class="form-label">Contrasena</label><input type="password" name="password" class="form-control" placeholder="Contrasena"></div>
            <div class="col-md-6"><label class="form-label">Repetir contrasena</label><input type="password" name="password_confirm" class="form-control" placeholder="Repetir contrasena"></div>
          </div>
          <div class="text-end mt-3">
            <button class="btn btn-primary btn-icon"><i class="bi bi-check-lg"></i> Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const userFilterInput = document.getElementById('user-search');
if (userFilterInput) {
  userFilterInput.addEventListener('input', () => {
    const term = userFilterInput.value.toLowerCase();
    document.querySelectorAll('#user-table tbody tr').forEach(tr => {
      const hay = Array.from(tr.querySelectorAll('[data-col]')).some(td =>
        (td.textContent || '').toLowerCase().includes(term)
      );
      tr.style.display = hay ? '' : 'none';
    });
  });
}

const flash = document.getElementById('flash-msg');
if (flash) setTimeout(() => flash.classList.add('d-none'), 5000);

function setupEditModal() {
  const modal = document.getElementById('editModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) return;
    const set = (id, attr) => {
      const el = document.getElementById(id);
      if (el) el.value = btn.getAttribute(attr) || '';
    };
    set('em-id', 'data-id');
    set('em-id-display', 'data-id');
    set('em-nombre', 'data-nombre');
    set('em-rol', 'data-rol');
    set('em-api', 'data-api');
    document.getElementById('em-password').value = '';
    document.getElementById('em-password_confirm').value = '';
  });
}

const existingIds = <?= json_encode(array_values(array_map(fn($u) => $u['id'] ?? '', $usuarios)), JSON_UNESCAPED_UNICODE) ?>.filter(Boolean);

function markDuplicate(input, isDup, msg = 'Ya existe') {
  if (!input) return;
  input.classList.toggle('is-invalid', isDup);
  let fb = input.parentElement.querySelector('.invalid-feedback');
  if (!fb) {
    fb = document.createElement('div');
    fb.className = 'invalid-feedback';
    input.parentElement.appendChild(fb);
  }
  fb.textContent = isDup ? msg : '';
}

function checkDuplicatesCreate() {
  const idInput = document.getElementById('new-id');
  const idVal = (idInput?.value || '').trim();
  markDuplicate(idInput, idVal !== '' && existingIds.includes(idVal), 'El ID ya existe');
}

const newIdInput = document.getElementById('new-id');
if (newIdInput) {
  newIdInput.addEventListener('input', checkDuplicatesCreate);
}

setupEditModal();
</script>
</div>
</body>
</html>
