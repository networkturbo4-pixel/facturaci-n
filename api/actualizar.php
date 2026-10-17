<?php
/**
 * FACTUSYS - API de Actualizaciones del Sistema & Migraciones Automáticas
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = currentUser();
if (($user['rol'] ?? '') !== 'Administrador') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado: Se requiere rol de Administrador para gestionar actualizaciones.'
    ]);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/migrator.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'status';

function getGitBinary() {
    static $bin = null;
    if ($bin !== null) return $bin;
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    if ($isWin) {
        $common = [
            'C:\\Program Files\\Git\\cmd\\git.exe',
            'C:\\Program Files\\Git\\bin\\git.exe',
            'C:\\Program Files (x86)\\Git\\cmd\\git.exe',
            'C:\\Git\\bin\\git.exe'
        ];
        foreach ($common as $p) {
            if (file_exists($p)) {
                $bin = '"' . $p . '"';
                return $bin;
            }
        }
        $bin = 'git';
        return $bin;
    } else {
        $commonLinux = [
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/bin/git',
            '/usr/local/cpanel/3rdparty/bin/git'
        ];
        foreach ($commonLinux as $p) {
            if (file_exists($p) && is_executable($p)) {
                $bin = $p;
                return $bin;
            }
        }
        $bin = 'git';
        return $bin;
    }
}

function getRepoDirectory() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $d = dirname(__DIR__);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $short = @shell_exec('cmd /c for %I in ("' . $d . '") do @echo %~fsI');
        if (!empty($short) && file_exists(trim($short))) {
            $dir = trim($short);
            return $dir;
        }
    }
    $real = realpath($d);
    $dir = $real ?: $d;
    return $dir;
}

function getGitEnvironment() {
    static $env = null;
    if ($env !== null) return $env;
    
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    if ($isWin) {
        $userProfile = getenv('USERPROFILE');
        if (!$userProfile || !file_exists($userProfile)) {
            $users = glob('C:\\Users\\*', GLOB_ONLYDIR);
            foreach ($users as $u) {
                if (!in_array(basename($u), ['Public', 'Default', 'All Users', 'Default User']) && file_exists($u . '/.gitconfig')) {
                    $userProfile = $u;
                    break;
                }
            }
        }
        $userProfile = $userProfile ?: 'C:\\Users\\CESAR';
        
        $env = [
            'PATH' => getenv('PATH') . ';C:\\Program Files\\Git\\cmd;C:\\Program Files\\Git\\bin',
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            'USERPROFILE' => $userProfile,
            'HOME' => $userProfile,
            'HOMEDRIVE' => 'C:',
            'HOMEPATH' => str_replace('C:', '', $userProfile),
            'LOCALAPPDATA' => $userProfile . '\\AppData\\Local',
            'APPDATA' => $userProfile . '\\AppData\\Roaming',
            'GIT_TERMINAL_PROMPT' => '0'
        ];
    } else {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $env = [
            'PATH' => (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') . ':/usr/local/cpanel/3rdparty/bin',
            'HOME' => $home,
            'GIT_TERMINAL_PROMPT' => '0'
        ];
    }
    return $env;
}

function runGitCommand($gitSubcmd, $workingDir = null) {
    if (!function_exists('proc_open')) {
        return ['output' => 'La función proc_open está deshabilitada en este hosting.', 'code' => -99];
    }
    $workingDir = $workingDir ?: getRepoDirectory();
    $gitBin = getGitBinary();
    $sub = (strpos(trim($gitSubcmd), 'git ') === 0) ? substr(trim($gitSubcmd), 4) : trim($gitSubcmd);
    
    $cmd = "{$gitBin} -c safe.directory=* -c core.quotepath=false -C \"{$workingDir}\" {$sub}";
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = @proc_open($cmd, $descriptor, $pipes, $workingDir, getGitEnvironment());
    if (!is_resource($process)) {
        return ['output' => 'Error al iniciar proceso Git (proc_open restringido o git inaccesible)', 'code' => -1];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    
    $out = trim($stdout);
    if ($stderr && $code !== 0) {
        $out = trim($out . "\n" . trim($stderr));
    } elseif ($stderr && empty($out)) {
        $out = trim($stderr);
    }
    return ['output' => $out, 'code' => $code];
}

function isGitRepository($repoDir) {
    if (file_exists($repoDir . '/.git') || is_dir($repoDir . '/.git')) {
        return true;
    }
    $test = runGitCommand('rev-parse --is-inside-work-tree', $repoDir);
    return trim($test['output']) === 'true';
}

function ensureGitRepository($repoDir, $remoteUrl = 'https://github.com/networkturbo4-pixel/facturaci-n.git') {
    if (isGitRepository($repoDir)) {
        return true;
    }
    runGitCommand('init', $repoDir);
    runGitCommand('config core.autocrlf true', $repoDir);
    runGitCommand("remote add origin {$remoteUrl}", $repoDir);
    runGitCommand('fetch origin main:refs/remotes/origin/main', $repoDir);
    runGitCommand('branch -M main', $repoDir);
    return isGitRepository($repoDir);
}

/**
 * Sincroniza forzosamente el código con origin/main (git reset --hard) preservando
 * archivos locales críticos del servidor (credenciales, .htaccess, .env).
 * Se usa cuando el repo local no tiene commit (HEAD inexistente, p.ej. creado con git init + fetch)
 * o cuando "git pull" aborta por archivos sin seguimiento que serían sobrescritos.
 */
