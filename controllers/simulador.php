<?php
// Envia un payload de prueba al webhook FastAPI para simular la llegada de un mensaje

const DEFAULT_WEBHOOK_URL = 'http://localhost:8000/webhook';

function build_timestamp($fecha, $hora) {
    $fecha = trim($fecha ?? '');
    $hora = trim($hora ?? '');
    if ($fecha === '' && $hora === '') {
        return time();
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', "$fecha $hora");
    if ($dt === false) {
        return time();
    }
    return $dt->getTimestamp();
}

function send_to_webhook($url, $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("No se pudo contactar el webhook: $err");
    }
    curl_close($ch);
    return [$httpCode, $response];
}

function handle_simulador() {
    $defaultUrl = getenv('WEBHOOK_URL') ?: DEFAULT_WEBHOOK_URL;
    $result = null;
    $error = null;
    $payload = null;
    $webhookUrl = $defaultUrl;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $webhookUrl = trim($_POST['webhook_url'] ?? $defaultUrl) ?: $defaultUrl;
        $numero = trim($_POST['numero'] ?? '');
        $mensaje = trim($_POST['mensaje'] ?? '');
        $fecha = trim($_POST['fecha'] ?? '');
        $hora = trim($_POST['hora'] ?? '');

        if ($numero === '' || $mensaje === '') {
            $error = 'El numero y mensaje son obligatorios';
        } else {
            $timestamp = build_timestamp($fecha, $hora);
            $payload = [
                'message' => [
                    'from' => $numero,
                    'text' => $mensaje,
                    'timestamp' => $timestamp,
                ],
                'from' => $numero,
            ];
            try {
                [$code, $body] = send_to_webhook($webhookUrl, $payload);
                $result = "HTTP $code - " . ($body !== '' ? $body : 'Sin cuerpo');
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

    return [$webhookUrl, $result, $error, $payload];
}
?>
