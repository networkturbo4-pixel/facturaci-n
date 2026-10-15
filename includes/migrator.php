<?php
/**
 * FACTUSYS - Motor Automatizado de Migraciones de Base de Datos
 * Ejecuta de forma segura cambios estructurales de BD sin sobreescribir datos existentes.
 */

class Migrator {
    public static function runMigrations(PDO $conn, ?string $migrationsDir = null): array {
        if ($migrationsDir === null) {
            $migrationsDir = __DIR__ . '/../migrations';
        }

        if (!is_dir($migrationsDir)) {
            return [
                'success' => false,
                'applied' => [],
                'count' => 0,
                'message' => 'El directorio de migraciones no existe.'
            ];
        }

        // 1. Asegurar tabla de control de migraciones
        $conn->exec("CREATE TABLE IF NOT EXISTS sistema_migraciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL DEFAULT 1,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Comprobar si hay migraciones previas registradas
        $executed = [];
        try {
            $stmt = $conn->query("SELECT migration_name FROM sistema_migraciones");
            $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {}

        // 3. Si la base de datos ya tenía tablas previas pero no la tabla de migraciones,
        // registrar las migraciones base para no duplicar ni generar errores
        if (empty($executed)) {
            try {
                $hasClientes = (bool)$conn->query("SHOW TABLES LIKE 'clientes'")->fetch();
                if ($hasClientes) {
                    $conn->exec("INSERT IGNORE INTO sistema_migraciones (migration_name, batch) VALUES ('001_initial_schema.sql', 1)");
                    $executed[] = '001_initial_schema.sql';
                }

                $hasExport = (bool)$conn->query("SHOW TABLES LIKE 'exportacion_partidas'")->fetch();
                if ($hasExport) {
                    $conn->exec("INSERT IGNORE INTO sistema_migraciones (migration_name, batch) VALUES ('002_migration_exportacion.sql', 1)");
                    $executed[] = '002_migration_exportacion.sql';
                }
            } catch (Exception $e) {}
        }

        // 4. Buscar archivos .sql pendientes
        $files = glob($migrationsDir . '/*.sql');
        sort($files, SORT_NATURAL);

        $applied = [];
        $batch = time();

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            if (in_array($filename, $executed)) {
                continue;
            }

            $sql = file_get_contents($filePath);
            if (trim($sql) === '') {
                continue;
            }

            // Sanitizar comandos de cambio de base de datos
            // para que siempre se ejecute en la base de datos configurada actualmente
            $cleanSql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+`?[a-zA-Z0-9_]+`?[^;]*;/i', '', $sql);
            $cleanSql = preg_replace('/USE\s+`?[a-zA-Z0-9_]+`?\s*;/i', '', $cleanSql);

            try {
                $conn->exec($cleanSql);

                $stmtIns = $conn->prepare("INSERT INTO sistema_migraciones (migration_name, batch) VALUES (?, ?)");
                $stmtIns->execute([$filename, $batch]);

                $applied[] = $filename;
            } catch (PDOException $ex) {
                return [
                    'success' => false,
                    'applied' => $applied,
                    'count' => count($applied),
                    'error' => "Error en {$filename}: " . $ex->getMessage()
                ];
            }
        }

        return [
            'success' => true,
            'applied' => $applied,
            'count' => count($applied),
            'message' => count($applied) > 0 
                ? "Se aplicaron " . count($applied) . " nuevas migraciones de base de datos exitosamente." 
                : "La base de datos está totalmente al día."
        ];
    }

    public static function getStatus(PDO $conn, ?string $migrationsDir = null): array {
        if ($migrationsDir === null) {
            $migrationsDir = __DIR__ . '/../migrations';
        }

        $allFiles = glob($migrationsDir . '/*.sql') ?: [];
        $allFilenames = array_map('basename', $allFiles);
        sort($allFilenames, SORT_NATURAL);

        $executed = [];
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS sistema_migraciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL DEFAULT 1,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $stmt = $conn->query("SELECT migration_name, executed_at FROM sistema_migraciones ORDER BY id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $executed[$r['migration_name']] = $r['executed_at'];
            }
        } catch (Exception $e) {}

        $pending = [];
        foreach ($allFilenames as $fn) {
            if (!isset($executed[$fn])) {
                $pending[] = $fn;
            }
        }

        return [
            'total' => count($allFilenames),
            'applied_count' => count($executed),
            'pending_count' => count($pending),
            'applied' => $executed,
            'pending' => $pending
        ];
    }
}
