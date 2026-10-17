<?php
require_once 'includes/auth.php';
requireLogin();

$inicioMes = date('Y-m-01');
$finMes    = date('Y-m-t');

// Métricas de Exportación (consultas sargables)
$expCount = 0;
$expUsd = 0.00;
$expPend = 0;
try {
    $stmtExp = $conn->prepare("SELECT COUNT(*) FROM comprobantes WHERE es_exportacion=1 AND fecha_emision >= ? AND fecha_emision <= ?");
    $stmtExp->execute([$inicioMes, $finMes]);
    $expCount = (int)$stmtExp->fetchColumn();

    $stmtUsd = $conn->prepare("SELECT COALESCE(SUM(subtotal),0) FROM comprobantes WHERE es_exportacion=1 AND fecha_emision >= ? AND fecha_emision <= ?");
    $stmtUsd->execute([$inicioMes, $finMes]);
    $expUsd = (float)$stmtUsd->fetchColumn();

    $expPend = (int)$conn->query("SELECT COUNT(*) FROM comprobantes WHERE es_exportacion=1 AND (nubefact_enviado=0 OR nubefact_enviado IS NULL)")->fetchColumn();
} catch(Exception $e) {}

// Métricas Generales Reales
$ventasMesPen = 0.00;
$cpeCount = 0;
$cpeAceptados = 0;
$cuentasCobrar = 0.00;

try {
    // Ventas del mes en moneda nacional
    $stmtV = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM comprobantes WHERE moneda = 'PEN' AND fecha_emision >= ? AND fecha_emision <= ?");
    $stmtV->execute([$inicioMes, $finMes]);
    $ventasMesPen = (float)$stmtV->fetchColumn();

    // CPE Emitidos este mes
    $stmtC = $conn->prepare("SELECT COUNT(*), COUNT(CASE WHEN estado = 'Aceptado' OR sunat_cdr_estado = 'Aceptado' THEN 1 END) FROM comprobantes WHERE fecha_emision >= ? AND fecha_emision <= ?");
    $stmtC->execute([$inicioMes, $finMes]);
    $rowC = $stmtC->fetch(PDO::FETCH_NUM);
    $cpeCount = (int)($rowC[0] ?? 0);
    $cpeAceptados = (int)($rowC[1] ?? 0);

    // Cuentas / proformas en seguimiento
    $stmtCob = $conn->query("SELECT COALESCE(SUM(total), 0) FROM proformas WHERE estado = 'Pendiente'");
    $cuentasCobrar = (float)$stmtCob->fetchColumn();
} catch (Exception $e) {}

