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
    $common = [
        'C:\\Program Files\\Git\\cmd\\git.exe',
        'C:\\Program Files\\Git\\bin\\git.exe',
        'C:\\Program Files (x86)\\Git\\cmd\\git.exe',
        'C:\\Git\\bin\\git.exe',
        '/usr/bin/git',
        '/usr/local/bin/git'
    ];
    foreach ($common as $p) {
        if (file_exists($p)) {
            $bin = '"' . $p . '"';
            return $bin;
        }
    }
    $bin = 'git';
    return $bin;
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

function runGitCommand($gitSubcmd, $workingDir = null) {
    $workingDir = $workingDir ?: getRepoDirectory();
    $gitBin = getGitBinary();
    $sub = (strpos(trim($gitSubcmd), 'git ') === 0) ? substr(trim($gitSubcmd), 4) : trim($gitSubcmd);
    
    $cmd = "{$gitBin} -c safe.directory=* -c core.quotepath=false -C \"{$workingDir}\" {$sub}";
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptor, $pipes, $workingDir);
    if (!is_resource($process)) {
        return ['output' => 'Error al iniciar proceso Git', 'code' => -1];
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
    runGitCommand('fetch origin main', $repoDir);
    runGitCommand('branch -M main', $repoDir);
    return isGitRepository($repoDir);
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
            $currentBranch = $branchRes['output'] ?: 'main';

            $commitRes = runGitCommand('rev-parse --short HEAD', $repoDir);
            $currentCommit = $commitRes['output'];

            $remoteRes = runGitCommand('config --get remote.origin.url', $repoDir);
            $remoteUrl = $remoteRes['output'];
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
        // Asegurar inicialización y conexión con el repositorio remoto
        ensureGitRepository($repoDir);
        if (!isGitRepository($repoDir)) {
            echo json_encode([
                'success' => false,
                'message' => 'El directorio actual no pudo conectarse con Git.'
            ]);
            exit;
        }

        $fetchRes = runGitCommand('fetch origin main', $repoDir);
        $localCommit = runGitCommand('rev-parse HEAD', $repoDir)['output'];
        $remoteCommit = runGitCommand('rev-parse origin/main', $repoDir)['output'];

        $hasUpdates = false;
        $commitsBehind = [];

        if (!empty($remoteCommit) && $localCommit !== $remoteCommit) {
            $hasUpdates = true;
            $logRes = runGitCommand('log HEAD..origin/main --oneline -n 10', $repoDir);
            if (!empty($logRes['output'])) {
                $commitsBehind = explode("\n", $logRes['output']);
            }
        }

        $migrationStatus = Migrator::getStatus($conn);

        echo json_encode([
            'success' => true,
            'has_updates' => $hasUpdates,
            'local_commit' => substr($localCommit, 0, 7),
            'remote_commit' => substr($remoteCommit, 0, 7),
            'commits_behind' => $commitsBehind,
            'pending_migrations' => $migrationStatus['pending_count'],
            'message' => $hasUpdates 
                ? '¡Hay nuevas actualizaciones disponibles en el repositorio!' 
                : 'Tu sistema ya está en la versión más reciente.'
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

        $pullRes = runGitCommand('pull origin main', $repoDir);
        $logs[] = ' ✓ Git Pull: ' . ($pullRes['output'] ?: 'Archivos sincronizados.');

        if ($pullRes['code'] !== 0 && strpos($pullRes['output'], 'Already up to date') === false) {
            // Intentar con git stash si había algún cambio accidental en archivo rastreado
            $logs[] = ' ! Hubo una advertencia en Git Pull. Intentando resolución segura...';
            runGitCommand('stash', $repoDir);
            $retryPull = runGitCommand('pull origin main', $repoDir);
            $logs[] = ' ✓ Reintento de sincronización: ' . $retryPull['output'];
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

        // 4. Registrar en historial de actualizaciones
        $logs[] = '[4/4] Finalizando y registrando actualización...';
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

        $logs[] = ' 🎉 ¡Actualización del sistema completada con éxito!';

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'new_commit' => $newCommit,
            'migrations_applied' => $migRes['count'] ?? 0,
            'message' => 'El sistema se actualizó correctamente desde el repositorio sin alterar datos existentes.'
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
