<?php
require_once 'includes/auth.php';
requireRole('Administrador');

$success_msg = '';
$error_msg   = '';
$activeTab   = $_POST['active_tab'] ?? ($_GET['tab'] ?? 'marca');
if (!in_array($activeTab, ['marca', 'pwa', 'empresa', 'ose', 'series', 'actualizacion'])) {
    $activeTab = 'marca';
}

/**
 * Redimensiona y genera un icono PNG cuadrado con transparencia usando GD
 */
function generatePwaPngIcon(string $srcPath, string $destPath, int $targetSize): bool {
    if (!extension_loaded('gd') || !file_exists($srcPath)) {
        return false;
    }
    $info = @getimagesize($srcPath);
    if (!$info) return false;

    $mime = $info['mime'] ?? '';
    $srcImg = match ($mime) {
        'image/png'  => @imagecreatefrompng($srcPath),
        'image/jpeg' => @imagecreatefromjpeg($srcPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
        'image/gif'  => @imagecreatefromgif($srcPath),
        default      => false
    };
    if (!$srcImg) return false;

    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);

    $dstImg = imagecreatetruecolor($targetSize, $targetSize);
    imagesavealpha($dstImg, true);
    $trans = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
    imagefill($dstImg, 0, 0, $trans);

    // Mantener proporción centrada en el lienzo cuadrado
    $scale = min($targetSize / max(1, $srcW), $targetSize / max(1, $srcH));
    $newW  = (int)round($srcW * $scale);
    $newH  = (int)round($srcH * $scale);
    $dstX  = (int)floor(($targetSize - $newW) / 2);
    $dstY  = (int)floor(($targetSize - $newH) / 2);

    imagecopyresampled($dstImg, $srcImg, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
    $ok = imagepng($dstImg, $destPath, 9);

    imagedestroy($srcImg);
    imagedestroy($dstImg);
    return (bool)$ok;
}

/**
 * Procesa de forma segura la subida de una imagen de marca (logo, favicon, icono PWA)
 */
function uploadBrandAsset(string $fileInput, string $prefix, array $allowedExts = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'ico']): array {
    if (empty($_FILES[$fileInput]['tmp_name']) || ($_FILES[$fileInput]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['uploaded' => false];
    }

    $file = $_FILES[$fileInput];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['uploaded' => false, 'error' => "Error al subir el archivo ({$fileInput}). Código: " . $file['error']];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['uploaded' => false, 'error' => "El archivo supera el límite máximo permitido de 5 MB."];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return ['uploaded' => false, 'error' => "Formato .$ext no permitido. Usa: " . implode(', ', $allowedExts)];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $validMimes = [
        'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'text/xml', 'text/plain',
        'image/x-icon', 'image/vnd.microsoft.icon', 'application/ico'
    ];
    if (!in_array($mime, $validMimes, true)) {
        return ['uploaded' => false, 'error' => "Tipo de contenido inválido ($mime)."];
    }

    // Si es SVG, sanitizar posibles etiquetas script u atributos de evento
    if ($ext === 'svg') {
        $svgContent = file_get_contents($file['tmp_name']);
        if ($svgContent === false || stripos($svgContent, '<svg') === false) {
            return ['uploaded' => false, 'error' => "El archivo SVG no es válido."];
        }
        $svgClean = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $svgContent);
        $svgClean = preg_replace('/\son[a-z]+\s*=\s*["\'][^"\']*["\']/i', '', $svgClean);
        file_put_contents($file['tmp_name'], $svgClean);
    }

    $uploadDir = __DIR__ . '/uploads/branding';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $destAbs  = $uploadDir . '/' . $filename;
    $destRel  = 'uploads/branding/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        return ['uploaded' => false, 'error' => "No se pudo guardar la imagen en uploads/branding/."];
    }

    return [
        'uploaded' => true,
        'rel_path' => $destRel,
        'abs_path' => $destAbs,
        'ext'      => $ext
    ];
}

// Procesar restauración/eliminación de un logo individual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['restaurar_activo'])) {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada.";
    } else {
        $claveTarget = $_POST['restaurar_activo'];
        $mapReset = [
            'sistema_logo'        => '',
            'sistema_logo_claro'  => '',
            'sistema_logo_oscuro' => '',
            'sistema_favicon'     => 'uploads/branding/default-favicon.png',
            'sistema_logo_pwa'    => 'uploads/branding/default-pwa-512.png',
        ];
        if (array_key_exists($claveTarget, $mapReset)) {
            $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            $stmt->execute([$claveTarget, $mapReset[$claveTarget]]);
            if ($claveTarget === 'sistema_logo') {
                $stmt->execute(['empresa_logo_url', '']);
            }
            if ($claveTarget === 'sistema_logo_pwa') {
                $stmt->execute(['sistema_logo_pwa_192', 'uploads/branding/default-pwa-192.png']);
            }
            $success_msg = "El recurso visual fue restaurado a su valor predeterminado.";
        }
    }
}

// Guardar configuración general, marca, PWA, empresa, OSE y series
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_configuracion'])) {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada. Intente de nuevo.";
    } else {
        try {
            $campos = [
                // Identidad, Tema & Estilos UI (Toast / Modal)
                'sistema_tema_default', 'sistema_color_primario',
                'ui_toast_estilo', 'ui_toast_posicion', 'ui_modal_estilo',
                // PWA
                'pwa_habilitado', 'pwa_nombre', 'pwa_nombre_corto', 'pwa_descripcion',
                'pwa_theme_color', 'pwa_bg_color',
                // Empresa
                'empresa_ruc', 'empresa_razon_social', 'empresa_nombre_comercial',
                'empresa_direccion', 'empresa_telefono', 'empresa_correo', 'empresa_web',
                'empresa_ubigeo', 'empresa_departamento', 'empresa_provincia', 'empresa_distrito',
                // SUNAT / OSE / Facturalo
                'facturacion_proveedor', 'sunat_modo', 'sunat_usuario_sol',
                'facturalo_modo', 'nubefact_url', 'nubefact_modo',
                // Series
                'serie_factura', 'serie_boleta', 'serie_exportacion', 'serie_guia'
            ];

            $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            foreach ($campos as $campo) {
                if (isset($_POST[$campo])) {
                    $val = trim($_POST[$campo]);
                    if (in_array($campo, ['serie_factura', 'serie_boleta', 'serie_exportacion', 'serie_guia'])) {
                        $val = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $val));
                    }
                    $stmt->execute([$campo, $val]);
                }
            }

            // Campos sensibles (contraseñas / tokens): solo actualizar si se enviaron
            $camposSensibles = ['sunat_clave_sol', 'sunat_cert_password', 'facturalo_token', 'nubefact_token', 'api_token_documento', 'github_token'];
            foreach ($camposSensibles as $campoS) {
                if (isset($_POST[$campoS]) && trim($_POST[$campoS]) !== '') {
                    $stmt->execute([$campoS, trim($_POST[$campoS])]);
                }
            }

            // 1. Subida de Logo Principal del Sistema
            $upLogo = uploadBrandAsset('logo_sistema_file', 'logo_sistema');
            if (!empty($upLogo['error'])) {
                $error_msg = $upLogo['error'];
            } elseif ($upLogo['uploaded']) {
                $stmt->execute(['sistema_logo', $upLogo['rel_path']]);
                $stmt->execute(['empresa_logo_url', $upLogo['rel_path']]);
            }

            // 2. Subida de Logo Modo Claro
            $upClaro = uploadBrandAsset('logo_claro_file', 'logo_claro');
            if (!empty($upClaro['error'])) {
                $error_msg = $upClaro['error'];
            } elseif ($upClaro['uploaded']) {
                $stmt->execute(['sistema_logo_claro', $upClaro['rel_path']]);
            }

            // 3. Subida de Logo Modo Oscuro
            $upOscuro = uploadBrandAsset('logo_oscuro_file', 'logo_oscuro');
            if (!empty($upOscuro['error'])) {
                $error_msg = $upOscuro['error'];
            } elseif ($upOscuro['uploaded']) {
                $stmt->execute(['sistema_logo_oscuro', $upOscuro['rel_path']]);
            }

            // 4. Subida de Favicon
            $upFav = uploadBrandAsset('favicon_file', 'favicon', ['png', 'ico', 'svg', 'webp', 'jpg', 'jpeg']);
            if (!empty($upFav['error'])) {
                $error_msg = $upFav['error'];
            } elseif ($upFav['uploaded']) {
                $stmt->execute(['sistema_favicon', $upFav['rel_path']]);
            }

            // 5. Subida de Logo PWA (+ auto-generación de variantes 192x192 y 512x512)
            $upPwa = uploadBrandAsset('logo_pwa_file', 'pwa_icon', ['png', 'jpg', 'jpeg', 'webp', 'svg']);
            if (!empty($upPwa['error'])) {
                $error_msg = $upPwa['error'];
            } elseif ($upPwa['uploaded']) {
                $ts = time();
                $dirAbs = __DIR__ . '/uploads/branding';
                $pwa192Rel = 'uploads/branding/pwa_192_' . $ts . '.png';
                $pwa512Rel = 'uploads/branding/pwa_512_' . $ts . '.png';

                $gen192 = generatePwaPngIcon($upPwa['abs_path'], $dirAbs . '/pwa_192_' . $ts . '.png', 192);
                $gen512 = generatePwaPngIcon($upPwa['abs_path'], $dirAbs . '/pwa_512_' . $ts . '.png', 512);

                $stmt->execute(['sistema_logo_pwa', $gen512 ? $pwa512Rel : $upPwa['rel_path']]);
                $stmt->execute(['sistema_logo_pwa_192', $gen192 ? $pwa192Rel : $upPwa['rel_path']]);
            }

            // 6. Subida de Certificado Digital (.pfx, .p12, .pem)
            if (!empty($_FILES['sunat_cert_file']['tmp_name'])) {
                $file = $_FILES['sunat_cert_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pfx', 'p12', 'pem'], true)) {
                    $certDir = __DIR__ . '/storage/certs';
                    if (!is_dir($certDir)) @mkdir($certDir, 0755, true);
                    $destPath = $certDir . '/certificado_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        $stmt->execute(['sunat_cert_path', $destPath]);
                    }
                } else {
                    $error_msg = "Formato de certificado no válido. Solo se admiten archivos .pfx, .p12 o .pem";
                }
            }

            if (empty($error_msg)) {
                $success_msg = "Configuración y recursos del sistema actualizados correctamente.";
            }
        } catch (Exception $e) {
            $error_msg = "Error al guardar la configuración: " . $e->getMessage();
        }
    }
}

// Recargar configuración fresca tras cambios
$cfg = getAllSystemConfig(true);
$brandConfig = getBrandingConfig(true);

// Estadísticas reales de series y correlativos desde BD
$seriesStats = [];
try {
    $stmtS = $conn->query("SELECT serie, COUNT(*) AS total_docs, COALESCE(MAX(correlativo), 0) AS max_corr FROM comprobantes GROUP BY serie");
    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $sRow) {
        $seriesStats[strtoupper($sRow['serie'])] = $sRow;
    }
} catch (Exception $e) {}

