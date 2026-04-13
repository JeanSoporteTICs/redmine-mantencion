<?php
require_once __DIR__ . '/init_paths.php';
require_once __DIR__ . '/controllers/auth.php';
auth_start_session();

// Rate limiting básico por sesión
$maxAttempts = 5;
$lockSeconds = 300;
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['login_lock_until'] = $_SESSION['login_lock_until'] ?? 0;

$error = null;
// CSRF token
if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $_SESSION['login_lock_until']) {
        $error = 'Demasiados intentos. Intenta de nuevo en unos minutos.';
    } elseif (!hash_equals($_SESSION['csrf_login'], $_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido. Recarga la página.';
    } else {
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        if (auth_login($user, $pass)) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_lock_until'] = 0;
            header('Location: views/Dashboard/dashboard.php');
            exit;
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= $maxAttempts) {
                $_SESSION['login_lock_until'] = time() + $lockSeconds;
            }
            $error = 'Credenciales inválidas. Usa tu ID o RUT y tu contraseña.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #4e73df 0%, #36b9cc 100%); min-height: 100vh; }
    .card { border: none; border-radius: 14px; box-shadow: 0 16px 30px rgba(0,0,0,0.18); }
    .form-control:focus { box-shadow: 0 0 0 .2rem rgba(78,115,223,.25); }
  </style>
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
      <div class="card">
        <div class="card-body p-4">
          <div class="text-center mb-3">
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:56px;height:56px;font-size:1.4rem;">
              <i class="bi bi-shield-lock"></i>
            </div>
            <h4 class="mt-2 mb-0">Iniciar sesión</h4>
            <small class="text-muted">Usa tu ID o RUT y tu contraseña</small>
          </div>
          <?php if ($error): ?>
            <div class="alert alert-danger" id="alert-msg"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
              <label class="form-label">Usuario (ID o RUT)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input name="username" class="form-control" autocomplete="username" required autofocus>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Contraseña</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input name="password" type="password" class="form-control" autocomplete="current-password" required>
              </div>
              <div class="form-text">Recuerda: diferencia mayúsculas/minúsculas.</div>
            </div>
            <div class="d-flex justify-content-end align-items-center mb-3">
              <a class="small text-muted" href="#" onclick="alert('Contacta al administrador para restablecer.');return false;">¿Olvidaste tu contraseña?</a>
            </div>
            <div class="d-grid">
              <button class="btn btn-primary btn-lg" type="submit">
                <i class="bi bi-box-arrow-in-right"></i> Ingresar
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const alertMsg = document.getElementById('alert-msg');
if (alertMsg) setTimeout(() => alertMsg.classList.add('d-none'), 5000);
</script>
</body>
</html>
