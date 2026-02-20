<?php
/**
 * Configuracion centralizada de base de datos
 * Escaneo de Matafuegos - HST SRL
 *
 * COPIAR este archivo como config.php y completar las credenciales.
 * config.php esta en .gitignore (no se sube al repo).
 */

// ====== CONFIGURAR ESTAS CREDENCIALES ======
define('DB_HOST', 'localhost');
define('DB_NAME', 'COMPLETAR_NOMBRE_BD');
define('DB_USER', 'COMPLETAR_USUARIO');
define('DB_PASS', 'COMPLETAR_PASSWORD');
// ============================================

define('AGC_DOMAIN', 'dghpsh.agcontrol.gob.ar');
define('AGC_BASE_URL', 'https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp');
define('MAX_SYNC_BATCH', 20);
define('MAX_REINTENTOS', 3);

/**
 * Obtiene conexion PDO singleton a MySQL
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES    => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Error de conexion a base de datos']));
        }
    }
    return $pdo;
}

/**
 * Habilitar CORS para la app Android
 */
function setCorsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}
