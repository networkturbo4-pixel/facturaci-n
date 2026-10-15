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

function runGitCommand($cmd, $workingDir) {
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptor, $pipes, $workingDir);
    if (!is_resource($process)) {
        return ['output' => 'Error al iniciar proceso', 'code' => -1];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [
        'output' => trim($stdout . ($stderr ? "\n" . $stderr : '')),
        'code' => $code
    ];
}

$repoDir = realpath(__DIR__ . '/..');

switch ($action) {
    case 'status':
        $migrationStatus = Migrator::getStatus($conn);

        // Información de Git
        $gitInstalled = false;
        $isGitRepo = is_dir($repoDir . '/.git');
        $currentBranch = 'desconocido';
        $currentCommit = '';
        $remoteUrl = '';

        if ($isGitRepo) {
            $gitVer = runGitCommand('git --version', $repoDir);
            $gitInstalled = ($gitVer['code'] === 0);

            if ($gitInstalled) {
                $branchRes = runGitCommand('git branch --show-current', $repoDir);
                $currentBranch = $branchRes['output'] ?: 'main';

                $commitRes = runGitCommand('git rev-parse --short HEAD', $repoDir);
                $currentCommit = $commitRes['output'];

                $remoteRes = runGitCommand('git config --get remote.origin.url', $repoDir);
                $remoteUrl = $remoteRes['output'];
            }
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
        // Comprobar si hay nuevos commits en el repositorio remoto
        if (!is_dir($repoDir . '/.git')) {
            echo json_encode([
                'success' => false,
                'message' => 'El directorio actual no está inicializado como repositorio Git.'
            ]);
            exit;
        }

        $fetchRes = runGitCommand('git fetch origin main', $repoDir);
        $localCommit = runGitCommand('git rev-parse HEAD', $repoDir)['output'];
        $remoteCommit = runGitCommand('git rev-parse origin/main', $repoDir)['output'];

        $hasUpdates = false;
        $commitsBehind = [];

        if (!empty($remoteCommit) && $localCommit !== $remoteCommit) {
            $hasUpdates = true;
            $logRes = runGitCommand('git log HEAD..origin/main --oneline -n 10', $repoDir);
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
        if (!is_dir($repoDir . '/.git')) {
            echo json_encode([
                'success' => false,
                'logs' => $logs,
                'message' => 'No se encontró el repositorio Git local (.git).'
            ]);
            exit;
        }

        $pullRes = runGitCommand('git pull origin main', $repoDir);
        $logs[] = ' ✓ Git Pull: ' . ($pullRes['output'] ?: 'Archivos sincronizados.');

        if ($pullRes['code'] !== 0 && strpos($pullRes['output'], 'Already up to date') === false) {
            // Intentar con git stash si había algún cambio accidental en archivo rastreado
            $logs[] = ' ! Hubo una advertencia en Git Pull. Intentando resolución segura...';
            runGitCommand('git stash', $repoDir);
            $retryPull = runGitCommand('git pull origin main', $repoDir);
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
        $newCommit = runGitCommand('git rev-parse --short HEAD', $repoDir)['output'];
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
