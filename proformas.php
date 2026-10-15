<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';

// Procesar cambio de estado o eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido.";
    } elseif (isset($_POST['cambiar_estado'])) {
        $profId = (int)($_POST['proforma_id'] ?? 0);
        $nuevoEstado = trim($_POST['nuevo_estado'] ?? 'Pendiente');
        try {
            $upd = $conn->prepare("UPDATE proformas SET estado = ? WHERE id = ?");
            $upd->execute([$nuevoEstado, $profId]);
            $success_msg = "Estado de proforma actualizado a $nuevoEstado.";
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['eliminar_proforma'])) {
        $profId = (int)($_POST['proforma_id'] ?? 0);
        try {
            $del = $conn->prepare("DELETE FROM proformas WHERE id = ?");
            $del->execute([$profId]);
            $success_msg = "Proforma eliminada.";
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

// Cargar proformas de la BD
try {
    $stmt = $conn->query("SELECT * FROM proformas ORDER BY id DESC");
    $proformas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalProf = count($proformas);
    $aprobadas = 0;
    $montoSeguimiento = 0;
    foreach ($proformas as $pr) {
        if ($pr['estado'] === 'Aceptada' || $pr['estado'] === 'Convertida') {
            $aprobadas++;
        } elseif ($pr['estado'] === 'Pendiente') {
            $montoSeguimiento += (float)$pr['total'];
        }
    }
} catch (Exception $e) {
    $proformas = [];
    $totalProf = 0;
    $aprobadas = 0;
    $montoSeguimiento = 0;
}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Proformas y Cotizaciones';
    $topbarAction = [
        'label' => 'Nueva Proforma',
        'url'   => 'proforma_nueva.php',
        'icon'  => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <?php if ($success_msg): ?>
            <div class="p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-500"></i>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="p-3 bg-red-50 border border-red-200 text-red-800 rounded-xl text-xs flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation text-red-500"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats Reales (2 Columnas en Móvil, 3 en Desktop) -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <div class="app-card p-3.5 sm:p-6">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1 leading-tight">Proformas Emitidas</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-800"><?php echo $totalProf; ?></p>
            </div>
            <div class="app-card p-3.5 sm:p-6">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1 leading-tight">Aprobadas</p>
                <p class="text-lg sm:text-2xl font-bold text-green-600">
                    <?php echo $aprobadas; ?> 
                    <span class="text-xs sm:text-sm font-normal text-gray-400">
                        (<?php echo $totalProf > 0 ? round(($aprobadas / $totalProf) * 100) : 0; ?>%)
                    </span>
                </p>
            </div>
            <div class="col-span-2 md:col-span-1 app-card p-3.5 sm:p-6">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1 leading-tight">Monto en Seguimiento</p>
                <p class="text-lg sm:text-2xl font-bold text-amber-500">S/ <?php echo number_format($montoSeguimiento, 2); ?></p>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <div class="relative w-64">
                    <input type="text" id="searchProformas" class="bg-white border border-gray-300 text-gray-900 text-xs rounded-xl focus:border-brand-500 block w-full p-2.5" placeholder="Buscar por número o cliente...">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs" id="tableProformas">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100 text-gray-500 text-xs uppercase tracking-wider">
                            <th class="px-6 py-4 font-semibold">N° Proforma</th>
                            <th class="px-6 py-4 font-semibold">Cliente</th>
                            <th class="px-6 py-4 font-semibold">Fecha Emisión</th>
                            <th class="px-6 py-4 font-semibold">Validez</th>
                            <th class="px-6 py-4 font-semibold">Total</th>
                            <th class="px-6 py-4 font-semibold">Estado</th>
                            <th class="px-6 py-4 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="text-xs divide-y divide-gray-100 text-slate-700">
                        <?php if (empty($proformas)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                                    No hay proformas emitidas aún. Haz clic en "Nueva Proforma" para elaborar tu primera cotización.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($proformas as $p): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 font-bold text-slate-900 font-mono">
                                    <?php echo htmlspecialchars($p['numero']); ?>
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-900">
                                    <div><?php echo htmlspecialchars($p['cliente_razon_social']); ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono"><?php echo htmlspecialchars($p['cliente_documento']); ?></div>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-mono">
                                    <?php echo date('d/m/Y', strtotime($p['fecha_emision'])); ?>
                                </td>
                                <td class="px-6 py-4 text-slate-500">
                                    <?php echo htmlspecialchars($p['validez_dias']); ?>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-900 font-mono">
                                    <?php echo ($p['moneda'] === 'USD') ? '$' : 'S/'; ?> <?php echo number_format($p['total'], 2); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php 
                                        $badgeClass = match($p['estado']) {
                                            'Aceptada', 'Convertida' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            'Rechazada'              => 'bg-rose-50 text-rose-700 border-rose-200',
                                            default                  => 'bg-amber-50 text-amber-700 border-amber-200'
                                        };
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold border <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($p['estado']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Cambiar Estado Rápido -->
                                        <form method="POST" action="" class="inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cambiar_estado" value="1">
                                            <input type="hidden" name="proforma_id" value="<?php echo $p['id']; ?>">
                                            <select name="nuevo_estado" onchange="this.form.submit()" class="text-[10px] bg-slate-50 border border-slate-200 rounded-lg p-1 text-slate-600 focus:outline-none">
                                                <option value="Pendiente" <?php echo $p['estado'] === 'Pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                                <option value="Aceptada" <?php echo $p['estado'] === 'Aceptada' ? 'selected' : ''; ?>>Aceptada</option>
                                                <option value="Convertida" <?php echo $p['estado'] === 'Convertida' ? 'selected' : ''; ?>>Convertida</option>
                                                <option value="Rechazada" <?php echo $p['estado'] === 'Rechazada' ? 'selected' : ''; ?>>Rechazada</option>
                                            </select>
                                        </form>

                                        <!-- Eliminar -->
                                        <form method="POST" action="" class="inline" onsubmit="return confirm('¿Eliminar esta proforma?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="eliminar_proforma" value="1">
                                            <input type="hidden" name="proforma_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Eliminar">
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

<script>
document.getElementById('searchProformas')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#tableProformas tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