$pageTitle = 'Configuración & Personalización';
include 'includes/header.php';
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 dark:bg-slate-950 overflow-hidden">
    <?php
    $topbarAction = [
        'label'   => 'Guardar Cambios',
        'icon'    => 'fa-solid fa-floppy-disk text-xs',
        'onclick' => 'document.getElementById("mainConfigForm").requestSubmit()'
    ];
    include 'includes/topbar.php';
    ?>

    <div class="flex-1 overflow-y-auto overflow-x-hidden p-3.5 sm:p-6 lg:p-8 pb-28 sm:pb-12 space-y-5 sm:space-y-6">
        <div class="max-w-6xl w-full min-w-0 mx-auto space-y-5 sm:space-y-6">

            <!-- ===== HERO BANNER DE ESTADO DEL SISTEMA (RESPONSIVE OPTIMIZADO) ===== -->
            <div class="p-4 sm:p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white shadow-xl border border-slate-800 relative overflow-hidden">
                <div class="absolute -right-12 -top-12 w-64 h-64 bg-brand-500/20 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute left-1/3 -bottom-16 w-56 h-56 bg-blue-500/10 rounded-full blur-2xl pointer-events-none"></div>

                <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-4 sm:gap-6">
                    <div class="flex items-center gap-3.5 sm:gap-4 min-w-0">
                        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-2xl bg-white/10 backdrop-blur-md border border-white/15 p-2 flex items-center justify-center shrink-0 shadow-lg">
                            <?php if (!empty($brandConfig['logo_oscuro_efectivo'])): ?>
                                <img src="<?php echo htmlspecialchars($brandConfig['logo_oscuro_efectivo']); ?>" alt="Logo" class="max-h-9 sm:max-h-12 max-w-9 sm:max-w-12 object-contain">
                            <?php elseif (!empty($brandConfig['logo_claro_efectivo'])): ?>
                                <img src="<?php echo htmlspecialchars($brandConfig['logo_claro_efectivo']); ?>" alt="Logo" class="max-h-9 sm:max-h-12 max-w-9 sm:max-w-12 object-contain">
                            <?php else: ?>
                                <i class="fa-solid fa-sliders text-xl sm:text-2xl text-brand-300"></i>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5 mb-1">
                                <span class="px-2 py-0.5 rounded-full text-[9px] sm:text-[10px] font-bold uppercase tracking-wider bg-brand-500/20 text-brand-300 border border-brand-400/30">
                                    Control v3.0
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-[9px] sm:text-[10px] font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 inline-flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                    PWA Activa
                                </span>
                            </div>
                            <h2 class="text-sm sm:text-2xl font-extrabold tracking-tight text-white truncate">
                                <?php echo htmlspecialchars($brandConfig['empresa_razon_social']); ?>
                            </h2>
                            <p class="text-[11px] sm:text-xs text-slate-300 mt-0.5 truncate">
                                RUC: <span class="font-semibold text-white"><?php echo htmlspecialchars($brandConfig['empresa_ruc']); ?></span>
                                <span class="mx-1 opacity-50">•</span>
                                <strong class="text-brand-300"><?php echo htmlspecialchars($brandConfig['empresa_nombre_comercial']); ?></strong>
                            </p>
                        </div>
                    </div>

                    <!-- Quick Controls in Hero -->
                    <div class="flex flex-wrap items-center justify-between sm:justify-start gap-2 pt-2 lg:pt-0 border-t border-white/10 lg:border-0 shrink-0">
                        <div class="bg-white/10 backdrop-blur-md border border-white/15 rounded-xl p-1 flex items-center gap-0.5 text-[11px] sm:text-xs w-full sm:w-auto justify-center">
                            <button type="button" onclick="window.setAppTheme('light')" id="heroThemeLight" class="flex-1 sm:flex-initial justify-center px-2.5 py-1.5 rounded-lg font-semibold transition-all flex items-center gap-1 text-slate-200 hover:text-white">
                                <i class="fa-solid fa-sun text-amber-300"></i> Claro
                            </button>
                            <button type="button" onclick="window.setAppTheme('dark')" id="heroThemeDark" class="flex-1 sm:flex-initial justify-center px-2.5 py-1.5 rounded-lg font-semibold transition-all flex items-center gap-1 text-slate-200 hover:text-white">
                                <i class="fa-solid fa-moon text-indigo-300"></i> Oscuro
                            </button>
                            <button type="button" onclick="window.setAppTheme('system')" id="heroThemeSystem" class="flex-1 sm:flex-initial justify-center px-2.5 py-1.5 rounded-lg font-semibold transition-all flex items-center gap-1 text-slate-200 hover:text-white">
                                <i class="fa-solid fa-desktop text-cyan-300"></i> Auto
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alertas de Éxito / Error -->
            <?php if ($success_msg): ?>
            <div class="p-3.5 sm:p-4 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-800 dark:text-emerald-300 rounded-2xl text-xs sm:text-sm flex items-center justify-between gap-3 shadow-xs animate-fade-in">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <span class="font-medium break-words"><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700 p-1 shrink-0">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
            <div class="p-3.5 sm:p-4 bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/30 text-rose-800 dark:text-rose-300 rounded-2xl text-xs sm:text-sm flex items-center justify-between gap-3 shadow-xs animate-fade-in">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-8 h-8 rounded-xl bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <span class="font-medium break-words"><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700 p-1 shrink-0">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <?php endif; ?>

            <!-- ===== LAYOUT PRINCIPAL DE CONFIGURACIÓN (SIDEBAR TABS + PANELES) ===== -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 lg:gap-6 items-start min-w-0">

                <!-- Menú de Pestañas (Cuadrícula de 2 Columnas en Móvil / Columna vertical en Desktop) -->
                <div class="lg:col-span-3 space-y-4 lg:sticky lg:top-6 min-w-0 w-full">
                    <nav class="app-card p-1.5 sm:p-2 grid grid-cols-2 sm:grid-cols-3 lg:flex lg:flex-col gap-1.5 lg:gap-1 w-full" aria-label="Secciones de Configuración">
                        <button type="button" data-tab="marca" class="tab-link w-full text-left px-2.5 py-2.5 lg:px-3.5 lg:py-3 rounded-xl transition-all flex items-center gap-2 lg:gap-3 group min-w-0">
                            <div class="w-7 h-7 lg:w-9 lg:h-9 rounded-xl bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 transition-transform group-hover:scale-105">
                                <i class="fa-solid fa-palette text-xs lg:text-sm"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] sm:text-xs font-bold text-slate-800 dark:text-slate-100 truncate">Identidad & Logos</p>
                                <p class="hidden lg:block text-[10px] text-slate-400 truncate">Claro, Oscuro y Favicon</p>
                            </div>
                        </button>

                        <button type="button" data-tab="pwa" class="tab-link w-full text-left px-2.5 py-2.5 lg:px-3.5 lg:py-3 rounded-xl transition-all flex items-center gap-2 lg:gap-3 group min-w-0">
                            <div class="w-7 h-7 lg:w-9 lg:h-9 rounded-xl bg-cyan-50 dark:bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center shrink-0 transition-transform group-hover:scale-105">
                                <i class="fa-solid fa-mobile-screen-button text-xs lg:text-sm"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1 min-w-0">
                                    <p class="text-[11px] sm:text-xs font-bold text-slate-800 dark:text-slate-100 truncate">App PWA</p>
                                    <span class="hidden sm:inline-block px-1.5 py-0.2 text-[9px] font-bold bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-300 rounded shrink-0">APP</span>
                                </div>
                                <p class="hidden lg:block text-[10px] text-slate-400 truncate">Logo PWA, Manifiesto & Offline</p>
                            </div>
                        </button>

                        <button type="button" data-tab="empresa" class="tab-link w-full text-left px-2.5 py-2.5 lg:px-3.5 lg:py-3 rounded-xl transition-all flex items-center gap-2 lg:gap-3 group min-w-0">
                            <div class="w-7 h-7 lg:w-9 lg:h-9 rounded-xl bg-blue-50 dark:bg-blue-500/15 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 transition-transform group-hover:scale-105">
                                <i class="fa-solid fa-building text-xs lg:text-sm"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] sm:text-xs font-bold text-slate-800 dark:text-slate-100 truncate">Datos Empresa</p>
                                <p class="hidden lg:block text-[10px] text-slate-400 truncate">RUC, Dirección y Ubigeo</p>
                            </div>
                        </button>

                        <button type="button" data-tab="ose" class="tab-link w-full text-left px-2.5 py-2.5 lg:px-3.5 lg:py-3 rounded-xl transition-all flex items-center gap-2 lg:gap-3 group min-w-0">
                            <div class="w-7 h-7 lg:w-9 lg:h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 transition-transform group-hover:scale-105">
                                <i class="fa-solid fa-satellite-dish text-xs lg:text-sm"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] sm:text-xs font-bold text-slate-800 dark:text-slate-100 truncate">SUNAT / OSE</p>
                                <p class="hidden lg:block text-[10px] text-slate-400 truncate">Greenter, Clave SOL y Cert.</p>
                            </div>
                        </button>

                        <button type="button" data-tab="series" class="col-span-2 sm:col-span-1 tab-link w-full text-left px-2.5 py-2.5 lg:px-3.5 lg:py-3 rounded-xl transition-all flex items-center justify-center sm:justify-start gap-2 lg:gap-3 group min-w-0">
                            <div class="w-7 h-7 lg:w-9 lg:h-9 rounded-xl bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 transition-transform group-hover:scale-105">
                                <i class="fa-solid fa-list-ol text-xs lg:text-sm"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[11px] sm:text-xs font-bold text-slate-800 dark:text-slate-100 truncate">Series & Numeración</p>
                                <p class="hidden lg:block text-[10px] text-slate-400 truncate">F001, B001, E001 y Guías</p>
                            </div>
                        </button>

                        <button type="button" data-tab="actualizacion" class="col-span-2 sm:col-span-1 tab-link w-full text-left px-2.5 py-2.5 lg:px-3.5 lg:py-3 rounded-xl transition-all flex items-center justify-center sm:justify-start gap-2 lg:gap-3 group min-w-0">
                            <div class="w-7 h-7 lg:w-9 lg:h-9 rounded-xl bg-cyan-50 dark:bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center shrink-0 transition-transform group-hover:scale-105">
                                <i class="fa-solid fa-cloud-arrow-down text-xs lg:text-sm"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[11px] sm:text-xs font-bold text-slate-800 dark:text-slate-100 truncate">Actualizaciones</p>
                                <p class="hidden lg:block text-[10px] text-slate-400 truncate">Repositorio & Base de Datos</p>
                            </div>
                        </button>
                    </nav>

                    <!-- Tarjeta Resumen de Activos Visuales (Visible en Desktop) -->
                    <div class="hidden lg:block app-card p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Estado de Marca</span>
                            <span class="text-[10px] font-semibold text-brand-600 dark:text-brand-400">En Vivo</span>
                        </div>
                        <div class="space-y-2 text-xs">
                            <div class="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                                <span class="text-slate-500">Logo General:</span>
                                <span class="font-semibold <?php echo !empty($brandConfig['sistema_logo']) ? 'text-emerald-600' : 'text-slate-400'; ?>">
                                    <?php echo !empty($brandConfig['sistema_logo']) ? 'Personalizado' : 'Por defecto'; ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                                <span class="text-slate-500">Logo Modo Claro:</span>
                                <span class="font-semibold <?php echo !empty($brandConfig['sistema_logo_claro']) ? 'text-emerald-600' : 'text-slate-400'; ?>">
                                    <?php echo !empty($brandConfig['sistema_logo_claro']) ? 'Personalizado' : 'Usa General'; ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                                <span class="text-slate-500">Logo Modo Oscuro:</span>
                                <span class="font-semibold <?php echo !empty($brandConfig['sistema_logo_oscuro']) ? 'text-emerald-600' : 'text-slate-400'; ?>">
                                    <?php echo !empty($brandConfig['sistema_logo_oscuro']) ? 'Personalizado' : 'Usa General'; ?>
                                </span>
                            </div>
                            <div class="flex items-center justify-between py-1">
                                <span class="text-slate-500">Icono PWA:</span>
                                <span class="font-semibold text-emerald-600">192px & 512px OK</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contenido de Pestañas -->
                <div class="lg:col-span-9 min-w-0 w-full">
                    <form id="mainConfigForm" method="POST" action="configuracion.php" enctype="multipart/form-data" class="space-y-6">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="guardar_configuracion" value="1">
                        <input type="hidden" name="active_tab" id="inp_active_tab" value="<?php echo htmlspecialchars($activeTab); ?>">

                        <!-- =================================================================== -->
                        <!-- TAB 1: IDENTIDAD VISUAL, LOGOS (GENERAL, CLARO, OSCURO) Y FAVICON -->
                        <!-- =================================================================== -->
                        <div id="tab-marca" class="tab-content space-y-6">

                            <!-- Selector de Tema Predeterminado y Color de Marca -->
                            <div class="app-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                            <i class="fa-solid fa-wand-magic-sparkles text-brand-500"></i>
                                            Apariencia Global y Modo Claro / Oscuro
                                        </h3>
                                        <p class="text-xs text-slate-500 mt-0.5">Define el esquema visual por defecto del sistema y prueba en vivo la conmutación automática de logos.</p>
                                    </div>
                                </div>

                                <div class="p-6 space-y-6">
                                    <?php $temaDef = $brandConfig['sistema_tema_default']; ?>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider mb-3">Esquema de Color Predeterminado</label>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <!-- Opción Claro -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="sistema_tema_default" value="light" class="peer sr-only" <?php echo ($temaDef === 'light') ? 'checked' : ''; ?> onchange="window.setAppTheme('light')">
                                                <div class="p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-brand-600 peer-checked:bg-brand-50/40 dark:peer-checked:bg-brand-500/10 peer-checked:ring-2 peer-checked:ring-brand-500/20 transition-all hover:border-slate-300">
                                                    <div class="h-16 rounded-xl bg-slate-100 border border-slate-200 p-2.5 mb-3 flex items-center gap-2 keep-light">
                                                        <div class="w-8 h-full rounded-lg bg-white border border-slate-200 keep-light"></div>
                                                        <div class="flex-1 space-y-1.5">
                                                            <div class="h-2.5 w-3/4 rounded bg-slate-300"></div>
                                                            <div class="h-2 w-1/2 rounded bg-indigo-500"></div>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                                            <i class="fa-solid fa-sun text-amber-500"></i> Modo Claro
                                                        </span>
                                                        <span class="text-[10px] font-semibold text-brand-600 dark:text-brand-400">Usa Logo Claro</span>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Opción Oscuro -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="sistema_tema_default" value="dark" class="peer sr-only" <?php echo ($temaDef === 'dark') ? 'checked' : ''; ?> onchange="window.setAppTheme('dark')">
                                                <div class="p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-brand-600 peer-checked:bg-brand-50/40 dark:peer-checked:bg-brand-500/10 peer-checked:ring-2 peer-checked:ring-brand-500/20 transition-all hover:border-slate-300">
                                                    <div class="h-16 rounded-xl bg-slate-950 border border-slate-800 p-2.5 mb-3 flex items-center gap-2">
                                                        <div class="w-8 h-full rounded-lg bg-slate-900 border border-slate-800"></div>
                                                        <div class="flex-1 space-y-1.5">
                                                            <div class="h-2.5 w-3/4 rounded bg-slate-700"></div>
                                                            <div class="h-2 w-1/2 rounded bg-indigo-400"></div>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                                            <i class="fa-solid fa-moon text-indigo-400"></i> Modo Oscuro
                                                        </span>
                                                        <span class="text-[10px] font-semibold text-brand-600 dark:text-brand-400">Usa Logo Oscuro</span>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Opción Sistema -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="sistema_tema_default" value="system" class="peer sr-only" <?php echo ($temaDef === 'system') ? 'checked' : ''; ?> onchange="window.setAppTheme('system')">
                                                <div class="p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-brand-600 peer-checked:bg-brand-50/40 dark:peer-checked:bg-brand-500/10 peer-checked:ring-2 peer-checked:ring-brand-500/20 transition-all hover:border-slate-300">
                                                    <div class="h-16 rounded-xl border border-slate-200 dark:border-slate-800 mb-3 flex overflow-hidden">
                                                        <!-- Mitad Claro -->
                                                        <div class="w-1/2 bg-slate-100 p-2 flex items-center gap-1.5 border-r border-slate-200 dark:border-slate-800 keep-light">
                                                            <div class="w-4 h-full rounded bg-white border border-slate-200 keep-light"></div>
                                                            <div class="flex-1 space-y-1">
                                                                <div class="h-2 w-full rounded bg-slate-300"></div>
                                                                <div class="h-1.5 w-2/3 rounded bg-amber-500"></div>
                                                            </div>
                                                        </div>
                                                        <!-- Mitad Oscuro -->
                                                        <div class="w-1/2 bg-slate-950 p-2 flex items-center gap-1.5">
                                                            <div class="w-4 h-full rounded bg-slate-900 border border-slate-800"></div>
                                                            <div class="flex-1 space-y-1">
                                                                <div class="h-2 w-full rounded bg-slate-700"></div>
                                                                <div class="h-1.5 w-2/3 rounded bg-indigo-400"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                                            <i class="fa-solid fa-desktop text-cyan-500"></i> Automático (SO)
                                                        </span>
                                                        <span class="text-[10px] font-semibold text-brand-600 dark:text-brand-400">Adaptativo</span>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Color de Marca Primario -->
                                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-200">Color de Acento Principal del Sistema</label>
                                            <p class="text-[11px] text-slate-500">Se aplica en botones de acción, indicadores activos y destacados visuales.</p>
                                        </div>
                                        <div class="flex items-center gap-2.5">
                                            <?php
                                            $colActual = $brandConfig['sistema_color_primario'];
                                            $presets = ['#4f46e5', '#2563eb', '#059669', '#0891b2', '#7c3aed', '#e11d48'];
                                            foreach ($presets as $hex):
                                            ?>
                                            <button type="button" onclick="document.getElementById('inp_color_primario').value='<?php echo $hex; ?>'" class="w-7 h-7 rounded-full border-2 border-white dark:border-slate-800 shadow-sm transition-transform hover:scale-110" style="background-color: <?php echo $hex; ?>;" title="<?php echo $hex; ?>"></button>
                                            <?php endforeach; ?>
                                            <input type="color" id="inp_color_primario" name="sistema_color_primario" value="<?php echo htmlspecialchars($colActual); ?>" class="w-10 h-9 rounded-lg border border-slate-300 dark:border-slate-700 cursor-pointer bg-transparent p-0.5">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- =================================================================== -->
                            <!-- PERSONALIZACIÓN DE AVISOS: ESTILOS DE TOAST Y MODAL DE CONFIRMACIÓN -->
                            <!-- =================================================================== -->
                            <?php
                            $toastEstiloActual = $brandConfig['ui_toast_estilo'] ?? 'glass';
                            $toastPosActual    = $brandConfig['ui_toast_posicion'] ?? 'top-right';
                            $modalEstiloActual = $brandConfig['ui_modal_estilo'] ?? 'modern';
                            ?>
                            <div class="app-card overflow-hidden">
                                <div class="p-5 sm:p-6 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-500/5 via-indigo-500/5 to-transparent flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-center gap-3.5">
                                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-base shrink-0">
                                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">Estilos de Modal de Confirmación y Notificaciones Toast</h3>
                                            <p class="text-xs text-slate-500">Reemplaza los avisos nativos del navegador por alertas flotantes y diálogos interactivos con el estilo que prefieras.</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button type="button" onclick="probarToastEnVivo()" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-sm transition-all flex items-center gap-1.5">
                                            <i class="fa-solid fa-bell"></i>
                                            <span>Probar Toast</span>
                                        </button>
                                        <button type="button" onclick="probarModalEnVivo()" class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-sm transition-all flex items-center gap-1.5">
                                            <i class="fa-solid fa-window-restore"></i>
                                            <span>Probar Modal</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="p-5 sm:p-6 space-y-6">
                                    <!-- 1. Selección de Estilo de Notificación Toast -->
                                    <div>
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
                                            <label class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-2">
                                                <i class="fa-solid fa-message text-emerald-500"></i>
                                                <span>1. Diseño Visual de Notificaciones Toast</span>
                                            </label>
                                            <!-- Posición del Toast -->
                                            <div class="flex items-center gap-2">
                                                <span class="text-[11px] font-semibold text-slate-400">Ubicación:</span>
                                                <select name="ui_toast_posicion" id="sel_toast_pos" onchange="cambiarPosicionToastPreview(this.value)" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1 text-xs font-bold text-slate-700 dark:text-slate-200">
                                                    <option value="top-right" <?php echo $toastPosActual === 'top-right' ? 'selected' : ''; ?>>Superior Derecha</option>
                                                    <option value="top-center" <?php echo $toastPosActual === 'top-center' ? 'selected' : ''; ?>>Superior Centro</option>
                                                    <option value="bottom-right" <?php echo $toastPosActual === 'bottom-right' ? 'selected' : ''; ?>>Inferior Derecha</option>
                                                    <option value="bottom-center" <?php echo $toastPosActual === 'bottom-center' ? 'selected' : ''; ?>>Inferior Centro</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                                            <!-- Toast Glassmorphism -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_toast_estilo" value="glass" class="peer sr-only" <?php echo $toastEstiloActual === 'glass' ? 'checked' : ''; ?> onchange="seleccionarEstiloToastPreview('glass')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-emerald-500 peer-checked:bg-emerald-50/40 dark:peer-checked:bg-emerald-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="p-2.5 rounded-xl bg-white/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 shadow-sm mb-2.5 flex items-center gap-2">
                                                        <span class="w-6 h-6 rounded-lg bg-emerald-500/15 text-emerald-500 flex items-center justify-center text-xs shrink-0"><i class="fa-solid fa-check"></i></span>
                                                        <div class="space-y-1 flex-1">
                                                            <div class="h-2 w-16 bg-slate-700 dark:bg-slate-200 rounded"></div>
                                                            <div class="h-1.5 w-24 bg-slate-300 dark:bg-slate-600 rounded"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Glassmorphism</p>
                                                        <p class="text-[10px] text-slate-500">Cristal translúcido con blur</p>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Toast Minimalista -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_toast_estilo" value="minimal" class="peer sr-only" <?php echo $toastEstiloActual === 'minimal' ? 'checked' : ''; ?> onchange="seleccionarEstiloToastPreview('minimal')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-emerald-500 peer-checked:bg-emerald-50/40 dark:peer-checked:bg-emerald-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="p-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 border-l-4 border-l-emerald-500 shadow-xs mb-2.5 flex items-center gap-2">
                                                        <span class="w-5 h-5 rounded text-emerald-500 flex items-center justify-center text-xs shrink-0"><i class="fa-solid fa-circle-check"></i></span>
                                                        <div class="space-y-1 flex-1">
                                                            <div class="h-2 w-16 bg-slate-800 dark:bg-slate-200 rounded"></div>
                                                            <div class="h-1.5 w-20 bg-slate-300 dark:bg-slate-600 rounded"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Minimalista</p>
                                                        <p class="text-[10px] text-slate-500">Borde lateral ejecutivo</p>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Toast Sólido Vibrante -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_toast_estilo" value="solid" class="peer sr-only" <?php echo $toastEstiloActual === 'solid' ? 'checked' : ''; ?> onchange="seleccionarEstiloToastPreview('solid')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-emerald-500 peer-checked:bg-emerald-50/40 dark:peer-checked:bg-emerald-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="p-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-500 text-white shadow-sm mb-2.5 flex items-center gap-2">
                                                        <span class="w-6 h-6 rounded-lg bg-white/20 flex items-center justify-center text-xs shrink-0"><i class="fa-solid fa-check"></i></span>
                                                        <div class="space-y-1 flex-1">
                                                            <div class="h-2 w-16 bg-white rounded"></div>
                                                            <div class="h-1.5 w-20 bg-white/70 rounded"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Sólido Vibrante</p>
                                                        <p class="text-[10px] text-slate-500">Color lleno de alto contraste</p>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Toast Isla Dinámica / Capsule -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_toast_estilo" value="capsule" class="peer sr-only" <?php echo $toastEstiloActual === 'capsule' ? 'checked' : ''; ?> onchange="seleccionarEstiloToastPreview('capsule')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-emerald-500 peer-checked:bg-emerald-50/40 dark:peer-checked:bg-emerald-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="px-3 py-2 rounded-full bg-slate-950 text-white border border-slate-700 shadow-md mb-2.5 flex items-center gap-2">
                                                        <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-[10px] shrink-0"><i class="fa-solid fa-check"></i></span>
                                                        <div class="space-y-1 flex-1">
                                                            <div class="h-2 w-14 bg-white rounded-full"></div>
                                                            <div class="h-1.5 w-18 bg-slate-400 rounded-full"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Isla Dinámica</p>
                                                        <p class="text-[10px] text-slate-500">Cápsula oscura flotante</p>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- 2. Selección de Estilo de Modal de Confirmación -->
                                    <div class="pt-5 border-t border-slate-100 dark:border-slate-800">
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider mb-3 flex items-center gap-2">
                                            <i class="fa-solid fa-layer-group text-indigo-500"></i>
                                            <span>2. Diseño Visual del Modal de Confirmación</span>
                                        </label>

                                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                                            <!-- Modal Moderno Centrado -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_modal_estilo" value="modern" class="peer sr-only" <?php echo $modalEstiloActual === 'modern' ? 'checked' : ''; ?> onchange="seleccionarEstiloModalPreview('modern')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-indigo-600 peer-checked:bg-indigo-50/40 dark:peer-checked:bg-indigo-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 mb-2.5 flex flex-col items-center gap-1.5">
                                                        <span class="w-6 h-6 rounded-full bg-indigo-500/15 text-indigo-600 flex items-center justify-center text-[10px]"><i class="fa-solid fa-paper-plane"></i></span>
                                                        <div class="h-1.5 w-16 bg-slate-700 dark:bg-slate-200 rounded"></div>
                                                        <div class="grid grid-cols-2 gap-1 w-full pt-1">
                                                            <div class="h-3 rounded bg-slate-200 dark:bg-slate-700"></div>
                                                            <div class="h-3 rounded bg-indigo-600"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Centrado con Halo</p>
                                                        <p class="text-[10px] text-slate-500">Icono circular y doble botón</p>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Modal Cristal Esmerilado -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_modal_estilo" value="glass" class="peer sr-only" <?php echo $modalEstiloActual === 'glass' ? 'checked' : ''; ?> onchange="seleccionarEstiloModalPreview('glass')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-indigo-600 peer-checked:bg-indigo-50/40 dark:peer-checked:bg-indigo-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="rounded-xl bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-700 overflow-hidden mb-2.5">
                                                        <div class="h-1 w-full bg-gradient-to-r from-indigo-500 via-cyan-500 to-emerald-500"></div>
                                                        <div class="p-2 flex flex-col items-center gap-1.5">
                                                            <span class="w-5 h-5 rounded-lg bg-cyan-500/15 text-cyan-600 flex items-center justify-center text-[10px]"><i class="fa-solid fa-sparkles"></i></span>
                                                            <div class="h-1.5 w-14 bg-slate-700 dark:bg-slate-200 rounded"></div>
                                                            <div class="grid grid-cols-2 gap-1 w-full pt-0.5">
                                                                <div class="h-2.5 rounded bg-slate-200 dark:bg-slate-700"></div>
                                                                <div class="h-2.5 rounded bg-indigo-600"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Cristal Esmerilado</p>
                                                        <p class="text-[10px] text-slate-500">Blur con franja degradada</p>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Modal Banner Ejecutivo -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_modal_estilo" value="executive" class="peer sr-only" <?php echo $modalEstiloActual === 'executive' ? 'checked' : ''; ?> onchange="seleccionarEstiloModalPreview('executive')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-indigo-600 peer-checked:bg-indigo-50/40 dark:peer-checked:bg-indigo-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 overflow-hidden mb-2.5">
                                                        <div class="p-2 flex items-center gap-2">
                                                            <span class="w-5 h-5 rounded-lg bg-amber-500/15 text-amber-500 flex items-center justify-center text-[10px] shrink-0"><i class="fa-solid fa-shield"></i></span>
                                                            <div class="space-y-1 flex-1">
                                                                <div class="h-1.5 w-14 bg-slate-700 dark:bg-slate-200 rounded"></div>
                                                                <div class="h-1 w-18 bg-slate-300 dark:bg-slate-600 rounded"></div>
                                                            </div>
                                                        </div>
                                                        <div class="px-2 py-1 bg-slate-50 dark:bg-slate-800 flex justify-end gap-1">
                                                            <div class="h-2.5 w-6 rounded bg-slate-300 dark:bg-slate-600"></div>
                                                            <div class="h-2.5 w-8 rounded bg-indigo-600"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Banner Ejecutivo</p>
                                                        <p class="text-[10px] text-slate-500">Horizontal con pie de acción</p>
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Modal Sheet Adaptativo -->
                                            <label class="cursor-pointer group">
                                                <input type="radio" name="ui_modal_estilo" value="sheet" class="peer sr-only" <?php echo $modalEstiloActual === 'sheet' ? 'checked' : ''; ?> onchange="seleccionarEstiloModalPreview('sheet')">
                                                <div class="p-3.5 rounded-2xl border-2 border-slate-200 dark:border-slate-800 peer-checked:border-indigo-600 peer-checked:bg-indigo-50/40 dark:peer-checked:bg-indigo-500/10 transition-all h-full flex flex-col justify-between">
                                                    <div class="p-2 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 mb-2.5 flex flex-col items-center gap-1">
                                                        <div class="w-6 h-1 rounded-full bg-slate-300 dark:bg-slate-600 mb-0.5"></div>
                                                        <div class="h-1.5 w-14 bg-slate-700 dark:bg-slate-200 rounded"></div>
                                                        <div class="w-full space-y-1 pt-1">
                                                            <div class="h-2.5 w-full rounded bg-indigo-600"></div>
                                                            <div class="h-2.5 w-full rounded bg-slate-200 dark:bg-slate-700"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Sheet Flotante</p>
                                                        <p class="text-[10px] text-slate-500">Deslizante estilo App móvil</p>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- GRILLA DE LOGOS: GENERAL, MODO CLARO, MODO OSCURO Y FAVICON -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                                <!-- 1. LOGO PRINCIPAL DEL SISTEMA Y COMPROBANTES -->
                                <div class="app-card p-6 flex flex-col justify-between space-y-4">
                                    <div>
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <div>
                                                <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold uppercase bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 mb-1">Principal & Facturas</span>
                                                <h4 class="text-sm font-bold text-slate-900 dark:text-white">Logo General del Sistema</h4>
                                            </div>
                                            <?php if (!empty($brandConfig['sistema_logo'])): ?>
                                            <button type="button" onclick="restaurarRecurso('sistema_logo')" class="text-[11px] text-rose-500 hover:text-rose-600 font-semibold flex items-center gap-1" title="Restaurar logo por defecto">
                                                <i class="fa-solid fa-rotate-left"></i> Restaurar
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-slate-500">Se muestra en las Facturas, Boletas, Exportaciones, Catálogo Público y como logo general si no defines uno específico para modo claro/oscuro.</p>
                                    </div>

                                    <!-- Caja de Previsualización -->
                                    <div class="h-36 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 bg-checkerboard-light flex flex-col items-center justify-center p-4 relative overflow-hidden group">
                                        <img id="prev_logo_sistema" src="<?php echo htmlspecialchars($brandConfig['sistema_logo'] ?: ''); ?>" alt="Vista previa Logo Sistema" class="max-h-24 max-w-[220px] object-contain <?php echo empty($brandConfig['sistema_logo']) ? 'hidden' : ''; ?>">
                                        <div id="empty_logo_sistema" class="text-center <?php echo !empty($brandConfig['sistema_logo']) ? 'hidden' : ''; ?>">
                                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-brand-600 to-blue-500 text-white flex items-center justify-center mx-auto mb-2 shadow-md">
                                                <i class="fa-solid fa-receipt text-xl"></i>
                                            </div>
                                            <p class="text-xs font-semibold text-slate-700">Emblema <?php echo htmlspecialchars($brandConfig['empresa_nombre_comercial']); ?> Activo</p>
                                            <p class="text-[10px] text-slate-400">Sube tu propio logo en PNG, SVG o WEBP</p>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-500/20 text-slate-700 dark:text-slate-200 hover:text-indigo-600 text-xs font-bold cursor-pointer border border-slate-200 dark:border-slate-700 transition-all">
                                            <i class="fa-solid fa-cloud-arrow-up"></i>
                                            <span id="lbl_logo_sistema">Seleccionar Logo del Sistema</span>
                                            <input type="file" name="logo_sistema_file" accept=".png,.jpg,.jpeg,.webp,.svg" class="hidden" onchange="previewImageUpload(this, 'prev_logo_sistema', 'empty_logo_sistema', 'lbl_logo_sistema')">
                                        </label>
                                        <p class="text-[10px] text-slate-400 mt-1.5 text-center">Recomendado: PNG o SVG transparente (300×90 px • Máx. 5 MB)</p>
                                    </div>
                                </div>

                                <!-- 2. FAVICON DEL NAVEGADOR -->
                                <div class="app-card p-6 flex flex-col justify-between space-y-4">
                                    <div>
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <div>
                                                <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold uppercase bg-amber-50 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300 mb-1">Pestaña Navegador</span>
                                                <h4 class="text-sm font-bold text-slate-900 dark:text-white">Favicon del Sistema</h4>
                                            </div>
                                            <?php if ($brandConfig['sistema_favicon'] !== 'uploads/branding/default-favicon.png'): ?>
                                            <button type="button" onclick="restaurarRecurso('sistema_favicon')" class="text-[11px] text-rose-500 hover:text-rose-600 font-semibold flex items-center gap-1">
                                                <i class="fa-solid fa-rotate-left"></i> Restaurar
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-slate-500">Icono que identifica al sistema en las pestañas del navegador, marcadores e historial tanto en vistas internas como públicas.</p>
                                    </div>

                                    <!-- Simulador de Pestaña de Navegador -->
                                    <div class="h-36 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-200/70 dark:bg-slate-900 p-4 flex flex-col justify-center">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Vista previa en pestaña del navegador:</p>
                                        <div class="bg-white dark:bg-slate-800 rounded-t-xl border border-b-0 border-slate-300 dark:border-slate-700 px-3.5 py-2.5 flex items-center justify-between max-w-xs shadow-sm">
                                            <div class="flex items-center gap-2.5 truncate">
                                                <img id="prev_favicon" src="<?php echo htmlspecialchars($brandConfig['sistema_favicon']); ?>" alt="Favicon" class="w-5 h-5 object-contain rounded-sm shrink-0">
                                                <span class="text-xs font-semibold text-slate-800 dark:text-slate-100 truncate">
                                                    <?php echo htmlspecialchars($brandConfig['empresa_nombre_comercial']); ?> • Facturación
                                                </span>
                                            </div>
                                            <i class="fa-solid fa-xmark text-[10px] text-slate-400 ml-2"></i>
                                        </div>
                                        <div class="bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-b-lg px-3 py-1.5 text-[10px] text-slate-400 font-mono flex items-center gap-1.5">
                                            <i class="fa-solid fa-lock text-emerald-500 text-[9px]"></i>
                                            <span>localhost/FACTURACIÓN/index.php</span>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-amber-50 dark:hover:bg-amber-500/20 text-slate-700 dark:text-slate-200 hover:text-amber-600 text-xs font-bold cursor-pointer border border-slate-200 dark:border-slate-700 transition-all">
                                            <i class="fa-solid fa-icons"></i>
                                            <span id="lbl_favicon">Cambiar Favicon (.PNG, .ICO, .SVG)</span>
                                            <input type="file" name="favicon_file" accept=".png,.ico,.svg,.webp" class="hidden" onchange="previewImageUpload(this, 'prev_favicon', null, 'lbl_favicon')">
                                        </label>
                                        <p class="text-[10px] text-slate-400 mt-1.5 text-center">Recomendado: Cuadrado 64×64 px o 128×128 px en PNG, ICO o SVG</p>
                                    </div>
                                </div>

                                <!-- 3. LOGO PARA MODO CLARO -->
                                <div class="app-card p-6 flex flex-col justify-between space-y-4">
                                    <div>
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <div>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase bg-amber-50 text-amber-700 border border-amber-200/60 mb-1">
                                                    <i class="fa-solid fa-sun text-amber-500"></i> Tema Claro
                                                </span>
                                                <h4 class="text-sm font-bold text-slate-900 dark:text-white">Logo para Modo Claro</h4>
                                            </div>
                                            <?php if (!empty($brandConfig['sistema_logo_claro'])): ?>
                                            <button type="button" onclick="restaurarRecurso('sistema_logo_claro')" class="text-[11px] text-rose-500 hover:text-rose-600 font-semibold flex items-center gap-1">
                                                <i class="fa-solid fa-rotate-left"></i> Restaurar
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-slate-500">Versión del logotipo con textos oscuros o colores vibrantes diseñada para resaltar sobre fondos blancos o claros.</p>
                                    </div>

                                    <!-- Previsualización sobre Fondo Claro -->
                                    <div class="h-36 rounded-2xl border-2 border-slate-200 bg-white keep-light flex flex-col items-center justify-center p-4 relative shadow-inner">
                                        <span class="absolute top-2.5 right-2.5 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200 keep-light">
                                            Fondo Claro (#FFFFFF)
                                        </span>
                                        <?php $previewClaro = $brandConfig['logo_claro_efectivo']; ?>
                                        <img id="prev_logo_claro" src="<?php echo htmlspecialchars($previewClaro ?: ''); ?>" alt="Logo Modo Claro" class="max-h-20 max-w-[210px] object-contain <?php echo empty($previewClaro) ? 'hidden' : ''; ?>">
                                        <div id="empty_logo_claro" class="flex items-center gap-2.5 <?php echo !empty($previewClaro) ? 'hidden' : ''; ?>">
                                            <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center shadow-sm">
                                                <i class="fa-solid fa-receipt"></i>
                                            </div>
                                            <div class="text-left">
                                                <p class="text-sm font-extrabold text-slate-900"><?php echo htmlspecialchars($brandConfig['empresa_nombre_comercial']); ?></p>
                                                <p class="text-[10px] text-slate-500">Vista por defecto en Modo Claro</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-500/20 text-slate-700 dark:text-slate-200 hover:text-indigo-600 text-xs font-bold cursor-pointer border border-slate-200 dark:border-slate-700 transition-all">
                                            <i class="fa-solid fa-sun"></i>
                                            <span id="lbl_logo_claro">Subir Logo para Modo Claro</span>
                                            <input type="file" name="logo_claro_file" accept=".png,.jpg,.jpeg,.webp,.svg" class="hidden" onchange="previewImageUpload(this, 'prev_logo_claro', 'empty_logo_claro', 'lbl_logo_claro')">
                                        </label>
                                    </div>
                                </div>

                                <!-- 4. LOGO PARA MODO OSCURO -->
                                <div class="app-card p-6 flex flex-col justify-between space-y-4">
                                    <div>
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <div>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase bg-slate-800 text-indigo-300 border border-slate-700 mb-1">
                                                    <i class="fa-solid fa-moon text-indigo-400"></i> Tema Oscuro
                                                </span>
                                                <h4 class="text-sm font-bold text-slate-900 dark:text-white">Logo para Modo Oscuro</h4>
                                            </div>
                                            <?php if (!empty($brandConfig['sistema_logo_oscuro'])): ?>
                                            <button type="button" onclick="restaurarRecurso('sistema_logo_oscuro')" class="text-[11px] text-rose-500 hover:text-rose-600 font-semibold flex items-center gap-1">
                                                <i class="fa-solid fa-rotate-left"></i> Restaurar
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-slate-500">Versión del logotipo con tipografía blanca o clara diseñada para destacar en el Modo Oscuro y pantallas nocturnas.</p>
                                    </div>

                                    <!-- Previsualización sobre Fondo Oscuro -->
                                    <div class="h-36 rounded-2xl border-2 border-slate-800 bg-slate-950 flex flex-col items-center justify-center p-4 relative shadow-inner">
                                        <span class="absolute top-2.5 right-2.5 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-900 text-slate-300 border border-slate-800">
                                            Fondo Oscuro (#0B0F19)
                                        </span>
                                        <?php $previewOscuro = $brandConfig['logo_oscuro_efectivo']; ?>
                                        <img id="prev_logo_oscuro" src="<?php echo htmlspecialchars($previewOscuro ?: ''); ?>" alt="Logo Modo Oscuro" class="max-h-20 max-w-[210px] object-contain <?php echo empty($previewOscuro) ? 'hidden' : ''; ?>">
                                        <div id="empty_logo_oscuro" class="flex items-center gap-2.5 <?php echo !empty($previewOscuro) ? 'hidden' : ''; ?>">
                                            <div class="w-10 h-10 rounded-xl bg-indigo-500/20 border border-indigo-500/40 text-indigo-300 flex items-center justify-center shadow-sm">
                                                <i class="fa-solid fa-receipt"></i>
                                            </div>
                                            <div class="text-left">
                                                <p class="text-sm font-extrabold text-white"><?php echo htmlspecialchars($brandConfig['empresa_nombre_comercial']); ?></p>
                                                <p class="text-[10px] text-slate-400">Vista por defecto en Modo Oscuro</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-500/20 text-slate-700 dark:text-slate-200 hover:text-indigo-600 text-xs font-bold cursor-pointer border border-slate-200 dark:border-slate-700 transition-all">
                                            <i class="fa-solid fa-moon"></i>
                                            <span id="lbl_logo_oscuro">Subir Logo para Modo Oscuro</span>
                                            <input type="file" name="logo_oscuro_file" accept=".png,.jpg,.jpeg,.webp,.svg" class="hidden" onchange="previewImageUpload(this, 'prev_logo_oscuro', 'empty_logo_oscuro', 'lbl_logo_oscuro')">
                                        </label>
                                    </div>
                                </div>

                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-brand-600/25 transition-all flex items-center gap-2">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                    <span>Guardar Identidad Visual y Logos</span>
                                </button>
                            </div>
                        </div>

                        <!-- =================================================================== -->
                        <!-- TAB 2: APLICACIÓN PROGRESIVA PWA (LOGO PWA, MANIFEST & OFFLINE)     -->
                        <!-- =================================================================== -->
                        <div id="tab-pwa" class="tab-content hidden space-y-6">

                            <!-- Tarjeta de Diagnóstico e Instalación PWA -->
                            <div class="app-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-500/5 via-indigo-500/5 to-transparent flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-center gap-3.5">
                                        <div class="w-12 h-12 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 dark:text-cyan-400 flex items-center justify-center text-xl shrink-0">
                                            <i class="fa-solid fa-mobile-screen-button"></i>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Progressive Web App (PWA)</h3>
                                                <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300">Activa e Instalable</span>
                                            </div>
                                            <p class="text-xs text-slate-500 mt-0.5">Permite instalar el sistema como una aplicación nativa en Windows, macOS, Android e iOS con soporte sin conexión.</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button type="button" onclick="window.triggerPwaInstall()" class="px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold rounded-xl shadow-sm transition-all flex items-center gap-2">
                                            <i class="fa-solid fa-download"></i>
                                            <span>Probar / Instalar App</span>
                                        </button>
                                        <button type="button" onclick="limpiarCachePwa()" class="px-3 py-2.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl transition-all" title="Actualizar caché del Service Worker">
                                            <i class="fa-solid fa-arrows-rotate"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Checklist Técnico PWA -->
                                <div class="p-4 sm:p-6 grid grid-cols-2 lg:grid-cols-4 gap-3 border-b border-slate-100 dark:border-slate-800">
                                    <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                                        <i class="fa-solid fa-circle-check text-emerald-500 text-base"></i>
                                        <div>
                                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100">Web Manifest</p>
                                            <a href="manifest.php" target="_blank" class="text-[10px] text-brand-600 hover:underline font-mono">manifest.php ↗</a>
                                        </div>
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                                        <i class="fa-solid fa-circle-check text-emerald-500 text-base" id="sw_status_icon"></i>
                                        <div>
                                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100">Service Worker</p>
                                            <p class="text-[10px] text-slate-500 font-mono" id="sw_status_text">sw.js registrado</p>
                                        </div>
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                                        <i class="fa-solid fa-circle-check text-emerald-500 text-base"></i>
                                        <div>
                                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100">Iconos Adaptativos</p>
                                            <p class="text-[10px] text-slate-500">192×192 & 512×512 PNG</p>
                                        </div>
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800 flex items-center gap-3">
                                        <i class="fa-solid fa-circle-check text-emerald-500 text-base"></i>
                                        <div>
                                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100">Contingencia Offline</p>
                                            <a href="offline.html" target="_blank" class="text-[10px] text-brand-600 hover:underline font-mono">offline.html ↗</a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Configuración de Logo PWA y Parámetros del Manifiesto -->
                                <div class="p-6 grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                                    <!-- Subida y Simulador de Icono PWA (5 columnas) -->
                                    <div class="lg:col-span-5 space-y-4">
                                        <div class="flex items-center justify-between">
                                            <label class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider">Logo / Icono de la App PWA</label>
                                            <?php if ($brandConfig['sistema_logo_pwa'] !== 'uploads/branding/default-pwa-512.png'): ?>
                                            <button type="button" onclick="restaurarRecurso('sistema_logo_pwa')" class="text-[11px] text-rose-500 hover:text-rose-600 font-semibold flex items-center gap-1">
                                                <i class="fa-solid fa-rotate-left"></i> Restaurar
                                            </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Simulador de Splash Screen & Home Screen Icon -->
                                        <div id="pwa_splash_preview" class="rounded-2xl p-6 border border-slate-800 flex flex-col items-center justify-center text-center shadow-xl relative overflow-hidden" style="background-color: <?php echo htmlspecialchars($brandConfig['pwa_bg_color']); ?>;">
                                            <div class="w-full flex justify-between items-center text-[10px] text-white/60 mb-4 px-1">
                                                <span>9:41</span>
                                                <span class="px-2 py-0.5 rounded-full bg-white/10 text-white/80">Vista Previa PWA</span>
                                                <span><i class="fa-solid fa-wifi mr-1"></i><i class="fa-solid fa-battery-full"></i></span>
                                            </div>
                                            <div class="w-24 h-24 rounded-3xl bg-white/10 backdrop-blur-md p-2 shadow-2xl border border-white/15 flex items-center justify-center mb-3">
                                                <img id="prev_logo_pwa" src="<?php echo htmlspecialchars($brandConfig['sistema_logo_pwa']); ?>" alt="Icono PWA" class="w-full h-full object-contain rounded-2xl">
                                            </div>
                                            <p id="preview_pwa_short" class="text-sm font-extrabold text-white tracking-tight">
                                                <?php echo htmlspecialchars($brandConfig['pwa_nombre_corto']); ?>
                                            </p>
                                            <p class="text-[11px] text-white/60 mt-0.5">Pantalla de Inicio / Escritorio</p>
                                        </div>

                                        <div>
                                            <label class="flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-xl bg-cyan-50 dark:bg-cyan-500/15 hover:bg-cyan-100 dark:hover:bg-cyan-500/25 text-cyan-700 dark:text-cyan-300 text-xs font-bold cursor-pointer border border-cyan-200 dark:border-cyan-500/30 transition-all">
                                                <i class="fa-solid fa-Upload"></i>
                                                <span id="lbl_logo_pwa">Cambiar Logo de la PWA (Auto 192px & 512px)</span>
                                                <input type="file" name="logo_pwa_file" accept=".png,.jpg,.jpeg,.webp,.svg" class="hidden" onchange="previewImageUpload(this, 'prev_logo_pwa', null, 'lbl_logo_pwa')">
                                            </label>
                                            <p class="text-[10px] text-slate-400 mt-1.5 text-center">El sistema genera automáticamente las resoluciones oficiales <strong>192×192</strong> y <strong>512×512 PNG</strong> exigidas por Chrome, Android e iOS.</p>
                                        </div>
                                    </div>

                                    <!-- Campos del Manifiesto PWA (7 columnas) -->
                                    <div class="lg:col-span-7 space-y-4">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div class="sm:col-span-2">
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Estado del Módulo PWA</label>
                                                <select name="pwa_habilitado" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-semibold">
                                                    <option value="1" <?php echo $brandConfig['pwa_habilitado'] ? 'selected' : ''; ?>>✅ Habilitado (Permitir instalar como App en PC y Móvil)</option>
                                                    <option value="0" <?php echo !$brandConfig['pwa_habilitado'] ? 'selected' : ''; ?>>⏸️ Deshabilitado (Solo modo navegador tradicional)</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Nombre Corto (Icono App)</label>
                                                <input type="text" name="pwa_nombre_corto" maxlength="20" value="<?php echo htmlspecialchars($brandConfig['pwa_nombre_corto']); ?>" oninput="document.getElementById('preview_pwa_short').textContent = this.value || 'FACTUSYS'" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-semibold" placeholder="FACTUSYS">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Nombre Completo de la App</label>
                                                <input type="text" name="pwa_nombre" maxlength="60" value="<?php echo htmlspecialchars($brandConfig['pwa_nombre']); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5" placeholder="FACTUSYS • Facturación & CRM">
                                            </div>

                                            <div class="sm:col-span-2">
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Descripción de la Aplicación</label>
                                                <input type="text" name="pwa_descripcion" value="<?php echo htmlspecialchars($brandConfig['pwa_descripcion']); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Color de Barra de Ventana (Theme)</label>
                                                <div class="flex items-center gap-2">
                                                    <input type="color" name="pwa_theme_color" value="<?php echo htmlspecialchars($brandConfig['pwa_theme_color']); ?>" class="w-11 h-10 rounded-xl border border-slate-300 cursor-pointer bg-transparent p-0.5">
                                                    <input type="text" value="<?php echo htmlspecialchars($brandConfig['pwa_theme_color']); ?>" readonly class="flex-1 bg-slate-50 border border-slate-300 text-slate-700 text-xs font-mono rounded-xl p-2.5">
                                                </div>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Color de Fondo Splash Screen</label>
                                                <div class="flex items-center gap-2">
                                                    <input type="color" name="pwa_bg_color" value="<?php echo htmlspecialchars($brandConfig['pwa_bg_color']); ?>" oninput="document.getElementById('pwa_splash_preview').style.backgroundColor = this.value" class="w-11 h-10 rounded-xl border border-slate-300 cursor-pointer bg-transparent p-0.5">
                                                    <input type="text" value="<?php echo htmlspecialchars($brandConfig['pwa_bg_color']); ?>" readonly class="flex-1 bg-slate-50 border border-slate-300 text-slate-700 text-xs font-mono rounded-xl p-2.5">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/60 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Guardar Configuración PWA</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- =================================================================== -->
                        <!-- TAB 3: DATOS DE LA EMPRESA EMISORA                                  -->
                        <!-- =================================================================== -->
                        <div id="tab-empresa" class="tab-content hidden space-y-6">
                            <div class="app-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-500/15 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                                            <i class="fa-solid fa-building"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-base font-bold text-slate-900 dark:text-white">Datos Tributarios del Emisor</h3>
                                            <p class="text-xs text-slate-500">Información fiscal que se imprime en Facturas, Boletas, Exportaciones y XML UBL 2.1.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Número de RUC Emisor</label>
                                        <div class="flex gap-2">
                                            <input type="text" id="inp_empresa_ruc" name="empresa_ruc" value="<?php echo htmlspecialchars($cfg['empresa_ruc'] ?? ''); ?>" maxlength="11" class="flex-1 bg-slate-50 border border-slate-300 text-slate-900 text-sm font-mono font-bold rounded-xl p-2.5" placeholder="20000000001">
                                            <button type="button" id="btn_buscar_ruc_emp" onclick="consultarRucEmpresa()" class="px-3.5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-xl transition-colors flex items-center gap-1.5 shrink-0" title="Autocompletar desde padrón SUNAT">
                                                <i class="fa-solid fa-magnifying-glass"></i> SUNAT
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Razón Social Oficial</label>
                                        <input type="text" id="inp_empresa_razon" name="empresa_razon_social" value="<?php echo htmlspecialchars($cfg['empresa_razon_social'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-semibold">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Nombre Comercial / Marca del Sistema</label>
                                        <input type="text" name="empresa_nombre_comercial" value="<?php echo htmlspecialchars($cfg['empresa_nombre_comercial'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-semibold" placeholder="FACTUSYS">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Teléfono / WhatsApp Comercial</label>
                                        <input type="text" name="empresa_telefono" value="<?php echo htmlspecialchars($cfg['empresa_telefono'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5" placeholder="+51 999 888 777">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Correo Electrónico Corporativo</label>
                                        <input type="email" name="empresa_correo" value="<?php echo htmlspecialchars($cfg['empresa_correo'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5" placeholder="facturacion@miempresa.com">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Sitio Web / Portal de Consultas</label>
                                        <input type="text" name="empresa_web" value="<?php echo htmlspecialchars($cfg['empresa_web'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5" placeholder="https://www.miempresa.com">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Dirección Fiscal Completa</label>
                                        <input type="text" id="inp_empresa_dir" name="empresa_direccion" value="<?php echo htmlspecialchars($cfg['empresa_direccion'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Ubigeo SUNAT (6 dígitos)</label>
                                        <input type="text" id="inp_empresa_ubigeo" name="empresa_ubigeo" value="<?php echo htmlspecialchars($cfg['empresa_ubigeo'] ?? '150101'); ?>" maxlength="6" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm font-mono rounded-xl p-2.5" placeholder="150101">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Departamento</label>
                                        <input type="text" id="inp_empresa_dep" name="empresa_departamento" value="<?php echo htmlspecialchars($cfg['empresa_departamento'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Provincia</label>
                                        <input type="text" id="inp_empresa_prov" name="empresa_provincia" value="<?php echo htmlspecialchars($cfg['empresa_provincia'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Distrito</label>
                                        <input type="text" id="inp_empresa_dist" name="empresa_distrito" value="<?php echo htmlspecialchars($cfg['empresa_distrito'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5">
                                    </div>
                                </div>

                                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/60 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Guardar Datos de Empresa</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- =================================================================== -->
                        <!-- TAB 4: INTEGRACIÓN SUNAT (GREENTER / NUBEFACT OSE)                  -->
                        <!-- =================================================================== -->
                        <div id="tab-ose" class="tab-content hidden space-y-6">
                            <div class="app-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                                        <i class="fa-solid fa-satellite-dish"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Motor de Facturación Electrónica SUNAT</h3>
                                        <p class="text-xs text-slate-500">Configura la emisión directa UBL 2.1 con Greenter (sin comisiones) o mediante OSE Nubefact.</p>
                                    </div>
                                </div>

                                <div class="p-6 space-y-6">
                                    <?php $prov = strtolower($cfg['facturacion_proveedor'] ?? 'greenter'); ?>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-2.5">Proveedor de Emisión</label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <label class="border-2 rounded-2xl p-4 cursor-pointer transition-all flex items-start gap-3 <?php echo ($prov === 'greenter') ? 'border-emerald-500 bg-emerald-50/40 dark:bg-emerald-500/10' : 'border-slate-200 dark:border-slate-800 hover:border-slate-300'; ?>">
                                                <input type="radio" name="facturacion_proveedor" value="greenter" class="mt-1 text-emerald-600 focus:ring-emerald-500" <?php echo ($prov === 'greenter') ? 'checked' : ''; ?> onchange="toggleProveedor()">
                                                <div>
                                                    <p class="font-bold text-sm text-slate-900 dark:text-white flex items-center gap-1.5">
                                                        Greenter (Directo SUNAT)
                                                        <span class="text-[10px] bg-emerald-100 dark:bg-emerald-500/20 text-emerald-800 dark:text-emerald-300 font-bold px-2 py-0.5 rounded-full">S/ 0.00 GRATIS</span>
                                                    </p>
                                                    <p class="text-xs text-slate-500 mt-1">Conexión directa SOAP UBL 2.1 con servidores de SUNAT y firma local X.509.</p>
                                                </div>
                                            </label>
                                            <label class="border-2 rounded-2xl p-4 cursor-pointer transition-all flex items-start gap-3 <?php echo ($prov === 'facturalo' || $prov === 'nubefact') ? 'border-sky-500 bg-sky-50/40 dark:bg-sky-500/10' : 'border-slate-200 dark:border-slate-800 hover:border-slate-300'; ?>">
                                                <input type="radio" name="facturacion_proveedor" value="facturalo" class="mt-1 text-sky-600 focus:ring-sky-500" <?php echo ($prov === 'facturalo' || $prov === 'nubefact') ? 'checked' : ''; ?> onchange="toggleProveedor()">
                                                <div>
                                                    <p class="font-bold text-sm text-slate-900 dark:text-white flex items-center gap-1.5">
                                                        Facturalo.pe API v3
                                                        <span class="text-[10px] bg-sky-100 dark:bg-sky-500/20 text-sky-800 dark:text-sky-300 font-bold px-2 py-0.5 rounded-full">RECOMENDADO</span>
                                                    </p>
                                                    <p class="text-xs text-slate-500 mt-1">API REST de alta velocidad con cálculo de IGV automático y consultas DNI/RUC integradas.</p>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- SECCIÓN GREENTER -->
                                    <div id="sec-greenter" class="space-y-5 <?php echo ($prov !== 'greenter') ? 'hidden' : ''; ?>">
                                        <div class="bg-emerald-50/80 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/25 rounded-2xl p-4 flex items-start gap-3">
                                            <i class="fa-solid fa-shield-halved text-emerald-600 dark:text-emerald-400 text-lg mt-0.5 shrink-0"></i>
                                            <div>
                                                <p class="text-xs font-bold text-emerald-900 dark:text-emerald-200">Plan de Contingencia y Reintentos Automáticos Activo</p>
                                                <p class="text-xs text-emerald-700 dark:text-emerald-300/80 mt-0.5">
                                                    Si SUNAT presenta intermitencia, el comprobante se firma localmente como <strong>Pendiente SUNAT</strong> y puede sincronizarse en lote con un clic.
                                                </p>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Entorno de Emisión SUNAT</label>
                                                <?php $modoSunat = strtolower($cfg['sunat_modo'] ?? 'beta'); ?>
                                                <select name="sunat_modo" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-semibold">
                                                    <option value="beta" <?php echo ($modoSunat === 'beta') ? 'selected' : ''; ?>>🧪 Beta / Pruebas (Servidores Demo SUNAT)</option>
                                                    <option value="produccion" <?php echo ($modoSunat === 'produccion') ? 'selected' : ''; ?>>🚀 Producción (SUNAT Oficial con Validez Fiscal)</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Usuario Secundario Clave SOL</label>
                                                <input type="text" name="sunat_usuario_sol" value="<?php echo htmlspecialchars($cfg['sunat_usuario_sol'] ?? 'MODDATOS'); ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-mono" placeholder="MODDATOS">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Contraseña Clave SOL</label>
                                                <input type="password" name="sunat_clave_sol" value="" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-mono" placeholder="<?php echo !empty($cfg['sunat_clave_sol']) ? '•••••••• (Guardada - escribe para cambiar)' : 'Ingresa tu clave SOL'; ?>">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Contraseña del Certificado Digital</label>
                                                <input type="password" name="sunat_cert_password" value="" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-mono" placeholder="<?php echo !empty($cfg['sunat_cert_password']) ? '•••••••• (Guardada - escribe para cambiar)' : 'Contraseña de tu archivo .pfx'; ?>">
                                            </div>

                                            <div class="md:col-span-2">
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide mb-1.5">Certificado Digital Tributario (.pfx / .p12 / .pem)</label>
                                                <input type="file" name="sunat_cert_file" accept=".pfx,.p12,.pem" class="block w-full text-xs text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 border border-slate-300 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800">
                                                <p class="text-[11px] text-slate-500 mt-1.5 flex items-center gap-1.5">
                                                    <i class="fa-solid fa-file-shield text-emerald-600"></i>
                                                    <?php
                                                    $currCert = $cfg['sunat_cert_path'] ?? '';
                                                    if (!empty($currCert) && file_exists($currCert)) {
                                                        echo "Certificado activo en bóveda segura: <strong>" . htmlspecialchars(basename($currCert)) . "</strong>";
                                                    } else {
                                                        echo "Certificado de prueba activo: <strong>certificate_beta.pem</strong> (Listo para entorno Beta).";
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="pt-1">
                                            <button type="button" id="btn_test_sunat" onclick="testConexionSunat()" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-2 shadow-sm">
                                                <i class="fa-solid fa-satellite-dish"></i> Probar Conexión Directa con SUNAT
                                            </button>
                                            <div id="test_sunat_result" class="mt-3 text-sm hidden"></div>
                                        </div>
                                    </div>

                                    <!-- SECCIÓN FACTURALO API V3 -->
                                    <div id="sec-facturalo" class="space-y-5 <?php echo ($prov === 'greenter') ? 'hidden' : ''; ?>">
                                        <div class="bg-sky-50/80 dark:bg-sky-500/10 border border-sky-200 dark:border-sky-500/25 rounded-2xl p-4 flex items-start gap-3">
                                            <i class="fa-solid fa-bolt-lightning text-sky-600 dark:text-sky-400 text-lg mt-0.5 shrink-0"></i>
                                            <div>
                                                <p class="text-xs font-bold text-sky-900 dark:text-sky-200">Facturación Electrónica y Consultas SUNAT/RENIEC Unificadas</p>
                                                <p class="text-xs text-sky-700 dark:text-sky-300/80 mt-0.5">
                                                    Facturalo API v3 automatiza el cálculo de IGV, firma digital, envío a SUNAT, links a PDF/XML/CDR y consultas de DNI y RUC con un solo token de acceso.
                                                </p>
                                            </div>
                                        </div>

                                        <div>
                                            <div class="flex items-center justify-between mb-1.5">
                                                <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide">
                                                    Secret Token Facturalo API v3
                                                </label>
                                                <span id="badge_facturalo_mode" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                                    <?php
                                                    $curFactToken = $cfg['facturalo_token'] ?? '';
                                                    if (str_starts_with($curFactToken, 'sk_test_')) {
                                                        echo '🧪 MODO DEMO / PRUEBAS';
                                                    } elseif (str_starts_with($curFactToken, 'sk_live_')) {
                                                        echo '🚀 MODO PRODUCCIÓN';
                                                    } else {
                                                        echo !empty($curFactToken) ? '🔒 TOKEN CONFIGURADO' : '⚪ TOKEN NO CONFIGURADO';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="relative">
                                                <input type="password" id="inp_facturalo_token" name="facturalo_token" value="" oninput="detectarModoFacturalo(this.value)" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-mono pr-12 focus:outline-none focus:border-sky-500" placeholder="<?php echo !empty($cfg['facturalo_token']) ? '•••••••••••••••• (Token guardado - escribe para reemplazar)' : 'sk_test_... o sk_live_...'; ?>">
                                                <button type="button" onclick="togglePasswordVisibility('inp_facturalo_token', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm p-1">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                            </div>
                                            <p class="text-[11px] text-slate-400 mt-1">
                                                Tokens que inician con <code class="text-sky-600 font-bold">sk_test_...</code> emiten a servidores Demo SUNAT. Tokens <code class="text-emerald-600 font-bold">sk_live_...</code> emiten en Producción con validez fiscal legal.
                                            </p>
                                        </div>

                                        <div class="pt-1">
                                            <button type="button" id="btn_test_facturalo" onclick="testConexionFacturalo()" class="px-5 py-2.5 bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-2 shadow-sm">
                                                <i class="fa-solid fa-satellite-dish"></i> Probar Conexión con Facturalo API v3
                                            </button>
                                            <div id="test_facturalo_result" class="mt-3 text-sm hidden"></div>
                                        </div>
                                    </div>

                                    <!-- CONSULTA DNI / RUC RESPALDO -->
                                    <div class="border-t border-slate-100 dark:border-slate-800 pt-5">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide">
                                                Token de Consulta de Respaldo (api.json.pe)
                                            </label>
                                            <span class="text-[10px] font-mono bg-slate-100 dark:bg-slate-800 text-slate-500 px-2 py-0.5 rounded">CONTINGENCIA</span>
                                        </div>
                                        <input type="password" name="api_token_documento" value="" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-sm rounded-xl p-2.5 font-mono" placeholder="<?php echo !empty($cfg['api_token_documento']) ? '•••••••••••••••• (Token configurado - escribe para actualizar)' : 'Token Bearer para json.pe (Opcional)'; ?>">
                                        <p class="text-[11px] text-slate-400 mt-1">Cuando Facturalo está configurado, las consultas de RUC y DNI se realizan a través de Facturalo automáticamente. Este token actúa como contingencia secundaria.</p>
                                    </div>
                                </div>

                                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/60 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Guardar Configuración SUNAT</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- =================================================================== -->
                        <!-- TAB 5: SERIES Y CORRELATIVOS                                        -->
                        <!-- =================================================================== -->
                        <div id="tab-series" class="tab-content hidden space-y-6">
                            <div class="app-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                                        <i class="fa-solid fa-list-ol"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Series y Correlativos Electrónicos</h3>
                                        <p class="text-xs text-slate-500">Administra las series alfanuméricas de 4 caracteres autorizadas para tus emisiones UBL 2.1.</p>
                                    </div>
                                </div>

                                <div class="p-6 overflow-x-auto">
                                    <?php
                                    $rowsSeries = [
                                        ['label' => 'Factura Electrónica (01)',    'name' => 'serie_factura',     'val' => $brandConfig['serie_factura'],     'badge' => 'Gravada / Nacional'],
                                        ['label' => 'Boleta de Venta (03)',        'name' => 'serie_boleta',      'val' => $brandConfig['serie_boleta'],      'badge' => 'Consumidor Final'],
                                        ['label' => 'Factura de Exportación (01)', 'name' => 'serie_exportacion', 'val' => $brandConfig['serie_exportacion'], 'badge' => 'Comercio Exterior (0% IGV)'],
                                        ['label' => 'Guía de Remisión Remitente',  'name' => 'serie_guia',        'val' => $brandConfig['serie_guia'],        'badge' => 'Traslado de Bienes'],
                                    ];
                                    ?>
                                    <table class="w-full text-xs text-left">
                                        <thead class="text-[11px] text-slate-500 uppercase bg-slate-50 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800">
                                            <tr>
                                                <th class="px-4 py-3">Tipo de Documento</th>
                                                <th class="px-4 py-3">Modalidad</th>
                                                <th class="px-4 py-3">Serie Activa</th>
                                                <th class="px-4 py-3 text-center">CPEs Emitidos</th>
                                                <th class="px-4 py-3 text-right">Próximo Correlativo</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            <?php foreach ($rowsSeries as $sr):
                                                $sKey = strtoupper($sr['val']);
                                                $totEmit = (int)($seriesStats[$sKey]['total_docs'] ?? 0);
                                                $nextCorr = ((int)($seriesStats[$sKey]['max_corr'] ?? 0)) + 1;
                                            ?>
                                            <tr>
                                                <td class="px-4 py-3.5 font-bold text-slate-800 dark:text-slate-100"><?php echo htmlspecialchars($sr['label']); ?></td>
                                                <td class="px-4 py-3.5">
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                                        <?php echo htmlspecialchars($sr['badge']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3.5">
                                                    <input type="text" name="<?php echo $sr['name']; ?>" value="<?php echo htmlspecialchars($sr['val']); ?>" maxlength="4" class="w-24 bg-slate-50 border border-slate-300 text-slate-900 text-xs font-mono font-bold uppercase rounded-lg px-2.5 py-1.5 text-center">
                                                </td>
                                                <td class="px-4 py-3.5 text-center font-semibold text-slate-600 dark:text-slate-300">
                                                    <?php echo $totEmit; ?> docs
                                                </td>
                                                <td class="px-4 py-3.5 text-right font-mono font-bold text-brand-600 dark:text-brand-400">
                                                    <?php echo htmlspecialchars($sKey . '-' . str_pad((string)$nextCorr, 6, '0', STR_PAD_LEFT)); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/60 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-md transition-all flex items-center gap-2">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        <span>Guardar Series</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- =================================================================== -->
                        <!-- TAB 6: ACTUALIZACIONES DEL SISTEMA & REPOSITORIO GITHUB             -->
                        <!-- =================================================================== -->
                        <div id="tab-actualizacion" class="tab-content hidden space-y-6">
                            
                            <!-- Banner de Seguridad y Garantía de Datos -->
                            <div class="p-5 rounded-2xl bg-gradient-to-r from-slate-900 via-cyan-950 to-slate-900 text-white border border-cyan-800/40 shadow-xl relative overflow-hidden">
                                <div class="absolute -right-8 -bottom-8 w-48 h-48 bg-cyan-500/15 rounded-full blur-3xl pointer-events-none"></div>
                                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div>
                                        <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-cyan-500/20 text-cyan-300 text-[11px] font-semibold mb-2">
                                            <i class="fa-solid fa-shield-halved"></i>
                                            <span>Protección de Datos & Integridad de Esquema</span>
                                        </div>
                                        <h3 class="text-lg font-bold text-white tracking-tight">Centro de Actualizaciones Continuas</h3>
                                        <p class="text-xs text-slate-300 max-w-2xl mt-1 leading-relaxed">
                                            Descarga la última versión del repositorio oficial de GitHub. El sistema actualiza el código fuente y crea automáticamente cualquier cambio o nueva tabla en la base de datos sin alterar nunca tus datos existentes ni tus credenciales locales.
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <a href="https://github.com/networkturbo4-pixel/facturaci-n" target="_blank" rel="noopener noreferrer" class="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 text-xs font-semibold text-white transition-all flex items-center gap-2">
                                            <i class="fa-brands fa-github text-sm"></i>
                                            <span>Ver en GitHub</span>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- 4 Pilares de Seguridad Visuales -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-500 flex items-center justify-center shrink-0 text-sm">
                                        <i class="fa-solid fa-database"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-xs font-bold text-slate-900 dark:text-white">Datos Intactos</h5>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Tus comprobantes, clientes y ventas jamás se sobreescriben.</p>
                                    </div>
                                </div>
                                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-500 flex items-center justify-center shrink-0 text-sm">
                                        <i class="fa-solid fa-key"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-xs font-bold text-slate-900 dark:text-white">Credenciales Aisladas</h5>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><code class="text-[10px] font-mono">db.config.php</code> está protegido y excluido de Git.</p>
                                    </div>
                                </div>
                                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-cyan-500/10 text-cyan-500 flex items-center justify-center shrink-0 text-sm">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-xs font-bold text-slate-900 dark:text-white">Migración Automática</h5>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Nuevas columnas y tablas de BD se aplican automáticamente.</p>
                                    </div>
                                </div>
                                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center shrink-0 text-sm">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-xs font-bold text-slate-900 dark:text-white">Storage Preservado</h5>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">XMLs, CDRs, PDFs y logos se mantienen 100% seguros.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Tarjeta de Estado del Repositorio y Botones de Acción -->
                            <div class="app-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-cyan-50 dark:bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center text-lg">
                                            <i class="fa-brands fa-github"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-base font-bold text-slate-900 dark:text-white">Estado de Sincronización</h4>
                                            <p class="text-xs text-slate-500" id="lbl_repo_subtext">Comprueba y descarga las actualizaciones disponibles.</p>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" onclick="comprobarActualizaciones()" id="btnCheckUpdate" class="px-4 py-2 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-all shadow-xs flex items-center gap-2">
                                            <i class="fa-solid fa-arrows-rotate text-brand-500"></i>
                                            <span>1. Comprobar Actualizaciones</span>
                                        </button>
                                        <button type="button" onclick="ejecutarActualizacionSistema()" id="btnApplyUpdate" class="px-5 py-2 rounded-xl bg-gradient-to-r from-cyan-600 via-indigo-600 to-brand-600 hover:from-cyan-500 hover:to-brand-500 text-white text-xs font-bold shadow-lg shadow-indigo-500/25 transition-all flex items-center gap-2">
                                            <i class="fa-solid fa-cloud-arrow-down"></i>
                                            <span>2. Actualizar Sistema Ahora</span>
                                        </button>
                                        <button type="button" onclick="ejecutarMigracionesBD()" id="btnMigrateOnly" class="px-3.5 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-500/15 hover:bg-emerald-100 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 text-xs font-bold transition-all flex items-center gap-2">
                                            <i class="fa-solid fa-database"></i>
                                            <span>Actualizar BD</span>
                                        </button>
                                        <input type="file" id="inp_zip_update" accept=".zip" class="hidden" onchange="subirPaqueteZipActualizacion(this)">
                                        <button type="button" onclick="document.getElementById('inp_zip_update').click()" id="btnUploadZip" class="px-3.5 py-2 rounded-xl bg-purple-50 dark:bg-purple-500/15 hover:bg-purple-100 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-500/30 text-xs font-bold transition-all flex items-center gap-2" title="Subir paquete de actualización en formato ZIP (Ideal para hostings cPanel)">
                                            <i class="fa-solid fa-file-zipper text-purple-600"></i>
                                            <span>Subir Paquete ZIP</span>
                                        </button>
                                    </div>
                                </div>

                                <div class="p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/70 dark:border-slate-700/60 space-y-1.5">
                                        <span class="text-slate-400 text-[11px] font-semibold uppercase tracking-wider block">Repositorio Origen</span>
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-link text-cyan-500"></i>
                                            <span class="font-mono font-medium text-slate-800 dark:text-slate-200 truncate" id="repo_url_display">github.com/...</span>
                                        </div>
                                    </div>
                                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/70 dark:border-slate-700/60 space-y-1.5">
                                        <span class="text-slate-400 text-[11px] font-semibold uppercase tracking-wider block">Rama & Commit Actual</span>
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-code-branch text-brand-500"></i>
                                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="repo_branch_display">main</span>
                                            <span class="px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-[10px] font-mono text-slate-700 dark:text-slate-300" id="repo_commit_display">-</span>
                                        </div>
                                    </div>
                                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/70 dark:border-slate-700/60 space-y-1.5">
                                        <span class="text-slate-400 text-[11px] font-semibold uppercase tracking-wider block">Estado de Base de Datos</span>
                                        <div class="flex items-center gap-2">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500" id="db_status_dot"></span>
                                            <span class="font-semibold text-emerald-600 dark:text-emerald-400" id="db_status_text">Verificando...</span>
                                        </div>
                                    </div>
                                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/70 dark:border-slate-700/60 space-y-1.5">
                                        <span class="text-slate-400 text-[11px] font-semibold uppercase tracking-wider block">Entorno de Producción</span>
                                        <div class="flex items-center gap-2">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500" id="prod_health_dot"></span>
                                            <span class="font-semibold text-emerald-600 dark:text-emerald-400" id="prod_health_badge">Verificando...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Configuración de Token GitHub y Compatibilidad cPanel -->
                            <div class="app-card p-6 space-y-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 flex items-center justify-center">
                                        <i class="fa-brands fa-github text-base"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-xs font-bold text-slate-900 dark:text-white">Token de Acceso GitHub (Para Servidores Web / cPanel)</h5>
                                        <p class="text-[11px] text-slate-500">Permite que el servidor se conecte al repositorio privado en GitHub. Si tu hosting bloquea comandos Git, puedes usar el botón <b>Subir Paquete ZIP</b>.</p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">GitHub Personal Access Token (PAT)</label>
                                        <input type="password" name="github_token" value="" placeholder="<?php echo !empty($cfg['github_token']) ? '•••••••••••••••• (Token guardado - escribe para actualizar)' : 'ghp_... o github_pat_...'; ?>" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-xl p-2.5 font-mono">
                                    </div>
                                    <div>
                                        <button type="submit" class="w-full px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-xl transition-all flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-floppy-disk"></i> Guardar Token GitHub
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Consola en Vivo de Actualización (Terminal) -->
                            <div class="app-card overflow-hidden">
                                <div class="px-4 py-3 bg-slate-900 border-b border-slate-800 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="flex items-center gap-1.5">
                                            <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                                            <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                                            <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                        </div>
                                        <span class="text-xs font-mono text-slate-400 ml-2">Terminal de Actualizaciones & Migraciones</span>
                                    </div>
                                    <button type="button" onclick="limpiarConsolaLogs()" class="text-[11px] text-slate-400 hover:text-white transition-colors">
                                        <i class="fa-solid fa-trash-can mr-1"></i> Limpiar
                                    </button>
                                </div>
                                <div id="updateConsoleLogs" class="p-4 bg-slate-950 font-mono text-xs text-emerald-400 h-52 overflow-y-auto space-y-1.5 select-text">
                                    <p class="text-slate-500">[INFO] Centro de actualizaciones iniciado y listo.</p>
                                    <p class="text-slate-500">[INFO] Pulsa "1. Comprobar Actualizaciones" para verificar cambios remotos.</p>
                                </div>
                            </div>

                        </div>

                    </form>

                    <!-- Formulario oculto auxiliar para restaurar un logo individual -->
                    <form id="resetAssetForm" method="POST" action="configuracion.php" class="hidden">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="restaurar_activo" id="inp_restaurar_activo" value="">
                        <input type="hidden" name="active_tab" id="inp_reset_tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                    </form>
                </div>

            </div>
        </div>
    </div>
</main>

<script>
// Gestión de Pestañas con persistencia de estado
function activateConfigTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    const target = document.getElementById('tab-' + tabName);
    if (target) target.classList.remove('hidden');

    document.querySelectorAll('.tab-link').forEach(btn => {
        const isCurrent = btn.dataset.tab === tabName;
        btn.classList.toggle('bg-brand-50', isCurrent);
        btn.classList.toggle('dark:bg-brand-500/15', isCurrent);
        btn.classList.toggle('ring-1', isCurrent);
        btn.classList.toggle('ring-brand-500/30', isCurrent);
    });

    const inpTab = document.getElementById('inp_active_tab');
    if (inpTab) inpTab.value = tabName;
    const inpResetTab = document.getElementById('inp_reset_tab');
    if (inpResetTab) inpResetTab.value = tabName;
}

document.querySelectorAll('.tab-link').forEach(btn => {
    btn.addEventListener('click', () => {
        activateConfigTab(btn.dataset.tab);
        history.replaceState(null, '', '?tab=' + btn.dataset.tab);
    });
});

// Inicializar pestaña activa
activateConfigTab('<?php echo htmlspecialchars($activeTab); ?>');

// Vista previa instantánea de imágenes antes de enviar el formulario
function previewImageUpload(input, imgId, emptyId, labelId) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById(imgId);
        if (img) {
            img.src = e.target.result;
            img.classList.remove('hidden');
        }
        if (emptyId) {
            const emp = document.getElementById(emptyId);
            if (emp) emp.classList.add('hidden');
        }
        if (labelId) {
            const lbl = document.getElementById(labelId);
            if (lbl) lbl.textContent = 'Listo: ' + file.name + ' (Pulsa Guardar)';
        }
    };
    reader.readAsDataURL(file);
}

async function restaurarRecurso(clave) {
    const ok = await window.showConfirm({
        title: 'Restaurar Imagen Original',
        message: '¿Deseas restaurar este recurso visual a su imagen predeterminada del sistema?',
        confirmText: 'Sí, restaurar',
        variant: 'danger'
    });
    if (!ok) return;
    document.getElementById('inp_restaurar_activo').value = clave;
    document.getElementById('resetAssetForm').submit();
}

function seleccionarEstiloToastPreview(style) {
    document.documentElement.setAttribute('data-toast-style', style);
    const nombres = {
        glass: 'Glassmorphism Pro',
        minimal: 'Minimalista Ejecutivo',
        solid: 'Sólido Vibrante',
        capsule: 'Isla Dinámica / Pill'
    };
    window.showToast('Vista previa activa del estilo "' + (nombres[style] || style) + '". Recuerda guardar los cambios.', 'success', 'Estilo Toast: ' + (nombres[style] || style));
}

function cambiarPosicionToastPreview(pos) {
    document.documentElement.setAttribute('data-toast-pos', pos);
    window.showToast('Ubicación del Toast cambiada. Pulsa Guardar para conservarla.', 'info', 'Posición Actualizada');
}

function probarToastEnVivo() {
    const style = document.querySelector('input[name="ui_toast_estilo"]:checked')?.value || 'glass';
    const pos   = document.getElementById('sel_toast_pos')?.value || 'top-right';
    document.documentElement.setAttribute('data-toast-style', style);
    document.documentElement.setAttribute('data-toast-pos', pos);
    window.showToast('Comprobante F001-000145 validado y aceptado por SUNAT con CDR.', 'success', 'Prueba de Toast en Vivo');
}

async function seleccionarEstiloModalPreview(style) {
    document.documentElement.setAttribute('data-modal-style', style);
    await probarModalEnVivo(style);
}

async function probarModalEnVivo(styleOverride) {
    const style = styleOverride || document.querySelector('input[name="ui_modal_estilo"]:checked')?.value || 'modern';
    document.documentElement.setAttribute('data-modal-style', style);
    const nombres = {
        modern: 'Centrado con Halo',
        glass: 'Cristal Esmerilado',
        executive: 'Banner Ejecutivo',
        sheet: 'Sheet Flotante'
    };
    const confirmed = await window.showConfirm({
        title: 'Modal: ' + (nombres[style] || style),
        message: 'Así se verá el diálogo de confirmación en todo el sistema cuando realices acciones importantes.',
        confirmText: '¡Excelente diseño!',
        cancelText: 'Cerrar prueba',
        variant: 'primary',
        style: style
    });
    if (confirmed) {
        window.showToast('Estilo de modal "' + (nombres[style] || style) + '" seleccionado.', 'success', 'Diseño Confirmado');
    }
}

function toggleProveedor() {
    const prov = document.querySelector('input[name="facturacion_proveedor"]:checked')?.value || 'greenter';
    const secGreenter = document.getElementById('sec-greenter');
    const secFacturalo = document.getElementById('sec-facturalo');
    if (secGreenter) secGreenter.classList.toggle('hidden', prov !== 'greenter');
    if (secFacturalo) secFacturalo.classList.toggle('hidden', prov === 'greenter');
}

function togglePasswordVisibility(inputId, btn) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    const isPassword = inp.type === 'password';
    inp.type = isPassword ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    }
}

