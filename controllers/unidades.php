<?php
// CRUD básico para unidades usando data/unidades.json
$DATA_FILE = __DIR__ . '/../data/unidades.json';

function ensure_uni_file($path) {
    if (!file_exists($path)) {
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
function load_unidades($path) {
    ensure_uni_file($path);
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) $data = [];
    $changed = false;
    foreach ($data as &$item) {
        if (!isset($item['id'])) { $item['id'] = uniqid('', true); $changed = true; }
        if (!isset($item['nombre'])) { $item['nombre'] = ''; $changed = true; }
    }
    if ($changed) save_unidades($path, $data);
    return $data;
}
function save_unidades($path, $data) {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function handle_unidades() {
    global $DATA_FILE;
    $rows = load_unidades($DATA_FILE);
    $flash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $rows[] = [
                'id' => trim($_POST['id'] ?? '') ?: uniqid('', true),
                'nombre' => trim($_POST['nombre'] ?? ''),
            ];
            save_unidades($DATA_FILE, $rows);
            $flash = 'Unidad creada';
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($rows as &$r) {
                if ($r['id'] === $id) {
                    $r['nombre'] = trim($_POST['nombre'] ?? $r['nombre']);
                    break;
                }
            }
            save_unidades($DATA_FILE, $rows);
            $flash = 'Unidad actualizada';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $rows = array_values(array_filter($rows, fn($r) => $r['id'] !== $id));
            save_unidades($DATA_FILE, $rows);
            $flash = 'Unidad eliminada';
        }
    }
    return [$rows, $flash];
}
?>

