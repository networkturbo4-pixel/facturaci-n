<?php 
require_once 'includes/auth.php';
requireLogin();

try {
    $stmt = $conn->query("SELECT * FROM clientes ORDER BY id DESC LIMIT 200");
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $clientes = [];
    $error_msg = "Error al obtener clientes: " . $e->getMessage();
}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Directorio de Clientes';
    $topbarAction = [
        'label' => 'Nuevo Cliente',
        'url'   => 'cliente_nuevo.php',
        'icon'  => 'fa-solid fa-user-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <!-- Filter & Search Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="relative w-full sm:w-80">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input 
                    type="text" 
                    id="searchClientes" 
                    placeholder="Buscar por Razón Social, RUC o DNI..." 
                    class="w-full bg-white border border-slate-200 rounded-xl py-2 pl-9 pr-3 text-xs text-slate-700 placeholder-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-xs transition-all"
                >
            </div>
            <div class="text-xs text-slate-500 font-medium">
                Total: <span class="font-bold text-slate-900"><?php echo count($clientes); ?></span> clientes
            </div>
        </div>

        <!-- Clients Table Card -->
        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <?php if (isset($error_msg)): ?>
                    <div class="p-4 bg-red-50 text-red-700 border-b border-red-100 text-xs flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><?php echo htmlspecialchars($error_msg); ?></span>
                    </div>
                <?php endif; ?>

                <table class="w-full text-left border-collapse text-xs" id="tableClientes">
                    <thead>
                        <tr class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-200/80 uppercase tracking-wider text-[11px]">
                            <th class="px-6 py-3.5">Cliente / Razón Social</th>
                            <th class="px-6 py-3.5">Documento</th>
                            <th class="px-6 py-3.5">Contacto</th>
                            <th class="px-6 py-3.5">Condición</th>
                            <th class="px-6 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        <?php if (count($clientes) > 0): ?>
                            <?php foreach ($clientes as $cli): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 text-white flex items-center justify-center font-bold text-xs shadow-xs shrink-0">
                                            <?php echo strtoupper(substr($cli['razon_social'], 0, 1)); ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-slate-900 truncate max-w-xs"><?php echo htmlspecialchars($cli['razon_social']); ?></div>
                                            <div class="text-[11px] text-slate-400 truncate max-w-xs" title="<?php echo htmlspecialchars($cli['direccion_fiscal']); ?>">
                                                <?php echo htmlspecialchars($cli['direccion_fiscal'] ?: 'Sin dirección fiscal'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 font-mono"><?php echo htmlspecialchars($cli['numero_documento']); ?></div>
                                    <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($cli['tipo_documento']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-slate-800"><?php echo htmlspecialchars($cli['telefono'] ?: '-'); ?></div>
                                    <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($cli['correo_electronico'] ?: '-'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($cli['es_credito']): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Crédito
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Al Contado
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors" title="Editar">
                                            <i class="fa-solid fa-pen text-xs"></i>
                                        </button>
                                        <button class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors" title="Historial">
                                            <i class="fa-solid fa-file-invoice text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="max-w-xs mx-auto text-slate-400">
                                        <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-xl mx-auto mb-3">
                                            <i class="fa-solid fa-users"></i>
                                        </div>
                                        <p class="font-bold text-slate-700 text-sm">No hay clientes registrados</p>
                                        <p class="text-xs text-slate-400 mt-1">Registra tu primer cliente con RUC o DNI.</p>
                                        <a href="cliente_nuevo.php" class="mt-4 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-sm transition-all">
                                            <i class="fa-solid fa-plus text-xs"></i>
                                            <span>Registrar Cliente</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between text-xs text-slate-500">
                <span>Directorio sincronizado en base de datos</span>
                <span class="font-mono text-[11px]"><?php echo count($clientes); ?> registro(s)</span>
            </div>
        </div>

    </div>
</main>

<script>
// Filtro instantáneo de clientes
document.getElementById('searchClientes')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#tableClientes tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
