<?php
require_once 'includes/auth.php';

$token = trim($_GET['token'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if (!empty($token)) {
    $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE token_publico = ?");
    $stmt->execute([$token]);
    $comprobante = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($id) && isLoggedIn()) {
    $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE id = ?");
    $stmt->execute([$id]);
    $comprobante = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    http_response_code(403);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>Acceso no autorizado</h2><p>Se requiere un enlace con token de seguridad válido para visualizar este comprobante.</p></div>");
}

if (!$comprobante) {
    http_response_code(404);
    die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>Comprobante no encontrado</h2></div>");
}

$stmt_lineas = $conn->prepare("SELECT * FROM comprobante_lineas WHERE comprobante_id = ?");
$stmt_lineas->execute([$comprobante['id']]);
$lineas = $stmt_lineas->fetchAll(PDO::FETCH_ASSOC);

$brand = getBrandingConfig();
$empresaRuc   = $brand['empresa_ruc'];
$empresaRazon = $brand['empresa_razon_social'];
$empresaDir   = $brand['empresa_direccion'];
$empresaTel   = $brand['empresa_telefono'];
$empresaWeb   = $brand['empresa_web'] ?: 'www.factusys.com';
$logoDoc      = $brand['logo_documento_efectivo'];

$monedaCodigo = strtoupper($comprobante['moneda'] ?? 'PEN');
$simboloMon   = ($monedaCodigo === 'USD') ? 'US$' : 'S/';
$nombreMoneda = ($monedaCodigo === 'USD') ? 'Dólares Americanos (USD)' : 'Soles (PEN)';

$numero = $comprobante['serie'] . '-' . str_pad($comprobante['correlativo'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante <?php echo htmlspecialchars($numero); ?> • <?php echo htmlspecialchars($empresaRazon); ?></title>
<?php echo renderHeadBranding($brand); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @media print {
            body { background-color: white !important; }
            .no-print { display: none !important; }
            .print-container { box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans text-gray-800 p-4 md:p-8">

    <div class="max-w-4xl mx-auto mb-6 flex justify-between items-center no-print bg-white p-4 rounded-xl shadow-sm">
        <div class="flex items-center gap-2.5">
            <?php if (!empty($logoDoc)): ?>
                <img src="<?php echo htmlspecialchars($logoDoc); ?>" alt="Logo" class="h-8 max-w-[120px] object-contain">
            <?php else: ?>
                <i class="fa-solid fa-file-invoice-dollar text-blue-600 text-xl"></i>
            <?php endif; ?>
            <h1 class="text-lg font-bold text-gray-800">Representación Electrónica • <?php echo htmlspecialchars($numero); ?></h1>
        </div>
        <div class="flex gap-3">
            <button onclick="window.print()" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2 text-sm font-semibold">
                <i class="fa-solid fa-print"></i> Imprimir / PDF
            </button>
        </div>
    </div>

    <div class="max-w-4xl mx-auto bg-white p-8 md:p-12 rounded-xl shadow-lg print-container">
        
        <div class="flex flex-col md:flex-row justify-between items-start mb-10 border-b pb-8">
            <div class="flex-1">
                <?php if (!empty($logoDoc)): ?>
                    <img src="<?php echo htmlspecialchars($logoDoc); ?>" alt="<?php echo htmlspecialchars($empresaRazon); ?>" class="max-h-16 max-w-[220px] object-contain mb-3">
                <?php endif; ?>
                <h2 class="text-2xl md:text-3xl font-black text-blue-600 tracking-wider mb-1"><?php echo htmlspecialchars($empresaRazon); ?></h2>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($empresaDir); ?></p>
                <?php if (!empty($empresaTel)): ?>
                <p class="text-sm text-gray-600 mt-1"><i class="fa-solid fa-phone mr-1"></i> <?php echo htmlspecialchars($empresaTel); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="mt-6 md:mt-0 md:ml-8 text-center border-2 border-gray-800 rounded-xl p-4 w-72 shrink-0">
                <p class="font-bold text-lg">RUC: <?php echo htmlspecialchars($empresaRuc); ?></p>
                <div class="bg-gray-800 text-white font-bold py-2 my-2 text-lg tracking-widest uppercase">
                    <?php echo htmlspecialchars($comprobante['tipo_comprobante']); ?>
                </div>
                <p class="font-bold text-xl text-red-600 tracking-wider"><?php echo htmlspecialchars($numero); ?></p>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2 text-sm border p-4 rounded-lg">
            <div class="col-span-2 md:col-span-1">
                <span class="font-semibold text-gray-600">Señor(es):</span> 
                <span class="font-medium text-gray-900 ml-1"><?php echo htmlspecialchars($comprobante['cliente_razon_social']); ?></span>
            </div>
            <div>
                <span class="font-semibold text-gray-600">Fecha de Emisión:</span> 
                <span class="font-medium text-gray-900 ml-1"><?php echo date('d/m/Y', strtotime($comprobante['fecha_emision'])); ?></span>
            </div>
            <div class="col-span-2 md:col-span-1">
                <span class="font-semibold text-gray-600">RUC/DNI:</span> 
                <span class="font-medium text-gray-900 ml-1"><?php echo htmlspecialchars($comprobante['cliente_documento']); ?></span>
            </div>
            <div>
                <span class="font-semibold text-gray-600">Moneda:</span> 
                <span class="font-medium text-gray-900 ml-1"><?php echo htmlspecialchars($nombreMoneda); ?></span>
            </div>
            <div class="col-span-2">
                <span class="font-semibold text-gray-600">Dirección:</span> 
                <span class="font-medium text-gray-900 ml-1"><?php echo htmlspecialchars($comprobante['cliente_direccion']); ?></span>
            </div>
        </div>

        <div class="mb-8">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-100 text-gray-700 text-sm uppercase">
                        <th class="py-3 px-4 border">Cant.</th>
                        <th class="py-3 px-4 border">Descripción</th>
                        <th class="py-3 px-4 border text-right">V. Unitario</th>
                        <th class="py-3 px-4 border text-right">Importe</th>
                    </tr>
                </thead>
                <tbody class="text-sm">
                    <?php foreach ($lineas as $l): ?>
                    <tr>
                        <td class="py-3 px-4 border text-center"><?php echo (float)$l['cantidad']; ?></td>
                        <td class="py-3 px-4 border"><?php echo htmlspecialchars($l['descripcion']); ?></td>
                        <td class="py-3 px-4 border text-right"><?php echo $simboloMon . ' ' . number_format($l['precio_unitario'], 2); ?></td>
                        <td class="py-3 px-4 border text-right"><?php echo $simboloMon . ' ' . number_format($l['total_linea'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flex flex-col md:flex-row justify-between items-end gap-8 mb-8">
            <div class="flex-1 w-full text-xs text-gray-600">
                <div class="flex gap-4 items-center mt-6">
                    <div class="w-24 h-24 bg-gray-50 border border-gray-300 rounded-lg flex items-center justify-center p-2">
                        <i class="fa-solid fa-qrcode text-5xl text-gray-700"></i>
                    </div>
                    <div>
                        <p>Representación impresa de la <strong><?php echo htmlspecialchars($comprobante['tipo_comprobante']); ?></strong>.</p>
                        <?php if (!empty($comprobante['sunat_hash_cpe'])): ?>
                        <p class="font-mono text-[11px] text-gray-500 mt-0.5">Resumen Hash: <?php echo htmlspecialchars($comprobante['sunat_hash_cpe']); ?></p>
                        <?php endif; ?>
                        <p class="mt-0.5">Emitido electrónicamente por <strong><?php echo htmlspecialchars($empresaRazon); ?></strong>.</p>
                    </div>
                </div>
            </div>

            <div class="w-full md:w-64">
                <table class="w-full text-sm text-right">
                    <tr>
                        <td class="py-1 text-gray-600">Op. Gravadas:</td>
                        <td class="py-1 font-medium"><?php echo $simboloMon . ' ' . number_format($comprobante['subtotal'], 2); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 text-gray-600">IGV (18%):</td>
                        <td class="py-1 font-medium"><?php echo $simboloMon . ' ' . number_format($comprobante['igv'], 2); ?></td>
                    </tr>
                    <tr class="border-t-2 border-gray-800 text-lg">
                        <td class="py-2 font-bold uppercase text-gray-800">Total a Pagar:</td>
                        <td class="py-2 font-bold text-blue-600"><?php echo $simboloMon . ' ' . number_format($comprobante['total'], 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
