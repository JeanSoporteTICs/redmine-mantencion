(function () {
  function ensureCanvasHeight(canvas, px = 320, containerPx = 360) {
    if (!canvas) return;
    canvas.style.height = `${px}px`;
    canvas.height = px;
    canvas.style.maxHeight = `${containerPx}px`;
    if (canvas.parentElement) canvas.parentElement.style.height = `${containerPx}px`;
  }

  function loadChartLibrary(callback) {
    if (typeof Chart !== 'undefined') {
      callback();
      return;
    }
    const existing = document.querySelector('script[data-chartjs-inline]');
    if (existing) {
      existing.addEventListener('load', () => callback());
      return;
    }
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    s.async = true;
    s.setAttribute('data-chartjs-inline', '1');
    s.onload = () => callback();
    s.onerror = () => console.error('No se pudo cargar Chart.js');
    document.head.appendChild(s);
  }

  function buildSeries(data) {
    const entries = Object.entries(data ?? {}).map(([k, v]) => [k, Number(v ?? 0)]);
    entries.sort((a, b) => (a[0] > b[0] ? 1 : -1));
    const months = new Map();
    entries.forEach(([dateStr, val]) => {
      const m = dateStr.slice(0, 7); // yyyy-mm
      months.set(m, (months.get(m) ?? 0) + val);
    });
    if (months.size > 6) {
      const mLabels = Array.from(months.keys()).sort();
      const mValues = mLabels.map((m) => months.get(m) ?? 0);
      return { labels: mLabels, values: mValues };
    }
    return {
      labels: entries.map((e) => e[0]),
      values: entries.map((e) => e[1]),
    };
  }

  function applyInitialMonthSelection(desdeVal, hastaVal) {
    const parseDateVal = (str) => {
      if (!str) return null;
      if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
        const [d, m, y] = str.split('-');
        return new Date(`${y}-${m}-${d}`);
      }
      return new Date(str);
    };
    const d1 = parseDateVal(desdeVal);
    const d2 = parseDateVal(hastaVal);
    if (d1 && d2 && !isNaN(d1) && !isNaN(d2) && d1.getFullYear() === d2.getFullYear()) {
      const m1 = d1.getMonth() + 1;
      const m2 = d2.getMonth() + 1;
      window.rangeStart = Math.min(m1, m2);
      window.rangeEnd = Math.max(m1, m2);
      highlightMonths(window.rangeStart, window.rangeEnd);
      return;
    }
    highlightMonths(null, null);
  }

  function highlightMonths(startM, endM) {
    const allButtons = document.querySelectorAll('.timeline-months button');
    allButtons.forEach((btn) => {
      btn.classList.remove('active', 'range', 'range-edge');
      const val = parseInt(btn.getAttribute('data-month'), 10);
      if (startM !== null && endM !== null) {
        if (val === startM || val === endM) btn.classList.add('range-edge');
        if (val >= startM && val <= endM) btn.classList.add('range');
      } else if (startM !== null && endM === null) {
        if (val === startM) btn.classList.add('active', 'range-edge');
      }
    });
  }

  function setPeriodo(mode) {
    const desde = document.querySelector('input[name="desde"]');
    const hasta = document.querySelector('input[name="hasta"]');
    const today = new Date();
    const pad = (n) => n.toString().padStart(2, '0');
    if (mode === 'month') {
      const y = today.getFullYear();
      const m = pad(today.getMonth() + 1);
      if (desde) desde.value = `${y}-${m}-01`;
      if (hasta) hasta.value = `${y}-${m}-31`;
    } else if (mode === 'year') {
      const y = today.getFullYear();
      if (desde) desde.value = `${y}-01-01`;
      if (hasta) hasta.value = `${y}-12-31`;
    } else if (mode === '30d') {
      const past = new Date(today.getTime() - 29 * 24 * 60 * 60 * 1000);
      if (desde) desde.value = `${past.getFullYear()}-${pad(past.getMonth() + 1)}-${pad(past.getDate())}`;
      if (hasta) hasta.value = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
    } else if (mode === 'today') {
      const y = today.getFullYear();
      const m = pad(today.getMonth() + 1);
      const d = pad(today.getDate());
      if (desde) desde.value = `${y}-${m}-${d}`;
      if (hasta) hasta.value = `${y}-${m}-${d}`;
    } else if (mode === 'clear') {
      if (desde) desde.value = '';
      if (hasta) hasta.value = '';
      window.rangeStart = null;
      window.rangeEnd = null;
      document.querySelectorAll('.timeline-months button').forEach((btn) => btn.classList.remove('active', 'range', 'range-edge'));
    }
    const form = document.getElementById('stats-form');
    if (form) form.submit();
  }

  function selectMonthRange(m) {
    if (window.rangeStart === null || (window.rangeStart !== null && window.rangeEnd !== null)) {
      window.rangeStart = m; window.rangeEnd = null;
    } else {
      window.rangeEnd = m;
      if (window.rangeEnd < window.rangeStart) {
        const tmp = window.rangeStart; window.rangeStart = window.rangeEnd; window.rangeEnd = tmp;
      }
    }
    const allButtons = document.querySelectorAll('.timeline-months button');
    highlightMonths(window.rangeStart, window.rangeEnd);
    if (window.rangeStart !== null && window.rangeEnd !== null) {
      const year = new Date().getFullYear();
      const pad = (n) => n.toString().padStart(2, '0');
      const desde = document.querySelector('input[name="desde"]');
      const hasta = document.querySelector('input[name="hasta"]');
      if (desde) desde.value = `${year}-${pad(window.rangeStart)}-01`;
      if (hasta) hasta.value = `${year}-${pad(window.rangeEnd)}-31`;
      const form = document.getElementById('stats-form');
      if (form) form.submit();
    } else {
      const btn = Array.from(allButtons).find((b) => b.classList.contains('range-edge'));
      if (btn && window.rangeStart !== null) {
        const year = new Date().getFullYear();
        const pad = (n) => n.toString().padStart(2, '0');
        const desde = document.querySelector('input[name="desde"]');
        const hasta = document.querySelector('input[name="hasta"]');
        if (desde) desde.value = `${year}-${pad(window.rangeStart)}-01`;
        if (hasta) hasta.value = `${year}-${pad(window.rangeStart)}-31`;
      }
    }
  }

  function initCharts(statsPorFecha, statsPorUsuario, userNameMap) {
    let chartFechasMain = null;
    let chartUsuariosMain = null;
    const { labels, values } = buildSeries(statsPorFecha);
    const ctx = document.getElementById('chart-fechas');
    if (ctx) {
      const emptyMsg = document.getElementById('no-data-fechas');
      if (!labels.length) {
        if (emptyMsg) emptyMsg.classList.remove('d-none');
        ctx.classList.add('d-none');
      } else {
        if (emptyMsg) emptyMsg.classList.add('d-none');
        ctx.classList.remove('d-none');
        const chartLabels = labels.length ? labels : ['Sin datos'];
        const chartValues = labels.length ? values : [0];
        ensureCanvasHeight(ctx, 320, 360);
        if (chartFechasMain) chartFechasMain.destroy();
        chartFechasMain = new Chart(ctx, {
          type: 'line',
          data: { labels: chartLabels, datasets: [{ label: 'Reportes', data: chartValues, borderColor: '#4e73df', backgroundColor: 'transparent', borderWidth: 2.5, tension: 0.35, pointRadius: 4, pointHoverRadius: 6, pointHitRadius: 10, fill: false }] },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false, axis: 'x' },
            plugins: {
              legend: { display: false },
              tooltip: {
                backgroundColor: 'rgba(31,41,55,0.92)',
                borderColor: '#3b62f6',
                borderWidth: 1,
                titleColor: '#fff',
                bodyColor: '#e5e7eb',
                callbacks: {
                  title: (ctx) => (ctx[0]?.label ?? ''),
                  label: (ctx) => `Reportes: ${ctx.parsed.y ?? 0}`
                }
              }
            },
            scales: { x: { ticks: { autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true, title: { display: true, text: 'Cantidad' }, ticks: { precision: 0, stepSize: 1 } } }
          }
        });
      }
    }

    const ctxUsers = document.getElementById('chart-usuarios');
    if (ctxUsers) {
      const emptyMsgU = document.getElementById('no-data-usuarios');
      const userIds = Object.keys(statsPorUsuario ?? {});
      const userValues = userIds.map((k) => statsPorUsuario[k]);
      const userLabels = userIds.map((id) => userNameMap[id] ?? id);
      if (!userLabels.length) {
        if (emptyMsgU) emptyMsgU.classList.remove('d-none');
        ctxUsers.classList.add('d-none');
      } else {
        if (emptyMsgU) emptyMsgU.classList.add('d-none');
        ctxUsers.classList.remove('d-none');
        const colors = ['#4e73df', '#5a8dee', '#6fa3ff', '#8cb5ff', '#aec9ff', '#c7d9ff', '#dee8ff'];
        const borders = colors.map((c) => c);
        ensureCanvasHeight(ctxUsers, 280, 320);
        if (chartUsuariosMain) chartUsuariosMain.destroy();
        chartUsuariosMain = new Chart(ctxUsers, {
          type: 'doughnut',
          data: { labels: userLabels, datasets: [{ data: userValues, backgroundColor: userLabels.map((_, i) => colors[i % colors.length]), borderColor: userLabels.map((_, i) => borders[i % borders.length]), hoverOffset: 6, borderWidth: 2 }] },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
              legend: { position: 'bottom' },
              tooltip: {
                backgroundColor: 'rgba(31,41,55,0.92)',
                borderColor: '#3b62f6',
                borderWidth: 1,
                titleColor: '#fff',
                bodyColor: '#e5e7eb',
                callbacks: {
                  label: (ctx) => `${ctx.label}: ${ctx.parsed ?? 0}`
                }
              }
            }
          }
        });
      }
    }
  }

  function initModals(statsPorFecha, statsPorUsuario, userNameMap) {
    let chartFechasModal = null;
    let chartUsuariosModal = null;
    const renderFechasModal = () => {
      const canvas = document.getElementById('chart-fechas-modal');
      if (!canvas) return;
      ensureCanvasHeight(canvas, 360, 400);
      const { labels, values } = buildSeries(statsPorFecha);
      if (chartFechasModal) { chartFechasModal.destroy(); chartFechasModal = null; }
      if (!labels.length) return;
      chartFechasModal = new Chart(canvas, {
        type: 'line',
        data: { labels, datasets: [{ data: values, borderColor: '#4e73df', backgroundColor: 'transparent', borderWidth: 2.5, tension: 0.35, pointRadius: 4, pointHoverRadius: 6, pointHitRadius: 10, fill: false }] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'nearest', intersect: false, axis: 'x' }, plugins: { legend: { display: false }, tooltip: { callbacks: { title: (ctx) => (ctx[0]?.label ?? ''), label: (ctx) => `Reportes: ${ctx.parsed.y ?? 0}` } } }, scales: { x: { ticks: { autoSkip: true, maxTicksLimit: 12 } }, y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } } } }
      });
    };

    const renderUsuariosModal = () => {
      const canvas = document.getElementById('chart-usuarios-modal');
      if (!canvas) return;
      ensureCanvasHeight(canvas, 340, 380);
      const ids = Object.keys(statsPorUsuario ?? {});
      const values = ids.map((k) => statsPorUsuario[k]);
      const labels = ids.map((id) => userNameMap[id] ?? id);
      if (chartUsuariosModal) { chartUsuariosModal.destroy(); chartUsuariosModal = null; }
      if (!labels.length) return;
      const colors = ['#4e73df', '#5a8dee', '#6fa3ff', '#8cb5ff', '#aec9ff', '#c7d9ff', '#dee8ff'];
      const borders = colors.map((c) => c);
      chartUsuariosModal = new Chart(canvas, {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: labels.map((_, i) => colors[i % colors.length]), borderColor: labels.map((_, i) => borders[i % borders.length]), hoverOffset: 6, borderWidth: 2 }] },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              backgroundColor: 'rgba(31,41,55,0.92)',
              borderColor: '#3b62f6',
              borderWidth: 1,
              titleColor: '#fff',
              bodyColor: '#e5e7eb',
              callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed ?? 0}` }
            }
          }
        }
      });
    };

    const modalFechas = document.getElementById('modalChartFechas');
    if (modalFechas) modalFechas.addEventListener('shown.bs.modal', renderFechasModal);
    const modalUsuarios = document.getElementById('modalChartUsuarios');
    if (modalUsuarios) modalUsuarios.addEventListener('shown.bs.modal', renderUsuariosModal);
  }

  function initEstadisticasPage(opts) {
    window.rangeStart = null;
    window.rangeEnd = null;
    // Botones timeline
    const monthButtons = document.querySelectorAll('.timeline-months button');
    if (monthButtons.length) {
      monthButtons.forEach((btn) => {
        btn.addEventListener('click', () => selectMonthRange(parseInt(btn.getAttribute('data-month'), 10)));
      });
      applyInitialMonthSelection(opts.desdeVal || '', opts.hastaVal || '');
    }

    // Filtros rápidos
    document.querySelectorAll('[data-periodo]').forEach((btn) => {
      btn.addEventListener('click', () => setPeriodo(btn.getAttribute('data-periodo')));
    });

    loadChartLibrary(() => {
      initCharts(opts.porFecha || {}, opts.porUsuario || {}, opts.userNameMap || {});
      initModals(opts.porFecha || {}, opts.porUsuario || {}, opts.userNameMap || {});
    });
  }

  window.initEstadisticasPage = initEstadisticasPage;
})();
