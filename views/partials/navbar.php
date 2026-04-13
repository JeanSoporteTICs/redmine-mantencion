<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_start_session();
$h = $h ?? fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$activeNav = $activeNav ?? '';
$sessionTimeout = auth_config_timeout();
$lastActivity = $_SESSION['last_activity'] ?? time();
$remaining = max(0, $sessionTimeout - (time() - $lastActivity));
$role = auth_get_user_role();
?>
<nav class="navbar navbar-expand-lg sb-navbar navbar-dark sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">Panel</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'mensajes' ? 'active' : '' ?>" href="../Dashboard/dashboard.php">Reportes</a></li>
        <?php if (auth_can('simulador')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'webhook' ? 'active' : '' ?>" href="../Webhook/simulador.php">Simular webhook</a></li>
        <?php endif; ?>
        <?php if (auth_can('horas_extra')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'horas' ? 'active' : '' ?>" href="../HorasExtra/horas_extra.php">Horas extra</a></li>
        <?php endif; ?>
        <?php if (auth_can('historico')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'historico' ? 'active' : '' ?>" href="../Historico/historico.php">Hist&oacute;rico</a></li>
        <?php endif; ?>
        <?php if (auth_can('usuarios')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'usuarios' ? 'active' : '' ?>" href="../Usuarios/usuarios.php">Usuarios</a></li>
        <?php endif; ?>
        <?php if (auth_can('configuracion') || auth_can('categorias') || auth_can('unidades')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'configuracion' ? 'active' : '' ?>" href="../Configuracion/configuracion.php">Configuraci&oacute;n</a></li>
        <?php endif; ?>
        <?php if (auth_can('estadisticas')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'estadisticas' ? 'active' : '' ?>" href="../Estadisticas/estadisticas.php">Estad&iacute;sticas</a></li>
        <?php endif; ?>
        <?php if (auth_can('estadisticas_manual')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'estadisticas_api' ? 'active' : '' ?>" href="../Estadisticas/estadisticas_manual.php">Estad&iacute;sticas Redmine API</a></li>
        <?php endif; ?>
        <?php if (auth_can('actividad')): ?>
          <li class="nav-item"><a class="nav-link <?= $activeNav === 'security' ? 'active' : '' ?>" href="../Security/activity.php">Actividad reciente</a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-light text-dark d-inline-flex align-items-center gap-1" id="session-timer" data-remaining="<?= $h($remaining) ?>" data-timeout="<?= $h($sessionTimeout) ?>">
          <i class="bi bi-clock"></i><span id="session-timer-text">--:--</span>
        </span>
        <?php if (!empty($_SESSION['user']['nombre'])): ?>
          <span class="text-white-50 small d-none d-sm-inline">Hola, <strong><?= $h($_SESSION['user']['nombre']) ?></strong></span>
        <?php endif; ?>
        <a class="btn btn-outline-light btn-sm" href="/redmine/logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
      </div>
    </div>
  </div>
</nav>
<script>
window.addEventListener('load', () => {
  // Navegaci&oacute;n parcial: carga vistas sin recargar navbar/footer si existe #page-content en destino.
  (function partialNav() {
    const enablePartialNav = true;
    const pageContent = document.getElementById('page-content');
    if (!enablePartialNav || !pageContent || !window.history || !window.fetch) return;
    const forceFullPaths = [
      'dashboard/dashboard.php',
      'dashboard.php',
      'horasextra/horas_extra.php',
      'horas_extra.php'
    ];
    const navLinks = document.querySelectorAll('.navbar-nav a.nav-link');
    const setActive = (urlStr) => {
      navLinks.forEach(a => {
        if (a.href === urlStr) a.classList.add('active'); else a.classList.remove('active');
      });
    };
    const executeScripts = (doc) => {
      const scripts = doc.querySelectorAll('script');
      scripts.forEach(old => {
        const s = document.createElement('script');
        if (old.src) {
          s.src = old.src;
        } else {
          s.textContent = old.textContent;
        }
        document.body.appendChild(s);
      });
      // Re-disparar eventos para vistas cargadas din&aacute;micamente.
      document.dispatchEvent(new Event('DOMContentLoaded'));
      document.dispatchEvent(new Event('partial:loaded'));
    };
    const loadPage = async (url, push) => {
      const targetPath = (new URL(url, window.location.href)).pathname.toLowerCase();
      if (forceFullPaths.some(p => targetPath.endsWith(p))) {
        window.location.href = url;
        return;
      }
      try {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'partial-nav' } });
        let text = await res.text();
        text = text.replace(/^\uFEFF/, ''); // eliminar BOM inicial
        const doc = new DOMParser().parseFromString(text, 'text/html');
        const newContent = doc.getElementById('page-content');
        if (!newContent) {
          window.location.href = url;
          return;
        }
        if (newContent.querySelectorAll('script').length > 0) {
          window.location.href = url;
          return;
        }
        let contentHtml = (newContent.innerHTML || '').trim();
        contentHtml = contentHtml.replace(/\uFEFF/g, '');
        if (/<!doctype|<html|<head/i.test(contentHtml)) {
          window.location.href = url;
          return;
        }
        pageContent.innerHTML = contentHtml;
        // limpiar nodos de texto vacíos/BOM
        Array.from(pageContent.childNodes).forEach(n => {
          if (n.nodeType === 3 && /^\s*$/.test(n.textContent.replace(/\uFEFF/g, ''))) {
            n.remove();
          }
        });
        if (doc.title) document.title = doc.title;
        if (push) history.pushState({ url }, '', url);
        setActive(url);
        window.scrollTo(0, 0);
        executeScripts(doc);
      } catch (err) {
        window.location.href = url;
      }
    };
    const handleClick = (e) => {
      const a = e.currentTarget;
      if (a.target === '_blank') return;
      const url = new URL(a.href, window.location.href);
      if (url.origin !== window.location.origin) return;
      e.preventDefault();
      loadPage(url.toString(), true);
    };
    navLinks.forEach(a => a.addEventListener('click', handleClick));
    window.addEventListener('popstate', (ev) => {
      const url = ev.state?.url || window.location.href;
      loadPage(url, false);
    });
  })();

  // Temporizador de Sesión;n
  const el = document.getElementById('session-timer');
  const textEl = document.getElementById('session-timer-text') || el;
  const baseTimeout = el ? (parseInt(el.getAttribute('data-timeout'), 10) || 300) : 300;
  let remaining = el ? (parseInt(el.getAttribute('data-remaining'), 10) || baseTimeout) : baseTimeout;
  const logoutUrl = '/redmine/logout.php';
  const modalEl = document.getElementById('sessionModal');
  const modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
  let modalShown = false;

  let tickHandle = null;
  function tick() {
    if (!el) return;
    if (remaining <= 0) {
      textEl.textContent = '00:00';
      el.className = 'badge bg-danger text-light d-inline-flex align-items-center gap-1';
      if (modal && !modalShown) {
        modal.show();
        modalShown = true;
      }
      return;
    }
    if (modal && !modalShown && remaining <= 60) {
      modal.show();
      modalShown = true;
    }
    const m = Math.floor(remaining / 60).toString().padStart(2, '0');
    const s = (remaining % 60).toString().padStart(2, '0');
    textEl.textContent = `${m}:${s}`;
    if (remaining <= 20) {
      el.className = 'badge bg-danger text-light d-inline-flex align-items-center gap-1';
    } else if (remaining <= 60) {
      el.className = 'badge bg-warning text-dark d-inline-flex align-items-center gap-1';
    } else {
      el.className = 'badge bg-light text-dark d-inline-flex align-items-center gap-1';
    }
    remaining -= 1;
    tickHandle = setTimeout(tick, 1000);
  }
  tick();

  const extendBtn = document.getElementById('btn-extend-session');
  const extendPwd = document.getElementById('session-password');
  const extendMsg = document.getElementById('session-msg');
  const closeBtn = document.getElementById('btn-logout-session');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      window.location.href = logoutUrl;
    });
  }
  if (extendBtn && extendPwd) {
    extendBtn.addEventListener('click', async () => {
      if (extendMsg) extendMsg.textContent = '';
      const pwd = extendPwd.value.trim();
      if (!pwd) {
        if (extendMsg) extendMsg.textContent = 'Ingresa tu Contraseña.';
        return;
      }
      try {
        const resp = await fetch('/redmine/session_extend.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'password=' + encodeURIComponent(pwd)
        });
        const data = await resp.json();
        if (data.ok) {
          remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
          modalShown = false;
          extendPwd.value = '';
          if (extendMsg) extendMsg.textContent = 'Sesión extendida.';
          if (tickHandle) clearTimeout(tickHandle);
          tick();
          if (modal) setTimeout(() => modal.hide(), 400);
        } else {
          if (extendMsg) extendMsg.textContent = data.msg || 'Contraseña incorrecta.';
        }
      } catch (e) {
        if (extendMsg) extendMsg.textContent = 'No se pudo extender la Sesión.';
      }
    });
  }
});
</script>

<!-- Modal sesion -->
<div class="modal fade" id="sessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Sesión por expirar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Tu Sesión expira pronto. &iquest;Deseas continuar?</p>
        <div class="mb-3">
          <label class="form-label">Contraseña</label>
          <input type="password" id="session-password" class="form-control" autocomplete="current-password">
          <div class="form-text text-danger" id="session-msg"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="btn-logout-session">Cerrar Sesión</button>
        <button type="button" class="btn btn-primary" id="btn-extend-session">Continuar Sesión</button>
      </div>
    </div>
  </div>
</div>
