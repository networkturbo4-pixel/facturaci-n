<?php
/**
 * includes/facturalo_service.php
 * Servicio oficial de integración con Facturalo API v3 (https://docs.facturalo.pe)
 * Emisión de Facturas, Boletas, Notas y Consultas RUC/DNI en tiempo real.
 */

if (!defined('FACTURALO_API_URL')) {
    define('FACTURALO_API_URL', 'https://api.facturalo.pe/api/v3');
}

/**
 * Realiza una petición HTTP a la API v3 de Facturalo
 *
 * @param string $endpoint Ej: '/documentos/enviar' o '/consulta/ruc/20100070970'
 * @param array|null $payload Datos a enviar (para POST)
 * @param string $method 'GET' o 'POST'
 * @param string|null $token Token de autorización (sk_live_... o sk_test_...)
 * @return array
 * @throws Exception
 */
function facturaloApiRequest(string $endpoint, ?array $payload = null, string $method = 'POST', ?string $token = null): array {
    $url = FACTURALO_API_URL . '/' . ltrim($endpoint, '/');

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    if (!empty($token)) {
        // En Facturalo v3, el header Authorization lleva el token directamente
        $headers[] = 'Authorization: ' . trim($token);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $responseRaw = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Error de conexión cURL con Facturalo: " . $curlError);
    }

    $json = json_decode($responseRaw, true);
    if (!$json && $httpCode !== 204) {
        throw new Exception("Respuesta inválida de Facturalo (HTTP $httpCode): " . substr($responseRaw, 0, 300));
    }

    return [
        'http_code' => $httpCode,
        'data'      => $json
    ];
}

/**
 * Prueba la validez del token de Facturalo mediante una consulta RUC de prueba a SUNAT
 *
 * @param string $token
 * @return array
 */
