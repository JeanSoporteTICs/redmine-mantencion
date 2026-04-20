<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\UserModel;

use function App\Support\app_base_url;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        require_once APP_BASE_PATH . '/controllers/auth.php';
        auth_start_session();

        $maxAttempts = 5;
        $lockSeconds = 300;
        $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
        $_SESSION['login_lock_until'] = $_SESSION['login_lock_until'] ?? 0;
        $_SESSION['csrf_login'] = $_SESSION['csrf_login'] ?? bin2hex(random_bytes(16));

        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (time() < $_SESSION['login_lock_until']) {
                $error = 'Demasiados intentos. Intenta de nuevo en unos minutos.';
            } elseif (!hash_equals($_SESSION['csrf_login'], $_POST['csrf_token'] ?? '')) {
                $error = 'Token de seguridad invalido. Recarga la pagina.';
            } else {
                $user = trim((string) ($_POST['username'] ?? ''));
                $pass = trim((string) ($_POST['password'] ?? ''));

                if (auth_login($user, $pass)) {
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['login_lock_until'] = 0;
                    $this->redirect(app_base_url('views/Dashboard/dashboard.php'));
                }

                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= $maxAttempts) {
                    $_SESSION['login_lock_until'] = time() + $lockSeconds;
                }
                $error = 'Credenciales invalidas. Usa tu ID o RUT y tu contrasena.';
            }
        }

        $this->view('auth/login', [
            'error' => $error,
            'csrfToken' => $_SESSION['csrf_login'],
            'usersCount' => (new UserModel())->count(),
        ]);
    }

    public function logout(): void
    {
        require_once APP_BASE_PATH . '/controllers/auth.php';
        auth_logout();
        $this->redirect(app_base_url('login.php'));
    }

    public function extendSession(): void
    {
        require_once APP_BASE_PATH . '/controllers/auth.php';

        auth_start_session();
        header('Content-Type: application/json');

        if (empty($_SESSION['user']['id'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'No autenticado']);
            return;
        }

        $pwd = $_POST['password'] ?? '';
        $user = auth_find_user_by_id($_SESSION['user']['id']);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'Usuario no encontrado']);
            return;
        }

        $apiField = $user['api'] ?? null;
        $passField = $user['password'] ?? null;
        $ok = false;

        if ($passField) {
            if (strlen($passField) > 20 && password_verify($pwd, $passField)) {
                $ok = true;
            } elseif ($passField === $pwd) {
                $ok = true;
            }
        }

        if (!$ok && $apiField !== null && $apiField === $pwd) {
            $ok = true;
        }

        if ($ok) {
            auth_touch_activity();
            $timeout = auth_config_timeout();
            echo json_encode([
                'ok' => true,
                'msg' => 'Sesion extendida',
                'timeout' => $timeout,
                'remaining' => $timeout,
            ]);
            return;
        }

        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'Contrasena incorrecta']);
    }
}
