<?php
/**
 * includes/greenter_service.php
 * Servicio centralizado para emisión electrónica a SUNAT usando Greenter (UBL 2.1).
 * Incluye plan de contingencia con manejo de caídas de servidores SUNAT y reintentos.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Client\Client;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;

/**
 * Convierte un número decimal a su representación en letras en español (moneda).
 */
function greenterNumeroALetras(float $monto, string $moneda = 'PEN'): string {
    $enteros = (int)floor($monto);
    $centavos = (int)round(($monto - $enteros) * 100);
    $centavosStr = str_pad($centavos, 2, '0', STR_PAD_LEFT);
    $nombreMoneda = ($moneda === 'USD') ? 'DÓLARES AMERICANOS' : 'SOLES';

    // Conversión básica de números comunes
    $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
    $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    $dieces = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
    $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    if ($enteros === 0) {
        $letras = 'CERO';
    } elseif ($enteros === 100) {
        $letras = 'CIEN';
    } else {
        $partes = [];
        $miles = (int)floor($enteros / 1000);
        $resto = $enteros % 1000;

        if ($miles > 0) {
            if ($miles === 1) {
                $partes[] = 'MIL';
            } else {
                $c = (int)floor($miles / 100);
                $d = (int)floor(($miles % 100) / 10);
                $u = $miles % 10;
                if ($c > 0) $partes[] = ($c === 1 && ($d > 0 || $u > 0)) ? 'CIENTO' : ($c === 1 ? 'CIEN' : $centenas[$c]);
                if ($d === 1) $partes[] = $dieces[$u];
                elseif ($d === 2 && $u > 0) $partes[] = 'VEINTI' . $unidades[$u];
                else {
                    if ($d > 0) $partes[] = $decenas[$d];
                    if ($u > 0) $partes[] = ($d > 2 ? 'Y ' : '') . $unidades[$u];
                }
                $partes[] = 'MIL';
            }
        }

        if ($resto > 0) {
            $c = (int)floor($resto / 100);
            $d = (int)floor(($resto % 100) / 10);
            $u = $resto % 10;
            if ($c > 0) $partes[] = ($c === 1 && ($d > 0 || $u > 0)) ? 'CIENTO' : ($c === 1 ? 'CIEN' : $centenas[$c]);
            if ($d === 1) $partes[] = $dieces[$u];
            elseif ($d === 2 && $u > 0) $partes[] = 'VEINTI' . $unidades[$u];
            else {
                if ($d > 0) $partes[] = $decenas[$d];
                if ($u > 0) $partes[] = ($d > 2 ? 'Y ' : '') . $unidades[$u];
            }
        }

        $letras = implode(' ', array_filter($partes));
    }

    return "SON $letras CON $centavosStr/100 $nombreMoneda";
}

/**
 * Obtiene la instancia configurada de Greenter\See
 */
function getGreenterSee(PDO $conn): array {
    $cfg = [];
    $stmt = $conn->query("SELECT clave, valor FROM configuracion");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cfg[$row['clave']] = $row['valor'];
    }

    $modo = strtolower($cfg['sunat_modo'] ?? 'beta');
    $isBeta = ($modo !== 'produccion');

    $ruc = trim($cfg['empresa_ruc'] ?? '20000000001');
    if ($isBeta && (empty($ruc) || strlen($ruc) !== 11)) {
        $ruc = '20000000001';
    }

    $usuarioSol = trim($cfg['sunat_usuario_sol'] ?? ($isBeta ? 'MODDATOS' : ''));
    $claveSol   = trim($cfg['sunat_clave_sol']   ?? ($isBeta ? 'moddatos' : ''));

    // Certificado
    $certPath = $cfg['sunat_cert_path'] ?? '';
    if (empty($certPath) || !file_exists($certPath)) {
        // Fallback al certificado beta local
        $certPath = __DIR__ . '/../storage/certs/certificate_beta.pem';
    }

    if (!file_exists($certPath)) {
        throw new Exception("No se encontró el certificado digital en: $certPath");
    }

    $certPassword = $cfg['sunat_cert_password'] ?? '';
    $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));

    if (in_array($ext, ['pfx', 'p12'])) {
        $certObj = X509Certificate::createFromFile($certPath, $certPassword);
        $certificateContent = $certObj->export(X509ContentType::PEM);
    } else {
        $certificateContent = file_get_contents($certPath);
    }

    $see = new See();
    $see->setCertificate($certificateContent);
    $see->setService($isBeta ? SunatEndpoints::FE_BETA : SunatEndpoints::FE_PRODUCCION);

    $loginSol = ($isBeta ? '20000000001' : $ruc) . $usuarioSol;
    $see->setCredentials($loginSol, $claveSol);

    return [
        'see'    => $see,
        'cfg'    => $cfg,
        'ruc'    => ($isBeta ? '20000000001' : $ruc),
        'isBeta' => $isBeta
    ];
}

