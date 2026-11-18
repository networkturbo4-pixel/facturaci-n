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
        try {
            $conn->exec("ALTER TABLE guias_remision ADD COLUMN IF NOT EXISTS token_publico VARCHAR(64) NULL AFTER id");
        } catch (Exception $eCol) {}

        $stmt = $conn->query("SELECT * FROM guias_remision ORDER BY id DESC LIMIT 200");
        $guias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Asegurar token_publico para guías antiguas sin token
        foreach ($guias as &$g) {
            if (empty($g['token_publico'])) {
                $tok = bin2hex(random_bytes(16));
                try {
                    $conn->prepare("UPDATE guias_remision SET token_publico = ? WHERE id = ?")->execute([$tok, (int)$g['id']]);
                    $g['token_publico'] = $tok;
                } catch (Exception $eTok) {}
            }
        }
        unset($g);
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

        <!-- Barra de Búsqueda y Filtros -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="relative w-full sm:w-80">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input
                    type="text"
                    id="searchGuias"
                    placeholder="Buscar por N° guía, destinatario, placa o ruta..."
                    class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl py-2 pl-9 pr-3 text-xs text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-xs transition-all"
                >
            </div>
            <div class="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl text-xs font-medium text-slate-600 dark:text-slate-300 self-start sm:self-auto" id="guia_filter_tabs">
                <button type="button" data-filter="todos" class="guia-tab-btn px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 shadow-xs font-semibold text-slate-900 dark:text-white transition-all">Todas (<?php echo count($guias); ?>)</button>
                <button type="button" data-filter="venta" class="guia-tab-btn px-3 py-1.5 rounded-lg hover:text-slate-900 dark:hover:text-white transition-colors">Venta</button>
                <button type="button" data-filter="traslado" class="guia-tab-btn px-3 py-1.5 rounded-lg hover:text-slate-900 dark:hover:text-white transition-colors">Traslado Interno</button>
                <button type="button" data-filter="compra" class="guia-tab-btn px-3 py-1.5 rounded-lg hover:text-slate-900 dark:hover:text-white transition-colors">Compra</button>
            </div>
        </div>

        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs" id="tableGuias">
                    <thead>
                        <tr class="bg-slate-50/80 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200/80 dark:border-slate-700 uppercase tracking-wider text-[11px]">
                            <th class="px-6 py-3.5">N° Guía</th>
                            <th class="px-6 py-3.5">Destinatario</th>
                            <th class="px-6 py-3.5">Motivo</th>
                            <th class="px-6 py-3.5">Partida / Llegada</th>
                            <th class="px-6 py-3.5">Fecha</th>
                            <th class="px-6 py-3.5">Estado</th>
                            <th class="px-6 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-medium text-slate-700 dark:text-slate-300">
                        <?php foreach ($guias as $g):
                            $numGuia = $g['serie'] . '-' . str_pad((string)$g['correlativo'], 6, '0', STR_PAD_LEFT);
                            $linkPub = !empty($g['token_publico']) ? ('guia_publica.php?token=' . urlencode($g['token_publico'])) : ('guia_publica.php?id=' . (int)$g['id']);
                            $linkPrint = $linkPub . '&print=1';
                            $motivoLower = mb_strtolower($g['motivo_traslado'] ?? '');
                            $motivoKey = str_contains($motivoLower, 'traslado') ? 'traslado' : (str_contains($motivoLower, 'compra') ? 'compra' : (str_contains($motivoLower, 'venta') ? 'venta' : 'otros'));
                            $guiaJson = htmlspecialchars(json_encode([
                                'id' => (int)$g['id'],
                                'numero' => $numGuia,
                                'cliente' => $g['destinatario_razon'] ?: 'Destinatario',
                                'documento' => $g['destinatario_doc'] ?: '—',
                                'url' => $linkPub
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="guia-row hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors" data-motivo="<?php echo $motivoKey; ?>">
                            <td class="px-6 py-4 font-mono font-bold text-slate-900 dark:text-white">
                                <?php echo htmlspecialchars($numGuia); ?>
                                <?php if (!empty($g['vehiculo_placa'])): ?>
                                    <div class="text-[10px] text-slate-400 font-normal">Placa: <?php echo htmlspecialchars($g['vehiculo_placa']); ?></div>
                                <?php endif; ?>
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
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="<?php echo htmlspecialchars($linkPub); ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-500/15 rounded-lg transition-colors inline-flex items-center justify-center" title="Ver vista pública">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                    </a>
                                    <button type="button" onclick='abrirCompartirGuia(<?php echo $guiaJson; ?>)' class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-500/15 rounded-lg transition-colors inline-flex items-center justify-center" title="Compartir guía">
                                        <i class="fa-solid fa-share-nodes text-xs"></i>
                                    </button>
                                    <a href="<?php echo htmlspecialchars($linkPrint); ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors inline-flex items-center justify-center" title="Imprimir / PDF">
                                        <i class="fa-solid fa-print text-xs"></i>
                                    </a>
                                    <a href="guia_nueva.php?editar=<?php echo (int)$g['id']; ?>" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-500/15 rounded-lg transition-colors inline-flex items-center justify-center" title="Editar guía">
                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    </a>
                                </div>
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

<!-- Modal Compartir Guía -->
<div id="modalCompartirGuia" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-share-nodes"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">Compartir Guía de Remisión</h3>
                    <p class="text-[11px] text-slate-400">Enlace público de visualización e impresión</p>
                </div>
            </div>
            <button type="button" onclick="cerrarCompartirGuia()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-center">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-6 space-y-4">
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/60 space-y-1.5">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md bg-orange-50 text-orange-700 border border-orange-200">Guía de Remisión</span>
                    <span id="share_guia_numero" class="font-mono font-bold text-xs text-slate-800 dark:text-slate-200">T001-000001</span>
                </div>
                <div id="share_guia_cliente" class="text-xs font-bold text-slate-900 dark:text-white truncate pt-1">Destinatario</div>
                <div id="share_guia_doc" class="text-[11px] text-slate-500 font-mono">—</div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Enlace de visualización</label>
                <div class="flex items-center gap-2">
                    <input type="text" id="share_guia_url" readonly class="w-full text-xs bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-slate-600 dark:text-slate-300 font-mono select-all focus:outline-none">
                    <button type="button" onclick="copiarEnlaceGuia()" class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl shrink-0 flex items-center gap-1.5">
                        <i class="fa-solid fa-copy"></i>
                        <span>Copiar</span>
                    </button>
                </div>
            </div>

            <div class="pt-1 flex flex-col gap-2">
                <a id="btn_whatsapp_guia" href="#" target="_blank" class="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-extrabold rounded-xl flex items-center justify-center gap-2">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Enviar por WhatsApp</span>
                </a>
                <a id="btn_ver_publico_guia" href="#" target="_blank" class="w-full py-2.5 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-bold rounded-xl flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-up-right-from-square text-[11px]"></i>
                    <span>Abrir en nueva pestaña</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let currentGuiaFilter = 'todos';

function aplicarFiltrosGuias() {
    const q = (document.getElementById('searchGuias')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#tableGuias tbody tr.guia-row').forEach(row => {
        const text = row.textContent.toLowerCase();
        const motivo = row.dataset.motivo || '';
        const matchTexto = !q || text.includes(q);
        const matchTab = currentGuiaFilter === 'todos' || motivo === currentGuiaFilter;
        row.style.display = (matchTexto && matchTab) ? '' : 'none';
    });
}

document.getElementById('searchGuias')?.addEventListener('input', aplicarFiltrosGuias);

document.querySelectorAll('#guia_filter_tabs .guia-tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        currentGuiaFilter = this.dataset.filter || 'todos';
        document.querySelectorAll('#guia_filter_tabs .guia-tab-btn').forEach(b => {
            b.classList.remove('bg-white', 'dark:bg-slate-900', 'shadow-xs', 'font-semibold', 'text-slate-900', 'dark:text-white');
        });
        this.classList.add('bg-white', 'dark:bg-slate-900', 'shadow-xs', 'font-semibold', 'text-slate-900', 'dark:text-white');
        aplicarFiltrosGuias();
    });
});

