<?php
/**
 * guia_publica.php
 * Vista pública e imprimible (A4) de Guía de Remisión Remitente Electrónica.
 */
require_once 'includes/auth.php';

$token = trim($_GET['token'] ?? '');
$id    = (int)($_GET['id'] ?? 0);

$guia = null;
$lineas = [];

try {
    if (!empty($token)) {
        $stmt = $conn->prepare("SELECT * FROM guias_remision WHERE token_publico = ? LIMIT 1");
        $stmt->execute([$token]);
        $guia = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($id) && isLoggedIn()) {
        $stmt = $conn->prepare("SELECT * FROM guias_remision WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $guia = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($guia) {
        $stL = $conn->prepare("SELECT * FROM guia_remision_lineas WHERE guia_id = ? ORDER BY id ASC");
        $stL->execute([(int)$guia['id']]);
        $lineas = $stL->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $guia = null;
}

$brand = getBrandingConfig();

if (!$guia) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Guía no encontrada • <?php echo htmlspecialchars($brand['empresa_razon_social']); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans text-slate-800">
        <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-xl border border-slate-200 text-center">
            <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fa-solid fa-truck-fast"></i>
            </div>
            <h1 class="text-lg font-extrabold text-slate-900">Guía de Remisión no encontrada</h1>
            <p class="text-xs text-slate-500 mt-2">El enlace es inválido o la guía fue eliminada.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$numGuia = $guia['serie'] . '-' . str_pad((string)$guia['correlativo'], 6, '0', STR_PAD_LEFT);
$logoSrc = !empty($brand['logo_claro']) ? $brand['logo_claro'] : ($brand['logo_general'] ?? '');
$autoPrint = !empty($_GET['print']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guía de Remisión <?php echo htmlspecialchars($numGuia); ?> • <?php echo htmlspecialchars($brand['empresa_razon_social']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @media print {
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .print-sheet { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-6 px-3 sm:px-6 font-sans text-slate-800">

    <!-- Barra de acciones flotante -->
    <div class="max-w-4xl mx-auto mb-4 flex items-center justify-between gap-3 no-print">
        <div class="text-xs text-slate-500 font-semibold flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <span>Documento Electrónico de Traslado • <?php echo htmlspecialchars($numGuia); ?></span>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl shadow-sm flex items-center gap-2">
                <i class="fa-solid fa-print"></i>
                <span>Imprimir / PDF</span>
            </button>
        </div>
    </div>

    <!-- Hoja A4 -->
    <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-lg border border-slate-200 p-6 sm:p-10 print-sheet space-y-6">

        <!-- Cabecera Empresa + Recuadro SUNAT -->
        <div class="flex flex-col sm:flex-row justify-between items-start gap-6 pb-6 border-b border-slate-200">
            <div class="flex items-start gap-4">
                <?php if ($logoSrc): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo" class="h-14 w-auto object-contain">
                <?php endif; ?>
                <div>
                    <h1 class="text-lg font-extrabold text-slate-900"><?php echo htmlspecialchars($brand['empresa_razon_social']); ?></h1>
                    <p class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($brand['empresa_direccion'] ?? ''); ?></p>
                    <?php if (!empty($brand['empresa_telefono']) || !empty($brand['empresa_correo'])): ?>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            <?php echo htmlspecialchars(trim(($brand['empresa_telefono'] ?? '') . ' • ' . ($brand['empresa_correo'] ?? ''), ' •')); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="w-full sm:w-64 border-2 border-slate-900 rounded-xl p-4 text-center shrink-0">
                <p class="text-xs font-bold text-slate-600">R.U.C. <?php echo htmlspecialchars($brand['empresa_ruc']); ?></p>
                <p class="text-xs font-extrabold uppercase tracking-wider text-slate-900 mt-1">Guía de Remisión<br>Remitente Electrónica</p>
                <p class="text-base font-mono font-extrabold text-indigo-700 mt-1.5"><?php echo htmlspecialchars($numGuia); ?></p>
            </div>
        </div>

        <!-- Datos de Traslado y Destinatario -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-1.5">
                <p class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Destinatario</p>
                <p class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($guia['destinatario_razon'] ?: '—'); ?></p>
                <p class="font-mono text-slate-600">Documento: <?php echo htmlspecialchars($guia['destinatario_doc'] ?: '—'); ?></p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-1.5">
                <p class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Datos del Traslado</p>
                <p><span class="text-slate-500">Fecha de Emisión:</span> <strong class="font-mono"><?php echo date('d/m/Y', strtotime($guia['fecha_emision'])); ?></strong></p>
                <p><span class="text-slate-500">Motivo:</span> <strong><?php echo htmlspecialchars($guia['motivo_traslado']); ?></strong></p>
                <p><span class="text-slate-500">Peso Bruto Total:</span> <strong class="font-mono"><?php echo number_format((float)$guia['peso_bruto'], 2); ?> KGM</strong></p>
            </div>
        </div>

        <!-- Puntos de Partida y Llegada -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            <div class="p-4 rounded-xl bg-blue-50/50 border border-blue-200/70">
                <p class="text-[10px] font-extrabold uppercase tracking-wider text-blue-700 mb-1"><i class="fa-solid fa-location-dot mr-1"></i> Punto de Partida</p>
                <p class="font-semibold text-slate-800"><?php echo htmlspecialchars($guia['partida_direccion'] ?: '—'); ?></p>
                <p class="text-[11px] font-mono text-slate-500 mt-1">Ubigeo: <?php echo htmlspecialchars($guia['partida_ubigeo'] ?: '—'); ?></p>
            </div>
            <div class="p-4 rounded-xl bg-emerald-50/50 border border-emerald-200/70">
                <p class="text-[10px] font-extrabold uppercase tracking-wider text-emerald-700 mb-1"><i class="fa-solid fa-flag-checkered mr-1"></i> Punto de Llegada</p>
                <p class="font-semibold text-slate-800"><?php echo htmlspecialchars($guia['llegada_direccion'] ?: '—'); ?></p>
                <p class="text-[11px] font-mono text-slate-500 mt-1">Ubigeo: <?php echo htmlspecialchars($guia['llegada_ubigeo'] ?: '—'); ?></p>
            </div>
        </div>

        <!-- Datos de Transporte -->
        <div class="p-4 rounded-xl bg-slate-50 border border-slate-200/80 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div>
                <span class="block text-[10px] font-bold uppercase text-slate-400">Modalidad</span>
                <span class="font-bold text-slate-800"><?php echo htmlspecialchars($guia['modalidad_transporte'] ?: '—'); ?></span>
            </div>
            <div>
                <span class="block text-[10px] font-bold uppercase text-slate-400">Conductor / Licencia</span>
                <span class="font-mono font-bold text-slate-800"><?php echo htmlspecialchars($guia['conductor_doc'] ?: '—'); ?></span>
            </div>
            <div>
                <span class="block text-[10px] font-bold uppercase text-slate-400">Placa Vehículo</span>
                <span class="font-mono font-bold text-slate-800 uppercase"><?php echo htmlspecialchars($guia['vehiculo_placa'] ?: '—'); ?></span>
            </div>
        </div>

        <!-- Tabla de Bienes -->
        <div class="overflow-hidden rounded-xl border border-slate-200">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-900 text-white uppercase tracking-wider text-[10px]">
                        <th class="px-4 py-3 w-12 text-center">#</th>
                        <th class="px-4 py-3 w-28 text-center">Cantidad</th>
                        <th class="px-4 py-3 w-28 text-center">Unidad</th>
                        <th class="px-4 py-3">Descripción del Bien</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($lineas as $idx => $l): ?>
                    <tr>
                        <td class="px-4 py-3 text-center text-slate-400 font-mono"><?php echo $idx + 1; ?></td>
                        <td class="px-4 py-3 text-center font-mono font-bold text-slate-900"><?php echo number_format((float)$l['cantidad'], 2); ?></td>
                        <td class="px-4 py-3 text-center font-mono text-slate-600"><?php echo htmlspecialchars($l['unidad']); ?></td>
                        <td class="px-4 py-3 font-semibold text-slate-800"><?php echo htmlspecialchars($l['descripcion']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pt-4 border-t border-slate-200 flex items-center justify-between text-[11px] text-slate-400">
            <span>Representación impresa de la Guía de Remisión Remitente</span>
            <span>Estado: <strong class="text-emerald-600"><?php echo htmlspecialchars($guia['estado'] ?? 'Emitida'); ?></strong></span>
        </div>
    </div>

    <?php if ($autoPrint): ?>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    <?php endif; ?>
</body>
</html>
