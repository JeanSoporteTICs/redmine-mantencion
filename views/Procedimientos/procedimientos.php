<?php
require_once __DIR__ . '/../../controllers/auth.php';
require_once __DIR__ . '/../../controllers/procedimientos.php';

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$shareToken = trim((string)($_GET['share'] ?? ''));
$isPublicShare = $shareToken !== '';
if (!$isPublicShare) {
  auth_require_login('/redmine-mantencion/login.php');
}
$csrf = $isPublicShare ? '' : csrf_token();
if ($isPublicShare) {
  $procedures = procedures_read_all();
  $selectedProcedure = procedures_find_by_share_token($procedures, $shareToken);
  $selectedId = (string)($selectedProcedure['id'] ?? '');
  $form = procedures_empty_form();
  $flash = null;
  $error = $selectedProcedure ? null : 'El enlace compartido no existe o fue eliminado.';
} else {
  [$procedures, $form, $flash, $error, $selectedId] = procedures_handle_request();
  $selectedProcedure = $selectedId !== '' ? procedures_find_by_id($procedures, $selectedId) : null;
}
$isPdfExport = (string)($_GET['export'] ?? '') === 'pdf';
$activeNav = 'procedimientos';
$isEditingProcedure = !$isPublicShare && (isset($_GET['new']) || ($selectedId !== '' && (string)($_GET['edit'] ?? '') === '1'));
$showDetail = $selectedId !== '' && !$isEditingProcedure;
$showEditor = $isEditingProcedure;
$pageSizeCssMap = [
  'a4' => 'A4',
  'letter' => 'letter',
  'oficio' => '216mm 330mm',
];
$selectedPageSize = strtolower(trim((string)($selectedProcedure['page_size'] ?? $form['page_size'] ?? 'letter')));
if (!isset($pageSizeCssMap[$selectedPageSize])) {
  $selectedPageSize = 'letter';
}
$shareUrl = '';
if ($selectedProcedure && !empty($selectedProcedure['share_token'])) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $shareUrl = $scheme . '://' . $host . '/redmine-mantencion/views/Procedimientos/procedimientos.php?share=' . urlencode((string)$selectedProcedure['share_token']);
}
if ($isPublicShare && !$selectedProcedure) {
  http_response_code(404);
}

