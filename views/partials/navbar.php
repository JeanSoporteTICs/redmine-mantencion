<?php
require_once __DIR__ . '/../../controllers/auth.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
auth_start_session();
$h = $h ?? fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$activeNav = $activeNav ?? '';
$sessionTimeout = auth_config_timeout();
$lastActivity = $_SESSION['last_activity'] ?? time();
$remaining = max(0, $sessionTimeout - (time() - $lastActivity));
$role = auth_get_user_role();
$maintenanceSettings = maintenance_mode_settings();
$maintenanceMode = !empty($maintenanceSettings['enabled']);
$maintenanceUntil = maintenance_mode_until_text();
$maintenanceNoticeKey = sha1(($maintenanceSettings['started_at'] ?? '') . '|' . ($maintenanceSettings['until'] ?? '') . '|' . ($maintenanceMode ? '1' : '0'));
$navItems = [
    ['key' => 'mensajes', 'label' => 'Reportes', 'href' => '../Dashboard/dashboard.php', 'icon' => 'bi-inboxes', 'can' => true],
    ['key' => 'manual', 'label' => 'Pendiente manual', 'href' => '../Pendientes/manual.php', 'icon' => 'bi-pencil-square', 'can' => auth_can('simulador')],
    ['key' => 'horas', 'label' => 'Horas extra', 'href' => '../HorasExtra/horas_extra.php', 'icon' => 'bi-clock-history', 'can' => auth_can('horas_extra')],
    ['key' => 'historico', 'label' => 'Hist&oacute;rico', 'href' => '../Historico/historico.php', 'icon' => 'bi-archive', 'can' => auth_can('historico')],
    ['key' => 'procedimientos', 'label' => 'Procedimientos', 'href' => '../Procedimientos/procedimientos.php', 'icon' => 'bi-journal-richtext', 'can' => auth_can('procedimientos')],
    ['key' => 'usuarios', 'label' => 'Usuarios', 'href' => '../Usuarios/usuarios.php', 'icon' => 'bi-people', 'can' => auth_can('usuarios')],
    ['key' => 'configuracion', 'label' => 'Configuraci&oacute;n', 'href' => '../Configuracion/configuracion.php', 'icon' => 'bi-sliders', 'can' => auth_can('configuracion') || auth_can('categorias')],
    ['key' => 'estadisticas', 'label' => 'Estad&iacute;sticas', 'href' => '../Estadisticas/estadisticas.php', 'icon' => 'bi-bar-chart-line', 'can' => auth_can('estadisticas')],
    ['key' => 'security', 'label' => 'Actividad reciente', 'href' => '../Security/activity.php', 'icon' => 'bi-activity', 'can' => auth_can('actividad')],
];
?>
<nav class="navbar navbar-expand-xl sb-navbar navbar-dark sticky-top">
  <div class="container-fluid sb-navbar-shell">
    <div class="sb-navbar-top">
      <a class="navbar-brand sb-navbar-brand" href="../Dashboard/dashboard.php">
        <span class="sb-brand-mark"><i class="bi bi-grid-1x2-fill"></i></span>
        <span>Panel</span>
      </a>
      <div class="sb-nav-actions d-flex align-items-center gap-2">
        <span class="sb-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1" id="session-timer" data-remaining="<?= $h($remaining) ?>" data-timeout="<?= $h($sessionTimeout) ?>">
          <i class="bi bi-clock"></i><span id="session-timer-text">--:--</span>
        </span>
        <?php if ($maintenanceMode): ?>
          <span class="sb-maintenance-badge d-none d-md-inline-flex" title="Mantenci&oacute;n activa<?= $maintenanceUntil !== '' ? ' hasta ' . $h($maintenanceUntil) : '' ?>">
            <i class="bi bi-tools"></i>
            <span>Mantenci&oacute;n activa<?= $maintenanceUntil !== '' ? ' hasta ' . $h($maintenanceUntil) : '' ?></span>
          </span>
        <?php endif; ?>
        <?php if (!empty($_SESSION['user']['nombre'])): ?>
          <span class="sb-user-pill text-white-50 small d-none d-sm-inline"><i class="bi bi-person-circle"></i> Hola, <strong><?= $h($_SESSION['user']['nombre']) ?></strong></span>
        <?php endif; ?>
        <a class="btn btn-outline-light btn-sm sb-logout-btn" href="/redmine-mantencion/logout.php"><i class="bi bi-box-arrow-right"></i> <span>Cerrar Sesi&oacute;n</span></a>
        <button class="navbar-toggler sb-navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-controls="navMenu" aria-expanded="false" aria-label="Abrir navegaci&oacute;n">
          <span class="navbar-toggler-icon"></span>
        </button>
      </div>
    </div>
    <div class="collapse navbar-collapse sb-navbar-menu" id="navMenu">
      <ul class="navbar-nav sb-nav-list me-auto mb-0">
        <?php foreach ($navItems as $item): ?>
          <?php if (!$item['can']) { continue; } ?>
          <?php $isActive = $activeNav === $item['key']; ?>
          <li class="nav-item">
            <a class="nav-link sb-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= $h($item['href']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
              <i class="bi <?= $h($item['icon']) ?>"></i>
              <span><?= $item['label'] ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
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
      'horas_extra.php',
      'procedimientos/procedimientos.php',
      'procedimientos.php'
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
  const extendBtn = document.getElementById('btn-extend-session');
  const extendPwd = document.getElementById('session-password');
  const extendMsg = document.getElementById('session-msg');
  const closeBtn = document.getElementById('btn-logout-session');
  const el = document.getElementById('session-timer');
  const textEl = document.getElementById('session-timer-text') || el;
  const baseTimeout = el ? (parseInt(el.getAttribute('data-timeout'), 10) || 300) : 300;
  let remaining = el ? (parseInt(el.getAttribute('data-remaining'), 10) || baseTimeout) : baseTimeout;
  let expiresAt = Date.now() + (remaining * 1000);
  const logoutUrl = '/redmine-mantencion/logout.php';
  const modalEl = document.getElementById('sessionModal');
  const modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
  const modalTitleEl = modalEl ? modalEl.querySelector('.modal-title') : null;
  const modalBodyTextEl = modalEl ? modalEl.querySelector('.modal-body > p') : null;
  let modalShown = false;
  let sessionExpired = false;

  let tickHandle = null;
  function setTimerAppearance(secondsLeft) {
    if (!el) return;
    if (secondsLeft <= 20) {
      el.className = 'sb-session-badge badge bg-danger text-light d-inline-flex align-items-center gap-1';
    } else if (secondsLeft <= 60) {
      el.className = 'sb-session-badge badge bg-warning text-dark d-inline-flex align-items-center gap-1';
    } else {
      el.className = 'sb-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1';
    }
  }

  function setModalState(expired) {
    sessionExpired = expired;
    if (modalTitleEl) modalTitleEl.textContent = expired ? 'Sesion expirada' : 'Sesion por expirar';
    if (modalBodyTextEl) {
      modalBodyTextEl.textContent = expired
        ? 'Tu sesion ya expiro. Debes iniciar sesion nuevamente.'
        : 'Tu sesion expira pronto. Deseas continuar?';
    }
    if (extendPwd) {
      extendPwd.disabled = false;
      if (expired && extendMsg) extendMsg.textContent = '';
    }
    if (extendBtn) {
      extendBtn.disabled = false;
      extendBtn.textContent = expired ? 'Continuar sesion' : 'Continuar sesion';
    }
    if (closeBtn) {
      closeBtn.textContent = expired ? 'Cancelar' : 'Cerrar sesion';
    }
  }

  function getRemainingSeconds() {
    return Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
  }
  function tick() {
    if (!el) return;
    remaining = getRemainingSeconds();
    if (remaining <= 0) {
      textEl.textContent = '00:00';
      setTimerAppearance(0);
      setModalState(true);
      if (modal && !modalShown) {
        modal.show();
        modalShown = true;
        if (extendPwd) setTimeout(() => extendPwd.focus(), 120);
      }
      return;
    }
    if (modal && !modalShown && remaining <= 60) {
      setModalState(false);
      modal.show();
      modalShown = true;
      if (extendPwd) setTimeout(() => extendPwd.focus(), 120);
    }
    const m = Math.floor(remaining / 60).toString().padStart(2, '0');
    const s = (remaining % 60).toString().padStart(2, '0');
    textEl.textContent = `${m}:${s}`;
    setTimerAppearance(remaining);
    tickHandle = setTimeout(tick, 1000);
  }
  function restartTick() {
    if (tickHandle) clearTimeout(tickHandle);
    tick();
  }

  function syncTimerState() {
    remaining = getRemainingSeconds();
    if (remaining <= 0) {
      restartTick();
      return;
    }
    if (modalShown && remaining > 60 && modal) {
      modal.hide();
      modalShown = false;
      setModalState(false);
      if (extendMsg) extendMsg.textContent = '';
    }
    restartTick();
  }

  function applySessionRefresh(data) {
    if (!data || !data.ok) return false;
    remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
    expiresAt = Date.now() + (remaining * 1000);
    modalShown = false;
    setModalState(false);
    if (extendMsg) extendMsg.textContent = '';
    if (modal) modal.hide();
    restartTick();
    return true;
  }

  window.redmineSessionTouch = async function redmineSessionTouch() {
    try {
      const resp = await fetch('/redmine-mantencion/session_touch.php', {
        method: 'POST',
        headers: {'X-Requested-With': 'session-touch'},
        credentials: 'same-origin'
      });
      const data = await resp.json();
      applySessionRefresh(data);
      return data;
    } catch (error) {
      return {ok: false};
    }
  };

  restartTick();
  document.addEventListener('visibilitychange', syncTimerState);
  window.addEventListener('focus', syncTimerState);
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      if (sessionExpired) {
        if (modal) modal.hide();
        modalShown = false;
        return;
      }
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
        const resp = await fetch('/redmine-mantencion/session_extend.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'password=' + encodeURIComponent(pwd)
        });
        const data = await resp.json();
        if (data.ok) {
          remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
          expiresAt = Date.now() + (remaining * 1000);
          modalShown = false;
          setModalState(false);
          extendPwd.value = '';
          if (extendMsg) extendMsg.textContent = 'Sesión extendida.';
          restartTick();
          if (modal) setTimeout(() => modal.hide(), 400);
        } else {
          if (extendMsg) extendMsg.textContent = data.msg || 'Contraseña incorrecta.';
        }
      } catch (e) {
        if (extendMsg) extendMsg.textContent = 'No se pudo extender la Sesión.';
      }
    });
  }
  setModalState(false);
});
</script>

