<?php
/**
 * Script temporal para crear la tabla historial_escaneos.
 * Ejecutar una sola vez y luego eliminar.
 */
require_once __DIR__ . '/config.php';

$db = getDB();

// Crear tabla historial_escaneos
$db->exec("
    CREATE TABLE IF NOT EXISTS historial_escaneos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        extintor_id INT NOT NULL,
        fecha_escaneo DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        origen VARCHAR(50) DEFAULT 'app_android',
        FOREIGN KEY (extintor_id) REFERENCES extintores(id) ON DELETE CASCADE,
        INDEX idx_extintor_id (extintor_id),
        INDEX idx_fecha (fecha_escaneo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "Tabla historial_escaneos creada OK.\n";

// Migrar: para cada extintor existente, crear un registro de historial con su fecha original
$stmt = $db->query("SELECT id, fecha_escaneo FROM extintores ORDER BY id ASC");
$registros = $stmt->fetchAll();

$insertados = 0;
$insert = $db->prepare(
    "INSERT INTO historial_escaneos (extintor_id, fecha_escaneo, origen) VALUES (:eid, :fecha, 'migracion')"
);

foreach ($registros as $r) {
    // Verificar si ya existe para evitar duplicados si se ejecuta 2 veces
    $check = $db->prepare("SELECT COUNT(*) FROM historial_escaneos WHERE extintor_id = :eid");
    $check->execute([':eid' => $r['id']]);
    if ($check->fetchColumn() == 0) {
        $insert->execute([':eid' => $r['id'], ':fecha' => $r['fecha_escaneo']]);
        $insertados++;
    }
}

echo "Migrados $insertados registros de historial de " . count($registros) . " extintores.\n";
echo "LISTO. Eliminar este archivo del servidor.\n";
