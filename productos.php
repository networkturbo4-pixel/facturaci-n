<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';

$currentUser = currentUser();
$activeTab = $_GET['tab'] ?? 'catalogo';
if (!in_array($activeTab, ['catalogo', 'lotes', 'alertas', 'movimientos'], true)) {
    $activeTab = 'catalogo';
}

// --------------------------------------------------------------------------
// PROCESAR ACCIONES DEL FORMULARIO (POST)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada.";
    } elseif (isset($_POST['crear_producto'])) {
        // 1. Crear nuevo producto + generar lote automático + trazabilidad de compra
        $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombreProd   = trim($_POST['nombre_producto'] ?? '');
        $descripcion  = trim($_POST['descripcion'] ?? '');
        if (empty($descripcion) && !empty($nombreProd)) {
            $descripcion = $nombreProd;
        } elseif (!empty($nombreProd) && !empty($descripcion) && $nombreProd !== $descripcion) {
            $descripcion = $nombreProd . ' - ' . $descripcion;
        }
        $unidad       = trim($_POST['unidad_medida'] ?? 'NIU');
        $precio       = (float)($_POST['precio_unitario'] ?? 0);
        $costoCompra  = (float)($_POST['costo_compra'] ?? 0);
        $partida      = trim($_POST['codigo_partida'] ?? '');
        $stock        = (float)($_POST['stock'] ?? 0);
        $stockMinimo  = (float)($_POST['stock_minimo'] ?? 10);
        $token        = bin2hex(random_bytes(16));

        // Procesar foto / URL de imagen del producto
        $imagenUrlInput = trim($_POST['imagen_url'] ?? '');
        $imagenFileInput = $_FILES['imagen_archivo'] ?? null;
        $imagenFinal = procesarSubidaImagenProducto($imagenFileInput, $imagenUrlInput);

        // Trazabilidad de compra
        $fechaCompra  = !empty($_POST['fecha_compra']) ? $_POST['fecha_compra'] : date('Y-m-d');
        $horaCompra   = !empty($_POST['hora_compra'])  ? $_POST['hora_compra']  : date('H:i:s');
        $compradoPor  = trim($_POST['comprado_por'] ?? ($currentUser['nombre'] ?? 'Administrador'));
        $provRuc      = trim($_POST['proveedor_ruc'] ?? '');
        $provNombre   = trim($_POST['proveedor_nombre'] ?? '');
        $tipoDoc      = trim($_POST['tipo_documento'] ?? 'Factura de Compra');
        $numDoc       = trim($_POST['numero_documento'] ?? '');
        $metodoCompra = trim($_POST['metodo_compra'] ?? 'Compra Directa / Contado');
        $obsLote      = trim($_POST['observaciones_lote'] ?? '');

        if (empty($codigo) || empty($descripcion)) {
            $error_msg = "El código y la descripción o nombre del producto son obligatorios.";
        } else {
            try {
                $conn->beginTransaction();

                // Generar código de lote automático
                $codLote = generarSiguienteCodigoLote($conn, str_replace('-', '', $fechaCompra));

                // Procesar archivo de compra adjunto (PDF, imagen, etc.)
                $uploadRes = ['success' => true, 'url' => null, 'nombre' => null];
                if (!empty($_FILES['documento_compra']['name'])) {
                    $uploadRes = procesarSubidaDocumentoCompra($_FILES['documento_compra'], $codLote);
                    if (!$uploadRes['success']) {
                        throw new Exception($uploadRes['message']);
                    }
                }

                // Insertar producto con imagen_url
                $stmt = $conn->prepare("INSERT INTO productos 
                    (token_publico, codigo, descripcion, imagen_url, unidad_medida, precio_unitario, costo_compra, codigo_partida, stock, stock_minimo, lote_actual) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $token, 
                    $codigo, 
                    $descripcion, 
                    $imagenFinal,
                    $unidad, 
                    $precio, 
                    $costoCompra, 
                    $partida ?: null, 
                    $stock, 
                    $stockMinimo, 
                    $codLote
                ]);
                $productoId = (int)$conn->lastInsertId();

                // Insertar Lote de Compra Automático
                $stmtLote = $conn->prepare("INSERT INTO producto_lotes 
                    (producto_id, codigo_lote, cantidad_inicial, cantidad_actual, costo_unitario, fecha_compra, hora_compra, comprado_por, usuario_id, proveedor_ruc, proveedor_nombre, tipo_documento, numero_documento, metodo_compra, documento_url, documento_nombre, observaciones, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Activo')");
                $stmtLote->execute([
                    $productoId,
                    $codLote,
                    $stock,
                    $stock,
                    $costoCompra,
                    $fechaCompra,
                    $horaCompra,
                    $compradoPor,
                    (int)($currentUser['id'] ?? 0),
                    $provRuc ?: null,
                    $provNombre ?: null,
                    $tipoDoc,
                    $numDoc ?: null,
                    $metodoCompra,
                    $uploadRes['url'],
                    $uploadRes['nombre'],
                    $obsLote ?: 'Lote generado automáticamente al registrar el producto.'
                ]);
                $loteId = (int)$conn->lastInsertId();

                // Registrar en Kardex de Movimientos
                if ($stock > 0) {
                    $stmtMov = $conn->prepare("INSERT INTO stock_movimientos 
                        (producto_id, lote_id, codigo_lote, tipo_movimiento, cantidad, stock_resultante, motivo, referencia_doc, usuario_nombre)
                        VALUES (?, ?, ?, 'ENTRADA', ?, ?, 'Ingreso por Compra Inicial', ?, ?)");
                    $stmtMov->execute([
                        $productoId,
                        $loteId,
                        $codLote,
                        $stock,
                        $stock,
                        $numDoc ?: $codLote,
                        $compradoPor
                    ]);
                }

                $conn->commit();
                $success_msg = "Producto '$codigo' y Lote automático '$codLote' registrados con éxito.";
            } catch (PDOException $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                if ($e->getCode() == 23000) {
                    $error_msg = "El código de producto '$codigo' ya está registrado.";
                } else {
                    $error_msg = "Error al guardar producto: " . $e->getMessage();
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $error_msg = "Error al procesar lote o documento: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['editar_producto'])) {
        // 2. Editar producto individual
        $prodId       = (int)($_POST['producto_id'] ?? 0);
        $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombreProd   = trim($_POST['nombre_producto'] ?? '');
        $descripcion  = trim($_POST['descripcion'] ?? '');
        if (empty($descripcion) && !empty($nombreProd)) {
            $descripcion = $nombreProd;
        }
        $unidad       = trim($_POST['unidad_medida'] ?? 'NIU');
        $precio       = (float)($_POST['precio_unitario'] ?? 0);
        $costoCompra  = (float)($_POST['costo_compra'] ?? 0);
        $partida      = trim($_POST['codigo_partida'] ?? '');
        $stock        = (float)($_POST['stock'] ?? 0);
        $stockMinimo  = (float)($_POST['stock_minimo'] ?? 10);

        // Foto / URL
        $imagenUrlInput = trim($_POST['imagen_url'] ?? '');
        $imagenFileInput = $_FILES['imagen_archivo'] ?? null;
        $imagenFinal = procesarSubidaImagenProducto($imagenFileInput, $imagenUrlInput);

        if (empty($codigo) || empty($descripcion) || $prodId <= 0) {
            $error_msg = "El código y la descripción del producto son obligatorios.";
        } else {
            try {
                if ($imagenFinal !== null) {
                    $stmt = $conn->prepare("UPDATE productos SET 
                        codigo = ?, descripcion = ?, imagen_url = ?, unidad_medida = ?, precio_unitario = ?, costo_compra = ?, codigo_partida = ?, stock = ?, stock_minimo = ? 
                        WHERE id = ?");
                    $stmt->execute([$codigo, $descripcion, $imagenFinal, $unidad, $precio, $costoCompra, $partida ?: null, $stock, $stockMinimo, $prodId]);
                } else {
                    $stmt = $conn->prepare("UPDATE productos SET 
                        codigo = ?, descripcion = ?, unidad_medida = ?, precio_unitario = ?, costo_compra = ?, codigo_partida = ?, stock = ?, stock_minimo = ? 
                        WHERE id = ?");
                    $stmt->execute([$codigo, $descripcion, $unidad, $precio, $costoCompra, $partida ?: null, $stock, $stockMinimo, $prodId]);
                }
                $success_msg = "Producto '$codigo' actualizado correctamente.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_msg = "El código '$codigo' ya pertenece a otro producto.";
                } else {
                    $error_msg = "Error al actualizar producto: " . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['eliminar_producto'])) {
        // 3. Eliminar producto unitario
        $prodId = (int)($_POST['producto_id'] ?? 0);
        try {
            $del = $conn->prepare("DELETE FROM productos WHERE id = ?");
            $del->execute([$prodId]);
            $success_msg = "Producto eliminado del catálogo.";
        } catch (Exception $e) {
            $error_msg = "No se pudo eliminar el producto: " . $e->getMessage();
        }
    } elseif (isset($_POST['accion_masiva'])) {
        // 4. Acciones Masivas (Eliminar selección múltiple o Edición masiva)
        $tipoAccion = $_POST['tipo_accion_masiva'] ?? '';
        $idsRaw     = $_POST['seleccion_ids'] ?? [];
        $ids        = array_filter(array_map('intval', (array)$idsRaw));

        if (empty($ids)) {
            $error_msg = "No seleccionaste ningún producto para la acción en lote.";
        } elseif ($tipoAccion === 'eliminar') {
            try {
                $inQuery = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $conn->prepare("DELETE FROM productos WHERE id IN ($inQuery)");
                $stmt->execute($ids);
                $countDel = count($ids);
                $success_msg = "Se eliminaron exitosamente $countDel producto(s) seleccionados.";
            } catch (Exception $e) {
                $error_msg = "Error al eliminar productos seleccionados: " . $e->getMessage();
            }
        } elseif ($tipoAccion === 'editar_masivo') {
            $ajusteTipo   = $_POST['bulk_ajuste_precio_tipo'] ?? 'ninguno';
            $ajusteValor  = (float)($_POST['bulk_ajuste_precio_valor'] ?? 0);
            $nuevaUnidad  = trim($_POST['bulk_unidad_medida'] ?? '');
            $nuevoMinimo  = trim($_POST['bulk_stock_minimo'] ?? '');

            try {
                $conn->beginTransaction();
                foreach ($ids as $id) {
                    $stmtProd = $conn->prepare("SELECT precio_unitario, stock_minimo, unidad_medida FROM productos WHERE id = ?");
                    $stmtProd->execute([$id]);
                    $p = $stmtProd->fetch();
                    if (!$p) continue;

                    $precioActual = (float)$p['precio_unitario'];
                    if ($ajusteTipo === 'aumento_porcentaje' && $ajusteValor > 0) {
                        $precioActual = round($precioActual * (1 + ($ajusteValor / 100)), 2);
                    } elseif ($ajusteTipo === 'descuento_porcentaje' && $ajusteValor > 0) {
                        $precioActual = max(0, round($precioActual * (1 - ($ajusteValor / 100)), 2));
                    } elseif ($ajusteTipo === 'precio_fijo' && $ajusteValor > 0) {
                        $precioActual = $ajusteValor;
                    }

                    $unidadFinal = !empty($nuevaUnidad) ? $nuevaUnidad : $p['unidad_medida'];
                    $minimoFinal = ($nuevoMinimo !== '') ? (float)$nuevoMinimo : (float)$p['stock_minimo'];

                    $upd = $conn->prepare("UPDATE productos SET precio_unitario = ?, unidad_medida = ?, stock_minimo = ? WHERE id = ?");
                    $upd->execute([$precioActual, $unidadFinal, $minimoFinal, $id]);
                }
                $conn->commit();
                $success_msg = "Se actualizaron correctamente " . count($ids) . " productos seleccionados.";
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $error_msg = "Error en la edición masiva: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['reabastecer_lote'])) {
        // 5. Registrar Nueva Compra / Reabastecimiento de Lote para producto existente
        $prodId       = (int)($_POST['producto_id'] ?? 0);
        $cantidadAdd  = (float)($_POST['cantidad_compra'] ?? 0);
        $costoUnit    = (float)($_POST['costo_unitario'] ?? 0);
        $fechaCompra  = !empty($_POST['fecha_compra']) ? $_POST['fecha_compra'] : date('Y-m-d');
        $horaCompra   = !empty($_POST['hora_compra'])  ? $_POST['hora_compra']  : date('H:i:s');
        $compradoPor  = trim($_POST['comprado_por'] ?? ($currentUser['nombre'] ?? 'Administrador'));
        $provRuc      = trim($_POST['proveedor_ruc'] ?? '');
        $provNombre   = trim($_POST['proveedor_nombre'] ?? '');
        $tipoDoc      = trim($_POST['tipo_documento'] ?? 'Factura de Compra');
        $numDoc       = trim($_POST['numero_documento'] ?? '');
        $metodoCompra = trim($_POST['metodo_compra'] ?? 'Compra Directa / Contado');
        $obsLote      = trim($_POST['observaciones'] ?? '');

        if ($prodId <= 0 || $cantidadAdd <= 0) {
            $error_msg = "Seleccione un producto válido y una cantidad mayor a cero.";
        } else {
            try {
                $conn->beginTransaction();

                $stmtP = $conn->prepare("SELECT codigo, descripcion, stock FROM productos WHERE id = ?");
                $stmtP->execute([$prodId]);
                $prodData = $stmtP->fetch();
                if (!$prodData) throw new Exception("Producto no encontrado.");

                $codLote = generarSiguienteCodigoLote($conn, str_replace('-', '', $fechaCompra));

                $uploadRes = ['success' => true, 'url' => null, 'nombre' => null];
                if (!empty($_FILES['documento_compra']['name'])) {
                    $uploadRes = procesarSubidaDocumentoCompra($_FILES['documento_compra'], $codLote);
                    if (!$uploadRes['success']) {
                        throw new Exception($uploadRes['message']);
                    }
                }

                // Insertar nuevo lote
                $stmtLote = $conn->prepare("INSERT INTO producto_lotes 
                    (producto_id, codigo_lote, cantidad_inicial, cantidad_actual, costo_unitario, fecha_compra, hora_compra, comprado_por, usuario_id, proveedor_ruc, proveedor_nombre, tipo_documento, numero_documento, metodo_compra, documento_url, documento_nombre, observaciones, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Activo')");
                $stmtLote->execute([
                    $prodId,
                    $codLote,
                    $cantidadAdd,
                    $cantidadAdd,
                    $costoUnit,
                    $fechaCompra,
                    $horaCompra,
                    $compradoPor,
                    (int)($currentUser['id'] ?? 0),
                    $provRuc ?: null,
                    $provNombre ?: null,
                    $tipoDoc,
                    $numDoc ?: null,
                    $metodoCompra,
                    $uploadRes['url'],
                    $uploadRes['nombre'],
                    $obsLote ?: 'Reabastecimiento de stock / Lote de compra registrado.'
                ]);
                $loteId = (int)$conn->lastInsertId();

                // Actualizar stock y lote actual del producto
                $nuevoStock = (float)$prodData['stock'] + $cantidadAdd;
                $updP = $conn->prepare("UPDATE productos SET stock = ?, lote_actual = ?, costo_compra = ? WHERE id = ?");
                $updP->execute([$nuevoStock, $codLote, $costoUnit, $prodId]);

                // Registrar en Kardex
                $stmtMov = $conn->prepare("INSERT INTO stock_movimientos 
                    (producto_id, lote_id, codigo_lote, tipo_movimiento, cantidad, stock_resultante, motivo, referencia_doc, usuario_nombre)
                    VALUES (?, ?, ?, 'ENTRADA', ?, ?, 'Reabastecimiento / Compra de Lote', ?, ?)");
                $stmtMov->execute([
                    $prodId,
                    $loteId,
                    $codLote,
                    $cantidadAdd,
                    $nuevoStock,
                    $numDoc ?: $codLote,
                    $compradoPor
                ]);

                $conn->commit();
                $success_msg = "Se reabasteció el producto '{$prodData['codigo']}' con {$cantidadAdd} unidades en el Lote '$codLote'.";
                $activeTab = 'lotes';
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $error_msg = "Error al reabastecer lote: " . $e->getMessage();
            }
        }
    }
}

// --------------------------------------------------------------------------
// CONSULTAS DE DATOS (PRODUCTOS, LOTES, KARDEX, ALERTAS)
// --------------------------------------------------------------------------
try {
    // 1. Productos
    $stmt = $conn->query("SELECT * FROM productos ORDER BY id DESC");
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalItems = count($productos);
    $valorInventario = 0;
    $conPartida = 0;
    $countSinStock = 0;
    $countStockBajo = 0;

    foreach ($productos as $p) {
        $st = (float)$p['stock'];
        $min = (float)($p['stock_minimo'] ?? 10);
        $esServicio = (strtoupper($p['unidad_medida'] ?? '') === 'ZZ') || str_starts_with(strtoupper($p['codigo'] ?? ''), 'SRV');

        $valorInventario += ($st * (float)$p['precio_unitario']);
        if (!empty($p['codigo_partida'])) $conPartida++;

        if (!$esServicio) {
            if ($st <= 0) {
                $countSinStock++;
            } elseif ($st <= $min) {
                $countStockBajo++;
            }
        }
    }

    // 2. Lotes de compra con trazabilidad
    $stmtLotes = $conn->query("SELECT l.*, p.codigo as prod_codigo, p.descripcion as prod_desc, p.unidad_medida as prod_unidad
                              FROM producto_lotes l
                              JOIN productos p ON p.id = l.producto_id
                              ORDER BY l.fecha_compra DESC, l.id DESC LIMIT 100");
    $lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

    // 3. Movimientos Kardex
    $stmtMovs = $conn->query("SELECT m.*, p.codigo as prod_codigo, p.descripcion as prod_desc, p.unidad_medida as prod_unidad
                             FROM stock_movimientos m
                             JOIN productos p ON p.id = m.producto_id
                             ORDER BY m.created_at DESC, m.id DESC LIMIT 100");
    $movimientos = $stmtMovs->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $productos = [];
    $lotes = [];
    $movimientos = [];
    $totalItems = 0;
    $valorInventario = 0;
    $conPartida = 0;
    $countSinStock = 0;
    $countStockBajo = 0;
}

$totalAlertas = $countSinStock + $countStockBajo;

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 dark:bg-slate-950 overflow-hidden">
    <?php 
    $pageTitle = 'Gestión de Ventas & Trazabilidad';
    $topbarAction = [
        'label'   => 'Nuevo Producto / Lote',
        'onclick' => "abrirModalNuevoProducto()",
        'icon'    => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <?php if ($success_msg): ?>
            <div data-auto-toast="true" class="p-4 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-800 dark:text-emerald-300 rounded-2xl text-xs flex items-center gap-2.5 shadow-xs">
                <i class="fa-solid fa-circle-check text-emerald-500 text-sm"></i>
                <span class="font-medium"><?php echo htmlspecialchars($success_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div data-auto-toast="true" class="p-4 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-800 dark:text-red-300 rounded-2xl text-xs flex items-center gap-2.5 shadow-xs">
                <i class="fa-solid fa-circle-exclamation text-red-500 text-sm"></i>
                <span class="font-medium"><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Banner Preventivo Inteligente de Alertas de Stock -->
        <?php if ($totalAlertas > 0): ?>
            <div class="p-4 rounded-2xl border <?php echo ($countSinStock > 0) ? 'bg-red-50/80 dark:bg-red-500/10 border-red-200 dark:border-red-500/30' : 'bg-amber-50/80 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/30'; ?> flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
                <div class="flex items-start sm:items-center gap-3">
                    <div class="w-9 h-9 rounded-xl <?php echo ($countSinStock > 0) ? 'bg-red-500 text-white' : 'bg-amber-500 text-white'; ?> flex items-center justify-center text-sm shrink-0 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h4 class="text-xs sm:text-sm font-extrabold <?php echo ($countSinStock > 0) ? 'text-red-950 dark:text-red-200' : 'text-amber-950 dark:text-amber-200'; ?>">
                            Atención de Inventario: <?php echo $totalAlertas; ?> producto(s) requieren reabastecimiento
                        </h4>
                        <p class="text-[11px] <?php echo ($countSinStock > 0) ? 'text-red-800 dark:text-red-300/90' : 'text-amber-800 dark:text-amber-300/90'; ?> mt-0.5">
                            <?php if ($countSinStock > 0): ?>
                                <span class="font-bold"><?php echo $countSinStock; ?> agotado(s)</span> (sin existencias para facturar)<?php echo ($countStockBajo > 0) ? " y $countStockBajo por debajo del stock mínimo recomendado." : '.'; ?>
                            <?php else: ?>
                                <span class="font-bold"><?php echo $countStockBajo; ?> producto(s)</span> están llegando a su stock mínimo de seguridad.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button" onclick="cambiarPestana('alertas')" class="px-3.5 py-2 rounded-xl text-xs font-bold <?php echo ($countSinStock > 0) ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-amber-600 hover:bg-amber-700 text-white'; ?> transition-all shadow-xs flex items-center gap-1.5">
                        <i class="fa-solid fa-bell"></i>
                        <span>Ver Alertas (<?php echo $totalAlertas; ?>)</span>
                    </button>
                    <button type="button" onclick="abrirModalReabastecer(null)" class="px-3.5 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 transition-all flex items-center gap-1.5">
                        <i class="fa-solid fa-truck-ramp-box text-brand-600"></i>
                        <span>Reabastecer Lote</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Cards Grid (4 Cards) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Total Ítems</span>
                    <span class="p-1.5 sm:p-2 bg-blue-50 dark:bg-blue-500/15 text-blue-600 dark:text-blue-400 rounded-lg text-xs shrink-0"><i class="fa-solid fa-box"></i></span>
                </div>
                <p class="text-lg sm:text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $totalItems; ?></p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">Catálogo de bienes y servicios</p>
            </div>

            <div class="app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Valor Stock</span>
                    <span class="p-1.5 sm:p-2 bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 rounded-lg text-xs shrink-0"><i class="fa-solid fa-coins"></i></span>
                </div>
                <p class="text-lg sm:text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 truncate">S/ <?php echo number_format($valorInventario, 2); ?></p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">Valorización en almacén</p>
            </div>

            <div class="app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Alertas de Stock</span>
                    <span class="p-1.5 sm:p-2 <?php echo ($totalAlertas > 0) ? 'bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'; ?> rounded-lg text-xs shrink-0">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </span>
                </div>
                <p class="text-lg sm:text-2xl font-extrabold <?php echo ($totalAlertas > 0) ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white'; ?>">
                    <?php echo $totalAlertas; ?>
                </p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">
                    <?php echo $countSinStock; ?> agotados • <?php echo $countStockBajo; ?> bajos
                </p>
            </div>

            <div class="app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Lotes & Trazabilidad</span>
                    <span class="p-1.5 sm:p-2 bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 rounded-lg text-xs shrink-0"><i class="fa-solid fa-barcode"></i></span>
                </div>
                <p class="text-lg sm:text-2xl font-extrabold text-indigo-600 dark:text-indigo-400"><?php echo count($lotes); ?></p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">Lotes de compra activos</p>
            </div>
        </div>

        <!-- Pestañas Superiores Modernas del Módulo -->
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 overflow-x-auto gap-2 pb-px">
            <div class="flex items-center gap-1.5 min-w-max">
                <button type="button" onclick="cambiarPestana('catalogo')" id="tab_btn_catalogo" class="tab-btn px-4 py-2.5 text-xs font-bold rounded-xl border-b-2 flex items-center gap-2 transition-all <?php echo ($activeTab === 'catalogo') ? 'border-brand-600 text-brand-600 dark:text-brand-400 bg-brand-50/60 dark:bg-brand-500/10' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'; ?>">
                    <i class="fa-solid fa-boxes-stacked"></i>
                    <span>Catálogo de Productos</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-mono"><?php echo $totalItems; ?></span>
                </button>

                <button type="button" onclick="cambiarPestana('lotes')" id="tab_btn_lotes" class="tab-btn px-4 py-2.5 text-xs font-bold rounded-xl border-b-2 flex items-center gap-2 transition-all <?php echo ($activeTab === 'lotes') ? 'border-brand-600 text-brand-600 dark:text-brand-400 bg-brand-50/60 dark:bg-brand-500/10' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'; ?>">
                    <i class="fa-solid fa-barcode"></i>
                    <span>Trazabilidad de Compras & Lotes</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-indigo-50 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300 font-mono"><?php echo count($lotes); ?></span>
                </button>

                <button type="button" onclick="cambiarPestana('alertas')" id="tab_btn_alertas" class="tab-btn px-4 py-2.5 text-xs font-bold rounded-xl border-b-2 flex items-center gap-2 transition-all <?php echo ($activeTab === 'alertas') ? 'border-brand-600 text-brand-600 dark:text-brand-400 bg-brand-50/60 dark:bg-brand-500/10' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'; ?>">
                    <i class="fa-solid fa-triangle-exclamation <?php echo ($totalAlertas > 0) ? 'text-amber-500 animate-pulse' : ''; ?>"></i>
                    <span>Alertas de Stock</span>
                    <?php if ($totalAlertas > 0): ?>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] <?php echo ($countSinStock > 0) ? 'bg-red-500 text-white' : 'bg-amber-500 text-white'; ?> font-mono font-extrabold"><?php echo $totalAlertas; ?></span>
                    <?php endif; ?>
                </button>

                <button type="button" onclick="cambiarPestana('movimientos')" id="tab_btn_movimientos" class="tab-btn px-4 py-2.5 text-xs font-bold rounded-xl border-b-2 flex items-center gap-2 transition-all <?php echo ($activeTab === 'movimientos') ? 'border-brand-600 text-brand-600 dark:text-brand-400 bg-brand-50/60 dark:bg-brand-500/10' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'; ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Kardex / Movimientos</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-mono"><?php echo count($movimientos); ?></span>
                </button>
            </div>

            <div class="hidden sm:flex items-center gap-2 shrink-0">
                <button type="button" onclick="abrirModalReabastecer(null)" class="px-3 py-1.5 bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 border border-indigo-200/80 dark:border-indigo-500/30 rounded-xl text-xs font-semibold flex items-center gap-1.5 transition-all">
                    <i class="fa-solid fa-cart-flatbed text-xs"></i>
                    <span>+ Reabastecer Lote</span>
                </button>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 1: CATÁLOGO DE PRODUCTOS (CHECKBOXES & ACCIONES EN LOTE)     -->
        <!-- ==================================================================== -->
        <div id="tab_content_catalogo" class="tab-pane space-y-4 <?php echo ($activeTab !== 'catalogo') ? 'hidden' : ''; ?>">
            
            <!-- Barra de Filtros, Buscador & Segmentos de Stock -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative w-full sm:w-80">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                        </span>
                        <input 
                            type="text" 
                            id="searchProductos" 
                            placeholder="Buscar por código, descripción, lote o partida..." 
                            class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl py-2 pl-9 pr-3 text-xs text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:border-brand-500 shadow-xs"
                        >
                    </div>

                    <!-- Filtros rápidos por estado de stock -->
                    <div class="flex items-center gap-1 bg-white dark:bg-slate-800 p-1 rounded-xl border border-slate-200 dark:border-slate-700 text-xs">
                        <button type="button" onclick="filtrarEstadoStock('todos', this)" class="btn-filtro-stock px-2.5 py-1 rounded-lg font-bold bg-brand-50 text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">
                            Todos (<?php echo $totalItems; ?>)
                        </button>
                        <button type="button" onclick="filtrarEstadoStock('en_stock', this)" class="btn-filtro-stock px-2.5 py-1 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900">
                            En Stock
                        </button>
                        <button type="button" onclick="filtrarEstadoStock('stock_bajo', this)" class="btn-filtro-stock px-2.5 py-1 rounded-lg text-amber-600 dark:text-amber-400 hover:bg-amber-50/50">
                            Bajo Stock (<?php echo $countStockBajo; ?>)
                        </button>
                        <button type="button" onclick="filtrarEstadoStock('sin_stock', this)" class="btn-filtro-stock px-2.5 py-1 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50/50">
                            Sin Stock (<?php echo $countSinStock; ?>)
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" onclick="exportarSeleccionadosCSV()" class="px-3 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 transition-colors flex items-center gap-1.5 shadow-xs">
                        <i class="fa-solid fa-file-excel text-emerald-600"></i>
                        <span>Exportar CSV</span>
                    </button>
                </div>
            </div>

            <!-- BARRA DE ACCIÓN FLOTANTE / INTERACTIVA AL SELECCIONAR CHECKBOXES -->
            <div id="selectionActionBar" class="hidden p-3 sm:p-4 bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white rounded-2xl shadow-xl border border-slate-800 flex flex-wrap items-center justify-between gap-3 animate-fade-in">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-xl bg-brand-500 text-white flex items-center justify-center text-xs font-extrabold shadow-sm" id="badgeSelectedCount">0</span>
                    <div>
                        <p class="text-xs sm:text-sm font-extrabold text-white" id="textSelectedCount">0 producto(s) seleccionado(s)</p>
                        <p class="text-[11px] text-slate-300">Aplica acciones masivas o individuales sobre la selección</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- Botón Editar (unitaria o masiva) -->
                    <button type="button" onclick="ejecutarAccionEditar()" id="btnActionEditar" class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span id="labelActionEditar">Editar</span>
                    </button>

                    <!-- Botón Compartir (ficha o selección de catálogo) -->
                    <button type="button" onclick="ejecutarAccionCompartir()" id="btnActionCompartir" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm">
                        <i class="fa-solid fa-share-nodes"></i>
                        <span id="labelActionCompartir">Compartir</span>
                    </button>

                    <!-- Botón Reabastecer Lote (si es 1) -->
                    <button type="button" onclick="ejecutarAccionReabastecer()" id="btnActionReabastecer" class="hidden sm:inline-flex px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-semibold transition-all items-center gap-1.5 border border-white/15">
                        <i class="fa-solid fa-cart-flatbed text-amber-300"></i>
                        <span>+ Nuevo Lote</span>
                    </button>

                    <!-- Botón Eliminar Selección -->
                    <button type="button" onclick="ejecutarAccionEliminarMasiva()" class="px-3.5 py-2 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm">
                        <i class="fa-solid fa-trash"></i>
                        <span>Eliminar</span>
                    </button>

                    <!-- Botón Deseleccionar -->
                    <button type="button" onclick="deseleccionarTodos()" class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition-colors" title="Deseleccionar todos">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
            </div>

            <!-- Tabla de Productos con Checkboxes Modernos -->
            <div class="app-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs" id="tableProductos">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200/80 dark:border-slate-700 uppercase tracking-wider text-[11px]">
                                <th class="w-12 px-4 py-3.5 text-center">
                                    <label class="modern-checkbox-label" title="Seleccionar todos los productos">
                                        <input type="checkbox" id="checkAllProductos" class="modern-checkbox" onchange="toggleSelectAll(this.checked)">
                                        <span class="checkbox-fallback-box"></span>
                                    </label>
                                </th>
                                <th class="px-4 py-3.5">Foto</th>
                                <th class="px-4 py-3.5">Código</th>
                                <th class="px-4 py-3.5">Descripción & Lote</th>
                                <th class="px-4 py-3.5">Unidad</th>
                                <th class="px-4 py-3.5">Precio Venta</th>
                                <th class="px-4 py-3.5">Costo Compra</th>
                                <th class="px-4 py-3.5">Stock & Alerta</th>
                                <th class="px-4 py-3.5">Partida Arancelaria</th>
                                <th class="px-4 py-3.5 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium text-slate-700 dark:text-slate-200">
                            <?php if (empty($productos)): ?>
                                <tr>
                                    <td colspan="10" class="px-6 py-12 text-center text-slate-400">
                                        <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-3 text-lg">
                                            <i class="fa-solid fa-box-open"></i>
                                        </div>
                                        <p class="font-bold text-slate-600 dark:text-slate-300 text-sm">No hay productos registrados en el catálogo</p>
                                        <p class="text-xs text-slate-400 mt-1">Haz clic en "Nuevo Producto / Servicio" para comenzar a registrar tu inventario.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($productos as $p): 
                                    $st = (float)$p['stock'];
                                    $min = (float)($p['stock_minimo'] ?? 10);
                                    $esServicio = (strtoupper($p['unidad_medida'] ?? '') === 'ZZ') || str_starts_with(strtoupper($p['codigo'] ?? ''), 'SRV');
                                    
                                    $statusClass = 'en_stock';
                                    if (!$esServicio) {
                                        if ($st <= 0) $statusClass = 'sin_stock';
                                        elseif ($st <= $min) $statusClass = 'stock_bajo';
                                    }
                                    $loteVig = $p['lote_actual'] ?: '-';
                                    $imgProd = !empty($p['imagen_url']) ? $p['imagen_url'] : '';
                                ?>
                                <tr class="selectable-product-row hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors" data-prod-id="<?php echo $p['id']; ?>" data-stock-status="<?php echo $statusClass; ?>">
                                    <td class="w-12 px-4 py-4 text-center">
                                        <label class="modern-checkbox-label">
                                            <input type="checkbox" class="modern-checkbox prod-checkbox" value="<?php echo $p['id']; ?>" data-prod='<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>' onchange="handleProductCheckChange()">
                                            <span class="checkbox-fallback-box"></span>
                                        </label>
                                    </td>
                                    <td class="px-4 py-4">
                                        <?php if ($imgProd): ?>
                                            <img src="<?php echo htmlspecialchars($imgProd); ?>" alt="<?php echo htmlspecialchars($p['descripcion']); ?>" class="w-10 h-10 rounded-xl object-cover border border-slate-200 dark:border-slate-700 shadow-xs cursor-pointer hover:scale-110 transition-transform" onclick="window.open('<?php echo htmlspecialchars($imgProd, ENT_QUOTES); ?>', '_blank')">
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center text-xs">
                                                <i class="<?php echo $esServicio ? 'fa-solid fa-briefcase' : 'fa-solid fa-image'; ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 font-mono font-bold text-slate-900 dark:text-white">
                                        <?php echo htmlspecialchars($p['codigo']); ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-semibold text-slate-800 dark:text-slate-100">
                                            <?php echo htmlspecialchars($p['descripcion']); ?>
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-1">
                                            <?php if (!empty($p['lote_actual'])): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 border border-indigo-200/60 dark:border-indigo-500/20" title="Lote actual vigente">
                                                    <i class="fa-solid fa-barcode text-[9px]"></i>
                                                    <?php echo htmlspecialchars($p['lote_actual']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($esServicio): ?>
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-50 dark:bg-purple-500/15 text-purple-700 dark:text-purple-300 border border-purple-200/60 dark:border-purple-500/20">
                                                    Servicio
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                            <?php echo htmlspecialchars($p['unidad_medida']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 font-bold text-slate-900 dark:text-white">
                                        S/ <?php echo number_format((float)$p['precio_unitario'], 2); ?>
                                    </td>
                                    <td class="px-4 py-4 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                        S/ <?php echo number_format((float)($p['costo_compra'] ?? 0), 2); ?>
                                    </td>
                                    <td class="px-4 py-4 font-mono">
                                        <div class="flex items-center gap-2">
                                            <?php if ($esServicio): ?>
                                                <span class="text-slate-500 dark:text-slate-400 font-bold">Ilimitado</span>
                                            <?php elseif ($st <= 0): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-500/30">
                                                    <i class="fa-solid fa-circle-exclamation text-[9px]"></i> 0.00 (Agotado)
                                                </span>
                                            <?php elseif ($st <= $min): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-amber-100 dark:bg-amber-500/20 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30">
                                                    <i class="fa-solid fa-triangle-exclamation text-[9px]"></i> <?php echo number_format($st, 2); ?> (Bajo)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400 font-bold">
                                                    <i class="fa-solid fa-circle-check text-[10px]"></i> <?php echo number_format($st, 2); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$esServicio): ?>
                                            <span class="text-[10px] text-slate-400 block mt-0.5">Mín: <?php echo number_format($min, 0); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 font-mono text-[11px] text-slate-600 dark:text-slate-400">
                                        <?php echo htmlspecialchars($p['codigo_partida'] ?: '-'); ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <!-- Reabastecer Lote Directo -->
                                            <?php if (!$esServicio): ?>
                                            <button type="button" onclick='abrirModalReabastecer(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-500/10 rounded-lg transition-colors" title="Reabastecer Lote de Compra">
                                                <i class="fa-solid fa-cart-flatbed text-xs"></i>
                                            </button>
                                            <?php endif; ?>

                                            <!-- Ver Vista Pública -->
                                            <?php $tokenProd = !empty($p['token_publico']) ? $p['token_publico'] : ''; ?>
                                            <a href="producto_publico.php?token=<?php echo urlencode($tokenProd); ?>&id=<?php echo $p['id']; ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-500/10 rounded-lg transition-colors" title="Ver Vista Pública">
                                                <i class="fa-solid fa-eye text-xs"></i>
                                            </a>

                                            <!-- Compartir Producto -->
                                            <button type="button" onclick='abrirCompartir(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-500/10 rounded-lg transition-colors" title="Compartir Ficha">
                                                <i class="fa-solid fa-share-nodes text-xs"></i>
                                            </button>

                                            <!-- Editar Producto -->
                                            <button type="button" onclick='abrirEditar(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 rounded-lg transition-colors" title="Editar Producto">
                                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                                            </button>

                                            <!-- Eliminar Producto -->
                                            <form method="POST" action="" class="inline" onsubmit="return confirm('¿Seguro de eliminar este producto del catálogo?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="eliminar_producto" value="1">
                                                <input type="hidden" name="producto_id" value="<?php echo $p['id']; ?>">
                                                <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors" title="Eliminar Producto">
                                                    <i class="fa-solid fa-trash text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 2: TRAZABILIDAD DE COMPRAS & LOTES REGISTRADOS               -->
        <!-- ==================================================================== -->
        <div id="tab_content_lotes" class="tab-pane space-y-4 <?php echo ($activeTab !== 'lotes') ? 'hidden' : ''; ?>">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <i class="fa-solid fa-barcode text-indigo-600"></i>
                        <span>Registro de Lotes & Trazabilidad de Adquisición</span>
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Auditoría completa: fecha, hora de compra, responsable, modalidad y comprobante adjunto.</p>
                </div>
                <button type="button" onclick="abrirModalReabastecer(null)" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold shadow-sm transition-all flex items-center gap-1.5">
                    <i class="fa-solid fa-plus text-[11px]"></i>
                    <span>Registrar Nuevo Lote / Compra</span>
                </button>
            </div>

            <div class="app-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200/80 dark:border-slate-700 uppercase tracking-wider text-[11px]">
                                <th class="px-4 py-3.5">Código Lote</th>
                                <th class="px-4 py-3.5">Producto</th>
                                <th class="px-4 py-3.5">Fecha & Hora Compra</th>
                                <th class="px-4 py-3.5">Comprado Por</th>
                                <th class="px-4 py-3.5">Proveedor / Sustento</th>
                                <th class="px-4 py-3.5">Cant. Inicial</th>
                                <th class="px-4 py-3.5">Saldo Actual</th>
                                <th class="px-4 py-3.5">Costo Unit.</th>
                                <th class="px-4 py-3.5 text-center">Documento Adjunto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium text-slate-700 dark:text-slate-200">
                            <?php if (empty($lotes)): ?>
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-slate-400">
                                        No se han registrado lotes de compra aún.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lotes as $l): ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                                    <td class="px-4 py-3.5 font-mono font-bold text-indigo-700 dark:text-indigo-400">
                                        <?php echo htmlspecialchars($l['codigo_lote']); ?>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <p class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($l['prod_desc']); ?></p>
                                        <p class="text-[10px] font-mono text-slate-400"><?php echo htmlspecialchars($l['prod_codigo']); ?> • <?php echo htmlspecialchars($l['prod_unidad']); ?></p>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <div class="font-semibold text-slate-800 dark:text-slate-200"><?php echo date('d/m/Y', strtotime($l['fecha_compra'])); ?></div>
                                        <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($l['hora_compra']); ?></div>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                            <i class="fa-regular fa-user text-[10px] text-slate-400"></i>
                                            <?php echo htmlspecialchars($l['comprado_por']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <p class="font-semibold text-slate-800 dark:text-slate-200"><?php echo htmlspecialchars($l['tipo_documento']); ?> <?php echo htmlspecialchars($l['numero_documento'] ? "({$l['numero_documento']})" : ''); ?></p>
                                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($l['proveedor_nombre'] ?: 'Proveedor no especificado'); ?> • <?php echo htmlspecialchars($l['metodo_compra']); ?></p>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono font-bold text-slate-900 dark:text-white">
                                        <?php echo number_format((float)$l['cantidad_inicial'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono font-bold <?php echo ((float)$l['cantidad_actual'] > 0) ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'; ?>">
                                        <?php echo number_format((float)$l['cantidad_actual'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono text-slate-800 dark:text-slate-200">
                                        S/ <?php echo number_format((float)$l['costo_unitario'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-center">
                                        <?php if (!empty($l['documento_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($l['documento_url']); ?>" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-bold bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 transition-colors" title="<?php echo htmlspecialchars($l['documento_nombre'] ?: 'Documento'); ?>">
                                                <i class="fa-solid fa-paperclip text-xs"></i>
                                                <span>Ver Documento</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400 italic">Sin sustento</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 3: ALERTAS DE STOCK & REABASTECIMIENTO                      -->
        <!-- ==================================================================== -->
        <div id="tab_content_alertas" class="tab-pane space-y-4 <?php echo ($activeTab !== 'alertas') ? 'hidden' : ''; ?>">
            <div>
                <h3 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i>
                    <span>Seguimiento de Stock Crítico y Productos Agotados</span>
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Control preventivo para evitar quiebres de inventario durante la emisión de facturas o cotizaciones.</p>
            </div>

            <?php if ($totalAlertas === 0): ?>
                <div class="app-card p-10 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-3 text-2xl">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <h4 class="text-base font-extrabold text-slate-900 dark:text-white">Inventario en Nivel Óptimo</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-md mx-auto">Todos los productos del catálogo cuentan con existencias saludables por encima de sus límites de seguridad.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($productos as $p): 
                        $st = (float)$p['stock'];
                        $min = (float)($p['stock_minimo'] ?? 10);
                        $esServicio = (strtoupper($p['unidad_medida'] ?? '') === 'ZZ') || str_starts_with(strtoupper($p['codigo'] ?? ''), 'SRV');
                        if ($esServicio || ($st > $min)) continue;
                        $esAgotado = ($st <= 0);
                    ?>
                    <div class="app-card p-4 sm:p-5 border-l-4 <?php echo $esAgotado ? 'border-l-red-500 bg-red-50/20 dark:bg-red-500/5' : 'border-l-amber-500 bg-amber-50/20 dark:bg-amber-500/5'; ?> flex flex-col justify-between gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <span class="font-mono text-[10px] font-bold px-2 py-0.5 rounded-md <?php echo $esAgotado ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300' : 'bg-amber-100 dark:bg-amber-500/20 text-amber-800 dark:text-amber-300'; ?>">
                                    <?php echo htmlspecialchars($p['codigo']); ?>
                                </span>
                                <h4 class="text-sm font-extrabold text-slate-900 dark:text-white mt-1.5"><?php echo htmlspecialchars($p['descripcion']); ?></h4>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Unidad: <?php echo htmlspecialchars($p['unidad_medida']); ?> • Lote activo: <span class="font-mono font-bold text-indigo-600"><?php echo htmlspecialchars($p['lote_actual'] ?: 'N/A'); ?></span></p>
                            </div>
                            <div class="text-right">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Stock Actual</span>
                                <p class="text-2xl font-black <?php echo $esAgotado ? 'text-red-600' : 'text-amber-600'; ?> font-mono">
                                    <?php echo number_format($st, 2); ?>
                                </p>
                                <span class="text-[10px] text-slate-400">Mínimo sugerido: <?php echo number_format($min, 0); ?></span>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                            <span class="text-[11px] font-semibold <?php echo $esAgotado ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'; ?> flex items-center gap-1.5">
                                <i class="<?php echo $esAgotado ? 'fa-solid fa-circle-xmark text-red-500' : 'fa-solid fa-triangle-exclamation text-amber-500'; ?>"></i>
                                <?php echo $esAgotado ? 'Sin existencias en almacén' : 'Por debajo del stock mínimo'; ?>
                            </span>
                            <button type="button" onclick='abrirModalReabastecer(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shadow-xs">
                                <i class="fa-solid fa-cart-flatbed"></i>
                                <span>Reabastecer (+ Lote)</span>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 4: HISTORIAL KARDEX / MOVIMIENTOS                           -->
        <!-- ==================================================================== -->
        <div id="tab_content_movimientos" class="tab-pane space-y-4 <?php echo ($activeTab !== 'movimientos') ? 'hidden' : ''; ?>">
            <div>
                <h3 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-brand-600"></i>
                    <span>Kardex de Movimientos de Inventario</span>
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Historial sincronizado automáticamente con facturación, compras y exportaciones.</p>
            </div>

            <div class="app-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200/80 dark:border-slate-700 uppercase tracking-wider text-[11px]">
                                <th class="px-4 py-3.5">Fecha & Hora</th>
                                <th class="px-4 py-3.5">Producto</th>
                                <th class="px-4 py-3.5">Lote Afectado</th>
                                <th class="px-4 py-3.5">Tipo Movimiento</th>
                                <th class="px-4 py-3.5">Cantidad</th>
                                <th class="px-4 py-3.5">Stock Final</th>
                                <th class="px-4 py-3.5">Motivo & Referencia</th>
                                <th class="px-4 py-3.5">Responsable</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium text-slate-700 dark:text-slate-200">
                            <?php if (empty($movimientos)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-10 text-center text-slate-400">
                                        No se han registrado movimientos de inventario aún.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movimientos as $m): 
                                    $isEntrada = ($m['tipo_movimiento'] === 'ENTRADA');
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                                    <td class="px-4 py-3.5 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                        <?php echo date('d/m/Y H:i', strtotime($m['created_at'])); ?>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <p class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($m['prod_desc']); ?></p>
                                        <p class="text-[10px] font-mono text-slate-400"><?php echo htmlspecialchars($m['prod_codigo']); ?></p>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono text-indigo-600 dark:text-indigo-400 font-bold">
                                        <?php echo htmlspecialchars($m['codigo_lote'] ?: '-'); ?>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-extrabold <?php echo $isEntrada ? 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-800 dark:text-emerald-300' : 'bg-rose-100 dark:bg-rose-500/20 text-rose-800 dark:text-rose-300'; ?>">
                                            <i class="<?php echo $isEntrada ? 'fa-solid fa-arrow-down' : 'fa-solid fa-arrow-up'; ?> text-[9px]"></i>
                                            <?php echo htmlspecialchars($m['tipo_movimiento']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono font-bold <?php echo $isEntrada ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'; ?>">
                                        <?php echo $isEntrada ? '+' : '-'; ?><?php echo number_format((float)$m['cantidad'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono font-bold text-slate-900 dark:text-white">
                                        <?php echo number_format((float)$m['stock_resultante'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <p class="font-medium text-slate-800 dark:text-slate-200"><?php echo htmlspecialchars($m['motivo']); ?></p>
                                        <?php if (!empty($m['referencia_doc'])): ?>
                                            <span class="text-[10px] font-mono text-slate-400">Doc: <?php echo htmlspecialchars($m['referencia_doc']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-slate-600 dark:text-slate-400 text-[11px]">
                                        <?php echo htmlspecialchars($m['usuario_nombre'] ?: 'Sistema'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- ============================================================================
     MODAL: NUEVO PRODUCTO O SERVICIO (CON PESTAÑAS DE TRAZABILIDAD & LOTE)
     ============================================================================ -->
<div id="modalNuevoProducto" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-2xl w-full overflow-hidden border border-slate-200 dark:border-slate-800 my-8">
        
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
            <div>
                <h3 class="font-extrabold text-slate-900 dark:text-white text-sm sm:text-base flex items-center gap-2">
                    <i class="fa-solid fa-box text-brand-600"></i>
                    <span>Registrar Nuevo Producto o Servicio</span>
                </h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Crea el producto y genera automáticamente su lote con trazabilidad de compra.</p>
            </div>
            <button type="button" onclick="document.getElementById('modalNuevoProducto').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="p-6 space-y-5">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="crear_producto" value="1">

            <!-- Subpestañas del Modal -->
            <div class="flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-2">
                <button type="button" onclick="switchModalTab('datos', this)" class="modal-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-50 text-brand-600 dark:bg-brand-500/20 dark:text-brand-300">
                    1. Datos Comerciales & Stock
                </button>
                <button type="button" onclick="switchModalTab('trazabilidad', this)" class="modal-tab-btn px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-white">
                    2. Trazabilidad de Compra & Lote Automático
                </button>
            </div>

            <!-- Panel 1: Datos Comerciales -->
            <div id="modal_panel_datos" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nombre del Producto / Servicio *</label>
                        <input type="text" name="nombre_producto" id="inp_nombre_producto" oninput="sincronizarDescripcion(this.value)" placeholder="Ej. Café Arábica Grano 250g" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Código del Ítem *</label>
                        <input type="text" name="codigo" id="inp_codigo_producto" placeholder="Ej. PRD-005 o SRV-002" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 uppercase focus:outline-none focus:border-brand-500 font-mono font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Unidad de Medida (SUNAT) *</label>
                        <select name="unidad_medida" id="inp_nueva_unidad" onchange="handleNuevaUnidadChange(this.value)" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-medium">
                            <option value="NIU">NIU - Unidades</option>
                            <option value="KGM">KGM - Kilogramos</option>
                            <option value="TNE">TNE - Toneladas</option>
                            <option value="LTR">LTR - Litros</option>
                            <option value="ZZ">ZZ - Servicios / Mutuo Acuerdo</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Partida Arancelaria (Exportación)</label>
                        <input type="text" name="codigo_partida" placeholder="Ej. 0901.21.00.00" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono focus:outline-none focus:border-brand-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Descripción Comercial Detallada</label>
                    <input type="text" name="descripcion" id="inp_descripcion_producto" placeholder="Descripción adicional o especificaciones técnicas..." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-medium">
                </div>

                <!-- Foto del Producto: Subida o Pegar URL con Vista Previa -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                            <i class="fa-solid fa-camera text-indigo-600"></i>
                            <span>Imagen del Producto (Foto o URL)</span>
                        </label>
                        <span class="text-[10px] text-slate-400">JPG, PNG, WEBP o enlace web</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                        <div class="sm:col-span-8 space-y-2">
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">Subir Archivo de Imagen</label>
                                <input type="file" name="imagen_archivo" id="inp_imagen_archivo" accept="image/*" onchange="previewProductImageFile(this)" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-1.5 text-xs text-slate-700 dark:text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">O Pegar URL de Imagen</label>
                                <input type="url" name="imagen_url" id="inp_imagen_url" oninput="previewProductImageUrl(this.value)" placeholder="https://ejemplo.com/foto-producto.jpg" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                            </div>
                        </div>

                        <!-- Vista Previa de la Imagen -->
                        <div class="sm:col-span-4 flex flex-col items-center justify-center p-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 h-28">
                            <img id="img_preview_box" src="" alt="Vista previa" class="w-full h-full object-contain rounded-lg hidden">
                            <div id="img_preview_placeholder" class="text-center text-slate-400 text-xs">
                                <i class="fa-solid fa-image text-2xl mb-1 text-slate-300 dark:text-slate-600 block"></i>
                                <span>Vista previa</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Precio Venta (S/) *</label>
                        <input type="number" step="0.01" min="0" name="precio_unitario" placeholder="0.00" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Stock Inicial *</label>
                        <input type="number" step="0.01" min="0" name="stock" id="inp_nuevo_stock" value="0" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono font-bold">
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1" title="Lanza alerta visual si el stock baja a esta cantidad o menos">
                            Stock Mín. Alerta <i class="fa-solid fa-circle-question text-[10px] text-amber-500"></i>
                        </label>
                        <input type="number" step="1" min="0" name="stock_minimo" value="10" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono">
                    </div>
                </div>
            </div>

            <!-- Panel 2: Trazabilidad de Compra & Lote Automático -->
            <div id="modal_panel_trazabilidad" class="space-y-4 hidden">
                <div class="p-3.5 rounded-2xl bg-indigo-50/70 dark:bg-indigo-500/10 border border-indigo-200/80 dark:border-indigo-500/30 flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xs shrink-0 shadow-xs">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                        </div>
                        <div>
                            <p class="text-xs font-extrabold text-indigo-950 dark:text-indigo-200">Generación Automática de Lote</p>
                            <p class="text-[11px] text-indigo-800 dark:text-indigo-300/90 mt-0.5">Al guardar este producto se creará de inmediato un lote único (ej. <span class="font-mono font-bold">LOT-<?php echo date('Ymd'); ?>-XXX</span>) vinculado con la trazabilidad registrada.</p>
                        </div>
                    </div>

                    <!-- Switcher Moderno: Relleno Automático vs Manual -->
                    <div class="shrink-0 flex items-center gap-2 bg-white dark:bg-slate-800 px-3 py-1.5 rounded-xl border border-indigo-100 dark:border-slate-700 shadow-2xs">
                        <label for="switch_auto_fill" class="custom-switch-label text-[11px] font-bold text-slate-700 dark:text-slate-300">
                            <span id="label_switch_mode">Auto-llenar por Documento</span>
                            <input type="checkbox" id="switch_auto_fill" switch checked onchange="toggleModoRellenoDocumento(this.checked)" class="custom-switch-input" title="Alternar entre llenado automático por documento y llenado manual">
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Fecha de Compra</label>
                        <input type="date" name="fecha_compra" id="lot_fecha_compra" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Hora de Compra</label>
                        <input type="time" name="hora_compra" id="lot_hora_compra" value="<?php echo date('H:i'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Comprado / Registrado Por</label>
                        <input type="text" name="comprado_por" id="lot_comprado_por" value="<?php echo htmlspecialchars($currentUser['nombre'] ?? 'Administrador'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Costo Unitario de Compra (S/)</label>
                        <input type="number" step="0.01" min="0" name="costo_compra" id="lot_costo_compra" placeholder="0.00" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Tipo de Sustento de Compra</label>
                        <select name="tipo_documento" id="lot_tipo_documento" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                            <option value="Factura de Compra">Factura de Compra</option>
                            <option value="Boleta de Compra">Boleta de Compra</option>
                            <option value="Guía de Remisión">Guía de Remisión</option>
                            <option value="DUA / DAM de Importación">DUA / DAM de Importación</option>
                            <option value="Orden de Compra">Orden de Compra</option>
                            <option value="Liquidación de Compra">Liquidación de Compra</option>
                            <option value="Inventario Inicial">Inventario Inicial</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">N° Comprobante / Serie</label>
                        <input type="text" name="numero_documento" id="lot_numero_documento" placeholder="Ej. F001-002482" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Proveedor (Razón Social o Nombre)</label>
                        <input type="text" name="proveedor_nombre" id="lot_proveedor_nombre" placeholder="Ej. Agroinsumos del Perú S.A.C." class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">RUC del Proveedor (Opcional)</label>
                        <input type="text" name="proveedor_ruc" id="lot_proveedor_ruc" maxlength="11" placeholder="20123456789" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono">
                    </div>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-0.5">
                            Subir Documento de Compra (XML, PDF, JPG, PNG, WEBP)
                        </label>
                        <span id="badge_doc_status" class="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/15 px-2 py-0.5 rounded-full">
                            Auto-detección activa
                        </span>
                    </div>
                    <input type="file" name="documento_compra" id="inp_doc_compra_file" onchange="procesarArchivoCompra(this)" accept=".xml,.pdf,.jpg,.jpeg,.png,.webp" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2 text-xs text-slate-700 dark:text-slate-300 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="text-[10px] text-slate-400">
                        <span class="font-bold text-indigo-600 dark:text-indigo-400"><i class="fa-solid fa-sparkles mr-1"></i>Tip:</span> Si subes una Factura Electrónica XML (UBL) o PDF con nombre formal, se auto-rellenarán la fecha, RUC, proveedor, N° de comprobante y costo de compra automáticamente.
                    </p>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <button type="button" onclick="document.getElementById('modalNuevoProducto').classList.add('hidden')" class="px-4 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold rounded-xl shadow-md shadow-brand-600/25 flex items-center gap-1.5 transition-all">
                    <i class="fa-solid fa-save"></i>
                    <span>Guardar Producto & Generar Lote</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================
     MODAL: EDITAR PRODUCTO INDIVIDUAL
     ============================================================================ -->
<div id="modalEditarProducto" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200 dark:border-slate-800 my-8">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
            <h3 class="font-extrabold text-slate-900 dark:text-white text-sm flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-indigo-600"></i>
                <span>Editar Producto o Servicio</span>
            </h3>
            <button type="button" onclick="document.getElementById('modalEditarProducto').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="editar_producto" value="1">
            <input type="hidden" name="producto_id" id="edit_producto_id" value="">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nombre del Producto / Servicio *</label>
                    <input type="text" name="nombre_producto" id="edit_nombre_producto" placeholder="Ej. Café Arábica Grano 250g" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Código del Ítem *</label>
                    <input type="text" name="codigo" id="edit_codigo" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 uppercase focus:outline-none focus:border-brand-500 font-mono font-bold">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Unidad de Medida</label>
                    <select name="unidad_medida" id="edit_unidad_medida" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                        <option value="NIU">NIU - Unidades</option>
                        <option value="KGM">KGM - Kilogramos</option>
                        <option value="TNE">TNE - Toneladas</option>
                        <option value="LTR">LTR - Litros</option>
                        <option value="ZZ">ZZ - Servicios / Mutuo Acuerdo</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Partida Arancelaria</label>
                    <input type="text" name="codigo_partida" id="edit_codigo_partida" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono focus:outline-none focus:border-brand-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Descripción Comercial Detallada</label>
                <input type="text" name="descripcion" id="edit_descripcion" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-medium">
            </div>

            <!-- Foto del Producto en Edición: Subir o Pegar URL -->
            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                        <i class="fa-solid fa-camera text-indigo-600"></i>
                        <span>Imagen del Producto (Foto o URL)</span>
                    </label>
                    <span class="text-[10px] text-slate-400">JPG, PNG, WEBP o enlace web</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <div class="sm:col-span-8 space-y-2">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">Cambiar Archivo de Imagen</label>
                            <input type="file" name="imagen_archivo" id="edit_imagen_archivo" accept="image/*" onchange="previewEditProductImageFile(this)" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-1.5 text-xs text-slate-700 dark:text-slate-300 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">O Actualizar URL de Imagen</label>
                            <input type="url" name="imagen_url" id="edit_imagen_url" oninput="previewEditProductImageUrl(this.value)" placeholder="https://ejemplo.com/foto-producto.jpg" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                        </div>
                    </div>

                    <!-- Vista Previa de la Imagen en Edición -->
                    <div class="sm:col-span-4 flex flex-col items-center justify-center p-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 h-28">
                        <img id="edit_img_preview_box" src="" alt="Vista previa" class="w-full h-full object-contain rounded-lg hidden">
                        <div id="edit_img_preview_placeholder" class="text-center text-slate-400 text-xs">
                            <i class="fa-solid fa-image text-2xl mb-1 text-slate-300 dark:text-slate-600 block"></i>
                            <span>Sin imagen</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Precio Unitario (S/)</label>
                    <input type="number" step="0.01" min="0" name="precio_unitario" id="edit_precio_unitario" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Costo de Compra (S/)</label>
                    <input type="number" step="0.01" min="0" name="costo_compra" id="edit_costo_compra" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Stock Actual</label>
                    <input type="number" step="0.01" min="0" name="stock" id="edit_stock" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Stock Mínimo (Alerta)</label>
                    <input type="number" step="1" min="0" name="stock_minimo" id="edit_stock_minimo" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Partida Arancelaria</label>
                <input type="text" name="codigo_partida" id="edit_codigo_partida" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono focus:outline-none focus:border-brand-500">
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalEditarProducto').classList.add('hidden')" class="px-4 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-sm">
                    <i class="fa-solid fa-save mr-1.5"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================
     MODAL: REABASTECER PRODUCTO CON NUEVO LOTE & COMPRA
     ============================================================================ -->
<div id="modalReabastecerLote" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-xl w-full overflow-hidden border border-slate-200 dark:border-slate-800 my-8">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
            <div>
                <h3 class="font-extrabold text-slate-900 dark:text-white text-sm sm:text-base flex items-center gap-2">
                    <i class="fa-solid fa-cart-flatbed text-indigo-600"></i>
                    <span>Registrar Nuevo Lote / Reabastecimiento</span>
                </h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Ingresa nuevo inventario físico y guarda el comprobante de adquisición.</p>
            </div>
            <button type="button" onclick="document.getElementById('modalReabastecerLote').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="reabastecer_lote" value="1">

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Producto a Reabastecer *</label>
                <select name="producto_id" id="reab_producto_id" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-semibold">
                    <option value="">Selecciona un producto...</option>
                    <?php foreach ($productos as $pr): 
                        if (strtoupper($pr['unidad_medida'] ?? '') === 'ZZ') continue;
                    ?>
                        <option value="<?php echo $pr['id']; ?>">
                            <?php echo htmlspecialchars($pr['codigo']); ?> — <?php echo htmlspecialchars($pr['descripcion']); ?> (Stock actual: <?php echo number_format((float)$pr['stock'], 2); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Cantidad Comprada *</label>
                    <input type="number" step="0.01" min="0.01" name="cantidad_compra" placeholder="0.00" required class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono font-bold focus:outline-none focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Costo Unitario (S/)</label>
                    <input type="number" step="0.01" min="0" name="costo_unitario" placeholder="0.00" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono font-bold focus:outline-none focus:border-brand-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Fecha de Compra</label>
                    <input type="date" name="fecha_compra" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Hora de Compra</label>
                    <input type="time" name="hora_compra" value="<?php echo date('H:i'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Comprado Por</label>
                    <input type="text" name="comprado_por" value="<?php echo htmlspecialchars($currentUser['nombre'] ?? 'Administrador'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-medium">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">N° Comprobante Compra</label>
                    <input type="text" name="numero_documento" placeholder="Ej. F001-00984" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500 font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Subir Factura / Sustento de Compra</label>
                <input type="file" name="documento_compra" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2 text-xs text-slate-700 dark:text-slate-300 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700">
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalReabastecerLote').classList.add('hidden')" class="px-4 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-sm">
                    <i class="fa-solid fa-check mr-1.5"></i> Registrar Lote & Aumentar Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================
     MODAL: EDICIÓN MASIVA DE PRODUCTOS (BULK EDIT)
     ============================================================================ -->
<div id="modalEdicionMasiva" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200 dark:border-slate-800 my-8">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
            <h3 class="font-extrabold text-slate-900 dark:text-white text-sm flex items-center gap-2">
                <i class="fa-solid fa-layer-group text-indigo-600"></i>
                <span>Edición Masiva de Productos</span>
            </h3>
            <button type="button" onclick="document.getElementById('modalEdicionMasiva').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="" id="formEdicionMasiva" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="accion_masiva" value="1">
            <input type="hidden" name="tipo_accion_masiva" value="editar_masivo">
            <div id="containerIdsEdicionMasiva"></div>

            <div class="p-3 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 text-xs text-indigo-900 dark:text-indigo-200">
                Se aplicarán los cambios a <strong id="bulkEditCountText" class="font-extrabold">0 productos</strong> seleccionados. Solo los campos que modifiques serán actualizados.
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Ajuste de Precios de Venta</label>
                <div class="grid grid-cols-2 gap-2">
                    <select name="bulk_ajuste_precio_tipo" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none">
                        <option value="ninguno">No modificar precios</option>
                        <option value="aumento_porcentaje">Aumentar precio en %</option>
                        <option value="descuento_porcentaje">Descontar precio en %</option>
                        <option value="precio_fijo">Asignar precio fijo (S/)</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="bulk_ajuste_precio_valor" placeholder="Valor (ej. 10)" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Cambiar Unidad de Medida (Opcional)</label>
                <select name="bulk_unidad_medida" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 focus:outline-none">
                    <option value="">Mantener unidad actual de cada producto</option>
                    <option value="NIU">NIU - Unidades</option>
                    <option value="KGM">KGM - Kilogramos</option>
                    <option value="TNE">TNE - Toneladas</option>
                    <option value="LTR">LTR - Litros</option>
                    <option value="ZZ">ZZ - Servicios</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Nuevo Umbral de Stock Mínimo (Alerta)</label>
                <input type="number" step="1" min="0" name="bulk_stock_minimo" placeholder="Dejar vacío para no cambiar" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-mono">
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalEdicionMasiva').classList.add('hidden')" class="px-4 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-sm">
                    Aplicar Cambios Masivos
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================
     MODAL: COMPARTIR INDIVIDUAL O MÚLTIPLES PRODUCTOS (WHATSAPP & ENLACE)
     ============================================================================ -->
<div id="modalCompartirProducto" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200 dark:border-slate-800 my-8">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/50">
            <h3 class="font-extrabold text-slate-900 dark:text-white text-sm flex items-center gap-2">
                <i class="fa-solid fa-share-nodes text-emerald-600"></i>
                <span id="share_modal_title">Compartir Ficha de Producto</span>
            </h3>
            <button type="button" onclick="document.getElementById('modalCompartirProducto').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div class="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-700" id="share_single_box">
                <span class="text-[10px] font-mono font-bold text-slate-400 uppercase" id="share_codigo">PRD-001</span>
                <h4 class="text-sm font-extrabold text-slate-900 dark:text-white mt-0.5" id="share_descripcion">Nombre del Producto</h4>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-xs font-extrabold text-emerald-600 dark:text-emerald-400" id="share_precio">S/ 0.00</p>
                    <span class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400" id="share_lote"></span>
                </div>
            </div>

            <div id="share_multi_box" class="hidden bg-slate-50 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-700 max-h-48 overflow-y-auto">
                <p class="text-xs font-bold text-slate-800 dark:text-slate-200 mb-2">Resumen de Selección Comercial:</p>
                <div id="share_multi_list" class="space-y-1.5 text-xs"></div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Texto o Enlace a Compartir</label>
                <div class="flex gap-2">
                    <input type="text" id="share_url_input" readonly class="flex-1 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs text-slate-700 dark:text-slate-200 font-mono select-all focus:outline-none">
                    <button type="button" onclick="copiarEnlacePublico()" id="btn_copiar_link" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 shrink-0">
                        <i class="fa-solid fa-copy"></i>
                        <span id="btn_copiar_texto">Copiar</span>
                    </button>
                </div>
            </div>

            <div class="pt-2 flex flex-col gap-2">
                <a id="btn_whatsapp_share" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition-all flex items-center justify-center gap-2 shadow-sm">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Compartir por WhatsApp</span>
                </a>
                <a id="btn_ver_publico" href="#" target="_blank" class="w-full py-2.5 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-semibold rounded-xl transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Abrir Ficha Pública</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     FORMULARIO OCULTO PARA ELIMINACIÓN MASIVA
     ============================================================================ -->
<form method="POST" action="" id="formEliminarMasivo" class="hidden">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="accion_masiva" value="1">
    <input type="hidden" name="tipo_accion_masiva" value="eliminar">
    <div id="containerIdsEliminarMasivo"></div>
</form>

<script>
// ============================================================================
// GESTIÓN DE PESTAÑAS DEL MÓDULO & URL HASH
// ============================================================================
function cambiarPestana(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('border-brand-600', 'text-brand-600', 'dark:text-brand-400', 'bg-brand-50/60', 'dark:bg-brand-500/10');
        btn.classList.add('border-transparent', 'text-slate-500');
    });

    const activeBtn = document.getElementById('tab_btn_' + tabName);
    if (activeBtn) {
        activeBtn.classList.add('border-brand-600', 'text-brand-600', 'dark:text-brand-400', 'bg-brand-50/60', 'dark:bg-brand-500/10');
        activeBtn.classList.remove('border-transparent', 'text-slate-500');
    }

    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.add('hidden'));
    const activePane = document.getElementById('tab_content_' + tabName);
    if (activePane) activePane.classList.remove('hidden');

    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);
}

// Subpestañas dentro del Modal "Nuevo Producto"
function switchModalTab(tab, btn) {
    document.querySelectorAll('.modal-tab-btn').forEach(b => {
        b.classList.remove('bg-brand-50', 'text-brand-600', 'dark:bg-brand-500/20', 'dark:text-brand-300', 'font-bold');
        b.classList.add('text-slate-500', 'font-semibold');
    });
    btn.classList.add('bg-brand-50', 'text-brand-600', 'dark:bg-brand-500/20', 'dark:text-brand-300', 'font-bold');
    btn.classList.remove('text-slate-500', 'font-semibold');

    document.getElementById('modal_panel_datos').classList.toggle('hidden', tab !== 'datos');
    document.getElementById('modal_panel_trazabilidad').classList.toggle('hidden', tab !== 'trazabilidad');
}

function handleNuevaUnidadChange(val) {
    const stockInp = document.getElementById('inp_nuevo_stock');
    if (val === 'ZZ') {
        stockInp.value = 999;
    }
}

// Sincronizar automáticamente el Nombre del producto con la Descripción comercial
function sincronizarDescripcion(val) {
    const desc = document.getElementById('inp_descripcion_producto');
    if (desc && (!desc.value || desc.dataset.autoSynced === 'true')) {
        desc.value = val;
        desc.dataset.autoSynced = 'true';
    }
}
document.getElementById('inp_descripcion_producto')?.addEventListener('input', function() {
    this.dataset.autoSynced = 'false';
});

// Previsualización de Imagen: Subida de Archivo (Nuevo Producto)
function previewProductImageFile(input) {
    const preview = document.getElementById('img_preview_box');
    const placeholder = document.getElementById('img_preview_placeholder');
    const urlInput = document.getElementById('inp_imagen_url');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (preview) {
                preview.src = e.target.result;
                preview.classList.remove('hidden');
            }
            if (placeholder) placeholder.classList.add('hidden');
            if (urlInput) urlInput.value = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Previsualización de Imagen: Pegar Enlace URL (Nuevo Producto)
function previewProductImageUrl(url) {
    const preview = document.getElementById('img_preview_box');
    const placeholder = document.getElementById('img_preview_placeholder');
    const fileInput = document.getElementById('inp_imagen_archivo');
    if (url && url.trim() !== '') {
        if (fileInput) fileInput.value = '';
        if (preview) {
            preview.src = url.trim();
            preview.classList.remove('hidden');
            preview.onerror = function() {
                preview.classList.add('hidden');
                if (placeholder) placeholder.classList.remove('hidden');
            };
            preview.onload = function() {
                preview.classList.remove('hidden');
                if (placeholder) placeholder.classList.add('hidden');
            };
        }
        if (placeholder) placeholder.classList.add('hidden');
    } else {
        if (preview) preview.classList.add('hidden');
        if (placeholder) placeholder.classList.remove('hidden');
    }
}

// Previsualización de Imagen: Subida de Archivo (Editar Producto)
function previewEditProductImageFile(input) {
    const preview = document.getElementById('edit_img_preview_box');
    const placeholder = document.getElementById('edit_img_preview_placeholder');
    const urlInput = document.getElementById('edit_imagen_url');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (preview) {
                preview.src = e.target.result;
                preview.classList.remove('hidden');
            }
            if (placeholder) placeholder.classList.add('hidden');
            if (urlInput) urlInput.value = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Previsualización de Imagen: Pegar Enlace URL (Editar Producto)
function previewEditProductImageUrl(url) {
    const preview = document.getElementById('edit_img_preview_box');
    const placeholder = document.getElementById('edit_img_preview_placeholder');
    if (url && url.trim() !== '') {
        if (preview) {
            preview.src = url.trim();
            preview.classList.remove('hidden');
            preview.onerror = function() {
                preview.classList.add('hidden');
                if (placeholder) placeholder.classList.remove('hidden');
            };
            preview.onload = function() {
                preview.classList.remove('hidden');
                if (placeholder) placeholder.classList.add('hidden');
            };
        }
        if (placeholder) placeholder.classList.add('hidden');
    } else {
        if (preview) preview.classList.add('hidden');
        if (placeholder) placeholder.classList.remove('hidden');
    }
}

// Alternar entre modo auto-detección y modo manual en Trazabilidad de Compra
function toggleModoRellenoDocumento(isChecked) {
    const label = document.getElementById('label_switch_mode');
    const badge = document.getElementById('badge_doc_status');
    if (isChecked) {
        if (label) label.textContent = 'Auto-llenar por Documento';
        if (badge) {
            badge.textContent = 'Auto-detección activa';
            badge.className = 'text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/15 px-2 py-0.5 rounded-full';
        }
        if (typeof window.showToast === 'function') {
            window.showToast('Modo activo: Al subir un comprobante XML o PDF los campos se rellenarán automáticamente.', 'info');
        }
    } else {
        if (label) label.textContent = 'Llenado Manual';
        if (badge) {
            badge.textContent = 'Modo Manual Activo';
            badge.className = 'text-[10px] font-semibold text-slate-500 bg-slate-100 dark:bg-slate-700/50 px-2 py-0.5 rounded-full';
        }
        if (typeof window.showToast === 'function') {
            window.showToast('Modo manual activado: Puedes ingresar o editar los campos de compra manualmente.', 'info');
        }
    }
}

// Procesar Documento de Compra y Auto-rellenar formulario
async function procesarArchivoCompra(input) {
    const switchAuto = document.getElementById('switch_auto_fill');
    if (switchAuto && !switchAuto.checked) {
        return; // El usuario prefirió llenar de forma manual
    }

    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    const badge = document.getElementById('badge_doc_status');
    if (badge) {
        badge.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Analizando comprobante...';
        badge.className = 'text-[10px] font-semibold text-amber-600 bg-amber-50 dark:bg-amber-500/15 px-2 py-0.5 rounded-full';
    }

    const formData = new FormData();
    formData.append('documento_compra', file);

    try {
        const response = await fetch('api/parsear_documento_compra.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success && result.data) {
            const d = result.data;

            // Rellenar tipo y serie/número de documento
            if (d.tipo_documento) {
                const el = document.getElementById('lot_tipo_documento');
                if (el) el.value = d.tipo_documento;
            }
            if (d.numero_documento) {
                const el = document.getElementById('lot_numero_documento');
                if (el) el.value = d.numero_documento;
            }

            // Fecha y hora de compra
            if (d.fecha_compra) {
                const el = document.getElementById('lot_fecha_compra');
                if (el) el.value = d.fecha_compra;
            }
            if (d.hora_compra) {
                const el = document.getElementById('lot_hora_compra');
                if (el) el.value = d.hora_compra;
            }

            // Proveedor y RUC
            if (d.proveedor_ruc) {
                const el = document.getElementById('lot_proveedor_ruc');
                if (el) el.value = d.proveedor_ruc;
            }
            if (d.proveedor_nombre) {
                const el = document.getElementById('lot_proveedor_nombre');
                if (el) el.value = d.proveedor_nombre;
            }

            // Costo unitario y cantidad inicial
            if (d.costo_unitario && parseFloat(d.costo_unitario) > 0) {
                const el = document.getElementById('lot_costo_compra');
                if (el) el.value = parseFloat(d.costo_unitario).toFixed(2);
            }
            if (d.cantidad && parseFloat(d.cantidad) > 0) {
                const elStock = document.getElementById('inp_nuevo_stock');
                if (elStock && (!elStock.value || parseFloat(elStock.value) === 0)) {
                    elStock.value = parseFloat(d.cantidad);
                }
            }

            // Descripción o nombre sugerido si está vacío
            if (d.descripcion) {
                const elNom = document.getElementById('inp_nombre_producto');
                if (elNom && !elNom.value) {
                    elNom.value = d.descripcion;
                    sincronizarDescripcion(d.descripcion);
                }
            }

            if (badge) {
                badge.innerHTML = '<i class="fa-solid fa-circle-check mr-1 text-emerald-500"></i> Auto-rellenado con éxito';
                badge.className = 'text-[10px] font-semibold text-emerald-700 bg-emerald-50 dark:bg-emerald-500/15 px-2 py-0.5 rounded-full';
            }

            if (typeof window.showToast === 'function') {
                window.showToast(result.message || 'Datos de compra extraídos y aplicados al formulario con éxito.', 'success', 'Comprobante Procesado');
            }
        } else {
            if (badge) {
                badge.textContent = 'Auto-detección activa';
                badge.className = 'text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/15 px-2 py-0.5 rounded-full';
            }
            if (result.message && typeof window.showToast === 'function') {
                window.showToast(result.message, 'warning');
            }
        }
    } catch (err) {
        console.error('Error al analizar comprobante:', err);
        if (badge) {
            badge.textContent = 'Auto-detección activa';
            badge.className = 'text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/15 px-2 py-0.5 rounded-full';
        }
        if (typeof window.showToast === 'function') {
            window.showToast('No se pudo procesar el documento automáticamente. Puedes ingresar los datos de forma manual.', 'warning');
        }
    }
}

// Búsqueda en tiempo real
document.getElementById('searchProductos')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableProductos tbody tr:not(.empty-table-row)');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
});

// Filtros rápidos por estado de stock
function filtrarEstadoStock(estado, btn) {
    document.querySelectorAll('.btn-filtro-stock').forEach(b => {
        b.classList.remove('bg-brand-50', 'text-brand-700', 'dark:bg-brand-500/20', 'dark:text-brand-300', 'font-bold');
        b.classList.add('text-slate-600', 'dark:text-slate-400');
    });
    btn.classList.add('bg-brand-50', 'text-brand-700', 'dark:bg-brand-500/20', 'dark:text-brand-300', 'font-bold');
    btn.classList.remove('text-slate-600', 'dark:text-slate-400');

    const rows = document.querySelectorAll('#tableProductos tbody tr:not(.empty-table-row)');
    rows.forEach(row => {
        const status = row.getAttribute('data-stock-status');
        if (estado === 'todos') {
            row.style.display = '';
        } else if (estado === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// ============================================================================
// CHECKBOXES MODERNOS, SELECCIÓN MÚLTIPLE & BARRA DE ACCIONES
// ============================================================================
function getSelectedProducts() {
    const checkboxes = document.querySelectorAll('.prod-checkbox:checked');
    const selected = [];
    checkboxes.forEach(cb => {
        try {
            const data = JSON.parse(cb.getAttribute('data-prod'));
            selected.push(data);
        } catch (e) {}
    });
    return selected;
}

function handleProductCheckChange() {
    const checkboxes = Array.from(document.querySelectorAll('.prod-checkbox'));
    const checked = checkboxes.filter(cb => cb.checked);
    const count = checked.length;
    const checkAll = document.getElementById('checkAllProductos');

    // Sincronizar estado maestro (indeterminate)
    if (checkAll) {
        checkAll.checked = (count === checkboxes.length && checkboxes.length > 0);
        checkAll.indeterminate = (count > 0 && count < checkboxes.length);
    }

    // Fallback de clase para navegadores sin :has()
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        if (row) {
            row.classList.toggle('is-row-selected', cb.checked);
        }
    });

    // Actualizar barra de acciones flotante
    const actionBar = document.getElementById('selectionActionBar');
    if (!actionBar) return;

    if (count > 0) {
        actionBar.classList.remove('hidden');
        document.getElementById('badgeSelectedCount').textContent = count;
        document.getElementById('textSelectedCount').textContent = `${count} producto${count === 1 ? '' : 's'} seleccionado${count === 1 ? '' : 's'}`;

        const labelEdit = document.getElementById('labelActionEditar');
        const labelShare = document.getElementById('labelActionCompartir');
        const btnReab = document.getElementById('btnActionReabastecer');

        if (count === 1) {
            if (labelEdit) labelEdit.textContent = 'Editar Producto';
            if (labelShare) labelShare.textContent = 'Compartir Ficha';
            if (btnReab) btnReab.classList.remove('hidden');
        } else {
            if (labelEdit) labelEdit.textContent = `Editar Masivo (${count})`;
            if (labelShare) labelShare.textContent = `Compartir Catálogo (${count})`;
            if (btnReab) btnReab.classList.add('hidden');
        }
    } else {
        actionBar.classList.add('hidden');
    }
}

function toggleSelectAll(isChecked) {
    const rows = document.querySelectorAll('#tableProductos tbody tr:not(.empty-table-row)');
    rows.forEach(row => {
        // Solo marcar las filas que están visibles según el filtro/búsqueda
        if (row.style.display !== 'none') {
            const cb = row.querySelector('.prod-checkbox');
            if (cb) cb.checked = isChecked;
        }
    });
    handleProductCheckChange();
}

function deseleccionarTodos() {
    document.querySelectorAll('.prod-checkbox').forEach(cb => cb.checked = false);
    const checkAll = document.getElementById('checkAllProductos');
    if (checkAll) {
        checkAll.checked = false;
        checkAll.indeterminate = false;
    }
    handleProductCheckChange();
}

// Acción: Editar desde la barra de selección
function ejecutarAccionEditar() {
    const selected = getSelectedProducts();
    if (selected.length === 0) return;

    if (selected.length === 1) {
        abrirEditar(selected[0]);
    } else {
        // Abrir Modal de Edición Masiva
        const container = document.getElementById('containerIdsEdicionMasiva');
        container.innerHTML = '';
        selected.forEach(p => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'seleccion_ids[]';
            inp.value = p.id;
            container.appendChild(inp);
        });
        document.getElementById('bulkEditCountText').textContent = `${selected.length} productos`;
        document.getElementById('modalEdicionMasiva').classList.remove('hidden');
    }
}

// Acción: Compartir desde la barra de selección
function ejecutarAccionCompartir() {
    const selected = getSelectedProducts();
    if (selected.length === 0) return;

    if (selected.length === 1) {
        abrirCompartir(selected[0]);
    } else {
        abrirCompartirMultiple(selected);
    }
}

// Acción: Reabastecer desde la barra de selección
function ejecutarAccionReabastecer() {
    const selected = getSelectedProducts();
    if (selected.length === 1) {
        abrirModalReabastecer(selected[0]);
    }
}

// Acción: Eliminar Masiva con confirmación moderna
async function ejecutarAccionEliminarMasiva() {
    const selected = getSelectedProducts();
    if (selected.length === 0) return;

    const count = selected.length;
    const msg = `¿Estás completamente seguro de eliminar los ${count} producto(s) seleccionados del catálogo? Esta acción no se puede deshacer.`;
    
    let confirmado = false;
    if (typeof window.showConfirm === 'function') {
        confirmado = await window.showConfirm({
            title: `Eliminar ${count} Producto${count === 1 ? '' : 's'}`,
            message: msg,
            confirmText: 'Sí, eliminar productos',
            variant: 'danger'
        });
    } else {
        confirmado = confirm(msg);
    }

    if (!confirmado) return;

    const form = document.getElementById('formEliminarMasivo');
    const container = document.getElementById('containerIdsEliminarMasivo');
    container.innerHTML = '';
    selected.forEach(p => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'seleccion_ids[]';
        inp.value = p.id;
        container.appendChild(inp);
    });
    form.submit();
}

// Exportar productos seleccionados (o todos) a CSV
function exportarSeleccionadosCSV() {
    const selected = getSelectedProducts();
    const items = selected.length > 0 ? selected : <?php echo json_encode($productos, JSON_UNESCAPED_UNICODE); ?>;

    if (!items || items.length === 0) {
        if (typeof window.showToast === 'function') window.showToast('No hay productos para exportar.', 'warning');
        return;
    }

    let csvContent = "\uFEFF"; // BOM para Excel UTF-8
    csvContent += "ID,Código,Descripción,Unidad,Precio Venta,Costo Compra,Stock Actual,Stock Mínimo,Lote Actual,Partida Arancelaria\n";

    items.forEach(p => {
        const row = [
            p.id,
            `"${(p.codigo || '').replace(/"/g, '""')}"`,
            `"${(p.descripcion || '').replace(/"/g, '""')}"`,
            p.unidad_medida || 'NIU',
            parseFloat(p.precio_unitario || 0).toFixed(2),
            parseFloat(p.costo_compra || 0).toFixed(2),
            parseFloat(p.stock || 0).toFixed(2),
            parseFloat(p.stock_minimo || 10).toFixed(2),
            `"${(p.lote_actual || '').replace(/"/g, '""')}"`,
            `"${(p.codigo_partida || '').replace(/"/g, '""')}"`
        ];
        csvContent += row.join(",") + "\n";
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", `catalogo_productos_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    if (typeof window.showToast === 'function') {
        window.showToast(`Se exportaron ${items.length} productos a CSV con éxito.`, 'success', 'Exportación Completa');
    }
}

// ============================================================================
// APERTURA DE MODALES
// ============================================================================
function abrirModalNuevoProducto() {
    document.getElementById('modalNuevoProducto').classList.remove('hidden');
}

function abrirEditar(prod) {
    document.getElementById('edit_producto_id').value = prod.id;
    document.getElementById('edit_codigo').value = prod.codigo;
    document.getElementById('edit_nombre_producto').value = prod.nombre_producto || prod.descripcion || '';
    document.getElementById('edit_descripcion').value = prod.descripcion || '';
    document.getElementById('edit_unidad_medida').value = prod.unidad_medida || 'NIU';
    document.getElementById('edit_precio_unitario').value = prod.precio_unitario;
    document.getElementById('edit_costo_compra').value = prod.costo_compra || 0;
    document.getElementById('edit_stock').value = prod.stock;
    document.getElementById('edit_stock_minimo').value = prod.stock_minimo || 10;
    document.getElementById('edit_codigo_partida').value = prod.codigo_partida || '';
    
    // Foto / URL y Vista Previa
    const imgUrlInp = document.getElementById('edit_imagen_url');
    if (imgUrlInp) imgUrlInp.value = prod.imagen_url || '';
    const fileInp = document.getElementById('edit_imagen_archivo');
    if (fileInp) fileInp.value = '';
    previewEditProductImageUrl(prod.imagen_url || '');

    document.getElementById('modalEditarProducto').classList.remove('hidden');
}

function abrirModalReabastecer(prod) {
    const select = document.getElementById('reab_producto_id');
    if (prod && prod.id) {
        select.value = prod.id;
    } else {
        select.value = '';
    }
    document.getElementById('modalReabastecerLote').classList.remove('hidden');
}

function abrirCompartir(prod) {
    const token = prod.token_publico || '';
    const origin = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const url = origin + path + 'producto_publico.php?token=' + encodeURIComponent(token) + '&id=' + prod.id;
    
    document.getElementById('share_modal_title').textContent = 'Compartir Ficha de Producto';
    document.getElementById('share_single_box').classList.remove('hidden');
    document.getElementById('share_multi_box').classList.add('hidden');
    document.getElementById('btn_ver_publico').classList.remove('hidden');

    document.getElementById('share_codigo').textContent = prod.codigo;
    document.getElementById('share_descripcion').textContent = prod.descripcion;
    document.getElementById('share_precio').textContent = 'S/ ' + parseFloat(prod.precio_unitario).toFixed(2);
    document.getElementById('share_lote').textContent = prod.lote_actual ? `Lote: ${prod.lote_actual}` : '';
    document.getElementById('share_url_input').value = url;
    document.getElementById('btn_ver_publico').href = url;

    const textoWsp = encodeURIComponent(`Hola, te comparto la ficha comercial de nuestro producto *${prod.descripcion}* (${prod.codigo}) con precio S/ ${parseFloat(prod.precio_unitario).toFixed(2)}:\n${url}`);
    document.getElementById('btn_whatsapp_share').href = `https://api.whatsapp.com/send?text=${textoWsp}`;

    resetearBotonCopiar();
    document.getElementById('modalCompartirProducto').classList.remove('hidden');
}

function abrirCompartirMultiple(productosSel) {
    const origin = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    
    document.getElementById('share_modal_title').textContent = `Compartir Catálogo (${productosSel.length} Ítems)`;
    document.getElementById('share_single_box').classList.add('hidden');
    document.getElementById('share_multi_box').classList.remove('hidden');
    document.getElementById('btn_ver_publico').classList.add('hidden');

    const listContainer = document.getElementById('share_multi_list');
    listContainer.innerHTML = '';

    let textoPlano = `*CATÁLOGO DE PRODUCTOS DISPONIBLES:*\n\n`;

    productosSel.forEach((p, idx) => {
        const itemUrl = origin + path + 'producto_publico.php?token=' + encodeURIComponent(p.token_publico || '') + '&id=' + p.id;
        textoPlano += `${idx + 1}. *${p.descripcion}* (${p.codigo})\n   Precio: S/ ${parseFloat(p.precio_unitario).toFixed(2)} • Ver: ${itemUrl}\n\n`;

        const itemDiv = document.createElement('div');
        itemDiv.className = 'flex justify-between items-center py-1 border-b border-slate-200/60 dark:border-slate-700/60';
        itemDiv.innerHTML = `
            <span class="font-semibold text-slate-800 dark:text-slate-200 truncate pr-2">${p.descripcion}</span>
            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400 shrink-0">S/ ${parseFloat(p.precio_unitario).toFixed(2)}</span>
        `;
        listContainer.appendChild(itemDiv);
    });

    document.getElementById('share_url_input').value = textoPlano.trim();
    document.getElementById('btn_whatsapp_share').href = `https://api.whatsapp.com/send?text=${encodeURIComponent(textoPlano.trim())}`;

    resetearBotonCopiar();
    document.getElementById('modalCompartirProducto').classList.remove('hidden');
}

function resetearBotonCopiar() {
    const btn = document.getElementById('btn_copiar_link');
    const txt = document.getElementById('btn_copiar_texto');
    txt.textContent = 'Copiar';
    btn.classList.remove('bg-emerald-600');
    btn.classList.add('bg-slate-800');
}

function copiarEnlacePublico() {
    const inp = document.getElementById('share_url_input');
    inp.select();
    inp.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(inp.value).then(() => {
        const txt = document.getElementById('btn_copiar_texto');
        const btn = document.getElementById('btn_copiar_link');
        txt.textContent = '¡Copiado!';
        btn.classList.remove('bg-slate-800');
        btn.classList.add('bg-emerald-600');
        if (typeof window.showToast === 'function') {
            window.showToast('Contenido copiado al portapapeles con éxito.', 'success');
        }
        setTimeout(resetearBotonCopiar, 2200);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
