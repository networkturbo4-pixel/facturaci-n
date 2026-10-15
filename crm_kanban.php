<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';

// Procesar acciones de oportunidades CRM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o expirado.";
    } elseif (isset($_POST['crear_oportunidad'])) {
        $titulo  = trim($_POST['titulo'] ?? '');
        $cliente = trim($_POST['cliente_nombre'] ?? '');
        $categoria = trim($_POST['categoria'] ?? 'Ventas');
        $monto   = (float)($_POST['monto'] ?? 0);
        $moneda  = trim($_POST['moneda'] ?? 'PEN');
        $etapa   = trim($_POST['etapa'] ?? 'Prospecto');

        if (empty($titulo) || empty($cliente)) {
            $error_msg = "El título y cliente son obligatorios.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO crm_oportunidades (titulo, cliente_nombre, categoria, monto, moneda, etapa) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$titulo, $cliente, $categoria, $monto, $moneda, $etapa]);
                $success_msg = "Oportunidad agregada al tablero.";
            } catch (Exception $e) {
                $error_msg = "Error al crear oportunidad: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['cambiar_etapa'])) {
        $opId = (int)($_POST['oportunidad_id'] ?? 0);
        $nuevaEtapa = trim($_POST['nueva_etapa'] ?? 'Prospecto');
        try {
            $upd = $conn->prepare("UPDATE crm_oportunidades SET etapa = ? WHERE id = ?");
            $upd->execute([$nuevaEtapa, $opId]);
            $success_msg = "Etapa de oportunidad actualizada.";
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['eliminar_oportunidad'])) {
        $opId = (int)($_POST['oportunidad_id'] ?? 0);
        try {
            $del = $conn->prepare("DELETE FROM crm_oportunidades WHERE id = ?");
            $del->execute([$opId]);
            $success_msg = "Oportunidad eliminada.";
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

// Cargar oportunidades agrupadas por etapa
$etapasDefinidas = [
    'Prospecto'     => ['color' => 'blue',   'badge' => 'bg-blue-50 text-blue-700 border-blue-200'],
    'Propuesta'     => ['color' => 'purple', 'badge' => 'bg-purple-50 text-purple-700 border-purple-200'],
    'Negociación'   => ['color' => 'amber',  'badge' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'Cerrado Ganado'=> ['color' => 'emerald','badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200']
];

$tablero = [];
foreach (array_keys($etapasDefinidas) as $et) {
    $tablero[$et] = [];
}

try {
    $stmt = $conn->query("SELECT * FROM crm_oportunidades ORDER BY id DESC");
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $f) {
        $et = $f['etapa'];
        if (isset($tablero[$et])) {
            $tablero[$et][] = $f;
        } else {
            $tablero['Prospecto'][] = $f;
        }
    }
} catch (Exception $e) {}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Tablero CRM & Oportunidades';
    $topbarAction = [
        'label'   => 'Nueva Oportunidad',
        'onclick' => "document.getElementById('modalNuevaOportunidad').classList.remove('hidden')",
        'icon'    => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <?php if ($success_msg): ?>
        <div class="mx-6 mt-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs flex items-center gap-2">
            <i class="fa-solid fa-circle-check text-emerald-500"></i>
            <span><?php echo htmlspecialchars($success_msg); ?></span>
        </div>
    <?php endif; ?>

    <div class="flex-1 overflow-x-auto p-6 h-full">
        <!-- Contenedor del Kanban Board -->
        <div class="flex gap-6 h-full items-start min-w-max pb-6">
            
            <?php foreach ($etapasDefinidas as $nombreEtapa => $conf): ?>
            <?php 
                $items = $tablero[$nombreEtapa] ?? [];
                $sumaMonto = array_sum(array_column($items, 'monto'));
            ?>
            <div class="w-80 bg-slate-100/80 rounded-2xl flex flex-col max-h-full border border-slate-200/80 shadow-xs">
                
                <!-- Encabezado de Columna -->
                <div class="p-4 border-b border-slate-200/80 flex justify-between items-center bg-white/70 rounded-t-2xl shrink-0">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-<?php echo $conf['color']; ?>-500"></span>
                        <h3 class="font-bold text-slate-800 text-xs tracking-wide uppercase"><?php echo htmlspecialchars($nombreEtapa); ?></h3>
                    </div>
                    <span class="bg-slate-200 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full"><?php echo count($items); ?></span>
                </div>

                <!-- Sub-total Columna -->
                <div class="px-4 py-2 bg-slate-50/50 border-b border-slate-200/40 text-[11px] font-semibold text-slate-500 flex justify-between">
                    <span>Total estimado:</span>
                    <span class="text-slate-800 font-bold font-mono">S/ <?php echo number_format($sumaMonto, 2); ?></span>
                </div>

                <!-- Lista de Tarjetas -->
                <div class="p-3 flex-1 overflow-y-auto space-y-3">
                    <?php if (empty($items)): ?>
                        <div class="p-6 text-center text-slate-400 text-xs italic">
                            Sin oportunidades en esta etapa
                        </div>
                    <?php else: ?>
                        <?php foreach ($items as $op): ?>
                        <div class="bg-white p-4 rounded-xl shadow-xs border border-slate-200 hover:border-brand-500 transition-all space-y-2">
                            <div class="flex justify-between items-start">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-md <?php echo $conf['badge']; ?>">
                                    <?php echo htmlspecialchars($op['categoria']); ?>
                                </span>
                                <form method="POST" action="" onsubmit="return confirm('¿Eliminar esta oportunidad?');" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="eliminar_oportunidad" value="1">
                                    <input type="hidden" name="oportunidad_id" value="<?php echo $op['id']; ?>">
                                    <button type="submit" class="text-slate-400 hover:text-red-500 p-1"><i class="fa-solid fa-trash text-[10px]"></i></button>
                                </form>
                            </div>
                            
                            <h4 class="font-bold text-slate-900 text-xs leading-snug"><?php echo htmlspecialchars($op['titulo']); ?></h4>
                            <p class="text-[11px] text-slate-500 flex items-center gap-1.5">
                                <i class="fa-regular fa-building text-slate-400"></i>
                                <?php echo htmlspecialchars($op['cliente_nombre']); ?>
                            </p>

                            <div class="flex items-center justify-between pt-2 border-t border-slate-100 text-xs">
                                <span class="font-bold text-slate-900 font-mono">
                                    <?php echo $op['moneda'] === 'USD' ? '$' : 'S/'; ?> <?php echo number_format($op['monto'], 2); ?>
                                </span>
                                
                                <!-- Cambiar etapa select rápido -->
                                <form method="POST" action="" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="cambiar_etapa" value="1">
                                    <input type="hidden" name="oportunidad_id" value="<?php echo $op['id']; ?>">
                                    <select name="nueva_etapa" onchange="this.form.submit()" class="text-[10px] bg-slate-50 border border-slate-200 rounded-lg p-1 text-slate-600 focus:outline-none">
                                        <?php foreach (array_keys($etapasDefinidas) as $optEt): ?>
                                            <option value="<?php echo $optEt; ?>" <?php echo $optEt === $nombreEtapa ? 'selected' : ''; ?>>
                                                ➔ <?php echo $optEt; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Footer de Columna -->
                <div class="p-3 border-t border-slate-200/80 bg-white/70 rounded-b-2xl shrink-0">
                    <button onclick="abrirModalConEtapa('<?php echo $nombreEtapa; ?>')" class="w-full text-slate-500 hover:text-brand-600 text-xs font-semibold py-1 transition-colors text-center flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-plus text-[10px]"></i> Añadir a esta etapa
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>
</main>

<!-- Modal: Nueva Oportunidad -->
<div id="modalNuevaOportunidad" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 text-sm">Nueva Oportunidad Comercial</h3>
            <button type="button" onclick="document.getElementById('modalNuevaOportunidad').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="crear_oportunidad" value="1">
            
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Título de la Oportunidad / Negocio</label>
                <input type="text" name="titulo" placeholder="Ej. Lote de Exportación Café 5TN" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Cliente / Empresa</label>
                <input type="text" name="cliente_nombre" placeholder="Razón social o nombre del contacto" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Monto Estimado</label>
                    <input type="number" step="0.01" min="0" name="monto" placeholder="0.00" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Moneda</label>
                    <select name="moneda" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="PEN">Soles (PEN)</option>
                        <option value="USD">Dólares (USD)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Categoría</label>
                    <select name="categoria" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="Exportación">Exportación</option>
                        <option value="Ventas">Ventas Nacional</option>
                        <option value="Servicios">Servicios</option>
                        <option value="Software">Software / Licencias</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Etapa Inicial</label>
                    <select id="modal_select_etapa" name="etapa" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="Prospecto">Prospecto</option>
                        <option value="Propuesta">Propuesta</option>
                        <option value="Negociación">Negociación</option>
                        <option value="Cerrado Ganado">Cerrado Ganado</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalNuevaOportunidad').classList.add('hidden')" class="px-4 py-2 border border-slate-300 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold rounded-xl shadow-sm">
                    Guardar Tarjeta
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalConEtapa(etapa) {
    document.getElementById('modal_select_etapa').value = etapa;
    document.getElementById('modalNuevaOportunidad').classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