// Comprobantes recientes
$recentDocs = [];
try {
    $stmtDocs = $conn->query("SELECT * FROM comprobantes ORDER BY id DESC LIMIT 5");
    $recentDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Conteo de clientes
$totalClientes = 0;
try {
    $totalClientes = (int)$conn->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
} catch(Exception $e) {}

// Datos para Gráficos de Ventas (Períodos: 7 días, 30 días, Mes actual)
$salesByDate = [];
try {
    $minDate = min(date('Y-m-01'), date('Y-m-d', strtotime('-29 days')));
    $stmtSales = $conn->prepare("SELECT fecha_emision, COALESCE(SUM(total), 0) as total FROM comprobantes WHERE fecha_emision >= ? AND (moneda = 'PEN' OR moneda IS NULL) GROUP BY fecha_emision");
    $stmtSales->execute([$minDate]);
    while ($row = $stmtSales->fetch(PDO::FETCH_ASSOC)) {
        $salesByDate[$row['fecha_emision']] = (float)$row['total'];
    }
} catch (Exception $e) {}

// 1. Período: 7 Días
$chart7Labels = [];
$chart7Data = [];
for ($i = 6; $i >= 0; $i--) {
    $fechaDia = date('Y-m-d', strtotime("-$i days"));
    $chart7Labels[] = date('d M', strtotime($fechaDia));
    $chart7Data[] = $salesByDate[$fechaDia] ?? 0.0;
}

// 2. Período: 30 Días
$chart30Labels = [];
$chart30Data = [];
for ($i = 29; $i >= 0; $i--) {
    $fechaDia = date('Y-m-d', strtotime("-$i days"));
    $chart30Labels[] = date('d M', strtotime($fechaDia));
    $chart30Data[] = $salesByDate[$fechaDia] ?? 0.0;
}

// 3. Período: Mes en curso
$chartMonthLabels = [];
$chartMonthData = [];
$daysInMonth = (int)date('t');
$yearMonth = date('Y-m-');
for ($d = 1; $d <= $daysInMonth; $d++) {
    $fechaDia = $yearMonth . str_pad($d, 2, '0', STR_PAD_LEFT);
    $chartMonthLabels[] = str_pad($d, 2, '0', STR_PAD_LEFT) . ' ' . date('M');
    $chartMonthData[] = $salesByDate[$fechaDia] ?? 0.0;
}

// Default para compatibilidad
$chartLabels = $chart7Labels;
$chartData = $chart7Data;

// Distribución de tipos
$countFacturas = 0;
$countBoletas = 0;
$countExport = 0;
try {
    $countFacturas = (int)$conn->query("SELECT COUNT(*) FROM comprobantes WHERE tipo_comprobante LIKE '%Factura%' AND es_exportacion = 0")->fetchColumn();
    $countBoletas = (int)$conn->query("SELECT COUNT(*) FROM comprobantes WHERE tipo_comprobante LIKE '%Boleta%'")->fetchColumn();
    $countExport = (int)$conn->query("SELECT COUNT(*) FROM comprobantes WHERE es_exportacion = 1")->fetchColumn();
} catch (Exception $e) {}

$hasAnyDoc = ($countFacturas + $countBoletas + $countExport) > 0;
$doughnutData = $hasAnyDoc ? [$countFacturas, $countBoletas, $countExport] : [0, 0, 0];
$totalDoughnut = array_sum($doughnutData);
$pctFact = $totalDoughnut > 0 ? round(($countFacturas / $totalDoughnut) * 100) : 0;
$pctBol  = $totalDoughnut > 0 ? round(($countBoletas / $totalDoughnut) * 100) : 0;
$pctExp  = $totalDoughnut > 0 ? round(($countExport / $totalDoughnut) * 100) : 0;

include 'includes/header.php';
?>

<?php include 'includes/sidebar.php'; ?>

<!-- Main App Content Canvas -->
<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    
    <!-- Top Navigation Header -->
    <?php 
    $pageTitle = 'Dashboard General';
    include 'includes/topbar.php'; 
    ?>

    <!-- Scrollable Workspace Body -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">
        
        <!-- Welcome Hero Banner -->
        <div class="p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white shadow-xl shadow-slate-950/10 border border-slate-800 relative overflow-hidden flex flex-col md:flex-row md:items-center justify-between gap-6">
            <!-- Decorative Glow Blobs -->
            <div class="absolute -right-10 -bottom-10 w-64 h-64 bg-brand-500/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute left-1/3 -top-10 w-48 h-48 bg-blue-500/10 rounded-full blur-2xl pointer-events-none"></div>

            <div class="relative z-10 max-w-xl">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-white/10 backdrop-blur-md border border-white/15 text-[11px] font-medium text-brand-200 mb-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Sistema Operativo • SUNAT En Línea</span>
                </div>
                <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-white">
                    Bienvenido, <?php echo htmlspecialchars($currentUser['nombre'] ?? 'Administrador'); ?> 👋
                </h2>
                <p class="text-xs sm:text-sm text-slate-300 mt-1 leading-relaxed">
                    Monitorea en tiempo real tus comprobantes electrónicos, cotizaciones en seguimiento y operaciones de exportación aduanera.
                </p>
            </div>

            <div class="relative z-10 flex flex-wrap sm:flex-nowrap items-center gap-3 shrink-0">
                <div class="text-xs text-slate-300 bg-white/10 backdrop-blur-md px-3.5 py-2.5 rounded-xl border border-white/15 flex items-center gap-2 shadow-xs">
                    <i class="fa-regular fa-calendar text-brand-300 text-sm"></i>
                    <span>
                        <?php 
                        $meses = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
                        echo date('d') . ' de ' . ($meses[date('F')] ?? date('F')) . ', ' . date('Y'); 
                        ?>
                    </span>
                </div>
                <a href="factura_nueva.php" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-brand-500 to-indigo-600 hover:from-brand-600 hover:to-indigo-700 text-white text-xs font-semibold shadow-lg shadow-brand-500/25 transition-all flex items-center gap-2">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span>Emitir CPE</span>
                </a>
            </div>
        </div>

        <!-- KPI Metric Cards Grid (2 Columns on Mobile, 4 on Desktop) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5">
            
            <!-- Card 1: Ventas del Mes -->
            <div class="app-card app-card-hover p-3.5 sm:p-5 relative overflow-hidden">
                <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 tracking-wide uppercase leading-tight">Ventas del Mes</span>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                </div>
                <div class="flex items-baseline justify-between gap-1">
                    <p class="text-base sm:text-2xl font-bold text-slate-900 tracking-tight truncate">S/ <?php echo number_format($ventasMesPen, 2); ?></p>
                    <span class="hidden sm:inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100 shrink-0">
                        <i class="fa-solid fa-circle-check text-[10px]"></i> Mes en curso
                    </span>
                </div>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1.5 sm:mt-2 flex items-center gap-1 truncate">
                    <i class="fa-regular fa-clock text-slate-400 shrink-0"></i> <span class="truncate">Acumulado en PEN</span>
                </p>
            </div>

            <!-- Card 2: Comprobantes Emitidos -->
            <div class="app-card app-card-hover p-3.5 sm:p-5 relative overflow-hidden">
                <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 tracking-wide uppercase leading-tight">CPE Emitidos</span>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="flex items-baseline justify-between gap-1">
                    <p class="text-base sm:text-2xl font-bold text-slate-900 tracking-tight"><?php echo $cpeCount; ?></p>
                    <span class="hidden sm:inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100 shrink-0">
                        <?php echo $cpeCount > 0 ? round(($cpeAceptados / $cpeCount) * 100) . '% Aceptados' : '100% Operativo'; ?>
                    </span>
                </div>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1.5 sm:mt-2 flex items-center gap-1 truncate">
                    <i class="fa-solid fa-check-double text-emerald-500 shrink-0"></i> <span class="truncate"><?php echo $cpeAceptados; ?> con CDR SUNAT</span>
                </p>
            </div>

            <!-- Card 3: Clientes Activos -->
            <div class="app-card app-card-hover p-3.5 sm:p-5 relative overflow-hidden">
                <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 tracking-wide uppercase leading-tight">Clientes Cartera</span>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="flex items-baseline justify-between gap-1">
                    <p class="text-base sm:text-2xl font-bold text-slate-900 tracking-tight"><?php echo $totalClientes; ?></p>
                    <span class="hidden sm:inline-flex items-center gap-1 text-[11px] font-semibold text-purple-600 bg-purple-50 px-2 py-0.5 rounded-full border border-purple-100 shrink-0">
                        Total Base
                    </span>
                </div>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1.5 sm:mt-2 flex items-center gap-1 truncate">
                    <i class="fa-regular fa-building text-purple-400 shrink-0"></i> <span class="truncate"><?php echo $totalClientes; ?> registrados</span>
                </p>
            </div>

            <!-- Card 4: Pendientes de Pago -->
            <div class="app-card app-card-hover p-3.5 sm:p-5 relative overflow-hidden">
                <div class="flex items-center justify-between gap-2 mb-2 sm:mb-3">
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-500 tracking-wide uppercase leading-tight">Cotizaciones</span>
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
                <div class="flex items-baseline justify-between gap-1">
                    <p class="text-base sm:text-2xl font-bold text-slate-900 tracking-tight truncate">S/ <?php echo number_format($cuentasCobrar, 2); ?></p>
                    <span class="hidden sm:inline-flex items-center gap-1 text-[11px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200 shrink-0">
                        En Seguimiento
                    </span>
                </div>
                <p class="text-[10px] sm:text-[11px] text-slate-400 mt-1.5 sm:mt-2 flex items-center gap-1 truncate">
                    <i class="fa-solid fa-file-signature text-amber-500 shrink-0"></i> <span class="truncate">Proformas abiertas</span>
                </p>
            </div>

        </div>

        <!-- Módulo Destacado: Comercio Exterior y Exportaciones -->
        <div class="app-card p-4 sm:p-6 bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950 text-white border-slate-800 shadow-md">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 sm:pb-5 border-b border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white text-base sm:text-lg shadow-md shadow-cyan-500/20 shrink-0">
                        <i class="fa-solid fa-ship"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-sm sm:text-base font-bold text-white tracking-tight">Módulo de Exportaciones SUNAT / OSE</h3>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">Incoterms & DAM</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">Control de despachos aduaneros, partidas arancelarias y facturas en USD con Nubefact</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="exportacion_nueva.php" class="flex-1 sm:flex-initial justify-center px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/15 text-white text-xs font-semibold transition-all flex items-center gap-1.5">
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>Nueva Exportación</span>
                    </a>
                    <a href="exportaciones.php" class="flex-1 sm:flex-initial justify-center px-3.5 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 text-slate-950 text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm shadow-cyan-500/25">
                        <span>Ver Listado</span>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>
            </div>

            <!-- Mini Grid de Estadísticas de Exportación (2 columnas en móvil) -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4 pt-4 sm:pt-5">
                <div class="p-3 sm:p-4 rounded-xl bg-slate-800/60 border border-slate-700/60 flex items-center gap-2.5 sm:gap-4">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] font-medium text-slate-400 uppercase tracking-wider truncate">Exportaciones Mes</p>
                        <p class="text-base sm:text-xl font-bold text-white mt-0.5"><?php echo $expCount; ?> <span class="text-[10px] sm:text-xs font-normal text-slate-400">fact.</span></p>
                    </div>
                </div>

                <div class="p-3 sm:p-4 rounded-xl bg-slate-800/60 border border-slate-700/60 flex items-center gap-2.5 sm:gap-4">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-dollar-sign"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] font-medium text-slate-400 uppercase tracking-wider truncate">Total USD</p>
                        <p class="text-base sm:text-xl font-bold text-emerald-400 mt-0.5 truncate">$ <?php echo number_format($expUsd, 2); ?></p>
                    </div>
                </div>

                <div class="col-span-2 sm:col-span-1 p-3 sm:p-4 rounded-xl bg-slate-800/60 border border-slate-700/60 flex items-center gap-2.5 sm:gap-4">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center text-xs sm:text-base shrink-0">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] sm:text-[11px] font-medium text-slate-400 uppercase tracking-wider truncate">CDR Pendientes SUNAT</p>
                        <p class="text-base sm:text-xl font-bold text-amber-400 mt-0.5"><?php echo $expPend; ?> <span class="text-[10px] sm:text-xs font-normal text-slate-400">por regularizar</span></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Interactive Charts Grid (2 columns on large screens) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            
            <!-- Left Chart: Ingresos de los últimos días (2 cols) -->
            <div class="app-card p-5 sm:p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h4 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white tracking-tight">Comportamiento de Ventas</h4>
                        <p class="text-xs text-slate-400">Facturación acumulada en moneda local (S/)</p>
                    </div>
                    <div class="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800/90 rounded-xl text-xs font-medium border border-slate-200/60 dark:border-slate-700/80 shadow-xs" id="salesPeriodSwitcher">
                        <button type="button" data-period="7" class="sales-period-btn px-2.5 py-1 rounded-lg bg-white dark:bg-slate-700 shadow-xs font-semibold text-slate-900 dark:text-white transition-all">7 Días</button>
                        <button type="button" data-period="30" class="sales-period-btn px-2.5 py-1 rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">30 Días</button>
                        <button type="button" data-period="month" class="sales-period-btn px-2.5 py-1 rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">Mes</button>
                    </div>
                </div>
                <div class="relative h-64 sm:h-72 w-full">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <!-- Right Chart: Tipos de Comprobantes (1 col) -->
            <div class="app-card p-5 sm:p-6 flex flex-col justify-between">
                <div>
                    <h4 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight mb-1">Distribución de Emisiones</h4>
                    <p class="text-xs text-slate-400 mb-4">Proporción por tipo de comprobante electrónico</p>
                </div>
                <div class="relative h-56 w-full flex items-center justify-center my-auto">
                    <canvas id="typeChart"></canvas>
                </div>
                <div class="grid grid-cols-3 gap-2 pt-4 border-t border-slate-100 text-center text-xs">
                    <div>
                        <p class="text-slate-400 text-[11px]">Facturas</p>
                        <p class="font-bold text-brand-600"><?php echo $pctFact; ?>%</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-[11px]">Boletas</p>
                        <p class="font-bold text-emerald-600"><?php echo $pctBol; ?>%</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-[11px]">Exportación</p>
                        <p class="font-bold text-cyan-600"><?php echo $pctExp; ?>%</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- Recent Documents Table Card -->
        <div class="app-card overflow-hidden">
            <div class="p-5 sm:px-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
                <div>
                    <h4 class="text-sm sm:text-base font-bold text-slate-900 tracking-tight">Comprobantes Recientes</h4>
                    <p class="text-xs text-slate-400">Últimos documentos emitidos y sincronizados con SUNAT</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="comprobantes.php" class="px-3.5 py-1.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-brand-600 transition-colors shadow-xs flex items-center gap-1.5">
                        <span>Ver Todos</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-200/80 uppercase tracking-wider text-[11px]">
                            <th class="px-6 py-3.5">Documento</th>
                            <th class="px-6 py-3.5">Cliente / Razón Social</th>
                            <th class="px-6 py-3.5">Fecha</th>
                            <th class="px-6 py-3.5">Total</th>
                            <th class="px-6 py-3.5">Estado SUNAT</th>
                            <th class="px-6 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        <?php if (!empty($recentDocs)): ?>
                            <?php foreach ($recentDocs as $doc): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?php echo ($doc['tipo_comprobante'] == 'Factura') ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200'; ?>">
                                                <?php echo htmlspecialchars($doc['tipo_comprobante']); ?>
                                            </span>
                                            <span class="font-semibold text-slate-900 font-mono"><?php echo htmlspecialchars($doc['serie'] . '-' . str_pad($doc['correlativo'], 6, '0', STR_PAD_LEFT)); ?></span>
                                            <?php if (!empty($doc['es_exportacion'])): ?>
                                                <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-cyan-50 text-cyan-700 border border-cyan-200">EXP</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($doc['cliente_razon_social']); ?></div>
                                        <div class="text-[11px] text-slate-400 font-mono"><?php echo htmlspecialchars($doc['cliente_documento']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-slate-500 font-mono">
                                        <?php echo date('d/m/Y', strtotime($doc['fecha_emision'])); ?>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-slate-900">
                                        <?php echo ($doc['moneda'] ?? 'PEN') === 'USD' ? '$' : 'S/'; ?> <?php echo number_format($doc['total'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (($doc['estado'] ?? '') === 'Aceptado' || ($doc['sunat_cdr_estado'] ?? '') === 'Aceptado'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Aceptado
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200/80">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <a href="comprobante_publico.php?id=<?php echo $doc['id']; ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors" title="Ver Detalle">
                                                <i class="fa-solid fa-eye text-xs"></i>
                                            </a>
                                            <a href="comprobante_publico.php?id=<?php echo $doc['id']; ?>&print=1" target="_blank" class="p-1.5 text-slate-400 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors" title="Imprimir / PDF">
                                                <i class="fa-solid fa-print text-xs"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Muestras de diseño por defecto si la base de datos está recién inicializada -->
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Factura</span>
                                        <span class="font-semibold text-slate-900 font-mono">F001-000042</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900">INVERSIONES TECNOLÓGICAS S.A.C.</div>
                                    <div class="text-[11px] text-slate-400 font-mono">RUC: 20601234567</div>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-mono">10/10/2026</td>
                                <td class="px-6 py-4 font-bold text-slate-900">S/ 1,250.00</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aceptado
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5 text-slate-400">
                                        <button class="p-1.5 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"><i class="fa-solid fa-eye text-xs"></i></button>
                                        <button class="p-1.5 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"><i class="fa-solid fa-file-pdf text-xs"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">Boleta</span>
                                        <span class="font-semibold text-slate-900 font-mono">B001-000128</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900">Juan Carlos Pérez Gómez</div>
                                    <div class="text-[11px] text-slate-400 font-mono">DNI: 45879632</div>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-mono">10/10/2026</td>
                                <td class="px-6 py-4 font-bold text-slate-900">S/ 85.50</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aceptado
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5 text-slate-400">
                                        <button class="p-1.5 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"><i class="fa-solid fa-eye text-xs"></i></button>
                                        <button class="p-1.5 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"><i class="fa-solid fa-file-pdf text-xs"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-cyan-50 text-cyan-700 border border-cyan-200">Exportación</span>
                                        <span class="font-semibold text-slate-900 font-mono">E001-000003</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900">GLOBAL LOGISTICS LLC (EE.UU.)</div>
                                    <div class="text-[11px] text-slate-400 font-mono">TAX ID: US-987654321</div>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-mono">09/10/2026</td>
                                <td class="px-6 py-4 font-bold text-emerald-600">$ 14,800.00</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200/80">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pendiente OSE
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5 text-slate-400">
                                        <button class="p-1.5 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors"><i class="fa-solid fa-eye text-xs"></i></button>
                                        <button class="p-1.5 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"><i class="fa-solid fa-file-pdf text-xs"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<!-- Chart.js Modern App Configuration -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.font.family = "'Plus Jakarta Sans', -apple-system, sans-serif";
    
    const isDark = () => document.documentElement.classList.contains('dark');
    const getGridColor = () => isDark() ? 'rgba(148, 163, 184, 0.12)' : 'rgba(241, 245, 249, 1)';
    const getTickColor = () => isDark() ? '#94a3b8' : '#64748b';

    Chart.defaults.color = getTickColor();

    // Datasets para Períodos de Ventas
    const salesPeriods = {
        '7': {
            labels: <?php echo json_encode($chart7Labels); ?>,
            data: <?php echo json_encode($chart7Data); ?>
        },
        '30': {
            labels: <?php echo json_encode($chart30Labels); ?>,
            data: <?php echo json_encode($chart30Data); ?>
        },
        'month': {
            labels: <?php echo json_encode($chartMonthLabels); ?>,
            data: <?php echo json_encode($chartMonthData); ?>
        }
    };

    let salesChartInstance = null;

    // Chart: Comportamiento de Ventas (Area Line Chart)
    const ctxSales = document.getElementById('salesChart');
    if (ctxSales) {
        const salesContext = ctxSales.getContext('2d');
        const gradient = salesContext.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(79, 70, 229, 0.30)');
        gradient.addColorStop(1, 'rgba(79, 70, 229, 0.0)');

        salesChartInstance = new Chart(salesContext, {
            type: 'line',
            data: {
                labels: salesPeriods['7'].labels,
                datasets: [{
                    label: 'Ventas (S/)',
                    data: salesPeriods['7'].data,
                    borderColor: '#6366f1',
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#6366f1',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 12,
                        titleFont: { size: 12, weight: '600' },
                        bodyFont: { size: 13, weight: '700' },
                        displayColors: false,
                        cornerRadius: 10,
                        callbacks: {
                            label: function(context) {
                                return 'Total: S/ ' + context.parsed.y.toLocaleString('es-PE', { minimumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: getGridColor(), drawBorder: false },
                        border: { display: false },
                        ticks: {
                            color: getTickColor(),
                            callback: function(val) {
                                return 'S/ ' + (val >= 1000 ? (val/1000) + 'k' : val);
                            }
                        }
                    },
                    x: {
                        grid: { display: false, drawBorder: false },
                        border: { display: false },
                        ticks: {
                            color: getTickColor()
                        }
                    }
                }
            }
        });

        // Switcher Interactivo de Períodos
        const periodButtons = document.querySelectorAll('.sales-period-btn');
        periodButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const period = this.getAttribute('data-period');
                if (!salesPeriods[period] || !salesChartInstance) return;

                // Actualizar estilos visuales
                periodButtons.forEach(b => {
                    b.className = 'sales-period-btn px-2.5 py-1 rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors';
                });
                this.className = 'sales-period-btn px-2.5 py-1 rounded-lg bg-white dark:bg-slate-700 shadow-xs font-semibold text-slate-900 dark:text-white transition-all';

                // Actualizar datos del gráfico
                salesChartInstance.data.labels = salesPeriods[period].labels;
                salesChartInstance.data.datasets[0].data = salesPeriods[period].data;
                salesChartInstance.update();
            });
        });
    }

    // Chart: Comprobantes por Tipo (Modern Doughnut)
    let typeChartInstance = null;
    const ctxType = document.getElementById('typeChart');
    if (ctxType) {
        typeChartInstance = new Chart(ctxType.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Facturas', 'Boletas', 'Exportación'],
                datasets: [{
                    data: <?php echo json_encode($doughnutData); ?>,
                    backgroundColor: ['#6366f1', '#10b981', '#06b6d4'],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 16,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11, weight: '600' },
                            color: getTickColor()
                        }
                    }
                }
            }
        });
    }

    // Observador para sincronizar colores de gráficos en cambio de tema
    const syncChartsWithTheme = () => {
        const gridCol = getGridColor();
        const textCol = getTickColor();
        if (salesChartInstance) {
            salesChartInstance.options.scales.y.grid.color = gridCol;
            salesChartInstance.options.scales.y.ticks.color = textCol;
            salesChartInstance.options.scales.x.ticks.color = textCol;
            salesChartInstance.update();
        }
        if (typeChartInstance) {
            typeChartInstance.options.plugins.legend.labels.color = textCol;
            typeChartInstance.update();
        }
    };

    const themeObserver = new MutationObserver(syncChartsWithTheme);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
});
</script>

<?php include 'includes/footer.php'; ?>
