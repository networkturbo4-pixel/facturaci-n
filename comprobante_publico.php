<?php
/**
 * comprobante_publico.php
 * Vista pública moderna, ejecutiva y profesional de Facturas y Boletas de Venta Electrónicas.
 * Incluye Código QR Oficial SUNAT en formato Vector SVG, código HASH, leyenda legal y estilos optimizados para impresión (A4).
 */
require_once 'includes/auth.php';
require_once 'vendor/autoload.php';

use Greenter\Report\Render\QrRender;

$token = trim($_GET['token'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$comprobante = null;

try {
    if (!empty($token)) {
        $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE token_publico = ?");
        $stmt->execute([$token]);
        $comprobante = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($id) && isLoggedIn()) {
        $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE id = ?");
        $stmt->execute([$id]);
        $comprobante = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $comprobante = null;
}

if (!$comprobante) {
    http_response_code(404);
    $brand = getBrandingConfig();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Comprobante no encontrado • <?php echo htmlspecialchars($brand['empresa_razon_social']); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </head>
    <body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans text-slate-800">
        <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-xl border border-slate-200 text-center">
            <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 text-2xl">
                <i class="fa-solid fa-file-circle-xmark"></i>
            </div>
            <h2 class="text-xl font-extrabold text-slate-900 mb-2">Comprobante no encontrado</h2>
            <p class="text-sm text-slate-500 mb-6">El enlace proporcionado no es válido o el comprobante no existe en el sistema.</p>
            <?php if (isLoggedIn()): ?>
                <a href="comprobantes.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold transition-all shadow-sm">
                    <i class="fa-solid fa-arrow-left"></i> Volver a Comprobantes
                </a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Asegurar token público si no lo tiene
if (empty($comprobante['token_publico'])) {
    $newToken = bin2hex(random_bytes(16));
    try {
        $upd = $conn->prepare("UPDATE comprobantes SET token_publico = ? WHERE id = ?");
        $upd->execute([$newToken, $comprobante['id']]);
        $comprobante['token_publico'] = $newToken;
    } catch (Exception $e) {}
}

// Cargar líneas del comprobante
$lineas = [];
try {
    $stmt_lineas = $conn->prepare("SELECT * FROM comprobante_lineas WHERE comprobante_id = ? ORDER BY id ASC");
    $stmt_lineas->execute([$comprobante['id']]);
    $lineas = $stmt_lineas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lineas = [];
}

// Configuración de Empresa y Marca
$brand = getBrandingConfig();
$empresaRuc       = $brand['empresa_ruc'] ?: '20000000001';
$empresaRazon     = $brand['empresa_razon_social'] ?: 'ROMA AGENCIA';
$empresaComercial = $brand['empresa_nombre_comercial'] ?: $empresaRazon;
$empresaDir       = $brand['empresa_direccion'] ?: 'Lima, Perú';
$empresaTel       = $brand['empresa_telefono'] ?: '';
$empresaCorreo    = $brand['empresa_correo'] ?: 'contacto@miempresa.com';
$empresaWeb       = $brand['empresa_web'] ?: '';
$logoDoc          = $brand['logo_documento_efectivo'] ?: $brand['sistema_logo'];

// Tipo de Comprobante
$tipoCompRaw = trim($comprobante['tipo_comprobante'] ?? '');
$serieRaw = trim($comprobante['serie'] ?? '');
$isFactura = (stripos($tipoCompRaw, 'factura') !== false || str_starts_with($serieRaw, 'F'));

$nombreTipoDocumento = $isFactura ? 'FACTURA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA';
$tipoDocSunat = $isFactura ? '01' : '03';

// Número oficial
$correlativoStr = str_pad((string)$comprobante['correlativo'], 6, '0', STR_PAD_LEFT);
$numero = $comprobante['serie'] . '-' . $correlativoStr;

// Moneda y Símbolos
$monedaCodigo = strtoupper($comprobante['moneda'] ?? 'PEN');
$simboloMon   = ($monedaCodigo === 'USD') ? '$' : 'S/';
$nombreMoneda = ($monedaCodigo === 'USD') ? 'Dólares Americanos (USD)' : 'Soles (PEN)';

// Fechas
$fechaEmision = $comprobante['fecha_emision'] ? date('d/m/Y', strtotime($comprobante['fecha_emision'])) : date('d/m/Y');

// Función de conversión de Monto a Letras en Español
if (!function_exists('comprobanteMontoALetras')) {
    function comprobanteMontoALetras(float $monto, string $moneda = 'PEN'): string {
        $enteros = (int)floor($monto);
        $centavos = (int)round(($monto - $enteros) * 100);
        $centavosStr = str_pad((string)$centavos, 2, '0', STR_PAD_LEFT);
        $nombreMoneda = ($moneda === 'USD') ? 'DÓLARES AMERICANOS' : 'SOLES';

        $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $dieces = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        if ($enteros === 0) {
            $letras = 'CERO';
        } elseif ($enteros === 100) {
            $letras = 'CIEN';
        } else {
            $convertirGrupo = function($n) use ($unidades, $decenas, $dieces, $centenas) {
                $c = (int)floor($n / 100);
                $d = (int)floor(($n % 100) / 10);
                $u = $n % 10;
                $res = [];

                if ($n === 100) return 'CIEN';
                if ($c > 0) $res[] = $centenas[$c];

                if ($d === 1) {
                    $res[] = $dieces[$u];
                } elseif ($d === 2) {
                    if ($u === 0) $res[] = 'VEINTE';
                    else $res[] = 'VEINTI' . $unidades[$u];
                } elseif ($d > 2) {
                    if ($u === 0) $res[] = $decenas[$d];
                    else $res[] = $decenas[$d] . ' Y ' . $unidades[$u];
                } elseif ($u > 0) {
                    $res[] = $unidades[$u];
                }
                return implode(' ', $res);
            };

            $millones = (int)floor($enteros / 1000000);
            $restoMillones = $enteros % 1000000;
            $miles = (int)floor($restoMillones / 1000);
            $restoMiles = $restoMillones % 1000;

            $partes = [];
            if ($millones > 0) {
                if ($millones === 1) $partes[] = 'UN MILLÓN';
                else $partes[] = $convertirGrupo($millones) . ' MILLONES';
            }
            if ($miles > 0) {
                if ($miles === 1) $partes[] = 'MIL';
                else $partes[] = $convertirGrupo($miles) . ' MIL';
            }
            if ($restoMiles > 0) {
                $partes[] = $convertirGrupo($restoMiles);
            }
            $letras = implode(' ', array_filter($partes));
        }

        return "SON: $letras Y $centavosStr/100 $nombreMoneda";
    }
}

$montoEnLetras = comprobanteMontoALetras((float)$comprobante['total'], $monedaCodigo);

// Generación Oficial del Código QR SUNAT (Vector SVG)
$qrContent = trim($comprobante['sunat_qr'] ?? '');
if (empty($qrContent)) {
    $docCliente = trim($comprobante['cliente_documento'] ?? '');
    $docLen = strlen($docCliente);
    $clienteTipoDocSunat = ($docLen === 11) ? '6' : (($docLen === 8) ? '1' : '-');
    $igvFmt = number_format((float)$comprobante['igv'], 2, '.', '');
    $totalFmt = number_format((float)$comprobante['total'], 2, '.', '');
    $fechaFmt = date('d/m/Y', strtotime($comprobante['fecha_emision']));
    $hashFmt = trim($comprobante['sunat_hash_cpe'] ?? '');

    $qrContent = "{$empresaRuc} | {$tipoDocSunat} | {$comprobante['serie']} | {$correlativoStr} | {$igvFmt} | {$totalFmt} | {$fechaFmt} | {$clienteTipoDocSunat} | {$docCliente} | {$hashFmt} |";
}

$qrSvg = '';
try {
    $renderer = new QrRender();
    $rawSvg = $renderer->getQrUrl($qrContent);
    // Eliminar cabecera XML inicial para incrustacion HTML5 limpia
    $qrSvg = trim(preg_replace('/<\?xml[^>]*\?>/i', '', $rawSvg));
} catch (\Throwable $t) {
    $qrSvg = '';
}

// Estado del comprobante
$cdrEstado = $comprobante['sunat_cdr_estado'] ?: $comprobante['estado'];
$esAceptado = in_array(strtoupper($cdrEstado), ['ACEPTADO', 'EMITIDO']);
$badgeEstado = $esAceptado
    ? 'bg-emerald-50 text-emerald-700 border-emerald-300'
    : 'bg-amber-50 text-amber-700 border-amber-300';
$badgeTexto = $esAceptado ? 'Aceptado por SUNAT' : ($cdrEstado ?: 'Pendiente SUNAT');

// Enlaces XML / CDR si existen
$linkXml = !empty($comprobante['sunat_enlace_xml']) ? $comprobante['sunat_enlace_xml'] : '';
$linkCdr = !empty($comprobante['sunat_enlace_cdr']) ? $comprobante['sunat_enlace_cdr'] : '';

// WhatsApp de contacto o compartir
$waPhone = preg_replace('/\D/', '', $empresaTel);
if (strlen($waPhone) === 9 && str_starts_with($waPhone, '9')) {
    $waPhone = '51' . $waPhone;
}
$urlActual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
$waMsg = rawurlencode("Estimado(a) {$comprobante['cliente_razon_social']}, le compartimos su {$nombreTipoDocumento} {$numero} por el monto de {$simboloMon} " . number_format($comprobante['total'], 2) . ".\nPuede consultarla aquí: {$urlActual}");
$waShareUrl = "https://api.whatsapp.com/send?text={$waMsg}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($nombreTipoDocumento); ?> <?php echo htmlspecialchars($numero); ?> • <?php echo htmlspecialchars($empresaComercial); ?></title>
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
        /* Exact color adjust para impresión y pantalla */
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

            .doc-sheet {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 auto !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .doc-top-accent {
                display: block !important;
                height: 3px !important;
                background: #0f172a !important;
            }

            .doc-header {
                display: flex !important;
                flex-direction: row !important;
                justify-content: space-between !important;
                align-items: flex-start !important;
                gap: 16px !important;
                padding-bottom: 12px !important;
                margin-bottom: 10px !important;
                border-bottom: 1px solid #e2e8f0 !important;
            }

            .doc-logo {
                max-height: 48px !important;
                max-width: 170px !important;
                margin-bottom: 6px !important;
            }

            .doc-ruc-box {
                display: block !important;
                width: 250px !important;
                min-width: 250px !important;
                max-width: 250px !important;
                border: 2px solid #0f172a !important;
                border-radius: 10px !important;
                padding: 6px 10px !important;
                text-align: center !important;
                background-color: #f8fafc !important;
            }

            .doc-ruc-banner {
                background-color: #0f172a !important;
                color: #ffffff !important;
                border-radius: 6px !important;
                padding: 4px 0 !important;
                margin: 4px 0 !important;
            }

            .doc-grid-client {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 10px !important;
                margin: 8px 0 !important;
            }

            .doc-card-info {
                background-color: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
                padding: 8px 10px !important;
            }

            .doc-table-wrap {
                margin: 8px 0 !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 8px !important;
                overflow: hidden !important;
            }

            .doc-table {
                width: 100% !important;
                border-collapse: collapse !important;
            }

            .doc-table thead tr {
                background-color: #f1f5f9 !important;
                color: #1e293b !important;
                border-bottom: 1px solid #cbd5e1 !important;
            }

            .doc-table th,
            .doc-table td {
                padding: 5px 8px !important;
                font-size: 9.5px !important;
                line-height: 1.35 !important;
            }

            .doc-table tbody tr {
                border-bottom: 1px solid #f1f5f9 !important;
            }

            .doc-bottom-wrap {
                display: flex !important;
                flex-direction: row !important;
                justify-content: space-between !important;
                align-items: flex-start !important;
                gap: 14px !important;
                margin: 8px 0 !important;
            }

            .doc-validation-box {
                flex: 1 !important;
                background-color: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
                padding: 8px 10px !important;
            }

            .doc-totals-box {
                width: 250px !important;
                min-width: 250px !important;
                max-width: 250px !important;
                background-color: #f8fafc !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px !important;
                padding: 8px 12px !important;
            }

            .doc-footer {
                display: flex !important;
                flex-direction: row !important;
                justify-content: space-between !important;
                align-items: center !important;
                padding-top: 8px !important;
                margin-top: 8px !important;
                border-top: 1px solid #e2e8f0 !important;
            }

            /* Evitar saltos de página indebidos */
            .doc-header,
            .doc-grid-client,
            .doc-bottom-wrap,
            .doc-footer,
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
                <a href="comprobantes.php" class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center text-xs transition-colors shrink-0" title="Volver al panel">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <?php endif; ?>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs sm:text-sm font-extrabold text-slate-900"><?php echo htmlspecialchars($numero); ?></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $badgeEstado; ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?php echo $esAceptado ? 'bg-emerald-500' : 'bg-amber-500'; ?> mr-1.5"></span>
                            <?php echo htmlspecialchars($badgeTexto); ?>
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-400">Representación Electrónica Oficial para el Cliente</p>
                </div>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-end flex-wrap">
                <!-- Botón Copiar Enlace -->
                <button type="button" onclick="copiarEnlacePublico()" id="btnCopiarLink" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all inline-flex items-center gap-1.5 shadow-2xs">
                    <i class="fa-solid fa-link text-slate-500"></i>
                    <span id="txtBtnCopiar">Copiar Enlace</span>
                </button>

                <!-- Botón WhatsApp -->
                <a href="<?php echo $waShareUrl; ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold transition-all inline-flex items-center gap-1.5 shadow-xs shadow-emerald-500/20">
                    <i class="fa-brands fa-whatsapp text-sm"></i>
                    <span>WhatsApp</span>
                </a>

                <!-- Descargar XML (si existe) -->
                <?php if (!empty($linkXml)): ?>
                <a href="<?php echo htmlspecialchars($linkXml); ?>" target="_blank" download class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all inline-flex items-center gap-1.5" title="Descargar archivo XML firmado">
                    <i class="fa-solid fa-code text-indigo-600"></i>
                    <span>XML</span>
                </a>
                <?php endif; ?>

                <!-- Descargar CDR (si existe) -->
                <?php if (!empty($linkCdr)): ?>
                <a href="<?php echo htmlspecialchars($linkCdr); ?>" target="_blank" download class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all inline-flex items-center gap-1.5" title="Descargar Constancia de Recepción SUNAT">
                    <i class="fa-solid fa-file-zipper text-emerald-600"></i>
                    <span>CDR</span>
                </a>
                <?php endif; ?>

                <!-- Botón Imprimir / PDF -->
                <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition-all inline-flex items-center gap-2 shadow-xs shadow-slate-900/20">
                    <i class="fa-solid fa-print"></i>
                    <span>Imprimir / PDF</span>
                </button>
            </div>
        </div>
    </div>

    <!-- DOCUMENTO PRINCIPAL (HOJA CORPORATIVA OFICIAL) -->
    <main class="max-w-4xl mx-auto bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-200/90 p-6 sm:p-10 lg:p-12 doc-sheet relative overflow-hidden">
        
        <!-- Acento estético superior -->
        <div class="doc-top-accent absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-slate-900 via-indigo-600 to-emerald-600"></div>

        <!-- CABECERA: LOGO + DATOS DE LA EMPRESA + RECUADRO OFICIAL RUC -->
        <header class="doc-header flex flex-col sm:flex-row justify-between items-start gap-4 pb-6 border-b border-slate-100">
            <!-- Logo y Datos de la Empresa Emisora -->
            <div class="flex-1 min-w-0">
                <?php if (!empty($logoDoc) && file_exists(__DIR__ . '/' . $logoDoc)): ?>
                    <img src="<?php echo htmlspecialchars($logoDoc); ?>" alt="<?php echo htmlspecialchars($empresaComercial); ?>" class="doc-logo max-h-16 sm:max-h-20 max-w-[240px] object-contain mb-3">
                <?php else: ?>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center text-lg font-black shadow-md shadow-slate-900/20">
                            <i class="fa-solid fa-receipt"></i>
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

            <!-- Recuadro Oficial SUNAT (RUC, Tipo Comprobante, Serie - Correlativo) -->
            <div class="doc-ruc-box w-full sm:w-72 md:w-80 border-2 border-slate-900 rounded-2xl p-4 text-center bg-slate-50/50 shadow-sm shrink-0">
                <p class="text-xs sm:text-sm font-mono font-bold tracking-wider text-slate-700 uppercase">RUC N° <?php echo htmlspecialchars($empresaRuc); ?></p>
                <div class="doc-ruc-banner my-2 py-2 bg-slate-900 text-white rounded-xl">
                    <span class="text-xs sm:text-sm font-black tracking-widest uppercase"><?php echo htmlspecialchars($nombreTipoDocumento); ?></span>
                </div>
                <p class="font-mono text-xl sm:text-2xl font-black text-rose-600 tracking-wider"><?php echo htmlspecialchars($numero); ?></p>
            </div>
        </header>

        <!-- SECCIÓN: DATOS DEL CLIENTE Y DETALLES DE EMISIÓN -->
        <section class="doc-grid-client grid grid-cols-1 sm:grid-cols-2 gap-4 my-6">
            <!-- Datos del Cliente / Receptor -->
            <div class="doc-card-info bg-slate-50/80 border border-slate-200/80 rounded-2xl p-4 sm:p-5">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-2">Cliente / Receptor</span>
                <p class="text-sm font-extrabold text-slate-900 mb-1"><?php echo htmlspecialchars($comprobante['cliente_razon_social']); ?></p>
                <div class="flex items-center gap-2 mb-1.5 text-xs text-slate-600">
                    <span class="font-bold text-slate-500"><?php echo (strlen(trim($comprobante['cliente_documento'] ?? '')) === 11) ? 'RUC:' : 'DNI / Doc:'; ?></span>
                    <span class="font-mono font-semibold bg-white border border-slate-200 px-2 py-0.5 rounded-md text-[11px] text-slate-800">
                        <?php echo htmlspecialchars($comprobante['cliente_documento']); ?>
                    </span>
                </div>
                <?php if (!empty($comprobante['cliente_direccion']) && $comprobante['cliente_direccion'] !== '-'): ?>
                <div class="text-xs text-slate-600">
                    <span class="font-bold text-slate-500">Dirección:</span>
                    <span><?php echo htmlspecialchars($comprobante['cliente_direccion']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Detalles del Comprobante -->
            <div class="doc-card-info bg-slate-50/80 border border-slate-200/80 rounded-2xl p-4 sm:p-5 flex flex-col justify-between">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-2">Detalles del Comprobante</span>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <span class="text-slate-400 block text-[11px]">Fecha de Emisión:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($fechaEmision); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[11px]">Forma de Pago:</span>
                        <span class="font-bold text-slate-800">Contado</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[11px]">Moneda:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($nombreMoneda); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[11px]">Tipo de Operación:</span>
                        <span class="font-bold text-slate-800"><?php echo !empty($comprobante['es_exportacion']) ? 'Exportación' : 'Venta Interna'; ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- TABLA DE PRODUCTOS / SERVICIOS -->
        <div class="doc-table-wrap overflow-hidden rounded-2xl border border-slate-200 my-6">
            <table class="doc-table w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-600 text-[11px] font-bold uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-4 text-center w-12">#</th>
                        <th class="py-3 px-4 text-center w-20">Cant.</th>
                        <th class="py-3 px-4 text-center w-24">Unidad</th>
                        <th class="py-3 px-4">Descripción</th>
                        <th class="py-3 px-4 text-right w-32">V. Unitario</th>
                        <th class="py-3 px-4 text-right w-32">Importe</th>
                    </tr>
                </thead>
                <tbody class="text-xs divide-y divide-slate-100 font-medium">
                    <?php if (empty($lineas)): ?>
                    <tr>
                        <td colspan="6" class="py-6 text-center text-slate-400">Sin líneas registradas</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($lineas as $idx => $l): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="py-3 px-4 text-center text-slate-400 font-mono"><?php echo $idx + 1; ?></td>
                            <td class="py-3 px-4 text-center font-mono font-bold text-slate-800"><?php echo (float)$l['cantidad']; ?></td>
                            <td class="py-3 px-4 text-center text-slate-500 font-mono text-[11px]">NIU</td>
                            <td class="py-3 px-4 text-slate-900 font-semibold"><?php echo htmlspecialchars($l['descripcion']); ?></td>
                            <td class="py-3 px-4 text-right font-mono text-slate-700"><?php echo $simboloMon . ' ' . number_format($l['precio_unitario'], 2); ?></td>
                            <td class="py-3 px-4 text-right font-mono font-bold text-slate-900"><?php echo $simboloMon . ' ' . number_format($l['total_linea'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- SECCIÓN INFERIOR: VALIDACIÓN SUNAT + CÓDIGO QR OFICIAL + TOTALES -->
        <section class="doc-bottom-wrap flex flex-col md:flex-row justify-between items-start gap-6 my-6">
            
            <!-- Columna Izquierda: Código QR Vectorial SUNAT y Leyendas Oficiales -->
            <div class="doc-validation-box flex-1 w-full bg-slate-50/70 border border-slate-200/80 rounded-2xl p-4 sm:p-5">
                
                <!-- Monto en Letras Oficial SUNAT -->
                <div class="mb-4 pb-3 border-b border-slate-200/70">
                    <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-0.5">Importe en Letras</span>
                    <p class="text-xs font-bold text-slate-800 tracking-wide"><?php echo htmlspecialchars($montoEnLetras); ?></p>
                </div>

                <!-- Recuadro con Código QR Real SVG y Datos Técnicos SUNAT -->
                <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4">
                    <!-- Código QR Vectorial SUNAT -->
                    <div class="p-2.5 bg-white border-2 border-slate-900/10 rounded-2xl shadow-xs shrink-0 flex items-center justify-center w-28 h-28 [&>svg]:w-full [&>svg]:h-full">
                        <?php if (!empty($qrSvg)): ?>
                            <?php echo $qrSvg; ?>
                        <?php else: ?>
                            <div class="text-center p-2">
                                <i class="fa-solid fa-qrcode text-3xl text-slate-400"></i>
                                <span class="block text-[8px] text-slate-400 mt-1">QR SUNAT</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Datos Legales y Resumen Hash -->
                    <div class="flex-1 text-xs text-slate-600 space-y-1.5 text-center sm:text-left">
                        <p class="text-[11px] leading-relaxed font-medium">
                            Representación impresa de la <strong class="text-slate-800"><?php echo htmlspecialchars($nombreTipoDocumento); ?></strong>, autorizada mediante Resolución de Intendencia de SUNAT.
                        </p>
                        
                        <?php if (!empty($comprobante['sunat_hash_cpe'])): ?>
                        <div class="inline-flex items-center gap-1.5 bg-white border border-slate-200 px-2 py-1 rounded-lg text-[10px] font-mono text-slate-700">
                            <span class="text-slate-400 font-sans font-bold">Hash CPE:</span>
                            <span class="select-all font-bold"><?php echo htmlspecialchars($comprobante['sunat_hash_cpe']); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="pt-1 flex flex-wrap items-center gap-2 justify-center sm:justify-start">
                            <span class="inline-flex items-center gap-1 text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">
                                <i class="fa-solid fa-shield-halved text-[9px]"></i> Validez Oficial SUNAT
                            </span>
                            <span class="text-[10px] text-slate-400">Consulte en <span class="text-slate-600 font-semibold">sunat.gob.pe</span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Tarjeta de Liquidación y Totales -->
            <div class="doc-totals-box w-full md:w-80 bg-slate-50/80 border border-slate-200/80 rounded-2xl p-4 sm:p-5 shrink-0">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 block mb-3">Resumen de Liquidación</span>
                
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between items-center text-slate-600">
                        <span>Op. Gravadas:</span>
                        <span class="font-mono font-medium text-slate-800"><?php echo $simboloMon . ' ' . number_format($comprobante['subtotal'], 2); ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center text-slate-600">
                        <span>Op. Exoneradas / Inafectas:</span>
                        <span class="font-mono font-medium text-slate-800"><?php echo $simboloMon . ' 0.00'; ?></span>
                    </div>

                    <div class="flex justify-between items-center text-slate-600">
                        <span>I.G.V. (18%):</span>
                        <span class="font-mono font-medium text-slate-800"><?php echo $simboloMon . ' ' . number_format($comprobante['igv'], 2); ?></span>
                    </div>

                    <div class="pt-3 border-t-2 border-slate-900/10 flex justify-between items-center">
                        <span class="text-xs sm:text-sm font-extrabold text-slate-900 uppercase">Total a Pagar:</span>
                        <span class="text-lg sm:text-xl font-mono font-extrabold text-slate-900"><?php echo $simboloMon . ' ' . number_format($comprobante['total'], 2); ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- PIE DE PÁGINA DEL COMPROBANTE -->
        <footer class="doc-footer border-t border-slate-100 pt-5 mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-400">
            <div class="flex items-center gap-2 text-center sm:text-left">
                <span>Emitido electrónicamente por <strong class="text-slate-700"><?php echo htmlspecialchars($empresaRazon); ?></strong></span>
            </div>
            <div class="flex items-center gap-3">
                <span><?php echo htmlspecialchars($empresaWeb); ?></span>
                <?php if (!empty($empresaTel)): ?>
                <span>• <?php echo htmlspecialchars($empresaTel); ?></span>
                <?php endif; ?>
            </div>
        </footer>

    </main>

    <!-- SCRIPT DE INTERACCIÓN -->
    <script>
        function copiarEnlacePublico() {
            const url = window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {
                    const txt = document.getElementById('txtBtnCopiar');
                    const original = txt.textContent;
                    txt.textContent = '¡Enlace Copiado!';
                    setTimeout(() => {
                        txt.textContent = original;
                    }, 2000);
                }).catch(() => {
                    prompt('Copie el enlace de este comprobante:', url);
                });
            } else {
                prompt('Copie el enlace de este comprobante:', url);
            }
        }

        // Auto-impresión si se pasa ?print=1
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('print') === '1') {
                setTimeout(() => {
                    window.print();
                }, 350);
            }
        });
    </script>
</body>
</html>
