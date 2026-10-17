<?php
/**
 * exportacion_nueva.php
 * Emisión moderna y asistida de Facturas de Exportación (Serie E001).
 * Incluye selección desde Gestión de Ventas (catálogo de productos) o conceptos personalizados,
 * autocompletado mediante API SUNAT para clientes y agentes de aduana,
 * y conexión en tiempo real con el Banco Central de Reserva del Perú (BCRP).
 */
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg   = '';

// Cargar catálogo de productos activos para selección rápida y autocompletado
$productosCatalogo = [];
try {
    $stmtProd = $conn->query("SELECT id, codigo, descripcion, unidad_medida, precio_unitario, codigo_partida, stock FROM productos WHERE estado = 'Activo' ORDER BY descripcion ASC");
    $productosCatalogo = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $productosCatalogo = [];
}

// Cargar clientes existentes para selección rápida
$clientesRegistrados = [];
try {
    $stmtCli = $conn->query("SELECT id, tipo_documento, numero_documento, razon_social, direccion_fiscal FROM clientes ORDER BY razon_social ASC");
    $clientesRegistrados = $stmtCli->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clientesRegistrados = [];
}

// Obtener tipo de cambio oficial BCRP con caché local en la base de datos
function obtenerTipoCambioBCRP() {
    global $conn;
    $hoy = date('Y-m-d');
    $fechaCache = getSystemConfig('tipo_cambio_fecha');
    $tcCache = getSystemConfig('tipo_cambio_cache');

    if ($fechaCache === $hoy && !empty($tcCache) && (float)$tcCache > 0) {
        return (float)$tcCache;
    }

    $fin = $hoy;
    $inicio = date('Y-m-d', strtotime('-10 days'));
    // Serie PD04638PD: Tipo de cambio interbancario de venta oficial del BCRP
    $url = "https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/{$inicio}/{$fin}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FactuSys/2.0'
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200 && $res) {
        $data = json_decode($res, true);
        if (!empty($data['periods']) && is_array($data['periods'])) {
            $periods = array_reverse($data['periods']);
            foreach ($periods as $p) {
                if (!empty($p['values'][0]) && is_numeric($p['values'][0])) {
                    $tc = round((float)$p['values'][0], 4);
                    try {
                        $upd = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                        $upd->execute(['tipo_cambio_cache', (string)$tc]);
                        $upd->execute(['tipo_cambio_fecha', $hoy]);
                    } catch (Exception $e) {}
                    return $tc;
                }
            }
        }
    }
    return !empty($tcCache) ? (float)$tcCache : 3.4282;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada. Intente de nuevo.";
    } else {
        $serie       = 'E001';
        $fecha       = $_POST['fecha_emision'] ?? date('Y-m-d');
        $moneda      = $_POST['moneda'] ?? 'USD';
        $tipo_cambio = (float)($_POST['tipo_cambio'] ?? 3.4282);

        // Datos cliente
        $doc_cliente  = trim($_POST['cliente_documento'] ?? '-');
        if (empty($doc_cliente)) $doc_cliente = '-';
        $razon        = trim($_POST['cliente_razon_social'] ?? '');
        $dir          = trim($_POST['cliente_direccion'] ?? '-');
        if (empty($dir)) $dir = '-';
        $pais_res     = trim($_POST['cliente_pais_residencia'] ?? '');

        // Datos aduaneros
        $pais_codigo  = $_POST['pais_destino_codigo'] ?? '';
        $pais_nombre  = $_POST['pais_destino_nombre'] ?? '';
        $incoterm     = $_POST['incoterm'] ?? 'FOB';
        $puerto       = trim($_POST['puerto_embarque'] ?? '');
        $aduana       = $_POST['aduana_partida'] ?? '';
        $numero_dam   = trim($_POST['numero_dam'] ?? '');
        $ruc_agente   = trim($_POST['ruc_agente_aduana'] ?? '');
        $nom_agente   = trim($_POST['nombre_agente_aduana'] ?? '');

        // Productos
        $qtys     = $_POST['qty']       ?? [];
        $descs    = $_POST['desc']      ?? [];
        $prices   = $_POST['price']     ?? [];
        $units    = $_POST['unidad']    ?? [];
        $partidas = $_POST['partida']   ?? [];
        $pesos_b  = $_POST['peso_bruto'] ?? [];
        $pesos_n  = $_POST['peso_neto']  ?? [];

        if (empty($razon) || empty($qtys) || empty($pais_codigo)) {
            $error_msg = "Complete los datos del comprador extranjero, país destino y al menos un producto o servicio.";
        } else {
            try {
                $conn->beginTransaction();

                $subtotal = 0;
                foreach ($qtys as $i => $q) {
                    $subtotal += (float)$q * (float)($prices[$i] ?? 0);
                }
                $igv   = 0; // Exportación exonerada al 0%
                $total = $subtotal;

                $stmt = $conn->prepare("SELECT MAX(correlativo) as max_corr FROM comprobantes WHERE serie = ?");
                $stmt->execute([$serie]);
                $row = $stmt->fetch();
                $correlativo = ($row['max_corr'] ?? 0) + 1;

                $token_publico = bin2hex(random_bytes(16));

                // Insertar comprobante de exportación
                $stmt = $conn->prepare("INSERT INTO comprobantes
                    (token_publico, tipo_comprobante, serie, correlativo, fecha_emision,
                     cliente_documento, cliente_razon_social, cliente_direccion,
                     subtotal, igv, total,
                     moneda, tipo_cambio, es_exportacion,
                     pais_destino_codigo, pais_destino_nombre, incoterm,
                     puerto_embarque, aduana_partida, numero_dam, estado_dam,
                     ruc_agente_aduana, nombre_agente_aduana, cliente_pais_residencia,
                     estado)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

                $stmt->execute([
                    $token_publico, 'Factura de Exportación', $serie, $correlativo, $fecha,
                    $doc_cliente, $razon, $dir,
                    $subtotal, $igv, $total,
                    $moneda, $tipo_cambio, 1,
                    $pais_codigo, $pais_nombre, $incoterm,
                    $puerto, $aduana, $numero_dam ?: null,
                    $numero_dam ? 'En Proceso' : 'Sin DAM',
                    $ruc_agente ?: null, $nom_agente ?: null, $pais_res,
                    'Pendiente SUNAT'
                ]);

                $comprobante_id = $conn->lastInsertId();

                // Insertar líneas y partidas arancelarias
                $stmt_line = $conn->prepare("INSERT INTO comprobante_lineas
                    (comprobante_id, cantidad, descripcion, precio_unitario, total_linea)
                    VALUES (?,?,?,?,?)");

                $stmt_part = $conn->prepare("INSERT INTO exportacion_partidas
                    (comprobante_id, linea_num, codigo_partida, unidad_medida, peso_bruto, peso_neto)
                    VALUES (?,?,?,?,?,?)");

                foreach ($qtys as $i => $q) {
                    $d  = trim($descs[$i] ?? '');
                    if (empty($d)) continue;
                    $p  = (float)($prices[$i] ?? 0);
                    $tl = (float)$q * $p;
                    $stmt_line->execute([$comprobante_id, $q, $d, $p, $tl]);

                    $linea_id = $conn->lastInsertId();

                    $partida = trim($partidas[$i] ?? '');
                    if ($partida) {
                        $stmt_part->execute([
                            $comprobante_id,
                            $linea_id,
                            $partida,
                            $units[$i] ?? 'NIU',
                            (float)($pesos_b[$i] ?? 0),
                            (float)($pesos_n[$i] ?? 0)
                        ]);
                    }
                }

                $conn->commit();
                $success_msg = "Factura de Exportación $serie-" . str_pad((string)$correlativo, 6, "0", STR_PAD_LEFT) . " generada con éxito. ID: $comprobante_id";

                // Enviar a SUNAT si se presionó "Emitir y Enviar a SUNAT"
                if (!empty($_POST['enviar_sunat'])) {
                    require_once __DIR__ . '/api/enviar_sunat.php';
                    try {
                        $sunat_data = enviarComprobanteNubefact($comprobante_id, $conn);
                        if (!empty($sunat_data['success'])) {
                            $success_msg .= " | SUNAT: ✅ " . ($sunat_data['cdr_estado'] ?? 'Aceptado');
                        } elseif (!empty($sunat_data['contingencia'])) {
                            $success_msg .= " | SUNAT: 🛡️ " . ($sunat_data['message'] ?? 'Guardado en contingencia para sincronización');
                        } else {
                            $success_msg .= " | SUNAT: ⚠️ " . ($sunat_data['message'] ?? 'Error al enviar');
                        }
                    } catch (Exception $exSunat) {
                        $success_msg .= " | SUNAT: 🛡️ " . $exSunat->getMessage();
                    }
                }

            } catch (Exception $e) {
                $conn->rollBack();
                $error_msg = "Error al generar exportación: " . $e->getMessage();
            }
        }
    }
}

$tipo_cambio_actual = obtenerTipoCambioBCRP();
$brand = getBrandingConfig();
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php
    $pageTitle = 'Nueva Exportación';
    $topbarAction = [
        'label' => 'Mis Exportaciones',
        'icon'  => 'fa-solid fa-earth-americas text-xs',
        'url'   => 'exportaciones.php'
    ];
    include 'includes/topbar.php';
    ?>

    <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
        <div class="max-w-5xl mx-auto space-y-6">

            <!-- Banner Informativo Superior -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-brand-600 to-indigo-500 text-white flex items-center justify-center text-xl shrink-0 shadow-md shadow-brand-500/20">
                        <i class="fa-solid fa-ship"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-black text-slate-900 tracking-tight">Factura de Exportación</h2>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                Serie E001 • IGV 0%
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">Operación exonerada de tributos según Art. 33° del TUO de la Ley del IGV e ISC.</p>
                    </div>
                </div>
                
                <!-- Conexión BCRP Pill -->
                <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-xl self-stretch sm:self-auto justify-between sm:justify-start">
                    <div class="flex items-center gap-1.5 text-xs text-slate-600">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="font-bold text-slate-700">BCRP:</span>
                        <span class="font-mono font-bold text-brand-600">S/ <?php echo number_format($tipo_cambio_actual, 4); ?></span>
                    </div>
                    <button type="button" id="btn_tc_bcrp_top" class="text-brand-600 hover:text-brand-700 text-xs font-bold transition-colors ml-2" title="Sincronizar Tipo de Cambio con el BCRP">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>
                </div>
            </div>

            <?php if ($success_msg): ?>
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-900 rounded-2xl flex items-center gap-3 animate-fade-in shadow-xs">
                <i class="fa-solid fa-circle-check text-emerald-600 text-xl shrink-0"></i>
                <div class="flex-1">
                    <p class="text-xs font-bold"><?php echo htmlspecialchars($success_msg); ?></p>
                    <a href="exportaciones.php" class="text-xs text-emerald-700 font-extrabold underline hover:text-emerald-900 mt-0.5 inline-block">Ir a la lista de mis exportaciones →</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
            <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-center gap-3 animate-fade-in shadow-xs">
                <i class="fa-solid fa-circle-exclamation text-rose-500 text-xl shrink-0"></i>
                <p class="text-xs font-bold"><?php echo htmlspecialchars($error_msg); ?></p>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="form_exportacion" class="space-y-6">
                <?php echo csrf_field(); ?>

                <!-- ===== SECCIÓN A: DATOS DEL COMPROBANTE Y TIPO DE CAMBIO BCRP ===== -->
                <div class="bg-white rounded-2xl shadow-xs border border-slate-200/90 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                        <div class="flex items-center gap-2.5">
                            <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center shrink-0">A</span>
                            <h3 class="font-extrabold text-slate-800 text-sm">Datos del Comprobante</h3>
                        </div>
                        <span class="text-[11px] font-mono font-bold text-slate-400">SERIE: E001</span>
                    </div>

                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Tipo de Documento</label>
                            <input type="text" value="Factura de Exportación" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 font-bold select-none" readonly>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Serie Oficial</label>
                            <input type="text" value="E001" class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 font-mono font-extrabold select-none" readonly>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Fecha de Emisión</label>
                            <input type="date" name="fecha_emision" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Moneda de Facturación</label>
                            <select name="moneda" id="sel_moneda" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                <option value="USD" selected>🇺🇸 USD — Dólar Americano</option>
                                <option value="EUR">🇪🇺 EUR — Euro</option>
                                <option value="GBP">🇬🇧 GBP — Libra Esterlina</option>
                                <option value="PEN">🇵🇪 PEN — Sol Peruano</option>
                            </select>
                        </div>

                        <!-- Card de Conexión y Tipo de Cambio BCRP -->
                        <div class="sm:col-span-2 md:col-span-4 bg-gradient-to-r from-slate-50 to-indigo-50/40 border border-slate-200/80 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-slate-800">Tipo de Cambio Oficial (S/ por USD)</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                        <i class="fa-solid fa-building-columns mr-1"></i> BCRP Oficial
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-0.5">Tipo de cambio interbancario de venta publicado por el Banco Central de Reserva del Perú.</p>
                            </div>

                            <div class="flex items-center gap-2.5">
                                <div class="relative w-36">
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 font-bold text-xs">S/</span>
                                    <input type="number" name="tipo_cambio" id="inp_tc" value="<?php echo $tipo_cambio_actual; ?>" step="0.0001" min="0.0001" class="w-full bg-white border border-slate-300 rounded-xl pl-8 pr-3 py-2 text-xs font-mono font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                </div>

                                <button type="button" id="btn_tc_bcrp" class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold transition-all shadow-xs shadow-brand-500/20 inline-flex items-center gap-1.5 shrink-0">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    <span id="btn_tc_bcrp_txt">Actualizar BCRP</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== SECCIÓN B: COMPRADOR EXTRANJERO ===== -->
                <div class="bg-white rounded-2xl shadow-xs border border-slate-200/90 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                        <div class="flex items-center gap-2.5">
                            <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center shrink-0">B</span>
                            <h3 class="font-extrabold text-slate-800 text-sm">Comprador Extranjero / Cliente</h3>
                        </div>
                        <span class="text-[11px] text-slate-500 font-medium">No domiciliado o Cliente local</span>
                    </div>

                    <div class="p-6 space-y-4">
                        <!-- Selector de Clientes Existentes (Opcional para autocompletar) -->
                        <?php if (!empty($clientesRegistrados)): ?>
                        <div class="bg-slate-50/80 border border-slate-200/80 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-xs text-slate-600">
                                <i class="fa-solid fa-users text-slate-400"></i>
                                <span class="font-bold">Elegir de mis clientes registrados:</span>
                            </div>
                            <div class="w-full sm:w-80">
                                <select id="sel_cliente_registrado" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-700 focus:outline-none focus:border-brand-500">
                                    <option value="">-- Seleccionar cliente guardado --</option>
                                    <?php foreach ($clientesRegistrados as $cli): ?>
                                    <option value="<?php echo htmlspecialchars($cli['id']); ?>" 
                                            data-razon="<?php echo htmlspecialchars($cli['razon_social']); ?>" 
                                            data-doc="<?php echo htmlspecialchars($cli['numero_documento']); ?>" 
                                            data-dir="<?php echo htmlspecialchars($cli['direccion_fiscal'] ?: '-'); ?>">
                                        <?php echo htmlspecialchars($cli['razon_social']); ?> (<?php echo htmlspecialchars($cli['numero_documento']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Documento / RUC con API SUNAT -->
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">
                                    N° Documento / RUC / Tax ID
                                    <span class="text-[10px] text-slate-400 font-normal lowercase">(opcional en exterior)</span>
                                </label>
                                <div class="flex gap-2">
                                    <input type="text" name="cliente_documento" id="inp_cliente_doc" placeholder="Ej: 20XXXXXXXXX o Tax ID" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono font-semibold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                    <button type="button" id="btn_consultar_cliente" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-colors inline-flex items-center gap-1.5 shrink-0" title="Consultar RUC / DNI con API oficial">
                                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                                        <span class="hidden sm:inline">API</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Razón Social -->
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Razón Social / Nombre del Comprador *</label>
                                <input type="text" name="cliente_razon_social" id="inp_cliente_razon" placeholder="Ej: GLOBAL FOOD DISTRIBUTORS LLC" required class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                            </div>

                            <!-- Dirección -->
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Dirección del Comprador</label>
                                <input type="text" name="cliente_direccion" id="inp_cliente_dir" placeholder="Ej: 742 Evergreen Terrace, Springfield, OR" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-medium text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                            </div>

                            <!-- País de Residencia -->
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">País de Residencia</label>
                                <input type="text" name="cliente_pais_residencia" id="inp_cliente_pais_res" placeholder="Ej: Estados Unidos" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-medium text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                            </div>
                        </div>

                        <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 flex items-start gap-2.5">
                            <i class="fa-solid fa-circle-info text-blue-500 mt-0.5 shrink-0 text-sm"></i>
                            <p class="text-xs text-blue-700 leading-relaxed">
                                Para compradores en el extranjero sin RUC, SUNAT asigna automáticamente el identificador <strong>"-" (No Domiciliado)</strong> según R.S. 117-2017/SUNAT. Si ingresas un RUC peruano, puedes pulsar el botón <strong>API</strong> para rellenar la Razón Social y Dirección al instante.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ===== SECCIÓN C: DATOS ADUANEROS Y LOGÍSTICA ===== -->
                <div class="bg-white rounded-2xl shadow-xs border border-slate-200/90 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/70">
                        <div class="flex items-center gap-2.5">
                            <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center shrink-0">C</span>
                            <h3 class="font-extrabold text-slate-800 text-sm">Logística y Datos Aduaneros (SUNAT)</h3>
                        </div>

                        <!-- Presets de Autocompletado Rápido -->
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider mr-1">Relleno Rápido:</span>
                            <button type="button" onclick="aplicarPresetLogistica('callao_fob')" class="px-2.5 py-1 rounded-lg bg-slate-200/70 hover:bg-brand-50 hover:text-brand-600 text-slate-600 text-[10px] font-bold transition-all">
                                🚢 Callao FOB
                            </button>
                            <button type="button" onclick="aplicarPresetLogistica('aereo_fca')" class="px-2.5 py-1 rounded-lg bg-slate-200/70 hover:bg-brand-50 hover:text-brand-600 text-slate-600 text-[10px] font-bold transition-all">
                                ✈️ Jorge Chávez FCA
                            </button>
                            <button type="button" onclick="aplicarPresetLogistica('paita_fob')" class="px-2.5 py-1 rounded-lg bg-slate-200/70 hover:bg-brand-50 hover:text-brand-600 text-slate-600 text-[10px] font-bold transition-all">
                                🚢 Paita FOB
                            </button>
                        </div>
                    </div>

                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <!-- País Destino -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">País Destino *</label>
                            <select name="pais_destino_codigo" id="sel_pais" required class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                <option value="">-- Seleccione país --</option>
                                <option value="US" data-nombre="Estados Unidos">🇺🇸 Estados Unidos</option>
                                <option value="CN" data-nombre="China">🇨🇳 China</option>
                                <option value="JP" data-nombre="Japón">🇯🇵 Japón</option>
                                <option value="DE" data-nombre="Alemania">🇩🇪 Alemania</option>
                                <option value="ES" data-nombre="España">🇪🇸 España</option>
                                <option value="GB" data-nombre="Reino Unido">🇬🇧 Reino Unido</option>
                                <option value="NL" data-nombre="Países Bajos">🇳🇱 Países Bajos</option>
                                <option value="FR" data-nombre="Francia">🇫🇷 Francia</option>
                                <option value="CA" data-nombre="Canadá">🇨🇦 Canadá</option>
                                <option value="AU" data-nombre="Australia">🇦🇺 Australia</option>
                                <option value="BR" data-nombre="Brasil">🇧🇷 Brasil</option>
                                <option value="CL" data-nombre="Chile">🇨🇱 Chile</option>
                                <option value="CO" data-nombre="Colombia">🇨🇴 Colombia</option>
                                <option value="MX" data-nombre="México">🇲🇽 México</option>
                                <option value="AR" data-nombre="Argentina">🇦🇷 Argentina</option>
                                <option value="BE" data-nombre="Bélgica">🇧🇪 Bélgica</option>
                                <option value="IT" data-nombre="Italia">🇮🇹 Italia</option>
                                <option value="KR" data-nombre="Corea del Sur">🇰🇷 Corea del Sur</option>
                                <option value="IN" data-nombre="India">🇮🇳 India</option>
                                <option value="SG" data-nombre="Singapur">🇸🇬 Singapur</option>
                                <option value="HK" data-nombre="Hong Kong">🇭🇰 Hong Kong</option>
                                <option value="CH" data-nombre="Suiza">🇨🇭 Suiza</option>
                                <option value="SE" data-nombre="Suecia">🇸🇪 Suecia</option>
                                <option value="NO" data-nombre="Noruega">🇳🇴 Noruega</option>
                                <option value="TR" data-nombre="Turquía">🇹🇷 Turquía</option>
                                <option value="EC" data-nombre="Ecuador">🇪🇨 Ecuador</option>
                                <option value="BO" data-nombre="Bolivia">🇧🇴 Bolivia</option>
                                <option value="PA" data-nombre="Panamá">🇵🇦 Panamá</option>
                                <option value="CR" data-nombre="Costa Rica">🇨🇷 Costa Rica</option>
                            </select>
                            <input type="hidden" name="pais_destino_nombre" id="inp_pais_nombre">
                        </div>

                        <!-- Incoterm -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Incoterm 2020</label>
                            <select name="incoterm" id="sel_incoterm" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                <optgroup label="Transporte marítimo/fluvial">
                                    <option value="FOB" selected>FOB — Free On Board (Libre a Bordo)</option>
                                    <option value="CFR">CFR — Cost and Freight (Coste y Flete)</option>
                                    <option value="CIF">CIF — Cost, Insurance and Freight</option>
                                    <option value="FAS">FAS — Free Alongside Ship</option>
                                </optgroup>
                                <optgroup label="Cualquier modo de transporte">
                                    <option value="FCA">FCA — Free Carrier (Franco Transportista)</option>
                                    <option value="EXW">EXW — Ex Works (En Fábrica)</option>
                                    <option value="CPT">CPT — Carriage Paid To</option>
                                    <option value="CIP">CIP — Carriage & Insurance Paid</option>
                                    <option value="DAP">DAP — Delivered At Place</option>
                                    <option value="DDP">DDP — Delivered Duty Paid</option>
                                </optgroup>
                            </select>
                        </div>

                        <!-- Aduana de Partida -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Aduana de Partida</label>
                            <select name="aduana_partida" id="sel_aduana" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                <option value="Callao">Callao (Marítima y Aérea — 118 / 235)</option>
                                <option value="Paita">Paita (Piura — 046)</option>
                                <option value="Salaverry">Salaverry (La Libertad — 028)</option>
                                <option value="Pisco">Pisco (Ica — 080)</option>
                                <option value="Chimbote">Chimbote (014)</option>
                                <option value="Ilo">Ilo (Moquegua — 145)</option>
                                <option value="Matarani">Mollendo / Matarani (127)</option>
                                <option value="Tacna">Tacna (172)</option>
                                <option value="Tumbes">Tumbes (Puerto Pizarro — 019)</option>
                                <option value="Puno">Puno / Desaguadero (181)</option>
                                <option value="Iquitos">Iquitos (Fluvial — 163)</option>
                            </select>
                        </div>

                        <!-- Puerto de Embarque -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Puerto o Aeropuerto de Embarque</label>
                            <input type="text" name="puerto_embarque" id="inp_puerto" placeholder="Ej: Puerto del Callao" value="Puerto del Callao" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                        </div>

                        <!-- N° DAM -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">
                                N° DAM (Declaración Aduanera)
                                <span class="text-[10px] text-slate-400 font-normal lowercase">(opcional al emitir)</span>
                            </label>
                            <input type="text" name="numero_dam" id="inp_dam" placeholder="Ej: 118-2026-10-123456" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono font-semibold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                        </div>

                        <!-- Agente de Aduana con API RUC -->
                        <div class="sm:col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">
                                Agente de Aduana — RUC
                                <span class="text-[10px] text-brand-600 font-bold lowercase">con API SUNAT</span>
                            </label>
                            <div class="flex gap-2">
                                <input type="text" name="ruc_agente_aduana" id="inp_ruc_agente" maxlength="11" placeholder="20XXXXXXXXX" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono font-semibold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                                <button type="button" id="btn_buscar_agente_ruc" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-colors inline-flex items-center gap-1.5 shrink-0" title="Consultar RUC del Agente en SUNAT">
                                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                                    <span class="hidden sm:inline">RUC</span>
                                </button>
                            </div>
                        </div>

                        <!-- Agente de Aduana — Razón Social -->
                        <div class="sm:col-span-2 md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Agente de Aduana — Razón Social</label>
                            <input type="text" name="nombre_agente_aduana" id="inp_nombre_agente" placeholder="Ej: AGENCIA AFIANZADA DE ADUANA XYZ SAC" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 shadow-2xs">
                        </div>
                    </div>
                </div>

                <!-- ===== SECCIÓN D: MERCANCÍAS Y PARTIDAS ARANCELARIAS ===== -->
                <div class="bg-white rounded-2xl shadow-xs border border-slate-200/90 overflow-visible relative">
                    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/70">
                        <div class="flex items-center gap-2.5">
                            <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center shrink-0">D</span>
                            <div>
                                <h3 class="font-extrabold text-slate-800 text-sm">Detalle de Mercancías y Partidas Arancelarias</h3>
                                <p class="text-[11px] text-slate-400">Escoge productos de Gestión de Ventas o escribe conceptos y productos personalizados</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap">
                            <?php if (!empty($productosCatalogo)): ?>
                            <select id="sel_quick_product" class="bg-brand-50 border border-brand-200 text-brand-900 rounded-xl px-3 py-1.5 text-xs font-bold focus:outline-none focus:border-brand-500 shadow-2xs">
                                <option value="">⚡ + Insertar de Gestión de Ventas...</option>
                                <?php foreach ($productosCatalogo as $prod): ?>
                                    <option value="<?php echo htmlspecialchars($prod['descripcion']); ?>"
                                            data-price="<?php echo number_format((float)$prod['precio_unitario'], 2, '.', ''); ?>"
                                            data-unit="<?php echo htmlspecialchars($prod['unidad_medida'] ?: 'NIU'); ?>"
                                            data-partida="<?php echo htmlspecialchars($prod['codigo_partida'] ?: ''); ?>"
                                            data-code="<?php echo htmlspecialchars($prod['codigo']); ?>">
                                        <?php echo htmlspecialchars($prod['codigo'] . ' — ' . $prod['descripcion']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <button type="button" id="btn_add_row" class="px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold transition-all shadow-xs shadow-brand-500/20 inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-plus text-[10px]"></i>
                                <span>Agregar Ítem</span>
                            </button>
                        </div>
                    </div>

                    <!-- Sugerencias de Partidas Arancelarias Peruanas Frecuentes -->
                    <div class="px-6 py-3 bg-slate-50/50 border-b border-slate-100 flex items-center gap-2 overflow-x-auto text-[11px]">
                        <span class="font-bold text-slate-500 shrink-0"><i class="fa-solid fa-tags text-slate-400 mr-1"></i> Partidas Comunes:</span>
                        <div class="flex items-center gap-1.5 shrink-0 flex-nowrap">
                            <button type="button" onclick="rellenarUltimaPartida('0901.21.00.00')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">0901.21 (Café Tostado)</button>
                            <button type="button" onclick="rellenarUltimaPartida('1801.00.19.00')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">1801.00 (Cacao)</button>
                            <button type="button" onclick="rellenarUltimaPartida('0804.40.00.00')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">0804.40 (Palta Hass)</button>
                            <button type="button" onclick="rellenarUltimaPartida('0810.40.00.00')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">0810.40 (Arándanos)</button>
                            <button type="button" onclick="rellenarUltimaPartida('0806.10.00.00')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">0806.10 (Uvas)</button>
                            <button type="button" onclick="rellenarUltimaPartida('2208.20.21.00')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">2208.20 (Pisco)</button>
                            <button type="button" onclick="rellenarUltimaPartida('6109.10.00.31')" class="px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-700 hover:border-brand-500 font-mono text-[10px]">6109.10 (T-Shirts)</button>
                        </div>
                    </div>

                    <div class="p-4 sm:p-6 pb-28 sm:pb-36 overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[980px]">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 text-[11px] font-extrabold uppercase tracking-wider">
                                    <th class="px-3 py-3 w-16 text-center">Cant.</th>
                                    <th class="px-3 py-3">Descripción de la Mercancía</th>
                                    <th class="px-3 py-3 w-28 text-center">Unidad</th>
                                    <th class="px-3 py-3 w-32 text-right">P. Unit. (<span class="lbl-moneda">USD</span>)</th>
                                    <th class="px-3 py-3 w-36">Partida Arancelaria</th>
                                    <th class="px-3 py-3 w-24 text-right">P. Bruto (KG)</th>
                                    <th class="px-3 py-3 w-24 text-right">P. Neto (KG)</th>
                                    <th class="px-3 py-3 w-32 text-right">Total (<span class="lbl-moneda">USD</span>)</th>
                                    <th class="px-2 py-3 w-10 text-center"></th>
                                </tr>
                            </thead>
                            <tbody id="items_body" class="divide-y divide-slate-100 text-xs font-medium">
                                <!-- Primera línea por defecto -->
                                <tr class="item-row hover:bg-slate-50/50 transition-colors">
                                    <td class="px-2 py-2.5 text-center">
                                        <input type="number" name="qty[]" value="1" min="0.001" step="0.001" class="item-qty w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono font-bold text-center focus:outline-none focus:border-brand-500" required>
                                    </td>
                                    <td class="px-2 py-2.5 relative">
                                        <div class="product-dropdown-wrapper relative w-full">
                                            <div class="relative flex items-center">
                                                <input type="text" name="desc[]" placeholder="Escribir producto o buscar en catálogo..." class="item-desc w-full bg-white border border-slate-200 rounded-xl pl-3 pr-8 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-brand-500" autocomplete="off" required>
                                                <button type="button" class="btn-toggle-dropdown absolute right-2.5 text-slate-400 hover:text-brand-600 transition-colors p-1" title="Ver catálogo de productos">
                                                    <i class="fa-solid fa-chevron-down text-[11px] transition-transform duration-200"></i>
                                                </button>
                                            </div>
                                            <div class="product-dropdown-menu hidden absolute left-0 top-full mt-1.5 z-50 min-w-[320px] max-w-[460px] w-full bg-white border border-slate-200 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                                                <div class="px-3.5 py-2.5 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                                                    <div class="flex items-center gap-2">
                                                        <div class="w-5 h-5 rounded-md bg-brand-500/10 text-brand-600 flex items-center justify-center text-[10px]">
                                                            <i class="fa-solid fa-boxes-stacked"></i>
                                                        </div>
                                                        <span class="text-[11px] font-extrabold text-slate-800">Gestión de Ventas</span>
                                                    </div>
                                                    <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-brand-50 text-brand-600">
                                                        <?php echo count($productosCatalogo); ?> ítems
                                                    </span>
                                                </div>
                                                <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                                                <div class="px-3 py-2 bg-slate-50/60 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-400">
                                                    <span><i class="fa-regular fa-keyboard mr-1"></i> Enter para producto libre</span>
                                                    <a href="productos.php" target="_blank" class="text-brand-600 hover:underline flex items-center gap-1 font-semibold">
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-2 py-2.5 text-center">
                                        <select name="unidad[]" class="item-unit w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-bold text-slate-700 focus:outline-none focus:border-brand-500">
                                            <option value="KGM">KG — Kilogramos</option>
                                            <option value="TNE">TNE — Toneladas</option>
                                            <option value="NIU" selected>NIU — Unidades</option>
                                            <option value="LTR">LTR — Litros</option>
                                            <option value="MTR">MTR — Metros</option>
                                            <option value="ZZ">ZZ — Servicios / Otros</option>
                                        </select>
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <input type="number" name="price[]" placeholder="0.00" min="0" step="0.01" class="item-price w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono font-bold text-right text-slate-800 focus:outline-none focus:border-brand-500" required>
                                    </td>
                                    <td class="px-2 py-2.5">
                                        <input type="text" name="partida[]" placeholder="0901.21.00.00" list="dl_partidas" class="item-partida w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono font-semibold text-slate-800 focus:outline-none focus:border-brand-500" pattern="^(\d{4}\.\d{2}\.\d{2}\.\d{2}|\d{10})?$" title="Formato arancelario: 0000.00.00.00">
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <input type="number" name="peso_bruto[]" placeholder="0.000" min="0" step="0.001" class="w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono text-right text-slate-800 focus:outline-none focus:border-brand-500">
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <input type="number" name="peso_neto[]" placeholder="0.000" min="0" step="0.001" class="w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono text-right text-slate-800 focus:outline-none focus:border-brand-500">
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono font-extrabold text-slate-900 item-total whitespace-nowrap">
                                        0.00
                                    </td>
                                    <td class="px-2 py-2.5 text-center btn-remove cursor-pointer text-slate-400 hover:text-rose-600 transition-colors" title="Quitar ítem">
                                        <i class="fa-solid fa-trash pointer-events-none text-xs"></i>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <datalist id="dl_partidas">
                        <option value="0901.11.00.00">Café verde sin tostar</option>
                        <option value="0901.21.00.00">Café tostado en grano o molido</option>
                        <option value="1801.00.19.00">Cacao en grano orgánico</option>
                        <option value="0804.40.00.00">Aguacates / Paltas Hass frescas</option>
                        <option value="0810.40.00.00">Arándanos frescos (Blueberries)</option>
                        <option value="0806.10.00.00">Uvas frescas de exportación</option>
                        <option value="0804.50.20.00">Mangos frescos</option>
                        <option value="0709.20.00.00">Espárragos frescos o refrigerados</option>
                        <option value="1008.50.90.00">Quinua en grano procesada</option>
                        <option value="2208.20.21.00">Pisco peruano en botellas</option>
                        <option value="6109.10.00.31">Camisetas y T-Shirts de algodón</option>
                        <option value="0307.43.00.00">Pota / Calamar gigante congelado</option>
                    </datalist>
                </div>

                <!-- ===== SECCIÓN E: RESUMEN DE TOTALES ===== -->
                <div class="bg-white rounded-2xl shadow-xs border border-slate-200/90 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                        <div class="flex items-center gap-2.5">
                            <span class="w-6 h-6 rounded-lg bg-brand-600 text-white text-xs font-bold flex items-center justify-center shrink-0">E</span>
                            <h3 class="font-extrabold text-slate-800 text-sm">Resumen de Liquidación</h3>
                        </div>
                    </div>

                    <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <!-- Alerta Exoneración Tributaria -->
                        <div class="flex-1 bg-amber-50/80 border border-amber-200/80 rounded-2xl p-4 flex items-start gap-3">
                            <div class="w-8 h-8 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0 text-sm">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-extrabold text-amber-900 uppercase tracking-wide">Operación de Exportación — IGV 0%</h4>
                                <p class="text-xs text-amber-800 mt-0.5 leading-relaxed">
                                    Comprobante emitido sin impuesto general a las ventas conforme a la normativa tributaria peruana.
                                </p>
                            </div>
                        </div>

                        <!-- Card de Totales -->
                        <div class="w-full md:w-80 bg-slate-50/80 border border-slate-200/80 rounded-2xl p-5 space-y-2.5 shrink-0">
                            <div class="flex justify-between items-center text-xs text-slate-600">
                                <span>Valor de Exportación (FOB):</span>
                                <span class="font-mono font-bold text-slate-900" id="lbl_subtotal">USD 0.00</span>
                            </div>

                            <div class="flex justify-between items-center text-xs text-slate-600">
                                <span>I.G.V. (Exportación 0%):</span>
                                <span class="font-mono font-medium text-slate-400">0.00 (Exonerado)</span>
                            </div>

                            <div class="pt-3 border-t-2 border-slate-900/10 flex justify-between items-center">
                                <span class="text-sm font-extrabold text-slate-900 uppercase">Total a Pagar:</span>
                                <span class="text-xl font-mono font-black text-brand-600" id="lbl_total">USD 0.00</span>
                            </div>

                            <div class="flex justify-between items-center text-[11px] text-slate-500 pt-1 border-t border-slate-200/60">
                                <span>Equivalente en Soles (BCRP):</span>
                                <span class="font-mono font-bold text-slate-700" id="lbl_pen">S/ 0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== BOTONES DE ACCIÓN ===== -->
                <div class="flex flex-col sm:flex-row justify-end items-center gap-3 pt-2 pb-6">
                    <a href="exportaciones.php" class="w-full sm:w-auto px-6 py-2.5 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition-colors text-center text-xs">
                        Cancelar
                    </a>

                    <button type="submit" name="enviar_sunat" value="" class="w-full sm:w-auto px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 text-xs">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Solo Guardar Borrador</span>
                    </button>

                    <button type="submit" name="enviar_sunat" value="1" id="btn_emitir" class="w-full sm:w-auto px-7 py-2.5 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-700 hover:to-indigo-700 text-white font-extrabold rounded-xl transition-all shadow-md shadow-brand-500/30 flex items-center justify-center gap-2 text-xs">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Emitir y Enviar a SUNAT</span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</main>

<!-- Inclusión del componente de desplegable moderno de productos -->
<script src="js/product_dropdown.js"></script>

<script>
// Catálogo de productos para selección asistida
const catalogoProductos = <?php echo json_encode($productosCatalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

document.addEventListener('DOMContentLoaded', function () {
    const selMoneda  = document.getElementById('sel_moneda');
    const selPais    = document.getElementById('sel_pais');
    const inpPaisNom = document.getElementById('inp_pais_nombre');
    const inpTc      = document.getElementById('inp_tc');
    const tbody      = document.getElementById('items_body');
    const lblSub     = document.getElementById('lbl_subtotal');
    const lblTot     = document.getElementById('lbl_total');
    const lblPen     = document.getElementById('lbl_pen');

    // --- Sincronizar etiquetas de moneda y cálculo ---
    function actualizarMoneda() {
        const mon = selMoneda.value;
        document.querySelectorAll('.lbl-moneda').forEach(el => el.textContent = mon);
        calcularTotales();
    }
    selMoneda.addEventListener('change', actualizarMoneda);

    // --- País destino → nombre oculto y sincronización con residencia ---
    selPais.addEventListener('change', function () {
        const opt = selPais.options[selPais.selectedIndex];
        const nombre = opt.dataset.nombre || opt.text;
        inpPaisNom.value = nombre;
        const inpRes = document.getElementById('inp_cliente_pais_res');
        if (inpRes && !inpRes.value) {
            inpRes.value = nombre;
        }
    });

    // --- Selector de cliente registrado ---
    document.getElementById('sel_cliente_registrado')?.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt || !opt.value) return;
        
        const razon = opt.dataset.razon || '';
        const doc = opt.dataset.doc || '';
        const dir = opt.dataset.dir || '';

        if (razon) document.getElementById('inp_cliente_razon').value = razon;
        if (doc) document.getElementById('inp_cliente_doc').value = doc;
        if (dir && dir !== '-') document.getElementById('inp_cliente_dir').value = dir;
    });

    // --- Consulta API para Comprador / Cliente RUC / DNI ---
    document.getElementById('btn_consultar_cliente')?.addEventListener('click', async function() {
        const docInp = document.getElementById('inp_cliente_doc');
        const numDoc = (docInp.value || '').trim();
        if (!numDoc) {
            alert('Ingrese el número de RUC (11 dígitos) o DNI (8 dígitos) del cliente.');
            docInp.focus();
            return;
        }

        const btn = this;
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            const res = await fetch(`api/consultar_documento.php?numero=${encodeURIComponent(numDoc)}`);
            const data = await res.json();
            if (data.success && data.data) {
                const info = data.data;
                if (info.razon_social) document.getElementById('inp_cliente_razon').value = info.razon_social;
                if (info.direccion && info.direccion !== '-') document.getElementById('inp_cliente_dir').value = info.direccion;
                if (!document.getElementById('inp_cliente_pais_res').value) {
                    document.getElementById('inp_cliente_pais_res').value = 'Perú';
                }
                if (window.showToast) {
                    window.showToast('Datos del cliente obtenidos correctamente desde SUNAT.', 'success');
                }
            } else {
                alert(data.message || 'No se encontró información para el documento ingresado.');
            }
        } catch (e) {
            alert('Error al consultar el documento: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });

    // --- Consulta API para Agente de Aduana (RUC de 11 dígitos) ---
    async function consultarAgenteAduana() {
        const rucInp = document.getElementById('inp_ruc_agente');
        const ruc = (rucInp.value || '').trim();
        if (ruc.length !== 11) {
            alert('El RUC del Agente de Aduana debe tener 11 dígitos.');
            rucInp.focus();
            return;
        }

        const btn = document.getElementById('btn_buscar_agente_ruc');
        const orig = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        }

        try {
            const res = await fetch(`api/consultar_documento.php?tipo=RUC&numero=${encodeURIComponent(ruc)}`);
            const data = await res.json();
            if (data.success && data.data && data.data.razon_social) {
                document.getElementById('inp_nombre_agente').value = data.data.razon_social;
                if (window.showToast) {
                    window.showToast('Agente de Aduana encontrado: ' + data.data.razon_social, 'success');
                }
            } else {
                alert(data.message || 'No se encontró la razón social del Agente de Aduana.');
            }
        } catch (e) {
            alert('Error al consultar Agente de Aduana: ' + e.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }
    }

    document.getElementById('btn_buscar_agente_ruc')?.addEventListener('click', consultarAgenteAduana);

    // Autoconsulta al terminar de escribir 11 dígitos en el RUC del agente
    document.getElementById('inp_ruc_agente')?.addEventListener('blur', function() {
        if (this.value.trim().length === 11 && !document.getElementById('inp_nombre_agente').value.trim()) {
            consultarAgenteAduana();
        }
    });

    // --- Conexión oficial al BCRP para Tipo de Cambio ---
    async function sincronizarTC_BCRP() {
        const btn = document.getElementById('btn_tc_bcrp');
        const txt = document.getElementById('btn_tc_bcrp_txt');
        const btnTop = document.getElementById('btn_tc_bcrp_top');

        if (btn) {
            btn.disabled = true;
            if (txt) txt.textContent = 'Conectando...';
            btn.querySelector('i')?.classList.add('fa-spin');
        }
        if (btnTop) btnTop.querySelector('i')?.classList.add('fa-spin');

        try {
            const res = await fetch('api/tipo_cambio_bcrp.php');
            const data = await res.json();
            if (data.success && data.tc) {
                const tcNum = parseFloat(data.tc).toFixed(4);
                inpTc.value = tcNum;
                calcularTotales();

                if (window.showToast) {
                    window.showToast(`Tipo de cambio actualizado con BCRP: S/ ${tcNum} (${data.fecha || ''})`, 'success', 'BCRP Oficial');
                } else {
                    alert(`Tipo de cambio BCRP actualizado: S/ ${tcNum} (${data.fecha || ''})`);
                }
            } else {
                alert(data.message || 'No se pudo obtener el tipo de cambio del BCRP.');
            }
        } catch (e) {
            alert('Error de conexión con el BCRP: ' + e.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                if (txt) txt.textContent = 'Actualizar BCRP';
                btn.querySelector('i')?.classList.remove('fa-spin');
            }
            if (btnTop) btnTop.querySelector('i')?.classList.remove('fa-spin');
        }
    }

    document.getElementById('btn_tc_bcrp')?.addEventListener('click', sincronizarTC_BCRP);
    document.getElementById('btn_tc_bcrp_top')?.addEventListener('click', sincronizarTC_BCRP);

    // --- Cálculo de Totales y Equivalencia en Soles ---
    function calcularTotales() {
        let subtotal = 0;
        tbody.querySelectorAll('.item-row').forEach(row => {
            const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            const tot   = qty * price;
            const totCell = row.querySelector('.item-total');
            if (totCell) totCell.textContent = tot.toFixed(2);
            subtotal += tot;
        });

        const mon = selMoneda.value;
        const tc  = parseFloat(inpTc.value) || 1;

        lblSub.textContent = mon + ' ' + subtotal.toFixed(2);
        lblTot.textContent = mon + ' ' + subtotal.toFixed(2);

        // Equivalente en PEN
        const totalPen = (mon === 'PEN') ? subtotal : (subtotal * tc);
        lblPen.textContent = 'S/ ' + totalPen.toFixed(2);
    }

    // --- Inicializar componente de autocompletado en fila ---
    function initRowProductDropdown(rowElement) {
        const wrapper = rowElement.querySelector('.product-dropdown-wrapper');
        if (!wrapper) return;

        attachProductDropdown(wrapper, catalogoProductos, function (product, row) {
            if (!product || !row) return;

            // 1. Descripción
            const descInp = row.querySelector('.item-desc');
            if (descInp) descInp.value = product.descripcion;

            // 2. Unidad de medida
            const unitSel = row.querySelector('select[name="unidad[]"]');
            if (unitSel && product.unidad_medida) {
                let found = false;
                for (let i = 0; i < unitSel.options.length; i++) {
                    if (unitSel.options[i].value === product.unidad_medida) {
                        unitSel.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const opt = new Option(product.unidad_medida, product.unidad_medida, true, true);
                    unitSel.add(opt);
                }
            }

            // 3. Precio Unitario (convertido a USD si corresponde)
            const priceInp = row.querySelector('.item-price');
            if (priceInp && product.precio_unitario !== undefined) {
                const precioBase = parseFloat(product.precio_unitario) || 0;
                const moneda = selMoneda.value;
                const tc = parseFloat(inpTc.value) || 3.4282;

                if (moneda === 'USD' && tc > 0) {
                    priceInp.value = (precioBase / tc).toFixed(2);
                } else {
                    priceInp.value = precioBase.toFixed(2);
                }
            }

            // 4. Partida Arancelaria
            const partidaInp = row.querySelector('input[name="partida[]"]');
            if (partidaInp && product.codigo_partida) {
                partidaInp.value = product.codigo_partida;
            }

            calcularTotales();
        });
    }

    // Inicializar filas existentes
    tbody.querySelectorAll('.item-row').forEach(initRowProductDropdown);

    // --- Agregar nueva fila con desplegable moderno ---
    document.getElementById('btn_add_row').addEventListener('click', function () {
        const tr = document.createElement('tr');
        tr.className = 'item-row hover:bg-slate-50/50 transition-colors';
        tr.innerHTML = `
            <td class="px-2 py-2.5 text-center">
                <input type="number" name="qty[]" value="1" min="0.001" step="0.001" class="item-qty w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono font-bold text-center focus:outline-none focus:border-brand-500" required>
            </td>
            <td class="px-2 py-2.5 relative">
                <div class="product-dropdown-wrapper relative w-full">
                    <div class="relative flex items-center">
                        <input type="text" name="desc[]" placeholder="Escribir producto o buscar en catálogo..." class="item-desc w-full bg-white border border-slate-200 rounded-xl pl-3 pr-8 py-2 text-xs font-semibold text-slate-800 focus:outline-none focus:border-brand-500" autocomplete="off" required>
                        <button type="button" class="btn-toggle-dropdown absolute right-2.5 text-slate-400 hover:text-brand-600 transition-colors p-1" title="Ver catálogo de productos">
                            <i class="fa-solid fa-chevron-down text-[11px] transition-transform duration-200"></i>
                        </button>
                    </div>
                    <div class="product-dropdown-menu hidden absolute left-0 top-full mt-1.5 z-50 min-w-[320px] max-w-[460px] w-full bg-white border border-slate-200 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                        <div class="px-3.5 py-2.5 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-5 h-5 rounded-md bg-brand-500/10 text-brand-600 flex items-center justify-center text-[10px]">
                                    <i class="fa-solid fa-boxes-stacked"></i>
                                </div>
                                <span class="text-[11px] font-extrabold text-slate-800">Gestión de Ventas</span>
                            </div>
                            <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-brand-50 text-brand-600">
                                ${catalogoProductos.length} ítems
                            </span>
                        </div>
                        <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                        <div class="px-3 py-2 bg-slate-50/60 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-400">
                            <span><i class="fa-regular fa-keyboard mr-1"></i> Enter para producto libre</span>
                            <a href="productos.php" target="_blank" class="text-brand-600 hover:underline flex items-center gap-1 font-semibold">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                            </a>
                        </div>
                    </div>
                </div>
            </td>
            <td class="px-2 py-2.5 text-center">
                <select name="unidad[]" class="item-unit w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-bold text-slate-700 focus:outline-none focus:border-brand-500">
                    <option value="KGM">KG — Kilogramos</option>
                    <option value="TNE">TNE — Toneladas</option>
                    <option value="NIU" selected>NIU — Unidades</option>
                    <option value="LTR">LTR — Litros</option>
                    <option value="MTR">MTR — Metros</option>
                    <option value="ZZ">ZZ — Servicios / Otros</option>
                </select>
            </td>
            <td class="px-2 py-2.5 text-right">
                <input type="number" name="price[]" placeholder="0.00" min="0" step="0.01" class="item-price w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono font-bold text-right text-slate-800 focus:outline-none focus:border-brand-500" required>
            </td>
            <td class="px-2 py-2.5">
                <input type="text" name="partida[]" placeholder="0901.21.00.00" list="dl_partidas" class="item-partida w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono font-semibold text-slate-800 focus:outline-none focus:border-brand-500" pattern="^(\d{4}\.\d{2}\.\d{2}\.\d{2}|\d{10})?$" title="Formato arancelario: 0000.00.00.00">
            </td>
            <td class="px-2 py-2.5 text-right">
                <input type="number" name="peso_bruto[]" placeholder="0.000" min="0" step="0.001" class="w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono text-right text-slate-800 focus:outline-none focus:border-brand-500">
            </td>
            <td class="px-2 py-2.5 text-right">
                <input type="number" name="peso_neto[]" placeholder="0.000" min="0" step="0.001" class="w-full bg-white border border-slate-200 rounded-xl p-2 text-xs font-mono text-right text-slate-800 focus:outline-none focus:border-brand-500">
            </td>
            <td class="px-3 py-2.5 text-right font-mono font-extrabold text-slate-900 item-total whitespace-nowrap">
                0.00
            </td>
            <td class="px-2 py-2.5 text-center btn-remove cursor-pointer text-slate-400 hover:text-rose-600 transition-colors" title="Quitar ítem">
                <i class="fa-solid fa-trash pointer-events-none text-xs"></i>
            </td>
        `;
        tbody.appendChild(tr);
        initRowProductDropdown(tr);
        calcularTotales();
    });

    // --- Delegación de eventos para inputs numéricos y eliminación ---
    tbody.addEventListener('input', function (e) {
        if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) {
            calcularTotales();
        }
    });

    inpTc.addEventListener('input', calcularTotales);

    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-remove') || e.target.closest('.btn-remove')) {
            if (tbody.querySelectorAll('.item-row').length > 1) {
                e.target.closest('.item-row').remove();
                calcularTotales();
            } else {
                alert('Debe haber al menos una línea en el comprobante.');
            }
        }
    });

    // --- Selector Rápido de Gestión de Ventas (Top) ---
    document.getElementById('sel_quick_product')?.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        if (!opt || !opt.value) return;

        const desc = opt.value;
        const priceBase = parseFloat(opt.dataset.price) || 0;
        const unit = opt.dataset.unit || 'NIU';
        const partida = opt.dataset.partida || '';
        const moneda = selMoneda.value;
        const tc = parseFloat(inpTc.value) || 3.4282;

        let finalPrice = priceBase;
        if (moneda === 'USD' && tc > 0) {
            finalPrice = (priceBase / tc).toFixed(2);
        } else {
            finalPrice = priceBase.toFixed(2);
        }

        // Si la primera fila está vacía, rellenar esa
        const rows = tbody.querySelectorAll('.item-row');
        let targetRow = null;
        if (rows.length === 1 && !rows[0].querySelector('.item-desc').value.trim()) {
            targetRow = rows[0];
        } else {
            // Sino, agregar una nueva fila
            document.getElementById('btn_add_row').click();
            const updatedRows = tbody.querySelectorAll('.item-row');
            targetRow = updatedRows[updatedRows.length - 1];
        }

        if (targetRow) {
            targetRow.querySelector('.item-desc').value = desc;
            targetRow.querySelector('.item-price').value = finalPrice;
            const unitSel = targetRow.querySelector('select[name="unidad[]"]');
            if (unitSel) {
                for (let i = 0; i < unitSel.options.length; i++) {
                    if (unitSel.options[i].value === unit) {
                        unitSel.selectedIndex = i;
                        break;
                    }
                }
            }
            if (partida) {
                targetRow.querySelector('input[name="partida[]"]').value = partida;
            }
            calcularTotales();
        }

        // Resetear selector rápido
        this.selectedIndex = 0;
    });

    // Calcular totales iniciales
    calcularTotales();
});

// Función para rellenar la última fila con una partida sugerida
function rellenarUltimaPartida(codigo) {
    const inputs = document.querySelectorAll('input[name="partida[]"]');
    if (inputs.length > 0) {
        const last = inputs[inputs.length - 1];
        last.value = codigo;
        last.classList.add('ring-2', 'ring-brand-500');
        setTimeout(() => last.classList.remove('ring-2', 'ring-brand-500'), 500);
    }
}

// Función para aplicar presets de logística rápida
function aplicarPresetLogistica(preset) {
    const selAduana = document.getElementById('sel_aduana');
    const inpPuerto = document.getElementById('inp_puerto');
    const selInco = document.getElementById('sel_incoterm');

    if (preset === 'callao_fob') {
        if (selAduana) selAduana.value = 'Callao';
        if (inpPuerto) inpPuerto.value = 'Puerto del Callao';
        if (selInco) selInco.value = 'FOB';
    } else if (preset === 'aereo_fca') {
        if (selAduana) selAduana.value = 'Callao';
        if (inpPuerto) inpPuerto.value = 'Aeropuerto Internacional Jorge Chávez';
        if (selInco) selInco.value = 'FCA';
    } else if (preset === 'paita_fob') {
        if (selAduana) selAduana.value = 'Paita';
        if (inpPuerto) inpPuerto.value = 'Puerto de Paita';
        if (selInco) selInco.value = 'FOB';
    }

    if (window.showToast) {
        window.showToast('Preset logístico aplicado.', 'info');
    }
}
</script>

<?php include 'includes/footer.php'; ?>
