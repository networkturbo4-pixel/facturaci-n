CREATE DATABASE IF NOT EXISTS facturacion_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE facturacion_crm;

-- Tabla de Clientes
CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento VARCHAR(20) NOT NULL,
    numero_documento VARCHAR(20) NOT NULL UNIQUE,
    razon_social VARCHAR(200) NOT NULL,
    correo_electronico VARCHAR(100),
    telefono VARCHAR(50),
    direccion_fiscal TEXT,
    es_credito BOOLEAN DEFAULT FALSE,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Productos y Servicios
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_publico VARCHAR(64) NULL UNIQUE,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NOT NULL,
    unidad_medida VARCHAR(20) NOT NULL DEFAULT 'NIU',
    precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    codigo_partida VARCHAR(20) NULL,
    stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tipo_igv VARCHAR(20) NOT NULL DEFAULT 'Gravado',
    estado VARCHAR(20) NOT NULL DEFAULT 'Activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Comprobantes (Cabecera)
CREATE TABLE IF NOT EXISTS comprobantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_publico VARCHAR(64) NULL,
    tipo_comprobante VARCHAR(50) NOT NULL,
    serie VARCHAR(10) NOT NULL,
    correlativo INT NOT NULL,
    fecha_emision DATE NOT NULL,
    cliente_documento VARCHAR(20) NOT NULL,
    cliente_razon_social VARCHAR(200) NOT NULL,
    cliente_direccion TEXT,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    igv DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado VARCHAR(20) DEFAULT 'Aceptado',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_serie_correlativo (serie, correlativo)
);

-- Tabla de Líneas de Comprobante (Detalle)
CREATE TABLE IF NOT EXISTS comprobante_lineas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comprobante_id INT NOT NULL,
    cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    descripcion VARCHAR(255) NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_linea DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (comprobante_id) REFERENCES comprobantes(id) ON DELETE CASCADE
);

-- Tabla de Proformas / Cotizaciones
CREATE TABLE IF NOT EXISTS proformas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) NOT NULL UNIQUE,
    cliente_documento VARCHAR(20) NOT NULL,
    cliente_razon_social VARCHAR(200) NOT NULL,
    cliente_direccion TEXT,
    fecha_emision DATE NOT NULL,
    validez_dias VARCHAR(50) DEFAULT '15 días',
    moneda VARCHAR(3) DEFAULT 'PEN',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    igv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado VARCHAR(30) DEFAULT 'Pendiente',
    comprobante_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Líneas de Proforma
CREATE TABLE IF NOT EXISTS proforma_lineas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proforma_id INT NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    descripcion VARCHAR(255) NOT NULL,
    precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    total_linea DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (proforma_id) REFERENCES proformas(id) ON DELETE CASCADE
);

-- Tabla de Oportunidades CRM Kanban
CREATE TABLE IF NOT EXISTS crm_oportunidades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    cliente_nombre VARCHAR(150) NOT NULL,
    categoria VARCHAR(50) DEFAULT 'Venta Directa',
    monto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    moneda VARCHAR(3) DEFAULT 'PEN',
    etapa VARCHAR(50) DEFAULT 'Prospecto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Usuarios del Sistema
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(50) DEFAULT 'Administrador',
    avatar VARCHAR(255) NULL,
    estado VARCHAR(20) DEFAULT 'Activo',
    ultimo_acceso DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO usuarios (nombre, email, password, rol, avatar, estado) VALUES 
('Admin User', 'admin@empresa.com', '$2y$10$dNgqaPFqz3vh.79ovWmgxONdmtMed9J7eUlTC5ZUSgRPleT.dcDHW', 'Administrador', 'https://ui-avatars.com/api/?name=Admin+User&background=3b82f6&color=fff', 'Activo'),
('Vendedor Uno', 'ventas1@empresa.com', '$2y$10$a5te.vdq6MkjoWDvcG1ufet2bAk6sYzpdEm6vuofWEy3NLa6X5tVm', 'Ventas', 'https://ui-avatars.com/api/?name=Vendedor+Uno&background=10b981&color=fff', 'Activo')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);
