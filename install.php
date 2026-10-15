<?php
/**
 * FACTUSYS - Asistente de Instalación & Configuración de Base de Datos
 */

$configFile = __DIR__ . '/includes/db.config.php';
$installed = false;
$error = '';
$success = '';

// Verificar si ya está conectado
if (file_exists($configFile)) {
    try {
        require_once $configFile;
        if (!empty($database) && !empty($username)) {
            $testConn = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $installed = true;
        }
    } catch (Exception $e) {
        $installed = false;
    }
}

// Si ya está instalado y no es una reconfiguración forzada, permitir ir al login
if ($installed && empty($_GET['force'])) {
    header("Location: login.php");
    exit;
}

$dbHost = $_POST['db_host'] ?? ($host ?? 'localhost');
$dbName = $_POST['db_name'] ?? ($database ?? '');
$dbUser = $_POST['db_user'] ?? ($username ?? '');
$dbPass = $_POST['db_pass'] ?? ($password ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($dbHost);
    $dbName = trim($dbName);
    $dbUser = trim($dbUser);
    $dbPass = (string)$dbPass;

    if (empty($dbName) || empty($dbUser)) {
        $error = "Por favor ingresa el nombre de la base de datos y el usuario MySQL.";
    } else {
        try {
            // 1. Probar conexión directa
            $conn = null;
            try {
                $conn = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } catch (PDOException $exDb) {
                // Si la BD no existe, intentar crearla
                if ($exDb->getCode() == 1049 || strpos($exDb->getMessage(), 'Unknown database') !== false) {
                    $pdoRoot = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $conn = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                } else {
                    throw $exDb;
                }
            }

            // 2. Guardar configuración en includes/db.config.php
            $configContent = "<?php\n"
                . "// CONFIGURACIÓN DE BASE DE DATOS (GENERADO POR ASISTENTE WEB)\n"
                . "\$host = " . var_export($dbHost, true) . ";\n"
                . "\$database = " . var_export($dbName, true) . ";\n"
                . "\$username = " . var_export($dbUser, true) . ";\n"
                . "\$password = " . var_export($dbPass, true) . ";\n";

            file_put_contents($configFile, $configContent);

            // 3. Ejecutar Migraciones Automáticas para crear las tablas
            require_once __DIR__ . '/includes/migrator.php';
            $migResult = Migrator::runMigrations($conn);

            $success = "¡Conexión establecida exitosamente! Se configuró la base de datos y se aplicaron las migraciones del sistema.";

        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Access denied') !== false) {
                $error = "Acceso denegado: El usuario MySQL o la contraseña son incorrectos. En cPanel recuerda que el usuario suele tener un prefijo (ej: <code>usuario_bduser</code>) y debe estar asignado a la base de datos con TODOS LOS PRIVILEGIOS.";
            } elseif (strpos($msg, 'Unknown database') !== false) {
                $error = "Base de datos no encontrada: La base de datos <code>$dbName</code> no existe. Por favor créala primero en cPanel en la sección <b>Bases de Datos MySQL</b>.";
            } else {
                $error = "Error al conectar con MySQL: " . $msg;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Base de Datos • FACTUSYS</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="h-full text-slate-100 flex items-center justify-center p-4 bg-radial from-slate-900 to-slate-950">

    <div class="w-full max-w-lg space-y-6">

        <!-- Logo & Header -->
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-600 to-indigo-500 shadow-xl shadow-indigo-500/20 text-white text-2xl mb-2">
                <i class="fa-solid fa-database"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-white tracking-tight">FACTUSYS</h1>
            <p class="text-xs text-slate-400">Asistente de Configuración de Base de Datos para Producción</p>
        </div>

        <!-- Card Form -->
        <div class="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 shadow-2xl backdrop-blur-xl">

            <?php if (!empty($success)): ?>
                <div class="p-5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs space-y-4">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-emerald-400 text-xl"></i>
                        <div>
                            <h4 class="font-bold text-sm text-white">¡Instalación Exitosa!</h4>
                            <p class="text-emerald-300/90 mt-0.5"><?php echo htmlspecialchars($success); ?></p>
                        </div>
                    </div>
                    <div class="pt-2 border-t border-emerald-500/20 flex justify-end">
                        <a href="login.php" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-xl shadow-lg transition-all flex items-center gap-2">
                            <span>Ingresar al Sistema</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php else: ?>

                <?php if (!empty($error)): ?>
                    <div class="mb-5 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-rose-400 text-base mt-0.5 shrink-0"></i>
                        <div class="leading-relaxed"><?php echo $error; ?></div>
                    </div>
                <?php endif; ?>

                <div class="mb-5 p-3.5 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-300 text-xs flex items-start gap-2.5">
                    <i class="fa-solid fa-circle-info text-indigo-400 text-sm mt-0.5 shrink-0"></i>
                    <p class="leading-relaxed">
                        Ingresa los datos de la base de datos que creaste en tu panel de <b>cPanel</b> en <i>Bases de Datos MySQL</i>.
                    </p>
                </div>

                <form method="POST" action="install.php" class="space-y-4">
                    
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Servidor MySQL (Host)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500 pointer-events-none">
                                <i class="fa-solid fa-server text-xs"></i>
                            </span>
                            <input type="text" name="db_host" required value="<?php echo htmlspecialchars($dbHost); ?>" placeholder="localhost" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all font-mono">
                        </div>
                        <p class="text-[10px] text-slate-500 mt-1">En el 99% de servidores cPanel es <code>localhost</code>.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nombre de la Base de Datos</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500 pointer-events-none">
                                <i class="fa-solid fa-database text-xs"></i>
                            </span>
                            <input type="text" name="db_name" required value="<?php echo htmlspecialchars($dbName); ?>" placeholder="ej: granelfa_facturacion" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all font-mono">
                        </div>
                        <p class="text-[10px] text-slate-500 mt-1">Nombre completo creado en cPanel (incluyendo prefijo).</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Usuario MySQL</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500 pointer-events-none">
                                <i class="fa-solid fa-user text-xs"></i>
                            </span>
                            <input type="text" name="db_user" required value="<?php echo htmlspecialchars($dbUser); ?>" placeholder="ej: granelfa_admin" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all font-mono">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Contraseña de Usuario MySQL</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500 pointer-events-none">
                                <i class="fa-solid fa-lock text-xs"></i>
                            </span>
                            <input type="password" name="db_pass" value="<?php echo htmlspecialchars($dbPass); ?>" placeholder="••••••••••••" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all font-mono">
                        </div>
                    </div>

                    <div class="pt-3">
                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-brand-600 via-indigo-600 to-cyan-600 hover:from-brand-500 hover:to-cyan-500 text-white font-bold text-xs rounded-xl shadow-lg shadow-indigo-500/25 transition-all flex items-center justify-center gap-2">
                            <i class="fa-solid fa-plug-circle-check"></i>
                            <span>Probar Conexión e Instalar Base de Datos</span>
                        </button>
                    </div>

                </form>

            <?php endif; ?>

        </div>

        <!-- Footer Notice -->
        <div class="text-center text-[11px] text-slate-500 space-y-1">
            <p>FACTUSYS Facturación Electrónica SUNAT UBL 2.1 & CRM</p>
            <p>Tus credenciales se almacenarán de forma segura en <code class="text-slate-400">includes/db.config.php</code>.</p>
        </div>

    </div>

</body>
</html>
