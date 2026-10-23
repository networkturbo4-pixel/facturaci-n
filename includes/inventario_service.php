<?php
/**
 * includes/inventario_service.php
 * Motor centralizado de Inventario, Lotes Automáticos, Trazabilidad de Compras, Kardex y Alertas de Stock.
 * Conectado con Gestión de Ventas (productos.php), Facturación (factura_nueva.php),
 * Exportaciones (exportacion_nueva.php), Proformas y Campana de Notificaciones Global (topbar.php).
 */

if (!function_exists('ensureInventarioSchema')) {
    function ensureInventarioSchema(PDO $conn): void {
        static $verified = false;
        if ($verified) {
            return;
        }
        $verified = true;

        try {
            // 1. Verificar columnas extendidas en productos
            $cols = $conn->query("SHOW COLUMNS FROM productos LIKE 'stock_minimo'")->fetch();
            if (!$cols) {
                $conn->exec("ALTER TABLE productos
                    ADD COLUMN IF NOT EXISTS stock_minimo DECIMAL(12,2) NOT NULL DEFAULT 10.00 AFTER stock,
                    ADD COLUMN IF NOT EXISTS costo_compra DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio_unitario,
                    ADD COLUMN IF NOT EXISTS lote_actual VARCHAR(60) NULL AFTER codigo_partida");
            }

            // Verificar columna imagen_url en productos
            $colImg = $conn->query("SHOW COLUMNS FROM productos LIKE 'imagen_url'")->fetch();
            if (!$colImg) {
                $conn->exec("ALTER TABLE productos
                    ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(500) NULL AFTER descripcion");
            }

            // 2. Asegurar tabla producto_lotes
            $conn->exec("CREATE TABLE IF NOT EXISTS producto_lotes (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 3. Asegurar tabla stock_movimientos
            $conn->exec("CREATE TABLE IF NOT EXISTS stock_movimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                producto_id INT NOT NULL,
                lote_id INT NULL,
                codigo_lote VARCHAR(60) NULL,
                tipo_movimiento VARCHAR(20) NOT NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 4. Autocompletar lotes iniciales para productos existentes que aún no tengan lote
            $sinLote = $conn->query("SELECT p.* FROM productos p LEFT JOIN producto_lotes l ON l.producto_id = p.id WHERE l.id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($sinLote)) {
                $stmtInsLote = $conn->prepare("INSERT INTO producto_lotes
                    (producto_id, codigo_lote, cantidad_inicial, cantidad_actual, costo_unitario, fecha_compra, hora_compra, comprado_por, proveedor_nombre, tipo_documento, numero_documento, metodo_compra, observaciones, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Activo')");
                $stmtUpdProd = $conn->prepare("UPDATE productos SET lote_actual = ?, costo_compra = ? WHERE id = ?");
                $stmtInsMov  = $conn->prepare("INSERT INTO stock_movimientos
                    (producto_id, lote_id, codigo_lote, tipo_movimiento, cantidad, stock_resultante, motivo, referencia_doc, usuario_nombre)
                    VALUES (?, ?, ?, 'ENTRADA', ?, ?, 'Inventario / Lote Inicial Automático', ?, ?)");

                foreach ($sinLote as $idx => $prod) {
                    $fechaCreacion = !empty($prod['created_at']) ? date('Y-m-d', strtotime($prod['created_at'])) : date('Y-m-d');
                    $horaCreacion  = !empty($prod['created_at']) ? date('H:i:s', strtotime($prod['created_at'])) : date('H:i:s');
                    $fechaCompacta = str_replace('-', '', $fechaCreacion);
                    $codLote       = 'LOT-' . $fechaCompacta . '-' . str_pad((string)$prod['id'], 3, '0', STR_PAD_LEFT);
                    $costoEst      = round(((float)$prod['precio_unitario']) * 0.65, 2);
                    $docRef        = 'INV-INI-' . str_pad((string)$prod['id'], 4, '0', STR_PAD_LEFT);

                    $stmtInsLote->execute([
                        $prod['id'],
                        $codLote,
                        (float)$prod['stock'],
                        (float)$prod['stock'],
                        $costoEst,
                        $fechaCreacion,
                        $horaCreacion,
                        'Administrador del Sistema',
                        'Proveedor / Almacén Central',
                        'Inventario Inicial',
                        $docRef,
                        'Registro Inicial de Catálogo',
                        'Lote generado automáticamente a partir del registro base del producto.'
                    ]);
                    $loteId = (int)$conn->lastInsertId();
                    $stmtUpdProd->execute([$codLote, $costoEst, $prod['id']]);
                    $stmtInsMov->execute([
                        $prod['id'],
                        $loteId,
                        $codLote,
                        (float)$prod['stock'],
                        (float)$prod['stock'],
                        $docRef,
                        'Administrador del Sistema'
                    ]);
                }
            }
        } catch (Exception $e) {
            error_log("EnsureInventarioSchema warning: " . $e->getMessage());
        }
    }
}

if (!function_exists('generarSiguienteCodigoLote')) {
    function generarSiguienteCodigoLote(PDO $conn, string $prefijoFecha = ''): string {
        $fechaStr = $prefijoFecha ?: date('Ymd');
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM producto_lotes WHERE codigo_lote LIKE ?");
            $stmt->execute(['LOT-' . $fechaStr . '-%']);
            $count = (int)$stmt->fetchColumn() + 1;
            return 'LOT-' . $fechaStr . '-' . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            return 'LOT-' . $fechaStr . '-' . rand(100, 999);
        }
    }
}

if (!function_exists('procesarSubidaImagenProducto')) {
    /**
     * Procesa de forma segura la subida de la imagen del producto o valida una URL directa
     */
    function procesarSubidaImagenProducto(?array $fileInput, ?string $urlInput): ?string {
        // 1. Si se proporcionó un archivo
        if ($fileInput && !empty($fileInput['name']) && ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $origName = basename($fileInput['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
            if (in_array($ext, $allowedExts, true)) {
                $uploadDirRel = 'uploads/productos';
                $uploadDirAbs = dirname(__DIR__) . '/' . $uploadDirRel;
                if (!is_dir($uploadDirAbs)) {
                    @mkdir($uploadDirAbs, 0775, true);
                }
                $fileName = 'prod_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
                $destAbs  = $uploadDirAbs . '/' . $fileName;
                $destRel  = $uploadDirRel . '/' . $fileName;
                if (move_uploaded_file($fileInput['tmp_name'], $destAbs)) {
                    return $destRel;
                }
            }
        }

        // 2. Si se proporcionó una URL directa
        $urlClean = trim($urlInput ?? '');
        if (!empty($urlClean)) {
            return $urlClean;
        }

        return null;
    }
}
if (!function_exists('procesarSubidaDocumentoCompra')) {
    /**
     * Procesa de forma segura la subida del documento sustento de compra (PDF, JPG, PNG, WEBP, XML)
     */
    function procesarSubidaDocumentoCompra(array $fileInput, string $codigoLote): array {
        if (empty($fileInput['name']) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'url' => null, 'nombre' => null];
        }

        if (($fileInput['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al subir el archivo adjunto (Código: ' . $fileInput['error'] . ').'];
        }

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if (($fileInput['size'] ?? 0) > $maxSize) {
            return ['success' => false, 'message' => 'El documento adjunto supera el límite permitido de 10 MB.'];
        }

        $origName = basename($fileInput['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

        if (!in_array($ext, $allowedExts, true)) {
            return ['success' => false, 'message' => 'Formato de documento no válido. Se permiten archivos PDF, JPG, PNG, WEBP o XML.'];
        }

        $uploadDirRel = 'uploads/lotes';
        $uploadDirAbs = dirname(__DIR__) . '/' . $uploadDirRel;
        if (!is_dir($uploadDirAbs)) {
            @mkdir($uploadDirAbs, 0775, true);
        }

        $safeLote = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigoLote);
        $fileName = $safeLote . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
        $destAbs  = $uploadDirAbs . '/' . $fileName;
        $destRel  = $uploadDirRel . '/' . $fileName;

        if (!move_uploaded_file($fileInput['tmp_name'], $destAbs)) {
            return ['success' => false, 'message' => 'No se pudo guardar el documento de compra en el servidor.'];
        }

        return [
            'success' => true,
            'url'     => $destRel,
            'nombre'  => $origName
        ];
    }
}

if (!function_exists('descontarStockPorVenta')) {
    /**
     * Descuenta stock automáticamente de un producto y de su lote activo (FIFO) cuando se emite
     * una Factura, Boleta o Factura de Exportación, dejando trazabilidad completa en stock_movimientos.
     */
    function descontarStockPorVenta(
        PDO $conn,
        string $descripcionOCodigo,
        float $cantidad,
        string $referenciaDoc,
        string $usuarioNombre = 'Sistema',
        ?int $comprobanteId = null
    ): void {
        if ($cantidad <= 0 || trim($descripcionOCodigo) === '') {
            return;
        }

        ensureInventarioSchema($conn);

        try {
            // Buscar producto coincidente por descripción o código
            $q = trim($descripcionOCodigo);
            $stmt = $conn->prepare("SELECT id, codigo, descripcion, unidad_medida, stock, lote_actual FROM productos WHERE descripcion = ? OR codigo = ? LIMIT 1");
            $stmt->execute([$q, $q]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                return;
            }

            // Si es un servicio (ZZ o código SRV-), no descuenta stock físico
            $esServicio = (strtoupper($prod['unidad_medida'] ?? '') === 'ZZ') || str_starts_with(strtoupper($prod['codigo'] ?? ''), 'SRV');
            if ($esServicio) {
                return;
            }

            $prodId    = (int)$prod['id'];
            $stockPrev = (float)$prod['stock'];
            $nuevoStock = max(0, $stockPrev - $cantidad);

            // Descontar en orden FIFO de los lotes con saldo > 0
            $restantePorDescontar = $cantidad;
            $loteUsadoCodigo = $prod['lote_actual'] ?: 'SIN-LOTE';
            $loteUsadoId     = null;

            $stmtLotes = $conn->prepare("SELECT id, codigo_lote, cantidad_actual FROM producto_lotes WHERE producto_id = ? AND cantidad_actual > 0 ORDER BY fecha_compra ASC, id ASC");
            $stmtLotes->execute([$prodId]);
            $lotesActivos = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lotesActivos as $lote) {
                if ($restantePorDescontar <= 0) break;
                $disp = (float)$lote['cantidad_actual'];
                $consumir = min($disp, $restantePorDescontar);
                $nuevoSaldoLote = max(0, $disp - $consumir);
                $estadoLote = ($nuevoSaldoLote <= 0) ? 'Agotado' : 'Activo';

                $updL = $conn->prepare("UPDATE producto_lotes SET cantidad_actual = ?, estado = ? WHERE id = ?");
                $updL->execute([$nuevoSaldoLote, $estadoLote, $lote['id']]);

                $loteUsadoCodigo = $lote['codigo_lote'];
                $loteUsadoId     = (int)$lote['id'];
                $restantePorDescontar -= $consumir;
            }

            // Determinar cuál queda como lote_actual vigente
            $stmtVig = $conn->prepare("SELECT codigo_lote FROM producto_lotes WHERE producto_id = ? AND cantidad_actual > 0 ORDER BY fecha_compra ASC, id ASC LIMIT 1");
            $stmtVig->execute([$prodId]);
            $loteVigente = $stmtVig->fetchColumn() ?: $loteUsadoCodigo;

            // Actualizar stock general del producto
            $updP = $conn->prepare("UPDATE productos SET stock = ?, lote_actual = ? WHERE id = ?");
            $updP->execute([$nuevoStock, $loteVigente, $prodId]);

            // Registrar salida en Kardex / stock_movimientos
            $insMov = $conn->prepare("INSERT INTO stock_movimientos
                (producto_id, lote_id, codigo_lote, tipo_movimiento, cantidad, stock_resultante, motivo, referencia_doc, comprobante_id, usuario_nombre)
                VALUES (?, ?, ?, 'SALIDA', ?, ?, 'Venta / Emisión de Comprobante', ?, ?, ?)");
            $insMov->execute([
                $prodId,
                $loteUsadoId,
                $loteUsadoCodigo,
                $cantidad,
                $nuevoStock,
                $referenciaDoc,
                $comprobanteId,
                $usuarioNombre
            ]);
        } catch (Exception $e) {
            error_log("Error en descontarStockPorVenta: " . $e->getMessage());
        }
    }
}

if (!function_exists('obtenerResumenAlertasStock')) {
    /**
     * Retorna todos los productos que están Sin Stock (<= 0) o con Stock Bajo (<= stock_minimo)
     * excluyendo servicios (unidad ZZ o código SRV-).
     */
    function obtenerResumenAlertasStock(PDO $conn): array {
        ensureInventarioSchema($conn);
        $agotados = [];
        $bajoStock = [];
        try {
            $stmt = $conn->query("SELECT id, codigo, descripcion, unidad_medida, precio_unitario, stock, stock_minimo, lote_actual
                                  FROM productos
                                  WHERE estado = 'Activo'
                                    AND UPPER(unidad_medida) != 'ZZ'
                                    AND UPPER(codigo) NOT LIKE 'SRV%'
                                  ORDER BY stock ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $st  = (float)$r['stock'];
                $min = (float)($r['stock_minimo'] ?? 10);
                if ($st <= 0) {
                    $agotados[] = $r;
                } elseif ($st <= $min) {
                    $bajoStock[] = $r;
                }
            }
        } catch (Exception $e) {}

        return [
            'agotados'    => $agotados,
            'bajo_stock'  => $bajoStock,
            'total_alertas' => count($agotados) + count($bajoStock)
        ];
    }
}
