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
                                        <?php 
                                            $linkPublico = !empty($p['token_publico']) 
                                                ? 'proforma_publica.php?token=' . urlencode($p['token_publico']) 
                                                : 'proforma_publica.php?id=' . $p['id'];
                                            $monedaSim = ($p['moneda'] === 'USD') ? '$' : 'S/';
                                            $proformaJson = htmlspecialchars(json_encode([
                                                'id' => $p['id'],
                                                'numero' => $p['numero'],
                                                'cliente' => $p['cliente_razon_social'],
                                                'documento' => $p['cliente_documento'],
                                                'total' => number_format((float)$p['total'], 2),
                                                'moneda' => $monedaSim,
                                                'url' => $linkPublico
                                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                        ?>

                                        <!-- Ver Vista Pública -->
                                        <a href="<?php echo $linkPublico; ?>" 
                                           target="_blank" 
                                           class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors inline-flex items-center justify-center" 
                                           title="Ver vista pública">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </a>

                                        <!-- Compartir Vista Pública -->
                                        <button type="button" 
                                                onclick='abrirCompartirProforma(<?php echo $proformaJson; ?>)' 
                                                class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors inline-flex items-center justify-center" 
                                                title="Compartir vista pública">
                                            <i class="fa-solid fa-share-nodes text-xs"></i>
                                        </button>

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

<!-- Modal: Compartir Proforma / Cotización -->
<div id="modalCompartirProforma" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200 dark:border-slate-800 animate-fade-in">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/70 dark:bg-slate-800/50">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-share-nodes"></i>
                </div>
                <div>
                    <h3 class="font-extrabold text-slate-900 dark:text-white text-sm">Compartir Cotización</h3>
                    <p class="text-[11px] text-slate-400">Enlace seguro de visualización pública</p>
                </div>
            </div>
            <button type="button" onclick="cerrarCompartirProforma()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-6 space-y-4">
            <!-- Tarjeta resumen de la cotización -->
            <div class="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-700/80">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] font-mono font-bold text-brand-600 dark:text-brand-400 bg-brand-50 dark:bg-brand-500/10 px-2 py-0.5 rounded-md" id="share_cot_numero">COT-00000</span>
                    <span class="text-sm font-mono font-extrabold text-slate-900 dark:text-white" id="share_cot_total">S/ 0.00</span>
                </div>
                <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 mt-2 truncate" id="share_cot_cliente">Nombre del Cliente</h4>
                <p class="text-[11px] text-slate-400 font-mono" id="share_cot_doc">00000000</p>
            </div>

            <!-- Campo de Enlace Público con botón Copiar -->
            <div>
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Enlace Público para el Cliente</label>
                <div class="flex gap-2">
                    <input type="text" id="share_cot_url" readonly class="flex-1 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs text-slate-700 dark:text-slate-200 font-mono select-all focus:outline-none">
                    <button type="button" onclick="copiarEnlaceModal()" id="btn_copiar_modal" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 dark:bg-brand-600 dark:hover:bg-brand-500 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 shrink-0 shadow-sm">
                        <i class="fa-solid fa-copy"></i>
                        <span id="btn_copiar_modal_txt">Copiar</span>
                    </button>
                </div>
                <p class="text-[10px] text-slate-400 mt-1">Cualquier persona con este enlace podrá ver la cotización oficial sin iniciar sesión.</p>
            </div>

            <!-- Botones de Acción Rápida -->
            <div class="pt-2 flex flex-col gap-2">
                <a id="btn_whatsapp_cot" href="#" target="_blank" class="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-extrabold rounded-xl transition-all flex items-center justify-center gap-2 shadow-sm shadow-emerald-500/20">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Enviar por WhatsApp</span>
                </a>
                <a id="btn_ver_publico_cot" href="#" target="_blank" class="w-full py-2.5 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 text-xs font-bold rounded-xl transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-up-right-from-square text-[11px]"></i>
                    <span>Abrir en nueva pestaña</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('searchProformas')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#tableProformas tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

function abrirCompartirProforma(data) {
    const origin = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    const fullUrl = origin + path + data.url;

    document.getElementById('share_cot_numero').textContent = data.numero;
    document.getElementById('share_cot_cliente').textContent = data.cliente;
    document.getElementById('share_cot_doc').textContent = data.documento;
    document.getElementById('share_cot_total').textContent = data.moneda + ' ' + data.total;
    document.getElementById('share_cot_url').value = fullUrl;
    document.getElementById('btn_ver_publico_cot').href = fullUrl;

    const textoWsp = encodeURIComponent(`Hola *${data.cliente}*, le compartimos la cotización oficial *${data.numero}* por un total de *${data.moneda} ${data.total}*:\n${fullUrl}\n\nQuedamos atentos a cualquier consulta.`);
    document.getElementById('btn_whatsapp_cot').href = `https://api.whatsapp.com/send?text=${textoWsp}`;

    const btn = document.getElementById('btn_copiar_modal');
    const txt = document.getElementById('btn_copiar_modal_txt');
    txt.textContent = 'Copiar';
    btn.classList.remove('bg-emerald-600');

    // Auto-copiar al portapapeles al pulsar el botón de compartir
    if (navigator.clipboard) {
        navigator.clipboard.writeText(fullUrl).then(() => {
            if (window.showToast) {
                window.showToast('Enlace de la cotización ' + data.numero + ' copiado al portapapeles.', 'success', 'Enlace Copiado');
            }
        }).catch(() => {});
    }

    document.getElementById('modalCompartirProforma').classList.remove('hidden');
}

function cerrarCompartirProforma() {
    document.getElementById('modalCompartirProforma').classList.add('hidden');
}

function copiarEnlaceModal() {
    const inp = document.getElementById('share_cot_url');
    inp.select();
    inp.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(inp.value).then(() => {
        const txt = document.getElementById('btn_copiar_modal_txt');
        const btn = document.getElementById('btn_copiar_modal');
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
document.getElementById('modalCompartirProforma')?.addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarCompartirProforma();
    }
});
</script>

<?php include 'includes/footer.php'; ?>