function detectarModoFacturalo(val) {
    const badge = document.getElementById('badge_facturalo_mode');
    if (!badge) return;
    val = (val || '').trim();
    if (val.startsWith('sk_test_')) {
        badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-800 dark:text-amber-300';
        badge.textContent = '🧪 MODO DEMO / PRUEBAS';
    } else if (val.startsWith('sk_live_')) {
        badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-500/20 text-emerald-800 dark:text-emerald-300';
        badge.textContent = '🚀 MODO PRODUCCIÓN';
    } else if (val.length > 0) {
        badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-100 dark:bg-sky-500/20 text-sky-800 dark:text-sky-300';
        badge.textContent = '🔒 TOKEN CONFIGURADO';
    } else {
        badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400';
        badge.textContent = '⚪ TOKEN NO CONFIGURADO';
    }
}

async function testConexionFacturalo() {
    const tokenInput = document.getElementById('inp_facturalo_token');
    const token = tokenInput ? tokenInput.value.trim() : '';
    const btn = document.getElementById('btn_test_facturalo');
    const res = document.getElementById('test_facturalo_result');
    if (!btn || !res) return;

    btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Probando Facturalo API v3...';
    res.className = 'mt-3 text-sm';
    res.textContent = '';

    try {
        const r = await fetch('api/test_facturalo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: token })
        });
        const data = await r.json();

        if (data.success) {
            res.className = 'mt-3 text-xs p-3.5 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 rounded-xl flex items-start gap-2.5';
            res.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-600 mt-0.5 shrink-0"></i><div><strong>Conexión Exitosa (' + (data.modo || 'Facturalo v3') + '):</strong> ' + data.message + '</div>';
            window.showToast(data.message, 'success', 'Facturalo API v3');
        } else {
            res.className = 'mt-3 text-xs p-3.5 bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-500/30 rounded-xl flex items-start gap-2.5';
            res.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-rose-600 mt-0.5 shrink-0"></i><div><strong>Aviso Facturalo:</strong> ' + data.message + '</div>';
            window.showToast(data.message, 'warning', 'Aviso Facturalo');
        }
    } catch (e) {
        res.className = 'mt-3 text-xs p-3.5 bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-500/30 rounded-xl flex items-start gap-2.5';
        res.innerHTML = '<i class="fa-solid fa-circle-xmark text-rose-600 mt-0.5 shrink-0"></i><div><strong>Error de Conexión:</strong> ' + e.message + '</div>';
        window.showToast(e.message, 'error', 'Error de Red');
    }
    res.classList.remove('hidden');
    btn.disabled = false;
    btn.innerHTML = oldHtml;
}

