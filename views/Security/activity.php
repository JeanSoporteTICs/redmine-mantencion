<?php
require_once __DIR__ . '/../../controllers/auth.php';
require_once __DIR__ . '/../../controllers/security.php';
auth_require_role(['root', 'administrador', 'gestor'], '/redmine-mantencion/login.php');
if (!auth_can('actividad')) {
  header('Location: /redmine-mantencion/views/Dashboard/dashboard.php');
  exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['security_flash'] ?? null;
unset($_SESSION['security_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    if (($_POST['action'] ?? '') === 'clear_activity') {
        security_clear_events();
        $_SESSION['security_flash'] = 'Actividad reciente borrada.';
    }
    header('Location: /redmine-mantencion/views/Security/activity.php');
    exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$formatSecurityTimestamp = fn($ts) => (function($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Throwable $_) {
        return $value;
    }
    return $dt->setTimezone(new DateTimeZone('America/Santiago'))->format('d-m-Y H:i:s');
})($ts);
$events = security_load_events(50);
$activeNav = 'security';
$csrf = csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Actividad de seguridad'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body class="bg-light">
<?php $activeNav = 'security'; include __DIR__ . '/../partials/navbar.php'; ?>
<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-shield-lock';
      $heroTitle = 'Actividad reciente';
      $heroSubtitle = 'Accesos, CSRF y eventos críticos registrados en la plataforma.';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($flash): ?>
      <div class="alert alert-success"><?= $h($flash) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <p class="text-muted mb-3">Se registran los últimos intentos de inicio de sesión y alertas de seguridad. Si ves fallas repetidas en poco tiempo, considera rotar tokens API o revisar accesos.</p>
        <div class="d-flex justify-content-end mb-3">
          <form method="post" class="mb-0">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="clear_activity">
            <button type="submit" class="btn btn-outline-danger btn-sm">
              <i class="bi bi-trash3"></i> Limpiar actividad reciente
            </button>
          </form>
        </div>
        <?php if (empty($events)): ?>
          <div class="alert alert-info mb-0">Todavía no hay eventos registrados.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th scope="col" style="width:200px;">Fecha / hora</th>
                  <th scope="col" style="width:150px;">Evento</th>
                  <th scope="col">Detalles</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($events as $evt): ?>
                  <tr>
                    <td class="text-muted"><?= $h($formatSecurityTimestamp($evt['ts'])) ?: '----' ?></td>
                    <td><span class="badge bg-secondary"><?= $h($evt['tag']) ?></span></td>
                    <td><?= $h($evt['details']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
</body>
</html>
