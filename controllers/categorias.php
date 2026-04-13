<?php
// Sincroniza categorías usando data/categorias.json y la API de Redmine
$DATA_FILE = __DIR__ . '/../data/categorias.json';
$CONFIG_FILE = __DIR__ . '/../data/configuracion.json';
$USERS_FILE = __DIR__ . '/../data/usuarios.json';

function ensure_cat_file($path) {
    if (!file_exists($path)) {
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
function load_categorias($path) {
    ensure_cat_file($path);
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) $data = [];
    foreach ($data as &$item) {
        if (!isset($item['id'])) { $item['id'] = uniqid('', true); }
        if (!isset($item['nombre'])) { $item['nombre'] = ''; }
    }
    return $data;
}
function save_categorias($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function categorias_api_url($platformUrl) {
    if (!$platformUrl) return '';
    if (preg_match('#/issues\\.json$#', $platformUrl)) {
        return preg_replace('#/issues\\.json$#', '/issue_categories.json', $platformUrl);
    }
    $parts = parse_url($platformUrl);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port . '/issue_categories.json';
}

function user_api_token_fallback($usersFile) {
    if (!function_exists('auth_get_user_id')) return '';
    $uid = auth_get_user_id();
    if (!$uid || !file_exists($usersFile)) return '';
    $users = json_decode(file_get_contents($usersFile), true);
    if (!is_array($users)) return '';
    foreach ($users as $u) {
        if (!is_array($u)) continue;
        if ((string)($u['id'] ?? '') === (string)$uid && !empty($u['api'])) {
            return $u['api'];
        }
    }
    return '';
}


function sync_categorias_desde_api($configPath, $dataPath) {
    $cfg = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
    $platformUrl = $cfg['platform_url'] ?? '';
    $apiKey = $cfg['platform_token'] ?? '';
    $userToken = user_api_token_fallback($GLOBALS['USERS_FILE']);
    if (!$apiKey && $userToken) $apiKey = $userToken;
    $url = !empty($cfg['categories_url']) ? $cfg['categories_url'] : categorias_api_url($platformUrl);
    if (!$url) {
        return ['error' => 'Falta platform_url o categories_url en configuraci&oacute;n.'];
    }
    if (!$apiKey) {
        return ['error' => 'Falta token de API (plataforma o usuario).'];
    }
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
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => "No se pudo conectar: $err"];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        return ['error' => "HTTP $code al consultar issue_categories."];
    }
    $json = json_decode($resp, true);
    if (!isset($json['issue_categories']) || !is_array($json['issue_categories'])) {
        return ['error' => 'La respuesta no contiene issue_categories.'];
    }
    $cats = [];
    foreach ($json['issue_categories'] as $cat) {
        if (!is_array($cat)) continue;
        $cats[] = [
            'id' => (string)($cat['id'] ?? ''),
            'nombre' => $cat['name'] ?? ''
        ];
    }
    save_categorias($dataPath, $cats);
    return ['ok' => count($cats)];
}


function handle_categorias() {
    global $DATA_FILE, $CONFIG_FILE;
    $cats = load_categorias($DATA_FILE);
    $flash = null;
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $action = $_POST['action'] ?? '';
        if ($action === 'sync_remote') {
            $res = sync_categorias_desde_api($CONFIG_FILE, $DATA_FILE);
            if (isset($res['error'])) {
                $error = $res['error'];
            } else {
                $flash = 'Categorías actualizadas desde API (' . ($res['ok'] ?? 0) . ' registros)';
            }
        }
        $cats = load_categorias($DATA_FILE);
    }
    return [$cats, $flash, $error];
}
?>
