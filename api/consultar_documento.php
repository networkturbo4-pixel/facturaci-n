<?php
/**
 * api/consultar_documento.php
 * Proxy seguro para consulta de DNI y RUC (SUNAT/RENIEC).
 * Oculta el token privado del navegador y aprovecha la caché local de clientes.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

// Exigir usuario autenticado
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado. Inicie sesión.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
} else {
    $input = $_GET;
}

$tipoDoc = strtoupper(trim($input['tipo_doc'] ?? $input['tipo'] ?? ''));
$numDoc  = trim($input['num_doc'] ?? $input['numero'] ?? $input['documento'] ?? '');

if (empty($numDoc)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Número de documento requerido']);
    exit;
}

// Auto-detección por longitud si no se especificó
if (empty($tipoDoc)) {
    if (strlen($numDoc) === 8)  $tipoDoc = 'DNI';
    elseif (strlen($numDoc) === 11) $tipoDoc = 'RUC';
}

if (!in_array($tipoDoc, ['DNI', 'RUC'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipo de documento no soportado. Use DNI o RUC.']);
    exit;
}

// 1. Verificar si ya existe en la base de datos local para ahorrar cuota y responder de inmediato
try {
    $stmt = $conn->prepare("SELECT numero_documento, razon_social, direccion_fiscal, correo_electronico, telefono FROM clientes WHERE numero_documento = ? LIMIT 1");
    $stmt->execute([$numDoc]);
    $local = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($local) {
        echo json_encode([
            'success' => true,
            'source'  => 'local_database',
            'data'    => [
                'numero'       => $local['numero_documento'],
                'razon_social' => $local['razon_social'],
                'direccion'    => $local['direccion_fiscal'] ?: '-',
                'correo'       => $local['correo_electronico'] ?: '',
                'telefono'     => $local['telefono'] ?: ''
            ]
        ]);
        exit;
    }
} catch (Exception $e) {}

// 2. Consultar API externa con token protegido
$token = getSystemConfig('api_token_documento', '4c06130c30c4789e8f0e66ad9eafe82c3bbea10e2895503e9a2359b75fea');
if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Token de consulta no configurado en el sistema.']);
    exit;
}

$endpoint = ($tipoDoc === 'DNI') ? 'https://api.json.pe/api/dni' : 'https://api.json.pe/api/ruc';
$body = ($tipoDoc === 'DNI') ? ['dni' => $numDoc] : ['ruc' => $numDoc];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true
]);

$responseRaw = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr     = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Error de conexión con el servicio de consulta: ' . $curlErr]);
    exit;
}

$jsonRes = json_decode($responseRaw, true);
if (!$jsonRes) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Respuesta no válida del servicio externo.']);
    exit;
}

if (empty($jsonRes['success'])) {
    echo json_encode([
        'success' => false,
        'message' => $jsonRes['message'] ?? 'Documento no encontrado en padrón SUNAT/RENIEC.'
    ]);
    exit;
}

$data = $jsonRes['data'] ?? [];
$nombre = '';
$direccion = '';

if ($tipoDoc === 'DNI') {
    $nombre = $data['nombre_completo'] ?? trim(($data['nombres'] ?? '') . ' ' . ($data['apellido_paterno'] ?? '') . ' ' . ($data['apellido_materno'] ?? ''));
} else {
    $nombre = $data['nombre_o_razon_social'] ?? $data['razon_social'] ?? $data['nombre_completo'] ?? '';
    $direccion = $data['direccion_completa'] ?? $data['direccion'] ?? '-';
}

echo json_encode([
    'success' => true,
    'source'  => 'sunat_reniec_api',
    'data'    => [
        'numero'       => $numDoc,
        'razon_social' => $nombre,
        'direccion'    => $direccion,
        'estado'       => $data['estado'] ?? 'ACTIVO',
        'condicion'    => $data['condicion'] ?? 'HABIDO'
    ]
]);
