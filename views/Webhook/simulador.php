<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine/login.php');
if (!auth_can('simulador')) {
    header('Location: /redmine/views/Dashboard/dashboard.php');
    exit;
}
require_once __DIR__ . '/../../controllers/simulador.php';

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();

// Número del usuario logueado (si existe) para pre-cargar el remitente
$loggedNumber = '';
$usersPath = __DIR__ . '/../../data/usuarios.json';
$users = [];
if (file_exists($usersPath)) {
    $users = json_decode(file_get_contents($usersPath), true) ?: [];
}
if (!empty($_SESSION['user']['id']) && is_array($users)) {
    foreach ($users as $u) {
        if (!is_array($u)) continue;
        if ((string)($u['id'] ?? '') === (string)$_SESSION['user']['id']) {
            $loggedNumber = $u['numero_celular'] ?? '';
            break;
        }
    }
}

[$webhookUrl, $result, $error, $payload] = handle_simulador();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Simular Webhook</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine/assets/theme.css" rel="stylesheet">
  <style>
    body { background: #f6f8fb; }
    .card { border: none; box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    .btn-icon { display: inline-flex; align-items: center; gap: .35rem; }
    .btn-spinner .spin-icon { animation: spin 1s linear infinite; font-size: 1rem; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'webhook'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-broadcast-pin';
    $heroTitle = 'Simular webhook';
    $heroSubtitle = 'Envía un mensaje de prueba al endpoint configurado';
    include __DIR__ . '/../partials/hero.php';
  ?>

  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3">
        <div class="icon rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;">
          <i class="bi bi-send"></i>
        </div>
        <h5 class="card-title mb-0">Enviar mensaje de prueba</h5>
      </div>
      <form method="post" class="row g-3" id="sim-form" aria-live="polite">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <div class="col-md-4">
          <label class="form-label">URL del webhook</label>
          <div class="input-group">
            <span class="input-group-text" id="webhook-prefix">http://</span>
            <input name="webhook_url" class="form-control" value="<?= $h($webhookUrl) ?>" placeholder="localhost:8000/webhook" aria-describedby="webhook-help">
          </div>
          <div id="webhook-help" class="form-text">Usa la IP/localhost del servidor que recibirá el POST.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">N&uacute;mero (remitente)</label>
          <input name="numero" id="numero" class="form-control" value="<?= $h($loggedNumber) ?>" placeholder="+569..." required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Seleccionar usuario (opcional)</label>
          <select id="usuario-select" class="form-select">
            <option value="">-- Selecciona un usuario --</option>
            <?php foreach ($users as $u): if (!is_array($u)) continue;
              $nombre = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
              $num = $u['numero_celular'] ?? '';
            ?>
            <option value="<?= $h($num) ?>" data-numero="<?= $h($num) ?>"><?= $h($nombre ?: ($u['id'] ?? '')) ?><?= $num ? ' - ' . $h($num) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Si eliges un usuario, se rellenar&aacute; el n&uacute;mero autom&aacute;ticamente.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Mensaje / texto</label>
          <textarea name="mensaje" class="form-control" rows="3" placeholder="problema impresora, poli uro, francisca" required></textarea>
          <div class="form-text">Limítate a una frase clara para que el webhook imprima datos concretos.</div>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-success w-100 btn-icon" type="submit" id="btn-submit">
            <i class="bi bi-check-lg" aria-hidden="true"></i>
            <span>Enviar</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="card mb-3 border-primary border border-opacity-25">
    <div class="card-body">
      <p class="mb-1"><strong>Consejo rápido:</strong> Mantén esta vista abierta mientras pruebas el endpoint de Redmine. La mayoría de los errores se deben a un token expirado o a no tener permiso para escribir en el servidor.</p>
      <ul class="mb-0 small">
        <li>Verifica que el webhook URL responde localmente (puedes usar `curl` o Postman).</li>
        <li>El botón “Enviar” queda deshabilitado mientras se realiza la petición.</li>
        <li>Si la respuesta demora más de 5s, revisa el log (`log/webhook.log`) o recarga la página.</li>
      </ul>
    </div>
  </div>

  <?php if ($result || $error): ?>
    <div class="alert <?= $result ? 'alert-success' : 'alert-danger' ?>" role="status" aria-live="polite">
      <?= $result ? 'Respuesta:' : 'Error:' ?> <?= $h($result ?: $error) ?>
    </div>
  <?php endif; ?>

  <?php if ($payload): ?>
    <div class="card">
      <div class="card-header">Payload enviado</div>
      <div class="card-body">
        <pre class="mb-0"><?= $h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const userSelect = document.getElementById('usuario-select');
  const numeroInput = document.getElementById('numero');
  if (userSelect && numeroInput) {
    userSelect.addEventListener('change', () => {
      const num = userSelect.selectedOptions[0]?.dataset.numero || '';
      if (num) numeroInput.value = num;
    });
  }
</script>
</div> <!-- #page-content -->
</body>
</html>
<script>
  (() => {
    const userSelect = document.getElementById('usuario-select');
    const numeroInput = document.getElementById('numero');
    const submitBtn = document.getElementById('btn-submit');
    const form = document.getElementById('sim-form');

    if (userSelect && numeroInput) {
      userSelect.addEventListener('change', () => {
        const num = userSelect.selectedOptions[0]?.dataset.numero || '';
        if (num) numeroInput.value = num;
      });
    }

    if (form && submitBtn) {
      form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-pressed', 'true');
        const spinner = submitBtn.querySelector('.btn-spinner');
        if (spinner) spinner.classList.remove('d-none');
        const text = submitBtn.querySelector('.btn-text');
        if (text) text.textContent = 'Enviando...';
      });
    }
  })();
</script>
