-- =============================================================
-- MIGRACIÓN 003: Sistema de Actualizaciones y Migraciones
-- FACTUSYS v2.6
-- =============================================================

CREATE TABLE IF NOT EXISTS sistema_migraciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    batch INT NOT NULL DEFAULT 1,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sistema_actualizaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL DEFAULT 'v2.6.0',
    commit_hash VARCHAR(50) NULL,
    descripcion TEXT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'Exitoso',
    detalles TEXT NULL,
    ejecutado_por VARCHAR(100) NULL DEFAULT 'Admin',
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