async function consultarRucEmpresa() {
    const ruc = document.getElementById('inp_empresa_ruc').value.trim();
    if (ruc.length !== 11) {
        window.showToast('Ingresa un número de RUC válido de 11 dígitos.', 'warning', 'RUC Inválido');
        return;
    }
    const btn = document.getElementById('btn_buscar_ruc_emp');
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    try {
        const r = await fetch('api/consultar_documento.php?tipo=RUC&numero=' + encodeURIComponent(ruc));
        const d = await r.json();
        if (d.success) {
            if (d.razon_social) document.getElementById('inp_empresa_razon').value = d.razon_social;
            if (d.direccion) document.getElementById('inp_empresa_dir').value = d.direccion;
            if (d.ubigeo) document.getElementById('inp_empresa_ubigeo').value = d.ubigeo;
            if (d.departamento) document.getElementById('inp_empresa_dep').value = d.departamento;
            if (d.provincia) document.getElementById('inp_empresa_prov').value = d.provincia;
            if (d.distrito) document.getElementById('inp_empresa_dist').value = d.distrito;
            window.showToast('Datos del RUC ' + ruc + ' sincronizados correctamente.', 'success', 'Consulta SUNAT');
        } else {
            window.showToast(d.message || 'No se encontraron datos para el RUC indicado.', 'warning', 'Sin Resultados');
        }
    } catch (e) {
        window.showToast('Error al consultar RUC: ' + e.message, 'error', 'Error de Conexión');
    }
    btn.disabled = false;
    btn.innerHTML = oldHtml;
}

