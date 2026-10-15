<?php
$currBottomPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$isHomeActive  = ($currBottomPage === 'index.php');
$isCpeActive   = in_array($currBottomPage, ['comprobantes.php', 'exportaciones.php', 'proformas.php', 'guias.php']);
$isNewActive   = in_array($currBottomPage, ['factura_nueva.php', 'exportacion_nueva.php', 'proforma_nueva.php']);
$isStockActive = in_array($currBottomPage, ['productos.php', 'clientes.php', 'cliente_nuevo.php', 'crm_kanban.php']);
$isCfgActive   = in_array($currBottomPage, ['configuracion.php', 'usuarios.php', 'perfil.php']);
?>
    <!-- Mobile Bottom Navigation Bar with Protruding Center FAB (md:hidden) -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl border-t border-slate-200/80 dark:border-slate-800/90 shadow-[0_-8px_30px_rgba(15,23,42,0.08)] dark:shadow-[0_-8px_30px_rgba(0,0,0,0.45)] pb-safe" aria-label="Navegación rápida móvil">
        <div class="grid grid-cols-5 items-center h-16 max-w-md mx-auto px-1 relative">
            
            <!-- 1. Inicio -->
            <a href="index.php" class="flex flex-col items-center justify-center gap-1 py-1.5 transition-colors <?php echo $isHomeActive ? 'text-brand-600 dark:text-brand-400 font-bold' : 'text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 font-medium'; ?>">
                <i class="fa-solid fa-gauge-high text-base <?php echo $isHomeActive ? 'scale-110' : ''; ?> transition-transform"></i>
                <span class="text-[10px] tracking-tight">Inicio</span>
            </a>

            <!-- 2. Comprobantes -->
            <a href="comprobantes.php" class="flex flex-col items-center justify-center gap-1 py-1.5 transition-colors <?php echo $isCpeActive ? 'text-brand-600 dark:text-brand-400 font-bold' : 'text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 font-medium'; ?>">
                <i class="fa-solid fa-receipt text-base <?php echo $isCpeActive ? 'scale-110' : ''; ?> transition-transform"></i>
                <span class="text-[10px] tracking-tight">CPEs</span>
            </a>

            <!-- 3. BOTÓN CENTRAL SOBRESALIENTE: Nueva Factura -->
            <div class="flex flex-col items-center justify-center relative">
                <a href="factura_nueva.php" class="group -mt-7 flex flex-col items-center justify-center focus:outline-none" title="Crear Nueva Factura / Boleta">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand-600 via-indigo-600 to-blue-500 text-white flex items-center justify-center shadow-xl shadow-brand-600/40 ring-4 ring-white dark:ring-slate-900 transition-all duration-200 group-hover:scale-105 group-active:scale-95 <?php echo $isNewActive ? 'ring-brand-200 dark:ring-brand-500/40' : ''; ?>">
                        <i class="fa-solid fa-plus text-xl"></i>
                    </div>
                    <span class="text-[10px] font-extrabold text-brand-600 dark:text-brand-400 mt-1 tracking-tight">
                        Facturar
                    </span>
                </a>
            </div>

            <!-- 4. Catálogo / Productos -->
            <a href="productos.php" class="flex flex-col items-center justify-center gap-1 py-1.5 transition-colors <?php echo $isStockActive ? 'text-brand-600 dark:text-brand-400 font-bold' : 'text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 font-medium'; ?>">
                <i class="fa-solid fa-box-archive text-base <?php echo $isStockActive ? 'scale-110' : ''; ?> transition-transform"></i>
                <span class="text-[10px] tracking-tight">Catálogo</span>
            </a>

            <!-- 5. Configuración / Ajustes -->
            <a href="configuracion.php" class="flex flex-col items-center justify-center gap-1 py-1.5 transition-colors <?php echo $isCfgActive ? 'text-brand-600 dark:text-brand-400 font-bold' : 'text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 font-medium'; ?>">
                <i class="fa-solid fa-sliders text-base <?php echo $isCfgActive ? 'scale-110' : ''; ?> transition-transform"></i>
                <span class="text-[10px] tracking-tight">Ajustes</span>
            </a>

        </div>
    </nav>

    <!-- Global Scripts & App Interactions -->
    <script src="js/main.js?v=3.0"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Sidebar Responsive Toggle
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const closeSidebarBtn = document.getElementById('closeSidebarBtn');
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');

        function openSidebar() {
            if (sidebar) sidebar.classList.remove('-translate-x-full');
            if (backdrop) backdrop.classList.remove('hidden');
        }

        function closeSidebar() {
            if (sidebar) sidebar.classList.add('-translate-x-full');
            if (backdrop) backdrop.classList.add('hidden');
        }

        if (mobileBtn) mobileBtn.addEventListener('click', openSidebar);
        if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', closeSidebar);
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        // Notifications Popover
        const notifBtn = document.getElementById('notificationsBtn');
        const notifMenu = document.getElementById('notificationsMenu');
        if (notifBtn && notifMenu) {
            notifBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                notifMenu.classList.toggle('hidden');
                if (userMenu) userMenu.classList.add('hidden');
            });
        }

        // User Dropdown Popover
        const userBtn = document.getElementById('userMenuBtn');
        const userMenu = document.getElementById('userMenu');
        if (userBtn && userMenu) {
            userBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenu.classList.toggle('hidden');
                if (notifMenu) notifMenu.classList.add('hidden');
            });
        }

        // Close dropdowns on outside click
        document.addEventListener('click', () => {
            if (notifMenu) notifMenu.classList.add('hidden');
            if (userMenu) userMenu.classList.add('hidden');
        });

        // Global Search Shortcut (Ctrl+K / Cmd+K) & Navigation
        const searchInput = document.getElementById('globalSearchInput');
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });

        if (searchInput) {
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    const q = searchInput.value.trim().toLowerCase();
                    if (!q) return;
                    if (q.includes('config') || q.includes('logo') || q.includes('pwa') || q.includes('sunat')) {
                        window.location.href = 'configuracion.php';
                    } else if (q.includes('export') || q.includes('dam') || q.includes('e001')) {
                        window.location.href = 'exportaciones.php';
                    } else if (q.includes('prod') || q.includes('stock')) {
                        window.location.href = 'productos.php';
                    } else if (q.includes('client') || q.includes('ruc') || q.includes('dni')) {
                        window.location.href = 'clientes.php';
                    } else if (q.includes('proform') || q.includes('cotiz')) {
                        window.location.href = 'proformas.php';
                    } else if (q.includes('crm') || q.includes('kanban')) {
                        window.location.href = 'crm_kanban.php';
                    } else {
                        window.location.href = 'comprobantes.php';
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
