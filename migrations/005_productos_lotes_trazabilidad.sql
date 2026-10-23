-- =============================================================
-- MIGRACIÓN 005: Trazabilidad de Compras, Lotes Automáticos, Kardex y Alertas de Stock
-- FACTUSYS v2.5
-- =============================================================

ALTER TABLE productos
  ADD COLUMN IF NOT EXISTS stock_minimo DECIMAL(12,2) NOT NULL DEFAULT 10.00 AFTER stock,
  ADD COLUMN IF NOT EXISTS costo_compra DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio_unitario,
  ADD COLUMN IF NOT EXISTS lote_actual VARCHAR(60) NULL AFTER codigo_partida;

CREATE TABLE IF NOT EXISTS producto_lotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  codigo_lote VARCHAR(60) NOT NULL,
  cantidad_inicial DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cantidad_actual DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fecha_compra DATE NOT NULL,
  hora_compra TIME NOT NULL,
  comprado_por VARCHAR(120) NOT NULL,
  usuario_id INT NULL,
  proveedor_ruc VARCHAR(20) NULL,
  proveedor_nombre VARCHAR(200) NULL,
  tipo_documento VARCHAR(60) NOT NULL DEFAULT 'Factura de Compra',
  numero_documento VARCHAR(60) NULL,
  metodo_compra VARCHAR(80) NOT NULL DEFAULT 'Compra Directa / Contado',
  documento_url VARCHAR(500) NULL,
  documento_nombre VARCHAR(255) NULL,
  observaciones TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Activo',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  INDEX idx_prod_lote (producto_id),
  INDEX idx_cod_lote (codigo_lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  lote_id INT NULL,
  codigo_lote VARCHAR(60) NULL,
  tipo_movimiento VARCHAR(20) NOT NULL COMMENT 'ENTRADA, SALIDA, AJUSTE',
  cantidad DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock_resultante DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  motivo VARCHAR(150) NOT NULL,
  referencia_doc VARCHAR(100) NULL,
  comprobante_id INT NULL,
  usuario_nombre VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  INDEX idx_mov_prod (producto_id),
  INDEX idx_mov_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
