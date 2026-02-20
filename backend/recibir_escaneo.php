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
$nro_orden = isset($input['nro_orden']) && $input['nro_orden'] !== null ? trim($input['nro_orden']) : null;

// Normalizar URL PRIMERO: forzar HTTPS, quitar puerto :80 si existe
$url = str_replace('http://', 'https://', $url);
$url = preg_replace('/:80\//', '/', $url);

// Validar que la URL sea del sistema AGC
if (stripos($url, 'agcontrol.gob.ar') === false || stripos($url, 'matafuegos') === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'URL no valida. Debe ser del sistema AGC de matafuegos.'
    ]);
    exit;
}

try {
    $db = getDB();

    // Verificar duplicados
    $check = $db->prepare("SELECT id, estado, nro_orden FROM extintores WHERE url = :url LIMIT 1");
    $check->execute([':url' => $url]);
    $existente = $check->fetch();

    if ($existente) {
        // Si se envia nro_orden y el extintor no tiene uno, actualizarlo
        if ($nro_orden && empty($existente['nro_orden'])) {
            $updateOrden = $db->prepare("UPDATE extintores SET nro_orden = :nro WHERE id = :id");
            $updateOrden->execute([':nro' => $nro_orden, ':id' => $existente['id']]);
        }

        // Registrar re-escaneo en historial
        $historial = $db->prepare(
            "INSERT INTO historial_escaneos (extintor_id, fecha_escaneo, origen)
             VALUES (:eid, NOW(), 'app_android')"
        );
        $historial->execute([':eid' => $existente['id']]);

        // Contar total de escaneos de este extintor
        $countStmt = $db->prepare("SELECT COUNT(*) FROM historial_escaneos WHERE extintor_id = :eid");
        $countStmt->execute([':eid' => $existente['id']]);
        $totalEscaneos = (int)$countStmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'message' => "Extintor re-escaneado. Escaneo #$totalEscaneos registrado.",
            'duplicado' => true,
            'estado' => $existente['estado'],
            'escaneos_total' => $totalEscaneos
        ]);
        exit;
    }

    // Insertar nuevo escaneo
    $stmt = $db->prepare(
        "INSERT INTO extintores (url, fecha_escaneo, estado, intentos_sync, nro_orden)
         VALUES (:url, NOW(), 'pendiente', 0, :nro_orden)"
    );
    $stmt->execute([':url' => $url, ':nro_orden' => $nro_orden]);
    $nuevoId = (int)$db->lastInsertId();

    // Registrar primer escaneo en historial
    $historial = $db->prepare(
        "INSERT INTO historial_escaneos (extintor_id, fecha_escaneo, origen)
         VALUES (:eid, NOW(), 'app_android')"
    );
    $historial->execute([':eid' => $nuevoId]);

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
