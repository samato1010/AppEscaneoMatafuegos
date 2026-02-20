<?php
/**
 * Script temporal para:
 * 1. Agregar columna nro_orden a extintores
 * 2. Crear tabla controles_periodicos
 * Ejecutar una sola vez y luego eliminar.
 */
require_once __DIR__ . '/config.php';

$db = getDB();

// 1. Agregar columna nro_orden a extintores
try {
    $db->exec("ALTER TABLE extintores ADD COLUMN nro_orden VARCHAR(50) DEFAULT NULL AFTER intentos_sync");
    echo "Columna nro_orden agregada a extintores OK.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Columna nro_orden ya existe, saltando.\n";
    } else {
        echo "Error agregando nro_orden: " . $e->getMessage() . "\n";
    }
}

// Agregar indice
try {
    $db->exec("ALTER TABLE extintores ADD INDEX idx_nro_orden (nro_orden)");
    echo "Indice idx_nro_orden creado OK.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "Indice idx_nro_orden ya existe, saltando.\n";
    } else {
        echo "Error creando indice: " . $e->getMessage() . "\n";
    }
}

// 2. Crear tabla controles_periodicos
$db->exec("
    CREATE TABLE IF NOT EXISTS controles_periodicos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        extintor_id INT NOT NULL,
        fecha_control DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        estado_carga ENUM('Cargado','Descargado','Sobrecargado') NOT NULL,
        chapa_baliza VARCHAR(100) NOT NULL,
        comentario TEXT DEFAULT NULL,
        origen VARCHAR(50) DEFAULT 'app_android',
        FOREIGN KEY (extintor_id) REFERENCES extintores(id) ON DELETE CASCADE,
        INDEX idx_extintor_id (extintor_id),
        INDEX idx_fecha (fecha_control)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "Tabla controles_periodicos creada OK.\n";
echo "LISTO. Eliminar este archivo del servidor.\n";
