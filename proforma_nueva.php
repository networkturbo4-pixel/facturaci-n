<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';

// Procesar guardado de proforma
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o expirado.";
    } else {
        $doc = trim($_POST['cliente_documento'] ?? '');
        $razon = trim($_POST['cliente_razon_social'] ?? '');
        $dir = trim($_POST['cliente_direccion'] ?? '-');
        $fecha = $_POST['fecha_emision'] ?? date('Y-m-d');
        $validez = $_POST['validez_dias'] ?? '15 Días';
        $moneda = $_POST['moneda'] ?? 'PEN';

        $qtys = $_POST['qty'] ?? [];
        $descs = $_POST['desc'] ?? [];
        $prices = $_POST['price'] ?? [];

        if (empty($doc) || empty($razon) || empty($qtys)) {
            $error_msg = "Complete los datos del cliente y agregue al menos un producto a cotizar.";
        } else {
            try {
                $conn->beginTransaction();

                // Calcular totales
                $subtotal = 0;
                foreach ($qtys as $i => $q) {
                    $p = (float)($prices[$i] ?? 0);
                    $subtotal += ((float)$q * $p);
                }
                $igv = $subtotal * 0.18;
                $total = $subtotal + $igv;

                // Generar correlativo COT-00001
                $stmtCorr = $conn->query("SELECT MAX(id) as max_id FROM proformas");
                $maxId = (int)$stmtCorr->fetchColumn();
                $numero = 'COT-' . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO proformas (numero, cliente_documento, cliente_razon_social, cliente_direccion, fecha_emision, validez_dias, moneda, subtotal, igv, total, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente')");
                $stmt->execute([$numero, $doc, $razon, $dir, $fecha, $validez, $moneda, $subtotal, $igv, $total]);
                $proforma_id = $conn->lastInsertId();

                // Guardar líneas
                $stmtLine = $conn->prepare("INSERT INTO proforma_lineas (proforma_id, cantidad, descripcion, precio_unitario, total_linea) VALUES (?, ?, ?, ?, ?)");
                foreach ($qtys as $i => $q) {
                    $d = trim($descs[$i] ?? '');
                    $p = (float)($prices[$i] ?? 0);
                    $tl = (float)$q * $p;
                    $stmtLine->execute([$proforma_id, (float)$q, $d, $p, $tl]);
                }

                $conn->commit();
                $success_msg = "Proforma $numero creada exitosamente.";
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error_msg = "Error al guardar proforma: " . $e->getMessage();
            }
        }
    }
}