function abrirCompartirGuia(data) {
    const origin = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const fullUrl = origin + path + data.url;

    document.getElementById('share_guia_numero').textContent = data.numero;
    document.getElementById('share_guia_cliente').textContent = data.cliente;
    document.getElementById('share_guia_doc').textContent = data.documento;
    document.getElementById('share_guia_url').value = fullUrl;
    document.getElementById('btn_ver_publico_guia').href = fullUrl;

    const txtWsp = encodeURIComponent(`Estimado(a) *${data.cliente}*, le compartimos el enlace de su Guía de Remisión *${data.numero}*:\n${fullUrl}`);
    document.getElementById('btn_whatsapp_guia').href = `https://api.whatsapp.com/send?text=${txtWsp}`;

    if (navigator.clipboard) {
        navigator.clipboard.writeText(fullUrl).then(() => {
            if (window.showToast) window.showToast('Enlace de ' + data.numero + ' copiado.', 'success');
        }).catch(() => {});
    }
    document.getElementById('modalCompartirGuia').classList.remove('hidden');
}

function cerrarCompartirGuia() {
    document.getElementById('modalCompartirGuia').classList.add('hidden');
}

function copiarEnlaceGuia() {
    const inp = document.getElementById('share_guia_url');
    inp.select();
    navigator.clipboard.writeText(inp.value).then(() => {
        if (window.showToast) window.showToast('Enlace copiado al portapapeles.', 'success');
    }).catch(() => {});
}

document.getElementById('modalCompartirGuia')?.addEventListener('click', function(e) {
    if (e.target === this) cerrarCompartirGuia();
});
</script>

<?php include 'includes/footer.php'; ?>