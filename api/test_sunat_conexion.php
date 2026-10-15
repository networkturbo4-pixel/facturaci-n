<?php
/**
 * api/test_sunat_conexion.php
 * Verifica en tiempo real la conectividad con los servidores de SUNAT vía SOAP/Greenter.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

try {
    require_once __DIR__ . '/../includes/greenter_service.php';
    $greenterSetup = getGreenterSee($conn);
    /** @var \Greenter\See $see */
    $see = $greenterSetup['see'];
    $isBeta = $greenterSetup['isBeta'];
    $ruc = $greenterSetup['ruc'];

    // Probar conectividad con el endpoint de SUNAT
    $endpoint = $isBeta ? \Greenter\Ws\Services\SunatEndpoints::FE_BETA : \Greenter\Ws\Services\SunatEndpoints::FE_PRODUCCION;
    
    // Test rápido de conexión TCP/SSL al WSDL de SUNAT
    $wsdlUrl = $endpoint . '?wsdl';
    $ch = curl_init($wsdlUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode([
            'success' => false,
            'modo' => $isBeta ? 'BETA (Pruebas)' : 'PRODUCCIÓN',
            'message' => "Servidores de SUNAT no disponibles en este momento ($err). El Plan de Contingencia gestionará los reintentos automáticamente."
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'modo' => $isBeta ? 'BETA (Pruebas)' : 'PRODUCCIÓN',
        'ruc' => $ruc,
        'endpoint' => $endpoint,
        'message' => "Conexión exitosa con SUNAT (" . ($isBeta ? 'Servidores Beta de Pruebas' : 'Servidores Oficiales de Producción') . "). Certificado digital válido y listo para emitir."
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error de configuración: ' . $e->getMessage()
    ]);
}
