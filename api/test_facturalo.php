<?php
/**
 * api/test_facturalo.php
 * Verifica la conectividad y validez de la API Secret Key con Facturalo API v3.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = trim($input['token'] ?? $_GET['token'] ?? '');

if (empty($token)) {
    // Si no se proporcionó en la petición, buscar el guardado en la base de datos
    $stmt = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'facturalo_token' LIMIT 1");
    $stmt->execute();
    $token = $stmt->fetchColumn() ?: '';
}

if (empty($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Ingresa un token de Facturalo API v3 para probar la conexión.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/facturalo_service.php';
$resultado = probarConexionFacturalo($token);
echo json_encode($resultado);
