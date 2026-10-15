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
        echo json_encode(['status' => 'healthy', 'production_ready' => true, 'timestamp' => time()]);
    } else {
        http_response_code(503);
        echo json_encode(['status' => 'unhealthy', 'production_ready' => false, 'error' => 'Database unreachable']);
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

// Directorios con permisos de escritura requeridos (auto-crear si no existen)
$dirsToCheck = ['uploads', 'uploads/branding', 'storage', 'storage/xml', 'storage/cdr', 'storage/pdf', 'storage/certs'];
$writableDirs = [];
foreach ($dirsToCheck as $d) {
    $full = __DIR__ . '/../' . $d;
    if (!file_exists($full)) {
        @mkdir($full, 0755, true);
    }
    $writableDirs[$d] = is_writable($full);
}
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
