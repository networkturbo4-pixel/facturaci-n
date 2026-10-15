<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$user = $currentUser ?? [
    'nombre' => 'Admin User',
    'email' => 'admin@empresa.com',
    'rol' => 'Administrador',
    'avatar' => 'https://ui-avatars.com/api/?name=Admin+User&background=4f46e5&color=fff'
];
$brand = $brandConfig ?? getBrandingConfig();
$logoClaro  = $brand['logo_claro_efectivo'];
$logoOscuro = $brand['logo_oscuro_efectivo'];

function isNavActive($targets, $currentPage) {
    if (!is_array($targets)) {
        $targets = [$targets];
    }
    return in_array($currentPage, $targets);
}
?>
<!-- Sidebar Mobile Backdrop -->
<div id="sidebarBackdrop" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-40 hidden md:hidden transition-opacity"></div>

<!-- App Sidebar (Adaptive Light / Dark Mode) -->
<aside id="sidebar" class="w-72 bg-white dark:bg-slate-950 text-slate-600 dark:text-slate-300 flex flex-col h-full border-r border-slate-200/80 dark:border-slate-800/80 shadow-xl dark:shadow-2xl transition-all duration-300 transform -translate-x-full md:translate-x-0 fixed md:relative z-50 shrink-0">
    
    <!-- Brand App Header -->
    <div class="h-20 px-6 flex items-center justify-between border-b border-slate-200/80 dark:border-slate-800/80 bg-slate-50/50 dark:bg-slate-950/50">
        <a href="index.php" class="flex items-center gap-3 group min-w-0">
            <!-- Light Mode Logo -->
            <span class="brand-logo-light items-center gap-3 min-w-0">
                <?php if (!empty($logoClaro)): ?>
                    <img src="<?php echo htmlspecialchars($logoClaro); ?>" alt="<?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?>" class="h-10 max-w-[155px] object-contain rounded-lg transition-transform duration-300 group-hover:scale-105">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 via-indigo-600 to-blue-500 p-0.5 shadow-md shadow-brand-500/20 group-hover:scale-105 transition-transform duration-300 shrink-0">
                        <div class="w-full h-full bg-white rounded-[10px] flex items-center justify-center">
                            <i class="fa-solid fa-receipt text-lg text-brand-600"></i>
                        </div>
                    </div>
                <?php endif; ?>
            </span>

            <!-- Dark Mode Logo -->
            <span class="brand-logo-dark items-center gap-3 min-w-0">
                <?php if (!empty($logoOscuro)): ?>
                    <img src="<?php echo htmlspecialchars($logoOscuro); ?>" alt="<?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?>" class="h-10 max-w-[155px] object-contain rounded-lg transition-transform duration-300 group-hover:scale-105">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 via-indigo-600 to-blue-500 p-0.5 shadow-lg shadow-brand-500/20 group-hover:scale-105 transition-transform duration-300 shrink-0">
                        <div class="w-full h-full bg-slate-950/80 rounded-[10px] flex items-center justify-center backdrop-blur-sm">
                            <i class="fa-solid fa-receipt text-lg text-transparent bg-clip-text bg-gradient-to-r from-brand-400 to-blue-300"></i>
                        </div>
                    </div>
                <?php endif; ?>
            </span>

            <div class="truncate">
                <div class="flex items-center gap-2">
                    <span class="text-base font-bold tracking-tight text-slate-900 dark:text-white group-hover:text-brand-600 dark:group-hover:text-brand-300 transition-colors truncate">
                        <?php echo htmlspecialchars($brand['empresa_nombre_comercial']); ?>
                    </span>
                    <span class="text-[9px] font-bold tracking-wider uppercase px-1.5 py-0.5 rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-300 border border-brand-500/20 shrink-0">PRO</span>
                </div>
                <p class="text-[10px] text-slate-400 tracking-wide truncate">Enterprise Billing & CRM</p>
            </div>
        </a>
        <button id="closeSidebarBtn" class="md:hidden text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>

    <!-- Active Workspace Pill -->
    <div class="px-4 pt-4 pb-2">
        <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900/80 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-7 h-7 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 text-xs shrink-0">
                    <i class="fa-solid fa-building"></i>
                </div>
                <div class="truncate">
                    <p class="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate" title="<?php echo htmlspecialchars($brand['empresa_razon_social']); ?>">
                        <?php echo htmlspecialchars($brand['empresa_razon_social']); ?>
                    </p>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-[10px] text-slate-500 dark:text-slate-400">
                            SUNAT: <?php echo strtoupper($brand['facturacion_proveedor']); ?> (<?php echo ucfirst($brand['sunat_modo']); ?>)
                        </span>
                    </div>
                </div>
            </div>
            <a href="configuracion.php" title="Configurar Empresa y Marca" class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded hover:bg-slate-200/60 dark:hover:bg-slate-800 text-xs">
                <i class="fa-solid fa-gear"></i>
            </a>
        </div>
    </div>
    
    <!-- Navigation Menu -->
    <nav class="flex-1 px-3 py-3 space-y-6 overflow-y-auto dark-scroll text-xs">
        
        <!-- Section: Principal -->
        <div>
            <p class="px-3 mb-2 text-[10px] font-bold tracking-wider text-slate-400 dark:text-slate-500 uppercase">Principal</p>
            <div class="space-y-1">
                <?php $active = isNavActive('index.php', $currentPage); ?>
                <a href="index.php" class="flex items-center justify-between px-3 py-2.5 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-gauge-high w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-brand-500 dark:text-brand-400'; ?>"></i>
                        <span class="text-xs">Dashboard</span>
                    </div>
                    <?php if ($active): ?>
                        <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Section: Ventas & Facturación -->
        <div>
            <p class="px-3 mb-2 text-[10px] font-bold tracking-wider text-slate-400 dark:text-slate-500 uppercase">Facturación</p>
            <div class="space-y-1">
                <?php $active = isNavActive('comprobantes.php', $currentPage); ?>
                <a href="comprobantes.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-receipt w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-slate-400'; ?>"></i>
                        <span class="text-xs">Comprobantes</span>
                    </div>
                </a>

                <?php $active = isNavActive('factura_nueva.php', $currentPage); ?>
                <a href="factura_nueva.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-plus-circle w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-blue-500 dark:text-blue-400'; ?>"></i>
                        <span class="text-xs">Emitir Factura / Boleta</span>
                    </div>
                </a>

                <?php $active = isNavActive(['proformas.php', 'proforma_nueva.php'], $currentPage); ?>
                <a href="proformas.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-file-signature w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-slate-400'; ?>"></i>
                        <span class="text-xs">Proformas</span>
                    </div>
                </a>

                <?php $active = isNavActive(['guias.php', 'guia_nueva.php'], $currentPage); ?>
                <a href="guias.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-truck-fast w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-slate-400'; ?>"></i>
                        <span class="text-xs">Guías de Remisión</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Section: Comercio Exterior -->
        <div>
            <div class="px-3 mb-2 flex items-center justify-between">
                <p class="text-[10px] font-bold tracking-wider text-slate-400 dark:text-slate-500 uppercase">Exportaciones</p>
                <span class="text-[9px] font-semibold px-1.5 py-0.2 bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20 rounded">SUNAT</span>
            </div>
            <div class="space-y-1">
                <?php $active = isNavActive('exportaciones.php', $currentPage); ?>
                <a href="exportaciones.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-earth-americas w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-cyan-500 dark:text-cyan-400'; ?>"></i>
                        <span class="text-xs">Mis Exportaciones</span>
                    </div>
                </a>

                <?php $active = isNavActive('exportacion_nueva.php', $currentPage); ?>
                <a href="exportacion_nueva.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-ship w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-slate-400'; ?>"></i>
                        <span class="text-xs">Nueva Exportación</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Section: CRM & Inventario -->
        <div>
            <p class="px-3 mb-2 text-[10px] font-bold tracking-wider text-slate-400 dark:text-slate-500 uppercase">CRM & Stock</p>
            <div class="space-y-1">
                <?php $active = isNavActive(['clientes.php', 'cliente_nuevo.php'], $currentPage); ?>
                <a href="clientes.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-users w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-purple-500 dark:text-purple-400'; ?>"></i>
                        <span class="text-xs">Clientes</span>
                    </div>
                </a>

                <?php $active = isNavActive('crm_kanban.php', $currentPage); ?>
                <a href="crm_kanban.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-table-columns w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-amber-500 dark:text-amber-400'; ?>"></i>
                        <span class="text-xs">Tablero CRM</span>
                    </div>
                </a>

                <?php $active = isNavActive('productos.php', $currentPage); ?>
                <a href="productos.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-box-archive w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-emerald-500 dark:text-emerald-400'; ?>"></i>
                        <span class="text-xs">Productos & Servicios</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Section: Configuración -->
        <div>
            <p class="px-3 mb-2 text-[10px] font-bold tracking-wider text-slate-400 dark:text-slate-500 uppercase">Sistema</p>
            <div class="space-y-1">
                <?php $active = isNavActive('usuarios.php', $currentPage); ?>
                <a href="usuarios.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-shield-halved w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-slate-400'; ?>"></i>
                        <span class="text-xs">Usuarios & Roles</span>
                    </div>
                </a>

                <?php $active = (isNavActive('configuracion.php', $currentPage) && ($_GET['tab'] ?? '') !== 'actualizacion'); ?>
                <a href="configuracion.php" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $active ? 'bg-gradient-to-r from-brand-600 to-indigo-600 text-white font-semibold shadow-md shadow-brand-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-sliders w-4 text-center text-sm <?php echo $active ? 'text-white' : 'text-indigo-500 dark:text-indigo-400'; ?>"></i>
                        <span class="text-xs">Configuración & Marca</span>
                    </div>
                </a>

                <?php $activeAct = ($currentPage === 'configuracion.php' && ($_GET['tab'] ?? '') === 'actualizacion'); ?>
                <a href="configuracion.php?tab=actualizacion" class="flex items-center justify-between px-3 py-2 rounded-xl transition-all duration-200 <?php echo $activeAct ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-semibold shadow-md shadow-cyan-600/25' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-900/90 font-medium'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-cloud-arrow-down w-4 text-center text-sm <?php echo $activeAct ? 'text-white' : 'text-cyan-500 dark:text-cyan-400'; ?>"></i>
                        <span class="text-xs">Actualizar Sistema</span>
                    </div>
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20">Git</span>
                </a>
            </div>
        </div>

    </nav>
    
    <!-- User Profile & Logout Bottom Card -->
    <div class="p-3 border-t border-slate-200/80 dark:border-slate-800/80 bg-slate-50/60 dark:bg-slate-950/60">
        <div class="flex items-center justify-between p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors">
            <a href="perfil.php" class="flex items-center gap-2.5 min-w-0 flex-1">
                <div class="relative shrink-0">
                    <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="User" class="w-9 h-9 rounded-xl border border-slate-300 dark:border-slate-700 object-cover">
                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-950"></span>
                </div>
                <div class="truncate">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($user['nombre']); ?></p>
                    <p class="text-[10px] text-slate-500 dark:text-slate-400 truncate"><?php echo htmlspecialchars($user['rol']); ?></p>
                </div>
            </a>
            <a href="logout.php" title="Cerrar Sesión" data-confirm="¿Deseas cerrar tu sesión actual en el sistema?" data-confirm-title="Cerrar Sesión" data-confirm-text="Sí, salir" data-confirm-variant="danger" class="p-2 text-slate-400 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-colors ml-1">
                <i class="fa-solid fa-arrow-right-from-bracket text-sm"></i>
            </a>
        </div>
    </div>
</aside>
