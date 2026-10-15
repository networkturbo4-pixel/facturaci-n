<?php
// Configuración de Seguridad y Sesión en Producción
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}

// En producción, silenciar salida directa de errores y registrar en log seguro
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}

if (!APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

require_once __DIR__ . '/db.php';

/**
 * Verifica si hay un usuario autenticado
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Obtiene los datos del usuario actual
 */
function currentUser(): array {
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    
    return [
        'id' => 0,
        'nombre' => 'Invitado',
        'email' => 'invitado@empresa.com',
        'rol' => 'Invitado',
        'avatar' => 'https://ui-avatars.com/api/?name=Invitado&background=64748b&color=fff'
    ];
}

/**
 * Exige inicio de sesión para acceder a la vista o endpoint
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $isApi = (strpos($uri, '/api/') !== false)
            || (strpos($script, '/api/') !== false)
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'session_expired' => true,
                'message' => 'Sesión expirada o no autorizada. Por favor inicie sesión nuevamente.'
            ]);
            exit;
        }

        $loginPath = (strpos($script, '/api/') !== false) ? '../login.php' : 'login.php';
        header("Location: {$loginPath}?redirect=" . urlencode($uri ?: 'index.php'));
        exit;
    }
}

/**
 * Exige roles específicos (ej: 'Administrador' o ['Administrador', 'Ventas'])
 */
function requireRole($roles): void {
    requireLogin();
    $u = currentUser();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($u['rol'], $allowed)) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $isApi = (strpos($uri, '/api/') !== false)
            || (strpos($script, '/api/') !== false)
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Acceso denegado: No tienes permisos suficientes para realizar esta acción.'
            ]);
            exit;
        }

        http_response_code(403);
        echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Acceso Denegado</title><script src='https://cdn.tailwindcss.com'></script></head><body class='h-screen flex items-center justify-center bg-slate-900 text-white'><div class='text-center p-8 bg-slate-800 rounded-2xl border border-slate-700 max-w-md'><h1 class='text-2xl font-bold text-red-400 mb-2'>Acceso Restringido</h1><p class='text-slate-300 text-sm mb-6'>No tienes permisos de Administrador para acceder a esta sección.</p><a href='index.php' class='px-4 py-2 bg-indigo-600 rounded-xl text-white text-sm font-semibold'>Volver al Inicio</a></div></body></html>";
        exit;
    }
}

/**
 * Genera o devuelve el token CSRF activo de la sesión
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Retorna un campo input hidden con el token CSRF
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Valida el token CSRF en peticiones POST
 */
function validate_csrf(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Carga y almacena en caché de memoria la tabla configuracion
 */
function getAllSystemConfig(bool $forceRefresh = false): array {
    static $cache = null;
    if ($cache !== null && !$forceRefresh) {
        return $cache;
    }
    global $conn;
    $cache = [];
    try {
        $stmt = $conn->query("SELECT clave, valor FROM configuracion");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$row['clave']] = (string)($row['valor'] ?? '');
            }
        }
    } catch (Exception $e) {}
    return $cache;
}

/**
 * Obtiene un valor de configuración de la BD con valor por defecto
 */