async function testConexionSunat() {
    const btn = document.getElementById('btn_test_sunat');
    const res = document.getElementById('test_sunat_result');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Conectando con SUNAT...';
    res.className = 'mt-3 text-sm';
    res.textContent = '';

    try {
        const r = await fetch('api/test_sunat_conexion.php');
        const data = await r.json();

        if (data.success) {
            res.className = 'mt-3 text-xs p-3.5 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 rounded-xl flex items-start gap-2.5';
            res.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-600 mt-0.5 shrink-0"></i><div><strong>Conexión SUNAT Exitosa (' + data.modo + '):</strong> ' + data.message + '</div>';
            window.showToast(data.message, 'success', 'SUNAT ' + data.modo);
        } else {
            res.className = 'mt-3 text-xs p-3.5 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30 rounded-xl flex items-start gap-2.5';
            res.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5 shrink-0"></i><div><strong>Aviso SUNAT:</strong> ' + data.message + '</div>';
            window.showToast(data.message, 'warning', 'Aviso SUNAT');
        }
    } catch (e) {
        res.className = 'mt-3 text-xs p-3.5 bg-rose-50 dark:bg-rose-500/10 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-500/30 rounded-xl flex items-start gap-2.5';
        res.innerHTML = '<i class="fa-solid fa-circle-xmark text-rose-600 mt-0.5 shrink-0"></i><div><strong>Error de Conexión:</strong> ' + e.message + '</div>';
        window.showToast(e.message, 'error', 'Error de Red');
    }
    res.classList.remove('hidden');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-satellite-dish mr-2"></i> Probar Conexión Directa con SUNAT';
}

