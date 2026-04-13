<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine/login.php');
if (!auth_can('estadisticas_manual')) {
  header('Location: /redmine/views/Dashboard/dashboard.php');
  exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$activeNav = 'estadisticas_api';

// Rutas
$cfgPath   = __DIR__ . '/../../data/configuracion.json';
$catPath   = __DIR__ . '/../../data/categorias.json';
$usersPath = __DIR__ . '/../../data/usuarios.json';
$unitPath  = __DIR__ . '/../../data/unidades.json';
$cachePath = __DIR__ . '/../../data/estadisticas_manual.json';

// Datos base
$cfg          = file_exists($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : [];
$categorias   = file_exists($catPath) ? (json_decode(file_get_contents($catPath), true) ?: []) : [];
$rawUnidades  = file_exists($unitPath) ? (json_decode(file_get_contents($unitPath), true) ?: []) : [];
$unidadesData = [];
foreach ($rawUnidades as $entry) {
  if (!is_array($entry)) continue;
  $name = trim($entry['nombre'] ?? '');
  if ($name === '') continue;
  $value = trim($entry['value'] ?? $entry['id'] ?? $name);
  if ($value === '') $value = $name;
  $unidadesData[] = ['nombre' => $name, 'value' => $value];
}
$cached       = file_exists($cachePath) ? (json_decode(file_get_contents($cachePath), true) ?: []) : [];
$statusTotals  = $cached['status_totals'] ?? [];
$priorityTotals = $cached['priority_totals'] ?? [];
$trackerTotals  = $cached['tracker_totals'] ?? [];

$issuesUrl = $cfg['platform_url'] ?? '';
$projectId = $cfg['project_id'] ?? null;
$apiKey    = $cfg['platform_token'] ?? '';
$cfUnitId  = $cfg['cf_unidad'] ?? ($cfg['cf_unidad_solicitante'] ?? null);
$dateField = in_array(trim($cfg['date_field'] ?? ''), ['start_date','due_date','created_on'], true) ? trim($cfg['date_field']) : 'start_date';
$trackers = $cfg['trackers'] ?? [];
$trackerScopeQuery = trim($_GET['tracker_scope'] ?? 'all');
$availableTrackerIds = array_values(array_unique(array_filter(array_map(fn($t) => (string)($t['id'] ?? ''), $trackers))));
$defaultTracker = (string)($cfg['tracker_id'] ?? '');
if ($trackerScopeQuery !== 'all' && $trackerScopeQuery !== '' && !in_array($trackerScopeQuery, $availableTrackerIds, true)) {
  $trackerScopeQuery = 'all';
}
$trackerSelection = $trackerScopeQuery;
$trackerFilter = $trackerSelection === 'all' ? null : $trackerSelection;
$priorities = $cfg['prioridades'] ?? [];
$priorityScopeQuery = trim($_GET['priority_scope'] ?? '');
$availablePriorityIds = array_values(array_unique(array_filter(array_map(fn($p) => (string)($p['id'] ?? ''), $priorities))));
$priorityDefault = (string)($cfg['priority_id'] ?? '');
$prioritySelection = 'all';
$priorityFilter = null;
if ($priorityScopeQuery === '' && $priorityDefault !== '' && in_array($priorityDefault, $availablePriorityIds, true)) {
  $prioritySelection = $priorityDefault;
  $priorityFilter = $priorityDefault;
} elseif ($priorityScopeQuery === 'all' || $priorityScopeQuery === '') {
  $prioritySelection = 'all';
  $priorityFilter = null;
} elseif (in_array($priorityScopeQuery, $availablePriorityIds, true)) {
  $prioritySelection = $priorityScopeQuery;
  $priorityFilter = $priorityScopeQuery;
}
$statusOverrides = $cfg['stats_status_ids'] ?? [];
$statusScopeQuery = trim($_GET['status_scope'] ?? '');
$allowedScopes = ['open', 'closed', 'all'];
$statusScopeSelection = '';
if (!in_array($statusScopeQuery, $allowedScopes, true)) {
  $statusScopeQuery = '';
}
if ($statusScopeQuery !== '') {
  $statusFilter = $statusScopeQuery;
  $statusScopeSelection = $statusScopeQuery;
} elseif (!empty($statusOverrides) && is_array($statusOverrides)) {
  $statusFilter = $statusOverrides;
  $statusScopeSelection = 'all';
} else {
  $statusFilter = 'open';
  $statusScopeSelection = 'open';
}

// Token del usuario (tiene prioridad)
$uid = auth_get_user_id();
if ($uid && file_exists($usersPath)) {
  $users = json_decode(file_get_contents($usersPath), true) ?: [];
  foreach ($users as $u) {
    if (!is_array($u)) continue;
    if ((string)($u['id'] ?? '') === (string)$uid && !empty($u['api'])) {
      $apiKey = $u['api'];
      break;
    }
  }
}

// Parámetros
$customStart    = $_GET['start'] ?? '';
$customEnd      = $_GET['end'] ?? '';
$doFetchRequest = isset($_GET['fetch']);
$savingCats     = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cats']));

// Si se envía post de selección, conservar el rango
if ($savingCats) {
  $customStart = $_POST['start'] ?? $customStart;
  $customEnd   = $_POST['end'] ?? $customEnd;
}

$error = null;
if (!$issuesUrl || !$projectId) $error = 'Falta platform_url o project_id en configuración.';
if ($doFetchRequest && !$apiKey) $error = 'Falta token API (usuario o plataforma).';
if ($doFetchRequest && (!$customStart || !$customEnd)) $error = 'Selecciona un rango de fechas.';
if ($doFetchRequest && !$cfUnitId) $error = 'Falta CF de unidad (cf_unidad o cf_unidad_solicitante) en configuración.';

// Helpers de Redmine
function build_status_parts($status) {
  $parts = [];
  if ($status === null || $status === false) return $parts;
  if (is_array($status)) {
    $values = array_values(array_filter(array_map(fn($item) => trim((string)$item), $status), fn($item) => $item !== ''));
    if (empty($values)) return $parts;
    $parts[] = 'status_id=' . urlencode(implode('|', array_unique($values)));
    return $parts;
  }
  $map = [
    'open' => 'o',
    'closed' => 'c',
    'all' => '*',
    'o' => 'o',
    'c' => 'c',
    '*' => '*'
  ];
  $value = trim((string)$status);
  $key = strtolower($value);
  if (isset($map[$key])) {
    $parts[] = 'status_id=' . urlencode($map[$key]);
    return $parts;
  }
  if ($value === '') return $parts;
  $values = array_values(array_filter(array_map('trim', explode(',', $value)), fn($item) => $item !== ''));
  if (!empty($values)) {
    $parts[] = 'status_id=' . urlencode(implode('|', array_unique($values)));
    return $parts;
  }
  $parts[] = 'status_id=' . urlencode($value);
  return $parts;
}

function redmine_get_total($issuesUrl, $apiKey, $projectId, $categoryId, $start, $end, $status = null, $trackerId = null, $priorityId = null, $dateField = 'created_on') {
  if (!$issuesUrl || !$apiKey || !$start || !$end) return null;
  $parts = array_merge(build_status_parts($status), ['limit=1']);
  if ($projectId) $parts[] = 'project_id=' . urlencode($projectId);
  if ($categoryId !== '' && $categoryId !== null) $parts[] = 'category_id=' . urlencode($categoryId);
  if ($trackerId !== null && $trackerId !== '') {
    $parts[] = 'tracker_id=' . urlencode($trackerId);
  }
  if ($priorityId !== null && $priorityId !== '') {
    $parts[] = 'priority_id=' . urlencode($priorityId);
  }
  $parts[] = $dateField . '=%3E%3C' . $start . '|' . $end;
  $url = $issuesUrl . (str_contains($issuesUrl, '?') ? '&' : '?') . implode('&', $parts);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'X-Redmine-API-Key: ' . $apiKey,
      'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) { curl_close($ch); return null; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code >= 400) return null;
  $json = json_decode($resp, true);
  return $json['total_count'] ?? null;
}

// Obtener posibles valores del CF de unidades (para coincidir con los nombres reales)
function normalize_cf_value($value) {
  if (!is_array($value)) {
    $text = trim((string)$value);
    if ($text === '') return null;
    return ['nombre' => $text, 'value' => $text];
  }
  $nombre = trim($value['label'] ?? $value['value'] ?? $value['id'] ?? '');
  $valor  = trim($value['value'] ?? $value['id'] ?? $nombre);
  if ($nombre === '' && $valor === '') return null;
  if ($nombre === '') $nombre = $valor;
  if ($valor === '') $valor = $nombre;
  return ['nombre' => $nombre, 'value' => $valor];
}

function redmine_get_unidades_cf($url, $apiKey, $cfId = null) {
  if (!$url || !$apiKey) return [];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'X-Redmine-API-Key: ' . $apiKey,
      'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) { curl_close($ch); return []; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code >= 400) return [];
  $json = json_decode($resp, true);
  if (!$json) return [];

  $values = [];
  if (isset($json['custom_fields']) && is_array($json['custom_fields'])) {
    foreach ($json['custom_fields'] as $cf) {
      if ($cfId && (int)($cf['id'] ?? 0) !== (int)$cfId) continue;
      if (!empty($cf['possible_values'])) {
        foreach ($cf['possible_values'] as $v) {
          $normalized = normalize_cf_value($v);
          if ($normalized) $values[] = $normalized;
        }
        break;
      }
    }
    if (empty($values)) {
      foreach ($json['custom_fields'] as $cf) {
        if (empty($cf['possible_values'])) continue;
        foreach ($cf['possible_values'] as $v) {
          $normalized = normalize_cf_value($v);
          if ($normalized) $values[] = $normalized;
        }
        break;
      }
    }
  }
  if (empty($values) && isset($json['possible_values'])) {
    foreach ($json['possible_values'] as $v) {
      $normalized = normalize_cf_value($v);
      if ($normalized) $values[] = $normalized;
    }
  }

  return array_values(array_reduce($values, function($carry, $item) {
    if (is_array($item) && isset($item['nombre'])) {
      $key = mb_strtolower($item['nombre'], 'UTF-8');
      if (!isset($carry[$key])) {
        $carry[$key] = $item;
      }
    }
    return $carry;
  }, []));
}

function normalize_value_key($value) {
  $text = trim((string)$value);
  if ($text === '') return '';
  return mb_strtoupper($text, 'UTF-8');
}

function normalize_relation_label($item, $fallback = '') {
  if (is_array($item)) {
    foreach (['name', 'nombre', 'value'] as $key) {
      if (isset($item[$key])) {
        $val = trim((string)$item[$key]);
        if ($val !== '') {
          return $val;
        }
      }
    }
  }
  $text = trim((string)$item);
  if ($text !== '') return $text;
  return $fallback;
}

function format_unit_label($key) {
  $text = trim((string)$key);
  if ($text === '') return '';
  return mb_convert_case(mb_strtolower($text, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function redmine_get_issue_counts_by_cf($issuesUrl, $apiKey, $projectId, $cfId, $start, $end, $status = null, $trackerId = null, $priorityId = null, $dateField = 'created_on') {
  if (!$issuesUrl || !$apiKey || !$cfId || !$start || !$end) return [];
  $counts = [];
  $limit = 100;
  $offset = 0;
  $total = null;
  do {
    $statusParts = build_status_parts($status);
    $parts = $statusParts ?: ['status_id=*'];
    $parts = array_merge($parts, [
      'limit=' . $limit,
      'offset=' . $offset,
      $dateField . '=%3E%3C' . $start . '|' . $end,
    ]);
    if ($projectId) $parts[] = 'project_id=' . urlencode($projectId);
    if ($trackerId !== null && $trackerId !== '') {
      $parts[] = 'tracker_id=' . urlencode($trackerId);
    }
    if ($priorityId !== null && $priorityId !== '') {
      $parts[] = 'priority_id=' . urlencode($priorityId);
    }
    $url = $issuesUrl . (str_contains($issuesUrl, '?') ? '&' : '?') . implode('&', $parts);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'X-Redmine-API-Key: ' . $apiKey,
        'Accept: application/json'
      ],
      CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { curl_close($ch); break; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) break;
    $json = json_decode($resp, true);
    if (!is_array($json)) break;
    $total = $json['total_count'] ?? $total;
    $issues = $json['issues'] ?? [];
    foreach ($issues as $issue) {
      foreach ($issue['custom_fields'] ?? [] as $cf) {
        if ((int)($cf['id'] ?? 0) !== (int)$cfId) continue;
        $value = trim((string)($cf['value'] ?? ''));
        if ($value === '') continue;
        $key = normalize_value_key($value);
        if ($key === '') continue;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
      }
    }
    $offset += $limit;
  } while ($total === null || $offset < $total);

  return $counts;
}

function redmine_fetch_issues($issuesUrl, $apiKey, $projectId, $start, $end, $status = null, $trackerId = null, $priorityId = null, $dateField = 'created_on', $limit = 100) {
  if (!$issuesUrl || !$apiKey || !$start || !$end) return [];
  $issues = [];
  $offset = 0;
  $total = null;
  $limit = max(1, min(250, (int)$limit));
  do {
    $parts = build_status_parts($status);
    if (empty($parts)) {
      $parts[] = 'status_id=*';
    }
    $parts = array_merge($parts, [
      'limit=' . $limit,
      'offset=' . $offset,
      $dateField . '=%3E%3C' . $start . '|' . $end,
    ]);
    if ($projectId) $parts[] = 'project_id=' . urlencode($projectId);
    if ($trackerId !== null && $trackerId !== '') {
      $parts[] = 'tracker_id=' . urlencode($trackerId);
    }
    if ($priorityId !== null && $priorityId !== '') {
      $parts[] = 'priority_id=' . urlencode($priorityId);
    }
    $url = $issuesUrl . (str_contains($issuesUrl, '?') ? '&' : '?') . implode('&', $parts);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'X-Redmine-API-Key: ' . $apiKey,
        'Accept: application/json'
      ],
      CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { curl_close($ch); break; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) break;
    $json = json_decode($resp, true);
    if (!is_array($json)) break;
    $total = $json['total_count'] ?? $total;
    $pageIssues = $json['issues'] ?? [];
    if (is_array($pageIssues) && !empty($pageIssues)) {
      foreach ($pageIssues as $issue) {
        $issues[] = $issue;
      }
    }
    $offset += $limit;
  } while ($total === null || $offset < $total);

  return $issues;
}

function count_issues_in_range($issues, $dateField, $rangeStart, $rangeEnd) {
  if (!$rangeStart || !$rangeEnd) return 0;
  $startTs = strtotime($rangeStart);
  $endTs = strtotime($rangeEnd);
  if ($startTs === false || $endTs === false) return 0;
  $count = 0;
  foreach ($issues as $issue) {
    $value = trim((string)($issue[$dateField] ?? ''));
    if ($value === '') continue;
    $issueTs = strtotime($value);
    if ($issueTs === false) continue;
    if ($issueTs >= $startTs && $issueTs <= $endTs) {
      $count++;
    }
  }
  return $count;
}

// Datos iniciales desde cache
$tabla          = $cached['tabla'] ?? [];
$tablaUnidades  = $cached['tabla_unidades'] ?? [];
$totalGlobal    = $cached['total'] ?? 0;
$totalUnidades  = $cached['total_unidades'] ?? 0;
$selectedCats   = $cached['selected_cats'] ?? [];
$selectedSum    = $cached['selected_sum'] ?? 0;
$quick2m        = $cached['quick_2m'] ?? 0;
$quick6m        = $cached['quick_6m'] ?? 0;
$quick12m       = $cached['quick_12m'] ?? 0;
$customStart    = $customStart ?: ($cached['start'] ?? '');
$customEnd      = $customEnd   ?: ($cached['end'] ?? '');

// Refrescar/mergar unidades desde la API del CF si está configurado
$unidadesUrl = $cfg['unidades_url'] ?? '';
if ($unidadesUrl && $apiKey) {
  $apiUnidades = redmine_get_unidades_cf($unidadesUrl, $apiKey, $cfUnitId);
  if (!empty($apiUnidades)) {
    // mezclar valores conocidos + API, preservando los nombres oficiales y evitando duplicados sin importar mayúsculas/minúsculas
    $seen = [];
    $merged = [];
    $sourceList = array_merge($apiUnidades, $unidadesData);
    foreach ($sourceList as $u) {
      $name = trim($u['nombre'] ?? '');
      if ($name === '') continue;
      $value = trim($u['value'] ?? $u['id'] ?? $name);
      if ($value === '') $value = $name;
      $key = mb_strtolower($name, 'UTF-8');
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $merged[] = ['nombre' => $name, 'value' => $value];
    }
    $unidadesData = $merged;
  }
}

// Fetch manual
if ($doFetchRequest && !$error) {
  $tabla = [];
  $tablaUnidades = [];
  $totalGlobal = 0;
  $totalUnidades = 0;
  $statusTotals = [];
  $priorityTotals = [];
  $trackerTotals = [];

  $issues = redmine_fetch_issues($issuesUrl, $apiKey, $projectId, $customStart, $customEnd, $statusFilter, $trackerFilter, $priorityFilter, $dateField);
  $totalGlobal = count($issues);

  $categoryTotals = [];
  $categoryMap = [];
  foreach ($categorias as $cat) {
    $catId = (string)($cat['id'] ?? '');
    if ($catId !== '') {
      $categoryMap[$catId] = $cat['nombre'] ?? '';
    }
  }
  foreach ($issues as $issue) {
    $statusLabel = normalize_relation_label($issue['status'], 'Sin estado');
    $priorityLabel = normalize_relation_label($issue['priority'], 'Sin prioridad');
    $trackerLabel = normalize_relation_label($issue['tracker'], 'Sin tracker');
    $statusTotals[$statusLabel] = ($statusTotals[$statusLabel] ?? 0) + 1;
    $priorityTotals[$priorityLabel] = ($priorityTotals[$priorityLabel] ?? 0) + 1;
    $trackerTotals[$trackerLabel] = ($trackerTotals[$trackerLabel] ?? 0) + 1;
    $category = $issue['category'] ?? null;
    $catId = '';
    $catName = '';
    if (is_array($category)) {
      $catId = (string)($category['id'] ?? '');
      $catName = trim((string)($category['name'] ?? ''));
    }
    if ($catName === '' && $catId !== '' && isset($categoryMap[$catId])) {
      $catName = $categoryMap[$catId];
    }
    if ($catName === '') {
      $catName = 'Sin categoría';
    }
    $key = $catId !== '' ? "cat_{$catId}" : 'cat_none';
    if (!isset($categoryTotals[$key])) {
      $categoryTotals[$key] = [
        'id' => $catId !== '' ? $catId : 'none',
        'nombre' => $catName,
        'total' => 0,
      ];
    }
    $categoryTotals[$key]['nombre'] = $catName;
    $categoryTotals[$key]['total'] += 1;
  }

  $seenCats = [];
  foreach ($categorias as $cat) {
    $catId = (string)($cat['id'] ?? '');
    if ($catId === '') continue;
    $key = "cat_{$catId}";
    if (!isset($categoryTotals[$key])) continue;
    $entry = $categoryTotals[$key];
    if ((int)($entry['total'] ?? 0) <= 0) continue;
    $tabla[] = [
      'id' => $catId,
      'nombre' => $cat['nombre'] ?? $entry['nombre'],
      'total' => $entry['total'],
    ];
    $seenCats[$key] = true;
  }
  $extraCats = [];
  foreach ($categoryTotals as $key => $entry) {
    if (isset($seenCats[$key]) || $key === 'cat_none') continue;
    $extraCats[] = $entry;
  }
  usort($extraCats, fn($a, $b) => (int)($b['total'] ?? 0) <=> (int)($a['total'] ?? 0));
  foreach ($extraCats as $entry) {
    if ((int)($entry['total'] ?? 0) <= 0) continue;
    $tabla[] = [
      'id' => $entry['id'] ?? '',
      'nombre' => $entry['nombre'] ?? 'Sin categoría',
      'total' => $entry['total'],
    ];
  }
  if (isset($categoryTotals['cat_none']) && (int)($categoryTotals['cat_none']['total'] ?? 0) > 0) {
    $tabla[] = [
      'id' => 'none',
      'nombre' => $categoryTotals['cat_none']['nombre'] ?: 'Sin categoría',
      'total' => $categoryTotals['cat_none']['total'],
    ];
  }

  $knownUnits = [];
  foreach ($unidadesData as $uni) {
    $name = trim($uni['nombre'] ?? '');
    $value = trim($uni['value'] ?? $name);
    if ($name === '' && $value === '') continue;
    $key = normalize_value_key($value ?: $name);
    if ($key === '') continue;
    if (!isset($knownUnits[$key])) {
      $knownUnits[$key] = ['nombre' => $name ?: ($value ?: format_unit_label($key)), 'value' => $value ?: $name];
    }
  }

  $unitCounts = [];
  if ($cfUnitId) {
    foreach ($issues as $issue) {
      foreach ($issue['custom_fields'] ?? [] as $cf) {
        if ((int)($cf['id'] ?? 0) !== (int)$cfUnitId) continue;
        $value = trim((string)($cf['value'] ?? ''));
        if ($value === '') continue;
        $key = normalize_value_key($value);
        if ($key === '') continue;
        $unitCounts[$key] = ($unitCounts[$key] ?? 0) + 1;
      }
    }
  }
  foreach ($unitCounts as $key => $count) {
    if ($count <= 0) continue;
    $label = $knownUnits[$key]['nombre'] ?? format_unit_label($key);
    if ($label === '') $label = $key;
    $tablaUnidades[] = ['nombre' => $label, 'total' => $count];
    $totalUnidades += $count;
  }
  usort($tablaUnidades, fn($a, $b) => (int)($b['total'] ?? 0) <=> (int)($a['total'] ?? 0));

  $endRef = $customEnd ?: date('Y-m-d');
  $startRange = $customStart ?: $endRef;
  $calcStart = fn($base, $months) => date('Y-m-d', strtotime($base . " -{$months} months"));
  $maxDate = function($a, $b) {
    return strtotime($a) > strtotime($b) ? $a : $b;
  };
  $start2  = $maxDate($calcStart($endRef, 2),  $startRange);
  $start6  = $maxDate($calcStart($endRef, 6),  $startRange);
  $start12 = $maxDate($calcStart($endRef, 12), $startRange);

  $quick2m = count_issues_in_range($issues, $dateField, $start2, $endRef);
  $quick6m = count_issues_in_range($issues, $dateField, $start6, $endRef);
  $quick12m = $totalGlobal ?: count_issues_in_range($issues, $dateField, $startRange, $endRef);

  // Por defecto, todas seleccionadas
  $selectedCats = $tabla;
  $selectedSum  = array_sum(array_map(fn($c) => (int)($c['total'] ?? 0), $selectedCats));

  $payload = [
    'start'          => $customStart,
    'end'            => $customEnd,
    'total'          => $totalGlobal,
    'tabla'          => $tabla,
    'tabla_unidades' => $tablaUnidades,
    'total_unidades' => $totalUnidades,
    'selected_cats'  => $selectedCats,
    'selected_sum'   => $selectedSum,
    'quick_2m'       => $quick2m,
    'quick_6m'       => $quick6m,
    'quick_12m'      => $quick12m,
    'status_totals'  => $statusTotals,
    'priority_totals'=> $priorityTotals,
    'tracker_totals' => $trackerTotals,
    'actualizado'    => date('c')
  ];
  clearstatcache(true, $cachePath);
  file_put_contents($cachePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Guardar selección de categorías
if ($savingCats && !$error) {
  $selNames = $_POST['cat_sel'] ?? [];
  $selSet   = array_flip($selNames);
  $selectedCats = [];
  $selectedSum  = 0;
  foreach ($tabla as $cat) {
    if (isset($selSet[$cat['nombre'] ?? ''])) {
      $selectedCats[] = $cat;
      $selectedSum   += (int)($cat['total'] ?? 0);
    }
  }
  $cached['start']          = $customStart;
  $cached['end']            = $customEnd;
  $cached['tabla']          = $tabla;
  $cached['tabla_unidades'] = $tablaUnidades;
  $cached['total']          = $totalGlobal;
  $cached['total_unidades'] = $totalUnidades;
  $cached['selected_cats']  = $selectedCats;
  $cached['selected_sum']   = $selectedSum;
  $cached['quick_2m']       = $quick2m;
  $cached['quick_6m']       = $quick6m;
  $cached['quick_12m']      = $quick12m;
  $cached['status_totals']  = $statusTotals;
  $cached['priority_totals']= $priorityTotals;
  $cached['tracker_totals'] = $trackerTotals;
  $cached['actualizado']    = date('c');
  clearstatcache(true, $cachePath);
  file_put_contents($cachePath, json_encode($cached, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Si no hay selección previa, mantener todo desmarcado por defecto
if (empty($selectedCats)) {
  $selectedSum = 0;
}

$statusTop = $statusTotals;
if (!empty($statusTop)) {
  arsort($statusTop);
  $statusTop = array_slice($statusTop, 0, 3, true);
}
$priorityTop = $priorityTotals;
if (!empty($priorityTop)) {
  arsort($priorityTop);
  $priorityTop = array_slice($priorityTop, 0, 3, true);
}
$trackerTop = $trackerTotals;
if (!empty($trackerTop)) {
  arsort($trackerTop);
  $trackerTop = array_slice($trackerTop, 0, 3, true);
}

// Vista
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Estadísticas (manual)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/redmine/assets/theme.css" rel="stylesheet">
  <style>
    .stat-card { border: none; box-shadow: 0 10px 24px rgba(0,0,0,0.08); border-radius: 14px; cursor: default; }
    .clickable-card { cursor: pointer; transition: transform .08s ease, box-shadow .08s ease; }
    .clickable-card:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(0,0,0,0.12); }
    .stat-value { font-size: 2.6rem; font-weight: 700; }
    .chart-panel {
      position: relative;
      cursor: pointer;
      min-height: 320px;
      background: transparent;
    }
    .chart-panel canvas {
      width: 100% !important;
      height: 100% !important;
    }
  #loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    z-index: 2050;
    display: none;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
    transition: opacity .3s ease;
    pointer-events: none;
  }
  #loading-overlay.active { display: flex; pointer-events: auto; }
    .loading-panel {
      width: min(440px, calc(100% - 32px));
      background: #fff;
      border-radius: 20px;
      padding: 32px 28px;
      box-shadow: 0 32px 60px rgba(15, 23, 42, 0.25);
      text-align: center;
    }
    .loading-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .15rem .75rem;
      border-radius: 999px;
      font-size: .68rem;
      font-weight: 600;
      letter-spacing: .2em;
      text-transform: uppercase;
      color: #475569;
      border: 1px solid rgba(79, 70, 229, 0.4);
      margin-bottom: .5rem;
      background: rgba(79, 70, 229, 0.05);
    }
    .loading-title {
      font-size: 1.45rem;
      font-weight: 600;
      color: #111827;
      margin-bottom: .25rem;
    }
    .loading-subtitle {
      font-size: .95rem;
      color: #475569;
      margin-bottom: 1.5rem;
    }
    .loading-progress {
      position: relative;
      height: 14px;
      border-radius: 999px;
      background: rgba(79, 70, 229, 0.1);
      overflow: hidden;
      margin-bottom: 1rem;
    }
    .loading-progress::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(90deg, rgba(79, 70, 229, 0.2), transparent, rgba(14, 165, 233, 0.25));
      animation: shimmer 1.6s ease-in-out infinite;
    }
    .loading-progress-bar {
      position: relative;
      width: 0;
      height: 100%;
      background: linear-gradient(120deg, #4f46e5, #0ea5e9);
      border-radius: inherit;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      padding-right: 10px;
      color: #fff;
      font-weight: 600;
      font-size: .75rem;
      letter-spacing: .03em;
      text-shadow: 0 1px 2px rgba(0, 0, 0, .4);
      transition: width 0.4s ease;
    }
    .loading-progress-bar span {
      display: inline-block;
    }
    .loading-hint {
      display: flex;
      justify-content: center;
      gap: .4rem;
      margin-top: .5rem;
      color: #6b7280;
      font-size: .85rem;
    }
    .loading-hint span {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: rgba(79, 70, 229, 0.8);
      animation: loadingDots 1.6s ease-in-out infinite;
    }
    .loading-hint span:nth-child(2) { animation-delay: .2s; }
    .loading-hint span:nth-child(3) { animation-delay: .4s; }
    @keyframes shimmer {
      from { transform: translateX(-100%); }
      to { transform: translateX(100%); }
    }
    @keyframes progressPulse {
      0%, 100% { transform: scaleX(0.98); }
      50% { transform: scaleX(1.02); }
    }
    @keyframes loadingDots {
      0% { opacity: .3; transform: translateY(0); }
      50% { opacity: 1; transform: translateY(-4px); }
      100% { opacity: .3; transform: translateY(0); }
    }
  </style>
</head>
<body class="bg-light">
<div class="modal fade" id="loading-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-transparent border-0">
      <div class="modal-body p-0">
        <div class="loading-panel m-0">
          <div class="loading-chip"><i class="bi bi-bar-chart-line"></i> Estadísticas manuales</div>
          <div class="loading-title">Actualizando las métricas</div>
          <div class="loading-subtitle">Consultando Redmine y reconstruyendo las tablas seleccionadas.</div>
          <div class="loading-progress" aria-hidden="true">
            <div class="loading-progress-bar"></div>
          </div>
          <div class="loading-hint">
            <span></span>
            <span></span>
            <span></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/navbar.php'; ?>
<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon    = 'bi-graph-up';
      $heroTitle   = 'Estadísticas (consulta manual)';
      $heroSubtitle= 'Consulta Redmine solo cuando presionas el botón de obtener datos.';
      ob_start(); ?>
      <form id="range-form" class="d-flex flex-wrap gap-2 align-items-end" method="get">
        <div>
          <label class="form-label text-white-50 mb-1">Desde</label>
          <input type="date" class="form-control form-control-sm" name="start" id="range-start" value="<?= $h($customStart) ?>">
        </div>
        <div>
          <label class="form-label text-white-50 mb-1">Hasta</label>
          <input type="date" class="form-control form-control-sm" name="end" id="range-end" value="<?= $h($customEnd) ?>">
        </div>
        <div>
          <label class="form-label text-white-50 mb-1">Estado</label>
          <select class="form-select form-select-sm" name="status_scope">
            <option value="open"<?= $statusScopeSelection === 'open' ? ' selected' : '' ?>>Abiertos</option>
            <option value="closed"<?= $statusScopeSelection === 'closed' ? ' selected' : '' ?>>Cerrados</option>
            <option value="all"<?= $statusScopeSelection === 'all' ? ' selected' : '' ?>>Todos</option>
          </select>
        </div>
        <div>
          <label class="form-label text-white-50 mb-1">Tipo</label>
          <select class="form-select form-select-sm" name="tracker_scope">
            <option value="all"<?= $trackerSelection === 'all' ? ' selected' : '' ?>>Todos</option>
            <?php foreach ($trackers as $tracker):
              $trackerId = (string)($tracker['id'] ?? '');
              if ($trackerId === '') continue;
              $trackerName = $tracker['nombre'] ?? $trackerId;
            ?>
            <option value="<?= $h($trackerId) ?>"<?= $trackerSelection === $trackerId ? ' selected' : '' ?>><?= $h($trackerName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label text-white-50 mb-1">Prioridad</label>
          <select class="form-select form-select-sm" name="priority_scope">
            <option value="all"<?= $prioritySelection === 'all' ? ' selected' : '' ?>>Todos</option>
            <?php foreach ($priorities as $priority):
              $priorityId = (string)($priority['id'] ?? '');
              if ($priorityId === '') continue;
              $priorityName = $priority['nombre'] ?? $priorityId;
            ?>
            <option value="<?= $h($priorityId) ?>"<?= $prioritySelection === $priorityId ? ' selected' : '' ?>><?= $h($priorityName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <input type="hidden" name="fetch" value="1">
          <button class="btn btn-sm btn-outline-light mt-2" id="btn-fetch"><i class="bi bi-arrow-repeat"></i> Obtener datos</button>
        </div>
        <div class="w-100"></div>
        <div class="text-warning small ms-1 mt-1" id="range-error" style="display:none;">Selecciona fecha desde y hasta.</div>
      </form>
      <?php
      $heroExtras = ob_get_clean();
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($error): ?>
      <div class="alert alert-danger mb-3"><?= $h($error) ?></div>
    <?php elseif (!$doFetchRequest && empty($tabla)): ?>
      <div class="card">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
          <div>
            <h5 class="mb-1">Consultar datos</h5>
            <div class="text-muted">Define rango de fechas y presiona el botón. No se consulta nada hasta que lo hagas.</div>
          </div>
          <button class="btn btn-primary" onclick="document.querySelector('#range-form').submit();"><i class="bi bi-arrow-repeat"></i> Obtener datos</button>
        </div>
      </div>
    <?php else: ?>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card stat-card clickable-card h-100" data-bs-toggle="modal" data-bs-target="#catModal">
            <div class="card-body">
              <div class="text-muted small mb-1">Tickets en rango</div>
              <div class="stat-value text-primary"><?= $h($totalGlobal) ?></div>
              <div class="text-muted">Detalle por categoría (click para ver).</div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card stat-card clickable-card h-100" data-bs-toggle="modal" data-bs-target="#uniModal">
            <div class="card-body">
              <div class="text-muted small mb-1">Unidades en rango</div>
              <div class="stat-value text-info"><?= $h($totalUnidades) ?></div>
              <div class="text-muted">Detalle por unidad (click para ver).</div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-xl-6">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <span class="fw-semibold">Totales rápidos</span>
              <span class="text-muted small">Consultan directamente a Redmine</span>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-sm-4">
                  <div class="p-3 rounded bg-primary bg-opacity-10 h-100">
                    <div class="text-muted small">Últimos 2 meses</div>
                    <div class="fw-bold fs-4 text-primary"><?= $h($quick2m ?? 0) ?></div>
                  </div>
                </div>
                <div class="col-sm-4">
                  <div class="p-3 rounded bg-info bg-opacity-10 h-100">
                    <div class="text-muted small">Últimos 6 meses</div>
                    <div class="fw-bold fs-4 text-info"><?= $h($quick6m ?? 0) ?></div>
                  </div>
                </div>
                <div class="col-sm-4">
                  <div class="p-3 rounded bg-success bg-opacity-10 h-100">
                    <div class="text-muted small">Último año</div>
                    <div class="fw-bold fs-4 text-success"><?= $h($quick12m ?? 0) ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="card h-100 clickable-card" data-bs-toggle="modal" data-bs-target="#selCatModal">
            <div class="card-header d-flex align-items-center justify-content-between">
              <span class="fw-semibold">Suma por categorías (selección)</span>
              <span class="text-muted small">Usa las categorías del rango actual</span>
            </div>
            <div class="card-body">
              <div class="mb-2 fw-bold">Total seleccionado: <span id="sum-cats" class="text-primary"><?= $h($selectedSum ?? 0) ?></span></div>
              <p class="text-muted small mb-2">Haz clic en la tarjeta para elegir categorías.</p>
              <div class="d-flex flex-wrap gap-1" id="selected-cats-preview">
                <?php
                  $preview = array_slice($selectedCats, 0, 6);
                  foreach ($preview as $cat):
                ?>
                  <span class="badge bg-light text-muted border"><?= $h($cat['nombre'] ?? '') ?></span>
                <?php endforeach; ?>
                <?php
                  $rest = max(0, count($selectedCats) - count($preview));
                  if ($rest > 0): ?>
                  <span class="badge bg-secondary text-white">+<?= $rest ?> más</span>
                <?php endif; ?>
                <?php if (empty($selectedCats)): ?>
                  <span class="text-muted small">Sin selección.</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-xl-4">
          <div class="card h-100">
            <div class="card-header">
              <span class="fw-semibold">Desglose por estado</span>
            </div>
            <div class="card-body">
              <?php if (!empty($statusTop)): ?>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($statusTop as $state => $count): ?>
                    <li class="d-flex justify-content-between mb-1">
                      <span><?= $h($state) ?></span>
                      <span class="fw-semibold"><?= $h($count) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="text-muted small mb-0">Sin datos de estados.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card h-100">
            <div class="card-header">
              <span class="fw-semibold">Prioridades principales</span>
            </div>
            <div class="card-body">
              <?php if (!empty($priorityTop)): ?>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($priorityTop as $prio => $count): ?>
                    <li class="d-flex justify-content-between mb-1">
                      <span><?= $h($prio) ?></span>
                      <span class="fw-semibold"><?= $h($count) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="text-muted small mb-0">Sin datos de prioridad.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card h-100">
            <div class="card-header">
              <span class="fw-semibold">Trackers dominantes</span>
            </div>
            <div class="card-body">
              <?php if (!empty($trackerTop)): ?>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($trackerTop as $trk => $count): ?>
                    <li class="d-flex justify-content-between mb-1">
                      <span><?= $h($trk) ?></span>
                      <span class="fw-semibold"><?= $h($count) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="text-muted small mb-0">Sin datos de trackers.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3" id="chart-card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div>
            <span class="fw-semibold">Gráficos interactivos</span>
            <div class="text-muted small">Top 10 categorías y unidades. Haz clic en cada gráfico para ver todos los valores.</div>
          </div>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
              <label class="form-label mb-0 small text-muted" for="chart-sort">Ordenar:</label>
              <select id="chart-sort" class="form-select form-select-sm">
                <option value="original">Original</option>
                <option value="name" selected>Alfabético</option>
                <option value="desc">Cantidad (mayor a menor)</option>
                <option value="asc">Cantidad (menor a mayor)</option>
              </select>
            </div>
            <div class="form-check form-check-inline m-0">
              <input class="form-check-input" type="checkbox" id="chart-show-values" checked>
              <label class="form-check-label small" for="chart-show-values">Mostrar totales sobre barras</label>
            </div>
          </div>
        </div>
        <div class="card-body p-3">
          <div class="row g-3">
            <div class="col-lg-6 h-100">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <span class="fw-semibold">Categorías</span>
                  <div class="text-muted small">Top 10 en el rango</div>
                </div>
                <span class="text-muted small">Click para ver todas</span>
              </div>
              <div class="chart-panel border rounded-3 p-2" data-bs-toggle="modal" data-bs-target="#chartModal" role="button" aria-label="Ver gráfico completo de categorías">
                <canvas id="chart-cats"></canvas>
              </div>
            </div>
            <div class="col-lg-6 h-100">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <span class="fw-semibold">Unidades solicitantes</span>
                  <div class="text-muted small">Top 10 en el rango</div>
                </div>
                <span class="text-muted small">Click para ver todas</span>
              </div>
              <div class="chart-panel border rounded-3 p-2" data-bs-toggle="modal" data-bs-target="#chartUnitsModal" role="button" aria-label="Ver gráfico completo de unidades">
                <canvas id="chart-units"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal gráfico completo -->
      <div class="modal fade" id="chartModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Gráfico completo de categorías</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body" style="height: 85vh;">
            <canvas id="chart-cats-all"></canvas>
          </div>
          <div class="modal-footer">
            <small class="text-muted">Incluye todas las categorías con total &gt; 0 en el rango.</small>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="chartUnitsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Gráfico completo de unidades solicitantes</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body" style="height: 85vh;">
            <canvas id="chart-units-all"></canvas>
          </div>
          <div class="modal-footer">
            <small class="text-muted">Incluye todas las unidades con tickets dentro del rango seleccionado.</small>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

      <?php
        $topCats = $tabla;
        usort($topCats, fn($a,$b)=> (int)($b['total']??0) <=> (int)($a['total']??0));
        $topCats = array_slice($topCats, 0, 10);
        $topPreview = array_slice($topCats, 0, 2);
        $topSum = array_sum(array_map(fn($c)=> (int)($c['total']??0), $topCats));
      ?>
      <div class="row g-3 mb-3">
        <div class="col-xl-6">
          <div class="card h-100 clickable-card" data-bs-toggle="modal" data-bs-target="#top10Modal">
            <div class="card-header d-flex align-items-center justify-content-between">
              <span class="fw-semibold">Top 10 categorías</span>
              <div class="d-flex align-items-center gap-2 text-muted small">
                <span>Mayor número de tickets en el rango</span>
                <span class="badge bg-primary-subtle text-primary border"><?= $h($topSum) ?></span>
              </div>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light"><tr><th style="width:50px;">#</th><th>Categoría</th><th class="text-end">Total</th></tr></thead>
                  <tbody>
                    <?php if (empty($topPreview)): ?>
                      <tr><td colspan="3" class="text-center text-muted">Sin datos</td></tr>
                    <?php else: foreach ($topPreview as $i => $cat): ?>
                      <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><?= $h($cat['nombre'] ?? '') ?></td>
                        <td class="text-end fw-semibold"><?= $h($cat['total'] ?? 0) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
              <?php if (count($topCats) > 2): ?>
                <div class="text-muted small mt-2">Click para ver el top 10 completo.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal selección de categorías -->
      <div class="modal fade" id="selCatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <form class="modal-content" method="post" id="sel-cat-form">
            <div class="modal-header">
              <h5 class="modal-title">Seleccionar categorías</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="save_cats" value="1">
              <input type="hidden" name="start" value="<?= $h($customStart) ?>">
              <input type="hidden" name="end" value="<?= $h($customEnd) ?>">
              <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" id="cat-select-all">
                  <label class="form-check-label" for="cat-select-all">Marcar/Desmarcar todas</label>
                </div>
                <div class="flex-grow-1">
                  <input type="text" class="form-control form-control-sm" id="search-cats" placeholder="Buscar categoría...">
                </div>
              </div>
              <div class="border rounded p-2" style="max-height:420px; overflow-y:auto;">
                <?php $selSet = array_flip(array_column($selectedCats ?? [], 'nombre')); ?>
                <?php foreach ($tabla as $i => $cat):
                  $checked = isset($selSet[$cat['nombre'] ?? '']);
                ?>
                  <div class="form-check cat-row" data-name="<?= $h(strtolower($cat['nombre'] ?? '')) ?>">
                    <input class="form-check-input cat-check" type="checkbox" id="selcat-<?= $i ?>" name="cat_sel[]" value="<?= $h($cat['nombre']) ?>" data-total="<?= $h($cat['total']) ?>" <?= $checked ? 'checked' : '' ?>>
                    <label class="form-check-label" for="selcat-<?= $i ?>"><?= $h($cat['nombre']) ?> (<?= $h($cat['total']) ?>)</label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-cats">Guardar selección</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Modal detalle categorías -->
      <div class="modal fade" id="catModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Categorías en rango</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div class="d-flex align-items-center gap-2 mb-3">
                <label class="form-label mb-0 small text-muted" for="cat-sort-modal">Ordenar:</label>
                <select id="cat-sort-modal" class="form-select form-select-sm" style="width:auto;">
                  <option value="original">Original</option>
                  <option value="name">Alfabético</option>
                  <option value="desc">Cantidad (mayor a menor)</option>
                  <option value="asc">Cantidad (menor a mayor)</option>
                </select>
              </div>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0" id="cat-table-modal">
                  <thead class="table-light"><tr><th style="width:60px;">#</th><th>Nombre</th><th class="text-end">Total</th></tr></thead>
                  <tbody id="cat-table-body">
                    <?php foreach ($tabla as $i => $cat): ?>
                      <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><?= $h($cat['nombre']) ?></td>
                        <td class="text-end"><?= $h($cat['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal detalle unidades -->
      <div class="modal fade" id="uniModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Unidades en rango</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-light"><tr><th style="width:60px;">#</th><th>Nombre</th><th class="text-end">Total</th></tr></thead>
                  <tbody>
                    <?php foreach ($tablaUnidades as $i => $u): ?>
                      <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><?= $h($u['nombre']) ?></td>
                        <td class="text-end"><?= $h($u['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal top 10 categorías -->
      <div class="modal fade" id="top10Modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Top 10 categorías</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-light"><tr><th style="width:60px;">#</th><th>Categoría</th><th class="text-end">Total</th></tr></thead>
                  <tbody>
                    <?php if (empty($topCats)): ?>
                      <tr><td colspan="3" class="text-center text-muted">Sin datos</td></tr>
                    <?php else: foreach ($topCats as $i => $cat): ?>
                      <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td><?= $h($cat['nombre']) ?></td>
                        <td class="text-end fw-semibold"><?= $h($cat['total']) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
          </div>
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  const loaderProgressBar = document.querySelector('.loading-progress-bar');
  const loadingModalElement = document.getElementById('loading-modal');
  const loadingModalInstance = loadingModalElement ? new bootstrap.Modal(loadingModalElement) : null;
  let loaderProgressInterval;

  const setLoaderProgress = (value) => {
    if (!loaderProgressBar) return;
    loaderProgressBar.style.width = `${Math.max(0, Math.min(100, value))}%`;
  };

  const startLoaderProgress = () => {
    if (loadingModalInstance) loadingModalInstance.show();
    setLoaderProgress(8);
    if (loaderProgressInterval) clearInterval(loaderProgressInterval);
    loaderProgressInterval = setInterval(() => {
      if (!loaderProgressBar) return;
      const current = Number(loaderProgressBar.style.width.replace('%', '')) || 0;
      const next = Math.min(95, current + (Math.random() * 6 + 2));
      setLoaderProgress(next);
    }, 400);
  };

  const stopLoaderProgress = () => {
    setLoaderProgress(100);
    if (loaderProgressInterval) {
      clearInterval(loaderProgressInterval);
      loaderProgressInterval = null;
    }
    if (loadingModalInstance) {
      setTimeout(() => loadingModalInstance.hide(), 120);
    }
  };

  const clearManualLoading = () => {
    stopLoaderProgress();
  };

  setTimeout(clearManualLoading, 2000);
  document.addEventListener('DOMContentLoaded', clearManualLoading);
  </script>
  <script src="/redmine/assets/js/chart.umd.min.js"></script>
  <script>
  if (typeof window !== 'undefined' && typeof Chart !== 'undefined' && !window.Chart) {
    window.Chart = Chart;
  }
  </script>
  <script>
  const ensureHeroIsAccessible = () => {
    stopLoaderProgress();
    document.querySelectorAll('.modal.show').forEach(modal => {
      if (modal.getAttribute('data-bs-backdrop') !== 'static') {
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        const bs = window.bootstrap?.Modal?.getInstance(modal);
        if (bs) bs.hide();
      }
    });
  };
  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(ensureHeroIsAccessible, 100);
  });
  </script>
<?php if (!empty($tabla) && !$error): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  if (typeof Chart === 'undefined') {
    console.warn('Chart.js no está disponible; los gráficos se omiten.');
    clearManualLoading();
    return;
  }

  const dataCatsBase = <?= json_encode($tabla, JSON_UNESCAPED_UNICODE) ?>;

  const dataUnitsBase = <?= json_encode($tablaUnidades, JSON_UNESCAPED_UNICODE) ?>;

  const chartConfigs = [

    {

      name: 'categorías',

      data: dataCatsBase,

      mainCanvas: document.getElementById('chart-cats'),

      modalCanvas: document.getElementById('chart-cats-all'),

      modalId: 'chartModal',

      label: 'Tickets en rango',

      color: '#4e73df',

      bgColor: 'rgba(78,115,223,0.12)',

      colorFull: '#1cc88a',

      bgFullColor: 'rgba(28,200,138,0.12)'

    },

    {

      name: 'unidades',

      data: dataUnitsBase,

      mainCanvas: document.getElementById('chart-units'),

      modalCanvas: document.getElementById('chart-units-all'),

      modalId: 'chartUnitsModal',

      label: 'Tickets por unidad',

      color: '#0ea5e9',

      bgColor: 'rgba(14,165,233,0.12)',

      colorFull: '#10b981',

      bgFullColor: 'rgba(16,185,129,0.12)'

    }

  ];

  const dataLabelPlugin = (typeof ChartDataLabels !== 'undefined') ? ChartDataLabels : null;

  const sortSelect = document.getElementById('chart-sort');

  if (sortSelect && !sortSelect.value) {
    sortSelect.value = 'name';
  }

  const showValuesCheck = document.getElementById('chart-show-values');

  const getCurrentMode = () => sortSelect ? sortSelect.value : 'original';
  const shouldShowValues = () => showValuesCheck ? showValuesCheck.checked : true;



  const getSortedData = (data, mode = 'original') => {

    const copy = [...data];

    if (mode === 'name') copy.sort((a, b) => (a.nombre || '').localeCompare(b.nombre || ''));

    else if (mode === 'desc') copy.sort((a, b) => (b.total || 0) - (a.total || 0));

    else if (mode === 'asc') copy.sort((a, b) => (a.total || 0) - (b.total || 0));

    return copy;

  };



  const renderChartForConfig = (cfg, limit = null, isFull = false) => {

    const ctx = isFull ? cfg.modalCanvas : cfg.mainCanvas;

    if (!ctx) return null;

    let dataset = getSortedData(cfg.data, getCurrentMode());

    if (limit) dataset = dataset.slice(0, limit);

    const labels = dataset.map(item => item.nombre || '');
    const values = dataset.map(item => Math.round(Number(item.total ?? 0)));
    const color = isFull ? cfg.colorFull : cfg.color;

    const bgColor = isFull ? cfg.bgFullColor : cfg.bgColor;

    return new Chart(ctx, {

      type: 'line',

      data: {

        labels,

        datasets: [{

          label: cfg.label,

          data: values,

          backgroundColor: bgColor,

          borderColor: color,

          borderWidth: 2,

          pointBackgroundColor: color,

          pointBorderColor: '#fff',

          pointBorderWidth: 2,

          pointRadius: 3,

          fill: true,

          tension: 0.35

        }]

      },

      options: {

        responsive: true,

        maintainAspectRatio: false,

        plugins: {

          legend: { display: false },

          datalabels: shouldShowValues() ? {

            anchor: 'end',

            align: 'end',

            color: '#111',

            font: { size: 10, weight: 'bold' },

            formatter: (value) => value

          } : false

        },

        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0, stepSize: 1 }
          }
        }
      },

      plugins: dataLabelPlugin ? [dataLabelPlugin] : []

    });

  };



  const refreshMainCharts = () => {

    chartConfigs.forEach(cfg => {

      if (!cfg.mainCanvas) return;

      cfg.mainChart?.destroy();

      cfg.mainChart = renderChartForConfig(cfg, 10, false);

    });

  };



  const handleChartControls = () => {

    refreshMainCharts();

  };



  if (sortSelect) sortSelect.addEventListener('change', handleChartControls);

  if (showValuesCheck) showValuesCheck.addEventListener('change', handleChartControls);



  handleChartControls();

  document.addEventListener('visibilitychange', () => {

    if (document.visibilityState === 'visible') handleChartControls();

  });

  setTimeout(handleChartControls, 200);



  chartConfigs.forEach(cfg => {

    if (!cfg.modalId || !cfg.modalCanvas) return;

    const modalEl = document.getElementById(cfg.modalId);

    if (!modalEl) return;

    modalEl.addEventListener('shown.bs.modal', () => {

      cfg.modalChart?.destroy();

      cfg.modalChart = renderChartForConfig(cfg, null, true);

    });

  });



  // Ordenar tabla del modal de categorías

  const catSortModal = document.getElementById('cat-sort-modal');

  const catTableBody = document.getElementById('cat-table-body');

  const renderCatTable = (mode = 'original') => {

    if (!catTableBody) return;

    let data = [...dataCatsBase];

    if (mode === 'name') data.sort((a,b)=> (a.nombre||'').localeCompare(b.nombre||''));

    else if (mode === 'desc') data.sort((a,b)=> (b.total||0) - (a.total||0));

    else if (mode === 'asc') data.sort((a,b)=> (a.total||0) - (b.total||0));

    catTableBody.innerHTML = '';

    data.forEach((cat, idx) => {

      const tr = document.createElement('tr');

      tr.innerHTML = `

        <td class="text-muted">${idx+1}</td>

        <td>${cat.nombre || ''}</td>

        <td class="text-end">${cat.total ?? 0}</td>

      `;

      catTableBody.appendChild(tr);

    });

  };

  if (catSortModal) {

    catSortModal.addEventListener('change', () => renderCatTable(catSortModal.value));

    renderCatTable(catSortModal.value);

  }



  // Suma de categorías seleccionadas

  const sumSpan = document.getElementById('sum-cats');

  const preview = document.getElementById('selected-cats-preview');

  const checks = Array.from(document.querySelectorAll('.cat-check'));

  const selectAll = document.getElementById('cat-select-all');

  const searchInput = document.getElementById('search-cats');

  const rows = Array.from(document.querySelectorAll('.cat-row'));

  const formSelCats = document.getElementById('sel-cat-form');

  const btnSaveCats = document.getElementById('btn-save-cats');

  const rangeForm = document.getElementById('range-form');

  const btnFetch = document.getElementById('btn-fetch');

  const rangeStart = document.getElementById('range-start');

  const rangeEnd = document.getElementById('range-end');

  const rangeError = document.getElementById('range-error');



  const updatePreview = () => {

    if (!preview) return;

    preview.innerHTML = '';

    const selected = checks.filter(ch => ch.checked);

    selected.slice(0,6).forEach(ch => {

      const badge = document.createElement('span');

      badge.className = 'badge bg-light text-muted border';

      badge.textContent = ch.value;

      preview.appendChild(badge);

    });

    const rest = Math.max(0, selected.length - 6);

    if (rest > 0) {

      const more = document.createElement('span');

      more.className = 'badge bg-secondary text-white';

      more.textContent = `+${rest} más`;

      preview.appendChild(more);

    }

    if (selected.length === 0) {

      const txt = document.createElement('span');

      txt.className = 'text-muted small';

      txt.textContent = 'Sin selección.';

      preview.appendChild(txt);

    }

  };



  const syncSelectAll = () => {

    if (!selectAll) return;

    const allChecked = checks.length && checks.every(ch => ch.checked);

    const anyChecked = checks.some(ch => ch.checked);

    selectAll.indeterminate = anyChecked && !allChecked;

    selectAll.checked = allChecked;

  };



  const recalc = () => {

    const total = checks

      .filter(ch => ch.checked)

      .map(ch => Number(ch.dataset.total || 0))

      .reduce((a, b) => a + b, 0);

    if (sumSpan) sumSpan.textContent = total;

    syncSelectAll();

    updatePreview();

  };



  checks.forEach(ch => ch.addEventListener('change', recalc));



  if (selectAll) {

    selectAll.addEventListener('change', () => {

      checks.forEach(ch => { ch.checked = selectAll.checked; });

      recalc();

    });

  }



  if (searchInput) {

    searchInput.addEventListener('input', () => {

      const q = searchInput.value.trim().toLowerCase();

      rows.forEach(row => {

        const name = row.dataset.name || '';

        const labelText = (row.querySelector('label')?.textContent || '').toLowerCase();

        row.style.display = (name.includes(q) || labelText.includes(q)) ? '' : 'none';

      });

    });

  }



  recalc();



  // UX: deshabilitar botón mientras envía selección para evitar doble submit y dar feedback

  if (formSelCats && btnSaveCats) {

    formSelCats.addEventListener('submit', () => {

      btnSaveCats.disabled = true;

      btnSaveCats.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando...';

    });

  }



  // Validar rango y mostrar overlay al consultar

  const validateRange = () => {

    const ok = (rangeStart && rangeStart.value) && (rangeEnd && rangeEnd.value);

    if (btnFetch) btnFetch.disabled = !ok;

    if (rangeError) rangeError.style.display = ok ? 'none' : 'block';

    return ok;

  };

  if (rangeStart) rangeStart.addEventListener('input', validateRange);

  if (rangeEnd) rangeEnd.addEventListener('input', validateRange);

  validateRange();



  if (rangeForm) {

    rangeForm.addEventListener('submit', (e) => {

      if (!validateRange()) {

        e.preventDefault();

        return;

      }

      // Permite que el loader se pinte antes de recargar la página

      e.preventDefault();
      startLoaderProgress();

      setTimeout(() => rangeForm.submit(), 60);

    });

  }

});

</script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const fallbackForm = document.getElementById('range-form');
  if (!fallbackForm) return;
  fallbackForm.addEventListener('submit', () => {
    const startVal = document.getElementById('range-start')?.value;
    const endVal = document.getElementById('range-end')?.value;
    if (!startVal || !endVal) return;
    startLoaderProgress();
  });
});
</script>
</body>
</html>
