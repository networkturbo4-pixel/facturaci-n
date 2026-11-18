<?php
/**
 * api/puntos_traslado.php
 * Gestión centralizada de Puntos de Partida y Llegada pre-registrados para Guías de Remisión.
 * Almacena los listados en la tabla `configuracion` (claves `guia_puntos_partida` y `guia_puntos_llegada`).
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado. Inicie sesión.']);
    exit;
}

function getPuntosByTipo(PDO $conn, string $tipo): array {
    $clave = $tipo === 'llegada' ? 'guia_puntos_llegada' : 'guia_puntos_partida';
    $raw = getSystemConfig($clave, '[]', true);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

function savePuntosByTipo(PDO $conn, string $tipo, array $puntos): void {
    $clave = $tipo === 'llegada' ? 'guia_puntos_llegada' : 'guia_puntos_partida';
    $json = json_encode(array_values($puntos), JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $stmt->execute([$clave, $json]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $partida = getPuntosByTipo($conn, 'partida');
    $llegada = getPuntosByTipo($conn, 'llegada');

    // Incluir dirección fiscal de la empresa como sugerencia si no hay puntos de partida registrados
    $empresaDir = trim(getSystemConfig('empresa_direccion', ''));
    $empresaUbigeo = trim(getSystemConfig('empresa_ubigeo', '150101'));

    echo json_encode([
        'success' => true,
        'partida' => $partida,
        'llegada' => $llegada,
        'empresa_default' => [
            'alias'     => 'Local Principal (Dirección Fiscal)',
            'direccion' => $empresaDir,
            'ubigeo'    => $empresaUbigeo ?: '150101'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? 'save';
    $tipo   = ($input['tipo'] ?? 'partida') === 'llegada' ? 'llegada' : 'partida';

    try {
        $puntos = getPuntosByTipo($conn, $tipo);

        if ($action === 'delete') {
            $id = trim((string)($input['id'] ?? ''));
            $puntos = array_filter($puntos, fn($p) => ($p['id'] ?? '') !== $id);
            savePuntosByTipo($conn, $tipo, $puntos);
            echo json_encode(['success' => true, 'message' => 'Punto eliminado.', 'puntos' => array_values($puntos)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $alias     = trim((string)($input['alias'] ?? ''));
        $direccion = trim((string)($input['direccion'] ?? ''));
        $ubigeo    = preg_replace('/\D/', '', (string)($input['ubigeo'] ?? ''));
        $lat       = isset($input['lat']) && $input['lat'] !== '' ? round((float)$input['lat'], 6) : null;
        $lng       = isset($input['lng']) && $input['lng'] !== '' ? round((float)$input['lng'], 6) : null;

        if ($direccion === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'La dirección es obligatoria.']);
            exit;
        }

        if ($alias === '') {
            $alias = mb_substr($direccion, 0, 35);
        }
        if (strlen($ubigeo) !== 6) {
            $ubigeo = $ubigeo ?: '150101';
        }

        $id = trim((string)($input['id'] ?? ''));
        if ($id === '') {
            $id = 'pt_' . bin2hex(random_bytes(5));
        }

        $nuevoPunto = [
            'id'        => $id,
            'alias'     => $alias,
            'direccion' => $direccion,
            'ubigeo'    => $ubigeo,
            'lat'       => $lat,
            'lng'       => $lng
        ];

        $updated = false;
        foreach ($puntos as &$p) {
            if (($p['id'] ?? '') === $id) {
                $p = $nuevoPunto;
                $updated = true;
                break;
            }
        }
        unset($p);

        if (!$updated) {
            $puntos[] = $nuevoPunto;
        }

        savePuntosByTipo($conn, $tipo, $puntos);

        echo json_encode([
            'success' => true,
            'message' => 'Punto guardado correctamente.',
            'punto'   => $nuevoPunto,
            'puntos'  => array_values($puntos)
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