function limpiarCachePwa() {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CACHE' });
    }
    if ('caches' in window) {
        caches.keys().then(names => Promise.all(names.map(n => caches.delete(n)))).then(() => {
            window.showToast('Caché de la aplicación PWA actualizada correctamente.', 'success', 'Caché Limpiada');
        });
    }
}

// Resaltar botón de tema activo en el Hero Banner
function updateHeroThemePills() {
    const stored = localStorage.getItem('color-scheme');
    const active = stored || 'system';
    const map = { light: 'heroThemeLight', dark: 'heroThemeDark', system: 'heroThemeSystem' };
    Object.entries(map).forEach(([k, id]) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (k === active || (k === 'system' && stored === 'light dark')) {
            el.classList.add('bg-white/20', 'text-white', 'shadow-xs');
        } else {
            el.classList.remove('bg-white/20', 'text-white', 'shadow-xs');
        }
    });
}
window.addEventListener('app-theme-changed', updateHeroThemePills);
document.addEventListener('DOMContentLoaded', updateHeroThemePills);

// ============================================================
// FUNCIONES DEL CENTRO DE ACTUALIZACIONES & MIGRACIONES
// ============================================================
function appendConsoleLog(msg, type = 'info') {
    const box = document.getElementById('updateConsoleLogs');
    if (!box) return;
    const p = document.createElement('p');
    const time = new Date().toLocaleTimeString();
    if (type === 'success') {
        p.className = 'text-emerald-400 font-semibold';
        p.textContent = `[${time}] ${msg}`;
    } else if (type === 'error') {
        p.className = 'text-rose-400 font-semibold';
        p.textContent = `[${time}] ❌ ${msg}`;
    } else if (type === 'warn') {
        p.className = 'text-amber-400 font-semibold';
        p.textContent = `[${time}] ⚠️ ${msg}`;
    } else {
        p.className = 'text-slate-300';
        p.textContent = `[${time}] ${msg}`;
    }
    box.appendChild(p);
    box.scrollTop = box.scrollHeight;
}

