<?php
/**
 * api/enviar_sunat.php
 * Envía una factura de exportación a SUNAT vía Nubefact OSE.
 * Puede ser invocado vía POST HTTP o como función interna enviarComprobanteNubefact().
 */
require_once __DIR__ . '/../includes/auth.php';

/**
 * Función principal para despachar un comprobante a Nubefact OSE
 *
 * @param int $comprobante_id
 * @param PDO $conn
 * @return array
 * @throws Exception
 */
function enviarComprobanteNubefact(int $comprobante_id, PDO $conn): array {
    // 1. Obtener configuración
    $cfg = [];
    $stmt = $conn->query("SELECT clave, valor FROM configuracion");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cfg[$row['clave']] = $row['valor'];
    }

    $proveedor = strtolower($cfg['facturacion_proveedor'] ?? 'greenter');

    // Si el proveedor seleccionado es Greenter (o si no hay token de Nubefact configurado)
    if ($proveedor === 'greenter' || (empty($cfg['nubefact_token']) && !empty($cfg['sunat_usuario_sol']))) {
        require_once __DIR__ . '/../includes/greenter_service.php';
        return enviarComprobanteGreenter($comprobante_id, $conn);
    }

    $nubefact_url   = $cfg['nubefact_url']   ?? '';
    $nubefact_token = $cfg['nubefact_token'] ?? '';

    if (empty($nubefact_url) || empty($nubefact_token)) {
        // Si no hay Nubefact pero Greenter está disponible, usar Greenter como fallback seguro
        require_once __DIR__ . '/../includes/greenter_service.php';
        return enviarComprobanteGreenter($comprobante_id, $conn);
    }

    // 2. Obtener datos del comprobante
    $stmt = $conn->prepare("SELECT * FROM comprobantes WHERE id = ?");
    $stmt->execute([$comprobante_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        throw new Exception("Comprobante ID $comprobante_id no encontrado.");
    }

    if (!empty($c['nubefact_enviado'])) {
        throw new Exception("Este comprobante ya fue enviado a SUNAT. CDR: " . ($c['sunat_cdr_estado'] ?? 'Desconocido'));
    }

    // 3. Obtener líneas del comprobante
    $stmt = $conn->prepare("SELECT cl.*, ep.codigo_partida, ep.unidad_medida, ep.peso_bruto, ep.peso_neto
                            FROM comprobante_lineas cl
                            LEFT JOIN exportacion_partidas ep ON ep.comprobante_id = cl.comprobante_id AND ep.linea_num = cl.id
                            WHERE cl.comprobante_id = ?
                            ORDER BY cl.id");
    $stmt->execute([$comprobante_id]);
    $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $numero = (int)$c['correlativo'];

    // 4. Construir items para Nubefact
    $items = [];
    foreach ($lineas as $idx => $lin) {
        $precio_unit = (float)$lin['precio_unitario'];
        $cantidad    = (float)$lin['cantidad'];
        $total_lin   = $precio_unit * $cantidad;

        $item = [
            "unidad_de_medida"        => $lin['unidad_medida'] ?: "NIU",
            "codigo"                  => $lin['codigo_partida'] ?: ("ITEM" . str_pad($idx + 1, 3, '0', STR_PAD_LEFT)),
            "descripcion"             => $lin['descripcion'],
            "cantidad"                => $cantidad,
            "valor_unitario"          => $precio_unit,
            "precio_unitario"         => $precio_unit,
            "descuento"               => 0,
            "subtotal"                => $total_lin,
            "tipo_de_igv"             => 4,   // 4 = Exportación (0%)
            "igv"                     => 0,
            "total"                   => $total_lin,
            "anticipo_regularizacion" => false,
            "anticipo_documento_serie" => "",
            "anticipo_documento_numero" => ""
        ];
        $items[] = $item;
    }

    $total_exportacion = (float)$c['subtotal'];

    // 5. Construir payload Nubefact
    $nubefact_payload = [
        "operacion"                         => "generar_comprobante",
        "tipo_de_comprobante"               => 1, // Factura
        "serie"                             => $c['serie'],
        "numero"                            => $numero,
        "sunat_transaction"                 => 2, // Exportación
        "cliente_tipo_de_documento"         => 0, // Sin RUC
        "cliente_numero_de_documento"       => "-",
        "cliente_denominacion"              => $c['cliente_razon_social'],
        "cliente_direccion"                 => $c['cliente_direccion'] ?: "-",
        "cliente_email"                     => "",
        "cliente_email_1"                   => "",
        "cliente_email_2"                   => "",
        "fecha_de_emision"                  => $c['fecha_emision'],
        "fecha_de_vencimiento"              => $c['fecha_emision'],
        "moneda"                            => $c['moneda'] ?: "USD",
        "tipo_de_cambio"                    => (float)($c['tipo_cambio'] ?: 1),
        "porcentaje_de_descuento"           => 0,
        "descuento_global"                  => 0,
        "total_descuento"                   => 0,
        "total_anticipo"                    => 0,
        "total_gravada"                     => 0,
        "total_inafecta"                    => 0,
        "total_exonerada"                   => 0,
        "total_exportacion"                 => $total_exportacion,
        "total_gratuita"                    => 0,
        "total_igv"                         => 0,
        "total"                             => $total_exportacion,
        "total_otros_cargos"                => 0,
        "total_isc"                         => 0,
        "total_otros_tributos"              => 0,
        "observaciones"                     => "Exportación - Art. 33° TUO IGV. " .
                                               "Incoterm: " . ($c['incoterm'] ?: '-') .
                                               " | País destino: " . ($c['pais_destino_nombre'] ?: '-') .
                                               " | Puerto: " . ($c['puerto_embarque'] ?: '-'),
        "documento_que_se_modifica_tipo"    => "",
        "documento_que_se_modifica_serie"   => "",
        "documento_que_se_modifica_numero"  => "",
        "tipo_de_nota_de_credito"           => "",
        "tipo_de_nota_de_debito"            => "",
        "enviar_automaticamente_a_la_sunat" => true,
        "enviar_automaticamente_al_cliente" => false,
        "codigo_unico"                      => "",
        "condiciones_de_pago"               => "Contado",
        "medio_de_pago"                     => "Transferencia",
        "placa_vehiculo"                    => "",
        "orden_compra_servicio"             => "",
        "tabla_personalizada_codigo"        => "",
        "formato_de_pdf"                    => "A4",
        "items"                             => $items
    ];

    // 6. Enviar a Nubefact con cURL
    $ch = curl_init($nubefact_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($nubefact_payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Token ' . $nubefact_token
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response_raw = curl_exec($ch);
    $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error   = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new Exception("Error de conexión con Nubefact: $curl_error");
    }

    $response = json_decode($response_raw, true);
    if (!$response) {
        throw new Exception("Respuesta inválida de Nubefact. HTTP $http_code: $response_raw");
    }

    // 7. Interpretar respuesta
    $aceptado     = !empty($response['aceptada_por_sunat']);
    $cdr_estado   = $aceptado ? 'Aceptado' : (($response['codigo'] ?? null) === '0' ? 'Aceptado' : 'Rechazado');
    $descripcion  = $response['sunat_description'] ?? $response['sunat_note'] ?? $response['errors'] ?? 'Sin descripción';

    if (is_array($descripcion)) {
        $descripcion = implode(', ', array_values($descripcion));
    }

    $nubefact_numero = $response['numero'] ?? $numero;

    // 8. Actualizar base de datos
    $stmt = $conn->prepare("UPDATE comprobantes SET
        nubefact_enviado = 1,
        sunat_cdr_estado = ?,
        sunat_cdr_descripcion = ?,
        sunat_enlace_pdf = ?,
        sunat_enlace_xml = ?,
        sunat_enlace_cdr = ?,
        sunat_cadena_con_hash = ?,
        sunat_hash_cpe = ?,
        sunat_qr = ?,
        nubefact_numero_comprobante = ?,
        fecha_envio_sunat = NOW()
        WHERE id = ?");

    $stmt->execute([
        $cdr_estado,
        substr((string)$descripcion, 0, 500),
        $response['enlace_del_pdf']            ?? null,
        $response['enlace_del_xml']            ?? null,
        $response['enlace_del_cdr']            ?? null,
        $response['cadena_para_codigo_qr']     ?? null,
        $response['codigo_hash']               ?? null,
        $response['enlace_del_codigo_qr']      ?? null,
        (int)$nubefact_numero,
        $comprobante_id
    ]);

    return [
        'success'      => true,
        'aceptado'     => $aceptado,
        'cdr_estado'   => $cdr_estado,
        'descripcion'  => $descripcion,
        'enlace_pdf'   => $response['enlace_del_pdf'] ?? null,
        'enlace_xml'   => $response['enlace_del_xml'] ?? null,
        'enlace_cdr'   => $response['enlace_del_cdr'] ?? null,
        'qr_url'       => $response['enlace_del_codigo_qr'] ?? null,
        'numero'       => $nubefact_numero,
        'raw_response' => $response
    ];
}

// Si se ejecuta como endpoint directo vía web
if (basename($_SERVER['PHP_SELF']) === 'enviar_sunat.php') {
    header('Content-Type: application/json; charset=utf-8');
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    $comprobante_id = (int)($input['comprobante_id'] ?? 0);

    if (!$comprobante_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Se requiere comprobante_id']);
        exit;
    }

    try {
        $resultado = enviarComprobanteNubefact($comprobante_id, $conn);
        echo json_encode($resultado);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