/**
 * Función principal para despachar un comprobante a SUNAT vía Greenter
 * con Plan de Contingencia ante caídas de servidor.
 */
function enviarComprobanteGreenter(int $comprobante_id, PDO $conn): array {
    // 1. Obtener datos del comprobante
    $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE id = ?");
    $stmt->execute([$comprobante_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        throw new Exception("Comprobante ID $comprobante_id no encontrado.");
    }

    // Si ya está aceptado formalmente por SUNAT
    if (!empty($c['sunat_cdr_estado']) && $c['sunat_cdr_estado'] === 'Aceptado') {
        return [
            'success'     => true,
            'cdr_estado'  => 'Aceptado',
            'message'     => 'El comprobante ya fue previamente aceptado por SUNAT.',
            'hash'        => $c['sunat_hash_cpe'] ?? ''
        ];
    }

    // 2. Obtener configuración y See
    $greenterSetup = getGreenterSee($conn);
    /** @var See $see */
    $see    = $greenterSetup['see'];
    $cfg    = $greenterSetup['cfg'];
    $emisorRuc = $greenterSetup['ruc'];

    // 3. Obtener líneas del comprobante
    $stmt = $conn->prepare("SELECT cl.*, ep.codigo_partida, ep.unidad_medida, ep.peso_bruto, ep.peso_neto
                            FROM comprobante_lineas cl
                            LEFT JOIN exportacion_partidas ep ON ep.comprobante_id = cl.comprobante_id AND ep.linea_num = cl.id
                            WHERE cl.comprobante_id = ?
                            ORDER BY cl.id");
    $stmt->execute([$comprobante_id]);
    $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($lineas)) {
        throw new Exception("El comprobante no tiene líneas de detalle registradas.");
    }

    // 4. Determinar tipo de comprobante y serie SUNAT
    $esExportacion = !empty($c['es_exportacion']) || stripos($c['tipo_comprobante'], 'Exportación') !== false;
    $serieOriginal = trim($c['serie']);
    
    // Normalizar serie para SUNAT SEE-Del Contribuyente:
    // Si la serie comienza con 'E' (p.ej. E001), para SUNAT debe ser F... (p.ej. FE01 o F001)
    $serieSunat = $serieOriginal;
    if ($esExportacion && strtoupper($serieOriginal[0]) === 'E') {
        $serieSunat = 'FE' . substr($serieOriginal, 1); // E001 -> FE01
    } elseif (strtoupper($serieOriginal[0]) !== 'F' && strtoupper($serieOriginal[0]) !== 'B') {
        $serieSunat = 'F001';
    }

    $correlativo = (int)$c['correlativo'];
    $tipoDoc = (strtoupper($serieSunat[0]) === 'B') ? '03' : '01'; // 01: Factura, 03: Boleta
    $moneda  = strtoupper($c['moneda'] ?: ($esExportacion ? 'USD' : 'PEN'));

    // 5. Configurar Emisor
    $company = new Company();
    $company->setRuc($emisorRuc)
        ->setRazonSocial($cfg['empresa_razon_social'] ?? 'EMPRESA EMISORA S.A.C.')
        ->setNombreComercial($cfg['empresa_nombre_comercial'] ?? '')
        ->setAddress((new Address())
            ->setUbigueo($cfg['empresa_ubigeo'] ?? '150101')
            ->setDepartamento($cfg['empresa_departamento'] ?? 'LIMA')
            ->setProvincia($cfg['empresa_provincia'] ?? 'LIMA')
            ->setDistrito($cfg['empresa_distrito'] ?? 'LIMA')
            ->setDireccion($cfg['empresa_direccion'] ?? 'AV. PRINCIPAL 123'));

    // 6. Configurar Cliente
    $clienteDoc = trim($c['cliente_documento'] ?? '');
    $clientTipoDoc = '0';
    if ($tipoDoc === '01') {
        if ($esExportacion) {
            $clientTipoDoc = '0'; // No domiciliado
        } else {
            $clientTipoDoc = '6'; // RUC
        }
    } else {
        $clientTipoDoc = (strlen($clienteDoc) === 8) ? '1' : '0';
    }

    $client = new Client();
    $client->setTipoDoc($clientTipoDoc)
        ->setNumDoc(!empty($clienteDoc) ? $clienteDoc : '-')
        ->setRznSocial($c['cliente_razon_social'] ?: 'CLIENTE VARIOS')
        ->setAddress((new Address())->setDireccion($c['cliente_direccion'] ?: '-'));

    // 7. Configurar Items
    $items = [];
    $subtotalGeneral = 0.0;
    $igvGeneral      = 0.0;
    $totalGeneral    = 0.0;

    foreach ($lineas as $lin) {
        $cantidad = (float)$lin['cantidad'];
        $precioUnit = (float)$lin['precio_unitario'];
        $totalLin = round($cantidad * $precioUnit, 2);

        $item = new SaleDetail();
        $item->setCodProducto($lin['codigo_partida'] ?: 'ITM001')
            ->setUnidad($lin['unidad_medida'] ?: 'NIU')
            ->setCantidad($cantidad)
            ->setDescripcion($lin['descripcion']);

        if ($esExportacion) {
            // Exportación: Afectación 40, IGV 0%
            $item->setMtoBaseIgv($totalLin)
                ->setPorcentajeIgv(0.00)
                ->setIgv(0.00)
                ->setTipAfeIgv('40')
                ->setTotalImpuestos(0.00)
                ->setMtoValorVenta($totalLin)
                ->setMtoValorUnitario($precioUnit)
                ->setMtoPrecioUnitario($precioUnit);

            $subtotalGeneral += $totalLin;
            $totalGeneral    += $totalLin;
        } else {
            // Venta interna Gravada 18%
            $igvLin = round($totalLin * 0.18, 2);
            $totalConIgv = $totalLin + $igvLin;
            $precioConIgv = round($precioUnit * 1.18, 2);

            $item->setMtoBaseIgv($totalLin)
                ->setPorcentajeIgv(18.00)
                ->setIgv($igvLin)
                ->setTipAfeIgv('10')
                ->setTotalImpuestos($igvLin)
                ->setMtoValorVenta($totalLin)
                ->setMtoValorUnitario($precioUnit)
                ->setMtoPrecioUnitario($precioConIgv);

            $subtotalGeneral += $totalLin;
            $igvGeneral      += $igvLin;
            $totalGeneral    += $totalConIgv;
        }
        $items[] = $item;
    }

    // 8. Construir Invoice
    $invoice = new Invoice();
    $invoice->setTipoDoc($tipoDoc)
        ->setSerie($serieSunat)
        ->setCorrelativo($correlativo)
        ->setFechaEmision(new DateTime($c['fecha_emision'] ?: date('Y-m-d')))
        ->setFormaPago(new FormaPagoContado())
        ->setTipoMoneda($moneda)
        ->setCompany($company)
        ->setClient($client)
        ->setValorVenta($subtotalGeneral)
        ->setSubTotal($subtotalGeneral)
        ->setMtoImpVenta($totalGeneral)
        ->setTotalImpuestos($igvGeneral)
        ->setDetails($items);

    if ($esExportacion) {
        $invoice->setTipoOperacion('0200') // Exportación de Bienes/Servicios
            ->setMtoOperExportacion($subtotalGeneral)
            ->setMtoIGV(0.00);
    } else {
        $invoice->setTipoOperacion('0101') // Venta Interna
            ->setMtoOperGravadas($subtotalGeneral)
            ->setMtoIGV($igvGeneral);
    }

    // Leyenda de monto en letras
    $leyendaTexto = greenterNumeroALetras($totalGeneral, $moneda);
    $invoice->setLegends([
        (new Legend())->setCode('1000')->setValue($leyendaTexto)
    ]);

    // 9. Generar XML firmado y extraer Hash
    $xmlSigned = $see->getXmlSigned($invoice);
    $filenameBase = "{$emisorRuc}-{$tipoDoc}-{$serieSunat}-{$correlativo}";
    
    $xmlDir = __DIR__ . '/../storage/xml';
    $cdrDir = __DIR__ . '/../storage/cdr';
    if (!is_dir($xmlDir)) @mkdir($xmlDir, 0755, true);
    if (!is_dir($cdrDir)) @mkdir($cdrDir, 0755, true);

    $xmlPath = "{$xmlDir}/{$filenameBase}.xml";
    file_put_contents($xmlPath, $xmlSigned);

    $hash = '';
    try {
        $doc = new DOMDocument();
        $doc->loadXML($xmlSigned);
        $hashNodes = $doc->getElementsByTagName('DigestValue');
        if ($hashNodes->length > 0) {
            $hash = $hashNodes->item(0)->nodeValue;
        }
    } catch (Exception $e) {}

    // Cadena QR oficial SUNAT
    $qrData = implode('|', [
        $emisorRuc,
        $tipoDoc,
        $serieSunat,
        $correlativo,
        number_format($igvGeneral, 2, '.', ''),
        number_format($totalGeneral, 2, '.', ''),
        $c['fecha_emision'] ?: date('Y-m-d'),
        $clientTipoDoc,
        $clienteDoc,
        $hash
    ]);

    // 10. PLAN DE CONTINGENCIA: Envío protegido con captura de caídas/timeouts
    try {
        $result = $see->send($invoice);

        if ($result->isSuccess()) {
            // ✅ ACEPTADO POR SUNAT
            $cdrZip = $result->getCdrZip();
            $cdrPath = "{$cdrDir}/R-{$filenameBase}.zip";
            file_put_contents($cdrPath, $cdrZip);

            $cdr = $result->getCdrResponse();
            $cdrCode = $cdr ? $cdr->getCode() : '0';
            $cdrDesc = $cdr ? $cdr->getDescription() : 'Comprobante aceptado por SUNAT';

            $upd = $conn->prepare("UPDATE comprobantes SET 
                estado = 'Aceptado',
                nubefact_enviado = 1,
                sunat_cdr_estado = 'Aceptado',
                sunat_cdr_descripcion = ?,
                sunat_hash_cpe = ?,
                sunat_qr = ?,
                fecha_envio_sunat = NOW()
                WHERE id = ?");
            $upd->execute([$cdrDesc, $hash, $qrData, $comprobante_id]);

            return [
                'success'      => true,
                'cdr_estado'   => 'Aceptado',
                'cdr_codigo'   => $cdrCode,
                'message'      => $cdrDesc,
                'hash'         => $hash,
                'contingencia' => false
            ];
        } else {
            // SUNAT respondió con error o rechazo
            $error = $result->getError();
            $errorCode = $error->getCode();
            $errorMsg  = $error->getMessage();

            // Identificar si es error de servidor SUNAT o rechazo de contenido
            $esCaidaServidor = in_array($errorCode, ['0500', '1033', '1034', '1035', 'P000', '0100', '0101', '0102']) 
                               || stripos($errorMsg, 'timeout') !== false 
                               || stripos($errorMsg, 'no disponible') !== false;

            if ($esCaidaServidor) {
                // Activar contingencia: Guardar como Pendiente SUNAT
                $upd = $conn->prepare("UPDATE comprobantes SET 
                    estado = 'Pendiente SUNAT',
                    sunat_cdr_estado = 'Pendiente',
                    sunat_cdr_descripcion = ?,
                    sunat_hash_cpe = ?,
                    sunat_qr = ?
                    WHERE id = ?");
                $upd->execute(["Servidor SUNAT ocupado o sin respuesta ($errorCode: $errorMsg). En cola para reintento.", $hash, $qrData, $comprobante_id]);

                return [
                    'success'      => false,
                    'cdr_estado'   => 'Pendiente',
                    'contingencia' => true,
                    'message'      => "SUNAT no respondió de inmediato ($errorMsg). El comprobante fue registrado y quedó en contingencia para sincronizarse en segundo plano."
                ];
            } else {
                // Rechazo tributario formal
                $upd = $conn->prepare("UPDATE comprobantes SET 
                    estado = 'Rechazado',
                    sunat_cdr_estado = 'Rechazado',
                    sunat_cdr_descripcion = ?,
                    sunat_hash_cpe = ?
                    WHERE id = ?");
                $upd->execute(["Rechazado por SUNAT [$errorCode]: $errorMsg", $hash, $comprobante_id]);

                return [
                    'success'      => false,
                    'cdr_estado'   => 'Rechazado',
                    'contingencia' => false,
                    'message'      => "SUNAT rechazó el comprobante ($errorCode): $errorMsg"
                ];
            }
        }

    } catch (Throwable $e) {
        // Excepción de conexión / red / timeout fatal
        $msg = $e->getMessage();
        $upd = $conn->prepare("UPDATE comprobantes SET 
            estado = 'Pendiente SUNAT',
            sunat_cdr_estado = 'Pendiente',
            sunat_cdr_descripcion = ?,
            sunat_hash_cpe = ?,
            sunat_qr = ?
            WHERE id = ?");
        $upd->execute(["Error de conexión con SUNAT: $msg. Reintento programado.", $hash, $qrData, $comprobante_id]);

        return [
            'success'      => false,
            'cdr_estado'   => 'Pendiente',
            'contingencia' => true,
            'message'      => "No se pudo conectar a los servidores de SUNAT (posible caída de red). El comprobante ha sido guardado exitosamente en el sistema y se reintentará el envío automáticamente."
        ];
    }
}
