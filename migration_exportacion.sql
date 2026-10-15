-- =============================================================
-- MIGRACIÓN: Módulo de Exportación con Integración SUNAT/Nubefact
-- FACTUSYS v2.0
-- Ejecutar en: phpMyAdmin > Base de datos facturacion_crm > SQL
-- =============================================================

USE facturacion_crm;

-- 1. Agregar columnas de exportación a la tabla comprobantes
ALTER TABLE comprobantes
  ADD COLUMN IF NOT EXISTS moneda VARCHAR(3) NOT NULL DEFAULT 'PEN' AFTER total,
  ADD COLUMN IF NOT EXISTS tipo_cambio DECIMAL(10,4) NOT NULL DEFAULT 1.0000 AFTER moneda,
  ADD COLUMN IF NOT EXISTS es_exportacion TINYINT(1) NOT NULL DEFAULT 0 AFTER tipo_cambio,
  ADD COLUMN IF NOT EXISTS pais_destino_codigo VARCHAR(5) NULL AFTER es_exportacion,
  ADD COLUMN IF NOT EXISTS pais_destino_nombre VARCHAR(100) NULL AFTER pais_destino_codigo,
  ADD COLUMN IF NOT EXISTS incoterm VARCHAR(10) NULL AFTER pais_destino_nombre,
  ADD COLUMN IF NOT EXISTS puerto_embarque VARCHAR(100) NULL AFTER incoterm,
  ADD COLUMN IF NOT EXISTS aduana_partida VARCHAR(100) NULL AFTER puerto_embarque,
  ADD COLUMN IF NOT EXISTS numero_dam VARCHAR(50) NULL AFTER aduana_partida,
  ADD COLUMN IF NOT EXISTS estado_dam VARCHAR(50) DEFAULT 'Sin DAM' AFTER numero_dam,
  ADD COLUMN IF NOT EXISTS ruc_agente_aduana VARCHAR(11) NULL AFTER estado_dam,
  ADD COLUMN IF NOT EXISTS nombre_agente_aduana VARCHAR(150) NULL AFTER ruc_agente_aduana,
  ADD COLUMN IF NOT EXISTS cliente_pais_residencia VARCHAR(100) NULL AFTER nombre_agente_aduana,
  -- Campos de respuesta SUNAT vía OSE (Nubefact)
  ADD COLUMN IF NOT EXISTS nubefact_enviado TINYINT(1) DEFAULT 0 AFTER cliente_pais_residencia,
  ADD COLUMN IF NOT EXISTS sunat_cdr_estado VARCHAR(30) NULL AFTER nubefact_enviado,
  ADD COLUMN IF NOT EXISTS sunat_cdr_descripcion TEXT NULL AFTER sunat_cdr_estado,
  ADD COLUMN IF NOT EXISTS sunat_enlace_pdf VARCHAR(500) NULL AFTER sunat_cdr_descripcion,
  ADD COLUMN IF NOT EXISTS sunat_enlace_xml VARCHAR(500) NULL AFTER sunat_enlace_pdf,
  ADD COLUMN IF NOT EXISTS sunat_enlace_cdr VARCHAR(500) NULL AFTER sunat_enlace_xml,
  ADD COLUMN IF NOT EXISTS sunat_cadena_con_hash VARCHAR(500) NULL AFTER sunat_enlace_cdr,
  ADD COLUMN IF NOT EXISTS sunat_hash_cpe VARCHAR(255) NULL AFTER sunat_cadena_con_hash,
  ADD COLUMN IF NOT EXISTS sunat_qr VARCHAR(500) NULL AFTER sunat_hash_cpe,
  ADD COLUMN IF NOT EXISTS nubefact_numero_comprobante INT NULL AFTER sunat_qr,
  ADD COLUMN IF NOT EXISTS fecha_envio_sunat TIMESTAMP NULL AFTER nubefact_numero_comprobante;

-- 2. Tabla de partidas arancelarias por línea de exportación
CREATE TABLE IF NOT EXISTS exportacion_partidas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comprobante_id INT NOT NULL,
  linea_num INT NOT NULL DEFAULT 1,
  codigo_partida VARCHAR(20) NOT NULL COMMENT 'Código SA p.ej. 0901.21.00.00',
  descripcion_aduanera VARCHAR(255) NULL,
  unidad_medida VARCHAR(20) NULL DEFAULT 'KGM' COMMENT 'KGM=Kilogramos, TNE=Toneladas, LTR=Litros, NIU=Unidades',
  peso_bruto DECIMAL(12,4) NULL DEFAULT 0,
  peso_neto DECIMAL(12,4) NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (comprobante_id) REFERENCES comprobantes(id) ON DELETE CASCADE,
  INDEX idx_comprobante (comprobante_id)
);

-- 3. Tabla de configuración del sistema (OSE, Empresa, etc.)
CREATE TABLE IF NOT EXISTS configuracion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(100) NOT NULL UNIQUE,
  valor TEXT NULL,
  descripcion VARCHAR(255) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 4. Insertar configuración inicial de Nubefact
INSERT INTO configuracion (clave, valor, descripcion) VALUES
  ('nubefact_url', 'https://api.nubefact.com/api/v1/323731e0-5fed-4ca9-b02e-94a8490dfc77', 'URL del endpoint de Nubefact OSE'),
  ('nubefact_token', 'd935d54d23f34cfca7d6b56d9f01eb29f9e60b7806504def8291289ec135d2b1', 'Token de autenticación Nubefact'),
  ('nubefact_modo', 'beta', 'Modo: beta o produccion'),
  ('empresa_ruc', '20000000001', 'RUC de la empresa emisora'),
  ('empresa_razon_social', 'MI EMPRESA S.A.C.', 'Razón social de la empresa'),
  ('empresa_nombre_comercial', 'FACTUSYS', 'Nombre comercial'),
  ('empresa_direccion', 'Av. Principal 123, Lima, Lima', 'Dirección fiscal'),
  ('empresa_ubigeo', '150101', 'Ubigeo SUNAT'),
  ('empresa_urbanizacion', '-', 'Urbanización'),
  ('empresa_departamento', 'Lima', 'Departamento'),
  ('empresa_provincia', 'Lima', 'Provincia'),
  ('empresa_distrito', 'Lima', 'Distrito'),
  ('empresa_codigo_pais', 'PE', 'Código ISO de país'),
  ('empresa_telefono', '(01) 555-0001', 'Teléfono de la empresa'),
  ('empresa_logo_url', '', 'URL del logotipo de la empresa'),
  ('moneda_exportacion_default', 'USD', 'Moneda por defecto para exportaciones')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- =============================================================
-- ✅ Migración completada
-- =============================================================