function probarConexionFacturalo(string $token): array {
    $token = trim($token);
    if (empty($token)) {
        return [
            'success' => false,
            'message' => 'El token de Facturalo no puede estar vacío.'
        ];
    }

    try {
        // Probamos consultando el RUC de SUNAT (20131312955)
        $res = facturaloApiRequest('/consulta/ruc/20131312955', null, 'GET', $token);
        $body = $res['data'] ?? [];

        if ($res['http_code'] === 200 && !empty($body['success'])) {
            $modo = str_starts_with($token, 'sk_test_') ? 'DEMO / BETA' : 'PRODUCCIÓN';
            return [
                'success' => true,
                'modo'    => $modo,
                'message' => "¡Conexión exitosa con Facturalo API v3! Entorno detectado: $modo.",
                'datos'   => $body['data'] ?? []
            ];
        }

        if ($res['http_code'] === 401) {
            return [
                'success' => false,
                'message' => 'Token no válido o no autorizado (HTTP 401). Verifica tu Secret Key en el panel de Facturalo.'
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? ("Error al conectar con Facturalo (HTTP " . $res['http_code'] . ")")
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Consulta datos de DNI (RENIEC) o RUC (SUNAT) mediante Facturalo API v3
 *
 * @param string $tipoDoc 'DNI' o 'RUC'
 * @param string $numDoc
 * @param string|null $token Si no se especifica, se toma de la base de datos
 * @param PDO|null $conn
 * @return array
 */
function consultarDocumentoFacturalo(string $tipoDoc, string $numDoc, ?string $token = null, ?PDO $conn = null): array {
    $tipoDoc = strtoupper(trim($tipoDoc));
    $numDoc  = trim($numDoc);

    if (empty($token)) {
        if ($conn === null) {
            require_once __DIR__ . '/db.php';
        }
        $stmt = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'facturalo_token' LIMIT 1");
        $stmt->execute();
        $token = $stmt->fetchColumn() ?: '';
    }

    if (empty($token)) {
        return [
            'success' => false,
            'message' => 'Token de Facturalo no configurado en el sistema.'
        ];
    }

    $subpath = ($tipoDoc === 'DNI') ? "/consulta/dni/{$numDoc}" : "/consulta/ruc/{$numDoc}";

    try {
        $res = facturaloApiRequest($subpath, null, 'GET', $token);
        $body = $res['data'] ?? [];

        if ($res['http_code'] === 200 && !empty($body['success']) && !empty($body['data'])) {
            $d = $body['data'];

            if ($tipoDoc === 'DNI') {
                $nombre = $d['nombre_completo'] ?? trim(($d['nombres'] ?? '') . ' ' . ($d['apellido_paterno'] ?? '') . ' ' . ($d['apellido_materno'] ?? ''));
                $direccion = $d['direccion_completa'] ?? $d['direccion'] ?? '-';
                return [
                    'success' => true,
                    'source'  => 'facturalo_reniec',
                    'data'    => [
                        'numero'       => $numDoc,
                        'razon_social' => $nombre,
                        'direccion'    => $direccion,
                        'estado'       => 'ACTIVO',
                        'condicion'    => 'HABIDO',
                        'ubigeo'       => $d['ubigeo_sunat'] ?? ($d['ubigeo_reniec'] ?? '')
                    ]
                ];
            } else {
                $nombre = $d['nombre_o_razon_social'] ?? $d['razon_social'] ?? '';
                $direccion = $d['direccion_completa'] ?? $d['direccion'] ?? '-';
                return [
                    'success' => true,
                    'source'  => 'facturalo_sunat',
                    'data'    => [
                        'numero'       => $numDoc,
                        'razon_social' => $nombre,
                        'direccion'    => $direccion,
                        'estado'       => $d['estado'] ?? 'ACTIVO',
                        'condicion'    => $d['condicion'] ?? 'HABIDO',
                        'ubigeo'       => $d['ubigeo_sunat'] ?? ''
                    ]
                ];
            }
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? 'Documento no encontrado en padrón Facturalo.'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Emite y despacha un comprobante electrónico (Factura, Boleta, Exportación) a SUNAT vía Facturalo API v3
 *
 * @param int $comprobante_id
 * @param PDO $conn
 * @return array
 * @throws Exception
 */
function enviarComprobanteFacturalo(int $comprobante_id, PDO $conn): array {
    // 1. Obtener configuración del sistema
    $cfg = [];
    $stmt = $conn->query("SELECT clave, valor FROM configuracion WHERE clave IN ('facturalo_token', 'facturacion_proveedor', 'ruc_empresa', 'razon_social_empresa')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cfg[$row['clave']] = $row['valor'];
    }

    $token = trim($cfg['facturalo_token'] ?? '');
    if (empty($token)) {
        throw new Exception("Token de Facturalo API v3 no está configurado. Ingrésalo en Configuración > SUNAT/OSE.");
    }

    // 2. Obtener comprobante
    $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE id = ?");
    $stmt->execute([$comprobante_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        throw new Exception("Comprobante ID $comprobante_id no encontrado en la base de datos.");
    }

    if (!empty($c['nubefact_enviado']) && in_array(strtoupper($c['sunat_cdr_estado'] ?? ''), ['ACEPTADO', 'ACEPTADA', '0'])) {
        throw new Exception("Este comprobante ya fue emitido y aceptado por SUNAT previamente (CDR: " . $c['sunat_cdr_estado'] . ").");
    }

    // 3. Determinar tipo de comprobante
    $serie = strtoupper(trim($c['serie']));
    $correlativo = (int)$c['correlativo'];

    // '01' Factura, '03' Boleta, '07' NC, '08' ND
    $tipoComprobante = '01';
    if (str_starts_with($serie, 'B') || stripos($c['tipo_comprobante'], 'Boleta') !== false) {
        $tipoComprobante = '03';
    } elseif (str_starts_with($serie, 'FC') || str_starts_with($serie, 'BC') || stripos($c['tipo_comprobante'], 'Crédito') !== false) {
        $tipoComprobante = '07';
    } elseif (str_starts_with($serie, 'FD') || str_starts_with($serie, 'BD') || stripos($c['tipo_comprobante'], 'Débito') !== false) {
        $tipoComprobante = '08';
    }

    // 4. Determinar transacción SUNAT ('0101' Venta Interna, '0102' Exportación)
    $esExportacion = !empty($c['es_exportacion']) || stripos($c['tipo_comprobante'], 'Exportación') !== false || !empty($c['pais_destino_codigo']);
    $sunatTransaccion = $esExportacion ? '0102' : '0101';

    // 5. Determinar tipo y número de documento del cliente
    $docCliente = trim($c['cliente_documento'] ?? '');
    $docLen     = strlen($docCliente);
    $tipoDocCliente = '0'; // Sin documento / no domiciliado por defecto

    if ($docLen === 11 && ctype_digit($docCliente)) {
        $tipoDocCliente = '6'; // RUC
    } elseif ($docLen === 8 && ctype_digit($docCliente)) {
        $tipoDocCliente = '1'; // DNI
    } elseif ($esExportacion) {
        $tipoDocCliente = '0'; // Exportación sin RUC nacional
        if (empty($docCliente)) {
            $docCliente = '-';
        }
    }

    $cliente = [
        'tipoDocumento'   => $tipoDocCliente,
        'numeroDocumento' => $docCliente ?: '-',
        'denominacion'    => $c['cliente_razon_social'] ?: 'CLIENTE VARIOS',
        'direccion'       => $c['cliente_direccion'] ?: '-'
    ];

    // 6. Obtener líneas del comprobante
    $stmt = $conn->prepare("SELECT cl.*, ep.codigo_partida, ep.unidad_medida AS partida_unidad
                            FROM comprobante_lineas cl
                            LEFT JOIN exportacion_partidas ep ON ep.comprobante_id = cl.comprobante_id AND ep.linea_num = cl.id
                            WHERE cl.comprobante_id = ?
                            ORDER BY cl.id ASC");
    $stmt->execute([$comprobante_id]);
    $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($lineas)) {
        throw new Exception("El comprobante ID $comprobante_id no tiene ítems o líneas registradas.");
    }

    $items = [];
    foreach ($lineas as $idx => $lin) {
        $cantidad = (float)$lin['cantidad'];
        $precioUnit = (float)$lin['precio_unitario'];

        // En Facturalo v3, tipoIgv: '10' Gravado, '20' Exonerado, '30' Inafecto, '40' Exportación
        $tipoIgv = $esExportacion ? '40' : '10';

        $items[] = [
            'unidadMedida'   => !empty($lin['partida_unidad']) ? $lin['partida_unidad'] : 'NIU',
            'descripcion'    => $lin['descripcion'] ?: ("ITEM " . ($idx + 1)),
            'cantidad'       => $cantidad,
            'precioUnitario' => $precioUnit,
            'tipoIgv'        => $tipoIgv
        ];
    }

    // 7. Estructurar payload Facturalo v3
    $fechaEmision = !empty($c['fecha_emision']) ? substr($c['fecha_emision'], 0, 10) : date('Y-m-d');
    $moneda       = !empty($c['moneda']) ? strtoupper($c['moneda']) : 'PEN';

    $documentoData = [
        'sunatTransaccion' => $sunatTransaccion,
        'tipoComprobante'  => $tipoComprobante,
        'serie'            => $serie,
        'correlativo'      => (string)$correlativo,
        'fechaEmision'     => $fechaEmision,
        'fechaVencimiento' => $fechaEmision,
        'moneda'           => $moneda,
        'formaPago'        => 'CONTADO',
        'cliente'          => $cliente,
        'items'            => $items
    ];

    // Campos complementarios para exportación
    if ($esExportacion) {
        $otrosCampos = [];
        if (!empty($c['incoterm'])) {
            $otrosCampos[] = ['label' => 'Incoterm', 'value' => (string)$c['incoterm']];
        }
        if (!empty($c['pais_destino_nombre'])) {
            $otrosCampos[] = ['label' => 'País de Destino', 'value' => (string)$c['pais_destino_nombre']];
        }
        if (!empty($c['puerto_embarque'])) {
            $otrosCampos[] = ['label' => 'Puerto de Embarque', 'value' => (string)$c['puerto_embarque']];
        }
        if (!empty($otrosCampos)) {
            $documentoData['otrosCampos'] = $otrosCampos;
        }
    }

    $payload = [
        'webhook'   => false,
        'documento' => $documentoData
    ];

    // 8. Enviar petición a Facturalo v3
    $res = facturaloApiRequest('/documentos/enviar', $payload, 'POST', $token);
    $httpCode = $res['http_code'];
    $body     = $res['data'] ?? [];

    $isSuccess = ($httpCode === 200 || $httpCode === 201) && !empty($body['success']);

    if (!$isSuccess) {
        $msg = $body['message'] ?? 'Error desconocido al emitir comprobante con Facturalo';
        if (!empty($body['errors']) && is_array($body['errors'])) {
            $detalles = [];
            foreach ($body['errors'] as $k => $v) {
                $detalles[] = is_array($v) ? implode(', ', $v) : "$k: $v";
            }
            $msg .= ' (' . implode(' | ', $detalles) . ')';
        }
        throw new Exception("Facturalo rechazo el envío (HTTP $httpCode): " . $msg);
    }

    // 9. Interpretar respuesta exitosa
    $dataRes     = $body['data'] ?? [];
    $estadoDoc   = strtoupper($dataRes['estado'] ?? 'ACEPTADA');
    $cdrEstado   = ($estadoDoc === 'ACEPTADA') ? 'Aceptado' : 'Observado';
    $descripcion = $body['message'] ?? 'Comprobante emitido y aceptado correctamente.';

    $enlaces     = $dataRes['enlaces'] ?? [];
    $enlacePdf   = $enlaces['pdf'] ?? ($enlaces['pdfTicket'] ?? null);
    $enlaceXml   = $enlaces['xml'] ?? null;
    $enlaceCdr   = $enlaces['cdr'] ?? null;
    $hash        = $dataRes['hash'] ?? null;
    $cadenaQr    = $dataRes['cadenaQr'] ?? null;

    // 10. Actualizar base de datos
    $updateStmt = $conn->prepare("UPDATE comprobantes SET
        nubefact_enviado            = 1,
        sunat_cdr_estado            = ?,
        sunat_cdr_descripcion       = ?,
        sunat_enlace_pdf            = ?,
        sunat_enlace_xml            = ?,
        sunat_enlace_cdr            = ?,
        sunat_cadena_con_hash       = ?,
        sunat_hash_cpe              = ?,
        sunat_qr                    = ?,
        nubefact_numero_comprobante = ?,
        fecha_envio_sunat           = NOW(),
        estado                      = 'EMITIDO'
        WHERE id = ?");

    $updateStmt->execute([
        $cdrEstado,
        substr((string)$descripcion, 0, 500),
        $enlacePdf,
        $enlaceXml,
        $enlaceCdr,
        $cadenaQr ?: $hash,
        $hash,
        $cadenaQr,
        $correlativo,
        $comprobante_id
    ]);

    return [
        'success'      => true,
        'aceptado'     => true,
        'cdr_estado'   => $cdrEstado,
        'descripcion'  => $descripcion,
        'enlace_pdf'   => $enlacePdf,
        'enlace_xml'   => $enlaceXml,
        'enlace_cdr'   => $enlaceCdr,
        'hash'         => $hash,
        'qr'           => $cadenaQr,
        'numero'       => $correlativo,
        'raw_response' => $body
    ];
}
