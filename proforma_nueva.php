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
                $conn->rollBack();
                $error_msg = "Error al guardar proforma: " . $e->getMessage();
            }
        }
    }
}

// Cargar catálogo de clientes y productos
try {
    $clientes = $conn->query("SELECT numero_documento, razon_social, direccion_fiscal FROM clientes ORDER BY razon_social ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $productos = $conn->query("SELECT codigo, descripcion, precio_unitario, unidad_medida FROM productos WHERE estado = 'Activo' ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clientes = [];
    $productos = [];
}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
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
        <div class="max-w-5xl mx-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-6">Elaborar Proforma / Cotización</h3>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden p-8">
                
                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-circle-check text-emerald-500 text-xl"></i>
                            <span class="font-medium"><?php echo htmlspecialchars($success_msg); ?></span>
                        </div>
                        <a href="proformas.php" class="text-xs font-semibold text-emerald-700 underline">Ir al Listado →</a>
                    </div>
                <?php endif; ?>

                <?php if ($error_msg): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg flex items-center gap-3">
                        <i class="fa-solid fa-circle-exclamation text-red-500 text-xl"></i>
                        <span><?php echo htmlspecialchars($error_msg); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?php echo csrf_field(); ?>
                    
                    <!-- Datos Base -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 pb-6 border-b border-gray-100">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Cliente Frecuente (Opcional)</label>
                            <select id="select_cliente_frecuente" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                                <option value="">-- Seleccionar de clientes guardados --</option>
                                <?php foreach ($clientes as $cl): ?>
                                    <option value="<?php echo htmlspecialchars($cl['numero_documento']); ?>" data-razon="<?php echo htmlspecialchars($cl['razon_social']); ?>" data-dir="<?php echo htmlspecialchars($cl['direccion_fiscal'] ?: '-'); ?>">
                                        <?php echo htmlspecialchars($cl['razon_social'] . ' (' . $cl['numero_documento'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de Emisión</label>
                            <input type="date" name="fecha_emision" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Validez de la Oferta</label>
                            <select name="validez_dias" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                                <option value="7 Días">7 Días</option>
                                <option value="15 Días" selected>15 Días</option>
                                <option value="30 Días">30 Días</option>
                                <option value="Hasta agotar stock">Hasta agotar stock</option>
                            </select>
                        </div>
                    </div>

                    <!-- Datos del Cliente -->
                    <div class="mb-8 p-4 bg-slate-50 rounded-xl border border-slate-200/80">
                        <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-user"></i> Datos del Destinatario
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">DNI / RUC</label>
                                <input type="text" id="cliente_doc" name="cliente_documento" required class="w-full bg-white border border-slate-300 rounded-lg p-2 text-xs text-slate-800" placeholder="Número de documento">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Razón Social / Nombre</label>
                                <input type="text" id="cliente_razon" name="cliente_razon_social" required class="w-full bg-white border border-slate-300 rounded-lg p-2 text-xs text-slate-800" placeholder="Nombre completo">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Dirección</label>
                                <input type="text" id="cliente_dir" name="cliente_direccion" class="w-full bg-white border border-slate-300 rounded-lg p-2 text-xs text-slate-800" placeholder="Dirección">
                            </div>
                        </div>
                    </div>

                    <!-- Detalle de la Cotización -->
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-semibold text-gray-800">Productos / Servicios a Cotizar</h4>
                            <span class="text-xs text-slate-500">Puedes escribir directamente o elegir del catálogo</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse mb-4">
                                <thead>
                                    <tr class="bg-gray-50 border-y border-gray-200 text-gray-600 text-xs uppercase tracking-wider">
                                        <th class="px-4 py-3 font-semibold w-20">Cant.</th>
                                        <th class="px-4 py-3 font-semibold">Descripción del Ítem</th>
                                        <th class="px-4 py-3 font-semibold w-32">P. Unitario</th>
                                        <th class="px-4 py-3 font-semibold w-32 text-right">Total</th>
                                        <th class="px-4 py-3 font-semibold w-10"></th>
                                    </tr>
                                </thead>
                                <tbody id="proforma_items_body">
                                    <tr class="border-b border-gray-100 item-row">
                                        <td class="px-4 py-3">
                                            <input type="number" step="0.01" min="1" name="qty[]" value="1" class="item-qty w-full border border-gray-300 rounded p-1.5 text-xs text-center" required>
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="text" list="lista_productos" name="desc[]" placeholder="Escribe o selecciona producto..." class="item-desc w-full border border-gray-300 rounded p-1.5 text-xs" required>
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="number" step="0.01" min="0" name="price[]" value="0.00" class="item-price w-full border border-gray-300 rounded p-1.5 text-xs text-right" required>
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-800 item-total whitespace-nowrap">S/ 0.00</td>
                                        <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none text-xs"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Datalist de productos para autocompletar -->
                        <datalist id="lista_productos">
                            <?php foreach ($productos as $pr): ?>
                                <option value="<?php echo htmlspecialchars($pr['descripcion']); ?>" data-price="<?php echo $pr['precio_unitario']; ?>">
                                    <?php echo htmlspecialchars($pr['codigo'] . ' — S/ ' . number_format($pr['precio_unitario'], 2)); ?>
                                </option>
                            <?php endforeach; ?>
                        </datalist>

                        <button type="button" id="btn_add_row" class="text-primary hover:text-blue-700 text-sm font-medium flex items-center gap-2">
                            <i class="fa-solid fa-plus-circle"></i> Agregar otra línea
                        </button>
                    </div>

                    <!-- Totales -->
                    <div class="flex justify-end mb-8 border-t border-gray-100 pt-6">
                        <div class="w-72 space-y-3">
                            <div class="flex justify-between text-sm text-gray-600">
                                <span>Subtotal</span>
                                <span class="font-medium" id="lbl_subtotal">S/ 0.00</span>
                            </div>
                            <div class="flex justify-between text-sm text-gray-600">
                                <span>I.G.V. (18%)</span>
                                <span class="font-medium" id="lbl_igv">S/ 0.00</span>
                            </div>
                            <div class="flex justify-between text-lg font-bold text-gray-900 border-t border-gray-200 pt-3">
                                <span>Total Cotizado</span>
                                <span class="text-primary" id="lbl_total">S/ 0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones Finales -->
                    <div class="flex justify-between items-center border-t border-gray-100 pt-6">
                        <a href="proformas.php" class="px-5 py-2.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors">
                            Cancelar
                        </a>
                        <button type="submit" class="px-6 py-2.5 bg-primary text-white text-sm font-medium rounded-lg shadow-sm hover:bg-blue-600 transition-colors flex items-center gap-2">
                            <i class="fa-solid fa-file-signature"></i> Emitir Proforma
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Selección de cliente frecuente
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

    const tbody = document.getElementById('proforma_items_body');
    const btnAdd = document.getElementById('btn_add_row');
    const lblSubtotal = document.getElementById('lbl_subtotal');
    const lblIgv = document.getElementById('lbl_igv');
    const lblTotal = document.getElementById('lbl_total');

    function calcular() {
        let subtotal = 0;
        tbody.querySelectorAll('.item-row').forEach(row => {
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
    }

    btnAdd.addEventListener('click', function() {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-gray-100 item-row';
        tr.innerHTML = `
            <td class="px-4 py-3"><input type="number" step="0.01" min="1" name="qty[]" value="1" class="item-qty w-full border border-gray-300 rounded p-1.5 text-xs text-center" required></td>
            <td class="px-4 py-3"><input type="text" list="lista_productos" name="desc[]" placeholder="Descripción..." class="item-desc w-full border border-gray-300 rounded p-1.5 text-xs" required></td>
            <td class="px-4 py-3"><input type="number" step="0.01" min="0" name="price[]" value="0.00" class="item-price w-full border border-gray-300 rounded p-1.5 text-xs text-right" required></td>
            <td class="px-4 py-3 text-right font-medium text-gray-800 item-total whitespace-nowrap">S/ 0.00</td>
            <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none text-xs"></i></td>
        `;
        tbody.appendChild(tr);
    });

    tbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) {
            calcular();
        }
    });

    tbody.addEventListener('click', function(e) {
        if (e.target.classList.contains('btn-remove') || e.target.closest('.btn-remove')) {
            const row = e.target.closest('.item-row');
            if (tbody.querySelectorAll('.item-row').length > 1) {
                row.remove();
                calcular();
            } else {
                alert('Debe existir al menos un producto cotizado.');
            }
        }
    });

    calcular();
});
</script>

<?php include 'includes/footer.php'; ?>
