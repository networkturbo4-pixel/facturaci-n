<?php
$rootDir = dirname(__DIR__);
$zipPath = $rootDir . '/factusys_actualizacion_latest.zip';
if (file_exists($zipPath)) {
    @unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("No se pudo crear el archivo ZIP.\n");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$excludePrefixes = [
    '.git',
    'uploads',
    'temp',
    'storage/temp',
    'storage/cache'
];

$count = 0;
foreach ($iterator as $item) {
    $subPath = substr($item->getPathname(), strlen($rootDir) + 1);
    $subPathNorm = str_replace('\\', '/', $subPath);

    // Exclusiones críticas
    if ($subPathNorm === 'factusys_actualizacion_latest.zip') continue;
    if ($subPathNorm === 'includes/db.config.php') continue;
    // No pisar configuración propia del servidor (handler PHP de cPanel, variables de entorno)
    if ($subPathNorm === '.htaccess' || $subPathNorm === '.env') continue;
    
    $skip = false;
    foreach ($excludePrefixes as $ex) {
        if (str_starts_with($subPathNorm, $ex)) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    if ($item->isDir()) {
        $zip->addEmptyDir($subPathNorm);
    } elseif ($item->isFile()) {
        $zip->addFile($item->getPathname(), $subPathNorm);
        $count++;
    }
}

$zip->close();
echo "✓ Paquete de actualización generado con éxito: " . basename($zipPath) . "\n";
echo "✓ Archivos incluidos: $count\n";
echo "✓ Tamaño: " . round(filesize($zipPath) / (1024 * 1024), 2) . " MB\n";
