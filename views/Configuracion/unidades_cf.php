<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine/login.php');
if (!auth_can('configuracion')) {
  header('Location: /redmine/views/Dashboard/dashboard.php');
  exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = csrf_token();
$cfgPath = __DIR__ . '/../../data/configuracion.json';
$dataPath = __DIR__ . '/../../data/unidades.json';

function build_cf_url($platformUrl) {
  if (!$platformUrl) return '';
  $parts = parse_url($platformUrl);
  $prefix = '';
  if (!empty($parts['path']) && strpos($parts['path'], '/gp/') !== false) {
    $prefix = '/gp';
  }
  if (preg_match('#/projects/[^/]+/issues(?:\\.json)?$#', $platformUrl)) {
    return preg_replace('#/projects/[^/]+/issues(?:\\.json)?$#', $prefix . '/custom_fields/11.json', $platformUrl);
  }
  if ($parts && !empty($parts['scheme']) && !empty($parts['host'])) {
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port . $prefix . '/custom_fields/11.json';
  }
  return '';
}

function load_unidades_local($path) {
  if (!file_exists($path)) return [];
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : [];
}
function save_unidades_local($path, $arr) {
  file_put_contents($path, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$cfg = file_exists($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : [];
$platformUrl = $cfg['platform_url'] ?? '';
$cfOverride = $cfg['unidades_url'] ?? '';
$apiKey = $cfg['platform_token'] ?? '';

$currentUserId = auth_get_user_id();
$userToken = '';
$userPath = __DIR__ . '/../../data/usuarios.json';
if ($currentUserId && file_exists($userPath)) {
  $users = json_decode(file_get_contents($userPath), true);
  if (is_array($users)) {
    foreach ($users as $u) {
      if (!is_array($u)) continue;
      if ((string)($u['id'] ?? '') === (string)$currentUserId && !empty($u['api'])) {
        $userToken = $u['api'];
        break;
      }
    }
  }
}
$apiKey = $userToken ?: $apiKey;

$cfUrl = $cfOverride ?: build_cf_url($platformUrl);
$flash = null;
$error = null;
$unidades = load_unidades_local($dataPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_validate')) csrf_validate();
if (!$apiKey) {
  $error = 'Falta token de API (usuario o plataforma).';
} elseif (!$cfUrl) {
  $error = 'URL de API inv&aacute;lida. Revisa platform_url/unidades_url.';
} else {
    $ch = curl_init($cfUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'X-Redmine-API-Key: ' . $apiKey,
        'Accept: application/json'
      ],
      CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
      $error = 'No se pudo conectar: ' . curl_error($ch) . " (URL: $cfUrl)";
    } else {
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($code >= 400) {
        $error = "HTTP $code al consultar el campo personalizado. URL: $cfUrl";
      } else {
        $json = json_decode($resp, true);
        $values = [];
        if (isset($json['custom_field']['possible_values'])) {
          $values = $json['custom_field']['possible_values'];
        } elseif (isset($json['custom_fields']) && is_array($json['custom_fields'])) {
          foreach ($json['custom_fields'] as $cf) {
            if (!is_array($cf)) continue;
            if ((string)($cf['id'] ?? '') === '11' && isset($cf['possible_values'])) {
              $values = $cf['possible_values'];
              break;
            }
          }
        }
        if (!is_array($values)) {
          $error = 'La respuesta no contiene possible_values.';
        } else {
          $parsed = [];
          foreach ($values as $v) {
            if (is_array($v) && isset($v['value'])) {
              $parsed[] = ['id' => $v['value'], 'nombre' => $v['value']];
            } elseif (is_string($v)) {
              $parsed[] = ['id' => $v, 'nombre' => $v];
            }
          }
          save_unidades_local($dataPath, $parsed);
          $unidades = $parsed;
          $flash = 'Unidades sincronizadas (' . count($parsed) . ' registros).';
        }
      }
    }
    curl_close($ch);
  }
}

$total = is_array($unidades) ? count($unidades) : 0;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Unidades solicitantes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine/assets/theme.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php $activeNav = 'configuracion'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-building';
      $heroTitle = 'Unidades solicitantes';
      $heroSubtitle = 'Sincronizadas desde custom_fields/11.json (solo lectura)';
      $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-collection"></i> Total: ' . $h($total) . '</span>';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($flash): ?><div class="alert alert-success"><?= $h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
          <div class="col-lg-6">
            <label class="form-label">URL de API</label>
            <input type="text" class="form-control" value="<?= $h($cfUrl) ?>" disabled>
          </div>
          <div class="col-lg-4">
            <label class="form-label">Token (usuario &gt; plataforma)</label>
            <input type="text" class="form-control" value="<?= $h($apiKey ? '********' : 'No definido') ?>" disabled>
          </div>
          <div class="col-lg-2 d-flex gap-2 justify-content-lg-end">
            <a class="btn btn-outline-secondary w-100" href="../Configuracion/configuracion.php"><i class="bi bi-arrow-left"></i> Volver</a>
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Actualizar</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">Lista de unidades</h5>
          <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark"><?= $h($total) ?> unidades</span>
            <input type="text" id="filter" class="form-control form-control-sm" placeholder="Filtrar" style="max-width:220px;">
          </div>
        </div>
        <div class="table-responsive">
          <table class="table align-middle" id="tbl">
            <thead><tr><th style="width:100px;">ID</th><th>Nombre</th></tr></thead>
            <tbody>
              <?php if ($unidades): foreach ($unidades as $u): ?>
                <tr>
                  <td class="text-muted"><?= $h($u['id'] ?? '') ?></td>
                  <td><?= $h($u['nombre'] ?? '') ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="2" class="text-center text-muted">A&uacute;n no hay datos sincronizados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const f = document.getElementById('filter');
  const tbl = document.getElementById('tbl');
  if (f && tbl) {
    f.addEventListener('input', () => {
      const t = f.value.toLowerCase();
      tbl.querySelectorAll('tbody tr').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(t) ? '' : 'none';
      });
    });
  }
</script>
</body>
</html>
