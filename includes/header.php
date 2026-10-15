<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$currentUser = currentUser();
$brandConfig = getBrandingConfig();
$appTitle = isset($pageTitle)
    ? ($pageTitle . ' • ' . $brandConfig['empresa_nombre_comercial'])
    : ($brandConfig['empresa_nombre_comercial'] . ' • Facturación & CRM');
?>
<!DOCTYPE html>
<html lang="es" class="h-full" data-default-theme="<?php echo htmlspecialchars($brandConfig['sistema_tema_default']); ?>" data-toast-style="<?php echo htmlspecialchars($brandConfig['ui_toast_estilo']); ?>" data-toast-pos="<?php echo htmlspecialchars($brandConfig['ui_toast_posicion']); ?>" data-modal-style="<?php echo htmlspecialchars($brandConfig['ui_modal_estilo']); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <script>
        (function() {
            try {
                var defaultTheme = document.documentElement.getAttribute('data-default-theme') || 'light';
                var stored = localStorage.getItem('color-scheme');
                var scheme = stored || (defaultTheme === 'system' ? 'light dark' : defaultTheme);
                var meta = document.querySelector('meta[name="color-scheme"]');
                if (meta) meta.content = scheme;
                var isDark = scheme === 'dark' || (scheme === 'light dark' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', isDark);
                document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <title><?php echo htmlspecialchars($appTitle); ?></title>
<?php echo renderHeadBranding($brandConfig); ?>
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            300: '#a5b4fc',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '<?php echo htmlspecialchars($brandConfig['sistema_color_primario']); ?>',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        },
                        primary: '<?php echo htmlspecialchars($brandConfig['sistema_color_primario']); ?>',
                        secondary: '#3b82f6',
                        dark: '#0b0f19',
                        darker: '#070a12',
                        light: '#f8fafc'
                    },
                    boxShadow: {
                        'xs': '0 1px 2px 0 rgba(0, 0, 0, 0.03)',
                        'app': '0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.03)',
                        'glow': '0 0 20px -3px rgba(99, 102, 241, 0.25)',
                    }
                }
            }
        }
    </script>
    <!-- Custom Styles -->
    <link rel="stylesheet" href="css/style.css?v=3.1">
    <!-- FontAwesome 6 for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-slate-100/70 dark:bg-slate-950 font-sans text-slate-800 dark:text-slate-100 antialiased h-screen flex overflow-hidden selection:bg-brand-600 selection:text-white transition-colors duration-200">
