<?php 
require_once 'includes/auth.php';
requireLogin();

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada.";
    } else {
        $motivo     = trim($_POST['motivo_traslado'] ?? 'Venta');
        $fecha      = $_POST['fecha_emision'] ?? date('Y-m-d');
        $peso       = (float)($_POST['peso_bruto'] ?? 0);
        $destDoc    = trim($_POST['destinatario_doc'] ?? '');
        $destRazon  = trim($_POST['destinatario_razon'] ?? '');
        $destDir    = trim($_POST['destinatario_dir'] ?? '');
        $partDir    = trim($_POST['partida_direccion'] ?? '');
        $partUbg    = trim($_POST['partida_ubigeo'] ?? '150101');
        $llegDir    = trim($_POST['llegada_direccion'] ?? '');
        $llegUbg    = trim($_POST['llegada_ubigeo'] ?? '150101');
        $modalidad  = trim($_POST['modalidad_transporte'] ?? 'Transporte Privado');
        $conductor  = trim($_POST['conductor_doc'] ?? '');
        $placa      = trim($_POST['vehiculo_placa'] ?? '');

        $qtys   = $_POST['qty'] ?? [];
        $units  = $_POST['unidad'] ?? [];
        $descs  = $_POST['desc'] ?? [];

        $itemsGuia = [];
        foreach ($qtys as $i => $q) {
            $d = trim((string)($descs[$i] ?? ''));
            $qNum = (float)$q;
            if ($d !== '' && $qNum > 0) {
                $itemsGuia[] = [
                    'qty'    => $qNum,
                    'unidad' => trim((string)($units[$i] ?? 'NIU')),
                    'desc'   => $d
                ];
            }
        }

        if (empty($itemsGuia)) {
            $error_msg = "Agregue al menos un bien a transportar.";
        } else {
            try {
                $conn->exec("CREATE TABLE IF NOT EXISTS guias_remision (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    token_publico VARCHAR(64) NULL,
                    serie VARCHAR(10) NOT NULL DEFAULT 'T001',
                    correlativo INT NOT NULL,
                    fecha_emision DATE NOT NULL,
                    motivo_traslado VARCHAR(120) NOT NULL,
                    peso_bruto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    destinatario_doc VARCHAR(20) NULL,
                    destinatario_razon VARCHAR(200) NULL,
                    partida_direccion TEXT NULL,
                    partida_ubigeo VARCHAR(10) NULL,
                    llegada_direccion TEXT NULL,
                    llegada_ubigeo VARCHAR(10) NULL,
                    modalidad_transporte VARCHAR(80) NULL,
                    conductor_doc VARCHAR(50) NULL,
                    vehiculo_placa VARCHAR(20) NULL,
                    estado VARCHAR(30) DEFAULT 'Emitida',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                try {
                    $conn->exec("ALTER TABLE guias_remision ADD COLUMN IF NOT EXISTS token_publico VARCHAR(64) NULL AFTER id");
                } catch (Exception $eCol) {}

                $conn->exec("CREATE TABLE IF NOT EXISTS guia_remision_lineas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    guia_id INT NOT NULL,
                    cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
                    unidad VARCHAR(20) NOT NULL DEFAULT 'NIU',
                    descripcion VARCHAR(255) NOT NULL,
                    FOREIGN KEY (guia_id) REFERENCES guias_remision(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $conn->beginTransaction();
                validarStockDisponible($conn, $itemsGuia);

                $editId = (int)($_POST['guia_id'] ?? 0);
                if ($editId > 0) {
                    $stEx = $conn->prepare("SELECT id, serie, correlativo, token_publico FROM guias_remision WHERE id = ? FOR UPDATE");
                    $stEx->execute([$editId]);
                    $ex = $stEx->fetch(PDO::FETCH_ASSOC);
                    if (!$ex) {
                        throw new Exception("Guía de remisión no encontrada para editar.");
                    }
                    $tok = !empty($ex['token_publico']) ? $ex['token_publico'] : bin2hex(random_bytes(16));
                    $numGuia = $ex['serie'] . '-' . str_pad((string)$ex['correlativo'], 6, '0', STR_PAD_LEFT);

                    $stUpd = $conn->prepare("UPDATE guias_remision SET
                        token_publico = ?, fecha_emision = ?, motivo_traslado = ?, peso_bruto = ?,
                        destinatario_doc = ?, destinatario_razon = ?,
                        partida_direccion = ?, partida_ubigeo = ?,
                        llegada_direccion = ?, llegada_ubigeo = ?,
                        modalidad_transporte = ?, conductor_doc = ?, vehiculo_placa = ?
                        WHERE id = ?");
                    $stUpd->execute([$tok, $fecha, $motivo, $peso, $destDoc, $destRazon, $partDir, $partUbg, $llegDir, $llegUbg, $modalidad, $conductor, $placa, $editId]);

                    $conn->prepare("DELETE FROM guia_remision_lineas WHERE guia_id = ?")->execute([$editId]);
                    $guiaId = $editId;
                    $flashMsg = "Guía de Remisión {$numGuia} actualizada correctamente.";
                } else {
                    $serie = getSystemConfig('serie_guia', 'T001') ?: 'T001';
                    $stMax = $conn->prepare("SELECT MAX(correlativo) FROM guias_remision WHERE serie = ? FOR UPDATE");
                    $stMax->execute([$serie]);
                    $corr = ((int)$stMax->fetchColumn()) + 1;
                    $numGuia = $serie . '-' . str_pad((string)$corr, 6, '0', STR_PAD_LEFT);
                    $tok = bin2hex(random_bytes(16));

                    $stIns = $conn->prepare("INSERT INTO guias_remision
                        (token_publico, serie, correlativo, fecha_emision, motivo_traslado, peso_bruto, destinatario_doc, destinatario_razon, partida_direccion, partida_ubigeo, llegada_direccion, llegada_ubigeo, modalidad_transporte, conductor_doc, vehiculo_placa, estado)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Emitida')");
                    $stIns->execute([$tok, $serie, $corr, $fecha, $motivo, $peso, $destDoc, $destRazon, $partDir, $partUbg, $llegDir, $llegUbg, $modalidad, $conductor, $placa]);
                    $guiaId = (int)$conn->lastInsertId();
                    $flashMsg = "Guía de Remisión {$numGuia} emitida y registrada correctamente.";
                }

                $stLin = $conn->prepare("INSERT INTO guia_remision_lineas (guia_id, cantidad, unidad, descripcion) VALUES (?, ?, ?, ?)");
                foreach ($itemsGuia as $it) {
                    $stLin->execute([$guiaId, $it['qty'], $it['unidad'], $it['desc']]);
                }

                $conn->commit();
                $_SESSION['flash_guia'] = $flashMsg;
                header("Location: guias.php");
                exit;
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $error_msg = $e->getMessage();
            }
        }
    }
}

// Cargar guía existente si viene ?editar=ID
$guiaEdit = null;
$guiaEditLineas = [];
if (!empty($_GET['editar'])) {
    $edId = (int)$_GET['editar'];
    try {
        $stG = $conn->prepare("SELECT * FROM guias_remision WHERE id = ? LIMIT 1");
        $stG->execute([$edId]);
        $guiaEdit = $stG->fetch(PDO::FETCH_ASSOC);
        if ($guiaEdit) {
            $stGL = $conn->prepare("SELECT * FROM guia_remision_lineas WHERE guia_id = ? ORDER BY id ASC");
            $stGL->execute([$edId]);
            $guiaEditLineas = $stGL->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// Cargar catálogo de productos activos para autocompletado y selección rápida
$productosCatalogo = [];
try {
    $stmtProd = $conn->query("SELECT id, codigo, descripcion, unidad_medida, precio_unitario, stock, stock_minimo, lote_actual FROM productos WHERE estado = 'Activo' ORDER BY descripcion ASC");
    if ($stmtProd) {
        $productosCatalogo = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

include 'includes/header.php'; 
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php
    $pageTitle = 'Nueva Guía de Remisión';
    $topbarAction = [
        'label' => 'Ver Guías',
        'icon'  => 'fa-solid fa-truck-fast text-xs',
        'url'   => 'guias.php'
    ];
    include 'includes/topbar.php';
    ?>

    <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
        <div class="max-w-5xl mx-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-6">
                <?php echo $guiaEdit ? ('Editar Guía de Remisión ' . htmlspecialchars($guiaEdit['serie'] . '-' . str_pad((string)$guiaEdit['correlativo'], 6, '0', STR_PAD_LEFT))) : 'Emitir Guía de Remisión Electrónica'; ?>
            </h3>

            <?php if ($error_msg): ?>
                <div class="mb-5 p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold flex items-center gap-2.5">
                    <i class="fa-solid fa-circle-exclamation text-rose-500 text-base shrink-0"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="formGuia">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="guia_id" value="<?php echo (int)($guiaEdit['id'] ?? 0); ?>">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden p-8">
                
                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">1. Datos Generales</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Motivo del Traslado</label>
                        <select name="motivo_traslado" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                            <option>Venta</option>
                            <option>Venta sujeta a confirmación</option>
                            <option>Compra</option>
                            <option>Traslado entre establecimientos de la misma empresa</option>
                            <option>Devolución</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de Emisión / Traslado</label>
                        <input type="date" name="fecha_emision" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Peso Bruto Total (KGM)</label>
                        <input type="number" name="peso_bruto" step="0.01" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary" placeholder="Ej. 15.50">
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">2. Datos del Destinatario</h4>
                <div class="mb-8">
                    <div class="flex gap-4">
                        <div class="flex-1 relative flex">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <i class="fa-solid fa-magnifying-glass text-gray-400"></i>
                            </div>
                            <input type="text" id="num_doc" name="destinatario_doc" class="flex-1 bg-white border border-gray-300 rounded-l-lg pl-10 p-2.5 text-sm focus:ring-primary focus:border-primary shadow-sm" placeholder="Ingrese RUC o DNI...">
                            <button type="button" id="btn_buscar_doc" class="bg-gray-100 hover:bg-gray-200 border border-l-0 border-gray-300 px-4 rounded-r-lg text-sm font-medium text-gray-600 flex items-center transition-colors">
                                <i id="icon_buscar" class="fa-solid fa-magnifying-glass mr-2"></i> <span id="text_buscar">Buscar</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 hidden" id="datos_cliente_encontrado">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Razón Social / Nombres</label>
                            <input type="text" id="razon_social" name="destinatario_razon" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-sm text-gray-800 focus:border-brand-500 focus:outline-none" placeholder="Editable...">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm text-gray-600">Dirección (Llegada sugerida)</label>
                                <button type="button" id="btn_usar_dir_dest" class="text-[11px] font-bold text-emerald-600 hover:underline">
                                    <i class="fa-solid fa-arrow-down mr-1"></i>Copiar a Punto de Llegada
                                </button>
                            </div>
                            <input type="text" id="direccion" name="destinatario_dir" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-sm text-gray-800 focus:border-brand-500 focus:outline-none" placeholder="Editable...">
                        </div>
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">3. Direcciones de Traslado</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- PUNTO DE PARTIDA -->
                    <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-200/80 space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <h5 class="font-bold text-blue-900 text-sm"><i class="fa-solid fa-location-dot mr-1.5 text-blue-600"></i>Punto de Partida</h5>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="abrirMapaPara('partida')" class="px-2.5 py-1 rounded-lg bg-white border border-blue-200 hover:bg-blue-100 text-blue-700 text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Elegir en mapa con pin">
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    <span>Mapa</span>
                                </button>
                                <button type="button" onclick="abrirModalGuardarPunto('partida')" class="px-2.5 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Registrar nuevo punto de partida">
                                    <i class="fa-solid fa-plus"></i>
                                    <span>Nuevo</span>
                                </button>
                            </div>
                        </div>
                        <select id="sel_punto_partida" class="w-full bg-white border border-blue-200 rounded-lg p-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-blue-500">
                            <option value="">— Elegir local / punto registrado —</option>
                        </select>
                        <input type="text" id="partida_direccion" name="partida_direccion" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm" placeholder="Dirección detallada de partida...">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" id="partida_ubigeo" name="partida_ubigeo" maxlength="6" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-xs font-mono font-bold" placeholder="Ubigeo (Ej. 150101)">
                            <input type="text" id="partida_coords" name="partida_coords" class="w-full bg-white/80 border border-gray-300 rounded-lg p-2 text-[11px] font-mono text-slate-600" placeholder="Lat, Lng (opcional)">
                        </div>
                    </div>

                    <!-- PUNTO DE LLEGADA -->
                    <div class="bg-green-50/50 p-4 rounded-xl border border-green-200/80 space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <h5 class="font-bold text-green-900 text-sm"><i class="fa-solid fa-flag-checkered mr-1.5 text-green-600"></i>Punto de Llegada</h5>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="abrirMapaPara('llegada')" class="px-2.5 py-1 rounded-lg bg-white border border-green-200 hover:bg-green-100 text-green-700 text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Elegir en mapa con pin">
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    <span>Mapa</span>
                                </button>
                                <button type="button" onclick="abrirModalGuardarPunto('llegada')" class="px-2.5 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Registrar nuevo punto de llegada">
                                    <i class="fa-solid fa-plus"></i>
                                    <span>Nuevo</span>
                                </button>
                            </div>
                        </div>
                        <select id="sel_punto_llegada" class="w-full bg-white border border-green-200 rounded-lg p-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-green-500">
                            <option value="">— Elegir punto de llegada frecuente —</option>
                        </select>
                        <input type="text" id="llegada_direccion" name="llegada_direccion" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm" placeholder="Dirección detallada de entrega...">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" id="llegada_ubigeo" name="llegada_ubigeo" maxlength="6" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-xs font-mono font-bold" placeholder="Ubigeo de llegada">
                            <input type="text" id="llegada_coords" name="llegada_coords" class="w-full bg-white/80 border border-gray-300 rounded-lg p-2 text-[11px] font-mono text-slate-600" placeholder="Lat, Lng (opcional)">
                        </div>
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">4. Datos del Transporte Público o Privado</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Modalidad de Transporte</label>
                        <select name="modalidad_transporte" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                            <option>Transporte Privado (Propios)</option>
                            <option>Transporte Público (Terceros)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">DNI/Licencia del Conductor</label>
                        <input type="text" name="conductor_doc" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary" placeholder="Número">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Placa del Vehículo</label>
                        <input type="text" name="vehiculo_placa" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary" placeholder="Ej. ABC-123">
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">5. Bienes a Transportar</h4>
                <div class="mb-8">
                    <div class="overflow-x-auto mb-4 border border-gray-100 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[600px]">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 text-xs uppercase tracking-wider">
                                    <th class="px-4 py-3 font-semibold w-28">Cant.</th>
                                    <th class="px-4 py-3 font-semibold w-32">Unidad</th>
                                    <th class="px-4 py-3 font-semibold min-w-[200px]">Descripción del Bien</th>
                                    <th class="px-4 py-3 font-semibold w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="items_table_body">
                                <tr class="border-b border-gray-100 item-row">
                                    <td class="px-4 py-3"><input type="number" name="qty[]" value="1" min="0.01" step="any" class="item-qty w-full border border-gray-300 rounded p-1.5 text-sm text-center" required></td>
                                    <td class="px-4 py-3">
                                        <select name="unidad[]" class="item-unit w-full border border-gray-300 rounded p-1.5 text-sm">
                                            <option value="NIU">NIU (Unidades)</option>
                                            <option value="KGM">KGM (Kilos)</option>
                                            <option value="LTR">LTR (Litros)</option>
                                            <option value="MTR">MTR (Metros)</option>
                                            <option value="ZZ">ZZ (Servicios / Otros)</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="product-dropdown-wrapper relative w-full">
                                            <div class="relative flex items-center">
                                                <input type="text" name="desc[]" placeholder="Escribe o busca del catálogo..." autocomplete="off" class="item-desc w-full border border-gray-300 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-blue-500" required>
                                                <button type="button" tabindex="-1" class="btn-toggle-dropdown absolute right-2 text-gray-400 hover:text-blue-600 p-1 rounded transition-transform" title="Ver catálogo">
                                                    <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200"></i>
                                                </button>
                                            </div>
                                            <div class="product-dropdown-menu hidden fixed z-50 min-w-[300px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                                                <div class="px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                                    <div class="flex items-center gap-2">
                                                        <div class="w-5 h-5 rounded-md bg-blue-500/10 text-blue-600 flex items-center justify-center text-[10px]">
                                                            <i class="fa-solid fa-boxes-stacked"></i>
                                                        </div>
                                                        <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Catálogo de Productos</span>
                                                    </div>
                                                    <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">
                                                        <?php echo count($productosCatalogo); ?> ítems
                                                    </span>
                                                </div>
                                                <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                                                <div class="px-3 py-2 bg-slate-50/60 dark:bg-slate-950/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
                                                    <span><i class="fa-regular fa-keyboard mr-1"></i> Navega con ↑ ↓ y Enter</span>
                                                    <a href="productos.php" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1 font-semibold">
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none"></i></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="btn_add_row" class="text-primary text-sm font-medium hover:text-blue-700"><i class="fa-solid fa-plus mr-1"></i> Agregar Bien</button>
                </div>

                <div id="alert_stock_guia" class="hidden mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold flex items-center gap-2">
                    <i class="fa-solid fa-ban shrink-0"></i>
                    <span>Stock insuficiente en uno o más bienes. Ajusta la cantidad para emitir la guía.</span>
                </div>

                <div class="flex justify-end gap-4 mt-8 pt-6 border-t border-gray-100">
                    <a href="guias.php" class="px-6 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors">Cancelar</a>
                    <button type="submit" id="btn_submit_guia" class="px-6 py-2.5 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors shadow-sm flex items-center disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-paper-plane mr-2"></i> <span id="txt_submit_guia">Emitir Guía a SUNAT</span>
                    </button>
                </div>
            </div>
            </form>
        </div>
    </div>
</main>

<!-- Modal de Guardado Directo de Punto de Partida / Llegada -->
<div id="modal_nuevo_punto_guia" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h4 id="modal_punto_titulo" class="text-sm font-extrabold text-slate-900 dark:text-white">Registrar Punto de Traslado</h4>
            <button type="button" onclick="cerrarModalPuntoGuia()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-5 space-y-3">
            <input type="hidden" id="mp_tipo" value="partida">
            <div>
                <label class="block text-[11px] font-bold uppercase text-slate-500 mb-1">Nombre / Alias del Local *</label>
                <input type="text" id="mp_alias" placeholder="Ej. Almacén Ate, Tienda Miraflores..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-semibold">
            </div>
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-[11px] font-bold uppercase text-slate-500">Dirección Exacta *</label>
                    <button type="button" onclick="elegirEnMapaParaModal()" class="text-[11px] font-bold text-brand-600 hover:underline flex items-center gap-1">
                        <i class="fa-solid fa-map-location-dot"></i> Elegir en Mapa
                    </button>
                </div>
                <input type="text" id="mp_direccion" placeholder="Av. / Jr. / Calle..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs">
            </div>
            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <label class="block text-[11px] font-bold uppercase text-slate-500 mb-1">Ubigeo (6 dígitos)</label>
                    <input type="text" id="mp_ubigeo" maxlength="6" placeholder="150101" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono font-bold">
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase text-slate-500 mb-1">Coordenadas</label>
                    <input type="text" id="mp_coords" placeholder="-12.0464, -77.0428" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-[11px] font-mono">
                </div>
            </div>
        </div>
        <div class="px-5 py-3.5 bg-slate-50 border-t border-slate-100 flex justify-end gap-2">
            <button type="button" onclick="cerrarModalPuntoGuia()" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600">Cancelar</button>
            <button type="button" id="btn_guardar_punto_guia" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold">Guardar y Usar</button>
        </div>
    </div>
</div>

<script src="js/mapa_ubigeo.js?v=<?php echo time(); ?>"></script>
<script src="js/product_dropdown.js?v=<?php echo time(); ?>"></script>
<script>
const catalogoProductos = <?php echo json_encode($productosCatalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let PUNTOS_TRASLADO = { partida: [], llegada: [], empresa_default: null };

async function cargarPuntosTraslado() {
    try {
        const res = await fetch('api/puntos_traslado.php');
        const json = await res.json();
        if (!json || !json.success) return;
        PUNTOS_TRASLADO = json;

        const selP = document.getElementById('sel_punto_partida');
        const selL = document.getElementById('sel_punto_llegada');

        if (selP) {
            selP.innerHTML = '<option value="">— Elegir local / punto de partida —</option>';
            if (json.empresa_default && json.empresa_default.direccion) {
                const optDef = document.createElement('option');
                optDef.value = 'empresa_default';
                optDef.textContent = '🏢 ' + json.empresa_default.alias + ' — ' + json.empresa_default.direccion;
                selP.appendChild(optDef);
            }
            (json.partida || []).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = `📍 ${p.alias} (${p.ubigeo || ''}) — ${p.direccion}`;
                selP.appendChild(opt);
            });
        }

        if (selL) {
            selL.innerHTML = '<option value="">— Elegir punto de llegada frecuente —</option>';
            (json.llegada || []).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = `🏁 ${p.alias} (${p.ubigeo || ''}) — ${p.direccion}`;
                selL.appendChild(opt);
            });
        }
    } catch (e) {}
}

function aplicarPuntoEnCampos(tipo, p) {
    if (!p) return;
    document.getElementById(tipo + '_direccion').value = p.direccion || '';
    document.getElementById(tipo + '_ubigeo').value    = p.ubigeo || '';
    document.getElementById(tipo + '_coords').value    = (p.lat && p.lng) ? `${p.lat}, ${p.lng}` : '';
}

function abrirMapaPara(tipo) {
    const coordsVal = (document.getElementById(tipo + '_coords').value || '').split(',');
    window.abrirModalMapaUbigeo({
        titulo: tipo === 'partida' ? 'Ubicar Punto de Partida en el Mapa' : 'Ubicar Punto de Llegada en el Mapa',
        direccion: document.getElementById(tipo + '_direccion').value,
        ubigeo: document.getElementById(tipo + '_ubigeo').value,
        lat: coordsVal[0] ? parseFloat(coordsVal[0]) : null,
        lng: coordsVal[1] ? parseFloat(coordsVal[1]) : null,
        onConfirm: (res) => {
            aplicarPuntoEnCampos(tipo, res);
            if (window.showToast) window.showToast('Dirección, ubigeo y coordenadas completados desde el mapa.', 'success');
        }
    });
}

function abrirModalGuardarPunto(tipo) {
    document.getElementById('mp_tipo').value = tipo;
    document.getElementById('modal_punto_titulo').textContent = tipo === 'partida' ? 'Registrar Nuevo Punto de Partida' : 'Registrar Nuevo Punto de Llegada';
    document.getElementById('mp_alias').value = '';
    document.getElementById('mp_direccion').value = document.getElementById(tipo + '_direccion').value || '';
    document.getElementById('mp_ubigeo').value    = document.getElementById(tipo + '_ubigeo').value || '150101';
    document.getElementById('mp_coords').value    = document.getElementById(tipo + '_coords').value || '';
    document.getElementById('modal_nuevo_punto_guia').classList.remove('hidden');
}

function cerrarModalPuntoGuia() {
    document.getElementById('modal_nuevo_punto_guia').classList.add('hidden');
}

function elegirEnMapaParaModal() {
    const coordsVal = (document.getElementById('mp_coords').value || '').split(',');
    window.abrirModalMapaUbigeo({
        titulo: 'Seleccionar Ubicación del Nuevo Punto',
        direccion: document.getElementById('mp_direccion').value,
        ubigeo: document.getElementById('mp_ubigeo').value,
        lat: coordsVal[0] ? parseFloat(coordsVal[0]) : null,
        lng: coordsVal[1] ? parseFloat(coordsVal[1]) : null,
        onConfirm: (res) => {
            document.getElementById('mp_direccion').value = res.direccion || '';
            document.getElementById('mp_ubigeo').value = res.ubigeo || '150101';
            document.getElementById('mp_coords').value = (res.lat && res.lng) ? `${res.lat.toFixed(6)}, ${res.lng.toFixed(6)}` : '';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    cargarPuntosTraslado();

    document.getElementById('sel_punto_partida')?.addEventListener('change', function() {
        const val = this.value;
        if (!val) return;
        if (val === 'empresa_default') {
            aplicarPuntoEnCampos('partida', PUNTOS_TRASLADO.empresa_default);
        } else {
            const found = (PUNTOS_TRASLADO.partida || []).find(p => p.id === val);
            aplicarPuntoEnCampos('partida', found);
        }
    });

    document.getElementById('sel_punto_llegada')?.addEventListener('change', function() {
        const val = this.value;
        if (!val) return;
        const found = (PUNTOS_TRASLADO.llegada || []).find(p => p.id === val);
        aplicarPuntoEnCampos('llegada', found);
    });

    document.getElementById('btn_usar_dir_dest')?.addEventListener('click', function() {
        const dir = document.getElementById('direccion')?.value || '';
        if (dir && dir !== '-') {
            document.getElementById('llegada_direccion').value = dir;
            if (window.showToast) window.showToast('Dirección del destinatario copiada a Punto de Llegada.', 'info');
        }
    });

    document.getElementById('btn_guardar_punto_guia')?.addEventListener('click', async function() {
        const tipo = document.getElementById('mp_tipo').value;
        const alias = document.getElementById('mp_alias').value.trim();
        const direccion = document.getElementById('mp_direccion').value.trim();
        const ubigeo = document.getElementById('mp_ubigeo').value.trim();
        const coordsParts = (document.getElementById('mp_coords').value || '').split(',');
        const lat = coordsParts[0] ? parseFloat(coordsParts[0]) : null;
        const lng = coordsParts[1] ? parseFloat(coordsParts[1]) : null;

        if (!direccion) {
            if (window.showToast) window.showToast('Ingrese la dirección del punto.', 'warning');
            return;
        }

        try {
            const r = await fetch('api/puntos_traslado.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save', tipo, alias, direccion, ubigeo, lat, lng })
            });
            const j = await r.json();
            if (!j.success) throw new Error(j.message || 'No se pudo guardar.');
            aplicarPuntoEnCampos(tipo, j.punto);
            await cargarPuntosTraslado();
            cerrarModalPuntoGuia();
            if (window.showToast) window.showToast('Punto guardado en configuración y aplicado a la guía.', 'success');
        } catch (err) {
            if (window.showToast) window.showToast(err.message, 'error');
        }
    });

    // ---- BÚSQUEDA DE DESTINATARIO (API) ----
    const btnBuscarDoc = document.getElementById('btn_buscar_doc');
    const iconBuscar = document.getElementById('icon_buscar');
    const textBuscar = document.getElementById('text_buscar');
    const numDocInput = document.getElementById('num_doc');
    const contenedorDatos = document.getElementById('datos_cliente_encontrado');

    if (btnBuscarDoc) {
        function resetBtn() {
            iconBuscar.className = 'fa-solid fa-magnifying-glass mr-2';
            textBuscar.innerText = 'Buscar';
            btnBuscarDoc.disabled = false;
        }

        btnBuscarDoc.addEventListener('click', async function() {
            const numDoc = numDocInput.value.trim();
            if (numDoc.length !== 8 && numDoc.length !== 11) {
                alert('El documento debe tener 8 dígitos (DNI) o 11 dígitos (RUC).');
                return;
            }
            const tipoDoc = numDoc.length === 8 ? 'DNI' : 'RUC';
            iconBuscar.className = 'fa-solid fa-spinner fa-spin mr-2';
            textBuscar.innerText = 'Buscando...';
            btnBuscarDoc.disabled = true;
            try {
                const response = await fetch('api/consultar_documento.php?documento=' + encodeURIComponent(numDoc));
                if (!response.ok) throw new Error('Error de red al consultar el documento.');
                const jsonResponse = await response.json();
                if (!jsonResponse.success) throw new Error(jsonResponse.message || 'Documento no encontrado.');
                const data = jsonResponse.data;
                contenedorDatos.classList.remove('hidden');
                document.getElementById('razon_social').value = data.razon_social || data.nombre_o_razon_social || data.nombre_completo || '';
                document.getElementById('direccion').value = data.direccion || data.direccion_completa || '-';

                iconBuscar.className = 'fa-solid fa-check text-green-500 mr-2';
                textBuscar.innerText = 'Encontrado';
                setTimeout(() => resetBtn(), 2000);
            } catch (error) {
                console.error(error);
                alert(error.message || 'Error al consultar el documento.');
                resetBtn();
                contenedorDatos.classList.add('hidden');
            }
        });
        numDocInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); btnBuscarDoc.click(); }
        });
    }

    // ---- AGREGAR LÍNEAS DINÁMICAS Y DESPLEGABLE DE PRODUCTOS ----
    const tbody = document.getElementById('items_table_body');
    const btnAddRow = document.getElementById('btn_add_row');

    function initGuiaRowDropdown(rowElement) {
        const wrapper = rowElement.querySelector('.product-dropdown-wrapper');
        if (!wrapper || typeof window.attachProductDropdown !== 'function') return;
        window.attachProductDropdown(wrapper, catalogoProductos, function(product, row) {
            if (!product || !row) return;
            const unitSel = row.querySelector('.item-unit');
            if (unitSel && product.unidad_medida) {
                const um = product.unidad_medida.toUpperCase();
                let found = false;
                for (let i = 0; i < unitSel.options.length; i++) {
                    if (unitSel.options[i].value.toUpperCase() === um) {
                        unitSel.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    unitSel.add(new Option(um, um, true, true));
                }
            }
            validarStockGuia();
        });
    }

    if (tbody) {
        tbody.querySelectorAll('.item-row').forEach(initGuiaRowDropdown);
    }

    if (btnAddRow && tbody) {
        btnAddRow.addEventListener('click', function() {
            const tr = document.createElement('tr');
            tr.className = 'border-b border-gray-100 item-row';
            tr.innerHTML = `
                <td class="px-4 py-3"><input type="number" name="qty[]" value="1" min="0.01" step="any" class="item-qty w-full border border-gray-300 rounded p-1.5 text-sm text-center" required></td>
                <td class="px-4 py-3">
                    <select name="unidad[]" class="item-unit w-full border border-gray-300 rounded p-1.5 text-sm">
                        <option value="NIU">NIU (Unidades)</option>
                        <option value="KGM">KGM (Kilos)</option>
                        <option value="LTR">LTR (Litros)</option>
                        <option value="MTR">MTR (Metros)</option>
                        <option value="ZZ">ZZ (Servicios / Otros)</option>
                    </select>
                </td>
                <td class="px-4 py-3">
                    <div class="product-dropdown-wrapper relative w-full">
                        <div class="relative flex items-center">
                            <input type="text" name="desc[]" placeholder="Escribe o busca del catálogo..." autocomplete="off" class="item-desc w-full border border-gray-300 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-blue-500" required>
                            <button type="button" tabindex="-1" class="btn-toggle-dropdown absolute right-2 text-gray-400 hover:text-blue-600 p-1 rounded transition-transform" title="Ver catálogo">
                                <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200"></i>
                            </button>
                        </div>
                        <div class="product-dropdown-menu hidden fixed z-50 min-w-[300px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                            <div class="px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="w-5 h-5 rounded-md bg-blue-500/10 text-blue-600 flex items-center justify-center text-[10px]">
                                        <i class="fa-solid fa-boxes-stacked"></i>
                                    </div>
                                    <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Catálogo de Productos</span>
                                </div>
                                <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">
                                    ${catalogoProductos.length} ítems
                                </span>
                            </div>
                            <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                            <div class="px-3 py-2 bg-slate-50/60 dark:bg-slate-950/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
                                <span><i class="fa-regular fa-keyboard mr-1"></i> Navega con ↑ ↓ y Enter</span>
                                <a href="productos.php" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1 font-semibold">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                                </a>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none"></i></td>
            `;
            tbody.appendChild(tr);
            initGuiaRowDropdown(tr);
            tr.querySelector('.item-desc')?.focus();
        });

        tbody.addEventListener('input', function(e) {
            if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-desc')) {
                validarStockGuia();
            }
        });

        tbody.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-remove') || e.target.closest('.btn-remove')) {
                const row = e.target.closest('.item-row');
                if (tbody.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                    validarStockGuia();
                } else {
                    alert('Debe haber al menos un bien a transportar.');
                }
            }
        });
    }

    function validarStockGuia() {
        if (!tbody) return true;
        const rows = tbody.querySelectorAll('.item-row');
        const solicitadoPorId = {};
        const prodPorFila = [];

        rows.forEach((row, idx) => {
            const desc = (row.querySelector('.item-desc')?.value || '').trim().toLowerCase();
            const qty  = parseFloat(row.querySelector('.item-qty')?.value) || 0;
            const prod = catalogoProductos.find(p =>
                (p.descripcion || '').trim().toLowerCase() === desc ||
                (p.codigo || '').trim().toLowerCase() === desc
            );
            if (prod) {
                const code = (prod.codigo || '').toUpperCase();
                const unit = (prod.unidad_medida || '').toUpperCase();
                const esServicio = code.startsWith('SRV') || unit === 'ZZ';
                if (!esServicio) {
                    solicitadoPorId[prod.id] = (solicitadoPorId[prod.id] || 0) + qty;
                    prodPorFila[idx] = prod;
                    return;
                }
            }
            prodPorFila[idx] = null;
        });

        let hayBloqueo = false;
        rows.forEach((row, idx) => {
            const prod = prodPorFila[idx];
            const qtyInput = row.querySelector('.item-qty');
            const qtyCell = qtyInput ? qtyInput.closest('td') : null;
            let errEl = qtyCell ? qtyCell.querySelector('.stock-error-msg') : null;

            if (prod && solicitadoPorId[prod.id] > (parseFloat(prod.stock) || 0) + 0.0001) {
                hayBloqueo = true;
                const disp = parseFloat(prod.stock) || 0;
                if (qtyInput) qtyInput.classList.add('text-rose-600', 'bg-rose-50', 'border-rose-400');
                if (qtyCell) {
                    if (!errEl) {
                        errEl = document.createElement('p');
                        errEl.className = 'stock-error-msg text-[10px] font-bold text-rose-600 text-center mt-1';
                        qtyCell.appendChild(errEl);
                    }
                    errEl.textContent = `Máx: ${disp}`;
                }
            } else {
                if (qtyInput) qtyInput.classList.remove('text-rose-600', 'bg-rose-50', 'border-rose-400');
                if (errEl) errEl.remove();
            }
        });

        const btnSubmit = document.getElementById('btn_submit_guia');
        const txtSubmit = document.getElementById('txt_submit_guia');
        const alertBox  = document.getElementById('alert_stock_guia');
        if (btnSubmit) btnSubmit.disabled = hayBloqueo;
        if (txtSubmit) txtSubmit.textContent = hayBloqueo ? 'Stock Insuficiente' : 'Emitir Guía a SUNAT';
        if (alertBox) alertBox.classList.toggle('hidden', !hayBloqueo);
        return !hayBloqueo;
    }

    document.getElementById('formGuia')?.addEventListener('submit', function(e) {
        if (!validarStockGuia()) {
            e.preventDefault();
            if (window.showToast) window.showToast('No puedes emitir una guía con cantidades superiores al stock disponible.', 'error', 'Stock Insuficiente');
        }
    });

    <?php if ($guiaEdit): ?>
    // Precargar datos de edición
    (function prefillGuiaEdit() {
        const g = <?php echo json_encode($guiaEdit, JSON_UNESCAPED_UNICODE); ?>;
        const lines = <?php echo json_encode($guiaEditLineas, JSON_UNESCAPED_UNICODE); ?>;
        const form = document.getElementById('formGuia');
        if (!form) return;
        const setVal = (sel, val) => { const el = form.querySelector(sel); if (el && val !== null && val !== undefined) el.value = val; };
        setVal('[name="motivo_traslado"]', g.motivo_traslado);
        setVal('[name="fecha_emision"]', g.fecha_emision);
        setVal('[name="peso_bruto"]', g.peso_bruto);
        setVal('[name="destinatario_doc"]', g.destinatario_doc);
        setVal('[name="destinatario_razon"]', g.destinatario_razon);
        if (g.destinatario_razon) document.getElementById('datos_cliente_encontrado')?.classList.remove('hidden');
        setVal('[name="partida_direccion"]', g.partida_direccion);
        setVal('[name="partida_ubigeo"]', g.partida_ubigeo);
        setVal('[name="llegada_direccion"]', g.llegada_direccion);
        setVal('[name="llegada_ubigeo"]', g.llegada_ubigeo);
        setVal('[name="modalidad_transporte"]', g.modalidad_transporte);
        setVal('[name="conductor_doc"]', g.conductor_doc);
        setVal('[name="vehiculo_placa"]', g.vehiculo_placa);

        if (Array.isArray(lines) && lines.length > 0 && tbody) {
            const firstRow = tbody.querySelector('.item-row');
            for (let i = 1; i < lines.length; i++) btnAddRow.click();
            const rows = tbody.querySelectorAll('.item-row');
            lines.forEach((ln, idx) => {
                const r = rows[idx];
                if (!r) return;
                const qEl = r.querySelector('.item-qty');
                const uEl = r.querySelector('.item-unit');
                const dEl = r.querySelector('.item-desc');
                if (qEl) qEl.value = parseFloat(ln.cantidad);
                if (uEl && ln.unidad) {
                    if (![...uEl.options].some(o => o.value === ln.unidad)) uEl.add(new Option(ln.unidad, ln.unidad));
                    uEl.value = ln.unidad;
                }
                if (dEl) dEl.value = ln.descripcion;
            });
            validarStockGuia();
        }
    })();
    <?php endif; ?>
});
</script>

<?php include 'includes/footer.php'; ?>
