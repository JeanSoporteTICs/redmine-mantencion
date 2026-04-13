<?php
// Controlador de estadísticas: fusiona reportes archivados, reportes vivos y horas extra
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../init_paths.php';

function normalize_date($str) {
    $str = trim((string)$str);
    if ($str === '') return '';
    // dd-mm-yyyy
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $str, $m)) {
        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }
    // yyyy-mm-dd
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str)) return $str;
    return '';
}

function load_report_messages($baseDir) {
    $messages = [];
    if (!is_dir($baseDir)) return $messages;
    $years = glob($baseDir . '/*', GLOB_ONLYDIR);
    foreach ($years as $yearDir) {
        $files = glob($yearDir . '/*.json');
        foreach ($files as $file) {
            $data = json_decode(@file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($data as $row) {
                    if (!is_array($row)) continue;
                    $row['_fuente'] = 'reportes';
                    $messages[] = $row;
                }
            }
        }
    }
    return $messages;
}

function load_live_messages($file) {
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data)) return [];
    foreach ($data as &$row) {
        if (is_array($row)) {
            $row['_fuente'] = 'mensajes';
        }
    }
    return $data;
}

function load_extra_messages($baseDir) {
    $messages = [];
    if (!is_dir($baseDir)) return $messages;
    $years = glob($baseDir . '/*', GLOB_ONLYDIR);
    foreach ($years as $yearDir) {
        $files = glob($yearDir . '/*.json');
        foreach ($files as $file) {
            $groups = json_decode(@file_get_contents($file), true);
            if (!is_array($groups)) continue;
            foreach ($groups as $group) {
                if (!isset($group['reports']) || !is_array($group['reports'])) continue;
                $fechaGrupo = $group['fecha'] ?? '';
                foreach ($group['reports'] as $rep) {
                    if (!is_array($rep)) continue;
                    $rep['fecha'] = $rep['fecha'] ?? $fechaGrupo;
                    $rep['_fuente'] = 'horas_extra';
                    $messages[] = $rep;
                }
            }
        }
    }
    return $messages;
}

function filter_messages($messages, $filters) {
    $result = [];
    $desde = $filters['desde'] ?? '';
    $hasta = $filters['hasta'] ?? '';
    $cat   = strtolower(trim($filters['categoria'] ?? ''));
    $unidad= strtolower(trim($filters['unidad'] ?? ''));
    $usuario = trim($filters['usuario'] ?? '');

    foreach ($messages as $msg) {
        if (!is_array($msg)) continue;
        $fechaRaw = $msg['fecha'] ?? ($msg['fecha_inicio'] ?? '');
        $fechaNorm = normalize_date($fechaRaw);
        if ($fechaNorm === '') continue;

        if ($desde && $fechaNorm < $desde) continue;
        if ($hasta && $fechaNorm > $hasta) continue;

        if ($cat !== '' && strtolower($msg['categoria'] ?? '') !== $cat) continue;
        $unidadMsg = strtolower($msg['unidad'] ?? ($msg['unidad_solicitante'] ?? ''));
        if ($unidad !== '' && $unidadMsg !== $unidad) continue;
        if ($usuario !== '' && (string)($msg['asignado_a'] ?? '') !== (string)$usuario) continue;

        $msg['_fecha_norm'] = $fechaNorm;
        $msg['_fecha_mes'] = substr($fechaNorm, 0, 7);
        $msg['_fuente'] = $msg['_fuente'] ?? 'reportes';
        $result[] = $msg;
    }
    return $result;
}

function compute_stats_from_messages($messages) {
    $stats = [
        'total' => 0,
        'por_usuario' => [],
        'por_categoria' => [],
        'por_unidad' => [],
        'por_estado' => [],
        'por_fecha' => [],
        'por_fecha_mes' => [],
        'msgs_por_usuario' => [],
        'msgs_por_categoria' => [],
        'msgs_por_unidad' => [],
        'actualizado' => date('Y-m-d H:i:s'),
    ];

    foreach ($messages as $msg) {
        $stats['total']++;
        $usuario = (string)($msg['asignado_a'] ?? '');
        $cat = (string)($msg['categoria'] ?? '');
        $unidad = (string)($msg['unidad'] ?? ($msg['unidad_solicitante'] ?? ''));
        $estado = (string)($msg['estado'] ?? '');
        $fecha = $msg['_fecha_norm'];
        $mes = $msg['_fecha_mes'];

        $stats['por_usuario'][$usuario] = ($stats['por_usuario'][$usuario] ?? 0) + 1;
        $stats['por_categoria'][$cat] = ($stats['por_categoria'][$cat] ?? 0) + 1;
        $stats['por_unidad'][$unidad] = ($stats['por_unidad'][$unidad] ?? 0) + 1;
        $stats['por_estado'][$estado] = ($stats['por_estado'][$estado] ?? 0) + 1;
        $stats['por_fecha'][$fecha] = ($stats['por_fecha'][$fecha] ?? 0) + 1;
        $stats['por_fecha_mes'][$mes] = ($stats['por_fecha_mes'][$mes] ?? 0) + 1;

        $stats['msgs_por_usuario'][$usuario][] = $msg;
        $stats['msgs_por_categoria'][$cat][] = $msg;
        $stats['msgs_por_unidad'][$unidad][] = $msg;
    }

    ksort($stats['por_fecha']);
    ksort($stats['por_fecha_mes']);
    arsort($stats['por_usuario']);
    arsort($stats['por_categoria']);
    arsort($stats['por_unidad']);
    arsort($stats['por_estado']);

    return $stats;
}

function handle_estadisticas() {
    if (!empty($_POST)) {
        csrf_validate();
    }

    $filters = [
        'desde' => '',
        'hasta' => '',
        'categoria' => $_POST['categoria'] ?? $_GET['categoria'] ?? '',
        'unidad' => $_POST['unidad'] ?? $_GET['unidad'] ?? '',
        'usuario' => $_POST['usuario'] ?? $_GET['usuario'] ?? '',
    ];

    $periodo = $_POST['periodo'] ?? $_GET['periodo'] ?? '';
    if ($periodo) {
        $periodo = trim($periodo);
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m)) {
            $filters['desde'] = sprintf('%s-%s-01', $m[1], $m[2]);
            $filters['hasta'] = sprintf('%s-%s-31', $m[1], $m[2]);
        } elseif (preg_match('/^(\d{4})$/', $periodo, $m)) {
            $filters['desde'] = $m[1] . '-01-01';
            $filters['hasta'] = $m[1] . '-12-31';
        }
    }

    $desde = normalize_date($_POST['desde'] ?? $_GET['desde'] ?? '');
    $hasta = normalize_date($_POST['hasta'] ?? $_GET['hasta'] ?? '');
    if ($desde) $filters['desde'] = $desde;
    if ($hasta) $filters['hasta'] = $hasta;

    $messages = [];
    $messages = array_merge($messages, load_report_messages(__DIR__ . '/../data/reportes'));
    $messages = array_merge($messages, load_live_messages(__DIR__ . '/../data/mensaje.json'));
    $messages = array_merge($messages, load_extra_messages(__DIR__ . '/../data/horasExtras'));

    $filtered = filter_messages($messages, $filters);
    return compute_stats_from_messages($filtered);
}
