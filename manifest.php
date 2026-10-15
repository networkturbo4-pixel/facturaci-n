<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$brand = getBrandingConfig();

$icon192 = $brand['sistema_logo_pwa_192'] ?: 'uploads/branding/default-pwa-192.png';
$icon512 = $brand['sistema_logo_pwa'] ?: 'uploads/branding/default-pwa-512.png';

function detectIconMime(string $path): string {
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    return match ($ext) {
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        'ico'  => 'image/x-icon',
        default => 'image/png'
    };
}

$manifest = [
    'id'               => './index.php',
    'name'             => $brand['pwa_nombre'],
    'short_name'       => $brand['pwa_nombre_corto'],
    'description'      => $brand['pwa_descripcion'],
    'start_url'        => './index.php?source=pwa',
    'scope'            => './',
    'display'          => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'orientation'      => 'any',
    'background_color' => $brand['pwa_bg_color'],
    'theme_color'      => $brand['pwa_theme_color'],
    'lang'             => 'es-PE',
    'dir'              => 'ltr',
    'categories'       => ['business', 'finance', 'productivity'],
    'icons'            => [
        [
            'src'     => $icon192,
            'sizes'   => '192x192',
            'type'    => detectIconMime($icon192),
            'purpose' => 'any'
        ],
        [
            'src'     => $icon192,
            'sizes'   => '192x192',
            'type'    => detectIconMime($icon192),
            'purpose' => 'maskable'
        ],
        [
            'src'     => $icon512,
            'sizes'   => '512x512',
            'type'    => detectIconMime($icon512),
            'purpose' => 'any'
        ],
        [
            'src'     => $icon512,
            'sizes'   => '512x512',
            'type'    => detectIconMime($icon512),
            'purpose' => 'maskable'
        ]
    ],
    'shortcuts' => [
        [
            'name'        => 'Emitir Factura / Boleta',
            'short_name'  => 'Nueva Factura',
            'description' => 'Crear nuevo comprobante electrónico SUNAT',
            'url'         => './factura_nueva.php?source=pwa',
            'icons'       => [['src' => $icon192, 'sizes' => '192x192', 'type' => detectIconMime($icon192)]]
        ],
        [
            'name'        => 'Nueva Exportación',
            'short_name'  => 'Exportación',
            'description' => 'Emitir Factura de Exportación E001',
            'url'         => './exportacion_nueva.php?source=pwa',
            'icons'       => [['src' => $icon192, 'sizes' => '192x192', 'type' => detectIconMime($icon192)]]
        ],
        [
            'name'        => 'Listado de Comprobantes',
            'short_name'  => 'Comprobantes',
            'description' => 'Consultar CPEs emitidos y estado CDR',
            'url'         => './comprobantes.php?source=pwa',
            'icons'       => [['src' => $icon192, 'sizes' => '192x192', 'type' => detectIconMime($icon192)]]
        ],
        [
            'name'        => 'Configuración del Sistema',
            'short_name'  => 'Configuración',
            'description' => 'Administrar empresa, logos, SUNAT y PWA',
            'url'         => './configuracion.php?source=pwa',
            'icons'       => [['src' => $icon192, 'sizes' => '192x192', 'type' => detectIconMime($icon192)]]
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
