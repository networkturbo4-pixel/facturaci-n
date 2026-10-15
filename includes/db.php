<?php
/**
 * FACTUSYS - Conexión de Base de Datos & Configuración Aislada
 */

// Valores por defecto
$host = "localhost";
$username = "root";
$password = "";
$database = "facturacion_crm";

// Cargar configuración local privada (Ignorada por Git para proteger credenciales locales)
$localConfig = __DIR__ . '/db.config.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
} else {
    // Generar archivo de configuración local automáticamente para que git pull jamás lo sobreescriba
    $initialConfig = "<?php\n"
        . "// CONFIGURACIÓN LOCAL DE BASE DE DATOS (PROTEGIDA DE GIT Y ACTUALIZACIONES)\n"
        . "\$host = " . var_export($host, true) . ";\n"
        . "\$username = " . var_export($username, true) . ";\n"
        . "\$password = " . var_export($password, true) . ";\n"
        . "\$database = " . var_export($database, true) . ";\n";
    @file_put_contents($localConfig, $initialConfig);
}

// Soporte para variables de entorno (Docker, Kubernetes, Cloud VPS, .env)
$host     = getenv('DB_HOST') ?: $host;
$username = getenv('DB_USER') ?: $username;
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : $password;
$database = getenv('DB_NAME') ?: $database;

try {
    $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch(PDOException $e) {
    // Si la base de datos no existe (código 1049 o texto), inicializarla automáticamente con el Migrador
    if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
        try {
            $pdoRoot = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
            $pdoRoot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdoRoot->exec("USE `$database`");

            $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            require_once __DIR__ . '/migrator.php';
            Migrator::runMigrations($conn);
        } catch(PDOException $ex) {
            error_log("FACTUSYS DB Init Error: " . $ex->getMessage());
            die("Error al inicializar la base de datos. Por favor revise los permisos del usuario MySQL o el log del servidor.");
        }
    } else {
        error_log("FACTUSYS DB Connection Error: " . $e->getMessage());
        die("Error de conexión a la base de datos. Verifique sus credenciales en <code>includes/db.config.php</code> o variables de entorno.");
    }
}
?>
