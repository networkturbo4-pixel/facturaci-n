<?php
/**
 * FACTUSYS - Health Check & Diagnóstico de Producción
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

// Si no es admin, solo reportar status simple para balanceadores de carga / uptime monitors
$isFullCheck = false;
if (isLoggedIn()) {
    $u = currentUser();
    if (($u['rol'] ?? '') === 'Administrador') {
        $isFullCheck = true;
    }
}

// 1. Verificación básica de conexión BD
$dbOk = false;
try {
    $conn->query("SELECT 1");
    $dbOk = true;
} catch (Exception $e) {}

if (!$isFullCheck) {
    if ($dbOk) {
        echo json_encode(['status' => 'healthy', 'timestamp' => time()]);
    } else {
        http_response_code(503);
        echo json_encode(['status' => 'unhealthy', 'error' => 'Database unreachable']);
    }
    exit;
}

// 2. Diagnóstico detallado para Administrador
$requiredExtensions = ['pdo_mysql', 'curl', 'openssl', 'mbstring', 'zip', 'gd'];
$extensionsStatus = [];
$allExtOk = true;
foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    $extensionsStatus[$ext] = $loaded;
    if (!$loaded) $allExtOk = false;
}

// Directorios con permisos de escritura requeridos
$writableDirs = [
    'uploads' => is_writable(__DIR__ . '/../uploads'),
    'uploads/branding' => is_writable(__DIR__ . '/../uploads/branding'),
    'storage' => is_writable(__DIR__ . '/../storage'),
    'storage/xml' => is_writable(__DIR__ . '/../storage/xml'),
    'storage/cdr' => is_writable(__DIR__ . '/../storage/cdr'),
    'storage/pdf' => is_writable(__DIR__ . '/../storage/pdf'),
    'storage/certs' => is_writable(__DIR__ . '/../storage/certs'),
];
$allDirsOk = !in_array(false, $writableDirs, true);

// Proveedor SUNAT configurado
$sunatModo = 'desconocido';
try {
    $stmtM = $conn->query("SELECT valor FROM configuracion WHERE clave = 'nubefact_modo' LIMIT 1");
    $sunatModo = $stmtM->fetchColumn() ?: 'beta';
} catch (Exception $e) {}

$isProductionReady = $dbOk && $allExtOk && $allDirsOk;

echo json_encode([
    'status' => $isProductionReady ? 'healthy' : 'warning',
    'php_version' => PHP_VERSION,
    'database' => $dbOk ? 'connected' : 'error',
    'sunat_mode' => $sunatModo,
    'extensions' => $extensionsStatus,
    'permissions' => $writableDirs,
    'production_ready' => $isProductionReady
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