// Cargar catálogo de clientes y productos
try {
    $clientes = $conn->query("SELECT numero_documento, razon_social, direccion_fiscal FROM clientes ORDER BY razon_social ASC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    $productos = $conn->query("SELECT codigo, descripcion, precio_unitario, unidad_medida, stock FROM productos WHERE estado = 'Activo' ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clientes = [];
    $productos = [];
}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 dark:bg-slate-950 overflow-hidden">
    <?php
    $pageTitle = 'Nueva Proforma';
    $topbarAction = [
        'label' => 'Ver Proformas',
        'icon'  => 'fa-solid fa-file-signature text-xs',
        'url'   => 'proformas.php'
    ];
    include 'includes/topbar.php';
    ?>

    <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
        <div class="max-w-5xl mx-auto space-y-6">
            
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">Elaborar Proforma / Cotización</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Genera presupuestos comerciales y propuestas con cálculo automático de impuestos</p>
                </div>
                <a href="proformas.php" class="px-3.5 py-2 text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl transition-all shadow-xs">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Volver
                </a>
            </div>
            
            <div class="app-card overflow-hidden p-6 sm:p-8">
                
                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-800 dark:text-emerald-300 rounded-2xl flex items-center justify-between gap-3 shadow-xs">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-emerald-500 text-xl shrink-0"></i>
                            <span class="font-bold text-xs sm:text-sm"><?php echo htmlspecialchars($success_msg); ?></span>
                        </div>
                        <a href="proformas.php" class="text-xs font-extrabold text-emerald-700 dark:text-emerald-400 underline shrink-0 hover:opacity-80">Ir al Listado →</a>
                    </div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="mb-6 p-4 bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/30 text-rose-800 dark:text-rose-300 rounded-2xl flex items-center gap-3 text-xs sm:text-sm font-semibold shadow-xs">
                        <i class="fa-solid fa-circle-exclamation text-rose-500 text-xl shrink-0"></i>
                        <span><?php echo htmlspecialchars($error_msg); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?php echo csrf_field(); ?>
                    
                    <!-- PASO 1: DATOS BASE DE LA COTIZACIÓN -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide mb-1.5">Cliente Frecuente (Opcional)</label>
                            <select id="select_cliente_frecuente" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-medium focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500">
                                <option value="">-- Seleccionar de clientes guardados --</option>
                                <?php foreach ($clientes as $cl): ?>
                                    <option value="<?php echo htmlspecialchars($cl['numero_documento']); ?>" data-razon="<?php echo htmlspecialchars($cl['razon_social']); ?>" data-dir="<?php echo htmlspecialchars($cl['direccion_fiscal'] ?: '-'); ?>">
                                        <?php echo htmlspecialchars($cl['razon_social'] . ' (' . $cl['numero_documento'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide mb-1.5">Fecha de Emisión</label>
                            <input type="date" name="fecha_emision" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-medium focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide mb-1.5">Validez de la Oferta</label>
                            <select name="validez_dias" class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs text-slate-800 dark:text-slate-100 font-semibold focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500">
                                <option value="7 Días">7 Días</option>
                                <option value="15 Días" selected>15 Días</option>
                                <option value="30 Días">30 Días</option>
                                <option value="Hasta agotar stock">Hasta agotar stock</option>
                            </select>
                        </div>
                    </div>

                    <!-- PASO 2: DATOS DEL CLIENTE / DESTINATARIO -->
                    <div class="mb-6 p-4.5 rounded-2xl bg-slate-50/70 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-800">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-user-check text-brand-500"></i>
                                Datos del Destinatario
                            </h4>
                            <span class="text-[11px] text-slate-400">Consultas oficiales en tiempo real</span>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-12 gap-3.5">
                            <div class="sm:col-span-4">
                                <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">DNI / RUC</label>
                                <div class="relative flex items-center">
                                    <input type="text" id="cliente_doc" name="cliente_documento" required maxlength="11" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-mono font-bold text-slate-900 dark:text-white pr-16 focus:outline-none focus:border-brand-500" placeholder="Número de documento">
                                    <button type="button" id="btn_consultar_destinatario" class="absolute right-1 top-1 bottom-1 px-2.5 bg-brand-50 dark:bg-brand-500/20 hover:bg-brand-100 text-brand-700 dark:text-brand-300 rounded-lg text-xs font-bold transition-all flex items-center gap-1" title="Buscar en RENIEC / SUNAT">
                                        <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                                        <span>Buscar</span>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="sm:col-span-5">
                                <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Razón Social / Nombre</label>
                                <input type="text" id="cliente_razon" name="cliente_razon_social" required class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-semibold text-slate-900 dark:text-white focus:outline-none focus:border-brand-500" placeholder="Nombre o Razón Social">
                            </div>
                            
                            <div class="sm:col-span-3">
                                <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Dirección</label>
                                <input type="text" id="cliente_dir" name="cliente_direccion" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs text-slate-800 dark:text-slate-200 focus:outline-none focus:border-brand-500" placeholder="Dirección fiscal">
                            </div>
                        </div>
                    </div>

                    <!-- PASO 3: DETALLE DE PRODUCTOS / SERVICIOS A COTIZAR -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <div>
                                <h4 class="text-sm font-extrabold text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                                    <i class="fa-solid fa-boxes-stacked text-brand-500"></i>
                                    Productos / Servicios a Cotizar
                                </h4>
                                <p class="text-[11px] text-slate-400 mt-0.5">Escribe para buscar o selecciona del catálogo interactivo moderno</p>
                            </div>
                            <span class="text-[11px] font-mono text-slate-500 dark:text-slate-400 font-semibold" id="lbl_count_rows">1 línea activa</span>
                        </div>

                        <div class="overflow-visible mb-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-2xs">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50/90 dark:bg-slate-800/80 border-b border-slate-200/80 dark:border-slate-700 text-slate-500 dark:text-slate-400 text-[11px] uppercase tracking-wider font-extrabold">
                                        <th class="px-3.5 py-3 w-28 text-center">Cant.</th>
                                        <th class="px-3.5 py-3">Descripción del Ítem</th>
                                        <th class="px-3.5 py-3 w-32 text-right">P. Unitario</th>
                                        <th class="px-3.5 py-3 w-32 text-right">Total</th>
                                        <th class="px-3 py-3 w-12 text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="proforma_items_body" class="divide-y divide-slate-100 dark:divide-slate-800/80">
                                    <tr class="item-row hover:bg-slate-50/50 dark:hover:bg-slate-800/40 transition-colors">
                                        <td class="px-3.5 py-3 align-top">
                                            <input type="number" step="0.01" min="1" name="qty[]" value="1" class="item-qty w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs font-bold text-center text-slate-800 dark:text-white focus:outline-none focus:border-brand-500" required>
                                        </td>
                                        <td class="px-3.5 py-3 align-top min-w-[280px]">
                                            <div class="product-dropdown-wrapper relative w-full">
                                                <div class="relative flex items-center">
                                                    <span class="absolute left-3 text-slate-400 dark:text-slate-500 pointer-events-none text-xs">
                                                        <i class="fa-solid fa-magnifying-glass"></i>
                                                    </span>
                                                    <input type="text" 
                                                           name="desc[]" 
                                                           placeholder="Escribe o selecciona producto..." 
                                                           autocomplete="off"
                                                           class="item-desc w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-8 pr-8 py-2 text-xs font-semibold text-slate-800 dark:text-white placeholder:text-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:bg-white dark:focus:bg-slate-900 transition-all" 
                                                           required>
                                                    <button type="button" 
                                                            tabindex="-1"
                                                            class="btn-toggle-dropdown absolute right-2 text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 p-1.5 rounded-lg transition-transform" 
                                                            title="Ver catálogo de ventas">
                                                        <i class="fa-solid fa-chevron-down text-[11px] transition-transform duration-200"></i>
                                                    </button>
                                                </div>

                                                <!-- Modern Floating Dropdown Menu -->
                                                <div class="product-dropdown-menu hidden absolute left-0 top-full mt-1.5 z-50 min-w-[320px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                                                    <div class="px-3.5 py-2.5 bg-slate-50/90 dark:bg-slate-800/90 border-b border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between">
                                                        <div class="flex items-center gap-2">
                                                            <div class="w-5 h-5 rounded-md bg-brand-500/10 text-brand-600 dark:text-brand-400 flex items-center justify-center text-[10px]">
                                                                <i class="fa-solid fa-boxes-stacked"></i>
                                                            </div>
                                                            <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Gestión de Ventas</span>
                                                        </div>
                                                        <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-brand-50 dark:bg-brand-500/20 text-brand-600 dark:text-brand-300">
                                                            <?php echo count($productos); ?> ítems
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
                                        <td class="px-3.5 py-3 align-top">
                                            <input type="number" step="0.01" min="0" name="price[]" value="0.00" class="item-price w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs font-mono font-bold text-right text-slate-800 dark:text-white focus:outline-none focus:border-brand-500 transition-all" required>
                                        </td>
                                        <td class="px-3.5 py-3 align-middle text-right font-mono font-extrabold text-slate-900 dark:text-white item-total whitespace-nowrap text-xs">
                                            S/ 0.00
                                        </td>
                                        <td class="px-3 py-3 align-middle text-center">
                                            <button type="button" class="btn-remove text-rose-500 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors" title="Eliminar fila">
                                                <i class="fa-solid fa-trash-can text-xs pointer-events-none"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <button type="button" id="btn_add_row" class="px-4 py-2.5 rounded-xl bg-brand-50 dark:bg-brand-500/15 hover:bg-brand-100 text-brand-700 dark:text-brand-300 text-xs font-extrabold transition-all inline-flex items-center gap-2">
                            <i class="fa-solid fa-plus-circle"></i>
                            <span>Agregar otra línea</span>
                        </button>
                    </div>

                    <!-- PASO 4: TOTALES -->
                    <div class="flex justify-end mb-8 border-t border-slate-100 dark:border-slate-800 pt-6">
                        <div class="w-full sm:w-80 space-y-3 bg-slate-50 dark:bg-slate-900/60 p-5 rounded-2xl border border-slate-200/80 dark:border-slate-800">
                            <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                                <span>Subtotal</span>
                                <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="lbl_subtotal">S/ 0.00</span>
                            </div>
                            <div class="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                                <span>I.G.V. (18%)</span>
                                <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="lbl_igv">S/ 0.00</span>
                            </div>
                            <div class="flex justify-between text-base font-extrabold text-slate-900 dark:text-white border-t border-slate-200 dark:border-slate-700 pt-3">
                                <span>Total Cotizado</span>
                                <span class="text-brand-600 dark:text-brand-400 font-mono text-lg" id="lbl_total">S/ 0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones Finales -->
                    <div class="flex justify-between items-center border-t border-slate-100 dark:border-slate-800 pt-6">
                        <a href="proformas.php" class="px-5 py-2.5 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                            Cancelar
                        </a>
                        <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
                            <i class="fa-solid fa-file-signature"></i>
                            <span>Emitir Proforma</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</main>

<script src="js/product_dropdown.js?v=1.0"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const catalogoProductos = <?php echo json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    
    // 1. Selección de cliente frecuente
    const selCli = document.getElementById('select_cliente_frecuente');
    if (selCli) {
        selCli.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                document.getElementById('cliente_doc').value = opt.value;
                document.getElementById('cliente_razon').value = opt.getAttribute('data-razon') || '';
                document.getElementById('cliente_dir').value = opt.getAttribute('data-dir') || '';
            }
        });
    }

    // 2. Consulta oficial DNI/RUC
    const btnConsultar = document.getElementById('btn_consultar_destinatario');
    const inpDoc = document.getElementById('cliente_doc');
    if (btnConsultar && inpDoc) {
        btnConsultar.addEventListener('click', async function() {
            const num = inpDoc.value.trim();
            if (num.length !== 8 && num.length !== 11) {
                if (window.showToast) {
                    window.showToast('Ingresa un DNI (8 dígitos) o RUC (11 dígitos) válido.', 'warning', 'Documento Inválido');
                } else {
                    alert('Ingresa un DNI (8 dígitos) o RUC (11 dígitos) válido.');
                }
                return;
            }

            const oldHtml = btnConsultar.innerHTML;
            btnConsultar.disabled = true;
            btnConsultar.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px]"></i>';

            try {
                const tipo = (num.length === 8) ? 'DNI' : 'RUC';
                const res = await fetch(`api/consultar_documento.php?tipo=${tipo}&numero=${encodeURIComponent(num)}`);
                const data = await res.json();

                if (data.success && data.data) {
                    document.getElementById('cliente_razon').value = data.data.razon_social || '';
                    document.getElementById('cliente_dir').value = data.data.direccion || '-';
                    if (window.showToast) {
                        window.showToast(`Datos de ${data.data.razon_social} obtenidos correctamente.`, 'success', 'Consulta Exitosa');
                    }
                } else {
                    if (window.showToast) {
                        window.showToast(data.message || 'No se encontró el documento.', 'warning', 'Aviso');
                    } else {
                        alert(data.message || 'No se encontró el documento.');
                    }
                }
            } catch (e) {
                if (window.showToast) {
                    window.showToast('Error al consultar documento: ' + e.message, 'error', 'Error');
                }
            } finally {
                btnConsultar.disabled = false;
                btnConsultar.innerHTML = oldHtml;
            }
        });
    }

    // 3. Gestión de Ítems y Cálculos
    const tbody = document.getElementById('proforma_items_body');
    const btnAdd = document.getElementById('btn_add_row');
    const lblSubtotal = document.getElementById('lbl_subtotal');
    const lblIgv = document.getElementById('lbl_igv');
    const lblTotal = document.getElementById('lbl_total');
    const lblCount = document.getElementById('lbl_count_rows');

    function calcular() {
        let subtotal = 0;
        const rows = tbody.querySelectorAll('.item-row');
        rows.forEach(row => {
            const q = parseFloat(row.querySelector('.item-qty').value) || 0;
            const p = parseFloat(row.querySelector('.item-price').value) || 0;
            const t = q * p;
            row.querySelector('.item-total').innerText = 'S/ ' + t.toFixed(2);
            subtotal += t;
        });
        const igv = subtotal * 0.18;
        const total = subtotal + igv;
        lblSubtotal.innerText = 'S/ ' + subtotal.toFixed(2);
        lblIgv.innerText = 'S/ ' + igv.toFixed(2);
        lblTotal.innerText = 'S/ ' + total.toFixed(2);

        if (lblCount) {
            lblCount.innerText = rows.length + (rows.length === 1 ? ' línea activa' : ' líneas activas');
        }
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
            calcular();
        });
    }

    function getNewRowHtml() {
        return `
            <td class="px-3.5 py-3 align-top">
                <input type="number" step="0.01" min="1" name="qty[]" value="1" class="item-qty w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs font-bold text-center text-slate-800 dark:text-white focus:outline-none focus:border-brand-500" required>
            </td>
            <td class="px-3.5 py-3 align-top min-w-[280px]">
                <div class="product-dropdown-wrapper relative w-full">
                    <div class="relative flex items-center">
                        <span class="absolute left-3 text-slate-400 dark:text-slate-500 pointer-events-none text-xs">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="text" 
                               name="desc[]" 
                               placeholder="Escribe o selecciona producto..." 
                               autocomplete="off"
                               class="item-desc w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl pl-8 pr-8 py-2 text-xs font-semibold text-slate-800 dark:text-white placeholder:text-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:bg-white dark:focus:bg-slate-900 transition-all" 
                               required>
                        <button type="button" 
                                tabindex="-1"
                                class="btn-toggle-dropdown absolute right-2 text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 p-1.5 rounded-lg transition-transform" 
                                title="Ver catálogo de ventas">
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
            <td class="px-3.5 py-3 align-top">
                <input type="number" step="0.01" min="0" name="price[]" value="0.00" class="item-price w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs font-mono font-bold text-right text-slate-800 dark:text-white focus:outline-none focus:border-brand-500 transition-all" required>
            </td>
            <td class="px-3.5 py-3 align-middle text-right font-mono font-extrabold text-slate-900 dark:text-white item-total whitespace-nowrap text-xs">
                S/ 0.00
            </td>
            <td class="px-3 py-3 align-middle text-center">
                <button type="button" class="btn-remove text-rose-500 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors" title="Eliminar fila">
                    <i class="fa-solid fa-trash-can text-xs pointer-events-none"></i>
                </button>
            </td>
        `;
    }

    // Inicializar filas existentes
    tbody.querySelectorAll('.item-row').forEach(initRowDropdown);

    // Agregar nueva fila
    btnAdd.addEventListener('click', function() {
        const tr = document.createElement('tr');
        tr.className = 'item-row hover:bg-slate-50/50 dark:hover:bg-slate-800/40 transition-colors';
        tr.innerHTML = getNewRowHtml();
        tbody.appendChild(tr);
        initRowDropdown(tr);
        calcular();
        const descInput = tr.querySelector('.item-desc');
        if (descInput) descInput.focus();
    });

    tbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) {
            calcular();
        }
    });

    tbody.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-remove');
        if (btn) {
            const row = btn.closest('.item-row');
            if (tbody.querySelectorAll('.item-row').length > 1) {
                row.remove();
                calcular();
            } else {
                if (window.showToast) {
                    window.showToast('Debe existir al menos un producto cotizado.', 'warning', 'Aviso');
                } else {
                    alert('Debe existir al menos un producto cotizado.');
                }
            }
        }
    });

    calcular();
});
</script>

<?php include 'includes/footer.php'; ?>
