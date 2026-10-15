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

try {
    $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // Si la base de datos no existe (código 1049 o texto), inicializarla automáticamente con el Migrador
    if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
        try {
            $pdoRoot = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
            $pdoRoot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdoRoot->exec("USE `$database`");

            $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            require_once __DIR__ . '/migrator.php';
            Migrator::runMigrations($conn);
        } catch(PDOException $ex) {
            die("Error al inicializar automáticamente la base de datos: " . $ex->getMessage());
        }
    } else {
        die("Error de conexión a la base de datos: " . $e->getMessage());
    }
}
?>