function limpiarConsolaLogs() {
    const box = document.getElementById('updateConsoleLogs');
    if (box) box.innerHTML = '<p class="text-slate-500">[INFO] Consola limpia. Listo para nuevas acciones.</p>';
}

async function requestApiJson(url, options = {}) {
    const opts = Object.assign({}, options);
    opts.headers = Object.assign({
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    }, opts.headers || {});

    const res = await fetch(url, opts);
    const contentType = res.headers.get('content-type') || '';

    if (res.status === 401) {
        throw new Error('Sesión expirada o no autorizada. Por favor recargue la página o inicie sesión nuevamente.');
    }

    if (!contentType.includes('application/json')) {
        const text = await res.text();
        if (text.includes('<!DOCTYPE') || text.includes('<html')) {
            throw new Error('La sesión de usuario ha caducado o el endpoint no respondió en formato JSON. Recargue la página.');
        }
        throw new Error('Respuesta no válida del servidor.');
    }

    const data = await res.json();
    if (data.session_expired) {
        throw new Error(data.message || 'Sesión expirada.');
    }
    return data;
}

async function cargarEstadoActualizaciones() {
    try {
        const data = await requestApiJson('api/actualizar.php?action=status');
        if (data.success) {
            const urlDisp = document.getElementById('repo_url_display');
            if (urlDisp) urlDisp.textContent = data.remote_url || 'https://github.com/networkturbo4-pixel/facturaci-n.git';
            
            const branchDisp = document.getElementById('repo_branch_display');
            if (branchDisp) branchDisp.textContent = data.current_branch || 'main';

            const commitDisp = document.getElementById('repo_commit_display');
            if (commitDisp) {
                commitDisp.textContent = (data.current_commit && data.current_commit.indexOf('fatal') === -1) 
                    ? ('#' + data.current_commit) 
                    : 'v2.6';
            }

            const dbText = document.getElementById('db_status_text');
            const dbDot = document.getElementById('db_status_dot');
            if (dbText && dbDot && data.migrations) {
                if (data.migrations.pending_count > 0) {
                    dbText.textContent = `${data.migrations.pending_count} migración(es) pendiente(s)`;
                    dbText.className = 'font-semibold text-amber-500';
                    dbDot.className = 'w-2 h-2 rounded-full bg-amber-500 animate-pulse';
                } else {
                    dbText.textContent = `Al día (${data.migrations.applied_count || 0} migraciones OK)`;
                    dbText.className = 'font-semibold text-emerald-500';
                    dbDot.className = 'w-2 h-2 rounded-full bg-emerald-500';
                }
            }
        }
    } catch (e) {
        console.warn('No se pudo cargar estado de actualizaciones:', e.message);
    }

    // Diagnóstico de Salud de Producción
    try {
        const dh = await requestApiJson('api/health.php');
        const prodHealth = document.getElementById('prod_health_badge');
        const prodHealthDot = document.getElementById('prod_health_dot');
        if (prodHealth && prodHealthDot) {
            if (dh.production_ready || dh.status === 'healthy') {
                prodHealth.textContent = 'Saludable • Listo';
                prodHealth.className = 'font-semibold text-emerald-600 dark:text-emerald-400';
                prodHealthDot.className = 'w-2 h-2 rounded-full bg-emerald-500';
            } else {
                prodHealth.textContent = 'Revisar Permisos / BD';
                prodHealth.className = 'font-semibold text-amber-500';
                prodHealthDot.className = 'w-2 h-2 rounded-full bg-amber-500';
            }
        }
    } catch (e) {
        console.warn('Diagnóstico de salud:', e.message);
    }
}

