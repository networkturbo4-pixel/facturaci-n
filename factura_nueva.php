<?php
require_once 'includes/auth.php';
requireLogin();

$brandConfig = getBrandingConfig();
$serieFacturaDefault = $brandConfig['serie_factura'] ?? 'F001';
$serieBoletaDefault  = $brandConfig['serie_boleta'] ?? 'B001';

$success_msg = '';
$error_msg = '';
$comprobante_emitido_id = null;
$token_emitido = '';
$numero_emitido = '';

// Cargar catálogo de productos activos para autocompletado y selección rápida
$productosCatalogo = [];
try {
    $stmtProd = $conn->query("SELECT id, codigo, descripcion, unidad_medida, precio_unitario FROM productos WHERE activo = 1 ORDER BY descripcion ASC");
    if ($stmtProd) {
        $productosCatalogo = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Cargar clientes frecuentes para selección rápida en 1 clic
$clientesCartera = [];
try {
    $stmtCli = $conn->query("SELECT tipo_documento, numero_documento, razon_social, direccion_fiscal FROM clientes ORDER BY razon_social ASC LIMIT 100");
    if ($stmtCli) {
        $clientesCartera = $stmtCli->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada. Intente de nuevo.";
    } else {
        $tipo   = $_POST['tipo_comprobante'] ?? 'Factura Electrónica';
        $serie  = $_POST['serie'] ?? $serieFacturaDefault;
        $fecha  = $_POST['fecha_emision'] ?? date('Y-m-d');
        $moneda = in_array($_POST['moneda'] ?? 'PEN', ['PEN', 'USD']) ? $_POST['moneda'] : 'PEN';
        $doc    = trim($_POST['cliente_documento'] ?? '');
        $razon  = trim($_POST['cliente_razon_social'] ?? '');
        $dir    = trim($_POST['cliente_direccion'] ?? '-');

        $qtys   = $_POST['qty'] ?? [];
        $descs  = $_POST['desc'] ?? [];
        $prices = $_POST['price'] ?? [];

        if (empty($doc) || empty($razon) || empty($qtys)) {
            $error_msg = "Por favor complete los datos del cliente y agregue al menos un producto o servicio.";
        } else {
            try {
                $conn->beginTransaction();

                // Calcular totales en el backend (Precio Unitario base + IGV 18%)
                $subtotal = 0;
                foreach ($qtys as $index => $q) {
                    $p = (float)($prices[$index] ?? 0);
                    $subtotal += ((float)$q * $p);
                }
                $igv   = $subtotal * 0.18;
                $total = $subtotal + $igv;

                // Obtener el correlativo máximo de forma segura
                $stmt = $conn->prepare("SELECT MAX(correlativo) as max_corr FROM comprobantes WHERE serie = ?");
                $stmt->execute([$serie]);
                $row = $stmt->fetch();
                $correlativo = ($row['max_corr'] ?? 0) + 1;

                $token_publico  = bin2hex(random_bytes(16));
                $numero_emitido = $serie . '-' . str_pad($correlativo, 6, '0', STR_PAD_LEFT);

                // Insertar Cabecera con moneda, token_publico y estado inicial
                $stmt = $conn->prepare("INSERT INTO comprobantes (token_publico, tipo_comprobante, serie, correlativo, fecha_emision, moneda, cliente_documento, cliente_razon_social, cliente_direccion, subtotal, igv, total, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente SUNAT')");
                $stmt->execute([$token_publico, $tipo, $serie, $correlativo, $fecha, $moneda, $doc, $razon, $dir ?: '-', $subtotal, $igv, $total]);
                $comprobante_id = $conn->lastInsertId();
                $comprobante_emitido_id = $comprobante_id;
                $token_emitido = $token_publico;

                // Insertar Líneas
                $stmt_line = $conn->prepare("INSERT INTO comprobante_lineas (comprobante_id, cantidad, descripcion, precio_unitario, total_linea) VALUES (?, ?, ?, ?, ?)");
                foreach ($qtys as $index => $q) {
                    $d  = trim($descs[$index] ?? '');
                    $p  = (float)($prices[$index] ?? 0);
                    $tl = (float)$q * $p;
                    $stmt_line->execute([$comprobante_id, (float)$q, $d, $p, $tl]);
                }

                $conn->commit();
                $success_msg = "Comprobante {$numero_emitido} emitido correctamente.";

                // Despachar a SUNAT con Plan de Contingencia
                try {
                    require_once __DIR__ . '/api/enviar_sunat.php';
                    $sunatRes = enviarComprobanteNubefact($comprobante_id, $conn);
                    if (!empty($sunatRes['success'])) {
                        $success_msg .= " | SUNAT: ✅ " . ($sunatRes['message'] ?? $sunatRes['cdr_estado'] ?? 'Aceptado');
                    } elseif (!empty($sunatRes['contingencia'])) {
                        $success_msg .= " | SUNAT: 🛡️ " . ($sunatRes['message'] ?? 'Guardado en contingencia');
                    } else {
                        $error_msg = "Comprobante guardado localmente, pero SUNAT observó: " . ($sunatRes['message'] ?? 'Rechazado');
                    }
                } catch (Throwable $eSunat) {
                    $success_msg .= " | SUNAT: 🛡️ Guardado en contingencia (" . $eSunat->getMessage() . ").";
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error_msg = "Error al emitir comprobante: " . $e->getMessage();
            }
        }
    }
}
?>
<?php
$pageTitle = 'Emitir Factura / Boleta';
include 'includes/header.php';
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-100/70 dark:bg-slate-950 overflow-hidden">
    <?php
    $topbarAction = [
        'label' => 'Historial CPE',
        'icon'  => 'fa-solid fa-list-check text-xs',
        'url'   => 'comprobantes.php'
    ];
    include 'includes/topbar.php';
    ?>

    <div class="flex-1 overflow-y-auto p-3.5 sm:p-6 lg:p-8">
        <div class="max-w-7xl mx-auto space-y-5">

            <!-- Banner de Éxito con Acciones Directas si se acaba de emitir un CPE -->
            <?php if ($success_msg): ?>
                <div data-auto-toast="true" class="p-4 sm:p-5 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm animate-fade-in">
                    <div class="flex items-start sm:items-center gap-3.5">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-base shrink-0 shadow-sm shadow-emerald-500/30">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <h4 class="text-xs sm:text-sm font-extrabold text-emerald-950 dark:text-emerald-200">¡Comprobante Electrónico Generado!</h4>
                            <p class="text-xs text-emerald-800 dark:text-emerald-300/90 mt-0.5"><?php echo htmlspecialchars($success_msg); ?></p>
                        </div>
                    </div>
                    <?php if ($comprobante_emitido_id): ?>
                    <div class="flex items-center gap-2 shrink-0">
                        <?php $urlVerCpe = !empty($token_emitido) ? 'comprobante_publico.php?token=' . urlencode($token_emitido) : 'comprobante_publico.php?id=' . (int)$comprobante_emitido_id; ?>
                        <a href="<?php echo htmlspecialchars($urlVerCpe); ?>" target="_blank" class="flex-1 sm:flex-initial justify-center px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold flex items-center gap-1.5 shadow-sm transition-all">
                            <i class="fa-solid fa-print"></i>
                            <span>Ver / Imprimir PDF</span>
                        </a>
                        <button type="button" onclick="copiarEnlaceCpe('<?php echo htmlspecialchars($urlVerCpe, ENT_QUOTES); ?>')" class="px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-emerald-200 dark:border-slate-700 text-emerald-700 dark:text-emerald-300 text-xs font-semibold hover:bg-emerald-100/50 transition-all flex items-center gap-1.5">
                            <i class="fa-solid fa-share-nodes"></i>
                            <span>Copiar Link</span>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div data-auto-toast="true" class="p-4 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-800 dark:text-red-300 rounded-2xl text-xs font-medium flex items-center gap-3 shadow-sm animate-fade-in">
                    <i class="fa-solid fa-circle-exclamation text-red-500 text-base shrink-0"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="formEmitirCpe">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="tipo_comprobante" id="inp_tipo_comprobante" value="Factura Electrónica">

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 lg:gap-6 items-start">

                    <!-- =============================================================== -->
                    <!-- COLUMNA IZQUIERDA (8 cols en PC): DOCUMENTO, CLIENTE E ÍTEMS    -->
                    <!-- =============================================================== -->
                    <div class="lg:col-span-8 space-y-5">

                        <!-- PASO 1: TIPO DE COMPROBANTE Y DATOS DE EMISIÓN -->
                        <div class="app-card p-4 sm:p-6">
                            <div class="flex items-center justify-between gap-2 mb-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-extrabold flex items-center justify-center shadow-xs">1</span>
                                    <h3 class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white tracking-tight">Tipo de Comprobante y Serie</h3>
                                </div>
                                <span class="text-[11px] font-semibold text-slate-400 hidden sm:inline">UBL 2.1 • SUNAT</span>
                            </div>

                            <!-- Tarjetas Interactivas en 2 Columnas: Factura vs Boleta -->
                            <div class="grid grid-cols-2 gap-3 mb-4">
                                <!-- Opción 1: Factura Electrónica -->
                                <button type="button" id="card_tipo_factura" onclick="seleccionarTipoCpe('Factura Electrónica', '<?php echo htmlspecialchars($serieFacturaDefault, ENT_QUOTES); ?>')" class="cpe-type-card group text-left p-3.5 sm:p-4 rounded-2xl border-2 border-brand-600 bg-brand-50/50 dark:bg-brand-500/10 transition-all flex items-start gap-3 relative">
                                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-brand-600 text-white flex items-center justify-center text-sm sm:text-base shrink-0 shadow-sm shadow-brand-600/30">
                                        <i class="fa-solid fa-file-invoice-dollar"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-1">
                                            <p class="text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white truncate">Factura</p>
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-brand-600 text-white"><?php echo htmlspecialchars($serieFacturaDefault); ?></span>
                                        </div>
                                        <p class="text-[10px] sm:text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 leading-snug">Para empresas con RUC (11 dígitos)</p>
                                    </div>
                                </button>

                                <!-- Opción 2: Boleta de Venta -->
                                <button type="button" id="card_tipo_boleta" onclick="seleccionarTipoCpe('Boleta de Venta Electrónica', '<?php echo htmlspecialchars($serieBoletaDefault, ENT_QUOTES); ?>')" class="cpe-type-card group text-left p-3.5 sm:p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-slate-300 transition-all flex items-start gap-3 relative">
                                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center text-sm sm:text-base shrink-0">
                                        <i class="fa-solid fa-receipt"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-1">
                                            <p class="text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white truncate">Boleta</p>
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($serieBoletaDefault); ?></span>
                                        </div>
                                        <p class="text-[10px] sm:text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 leading-snug">Para DNI (8 dígitos) o Consumidor</p>
                                    </div>
                                </button>
                            </div>

                            <!-- Parámetros en 2 Columnas en Móvil / 3 en PC -->
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-3 border-t border-slate-100 dark:border-slate-800">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1">Serie Activa</label>
                                    <select name="serie" id="sel_serie" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono font-bold text-slate-800 dark:text-white focus:outline-none focus:border-brand-500">
                                        <option value="<?php echo htmlspecialchars($serieFacturaDefault); ?>"><?php echo htmlspecialchars($serieFacturaDefault); ?> (Factura)</option>
                                        <option value="<?php echo htmlspecialchars($serieBoletaDefault); ?>"><?php echo htmlspecialchars($serieBoletaDefault); ?> (Boleta)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1">Fecha Emisión</label>
                                    <input type="date" name="fecha_emision" id="inp_fecha_emision" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-medium text-slate-800 dark:text-white focus:outline-none focus:border-brand-500">
                                </div>

                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1">Moneda</label>
                                    <select name="moneda" id="sel_moneda_cpe" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-brand-500">
                                        <option value="PEN" data-symbol="S/">PEN (S/) — Soles</option>
                                        <option value="USD" data-symbol="$">USD ($) — Dólares</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- PASO 2: DATOS DEL CLIENTE / RECEPTOR -->
                        <div class="app-card p-4 sm:p-6">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 mb-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-extrabold flex items-center justify-center shadow-xs">2</span>
                                    <div>
                                        <h3 class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white tracking-tight">Datos del Cliente</h3>
                                    </div>
                                </div>

                                <!-- Acciones Rápidas de Cliente -->
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button type="button" onclick="usarClienteVarios()" class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-500/15 text-slate-600 dark:text-slate-300 hover:text-brand-600 text-[11px] font-bold transition-colors flex items-center gap-1.5" title="Rellenar como Consumidor Final para Boleta rápida">
                                        <i class="fa-solid fa-bolt text-amber-500"></i>
                                        <span>Consumidor Final</span>
                                    </button>
                                    <a href="cliente_nuevo.php" class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-300 text-[11px] font-bold transition-colors flex items-center gap-1">
                                        <i class="fa-solid fa-user-plus text-[10px]"></i>
                                        <span>Nuevo Cliente</span>
                                    </a>
                                </div>
                            </div>

                            <!-- Buscador RUC/DNI + Selector de Cartera en 1 Clic -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mb-4">
                                <div class="md:col-span-7">
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1" id="lbl_doc_cliente">RUC o DNI del Cliente *</label>
                                    <div class="flex rounded-xl shadow-xs">
                                        <div class="relative flex-1">
                                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                                <i class="fa-solid fa-id-card text-xs"></i>
                                            </span>
                                            <input type="text" id="num_doc" name="cliente_documento" maxlength="11" inputmode="numeric" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-l-xl pl-9 pr-3 py-2.5 text-xs sm:text-sm font-mono font-bold text-slate-900 dark:text-white focus:outline-none focus:bg-white focus:border-brand-500" placeholder="Ingrese RUC (11) o DNI (8)..." required>
                                        </div>
                                        <button type="button" id="btn_buscar_doc" class="px-3.5 sm:px-4 bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold rounded-r-xl flex items-center gap-1.5 transition-colors shrink-0">
                                            <i id="icon_buscar" class="fa-solid fa-magnifying-glass"></i>
                                            <span id="text_buscar">SUNAT / RENIEC</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="md:col-span-5">
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1">O elegir de tu Cartera</label>
                                    <select id="sel_cliente_cartera" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-xs text-slate-700 dark:text-slate-200 focus:outline-none focus:border-brand-500">
                                        <option value="">— Seleccionar cliente registrado —</option>
                                        <?php foreach ($clientesCartera as $cli): ?>
                                            <option value="<?php echo htmlspecialchars($cli['numero_documento']); ?>"
                                                    data-razon="<?php echo htmlspecialchars($cli['razon_social']); ?>"
                                                    data-dir="<?php echo htmlspecialchars($cli['direccion_fiscal'] ?? ''); ?>"
                                                    data-tipo="<?php echo htmlspecialchars($cli['tipo_documento']); ?>">
                                                <?php echo htmlspecialchars($cli['numero_documento'] . ' — ' . $cli['razon_social']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Razón Social y Dirección -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5" id="datos_cliente_encontrado">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1">Razón Social / Nombre Completo *</label>
                                    <div class="relative">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                            <i class="fa-solid fa-building-user text-xs"></i>
                                        </span>
                                        <input type="text" id="razon_social" name="cliente_razon_social" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-2.5 text-xs sm:text-sm font-semibold text-slate-800 dark:text-white focus:outline-none focus:bg-white focus:border-brand-500" required placeholder="Nombre o Razón Social del receptor">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-1">Dirección del Cliente</label>
                                    <div class="relative">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                                            <i class="fa-solid fa-location-dot text-xs"></i>
                                        </span>
                                        <input type="text" id="direccion" name="cliente_direccion" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-2.5 text-xs sm:text-sm text-slate-800 dark:text-white focus:outline-none focus:bg-white focus:border-brand-500" placeholder="Dirección fiscal o domicilio">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PASO 3: DETALLE DE PRODUCTOS / SERVICIOS -->
                        <div class="app-card p-4 sm:p-6">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-extrabold flex items-center justify-center shadow-xs">3</span>
                                    <div>
                                        <h3 class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white tracking-tight">Detalle de Productos / Servicios</h3>
                                        <p class="text-[11px] text-slate-400">Elige del catálogo o escribe directamente cada concepto</p>
                                    </div>
                                </div>

                                <!-- Selector Rápido para insertar producto del catálogo en 1 toque -->
                                <?php if (!empty($productosCatalogo)): ?>
                                <div class="flex items-center gap-2 w-full sm:w-auto">
                                    <select id="sel_quick_product" class="flex-1 sm:w-64 bg-brand-50/70 dark:bg-slate-800 border border-brand-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold text-brand-900 dark:text-slate-200 focus:outline-none focus:border-brand-500">
                                        <option value="">⚡ + Insertar producto del catálogo...</option>
                                        <?php foreach ($productosCatalogo as $prod): ?>
                                            <option value="<?php echo htmlspecialchars($prod['descripcion']); ?>"
                                                    data-price="<?php echo number_format((float)$prod['precio_unitario'], 2, '.', ''); ?>"
                                                    data-code="<?php echo htmlspecialchars($prod['codigo']); ?>">
                                                <?php echo htmlspecialchars($prod['codigo'] . ' — ' . $prod['descripcion'] . ' (S/ ' . number_format((float)$prod['precio_unitario'], 2) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>



                            <!-- Tabla Responsiva de Ítems (Tabla en PC / Tarjetas 2-Columnas en Móvil sin scroll horizontal) -->
                            <div class="overflow-x-auto mb-4 rounded-xl border border-slate-200/80 dark:border-slate-800">
                                <table class="w-full text-left border-collapse responsive-table">
                                    <thead>
                                        <tr class="bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/80 dark:border-slate-700 text-slate-500 text-[11px] uppercase tracking-wider font-bold">
                                            <th class="px-3.5 py-3 w-32 text-center">Cantidad</th>
                                            <th class="px-3.5 py-3">Descripción del Producto / Servicio</th>
                                            <th class="px-3.5 py-3 w-36 text-right">Valor Unit.</th>
                                            <th class="px-3.5 py-3 w-32 text-right">Subtotal</th>
                                            <th class="px-3 py-3 w-12 text-center"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="items_table_body" class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                                        <tr class="item-row hover:bg-slate-50/60 transition-colors">
                                            <td class="px-3.5 py-3" data-label="Cantidad" data-field="input">
                                                <div class="flex items-center border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 overflow-hidden">
                                                    <button type="button" class="btn-qty-minus w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors shrink-0" tabindex="-1">
                                                        <i class="fa-solid fa-minus text-[10px] pointer-events-none"></i>
                                                    </button>
                                                    <input type="number" name="qty[]" value="1" min="0.01" step="any" class="item-qty w-full border-0 p-1 text-xs font-bold text-center bg-transparent focus:ring-0" required>
                                                    <button type="button" class="btn-qty-plus w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors shrink-0" tabindex="-1">
                                                        <i class="fa-solid fa-plus text-[10px] pointer-events-none"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="px-3.5 py-3" data-label="Descripción" data-field="desc">
                                                <div class="product-dropdown-wrapper relative w-full">
                                                    <div class="relative flex items-center">
                                                        <span class="absolute left-3 text-slate-400 dark:text-slate-500 pointer-events-none text-xs">
                                                            <i class="fa-solid fa-magnifying-glass"></i>
                                                        </span>
                                                        <input type="text" name="desc[]" placeholder="Escribe o busca del catálogo..." autocomplete="off" class="item-desc w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-8 pr-8 py-2 text-xs font-semibold text-slate-800 dark:text-white placeholder:text-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition-all" required>
                                                        <button type="button" tabindex="-1" class="btn-toggle-dropdown absolute right-2 text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 p-1.5 rounded-lg transition-transform" title="Ver catálogo">
                                                            <i class="fa-solid fa-chevron-down text-[11px] transition-transform duration-200"></i>
                                                        </button>
                                                    </div>
                                                    <div class="product-dropdown-menu hidden absolute left-0 top-full mt-1.5 z-50 min-w-[320px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                                                        <div class="px-3.5 py-2.5 bg-slate-50/90 dark:bg-slate-800/90 border-b border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between">
                                                            <div class="flex items-center gap-2">
                                                                <div class="w-5 h-5 rounded-md bg-brand-500/10 text-brand-600 dark:text-brand-400 flex items-center justify-center text-[10px]">
                                                                    <i class="fa-solid fa-boxes-stacked"></i>
                                                                </div>
                                                                <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Gestión de Ventas</span>
                                                            </div>
                                                            <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-brand-50 dark:bg-brand-500/20 text-brand-600 dark:text-brand-300">
                                                                <?php echo count($productosCatalogo); ?> ítems
                                                            </span>
                                                        </div>
                                                        <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                                                        <div class="px-3 py-2 bg-slate-50/60 dark:bg-slate-950/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
                                                            <span><i class="fa-regular fa-keyboard mr-1"></i> Navega con ↑ ↓ y Enter</span>
                                                            <a href="productos.php" target="_blank" class="text-brand-600 dark:text-brand-400 hover:underline flex items-center gap-1 font-semibold">
                                                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-3.5 py-3" data-label="Valor Unit." data-field="input">
                                                <input type="number" name="price[]" placeholder="0.00" min="0" step="0.01" class="item-price w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono font-bold text-right text-slate-800 dark:text-white focus:outline-none focus:border-brand-500" required>
                                            </td>
                                            <td class="px-3.5 py-3 text-right font-mono font-bold text-slate-900 dark:text-white item-total whitespace-nowrap" data-label="Subtotal" data-field="total">
                                                S/ 0.00
                                            </td>
                                            <td class="px-3 py-3 text-center btn-remove cursor-pointer text-rose-500 hover:text-rose-600" data-label="Quitar" data-field="remove" title="Quitar ítem">
                                                <i class="fa-solid fa-trash-can pointer-events-none"></i>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <button type="button" id="btn_add_row" class="px-4 py-2.5 rounded-xl bg-brand-50 dark:bg-brand-500/15 hover:bg-brand-100 text-brand-700 dark:text-brand-300 text-xs font-extrabold transition-all inline-flex items-center gap-2">
                                    <i class="fa-solid fa-plus-circle"></i>
                                    <span>Agregar Nueva Línea</span>
                                </button>
                                <span class="text-[11px] text-slate-400 font-medium" id="lbl_items_count">1 línea(s) activa(s)</span>
                            </div>
                        </div>

                    </div>

                    <!-- =============================================================== -->
                    <!-- COLUMNA DERECHA (4 cols en PC): RESUMEN EN VIVO Y EMISIÓN SUNAT -->
                    <!-- =============================================================== -->
                    <div class="lg:col-span-4 lg:sticky lg:top-6 space-y-5">

                        <div class="app-card overflow-hidden border-slate-200/90 dark:border-slate-800 shadow-lg">
                            <!-- Cabecera Tipo Ticket Electrónico -->
                            <div class="p-5 bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950 text-white">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <span id="badge_resumen_tipo" class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-brand-500/25 text-brand-200 border border-brand-400/30">
                                        Factura Electrónica
                                    </span>
                                    <span class="text-xs font-mono font-bold text-emerald-400 flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                        <span id="badge_resumen_serie"><?php echo htmlspecialchars($serieFacturaDefault); ?></span>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-300 truncate" id="badge_resumen_cliente">Cliente: Pendiente de completar</p>
                            </div>

                            <!-- Desglose Tributario -->
                            <div class="p-5 space-y-3.5">
                                <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <span>Operaciones Gravadas</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="lbl_subtotal">S/ 0.00</span>
                                </div>
                                <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <span>I.G.V. (18%)</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="lbl_igv">S/ 0.00</span>
                                </div>

                                <div class="pt-3.5 border-t-2 border-dashed border-slate-200 dark:border-slate-800 flex items-baseline justify-between">
                                    <div>
                                        <span class="block text-xs font-extrabold uppercase tracking-wider text-slate-800 dark:text-white">Importe Total</span>
                                        <span class="text-[10px] text-slate-400">Incluye impuestos de ley</span>
                                    </div>
                                    <span class="text-2xl font-extrabold font-mono text-brand-600 dark:text-brand-400 tracking-tight" id="lbl_total">S/ 0.00</span>
                                </div>

                                <!-- Botón Principal de Emisión -->
                                <div class="pt-3 space-y-2.5">
                                    <button type="submit" id="btn_submit_cpe" class="w-full py-3.5 px-5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs sm:text-sm font-extrabold rounded-xl shadow-lg shadow-emerald-600/25 transition-all flex items-center justify-center gap-2 hover:scale-[1.01] active:scale-[0.99]">
                                        <i class="fa-solid fa-paper-plane"></i>
                                        <span>Emitir Comprobante a SUNAT</span>
                                    </button>

                                    <div class="grid grid-cols-2 gap-2">
                                        <button type="button" onclick="limpiarFormularioCpe()" class="py-2.5 px-3 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-semibold transition-colors flex items-center justify-center gap-1.5">
                                            <i class="fa-solid fa-eraser text-[11px]"></i>
                                            <span>Limpiar</span>
                                        </button>
                                        <a href="comprobantes.php" class="py-2.5 px-3 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-semibold transition-colors flex items-center justify-center gap-1.5">
                                            <i class="fa-solid fa-xmark text-[11px]"></i>
                                            <span>Cancelar</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>
            </form>

        </div>
    </div>
</main>

<script src="js/product_dropdown.js?v=1.0"></script>
<script>
// Catálogo de productos para el componente desplegable moderno
const catalogoProductos = <?php echo json_encode($productosCatalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// Mapa de precios del catálogo para autocompletar instantáneamente
const PRODUCTOS_PRECIOS = {
<?php foreach ($productosCatalogo as $prod): ?>
    <?php echo json_encode(mb_strtolower(trim($prod['descripcion']))); ?>: <?php echo (float)$prod['precio_unitario']; ?>,
<?php endforeach; ?>
};

function copiarEnlaceCpe(relUrl) {
    const fullUrl = new URL(relUrl, window.location.href).href;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(fullUrl).then(() => {
            window.showToast('Enlace del comprobante copiado al portapapeles.', 'success', 'Enlace Copiado');
        });
    } else {
        window.showToast(fullUrl, 'info', 'Enlace Público');
    }
}

function seleccionarTipoCpe(tipo, serieDefault) {
    document.getElementById('inp_tipo_comprobante').value = tipo;
    const selSerie = document.getElementById('sel_serie');
    if (selSerie && serieDefault) {
        selSerie.value = serieDefault;
    }

    const isFactura = tipo.includes('Factura');
    const cardFact  = document.getElementById('card_tipo_factura');
    const cardBol   = document.getElementById('card_tipo_boleta');
    const numDoc    = document.getElementById('num_doc');
    const lblDoc    = document.getElementById('lbl_doc_cliente');

    if (isFactura) {
        cardFact.className = 'cpe-type-card group text-left p-3.5 sm:p-4 rounded-2xl border-2 border-brand-600 bg-brand-50/50 dark:bg-brand-500/10 transition-all flex items-start gap-3 relative';
        cardBol.className  = 'cpe-type-card group text-left p-3.5 sm:p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-slate-300 transition-all flex items-start gap-3 relative';
        lblDoc.textContent = 'RUC del Cliente (11 dígitos) *';
        numDoc.placeholder = 'Ingrese número de RUC (11 dígitos)...';
    } else {
        cardBol.className  = 'cpe-type-card group text-left p-3.5 sm:p-4 rounded-2xl border-2 border-brand-600 bg-brand-50/50 dark:bg-brand-500/10 transition-all flex items-start gap-3 relative';
        cardFact.className = 'cpe-type-card group text-left p-3.5 sm:p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:border-slate-300 transition-all flex items-start gap-3 relative';
        lblDoc.textContent = 'DNI del Cliente (8 dígitos) *';
        numDoc.placeholder = 'Ingrese número de DNI (8 dígitos)...';
    }

    document.getElementById('badge_resumen_tipo').textContent = isFactura ? 'Factura Electrónica' : 'Boleta de Venta';
    document.getElementById('badge_resumen_serie').textContent = selSerie.value;
}

function usarClienteVarios() {
    seleccionarTipoCpe('Boleta de Venta Electrónica', '<?php echo htmlspecialchars($serieBoletaDefault, ENT_QUOTES); ?>');
    document.getElementById('num_doc').value = '00000000';
    document.getElementById('razon_social').value = 'CLIENTES VARIOS - CONSUMIDOR FINAL';
    document.getElementById('direccion').value = '-';
    document.getElementById('badge_resumen_cliente').textContent = 'Cliente: CLIENTES VARIOS';
    window.showToast('Configurado como Boleta de Venta para Consumidor Final.', 'info', 'Modo Rápido');
}

async function limpiarFormularioCpe() {
    const ok = await window.showConfirm({
        title: 'Limpiar Formulario',
        message: '¿Deseas reiniciar todos los campos del comprobante actual?',
        confirmText: 'Sí, limpiar',
        variant: 'warning'
    });
    if (!ok) return;
    document.getElementById('formEmitirCpe').reset();
    const tbody = document.getElementById('items_table_body');
    const rows = tbody.querySelectorAll('.item-row');
    rows.forEach((r, idx) => { if (idx > 0) r.remove(); });
    document.getElementById('badge_resumen_cliente').textContent = 'Cliente: Pendiente de completar';
    window.dispatchEvent(new Event('recalcular-cpe'));
    window.showToast('Formulario reiniciado.', 'info');
}

document.addEventListener('DOMContentLoaded', function() {
    const btnBuscarDoc = document.getElementById('btn_buscar_doc');
    const iconBuscar   = document.getElementById('icon_buscar');
    const textBuscar   = document.getElementById('text_buscar');
    const numDocInput  = document.getElementById('num_doc');
    const razonInput   = document.getElementById('razon_social');
    const dirInput     = document.getElementById('direccion');
    const selCartera   = document.getElementById('sel_cliente_cartera');
    const selSerie     = document.getElementById('sel_serie');
    const selMoneda    = document.getElementById('sel_moneda_cpe');
    const selQuickProd = document.getElementById('sel_quick_product');

    function getCurrencySymbol() {
        const opt = selMoneda.options[selMoneda.selectedIndex];
        return (opt && opt.getAttribute('data-symbol')) || 'S/';
    }

    selSerie.addEventListener('change', function() {
        document.getElementById('badge_resumen_serie').textContent = this.value;
    });

    razonInput.addEventListener('input', function() {
        const val = this.value.trim();
        document.getElementById('badge_resumen_cliente').textContent = val ? ('Cliente: ' + val) : 'Cliente: Pendiente de completar';
    });

    // Selección desde cartera en 1 clic
    if (selCartera) {
        selCartera.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;
            const doc   = opt.value;
            const razon = opt.getAttribute('data-razon') || '';
            const dir   = opt.getAttribute('data-dir') || '-';
            numDocInput.value = doc;
            razonInput.value  = razon;
            dirInput.value    = dir;
            document.getElementById('badge_resumen_cliente').textContent = 'Cliente: ' + razon;

            if (doc.length === 11) {
                seleccionarTipoCpe('Factura Electrónica', '<?php echo htmlspecialchars($serieFacturaDefault, ENT_QUOTES); ?>');
            } else if (doc.length === 8) {
                seleccionarTipoCpe('Boleta de Venta Electrónica', '<?php echo htmlspecialchars($serieBoletaDefault, ENT_QUOTES); ?>');
            }
            window.showToast('Cliente cargado desde tu cartera: ' + razon, 'success', 'Cliente Seleccionado');
        });
    }

    function resetBtn() {
        iconBuscar.className = 'fa-solid fa-magnifying-glass';
        textBuscar.innerText = 'SUNAT / RENIEC';
        btnBuscarDoc.disabled = false;
    }

    btnBuscarDoc.addEventListener('click', async function() {
        const numDoc = numDocInput.value.trim();
        if (numDoc.length !== 8 && numDoc.length !== 11) {
            window.showToast('El documento debe tener exactamente 8 dígitos (DNI) o 11 dígitos (RUC).', 'warning', 'Documento Inválido');
            numDocInput.focus();
            return;
        }

        const tipoDoc = numDoc.length === 8 ? 'DNI' : 'RUC';
        if (tipoDoc === 'RUC') {
            seleccionarTipoCpe('Factura Electrónica', '<?php echo htmlspecialchars($serieFacturaDefault, ENT_QUOTES); ?>');
        } else {
            seleccionarTipoCpe('Boleta de Venta Electrónica', '<?php echo htmlspecialchars($serieBoletaDefault, ENT_QUOTES); ?>');
        }

        iconBuscar.className = 'fa-solid fa-spinner fa-spin';
        textBuscar.innerText = 'Consultando...';
        btnBuscarDoc.disabled = true;

        try {
            const response = await fetch('api/consultar_documento.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ tipo_doc: tipoDoc, num_doc: numDoc })
            });

            const jsonResponse = await response.json();
            if (!response.ok || !jsonResponse.success) {
                throw new Error(jsonResponse.message || 'Documento no encontrado en el padrón.');
            }

            const data = jsonResponse.data;
            if (data.razon_social) {
                razonInput.value = data.razon_social;
                document.getElementById('badge_resumen_cliente').textContent = 'Cliente: ' + data.razon_social;
            }
            if (data.direccion) {
                dirInput.value = data.direccion;
            }

            iconBuscar.className = 'fa-solid fa-check text-emerald-300';
            textBuscar.innerText = (jsonResponse.source === 'local_database') ? 'En Cartera' : 'Verificado';
            window.showToast((data.razon_social || 'Datos cargados') + ' verificado correctamente.', 'success', 'Consulta ' + tipoDoc);
            setTimeout(() => resetBtn(), 2000);
        } catch (error) {
            window.showToast(error.message || 'No se pudo consultar el documento. Puedes ingresar la Razón Social manualmente.', 'error', 'Error de Consulta');
            resetBtn();
        }
    });

    numDocInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            btnBuscarDoc.click();
        }
    });

    // Gestión de líneas de productos y cálculo en tiempo real
    const tbody       = document.getElementById('items_table_body');
    const btnAddRow   = document.getElementById('btn_add_row');
    const lblSubtotal = document.getElementById('lbl_subtotal');
    const lblIgv      = document.getElementById('lbl_igv');
    const lblTotal    = document.getElementById('lbl_total');
    const lblCount    = document.getElementById('lbl_items_count');

    function calcularTotales() {
        let subtotal = 0;
        const sym  = getCurrencySymbol();
        const rows = tbody.querySelectorAll('.item-row');

        rows.forEach(row => {
            const qty   = parseFloat(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            const total = qty * price;
            row.querySelector('.item-total').innerText = sym + ' ' + total.toFixed(2);
            subtotal += total;
        });

        const igv   = subtotal * 0.18;
        const total = subtotal + igv;

        lblSubtotal.innerText = sym + ' ' + subtotal.toFixed(2);
        lblIgv.innerText      = sym + ' ' + igv.toFixed(2);
        lblTotal.innerText    = sym + ' ' + total.toFixed(2);
        if (lblCount) {
            lblCount.textContent = rows.length + ' línea(s) activa(s)';
        }
    }

    window.addEventListener('recalcular-cpe', calcularTotales);
    selMoneda.addEventListener('change', calcularTotales);

    function crearFilaHtml(desc = '', price = '') {
        const sym = getCurrencySymbol();
        const tr = document.createElement('tr');
        tr.className = 'item-row hover:bg-slate-50/60 transition-colors';
        tr.innerHTML = `
            <td class="px-3.5 py-3" data-label="Cantidad" data-field="input">
                <div class="flex items-center border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 overflow-hidden">
                    <button type="button" class="btn-qty-minus w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors shrink-0" tabindex="-1">
                        <i class="fa-solid fa-minus text-[10px] pointer-events-none"></i>
                    </button>
                    <input type="number" name="qty[]" value="1" min="0.01" step="any" class="item-qty w-full border-0 p-1 text-xs font-bold text-center bg-transparent focus:ring-0" required>
                    <button type="button" class="btn-qty-plus w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors shrink-0" tabindex="-1">
                        <i class="fa-solid fa-plus text-[10px] pointer-events-none"></i>
                    </button>
                </div>
            </td>
            <td class="px-3.5 py-3 align-top min-w-[280px]" data-label="Descripción" data-field="desc">
                <div class="product-dropdown-wrapper relative w-full">
                    <div class="relative flex items-center">
                        <span class="absolute left-3 text-slate-400 dark:text-slate-500 pointer-events-none text-xs">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="text" name="desc[]" placeholder="Escribe o busca del catálogo..." autocomplete="off" class="item-desc w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-8 pr-8 py-2 text-xs font-semibold text-slate-800 dark:text-white placeholder:text-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition-all" required>
                        <button type="button" tabindex="-1" class="btn-toggle-dropdown absolute right-2 text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 p-1.5 rounded-lg transition-transform" title="Ver catálogo">
                            <i class="fa-solid fa-chevron-down text-[11px] transition-transform duration-200"></i>
                        </button>
                    </div>
                    <div class="product-dropdown-menu hidden absolute left-0 top-full mt-1.5 z-50 min-w-[320px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                        <div class="px-3.5 py-2.5 bg-slate-50/90 dark:bg-slate-800/90 border-b border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-5 h-5 rounded-md bg-brand-500/10 text-brand-600 dark:text-brand-400 flex items-center justify-center text-[10px]">
                                    <i class="fa-solid fa-boxes-stacked"></i>
                                </div>
                                <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Gestión de Ventas</span>
                            </div>
                            <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-brand-50 dark:bg-brand-500/20 text-brand-600 dark:text-brand-300">
                                ${catalogoProductos.length} ítems
                            </span>
                        </div>
                        <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                        <div class="px-3 py-2 bg-slate-50/60 dark:bg-slate-950/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
                            <span><i class="fa-regular fa-keyboard mr-1"></i> Navega con ↑ ↓ y Enter</span>
                            <a href="productos.php" target="_blank" class="text-brand-600 dark:text-brand-400 hover:underline flex items-center gap-1 font-semibold">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                            </a>
                        </div>
                    </div>
                </div>
            </td>
            <td class="px-3.5 py-3" data-label="Valor Unit." data-field="input">
                <input type="number" name="price[]" placeholder="0.00" min="0" step="0.01" class="item-price w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono font-bold text-right text-slate-800 dark:text-white focus:outline-none focus:border-brand-500" required>
            </td>
            <td class="px-3.5 py-3 text-right font-mono font-bold text-slate-900 dark:text-white item-total whitespace-nowrap" data-label="Subtotal" data-field="total">
                ${sym} 0.00
            </td>
            <td class="px-3 py-3 text-center btn-remove cursor-pointer text-rose-500 hover:text-rose-600" data-label="Quitar" data-field="remove" title="Quitar ítem">
                <i class="fa-solid fa-trash-can pointer-events-none"></i>
            </td>
        `;
        if (desc) tr.querySelector('.item-desc').value = desc;
        if (price !== '') tr.querySelector('.item-price').value = price;
        initRowDropdown(tr);
        return tr;
    }

    function initRowDropdown(rowElement) {
        const wrapper = rowElement.querySelector('.product-dropdown-wrapper');
        if (!wrapper) return;
        attachProductDropdown(wrapper, catalogoProductos, function(product, row) {
            const priceInput = row.querySelector('.item-price');
            if (priceInput && product.precio_unitario !== undefined) {
                priceInput.value = parseFloat(product.precio_unitario).toFixed(2);
                priceInput.classList.add('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'dark:bg-emerald-500/20');
                setTimeout(() => {
                    priceInput.classList.remove('ring-2', 'ring-emerald-500', 'bg-emerald-50', 'dark:bg-emerald-500/20');
                }, 600);
            }
            calcularTotales();
        });
    }

    // Inicializar filas existentes
    tbody.querySelectorAll('.item-row').forEach(initRowDropdown);

    btnAddRow.addEventListener('click', function() {
        const tr = crearFilaHtml();
        tbody.appendChild(tr);
        tr.querySelector('.item-desc').focus();
        calcularTotales();
    });

    // Inserción rápida desde el selector de catálogo
    if (selQuickProd) {
        selQuickProd.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;
            const desc  = opt.value;
            const price = opt.getAttribute('data-price') || '0.00';

            // Si la primera fila está vacía, la reutilizamos; si no, agregamos una nueva
            const firstRow = tbody.querySelector('.item-row');
            if (firstRow && !firstRow.querySelector('.item-desc').value.trim()) {
                firstRow.querySelector('.item-desc').value  = desc;
                firstRow.querySelector('.item-price').value = price;
            } else {
                const tr = crearFilaHtml(desc, price);
                tbody.appendChild(tr);
            }
            calcularTotales();
            this.selectedIndex = 0;
            window.showToast('Producto agregado: ' + desc, 'success', 'Catálogo');
        });
    }

    // Autocompletar precio al elegir de datalist o escribir descripción exacta
    tbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('item-desc')) {
            const key = e.target.value.trim().toLowerCase();
            if (PRODUCTOS_PRECIOS[key] !== undefined) {
                const row = e.target.closest('.item-row');
                const priceInput = row.querySelector('.item-price');
                if (!priceInput.value || parseFloat(priceInput.value) === 0) {
                    priceInput.value = PRODUCTOS_PRECIOS[key].toFixed(2);
                }
            }
            calcularTotales();
        } else if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) {
            calcularTotales();
        }
    });

    // Botones +/- de cantidad y eliminar fila
    tbody.addEventListener('click', function(e) {
        const minusBtn = e.target.closest('.btn-qty-minus');
        const plusBtn  = e.target.closest('.btn-qty-plus');
        const removeBtn = e.target.closest('.btn-remove');

        if (minusBtn) {
            const input = minusBtn.parentElement.querySelector('.item-qty');
            const curr  = parseFloat(input.value) || 1;
            if (curr > 1) {
                input.value = (curr - 1).toFixed(curr % 1 === 0 ? 0 : 2);
                calcularTotales();
            }
        } else if (plusBtn) {
            const input = plusBtn.parentElement.querySelector('.item-qty');
            const curr  = parseFloat(input.value) || 0;
            input.value = (curr + 1).toFixed(curr % 1 === 0 ? 0 : 2);
            calcularTotales();
        } else if (removeBtn) {
            const row = removeBtn.closest('.item-row');
            if (tbody.querySelectorAll('.item-row').length > 1) {
                row.remove();
                calcularTotales();
            } else {
                window.showToast('El comprobante debe contener al menos un producto o servicio.', 'warning', 'Línea Requerida');
            }
        }
    });

    calcularTotales();
});
</script>

<?php include 'includes/footer.php'; ?>
