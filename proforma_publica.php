<?php
/**
 * proforma_publica.php
 * Vista pública moderna, ejecutiva y profesional de Proforma / Cotización Comercial.
 * Diseñada para máxima fidelidad en pantalla y 100% optimizada para impresión / exportación a PDF (A4).
 */
require_once 'includes/auth.php';

$token = trim($_GET['token'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$proforma = null;

try {
    if (!empty($token)) {
        $stmt = $conn->prepare("SELECT * FROM proformas WHERE token_publico = ?");
        $stmt->execute([$token]);
        $proforma = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($id) && isLoggedIn()) {
        $stmt = $conn->prepare("SELECT * FROM proformas WHERE id = ?");
        $stmt->execute([$id]);
        $proforma = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $proforma = null;
}

if (!$proforma) {
    http_response_code(404);
    $brand = getBrandingConfig();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cotización no encontrada • <?php echo htmlspecialchars($brand['empresa_razon_social']); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans text-slate-800">
        <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-xl border border-slate-200 text-center">
            <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fa-solid fa-file-circle-xmark"></i>
            </div>
            <h2 class="text-xl font-extrabold text-slate-900 mb-2">Cotización no encontrada</h2>
            <p class="text-sm text-slate-500 mb-6">El enlace proporcionado no es válido o la proforma ha sido retirada.</p>
            <?php if (isLoggedIn()): ?>
                <a href="proformas.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-all shadow-sm">
                    <i class="fa-solid fa-arrow-left"></i> Volver a Proformas
                </a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Cargar líneas de la proforma
$lineas = [];
try {
    $stmtLineas = $conn->prepare("SELECT * FROM proforma_lineas WHERE proforma_id = ? ORDER BY id ASC");
    $stmtLineas->execute([$proforma['id']]);
    $lineas = $stmtLineas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lineas = [];
}

// Configuración de Marca y Empresa
$brand = getBrandingConfig();
$empresaRuc          = $brand['empresa_ruc'] ?: '20000000001';
$empresaRazon        = $brand['empresa_razon_social'] ?: 'ROMA AGENCIA';
$empresaComercial    = $brand['empresa_nombre_comercial'] ?: $empresaRazon;
$empresaDir          = $brand['empresa_direccion'] ?: 'Lima, Perú';
$empresaTel          = $brand['empresa_telefono'] ?: '';
$empresaCorreo       = $brand['empresa_correo'] ?: 'contacto@miempresa.com';
$empresaWeb          = $brand['empresa_web'] ?: '';
$logoDoc             = $brand['logo_documento_efectivo'] ?: $brand['sistema_logo'];

$monedaCodigo = strtoupper($proforma['moneda'] ?? 'PEN');
$simboloMon   = ($monedaCodigo === 'USD') ? '$' : 'S/';
$nombreMoneda = ($monedaCodigo === 'USD') ? 'Dólares Americanos (USD)' : 'Soles Peruanos (PEN)';

$numeroCot    = $proforma['numero'] ?: 'COT-00000';
$fechaEmision = $proforma['fecha_emision'] ? date('d/m/Y', strtotime($proforma['fecha_emision'])) : date('d/m/Y');

// Cálculo de fecha de validez
$validezTexto = trim($proforma['validez_dias'] ?: '15 Días');
preg_match('/\d+/', $validezTexto, $matchesDias);
$diasValidez = !empty($matchesDias[0]) ? (int)$matchesDias[0] : 15;
$fechaVence = date('d/m/Y', strtotime($proforma['fecha_emision'] . " + $diasValidez days"));

$estado = $proforma['estado'] ?: 'Pendiente';
$badgeColor = match($estado) {
    'Aceptada', 'Convertida' => 'bg-emerald-50 text-emerald-700 border-emerald-300 dark:bg-emerald-500/15 dark:text-emerald-400',
    'Rechazada'              => 'bg-rose-50 text-rose-700 border-rose-300 dark:bg-rose-500/15 dark:text-rose-400',
    default                  => 'bg-amber-50 text-amber-700 border-amber-300 dark:bg-amber-500/15 dark:text-amber-400'
};

// WhatsApp para contacto
$waPhone = preg_replace('/\D/', '', $empresaTel);
if (strlen($waPhone) === 9 && str_starts_with($waPhone, '9')) {
    $waPhone = '51' . $waPhone;
}
$waMsg = rawurlencode("Hola $empresaComercial, me pongo en contacto respecto a la cotización $numeroCot por el monto de $simboloMon " . number_format($proforma['total'], 2) . ".");
$waUrl = !empty($waPhone) ? "https://wa.me/{$waPhone}?text={$waMsg}" : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotización <?php echo htmlspecialchars($numeroCot); ?> • <?php echo htmlspecialchars($empresaComercial); ?></title>
    <?php echo renderHeadBranding($brand); ?>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* Exact color adjust for screen and print */
        *, *::before, *::after {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }

        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }

        @media print {
            html, body {
                background: #ffffff !important;
                color: #0f172a !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                font-size: 11px !important;
                -webkit-font-smoothing: antialiased;
            }

            .no-print {
                display: none !important;
            }

            .proforma-sheet {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 auto !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .proforma-top-accent {
                display: block !important;
                height: 3px !important;
                background: #4f46e5 !important;
            }

            .proforma-header {
                display: flex !important;
                flex-direction: row !important;
                justify-content: space-between !important;
                align-items: flex-start !important;
                gap: 16px !important;
                padding-bottom: 12px !important;
                margin-bottom: 10px !important;
                border-bottom: 1px solid #e2e8f0 !important;
            }

            .proforma-logo {
                max-height: 48px !important;
                max-width: 170px !important;
                margin-bottom: 6px !important;
            }

            .proforma-ruc-box {
                display: block !important;
                width: 240px !important;
                min-width: 240px !important;
                max-width: 240px !important;
                border: 2px solid #0f172a !important;
                border-radius: 10px !important;
                padding: 6px 10px !important;
                text-align: center !important;
                background-color: #f8fafc !important;
            }

            .proforma-ruc-banner {
                background-color: #0f172a !important;
                color: #ffffff !important;
                border-radius: 6px !important;
                padding: 3px 0 !important;
                margin: 4px 0 !important;
            }

            .proforma-grid-client {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 10px !important;
                margin: 8px 0 !important;
            }

            .proforma-card-info {
                background-color: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
                padding: 8px 10px !important;
            }

            .proforma-table-wrap {
                margin: 8px 0 !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 8px !important;
                overflow: hidden !important;
            }

            .proforma-table {
                width: 100% !important;
                border-collapse: collapse !important;
            }

            .proforma-table thead tr {
                background-color: #f1f5f9 !important;
                color: #1e293b !important;
                border-bottom: 1px solid #cbd5e1 !important;
            }

            .proforma-table th,
            .proforma-table td {
                padding: 5px 8px !important;
                font-size: 9.5px !important;
                line-height: 1.35 !important;
            }

            .proforma-table tbody tr {
                border-bottom: 1px solid #f1f5f9 !important;
            }

            .proforma-totals-wrap {
                display: flex !important;
                flex-direction: row !important;
                justify-content: space-between !important;
                align-items: flex-start !important;
                gap: 14px !important;
                margin: 8px 0 !important;
            }

            .proforma-terms-box {
                background-color: #fffbeb !important;
                border: 1px solid #fde68a !important;
                border-radius: 8px !important;
                padding: 6px 10px !important;
                color: #92400e !important;
            }

            .proforma-totals-card {
                width: 250px !important;
                min-width: 250px !important;
                max-width: 250px !important;
                background-color: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
                padding: 8px 12px !important;
            }

            .proforma-footer {
                display: flex !important;
                flex-direction: row !important;
                justify-content: space-between !important;
                align-items: center !important;
                padding-top: 8px !important;
                margin-top: 8px !important;
                border-top: 1px solid #e2e8f0 !important;
            }

            /* Evitar saltos de página indebidos */
            .proforma-header,
            .proforma-grid-client,
            .proforma-totals-wrap,
            .proforma-footer,
            tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 font-sans text-slate-800 min-h-screen py-4 sm:py-8 px-2 sm:px-4 antialiased selection:bg-brand-500 selection:text-white">

    <!-- BARRA SUPERIOR DE ACCIONES (Sólo visible en pantalla) -->
    <div class="max-w-4xl mx-auto mb-5 no-print">
        <div class="bg-white/90 backdrop-blur-md border border-slate-200/90 rounded-2xl p-3 sm:p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2.5 w-full sm:w-auto">
                <?php if (isLoggedIn()): ?>
                <a href="proformas.php" class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center text-xs transition-colors shrink-0" title="Volver al panel">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <?php endif; ?>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs sm:text-sm font-extrabold text-slate-900"><?php echo htmlspecialchars($numeroCot); ?></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $badgeColor; ?>">
                            <?php echo htmlspecialchars($estado); ?>
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-400">Vista pública oficial para cliente</p>
                </div>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-end flex-wrap">
                <!-- Botón Copiar Enlace -->
                <button type="button" onclick="copiarEnlacePublico()" id="btnCopiarLink" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all inline-flex items-center gap-1.5 shadow-2xs">
                    <i class="fa-solid fa-link text-slate-500"></i>
                    <span>Copiar Enlace</span>
                </button>

                <!-- Botón WhatsApp -->
                <?php if (!empty($waUrl)): ?>
                <a href="<?php echo $waUrl; ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold transition-all inline-flex items-center gap-1.5 shadow-xs shadow-emerald-500/20">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>Contactar</span>
                </a>
                <?php endif; ?>

                <!-- Botón Imprimir / PDF -->
                <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold transition-all inline-flex items-center gap-2 shadow-xs shadow-brand-600/30">
                    <i class="fa-solid fa-print"></i>
                    <span>Imprimir / PDF</span>
                </button>
            </div>
        </div>
    </div>

    <!-- DOCUMENTO PRINCIPAL (HOJA CORPORATIVA DE COTIZACIÓN) -->
    <main class="max-w-4xl mx-auto bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-200/90 p-6 sm:p-10 lg:p-12 proforma-sheet relative overflow-hidden">
        
        <!-- Acento estético superior -->
        <div class="proforma-top-accent absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-brand-600 via-indigo-500 to-emerald-500"></div>

        <!-- CABECERA: LOGO + DATOS DE LA EMPRESA + RECUADRO OFICIAL -->
        <header class="proforma-header flex flex-col sm:flex-row justify-between items-start gap-4 pb-6 border-b border-slate-100">
            <!-- Logo y Datos de la Empresa -->
            <div class="flex-1 min-w-0">
                <?php if (!empty($logoDoc) && file_exists(__DIR__ . '/' . $logoDoc)): ?>
                    <img src="<?php echo htmlspecialchars($logoDoc); ?>" alt="<?php echo htmlspecialchars($empresaComercial); ?>" class="proforma-logo max-h-16 sm:max-h-20 max-w-[240px] object-contain mb-3">
                <?php else: ?>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-brand-600 text-white flex items-center justify-center text-lg font-black shadow-md shadow-brand-500/30">
                            <i class="fa-solid fa-file-signature"></i>
                        </div>
                        <h1 class="text-xl font-extrabold text-slate-900 tracking-tight"><?php echo htmlspecialchars($empresaComercial); ?></h1>
                    </div>
                <?php endif; ?>

                <h2 class="text-sm font-extrabold text-slate-900 tracking-tight"><?php echo htmlspecialchars($empresaRazon); ?></h2>
                <p class="text-xs text-slate-500 mt-0.5 leading-relaxed"><?php echo htmlspecialchars($empresaDir); ?></p>
                
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-slate-500 font-medium">
                    <?php if (!empty($empresaTel)): ?>
                    <span class="inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-phone text-slate-400 text-[10px]"></i> <?php echo htmlspecialchars($empresaTel); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($empresaCorreo)): ?>
                    <span class="inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-envelope text-slate-400 text-[10px]"></i> <?php echo htmlspecialchars($empresaCorreo); ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($empresaWeb)): ?>
                    <span class="inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-globe text-slate-400 text-[10px]"></i> <?php echo htmlspecialchars($empresaWeb); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recuadro Oficial de Proforma -->
            <div class="proforma-ruc-box w-full sm:w-72 md:w-80 border-2 border-slate-900 rounded-2xl p-4 text-center bg-slate-50/50 shadow-sm shrink-0">
                <p class="text-xs font-mono font-bold tracking-wider text-slate-600 uppercase">RUC N° <?php echo htmlspecialchars($empresaRuc); ?></p>
                <div class="proforma-ruc-banner my-2 py-2 bg-slate-900 text-white rounded-xl">
                    <span class="text-xs sm:text-sm font-black tracking-widest uppercase">COTIZACIÓN / PROFORMA</span>
                </div>
                <p class="font-mono text-lg font-black text-brand-600 tracking-wider"><?php echo htmlspecialchars($numeroCot); ?></p>
            </div>
        </header>

        <!-- SECCIÓN: DATOS DEL CLIENTE Y CONDICIONES COMERCIALES -->
        <section class="proforma-grid-client grid grid-cols-1 sm:grid-cols-2 gap-4 my-6">
            <!-- Destinatario / Cliente -->
            <div class="proforma-card-info bg-slate-50/80 border border-slate-200/80 rounded-2xl p-4 sm:p-5">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-2">Preparado Para</span>
                <p class="text-sm font-extrabold text-slate-900 mb-1"><?php echo htmlspecialchars($proforma['cliente_razon_social']); ?></p>
                <div class="flex items-center gap-2 mb-1.5 text-xs text-slate-600">
                    <span class="font-bold text-slate-500">RUC / DNI:</span>
                    <span class="font-mono font-semibold bg-white border border-slate-200 px-2 py-0.5 rounded-md text-[11px] text-slate-800">
                        <?php echo htmlspecialchars($proforma['cliente_documento']); ?>
                    </span>
                </div>
                <?php if (!empty($proforma['cliente_direccion']) && $proforma['cliente_direccion'] !== '-'): ?>
                <div class="text-xs text-slate-600">
                    <span class="font-bold text-slate-500">Dirección:</span>
                    <span><?php echo htmlspecialchars($proforma['cliente_direccion']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Parámetros de la Cotización -->
            <div class="proforma-card-info bg-slate-50/80 border border-slate-200/80 rounded-2xl p-4 sm:p-5 flex flex-col justify-between">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-2">Condiciones Comerciales</span>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <span class="text-slate-400 block text-[11px]">Fecha de Emisión:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($fechaEmision); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[11px]">Validez de Oferta:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($validezTexto); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[11px]">Vigencia Hasta:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($fechaVence); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[11px]">Moneda:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($nombreMoneda); ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- TABLA DE DETALLE DE PRODUCTOS / SERVICIOS -->
        <section class="proforma-table-wrap my-6 overflow-hidden rounded-2xl border border-slate-200">
            <table class="proforma-table w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-100 text-slate-600 uppercase text-[10px] tracking-wider font-extrabold border-b border-slate-200">
                        <th class="py-3 px-3.5 w-12 text-center">Ítem</th>
                        <th class="py-3 px-3.5 w-24 text-center">Cantidad</th>
                        <th class="py-3 px-3.5">Descripción del Concepto</th>
                        <th class="py-3 px-3.5 w-28 text-right">P. Unitario</th>
                        <th class="py-3 px-3.5 w-32 text-right">Importe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($lineas)): ?>
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-400 text-xs">
                                No se encontraron ítems registrados en esta proforma.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($lineas as $idx => $lin): ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-3.5 text-center font-mono text-slate-400 text-[11px]"><?php echo $idx + 1; ?></td>
                            <td class="py-3 px-3.5 text-center font-mono font-bold text-slate-800">
                                <?php echo (float)$lin['cantidad']; ?>
                            </td>
                            <td class="py-3 px-3.5 text-slate-900 font-semibold leading-relaxed">
                                <?php echo htmlspecialchars($lin['descripcion']); ?>
                            </td>
                            <td class="py-3 px-3.5 text-right font-mono text-slate-700">
                                <?php echo $simboloMon; ?> <?php echo number_format((float)$lin['precio_unitario'], 2); ?>
                            </td>
                            <td class="py-3 px-3.5 text-right font-mono font-bold text-slate-900">
                                <?php echo $simboloMon; ?> <?php echo number_format((float)$lin['total_linea'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- SECCIÓN DE TOTALES Y NOTAS COMERCIALES -->
        <section class="proforma-totals-wrap flex flex-col sm:flex-row justify-between items-start gap-6 my-6">
            <!-- Notas Comerciales y Aprobación -->
            <div class="flex-1 w-full text-xs text-slate-600 space-y-3">
                <div class="proforma-terms-box bg-amber-50/70 border border-amber-200/80 rounded-xl p-3.5 text-amber-900">
                    <p class="font-bold flex items-center gap-1.5 mb-1 text-[11px]">
                        <i class="fa-solid fa-circle-info text-amber-600"></i> Términos y Condiciones Comerciales:
                    </p>
                    <ul class="list-disc list-inside space-y-0.5 text-[11px] text-amber-800/90 ml-1">
                        <li>Precios expresados en <strong><?php echo htmlspecialchars($nombreMoneda); ?></strong> e incluyen el I.G.V. (18%).</li>
                        <li>Cotización con vigencia de <strong><?php echo htmlspecialchars($validezTexto); ?></strong> desde la fecha de emisión.</li>
                        <li>Disponibilidad de productos sujeta a confirmación de pedido o stock.</li>
                        <li>Para coordinar la aprobación o emisión de Comprobante Electrónico, contáctenos directamente.</li>
                    </ul>
                </div>

                <p class="text-[11px] text-slate-400">
                    Documento informativo de carácter comercial sin valor tributario. Para efectos fiscales se emitirá la correspondiente Factura o Boleta Electrónica autorizada por SUNAT.
                </p>
            </div>

            <!-- Resumen Financiero -->
            <div class="proforma-totals-card w-full sm:w-72 md:w-80 bg-slate-50/80 border border-slate-200/90 rounded-2xl p-5 shadow-2xs shrink-0">
                <table class="w-full text-xs">
                    <tbody>
                        <tr>
                            <td class="py-1.5 text-slate-500 font-semibold">Subtotal / Op. Gravada:</td>
                            <td class="py-1.5 text-right font-mono font-bold text-slate-800">
                                <?php echo $simboloMon; ?> <?php echo number_format((float)$proforma['subtotal'], 2); ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-slate-500 font-semibold">I.G.V. (18%):</td>
                            <td class="py-1.5 text-right font-mono font-bold text-slate-800">
                                <?php echo $simboloMon; ?> <?php echo number_format((float)$proforma['igv'], 2); ?>
                            </td>
                        </tr>
                        <tr class="border-t-2 border-slate-900 pt-3">
                            <td class="py-3 text-sm font-black text-slate-900 uppercase">Total Cotizado:</td>
                            <td class="py-3 text-right font-mono text-base font-black text-brand-600">
                                <?php echo $simboloMon; ?> <?php echo number_format((float)$proforma['total'], 2); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- PIE DE FIRMA Y DATOS DE VERIFICACIÓN -->
        <footer class="proforma-footer pt-6 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-500 text-sm shrink-0">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <p class="font-bold text-slate-700">Cotización Verificada Digitalmente</p>
                    <p class="text-[11px]">Emitida por el sistema comercial de <?php echo htmlspecialchars($empresaRazon); ?></p>
                </div>
            </div>
            <div class="text-center sm:text-right font-mono text-[11px] text-slate-400">
                <span><?php echo htmlspecialchars($numeroCot); ?></span> • <span><?php echo htmlspecialchars($fechaEmision); ?></span>
            </div>
        </footer>

    </main>

    <!-- TOAST NOTIFICACIÓN -->
    <div id="toastNotification" class="fixed bottom-5 right-5 z-50 bg-slate-900 text-white px-4 py-2.5 rounded-xl shadow-2xl text-xs font-bold flex items-center gap-2 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none">
        <i class="fa-solid fa-circle-check text-emerald-400"></i>
        <span id="toastMessage">¡Enlace copiado al portapapeles!</span>
    </div>

    <script>
        function copiarEnlacePublico() {
            const url = window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {
                    mostrarToast('¡Enlace de la cotización copiado al portapapeles!');
                });
            } else {
                const temp = document.createElement('input');
                temp.value = url;
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                document.body.removeChild(temp);
                mostrarToast('¡Enlace copiado al portapapeles!');
            }
        }

        function mostrarToast(msg) {
            const toast = document.getElementById('toastNotification');
            const msgEl = document.getElementById('toastMessage');
            if (!toast || !msgEl) return;
            msgEl.textContent = msg;
            toast.classList.remove('translate-y-20', 'opacity-0');
            setTimeout(() => {
                toast.classList.add('translate-y-20', 'opacity-0');
            }, 3000);
        }
    </script>
</body>
</html>
