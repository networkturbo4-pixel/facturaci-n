<?php
/**
 * api/parsear_documento_compra.php
 * Extrae automáticamente datos de compra (proveedor, RUC, comprobante, fecha, montos, ítems)
 * a partir de un archivo subido (XML factura electrónica UBL 2.1 de SUNAT, o PDF con texto).
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['documento_compra'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No se recibió ningún documento.']);
    exit;
}

$file = $_FILES['documento_compra'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Error al transferir el archivo (código ' . $file['error'] . ')']);
    exit;
}

$tmpPath = $file['tmp_name'];
$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

$data = [
    'tipo_documento'     => 'Factura de Compra',
    'numero_documento'   => '',
    'fecha_compra'       => date('Y-m-d'),
    'hora_compra'        => date('H:i'),
    'proveedor_ruc'      => '',
    'proveedor_nombre'   => '',
    'costo_unitario'     => '',
    'cantidad'           => '',
    'descripcion'        => '',
    'metodo_compra'      => 'Compra Directa / Contado',
    'mensaje_extraccion' => ''
];

// 1. Procesar archivo XML (UBL 2.1 estándar SUNAT / factura electrónica)
if ($ext === 'xml') {
    $content = file_get_contents($tmpPath);
    if ($content) {
        // Suprimir errores de XML malformado
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml !== false) {
            $ns = $xml->getNamespaces(true);
            $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

            // ID / Número de Comprobante
            $idNode = $xml->xpath('//cbc:ID');
            if (!empty($idNode)) {
                $data['numero_documento'] = (string)$idNode[0];
            }

            // Tipo de Comprobante
            $tipoNode = $xml->xpath('//cbc:InvoiceTypeCode');
            if (!empty($tipoNode)) {
                $t = (string)$tipoNode[0];
                if ($t === '01') $data['tipo_documento'] = 'Factura de Compra';
                elseif ($t === '03') $data['tipo_documento'] = 'Boleta de Compra';
                elseif ($t === '09') $data['tipo_documento'] = 'Guía de Remisión';
            }

            // Fecha de emisión
            $fechaNode = $xml->xpath('//cbc:IssueDate');
            if (!empty($fechaNode)) {
                $data['fecha_compra'] = (string)$fechaNode[0];
            }
            $horaNode = $xml->xpath('//cbc:IssueTime');
            if (!empty($horaNode)) {
                $data['hora_compra'] = substr((string)$horaNode[0], 0, 5);
            }

            // Proveedor / AccountingSupplierParty
            $rucNode = $xml->xpath('//cac:AccountingSupplierParty//cbc:CustomerAssignedAccountID | //cac:AccountingSupplierParty//cac:PartyIdentification//cbc:ID | //cac:AccountingSupplierParty//cac:PartyLegalEntity//cbc:CompanyID');
            if (!empty($rucNode)) {
                $data['proveedor_ruc'] = (string)$rucNode[0];
            }
            $nomNode = $xml->xpath('//cac:AccountingSupplierParty//cac:PartyLegalEntity//cbc:RegistrationName | //cac:AccountingSupplierParty//cac:PartyName//cbc:Name');
            if (!empty($nomNode)) {
                $data['proveedor_nombre'] = (string)$nomNode[0];
            }

            // Líneas de producto (primera línea o totales)
            $itemDesc = $xml->xpath('//cac:InvoiceLine[1]//cac:Item//cbc:Description');
            if (!empty($itemDesc)) {
                $data['descripcion'] = (string)$itemDesc[0];
            }
            $itemQty = $xml->xpath('//cac:InvoiceLine[1]//cbc:InvoicedQuantity');
            if (!empty($itemQty)) {
                $data['cantidad'] = (float)$itemQty[0];
            }
            $itemPrice = $xml->xpath('//cac:InvoiceLine[1]//cac:Price//cbc:PriceAmount');
            if (!empty($itemPrice)) {
                $data['costo_unitario'] = number_format((float)$itemPrice[0], 2, '.', '');
            }

            $data['mensaje_extraccion'] = 'Datos extraídos exitosamente desde el comprobante electrónico XML (UBL 2.1).';
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }
    }
}

// 2. Si es imagen o PDF: Análisis por patrones de texto y nombre de archivo
// Muchos nombres de archivo de facturas electrónicas siguen el estándar: 20123456789-01-F001-000123.pdf
$matchRuc = [];
if (preg_match('/(10\d{9}|20\d{9})[-_](01|03|09|factura|boleta|guia)[-_]([a-zA-Z0-9]{4})[-_](\d+)/i', $origName, $m)) {
    $data['proveedor_ruc'] = $m[1];
    $data['numero_documento'] = strtoupper($m[3]) . '-' . str_pad($m[4], 6, '0', STR_PAD_LEFT);
    if ($m[2] === '01' || strtolower($m[2]) === 'factura') $data['tipo_documento'] = 'Factura de Compra';
    elseif ($m[2] === '03' || strtolower($m[2]) === 'boleta') $data['tipo_documento'] = 'Boleta de Compra';
    elseif ($m[2] === '09' || strtolower($m[2]) === 'guia') $data['tipo_documento'] = 'Guía de Remisión';
} elseif (preg_match('/(10\d{9}|20\d{9})/i', $origName, $mRuc)) {
    $data['proveedor_ruc'] = $mRuc[1];
}

// Intentar extraer texto básico si es PDF o texto plano
$pdfContent = @file_get_contents($tmpPath);
if ($pdfContent && $ext === 'pdf') {
    // Buscar RUC en texto de streams o encabezados
    if (empty($data['proveedor_ruc']) && preg_match('/R\.?U\.?C\.?[:\s]*([12]0\d{9})/i', $pdfContent, $mR)) {
        $data['proveedor_ruc'] = $mR[1];
    }
    // Buscar Serie-Correlativo (ej: F001-0001234, E001-1234, B001-1234)
    if (empty($data['numero_documento']) && preg_match('/([FEB][A-Z0-9]{3}[-\s]\d{1,8})/i', $pdfContent, $mSer)) {
        $data['numero_documento'] = str_replace(' ', '-', strtoupper(trim($mSer[1])));
    }
    // Buscar fecha (ej: 2026-10-23 o 23/10/2026)
    if (preg_match('/(\d{2})[\/\-](\d{2})[\/\-](\d{4})/', $pdfContent, $mDate)) {
        $data['fecha_compra'] = $mDate[3] . '-' . $mDate[2] . '-' . $mDate[1];
    }
}

// Si se detectó el RUC del proveedor, buscar en la BD local de clientes/proveedores si ya tenemos su razón social
if (!empty($data['proveedor_ruc'])) {
    try {
        $stmtP = $conn->prepare("SELECT razon_social FROM clientes WHERE numero_documento = ? LIMIT 1");
        $stmtP->execute([$data['proveedor_ruc']]);
        $rowP = $stmtP->fetch();
        if ($rowP && !empty($rowP['razon_social'])) {
            $data['proveedor_nombre'] = $rowP['razon_social'];
        }
    } catch (Exception $e) {}
}

$data['mensaje_extraccion'] = (!empty($data['numero_documento']) || !empty($data['proveedor_ruc']))
    ? 'Se autocompletaron datos detectados del archivo (' . htmlspecialchars($origName) . '). Puedes ajustarlos libremente.'
    : 'Documento cargado correctamente. Listo para verificación.';

echo json_encode(['success' => true, 'data' => $data]);
exit;