<?php if ($maintenanceMode): ?>
<div class="modal fade" id="maintenanceNoticeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title"><i class="bi bi-tools"></i> Plataforma en mantenci&oacute;n</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">La plataforma se encuentra en mantenci&oacute;n. Mientras est&eacute; activa, no se podr&aacute;n ingresar ni importar datos nuevos.</p>
        <?php if ($maintenanceUntil !== ''): ?>
          <div class="alert alert-warning mb-0"><i class="bi bi-clock"></i> Hora estimada: <?= $h($maintenanceUntil) ?></div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button>
      </div>
    </div>
  </div>
</div>
<script>
window.addEventListener('load', () => {
  const el = document.getElementById('maintenanceNoticeModal');
  const noticeKey = 'redmine-maintenance-notice:<?= $h($maintenanceNoticeKey) ?>';
  let alreadySeen = false;
  try {
    alreadySeen = window.localStorage.getItem(noticeKey) === '1';
  } catch (error) {
    alreadySeen = false;
  }
  if (el && window.bootstrap && !alreadySeen) {
    window.bootstrap.Modal.getOrCreateInstance(el).show();
    el.addEventListener('hidden.bs.modal', () => {
      try {
        window.localStorage.setItem(noticeKey, '1');
      } catch (error) {}
    }, { once: true });
  }
});
</script>
<?php endif; ?>

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