async function comprobarActualizaciones() {
    const btn = document.getElementById('btnCheckUpdate');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-brand-500"></i><span>Comprobando...</span>';

    appendConsoleLog('Iniciando comprobación con GitHub (git fetch)...', 'info');

    try {
        const data = await requestApiJson('api/actualizar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=check'
        });

        if (data.success) {
            if (data.has_updates) {
                appendConsoleLog(`¡Nuevas actualizaciones encontradas en GitHub! Commit remoto: ${data.remote_commit}`, 'warn');
                if (data.commits_behind && data.commits_behind.length > 0) {
                    data.commits_behind.forEach(c => appendConsoleLog(` -> ${c}`, 'info'));
                }
                appendConsoleLog('Pulsa "2. Actualizar Sistema Ahora" para descargar las mejoras con seguridad.', 'info');
                window.showToast('Hay una nueva versión disponible para descargar.', 'info', 'Actualización Disponible');
            } else {
                const commitLabel = data.local_commit ? ` #${data.local_commit}` : '';
                appendConsoleLog(`✓ Tu sistema local ya cuenta con la versión más reciente${commitLabel} de GitHub. No se requieren descargas.`, 'success');
                appendConsoleLog(`💡 Los cambios ya están en tus archivos locales. Si notas textos antiguos en el menú, presiona Ctrl+F5 o usa "Limpiar Caché PWA".`, 'info');
                window.showToast('El sistema ya cuenta con la versión más reciente.', 'success', 'Sistema al Día');
            }
        } else {
            appendConsoleLog(data.message || 'No se pudo conectar con el repositorio remoto.', 'warn');
            window.showToast(data.message, 'warning', 'Aviso');
        }
    } catch (e) {
        appendConsoleLog('❌ ' + e.message, 'error');
        window.showToast(e.message, 'error', 'Actualizaciones');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        cargarEstadoActualizaciones();
    }
}

async function ejecutarActualizacionSistema() {
    const ok = await window.showConfirm({
        title: 'Actualizar Sistema desde Repositorio',
        message: 'Se descargará la última versión de FACTUSYS y se aplicarán automáticamente las migraciones de base de datos pendientes. Tu configuración y registros existentes están 100% protegidos. ¿Deseas continuar?',
        confirmText: 'Sí, Actualizar Ahora',
        variant: 'primary'
    });
    if (!ok) return;

    const btn = document.getElementById('btnApplyUpdate');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Actualizando...</span>';

    appendConsoleLog('=== INICIANDO PROCESO DE ACTUALIZACIÓN DEL SISTEMA ===', 'info');

    try {
        const data = await requestApiJson('api/actualizar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=update'
        });

        if (data.logs && Array.isArray(data.logs)) {
            data.logs.forEach(l => appendConsoleLog(l, data.success ? 'success' : 'info'));
        }

        if (data.success) {
            appendConsoleLog('✓ ¡Actualización finalizada con éxito!', 'success');
            window.showToast('Sistema actualizado con éxito sin alterar datos existentes.', 'success', 'Actualización Completada');
        } else {
            appendConsoleLog('Error: ' + (data.message || 'Fallo durante la actualización.'), 'error');
            window.showToast(data.message, 'error', 'Error de Actualización');
        }
    } catch (e) {
        appendConsoleLog('❌ Error: ' + e.message, 'error');
        window.showToast(e.message, 'error', 'Error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        cargarEstadoActualizaciones();
    }
}

async function ejecutarMigracionesBD() {
    const btn = document.getElementById('btnMigrateOnly');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Migrando...</span>';

    appendConsoleLog('Verificando archivos de migración SQL...', 'info');

    try {
        const data = await requestApiJson('api/actualizar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=migrate'
        });

        if (data.success) {
            if (data.count > 0) {
                appendConsoleLog(`✓ Se ejecutaron ${data.count} migraciones: ${data.applied.join(', ')}`, 'success');
                window.showToast(data.message, 'success', 'Migraciones Aplicadas');
            } else {
                appendConsoleLog('✓ Todas las migraciones ya estaban ejecutadas. Base de datos al día.', 'success');
                window.showToast(data.message, 'info', 'Base de Datos');
            }
        } else {
            appendConsoleLog('❌ Error en migraciones: ' + (data.error || 'Desconocido'), 'error');
            window.showToast(data.error, 'error', 'Error BD');
        }
    } catch (e) {
        appendConsoleLog('❌ Error: ' + e.message, 'error');
        window.showToast(e.message, 'error', 'Error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        cargarEstadoActualizaciones();
    }
}

async function subirPaqueteZipActualizacion(input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    if (!file.name.toLowerCase().endsWith('.zip')) {
        window.showToast('El archivo debe tener formato .zip', 'warning', 'Archivo Inválido');
        return;
    }

    const ok = await window.showConfirm({
        title: 'Instalar Actualización desde Paquete ZIP',
        message: `¿Deseas instalar el paquete "${file.name}"? Los archivos del sistema serán actualizados de forma segura sin alterar tus datos ni credenciales.`,
        confirmText: 'Sí, Instalar Paquete',
        variant: 'primary'
    });
    if (!ok) {
        input.value = '';
        return;
    }

    const btn = document.getElementById('btnUploadZip');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Instalando...</span>';
    }

    appendConsoleLog(`Subiendo y aplicando paquete ZIP: ${file.name} (${(file.size / (1024*1024)).toFixed(2)} MB)...`, 'info');

    const formData = new FormData();
    formData.append('action', 'upload_zip');
    formData.append('zip_file', file);

    try {
        const resp = await fetch('api/actualizar.php', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (data.success) {
            appendConsoleLog(`✓ ${data.message}`, 'success');
            window.showToast(data.message, 'success', 'Actualización Exitosa');
            setTimeout(() => window.location.reload(), 2500);
        } else {
            appendConsoleLog(`❌ Error: ${data.message}`, 'error');
            window.showToast(data.message, 'error', 'Error');
        }
    } catch (e) {
        appendConsoleLog(`❌ Error al subir ZIP: ${e.message}`, 'error');
        window.showToast(e.message, 'error', 'Error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        input.value = '';
        cargarEstadoActualizaciones();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    cargarEstadoActualizaciones();
});
</script>

<?php include 'includes/footer.php'; ?>
