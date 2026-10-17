<?php
/**
 * api/tipo_cambio_bcrp.php
 * Conector oficial al Banco Central de Reserva del Perú (BCRP)
 * Obtiene el Tipo de Cambio Interbancario Venta (Serie PD04638PD) en tiempo real
 * y lo resguarda en la base de datos local para el sistema.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado. Inicie sesión.']);
    exit;
}

try {
    $fin = date('Y-m-d');
    $inicio = date('Y-m-d', strtotime('-10 days'));

    // Endpoint oficial REST API del BCRP
    // Serie PD04638PD: Tipo de cambio interbancario (S/ por US$) - Venta
    $url = "https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/{$inicio}/{$fin}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FactuSys/2.0'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $tcEncontrado = null;
    $fechaBcrp = null;

    if ($httpCode === 200 && !empty($res)) {
        $json = json_decode($res, true);
        if (!empty($json['periods']) && is_array($json['periods'])) {
            // Recorrer del más reciente al más antiguo para encontrar la última cotización hábil
            $periods = array_reverse($json['periods']);
            foreach ($periods as $p) {
                if (!empty($p['values'][0]) && is_numeric($p['values'][0])) {
                    $tcEncontrado = round((float)$p['values'][0], 4);
                    $fechaBcrp = $p['name'] ?? $fin;
                    break;
                }
            }
        }
    }

    // Si BCRP respondió con éxito, actualizar caché en base de datos
    if ($tcEncontrado && $tcEncontrado > 0) {
        try {
            $hoy = date('Y-m-d');
            $upd = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            $upd->execute(['tipo_cambio_cache', (string)$tcEncontrado]);
            $upd->execute(['tipo_cambio_fecha', $hoy]);
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'tc'      => $tcEncontrado,
            'fecha'   => $fechaBcrp,
            'fuente'  => 'BCRP Oficial (Serie PD04638PD)',
            'message' => "Tipo de cambio BCRP: S/ " . number_format($tcEncontrado, 4) . " ({$fechaBcrp})"
        ]);
        exit;
    }

    // Fallback: Si BCRP no responde temporalmente, usar caché local o valor por defecto
    $tcCache = (float)getSystemConfig('tipo_cambio_cache', 3.75);
    $fechaCache = getSystemConfig('tipo_cambio_fecha', date('Y-m-d'));

    echo json_encode([
        'success'  => true,
        'tc'       => $tcCache > 0 ? $tcCache : 3.75,
        'fecha'    => $fechaCache,
        'fuente'   => 'Caché del sistema (BCRP fuera de línea temporalmente)',
        'warning'  => $curlErr ?: 'Respuesta no numérica del BCRP',
        'message'  => "Usando último tipo de cambio registrado: S/ " . number_format($tcCache, 4)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar BCRP: ' . $e->getMessage()
    ]);
}
