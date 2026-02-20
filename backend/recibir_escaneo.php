<?php
/**
 * Endpoint para recibir extintores desde la app Android
 *
 * POST /belga/recibir_escaneo.php
 * Body: {"url": "https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp?..."}
 * Response: {"success": true/false, "message": "..."}
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido. Use POST.']);
    exit;
}

// Leer body JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['url'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Falta el campo "url" en el body JSON.']);
    exit;
}

$url = trim($input['url']);

// Validar que la URL sea del sistema AGC
if (stripos($url, 'agcontrol.gob.ar/matafuegos') === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'URL no valida. Debe ser del sistema AGC de matafuegos.'
    ]);
    exit;
}

// Normalizar URL: forzar HTTPS, quitar puerto :80 si existe
$url = str_replace('http://', 'https://', $url);
$url = preg_replace('/:80\//', '/', $url);

try {
    $db = getDB();

    // Verificar duplicados
    $check = $db->prepare("SELECT id, estado FROM extintores WHERE url = :url LIMIT 1");
    $check->execute([':url' => $url]);
    $existente = $check->fetch();

    if ($existente) {
        echo json_encode([
            'success' => true,
            'message' => 'URL ya registrada previamente.',
            'duplicado' => true,
            'estado' => $existente['estado']
        ]);
        exit;
    }

    // Insertar nuevo escaneo
    $stmt = $db->prepare(
        "INSERT INTO extintores (url, fecha_escaneo, estado, intentos_sync)
         VALUES (:url, NOW(), 'pendiente', 0)"
    );
    $stmt->execute([':url' => $url]);

    echo json_encode([
        'success' => true,
        'message' => 'Escaneo registrado correctamente.',
        'url' => $url
    ]);

} catch (PDOException $e) {
    error_log("recibir_escaneo.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.'
    ]);
}
