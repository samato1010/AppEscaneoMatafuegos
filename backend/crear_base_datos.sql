-- ======================================================
-- Script de creacion de base de datos
-- Escaneo de Matafuegos - HST SRL / Belga
-- ======================================================
-- Ejecutar en phpMyAdmin de Hostinger
-- Si la tabla ya existe, usar los ALTER TABLE al final
-- ======================================================

-- Crear tabla principal (si no existe)
CREATE TABLE IF NOT EXISTS extintores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url TEXT NOT NULL,
    fecha_escaneo DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('pendiente', 'cargado', 'error') DEFAULT 'pendiente',
    domicilio VARCHAR(255) DEFAULT NULL,
    fabricante VARCHAR(255) DEFAULT NULL,
    recargadora VARCHAR(255) DEFAULT NULL,
    fecha_mantenimiento VARCHAR(20) DEFAULT NULL,
    venc_mantenimiento VARCHAR(20) DEFAULT NULL,
    agente VARCHAR(100) DEFAULT NULL,
    capacidad VARCHAR(50) DEFAULT NULL,
    fecha_fabricacion VARCHAR(20) DEFAULT NULL,
    venc_vida_util VARCHAR(20) DEFAULT NULL,
    venc_ph VARCHAR(20) DEFAULT NULL,
    nro_tarjeta VARCHAR(50) DEFAULT NULL,
    nro_extintor VARCHAR(50) DEFAULT NULL,
    uso VARCHAR(50) DEFAULT NULL,
    fecha_sincronizacion DATETIME DEFAULT NULL,
    intentos_sync INT DEFAULT 0,
    INDEX idx_estado (estado),
    INDEX idx_fecha (fecha_escaneo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ======================================================
-- Si la tabla YA EXISTE y le faltan columnas, ejecutar:
-- ======================================================

-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS fecha_fabricacion VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS venc_vida_util VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS venc_ph VARCHAR(20) DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS nro_tarjeta VARCHAR(50) DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS nro_extintor VARCHAR(50) DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS uso VARCHAR(50) DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS fecha_sincronizacion DATETIME DEFAULT NULL;
-- ALTER TABLE extintores ADD COLUMN IF NOT EXISTS intentos_sync INT DEFAULT 0;
-- ALTER TABLE extintores ADD INDEX IF NOT EXISTS idx_estado (estado);
-- ALTER TABLE extintores ADD INDEX IF NOT EXISTS idx_fecha (fecha_escaneo);


-- ======================================================
-- Limpiar datos de prueba (OPCIONAL - ejecutar manualmente)
-- ======================================================

-- DELETE FROM extintores WHERE url LIKE '%TEST%'
--     OR url LIKE '%CURL%'
--     OR url LIKE '%VERIFY%'
--     OR url LIKE '%FINAL%'
--     OR url LIKE '%p_tarjeta=00000001%'
--     OR url LIKE '%id=12345%';

-- Resetear registro real para re-sincronizar
-- UPDATE extintores SET estado = 'pendiente', intentos_sync = 0
--     WHERE url LIKE '%p_tarjeta=4d5451324d5445314e6a453d%';
