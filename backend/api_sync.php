<?php
/**
 * Endpoint AJAX para sincronizar pendientes
 *
 * GET /belga/api_sync.php
 * Response: {"ok": N, "fail": N, "total": N}
 *
 * Llamado por el visor via JavaScript para sincronizar sin recargar la pagina.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sincronizar.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

try {
    $resultado = sincronizarPendientes();
    echo json_encode($resultado);
} catch (Exception $e) {
    error_log("api_sync.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => 0,
        'fail'  => 0,
        'total' => 0,
        'error' => $e->getMessage()
    ]);
}
