<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root','gestor'], '/redmine/login.php');
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
  <link href="/redmine/assets/theme.css" rel="stylesheet">
  <style>
    body { background: #f6f8fb; }
    /* Ajustes de spacing para que el navbar quede pegado arriba sin gap */
    body { margin: 0; padding-top: 0 !important; }
    .navbar { margin-top: 0 !important; margin-bottom: 0; }
    .card { border: none; box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    .btn-icon { display: inline-flex; align-items: center; gap: .35rem; }
    .table thead th { font-weight: 600; text-transform: uppercase; font-size: .78rem; letter-spacing: .02em; }
    .form-icon-feedback {
      position: absolute;
      top: 50%;
      right: 16px;
      transform: translateY(-50%);
      font-size: 1.1rem;
      opacity: 0;
      transition: opacity .2s;
      pointer-events: none;
    }
    .form-icon-feedback.valid { color: #198754; opacity: 1; }
    .form-icon-feedback.invalid { color: #dc3545; opacity: 1; }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'usuarios'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-people';
    $heroTitle = 'Usuarios';
    $heroSubtitle = 'Gestión de usuarios y credenciales';
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
              <th scope="col">Apellido</th>
              <th scope="col">RUT</th>
              <th scope="col">Celular</th>
              <th scope="col">Estamento</th>
              <th scope="col">Rol</th>
              <th scope="col">API</th>
              <th scope="col" style="width:240px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($usuarios as $u): ?>
            <?php $rowId = 'u-' . preg_replace('/[^a-zA-Z0-9]/','', $u['id'] ?? uniqid()); ?>
            <tr>
              <td data-col="id"><?= $h($u['id'] ?? ($u['rut_sin_dv'] ?? '')) ?></td>
              <td data-col="nombre"><?= $h($u['nombre']) ?></td>
              <td data-col="apellido"><?= $h($u['apellido']) ?></td>
              <td data-col="rut"><?= $h($u['rut']) ?></td>
              <td data-col="celular"><?= $h($u['numero_celular']) ?></td>
              <td data-col="estamento"><?= $h($u['estamento']) ?></td>
              <td data-col="rol"><?= $h($u['rol'] ?? 'usuario') ?></td>
              <td data-col="api"><?= $h($u['api']) ?></td>
              <td class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $h($u['id']) ?>"
                  data-rut="<?= $h($u['rut']) ?>"
                  data-nombre="<?= $h($u['nombre']) ?>"
                  data-apellido="<?= $h($u['apellido']) ?>"
                  data-numero_celular="<?= $h($u['numero_celular']) ?>"
                  data-estamento="<?= $h($u['estamento']) ?>"
                  data-rol="<?= $h($u['rol'] ?? 'usuario') ?>"
                  data-api="<?= $h($u['api']) ?>"
                  aria-label="Editar usuario">
                  <i class="bi bi-pencil-square"></i> Editar
                </button>
                <form method="post" onsubmit="return confirm('&iquest;Eliminar este usuario?')" class="m-0">
                  <input type="hidden" name="id" value="<?= $h($u['id']) ?>">
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

<!-- Modal Editar Usuario -->
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
            <div class="col-md-4 position-relative"><label class="form-label">RUT</label><input name="rut" id="em-rut" class="form-control" placeholder="xx.xxx.xxx-x"><span class="form-icon-feedback" id="em-rut-icon"></span></div>
            <div class="col-md-4 position-relative"><label class="form-label">Celular</label><input name="numero_celular" id="em-numero_celular" class="form-control" placeholder="+56..."><span class="form-icon-feedback" id="em-phone-icon"></span></div>
            <div class="col-md-4"><label class="form-label">Nombre</label><input name="nombre" id="em-nombre" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Apellido</label><input name="apellido" id="em-apellido" class="form-control" required></div>
            <div class="col-md-4">
              <label class="form-label">Estamento</label>
              <select name="estamento" id="em-estamento" class="form-select">
                <!-- <option value="">Estamento</option> -->
                <option value="administrativo">Administrativo</option>
                <option value="tecnico">Técnico</option>
                <option value="profesional">Profesional</option>
              </select>
            </div>
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
            <div class="col-md-6"><label class="form-label">Nueva Contraseña</label><input type="password" name="password" id="em-password" class="form-control" placeholder="Opcional"></div>
            <div class="col-md-6"><label class="form-label">Repetir Contraseña</label><input type="password" name="password_confirm" id="em-password_confirm" class="form-control" placeholder="Opcional"></div>
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

<!-- Modal Crear Usuario -->
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
            <div class="col-md-4"><label class="form-label">ID (manual)</label><input name="rut_sin_dv" id="new-id" class="form-control" placeholder="ID" aria-label="ID"></div>
            <div class="col-md-4 position-relative"><label class="form-label">RUT</label><input name="rut" id="new-rut" class="form-control" placeholder="xx.xxx.xxx-x" aria-label="RUT"><span class="form-icon-feedback" id="new-rut-icon"></span></div>
            <div class="col-md-4 position-relative"><label class="form-label">Celular</label><input name="numero_celular" class="form-control" placeholder="+569..." id="new-numero-celular"><span class="form-icon-feedback" id="new-phone-icon"></span></div>
            <div class="col-md-4"><label class="form-label">Nombre</label><input name="nombre" class="form-control" placeholder="Nombre" required></div>
            <div class="col-md-4"><label class="form-label">Apellido</label><input name="apellido" class="form-control" placeholder="Apellido" required></div>
            <div class="col-md-4">
              <label class="form-label">Estamento</label>
              <select name="estamento" class="form-select">
                <!-- <option value="">Estamento</option> -->
                <option value="administrativo">Administrativo</option>
                <option value="tecnico">Técnico</option>
                <option value="profesional">Profesional</option>
              </select>
            </div>
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
            <div class="col-md-6"><label class="form-label">Contraseña;a</label><input type="password" name="password" class="form-control" placeholder="Contraseña;a"></div>
            <div class="col-md-6"><label class="form-label">Repetir Contraseña;a</label><input type="password" name="password_confirm" class="form-control" placeholder="Repetir Contraseña;a"></div>
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

function normalizePhoneForComparison(raw) {
  let digits = (raw || '').replace(/\D/g, '');
  if (!digits) return '';
  if (digits.length === 8 && digits.startsWith('7')) {
    digits = '9' + digits;
  }
  if (digits.length > 9) {
    digits = digits.slice(-9);
  }
  return digits;
}

function setupPhonePrefix() {
  const formatPhoneValue = (raw) => {
    let digits = (raw || '').replace(/\D/g, '');
    if (!digits) return '';
    if (digits.length === 8 && digits.startsWith('7')) {
      digits = '9' + digits;
    }
    if (digits.length > 9) {
      digits = digits.slice(-9);
    }
    return '+569' + digits.slice(-9);
  };

  const ensure = (input, force = false) => {
    const formatted = formatPhoneValue(input.value);
    if (!formatted) return;
    if (force || !input.value.startsWith('+569')) {
      input.value = formatted;
    }
  };

  document.querySelectorAll('input[name="numero_celular"]').forEach(input => {
    input.addEventListener('blur', () => ensure(input, true));
    input.addEventListener('input', () => {
      const digits = input.value.replace(/\D/g, '');
      if (digits.length >= 8) {
        ensure(input);
      }
    });
  });
}

function setupEditModal() {
  const modal = document.getElementById('editModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget; if (!btn) return;
    const set = (id, attr) => { const el = document.getElementById(id); if (el) el.value = btn.getAttribute(attr) || ''; };
    set('em-id', 'data-id');
    set('em-id-display', 'data-id');
    set('em-rut', 'data-rut');
    set('em-nombre', 'data-nombre');
    set('em-apellido', 'data-apellido');
    set('em-numero_celular', 'data-numero_celular');
    const phoneEl = document.getElementById('em-numero_celular');
    if (phoneEl) phoneEl.setAttribute('data-original-phone', btn.getAttribute('data-numero_celular') || '');
    set('em-estamento', 'data-estamento');
    set('em-rol', 'data-rol');
    set('em-api', 'data-api');
    const rutInput = document.getElementById('em-rut');
    if (rutInput) rutInput.setAttribute('data-original-rut', btn.getAttribute('data-rut') || '');
    document.getElementById('em-password').value = '';
    document.getElementById('em-password_confirm').value = '';
  });
}

// Validaci&oacute;n en tiempo real de ID y RUT duplicados
const existingIds = <?= json_encode(array_values(array_map(fn($u)=>$u['id']??'', $usuarios)), JSON_UNESCAPED_UNICODE) ?>.filter(Boolean);
const existingRutBase = <?= json_encode(array_values(array_map(fn($u)=>preg_replace('/[^0-9kK]/','',$u['rut']??''), $usuarios)), JSON_UNESCAPED_UNICODE) ?>.map(v => v ? v.slice(0, -1) : '').filter(Boolean);
const existingPhones = <?= json_encode(array_values(array_map(fn($u)=>preg_replace('/[^0-9]/','',$u['numero_celular']??''), $usuarios)), JSON_UNESCAPED_UNICODE) ?>;

function markDuplicate(input, isDup, msg = 'Ya existe', iconEl = null) {
  if (!input) return;
  input.classList.toggle('is-invalid', isDup);
  let fb = input.parentElement.querySelector('.invalid-feedback');
  if (!fb) {
    fb = document.createElement('div');
    fb.className = 'invalid-feedback';
    input.parentElement.appendChild(fb);
  }
  fb.textContent = isDup ? msg : '';
  if (iconEl) {
    iconEl.classList.remove('valid', 'invalid');
    if (isDup) {
      iconEl.classList.add('invalid');
      iconEl.innerHTML = '<i class="bi bi-x-circle-fill"></i>';
    } else if (input.value.trim()) {
      iconEl.classList.add('valid');
      iconEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
    } else {
      iconEl.innerHTML = '';
    }
  }
}

function checkDuplicatesCreate() {
  const idInput = document.getElementById('new-id');
  const rutInput = document.getElementById('new-rut');
  const idVal = (idInput?.value || '').trim();
  const rutVal = (rutInput?.value || '').replace(/[^0-9kK]/gi,'');
  const rutBase = rutVal.length > 1 ? rutVal.slice(0, -1) : '';
  markDuplicate(idInput, idVal !== '' && existingIds.includes(idVal), 'El ID ya existe');
  markDuplicate(rutInput, rutBase !== '' && existingRutBase.includes(rutBase), 'El RUT ya existe', document.getElementById('new-rut-icon'));
  checkPhoneDuplicateNew();
}

const existingPhoneDigits = existingPhones.map(normalizePhoneForComparison).filter(Boolean);

function normalizePhoneDigits(raw) {
  return normalizePhoneForComparison(raw);
}

function checkPhoneDuplicateNew() {
  const phoneInput = document.getElementById('new-numero-celular');
  if (!phoneInput) return;
  const digits = normalizePhoneDigits(phoneInput.value);
  markDuplicate(phoneInput, digits && existingPhoneDigits.includes(digits), 'El celular ya existe', document.getElementById('new-phone-icon'));
}

function checkPhoneDuplicateEdit() {
  const phoneInput = document.getElementById('em-numero_celular');
  if (!phoneInput) return;
  const currentDigits = normalizePhoneDigits(phoneInput.value);
  const originalDigits = normalizePhoneDigits(phoneInput.getAttribute('data-original-phone') || '');
  const duplicate = currentDigits && currentDigits !== originalDigits && existingPhoneDigits.includes(currentDigits);
  markDuplicate(phoneInput, duplicate, 'El celular ya existe', document.getElementById('em-phone-icon'));
}

function checkDuplicatesEdit() {
  const rutInput = document.getElementById('em-rut');
  if (!rutInput) return;
  const originalRut = (rutInput.getAttribute('data-original-rut') || '').replace(/[^0-9kK]/gi,'').toUpperCase();
  const currentBase = originalRut.length > 1 ? originalRut.slice(0, -1) : '';
  const rutVal = (rutInput.value || '').replace(/[^0-9kK]/gi,'').toUpperCase();
  const rutBase = rutVal.length > 1 ? rutVal.slice(0, -1) : '';
  const dupRut = rutBase !== '' && rutBase !== currentBase && existingRutBase.includes(rutBase);
  markDuplicate(rutInput, dupRut, 'El RUT ya existe');
}

['new-id','new-rut'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', checkDuplicatesCreate);
});
const phoneNew = document.getElementById('new-numero-celular');
if (phoneNew) phoneNew.addEventListener('input', checkPhoneDuplicateNew);

function calcDv(num) {
  let sum = 0, mul = 2;
  for (let i = num.length - 1; i >= 0; i--) {
    sum += parseInt(num[i], 10) * mul;
    mul = mul === 7 ? 2 : mul + 1;
  }
  const res = 11 - (sum % 11);
  if (res === 11) return '0';
  if (res === 10) return 'K';
  return String(res);
}

function formatRut(raw) {
  const clean = (raw || '').replace(/[^0-9kK]/gi, '').toUpperCase();
  if (clean.length < 2) return clean;
  const cuerpo = clean.slice(0, -1);
  const dv = clean.slice(-1);
  const cuerpoFmt = cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${cuerpoFmt}-${dv}`;
}

function validateRutInput(input) {
  if (!input) return;
  const clean = (input.value || '').replace(/[^0-9kK]/gi, '').toUpperCase();
  let valid = true;
  if (clean.length < 2) valid = false;
  else {
    const cuerpo = clean.slice(0, -1);
    const dv = clean.slice(-1);
    valid = calcDv(cuerpo) === dv;
  }
  input.classList.toggle('is-invalid', !valid && clean.length > 0);
  let fb = input.parentElement.querySelector('.invalid-feedback');
  if (!fb) {
    fb = document.createElement('div');
    fb.className = 'invalid-feedback';
    input.parentElement.appendChild(fb);
  }
  fb.textContent = (!valid && clean.length > 0) ? 'RUT inv&aacute;lido' : '';
  if (valid && clean.length >= 2) {
    input.value = formatRut(clean);
  }
  return valid;
}

function setupRutHandlers() {
  ['new-rut','em-rut'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('blur', () => {
      const ok = validateRutInput(el);
      if (el.id === 'new-rut') checkDuplicatesCreate();
      if (el.id === 'em-rut' && ok) checkDuplicatesEdit();
    });
    el.addEventListener('input', () => {
      // limpiar feedback mientras escribe
      el.classList.remove('is-invalid');
      const fb = el.parentElement.querySelector('.invalid-feedback');
      if (fb) fb.textContent = '';
      if (el.id === 'new-rut') checkDuplicatesCreate();
      if (el.id === 'em-rut') checkDuplicatesEdit();
    });
  });
}

setupPhonePrefix();
setupEditModal();
setupRutHandlers();
const phoneEdit = document.getElementById('em-numero_celular');
if (phoneEdit) phoneEdit.addEventListener('input', checkPhoneDuplicateEdit);
</script>
</div> <!-- #page-content -->
</body>
</html>






