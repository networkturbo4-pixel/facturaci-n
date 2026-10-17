<?php
require_once 'includes/auth.php';

$token = trim($_GET['token'] ?? '');
$id = (int)($_GET['id'] ?? 0);

try {
    if (!empty($token)) {
        $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE token_publico = ? AND es_exportacion = 1");
        $stmt->execute([$token]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($id) && isLoggedIn()) {
        $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE id = ? AND es_exportacion = 1");
        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        http_response_code(403);
        die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>Acceso no autorizado</h2><p>Se requiere un enlace con token de seguridad válido para visualizar esta factura.</p></div>");
    }

    if (!$c) {
        http_response_code(404);
        die("<div style='font-family:sans-serif;text-align:center;padding:50px;'><h2>Factura de exportación no encontrada</h2></div>");
    }

    $stmt2 = $conn->prepare("SELECT cl.*, ep.codigo_partida, ep.descripcion_aduanera, ep.unidad_medida, ep.peso_bruto, ep.peso_neto
                              FROM comprobante_lineas cl
                              LEFT JOIN exportacion_partidas ep ON ep.comprobante_id = cl.comprobante_id AND ep.linea_num = cl.id
                              WHERE cl.comprobante_id = ? ORDER BY cl.id");
    $stmt2->execute([$c['id']]);
    $lineas = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Datos de la empresa desde configuración
    $cfg = getAllSystemConfig();
    $brand = getBrandingConfig();
} catch (Exception $e) {
    die("Error al cargar comprobante de exportación.");
}

$empresa_ruc    = $brand['empresa_ruc'];
$empresa_razon  = $brand['empresa_razon_social'];
$empresa_dir    = $brand['empresa_direccion'];
$empresa_tel    = $brand['empresa_telefono'];
$logo_doc       = $brand['logo_documento_efectivo'];

$numero_comp = $c['serie'] . '-' . str_pad($c['correlativo'], 6, '0', STR_PAD_LEFT);
$moneda      = $c['moneda'] ?? 'USD';
$tc          = (float)($c['tipo_cambio'] ?? 1);
$total_pen   = $c['total'] * $tc;

$cdr_estado  = $c['sunat_cdr_estado'] ?? 'Pendiente';
$cdr_color   = match($cdr_estado) {
    'Aceptado'  => '#059669',
    'Rechazado' => '#dc2626',
    default     => '#d97706'
};

require_once 'vendor/autoload.php';
$qrContent = trim($c['sunat_qr'] ?? '');
$qrSvg = '';
if (!empty($qrContent)) {
    try {
        $renderer = new \Greenter\Report\Render\QrRender();
        $rawSvg = $renderer->getQrUrl($qrContent);
        $qrSvg = trim(preg_replace('/<\?xml[^>]*\?>/i', '', $rawSvg));
    } catch (\Throwable $t) {
        $qrSvg = '';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura de Exportación <?php echo htmlspecialchars($numero_comp); ?> — <?php echo htmlspecialchars($empresa_razon); ?></title>
<?php echo renderHeadBranding($brand); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1e293b; }

        .btn-print {
            position: fixed; top: 20px; right: 20px;
            background: #2563eb; color: white; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer;
            display: flex; align-items: center; gap: 8px; font-size: 14px;
            box-shadow: 0 4px 12px rgba(37,99,235,0.35);
            transition: background 0.2s;
            z-index: 100;
        }
        .btn-print:hover { background: #1d4ed8; }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            background: white;
            box-shadow: 0 4px 32px rgba(0,0,0,0.12);
            padding: 14mm 14mm;
            position: relative;
        }

        /* Encabezado */
        .header-doc { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .empresa-info h1 { font-size: 18px; font-weight: 800; color: #1e293b; margin-bottom: 3px; }
        .empresa-info p { font-size: 10px; color: #64748b; line-height: 1.6; }

        .comp-box {
            border: 2.5px solid #1e40af; border-radius: 8px; padding: 12px 18px; text-align: center; min-width: 200px;
        }
        .comp-box .tipo { font-size: 11px; font-weight: 700; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px; }
        .comp-box .ruc  { font-size: 10px; color: #64748b; margin: 3px 0; }
        .comp-box .num  { font-size: 16px; font-weight: 800; color: #1e293b; }

        .divider { border: none; border-top: 1.5px solid #e2e8f0; margin: 10px 0; }

        /* Badges */
        .badge-export {
            display: inline-flex; align-items: center; gap: 5px;
            background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;
            padding: 3px 10px; border-radius: 20px; font-size: 9px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;
        }
        .badge-cdr {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 20px; font-size: 9px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            border: 1px solid;
        }

        /* Grid de datos */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
        .info-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
        .info-card h4 { font-size: 8px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.7px; margin-bottom: 6px; }
        .info-row { display: flex; justify-content: space-between; font-size: 10px; margin-bottom: 3px; }
        .info-row .label { color: #64748b; }
        .info-row .value { font-weight: 600; color: #1e293b; text-align: right; max-width: 60%; }

        /* Tabla de mercancías */
        .mercancias-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 9.5px; }
        .mercancias-table thead tr { background: #1e40af; color: white; }
        .mercancias-table thead th { padding: 7px 8px; font-weight: 600; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .mercancias-table tbody tr:nth-child(even) { background: #f8fafc; }
        .mercancias-table tbody td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .partida-code { font-family: 'Courier New', monospace; font-size: 9px; font-weight: 700; color: #1e40af; background: #eff6ff; padding: 2px 5px; border-radius: 3px; display: inline-block; }

        /* Totales */
        .totales-section { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 16px; }
        .nota-legal { flex: 1; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 12px; }
        .nota-legal p { font-size: 9px; color: #92400e; line-height: 1.6; }
        .nota-legal strong { font-weight: 700; }

        .totales-box { min-width: 200px; }
        .totales-row { display: flex; justify-content: space-between; font-size: 10px; padding: 4px 0; border-bottom: 1px solid #f1f5f9; }
        .totales-row.final { font-size: 13px; font-weight: 800; border-top: 2px solid #1e293b; border-bottom: none; padding-top: 6px; margin-top: 4px; color: #1e293b; }
        .totales-row .lbl { color: #64748b; }
        .totales-row.final .lbl { color: #1e293b; }
        .totales-row .val { font-weight: 700; }
        .totales-row.final .val { color: #1e40af; }

        /* QR y firma */
        .footer-doc { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 16px; }
        .qr-section { text-align: center; }
        .qr-section img { width: 70px; height: 70px; }
        .qr-section p { font-size: 7.5px; color: #94a3b8; margin-top: 4px; }
        .firma-section { text-align: center; }
        .firma-line { border-top: 1px solid #1e293b; width: 160px; margin: 0 auto 4px; }
        .firma-section p { font-size: 9px; color: #475569; }

        /* Aduana box */
        .aduana-box { background: #f0f9ff; border: 1.5px solid #bae6fd; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
        .aduana-box h4 { font-size: 8px; font-weight: 700; color: #0369a1; text-transform: uppercase; letter-spacing: 0.7px; margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .aduana-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .aduana-item .label { font-size: 7.5px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
        .aduana-item .value { font-size: 10px; font-weight: 700; color: #0c4a6e; margin-top: 2px; }

        @media print {
            body { background: white; }
            .btn-print { display: none; }
            .page { margin: 0; box-shadow: none; width: 100%; min-height: 100vh; padding: 12mm 14mm; }
        }
    </style>
</head>
<body>

<button class="btn-print" onclick="window.print()">
    <i class="fa-solid fa-print"></i> Imprimir / PDF
</button>

<div class="page">

    <!-- === ENCABEZADO === -->
    <div class="header-doc">
        <div class="empresa-info">
            <?php if (!empty($logo_doc)): ?>
                <img src="<?php echo htmlspecialchars($logo_doc); ?>" alt="<?php echo htmlspecialchars($empresa_razon); ?>" style="max-height:48px; max-width:180px; object-fit:contain; margin-bottom:6px; display:block;">
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($empresa_razon); ?></h1>
            <p>
                RUC: <strong><?php echo htmlspecialchars($empresa_ruc); ?></strong><br>
                <?php echo htmlspecialchars($empresa_dir); ?><br>
                <?php if ($empresa_tel): ?>Tel: <?php echo htmlspecialchars($empresa_tel); ?><br><?php endif; ?>
            </p>
        </div>
        <div class="comp-box">
            <div class="tipo">Factura de Exportación</div>
            <div class="ruc">R.U.C. <?php echo htmlspecialchars($empresa_ruc); ?></div>
            <div class="num"><?php echo htmlspecialchars($numero_comp); ?></div>
        </div>
    </div>

    <!-- Badges estado -->
    <div style="display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
        <span class="badge-export">
            <i class="fa-solid fa-ship" style="font-size:9px;"></i> Factura de Exportación — Art. 33° TUO IGV
        </span>
        <span class="badge-cdr" style="color:<?php echo $cdr_color; ?>; border-color:<?php echo $cdr_color; ?>20; background:<?php echo $cdr_color; ?>10;">
            <i class="fa-solid fa-<?php echo $cdr_estado === 'Aceptado' ? 'circle-check' : 'clock'; ?>" style="font-size:9px;"></i>
            SUNAT: <?php echo htmlspecialchars($cdr_estado); ?>
        </span>
        <?php if ($c['fecha_envio_sunat']): ?>
        <span style="font-size:9px; color:#94a3b8;">Enviado: <?php echo date('d/m/Y H:i', strtotime($c['fecha_envio_sunat'])); ?></span>
        <?php endif; ?>
    </div>

    <hr class="divider">

    <!-- === DATOS COMPRADOR Y COMPROBANTE === -->
    <div class="info-grid">
        <div class="info-card">
            <h4><i class="fa-solid fa-user" style="font-size:7px;"></i> Comprador (No Domiciliado)</h4>
            <div class="info-row">
                <span class="label">Razón Social:</span>
                <span class="value"><?php echo htmlspecialchars($c['cliente_razon_social']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Dirección:</span>
                <span class="value"><?php echo htmlspecialchars($c['cliente_direccion'] ?: '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">País Residencia:</span>
                <span class="value"><?php echo htmlspecialchars($c['cliente_pais_residencia'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Tipo Documento:</span>
                <span class="value">No Domiciliado (-)</span>
            </div>
        </div>
        <div class="info-card">
            <h4><i class="fa-solid fa-file-invoice" style="font-size:7px;"></i> Datos del Comprobante</h4>
            <div class="info-row">
                <span class="label">Fecha de Emisión:</span>
                <span class="value"><?php echo date('d/m/Y', strtotime($c['fecha_emision'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Moneda:</span>
                <span class="value"><?php echo htmlspecialchars($moneda); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Tipo de Cambio:</span>
                <span class="value">S/ <?php echo number_format($tc, 4); ?></span>
            </div>
            <div class="info-row">
                <span class="label">País Destino:</span>
                <span class="value"><?php echo htmlspecialchars($c['pais_destino_nombre'] ?? '-'); ?> (<?php echo htmlspecialchars($c['pais_destino_codigo'] ?? ''); ?>)</span>
            </div>
        </div>
    </div>

    <!-- === DATOS ADUANEROS === -->
    <div class="aduana-box">
        <h4><i class="fa-solid fa-anchor"></i> Información Aduanera — SUNAT / ADUANAS PERÚ</h4>
        <div class="aduana-grid">
            <div class="aduana-item">
                <div class="label">Incoterm</div>
                <div class="value"><?php echo htmlspecialchars($c['incoterm'] ?: '-'); ?></div>
            </div>
            <div class="aduana-item">
                <div class="label">Puerto de Embarque</div>
                <div class="value"><?php echo htmlspecialchars($c['puerto_embarque'] ?: '-'); ?></div>
            </div>
            <div class="aduana-item">
                <div class="label">Aduana de Partida</div>
                <div class="value"><?php echo htmlspecialchars($c['aduana_partida'] ?: '-'); ?></div>
            </div>
            <div class="aduana-item">
                <div class="label">N° DAM</div>
                <div class="value"><?php echo htmlspecialchars($c['numero_dam'] ?: 'Pendiente'); ?></div>
            </div>
            <div class="aduana-item">
                <div class="label">Agente de Aduana</div>
                <div class="value"><?php echo htmlspecialchars($c['nombre_agente_aduana'] ?: '-'); ?></div>
            </div>
            <div class="aduana-item">
                <div class="label">RUC Agente</div>
                <div class="value"><?php echo htmlspecialchars($c['ruc_agente_aduana'] ?: '-'); ?></div>
            </div>
        </div>
    </div>

    <!-- === TABLA DE MERCANCÍAS === -->
    <table class="mercancias-table">
        <thead>
            <tr>
                <th style="width:30px;">#</th>
                <th>Descripción de la Mercancía</th>
                <th style="width:55px;">Partida SA</th>
                <th style="width:35px;">Unid.</th>
                <th style="width:45px; text-align:right;">Cant.</th>
                <th style="width:55px; text-align:right;">P.Unit (<?php echo $moneda; ?>)</th>
                <th style="width:55px; text-align:right;">Total (<?php echo $moneda; ?>)</th>
                <th style="width:45px; text-align:right;">P.Neto (kg)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lineas as $i => $lin): ?>
        <tr>
            <td style="text-align:center; color:#94a3b8;"><?php echo $i + 1; ?></td>
            <td><?php echo htmlspecialchars($lin['descripcion']); ?></td>
            <td>
                <?php if ($lin['codigo_partida']): ?>
                <span class="partida-code"><?php echo htmlspecialchars($lin['codigo_partida']); ?></span>
                <?php else: ?>
                <span style="color:#94a3b8; font-size:9px;">—</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center; font-size:9px;"><?php echo htmlspecialchars($lin['unidad_medida'] ?? 'NIU'); ?></td>
            <td style="text-align:right;"><?php echo number_format((float)$lin['cantidad'], 3); ?></td>
            <td style="text-align:right;"><?php echo number_format((float)$lin['precio_unitario'], 2); ?></td>
            <td style="text-align:right; font-weight:700;"><?php echo number_format((float)$lin['total_linea'], 2); ?></td>
            <td style="text-align:right; color:#64748b;"><?php echo $lin['peso_neto'] ? number_format((float)$lin['peso_neto'], 3) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- === TOTALES + NOTA LEGAL === -->
    <div class="totales-section">
        <div class="nota-legal">
            <p>
                <strong>Nota Legal:</strong> El presente comprobante corresponde a una <strong>Operación de Exportación</strong>,
                la cual está exonerada del Impuesto General a las Ventas (IGV) de conformidad con el artículo 33° del
                Texto Único Ordenado de la Ley del IGV e ISC (D.S. Nro. 055-99-EF) y sus modificatorias.
                <br><br>
                <strong>Tipo de Cambio:</strong> <?php echo $moneda; ?> 1.00 = S/ <?php echo number_format($tc, 4); ?> (BCRP a la fecha de emisión).
            </p>
        </div>
        <div class="totales-box">
            <div class="totales-row">
                <span class="lbl">Valor de Exportación (<?php echo $moneda; ?>):</span>
                <span class="val"><?php echo number_format($c['subtotal'], 2); ?></span>
            </div>
            <div class="totales-row">
                <span class="lbl">IGV (Exportación 0%):</span>
                <span class="val" style="color:#94a3b8;">0.00</span>
            </div>
            <div class="totales-row" style="font-size:9px; color:#64748b;">
                <span class="lbl">Equivalente en PEN:</span>
                <span class="val">S/ <?php echo number_format($total_pen, 2); ?></span>
            </div>
            <div class="totales-row final">
                <span class="lbl">Total <?php echo $moneda; ?>:</span>
                <span class="val"><?php echo $moneda; ?> <?php echo number_format($c['total'], 2); ?></span>
            </div>
        </div>
    </div>

    <!-- === FOOTER: QR + CDR === -->
    <hr class="divider">
    <div class="footer-doc">
        <div class="qr-section">
            <?php if (!empty($qrSvg)): ?>
            <div style="width:75px;height:75px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:3px;">
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                    <?php echo $qrSvg; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="width:75px;height:75px;background:#f1f5f9;border:1.5px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;">
                <i class="fa-solid fa-qrcode" style="font-size:28px;color:#94a3b8;"></i>
            </div>
            <?php endif; ?>
            <p style="font-size:8px;font-weight:700;color:#64748b;margin-top:4px;">QR OFICIAL SUNAT</p>
        </div>

        <div style="text-align:center; flex:1; font-size:9px; color:#64748b; line-height:1.7;">
            <p><strong style="color:#1e293b;">CDR SUNAT:</strong> <?php echo htmlspecialchars($cdr_estado); ?></p>
            <?php if ($c['sunat_cdr_descripcion']): ?>
            <p style="font-size:8px;"><?php echo htmlspecialchars(substr($c['sunat_cdr_descripcion'], 0, 120)); ?></p>
            <?php endif; ?>
            <?php if ($c['sunat_hash_cpe']): ?>
            <p style="font-size:7.5px; font-family:monospace; color:#94a3b8;">Hash: <?php echo htmlspecialchars(substr($c['sunat_hash_cpe'], 0, 40)); ?>...</p>
            <?php endif; ?>
            <p style="margin-top:5px;">Representación impresa de la Factura de Exportación Electrónica</p>
            <p>Emisor: <?php echo htmlspecialchars($empresa_razon); ?> | RUC: <?php echo htmlspecialchars($empresa_ruc); ?></p>
        </div>

        <div class="firma-section">
            <div style="height:40px;"></div>
            <div class="firma-line"></div>
            <p style="font-size:9px; font-weight:600; color:#1e293b;"><?php echo htmlspecialchars($empresa_razon); ?></p>
            <p style="font-size:8px; color:#64748b;">Firma Autorizada / Sello</p>
        </div>
    </div>

</div>
</body>
</html>
