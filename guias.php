<?php 
require_once 'includes/auth.php';
requireLogin();

$flash_guia = $_SESSION['flash_guia'] ?? '';
if ($flash_guia !== '') {
    unset($_SESSION['flash_guia']);
}

$guias = [];
try {
    $hasTable = (bool)$conn->query("SHOW TABLES LIKE 'guias_remision'")->fetch();
    if ($hasTable) {
        $stmt = $conn->query("SELECT * FROM guias_remision ORDER BY id DESC LIMIT 200");
        $guias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

include 'includes/header.php'; 
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 dark:bg-slate-950 overflow-hidden">
    <?php 
    $pageTitle = 'Guías de Remisión Remitente';
    $topbarAction = [
        'label' => 'Nueva Guía',
        'url'   => 'guia_nueva.php',
        'icon'  => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <?php if (!empty($flash_guia)): ?>
        <div data-auto-toast="true" class="p-4 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-800 dark:text-emerald-200 rounded-2xl text-xs font-bold flex items-center gap-3 shadow-sm">
            <i class="fa-solid fa-circle-check text-emerald-500 text-lg shrink-0"></i>
            <span><?php echo htmlspecialchars($flash_guia); ?></span>
        </div>
        <?php endif; ?>

        <?php if (empty($guias)): ?>
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-200/80 dark:border-slate-800 p-8 text-center mt-6">
            <div class="w-20 h-20 bg-orange-50 dark:bg-orange-500/15 text-orange-500 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                <i class="fa-solid fa-truck-fast"></i>
            </div>
            <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Guías de Remisión Electrónicas</h3>
            <p class="text-slate-500 dark:text-slate-400 mb-6 max-w-md mx-auto text-sm">Emite y gestiona tus guías de remisión electrónicas requeridas para el traslado de bienes con validación de SUNAT.</p>
            <a href="guia_nueva.php" class="px-6 py-3 bg-brand-600 text-white text-sm font-bold rounded-xl shadow-sm hover:bg-brand-500 transition-colors inline-flex items-center">
                <i class="fa-solid fa-plus mr-2"></i> Crear Guía de Remisión
            </a>
        </div>
        <?php else: ?>
        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50/80 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200/80 dark:border-slate-700 uppercase tracking-wider text-[11px]">
                            <th class="px-6 py-3.5">N° Guía</th>
                            <th class="px-6 py-3.5">Destinatario</th>
                            <th class="px-6 py-3.5">Motivo</th>
                            <th class="px-6 py-3.5">Partida / Llegada</th>
                            <th class="px-6 py-3.5">Fecha</th>
                            <th class="px-6 py-3.5">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium text-slate-700 dark:text-slate-300">
                        <?php foreach ($guias as $g): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4 font-mono font-bold text-slate-900 dark:text-white">
                                <?php echo htmlspecialchars($g['serie'] . '-' . str_pad((string)$g['correlativo'], 6, '0', STR_PAD_LEFT)); ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900 dark:text-white"><?php echo htmlspecialchars($g['destinatario_razon'] ?: 'Sin destinatario'); ?></div>
                                <div class="text-[11px] text-slate-400 font-mono"><?php echo htmlspecialchars($g['destinatario_doc'] ?: '—'); ?></div>
                            </td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($g['motivo_traslado']); ?></td>
                            <td class="px-6 py-4 max-w-xs">
                                <div class="truncate text-[11px]"><span class="text-blue-600 font-bold">De:</span> <?php echo htmlspecialchars($g['partida_direccion'] ?: '—'); ?></div>
                                <div class="truncate text-[11px]"><span class="text-emerald-600 font-bold">A:</span> <?php echo htmlspecialchars($g['llegada_direccion'] ?: '—'); ?></div>
                            </td>
                            <td class="px-6 py-4 font-mono text-slate-500"><?php echo date('d/m/Y', strtotime($g['fecha_emision'])); ?></td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30">
                                    <?php echo htmlspecialchars($g['estado'] ?? 'Emitida'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'includes/footer.php'; ?>