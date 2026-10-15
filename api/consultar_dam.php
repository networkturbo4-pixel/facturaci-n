<?php
/**
 * api/consultar_dam.php
 * Actualiza el estado de la DAM de exportación en la BD.
 * Método: POST con JSON { comprobante_id: N, numero_dam: "X", estado_dam: "Y" }
 *
 * Para consulta en SUNAT: https://www.sunat.gob.pe/aduanas/informaRaiz/index.html
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$comprobante_id = (int)($input['comprobante_id'] ?? 0);
$numero_dam     = trim($input['numero_dam'] ?? '');
$estado_dam     = trim($input['estado_dam'] ?? 'En Proceso');

$estados_validos = ['Sin DAM', 'En Proceso', 'Salida Autorizada', 'Exportado', 'Observado', 'Rechazado'];
if (!in_array($estado_dam, $estados_validos)) {
    $estado_dam = 'En Proceso';
}

if (!$comprobante_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Se requiere comprobante_id']);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE comprobantes SET numero_dam = ?, estado_dam = ? WHERE id = ?");
    $stmt->execute([$numero_dam ?: null, $estado_dam, $comprobante_id]);

    echo json_encode([
        'success'    => true,
        'message'    => 'Estado DAM actualizado correctamente',
        'numero_dam' => $numero_dam,
        'estado_dam' => $estado_dam,
        // URL para consultar en el portal de SUNAT Aduanas
        'url_sunat'  => 'https://www.sunat.gob.pe/aduanas/informaRaiz/index.html'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
