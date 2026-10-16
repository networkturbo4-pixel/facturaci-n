<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';

// Procesar creación / edición / eliminación de productos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada.";
    } elseif (isset($_POST['crear_producto'])) {
        $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
        $descripcion  = trim($_POST['descripcion'] ?? '');
        $unidad       = trim($_POST['unidad_medida'] ?? 'NIU');
        $precio       = (float)($_POST['precio_unitario'] ?? 0);
        $partida      = trim($_POST['codigo_partida'] ?? '');
        $stock        = (float)($_POST['stock'] ?? 0);
        $token        = bin2hex(random_bytes(16));

        if (empty($codigo) || empty($descripcion)) {
            $error_msg = "El código y la descripción del producto son obligatorios.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO productos (token_publico, codigo, descripcion, unidad_medida, precio_unitario, codigo_partida, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$token, $codigo, $descripcion, $unidad, $precio, $partida ?: null, $stock]);
                $success_msg = "Producto '$codigo' registrado correctamente.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_msg = "El código de producto '$codigo' ya está registrado.";
                } else {
                    $error_msg = "Error al guardar producto: " . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['editar_producto'])) {
        $prodId       = (int)($_POST['producto_id'] ?? 0);
        $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
        $descripcion  = trim($_POST['descripcion'] ?? '');
        $unidad       = trim($_POST['unidad_medida'] ?? 'NIU');
        $precio       = (float)($_POST['precio_unitario'] ?? 0);
        $partida      = trim($_POST['codigo_partida'] ?? '');
        $stock        = (float)($_POST['stock'] ?? 0);

        if (empty($codigo) || empty($descripcion) || $prodId <= 0) {
            $error_msg = "El código y la descripción del producto son obligatorios.";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE productos SET codigo = ?, descripcion = ?, unidad_medida = ?, precio_unitario = ?, codigo_partida = ?, stock = ? WHERE id = ?");
                $stmt->execute([$codigo, $descripcion, $unidad, $precio, $partida ?: null, $stock, $prodId]);
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
        $prodId = (int)($_POST['producto_id'] ?? 0);
        try {
            $del = $conn->prepare("DELETE FROM productos WHERE id = ?");
            $del->execute([$prodId]);
            $success_msg = "Producto eliminado del catálogo.";
        } catch (Exception $e) {
            $error_msg = "No se pudo eliminar el producto: " . $e->getMessage();
        }
    }
}

