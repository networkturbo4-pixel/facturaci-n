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
                                        <?php 
                                            $linkComp = !empty($c['token_publico']) 
                                                ? 'comprobante_publico.php?token=' . urlencode($c['token_publico']) 
                                                : 'comprobante_publico.php?id=' . $c['id'];
                                            $linkCompPrint = $linkComp . (str_contains($linkComp, '?') ? '&print=1' : '?print=1');
                                            $simboloComp = (($c['moneda'] ?? 'PEN') === 'USD') ? '$' : 'S/';
                                            $numeroDoc = $c['serie'] . '-' . str_pad((string)$c['correlativo'], 6, '0', STR_PAD_LEFT);
                                            $compJson = htmlspecialchars(json_encode([
                                                'id'        => $c['id'],
                                                'numero'    => $numeroDoc,
                                                'tipo'      => $c['tipo_comprobante'],
                                                'cliente'   => $c['cliente_razon_social'],
                                                'documento' => $c['cliente_documento'],
                                                'total'     => number_format((float)$c['total'], 2),
                                                'moneda'    => $simboloComp,
                                                'url'       => $linkComp
                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                        ?>

                                        <!-- Ver Vista Pública -->
                                        <a href="<?php echo $linkComp; ?>" 
                                           target="_blank" 
                                           class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors inline-flex items-center justify-center" 
                                           title="Ver vista pública">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </a>

                                        <!-- Compartir Vista Pública -->
                                        <button type="button" 
                                                onclick='abrirCompartirComprobante(<?php echo $compJson; ?>)' 
                                                class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors inline-flex items-center justify-center" 
                                                title="Compartir vista pública">
                                            <i class="fa-solid fa-share-nodes text-xs"></i>
                                        </button>

                                        <!-- Imprimir / PDF -->
                                        <a href="<?php echo $linkCompPrint; ?>" 
                                           target="_blank" 
                                           class="p-1.5 text-slate-400 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors inline-flex items-center justify-center" 
                                           title="Imprimir / PDF">
                                            <i class="fa-solid fa-print text-xs"></i>
                                        </a>

                                        <!-- Descargar XML (si existe) -->
                                        <?php if (!empty($c['sunat_enlace_xml'])): ?>
                                        <a href="<?php echo htmlspecialchars($c['sunat_enlace_xml']); ?>" 
                                           target="_blank" 
                                           download 
                                           class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors inline-flex items-center justify-center" 
                                           title="Descargar XML">
                                            <i class="fa-solid fa-code text-xs"></i>
                                        </a>
                                        <?php endif; ?>

                                        <!-- Descargar CDR (si existe) -->
                                        <?php if (!empty($c['sunat_enlace_cdr'])): ?>
                                        <a href="<?php echo htmlspecialchars($c['sunat_enlace_cdr']); ?>" 
                                           target="_blank" 
                                           download 
                                           class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors inline-flex items-center justify-center" 
                                           title="Descargar CDR">
                                            <i class="fa-solid fa-file-zipper text-xs"></i>
                                        </a>
                                        <?php endif; ?>
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

<!-- Modal: Compartir Comprobante de Pago -->
<div id="modalCompartirComprobante" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200 dark:border-slate-800 animate-fade-in">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/70 dark:bg-slate-800/50">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-share-nodes"></i>
                </div>
                <div>
                    <h3 class="font-extrabold text-slate-900 dark:text-white text-sm">Compartir Comprobante</h3>
                    <p class="text-[11px] text-slate-400">Enlace seguro de visualización electrónica</p>
                </div>
            </div>
            <button type="button" onclick="cerrarCompartirComprobante()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-6 space-y-4">
            <!-- Tarjeta Resumen del Comprobante -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/60 space-y-2">
                <div class="flex items-center justify-between">
                    <span id="share_comp_tipo" class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 border border-brand-200">Factura Electrónica</span>
                    <span id="share_comp_numero" class="font-mono font-bold text-xs text-slate-800 dark:text-slate-200">F001-000001</span>
                </div>
                <div class="text-xs">
                    <div class="text-slate-400 text-[11px]">Cliente / Razón Social:</div>
                    <div id="share_comp_cliente" class="font-bold text-slate-900 dark:text-white truncate">Cliente SAC</div>
                    <div id="share_comp_doc" class="text-[11px] text-slate-500 font-mono">20000000001</div>
                </div>
                <div class="pt-2 border-t border-slate-200/60 dark:border-slate-700/60 flex items-center justify-between">
                    <span class="text-xs text-slate-500">Total Comprobante:</span>
                    <span id="share_comp_total" class="font-mono font-extrabold text-sm text-slate-900 dark:text-white">S/ 0.00</span>
                </div>
            </div>

            <!-- Input con el Enlace y Botón Copiar -->
            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Enlace de visualización e impresión</label>
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <input type="text" id="share_comp_url" readonly class="w-full text-xs bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-slate-600 dark:text-slate-300 font-mono select-all focus:outline-none" value="">
                    </div>
                    <button type="button" onclick="copiarEnlaceModalComprobante()" id="btn_copiar_comp_modal" class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 shrink-0 shadow-sm">
                        <i class="fa-solid fa-copy"></i>
                        <span id="btn_copiar_comp_modal_txt">Copiar</span>
                    </button>
                </div>
                <p class="text-[10px] text-slate-400 mt-1">Cualquier persona con este enlace podrá ver e imprimir el comprobante con su QR oficial SUNAT.</p>
            </div>

            <!-- Botones de Acción Rápida -->
            <div class="pt-2 flex flex-col gap-2">
                <a id="btn_whatsapp_comp" href="#" target="_blank" class="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-extrabold rounded-xl transition-all flex items-center justify-center gap-2 shadow-sm shadow-emerald-500/20">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Enviar por WhatsApp</span>
                </a>
                <a id="btn_ver_publico_comp" href="#" target="_blank" class="w-full py-2.5 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-bold rounded-xl transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-up-right-from-square text-[11px]"></i>
                    <span>Abrir en nueva pestaña</span>
                </a>
            </div>
        </div>
    </div>
</div>

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

function abrirCompartirComprobante(data) {
    const origin = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const fullUrl = origin + path + data.url;

    document.getElementById('share_comp_tipo').textContent = data.tipo || 'Comprobante Electrónico';
    document.getElementById('share_comp_numero').textContent = data.numero;
    document.getElementById('share_comp_cliente').textContent = data.cliente;
    document.getElementById('share_comp_doc').textContent = data.documento;
    document.getElementById('share_comp_total').textContent = data.moneda + ' ' + data.total;
    document.getElementById('share_comp_url').value = fullUrl;
    document.getElementById('btn_ver_publico_comp').href = fullUrl;

    const textoWsp = encodeURIComponent(`Estimado(a) *${data.cliente}*, le compartimos el enlace oficial para consultar su comprobante electrónico *${data.tipo} ${data.numero}* por un total de *${data.moneda} ${data.total}*:\n${fullUrl}\n\nGracias por su preferencia.`);
    document.getElementById('btn_whatsapp_comp').href = `https://api.whatsapp.com/send?text=${textoWsp}`;

    const btn = document.getElementById('btn_copiar_comp_modal');
    const txt = document.getElementById('btn_copiar_comp_modal_txt');
    txt.textContent = 'Copiar';
    btn.classList.remove('bg-emerald-600');

    // Auto-copiar al portapapeles al pulsar el botón de compartir
    if (navigator.clipboard) {
        navigator.clipboard.writeText(fullUrl).then(() => {
            if (window.showToast) {
                window.showToast('Enlace de ' + data.numero + ' copiado al portapapeles.', 'success', 'Enlace Copiado');
            }
        }).catch(() => {});
    }

    document.getElementById('modalCompartirComprobante').classList.remove('hidden');
}

function cerrarCompartirComprobante() {
    document.getElementById('modalCompartirComprobante').classList.add('hidden');
}

function copiarEnlaceModalComprobante() {
    const inp = document.getElementById('share_comp_url');
    inp.select();
    inp.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(inp.value).then(() => {
        const txt = document.getElementById('btn_copiar_comp_modal_txt');
        const btn = document.getElementById('btn_copiar_comp_modal');
        txt.textContent = '¡Copiado!';
        btn.classList.add('bg-emerald-600');
        if (window.showToast) {
            window.showToast('Enlace copiado al portapapeles.', 'success');
        }
        setTimeout(() => {
            txt.textContent = 'Copiar';
            btn.classList.remove('bg-emerald-600');
        }, 2000);
    }).catch(() => {});
}

// Cerrar modal al hacer clic en el backdrop
document.getElementById('modalCompartirComprobante')?.addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarCompartirComprobante();
    }
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
