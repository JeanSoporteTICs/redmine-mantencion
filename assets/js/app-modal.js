(function () {
  const modalEl = document.getElementById('appFeedbackModal');
  if (!modalEl || !window.bootstrap) return;

  const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
  const header = modalEl.querySelector('.modal-header');
  const kicker = document.getElementById('appFeedbackModalKicker');
  const title = document.getElementById('appFeedbackModalTitle');
  const message = document.getElementById('appFeedbackModalMessage');
  const cancelBtn = modalEl.querySelector('.app-modal-cancel');
  const confirmBtn = modalEl.querySelector('.app-modal-confirm');
  let pendingConfirm = null;
  const queue = [];
  let activeItem = null;

  const labels = {
    info: 'Aviso',
    success: 'Listo',
    warning: 'Atencion',
    danger: 'Confirmacion',
  };

  function configure(options) {
    const tone = options.tone || 'info';
    header?.setAttribute('data-app-modal-tone', tone);
    if (kicker) kicker.textContent = options.kicker || labels[tone] || labels.info;
    if (title) title.textContent = options.title || (tone === 'danger' ? 'Confirmar accion' : 'Mensaje');
    if (message) message.textContent = options.message || '';
    if (cancelBtn) {
      cancelBtn.classList.toggle('d-none', !options.confirm);
      cancelBtn.textContent = options.cancelText || 'Cancelar';
    }
    if (confirmBtn) {
      confirmBtn.textContent = options.confirmText || (options.confirm ? 'Confirmar' : 'Aceptar');
      confirmBtn.className = `btn app-modal-confirm ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`;
      confirmBtn.setAttribute('data-bs-dismiss', 'modal');
    }
  }

  function runNext() {
    if (activeItem || queue.length === 0) return;
    activeItem = queue.shift();
    pendingConfirm = activeItem.confirm ? activeItem.resolve : null;
    configure(activeItem);
    modal.show();
  }

  function enqueue(options) {
    return new Promise((resolve) => {
      queue.push({ ...options, resolve });
      runNext();
    });
  }

  window.appModal = {
    show(options) {
      return enqueue({ ...options, confirm: false });
    },
    confirm(options) {
      return enqueue({ ...options, confirm: true, tone: options.tone || 'danger' });
    },
  };

  confirmBtn?.addEventListener('click', () => {
    if (pendingConfirm) {
      const resolve = pendingConfirm;
      pendingConfirm = null;
      resolve(true);
    }
    if (activeItem && !activeItem.confirm) {
      activeItem.resolve(true);
    }
  });

  modalEl.addEventListener('hidden.bs.modal', () => {
    if (pendingConfirm) {
      const resolve = pendingConfirm;
      pendingConfirm = null;
      resolve(false);
    }
    if (activeItem && !activeItem.confirm) {
      activeItem.resolve(true);
    }
    activeItem = null;
    runNext();
  });

  window.alert = (text) => {
    window.appModal.show({
      title: 'Mensaje',
      message: String(text || ''),
      tone: 'info',
      confirmText: 'Aceptar',
    });
  };

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-app-confirm]');
    if (!form || form.dataset.appConfirmAccepted === '1') return;
    event.preventDefault();
    window.appModal.confirm({
      title: form.dataset.appConfirmTitle || 'Confirmar accion',
      message: form.dataset.appConfirm || 'Confirma esta accion.',
      tone: form.dataset.appConfirmTone || 'danger',
      confirmText: form.dataset.appConfirmText || 'Eliminar',
      cancelText: form.dataset.appCancelText || 'Cancelar',
    }).then((accepted) => {
      if (!accepted) return;
      form.dataset.appConfirmAccepted = '1';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
  }, true);

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert:not(.d-none)').forEach((alertEl) => {
      if (alertEl.closest('.modal')) return;
      const text = alertEl.textContent.replace(/\s+/g, ' ').trim();
      if (!text) return;
      const tone = alertEl.classList.contains('alert-danger') ? 'danger'
        : alertEl.classList.contains('alert-warning') ? 'warning'
          : alertEl.classList.contains('alert-success') ? 'success'
            : 'info';
      alertEl.classList.add('d-none');
      window.appModal.show({
        title: tone === 'success' ? 'Mensaje' : 'Aviso',
        message: text,
        tone,
      });
    });
  });
})();