// Obtener productos
try {
    $stmt = $conn->query("SELECT * FROM productos ORDER BY id DESC");
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalItems = count($productos);
    $valorInventario = 0;
    $conPartida = 0;
    foreach ($productos as $p) {
        $valorInventario += ((float)$p['stock'] * (float)$p['precio_unitario']);
        if (!empty($p['codigo_partida'])) $conPartida++;
    }
} catch (Exception $e) {
    $productos = [];
    $totalItems = 0;
    $valorInventario = 0;
    $conPartida = 0;
}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Gestión de Ventas';
    $topbarAction = [
        'label'   => 'Nuevo Producto / Servicio',
        'onclick' => "document.getElementById('modalNuevoProducto').classList.remove('hidden')",
        'icon'    => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <?php if ($success_msg): ?>
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-500"></i>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-xs flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation text-red-500"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- KPI Cards (2 Columns on Mobile, 3 on Desktop) -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
            <div class="app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Total Ítems</span>
                    <span class="p-1.5 sm:p-2 bg-blue-50 text-blue-600 rounded-lg text-xs shrink-0"><i class="fa-solid fa-box"></i></span>
                </div>
                <p class="text-lg sm:text-2xl font-bold text-slate-900"><?php echo $totalItems; ?></p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">Productos y servicios</p>
            </div>

            <div class="app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Valor Stock</span>
                    <span class="p-1.5 sm:p-2 bg-emerald-50 text-emerald-600 rounded-lg text-xs shrink-0"><i class="fa-solid fa-coins"></i></span>
                </div>
                <p class="text-lg sm:text-2xl font-bold text-emerald-600 truncate">S/ <?php echo number_format($valorInventario, 2); ?></p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">Valorización en almacén</p>
            </div>

            <div class="col-span-2 sm:col-span-1 app-card p-3.5 sm:p-5">
                <div class="flex items-center justify-between gap-2 mb-1.5 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 uppercase leading-tight">Partidas Exportación</span>
                    <span class="p-1.5 sm:p-2 bg-purple-50 text-purple-600 rounded-lg text-xs shrink-0"><i class="fa-solid fa-ship"></i></span>
                </div>
                <p class="text-lg sm:text-2xl font-bold text-purple-600"><?php echo $conPartida; ?></p>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1 truncate">Con subpartida nacional SA</p>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="relative w-full sm:w-80">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input 
                    type="text" 
                    id="searchProductos" 
                    placeholder="Buscar por código, descripción o partida..." 
                    class="w-full bg-white border border-slate-200 rounded-xl py-2 pl-9 pr-3 text-xs text-slate-700 placeholder-slate-400 focus:outline-none focus:border-brand-500 shadow-xs"
                >
            </div>
        </div>

        <!-- Products Table Card -->
        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs" id="tableProductos">
                    <thead>
                        <tr class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-200/80 uppercase tracking-wider text-[11px]">
                            <th class="px-6 py-3.5">Código</th>
                            <th class="px-6 py-3.5">Descripción del Bien / Servicio</th>
                            <th class="px-6 py-3.5">Unidad</th>
                            <th class="px-6 py-3.5">Precio Unitario</th>
                            <th class="px-6 py-3.5">Stock</th>
                            <th class="px-6 py-3.5">Partida Arancelaria</th>
                            <th class="px-6 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        <?php if (empty($productos)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                                    No hay productos registrados en el catálogo.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($productos as $p): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4 font-mono font-bold text-slate-900">
                                    <?php echo htmlspecialchars($p['codigo']); ?>
                                </td>
                                <td class="px-6 py-4 font-semibold text-slate-800">
                                    <?php echo htmlspecialchars($p['descripcion']); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                        <?php echo htmlspecialchars($p['unidad_medida']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-900">
                                    S/ <?php echo number_format($p['precio_unitario'], 2); ?>
                                </td>
                                <td class="px-6 py-4 font-mono">
                                    <span class="<?php echo ($p['stock'] > 10) ? 'text-emerald-700 font-bold' : 'text-amber-700 font-bold'; ?>">
                                        <?php echo number_format($p['stock'], 2); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-mono text-[11px] text-slate-600">
                                    <?php echo htmlspecialchars($p['codigo_partida'] ?: '-'); ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Ver Vista Pública -->
                                        <?php $tokenProd = !empty($p['token_publico']) ? $p['token_publico'] : ''; ?>
                                        <a href="producto_publico.php?token=<?php echo urlencode($tokenProd); ?>&id=<?php echo $p['id']; ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors" title="Ver Vista Pública">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </a>

                                        <!-- Compartir Producto -->
                                        <button type="button" onclick='abrirCompartir(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Compartir Producto">
                                            <i class="fa-solid fa-share-nodes text-xs"></i>
                                        </button>

                                        <!-- Editar Producto -->
                                        <button type="button" onclick='abrirEditar(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Editar Producto">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i>
                                        </button>

                                        <!-- Eliminar Producto -->
                                        <form method="POST" action="" class="inline" onsubmit="return confirm('¿Seguro de eliminar este producto del catálogo?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="eliminar_producto" value="1">
                                            <input type="hidden" name="producto_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar Producto">
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
</main>

<!-- Modal: Nuevo Producto -->
<div id="modalNuevoProducto" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 text-sm">Registrar Nuevo Producto o Servicio</h3>
            <button type="button" onclick="document.getElementById('modalNuevoProducto').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="crear_producto" value="1">
            
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Código del Ítem</label>
                    <input type="text" name="codigo" placeholder="Ej. PRD-005" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 uppercase focus:outline-none focus:border-brand-500 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Unidad de Medida</label>
                    <select name="unidad_medida" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="NIU">NIU - Unidades</option>
                        <option value="KGM">KGM - Kilogramos</option>
                        <option value="TNE">TNE - Toneladas</option>
                        <option value="LTR">LTR - Litros</option>
                        <option value="ZZ">ZZ - Servicios / Mutuo Acuerdo</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Descripción Comercial</label>
                <input type="text" name="descripcion" placeholder="Nombre completo del producto o servicio" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Precio Unitario (S/ o USD)</label>
                    <input type="number" step="0.01" min="0" name="precio_unitario" placeholder="0.00" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Stock Inicial</label>
                    <input type="number" step="0.01" min="0" name="stock" value="0" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Partida Arancelaria (Opcional - Exportación)</label>
                <input type="text" name="codigo_partida" placeholder="Ej. 0901.21.00.00" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 font-mono focus:outline-none focus:border-brand-500">
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalNuevoProducto').classList.add('hidden')" class="px-4 py-2 border border-slate-300 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold rounded-xl shadow-sm">
                    Guardar Producto
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Producto -->
<div id="modalEditarProducto" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-indigo-600"></i>
                <span>Editar Producto o Servicio</span>
            </h3>
            <button type="button" onclick="document.getElementById('modalEditarProducto').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="editar_producto" value="1">
            <input type="hidden" name="producto_id" id="edit_producto_id" value="">
            
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Código del Ítem</label>
                    <input type="text" name="codigo" id="edit_codigo" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 uppercase focus:outline-none focus:border-brand-500 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Unidad de Medida</label>
                    <select name="unidad_medida" id="edit_unidad_medida" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="NIU">NIU - Unidades</option>
                        <option value="KGM">KGM - Kilogramos</option>
                        <option value="TNE">TNE - Toneladas</option>
                        <option value="LTR">LTR - Litros</option>
                        <option value="ZZ">ZZ - Servicios / Mutuo Acuerdo</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Descripción Comercial</label>
                <input type="text" name="descripcion" id="edit_descripcion" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Precio Unitario (S/)</label>
                    <input type="number" step="0.01" min="0" name="precio_unitario" id="edit_precio_unitario" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Stock Actual</label>
                    <input type="number" step="0.01" min="0" name="stock" id="edit_stock" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Partida Arancelaria (Opcional - Exportación)</label>
                <input type="text" name="codigo_partida" id="edit_codigo_partida" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 font-mono focus:outline-none focus:border-brand-500">
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalEditarProducto').classList.add('hidden')" class="px-4 py-2 border border-slate-300 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-xl shadow-sm">
                    <i class="fa-solid fa-save mr-1.5"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Compartir Producto -->
<div id="modalCompartirProducto" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <i class="fa-solid fa-share-nodes text-emerald-600"></i>
                <span>Compartir Ficha de Producto</span>
            </h3>
            <button type="button" onclick="document.getElementById('modalCompartirProducto').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200/80">
                <span class="text-[10px] font-mono font-bold text-slate-400 uppercase" id="share_codigo">PRD-001</span>
                <h4 class="text-sm font-bold text-slate-900 mt-0.5" id="share_descripcion">Nombre del Producto</h4>
                <p class="text-xs font-bold text-emerald-600 mt-1" id="share_precio">S/ 0.00</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Enlace Público Directo</label>
                <div class="flex gap-2">
                    <input type="text" id="share_url_input" readonly class="flex-1 bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs text-slate-700 font-mono select-all focus:outline-none">
                    <button type="button" onclick="copiarEnlacePublico()" id="btn_copiar_link" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-semibold rounded-xl transition-all flex items-center gap-1.5 shrink-0">
                        <i class="fa-solid fa-copy"></i>
                        <span id="btn_copiar_texto">Copiar</span>
                    </button>
                </div>
            </div>

            <div class="pt-2 flex flex-col gap-2">
                <a id="btn_whatsapp_share" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl transition-all flex items-center justify-center gap-2 shadow-sm">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Compartir por WhatsApp</span>
                </a>
                <a id="btn_ver_publico" href="#" target="_blank" class="w-full py-2.5 border border-slate-300 text-slate-700 hover:bg-slate-50 text-xs font-semibold rounded-xl transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>Abrir Ficha Pública</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Búsqueda en tiempo real
document.getElementById('searchProductos')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#tableProductos tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

function abrirEditar(prod) {
    document.getElementById('edit_producto_id').value = prod.id;
    document.getElementById('edit_codigo').value = prod.codigo;
    document.getElementById('edit_descripcion').value = prod.descripcion;
    document.getElementById('edit_unidad_medida').value = prod.unidad_medida;
    document.getElementById('edit_precio_unitario').value = prod.precio_unitario;
    document.getElementById('edit_stock').value = prod.stock;
    document.getElementById('edit_codigo_partida').value = prod.codigo_partida || '';
    document.getElementById('modalEditarProducto').classList.remove('hidden');
}

function abrirCompartir(prod) {
    const token = prod.token_publico || '';
    const origin = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const url = origin + path + 'producto_publico.php?token=' + encodeURIComponent(token) + '&id=' + prod.id;
    
    document.getElementById('share_codigo').textContent = prod.codigo;
    document.getElementById('share_descripcion').textContent = prod.descripcion;
    document.getElementById('share_precio').textContent = 'S/ ' + parseFloat(prod.precio_unitario).toFixed(2);
    document.getElementById('share_url_input').value = url;
    document.getElementById('btn_ver_publico').href = url;

    const textoWsp = encodeURIComponent(`Hola, te comparto la ficha comercial de nuestro producto *${prod.descripcion}* (${prod.codigo}) con precio S/ ${parseFloat(prod.precio_unitario).toFixed(2)}:\n${url}`);
    document.getElementById('btn_whatsapp_share').href = `https://api.whatsapp.com/send?text=${textoWsp}`;

    const btn = document.getElementById('btn_copiar_link');
    const txt = document.getElementById('btn_copiar_texto');
    txt.textContent = 'Copiar';
    btn.classList.remove('bg-emerald-600');
    btn.classList.add('bg-slate-800');

    document.getElementById('modalCompartirProducto').classList.remove('hidden');
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
        setTimeout(() => {
            txt.textContent = 'Copiar';
            btn.classList.remove('bg-emerald-600');
            btn.classList.add('bg-slate-800');
        }, 2000);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
