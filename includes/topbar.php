<?php
$user = $currentUser ?? [
    'nombre' => 'Admin User',
    'email' => 'admin@empresa.com',
    'rol' => 'Administrador',
    'avatar' => 'https://ui-avatars.com/api/?name=Admin+User&background=4f46e5&color=fff'
];
$title = $pageTitle ?? 'Dashboard';
$brand = $brandConfig ?? getBrandingConfig();
$isProd = ($brand['sunat_modo'] === 'produccion');
?>
<!-- Top Navigation Header (Redesigned Mobile-First & Spacious) -->
<header class="h-16 bg-white/90 dark:bg-slate-900/90 backdrop-blur-xl border-b border-slate-200/80 dark:border-slate-800/80 flex items-center justify-between px-3.5 sm:px-6 lg:px-8 z-30 shrink-0 sticky top-0 transition-colors duration-200">
    
    <!-- Left: Mobile Menu Button & Clean Single-Line Title -->
    <div class="flex items-center gap-2.5 sm:gap-4 min-w-0 flex-1 mr-2">
        <button id="mobileMenuBtn" class="md:hidden w-9 h-9 rounded-xl bg-slate-100/80 dark:bg-slate-800/90 text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white flex items-center justify-center transition-colors focus:outline-none shrink-0" aria-label="Abrir menú">
            <i class="fa-solid fa-bars-staggered text-sm"></i>
        </button>

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 min-w-0">
                <h1 class="text-sm sm:text-lg font-extrabold text-slate-900 dark:text-white tracking-tight truncate" title="<?php echo htmlspecialchars($title); ?>">
                    <?php echo htmlspecialchars($title); ?>
                </h1>
                <!-- Mobile compact status dot -->
                <span class="sm:hidden w-2 h-2 rounded-full <?php echo $isProd ? 'bg-blue-500' : 'bg-emerald-500'; ?> shrink-0" title="SUNAT <?php echo $isProd ? 'Producción' : 'Beta Activo'; ?>"></span>
                <!-- Desktop full pill -->
                <span class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold <?php echo $isProd ? 'bg-blue-50 text-blue-700 border-blue-200/80 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/20' : 'bg-emerald-50 text-emerald-700 border-emerald-200/80 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20'; ?> border shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full <?php echo $isProd ? 'bg-blue-500' : 'bg-emerald-500'; ?> animate-pulse"></span>
                    SUNAT <?php echo $isProd ? 'Producción' : 'Beta Activo'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Center: Global Quick Search (Desktop Only) -->
    <div class="hidden lg:flex items-center flex-1 max-w-md mx-6">
        <div class="relative w-full">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                <i class="fa-solid fa-magnifying-glass text-xs"></i>
            </span>
            <input 
                type="text" 
                id="globalSearchInput"
                placeholder="Buscar módulo, cliente, comprobante... (Ctrl+K)" 
                class="w-full bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl py-1.5 pl-9 pr-12 text-xs text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:bg-white dark:focus:bg-slate-800 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition-all"
            >
            <div class="absolute inset-y-0 right-0 flex items-center pr-2.5 pointer-events-none">
                <kbd class="px-1.5 py-0.5 text-[10px] font-mono text-slate-400 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-md shadow-xs">⌘K</kbd>
            </div>
        </div>
    </div>

    <!-- Right: Uncluttered Responsive Actions -->
    <div class="flex items-center gap-1.5 sm:gap-2.5 shrink-0">

        <!-- Action Button (Hidden on mobile when default 'Nueva Factura' because mobile has the protruding Bottom FAB!) -->
        <?php if (isset($topbarActionHtml)): ?>
            <div class="hidden sm:block"><?php echo $topbarActionHtml; ?></div>
        <?php elseif (isset($topbarAction)): ?>
            <?php $isDefaultInvoiceAction = (($topbarAction['url'] ?? '') === 'factura_nueva.php'); ?>
            <?php if (!empty($topbarAction['onclick'])): ?>
                <button type="button" onclick="<?php echo htmlspecialchars($topbarAction['onclick']); ?>" class="w-9 h-9 sm:w-auto sm:h-auto sm:px-3.5 sm:py-2 inline-flex items-center justify-center gap-1.5 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-semibold rounded-xl shadow-sm shadow-brand-600/20 transition-all hover:scale-[1.02] active:scale-[0.98]" title="<?php echo htmlspecialchars($topbarAction['label']); ?>">
                    <i class="<?php echo htmlspecialchars($topbarAction['icon'] ?? 'fa-solid fa-plus text-xs'); ?>"></i>
                    <span class="hidden sm:inline"><?php echo htmlspecialchars($topbarAction['label']); ?></span>
                </button>
            <?php elseif (!empty($topbarAction['url'])): ?>
                <a href="<?php echo htmlspecialchars($topbarAction['url']); ?>" class="<?php echo $isDefaultInvoiceAction ? 'hidden md:inline-flex px-3.5 py-2' : 'w-9 h-9 sm:w-auto sm:h-auto sm:px-3.5 sm:py-2 inline-flex'; ?> items-center justify-center gap-1.5 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-semibold rounded-xl shadow-sm shadow-brand-600/20 transition-all hover:scale-[1.02] active:scale-[0.98]" title="<?php echo htmlspecialchars($topbarAction['label']); ?>">
                    <i class="<?php echo htmlspecialchars($topbarAction['icon'] ?? 'fa-solid fa-plus text-xs'); ?>"></i>
                    <span class="<?php echo $isDefaultInvoiceAction ? '' : 'hidden sm:inline'; ?>"><?php echo htmlspecialchars($topbarAction['label']); ?></span>
                </a>
            <?php endif; ?>
        <?php else: ?>
            <!-- Desktop Nueva Factura button (on mobile the protruding bottom navbar button handles this) -->
            <a href="factura_nueva.php" class="hidden md:inline-flex items-center gap-1.5 px-3.5 py-2 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white text-xs font-semibold rounded-xl shadow-sm shadow-brand-600/20 transition-all hover:scale-[1.02] active:scale-[0.98]">
                <i class="fa-solid fa-plus text-[11px]"></i>
                <span>Nueva Factura</span>
            </a>
        <?php endif; ?>

        <!-- Theme Toggle Button (Light / Dark Mode) -->
        <button type="button" id="themeToggleBtn" onclick="window.toggleAppTheme && window.toggleAppTheme()" class="w-9 h-9 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:text-brand-600 dark:hover:text-amber-300 bg-slate-100/70 dark:bg-slate-800/80 hover:bg-slate-200/70 dark:hover:bg-slate-700 rounded-xl transition-colors focus:outline-none" title="Alternar Modo Claro / Oscuro">
            <i class="fa-solid fa-moon text-xs sm:text-sm brand-logo-light"></i>
            <i class="fa-solid fa-sun text-xs sm:text-sm text-amber-400 brand-logo-dark"></i>
        </button>

        <!-- Notifications Dropdown -->
        <div class="relative" id="notificationsContainer">
            <button id="notificationsBtn" class="relative w-9 h-9 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:text-slate-800 dark:hover:text-white bg-slate-100/70 dark:bg-slate-800/80 hover:bg-slate-200/70 dark:hover:bg-slate-700 rounded-xl transition-colors focus:outline-none" title="Notificaciones">
                <i class="fa-regular fa-bell text-xs sm:text-sm"></i>
                <span class="absolute top-1.5 right-2 w-2 h-2 bg-brand-600 rounded-full border-2 border-white dark:border-slate-900"></span>
            </button>

            <!-- Notifications Popover -->
            <div id="notificationsMenu" class="hidden absolute right-0 mt-2 w-72 sm:w-80 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200/80 dark:border-slate-700 py-2 z-50 animate-fade-in">
                <div class="px-4 py-2 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-800 dark:text-white">Notificaciones del Sistema</span>
                    <span class="text-[10px] font-semibold text-brand-600 dark:text-brand-300 bg-brand-50 dark:bg-brand-500/15 px-2 py-0.5 rounded-full">Activas</span>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                    <div class="px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors cursor-pointer flex gap-3">
                        <div class="w-7 h-7 rounded-lg bg-emerald-100 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 text-xs">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-800 dark:text-slate-100">Motor SUNAT Operativo</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">Proveedor activo: <?php echo strtoupper($brand['facturacion_proveedor']); ?> (<?php echo ucfirst($brand['sunat_modo']); ?>).</p>
                        </div>
                    </div>
                    <div class="px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors cursor-pointer flex gap-3">
                        <div class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 text-xs">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-800 dark:text-slate-100">Personalización UI & Tema</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">Configura logos claro/oscuro, estilos de Toast y Modales en Ajustes.</p>
                        </div>
                    </div>
                </div>
                <div class="px-4 pt-2 pb-1 border-t border-slate-100 dark:border-slate-800 text-center">
                    <a href="comprobantes.php" class="text-[11px] font-semibold text-brand-600 dark:text-brand-400 hover:underline">Ver todos los comprobantes</a>
                </div>
            </div>
        </div>

        <div class="hidden sm:block h-6 w-[1px] bg-slate-200 dark:bg-slate-800"></div>

        <!-- User Profile Avatar & Dropdown -->
        <div class="relative" id="userMenuContainer">
            <button id="userMenuBtn" class="flex items-center gap-2 p-0.5 sm:p-1 sm:pl-1.5 sm:pr-2.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 border border-transparent sm:hover:border-slate-200 dark:sm:hover:border-slate-700 transition-all focus:outline-none">
                <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="User" class="w-8 h-8 sm:w-7 sm:h-7 rounded-xl sm:rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700">
                <span class="hidden sm:inline text-xs font-semibold text-slate-700 dark:text-slate-200 max-w-[100px] truncate"><?php echo htmlspecialchars($user['nombre']); ?></span>
                <i class="hidden sm:inline fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
            </button>

            <!-- User Menu Popover -->
            <div id="userMenu" class="hidden absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200/80 dark:border-slate-700 py-2 z-50 animate-fade-in">
                <div class="px-4 py-2.5 border-b border-slate-100 dark:border-slate-800">
                    <p class="text-xs font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($user['nombre']); ?></p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?php echo htmlspecialchars($user['email']); ?></p>
                    <span class="mt-1.5 inline-block text-[10px] font-semibold px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($user['rol']); ?></span>
                </div>
                <div class="py-1 text-xs">
                    <a href="perfil.php" class="flex items-center gap-2.5 px-4 py-2 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-brand-600 transition-colors">
                        <i class="fa-regular fa-user w-4 text-slate-400"></i>
                        <span>Mi Perfil</span>
                    </a>
                    <a href="configuracion.php" class="flex items-center gap-2.5 px-4 py-2 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-brand-600 transition-colors">
                        <i class="fa-solid fa-sliders w-4 text-slate-400"></i>
                        <span>Configuración & Logos</span>
                    </a>
                </div>
                <div class="pt-1 border-t border-slate-100 dark:border-slate-800">
                    <a href="logout.php" data-confirm="¿Deseas cerrar tu sesión actual en el sistema?" data-confirm-title="Cerrar Sesión" data-confirm-text="Sí, salir" data-confirm-variant="danger" class="flex items-center gap-2.5 px-4 py-2 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors">
                        <i class="fa-solid fa-arrow-right-from-bracket w-4"></i>
                        <span>Cerrar Sesión</span>
                    </a>
                </div>
            </div>
        </div>

    </div>
</header>
