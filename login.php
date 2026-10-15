<?php
require_once __DIR__ . '/includes/auth.php';

// Si ya tiene sesión activa, redirigir al dashboard
if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$brand = getBrandingConfig();
$logoClaro  = $brand['logo_claro_efectivo'];
$logoOscuro = $brand['logo_oscuro_efectivo'];

$error = '';
$emailInput = '';
$redirect = $_GET['redirect'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $emailInput = $email;

    if (empty($email) || empty($password)) {
        $error = 'Por favor ingresa tu correo y contraseña.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT * FROM usuarios WHERE email = ? AND estado = 'Activo' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'email' => $user['email'],
                    'rol' => $user['rol'],
                    'avatar' => $user['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($user['nombre']) . '&background=3b82f6&color=fff'
                ];

                $update = $conn->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
                $update->execute([$user['id']]);

                $target = filter_var($redirect, FILTER_SANITIZE_URL);
                if (empty($target) || strpos($target, ':') !== false || strpos($target, '//') !== false) {
                    $target = 'index.php';
                }
                header("Location: " . $target);
                exit;
            } else {
                $error = 'Credenciales incorrectas. Verifica tu correo y contraseña.';
            }
        } catch (PDOException $e) {
            $error = 'Error de conexión con la base de datos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full" data-default-theme="<?php echo htmlspecialchars($brand['sistema_tema_default']); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <script>
        (function() {
            try {
                var defaultTheme = document.documentElement.getAttribute('data-default-theme') || 'dark';
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
    <title>Iniciar Sesión • <?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?></title>
<?php echo renderHeadBranding($brand); ?>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '<?php echo htmlspecialchars($brand['sistema_color_primario']); ?>',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="css/style.css?v=2.6">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .bg-grid-pattern {
            background-size: 32px 32px;
            background-image: linear-gradient(to right, rgba(148, 163, 184, 0.08) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
        }
    </style>
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 flex items-center justify-center p-4 relative overflow-x-hidden selection:bg-brand-500 selection:text-white transition-colors duration-200">

    <!-- Top Right Theme & PWA Controls -->
    <div class="fixed top-4 right-4 z-50 flex items-center gap-2">
        <button type="button" id="pwaInstallBtn" class="hidden items-center gap-1.5 px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold shadow-lg">
            <i class="fa-solid fa-mobile-screen-button"></i>
            <span>Instalar App</span>
        </button>
        <button type="button" onclick="window.toggleAppTheme && window.toggleAppTheme()" class="p-2.5 rounded-xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:text-brand-600 shadow-sm transition-all" title="Alternar Modo Claro / Oscuro">
            <i class="fa-solid fa-moon text-sm brand-logo-light"></i>
            <i class="fa-solid fa-sun text-sm text-amber-400 brand-logo-dark"></i>
        </button>
    </div>

    <!-- Ambient Glowing Blobs -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden">
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-brand-600/15 dark:bg-brand-600/20 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-blue-600/15 dark:bg-blue-600/20 rounded-full blur-3xl"></div>
        <div class="absolute inset-0 bg-grid-pattern opacity-60"></div>
    </div>

    <!-- Login Container -->
    <div class="relative w-full max-w-md my-auto z-10">
        
        <!-- Brand Header (Adaptive Light / Dark Logo) -->
        <div class="text-center mb-8">
            <div class="flex justify-center mb-4">
                <!-- Light Mode Logo -->
                <span class="brand-logo-light justify-center">
                    <?php if (!empty($logoClaro)): ?>
                        <img src="<?php echo htmlspecialchars($logoClaro); ?>" alt="<?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?>" class="max-h-16 max-w-[220px] object-contain drop-shadow-sm">
                    <?php else: ?>
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-600 via-indigo-500 to-blue-400 p-0.5 shadow-xl shadow-brand-500/20">
                            <div class="w-full h-full bg-white rounded-[14px] flex items-center justify-center">
                                <i class="fa-solid fa-receipt text-2xl text-brand-600"></i>
                            </div>
                        </div>
                    <?php endif; ?>
                </span>

                <!-- Dark Mode Logo -->
                <span class="brand-logo-dark justify-center">
                    <?php if (!empty($logoOscuro)): ?>
                        <img src="<?php echo htmlspecialchars($logoOscuro); ?>" alt="<?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?>" class="max-h-16 max-w-[220px] object-contain drop-shadow-md">
                    <?php else: ?>
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-600 via-indigo-500 to-blue-400 p-0.5 shadow-xl shadow-brand-500/20">
                            <div class="w-full h-full bg-slate-950/80 rounded-[14px] flex items-center justify-center backdrop-blur-sm">
                                <i class="fa-solid fa-receipt text-2xl text-transparent bg-clip-text bg-gradient-to-r from-brand-400 to-blue-300"></i>
                            </div>
                        </div>
                    <?php endif; ?>
                </span>
            </div>

            <div class="flex items-center justify-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white"><?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?></h1>
                <span class="text-[11px] font-semibold tracking-wider uppercase px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-300 border border-brand-500/20">v2.6</span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5"><?php echo htmlspecialchars($brand['empresa_razon_social']); ?> • Facturación SUNAT & CRM</p>
        </div>

        <!-- Card -->
        <div class="bg-white/90 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/80 dark:border-slate-800 rounded-2xl shadow-2xl p-7 sm:p-8">
            <div class="mb-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Acceso al Sistema</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Ingresa tus credenciales para continuar a tu panel</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-300 text-xs flex items-center gap-3">
                    <i class="fa-solid fa-circle-exclamation text-base text-red-500 shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logged_out'])): ?>
                <div class="mb-5 p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-xs flex items-center gap-3">
                    <i class="fa-solid fa-circle-check text-base text-emerald-500 shrink-0"></i>
                    <span>Sesión cerrada correctamente. ¡Hasta pronto!</span>
                </div>
            <?php endif; ?>


            <!-- Form -->
            <form action="login.php?redirect=<?php echo urlencode($redirect); ?>" method="POST" autocomplete="on" class="space-y-4">
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Correo Electrónico</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                            <i class="fa-regular fa-envelope text-sm"></i>
                        </span>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            autocomplete="username" 
                            required 
                            autofocus
                            value="<?php echo htmlspecialchars($emailInput); ?>"
                            placeholder="ejemplo@empresa.com"
                            class="w-full bg-slate-50 dark:bg-slate-900/80 border border-slate-300 dark:border-slate-700/80 rounded-xl py-2.5 pl-10 pr-3.5 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500 transition-all"
                        >
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block text-xs font-semibold text-slate-700 dark:text-slate-300">Contraseña</label>
                        <a href="javascript:void(0)" onclick="alert('Contacta al Administrador del sistema para restablecer tu clave o utiliza las credenciales demo superiores.')" class="text-[11px] text-brand-600 dark:text-brand-400 hover:underline">¿Olvidaste tu contraseña?</a>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                            <i class="fa-solid fa-lock text-sm"></i>
                        </span>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            autocomplete="current-password" 
                            required 
                            placeholder="••••••••••••"
                            class="w-full bg-slate-50 dark:bg-slate-900/80 border border-slate-300 dark:border-slate-700/80 rounded-xl py-2.5 pl-10 pr-10 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500 transition-all"
                        >
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors" title="Mostrar/Ocultar contraseña">
                            <i class="fa-regular fa-eye text-sm" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button 
                        type="submit" 
                        id="submitBtn"
                        class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-semibold text-sm shadow-lg shadow-brand-600/30 active:scale-[0.99] transition-all flex items-center justify-center gap-2 group"
                    >
                        <span>Ingresar al Sistema</span>
                        <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Trust Badges -->
        <div class="mt-8 text-center text-xs text-slate-500 space-y-2">
            <div class="flex items-center justify-center gap-4 text-[11px]">
                <span class="flex items-center gap-1.5"><i class="fa-solid fa-shield-halved text-emerald-500"></i> Cifrado SSL 256-Bit</span>
                <span>•</span>
                <span class="flex items-center gap-1.5"><i class="fa-solid fa-satellite-dish text-blue-500"></i> SUNAT UBL 2.1</span>
            </div>
            <p class="text-[11px] text-slate-400">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?>. Todos los derechos reservados.</p>
        </div>

    </div>

    <script src="js/main.js?v=2.6"></script>
    <script>
        const toggleBtn = document.getElementById('togglePassword');
        const passInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        if (toggleBtn && passInput) {
            toggleBtn.addEventListener('click', () => {
                const isPassword = passInput.getAttribute('type') === 'password';
                passInput.setAttribute('type', isPassword ? 'text' : 'password');
                eyeIcon.className = isPassword ? 'fa-regular fa-eye-slash text-sm' : 'fa-regular fa-eye text-sm';
            });
        }
    </script>
</body>
</html>
