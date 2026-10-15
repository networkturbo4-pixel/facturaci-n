<?php 
require_once 'includes/auth.php';
requireLogin();
include 'includes/header.php'; 
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Guías de Remisión Remitente';
    $topbarAction = [
        'label' => 'Nueva Guía',
        'url'   => 'guia_nueva.php',
        'icon'  => 'fa-solid fa-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-auto p-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center mt-10">
            <div class="w-20 h-20 bg-orange-50 text-orange-500 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                <i class="fa-solid fa-truck-fast"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Guías de Remisión Electrónicas</h3>
            <p class="text-gray-500 mb-6 max-w-md mx-auto">Emite y gestiona tus guías de remisión electrónicas requeridas para el traslado de bienes con validación de SUNAT.</p>
            <a href="guia_nueva.php" class="px-6 py-3 bg-primary text-white text-sm font-medium rounded-lg shadow-sm hover:bg-blue-600 transition-colors inline-flex items-center">
                <i class="fa-solid fa-plus mr-2"></i> Crear Guía de Remisión
            </a>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
