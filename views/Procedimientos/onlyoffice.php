<?php
require_once __DIR__ . '/../../controllers/onlyoffice.php';

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$shareToken = trim((string)($_GET['share'] ?? ''));
$isPublicShare = $shareToken !== '';
if (!$isPublicShare) {
  auth_require_login('/redmine-mantencion/login.php');
  if (!auth_can('procedimientos')) {
    header('Location: /redmine-mantencion/views/Dashboard/dashboard.php');
    exit;
  }
}

$cfg = onlyoffice_config();
$documentServer = rtrim((string)($cfg['onlyoffice_url'] ?? ''), '/');
$procedures = procedures_read_all();
$selectedId = trim((string)($_GET['id'] ?? ''));
$officeMode = $isPublicShare ? 'view' : ((string)($_GET['mode'] ?? '') === 'view' ? 'view' : 'edit');
if ($isPublicShare) {
  $procedure = procedures_find_by_share_token($procedures, $shareToken);
  if ($selectedId !== '' && $procedure && (string)($procedure['id'] ?? '') !== $selectedId) {
    $procedure = null;
  }
} else {
  $procedure = $selectedId !== '' ? procedures_find_by_id($procedures, $selectedId) : null;
}
$fileSupported = $procedure && procedures_onlyoffice_supported($procedure);
$editorConfig = ($procedure && $fileSupported && $documentServer !== '') ? onlyoffice_editor_config($procedure, $cfg, $officeMode) : null;
$backUrl = $isPublicShare
  ? '/redmine-mantencion/views/Procedimientos/procedimientos.php?share=' . urlencode($shareToken)
  : '/redmine-mantencion/views/Procedimientos/procedimientos.php' . ($procedure ? '?id=' . urlencode((string)$procedure['id']) : '');
$activeNav = 'procedimientos';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OnlyOffice | Procedimientos</title>
  <?php $pageTitle = 'OnlyOffice'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <?php if ($documentServer !== ''): ?>
    <script src="<?= $h($documentServer) ?>/web-apps/apps/api/documents/api.js"></script>
  <?php endif; ?>
  <style>
    html, body { height: 100%; overflow: hidden; }
    body { min-height: 100vh; }
    body.onlyoffice-page #page-content { min-height: calc(100vh - var(--navbar-height)); }
    body.onlyoffice-page.proc-public-share { padding-top: 0 !important; }
    body.onlyoffice-page.proc-public-share #page-content { min-height: 100vh; }
    .office-main { height: calc(100vh - var(--navbar-height)); padding: .75rem .9rem .9rem; overflow: hidden; }
    body.onlyoffice-page.proc-public-share .office-main { height: 100vh; }
    .office-actions { height: 44px; margin-bottom: .65rem; }
    .office-shell { height: calc(100% - 52px); min-height: 0; }
    .office-frame { height: 100%; border-radius: .8rem; overflow: hidden; background: #fff; box-shadow: 0 18px 46px rgba(15, 23, 42, .16); }
    #onlyoffice-editor { width: 100%; height: 100%; }
  </style>
</head>
<body class="bg-light onlyoffice-page<?= $isPublicShare ? ' proc-public-share' : '' ?>">
<?php if (!$isPublicShare) { include __DIR__ . '/../partials/navbar.php'; } ?>
<div id="page-content">
  <main class="container-fluid office-main">
    <div class="office-actions d-flex justify-content-between align-items-center gap-2">
      <a class="btn btn-outline-secondary" href="<?= $h($backUrl) ?>">
        <i class="bi bi-arrow-left"></i> Volver
      </a>
      <?php if ($procedure && !empty($procedure['file_url'])): ?>
        <a class="btn btn-outline-primary" href="<?= $h($procedure['file_url']) ?>" download><i class="bi bi-download"></i> Descargar</a>
      <?php endif; ?>
    </div>

    <?php if (!$procedure): ?>
      <section class="card border-0 shadow-sm">
        <div class="card-body p-4">No se encontró el procedimiento.</div>
      </section>
    <?php elseif (!$fileSupported): ?>
      <section class="card border-0 shadow-sm">
        <div class="card-body p-4">
          <h1 class="h4">Archivo no editable en OnlyOffice</h1>
          <p class="text-muted mb-0">OnlyOffice edita archivos Word, Excel y PowerPoint. Para PDF usa la visualización normal.</p>
        </div>
      </section>
    <?php elseif ($documentServer === ''): ?>
      <section class="card border-0 shadow-sm">
        <div class="card-body p-4">
          <h1 class="h4">OnlyOffice no está configurado</h1>
          <p class="text-muted mb-0">Configura la URL del Document Server en Configuración > Conexión API.</p>
        </div>
      </section>
    <?php else: ?>
      <section class="office-shell">
        <div class="office-frame">
          <div id="onlyoffice-editor"></div>
        </div>
      </section>
      <script>
        (() => {
          const onlyOfficeConfig = <?= json_encode($editorConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          const editorTarget = document.getElementById('onlyoffice-editor');
          const showOfficeError = (message) => {
            if (!editorTarget) return;
            editorTarget.innerHTML = `
              <div class="h-100 d-flex align-items-center justify-content-center p-4">
                <div class="alert alert-warning shadow-sm mb-0" role="alert">
                  <div class="fw-semibold mb-1">No se pudo cargar OnlyOffice</div>
                  <div>${message}</div>
                </div>
              </div>
            `;
          };
          const startEditor = () => {
            try {
              window.docEditor = new window.DocsAPI.DocEditor('onlyoffice-editor', onlyOfficeConfig);
            } catch (error) {
              console.error('OnlyOffice init error', error);
              showOfficeError('El editor no pudo iniciar. Revisa la configuracion de OnlyOffice o la consola del navegador.');
            }
          };
          const waitForDocsApi = (attempt = 0) => {
            if (window.DocsAPI && typeof window.DocsAPI.DocEditor === 'function') {
              startEditor();
              return;
            }
            if (attempt >= 80) {
              showOfficeError('No se pudo cargar la API del Document Server. Revisa que la URL de OnlyOffice este accesible.');
              return;
            }
            window.setTimeout(() => waitForDocsApi(attempt + 1), 250);
          };
          waitForDocsApi();
        })();
        <?php if (!$isPublicShare && $officeMode === 'edit'): ?>
        (() => {
          const touchSession = () => {
            if (typeof window.redmineSessionTouch === 'function') {
              window.redmineSessionTouch();
              return;
            }
            fetch('/redmine-mantencion/session_touch.php', {
              method: 'POST',
              headers: {'X-Requested-With': 'session-touch'},
              credentials: 'same-origin'
            }).catch(() => {});
          };
          window.setTimeout(() => {
            touchSession();
            window.setInterval(touchSession, 60000);
          }, 30000);
        })();
        <?php endif; ?>
      </script>
    <?php endif; ?>
  </main>
</div>
<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
</body>
</html>