if ($isPdfExport) {
  http_response_code($selectedProcedure ? 200 : 404);
  $exportTitle = $selectedProcedure['title'] ?? 'Procedimiento no encontrado';
  $exportAuthor = trim((string)($selectedProcedure['author_name'] ?? ''));
  $exportUpdated = trim((string)($selectedProcedure['updated_at'] ?? ''));
  $exportContent = (string)($selectedProcedure['content_html'] ?? '');
  $exportPageSize = strtolower(trim((string)($selectedProcedure['page_size'] ?? 'letter')));
  if (!isset($pageSizeCssMap[$exportPageSize])) {
    $exportPageSize = 'letter';
  }
  $exportPageCss = $pageSizeCssMap[$exportPageSize];
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($exportTitle) ?> | Exportar PDF</title>
    <?php $pageTitle = 'Exportar PDF'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
    <style>
      :root { color-scheme: light; }
      body {
        margin: 0;
        min-height: 100vh;
        background:
          radial-gradient(circle at top left, rgba(56, 189, 248, .16), transparent 24rem),
          radial-gradient(circle at top right, rgba(139, 92, 246, .12), transparent 20rem),
          linear-gradient(180deg, #edf4ff, #f8fbff 26%, #eef6ff);
        color: #0f172a;
      }
      .pdf-shell { max-width: 1040px; margin: 2rem auto; padding: 0 1.2rem 2rem; }
      .pdf-toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
      .pdf-toolbar-group { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
      .pdf-card {
        background: rgba(255,255,255,.88);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(217, 228, 241, .9);
        border-radius: 1.8rem;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .12);
        overflow: hidden;
      }
      .pdf-header {
        position: relative;
        padding: 2rem 2.1rem 1.25rem;
        border-bottom: 1px solid #e6eef7;
        background:
          linear-gradient(135deg, rgba(56, 189, 248, .10), rgba(255,255,255,.96) 40%),
          linear-gradient(180deg, #f8fbff, #ffffff);
      }
      .pdf-badge {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        margin-bottom: 1rem;
        padding: .42rem .75rem;
        border-radius: 999px;
        background: #ecf7ff;
        color: #0369a1;
        font-size: .78rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
      }
      .pdf-title {
        margin: 0;
        font-size: clamp(2rem, 3vw, 2.5rem);
        line-height: 1.08;
        color: #0f172a;
        max-width: 18ch;
      }
      .pdf-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .85rem 1.2rem;
        margin-top: 1.2rem;
      }
      .pdf-meta-item {
        min-width: 180px;
        padding: .8rem .95rem;
        border-radius: 1rem;
        background: rgba(255,255,255,.8);
        border: 1px solid #e4edf7;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.85);
      }
      .pdf-meta-label {
        display: block;
        margin-bottom: .2rem;
        color: #64748b;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .05em;
        text-transform: uppercase;
      }
      .pdf-meta-value {
        color: #0f172a;
        font-size: .96rem;
        font-weight: 600;
      }
      .pdf-content {
        position: relative;
        padding: 1.75rem 2.1rem 2.2rem;
        line-height: 1.75;
        font-size: 1rem;
      }
      .pdf-content::after { content: ""; display: block; clear: both; }
      .pdf-content > :first-child { margin-top: 0 !important; }
      .pdf-content h1, .pdf-content h2, .pdf-content h3, .pdf-content h4 {
        color: #0f172a;
        line-height: 1.22;
        margin-top: 1.7rem;
        margin-bottom: .7rem;
      }
      .pdf-content p, .pdf-content ul, .pdf-content ol, .pdf-content blockquote { margin-bottom: .95rem; }
      .pdf-content ul, .pdf-content ol { padding-left: 1.35rem; }
      .pdf-content blockquote {
        margin-left: 0;
        padding: .9rem 1rem;
        border-left: 4px solid #7dd3fc;
        background: #f4fbff;
        border-radius: 0 1rem 1rem 0;
        color: #334155;
      }
      .pdf-content img { display: block; max-width: 100%; height: auto; border-radius: .8rem; }
      .pdf-content .proc-side-layout { display: grid; width: 100%; --proc-left-space: 1fr; --proc-right-space: 1fr; grid-template-columns: minmax(0, var(--proc-left-space)) minmax(260px, auto) minmax(0, var(--proc-right-space)); gap: 1rem; align-items: start; margin: 1rem 0; }
      .pdf-content .proc-side-layout[data-side-mode="compact"] { display: inline-grid; width: auto; max-width: 100%; gap: 0; }
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="left"],
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="free"] { float: left; clear: none; margin: 1rem 1rem 1rem 0; }
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="right"] { float: right; clear: none; margin: 1rem 0 1rem 1rem; }
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="center"] { display: grid; width: fit-content; margin-left: auto; margin-right: auto; }
      .pdf-content .proc-side-layout[data-side-mode="compact"] .proc-side-text { display: none; }
     .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-image-wrap,
     .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap,
     .pdf-content .proc-side-layout[data-side-mode="compact"] > pre.proc-code-block,
     .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-callout { justify-self: start; }
     .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap { width: fit-content; max-width: min(100%, 760px); }
      .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap .proc-table-scroll { display: inline-block; width: auto !important; min-width: 420px; max-width: 100%; }
      .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap table { width: max-content; min-width: 420px; max-width: 100%; }
      .pdf-content .proc-side-layout[data-side-mode="compact"] > pre.proc-code-block { width: fit-content; min-width: 320px; max-width: min(100%, 760px); }
      .pdf-content .proc-side-layout[data-side-mode="compact"] > .proc-callout { width: fit-content; min-width: 320px; max-width: min(100%, 540px); }
      .pdf-content .proc-side-text { min-height: 1.5rem; white-space: pre-wrap; word-break: break-word; color: #334155; }
      .pdf-content .proc-side-layout > .proc-image-wrap,
      .pdf-content .proc-side-layout > .proc-table-wrap,
      .pdf-content .proc-side-layout > pre.proc-code-block { grid-column: 2; float: none !important; margin: 0 !important; justify-self: center; }
      .pdf-content .proc-image-wrap,
      .pdf-content .proc-table-wrap { --proc-inline-offset: 0px; max-width: 100%; }
      .pdf-content .proc-image-wrap[data-align="left"],
      .pdf-content .proc-table-wrap[data-align="left"] { float: left; margin: .5rem 1rem .85rem var(--proc-inline-offset); }
      .pdf-content .proc-image-wrap[data-align="right"],
      .pdf-content .proc-table-wrap[data-align="right"] { float: right; margin: .5rem var(--proc-inline-offset) .85rem 1rem; }
      .pdf-content .proc-image-wrap[data-align="center"],
      .pdf-content .proc-table-wrap[data-align="center"] { display: block; margin-left: auto; margin-right: auto; float: none; clear: both; }
      .pdf-content .proc-table-wrap {
        display: block;
        margin: 1rem 0;
        border: 1px solid #dbe6f2;
        border-radius: 1rem;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 12px 26px rgba(15, 23, 42, .06);
      }
      .pdf-content .proc-table-scroll { overflow: visible; min-width: 0 !important; padding: 0; background: transparent; }
      .pdf-content .proc-table-wrap table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
      .pdf-content .proc-table-wrap th,
      .pdf-content .proc-table-wrap td { border-right: 1px solid #dde7f2; border-bottom: 1px solid #dde7f2; padding: .75rem .85rem; vertical-align: top; white-space: pre-wrap; word-break: break-word; }
      .pdf-content .proc-table-wrap tr > *:first-child { border-left: 1px solid #dde7f2; }
      .pdf-content .proc-table-wrap thead tr:first-child > * { border-top: 1px solid #dde7f2; }
      .pdf-content .proc-table-wrap thead th { background: linear-gradient(180deg, #d9efff, #eef8ff); color: #1e3a5f; font-weight: 700; }
      .pdf-content .proc-table-wrap tbody tr:nth-child(even) td { background: #f9fbfe; }
      .pdf-content .proc-table-wrap[data-border-style="dashed"] th,
      .pdf-content .proc-table-wrap[data-border-style="dashed"] td { border-right-style: dashed; border-bottom-style: dashed; }
      .pdf-content .proc-table-wrap[data-border-style="dashed"] tr > *:first-child { border-left-style: dashed; }
      .pdf-content .proc-table-wrap[data-border-style="dashed"] thead tr:first-child > * { border-top-style: dashed; }
      .pdf-content .proc-table-wrap[data-border-style="thick"] th,
      .pdf-content .proc-table-wrap[data-border-style="thick"] td { border-right-width: 2px; border-bottom-width: 2px; border-color: #b8cce2; }
      .pdf-content .proc-table-wrap[data-border-style="thick"] tr > *:first-child { border-left-width: 2px; border-left-color: #b8cce2; }
      .pdf-content .proc-table-wrap[data-border-style="thick"] thead tr:first-child > * { border-top-width: 2px; border-top-color: #b8cce2; }
      .pdf-content .proc-table-wrap[data-border-style="minimal"] th,
      .pdf-content .proc-table-wrap[data-border-style="minimal"] td { border-left-color: transparent; border-right-color: transparent; }
      .pdf-content .proc-table-wrap[data-border-style="minimal"] tbody td { border-bottom-color: #e8eef6; }
      .pdf-content pre.proc-code-block {
        display: block;
        max-width: 100%;
        --proc-inline-offset: 0px;
        margin: 1.15rem 0;
        padding: 1.25rem 1rem 1rem;
        border-radius: 1.15rem;
        background: linear-gradient(180deg, #fffdf0, #fff9cf);
        color: #374151;
        overflow: auto;
        border: 1px solid #efe19a;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.92);
      }
      .pdf-content pre.proc-code-block[data-align="left"] { float: left; margin: 1.15rem 1rem 1rem var(--proc-inline-offset); }
      .pdf-content pre.proc-code-block[data-align="right"] { float: right; margin: 1.15rem var(--proc-inline-offset) 1rem 1rem; }
      .pdf-content pre.proc-code-block[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
      .pdf-content pre.proc-code-block::before {
        content: attr(data-lang-label);
        display: inline-block;
        margin-bottom: .9rem;
        font-size: .72rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #7c5d00;
        background: #f7e77a;
        padding: .22rem .5rem;
        border-radius: 999px;
      }
      .pdf-content pre.proc-code-block code { display: block; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; font-size: .92rem; line-height: 1.55; }
      .pdf-content .tok-keyword { color: #7c3aed; font-weight: 700; }
      .pdf-content .tok-tag { color: #0f766e; font-weight: 600; }
      .pdf-content .tok-string { color: #0f9f5a; }
      .pdf-content .tok-number { color: #c2410c; }
      .pdf-content .tok-comment { color: #6b7280; font-style: italic; }
      .pdf-content .tok-attr { color: #9a6700; font-weight: 600; }
      .pdf-content .proc-editor-sep { height: 0; margin: 1.15rem 0; border: 0; border-top: 2px dashed #cbd5e1; }
      .pdf-content .proc-callout { margin: 1rem 0; padding: .95rem 1rem; border-radius: 1rem; border: 1px solid #cfe5ff; background: linear-gradient(180deg, #f7fbff, #edf7ff); color: #1e3a5f; }
      .pdf-content .proc-callout[data-align="left"] { float: left; clear: none; margin: 1rem 1rem 1rem var(--proc-inline-offset, 0px); }
      .pdf-content .proc-callout[data-align="right"] { float: right; clear: none; margin: 1rem var(--proc-inline-offset, 0px) 1rem 1rem; }
      .pdf-content .proc-callout[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
      .pdf-content .proc-side-layout[data-position="free"],
      .pdf-content .proc-callout[data-position="free"] { position: absolute; float: none !important; clear: none !important; margin: 0 !important; z-index: 1; box-sizing: border-box; max-width: 100%; }
      .pdf-content .proc-side-layout[data-position="free"] { display: block; width: fit-content; max-width: 100%; min-width: 120px; }
      .pdf-content .proc-side-layout[data-position="free"] .proc-side-text { display: none; }
      .pdf-content .proc-side-layout[data-position="free"] > .proc-image-wrap,
      .pdf-content .proc-side-layout[data-position="free"] > .proc-table-wrap,
      .pdf-content .proc-side-layout[data-position="free"] > pre.proc-code-block,
      .pdf-content .proc-side-layout[data-position="free"] > .proc-callout { float: none !important; clear: none !important; margin: 0 !important; max-width: 100%; box-sizing: border-box; }
      .pdf-content .proc-callout[data-tone="warning"] { border-color: #f5d88b; background: linear-gradient(180deg, #fffdf3, #fff7d8); color: #7c5d00; }
      .pdf-content .proc-callout-title { display: block; margin-bottom: .35rem; font-size: .76rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
      .pdf-content .proc-checklist { margin: 1rem 0; padding-left: 1.3rem; }
      .pdf-content .proc-checklist li { margin-bottom: .45rem; list-style: none; position: relative; }
      .pdf-content .proc-checklist li::before { content: "☐"; position: absolute; left: -1.25rem; top: 0; color: #2563eb; font-weight: 700; }
      .pdf-shell {
        width: max-content;
        min-width: 100%;
        max-width: none;
        margin: 1rem auto;
        padding: 0 1.2rem 2rem;
      }
      .pdf-card,
      .pdf-preview-canvas {
        width: 1440px;
        max-width: none;
        border: 1px solid #d7deea;
        border-radius: 1.15rem;
        box-shadow: 0 18px 36px rgba(15, 23, 42, .06);
        overflow: visible;
        background: #fff;
      }
      .pdf-header { display: none; }
      .pdf-content,
      .pdf-preview-canvas {
        box-sizing: border-box;
        min-height: 560px;
        padding: 1.25rem;
        background-color: #fff;
        background-image:
          linear-gradient(to right, rgba(148, 163, 184, .12) 1px, transparent 1px),
          linear-gradient(to bottom, rgba(148, 163, 184, .12) 1px, transparent 1px);
        background-size: 24px 24px;
        border-radius: 1.15rem;
        line-height: normal;
        font-size: inherit;
      }
      .pdf-content { width: 100%; }
      .pdf-content h1,
      .pdf-content h2,
      .pdf-content h3,
      .pdf-content h4 {
        margin-top: .8rem;
        margin-bottom: .6rem;
        line-height: 1.2;
      }
      .pdf-content pre.proc-code-block {
        position: relative;
        display: block;
        width: fit-content;
        min-width: 320px;
        max-width: 100%;
        margin: 1rem 0;
        padding: 3rem 1rem 1rem;
        border-radius: 1rem;
        background: #fffbd1;
        color: #4b5563;
        overflow: auto;
        border: 1px solid #efe7a8;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
      }
      .pdf-content pre.proc-code-block::before {
        position: absolute;
        top: .65rem;
        right: .8rem;
        display: inline-block;
        margin: 0;
        background: #f7ef9a;
      }
      .pdf-content .proc-table-wrap {
        position: relative;
        display: block;
        width: fit-content;
        max-width: 100%;
        margin: 1rem 0;
        padding-top: 0;
        border: 1px solid #dbe6f2;
        border-radius: 1.15rem;
        background: linear-gradient(180deg, #ffffff, #fbfdff);
        box-shadow: 0 18px 34px rgba(15, 23, 42, 0.07);
        overflow: hidden;
        clear: both;
      }
      .pdf-content .proc-table-scroll {
        overflow: auto;
        min-width: 420px;
        min-height: 170px;
        width: auto;
        padding: .45rem .55rem .75rem;
        background: linear-gradient(180deg, rgba(239,246,255,.55), rgba(255,255,255,0));
      }
      .pdf-content .proc-table-wrap table {
        width: max-content;
        min-width: 420px;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
        margin: 0;
        background: #fff;
        border-radius: .9rem;
        overflow: hidden;
      }
      .pdf-content .proc-table-wrap td,
      .pdf-content .proc-table-wrap th {
        position: relative;
        min-width: 150px;
        min-height: 54px;
        height: 54px;
      }
      .pdf-content .proc-side-layout[data-side-mode="compact"] {
        display: block;
        width: fit-content;
        max-width: 100%;
        clear: both;
      }
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="left"],
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="free"],
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="right"],
      .pdf-content .proc-side-layout[data-side-mode="compact"][data-align="center"] {
        float: none;
        margin: 1rem 0;
      }
      .pdf-empty { color: #64748b; font-size: 1rem; }
      .proc-page-break { display: none !important; }
      @media print {
        @page { size: <?= $h($exportPageCss) ?>; margin: 14mm 12mm; }
        body { background: #fff; }
        .pdf-shell { width: auto; min-width: 0; max-width: none; margin: 0; padding: 0; }
        .pdf-toolbar { display: none !important; }
        .pdf-card, .pdf-preview-canvas { width: auto; max-width: none; border: 0; border-radius: 0; box-shadow: none; }
        .pdf-header, .pdf-content, .pdf-preview-canvas { padding-left: 0; padding-right: 0; }
        .proc-page-break { display: none !important; }
      }
    </style>
  </head>
  <body>
    <div class="pdf-shell">
      <div class="pdf-toolbar">
        <a class="btn btn-outline-secondary" href="/redmine-mantencion/views/Procedimientos/procedimientos.php<?= $selectedProcedure ? '?id=' . urlencode((string)$selectedProcedure['id']) : '' ?>">
          <i class="bi bi-arrow-left"></i> Volver
        </a>
        <?php if ($selectedProcedure): ?>
          <div class="pdf-toolbar-group">
            <button type="button" class="btn btn-primary" onclick="window.print()">
              <i class="bi bi-file-earmark-pdf"></i> Imprimir / Guardar PDF
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.close()">Cerrar</button>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($selectedProcedure): ?>
        <main class="pdf-preview-canvas pdf-content" id="procedurePdfContent"><?= $exportContent ?></main>
      <?php else: ?>
        <main class="pdf-preview-canvas">
          <p class="pdf-empty">El procedimiento solicitado no existe o fue eliminado.</p>
        </main>
      <?php endif; ?>
    </div>
    <?php if ($selectedProcedure): ?>
      <script>
        (() => {
          const container = document.getElementById('procedurePdfContent');
          if (!container) return;

          container.querySelectorAll('[contenteditable]').forEach((node) => node.removeAttribute('contenteditable'));
          container.querySelectorAll('.is-selected, .is-editing, .is-dragging, .proc-table-cell-selected').forEach((node) => {
            node.classList.remove('is-selected', 'is-editing', 'is-dragging', 'proc-table-cell-selected');
          });
          container.querySelectorAll('.proc-image-tools, .proc-image-resize, .proc-table-tools, .proc-table-resize, .proc-table-col-resize-handle, .proc-table-row-resize-handle, .proc-code-actions, .proc-code-resize, .proc-callout-actions, .proc-callout-resize, .proc-drop-indicator, .proc-drop-indicator-vertical').forEach((node) => node.remove());
          container.querySelectorAll('p').forEach((node) => {
            if (node.innerHTML.replace(/<br\s*\/?>/gi, '').trim() === '') {
              node.remove();
            }
          });

          const escapeHtml = (value) => value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

          const normalizeBrokenTokens = (value) => value
            .replace(/"?tok-[a-z-]+"?>/gi, '')
            .replace(/&quot;?tok-[a-z-]+&quot;&gt;/gi, '')
            .replace(/\u00a0/g, ' ');

          const highlightCode = (source, lang) => {
            let html = escapeHtml(source);
            if (lang === 'html') {
              html = html.replace(/(&lt;\/?)([a-zA-Z0-9:-]+)/g, '$1<span class="tok-tag">$2</span>');
              html = html.replace(/([a-zA-Z-:]+)=(&quot;.*?&quot;)/g, '<span class="tok-attr">$1</span>=<span class="tok-string">$2</span>');
              html = html.replace(/&lt;!--[\s\S]*?--&gt;/g, '<span class="tok-comment">$&</span>');
            } else if (lang === 'sql') {
              html = html.replace(/(--.*?$)/gm, '<span class="tok-comment">$1</span>');
              html = html.replace(/\b(SELECT|FROM|WHERE|AND|OR|ORDER BY|GROUP BY|INSERT INTO|VALUES|UPDATE|SET|DELETE|INNER JOIN|LEFT JOIN|RIGHT JOIN|ON|AS|COUNT|SUM|AVG|MIN|MAX|LIKE|IN|IS NULL|NOT NULL|CREATE|ALTER|DROP|TABLE|FOR UPDATE)\b/gi, '<span class="tok-keyword">$1</span>');
              html = html.replace(/'([^']*)'/g, '<span class="tok-string">\'$1\'</span>');
              html = html.replace(/\b\d+(\.\d+)?\b/g, '<span class="tok-number">$&</span>');
            } else if (lang === 'css') {
              html = html.replace(/\/\*[\s\S]*?\*\//g, '<span class="tok-comment">$&</span>');
              html = html.replace(/([.#]?[a-zA-Z_][\w-]*)(\s*\{)/g, '<span class="tok-tag">$1</span>$2');
              html = html.replace(/([a-z-]+)(\s*:)/gi, '<span class="tok-attr">$1</span>$2');
              html = html.replace(/(#(?:[0-9a-fA-F]{3,8})|\b\d+(\.\d+)?(px|rem|em|%|vh|vw)?\b)/g, '<span class="tok-number">$1</span>');
            } else if (lang === 'javascript' || lang === 'php' || lang === 'bash') {
              html = html.replace(/(\/\/.*?$|#.*?$|\/\*[\s\S]*?\*\/)/gm, '<span class="tok-comment">$1</span>');
              html = html.replace(/\b(function|const|let|var|return|if|else|for|while|switch|case|break|class|new|echo|public|private|protected|foreach|as|try|catch|finally|throw)\b/gi, '<span class="tok-keyword">$1</span>');
              html = html.replace(/(["'`])((?:\\.|(?!\1).)*)\1/g, '<span class="tok-string">$&</span>');
              html = html.replace(/\b\d+(\.\d+)?\b/g, '<span class="tok-number">$&</span>');
            }
            return html;
          };

          container.querySelectorAll('pre.proc-code-block code').forEach((codeEl) => {
            const lang = (codeEl.getAttribute('data-lang') || 'text').toLowerCase();
            const raw = normalizeBrokenTokens(codeEl.textContent || '');
            codeEl.textContent = raw;
            codeEl.innerHTML = highlightCode(raw, lang);
          });

          let maxBottom = 260;
          container.querySelectorAll('.proc-side-layout[data-position="free"], .proc-callout[data-position="free"]').forEach((node) => {
            const top = parseFloat(node.style.top || '0') || 0;
            const height = Math.max(0, Math.round(node.getBoundingClientRect().height || node.offsetHeight || 0));
            maxBottom = Math.max(maxBottom, Math.ceil(top + height + 32));
          });
          container.style.minHeight = `${maxBottom}px`;
        })();

      </script>
    <?php endif; ?>
  </body>
  </html>
  <?php
  return;
}
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Procedimientos'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
    .proc-shell { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 1.5rem; }
    .proc-panel { border: 0; box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08); overflow: visible; }
    .proc-panel > .card-body { overflow: visible; }
    .proc-list-search { position: sticky; top: 0; background: #fff; z-index: 2; }
    .proc-list-item { display: block; color: inherit; text-decoration: none; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1rem; transition: .2s ease; }
    .proc-list-item:hover { border-color: #93c5fd; box-shadow: 0 10px 22px rgba(59, 130, 246, 0.12); }
    .proc-list-item.active { background: linear-gradient(180deg, #eff6ff, #ffffff); border-color: #60a5fa; }
    .proc-meta { color: #64748b; font-size: .875rem; }
    .proc-meta .proc-meta-label { color: #94a3b8; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
    .proc-meta .proc-meta-value { color: #334155; font-weight: 600; }
    .proc-detail-panel { border: 0; box-shadow: 0 20px 44px rgba(15, 23, 42, 0.09); overflow: hidden; }
    .proc-detail-header { padding: 1.75rem 1.9rem 1.2rem; border-bottom: 1px solid #e5edf7; background: linear-gradient(180deg, #f8fbff, #fff); }
    .proc-detail-badge { display: inline-flex; align-items: center; gap: .45rem; padding: .34rem .72rem; border-radius: 999px; background: #eff6ff; color: #2563eb; font-size: .76rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .proc-detail-title { margin: 1rem 0 .45rem; font-size: clamp(1.7rem, 2.6vw, 2.25rem); line-height: 1.1; color: #0f172a; }
    .proc-detail-meta { display: flex; flex-wrap: wrap; gap: .85rem; margin-top: 1.2rem; }
    .proc-detail-meta-item { min-width: 180px; padding: .8rem .95rem; border: 1px solid #e2e8f0; border-radius: 1rem; background: rgba(255,255,255,.86); }
    .proc-detail-meta-label { display: block; margin-bottom: .2rem; color: #64748b; font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
    .proc-detail-meta-value { color: #0f172a; font-weight: 600; }
    .proc-detail-actions { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; padding: 1rem 1.9rem; background: #fff; border-bottom: 1px solid #edf2f7; }
    .proc-detail-actions-group { display: flex; flex-wrap: wrap; gap: .75rem; }
    .proc-detail-content { position: relative; padding: 1.7rem 1.9rem 2rem; background: #fff; line-height: 1.75; }
    .proc-detail-content::after { content: ""; display: block; clear: both; }
    .proc-detail-content > :first-child { margin-top: 0 !important; }
    .proc-detail-content h1, .proc-detail-content h2, .proc-detail-content h3, .proc-detail-content h4 { color: #0f172a; line-height: 1.22; margin-top: 1.7rem; margin-bottom: .7rem; }
    .proc-detail-content p, .proc-detail-content ul, .proc-detail-content ol, .proc-detail-content blockquote { margin-bottom: .95rem; }
    .proc-detail-content ul, .proc-detail-content ol { padding-left: 1.35rem; }
    .proc-detail-content blockquote { margin-left: 0; padding: .9rem 1rem; border-left: 4px solid #7dd3fc; background: #f4fbff; border-radius: 0 1rem 1rem 0; color: #334155; }
    .proc-detail-content img { display: block; max-width: 100%; height: auto; border-radius: .85rem; }
    .proc-detail-content .proc-side-layout { display: grid; width: 100%; --proc-left-space: 1fr; --proc-right-space: 1fr; grid-template-columns: minmax(0, var(--proc-left-space)) minmax(260px, auto) minmax(0, var(--proc-right-space)); gap: 1rem; align-items: start; margin: 1rem 0; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] { display: inline-grid; width: auto; max-width: 100%; gap: 0; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="left"],
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="free"] { float: left; clear: none; margin: 1rem 1rem 1rem 0; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="right"] { float: right; clear: none; margin: 1rem 0 1rem 1rem; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="center"] { display: grid; width: fit-content; margin-left: auto; margin-right: auto; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] .proc-side-text { display: none; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-image-wrap,
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap,
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > pre.proc-code-block,
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-callout { justify-self: start; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap { width: fit-content; max-width: min(100%, 760px); }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap .proc-table-scroll { display: inline-block; width: auto !important; min-width: 420px; max-width: 100%; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap table { width: max-content; min-width: 420px; max-width: 100%; }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > pre.proc-code-block { width: fit-content; min-width: 320px; max-width: min(100%, 760px); }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] > .proc-callout { width: fit-content; min-width: 320px; max-width: min(100%, 540px); }
    .proc-detail-content .proc-side-text { min-height: 1.5rem; white-space: pre-wrap; word-break: break-word; color: #334155; }
    .proc-detail-content .proc-side-layout > .proc-image-wrap,
    .proc-detail-content .proc-side-layout > .proc-table-wrap,
    .proc-detail-content .proc-side-layout > pre.proc-code-block { grid-column: 2; float: none !important; margin: 0 !important; justify-self: center; }
    .proc-detail-content .proc-image-wrap,
    .proc-detail-content .proc-table-wrap { --proc-inline-offset: 0px; max-width: 100%; }
    .proc-detail-content .proc-image-wrap[data-align="left"],
    .proc-detail-content .proc-table-wrap[data-align="left"] { float: left; margin: .5rem 1rem .85rem var(--proc-inline-offset); }
    .proc-detail-content .proc-image-wrap[data-align="right"],
    .proc-detail-content .proc-table-wrap[data-align="right"] { float: right; margin: .5rem var(--proc-inline-offset) .85rem 1rem; }
    .proc-detail-content .proc-image-wrap[data-align="center"],
    .proc-detail-content .proc-table-wrap[data-align="center"] { display: block; margin-left: auto; margin-right: auto; float: none; clear: both; }
    .proc-detail-content .proc-table-wrap { display: block; margin: 1rem 0; border: 1px solid #dbe6f2; border-radius: 1rem; overflow: hidden; background: #fff; box-shadow: 0 12px 26px rgba(15, 23, 42, .06); }
    .proc-detail-content .proc-table-scroll { overflow: visible; min-width: 0 !important; padding: 0; background: transparent; }
    .proc-detail-content .proc-table-wrap table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
    .proc-detail-content .proc-table-wrap th,
    .proc-detail-content .proc-table-wrap td { border-right: 1px solid #dde7f2; border-bottom: 1px solid #dde7f2; padding: .75rem .85rem; vertical-align: top; white-space: pre-wrap; word-break: break-word; }
    .proc-detail-content .proc-table-wrap tr > *:first-child { border-left: 1px solid #dde7f2; }
    .proc-detail-content .proc-table-wrap thead tr:first-child > * { border-top: 1px solid #dde7f2; }
    .proc-detail-content .proc-table-wrap thead th { background: linear-gradient(180deg, #d9efff, #eef8ff); color: #1e3a5f; font-weight: 700; }
    .proc-detail-content .proc-table-wrap tbody tr:nth-child(even) td { background: #f9fbfe; }
    .proc-detail-content .proc-table-wrap[data-border-style="dashed"] th,
    .proc-detail-content .proc-table-wrap[data-border-style="dashed"] td { border-right-style: dashed; border-bottom-style: dashed; }
    .proc-detail-content .proc-table-wrap[data-border-style="dashed"] tr > *:first-child { border-left-style: dashed; }
    .proc-detail-content .proc-table-wrap[data-border-style="dashed"] thead tr:first-child > * { border-top-style: dashed; }
    .proc-detail-content .proc-table-wrap[data-border-style="thick"] th,
    .proc-detail-content .proc-table-wrap[data-border-style="thick"] td { border-right-width: 2px; border-bottom-width: 2px; border-color: #b8cce2; }
    .proc-detail-content .proc-table-wrap[data-border-style="thick"] tr > *:first-child { border-left-width: 2px; border-left-color: #b8cce2; }
    .proc-detail-content .proc-table-wrap[data-border-style="thick"] thead tr:first-child > * { border-top-width: 2px; border-top-color: #b8cce2; }
    .proc-detail-content .proc-table-wrap[data-border-style="minimal"] th,
    .proc-detail-content .proc-table-wrap[data-border-style="minimal"] td { border-left-color: transparent; border-right-color: transparent; }
    .proc-detail-content .proc-table-wrap[data-border-style="minimal"] tbody td { border-bottom-color: #e8eef6; }
    .proc-detail-content pre.proc-code-block { display: block; max-width: 100%; --proc-inline-offset: 0px; margin: 1.15rem 0; padding: 1.25rem 1rem 1rem; border-radius: 1.15rem; background: linear-gradient(180deg, #fffdf0, #fff9cf); color: #374151; overflow: auto; border: 1px solid #efe19a; box-shadow: inset 0 1px 0 rgba(255,255,255,.92); }
    .proc-detail-content pre.proc-code-block[data-align="left"] { float: left; margin: 1.15rem 1rem 1rem var(--proc-inline-offset); }
    .proc-detail-content pre.proc-code-block[data-align="right"] { float: right; margin: 1.15rem var(--proc-inline-offset) 1rem 1rem; }
    .proc-detail-content pre.proc-code-block[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
    .proc-detail-content pre.proc-code-block::before { content: attr(data-lang-label); display: inline-block; margin-bottom: .9rem; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: #7c5d00; background: #f7e77a; padding: .22rem .5rem; border-radius: 999px; }
    .proc-detail-content pre.proc-code-block code { display: block; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; font-size: .92rem; line-height: 1.55; }
    .proc-detail-content .tok-keyword { color: #7c3aed; font-weight: 700; }
    .proc-detail-content .tok-tag { color: #0f766e; font-weight: 600; }
    .proc-detail-content .tok-string { color: #0f9f5a; }
    .proc-detail-content .tok-number { color: #c2410c; }
    .proc-detail-content .tok-comment { color: #6b7280; font-style: italic; }
    .proc-detail-content .tok-attr { color: #9a6700; font-weight: 600; }
    .proc-detail-content .proc-editor-sep { height: 0; margin: 1.15rem 0; border: 0; border-top: 2px dashed #cbd5e1; }
    .proc-detail-content .proc-callout { margin: 1rem 0; padding: .95rem 1rem; border-radius: 1rem; border: 1px solid #cfe5ff; background: linear-gradient(180deg, #f7fbff, #edf7ff); color: #1e3a5f; }
    .proc-detail-content .proc-callout[data-align="left"] { float: left; clear: none; margin: 1rem 1rem 1rem var(--proc-inline-offset, 0px); }
    .proc-detail-content .proc-callout[data-align="right"] { float: right; clear: none; margin: 1rem var(--proc-inline-offset, 0px) 1rem 1rem; }
    .proc-detail-content .proc-callout[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
    .proc-detail-content .proc-side-layout[data-position="free"],
    .proc-detail-content .proc-callout[data-position="free"] { position: absolute; float: none !important; clear: none !important; margin: 0 !important; z-index: 1; box-sizing: border-box; max-width: 100%; }
    .proc-detail-content .proc-side-layout[data-position="free"] { display: block; width: fit-content; max-width: 100%; min-width: 120px; }
    .proc-detail-content .proc-side-layout[data-position="free"] .proc-side-text { display: none; }
    .proc-detail-content .proc-side-layout[data-position="free"] > .proc-image-wrap,
    .proc-detail-content .proc-side-layout[data-position="free"] > .proc-table-wrap,
    .proc-detail-content .proc-side-layout[data-position="free"] > pre.proc-code-block,
    .proc-detail-content .proc-side-layout[data-position="free"] > .proc-callout { float: none !important; clear: none !important; margin: 0 !important; max-width: 100%; box-sizing: border-box; }
    .proc-detail-content .proc-callout[data-tone="warning"] { border-color: #f5d88b; background: linear-gradient(180deg, #fffdf3, #fff7d8); color: #7c5d00; }
    .proc-detail-content .proc-callout-title { display: block; margin-bottom: .35rem; font-size: .76rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .proc-detail-content .proc-checklist { margin: 1rem 0; padding-left: 1.3rem; }
    .proc-detail-content .proc-checklist li { margin-bottom: .45rem; list-style: none; position: relative; }
    .proc-detail-content .proc-checklist li::before { content: "☐"; position: absolute; left: -1.25rem; top: 0; color: #2563eb; font-weight: 700; }
    .proc-detail-content {
      min-height: 560px;
      background-color: #fff;
      background-image:
        linear-gradient(to right, rgba(148, 163, 184, .12) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(148, 163, 184, .12) 1px, transparent 1px);
      background-size: 24px 24px;
      line-height: normal;
    }
    .proc-detail-content h1,
    .proc-detail-content h2,
    .proc-detail-content h3,
    .proc-detail-content h4 {
      margin-top: .8rem;
      margin-bottom: .6rem;
      line-height: 1.2;
    }
    .proc-detail-content .proc-table-wrap {
      position: relative;
      display: block;
      width: fit-content;
      max-width: 100%;
      margin: 1rem 0;
      padding-top: 0;
      border: 1px solid #dbe6f2;
      border-radius: 1.15rem;
      background: linear-gradient(180deg, #ffffff, #fbfdff);
      box-shadow: 0 18px 34px rgba(15, 23, 42, 0.07);
      overflow: hidden;
      clear: both;
    }
    .proc-detail-content .proc-table-scroll {
      overflow: auto;
      min-width: 420px !important;
      min-height: 170px;
      width: auto;
      padding: .45rem .55rem .75rem;
      background: linear-gradient(180deg, rgba(239,246,255,.55), rgba(255,255,255,0));
    }
    .proc-detail-content .proc-table-wrap table {
      width: max-content;
      min-width: 420px;
      table-layout: fixed;
      border-collapse: separate;
      border-spacing: 0;
      margin: 0;
      background: #fff;
      border-radius: .9rem;
      overflow: hidden;
    }
    .proc-detail-content .proc-table-wrap td,
    .proc-detail-content .proc-table-wrap th {
      position: relative;
      min-width: 150px;
      min-height: 54px;
      height: 54px;
    }
    .proc-detail-content pre.proc-code-block {
      position: relative;
      display: block;
      width: fit-content;
      min-width: 320px;
      max-width: 100%;
      margin: 1rem 0;
      padding: 3rem 1rem 1rem;
      border-radius: 1rem;
      background: #fffbd1;
      color: #4b5563;
      overflow: auto;
      border: 1px solid #efe7a8;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
    }
    .proc-detail-content pre.proc-code-block::before {
      position: absolute;
      top: .65rem;
      right: .8rem;
      display: inline-block;
      margin: 0;
      background: #f7ef9a;
    }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"] {
      display: block;
      width: fit-content;
      max-width: 100%;
      clear: both;
    }
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="left"],
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="free"],
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="right"],
    .proc-detail-content .proc-side-layout[data-side-mode="compact"][data-align="center"] {
      float: none;
      margin: 1rem 0;
    }
    .proc-pdf-modal .modal-dialog { max-width: min(1440px, 96vw); }
    .proc-pdf-modal .modal-content { height: 92vh; border: 0; border-radius: 1.25rem; overflow: hidden; box-shadow: 0 28px 60px rgba(15, 23, 42, .24); }
    .proc-pdf-modal .modal-header { background: linear-gradient(180deg, #f8fbff, #ffffff); border-bottom: 1px solid #e5edf7; }
    .proc-pdf-modal .modal-body { padding: 0; background: #edf4ff; }
    .proc-pdf-frame { width: 100%; height: 100%; min-height: 72vh; border: 0; background: #fff; }
    .proc-editor-wrap { border: 1px solid #d7deea; border-radius: 1.15rem; overflow: visible; background: #fff; box-shadow: 0 18px 36px rgba(15, 23, 42, .06); }
    .proc-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      padding: .85rem;
      background:
        radial-gradient(circle at top left, rgba(56, 189, 248, .11), transparent 18rem),
        linear-gradient(180deg, #f8fbff, #fdfefe);
      border-bottom: 1px solid #d7deea;
    }
    .proc-toolbar-group {
      display: flex;
      flex-wrap: wrap;
      gap: .45rem;
      align-items: center;
      padding: .55rem;
      border: 1px solid #dce7f3;
      border-radius: 1rem;
      background: rgba(255,255,255,.82);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.92);
    }
    .proc-toolbar-label {
      display: inline-flex;
      align-items: center;
      padding: 0 .15rem;
      color: #64748b;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .proc-toolbar .btn {
      min-width: 2.65rem;
      border-radius: .85rem;
      border-color: #d5e2f0;
      color: #334155;
      background: #fff;
      box-shadow: 0 1px 0 rgba(255,255,255,.9) inset;
    }
    .proc-toolbar .btn:hover {
      border-color: #93c5fd;
      color: #1d4ed8;
      background: #eff6ff;
    }
    .proc-toolbar .btn-outline-dark {
      border-color: #cbd5e1;
      color: #0f172a;
      background: linear-gradient(180deg, #fffef5, #fff8d7);
    }
    .proc-toolbar .btn-outline-primary,
    .proc-toolbar .btn-outline-secondary {
      background: #fff;
    }
    .proc-toolbar .form-select { width: auto; min-width: 140px; border-radius: .85rem; border-color: #d5e2f0; }
    .proc-toolbar .form-control { min-width: 140px; border-radius: .85rem; border-color: #d5e2f0; }
    .proc-toolbar #font-size-select {
      min-width: 0;
      width: 4.75rem;
      text-align: center;
      padding-left: .55rem;
      padding-right: .35rem;
    }
    .proc-toolbar #font-color-input {
      width: 3rem;
      min-width: 3rem;
      height: 2rem;
      padding: .18rem;
    }
    .proc-toolbar .proc-toolbar-spacer { flex: 1 1 1rem; min-width: 0; }
    .proc-toolbar-hint {
      width: 100%;
      margin-top: -.1rem;
      color: #64748b;
      font-size: .78rem;
      line-height: 1.4;
    }
    .proc-editor-sep {
      height: 0;
      margin: 1.15rem 0;
      border: 0;
      border-top: 2px dashed #cbd5e1;
    }
    .proc-page-break {
      display: none !important;
    }
    .proc-callout {
      margin: 1rem 0;
      padding: .95rem 1rem;
      border-radius: 1rem;
      border: 1px solid #cfe5ff;
      background: linear-gradient(180deg, #f7fbff, #edf7ff);
      color: #1e3a5f;
    }
    .proc-editor .proc-callout {
      position: relative;
      display: block;
      width: min(100%, 540px);
      max-width: 100%;
      clear: both;
      --proc-inline-offset: 0px;
      padding: 2.85rem 1rem 1rem;
      box-shadow: 0 14px 26px rgba(15, 23, 42, .08);
    }
    .proc-editor .proc-callout[data-align="left"] { float: left; clear: none; margin: 1rem 1rem 1rem var(--proc-inline-offset); }
    .proc-editor .proc-callout[data-align="right"] { float: right; clear: none; margin: 1rem var(--proc-inline-offset) 1rem 1rem; }
    .proc-editor .proc-callout[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
    .proc-editor .proc-callout.is-selected { outline: 2px solid #60a5fa; outline-offset: 4px; }
    .proc-editor .proc-callout.is-dragging { opacity: .55; }
    .proc-editor .proc-callout-actions {
      position: absolute;
      top: .55rem;
      left: .7rem;
      display: flex;
      gap: .35rem;
      z-index: 2;
    }
    .proc-editor .proc-callout-actions .btn {
      padding: .15rem .45rem;
      font-size: .8rem;
      line-height: 1.1;
    }
    .proc-editor .proc-callout-drag { cursor: grab; user-select: none; }
    .proc-editor .proc-callout-drag:active { cursor: grabbing; }
    .proc-editor .proc-callout-resize {
      position: absolute;
      right: .45rem;
      bottom: .45rem;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      border: 2px solid #2563eb;
      background: #fff;
      box-shadow: 0 2px 8px rgba(37, 99, 235, .25);
      cursor: nwse-resize;
    }
    .proc-callout[data-tone="warning"] {
      border-color: #f5d88b;
      background: linear-gradient(180deg, #fffdf3, #fff7d8);
      color: #7c5d00;
    }
    .proc-callout-title {
      display: block;
      margin-bottom: .35rem;
      font-size: .76rem;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .proc-checklist {
      margin: 1rem 0;
      padding-left: 1.3rem;
    }
    .proc-checklist li {
      margin-bottom: .45rem;
      list-style: none;
      position: relative;
    }
    .proc-checklist li::before {
      content: "☐";
      position: absolute;
      left: -1.25rem;
      top: 0;
      color: #2563eb;
      font-weight: 700;
    }
    .proc-editor {
      position: relative;
      min-height: 480px;
      padding: 1rem 1.1rem;
      outline: none;
      line-height: 1.6;
      overflow: auto;
      isolation: isolate;
      box-sizing: border-box;
      background-image:
        linear-gradient(to right, rgba(59, 130, 246, .06) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(59, 130, 246, .06) 1px, transparent 1px);
      background-size: 24px 24px;
      background-position: 1.1rem 1rem;
    }
    .proc-editor:empty:before { content: attr(data-placeholder); color: #94a3b8; }
    .proc-editor .proc-side-layout { display: grid; width: 100%; --proc-left-space: 1fr; --proc-right-space: 1fr; grid-template-columns: minmax(0, var(--proc-left-space)) minmax(260px, auto) minmax(0, var(--proc-right-space)); gap: 1rem; align-items: start; margin: 1rem 0; clear: both; }
    .proc-editor .proc-side-layout[data-kind="table"] { grid-template-columns: minmax(0, var(--proc-left-space)) minmax(420px, auto) minmax(0, var(--proc-right-space)); }
    .proc-editor .proc-side-layout[data-kind="image"] { grid-template-columns: minmax(0, var(--proc-left-space)) minmax(220px, auto) minmax(0, var(--proc-right-space)); }
    .proc-editor .proc-side-layout[data-side-mode="compact"] { display: inline-grid; width: auto; max-width: 100%; gap: 0; clear: none; }
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="left"] { float: left; margin: 1rem 1rem 1rem 0; }
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="free"] { float: left; margin: 1rem 0 1rem 0; }
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="right"] { float: right; margin: 1rem 0 1rem 1rem; }
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="center"] { display: grid; width: fit-content; margin-left: auto; margin-right: auto; clear: both; }
    .proc-editor .proc-side-layout[data-side-mode="compact"] .proc-side-text { display: none; }
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-image-wrap,
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap,
    .proc-editor .proc-side-layout[data-side-mode="compact"] > pre.proc-code-block,
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-callout { justify-self: start; }
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap { width: fit-content; max-width: min(100%, 760px); }
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap .proc-table-scroll { display: inline-block; width: auto !important; min-width: 420px; max-width: 100%; }
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-table-wrap table { width: max-content; min-width: 420px; max-width: 100%; }
    .proc-editor .proc-side-layout[data-side-mode="compact"] > pre.proc-code-block { width: fit-content; min-width: 320px; max-width: min(100%, 760px); }
    .proc-editor .proc-side-layout[data-side-mode="compact"] > .proc-callout { width: fit-content; min-width: 320px; max-width: min(100%, 540px); }
    .proc-editor .proc-side-text { min-height: 2rem; padding: .35rem .45rem; border-radius: .75rem; outline: none; white-space: pre-wrap; word-break: break-word; }
    .proc-editor .proc-side-text:empty::before { content: attr(data-placeholder); color: #94a3b8; }
    .proc-editor .proc-side-text:focus { background: #f8fbff; box-shadow: inset 0 0 0 1px rgba(96, 165, 250, .28); }
    .proc-editor .proc-side-layout > .proc-image-wrap,
    .proc-editor .proc-side-layout > .proc-table-wrap,
    .proc-editor .proc-side-layout > pre.proc-code-block { grid-column: 2; float: none !important; margin: 0 !important; justify-self: center; }
    .proc-editor .proc-side-layout[data-position="free"],
    .proc-editor .proc-callout[data-position="free"] {
      position: absolute;
      float: none !important;
      clear: none !important;
      margin: 0 !important;
      z-index: 2;
      box-sizing: border-box;
      max-width: 100%;
      transform: translateZ(0);
      will-change: left, top;
    }
    .proc-editor .proc-side-layout[data-position="free"] {
      display: block;
      width: fit-content;
      max-width: 100%;
      min-width: 120px;
    }
    .proc-editor .proc-side-layout[data-position="free"] .proc-side-text { display: none; }
    .proc-editor .proc-side-layout[data-position="free"] > .proc-image-wrap,
    .proc-editor .proc-side-layout[data-position="free"] > .proc-table-wrap,
    .proc-editor .proc-side-layout[data-position="free"] > pre.proc-code-block,
    .proc-editor .proc-side-layout[data-position="free"] > .proc-callout {
      float: none !important;
      clear: none !important;
      margin: 0 !important;
      max-width: 100%;
      box-sizing: border-box;
    }
    .proc-editor .proc-side-layout[data-position="free"] > .proc-image-wrap img { max-width: 100%; }
    .proc-editor .proc-side-layout[data-position="free"] > .proc-table-wrap { overflow: visible; }
    .proc-editor .proc-side-layout[data-position="free"].is-selected,
    .proc-editor .proc-callout[data-position="free"].is-selected,
    .proc-editor .proc-side-layout[data-position="free"]:focus-within,
    .proc-editor .proc-callout[data-position="free"]:focus-within { z-index: 20; }
    .proc-editor .proc-side-layout[data-position="free"].is-dragging,
    .proc-editor .proc-callout[data-position="free"].is-dragging { z-index: 30; }
    .proc-editor .proc-side-layout[data-position="free"] .proc-image-tools,
    .proc-editor .proc-side-layout[data-position="free"] .proc-table-tools,
    .proc-editor .proc-side-layout[data-position="free"] .proc-code-actions,
    .proc-editor .proc-callout[data-position="free"] .proc-callout-actions { z-index: 25; }
    .proc-editor .proc-side-layout[data-position="free"] .proc-image-resize,
    .proc-editor .proc-side-layout[data-position="free"] .proc-table-resize,
    .proc-editor .proc-side-layout[data-position="free"] .proc-code-resize,
    .proc-editor .proc-callout[data-position="free"] .proc-callout-resize { z-index: 26; }
    .proc-editor .proc-side-layout[data-position="free"] .proc-image-drag,
    .proc-editor .proc-side-layout[data-position="free"] .proc-table-drag,
    .proc-editor .proc-side-layout[data-position="free"] .proc-code-drag,
    .proc-editor .proc-callout[data-position="free"] .proc-callout-drag { touch-action: none; }
    .proc-editor .proc-drop-indicator {
      position: absolute;
      left: 0;
      right: 0;
      height: 3px;
      transform: translateY(-50%);
      border-radius: 999px;
      background: linear-gradient(90deg, #60a5fa, #2563eb 52%, #60a5fa);
      box-shadow:
        0 0 0 1px rgba(191, 219, 254, .82),
        0 0 18px rgba(59, 130, 246, .22);
      pointer-events: none;
      z-index: 6;
      opacity: .98;
    }
    .proc-editor .proc-drop-indicator-vertical {
      position: absolute;
      top: 0;
      bottom: 0;
      width: 3px;
      transform: translateX(-50%);
      border-radius: 999px;
      background: linear-gradient(180deg, #60a5fa, #2563eb 52%, #60a5fa);
      box-shadow:
        0 0 0 1px rgba(191, 219, 254, .82),
        0 0 18px rgba(59, 130, 246, .22);
      pointer-events: none;
      z-index: 6;
      opacity: .98;
    }
    .proc-editor .proc-drop-placeholder {
      position: relative;
      min-height: 18px;
      margin: .35rem 0;
      clear: both;
      pointer-events: none;
    }
    .proc-editor .is-drag-ghost {
      opacity: .92;
      pointer-events: none !important;
      z-index: 1400 !important;
      max-width: calc(100vw - 2rem) !important;
      box-sizing: border-box;
      box-shadow: 0 24px 48px rgba(15, 23, 42, .18);
    }
    .proc-editor::after { content: ""; display: block; clear: both; }
    .proc-editor .proc-image-wrap { position: relative; display: inline-block; width: fit-content; max-width: 100%; margin: .5rem 0; clear: both; --proc-inline-offset: 0px; }
    .proc-editor .proc-image-wrap[data-align="left"] { display: block; float: left; clear: none; margin: .5rem 1rem .85rem var(--proc-inline-offset); }
    .proc-editor .proc-image-wrap[data-align="center"] { display: block; float: none; clear: both; margin-left: auto; margin-right: auto; }
    .proc-editor .proc-image-wrap[data-align="right"] { display: block; float: right; clear: none; margin: .5rem var(--proc-inline-offset) .85rem 1rem; }
    .proc-editor .proc-image-wrap.is-selected { outline: 2px solid #60a5fa; outline-offset: 4px; border-radius: .9rem; }
    .proc-editor .proc-image-wrap img { display: block; max-width: 100%; height: auto; border-radius: .75rem; box-shadow: 0 12px 24px rgba(15, 23, 42, 0.10); }
    .proc-editor .proc-image-tools { position: absolute; top: .55rem; left: .55rem; display: flex; gap: .35rem; z-index: 2; }
    .proc-editor .proc-image-tools .btn { padding: .15rem .45rem; font-size: .8rem; line-height: 1.1; }
    .proc-editor .proc-image-drag { cursor: grab; user-select: none; }
    .proc-editor .proc-image-drag:active { cursor: grabbing; }
    .proc-editor .proc-image-resize { position: absolute; right: .45rem; bottom: .45rem; width: 16px; height: 16px; border-radius: 50%; border: 2px solid #2563eb; background: #fff; box-shadow: 0 2px 8px rgba(37, 99, 235, .25); cursor: nwse-resize; }
    .proc-editor .proc-image-wrap.is-dragging { opacity: .55; }
    .proc-editor .proc-table-wrap { position: relative; display: block; width: fit-content; max-width: 100%; margin: 1rem 0; padding-top: 0; border: 1px solid #dbe6f2; border-radius: 1.15rem; background: linear-gradient(180deg, #ffffff, #fbfdff); box-shadow: 0 18px 34px rgba(15, 23, 42, 0.07); overflow: hidden; clear: both; --proc-inline-offset: 0px; }
    .proc-editor .proc-table-wrap[data-align="left"] { float: left; clear: none; margin: 1rem 1rem 1rem var(--proc-inline-offset); }
    .proc-editor .proc-table-wrap[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
    .proc-editor .proc-table-wrap[data-align="right"] { float: right; clear: none; margin: 1rem var(--proc-inline-offset) 1rem 1rem; }
    .proc-editor .proc-table-wrap.is-selected { outline: 2px solid #60a5fa; outline-offset: 4px; }
    .proc-editor .proc-table-wrap.is-editing { border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96, 165, 250, .18); }
    .proc-editor .proc-table-tools { position: relative; display: flex; flex-wrap: wrap; gap: .35rem; z-index: 2; align-items: center; padding: .55rem .7rem .45rem; border-bottom: 1px solid #e6eef7; background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.96)); }
    .proc-editor .proc-table-tools .btn { padding: .15rem .45rem; font-size: .8rem; line-height: 1.1; max-width: 100%; }
    .proc-editor .proc-table-tools .form-select,
    .proc-editor .proc-table-tools .form-control { max-width: 100%; }
    .proc-editor .proc-table-wrap.is-compact .proc-table-tools { gap: .3rem; padding: .5rem .55rem .4rem; }
    .proc-editor .proc-table-wrap.is-compact .proc-table-tools .btn { font-size: .74rem; padding: .14rem .38rem; }
    .proc-editor .proc-table-wrap.is-compact .proc-table-style { min-width: 0; width: 100%; flex: 1 1 11rem; }
    .proc-editor .proc-table-wrap.is-compact .proc-table-drag { margin-left: 0; }
    .proc-editor .proc-table-drag { cursor: grab; user-select: none; margin-left: auto; }
    .proc-editor .proc-table-drag:active { cursor: grabbing; }
    .proc-editor .proc-table-wrap.is-dragging { opacity: .55; }
    .proc-editor .proc-table-scroll { overflow: auto; min-width: 420px; min-height: 170px; width: auto; padding: .45rem .55rem .75rem; background: linear-gradient(180deg, rgba(239,246,255,.55), rgba(255,255,255,0)); }
    .proc-editor .proc-table-wrap table { width: max-content; min-width: 420px; table-layout: fixed; border-collapse: separate; border-spacing: 0; margin: 0; background: #fff; border-radius: .9rem; overflow: hidden; }
    .proc-editor .proc-table-wrap thead th { background: linear-gradient(180deg, #d9efff, #eef8ff); color: #1e3a5f; font-weight: 700; letter-spacing: .02em; }
    .proc-editor .proc-table-wrap td, .proc-editor .proc-table-wrap th { position: relative; border-right: 1px solid #dde7f2; border-bottom: 1px solid #dde7f2; padding: .75rem .85rem; min-width: 150px; min-height: 54px; height: 54px; vertical-align: top; white-space: pre-wrap; word-break: break-word; transition: background .15s ease; }
    .proc-editor .proc-table-wrap tr > *:first-child { border-left: 1px solid #dde7f2; }
    .proc-editor .proc-table-wrap thead tr:first-child > * { border-top: 1px solid #dde7f2; }
    .proc-editor .proc-table-wrap tbody td { color: #334155; }
    .proc-editor .proc-table-wrap tbody tr:nth-child(odd) td { background: #ffffff; }
    .proc-editor .proc-table-wrap tbody tr:nth-child(even) td { background: #f9fbfe; }
    .proc-editor .proc-table-wrap td img,
    .proc-editor .proc-table-wrap th img { display: block; max-width: 100%; height: auto; margin: .2rem 0; border-radius: .65rem; }
    .proc-editor .proc-table-wrap td[contenteditable="true"],
    .proc-editor .proc-table-wrap th[contenteditable="true"] { outline: none; background: #fffef0; }
    .proc-editor .proc-table-wrap td[contenteditable="false"],
    .proc-editor .proc-table-wrap th[contenteditable="false"] { background: #f8fafc; cursor: default; }
    .proc-editor .proc-table-cell-selected { box-shadow: inset 0 0 0 2px rgba(37, 99, 235, .35); }
    .proc-editor .proc-table-wrap th:empty::before { content: attr(data-placeholder); color: #3b82f6; font-style: italic; }
    .proc-editor .proc-table-wrap th:empty::before { color: #3b82f6; font-weight: 600; }
    .proc-editor .proc-table-wrap[data-border-style="dashed"] td,
    .proc-editor .proc-table-wrap[data-border-style="dashed"] th { border-right-style: dashed; border-bottom-style: dashed; }
    .proc-editor .proc-table-wrap[data-border-style="dashed"] tr > *:first-child { border-left-style: dashed; }
    .proc-editor .proc-table-wrap[data-border-style="dashed"] thead tr:first-child > * { border-top-style: dashed; }
    .proc-editor .proc-table-wrap[data-border-style="thick"] td,
    .proc-editor .proc-table-wrap[data-border-style="thick"] th { border-right-width: 2px; border-bottom-width: 2px; border-color: #b8cce2; }
    .proc-editor .proc-table-wrap[data-border-style="thick"] tr > *:first-child { border-left-width: 2px; border-left-color: #b8cce2; }
    .proc-editor .proc-table-wrap[data-border-style="thick"] thead tr:first-child > * { border-top-width: 2px; border-top-color: #b8cce2; }
    .proc-editor .proc-table-wrap[data-border-style="minimal"] td,
    .proc-editor .proc-table-wrap[data-border-style="minimal"] th { border-right-color: transparent; border-left-color: transparent; }
    .proc-editor .proc-table-wrap[data-border-style="minimal"] tbody td { border-bottom-color: #e8eef6; }
    .proc-editor .proc-table-style { width: auto; min-width: 110px; font-size: .8rem; padding: .2rem 1.8rem .2rem .5rem; }
    @media (max-width: 760px) {
      .proc-editor .proc-table-tools { gap: .3rem; padding: .5rem .55rem .4rem; }
      .proc-editor .proc-table-tools .btn { font-size: .74rem; padding: .14rem .38rem; }
      .proc-editor .proc-table-style { min-width: 0; width: 100%; flex: 1 1 11rem; }
      .proc-editor .proc-table-drag { margin-left: 0; }
    }
    .proc-editor .proc-table-resize { position: absolute; right: .55rem; bottom: .5rem; width: 16px; height: 16px; border-radius: 50%; border: 2px solid #2563eb; background: #fff; box-shadow: 0 2px 8px rgba(37, 99, 235, .25); cursor: nwse-resize; z-index: 3; }
    .proc-editor .proc-table-col-resize-handle,
    .proc-editor .proc-table-row-resize-handle { position: absolute; background: #2563eb; border-radius: 999px; box-shadow: 0 2px 8px rgba(37, 99, 235, .25); z-index: 4; }
    .proc-editor .proc-table-col-resize-handle { width: 10px; height: 38px; cursor: ew-resize; }
    .proc-editor .proc-table-row-resize-handle { width: 38px; height: 10px; cursor: ns-resize; }
    .proc-editor pre.proc-code-block { position: relative; display: block; width: fit-content; min-width: 320px; max-width: 100%; --proc-inline-offset: 0px; margin: 1rem 0; padding: 3rem 1rem 1rem; border-radius: 1rem; background: #fffbd1; color: #4b5563; overflow: auto; border: 1px solid #efe7a8; box-shadow: inset 0 1px 0 rgba(255,255,255,.9); }
    .proc-editor pre.proc-code-block[data-align="left"] { float: left; clear: none; margin: 1rem 1rem 1rem var(--proc-inline-offset); }
    .proc-editor pre.proc-code-block[data-align="right"] { float: right; clear: none; margin: 1rem var(--proc-inline-offset) 1rem 1rem; }
    .proc-editor pre.proc-code-block[data-align="center"] { float: none; clear: both; margin-left: auto; margin-right: auto; }
    .proc-editor pre.proc-code-block.is-selected { outline: 2px solid #d6c94a; }
    .proc-editor pre.proc-code-block.is-editing { border-color: #d6c94a; box-shadow: 0 0 0 3px rgba(214, 201, 74, .18); }
    .proc-editor pre.proc-code-block::before { content: attr(data-lang-label); position: absolute; top: .65rem; right: .8rem; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: #7c5d00; background: #f7ef9a; padding: .2rem .45rem; border-radius: 999px; }
    .proc-editor .proc-code-actions { position: absolute; top: .55rem; left: .7rem; display: flex; gap: .35rem; z-index: 2; }
    .proc-editor .proc-code-actions .btn { padding: .15rem .45rem; font-size: .8rem; line-height: 1.1; }
    .proc-editor .proc-code-drag { cursor: grab; user-select: none; }
    .proc-editor .proc-code-drag:active { cursor: grabbing; }
    .proc-editor .proc-code-resize { position: absolute; right: .45rem; bottom: .45rem; width: 16px; height: 16px; border-radius: 50%; border: 2px solid #b45309; background: #fffdf0; box-shadow: 0 2px 8px rgba(180, 83, 9, .2); cursor: nwse-resize; }
    .proc-editor pre.proc-code-block.is-dragging { opacity: .55; }
    .proc-editor pre.proc-code-block code { display: block; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; font-size: .92rem; line-height: 1.55; min-height: 1.2rem; outline: none; }
    .proc-editor pre.proc-code-block code[contenteditable="false"] { user-select: text; cursor: default; }
    .proc-editor .tok-keyword { color: #7c3aed; font-weight: 700; }
    .proc-editor .tok-tag { color: #0f766e; font-weight: 600; }
    .proc-editor .tok-string { color: #0f9f5a; }
    .proc-editor .tok-number { color: #c2410c; }
    .proc-editor .tok-comment { color: #6b7280; font-style: italic; }
    .proc-editor .tok-attr { color: #9a6700; font-weight: 600; }
    .proc-dropzone { border: 1px dashed #93c5fd; background: #eff6ff; color: #1d4ed8; border-radius: .85rem; padding: .75rem 1rem; font-size: .95rem; }
    .proc-panel,
    .proc-detail-panel,
    .proc-editor-wrap { border-radius: .75rem; }
    .proc-detail-header,
    .proc-detail-actions,
    .proc-detail-content { padding-left: 1.35rem; padding-right: 1.35rem; }
    .proc-toolbar {
      position: sticky;
      top: 5.85rem;
      z-index: 1045;
      gap: .5rem;
      margin: -.1rem -.1rem 0;
      background: rgba(248, 251, 255, .96);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(215, 226, 240, .9);
      border-radius: .75rem;
      box-shadow: 0 16px 34px rgba(15, 23, 42, .14);
      max-height: 38vh;
      overflow: auto;
    }
    .proc-toolbar .btn,
    .proc-toolbar .form-select,
    .proc-toolbar .form-control { border-radius: .55rem; }
    .proc-editor {
      min-height: 560px;
      padding: 1.25rem;
      background-color: #fff;
      border-radius: 0 0 .75rem .75rem;
      background-clip: padding-box;
      --proc-page-guide-height: 950px;
      --proc-page-guide-color: rgba(37, 99, 235, .55);
      --proc-page-guide-gap: 48px;
      --proc-page-guide-gap-color: rgba(219, 234, 254, .58);
      background-image:
        repeating-linear-gradient(
          to bottom,
          var(--proc-page-guide-gap-color) 0 var(--proc-page-guide-gap),
          transparent var(--proc-page-guide-gap) calc(var(--proc-page-guide-height) - var(--proc-page-guide-gap)),
          var(--proc-page-guide-gap-color) calc(var(--proc-page-guide-height) - var(--proc-page-guide-gap)) calc(var(--proc-page-guide-height) - 1px),
          var(--proc-page-guide-color) calc(var(--proc-page-guide-height) - 1px) var(--proc-page-guide-height)
        ),
        linear-gradient(to right, rgba(148, 163, 184, .12) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(148, 163, 184, .12) 1px, transparent 1px);
      background-size:
        100% var(--proc-page-guide-height),
        24px 24px,
        24px 24px;
      background-position:
        0 0,
        0 0,
        0 0;
    }
    .proc-editor:focus { box-shadow: inset 0 0 0 2px rgba(37, 99, 235, .18); }
    .proc-dropzone {
      display: flex;
      align-items: center;
      min-height: 48px;
      background: #f8fbff;
    }
    @media (max-width: 820px) {
      .proc-board-head { align-items: stretch; flex-direction: column; }
      .proc-board-actions { width: 100%; }
      .proc-search-wrap { flex: 1 1 auto; min-width: 0; }
      .proc-new-btn { flex: 0 0 auto; }
    }
    @media (max-width: 560px) {
      .proc-board-head { padding: 1rem; }
      .proc-board-actions { flex-direction: column; align-items: stretch; }
      .proc-new-btn { width: 100%; }
      .proc-grid { grid-template-columns: 1fr; padding: 1rem; }
    }
    @media (max-width: 1100px) {
      .proc-shell { grid-template-columns: 1fr; }
      .proc-editor { min-height: 320px; }
    }
    body.proc-public-share { padding-top: 0; }
  </style>
</head>
<body class="bg-light<?= $isPublicShare ? ' proc-public-share' : '' ?>" data-proc-page-size="<?= $h($selectedPageSize) ?>">
<?php if (!$isPublicShare): ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>
<?php endif; ?>
<div id="page-content">
  <div class="container-fluid py-4">
    <?php if ($isPublicShare && $selectedProcedure): ?>
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <span class="proc-detail-badge"><i class="bi bi-share"></i> Enlace compartido</span>
      </div>
    <?php endif; ?>
    <?php if (!$isPublicShare): ?>
    <?php
      $heroIcon = 'bi-journal-richtext';
      $heroTitle = 'Procedimientos';
      $heroSubtitle = 'Base interna de procedimientos con texto enriquecido e imágenes, editable como un documento operativo.';
      include __DIR__ . '/../partials/hero.php';
    ?>
    <?php endif; ?>

    <?php if ($isPublicShare && !$selectedProcedure): ?>
      <section class="proc-board card shadow-sm mb-4">
        <div class="proc-empty-state"><?= $h($error ?? 'El enlace compartido no está disponible.') ?></div>
      </section>
    <?php elseif (!$showEditor && !$showDetail): ?>
      <section class="proc-board card shadow-sm mb-4">
        <div class="proc-board-head">
          <div class="proc-board-title">
            <span class="proc-board-icon badge-soft badge-soft-primary"><i class="bi bi-folder2-open"></i></span>
            <div>
              <h2>Documentos creados</h2>
              <p><?= count($procedures) ?> registrados</p>
            </div>
          </div>
          <div class="proc-board-actions">
            <label class="proc-search-wrap" for="procedure-search">
              <i class="bi bi-search"></i>
              <input type="search" id="procedure-search" class="form-control" placeholder="Buscar por título">
            </label>
            <a class="proc-new-btn btn btn-primary" href="/redmine-mantencion/views/Procedimientos/procedimientos.php?new=1">
              <i class="bi bi-plus-lg"></i> Nuevo
            </a>
          </div>
        </div>
          <?php if (empty($procedures)): ?>
            <div class="proc-empty-state">Aún no hay procedimientos guardados.</div>
          <?php else: ?>
            <div class="proc-grid" id="procedure-list">
              <?php foreach ($procedures as $procedure): ?>
                <?php
                  $itemId = (string)($procedure['id'] ?? '');
                  $itemTitle = trim((string)($procedure['title'] ?? 'Sin título'));
                  $itemUpdated = trim((string)($procedure['updated_at'] ?? ''));
                ?>
                <a
                  href="/redmine-mantencion/views/Procedimientos/procedimientos.php?id=<?= urlencode($itemId) ?>"
                   class="proc-grid-card card"
                  data-search="<?= $h(strtolower($itemTitle)) ?>"
                >
                  <div class="proc-card-top">
                    <span class="proc-card-icon"><i class="bi bi-file-earmark-text"></i></span>
                    <div class="proc-card-body">
                      <div class="proc-title"><?= $h($itemTitle) ?></div>
                      <?php if ($itemUpdated !== ''): ?>
                        <div class="proc-card-meta"><?= $h(date('d-m-Y', strtotime($itemUpdated))) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
      </section>
    <?php elseif ($showDetail && $selectedProcedure): ?>
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <?php if (!$isPublicShare): ?>
        <a class="btn btn-outline-secondary" href="/redmine-mantencion/views/Procedimientos/procedimientos.php">
          <i class="bi bi-arrow-left"></i> Volver
        </a>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <div class="text-muted small">Vista del documento</div>
      </div>
      <section class="card proc-detail-panel">
        <header class="proc-detail-header">
          <span class="proc-detail-badge"><i class="bi bi-file-earmark-richtext"></i> Procedimiento</span>
          <h1 class="proc-detail-title"><?= $h($selectedProcedure['title'] ?? '') ?></h1>
          <div class="proc-detail-meta">
            <?php if (!empty($selectedProcedure['author_name'])): ?>
              <span class="proc-detail-meta-item">
                <span class="proc-detail-meta-label">Autor</span>
                <span class="proc-detail-meta-value"><?= $h($selectedProcedure['author_name']) ?></span>
              </span>
            <?php endif; ?>
            <?php if (!empty($selectedProcedure['updated_at'])): ?>
              <span class="proc-detail-meta-item">
                <span class="proc-detail-meta-label">Actualizado</span>
                <span class="proc-detail-meta-value"><?= $h(date('d-m-Y H:i', strtotime((string)$selectedProcedure['updated_at']))) ?></span>
              </span>
            <?php endif; ?>
          </div>
        </header>
        <div class="proc-detail-actions">
          <div class="proc-detail-actions-group">
            <?php if (!$isPublicShare): ?>
            <a class="btn btn-primary" href="/redmine-mantencion/views/Procedimientos/procedimientos.php?id=<?= urlencode((string)$selectedProcedure['id']) ?>&edit=1">
              <i class="bi bi-pencil-square"></i> Editar
            </a>
            <?php endif; ?>
            <button
              type="button"
              class="btn btn-outline-primary"
              data-procedure-print
              data-pdf-title="<?= $h($selectedProcedure['title'] ?? 'Procedimiento') ?>"
              data-page-size="<?= $h($selectedPageSize) ?>"
            >
              <i class="bi bi-printer"></i> Imprimir
            </button>
            <?php if ($shareUrl !== ''): ?>
              <button
                type="button"
                class="btn btn-outline-primary"
                data-share-url="<?= $h($shareUrl) ?>"
              >
                <i class="bi bi-share"></i> Compartir
              </button>
            <?php endif; ?>
          </div>
        </div>
        <div class="proc-detail-content" id="procedureReadContent"><?= $selectedProcedure['content_html'] ?? '' ?></div>
      </section>
    <?php else: ?>
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <a class="btn btn-outline-secondary" href="/redmine-mantencion/views/Procedimientos/procedimientos.php">
          <i class="bi bi-arrow-left"></i> Volver
        </a>
        <div class="text-muted small"><?= !empty($form['id']) ? 'Editando procedimiento' : 'Nuevo procedimiento' ?></div>
      </div>
      <section class="card proc-panel">
        <div class="card-body">
          <form method="post" id="procedure-form" class="d-flex flex-column gap-3">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $h($form['id'] ?? '') ?>">
            <input type="hidden" name="content_html" id="content_html">

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-semibold">Título</label>
                <input type="text" name="title" class="form-control form-control-lg" value="<?= $h($form['title'] ?? '') ?>" placeholder="Ej. Alta de usuario CORE" required>
              </div>
            </div>

            <div class="proc-editor-wrap">
              <div class="proc-toolbar">
                <button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="bold" title="Negrita"><i class="bi bi-type-bold"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="italic" title="Cursiva"><i class="bi bi-type-italic"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="underline" title="Subrayado"><i class="bi bi-type-underline"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="insertUnorderedList" title="Lista"><i class="bi bi-list-ul"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="insertOrderedList" title="Lista numerada"><i class="bi bi-list-ol"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="insert-checklist-btn" title="Checklist"><i class="bi bi-check2-square"></i> Checklist</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="add-list-item-btn" title="Añadir ítem"><i class="bi bi-plus-lg"></i> Ítem</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="remove-list-item-btn" title="Quitar ítem"><i class="bi bi-dash-lg"></i> Ítem</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-block="h2" title="Título"><i class="bi bi-type-h2"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-block="h3" title="Subtítulo"><i class="bi bi-type-h3"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="add-link-btn" title="Enlace"><i class="bi bi-link-45deg"></i></button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="remove-format-btn" title="Limpiar formato"><i class="bi bi-eraser"></i></button>
                <select class="form-select form-select-sm" id="font-family-select" title="Fuente">
                  <option value="">Fuente</option>
                  <option value="Calibri, sans-serif">Calibri</option>
                  <option value="'Arial', sans-serif">Arial</option>
                  <option value="'Times New Roman', serif">Times New Roman</option>
                  <option value="'Aptos', 'Segoe UI', sans-serif">Aptos</option>
                  <option value="'Verdana', sans-serif">Verdana</option>
                  <option value="'Tahoma', sans-serif">Tahoma</option>
                  <option value="'Georgia', serif">Georgia</option>
                  <option value="'Garamond', serif">Garamond</option>
                  <option value="'Trebuchet MS', sans-serif">Trebuchet MS</option>
                  <option value="'Fira Code', monospace">Fira Code</option>
                  <option value="'Courier New', monospace">Courier New</option>
                </select>
                <input type="number" class="form-control form-control-sm" id="font-size-select" title="Tamaño" min="8" max="72" step="1" value="12" placeholder="12">
                <input type="color" class="form-control form-control-color" id="font-color-input" title="Color del texto" value="#334155">
                <select class="form-select form-select-sm" id="page-size-select" name="page_size" title="Tamaño de hoja">
                  <option value="letter" <?= $selectedPageSize === 'letter' ? 'selected' : '' ?>>Hoja Carta</option>
                  <option value="a4" <?= $selectedPageSize === 'a4' ? 'selected' : '' ?>>Hoja A4</option>
                  <option value="oficio" <?= $selectedPageSize === 'oficio' ? 'selected' : '' ?>>Hoja Oficio</option>
                </select>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="insert-note-btn" title="Insertar nota"><i class="bi bi-stickies"></i> Nota</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="insert-warning-btn" title="Insertar advertencia"><i class="bi bi-exclamation-triangle"></i> Alerta</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="insert-separator-btn" title="Insertar separador"><i class="bi bi-hr"></i> Separador</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="insert-table-btn" title="Insertar tabla"><i class="bi bi-table"></i> Tabla</button>
                <select class="form-select form-select-sm" id="code-language" title="Lenguaje de código">
                  <option value="html">HTML</option>
                  <option value="sql">SQL</option>
                  <option value="css">CSS</option>
                  <option value="javascript">JavaScript</option>
                  <option value="php">PHP</option>
                  <option value="bash">Bash</option>
                  <option value="text">Texto plano</option>
                </select>
                <button class="btn btn-outline-dark btn-sm" type="button" id="insert-code-btn" title="Insertar bloque de código">
                  <i class="bi bi-code-slash"></i> Código
                </button>
                <label class="btn btn-outline-primary btn-sm mb-0" for="image-input" title="Insertar imagen">
                  <i class="bi bi-image"></i> Imagen
                </label>
                <input type="file" id="image-input" accept="image/*" class="d-none" multiple>
                <div class="proc-toolbar-hint">
                  
                </div>
              </div>
              <div class="proc-editor" id="procedure-editor" contenteditable="true" data-placeholder="Escribe aquí el procedimiento, pega capturas o inserta imágenes."><?= $form['content_html'] ?? '' ?></div>
            </div>

            <div class="proc-dropzone" id="dropzone">
              Arrastra imágenes aquí, pégalas desde el portapapeles o usa el botón <strong>Imagen</strong>.
            </div>

            <div class="d-flex flex-wrap gap-2">
              <button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Guardar procedimiento</button>
              <?php if (!empty($form['id'])): ?>
                <button
                  type="button"
                  class="btn btn-outline-primary"
                  data-procedure-print
                  data-pdf-title="<?= $h($form['title'] ?? 'Procedimiento') ?>"
                  data-page-size="<?= $h($selectedPageSize) ?>"
                >
                  <i class="bi bi-printer"></i> Imprimir
                </button>
                <?php if ($shareUrl !== ''): ?>
                  <button
                    type="button"
                    class="btn btn-outline-primary"
                    data-share-url="<?= $h($shareUrl) ?>"
                  >
                    <i class="bi bi-share"></i> Compartir
                  </button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" type="submit" form="procedure-delete-form"><i class="bi bi-trash"></i> Eliminar</button>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!empty($form['id'])): ?>
            <form method="post" id="procedure-delete-form" class="d-none" data-app-confirm="¿Eliminar este procedimiento?">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $h($form['id']) ?>">
            </form>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<?php if ($flash || $error): ?>
  <div class="modal fade" id="procedureMessageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header" data-app-modal-tone="<?= $error ? 'danger' : 'success' ?>">
          <h5 class="modal-title"><?= $error ? 'Error' : 'Mensaje' ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0"><?= $h($error ?: $flash) ?></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<div class="modal fade" id="procedureUiModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" data-app-modal-tone="warning">
        <h5 class="modal-title">Aviso</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0" id="procedureUiModalText"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Aceptar</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade proc-pdf-modal" id="procedurePdfModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="procedurePdfModalTitle">Vista previa PDF</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <iframe class="proc-pdf-frame" id="procedurePdfFrame" title="Vista previa PDF"></iframe>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="procedureLinkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-primary-subtle">
        <h5 class="modal-title">Insertar enlace</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="procedureLinkForm">
        <div class="modal-body">
          <div class="mb-3">
            <label for="procedureLinkUrl" class="form-label fw-semibold">URL</label>
            <input type="text" class="form-control" id="procedureLinkUrl" placeholder="https://ejemplo.com" autocomplete="off" required>
          </div>
          <div class="mb-3">
            <label for="procedureLinkText" class="form-label fw-semibold">Texto visible</label>
            <input type="text" class="form-control" id="procedureLinkText" placeholder="Texto del enlace">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="procedureLinkBlank">
            <label class="form-check-label" for="procedureLinkBlank">Abrir en nueva pestaña</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Insertar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(() => {
  const form = document.getElementById('procedure-form');
  const editor = document.getElementById('procedure-editor');
  const hiddenContent = document.getElementById('content_html');
  const search = document.getElementById('procedure-search');
  const list = document.getElementById('procedure-list');
  const imageInput = document.getElementById('image-input');
  const dropzone = document.getElementById('dropzone');
  const insertTableBtn = document.getElementById('insert-table-btn');
  const codeLanguage = document.getElementById('code-language');
  const fontFamilySelect = document.getElementById('font-family-select');
  const fontSizeSelect = document.getElementById('font-size-select');
  const fontColorInput = document.getElementById('font-color-input');
  const pageSizeSelect = document.getElementById('page-size-select');
  const insertCodeBtn = document.getElementById('insert-code-btn');
  const uiModalEl = document.getElementById('procedureUiModal');
  const uiModalText = document.getElementById('procedureUiModalText');
  const uiModal = (uiModalEl && window.bootstrap) ? new bootstrap.Modal(uiModalEl) : null;
  const pdfModalEl = document.getElementById('procedurePdfModal');
  const pdfFrame = document.getElementById('procedurePdfFrame');
  const pdfModalTitle = document.getElementById('procedurePdfModalTitle');
  const pdfModal = (pdfModalEl && window.bootstrap) ? bootstrap.Modal.getOrCreateInstance(pdfModalEl) : null;
  const linkModalEl = document.getElementById('procedureLinkModal');
  const linkModal = (linkModalEl && window.bootstrap) ? bootstrap.Modal.getOrCreateInstance(linkModalEl) : null;
  const linkForm = document.getElementById('procedureLinkForm');
  const linkUrlInput = document.getElementById('procedureLinkUrl');
  const linkTextInput = document.getElementById('procedureLinkText');
  const linkBlankInput = document.getElementById('procedureLinkBlank');
  const readContent = document.getElementById('procedureReadContent');

  const prepareStaticProcedureContent = (container) => {
    if (!container) return;
    container.querySelectorAll('.proc-side-layout').forEach((layout) => {
      layout.classList.remove('is-selected', 'is-dragging');
      layout.removeAttribute('contenteditable');
      layout.querySelectorAll('.proc-side-text').forEach((region) => region.removeAttribute('contenteditable'));
    });
    container.querySelectorAll('[contenteditable]').forEach((node) => node.removeAttribute('contenteditable'));
    container.querySelectorAll('.is-selected, .is-editing, .is-dragging, .proc-table-cell-selected').forEach((node) => {
      node.classList.remove('is-selected', 'is-editing', 'is-dragging', 'proc-table-cell-selected');
    });
    container.querySelectorAll('.proc-image-tools, .proc-image-resize, .proc-table-tools, .proc-table-resize, .proc-table-col-resize-handle, .proc-table-row-resize-handle, .proc-code-actions, .proc-code-resize, .proc-callout-actions, .proc-callout-resize, .proc-drop-indicator, .proc-drop-indicator-vertical').forEach((node) => node.remove());
    container.querySelectorAll('p').forEach((node) => {
      if (node.innerHTML.replace(/<br\s*\/?>/gi, '').trim() === '') {
        node.remove();
      }
    });
    const escapeHtml = (value) => value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
    const normalizeBrokenTokens = (value) => value
      .replace(/"?tok-[a-z-]+"?>/gi, '')
      .replace(/&quot;?tok-[a-z-]+&quot;&gt;/gi, '')
      .replace(/\u00a0/g, ' ');
    const highlightStaticCode = (source, lang) => {
      let html = escapeHtml(source);
      if (lang === 'html') {
        html = html.replace(/(&lt;\/?)([a-zA-Z0-9:-]+)/g, '$1<span class="tok-tag">$2</span>');
        html = html.replace(/([a-zA-Z-:]+)=(&quot;.*?&quot;)/g, '<span class="tok-attr">$1</span>=<span class="tok-string">$2</span>');
        html = html.replace(/&lt;!--[\s\S]*?--&gt;/g, '<span class="tok-comment">$&</span>');
      } else if (lang === 'sql') {
        html = html.replace(/(--.*?$)/gm, '<span class="tok-comment">$1</span>');
        html = html.replace(/\b(SELECT|FROM|WHERE|AND|OR|ORDER BY|GROUP BY|INSERT INTO|VALUES|UPDATE|SET|DELETE|INNER JOIN|LEFT JOIN|RIGHT JOIN|ON|AS|COUNT|SUM|AVG|MIN|MAX|LIKE|IN|IS NULL|NOT NULL|CREATE|ALTER|DROP|TABLE|FOR UPDATE)\b/gi, '<span class="tok-keyword">$1</span>');
        html = html.replace(/'([^']*)'/g, '<span class="tok-string">\'$1\'</span>');
        html = html.replace(/\b\d+(\.\d+)?\b/g, '<span class="tok-number">$&</span>');
      } else if (lang === 'css') {
        html = html.replace(/\/\*[\s\S]*?\*\//g, '<span class="tok-comment">$&</span>');
        html = html.replace(/([.#]?[a-zA-Z_][\w-]*)(\s*\{)/g, '<span class="tok-tag">$1</span>$2');
        html = html.replace(/([a-z-]+)(\s*:)/gi, '<span class="tok-attr">$1</span>$2');
        html = html.replace(/(#(?:[0-9a-fA-F]{3,8})|\b\d+(\.\d+)?(px|rem|em|%|vh|vw)?\b)/g, '<span class="tok-number">$1</span>');
      } else if (lang === 'javascript' || lang === 'php' || lang === 'bash') {
        html = html.replace(/(\/\/.*?$|#.*?$|\/\*[\s\S]*?\*\/)/gm, '<span class="tok-comment">$1</span>');
        html = html.replace(/\b(function|const|let|var|return|if|else|for|while|switch|case|break|class|new|echo|public|private|protected|foreach|as|try|catch|finally|throw)\b/gi, '<span class="tok-keyword">$1</span>');
        html = html.replace(/(["'`])((?:\\.|(?!\1).)*)\1/g, '<span class="tok-string">$&</span>');
        html = html.replace(/\b\d+(\.\d+)?\b/g, '<span class="tok-number">$&</span>');
      }
      return html;
    };
    container.querySelectorAll('pre.proc-code-block code').forEach((codeEl) => {
      const lang = (codeEl.getAttribute('data-lang') || 'text').toLowerCase();
      const raw = normalizeBrokenTokens(codeEl.textContent || '');
      codeEl.textContent = raw;
      codeEl.innerHTML = highlightStaticCode(raw, lang);
    });
    reserveStaticLayoutSpace(container);
    normalizeFlowAwayFromPageDivisions(container);
    updateFreePositionCanvas(container);
    window.setTimeout(() => {
      normalizeFlowAwayFromPageDivisions(container);
      updateFreePositionCanvas(container);
    }, 120);
  };

  const reserveStaticLayoutSpace = (container) => {
    if (!container) return;
    const apply = () => {
      container.querySelectorAll('.proc-side-layout[data-side-mode="compact"]').forEach((layout) => {
        const children = Array.from(layout.children).filter((child) => !child.classList.contains('proc-side-text'));
        const height = Math.max(
          0,
          ...children.map((child) => Math.ceil(child.getBoundingClientRect().height || child.offsetHeight || 0))
        );
        if (height > 0) {
          layout.style.minHeight = `${height}px`;
        }
      });
      updateFreePositionCanvas(container);
    };
    apply();
    window.setTimeout(apply, 80);
    window.setTimeout(apply, 250);
  };

  function getFreePositionNodes(container) {
    if (!container) return [];
    return Array.from(container.querySelectorAll('.proc-side-layout[data-position="free"], .proc-callout[data-position="free"]'));
  }

  function updateFreePositionCanvas(container = editor) {
    if (!container) return;
    const baseMinHeight = container === editor ? 560 : 260;
    let maxBottom = baseMinHeight;
    getFreePositionNodes(container).forEach((node) => {
      const top = parseFloat(node.style.top || '0') || 0;
      const height = Math.max(0, Math.round(node.getBoundingClientRect().height || node.offsetHeight || 0));
      const safeTop = keepAwayFromPageDivision(top, height);
      if (Math.abs(safeTop - top) >= 1) {
        node.style.top = `${safeTop}px`;
      }
      maxBottom = Math.max(maxBottom, Math.ceil(safeTop + height + 32));
    });
    container.style.minHeight = `${maxBottom}px`;
  }

  const clearFreePosition = (node) => {
    if (!node) return;
    node.removeAttribute('data-position');
    node.style.removeProperty('position');
    node.style.removeProperty('left');
    node.style.removeProperty('top');
    node.style.removeProperty('z-index');
  };

  let selectedCodeBlock = null;
  let selectedImageWrap = null;
  let selectedTableWrap = null;
  let selectedTableCell = null;
  let selectedCallout = null;
  let draggingSortNode = null;
  let draggingSortType = null;
  let draggingSortCompanion = null;
  let draggingSortTail = null;
  let dragIndicator = null;
  let dragVerticalIndicator = null;
  let dragPointerX = null;
  let dragPointerY = null;
  let dragGrabOffsetX = null;
  let dragGrabOffsetY = null;
  let dragNodeGrabOffsetX = null;
  let dragNodeGrabOffsetY = null;
  let dragDropTarget = null;
  let dragDropAfter = false;
  let dragPlaceholder = null;
  let dragLastPlaceholderTarget = null;
  let dragLastPlaceholderAfter = null;
  let draggingSortOriginalParent = null;
  let draggingSortOriginalNextSibling = null;
  let draggingSortOriginalCssText = '';
  let resizingImageWrap = null;
  let resizingTableWrap = null;
  let resizingTableColumnWrap = null;
  let resizingTableRowWrap = null;
  let resizingCodeBlock = null;
  let resizingCallout = null;
  let lastEditorRange = null;
  let resizeStartX = 0;
  let resizeStartY = 0;
  let resizeStartWidth = 0;
  let resizeStartHeight = 0;
  const PROC_GRID_SIZE = 16;
  const PROC_ROW_THRESHOLD = 48;

  const escapeHtml = (value) => value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const showUiMessage = (message) => {
    if (uiModal && uiModalText) {
      uiModalText.textContent = message;
      uiModal.show();
      return;
    }
    console.warn(message);
  };

  const pageGuideHeights = {
    a4: 1016,
    letter: 950,
    oficio: 1141,
  };
  const pageGuideSafeGap = 56;

  const getCurrentPageGuideHeight = () => {
    const pageSize = (pageSizeSelect?.value || document.body.getAttribute('data-proc-page-size') || '<?= $h($selectedPageSize) ?>').toLowerCase();
    return pageGuideHeights[pageSize] || pageGuideHeights.letter;
  };

  const keepAwayFromPageDivision = (top, height) => {
    const guideHeight = getCurrentPageGuideHeight();
    if (!Number.isFinite(top) || !Number.isFinite(height) || guideHeight <= 0) {
      return Math.max(0, top || 0);
    }
    let nextTop = Math.max(0, top);
    let guard = 0;
    while (guard < 12) {
      guard += 1;
      const bottom = nextTop + Math.max(1, height);
      const firstBoundary = Math.max(1, Math.floor(nextTop / guideHeight));
      let moved = false;
      for (let page = firstBoundary; page <= Math.ceil(bottom / guideHeight) + 1; page += 1) {
        const boundary = page * guideHeight;
        if (boundary <= 0) continue;
        const unsafeTop = boundary - pageGuideSafeGap;
        const unsafeBottom = boundary + pageGuideSafeGap;
        if (nextTop < unsafeBottom && bottom > unsafeTop) {
          const center = nextTop + height / 2;
          const beforeTop = Math.max(0, unsafeTop - height - 8);
          const afterTop = unsafeBottom + 8;
          nextTop = center < boundary && beforeTop > 0 ? beforeTop : afterTop;
          moved = true;
          break;
        }
      }
      if (!moved) break;
    }
    return Math.max(0, Math.round(nextTop / PROC_GRID_SIZE) * PROC_GRID_SIZE);
  };

  const keepFlowBlockAwayFromPageDivision = (top, height) => {
    const guideHeight = getCurrentPageGuideHeight();
    if (!Number.isFinite(top) || !Number.isFinite(height) || guideHeight <= 0) {
      return Math.max(0, top || 0);
    }
    const blockHeight = Math.max(1, height);
    const bottom = top + blockHeight;
    const firstPage = Math.max(1, Math.floor((top - pageGuideSafeGap) / guideHeight));
    const lastPage = Math.max(firstPage, Math.ceil((bottom + pageGuideSafeGap) / guideHeight));

    for (let page = firstPage; page <= lastPage; page += 1) {
      const pageBoundary = page * guideHeight;
      const unsafeTop = pageBoundary - pageGuideSafeGap;
      const unsafeBottom = pageBoundary + pageGuideSafeGap;
      const startsInside = top >= unsafeTop && top <= unsafeBottom;
      const crossesDivision = top < unsafeBottom && bottom > unsafeTop;
      const endsInside = bottom >= unsafeTop && bottom <= unsafeBottom;
      if (startsInside || endsInside || crossesDivision) {
        return Math.max(0, Math.round((unsafeBottom + 18) / PROC_GRID_SIZE) * PROC_GRID_SIZE);
      }
    }

    return Math.max(0, top);
  };

  const normalizeFlowAwayFromPageDivisions = (container = editor) => {
    if (!container) return;
    const flowNodes = Array.from(container.children).filter((node) => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.dataset.position === 'free') return false;
      if (node.classList.contains('proc-drop-indicator') || node.classList.contains('proc-drop-indicator-vertical')) return false;
      if (node.classList.contains('proc-drop-placeholder')) return false;
      return window.getComputedStyle(node).display !== 'none';
    });

    for (let pass = 0; pass < 5; pass += 1) {
      let changed = false;
      const containerRect = container.getBoundingClientRect();
      const containerStyle = window.getComputedStyle(container);
      const paddingTop = parseFloat(containerStyle.paddingTop || '0') || 0;
      flowNodes.forEach((node) => {
        if (pass === 0) {
          if (!Object.prototype.hasOwnProperty.call(node.dataset, 'pageSafeBaseMarginTop')) {
            node.dataset.pageSafeBaseMarginTop = node.style.marginTop || '';
          }
          node.style.marginTop = node.dataset.pageSafeBaseMarginTop || '';
        }
        const rect = node.getBoundingClientRect();
        const top = rect.top - containerRect.top - paddingTop + container.scrollTop;
        const height = Math.max(1, Math.round(rect.height || node.offsetHeight || 1));
        const safeTop = keepFlowBlockAwayFromPageDivision(top, height);
        const delta = safeTop - top;
        if (delta > 1) {
          const computedMarginTop = parseFloat(window.getComputedStyle(node).marginTop || '0') || 0;
          node.style.marginTop = `${Math.ceil(computedMarginTop + delta)}px`;
          changed = true;
        }
      });
      if (!changed) break;
    }
  };

  const updatePageGuides = () => {
    if (!editor) return;
    const guideHeight = getCurrentPageGuideHeight();
    const pageSize = (pageSizeSelect?.value || document.body.getAttribute('data-proc-page-size') || '<?= $h($selectedPageSize) ?>').toLowerCase();
    editor.style.setProperty('--proc-page-guide-height', `${guideHeight}px`);
    document.body.setAttribute('data-proc-page-size', pageSize);
    normalizeFlowAwayFromPageDivisions(editor);
    updateFreePositionCanvas(editor);
  };

  const getCodeRawText = (codeEl) => {
    if (!codeEl) return '';
    const stored = codeEl.getAttribute('data-raw-code');
    if (stored !== null) {
      return stored;
    }
    return extractCodePlainText(codeEl);
  };

  const setCodeRawText = (codeEl, value) => {
    if (!codeEl) return;
    codeEl.setAttribute('data-raw-code', value);
  };

  const getCodeSavedText = (codeEl) => {
    if (!codeEl) return '';
    const saved = codeEl.getAttribute('data-saved-code');
    if (saved !== null) {
      return saved;
    }
    return getCodeRawText(codeEl);
  };

  const setCodeSavedText = (codeEl, value) => {
    if (!codeEl) return;
    codeEl.setAttribute('data-saved-code', value);
  };

  const extractCodePlainText = (codeEl) => {
    if (!codeEl) return '';
    const readNode = (node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        return node.nodeValue || '';
      }
      if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
      }
      const tag = node.tagName.toLowerCase();
      if (tag === 'br') {
        return '\n';
      }
      let text = '';
      node.childNodes.forEach((child) => {
        text += readNode(child);
      });
      if (['div', 'p', 'li'].includes(tag) && text !== '' && !text.endsWith('\n')) {
        text += '\n';
      }
      return text;
    };
    return readNode(codeEl)
      .replace(/\r\n?/g, '\n')
      .replace(/\u00a0/g, ' ');
  };

  const getCodeSavedLanguage = (codeEl) => {
    if (!codeEl) return 'text';
    return (codeEl.getAttribute('data-saved-lang') || codeEl.getAttribute('data-lang') || 'text').toLowerCase();
  };

  const setCodeSavedLanguage = (codeEl, value) => {
    if (!codeEl) return;
    codeEl.setAttribute('data-saved-lang', value);
  };

  const filterProcedureCards = () => {
    if (!search || !list) return;
    const term = (search.value || '').trim().toLowerCase();
    list.querySelectorAll('[data-search]').forEach((item) => {
      const haystack = item.getAttribute('data-search') || '';
      item.classList.toggle('d-none', term !== '' && !haystack.includes(term));
    });
  };

  search?.addEventListener('input', filterProcedureCards);
  const buildCurrentEditorPreview = (title) => {
    if (!editor || !hiddenContent) return '';
    syncContent();
    return buildProcedureContentPreview({
      title,
      content: hiddenContent.value || editor.innerHTML || '',
      source: editor,
    });
  };

  const buildProcedureContentPreview = ({ title, content, source, preferredWidth = 0 }) => {
    if (!source) return '';
    const editorWidth = Math.max(960, Math.round(preferredWidth || source.getBoundingClientRect().width || 0));
    const selectedPrintSize = (pageSizeSelect?.value || document.body.getAttribute('data-proc-page-size') || '<?= $h($selectedPageSize) ?>').toLowerCase();
    const pageCssMap = {
      a4: 'A4',
      letter: 'letter',
      oficio: '216mm 330mm',
    };
    const pageCssSize = pageCssMap[selectedPrintSize] || pageCssMap.a4;
    const styleText = Array.from(document.querySelectorAll('style'))
      .map((style) => style.textContent || '')
      .join('\n');
    const linkText = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .map((link) => link.outerHTML)
      .join('\n');
    return `<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)}</title>
  ${linkText}
  <style>
    ${styleText}
    body {
      margin: 0;
      min-width: ${editorWidth + 48}px;
      padding: 24px;
      background: #eef6ff;
      color: #172033;
      overflow: auto;
    }
    .preview-shell {
      width: ${editorWidth}px;
      max-width: none;
      margin: 0;
    }
    .preview-toolbar {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-bottom: 12px;
    }
    .preview-toolbar button {
      border: 0;
      border-radius: 10px;
      padding: 9px 13px;
      background: #2563eb;
      color: #fff;
      font-weight: 700;
      cursor: pointer;
    }
    .proc-editor {
      width: ${editorWidth}px;
      max-width: none;
      box-sizing: border-box;
      min-height: 560px;
      border: 1px solid #d7deea;
      border-radius: 1.15rem;
      overflow: visible;
      box-shadow: 0 18px 36px rgba(15, 23, 42, .06);
    }
    .proc-editor .proc-image-tools,
    .proc-editor .proc-image-resize,
    .proc-editor .proc-table-tools,
    .proc-editor .proc-table-resize,
    .proc-editor .proc-table-col-resize-handle,
    .proc-editor .proc-table-row-resize-handle,
    .proc-editor .proc-code-actions,
    .proc-editor .proc-code-resize,
    .proc-editor .proc-callout-actions,
    .proc-editor .proc-callout-resize,
    .proc-editor .proc-drop-indicator,
    .proc-editor .proc-drop-indicator-vertical {
      display: none !important;
    }
    .proc-editor .is-selected,
    .proc-editor .is-editing,
    .proc-editor .is-dragging,
    .proc-editor .proc-table-cell-selected {
      outline: 0 !important;
      box-shadow: none !important;
    }
    .proc-editor .proc-side-layout[data-side-mode="compact"] {
      display: block;
      width: fit-content;
      max-width: 100%;
      clear: both;
    }
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="left"],
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="free"],
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="right"],
    .proc-editor .proc-side-layout[data-side-mode="compact"][data-align="center"] {
      float: none;
      margin: 1rem 0;
    }
    @media print {
      @page { size: ${pageCssSize}; margin: 14mm 12mm; }
      body { min-width: 0; padding: 0; background: #fff; }
      .preview-toolbar { display: none; }
      .preview-shell,
      .proc-editor { width: auto; max-width: none; border: 0; box-shadow: none; }
      .proc-page-break { display: none !important; }
    }
  </style>
</head>
<body>
  <main class="preview-shell">
    <div class="preview-toolbar">
      <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>
    <div class="proc-editor">${content}</div>
  </main>
  <script>
    (() => {
      const container = document.querySelector('.proc-editor');
      if (!container) return;
      const apply = () => {
        container.querySelectorAll('.proc-side-layout[data-side-mode="compact"]').forEach((layout) => {
          const children = Array.from(layout.children).filter((child) => !child.classList.contains('proc-side-text'));
          const height = Math.max(0, ...children.map((child) => Math.ceil(child.getBoundingClientRect().height || child.offsetHeight || 0)));
          if (height > 0) layout.style.minHeight = height + 'px';
        });
        let maxBottom = 560;
        container.querySelectorAll('.proc-side-layout[data-position="free"], .proc-callout[data-position="free"]').forEach((node) => {
          const top = parseFloat(node.style.top || '0') || 0;
          const height = Math.max(0, Math.round(node.getBoundingClientRect().height || node.offsetHeight || 0));
          maxBottom = Math.max(maxBottom, Math.ceil(top + height + 32));
        });
        container.style.minHeight = maxBottom + 'px';
      };
      apply();
      setTimeout(apply, 80);
      setTimeout(apply, 250);
    })();
  <\/script>
</body>
</html>`;
  };

  const buildReadContentPreview = (title) => {
    if (!readContent) return '';
    return buildProcedureContentPreview({
      title,
      content: readContent.innerHTML || '',
      source: readContent,
      preferredWidth: 1440,
    });
  };

  const printProcedurePreview = (title) => {
    const html = editor && hiddenContent && document.body.contains(editor)
      ? buildCurrentEditorPreview(title)
      : buildReadContentPreview(title);
    if (!html) {
      showUiMessage('No se pudo preparar el documento para imprimir.');
      return;
    }
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    let didPrint = false;
    const cleanup = () => {
      setTimeout(() => frame.remove(), 300);
    };
    frame.onload = () => {
      if (didPrint) return;
      didPrint = true;
      frame.onload = null;
      const printWindow = frame.contentWindow;
      if (!printWindow) return;
      printWindow.onafterprint = cleanup;
      printWindow.focus();
      setTimeout(() => {
        printWindow.print();
        cleanup();
      }, 250);
    };
    document.body.appendChild(frame);
    frame.srcdoc = html;
  };

  document.querySelectorAll('[data-procedure-print]').forEach((button) => {
    button.addEventListener('click', () => {
      if (pageSizeSelect && button.hasAttribute('data-page-size')) {
        button.setAttribute('data-page-size', pageSizeSelect.value || 'a4');
      }
      printProcedurePreview(button.getAttribute('data-pdf-title') || 'Procedimiento');
    });
  });

  document.querySelectorAll('[data-share-url]').forEach((button) => {
    button.addEventListener('click', async () => {
      const url = button.getAttribute('data-share-url') || '';
      if (!url) {
        showUiMessage('No se pudo generar el enlace compartido.');
        return;
      }
      try {
        await navigator.clipboard.writeText(url);
        showUiMessage('Enlace copiado. Se puede abrir sin iniciar sesión.');
      } catch (error) {
        window.prompt('Copia este enlace para compartir el procedimiento:', url);
      }
    });
  });

  pdfModalEl?.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    const url = trigger?.getAttribute?.('data-pdf-url') || '';
    const title = trigger?.getAttribute?.('data-pdf-title') || 'Vista previa PDF';
    if (pdfModalTitle) {
      pdfModalTitle.textContent = `Vista previa PDF: ${title}`;
    }
    if (pdfFrame) {
      if (editor && hiddenContent && document.body.contains(editor)) {
        pdfFrame.removeAttribute('src');
        pdfFrame.srcdoc = buildCurrentEditorPreview(title);
      } else if (readContent && document.body.contains(readContent)) {
        pdfFrame.removeAttribute('src');
        pdfFrame.srcdoc = buildReadContentPreview(title);
      } else {
        pdfFrame.removeAttribute('srcdoc');
        pdfFrame.src = url ? `${url}${url.includes('?') ? '&' : '?'}_=${Date.now()}` : 'about:blank';
      }
    }
  });
  pdfModalEl?.addEventListener('hidden.bs.modal', () => {
    if (pdfFrame) {
      pdfFrame.removeAttribute('srcdoc');
      pdfFrame.src = 'about:blank';
    }
  });
  pdfModalEl?.querySelector('.btn-close')?.addEventListener('click', () => {
    pdfModal?.hide();
  });
  if (readContent) {
    prepareStaticProcedureContent(readContent);
  }

  const messageModalEl = document.getElementById('procedureMessageModal');
  if (messageModalEl && window.bootstrap) {
    const messageModal = new bootstrap.Modal(messageModalEl);
    messageModal.show();
  }

  if (!form || !editor) {
    return;
  }

  const highlightCodeElement = (codeEl) => {
    if (!codeEl) return;
    const lang = (codeEl.getAttribute('data-lang') || 'text').toLowerCase();
    const raw = getCodeRawText(codeEl);
    const source = raw.replace(/\r\n/g, '\n');
    let html = escapeHtml(source);

    if (lang === 'html') {
      html = html.replace(/(&lt;\/?)([a-zA-Z0-9:-]+)/g, '$1<span class="tok-tag">$2</span>');
      html = html.replace(/([a-zA-Z-:]+)=(&quot;.*?&quot;)/g, '<span class="tok-attr">$1</span>=<span class="tok-string">$2</span>');
      html = html.replace(/&lt;!--[\s\S]*?--&gt;/g, '<span class="tok-comment">$&</span>');
    } else if (lang === 'sql') {
      html = html.replace(/(--.*?$)/gm, '<span class="tok-comment">$1</span>');
      html = html.replace(/\b(SELECT|FROM|WHERE|AND|OR|ORDER BY|GROUP BY|INSERT INTO|VALUES|UPDATE|SET|DELETE|INNER JOIN|LEFT JOIN|RIGHT JOIN|ON|AS|COUNT|SUM|AVG|MIN|MAX|LIKE|IN|IS NULL|NOT NULL|CREATE|ALTER|DROP|TABLE)\b/gi, '<span class="tok-keyword">$1</span>');
      html = html.replace(/'([^']*)'/g, '<span class="tok-string">\'$1\'</span>');
      html = html.replace(/\b\d+(\.\d+)?\b/g, '<span class="tok-number">$&</span>');
    } else if (lang === 'css') {
      html = html.replace(/\/\*[\s\S]*?\*\//g, '<span class="tok-comment">$&</span>');
      html = html.replace(/([.#]?[a-zA-Z_][\w-]*)(\s*\{)/g, '<span class="tok-tag">$1</span>$2');
      html = html.replace(/([a-z-]+)(\s*:)/gi, '<span class="tok-attr">$1</span>$2');
      html = html.replace(/(#(?:[0-9a-fA-F]{3,8})|\b\d+(\.\d+)?(px|rem|em|%|vh|vw)?\b)/g, '<span class="tok-number">$1</span>');
    } else if (lang === 'javascript' || lang === 'php' || lang === 'bash') {
      html = html.replace(/(\/\/.*?$|#.*?$|\/\*[\s\S]*?\*\/)/gm, '<span class="tok-comment">$1</span>');
      html = html.replace(/\b(function|const|let|var|return|if|else|for|while|switch|case|break|class|new|echo|public|private|protected|foreach|as|try|catch|finally|throw|SELECT|FROM|WHERE)\b/gi, '<span class="tok-keyword">$1</span>');
      html = html.replace(/(["'`])((?:\\.|(?!\1).)*)\1/g, '<span class="tok-string">$&</span>');
      html = html.replace(/\b\d+(\.\d+)?\b/g, '<span class="tok-number">$&</span>');
    }

    codeEl.innerHTML = html;
  };

  const highlightAllCodeBlocks = () => {
    document.querySelectorAll('.proc-code-block code[data-lang]').forEach((codeEl) => {
      if (codeEl.closest('.proc-code-block.is-editing')) {
        return;
      }
      highlightCodeElement(codeEl);
    });
  };

  const wrapImageHtml = (src, alt) => {
    return '<span class="proc-image-wrap" contenteditable="false" data-align="left" data-offset="0"><span class="proc-image-tools" contenteditable="false"><button type="button" class="btn btn-sm btn-outline-secondary proc-image-move-up" title="Subir imagen"><i class="bi bi-arrow-up"></i></button><button type="button" class="btn btn-sm btn-outline-secondary proc-image-move-down" title="Bajar imagen"><i class="bi bi-arrow-down"></i></button><button type="button" class="btn btn-sm btn-outline-secondary proc-image-drag" title="Arrastrar imagen"><i class="bi bi-grip-vertical"></i></button><button type="button" class="btn btn-sm btn-outline-danger proc-image-remove" title="Eliminar imagen"><i class="bi bi-x-lg"></i></button></span><img src="' + src + '" alt="' + alt + '"><span class="proc-image-resize" contenteditable="false" title="Cambiar tamaño"></span></span><p><br></p>';
  };

  const createTableHtml = (rows = 2, cols = 3) => {
    let head = '<tr>';
    for (let c = 0; c < cols; c += 1) {
      head += '<th contenteditable="false" data-placeholder="Nombre de columna"></th>';
    }
    head += '</tr>';
    let body = '';
    for (let r = 0; r < rows; r += 1) {
      body += '<tr>';
      for (let c = 0; c < cols; c += 1) {
        body += '<td contenteditable="false"></td>';
      }
      body += '</tr>';
    }
    return `<div class="proc-table-wrap" contenteditable="false" data-border-style="solid" data-align="left" data-offset="0"><div class="proc-table-tools" contenteditable="false"><button type="button" class="btn btn-sm btn-outline-primary proc-table-edit" title="Editar tabla">Editar</button><button type="button" class="btn btn-sm btn-success proc-table-save d-none" title="Guardar tabla">Guardar</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-bold" title="Negrita"><i class="bi bi-type-bold"></i></button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-italic" title="Cursiva"><i class="bi bi-type-italic"></i></button><select class="form-select form-select-sm proc-table-style" title="Estilo de líneas"><option value="solid">Línea sólida</option><option value="dashed">Línea discontinua</option><option value="thick">Línea marcada</option><option value="minimal">Minimalista</option></select><button type="button" class="btn btn-sm btn-outline-info proc-table-head-add" title="Añadir títulos de columnas"><i class="bi bi-type-h4"></i> Títulos</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-row-add" title="Añadir fila"><i class="bi bi-plus-lg"></i> Fila</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-row-remove" title="Quitar fila"><i class="bi bi-dash-lg"></i> Fila</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-col-add" title="Añadir columna"><i class="bi bi-plus-lg"></i> Col</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-col-remove" title="Quitar columna"><i class="bi bi-dash-lg"></i> Col</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-drag" title="Arrastrar tabla"><i class="bi bi-grip-vertical"></i></button><button type="button" class="btn btn-sm btn-outline-danger proc-table-remove" title="Eliminar tabla"><i class="bi bi-x-lg"></i></button></div><div class="proc-table-scroll" style="width: auto; min-height: 170px;"><table><thead>${head}</thead><tbody>${body}</tbody></table></div><span class="proc-table-resize" contenteditable="false" title="Cambiar ancho y alto"></span><span class="proc-table-col-resize-handle d-none" contenteditable="false" title="Arrastra para cambiar ancho de columna"></span><span class="proc-table-row-resize-handle d-none" contenteditable="false" title="Arrastra para cambiar alto de fila"></span></div><p><br></p>`;
  };

  const attachImageControls = (wrap) => {
    if (!wrap || wrap.querySelector('.proc-image-tools')) return;
    const img = wrap.querySelector('img');
    if (!img) return;
    const src = img.getAttribute('src') || '';
    const alt = img.getAttribute('alt') || 'Imagen';
    wrap.innerHTML = '<span class="proc-image-tools" contenteditable="false"><button type="button" class="btn btn-sm btn-outline-secondary proc-image-move-up" title="Subir imagen"><i class="bi bi-arrow-up"></i></button><button type="button" class="btn btn-sm btn-outline-secondary proc-image-move-down" title="Bajar imagen"><i class="bi bi-arrow-down"></i></button><button type="button" class="btn btn-sm btn-outline-secondary proc-image-drag" title="Arrastrar imagen"><i class="bi bi-grip-vertical"></i></button><button type="button" class="btn btn-sm btn-outline-danger proc-image-remove" title="Eliminar imagen"><i class="bi bi-x-lg"></i></button></span><img src="' + src + '" alt="' + escapeHtml(alt) + '"><span class="proc-image-resize" contenteditable="false" title="Cambiar tamaño"></span>';
  };

  const syncImageWrapSize = (wrap) => {
    const img = wrap?.querySelector('img');
    if (!wrap || !img) return;
    const widthAttr = parseInt(img.getAttribute('width') || '', 10);
    const width = widthAttr || Math.round(img.getBoundingClientRect().width || img.naturalWidth || 0);
    if (width > 0) {
      wrap.style.width = `${width}px`;
      wrap.style.maxWidth = 'none';
      img.style.maxWidth = 'none';
    } else {
      wrap.style.removeProperty('width');
      wrap.style.maxWidth = '100%';
      img.style.maxWidth = '100%';
    }
  };

  const syncFloatingOffsetStyle = (node) => {
    if (!node) return;
    if (node.dataset.position === 'free') {
      return;
    }
    const offset = Math.max(0, parseInt(node.dataset.offset || '0', 10) || 0);
    node.style.setProperty('--proc-inline-offset', `${offset}px`);
    const layout = node.closest('.proc-side-layout');
    if (layout?.dataset.position === 'free') {
      return;
    }
    if (!layout || !editor || node === layout) {
      return;
    }
    const align = (node.dataset.align || 'left').toLowerCase();
    layout.dataset.align = align;
    const sideRegions = Array.from(layout.querySelectorAll('.proc-side-text'));
    const hasSideText = sideRegions.some((region) => ((region.textContent || '').replace(/\u00a0/g, ' ').trim() !== ''));
    layout.dataset.sideMode = hasSideText ? 'full' : 'compact';
    if (!hasSideText) {
      layout.style.marginLeft = align === 'free' ? `${offset}px` : '';
      layout.style.marginRight = align === 'free' ? '0' : '';
      layout.style.setProperty('--proc-left-space', '0px');
      layout.style.setProperty('--proc-right-space', '0px');
      return;
    }
    layout.style.marginLeft = '';
    layout.style.marginRight = '';
    const fallbackWidth = parseFloat(node.style.width || '0') || parseFloat(node.getAttribute('width') || '0') || 320;
    const nodeWidth = Math.max(1, Math.round(node.getBoundingClientRect().width || fallbackWidth));
    const editorWidth = Math.max(1, Math.round(editor.getBoundingClientRect().width || editor.clientWidth || 0));
    const available = Math.max(0, editorWidth - nodeWidth);
    let leftSpace = Math.max(0, available / 2);
    let rightSpace = Math.max(0, available / 2);

    if (align === 'right') {
      rightSpace = Math.max(0, offset);
      leftSpace = Math.max(0, available - offset);
    } else if (align === 'center') {
      leftSpace = Math.max(0, Math.round(available / 2));
      rightSpace = Math.max(0, Math.round(available / 2));
    } else {
      leftSpace = Math.max(0, offset);
      rightSpace = Math.max(0, available - offset);
    }

    layout.style.setProperty('--proc-left-space', `${leftSpace}px`);
    layout.style.setProperty('--proc-right-space', `${rightSpace}px`);
  };

  const prepareImages = () => {
    editor.querySelectorAll('img').forEach((img, index) => {
      if (img.closest('.proc-image-wrap')) return;
      const wrap = document.createElement('span');
      wrap.className = 'proc-image-wrap';
      wrap.setAttribute('contenteditable', 'false');
      wrap.dataset.imageId = 'img-' + index + '-' + Date.now();
      img.parentNode.insertBefore(wrap, img);
      wrap.appendChild(img);
      attachImageControls(wrap);
    });
    editor.querySelectorAll('.proc-image-wrap').forEach((wrap, index) => {
      wrap.dataset.imageId = wrap.dataset.imageId || ('img-' + index + '-' + Date.now());
      wrap.setAttribute('contenteditable', 'false');
      wrap.dataset.align = wrap.dataset.align || 'left';
      wrap.dataset.offset = wrap.dataset.offset || '0';
      ensureSideLayout(wrap, 'image');
      attachImageControls(wrap);
      syncImageWrapSize(wrap);
      syncFloatingOffsetStyle(wrap);
    });
  };

  const attachCalloutControls = (callout) => {
    if (!callout || callout.querySelector('.proc-callout-actions')) return;
    const actions = document.createElement('div');
    actions.className = 'proc-callout-actions';
    actions.setAttribute('contenteditable', 'false');
    actions.innerHTML = `
      <button type="button" class="btn btn-sm btn-outline-secondary proc-callout-move-up" title="Subir bloque"><i class="bi bi-arrow-up"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary proc-callout-move-down" title="Bajar bloque"><i class="bi bi-arrow-down"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary proc-callout-drag" title="Arrastrar bloque"><i class="bi bi-grip-vertical"></i></button>
      <button type="button" class="btn btn-sm btn-outline-danger proc-callout-remove" title="Eliminar bloque"><i class="bi bi-x-lg"></i></button>
    `;
    callout.insertBefore(actions, callout.firstChild);
    if (!callout.querySelector('.proc-callout-resize')) {
      const resizeHandle = document.createElement('span');
      resizeHandle.className = 'proc-callout-resize';
      resizeHandle.setAttribute('contenteditable', 'false');
      resizeHandle.title = 'Cambiar tamaño del bloque';
      callout.appendChild(resizeHandle);
    }
  };

  const getCalloutAutoWidth = (callout) => {
    if (!callout || !editor) return 420;
    const editorRect = editor.getBoundingClientRect();
    const style = window.getComputedStyle(callout);
    const marginX = (parseFloat(style.marginLeft || '0') || 0) + (parseFloat(style.marginRight || '0') || 0);
    return Math.max(320, Math.round(editorRect.width - marginX - 2));
  };

  const setCalloutWidth = (callout, width) => {
    if (!callout) return;
    const autoWidth = getCalloutAutoWidth(callout);
    if (width >= autoWidth - 12) {
      callout.style.width = `${autoWidth}px`;
      callout.style.maxWidth = '100%';
      callout.style.minWidth = '';
      return;
    }
    const nextWidth = Math.max(320, Math.round(width));
    callout.style.width = `${nextWidth}px`;
    callout.style.maxWidth = 'none';
    callout.style.minWidth = `${nextWidth}px`;
  };

  const prepareCallouts = () => {
    editor.querySelectorAll('.proc-callout').forEach((callout, index) => {
      callout.dataset.calloutId = callout.dataset.calloutId || ('callout-' + index + '-' + Date.now());
      callout.dataset.align = callout.dataset.align || 'left';
      callout.dataset.offset = callout.dataset.offset || '0';
      attachCalloutControls(callout);
      syncFloatingOffsetStyle(callout);
      if (!callout.style.width) {
        callout.style.width = `${Math.min(getCalloutAutoWidth(callout), 540)}px`;
        callout.style.maxWidth = '100%';
      }
    });
  };

  const attachTableControls = (wrap) => {
    if (!wrap) return;
    if (wrap.querySelector('.proc-table-tools')) {
      return;
    }
    const table = wrap.querySelector('table');
    if (!table) return;
    const tools = document.createElement('div');
    tools.className = 'proc-table-tools';
    tools.setAttribute('contenteditable', 'false');
    tools.innerHTML = '<button type="button" class="btn btn-sm btn-outline-primary proc-table-edit" title="Editar tabla">Editar</button><button type="button" class="btn btn-sm btn-success proc-table-save d-none" title="Guardar tabla">Guardar</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-bold" title="Negrita"><i class="bi bi-type-bold"></i></button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-italic" title="Cursiva"><i class="bi bi-type-italic"></i></button><select class="form-select form-select-sm proc-table-style" title="Estilo de líneas"><option value="solid">Línea sólida</option><option value="dashed">Línea discontinua</option><option value="thick">Línea marcada</option><option value="minimal">Minimalista</option></select><button type="button" class="btn btn-sm btn-outline-info proc-table-head-add" title="Añadir títulos de columnas"><i class="bi bi-type-h4"></i> Títulos</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-row-add" title="Añadir fila"><i class="bi bi-plus-lg"></i> Fila</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-row-remove" title="Quitar fila"><i class="bi bi-dash-lg"></i> Fila</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-col-add" title="Añadir columna"><i class="bi bi-plus-lg"></i> Col</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-col-remove" title="Quitar columna"><i class="bi bi-dash-lg"></i> Col</button><button type="button" class="btn btn-sm btn-outline-secondary proc-table-drag" title="Arrastrar tabla"><i class="bi bi-grip-vertical"></i></button><button type="button" class="btn btn-sm btn-outline-danger proc-table-remove" title="Eliminar tabla"><i class="bi bi-x-lg"></i></button>';
    wrap.insertBefore(tools, wrap.firstChild);
    if (!wrap.querySelector('.proc-table-resize')) {
      const resizeHandle = document.createElement('span');
      resizeHandle.className = 'proc-table-resize';
      resizeHandle.setAttribute('contenteditable', 'false');
      resizeHandle.title = 'Cambiar ancho y alto';
      wrap.appendChild(resizeHandle);
    }
    if (!wrap.querySelector('.proc-table-col-resize-handle')) {
      const resizeHandle = document.createElement('span');
      resizeHandle.className = 'proc-table-col-resize-handle d-none';
      resizeHandle.setAttribute('contenteditable', 'false');
      resizeHandle.title = 'Arrastra para cambiar ancho de columna';
      wrap.appendChild(resizeHandle);
    }
    if (!wrap.querySelector('.proc-table-row-resize-handle')) {
      const resizeHandle = document.createElement('span');
      resizeHandle.className = 'proc-table-row-resize-handle d-none';
      resizeHandle.setAttribute('contenteditable', 'false');
      resizeHandle.title = 'Arrastra para cambiar alto de fila';
      wrap.appendChild(resizeHandle);
    }
  };

  const ensureTableToolState = (wrap) => {
    if (!wrap) return;
    wrap.dataset.borderStyle = wrap.dataset.borderStyle || 'solid';
    const styleSelect = wrap.querySelector('.proc-table-style');
    if (styleSelect) {
      styleSelect.value = wrap.dataset.borderStyle;
    }
    const activeCell = (() => {
      if (selectedTableCell && wrap.contains(selectedTableCell)) {
        return selectedTableCell;
      }
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0) {
        return null;
      }
      const node = selection.anchorNode?.nodeType === Node.ELEMENT_NODE
        ? selection.anchorNode
        : selection.anchorNode?.parentElement;
      const cell = node?.closest?.('td, th');
      return cell && wrap.contains(cell) ? cell : null;
    })();
  };

  const updateTableResponsiveState = (wrap) => {
    if (!wrap) return;
    const width = Math.round(wrap.getBoundingClientRect().width || wrap.clientWidth || 0);
    wrap.classList.toggle('is-compact', width > 0 && width < 760);
  };

  const getSelectedRangeInsideCell = (cell) => {
    if (!cell) return null;
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return null;
    const range = selection.getRangeAt(0);
    if (!cell.contains(range.commonAncestorContainer)) return null;
    return range;
  };

  const wrapSelectionWithNode = (range, node) => {
    if (!range || range.collapsed) return false;
    try {
      range.surroundContents(node);
      return true;
    } catch (error) {
      const fragment = range.extractContents();
      node.appendChild(fragment);
      range.insertNode(node);
      return true;
    }
  };

  const getTargetTableCell = (wrap) => {
    if (!wrap) return null;
    const selection = window.getSelection();
    if (selection && selection.rangeCount > 0) {
      const node = selection.anchorNode?.nodeType === Node.ELEMENT_NODE
        ? selection.anchorNode
        : selection.anchorNode?.parentElement;
      const cellFromSelection = node?.closest?.('td, th');
      if (cellFromSelection && wrap.contains(cellFromSelection)) {
        selectedTableCell = cellFromSelection;
        return cellFromSelection;
      }
    }
    return selectedTableCell && wrap.contains(selectedTableCell)
      ? selectedTableCell
      : wrap.querySelector('thead th, tbody td');
  };

  const getActiveEditingTableCell = () => {
    const selection = window.getSelection();
    const node = selection && selection.rangeCount > 0
      ? (selection.anchorNode?.nodeType === Node.ELEMENT_NODE
          ? selection.anchorNode
          : selection.anchorNode?.parentElement)
      : null;
    const selectedCell = node?.closest?.('.proc-table-wrap.is-editing td, .proc-table-wrap.is-editing th');
    if (selectedCell) {
      return selectedCell;
    }
    return selectedTableCell?.closest?.('.proc-table-wrap.is-editing')
      ? selectedTableCell
      : null;
  };

  const insertImageIntoTableCell = (cell, src, alt) => {
    if (!cell) return false;
    const safeSrc = escapeHtml(src);
    const safeAlt = escapeHtml(alt || 'Imagen');
    const imageHtml = `<img src="${safeSrc}" alt="${safeAlt}">`;
    let inserted = false;
    const selection = window.getSelection();
    if (selection && selection.rangeCount > 0) {
      const range = selection.getRangeAt(0);
      if (cell.contains(range.commonAncestorContainer)) {
        const holder = document.createElement('div');
        holder.innerHTML = imageHtml;
        const imgNode = holder.firstChild;
        if (imgNode) {
          range.deleteContents();
          range.insertNode(imgNode);
          range.setStartAfter(imgNode);
          range.collapse(true);
          selection.removeAllRanges();
          selection.addRange(range);
          inserted = true;
        }
      }
    }
    if (!inserted) {
      cell.insertAdjacentHTML('beforeend', imageHtml);
    }
    selectedTableCell = cell;
    focusTableCell(cell);
    syncContent();
    return true;
  };

  const adjustSelectedColumnWidth = (wrap, delta) => {
    const cell = getTargetTableCell(wrap);
    if (!cell) return;
    const table = wrap.querySelector('table');
    if (!table) return;
    const columnIndex = cell.cellIndex;
    const rows = Array.from(table.rows);
    const referenceCell = rows.find((row) => row.cells[columnIndex])?.cells[columnIndex];
    if (!referenceCell) return;
    const currentWidth = parseInt(referenceCell.style.width || '', 10) || Math.round(referenceCell.getBoundingClientRect().width);
    const nextWidth = Math.max(90, currentWidth + delta);
    rows.forEach((row) => {
      if (row.cells[columnIndex]) {
        row.cells[columnIndex].style.width = `${nextWidth}px`;
        row.cells[columnIndex].style.minWidth = `${nextWidth}px`;
      }
    });
    selectedTableCell = cell;
    ensureTableToolState(wrap);
    syncContent();
  };

  const adjustSelectedRowHeight = (wrap, delta) => {
    const cell = getTargetTableCell(wrap);
    if (!cell) return;
    const row = cell.parentElement;
    if (!row) return;
    const currentHeight = parseInt(row.style.height || '', 10) || Math.round(row.getBoundingClientRect().height);
    const nextHeight = Math.max(40, currentHeight + delta);
    Array.from(row.cells).forEach((rowCell) => {
      rowCell.style.height = `${nextHeight}px`;
      rowCell.style.minHeight = `${nextHeight}px`;
    });
    selectedTableCell = cell;
    ensureTableToolState(wrap);
    syncContent();
  };

  const updateSelectedTableCellVisual = () => {
    editor.querySelectorAll('.proc-table-cell-selected').forEach((cell) => {
      cell.classList.remove('proc-table-cell-selected');
    });
    if (selectedTableCell && editor.contains(selectedTableCell)) {
      selectedTableCell.classList.add('proc-table-cell-selected');
    }
  };

  const updateTableResizeHandles = (wrap) => {
    if (!wrap) return;
    const colHandle = wrap.querySelector('.proc-table-col-resize-handle');
    const rowHandle = wrap.querySelector('.proc-table-row-resize-handle');
    const cell = selectedTableCell && wrap.contains(selectedTableCell) ? selectedTableCell : null;
    if (!colHandle || !rowHandle || !cell || !wrap.classList.contains('is-editing')) {
      colHandle?.classList.add('d-none');
      rowHandle?.classList.add('d-none');
      return;
    }
    const wrapRect = wrap.getBoundingClientRect();
    const cellRect = cell.getBoundingClientRect();
    colHandle.style.left = `${cellRect.right - wrapRect.left - 5}px`;
    colHandle.style.top = `${cellRect.top - wrapRect.top + Math.max(8, (cellRect.height - 38) / 2)}px`;
    rowHandle.style.left = `${cellRect.left - wrapRect.left + Math.max(8, (cellRect.width - 38) / 2)}px`;
    rowHandle.style.top = `${cellRect.bottom - wrapRect.top - 5}px`;
    colHandle.classList.remove('d-none');
    rowHandle.classList.remove('d-none');
  };

  const applyTableCellTextStyle = (wrap, styleName, styleValue) => {
    const cell = getTargetTableCell(wrap);
    if (!cell) return;
    const range = getSelectedRangeInsideCell(cell);
    if (!range || range.collapsed || range.toString().trim() === '') {
      cell.style[styleName] = styleValue;
      focusSelectedTableCell(wrap);
      ensureTableToolState(wrap);
      syncContent();
      return;
    }
    const span = document.createElement('span');
    span.style[styleName] = styleValue;
    wrapSelectionWithNode(range, span);
    selectedTableCell = cell;
    ensureTableToolState(wrap);
    syncContent();
  };

  const applyTableToolbarControl = (control) => {
    const wrap = control?.closest('.proc-table-wrap');
    if (!wrap) return false;

    selectedTableWrap = wrap;
    selectedTableWrap.classList.add('is-selected');

    if (control.matches('.proc-table-style')) {
      wrap.dataset.borderStyle = control.value || 'solid';
      ensureTableToolState(wrap);
      syncContent();
      return true;
    }

    return false;
  };

  const toggleTableCellTextStyle = (wrap, styleName, activeValue, inactiveValue) => {
    const cell = getTargetTableCell(wrap);
    if (!cell) return;
    const range = getSelectedRangeInsideCell(cell);
    if (!range || range.collapsed || range.toString().trim() === '') {
      const current = window.getComputedStyle(cell)[styleName];
      cell.style[styleName] = current === activeValue ? inactiveValue : activeValue;
      focusSelectedTableCell(wrap);
      ensureTableToolState(wrap);
      syncContent();
      return;
    }
    const span = document.createElement('span');
    span.style[styleName] = activeValue;
    wrapSelectionWithNode(range, span);
    selectedTableCell = cell;
    ensureTableToolState(wrap);
    syncContent();
  };

  const ensureTableHead = (table) => {
    if (!table) return null;
    let thead = table.querySelector('thead');
    const firstBodyRow = table.tBodies[0]?.rows[0] || null;
    if (!thead) {
      thead = table.createTHead();
    }
    if (thead.rows.length === 0) {
      const headerRow = thead.insertRow();
      const colCount = firstBodyRow?.cells.length || table.rows[0]?.cells.length || 1;
      for (let i = 0; i < colCount; i += 1) {
        const th = document.createElement('th');
        th.setAttribute('contenteditable', 'false');
        th.setAttribute('data-placeholder', 'Nombre de columna');
        th.textContent = '';
        headerRow.appendChild(th);
      }
    }
    return thead;
  };

  const applyTableCellPlaceholders = (table) => {
    if (!table) return;
    table.querySelectorAll('thead th').forEach((cell) => {
      cell.setAttribute('data-placeholder', 'Nombre de columna');
    });
    table.querySelectorAll('tbody td').forEach((cell) => {
      cell.removeAttribute('data-placeholder');
    });
  };

  const normalizeTableStructure = (table) => {
    if (!table) return;
    const thead = ensureTableHead(table);
    const body = table.tBodies[0] || table.createTBody();
    const colCount = thead?.rows[0]?.cells.length || body.rows[0]?.cells.length || 1;
    if (body.rows.length === 0) {
      const row = body.insertRow();
      for (let i = 0; i < colCount; i += 1) {
        const cell = row.insertCell();
        cell.setAttribute('contenteditable', 'false');
        cell.textContent = '';
      }
    }
    Array.from(body.rows).forEach((row) => {
      while (row.cells.length < colCount) {
        const cell = row.insertCell();
        cell.setAttribute('contenteditable', 'false');
        cell.textContent = '';
      }
    });
    applyTableCellPlaceholders(table);
  };

  const appendTableBodyRow = (table, editable = true) => {
    if (!table) return null;
    const body = table.tBodies[0] || table.createTBody();
    const cols = table.tHead?.rows[0]?.cells.length || body.rows[0]?.cells.length || 1;
    const row = body.insertRow();
    for (let i = 0; i < cols; i += 1) {
      const cell = row.insertCell();
      cell.setAttribute('contenteditable', editable ? 'true' : 'false');
      cell.textContent = '';
    }
    return row;
  };

  const appendTableColumn = (table, editable = true) => {
    if (!table) return;
    ensureTableHead(table);
    Array.from(table.rows).forEach((row) => {
      const tagName = row.parentElement.tagName === 'THEAD' ? 'th' : 'td';
      const cell = document.createElement(tagName);
      cell.setAttribute('contenteditable', editable ? 'true' : 'false');
      if (tagName === 'th') {
        cell.setAttribute('data-placeholder', 'Nombre de columna');
      }
      cell.textContent = '';
      row.appendChild(cell);
    });
    applyTableCellPlaceholders(table);
  };

  const getTableCellPosition = (cell) => {
    const row = cell?.parentElement;
    const table = row?.closest('table');
    if (!row || !table) return null;
    return {
      table,
      rowIndex: Array.from(table.rows).indexOf(row),
      colIndex: cell.cellIndex
    };
  };

  const focusTableCell = (cell) => {
    if (!cell) return null;
    const wrap = cell.closest('.proc-table-wrap');
    if (!wrap) return null;
    selectedTableWrap = wrap;
    selectedTableWrap.classList.add('is-selected');
    selectedTableCell = cell;
    cell.focus();
    const range = document.createRange();
    range.selectNodeContents(cell);
    range.collapse(false);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    updateSelectedTableCellVisual();
    ensureTableToolState(wrap);
    updateTableResizeHandles(wrap);
    saveEditorSelection();
    return cell;
  };

  const focusTableCellByDelta = (cell, rowDelta, colDelta, options = {}) => {
    const position = getTableCellPosition(cell);
    if (!position) return null;
    const { table } = position;
    const rows = Array.from(table.rows);
    let nextRowIndex = Math.max(0, position.rowIndex + rowDelta);
    let nextColIndex = Math.max(0, position.colIndex + colDelta);

    if (nextColIndex >= rows[Math.min(nextRowIndex, rows.length - 1)]?.cells.length) {
      nextColIndex = 0;
      nextRowIndex += 1;
    } else if (nextColIndex < 0) {
      nextRowIndex = Math.max(0, nextRowIndex - 1);
      nextColIndex = Math.max(0, (rows[nextRowIndex]?.cells.length || 1) - 1);
    }

    while (nextRowIndex >= table.rows.length && options.extendBody) {
      appendTableBodyRow(table, true);
    }

    const finalRows = Array.from(table.rows);
    nextRowIndex = Math.max(0, Math.min(nextRowIndex, finalRows.length - 1));
    const targetRow = finalRows[nextRowIndex];
    if (!targetRow) return null;
    nextColIndex = Math.max(0, Math.min(nextColIndex, targetRow.cells.length - 1));
    return focusTableCell(targetRow.cells[nextColIndex]);
  };

  const pasteTableGrid = (cell, rawText) => {
    if (!cell || !rawText) return false;
    const position = getTableCellPosition(cell);
    if (!position) return false;
    const rowsData = rawText
      .replace(/\r\n/g, '\n')
      .replace(/\r/g, '\n')
      .split('\n')
      .filter((line, index, arr) => !(index === arr.length - 1 && line === ''))
      .map((line) => line.split('\t'));
    if (rowsData.length === 0) return false;

    const { table, rowIndex, colIndex } = position;
    const requiredCols = colIndex + Math.max(...rowsData.map((row) => row.length));
    const currentCols = table.tHead?.rows[0]?.cells.length || table.rows[0]?.cells.length || 0;
    for (let i = currentCols; i < requiredCols; i += 1) {
      appendTableColumn(table, true);
    }

    const neededRows = rowIndex + rowsData.length;
    while (table.rows.length < neededRows) {
      appendTableBodyRow(table, true);
    }

    rowsData.forEach((rowValues, rowOffset) => {
      const targetRow = table.rows[rowIndex + rowOffset];
      rowValues.forEach((value, colOffset) => {
        const targetCell = targetRow?.cells[colIndex + colOffset];
        if (!targetCell) return;
        targetCell.innerHTML = '';
        targetCell.textContent = value;
      });
    });

    applyTableCellPlaceholders(table);
    const wrap = table.closest('.proc-table-wrap');
    if (wrap) {
      ensureTableToolState(wrap);
      updateTableResizeHandles(wrap);
    }
    const lastRow = table.rows[Math.min(table.rows.length - 1, rowIndex + rowsData.length - 1)];
    const lastCell = lastRow?.cells[Math.min(lastRow.cells.length - 1, colIndex + rowsData[rowsData.length - 1].length - 1)];
    if (lastCell) {
      focusTableCell(lastCell);
    }
    syncContent();
    return true;
  };

  const setTableEditing = (wrap, editing) => {
    if (!wrap) return;
    const editBtn = wrap.querySelector('.proc-table-edit');
    const saveBtn = wrap.querySelector('.proc-table-save');
    const table = wrap.querySelector('table');
    normalizeTableStructure(table);
    wrap.classList.toggle('is-editing', editing);
    wrap.querySelectorAll('td, th').forEach((cell) => {
      cell.setAttribute('contenteditable', editing ? 'true' : 'false');
    });
    editBtn?.classList.toggle('d-none', editing);
    saveBtn?.classList.toggle('d-none', !editing);
    updateTableResponsiveState(wrap);
  };

  const prepareTables = () => {
    editor.querySelectorAll('table').forEach((table, index) => {
      if (table.closest('.proc-table-wrap')) return;
      const wrap = document.createElement('div');
      wrap.className = 'proc-table-wrap';
      wrap.setAttribute('contenteditable', 'false');
      wrap.dataset.tableId = 'tbl-' + index + '-' + Date.now();
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
      attachTableControls(wrap);
    });
    editor.querySelectorAll('.proc-table-wrap').forEach((wrap, index) => {
      wrap.dataset.tableId = wrap.dataset.tableId || ('tbl-' + index + '-' + Date.now());
      wrap.setAttribute('contenteditable', 'false');
      wrap.dataset.align = wrap.dataset.align || 'left';
      wrap.dataset.offset = wrap.dataset.offset || '0';
      ensureSideLayout(wrap, 'table');
      attachTableControls(wrap);
      normalizeTableStructure(wrap.querySelector('table'));
      ensureTableToolState(wrap);
      updateTableResponsiveState(wrap);
      updateTableResizeHandles(wrap);
      syncFloatingOffsetStyle(wrap);
      if (!wrap.classList.contains('is-editing')) {
        setTableEditing(wrap, false);
      }
    });
  };

  const attachCodeBlockControls = (block) => {
    if (!block || block.querySelector('.proc-code-actions')) return;
    const actions = document.createElement('div');
    actions.className = 'proc-code-actions';
    actions.setAttribute('contenteditable', 'false');
    actions.innerHTML = `
      <button type="button" class="btn btn-sm btn-outline-secondary proc-code-move-up" title="Subir bloque"><i class="bi bi-arrow-up"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary proc-code-move-down" title="Bajar bloque"><i class="bi bi-arrow-down"></i></button>
      <button type="button" class="btn btn-sm btn-outline-primary proc-code-edit" title="Editar código">Editar</button>
      <button type="button" class="btn btn-sm btn-success proc-code-save d-none" title="Guardar cambios">Guardar</button>
      <button type="button" class="btn btn-sm btn-outline-secondary proc-code-drag" title="Arrastrar bloque"><i class="bi bi-grip-vertical"></i></button>
      <button type="button" class="btn btn-sm btn-outline-danger proc-code-remove" title="Eliminar bloque"><i class="bi bi-x-lg"></i></button>
    `;
    block.insertBefore(actions, block.firstChild);
    if (!block.querySelector('.proc-code-resize')) {
      const resizeHandle = document.createElement('span');
      resizeHandle.className = 'proc-code-resize';
      resizeHandle.setAttribute('contenteditable', 'false');
      resizeHandle.title = 'Cambiar tamaño del bloque';
      block.appendChild(resizeHandle);
    }
  };

  const setCodeBlockEditing = (block, editing) => {
    if (!block) return;
    const codeEl = block.querySelector('code[data-lang]');
    const editBtn = block.querySelector('.proc-code-edit');
    const saveBtn = block.querySelector('.proc-code-save');
    if (!codeEl) return;

    if (editing) {
      block.classList.add('is-editing');
      codeEl.setAttribute('contenteditable', 'true');
      codeEl.setAttribute('spellcheck', 'false');
      codeEl.setAttribute('data-lang', getCodeSavedLanguage(codeEl));
      codeEl.textContent = getCodeSavedText(codeEl);
      setCodeRawText(codeEl, getCodeSavedText(codeEl));
      editBtn?.classList.add('d-none');
      saveBtn?.classList.remove('d-none');
      if (codeLanguage) {
        codeLanguage.value = getCodeSavedLanguage(codeEl);
      }
      codeEl.focus();
      const range = document.createRange();
      range.selectNodeContents(codeEl);
      range.collapse(false);
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
      return;
    }

    const finalText = extractCodePlainText(codeEl) || getCodeRawText(codeEl);
    const finalLang = (codeEl.getAttribute('data-lang') || getCodeSavedLanguage(codeEl)).toLowerCase();
    setCodeRawText(codeEl, finalText);
    setCodeSavedText(codeEl, finalText);
    setCodeSavedLanguage(codeEl, finalLang);
    block.classList.remove('is-editing');
    codeEl.setAttribute('contenteditable', 'false');
    codeEl.removeAttribute('spellcheck');
    codeEl.setAttribute('data-lang', finalLang);
    editBtn?.classList.remove('d-none');
    saveBtn?.classList.add('d-none');
    highlightCodeElement(codeEl);
  };

  const getCodeBlockAutoWidth = (block) => {
    if (!block || !editor) return 420;
    const editorRect = editor.getBoundingClientRect();
    const blockStyle = window.getComputedStyle(block);
    const marginX = (parseFloat(blockStyle.marginLeft || '0') || 0) + (parseFloat(blockStyle.marginRight || '0') || 0);
    return Math.max(320, Math.round(editorRect.width - marginX - 2));
  };

  const setCodeBlockWidth = (block, width) => {
    if (!block) return;
    const autoWidth = getCodeBlockAutoWidth(block);
    if (width >= autoWidth - 12) {
      block.style.width = `${autoWidth}px`;
      block.style.maxWidth = '100%';
      block.style.minWidth = '';
      return;
    }
    const nextWidth = Math.max(320, Math.round(width));
    block.style.width = `${nextWidth}px`;
    block.style.maxWidth = 'none';
    block.style.minWidth = `${nextWidth}px`;
  };

  const prepareCodeBlocks = () => {
    editor.querySelectorAll('pre.proc-code-block').forEach((block, index) => {
      block.dataset.blockId = block.dataset.blockId || ('code-' + index + '-' + Date.now());
      block.dataset.align = block.dataset.align || 'left';
      block.dataset.offset = block.dataset.offset || '0';
      ensureSideLayout(block, 'code');
      attachCodeBlockControls(block);
      if (!block.style.width) {
        block.style.width = `${Math.min(getCodeBlockAutoWidth(block), 640)}px`;
        block.style.maxWidth = '100%';
      }
      syncFloatingOffsetStyle(block);
      const codeEl = block.querySelector('code[data-lang]');
      if (codeEl) {
        if (!codeEl.hasAttribute('data-raw-code')) {
          setCodeRawText(codeEl, extractCodePlainText(codeEl));
        }
        if (!codeEl.hasAttribute('data-saved-code')) {
          setCodeSavedText(codeEl, extractCodePlainText(codeEl));
        }
        if (!codeEl.hasAttribute('data-saved-lang')) {
          setCodeSavedLanguage(codeEl, (codeEl.getAttribute('data-lang') || 'text').toLowerCase());
        }
        if (!block.classList.contains('is-editing')) {
          setCodeBlockEditing(block, false);
        }
      }
    });
  };

  const clearSelectedCodeBlock = () => {
    if (selectedCodeBlock) {
      selectedCodeBlock.classList.remove('is-selected');
      selectedCodeBlock = null;
    }
  };

  const clearSelectedImageWrap = () => {
    if (selectedImageWrap) {
      selectedImageWrap.classList.remove('is-selected');
      selectedImageWrap = null;
    }
  };

  const clearSelectedTableWrap = () => {
    if (selectedTableWrap) {
      selectedTableWrap.classList.remove('is-selected');
      selectedTableWrap.querySelector('.proc-table-col-resize-handle')?.classList.add('d-none');
      selectedTableWrap.querySelector('.proc-table-row-resize-handle')?.classList.add('d-none');
      selectedTableWrap = null;
    }
    selectedTableCell = null;
    updateSelectedTableCellVisual();
  };

  const clearSelectedCallout = () => {
    if (selectedCallout) {
      selectedCallout.classList.remove('is-selected');
      selectedCallout = null;
    }
  };

  const isBlankEditorParagraph = (node) => {
    if (!node || node.nodeType !== Node.ELEMENT_NODE || node.tagName !== 'P') {
      return false;
    }
    return node.innerHTML.replace(/<br\s*\/?>/gi, '').trim() === '';
  };

  const isIgnorableDropTarget = (node) => {
    return isBlankEditorParagraph(node);
  };

  const snapToGrid = (value, size = PROC_GRID_SIZE) => {
    return Math.round(value / size) * size;
  };

  const clamp = (value, min, max) => {
    return Math.max(min, Math.min(max, value));
  };

  const getEditorContentMetrics = () => {
    const rect = editor.getBoundingClientRect();
    const style = window.getComputedStyle(editor);
    const paddingLeft = parseFloat(style.paddingLeft || '0') || 0;
    const paddingRight = parseFloat(style.paddingRight || '0') || 0;
    const paddingTop = parseFloat(style.paddingTop || '0') || 0;
    const paddingBottom = parseFloat(style.paddingBottom || '0') || 0;
    const left = Math.round(rect.left + paddingLeft);
    const top = Math.round(rect.top + paddingTop);
    const right = Math.round(rect.right - paddingRight);
    const bottom = Math.round(rect.bottom - paddingBottom);
    return {
      rect,
      left,
      top,
      right,
      bottom,
      width: Math.max(0, right - left),
      height: Math.max(0, bottom - top),
      paddingLeft,
      paddingRight,
      paddingTop,
      paddingBottom,
    };
  };

  const ensureDragPlaceholder = () => {
    if (!dragPlaceholder) {
      dragPlaceholder = document.createElement('div');
      dragPlaceholder.className = 'proc-drop-placeholder';
      dragPlaceholder.setAttribute('contenteditable', 'false');
    }
    return dragPlaceholder;
  };

  const syncDragPlaceholderMetrics = (placement = null) => {
    if (!dragPlaceholder || !draggingSortNode) {
      return;
    }
    const visualRect = getSortableVisualRect(draggingSortNode);
    const nodeWidth = Math.max(120, snapToGrid(visualRect.width || PROC_GRID_SIZE));
    const nodeHeight = Math.max(PROC_GRID_SIZE, snapToGrid(visualRect.height || PROC_GRID_SIZE));
    const layout = draggingSortCompanion?.closest('.proc-side-layout');
    const compactMode = !!layout && layout.dataset.sideMode === 'compact';

    dragPlaceholder.style.height = `${nodeHeight}px`;
    dragPlaceholder.style.maxWidth = '100%';

    if (compactMode && placement) {
      dragPlaceholder.style.cssFloat = 'left';
      dragPlaceholder.style.clear = 'none';
      dragPlaceholder.style.display = 'block';
      dragPlaceholder.style.width = `${nodeWidth}px`;
      dragPlaceholder.style.marginTop = '1rem';
      dragPlaceholder.style.marginRight = '0';
      dragPlaceholder.style.marginBottom = '1rem';
      dragPlaceholder.style.marginLeft = `${Math.max(0, placement.left || 0)}px`;
      return;
    }

    dragPlaceholder.style.cssFloat = '';
    dragPlaceholder.style.clear = 'both';
    dragPlaceholder.style.display = 'block';
    dragPlaceholder.style.width = `${nodeWidth}px`;
    dragPlaceholder.style.margin = '.35rem 0';
    dragPlaceholder.style.marginLeft = '';
  };

  const getDragSideFromPointer = (rect, pointerX, pointerY) => {
    const horizontalRatio = rect.width > 0 ? (pointerX - rect.left) / rect.width : 0.5;
    const verticalRatio = rect.height > 0 ? (pointerY - rect.top) / rect.height : 0.5;
    if (verticalRatio < 0.28) return 'top';
    if (verticalRatio > 0.72) return 'bottom';
    return horizontalRatio < 0.5 ? 'left' : 'right';
  };

  const getPointerDistanceToRect = (rect, pointerX, pointerY) => {
    const dx = pointerX < rect.left ? rect.left - pointerX : (pointerX > rect.right ? pointerX - rect.right : 0);
    const dy = pointerY < rect.top ? rect.top - pointerY : (pointerY > rect.bottom ? pointerY - rect.bottom : 0);
    return Math.sqrt((dx * dx) + (dy * dy));
  };

  const getSortableVisualNode = (node) => {
    if (!node || node.nodeType !== Node.ELEMENT_NODE) {
      return null;
    }
    if (node.classList.contains('proc-side-layout')) {
      return node.querySelector('.proc-image-wrap, .proc-table-wrap, pre.proc-code-block, .proc-callout') || node;
    }
    return node;
  };

  const getSortableVisualRect = (node) => {
    const visualNode = getSortableVisualNode(node) || node;
    return visualNode.getBoundingClientRect();
  };

  const startSortableDrag = (node, companion, type, event) => {
    if (!node || !editor) return;
    const marker = ensureDragPlaceholder();
    const rect = node.getBoundingClientRect();
    const refRect = (companion || node).getBoundingClientRect();
    const dragRect = refRect.width > 0 && refRect.height > 0 ? refRect : rect;
    const bounds = getEditorContentMetrics();
    draggingSortNode = node;
    draggingSortCompanion = companion || node;
    draggingSortType = type;
    draggingSortTail = getTrailingEditableParagraph(node);
    draggingSortOriginalParent = node.parentNode;
    draggingSortOriginalNextSibling = node.nextSibling;
    draggingSortOriginalCssText = node.getAttribute('style') || '';
    dragNodeGrabOffsetX = Math.max(0, Math.round(event.clientX - dragRect.left));
    dragNodeGrabOffsetY = Math.max(0, Math.round(event.clientY - dragRect.top));
    dragGrabOffsetX = Math.max(0, Math.round(event.clientX - refRect.left));
    dragGrabOffsetY = Math.max(0, Math.round(event.clientY - refRect.top));
    dragPointerX = event.clientX;
    dragPointerY = event.clientY;
    dragLastPlaceholderTarget = null;
    dragLastPlaceholderAfter = null;
    if (draggingSortTail && draggingSortTail.parentNode === editor) {
      draggingSortTail.remove();
    }
    if (node.parentNode === editor) {
      editor.insertBefore(marker, node);
    }
    node.classList.add('is-dragging', 'is-drag-ghost');
    if (draggingSortCompanion && draggingSortCompanion !== node) {
      draggingSortCompanion.classList.add('is-dragging');
    }
    node.dataset.position = 'free';
    node.style.position = 'fixed';
    node.style.left = `${Math.round(dragRect.left)}px`;
    node.style.top = `${Math.round(dragRect.top)}px`;
    node.style.width = `${Math.round(dragRect.width)}px`;
    node.style.maxWidth = `${Math.round(dragRect.width)}px`;
    node.style.margin = '0';
    node.style.zIndex = '1400';
    node.style.pointerEvents = 'none';
    document.body.appendChild(node);
    syncDragPlaceholderMetrics(resolveSortableHorizontalPlacementFromVisualRect(draggingSortCompanion || node, refRect));
    updateDragIndicators();
  };

  const getSortableDropCandidates = () => {
    return Array.from(editor.children)
      .filter((child) =>
        child !== draggingSortNode &&
        child !== draggingSortTail &&
        child !== dragIndicator &&
        child !== dragPlaceholder &&
        !isIgnorableDropTarget(child)
      )
      .map((child) => ({ child, rect: getSortableVisualRect(child) }))
      .sort((a, b) => {
        const topDiff = a.rect.top - b.rect.top;
        if (Math.abs(topDiff) > 10) {
          return topDiff;
        }
        return a.rect.left - b.rect.left;
      });
  };

  const updateDragIndicators = () => {
    if (!editor) return;
    const bounds = getEditorContentMetrics();
    if (!dragIndicator) {
      dragIndicator = document.createElement('div');
      dragIndicator.className = 'proc-drop-indicator';
    }
    let horizontalTop = null;
    if (dragPointerY !== null) {
      horizontalTop = clamp(
        Math.round(dragPointerY - bounds.rect.top),
        Math.round(bounds.top - bounds.rect.top),
        Math.round(bounds.bottom - bounds.rect.top)
      );
    } else if (dragPlaceholder && dragPlaceholder.parentNode === editor) {
      const placeholderRect = dragPlaceholder.getBoundingClientRect();
      horizontalTop = Math.round(placeholderRect.top - bounds.rect.top + (placeholderRect.height / 2));
    }
    if (horizontalTop !== null) {
      dragIndicator.style.left = `${Math.round(bounds.left - bounds.rect.left)}px`;
      dragIndicator.style.width = `${Math.round(bounds.width)}px`;
      dragIndicator.style.top = `${horizontalTop}px`;
      if (dragIndicator.parentNode !== editor) {
        editor.appendChild(dragIndicator);
      }
    }
    if (dragVerticalIndicator) {
      dragVerticalIndicator.style.top = `${Math.round(bounds.top - bounds.rect.top)}px`;
      dragVerticalIndicator.style.height = `${Math.round(bounds.height)}px`;
    }
  };

  const groupDropCandidatesByRow = (entries) => {
    const rows = [];
    entries.forEach((entry) => {
      const existingRow = rows.find((row) => Math.abs(row.top - entry.rect.top) <= PROC_ROW_THRESHOLD);
      if (existingRow) {
        existingRow.items.push(entry);
        existingRow.top = Math.min(existingRow.top, entry.rect.top);
        existingRow.bottom = Math.max(existingRow.bottom, entry.rect.bottom);
      } else {
        rows.push({
          top: entry.rect.top,
          bottom: entry.rect.bottom,
          items: [entry],
        });
      }
    });
    rows.forEach((row) => {
      row.items.sort((a, b) => a.rect.left - b.rect.left);
      row.top = Math.min(...row.items.map((item) => item.rect.top));
      row.bottom = Math.max(...row.items.map((item) => item.rect.bottom));
    });
    rows.sort((a, b) => a.top - b.top);
    return rows;
  };

  const resolveDropPlacement = (pointerX, pointerY) => {
    const entries = getSortableDropCandidates();
    if (entries.length === 0) {
      return { target: null, after: true };
    }
    const first = entries[0];
    const last = entries[entries.length - 1];
    if (pointerY < first.rect.top - PROC_ROW_THRESHOLD) {
      return { target: first.child, after: false };
    }
    if (pointerY > last.rect.bottom + PROC_ROW_THRESHOLD) {
      return { target: last.child, after: true };
    }
    const containing = entries.find((entry) =>
      pointerX >= entry.rect.left - PROC_ROW_THRESHOLD &&
      pointerX <= entry.rect.right + PROC_ROW_THRESHOLD &&
      pointerY >= entry.rect.top - PROC_ROW_THRESHOLD &&
      pointerY <= entry.rect.bottom + PROC_ROW_THRESHOLD
    );
    if (containing) {
      const side = getDragSideFromPointer(containing.rect, pointerX, pointerY);
      return { target: containing.child, after: side === 'right' || side === 'bottom', side };
    }
    const rows = groupDropCandidatesByRow(entries);
    const row = rows.find((entry) => pointerY >= entry.top - PROC_ROW_THRESHOLD && pointerY <= entry.bottom + PROC_ROW_THRESHOLD)
      || rows.reduce((best, current) => {
        const bestDistance = Math.abs(pointerY - ((best.top + best.bottom) / 2));
        const currentDistance = Math.abs(pointerY - ((current.top + current.bottom) / 2));
        return currentDistance < bestDistance ? current : best;
      });
    if (!row) {
      return { target: rows[rows.length - 1].items[rows[rows.length - 1].items.length - 1].child, after: true };
    }
    const nearest = row.items.reduce((best, current) => {
      const bestDistance = getPointerDistanceToRect(best.rect, pointerX, pointerY);
      const currentDistance = getPointerDistanceToRect(current.rect, pointerX, pointerY);
      return currentDistance < bestDistance ? current : best;
    });
    if (nearest) {
      const side = getDragSideFromPointer(nearest.rect, pointerX, pointerY);
      return { target: nearest.child, after: side === 'right' || side === 'bottom', side };
    }
    for (const item of row.items) {
      const centerX = item.rect.left + (item.rect.width / 2);
      if (pointerX < centerX) {
        return { target: item.child, after: false };
      }
    }
    return { target: row.items[row.items.length - 1].child, after: true };
  };

  const placeDraggingNode = (target, after = false) => {
    if (!editor) {
      return;
    }
    const marker = ensureDragPlaceholder();
    if (!marker) {
      return;
    }
    if (dragLastPlaceholderTarget === target && dragLastPlaceholderAfter === after) {
      updateDragIndicators();
      return;
    }
    dragLastPlaceholderTarget = target;
    dragLastPlaceholderAfter = after;
    syncDragPlaceholderMetrics(resolveSortableHorizontalPlacement(draggingSortCompanion || draggingSortNode, dragPointerX ?? 0));
    if (!target || target.parentNode !== editor) {
      editor.appendChild(marker);
      updateDragIndicators();
      return;
    }
    if (after) {
      const anchor = target.nextSibling;
      if (anchor) {
        editor.insertBefore(marker, anchor);
      } else {
        editor.appendChild(marker);
      }
      updateDragIndicators();
      return;
    }
    editor.insertBefore(marker, target);
    updateDragIndicators();
  };

  const getTrailingEditableParagraph = (node) => {
    const next = node?.nextElementSibling;
    return isBlankEditorParagraph(next) ? next : null;
  };

  const ensureTrailingEditableParagraph = (node) => {
    if (!node || !editor || !editor.contains(node)) return null;
    const existing = getTrailingEditableParagraph(node);
    if (existing) {
      return existing;
    }
    const paragraph = document.createElement('p');
    paragraph.innerHTML = '<br>';
    if (node.nextSibling) {
      node.parentNode.insertBefore(paragraph, node.nextSibling);
    } else {
      node.parentNode.appendChild(paragraph);
    }
    return paragraph;
  };

  const createSideText = (side) => {
    const region = document.createElement('div');
    region.className = `proc-side-text proc-side-text-${side}`;
    region.dataset.side = side;
    region.dataset.placeholder = side === 'left' ? 'Texto izquierda' : 'Texto derecha';
    region.setAttribute('contenteditable', 'true');
    region.innerHTML = '<br>';
    return region;
  };

  const ensureSideLayout = (node, kind) => {
    if (!node) return null;
    let layout = node.closest('.proc-side-layout');
    if (!layout) {
      layout = document.createElement('div');
      layout.className = 'proc-side-layout';
      layout.dataset.kind = kind;
      const left = createSideText('left');
      const right = createSideText('right');
      node.parentNode.insertBefore(layout, node);
      layout.appendChild(left);
      layout.appendChild(node);
      layout.appendChild(right);
    }
    layout.dataset.kind = kind;
    let left = layout.querySelector('.proc-side-text-left');
    let right = layout.querySelector('.proc-side-text-right');
    if (!left) {
      left = createSideText('left');
      layout.insertBefore(left, layout.firstChild);
    }
    if (!right) {
      right = createSideText('right');
      layout.appendChild(right);
    }
    [left, right].forEach((region) => {
      region.setAttribute('contenteditable', 'true');
      region.dataset.placeholder = region.classList.contains('proc-side-text-left') ? 'Texto izquierda' : 'Texto derecha';
      if (region.innerHTML.trim() === '') {
        region.innerHTML = '<br>';
      }
    });
    return layout;
  };

  const moveSortableNode = (event) => {
    if (!draggingSortNode || !draggingSortType) return;
    const bounds = getEditorContentMetrics();
    const snappedClientX = bounds.left + snapToGrid(event.clientX - bounds.left);
    const snappedClientY = bounds.top + snapToGrid(event.clientY - bounds.top);
    dragPointerX = snappedClientX;
    dragPointerY = snappedClientY;
    const dragNodeRect = draggingSortNode.getBoundingClientRect();
    const dragVisualRect = getSortableVisualRect(draggingSortNode);
    const rawLeft = snappedClientX - (dragNodeGrabOffsetX == null ? Math.round(dragNodeRect.width / 2) : dragNodeGrabOffsetX) - bounds.left;
    const rawTop = snappedClientY - (dragNodeGrabOffsetY == null ? Math.round(dragNodeRect.height / 2) : dragNodeGrabOffsetY) - bounds.top;
    const nextLeft = clamp(
      bounds.left + snapToGrid(rawLeft),
      bounds.left,
      Math.max(bounds.left, bounds.right - Math.round(dragNodeRect.width))
    );
    const nextTop = Math.max(
      bounds.top,
      bounds.top + snapToGrid(rawTop)
    );
    const placement = resolveDropPlacement(snappedClientX, snappedClientY);
    dragDropTarget = placement.target;
    dragDropAfter = placement.after;
    placeDraggingNode(placement.target, placement.after);
    const visualOffsetLeft = Math.round(dragVisualRect.left - dragNodeRect.left);
    const visualOffsetTop = Math.round(dragVisualRect.top - dragNodeRect.top);
    const ghostLeft = Math.round(nextLeft - visualOffsetLeft);
    const ghostTop = Math.round(nextTop - visualOffsetTop);
    draggingSortNode.style.left = `${ghostLeft}px`;
    draggingSortNode.style.top = `${ghostTop}px`;
    updateDragIndicators();
  };

  const resolveSortableHorizontalPlacement = (node, clientX) => {
    if (!node || !editor || !['image', 'table', 'code', 'callout'].includes(draggingSortType || '')) {
      return null;
    }
    const bounds = getEditorContentMetrics();
    const nodeRect = node.getBoundingClientRect();
    const nodeWidth = Math.max(1, Math.round(nodeRect.width));
    const contentLeft = 0;
    const contentWidth = Math.max(0, bounds.width);
    const available = Math.max(0, Math.round(contentWidth - nodeWidth));
    const grabOffset = dragGrabOffsetX == null ? Math.round(nodeWidth / 2) : dragGrabOffsetX;
    const desiredLeft = clamp(
      snapToGrid(clientX - bounds.left - grabOffset),
      contentLeft,
      contentLeft + available
    );

    if (available <= 24) {
      return { align: 'free', offset: 0, left: contentLeft };
    }

    return {
      align: 'free',
      offset: Math.max(0, desiredLeft - contentLeft),
      left: desiredLeft
    };
  };

  const resolveSortableHorizontalPlacementFromVisualRect = (node, rect) => {
    if (!node || !editor || !rect || !['image', 'table', 'code', 'callout'].includes(draggingSortType || '')) {
      return null;
    }
    const bounds = getEditorContentMetrics();
    const nodeWidth = Math.max(1, Math.round(rect.width));
    const contentLeft = 0;
    const contentWidth = Math.max(0, bounds.width);
    const available = Math.max(0, Math.round(contentWidth - nodeWidth));
    const desiredLeft = clamp(
      snapToGrid(rect.left - bounds.left),
      contentLeft,
      contentLeft + available
    );

    if (available <= 24) {
      return { align: 'free', offset: 0, left: contentLeft };
    }

    return {
      align: 'free',
      offset: Math.max(0, desiredLeft - contentLeft),
      left: desiredLeft
    };
  };

  const updateSortableHorizontalIndicator = (node, clientX) => {
    const placement = resolveSortableHorizontalPlacement(draggingSortCompanion || node, clientX);
    if (!placement || !editor) return;
    const bounds = getEditorContentMetrics();
    if (!dragVerticalIndicator) {
      dragVerticalIndicator = document.createElement('div');
      dragVerticalIndicator.className = 'proc-drop-indicator-vertical';
    }
    dragVerticalIndicator.style.left = `${Math.round((bounds.left - bounds.rect.left) + placement.left)}px`;
    dragVerticalIndicator.style.top = `${Math.round(bounds.top - bounds.rect.top)}px`;
    dragVerticalIndicator.style.height = `${Math.round(bounds.height)}px`;
    if (dragVerticalIndicator.parentNode !== editor) {
      editor.appendChild(dragVerticalIndicator);
    }
  };

  const applySortableHorizontalAlign = (node, clientX) => {
    const targetNode = draggingSortCompanion || node;
    const placement = resolveSortableHorizontalPlacement(targetNode, clientX);
    if (!placement) return;
    targetNode.dataset.align = placement.align;
    targetNode.dataset.offset = String(placement.offset);
    syncFloatingOffsetStyle(targetNode);
  };

  const applySortableHorizontalAlignFromVisualRect = (node, rect) => {
    const targetNode = draggingSortCompanion || node;
    const placement = resolveSortableHorizontalPlacementFromVisualRect(targetNode, rect);
    if (!placement) return;
    targetNode.dataset.align = placement.align;
    targetNode.dataset.offset = String(placement.offset);
    syncFloatingOffsetStyle(targetNode);
  };

  const getTableScrollAutoWidth = (wrap) => {
    if (!wrap || !editor) return 420;
    const editorRect = editor.getBoundingClientRect();
    const wrapStyle = window.getComputedStyle(wrap);
    const marginX = (parseFloat(wrapStyle.marginLeft || '0') || 0) + (parseFloat(wrapStyle.marginRight || '0') || 0);
    const borderX = (parseFloat(wrapStyle.borderLeftWidth || '0') || 0) + (parseFloat(wrapStyle.borderRightWidth || '0') || 0);
    const scroll = wrap.querySelector('.proc-table-scroll');
    const scrollStyle = scroll ? window.getComputedStyle(scroll) : null;
    const scrollPaddingX = scrollStyle
      ? (parseFloat(scrollStyle.paddingLeft || '0') || 0) + (parseFloat(scrollStyle.paddingRight || '0') || 0)
      : 0;
    return Math.max(420, Math.round(editorRect.width - marginX - borderX - scrollPaddingX - 2));
  };

  const setTableHorizontalSize = (wrap, width) => {
    const table = wrap?.querySelector('table');
    const scroll = wrap?.querySelector('.proc-table-scroll');
    if (!table || !scroll) return;
    const wrapStyle = window.getComputedStyle(wrap);
    const scrollStyle = window.getComputedStyle(scroll);
    const wrapChromeWidth =
      (parseFloat(wrapStyle.borderLeftWidth || '0') || 0) +
      (parseFloat(wrapStyle.borderRightWidth || '0') || 0) +
      (parseFloat(scrollStyle.paddingLeft || '0') || 0) +
      (parseFloat(scrollStyle.paddingRight || '0') || 0);
    const autoTableWidth = getTableScrollAutoWidth(wrap);
    const autoFrameWidth = Math.max(460, Math.round(autoTableWidth + wrapChromeWidth));

    if (width >= autoTableWidth - 12) {
      wrap.style.width = `${autoFrameWidth}px`;
      wrap.style.minWidth = '';
      table.style.width = `${autoTableWidth}px`;
      table.style.minWidth = '';
      updateTableResponsiveState(wrap);
      return;
    }

    const nextTableWidth = Math.max(420, Math.round(width));
    const nextFrameWidth = Math.max(460, Math.round(nextTableWidth + wrapChromeWidth));
    wrap.style.width = `${Math.min(nextFrameWidth, autoFrameWidth)}px`;
    wrap.style.minWidth = `${Math.min(nextFrameWidth, autoFrameWidth)}px`;
    table.style.width = `${nextTableWidth}px`;
    table.style.minWidth = `${nextTableWidth}px`;
    updateTableResponsiveState(wrap);
  };

  const placeDraggedNodeFreely = (event, fallbackRect) => {
    if (!draggingSortNode || !editor) return;
    const bounds = getEditorContentMetrics();
    const visualNode = getSortableVisualNode(draggingSortNode) || draggingSortNode;
    const visualRect = fallbackRect || visualNode.getBoundingClientRect();
    const nodeWidth = Math.max(120, Math.round(visualRect.width || draggingSortNode.getBoundingClientRect().width || 120));
    const nodeHeight = Math.max(24, Math.round(visualRect.height || draggingSortNode.getBoundingClientRect().height || 24));
    const clientX = event?.clientX ?? dragPointerX ?? Math.round(visualRect.left + nodeWidth / 2);
    const clientY = event?.clientY ?? dragPointerY ?? Math.round(visualRect.top + nodeHeight / 2);
    const rawLeft = clientX - bounds.left - (dragGrabOffsetX == null ? Math.round(nodeWidth / 2) : dragGrabOffsetX);
    const rawTop = clientY - bounds.top - (dragGrabOffsetY == null ? Math.round(nodeHeight / 2) : dragGrabOffsetY);
    const left = clamp(snapToGrid(rawLeft), 0, Math.max(0, bounds.width - nodeWidth));
    const top = keepAwayFromPageDivision(Math.max(0, snapToGrid(rawTop)), nodeHeight);

    if (draggingSortNode.parentNode !== editor) {
      editor.appendChild(draggingSortNode);
    }
    if (draggingSortTail?.parentNode === editor) {
      draggingSortTail.remove();
    }

    draggingSortNode.dataset.position = 'free';
    draggingSortNode.dataset.align = 'free';
    draggingSortNode.dataset.offset = String(left);
    draggingSortNode.style.position = 'absolute';
    draggingSortNode.style.left = `${left}px`;
    draggingSortNode.style.top = `${top}px`;
    draggingSortNode.style.width = `${nodeWidth}px`;
    draggingSortNode.style.maxWidth = `calc(100% - ${left}px)`;
    draggingSortNode.style.margin = '0';
    draggingSortNode.style.zIndex = '';
    draggingSortNode.style.pointerEvents = '';

    if (draggingSortCompanion && draggingSortCompanion !== draggingSortNode) {
      draggingSortCompanion.dataset.position = 'free';
      draggingSortCompanion.dataset.align = 'free';
      draggingSortCompanion.dataset.offset = String(left);
      draggingSortCompanion.style.margin = '0';
      draggingSortCompanion.style.pointerEvents = '';
    }
    updateFreePositionCanvas(editor);
  };

  const stopSortableDrag = (event = null) => {
    if (draggingSortNode) {
      const finalVisualRect = getSortableVisualRect(draggingSortNode);
      const bounds = getEditorContentMetrics();
      const fallbackClientX = Math.round(finalVisualRect.left + (dragGrabOffsetX == null ? finalVisualRect.width / 2 : dragGrabOffsetX));
      const fallbackClientY = Math.round(finalVisualRect.top + (dragGrabOffsetY == null ? finalVisualRect.height / 2 : dragGrabOffsetY));
      const finalClientX = bounds.left + snapToGrid(((event?.clientX ?? dragPointerX ?? fallbackClientX) - bounds.left));
      const finalClientY = bounds.top + snapToGrid(((event?.clientY ?? dragPointerY ?? fallbackClientY) - bounds.top));
      if (event) {
        dragPointerX = finalClientX;
        dragPointerY = finalClientY;
      }
      draggingSortNode.classList.remove('is-dragging', 'is-drag-ghost');
      draggingSortCompanion?.classList.remove('is-dragging');
      placeDraggedNodeFreely(event, finalVisualRect);
      draggingSortOriginalParent = null;
      draggingSortOriginalNextSibling = null;
      draggingSortOriginalCssText = '';
      draggingSortNode = null;
      draggingSortType = null;
      draggingSortCompanion = null;
      draggingSortTail = null;
      dragPointerX = null;
      dragPointerY = null;
      dragGrabOffsetX = null;
      dragGrabOffsetY = null;
      dragNodeGrabOffsetX = null;
      dragNodeGrabOffsetY = null;
      dragDropTarget = null;
      dragDropAfter = false;
      dragLastPlaceholderTarget = null;
      dragLastPlaceholderAfter = null;
      dragPlaceholder?.remove();
      dragPlaceholder = null;
      dragIndicator?.remove();
      dragVerticalIndicator?.remove();
      syncContent();
    }
  };

  const normalizeLanguageLabel = (language) => {
    const labels = {
      html: 'HTML',
      sql: 'SQL',
      css: 'CSS',
      javascript: 'JavaScript',
      php: 'PHP',
      bash: 'Bash',
      text: 'Texto'
    };
    return labels[language] || language.toUpperCase();
  };

  const getEditableBlockRoot = (node) => {
    if (!node || !editor) return null;
    const layout = node.closest?.('.proc-side-layout');
    return layout && editor.contains(layout) ? layout : node;
  };

  const moveEditableBlock = (node, direction) => {
    const root = getEditableBlockRoot(node);
    if (!root || !editor.contains(root)) return false;
    const sibling = direction === 'up' ? root.previousElementSibling : root.nextElementSibling;
    if (!sibling) return false;
    if (direction === 'up') {
      sibling.before(root);
    } else {
      sibling.after(root);
    }
    syncContent();
    return true;
  };

  const removeEditableBlock = (node) => {
    const root = getEditableBlockRoot(node);
    if (!root || !editor.contains(root)) return false;
    const next = root.nextElementSibling;
    root.remove();
    if (next && isBlankEditorParagraph(next)) {
      next.remove();
    }
    syncContent();
    return true;
  };

  search?.addEventListener('input', () => {
    const needle = search.value.trim().toLowerCase();
    list?.querySelectorAll('[data-search]').forEach((item) => {
      const haystack = item.getAttribute('data-search') || '';
      item.classList.toggle('d-none', needle !== '' && !haystack.includes(needle));
    });
  });

  if (!form || !editor || !hiddenContent) {
    return;
  }

  const syncContent = () => {
    normalizeFlowAwayFromPageDivisions(editor);
    prepareImages();
    prepareTables();
    prepareCodeBlocks();
    prepareCallouts();
    highlightAllCodeBlocks();
    const clone = editor.cloneNode(true);
    clone.querySelectorAll('.proc-code-actions, .proc-code-resize').forEach((node) => node.remove());
    clone.querySelectorAll('.proc-image-tools, .proc-image-resize').forEach((node) => node.remove());
    clone.querySelectorAll('.proc-table-tools, .proc-table-resize, .proc-table-col-resize-handle, .proc-table-row-resize-handle').forEach((node) => node.remove());
    clone.querySelectorAll('.proc-callout-actions, .proc-callout-resize').forEach((node) => node.remove());
    clone.querySelectorAll('.proc-side-layout').forEach((layout) => {
      layout.classList.remove('is-selected', 'is-dragging');
      layout.querySelectorAll('.proc-side-text').forEach((region) => {
        region.removeAttribute('contenteditable');
      });
    });
    clone.querySelectorAll('.proc-image-wrap').forEach((wrap) => {
      wrap.classList.remove('is-selected', 'is-dragging');
      wrap.removeAttribute('contenteditable');
      wrap.removeAttribute('data-image-id');
      const img = wrap.querySelector('img');
      if (img) {
        const parent = wrap.parentNode;
        parent.insertBefore(img, wrap);
        wrap.remove();
      }
    });
    clone.querySelectorAll('.proc-table-wrap').forEach((wrap) => {
      wrap.classList.remove('is-selected');
      wrap.removeAttribute('contenteditable');
      wrap.removeAttribute('data-table-id');
      wrap.querySelectorAll('td, th').forEach((cell) => {
        cell.removeAttribute('contenteditable');
      });
    });
    clone.querySelectorAll('pre.proc-code-block').forEach((block) => {
      block.classList.remove('is-selected', 'is-dragging', 'is-editing');
      block.removeAttribute('draggable');
      block.removeAttribute('data-block-id');
      const codeEl = block.querySelector('code[data-lang]');
      if (codeEl) {
        const savedLang = getCodeSavedLanguage(codeEl);
        const savedText = getCodeSavedText(codeEl);
        setCodeRawText(codeEl, savedText);
        codeEl.removeAttribute('contenteditable');
        codeEl.removeAttribute('spellcheck');
        codeEl.setAttribute('data-lang', savedLang);
        codeEl.textContent = savedText;
        codeEl.removeAttribute('data-raw-code');
        codeEl.removeAttribute('data-saved-code');
        codeEl.removeAttribute('data-saved-lang');
      }
    });
    clone.querySelectorAll('.proc-callout').forEach((callout) => {
      callout.classList.remove('is-selected', 'is-dragging');
      callout.removeAttribute('data-callout-id');
    });
    updateFreePositionCanvas(editor);
    hiddenContent.value = clone.innerHTML.trim();
  };

  pageSizeSelect?.addEventListener('change', () => {
    updatePageGuides();
    syncContent();
  });

  const focusEditor = () => {
    editor.focus();
    const range = document.createRange();
    range.selectNodeContents(editor);
    range.collapse(false);
    const selection = window.getSelection();
    if (selection) {
      selection.removeAllRanges();
      selection.addRange(range);
    }
  };

  const getEditorSelectionRange = () => {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) {
      return null;
    }
    const range = selection.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) {
      return null;
    }
    return range;
  };

  const saveEditorSelection = () => {
    const range = getEditorSelectionRange();
    if (range) {
      lastEditorRange = range.cloneRange();
    }
  };

  const restoreEditorSelection = () => {
    const selection = window.getSelection();
    if (!selection) return false;
    if (lastEditorRange && editor.contains(lastEditorRange.commonAncestorContainer)) {
      editor.focus();
      selection.removeAllRanges();
      selection.addRange(lastEditorRange);
      return true;
    }
    return false;
  };

  const getStoredEditorSelectionRange = () => {
    if (lastEditorRange && editor.contains(lastEditorRange.commonAncestorContainer)) {
      return lastEditorRange.cloneRange();
    }
    return null;
  };

  const getPreferredEditorSelectionRange = () => {
    const liveRange = getEditorSelectionRange();
    const storedRange = getStoredEditorSelectionRange();
    if (liveRange && !liveRange.collapsed) {
      return liveRange;
    }
    if (storedRange && !storedRange.collapsed) {
      return storedRange;
    }
    return liveRange || storedRange;
  };

  const getSelectionNode = () => {
    const range = getEditorSelectionRange();
    if (!range) {
      return null;
    }
    return range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
      ? range.commonAncestorContainer
      : range.commonAncestorContainer.parentElement;
  };

  const getActiveListItem = () => getSelectionNode()?.closest('li') || null;

  const getActiveProceduralList = () => {
    const listItem = getActiveListItem();
    if (!listItem) return null;
    const list = listItem.closest('ol, ul');
    return list && editor.contains(list) ? list : null;
  };

  const focusListItem = (item) => {
    if (!item) return;
    const range = document.createRange();
    range.selectNodeContents(item);
    range.collapse(false);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    saveEditorSelection();
  };

  const unwrapElement = (element) => {
    if (!element || !element.parentNode) return;
    const fragment = document.createDocumentFragment();
    while (element.firstChild) {
      fragment.appendChild(element.firstChild);
    }
    element.parentNode.replaceChild(fragment, element);
  };

  const clearInlineStylesInNode = (root) => {
    if (!root) return;
    const nodes = [root, ...root.querySelectorAll('*')];
    nodes.forEach((node) => {
      if (!(node instanceof HTMLElement)) return;
      if (node.closest('.proc-code-block, .proc-table-wrap')) return;
      node.removeAttribute('style');
      if (node.tagName === 'FONT') {
        unwrapElement(node);
      }
    });
  };

  const applyTextStyle = (styleName, styleValue) => {
    let range = getPreferredEditorSelectionRange();
    if (range) {
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
      editor.focus();
    } else {
      editor.focus();
      range = getEditorSelectionRange();
    }
    const activeListItem = getActiveListItem();
    const editingCodeBlock = getActiveEditingCodeBlock();
    const activeCodeEl = editingCodeBlock?.querySelector('code[data-lang]') || null;
    const activeTableCell = getActiveEditingTableCell();
    const activeTableWrap = activeTableCell?.closest('.proc-table-wrap') || null;
    if (activeTableCell && activeTableWrap) {
      selectedTableCell = activeTableCell;
      selectedTableWrap = activeTableWrap;
      applyTableCellTextStyle(activeTableWrap, styleName, styleValue);
      saveEditorSelection();
      return;
    }
    if (styleName === 'color') {
      document.execCommand('foreColor', false, styleValue);
      saveEditorSelection();
      syncContent();
      return;
    }
    if (activeCodeEl) {
      activeCodeEl.style[styleName] = styleValue;
      saveEditorSelection();
      syncContent();
      return;
    }
    if (range && !range.collapsed && range.toString().trim() !== '') {
      const span = document.createElement('span');
      span.style[styleName] = styleValue;
      wrapSelectionWithNode(range, span);
      saveEditorSelection();
      syncContent();
      return;
    }
    const target = activeCodeEl || activeListItem || getSelectionNode()?.closest('p, div, li, h1, h2, h3, h4, blockquote') || editor;
    if (target instanceof HTMLElement) {
      target.style[styleName] = styleValue;
      saveEditorSelection();
      syncContent();
    }
  };

  const applyBlockFormat = (tagName) => {
    const activeListItem = getActiveListItem();
    if (activeListItem) {
      if (tagName === 'h2') {
        activeListItem.style.fontSize = '1.55rem';
        activeListItem.style.fontWeight = '800';
        activeListItem.style.lineHeight = '1.2';
      } else if (tagName === 'h3') {
        activeListItem.style.fontSize = '1.3rem';
        activeListItem.style.fontWeight = '700';
        activeListItem.style.lineHeight = '1.25';
      }
      saveEditorSelection();
      syncContent();
      return;
    }
    document.execCommand('formatBlock', false, tagName);
    saveEditorSelection();
    syncContent();
  };

  const clearEditorFormatting = () => {
    restoreEditorSelection();
    document.execCommand('removeFormat', false, null);
    document.execCommand('unlink', false, null);
    const activeListItem = getActiveListItem();
    if (activeListItem) {
      clearInlineStylesInNode(activeListItem);
      activeListItem.style.fontSize = '';
      activeListItem.style.fontWeight = '';
      activeListItem.style.lineHeight = '';
      activeListItem.style.fontFamily = '';
    }
    const heading = getSelectionNode()?.closest('h1, h2, h3, h4, h5, h6');
    if (heading && editor.contains(heading)) {
      const paragraph = document.createElement('p');
      paragraph.innerHTML = heading.innerHTML;
      heading.replaceWith(paragraph);
      const range = document.createRange();
      range.selectNodeContents(paragraph);
      range.collapse(false);
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
    }
    const context = activeListItem || getSelectionNode()?.closest('p, div, blockquote');
    if (context) {
      clearInlineStylesInNode(context);
    }
    saveEditorSelection();
    syncContent();
  };

  const focusSelectedTableCell = (wrap) => {
    const cell = selectedTableCell && wrap.contains(selectedTableCell)
      ? selectedTableCell
      : wrap.querySelector('thead th, tbody td');
    if (!cell) return null;
    return focusTableCell(cell);
  };

  const getActiveEditingCodeBlock = () => {
    const direct = editor.querySelector('.proc-code-block.is-editing.is-selected');
    if (direct) {
      return direct;
    }
    const range = getEditorSelectionRange();
    if (!range) {
      return null;
    }
    const node = range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
      ? range.commonAncestorContainer
      : range.commonAncestorContainer.parentElement;
    const block = node?.closest('.proc-code-block.is-editing');
    return block && editor.contains(block) ? block : null;
  };

  const insertHtml = (html) => {
    if (!restoreEditorSelection()) {
      focusEditor();
    }
    document.execCommand('insertHTML', false, html);
    saveEditorSelection();
    syncContent();
  };

  const fragmentHasBlockContent = (fragment) => Array.from(fragment.childNodes).some((node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      return false;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      return false;
    }
    return /^(P|DIV|UL|OL|LI|TABLE|H[1-6]|BLOCKQUOTE|PRE|HR)$/.test(node.tagName)
      || !!node.querySelector?.('p, div, ul, ol, table, h1, h2, h3, h4, h5, h6, blockquote, pre, hr');
  });

  const getRangeCallout = (range) => {
    if (!range || !editor) return null;
    const startNode = range.startContainer.nodeType === Node.ELEMENT_NODE
      ? range.startContainer
      : range.startContainer.parentElement;
    const endNode = range.endContainer.nodeType === Node.ELEMENT_NODE
      ? range.endContainer
      : range.endContainer.parentElement;
    const startCallout = startNode?.closest?.('.proc-callout') || null;
    const endCallout = endNode?.closest?.('.proc-callout') || null;
    return startCallout && startCallout === endCallout && editor.contains(startCallout)
      ? startCallout
      : null;
  };

  const retoneCallout = (callout, tone, title) => {
    if (!callout) return false;
    callout.dataset.tone = tone;
    let titleNode = callout.querySelector(':scope > .proc-callout-title');
    if (!titleNode) {
      titleNode = document.createElement('span');
      titleNode.className = 'proc-callout-title';
      callout.insertBefore(titleNode, callout.firstChild);
    }
    titleNode.textContent = title;
    prepareCallouts();
    syncContent();
    return true;
  };

  const insertCalloutBlock = ({ tone, title, fallback }) => {
    let range = getPreferredEditorSelectionRange();
    if (range) {
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
      editor.focus();
      range = selection?.rangeCount ? selection.getRangeAt(0) : range;
    }

    const activeCallout = getRangeCallout(range);
    if (activeCallout) {
      retoneCallout(activeCallout, tone, title);
      return;
    }

    if (!range || !editor.contains(range.commonAncestorContainer) || range.collapsed) {
      insertHtml(`<div class="proc-callout" data-tone="${tone}"><span class="proc-callout-title">${title}</span><p class="mb-0">${fallback}</p></div>`);
      prepareCallouts();
      syncContent();
      return;
    }

    const callout = document.createElement('div');
    callout.className = 'proc-callout';
    callout.dataset.tone = tone;

    const titleNode = document.createElement('span');
    titleNode.className = 'proc-callout-title';
    titleNode.textContent = title;
    callout.appendChild(titleNode);

    const fragment = range.extractContents();
    if (fragmentHasBlockContent(fragment)) {
      callout.appendChild(fragment);
    } else {
      const paragraph = document.createElement('p');
      paragraph.className = 'mb-0';
      paragraph.appendChild(fragment);
      if (!paragraph.textContent.trim() && !paragraph.querySelector('img, table')) {
        paragraph.innerHTML = '<br>';
      }
      callout.appendChild(paragraph);
    }

    range.insertNode(callout);
    setSelectionAfterNode(callout);
    prepareCallouts();
    syncContent();
  };

  const escapePlainTextHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const plainTextToPasteHtml = (text) => {
    const normalized = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    if (normalized.includes('\t')) {
      const rows = normalized
        .split('\n')
        .filter((line, index, arr) => !(index === arr.length - 1 && line === ''));
      if (rows.length > 0) {
        const body = rows.map((line) => {
          const cells = line.split('\t').map((cell) => `<td>${escapePlainTextHtml(cell).replace(/\n/g, '<br>')}</td>`).join('');
          return `<tr>${cells}</tr>`;
        }).join('');
        return `<table><tbody>${body}</tbody></table>`;
      }
    }
    return normalized
      .split(/\n{2,}/)
      .map((paragraph) => `<p>${escapePlainTextHtml(paragraph).replace(/\n/g, '<br>') || '<br>'}</p>`)
      .join('');
  };

  const sanitizePastedStyle = (styleText) => {
    const allowed = new Set([
      'color',
      'background',
      'background-color',
      'font-family',
      'font-size',
      'font-style',
      'font-weight',
      'text-align',
      'text-decoration',
      'vertical-align',
      'border',
      'border-top',
      'border-right',
      'border-bottom',
      'border-left',
      'border-color',
      'border-style',
      'border-width',
      'padding',
      'padding-top',
      'padding-right',
      'padding-bottom',
      'padding-left',
      'width',
      'height',
      'white-space'
    ]);
    const blockedValue = /(expression\s*\(|javascript:|vbscript:|url\s*\()/i;
    return String(styleText || '')
      .split(';')
      .map((part) => part.trim())
      .filter(Boolean)
      .map((part) => {
        const separator = part.indexOf(':');
        if (separator === -1) return '';
        const property = part.slice(0, separator).trim().toLowerCase();
        const value = part.slice(separator + 1).trim();
        if (!allowed.has(property) || property.startsWith('mso-') || blockedValue.test(value)) {
          return '';
        }
        return `${property}: ${value}`;
      })
      .filter(Boolean)
      .join('; ');
  };

  const sanitizePastedHtml = (html) => {
    const source = String(html || '')
      .replace(/<!--StartFragment-->/gi, '')
      .replace(/<!--EndFragment-->/gi, '')
      .replace(/<o:p>\s*<\/o:p>/gi, '')
      .replace(/<o:p>[\s\S]*?<\/o:p>/gi, ' ');
    if (!source.trim()) return '';

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const blockedTags = 'script, style, meta, link, object, embed, iframe, form, input, button, textarea, select, xml';
    doc.querySelectorAll(blockedTags).forEach((node) => node.remove());
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT);
    const comments = [];
    while (walker.nextNode()) {
      comments.push(walker.currentNode);
    }
    comments.forEach((node) => node.remove());

    const allowedTags = new Set([
      'A', 'B', 'BLOCKQUOTE', 'BR', 'CODE', 'COL', 'COLGROUP', 'DEL', 'DIV', 'EM', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
      'HR', 'I', 'IMG', 'LI', 'OL', 'P', 'PRE', 'S', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TABLE', 'TBODY', 'TD', 'TFOOT',
      'TH', 'THEAD', 'TR', 'U', 'UL'
    ]);
    const unwrapNode = (node) => {
      const fragment = node.ownerDocument.createDocumentFragment();
      while (node.firstChild) {
        fragment.appendChild(node.firstChild);
      }
      node.replaceWith(fragment);
    };

    Array.from(doc.body.querySelectorAll('*')).forEach((node) => {
      if (!allowedTags.has(node.tagName)) {
        unwrapNode(node);
        return;
      }

      Array.from(node.attributes).forEach((attr) => {
        const name = attr.name.toLowerCase();
        const value = attr.value || '';
        let keep = false;

        if (name === 'style') {
          const cleanStyle = sanitizePastedStyle(value);
          if (cleanStyle) {
            node.setAttribute('style', cleanStyle);
          } else {
            node.removeAttribute('style');
          }
          return;
        }

        if (name === 'href' && node.tagName === 'A') {
          keep = /^(https?:|mailto:|tel:|#|\/)/i.test(value.trim());
        } else if (name === 'src' && node.tagName === 'IMG') {
          keep = /^(https?:|data:image\/|\/redmine-mantencion\/data\/procedimientos\/imagenes\/)/i.test(value.trim());
        } else if (['colspan', 'rowspan'].includes(name) && ['TD', 'TH'].includes(node.tagName)) {
          keep = /^\d{1,3}$/.test(value.trim());
        } else if (['width', 'height'].includes(name) && ['IMG', 'TABLE', 'TD', 'TH', 'COL'].includes(node.tagName)) {
          keep = /^[\d.]+%?$/.test(value.trim());
        } else if (name === 'alt' && node.tagName === 'IMG') {
          keep = true;
        }

        if (!keep) {
          node.removeAttribute(attr.name);
        }
      });

      if (node.tagName === 'A') {
        node.setAttribute('rel', 'noopener noreferrer');
      }
    });

    doc.body.querySelectorAll('span, div, p').forEach((node) => {
      if (!node.getAttribute('style') && node.tagName === 'SPAN') {
        unwrapNode(node);
      }
    });

    return doc.body.innerHTML
      .replace(/\sclass="Mso[^"]*"/gi, '')
      .trim();
  };

  const pasteRichHtml = (html) => {
    const cleanHtml = sanitizePastedHtml(html);
    if (!cleanHtml) return false;
    insertHtml(cleanHtml);
    prepareImages();
    prepareTables();
    prepareCodeBlocks();
    prepareCallouts();
    syncContent();
    return true;
  };

  const pasteTableHtmlGrid = (cell, html) => {
    if (!cell || !html) return false;
    const cleanHtml = sanitizePastedHtml(html);
    const holder = document.createElement('div');
    holder.innerHTML = cleanHtml;
    const sourceTable = holder.querySelector('table');
    if (!sourceTable) return false;

    const position = getTableCellPosition(cell);
    if (!position) return false;
    const { table, rowIndex, colIndex } = position;
    const sourceRows = Array.from(sourceTable.rows);
    if (sourceRows.length === 0) return false;

    const maxCols = Math.max(...sourceRows.map((row) => row.cells.length));
    const currentCols = table.tHead?.rows[0]?.cells.length || table.rows[0]?.cells.length || 0;
    for (let i = currentCols; i < colIndex + maxCols; i += 1) {
      appendTableColumn(table, true);
    }
    while (table.rows.length < rowIndex + sourceRows.length) {
      appendTableBodyRow(table, true);
    }

    sourceRows.forEach((sourceRow, rowOffset) => {
      const targetRow = table.rows[rowIndex + rowOffset];
      Array.from(sourceRow.cells).forEach((sourceCell, colOffset) => {
        const targetCell = targetRow?.cells[colIndex + colOffset];
        if (!targetCell) return;
        targetCell.innerHTML = sourceCell.innerHTML || '<br>';
        const style = sanitizePastedStyle(sourceCell.getAttribute('style') || '');
        if (style) {
          targetCell.setAttribute('style', style);
        }
      });
    });

    applyTableCellPlaceholders(table);
    const wrap = table.closest('.proc-table-wrap');
    if (wrap) {
      ensureTableToolState(wrap);
      updateTableResizeHandles(wrap);
    }
    const lastRow = table.rows[Math.min(table.rows.length - 1, rowIndex + sourceRows.length - 1)];
    const lastCell = lastRow?.cells[Math.min(lastRow.cells.length - 1, colIndex + maxCols - 1)];
    if (lastCell) {
      focusTableCell(lastCell);
    }
    syncContent();
    return true;
  };

  const normalizeLinkHref = (value) => {
    const href = (value || '').trim();
    if (!href) return '';
    if (/^(https?:|mailto:|tel:|#|\/)/i.test(href)) {
      return href;
    }
    return `https://${href}`;
  };

  const setSelectionAfterNode = (node) => {
    if (!node || !editor.contains(node)) {
      return;
    }
    const range = document.createRange();
    range.setStartAfter(node);
    range.collapse(true);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    saveEditorSelection();
  };

  const insertLinkNode = ({ href, text, openBlank }) => {
    const linkHref = normalizeLinkHref(href);
    if (!linkHref) {
      showUiMessage('Ingresa la URL del enlace.');
      return;
    }
    let range = getStoredEditorSelectionRange() || getEditorSelectionRange();
    if (!range) {
      focusEditor();
      range = getEditorSelectionRange();
    }

    const anchor = document.createElement('a');
    anchor.href = linkHref;
    if (openBlank) {
      anchor.target = '_blank';
      anchor.rel = 'noopener noreferrer';
    }

    const selectedText = range ? range.toString().trim() : '';
    const fallbackText = text?.trim() || selectedText || linkHref;
    anchor.textContent = fallbackText;

    if (range) {
      if (!range.collapsed && (!text?.trim() || text.trim() === selectedText)) {
        const inserted = wrapSelectionWithNode(range, anchor);
        if (!inserted) {
          range.deleteContents();
          range.insertNode(anchor);
        }
      } else if (!range.collapsed) {
        range.deleteContents();
        range.insertNode(anchor);
      } else {
        range.insertNode(anchor);
      }
      setSelectionAfterNode(anchor);
    } else {
      editor.appendChild(anchor);
      setSelectionAfterNode(anchor);
    }

    saveEditorSelection();
    syncContent();
  };

  const readImageFile = (file, targetTableCell = null) => {
    if (!file || !file.type.startsWith('image/')) {
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const activeCell = targetTableCell && targetTableCell.closest('.proc-table-wrap.is-editing')
        ? targetTableCell
        : getActiveEditingTableCell();
      if (activeCell) {
        insertImageIntoTableCell(activeCell, String(reader.result || ''), file.name || 'Imagen');
        return;
      }
      insertHtml(wrapImageHtml(reader.result, escapeHtml(file.name || 'Imagen')));
    };
    reader.readAsDataURL(file);
  };

  document.querySelectorAll('[data-cmd]').forEach((button) => {
    button.addEventListener('click', () => {
      document.execCommand(button.getAttribute('data-cmd'), false, null);
      syncContent();
    });
  });
  document.querySelectorAll('.proc-toolbar button, .proc-toolbar label').forEach((control) => {
    control.addEventListener('mousedown', (event) => {
      saveEditorSelection();
      event.preventDefault();
    });
  });
  [fontFamilySelect, fontSizeSelect, fontColorInput].forEach((control) => {
    control?.addEventListener('mousedown', () => saveEditorSelection());
  });

  document.querySelectorAll('[data-block]').forEach((button) => {
    button.addEventListener('click', () => {
      applyBlockFormat(button.getAttribute('data-block'));
    });
  });

  document.getElementById('add-link-btn')?.addEventListener('click', () => {
    const selectedRange = getStoredEditorSelectionRange() || getEditorSelectionRange();
    const selectedText = selectedRange ? selectedRange.toString().trim() : '';
    if (linkUrlInput) linkUrlInput.value = '';
    if (linkTextInput) linkTextInput.value = selectedText;
    if (linkBlankInput) linkBlankInput.checked = true;
    if (linkModal) {
      linkModal.show();
      window.setTimeout(() => linkUrlInput?.focus(), 120);
    }
  });

  linkForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    insertLinkNode({
      href: linkUrlInput?.value || '',
      text: linkTextInput?.value || '',
      openBlank: !!linkBlankInput?.checked,
    });
    linkModal?.hide();
  });

  linkModalEl?.addEventListener('hidden.bs.modal', () => {
    if (linkForm) {
      linkForm.reset();
    }
  });

  document.getElementById('remove-format-btn')?.addEventListener('click', () => {
    clearEditorFormatting();
  });

  fontFamilySelect?.addEventListener('change', () => {
    if (!fontFamilySelect.value) return;
    applyTextStyle('fontFamily', fontFamilySelect.value);
  });

  const applyNumericFontSize = () => {
    const size = parseInt(String(fontSizeSelect?.value || ''), 10);
    if (!size || Number.isNaN(size)) return;
    const clamped = Math.min(72, Math.max(8, size));
    fontSizeSelect.value = String(clamped);
    applyTextStyle('fontSize', `${clamped}pt`);
  };

  fontSizeSelect?.addEventListener('change', applyNumericFontSize);
  fontSizeSelect?.addEventListener('blur', applyNumericFontSize);
  fontSizeSelect?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      applyNumericFontSize();
    }
  });

  fontColorInput?.addEventListener('input', () => {
    if (!fontColorInput.value) return;
    applyTextStyle('color', fontColorInput.value);
  });
  fontColorInput?.addEventListener('change', () => {
    if (!fontColorInput.value) return;
    applyTextStyle('color', fontColorInput.value);
  });

  document.getElementById('insert-checklist-btn')?.addEventListener('click', () => {
    insertHtml('<ul class="proc-checklist"><li><br></li><li><br></li><li><br></li></ul>');
    syncContent();
  });

  document.getElementById('add-list-item-btn')?.addEventListener('click', () => {
    const list = getActiveProceduralList();
    const currentItem = getActiveListItem();
    if (!list || !currentItem) {
      showUiMessage('Ubica el cursor dentro de un paso o checklist para añadir un ítem.');
      return;
    }
    const newItem = document.createElement('li');
    newItem.innerHTML = '<br>';
    if (currentItem.nextSibling) {
      currentItem.parentNode.insertBefore(newItem, currentItem.nextSibling);
    } else {
      currentItem.parentNode.appendChild(newItem);
    }
    focusListItem(newItem);
    syncContent();
  });

  document.getElementById('remove-list-item-btn')?.addEventListener('click', () => {
    const list = getActiveProceduralList();
    const currentItem = getActiveListItem();
    if (!list || !currentItem) {
      showUiMessage('Ubica el cursor dentro de un paso o checklist para quitar un ítem.');
      return;
    }
    if (list.children.length <= 1) {
      const parentBlock = list.closest('div, section, article, p') || list.parentElement;
      list.remove();
      if (parentBlock && editor.contains(parentBlock)) {
        const range = document.createRange();
        range.selectNodeContents(parentBlock);
        range.collapse(false);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
        saveEditorSelection();
      } else {
        focusEditor();
      }
      syncContent();
      return;
    }
    const fallback = currentItem.previousElementSibling || currentItem.nextElementSibling;
    currentItem.remove();
    focusListItem(fallback);
    syncContent();
  });

  document.getElementById('insert-note-btn')?.addEventListener('click', () => {
    insertCalloutBlock({
      tone: 'info',
      title: 'Nota',
      fallback: 'Agrega aquí una aclaración operativa, contexto o precondición.'
    });
  });

  document.getElementById('insert-warning-btn')?.addEventListener('click', () => {
    insertCalloutBlock({
      tone: 'warning',
      title: 'Advertencia',
      fallback: 'Indica aquí un riesgo, validación crítica o impacto antes de continuar.'
    });
  });

  document.getElementById('insert-separator-btn')?.addEventListener('click', () => {
    insertHtml('<hr class="proc-editor-sep">');
    syncContent();
  });

  insertTableBtn?.addEventListener('click', () => {
    insertHtml(createTableHtml());
    prepareTables();
    syncContent();
  });

  insertCodeBtn?.addEventListener('click', () => {
    const language = (codeLanguage?.value || 'text').toLowerCase();
    const editingBlock = getActiveEditingCodeBlock();
    if (editingBlock) {
      const codeEl = editingBlock.querySelector('code[data-lang]');
      if (codeEl) {
        codeEl.setAttribute('data-lang', language);
        editingBlock.setAttribute('data-lang-label', normalizeLanguageLabel(language));
        setCodeRawText(codeEl, extractCodePlainText(codeEl) || getCodeRawText(codeEl));
        if (codeLanguage) {
          codeLanguage.value = language;
        }
      }
      return;
    }
    if (selectedCodeBlock) {
      showUiMessage('Primero pulsa Editar en la card del código para cambiar el lenguaje.');
      return;
    }
    const range = getEditorSelectionRange();
    const selectedText = range ? (range.toString() || '') : '';
    const code = selectedText.trim();
    if (!range || code === '') {
      showUiMessage('Selecciona el texto que quieres convertir en código.');
      return;
    }
    const label = normalizeLanguageLabel(language);
    const wrapper = document.createElement('div');
    wrapper.innerHTML = '<pre class="proc-code-block" data-lang-label="' + escapeHtml(label) + '"><code data-lang="' + escapeHtml(language) + '">' + escapeHtml(code) + '</code></pre><p><br></p>';
    range.deleteContents();
    const fragment = document.createDocumentFragment();
    while (wrapper.firstChild) {
      fragment.appendChild(wrapper.firstChild);
    }
    range.insertNode(fragment);
    prepareCodeBlocks();
    syncContent();
  });

  imageInput?.addEventListener('change', (event) => {
    const activeCell = getActiveEditingTableCell();
    Array.from(event.target.files || []).forEach((file) => readImageFile(file, activeCell));
    event.target.value = '';
  });

  editor.addEventListener('click', (event) => {
    const tableEditBtn = event.target.closest('.proc-table-edit');
    const tableSaveBtn = event.target.closest('.proc-table-save');
    const tableHeadAddBtn = event.target.closest('.proc-table-head-add');
    const tableBoldBtn = event.target.closest('.proc-table-bold');
    const tableItalicBtn = event.target.closest('.proc-table-italic');
    const tableStyleControl = event.target.closest('.proc-table-style');
    const tableRowAddBtn = event.target.closest('.proc-table-row-add');
    const tableRowRemoveBtn = event.target.closest('.proc-table-row-remove');
    const tableColAddBtn = event.target.closest('.proc-table-col-add');
    const tableColRemoveBtn = event.target.closest('.proc-table-col-remove');
    const tableDragBtn = event.target.closest('.proc-table-drag');
    const tableRemoveBtn = event.target.closest('.proc-table-remove');
    if (tableEditBtn || tableSaveBtn || tableHeadAddBtn || tableBoldBtn || tableItalicBtn || tableStyleControl || tableRowAddBtn || tableRowRemoveBtn || tableColAddBtn || tableColRemoveBtn || tableDragBtn || tableRemoveBtn) {
      const wrap = event.target.closest('.proc-table-wrap');
      const table = wrap?.querySelector('table');
      if (!wrap || !table) return;
      if (tableStyleControl) {
        selectedTableWrap = wrap;
        selectedTableWrap.classList.add('is-selected');
        ensureTableToolState(wrap);
        return;
      }
      if (tableDragBtn) {
        return;
      }
      if (tableEditBtn) {
        setTableEditing(wrap, true);
        syncContent();
        return;
      }
      if (tableSaveBtn) {
        setTableEditing(wrap, false);
        syncContent();
        return;
      }
      if (!wrap.classList.contains('is-editing')) {
        showUiMessage('Pulsa Editar en la tabla para modificar su estructura.');
        return;
      }
      if (tableBoldBtn || tableItalicBtn) {
        toggleTableCellTextStyle(
          wrap,
          tableBoldBtn ? 'fontWeight' : 'fontStyle',
          tableBoldBtn ? '700' : 'italic',
          tableBoldBtn ? '400' : 'normal'
        );
        return;
      }
      const rows = Array.from(table.rows);
      const activeCell = selectedTableCell && wrap.contains(selectedTableCell) ? selectedTableCell : null;
      const rowIndex = activeCell ? activeCell.parentElement.rowIndex : Math.max(rows.length - 1, 0);
      const cellIndex = activeCell ? activeCell.cellIndex : 0;

      if (tableHeadAddBtn) {
        ensureTableHead(table);
      } else if (tableRowAddBtn) {
        const body = table.tBodies[0] || table.createTBody();
        const cols = table.tHead?.rows[0]?.cells.length || body.rows[0]?.cells.length || 1;
        const bodyIndex = activeCell && activeCell.parentElement.parentElement.tagName === 'TBODY'
          ? activeCell.parentElement.sectionRowIndex
          : body.rows.length - 1;
        const newRow = body.insertRow(Math.min(bodyIndex + 1, body.rows.length));
        for (let i = 0; i < cols; i += 1) {
          const cell = newRow.insertCell();
          cell.setAttribute('contenteditable', 'true');
          cell.textContent = '';
        }
      } else if (tableRowRemoveBtn) {
        const body = table.tBodies[0];
        if (!body || body.rows.length <= 1) {
          showUiMessage('La tabla debe mantener al menos una fila.');
          return;
        }
        const bodyIndex = activeCell && activeCell.parentElement.parentElement.tagName === 'TBODY'
          ? activeCell.parentElement.sectionRowIndex
          : Math.max(body.rows.length - 1, 0);
        body.deleteRow(bodyIndex);
      } else if (tableColAddBtn) {
        ensureTableHead(table);
        Array.from(table.rows).forEach((row) => {
          const tagName = row.parentElement.tagName === 'THEAD' ? 'th' : 'td';
          const cell = document.createElement(tagName);
          cell.setAttribute('contenteditable', 'true');
          if (tagName === 'th') {
            cell.setAttribute('data-placeholder', 'Nombre de columna');
          }
          cell.textContent = '';
          row.insertBefore(cell, row.cells[Math.min(cellIndex + 1, row.cells.length)] || null);
        });
      } else if (tableColRemoveBtn) {
        const cols = table.tHead?.rows[0]?.cells.length || rows[0]?.cells.length || 0;
        if (cols <= 1) {
          showUiMessage('La tabla debe mantener al menos una columna.');
          return;
        }
        rows.forEach((row) => {
          if (row.cells[cellIndex]) {
            row.deleteCell(cellIndex);
          }
        });
      } else if (tableRemoveBtn) {
        removeEditableBlock(wrap);
        clearSelectedTableWrap();
        return;
      }
      prepareTables();
      ensureTableToolState(wrap);
      updateTableResizeHandles(wrap);
      saveEditorSelection();
      syncContent();
      return;
    }
    const imageMoveUpBtn = event.target.closest('.proc-image-move-up');
    const imageMoveDownBtn = event.target.closest('.proc-image-move-down');
    const imageRemoveBtn = event.target.closest('.proc-image-remove');
    if (imageMoveUpBtn || imageMoveDownBtn || imageRemoveBtn) {
      const wrap = event.target.closest('.proc-image-wrap');
      if (!wrap) return;
      if (imageMoveUpBtn) {
        moveEditableBlock(wrap, 'up');
      } else if (imageMoveDownBtn) {
        moveEditableBlock(wrap, 'down');
      } else if (imageRemoveBtn) {
        removeEditableBlock(wrap);
        clearSelectedImageWrap();
      }
      syncContent();
      return;
    }
    const moveUpBtn = event.target.closest('.proc-code-move-up');
    const moveDownBtn = event.target.closest('.proc-code-move-down');
    const removeBtn = event.target.closest('.proc-code-remove');
    const editBtn = event.target.closest('.proc-code-edit');
    const saveBtn = event.target.closest('.proc-code-save');
    if (moveUpBtn || moveDownBtn || removeBtn || editBtn || saveBtn) {
      const block = event.target.closest('.proc-code-block');
      if (!block) return;
      if (editBtn) {
        setCodeBlockEditing(block, true);
      } else if (saveBtn) {
        setCodeBlockEditing(block, false);
      } else if (moveUpBtn) {
        moveEditableBlock(block, 'up');
      } else if (moveDownBtn) {
        moveEditableBlock(block, 'down');
      } else if (removeBtn) {
        removeEditableBlock(block);
        clearSelectedCodeBlock();
      }
      syncContent();
      return;
    }
    const calloutMoveUpBtn = event.target.closest('.proc-callout-move-up');
    const calloutMoveDownBtn = event.target.closest('.proc-callout-move-down');
    const calloutRemoveBtn = event.target.closest('.proc-callout-remove');
    if (calloutMoveUpBtn || calloutMoveDownBtn || calloutRemoveBtn) {
      const callout = event.target.closest('.proc-callout');
      if (!callout) return;
      if (calloutMoveUpBtn) {
        const prev = callout.previousElementSibling;
        if (prev) prev.before(callout);
      } else if (calloutMoveDownBtn) {
        const next = callout.nextElementSibling;
        if (next) next.after(callout);
      } else if (calloutRemoveBtn) {
        callout.remove();
        clearSelectedCallout();
      }
      syncContent();
      return;
    }
    const imageWrap = event.target.closest('.proc-image-wrap');
    const callout = event.target.closest('.proc-callout');
    const tableCell = event.target.closest('td, th');
      const tableWrap = event.target.closest('.proc-table-wrap');
    clearSelectedImageWrap();
    clearSelectedTableWrap();
    clearSelectedCallout();
    if (imageWrap && editor.contains(imageWrap)) {
      selectedImageWrap = imageWrap;
      selectedImageWrap.classList.add('is-selected');
      return;
    }
    if (callout && editor.contains(callout)) {
      selectedCallout = callout;
      selectedCallout.classList.add('is-selected');
      return;
    }
    if (tableWrap && editor.contains(tableWrap)) {
      selectedTableWrap = tableWrap;
      selectedTableWrap.classList.add('is-selected');
      selectedTableCell = tableCell || null;
      updateSelectedTableCellVisual();
      ensureTableToolState(selectedTableWrap);
      updateTableResizeHandles(selectedTableWrap);
      return;
    }
    const codeBlock = event.target.closest('.proc-code-block');
    clearSelectedCodeBlock();
    if (codeBlock && editor.contains(codeBlock)) {
      selectedCodeBlock = codeBlock;
      selectedCodeBlock.classList.add('is-selected');
      const codeEl = selectedCodeBlock.querySelector('code[data-lang]');
      if (codeEl && codeLanguage) {
        codeLanguage.value = (selectedCodeBlock.classList.contains('is-editing')
          ? (codeEl.getAttribute('data-lang') || 'text')
          : getCodeSavedLanguage(codeEl)).toLowerCase();
      }
    }
  });
  editor.addEventListener('paste', (event) => {
    const editingTableCell = event.target.closest('.proc-table-wrap.is-editing td, .proc-table-wrap.is-editing th');
    const editingCodeEl = event.target.closest('.proc-code-block.is-editing code[data-lang]');
    const files = Array.from(event.clipboardData?.files || []);
    const html = event.clipboardData?.getData('text/html') || '';
    const text = event.clipboardData?.getData('text/plain') || '';

    if (editingCodeEl) {
      event.preventDefault();
      let codePaste = text;
      if (codePaste === '' && html.trim() !== '') {
        const htmlPaste = document.createElement('div');
        htmlPaste.innerHTML = html.replace(/<br\s*\/?>/gi, '\n');
        codePaste = htmlPaste.innerText || htmlPaste.textContent || '';
      }
      document.execCommand('insertText', false, codePaste);
      setCodeRawText(editingCodeEl, extractCodePlainText(editingCodeEl));
      window.setTimeout(() => {
        setCodeRawText(editingCodeEl, extractCodePlainText(editingCodeEl));
        syncContent();
      }, 0);
      return;
    }

    if (editingTableCell && files.length === 0) {
      event.preventDefault();
      selectedTableCell = editingTableCell;
      if (html.trim() !== '' && /<table[\s>]/i.test(html) && pasteTableHtmlGrid(editingTableCell, html)) {
        return;
      }
      if (text !== '') {
        pasteTableGrid(editingTableCell, text);
        return;
      }
      if (html.trim() !== '') {
        const cleanHtml = sanitizePastedHtml(html);
        if (cleanHtml) {
          editingTableCell.innerHTML = cleanHtml;
          applyTableCellPlaceholders(editingTableCell.closest('table'));
          syncContent();
        }
        return;
      }
    }

    if (files.length === 0 && html.trim() !== '') {
      event.preventDefault();
      clearSelectedCodeBlock();
      clearSelectedImageWrap();
      clearSelectedTableWrap();
      pasteRichHtml(html);
      return;
    }

    clearSelectedCodeBlock();
    clearSelectedImageWrap();
    clearSelectedTableWrap();
    if (files.length === 0) {
      if (text !== '') {
        event.preventDefault();
        pasteRichHtml(plainTextToPasteHtml(text));
        return;
      }
      window.setTimeout(syncContent, 0);
      return;
    }
    event.preventDefault();
    files.forEach((file) => readImageFile(file, editingTableCell || getActiveEditingTableCell()));
  });

  editor.addEventListener('mouseup', saveEditorSelection);
  editor.addEventListener('keyup', saveEditorSelection);
  editor.addEventListener('focusin', saveEditorSelection);
  editor.addEventListener('mousedown', (event) => {
    const tableControl = event.target.closest('.proc-table-tools button');
    if (tableControl) {
      event.preventDefault();
    }
  });
  editor.addEventListener('mousedown', (event) => {
    const tableControl = event.target.closest('.proc-table-tools .proc-table-style');
    if (!tableControl) {
      return;
    }
    const wrap = tableControl.closest('.proc-table-wrap');
    if (!wrap) {
      return;
    }
    selectedTableWrap = wrap;
    selectedTableWrap.classList.add('is-selected');
    ensureTableToolState(wrap);
  });
  editor.addEventListener('change', (event) => {
    const styleSelect = event.target.closest('.proc-table-style');
    const control = styleSelect;
    if (control) {
      applyTableToolbarControl(control);
    }
  });
  editor.addEventListener('input', (event) => {
    const styleSelect = event.target.closest('.proc-table-style');
    const control = styleSelect;
    if (control) {
      applyTableToolbarControl(control);
      return;
    }
    if (event.target.closest('code[data-lang]')) {
      const codeEl = event.target.closest('code[data-lang]');
      const block = codeEl?.closest('.proc-code-block');
      if (!block?.classList.contains('is-editing')) {
        return;
      }
      setCodeRawText(codeEl, extractCodePlainText(codeEl));
      const clone = editor.cloneNode(true);
      clone.querySelectorAll('pre.proc-code-block').forEach((blockClone) => {
        const codeClone = blockClone.querySelector('code[data-lang]');
        if (!codeClone) return;
        codeClone.textContent = getCodeSavedText(codeClone);
      });
      hiddenContent.value = clone.innerHTML.trim();
      return;
    }
    syncContent();
  });

  editor.addEventListener('focusin', (event) => {
    const tableCell = event.target.closest('.proc-table-wrap.is-editing td, .proc-table-wrap.is-editing th');
    if (tableCell) {
      selectedTableCell = tableCell;
      const wrap = tableCell.closest('.proc-table-wrap');
      if (wrap) {
        selectedTableWrap = wrap;
        selectedTableWrap.classList.add('is-selected');
        updateSelectedTableCellVisual();
        ensureTableToolState(wrap);
        updateTableResizeHandles(wrap);
      }
    }
    const codeEl = event.target.closest('code[data-lang]');
    if (!codeEl) return;
    const block = codeEl.closest('.proc-code-block');
    if (!block?.classList.contains('is-editing')) return;
    codeEl.textContent = getCodeRawText(codeEl);
  });

  editor.addEventListener('focusout', (event) => {
    const codeEl = event.target.closest('code[data-lang]');
    if (!codeEl) return;
    const block = codeEl.closest('.proc-code-block');
    if (!block?.classList.contains('is-editing')) return;
    setCodeRawText(codeEl, extractCodePlainText(codeEl));
  });

  editor.addEventListener('mousedown', (event) => {
    const imageHandle = event.target.closest('.proc-image-drag');
    if (imageHandle) {
      const wrap = imageHandle.closest('.proc-image-wrap');
      if (!wrap) return;
      const layout = wrap.closest('.proc-side-layout') || wrap;
      event.preventDefault();
      startSortableDrag(layout, wrap, 'image', event);
      return;
    }
    const codeHandle = event.target.closest('.proc-code-drag');
    if (codeHandle) {
      const block = codeHandle.closest('.proc-code-block');
      if (!block) return;
      const layout = block.closest('.proc-side-layout') || block;
      event.preventDefault();
      startSortableDrag(layout, block, 'code', event);
      return;
    }
    const tableHandle = event.target.closest('.proc-table-drag');
    if (tableHandle) {
      const wrap = tableHandle.closest('.proc-table-wrap');
      if (!wrap) return;
      const layout = wrap.closest('.proc-side-layout') || wrap;
      event.preventDefault();
      startSortableDrag(layout, wrap, 'table', event);
      return;
    }
    const calloutHandle = event.target.closest('.proc-callout-drag');
    if (calloutHandle) {
      const callout = calloutHandle.closest('.proc-callout');
      if (!callout) return;
      event.preventDefault();
      startSortableDrag(callout, callout, 'callout', event);
    }
  });

  editor.addEventListener('mousedown', (event) => {
    const resizeHandle = event.target.closest('.proc-image-resize');
    if (!resizeHandle) return;
    const wrap = resizeHandle.closest('.proc-image-wrap');
    const img = wrap?.querySelector('img');
    if (!wrap || !img) return;
    event.preventDefault();
    resizingImageWrap = wrap;
    resizeStartX = event.clientX;
    resizeStartWidth = img.getBoundingClientRect().width;
  });
  editor.addEventListener('mousedown', (event) => {
    const resizeHandle = event.target.closest('.proc-table-resize');
    if (!resizeHandle) return;
    const wrap = resizeHandle.closest('.proc-table-wrap');
    const scroll = wrap?.querySelector('.proc-table-scroll');
    const table = wrap?.querySelector('table');
    if (!wrap || !scroll || !table) return;
    event.preventDefault();
    resizingTableWrap = wrap;
    resizeStartX = event.clientX;
    resizeStartY = event.clientY;
    resizeStartWidth = table.getBoundingClientRect().width;
    resizeStartHeight = scroll.getBoundingClientRect().height;
  });
  editor.addEventListener('mousedown', (event) => {
    const resizeHandle = event.target.closest('.proc-table-col-resize-handle');
    if (!resizeHandle) return;
    const wrap = resizeHandle.closest('.proc-table-wrap');
    if (!wrap || !selectedTableCell || !wrap.contains(selectedTableCell)) return;
    event.preventDefault();
    resizingTableColumnWrap = wrap;
    resizeStartX = event.clientX;
  });
  editor.addEventListener('mousedown', (event) => {
    const resizeHandle = event.target.closest('.proc-table-row-resize-handle');
    if (!resizeHandle) return;
    const wrap = resizeHandle.closest('.proc-table-wrap');
    if (!wrap || !selectedTableCell || !wrap.contains(selectedTableCell)) return;
    event.preventDefault();
    resizingTableRowWrap = wrap;
    resizeStartY = event.clientY;
  });
  editor.addEventListener('mousedown', (event) => {
    const resizeHandle = event.target.closest('.proc-code-resize');
    if (!resizeHandle) return;
    const block = resizeHandle.closest('.proc-code-block');
    if (!block) return;
    event.preventDefault();
    resizingCodeBlock = block;
    resizeStartX = event.clientX;
    resizeStartWidth = block.getBoundingClientRect().width;
  });
  editor.addEventListener('mousedown', (event) => {
    const resizeHandle = event.target.closest('.proc-callout-resize');
    if (!resizeHandle) return;
    const callout = resizeHandle.closest('.proc-callout');
    if (!callout) return;
    event.preventDefault();
    resizingCallout = callout;
    resizeStartX = event.clientX;
    resizeStartWidth = callout.getBoundingClientRect().width;
  });

  document.addEventListener('mousemove', (event) => {
    moveSortableNode(event);
    if (draggingSortNode && (draggingSortType === 'image' || draggingSortType === 'table' || draggingSortType === 'code' || draggingSortType === 'callout')) {
      updateSortableHorizontalIndicator(draggingSortNode, event.clientX);
    }
    if (resizingImageWrap) {
      const img = resizingImageWrap.querySelector('img');
      if (!img) return;
      const delta = event.clientX - resizeStartX;
      const newWidth = Math.max(120, resizeStartWidth + delta);
      img.style.width = `${newWidth}px`;
      img.style.maxWidth = 'none';
      syncImageWrapSize(resizingImageWrap);
      return;
    }
    if (resizingTableWrap) {
      const scroll = resizingTableWrap.querySelector('.proc-table-scroll');
      const table = resizingTableWrap.querySelector('table');
      if (!scroll || !table) return;
      const deltaX = event.clientX - resizeStartX;
      const deltaY = event.clientY - resizeStartY;
      const newWidth = Math.max(420, resizeStartWidth + deltaX);
      const newHeight = Math.max(170, resizeStartHeight + deltaY);
      setTableHorizontalSize(resizingTableWrap, newWidth);
      scroll.style.minHeight = `${newHeight}px`;
      return;
    }
    if (resizingTableColumnWrap) {
      const deltaX = event.clientX - resizeStartX;
      if (Math.abs(deltaX) >= 4) {
        adjustSelectedColumnWidth(resizingTableColumnWrap, deltaX);
        resizeStartX = event.clientX;
      }
      return;
    }
    if (resizingTableRowWrap) {
      const deltaY = event.clientY - resizeStartY;
      if (Math.abs(deltaY) >= 4) {
        adjustSelectedRowHeight(resizingTableRowWrap, deltaY);
        resizeStartY = event.clientY;
      }
      return;
    }
    if (resizingCodeBlock) {
      const deltaX = event.clientX - resizeStartX;
      const newWidth = Math.max(320, resizeStartWidth + deltaX);
      setCodeBlockWidth(resizingCodeBlock, newWidth);
      return;
    }
    if (resizingCallout) {
      const deltaX = event.clientX - resizeStartX;
      const newWidth = Math.max(320, resizeStartWidth + deltaX);
      setCalloutWidth(resizingCallout, newWidth);
    }
  });

  document.addEventListener('mouseup', (event) => {
    stopSortableDrag(event);
    if (resizingImageWrap) {
      const img = resizingImageWrap.querySelector('img');
      if (img) {
        const width = Math.round(img.getBoundingClientRect().width);
        img.setAttribute('width', String(width));
        img.style.width = `${width}px`;
        img.style.maxWidth = 'none';
        syncImageWrapSize(resizingImageWrap);
      }
      resizingImageWrap = null;
      syncContent();
      return;
    }
    if (resizingTableWrap) {
      const scroll = resizingTableWrap.querySelector('.proc-table-scroll');
      const table = resizingTableWrap.querySelector('table');
      if (scroll && table) {
        const width = Math.round(table.getBoundingClientRect().width);
        const height = Math.round(scroll.getBoundingClientRect().height);
        setTableHorizontalSize(resizingTableWrap, width);
        scroll.style.minHeight = `${height}px`;
      }
      resizingTableWrap = null;
      syncContent();
      return;
    }
    if (resizingTableColumnWrap) {
      const wrap = resizingTableColumnWrap;
      resizingTableColumnWrap = null;
      updateTableResizeHandles(wrap);
      syncContent();
      return;
    }
    if (resizingTableRowWrap) {
      const wrap = resizingTableRowWrap;
      resizingTableRowWrap = null;
      updateTableResizeHandles(wrap);
      syncContent();
      return;
    }
    if (resizingCodeBlock) {
      const block = resizingCodeBlock;
      const width = Math.round(block.getBoundingClientRect().width);
      setCodeBlockWidth(block, width);
      resizingCodeBlock = null;
      syncContent();
      return;
    }
    if (resizingCallout) {
      const callout = resizingCallout;
      const width = Math.round(callout.getBoundingClientRect().width);
      setCalloutWidth(callout, width);
      resizingCallout = null;
      syncContent();
    }
  });
  editor.addEventListener('keydown', (event) => {
    const cell = event.target.closest('.proc-table-wrap.is-editing td, .proc-table-wrap.is-editing th');
    if (!cell) {
      return;
    }
    selectedTableCell = cell;
    const wrap = cell.closest('.proc-table-wrap');
    updateSelectedTableCellVisual();
    updateTableResizeHandles(wrap);
    if (event.key === 'Enter' && event.altKey) {
      event.preventDefault();
      document.execCommand('insertHTML', false, '<br>');
      saveEditorSelection();
      syncContent();
      return;
    }

    if (event.key === 'Tab') {
      event.preventDefault();
      focusTableCellByDelta(cell, 0, event.shiftKey ? -1 : 1, { extendBody: true });
      return;
    }

    if (event.key === 'Enter') {
      event.preventDefault();
      focusTableCellByDelta(cell, 1, 0, { extendBody: true });
      return;
    }

    if (event.ctrlKey && !event.altKey && !event.shiftKey) {
      if (event.key === 'ArrowRight') {
        event.preventDefault();
        focusTableCellByDelta(cell, 0, 1, { extendBody: false });
        return;
      }
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        focusTableCellByDelta(cell, 0, -1, { extendBody: false });
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        focusTableCellByDelta(cell, 1, 0, { extendBody: true });
        return;
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        focusTableCellByDelta(cell, -1, 0, { extendBody: false });
        return;
      }
    }
  });

  ['dragenter', 'dragover'].forEach((type) => {
    dropzone?.addEventListener(type, (event) => {
      event.preventDefault();
      dropzone.classList.add('border-primary');
    });
  });
  ['dragleave', 'drop'].forEach((type) => {
    dropzone?.addEventListener(type, (event) => {
      event.preventDefault();
      dropzone.classList.remove('border-primary');
    });
  });
  dropzone?.addEventListener('drop', (event) => {
    Array.from(event.dataTransfer?.files || []).forEach(readImageFile);
  });

  form.addEventListener('submit', syncContent);
  document.addEventListener('click', (event) => {
    if (!editor.contains(event.target) && event.target !== codeLanguage && event.target !== insertCodeBtn) {
      clearSelectedCodeBlock();
      clearSelectedImageWrap();
      clearSelectedTableWrap();
    }
  });

  window.addEventListener('resize', () => {
    editor.querySelectorAll('.proc-table-wrap').forEach((wrap) => {
      updateTableResponsiveState(wrap);
      updateTableResizeHandles(wrap);
    });
    normalizeFlowAwayFromPageDivisions(editor);
    updateFreePositionCanvas(editor);
  });

  updatePageGuides();
  prepareImages();
  prepareTables();
  prepareCodeBlocks();
  prepareCallouts();
  normalizeFlowAwayFromPageDivisions(editor);
  updateFreePositionCanvas(editor);
  syncContent();

})();
</script>
</body>
</html>
