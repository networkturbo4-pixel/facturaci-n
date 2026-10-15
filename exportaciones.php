<?php
require_once 'includes/auth.php';
requireLogin();

try {
    // Consulta optimizada sin duplicación de filas usando agregación
    $stmt = $conn->query("
        SELECT c.*, 
               (SELECT ep.codigo_partida FROM exportacion_partidas ep WHERE ep.comprobante_id = c.id LIMIT 1) as codigo_partida,
               (SELECT ep.unidad_medida FROM exportacion_partidas ep WHERE ep.comprobante_id = c.id LIMIT 1) as unidad_medida,
               (SELECT COUNT(*) FROM exportacion_partidas ep WHERE ep.comprobante_id = c.id) as total_partidas
        FROM comprobantes c
        WHERE c.es_exportacion = 1
        ORDER BY c.id DESC
    ");
    $exportaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $exportaciones = [];
    $error_msg = "Error: " . $e->getMessage();
}

// Estadísticas rápidas con rango de fecha sargable
try {
    $inicioMes = date('Y-m-01');
    $finMes    = date('Y-m-t');
    $stmtStats = $conn->prepare("
        SELECT
            COUNT(*) as total_exp,
            COALESCE(SUM(subtotal), 0) as total_usd,
            COUNT(CASE WHEN sunat_cdr_estado = 'Aceptado' THEN 1 END) as aceptados,
            COUNT(CASE WHEN sunat_cdr_estado IS NULL OR sunat_cdr_estado = '' THEN 1 END) as pendientes,
            COUNT(CASE WHEN estado_dam = 'Salida Autorizada' THEN 1 END) as dam_ok
        FROM comprobantes
        WHERE es_exportacion = 1
          AND fecha_emision >= ? AND fecha_emision <= ?
    ");
    $stmtStats->execute([$inicioMes, $finMes]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = ['total_exp' => 0, 'total_usd' => 0, 'aceptados' => 0, 'pendientes' => 0, 'dam_ok' => 0];
}

include 'includes/header.php';
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Comercio Exterior y Exportaciones';
    $topbarAction = [
        'label' => 'Nueva Exportación',
        'url'   => 'exportacion_nueva.php',
        'icon'  => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

        <!-- Stats Cards (2 Columns on Mobile, 4 on Desktop) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
            <div class="app-card p-3.5 sm:p-5">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1 leading-tight">Exportaciones Mes</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-800"><?php echo $stats['total_exp']; ?></p>
            </div>
            <div class="app-card p-3.5 sm:p-5">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1 leading-tight">Total Exportado (USD)</p>
                <p class="text-lg sm:text-2xl font-bold text-blue-600 truncate">$ <?php echo number_format($stats['total_usd'], 2); ?></p>
            </div>
            <div class="app-card p-3.5 sm:p-5">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1 leading-tight">CDR Aceptados</p>
                <p class="text-lg sm:text-2xl font-bold text-emerald-600"><?php echo $stats['aceptados']; ?></p>
            </div>
            <div class="app-card p-3.5 sm:p-5">
                <p class="text-[10px] sm:text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1 leading-tight">Pendientes Envío</p>
                <p class="text-lg sm:text-2xl font-bold text-amber-500"><?php echo $stats['pendientes']; ?></p>
            </div>
        </div>

        <!-- Filtros rápidos -->
        <div class="flex flex-col md:flex-row gap-4 mb-6">
            <div class="relative flex-1 max-w-md">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="filtro_buscar" class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg block w-full pl-10 p-2.5 shadow-sm" placeholder="Buscar por comprador, serie o país...">
            </div>
            <select id="filtro_cdr" class="bg-white border border-gray-300 text-gray-700 text-sm rounded-lg p-2.5 shadow-sm">
                <option value="">Todos los estados CDR</option>
                <option value="Aceptado">✅ Aceptado</option>
                <option value="Rechazado">❌ Rechazado</option>
                <option value="Pendiente SUNAT">⏳ Pendiente SUNAT</option>
            </select>
            <select id="filtro_dam" class="bg-white border border-gray-300 text-gray-700 text-sm rounded-lg p-2.5 shadow-sm">
                <option value="">Todos los estados DAM</option>
                <option value="Sin DAM">⚫ Sin DAM</option>
                <option value="En Proceso">🟡 En Proceso</option>
                <option value="Salida Autorizada">🟢 Salida Autorizada</option>
                <option value="Exportado">🔵 Exportado</option>
            </select>
        </div>

        <!-- Tabla -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <?php if (isset($error_msg)): ?>
            <div class="p-4 bg-red-50 text-red-600 text-sm border-b border-red-100">
                <i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
            </div>
            <?php endif; ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left" id="tabla_exp">
                    <thead class="text-xs text-gray-600 uppercase bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-5 py-4 font-semibold">Comprobante</th>
                            <th class="px-5 py-4 font-semibold">Comprador</th>
                            <th class="px-5 py-4 font-semibold">País Destino</th>
                            <th class="px-5 py-4 font-semibold">Incoterm</th>
                            <th class="px-5 py-4 font-semibold">Monto</th>
                            <th class="px-5 py-4 font-semibold">CDR SUNAT</th>
                            <th class="px-5 py-4 font-semibold">Estado DAM</th>
                            <th class="px-5 py-4 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100" id="tbody_exp">
                    <?php if (empty($exportaciones)): ?>
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <div class="flex flex-col items-center gap-3 text-gray-400">
                                    <i class="fa-solid fa-ship text-4xl"></i>
                                    <p class="font-medium">No hay exportaciones registradas</p>
                                    <a href="exportacion_nueva.php" class="text-blue-600 text-sm underline">Crear primera exportación →</a>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exportaciones as $e): ?>
                        <?php
                            $cdr = $e['sunat_cdr_estado'] ?? '';
                            $dam = $e['estado_dam'] ?? 'Sin DAM';
                            $cdr_badge = match($cdr) {
                                'Aceptado'       => 'bg-emerald-100 text-emerald-800',
                                'Rechazado'      => 'bg-red-100 text-red-800',
                                'Pendiente SUNAT'=> 'bg-amber-100 text-amber-800',
                                default          => 'bg-gray-100 text-gray-600'
                            };
                            $cdr_icon = match($cdr) {
                                'Aceptado'  => 'fa-circle-check',
                                'Rechazado' => 'fa-circle-xmark',
                                default     => 'fa-clock'
                            };
                            $dam_badge = match($dam) {
                                'Salida Autorizada' => 'bg-emerald-100 text-emerald-800',
                                'En Proceso'        => 'bg-amber-100 text-amber-800',
                                'Exportado'         => 'bg-blue-100 text-blue-800',
                                'Observado'         => 'bg-orange-100 text-orange-800',
                                'Rechazado'         => 'bg-red-100 text-red-800',
                                default             => 'bg-gray-100 text-gray-500'
                            };
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors bg-white exp-row"
                            data-cdr="<?php echo htmlspecialchars($cdr ?: 'Pendiente SUNAT'); ?>"
                            data-dam="<?php echo htmlspecialchars($dam); ?>">
                            <td class="px-5 py-4">
                                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($e['serie'] . '-' . str_pad($e['correlativo'], 6, '0', STR_PAD_LEFT)); ?></div>
                                <div class="text-xs text-gray-400"><?php echo date('d/m/Y', strtotime($e['fecha_emision'])); ?></div>
                            </td>
                            <td class="px-5 py-4 max-w-[180px]">
                                <div class="font-medium text-gray-800 truncate" title="<?php echo htmlspecialchars($e['cliente_razon_social']); ?>"><?php echo htmlspecialchars($e['cliente_razon_social']); ?></div>
                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($e['cliente_pais_residencia'] ?? '-'); ?></div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="font-medium text-gray-800"><?php echo htmlspecialchars($e['pais_destino_nombre'] ?? '-'); ?></span>
                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($e['pais_destino_codigo'] ?? ''); ?></div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="font-mono text-xs font-semibold bg-gray-100 text-gray-700 px-2 py-1 rounded"><?php echo htmlspecialchars($e['incoterm'] ?: '-'); ?></span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($e['moneda'] ?? 'USD'); ?> <?php echo number_format($e['total'], 2); ?></div>
                                <div class="text-xs text-gray-400">S/ <?php echo number_format($e['total'] * ($e['tipo_cambio'] ?? 1), 2); ?></div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $cdr_badge; ?>">
                                    <i class="fa-solid <?php echo $cdr_icon; ?>"></i>
                                    <?php echo htmlspecialchars($cdr ?: 'Pendiente SUNAT'); ?>
                                </span>
                                <?php if ($e['sunat_enlace_pdf']): ?>
                                <a href="<?php echo htmlspecialchars($e['sunat_enlace_pdf']); ?>" target="_blank" class="block text-xs text-blue-600 underline mt-1">Ver PDF SUNAT</a>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $dam_badge; ?>">
                                    <?php echo htmlspecialchars($dam); ?>
                                </span>
                                <?php if ($e['numero_dam']): ?>
                                <div class="text-xs text-gray-400 mt-1 font-mono">DAM: <?php echo htmlspecialchars($e['numero_dam']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <!-- Ver impresión segura -->
                                    <?php $linkPublico = !empty($e['token_publico']) ? 'exportacion_publica.php?token=' . urlencode($e['token_publico']) : 'exportacion_publica.php?id=' . $e['id']; ?>
                                    <a href="<?php echo $linkPublico; ?>" target="_blank"
                                       class="p-2 text-gray-400 hover:text-blue-600 transition-colors" title="Ver / Imprimir">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <!-- Enviar a SUNAT -->
                                    <?php if (!$e['nubefact_enviado']): ?>
                                    <button onclick="enviarSunat(<?php echo $e['id']; ?>, this)"
                                            class="p-2 text-gray-400 hover:text-emerald-600 transition-colors" title="Enviar a SUNAT">
                                        <i class="fa-solid fa-cloud-arrow-up"></i>
                                    </button>
                                    <?php endif; ?>
                                    <!-- Actualizar DAM -->
                                    <button onclick="abrirModalDam(<?php echo $e['id']; ?>, '<?php echo htmlspecialchars($e['numero_dam'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dam, ENT_QUOTES); ?>')"
                                            class="p-2 text-gray-400 hover:text-amber-600 transition-colors" title="Actualizar DAM">
                                        <i class="fa-solid fa-ship"></i>
                                    </button>
                                    <!-- Compartir Factura -->
                                    <a href="<?php echo $linkPublico; ?>"
                                       target="_blank" class="p-2 text-gray-400 hover:text-green-500 transition-colors" title="Abrir enlace seguro para cliente">
                                        <i class="fa-solid fa-share-nodes text-base"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
                <span class="text-sm text-gray-500"><?php echo count($exportaciones); ?> exportaciones registradas</span>
                <a href="https://www.sunat.gob.pe/aduanas/informaRaiz/index.html" target="_blank" class="text-xs text-blue-600 hover:underline flex items-center gap-1">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Consultar DAM en SUNAT
                </a>
            </div>
        </div>
    </div>
</main>

<!-- Modal: Actualizar DAM -->
<div id="modal_dam" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                <i class="fa-solid fa-ship text-amber-600"></i>
            </div>
            <div>
                <h3 class="font-bold text-gray-800">Actualizar Estado DAM</h3>
                <p class="text-xs text-gray-500">Declaración Aduanera de Mercancías</p>
            </div>
        </div>
        <input type="hidden" id="modal_dam_id">
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1.5">N° de DAM</label>
                <input type="text" id="modal_dam_num" placeholder="Ej: 118-2025-10-123456" class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Estado</label>
                <select id="modal_dam_estado" class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="Sin DAM">⚫ Sin DAM</option>
                    <option value="En Proceso">🟡 En Proceso</option>
                    <option value="Salida Autorizada">🟢 Salida Autorizada</option>
                    <option value="Exportado">🔵 Exportado</option>
                    <option value="Observado">🟠 Observado</option>
                    <option value="Rechazado">🔴 Rechazado</option>
                </select>
            </div>
            <div class="bg-gray-50 border border-gray-100 rounded-lg p-3 text-xs text-gray-500">
                <a href="https://www.sunat.gob.pe/aduanas/informaRaiz/index.html" target="_blank" class="text-blue-600 underline">
                    <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i>Consultar estado real en SUNAT Aduanas
                </a>
            </div>
        </div>
        <div class="flex gap-3 mt-6">
            <button onclick="cerrarModalDam()" class="flex-1 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50">Cancelar</button>
            <button onclick="guardarDam()" id="btn_guardar_dam" class="flex-1 py-2 bg-amber-500 text-white rounded-lg text-sm font-semibold hover:bg-amber-600 transition-colors">
                <i class="fa-solid fa-save mr-1"></i> Guardar
            </button>
        </div>
    </div>
</div>

<script>
// --- Filtros en tiempo real ---
function aplicarFiltros() {
    const buscar = document.getElementById('filtro_buscar').value.toLowerCase();
    const cdrF   = document.getElementById('filtro_cdr').value;
    const damF   = document.getElementById('filtro_dam').value;
    document.querySelectorAll('.exp-row').forEach(row => {
        const txt = row.textContent.toLowerCase();
        const cdr = row.dataset.cdr;
        const dam = row.dataset.dam;
        const ok  = txt.includes(buscar) &&
                    (cdrF === '' || cdr === cdrF) &&
                    (damF === '' || dam === damF);
        row.style.display = ok ? '' : 'none';
    });
}
document.getElementById('filtro_buscar').addEventListener('input', aplicarFiltros);
document.getElementById('filtro_cdr').addEventListener('change', aplicarFiltros);
document.getElementById('filtro_dam').addEventListener('change', aplicarFiltros);

// --- Enviar a SUNAT ---
async function enviarSunat(id, btn) {
    const ok = await window.showConfirm({
        title: 'Enviar Exportación a SUNAT',
        message: '¿Deseas transmitir esta factura de exportación a SUNAT / OSE ahora?',
        confirmText: 'Sí, enviar a SUNAT',
        variant: 'primary'
    });
    if (!ok) return;
    const icon = btn.querySelector('i');
    icon.className = 'fa-solid fa-spinner fa-spin';
    btn.disabled = true;
    try {
        const res  = await fetch('api/enviar_sunat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ comprobante_id: id })
        });
        const data = await res.json();
        if (data.success) {
            window.showToast('SUNAT: ' + data.cdr_estado + ' — ' + (data.descripcion || ''), 'success', 'Exportación Validada');
            setTimeout(() => location.reload(), 1200);
        } else {
            window.showToast(data.message || 'Error al transmitir a SUNAT', 'error', 'Error SUNAT');
            icon.className = 'fa-solid fa-cloud-arrow-up';
            btn.disabled = false;
        }
    } catch (e) {
        window.showToast('Error de conexión: ' + e.message, 'error', 'Error de Red');
        icon.className = 'fa-solid fa-cloud-arrow-up';
        btn.disabled = false;
    }
}

// --- Modal DAM ---
function abrirModalDam(id, numDam, estadoDam) {
    document.getElementById('modal_dam_id').value = id;
    document.getElementById('modal_dam_num').value = numDam;
    document.getElementById('modal_dam_estado').value = estadoDam;
    document.getElementById('modal_dam').classList.remove('hidden');
}
function cerrarModalDam() {
    document.getElementById('modal_dam').classList.add('hidden');
}
async function guardarDam() {
    const id     = document.getElementById('modal_dam_id').value;
    const num    = document.getElementById('modal_dam_num').value;
    const estado = document.getElementById('modal_dam_estado').value;
    const btn    = document.getElementById('btn_guardar_dam');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Guardando...';
    try {
        const res  = await fetch('api/consultar_dam.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ comprobante_id: id, numero_dam: num, estado_dam: estado })
        });
        const data = await res.json();
        if (data.success) {
            window.showToast('Declaración Aduanera (DAM) actualizada.', 'success', 'DAM Guardada');
            setTimeout(() => location.reload(), 900);
        } else {
            window.showToast(data.message || 'No se pudo actualizar la DAM.', 'error', 'Error DAM');
        }
    } catch (e) {
        window.showToast(e.message, 'error', 'Error de Red');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-save mr-1"></i> Guardar';
}
document.getElementById('modal_dam').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalDam();
});
</script>

<?php include 'includes/footer.php'; ?>
