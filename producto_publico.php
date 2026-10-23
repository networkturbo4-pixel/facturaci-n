<?php
/**
 * producto_publico.php
 * Ficha pública comercial de un producto o servicio para compartir con clientes.
 * Accesible sin inicio de sesión mediante token de seguridad único.
 */

require_once 'includes/db.php';
require_once 'includes/auth.php';

$token = trim($_GET['token'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$producto = null;

if (!empty($token)) {
    $stmt = $conn->prepare("SELECT * FROM productos WHERE token_publico = ? LIMIT 1");
    $stmt->execute([$token]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fallback por ID solo si el usuario está autenticado
if (!$producto && $id > 0 && isLoggedIn()) {
    $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$producto) {
    http_response_code(404);
    die("
    <!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Producto no encontrado</title>
        <script src='https://cdn.tailwindcss.com'></script>
    </head>
    <body class='bg-slate-50 min-h-screen flex items-center justify-center p-4 text-center font-sans'>
        <div class='bg-white p-8 rounded-2xl shadow-sm border border-slate-200 max-w-md w-full'>
            <div class='w-16 h-16 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl'>
                ✕
            </div>
            <h2 class='text-lg font-bold text-slate-800 mb-1'>Producto no disponible</h2>
            <p class='text-xs text-slate-500 mb-6'>El enlace al producto es inválido o ha sido retirado del catálogo.</p>
            <a href='login.php' class='px-4 py-2 bg-slate-800 text-white text-xs font-semibold rounded-xl'>Ir al Sistema</a>
        </div>
    </body>
    </html>");
}

// Cargar datos de la empresa y marca
$cfgRows        = getAllSystemConfig();
$brand          = getBrandingConfig();
$empresaRazon   = $brand['empresa_razon_social'];
$empresaNombre  = $brand['empresa_nombre_comercial'];
$empresaRuc     = $brand['empresa_ruc'];
$empresaDir     = $brand['empresa_direccion'];
$empresaTel     = $brand['empresa_telefono'];
$logoDoc        = $brand['logo_documento_efectivo'];
$tipoCambio     = (float)($cfgRows['tipo_cambio_cache'] ?? 3.75);

$precioSoles = (float)$producto['precio_unitario'];
$precioDolares = ($tipoCambio > 0) ? round($precioSoles / $tipoCambio, 2) : 0;
$stockNum = (float)$producto['stock'];

$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$reqUri   = $_SERVER['REQUEST_URI'] ?? '/FACTURACIÓN/producto_publico.php';
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$urlActual = "{$protocol}://{$httpHost}{$reqUri}";

// Mensaje para WhatsApp
$telLimpio = preg_replace('/[^0-9]/', '', $empresaTel);
$wspText = rawurlencode("Hola, vengo de la ficha pública de su catálogo. Me interesa solicitar información / cotización del producto: *" . $producto['descripcion'] . "* (Código: " . $producto['codigo'] . ").\nEnlace: " . $urlActual);
$wspUrl = !empty($telLimpio) ? "https://api.whatsapp.com/send?phone={$telLimpio}&text={$wspText}" : "https://api.whatsapp.com/send?text={$wspText}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($producto['descripcion']); ?> — <?php echo htmlspecialchars($empresaNombre); ?></title>
<?php echo renderHeadBranding($brand); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .print-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
        }
    </style>
</head>
<body class="bg-slate-100/70 text-slate-800 font-sans min-h-screen flex flex-col justify-between p-4 sm:p-6 lg:p-10">

    <!-- Top Action Bar (no print) -->
    <div class="max-w-3xl mx-auto w-full mb-6 no-print flex flex-col sm:flex-row items-center justify-between gap-3 bg-white p-4 rounded-2xl shadow-xs border border-slate-200/80">
        <div class="flex items-center gap-3">
            <?php if (!empty($logoDoc)): ?>
                <img src="<?php echo htmlspecialchars($logoDoc); ?>" alt="<?php echo htmlspecialchars($empresaNombre); ?>" class="h-10 max-w-[130px] object-contain rounded-lg">
            <?php else: ?>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-box-open"></i>
                </div>
            <?php endif; ?>
            <div>
                <p class="text-xs font-bold text-slate-900"><?php echo htmlspecialchars($empresaNombre); ?></p>
                <p class="text-[11px] text-slate-500">Catálogo Comercial Oficial • Ficha de Producto</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-print"></i>
                <span>Imprimir Ficha</span>
            </button>
            <button onclick="copiarLink()" id="btn_copiar" class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-xl transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-copy"></i>
                <span id="btn_copiar_txt">Copiar Enlace</span>
            </button>
        </div>
    </div>

    <!-- Main Product Card -->
    <div class="max-w-3xl mx-auto w-full bg-white rounded-3xl shadow-sm border border-slate-200/80 overflow-hidden print-card">
        
        <!-- Header Banner -->
        <div class="p-6 sm:p-8 bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white relative overflow-hidden">
            <div class="absolute -right-8 -top-8 w-48 h-48 bg-indigo-500/10 rounded-full blur-2xl pointer-events-none"></div>
            
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="px-3 py-1 rounded-full text-xs font-mono font-bold bg-white/10 text-white border border-white/20">
                    <?php echo htmlspecialchars($producto['codigo']); ?>
                </span>
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                    Unidad: <?php echo htmlspecialchars($producto['unidad_medida']); ?>
                </span>
                <?php if ($stockNum > 0): ?>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-300 border border-blue-500/30">
                        <i class="fa-solid fa-circle-check text-[10px] mr-1"></i> Disponible en Stock
                    </span>
                <?php else: ?>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                        Bajo Pedido
                    </span>
                <?php endif; ?>
            </div>

            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-white leading-tight">
                <?php echo htmlspecialchars($producto['descripcion']); ?>
            </h1>
        </div>

        <!-- Body Details -->
        <div class="p-6 sm:p-8 space-y-6">
            
            <!-- Price Box -->
            <div class="p-5 rounded-2xl bg-gradient-to-br from-slate-50 to-indigo-50/40 border border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Precio Unitario Sugerido</span>
                    <div class="flex items-baseline gap-3 mt-1">
                        <span class="text-3xl sm:text-4xl font-black text-slate-900">
                            S/ <?php echo number_format($precioSoles, 2); ?>
                        </span>
                        <?php if ($precioDolares > 0): ?>
                        <span class="text-sm font-semibold text-slate-500">
                            ≈ $ <?php echo number_format($precioDolares, 2); ?> USD
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-1">Precios referenciales sujetos a cotización y volumen de compra.</p>
                </div>

                <div class="no-print">
                    <a href="<?php echo $wspUrl; ?>" target="_blank" class="w-full sm:w-auto px-5 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-sm transition-all flex items-center justify-center gap-2 hover:scale-[1.02] active:scale-[0.98]">
                        <i class="fa-brands fa-whatsapp text-base"></i>
                        <span>Cotizar por WhatsApp</span>
                    </a>
                </div>
            </div>

            <!-- Technical Data Grid -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Ficha Técnica & Clasificación</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <span class="text-slate-500 font-medium">Código del Producto:</span>
                        <span class="font-mono font-bold text-slate-900"><?php echo htmlspecialchars($producto['codigo']); ?></span>
                    </div>
                    <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <span class="text-slate-500 font-medium">Unidad de Medida (SUNAT):</span>
                        <span class="font-bold text-slate-900"><?php echo htmlspecialchars($producto['unidad_medida']); ?></span>
                    </div>
                    <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <span class="text-slate-500 font-medium">Partida Arancelaria:</span>
                        <span class="font-mono font-bold text-slate-900"><?php echo htmlspecialchars($producto['codigo_partida'] ?: 'No aplica (Venta Local)'); ?></span>
                    </div>
                    <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <span class="text-slate-500 font-medium">Disponibilidad en Almacén:</span>
                        <span class="font-bold <?php echo ($stockNum > 0) ? 'text-emerald-700' : 'text-slate-700'; ?>">
                            <?php echo number_format($stockNum, 2); ?> <?php echo htmlspecialchars($producto['unidad_medida']); ?>
                        </span>
                    </div>
                    <?php if (!empty($producto['lote_actual'])): ?>
                    <div class="p-4 rounded-xl border border-indigo-100 bg-indigo-50/40 flex justify-between items-center sm:col-span-2">
                        <span class="text-slate-600 font-medium flex items-center gap-1.5"><i class="fa-solid fa-barcode text-indigo-600"></i> Lote de Trazabilidad Vigente:</span>
                        <span class="font-mono font-bold text-indigo-700 bg-white px-2.5 py-1 rounded-lg border border-indigo-200"><?php echo htmlspecialchars($producto['lote_actual']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Company Contact Box -->
            <div class="p-5 rounded-2xl border border-slate-200/80 bg-white flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Proveedor / Emisor Oficial</h4>
                    <p class="text-sm font-semibold text-slate-800 mt-0.5"><?php echo htmlspecialchars($empresaRazon); ?></p>
                    <p class="text-[11px] text-slate-500 font-mono">RUC: <?php echo htmlspecialchars($empresaRuc); ?> • <?php echo htmlspecialchars($empresaDir); ?></p>
                    <?php if (!empty($empresaTel)): ?>
                    <p class="text-[11px] text-slate-500 mt-0.5"><i class="fa-solid fa-phone text-[10px] mr-1 text-slate-400"></i> <?php echo htmlspecialchars($empresaTel); ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right shrink-0">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        Empresa Verificada
                    </span>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 text-center text-[11px] text-slate-400">
            Documento informativo generado por <?php echo htmlspecialchars($empresaNombre); ?> • <?php echo date('Y'); ?>
        </div>

    </div>

    <!-- Bottom Copyright -->
    <div class="max-w-3xl mx-auto w-full text-center text-xs text-slate-400 mt-6 no-print">
        Plataforma de Facturación Comercial FACTUSYS
    </div>

    <script>
    function copiarLink() {
        navigator.clipboard.writeText(window.location.href).then(() => {
            const btn = document.getElementById('btn_copiar');
            const txt = document.getElementById('btn_copiar_txt');
            txt.textContent = '¡Copiado!';
            btn.classList.remove('bg-slate-900');
            btn.classList.add('bg-emerald-600');
            setTimeout(() => {
                txt.textContent = 'Copiar Enlace';
                btn.classList.remove('bg-emerald-600');
                btn.classList.add('bg-slate-900');
            }, 2000);
        });
    }
    </script>
</body>
</html>
