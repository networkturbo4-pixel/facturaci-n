<?php
/**
 * api/cron_reintentar_sunat.php
 * Proceso en segundo plano para reintentar el envío de comprobantes en contingencia.
 * Puede ser ejecutado por:
 * 1. CLI (Programador de Tareas de Windows / cron)
 * 2. Petición Web con sesión iniciada o con token secreto.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/greenter_service.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    session_start();
    $tokenSecret = 'contingencia_sunat_2026'; // Token opcional para llamadas automáticas HTTP
    $tokenParam = $_GET['token'] ?? '';

    $isLoggedIn = !empty($_SESSION['user_id']);
    $isAuthorizedToken = ($tokenParam === $tokenSecret);

    if (!$isLoggedIn && !$isAuthorizedToken) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_pendientes' => 0,
    'procesados' => 0,
    'aceptados' => 0,
    'aun_pendientes' => 0,
    'rechazados' => 0,
    'detalles' => []
];

try {
    // Buscar comprobantes en estado 'Pendiente SUNAT' emitidos en los últimos 7 días
    $stmt = $conn->prepare("SELECT id, tipo_comprobante, serie, correlativo, fecha_emision, total 
                            FROM comprobantes 
                            WHERE estado = 'Pendiente SUNAT' OR sunat_cdr_estado = 'Pendiente'
                            ORDER BY id ASC 
                            LIMIT 20");
    $stmt->execute();
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report['total_pendientes'] = count($pendientes);

    foreach ($pendientes as $p) {
        $cId = (int)$p['id'];
        $numCompleto = "{$p['serie']}-" . str_pad($p['correlativo'], 6, '0', STR_PAD_LEFT);
        $itemReport = [
            'id' => $cId,
            'comprobante' => $numCompleto,
            'estado_anterior' => 'Pendiente SUNAT'
        ];

        try {
            $res = enviarComprobanteGreenter($cId, $conn);
            $report['procesados']++;

            if (!empty($res['success'])) {
                $report['aceptados']++;
                $itemReport['resultado'] = 'Aceptado por SUNAT';
                $itemReport['cdr'] = $res['message'] ?? '';
            } elseif (!empty($res['contingencia'])) {
                $report['aun_pendientes']++;
                $itemReport['resultado'] = 'Aun en contingencia (SUNAT no disponible)';
                $itemReport['error'] = $res['message'] ?? '';
            } else {
                $report['rechazados']++;
                $itemReport['resultado'] = 'Rechazado por SUNAT';
                $itemReport['error'] = $res['message'] ?? '';
            }
        } catch (Throwable $e) {
            $report['procesados']++;
            $report['aun_pendientes']++;
            $itemReport['resultado'] = 'Excepción al reintentar';
            $itemReport['error'] = $e->getMessage();
        }

        $report['detalles'][] = $itemReport;
    }

} catch (Exception $e) {
    $report['error_fatal'] = $e->getMessage();
}

if ($isCli) {
    echo "=== SINCRONIZADOR SUNAT CONTINGENCIA ===\n";
    echo "Fecha: " . $report['timestamp'] . "\n";
    echo "Total pendientes: " . $report['total_pendientes'] . "\n";
    echo "Aceptados: " . $report['aceptados'] . "\n";
    echo "Aún pendientes: " . $report['aun_pendientes'] . "\n";
    echo "Rechazados: " . $report['rechazados'] . "\n";
    foreach ($report['detalles'] as $d) {
        echo "- {$d['comprobante']}: {$d['resultado']}\n";
    }
} else {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