function getSystemConfig(string $key, string $default = '', bool $forceRefresh = false): string {
    $all = getAllSystemConfig($forceRefresh);
    return (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : $default;
}

/**
 * Verifica que una ruta de imagen local exista en el proyecto
 */
function resolveAssetPath(string $relPath, string $fallback = ''): string {
    $relPath = trim($relPath);
    if ($relPath === '') {
        return $fallback;
    }
    if (preg_match('#^https?://#i', $relPath)) {
        return $relPath;
    }
    $absPath = dirname(__DIR__) . '/' . ltrim($relPath, '/\\');
    if (file_exists($absPath) && is_file($absPath)) {
        return $relPath;
    }
    return $fallback;
}

/**
 * Obtiene toda la configuración consolidada de Identidad Visual, Logos, Modo Claro/Oscuro y PWA
 */
function getBrandingConfig(bool $forceRefresh = false): array {
    $cfg = getAllSystemConfig($forceRefresh);

    $logoGeneral = resolveAssetPath($cfg['sistema_logo'] ?? ($cfg['empresa_logo_url'] ?? ''), '');
    $logoClaro   = resolveAssetPath($cfg['sistema_logo_claro'] ?? '', '');
    $logoOscuro  = resolveAssetPath($cfg['sistema_logo_oscuro'] ?? '', '');
    $favicon     = resolveAssetPath($cfg['sistema_favicon'] ?? '', 'uploads/branding/default-favicon.png');
    $logoPwa512  = resolveAssetPath($cfg['sistema_logo_pwa'] ?? '', 'uploads/branding/default-pwa-512.png');
    $logoPwa192  = resolveAssetPath($cfg['sistema_logo_pwa_192'] ?? '', $logoPwa512 ?: 'uploads/branding/default-pwa-192.png');

    $nombreComercial = !empty($cfg['empresa_nombre_comercial']) ? $cfg['empresa_nombre_comercial'] : 'FACTUSYS';
    $razonSocial     = !empty($cfg['empresa_razon_social']) ? $cfg['empresa_razon_social'] : 'MI EMPRESA S.A.C.';

    return [
        'empresa_ruc'              => $cfg['empresa_ruc'] ?? '20000000001',
        'empresa_razon_social'     => $razonSocial,
        'empresa_nombre_comercial' => $nombreComercial,
        'empresa_direccion'        => $cfg['empresa_direccion'] ?? 'Av. Principal 123, Lima',
        'empresa_telefono'         => $cfg['empresa_telefono'] ?? '',
        'empresa_correo'           => $cfg['empresa_correo'] ?? '',
        'empresa_web'              => $cfg['empresa_web'] ?? '',
        'sunat_modo'               => strtolower($cfg['sunat_modo'] ?? 'beta'),
        'facturacion_proveedor'    => strtolower($cfg['facturacion_proveedor'] ?? 'greenter'),
        'sistema_logo'             => $logoGeneral,
        'sistema_logo_claro'       => $logoClaro,
        'sistema_logo_oscuro'      => $logoOscuro,
        'logo_claro_efectivo'      => $logoClaro ?: $logoGeneral,
        'logo_oscuro_efectivo'     => $logoOscuro ?: $logoGeneral,
        'logo_documento_efectivo'  => $logoGeneral ?: ($logoClaro ?: $logoOscuro),
        'sistema_favicon'          => $favicon,
        'sistema_logo_pwa'         => $logoPwa512,
        'sistema_logo_pwa_192'     => $logoPwa192,
        'sistema_tema_default'     => in_array($cfg['sistema_tema_default'] ?? 'light', ['light', 'dark', 'system']) ? $cfg['sistema_tema_default'] : 'light',
        'sistema_color_primario'   => $cfg['sistema_color_primario'] ?? '#4f46e5',
        'pwa_habilitado'           => ($cfg['pwa_habilitado'] ?? '1') === '1',
        'pwa_nombre'               => !empty($cfg['pwa_nombre']) ? $cfg['pwa_nombre'] : ($nombreComercial . ' • Facturación & CRM'),
        'pwa_nombre_corto'         => !empty($cfg['pwa_nombre_corto']) ? $cfg['pwa_nombre_corto'] : $nombreComercial,
        'pwa_descripcion'          => !empty($cfg['pwa_descripcion']) ? $cfg['pwa_descripcion'] : 'Sistema de Facturación Electrónica SUNAT, Exportaciones y CRM',
        'pwa_theme_color'          => !empty($cfg['pwa_theme_color']) ? $cfg['pwa_theme_color'] : '#4f46e5',
        'pwa_bg_color'             => !empty($cfg['pwa_bg_color']) ? $cfg['pwa_bg_color'] : '#0b0f19',
        'serie_factura'            => !empty($cfg['serie_factura']) ? $cfg['serie_factura'] : 'F001',
        'serie_boleta'             => !empty($cfg['serie_boleta']) ? $cfg['serie_boleta'] : 'B001',
        'serie_exportacion'        => !empty($cfg['serie_exportacion']) ? $cfg['serie_exportacion'] : 'E001',
        'serie_guia'               => !empty($cfg['serie_guia']) ? $cfg['serie_guia'] : 'T001',
        'ui_toast_estilo'          => in_array($cfg['ui_toast_estilo'] ?? 'glass', ['minimal', 'glass', 'solid', 'capsule']) ? $cfg['ui_toast_estilo'] : 'glass',
        'ui_toast_posicion'        => in_array($cfg['ui_toast_posicion'] ?? 'top-right', ['top-right', 'top-center', 'bottom-right', 'bottom-center']) ? $cfg['ui_toast_posicion'] : 'top-right',
        'ui_modal_estilo'          => in_array($cfg['ui_modal_estilo'] ?? 'modern', ['modern', 'glass', 'executive', 'sheet']) ? $cfg['ui_modal_estilo'] : 'modern',
    ];
}

/**
 * Renderiza etiquetas <head> de Favicon, Apple Touch Icon y PWA Manifest
 */
function renderHeadBranding(array $brand = null): string {
    if ($brand === null) {
        $brand = getBrandingConfig();
    }
    $fav = htmlspecialchars($brand['sistema_favicon']);
    $pwaIcon = htmlspecialchars($brand['sistema_logo_pwa_192']);
    $themeColor = htmlspecialchars($brand['pwa_theme_color']);
    $shortName = htmlspecialchars($brand['pwa_nombre_corto']);
    $ext = strtolower(pathinfo(parse_url($brand['sistema_favicon'], PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'image/png'
    };

    $html  = "    <!-- Favicon & App Icons -->\n";
    $html .= "    <link rel=\"icon\" type=\"{$mime}\" href=\"{$fav}\">\n";
    $html .= "    <link rel=\"shortcut icon\" href=\"{$fav}\">\n";
    $html .= "    <link rel=\"apple-touch-icon\" href=\"{$pwaIcon}\">\n";
    if ($brand['pwa_habilitado']) {
        $html .= "    <!-- Progressive Web App (PWA) -->\n";
        $html .= "    <link rel=\"manifest\" href=\"manifest.php\">\n";
        $html .= "    <meta name=\"theme-color\" content=\"{$themeColor}\" id=\"meta-theme-color\">\n";
        $html .= "    <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
        $html .= "    <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">\n";
        $html .= "    <meta name=\"apple-mobile-web-app-title\" content=\"{$shortName}\">\n";
        $html .= "    <meta name=\"mobile-web-app-capable\" content=\"yes\">\n";
    }
    return $html;
}

/**
 * Cierra la sesión activa de forma segura
 */
function logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
?>
