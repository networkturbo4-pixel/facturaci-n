<?php
require_once 'includes/auth.php';
requireLogin();

try {
    $stmt = $conn->query("SELECT * FROM comprobantes ORDER BY id DESC LIMIT 200");
    $comprobantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtPend = $conn->query("SELECT COUNT(*) FROM comprobantes WHERE estado = 'Pendiente SUNAT' OR sunat_cdr_estado = 'Pendiente'");
    $pendientesCount = (int)$stmtPend->fetchColumn();
} catch (PDOException $e) {
    $error_msg = "Error al obtener comprobantes: " . $e->getMessage();
    $comprobantes = [];
    $pendientesCount = 0;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Comprobantes de Pago';
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <?php if ($pendientesCount > 0): ?>
        <!-- Banner Plan de Contingencia SUNAT -->
        <div class="p-4 bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 text-amber-900 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-triangle-exclamation text-amber-600 text-lg"></i>
                </div>
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wide text-amber-800 flex items-center gap-1.5">
                        Plan de Contingencia Activo
                        <span class="bg-amber-200 text-amber-900 text-[10px] px-2 py-0.5 rounded-full font-bold"><?php echo $pendientesCount; ?> pendiente(s)</span>
                    </h4>
                    <p class="text-xs text-amber-700 mt-0.5">Los comprobantes están resguardados localmente debido a lentitud o desconexión temporal con los servidores de SUNAT.</p>
                </div>
            </div>
            <button onclick="sincronizarPendientes()" id="btn_sincronizar_sunat" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center gap-2 shrink-0">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span>Reintentar Envío a SUNAT</span>
            </button>
        </div>
        <?php endif; ?>

        <!-- Filter & Search Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="relative w-full sm:w-80">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input 
                    type="text" 
                    id="searchComprobantes" 
                    placeholder="Buscar por serie, cliente o DNI/RUC..." 
                    class="w-full bg-white border border-slate-200 rounded-xl py-2 pl-9 pr-3 text-xs text-slate-700 placeholder-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-xs transition-all"
                >
            </div>
            <div class="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl text-xs font-medium text-slate-600 self-start sm:self-auto">
                <button class="px-3 py-1.5 rounded-lg bg-white shadow-xs font-semibold text-slate-900">Todos (<?php echo count($comprobantes); ?>)</button>
                <button class="px-3 py-1.5 rounded-lg hover:text-slate-900 transition-colors">Facturas</button>
                <button class="px-3 py-1.5 rounded-lg hover:text-slate-900 transition-colors">Boletas</button>
            </div>
        </div>

        <!-- Comprobantes Table Card -->
        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <?php if (isset($error_msg)): ?>
                    <div class="p-4 bg-red-50 text-red-700 border-b border-red-100 text-xs flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><?php echo htmlspecialchars($error_msg); ?></span>
                    </div>
                <?php endif; ?>

                <table class="w-full text-left border-collapse text-xs" id="tableDocs">
                    <thead>
                        <tr class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-200/80 uppercase tracking-wider text-[11px]">
                            <th class="px-6 py-3.5">Comprobante</th>
                            <th class="px-6 py-3.5">Cliente / Razón Social</th>
                            <th class="px-6 py-3.5">Fecha</th>
                            <th class="px-6 py-3.5">Total</th>
                            <th class="px-6 py-3.5">Estado SUNAT</th>
                            <th class="px-6 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        <?php if (empty($comprobantes)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <div class="max-w-xs mx-auto text-slate-400">
                                        <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-xl mx-auto mb-3">
                                            <i class="fa-solid fa-file-invoice"></i>
                                        </div>
                                        <p class="font-bold text-slate-700 text-sm">No hay comprobantes emitidos</p>
                                        <p class="text-xs text-slate-400 mt-1">Empieza creando una factura o boleta de venta electrónica.</p>
                                        <a href="factura_nueva.php" class="mt-4 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold shadow-sm transition-all">
                                            <i class="fa-solid fa-plus text-xs"></i>
                                            <span>Nuevo Comprobante</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($comprobantes as $c): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?php echo ($c['tipo_comprobante'] == 'Factura') ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200'; ?>">
                                            <?php echo htmlspecialchars($c['tipo_comprobante']); ?>
                                        </span>
                                        <span class="font-semibold text-slate-900 font-mono"><?php echo htmlspecialchars($c['serie'] . '-' . str_pad($c['correlativo'], 6, '0', STR_PAD_LEFT)); ?></span>
                                        <?php if (!empty($c['es_exportacion'])): ?>
                                            <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-cyan-50 text-cyan-700 border border-cyan-200">EXP</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($c['cliente_razon_social']); ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono"><?php echo htmlspecialchars($c['cliente_documento']); ?></div>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-mono">
                                    <?php echo date('d/m/Y', strtotime($c['fecha_emision'])); ?>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-900">
                                    <?php echo ($c['moneda'] ?? 'PEN') === 'USD' ? '$' : 'S/'; ?> <?php echo number_format($c['total'], 2); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if (($c['estado'] ?? '') === 'Aceptado' || ($c['sunat_cdr_estado'] ?? '') === 'Aceptado'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            Aceptado
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200/80">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                            <?php echo htmlspecialchars($c['estado'] ?: 'Pendiente'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php $linkComp = !empty($c['token_publico']) ? 'comprobante_publico.php?token=' . urlencode($c['token_publico']) : 'comprobante_publico.php?id=' . $c['id']; ?>
                                        <a href="<?php echo $linkComp; ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors" title="Ver Comprobante">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </a>
                                        <a href="<?php echo $linkComp; ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Enlace Seguro Cliente">
                                            <i class="fa-solid fa-share-nodes text-xs"></i>
                                        </a>
                                        <a href="<?php echo $linkComp; ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Imprimir / PDF">
                                            <i class="fa-solid fa-file-pdf text-xs"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between text-xs text-slate-500">
                <span>Registros sincronizados con la base de datos</span>
                <span class="font-mono text-[11px]"><?php echo count($comprobantes); ?> comprobante(s)</span>
            </div>
        </div>

    </div>
</main>

<script>
// Filtro en tiempo real de comprobantes
document.getElementById('searchComprobantes')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#tableDocs tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
});

async function sincronizarPendientes() {
    const btn = document.getElementById('btn_sincronizar_sunat') || document.getElementById('btn_sincronizar_sunat_top');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Sincronizando con SUNAT...';
    }

    try {
        const r = await fetch('api/cron_reintentar_sunat.php');
        const data = await r.json();

        let msg = `Proceso completado:\n- Aceptados por SUNAT: ${data.aceptados}\n- Aún en contingencia: ${data.aun_pendientes}`;
        if (data.rechazados > 0) msg += `\n- Rechazados: ${data.rechazados}`;
        alert(msg);
        location.reload();
    } catch (e) {
        alert('Error al intentar sincronizar: ' + e.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}
</script>

<?php include 'includes/footer.php'; ?>