function forceSyncWithRemote($repoDir, array &$logs) {
    $protected = ['includes/db.config.php', '.htaccess', '.env'];
    $backups = [];
    foreach ($protected as $rel) {
        $path = $repoDir . '/' . $rel;
        if (is_file($path)) {
            $backups[$rel] = file_get_contents($path);
        }
    }

    $res = runGitCommand('reset --hard origin/main', $repoDir);
    $logs[] = ' ✓ Sincronización forzada (git reset --hard origin/main): ' . ($res['output'] ?: 'OK');

    // Asegurar que la rama local quede apuntando a main
    runGitCommand('checkout -B main origin/main', $repoDir);

    foreach ($backups as $rel => $content) {
        @file_put_contents($repoDir . '/' . $rel, $content);
    }
    $logs[] = ' ✓ Configuración local protegida y restaurada (' . implode(', ', array_keys($backups)) . ').';

    return $res['code'] === 0;
}

$repoDir = getRepoDirectory();

switch ($action) {
    case 'status':
        $migrationStatus = Migrator::getStatus($conn);

        // Información de Git con auto-detección y safe.directory
        $gitVer = runGitCommand('version', $repoDir);
        $gitInstalled = ($gitVer['code'] === 0);
        $isGitRepo = isGitRepository($repoDir);
        $currentBranch = 'desconocido';
        $currentCommit = '';
        $remoteUrl = '';

        if ($isGitRepo && $gitInstalled) {
            $branchRes = runGitCommand('branch --show-current', $repoDir);
            $bOut = trim($branchRes['output'] ?? '');
            $currentBranch = ($branchRes['code'] === 0 && strpos($bOut, 'fatal:') === false && !empty($bOut)) ? $bOut : 'main';

            $commitRes = runGitCommand('rev-parse --short HEAD', $repoDir);
            $cOut = trim($commitRes['output'] ?? '');
            $currentCommit = ($commitRes['code'] === 0 && strpos($cOut, 'fatal:') === false && preg_match('/^[a-f0-9]{6,40}$/i', $cOut)) ? $cOut : '';

            $remoteRes = runGitCommand('config --get remote.origin.url', $repoDir);
            $rOut = trim($remoteRes['output'] ?? '');
            $remoteUrl = ($remoteRes['code'] === 0 && strpos($rOut, 'fatal:') === false && !empty($rOut)) ? $rOut : '';
        }

        // Historial de actualizaciones recientes
        $updatesHistory = [];
        try {
            $stmtH = $conn->query("SELECT * FROM sistema_actualizaciones ORDER BY id DESC LIMIT 5");
            $updatesHistory = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'git_installed' => $gitInstalled,
            'is_git_repo' => $isGitRepo,
            'current_branch' => $currentBranch,
            'current_commit' => $currentCommit,
            'remote_url' => $remoteUrl ?: 'https://github.com/networkturbo4-pixel/facturaci-n.git',
            'migrations' => $migrationStatus,
            'history' => $updatesHistory
        ]);
        break;

    case 'check':
        // Obtener configuración de token de GitHub si existe
        $ghToken = '';
        try {
            $stG = $conn->query("SELECT valor FROM configuracion WHERE clave = 'github_token' LIMIT 1");
            $ghToken = trim($stG->fetchColumn() ?: '');
        } catch (Exception $e) {}

        $procEnabled = function_exists('proc_open');
        $gitVer = runGitCommand('version', $repoDir);
        $gitWorking = ($procEnabled && $gitVer['code'] === 0);

        if (!$gitWorking) {
            echo json_encode([
                'success' => false,
                'has_updates' => false,
                'git_installed' => false,
                'message' => 'Git CLI no está disponible o proc_open está deshabilitado en este hosting cPanel. Puedes actualizar subiendo directamente el paquete factusys_actualizacion_latest.zip con el botón "Subir Paquete ZIP" o vía Administrador de Archivos de cPanel.'
            ]);
            break;
        }

        ensureGitRepository($repoDir);
        if (!isGitRepository($repoDir)) {
            echo json_encode([
                'success' => false,
                'has_updates' => false,
                'message' => 'El directorio actual no cuenta con un repositorio Git (.git) inicializado.'
            ]);
            break;
        }

        if ($ghToken) {
            runGitCommand("config http.https://github.com/.extraheader \"AUTHORIZATION: bearer {$ghToken}\"", $repoDir);
        }

        // Fetch garantizando actualización de origin/main
        $fetchRes = runGitCommand('fetch origin main:refs/remotes/origin/main', $repoDir);
        if ($fetchRes['code'] !== 0) {
            $errText = trim($fetchRes['output']);
            $isAuthErr = (stripos($errText, 'could not read Username') !== false || stripos($errText, 'Authentication failed') !== false);
            echo json_encode([
                'success' => false,
                'has_updates' => false,
                'git_error' => $errText,
                'message' => $isAuthErr 
                    ? 'Autenticación requerida para acceder al repositorio privado en GitHub. Configura tu Personal Access Token (PAT) de GitHub en los ajustes.'
                    : 'Error al conectar con GitHub: ' . ($errText ?: 'No se pudo completar git fetch en el servidor.')
            ]);
            break;
        }

        $localRes = runGitCommand('rev-parse --short HEAD', $repoDir);
        $localCommit = ($localRes['code'] === 0 && preg_match('/^[a-f0-9]{6,40}$/i', trim($localRes['output'] ?? ''))) ? trim($localRes['output']) : '';

        $remoteRes = runGitCommand('rev-parse --short origin/main', $repoDir);
        $remoteCommit = ($remoteRes['code'] === 0 && preg_match('/^[a-f0-9]{6,40}$/i', trim($remoteRes['output'] ?? ''))) ? trim($remoteRes['output']) : '';

        if (empty($localCommit) && empty($remoteCommit)) {
            echo json_encode([
                'success' => false,
                'has_updates' => false,
                'message' => 'No se pudo leer el commit actual de la rama en el servidor.'
            ]);
            break;
        }

        // Comprobar con precisión cuántos commits remotos faltan por descargar localmente
        if (empty($localCommit) && !empty($remoteCommit)) {
            // Repo local sin commit (HEAD inexistente): todo el remoto es "nuevo"
            $commitsBehindCount = 1;
        } else {
            $behindCheck = runGitCommand('rev-list --count HEAD..origin/main', $repoDir);
            $bOut = trim($behindCheck['output'] ?? '');
            $commitsBehindCount = ($behindCheck['code'] === 0 && is_numeric($bOut)) ? (int)$bOut : 0;
        }

        $hasUpdates = ($commitsBehindCount > 0);
        $commitsBehind = [];

        if ($hasUpdates && !empty($localCommit)) {
            $logRes = runGitCommand('log HEAD..origin/main --oneline -n 10', $repoDir);
            if (!empty($logRes['output']) && strpos($logRes['output'], 'fatal:') === false) {
                $commitsBehind = explode("\n", trim($logRes['output']));
            }
        }

        // Reset OPcache if active so changes in PHP files are seen immediately
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        echo json_encode([
            'success' => true,
            'has_updates' => $hasUpdates,
            'local_commit' => $localCommit,
            'remote_commit' => $remoteCommit,
            'commits_behind' => $commitsBehind,
            'commits_behind_count' => $commitsBehindCount,
            'pending_migrations' => $migrationStatus['pending_count'],
            'message' => $hasUpdates 
                ? "¡Hay {$commitsBehindCount} nueva(s) actualización(es) disponibles en GitHub!" 
                : 'Tu sistema local ya cuenta con la versión más reciente (' . ($localCommit ? "#$localCommit" : 'Al día') . ').'
        ]);
        break;

    case 'update':
        $logs = [];
        $logs[] = '[1/4] Protegiendo configuraciones locales y base de datos...';

        // 1. Asegurar archivo de configuración local antes del pull
        $localConfig = __DIR__ . '/../includes/db.config.php';
        if (!file_exists($localConfig)) {
            $cfg = "<?php\n\$host = " . var_export($host, true) . ";\n\$username = " . var_export($username, true) . ";\n\$password = " . var_export($password, true) . ";\n\$database = " . var_export($database, true) . ";\n";
            file_put_contents($localConfig, $cfg);
        }
        $logs[] = ' ✓ Archivo db.config.php asegurado (las credenciales de base de datos no se tocarán).';

        // 2. Ejecutar Git Pull
        $logs[] = '[2/4] Conectando con el repositorio y descargando archivos nuevos...';
        ensureGitRepository($repoDir);
        if (!isGitRepository($repoDir)) {
            echo json_encode([
                'success' => false,
                'logs' => $logs,
                'message' => 'No se encontró el repositorio Git local (.git).'
            ]);
            exit;
        }

        // Reutilizar token si está configurado
        try {
            $stG = $conn->query("SELECT valor FROM configuracion WHERE clave = 'github_token' LIMIT 1");
            $ghToken = trim($stG->fetchColumn() ?: '');
            if ($ghToken) {
                runGitCommand("config http.https://github.com/.extraheader \"AUTHORIZATION: bearer {$ghToken}\"", $repoDir);
            }
        } catch (Exception $e) {}

        $fetchRes = runGitCommand('fetch origin main:refs/remotes/origin/main', $repoDir);
        if ($fetchRes['code'] !== 0) {
            echo json_encode([
                'success' => false,
                'logs' => $logs,
                'message' => 'No se pudo descargar desde GitHub: ' . $fetchRes['output']
            ]);
            exit;
        }

        $headCheck = runGitCommand('rev-parse --verify HEAD', $repoDir);
        $hasHead = ($headCheck['code'] === 0);

        $synced = false;
        if ($hasHead) {
            $pullRes = runGitCommand('pull origin main', $repoDir);
            $logs[] = ' ✓ Git Pull: ' . ($pullRes['output'] ?: 'Archivos sincronizados.');
            $synced = ($pullRes['code'] === 0 || strpos($pullRes['output'], 'Already up to date') !== false);
        } else {
            $logs[] = ' ! El repositorio local no tenía commit base; se sincronizará directamente con origin/main.';
        }

        if (!$synced) {
            // Pull abortado (archivos sin seguimiento, cambios locales o HEAD inexistente): sincronización forzada segura
            if ($hasHead) {
                $logs[] = ' ! Git Pull no pudo completarse. Aplicando sincronización forzada segura...';
            }
            $synced = forceSyncWithRemote($repoDir, $logs);
        }

        if (!$synced) {
            echo json_encode([
                'success' => false,
                'logs' => $logs,
                'message' => 'No se pudo sincronizar el código con el repositorio. Revisa los registros o sube el paquete ZIP manualmente.'
            ]);
            exit;
        }

        // 3. Ejecutar Migraciones de Base de Datos
        $logs[] = '[3/4] Verificando y aplicando migraciones de base de datos...';
        $migRes = Migrator::runMigrations($conn);
        if ($migRes['success']) {
            if ($migRes['count'] > 0) {
                $logs[] = " ✓ Se aplicaron {$migRes['count']} migración(es) a la base de datos: " . implode(', ', $migRes['applied']);
            } else {
                $logs[] = ' ✓ La base de datos ya contiene la estructura más reciente (0 migraciones pendientes).';
            }
        } else {
            $logs[] = ' ⚠️ Error en migraciones: ' . ($migRes['error'] ?? 'desconocido');
        }

        // 4. Registrar en historial de actualizaciones y limpiar OPcache
        $logs[] = '[4/4] Purgando caché de bytecode PHP y registrando actualización...';
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $logs[] = ' ✓ Memoria OPcache de PHP purgada con éxito.';
        }

        $newCommit = runGitCommand('rev-parse --short HEAD', $repoDir)['output'];
        try {
            $stmtIns = $conn->prepare("INSERT INTO sistema_actualizaciones (version, commit_hash, descripcion, estado, detalles, ejecutado_por) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                'v2.6.5',
                $newCommit ?: 'main',
                'Actualización ejecutada desde repositorio remoto',
                'Exitoso',
                implode("\n", $logs),
                $user['nombre'] ?? 'Administrador'
            ]);
        } catch (Exception $e) {}

        $logs[] = ' 🎉 ¡Actualización y sincronización completada con éxito!';

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'new_commit' => $newCommit,
            'migrations_applied' => $migRes['count'] ?? 0,
            'message' => 'El sistema se actualizó correctamente desde el repositorio sin alterar datos existentes.'
        ]);
        break;

    case 'upload_zip':
        if (empty($_FILES['zip_file']['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => 'No se seleccionó ningún archivo ZIP.']);
            exit;
        }
        $zipTmp = $_FILES['zip_file']['tmp_name'];
        if (!class_exists('ZipArchive')) {
            echo json_encode(['success' => false, 'message' => 'La extensión ZipArchive de PHP no está habilitada en el servidor.']);
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipTmp) !== true) {
            echo json_encode(['success' => false, 'message' => 'El archivo subido no es un archivo ZIP válido.']);
            exit;
        }

        $localConfig = __DIR__ . '/../includes/db.config.php';
        $dbConfigBackup = file_exists($localConfig) ? file_get_contents($localConfig) : null;

        $extractedCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entryName = str_replace('\\', '/', $stat['name']);
            if (str_ends_with($entryName, '/')) continue;
            if ($entryName === 'includes/db.config.php') continue;
            if ($entryName === '.htaccess' || $entryName === '.env') continue;
            if (str_starts_with($entryName, 'uploads/')) continue;

            $targetPath = $repoDir . '/' . $entryName;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
            $stream = $zip->getStream($stat['name']);
            if ($stream) {
                file_put_contents($targetPath, stream_get_contents($stream));
                fclose($stream);
                $extractedCount++;
            }
        }
        $zip->close();

        if ($dbConfigBackup !== null) {
            file_put_contents($localConfig, $dbConfigBackup);
        }

        $migRes = Migrator::runMigrations($conn);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        try {
            $stmtIns = $conn->prepare("INSERT INTO sistema_actualizaciones (version, commit_hash, descripcion, estado, detalles, ejecutado_por) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([
                'v2.6.5',
                'PAQUETE-ZIP',
                "Actualización manual mediante paquete ZIP ({$extractedCount} archivos actualizados)",
                'Exitoso',
                "Archivos reemplazados: {$extractedCount}\nMigraciones BD: " . ($migRes['count'] ?? 0),
                $user['nombre'] ?? 'Administrador'
            ]);
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'files_updated' => $extractedCount,
            'migrations' => $migRes['count'] ?? 0,
            'message' => "¡Actualización completada! Se actualizaron {$extractedCount} archivos y se aplicaron {$migRes['count']} migraciones de base de datos sin alterar configuraciones ni comprobantes existentes."
        ]);
        break;

    case 'migrate':
        // Ejecución manual de migraciones de base de datos
        $res = Migrator::runMigrations($conn);
        echo json_encode($res);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida.']);
        break;
}
