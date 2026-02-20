<?php
/**
 * Endpoint para recibir controles periodicos desde la app Android
 *
 * POST /belga/recibir_control_periodico.php
 * Body: {"url": "...", "estado_carga": "Cargado", "chapa_baliza": "ABC", "comentario": "..."}
 * Response: {"success": true/false, "message": "..."}
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['url']) || empty($input['estado_carga']) || empty($input['chapa_baliza'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos: url, estado_carga, chapa_baliza.']);
    exit;
}

$url = trim($input['url']);
$estadoCarga = trim($input['estado_carga']);
$chapaBaliza = trim($input['chapa_baliza']);
$comentario = isset($input['comentario']) && $input['comentario'] !== null ? trim($input['comentario']) : null;

// Normalizar URL
$url = str_replace('http://', 'https://', $url);
$url = preg_replace('/:80\//', '/', $url);

// Validar estado_carga
$estadosValidos = ['Cargado', 'Descargado', 'Sobrecargado'];
if (!in_array($estadoCarga, $estadosValidos)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'estado_carga invalido. Valores: Cargado, Descargado, Sobrecargado.']);
    exit;
}

try {
    $db = getDB();

    // Buscar extintor por URL
    $check = $db->prepare("SELECT id FROM extintores WHERE url = :url LIMIT 1");
    $check->execute([':url' => $url]);
    $extintor = $check->fetch();

    if (!$extintor) {
        echo json_encode(['success' => false, 'message' => 'Extintor no encontrado. Debe escanearse primero.']);
        exit;
    }

    // Insertar control periodico
    $stmt = $db->prepare(
        "INSERT INTO controles_periodicos (extintor_id, fecha_control, estado_carga, chapa_baliza, comentario, origen)
         VALUES (:eid, NOW(), :estado, :chapa, :comentario, 'app_android')"
    );
    $stmt->execute([
        ':eid' => $extintor['id'],
        ':estado' => $estadoCarga,
        ':chapa' => $chapaBaliza,
        ':comentario' => $comentario,
    ]);

    // Contar total de controles para este extintor
    $countStmt = $db->prepare("SELECT COUNT(*) FROM controles_periodicos WHERE extintor_id = :eid");
    $countStmt->execute([':eid' => $extintor['id']]);
    $totalControles = (int)$countStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => "Control periodico #$totalControles registrado.",
        'total_controles' => $totalControles,
    ]);

} catch (PDOException $e) {
    error_log("recibir_control_periodico.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor.']);
}
