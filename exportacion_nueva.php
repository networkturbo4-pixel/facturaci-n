<?php
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg   = '';

// Obtener tipo de cambio SUNAT con caché local en la base de datos
function obtenerTipoCambio() {
    global $conn;
    $hoy = date('Y-m-d');
    $fechaCache = getSystemConfig('tipo_cambio_fecha');
    $tcCache = getSystemConfig('tipo_cambio_cache');

    if ($fechaCache === $hoy && !empty($tcCache) && (float)$tcCache > 0) {
        return (float)$tcCache;
    }

    $url = 'https://api.apis.net.pe/v2/sunat/tipo-cambio';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data['venta'])) {
            $venta = (float)$data['venta'];
            try {
                $upd = $conn->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                $upd->execute(['tipo_cambio_cache', (string)$venta]);
                $upd->execute(['tipo_cambio_fecha', $hoy]);
            } catch (Exception $e) {}
            return $venta;
        }
    }
    return !empty($tcCache) ? (float)$tcCache : 3.75;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o sesión expirada. Intente de nuevo.";
    } else {
        $serie       = 'E001';
        $fecha       = $_POST['fecha_emision'] ?? date('Y-m-d');
        $moneda      = $_POST['moneda'] ?? 'USD';
        $tipo_cambio = (float)($_POST['tipo_cambio'] ?? 3.75);

        // Datos cliente
        $razon        = trim($_POST['cliente_razon_social'] ?? '');
        $dir          = trim($_POST['cliente_direccion'] ?? '-');
        $pais_res     = trim($_POST['cliente_pais_residencia'] ?? '');

        // Datos aduaneros
        $pais_codigo  = $_POST['pais_destino_codigo'] ?? '';
        $pais_nombre  = $_POST['pais_destino_nombre'] ?? '';
        $incoterm     = $_POST['incoterm'] ?? '';
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
            $error_msg = "Complete los datos del comprador, país destino y al menos un producto.";
        } else {
            try {
                $conn->beginTransaction();

                $subtotal = 0;
                foreach ($qtys as $i => $q) {
                    $subtotal += (float)$q * (float)($prices[$i] ?? 0);
                }
                $igv   = 0; // Exportación exonerada
                $total = $subtotal;

                $stmt = $conn->prepare("SELECT MAX(correlativo) as max_corr FROM comprobantes WHERE serie = ?");
                $stmt->execute([$serie]);
                $row = $stmt->fetch();
                $correlativo = ($row['max_corr'] ?? 0) + 1;

                $token_publico = bin2hex(random_bytes(16));

                // Insertar comprobante
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
                    '-', $razon, $dir,
                    $subtotal, $igv, $total,
                    $moneda, $tipo_cambio, 1,
                    $pais_codigo, $pais_nombre, $incoterm,
                    $puerto, $aduana, $numero_dam ?: null,
                    $numero_dam ? 'En Proceso' : 'Sin DAM',
                    $ruc_agente ?: null, $nom_agente ?: null, $pais_res,
                    'Pendiente SUNAT'
                ]);

                $comprobante_id = $conn->lastInsertId();

                // Insertar líneas
                $stmt_line = $conn->prepare("INSERT INTO comprobante_lineas
                    (comprobante_id, cantidad, descripcion, precio_unitario, total_linea)
                    VALUES (?,?,?,?,?)");

                $stmt_part = $conn->prepare("INSERT INTO exportacion_partidas
                    (comprobante_id, linea_num, codigo_partida, unidad_medida, peso_bruto, peso_neto)
                    VALUES (?,?,?,?,?,?)");

                foreach ($qtys as $i => $q) {
                    $d  = $descs[$i]  ?? '';
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
                $success_msg = "Factura de Exportación $serie-" . str_pad($correlativo, 6, "0", STR_PAD_LEFT) . " generada. ID: $comprobante_id";

                // Si se solicita enviar a SUNAT directamente
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
                $error_msg = "Error: " . $e->getMessage();
            }
        }
    }
}

$tipo_cambio_actual = obtenerTipoCambio();
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

    <div class="flex-1 overflow-auto p-4 sm:p-6">
        <div class="max-w-5xl mx-auto">

            <!-- Header con badge aduanero -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-2xl font-bold text-gray-800">Factura de Exportación</h3>
                    <p class="text-sm text-gray-500 mt-1">Operación exonerada de IGV — Art. 33° TUO del IGV | Serie <strong>E001</strong></p>
                </div>
                <div class="flex gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                        <i class="fa-solid fa-ship"></i> Exportación
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Nubefact / SUNAT
                    </span>
                </div>
            </div>

            <?php if ($success_msg): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg flex items-center gap-3">
                <i class="fa-solid fa-circle-check text-green-500 text-xl shrink-0"></i>
                <div>
                    <p class="font-medium"><?php echo htmlspecialchars($success_msg); ?></p>
                    <a href="exportaciones.php" class="text-sm text-green-700 underline">Ver mis exportaciones →</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation text-red-500 text-xl shrink-0"></i>
                <p><?php echo htmlspecialchars($error_msg); ?></p>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="form_exportacion">
            <?php echo csrf_field(); ?>

            <!-- ===== SECCIÓN A: COMPROBANTE ===== -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-5 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3 bg-slate-50">
                    <span class="w-7 h-7 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">A</span>
                    <h4 class="font-semibold text-gray-800">Datos del Comprobante</h4>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Tipo</label>
                        <input type="text" value="Factura de Exportación" class="w-full bg-gray-100 border border-gray-200 rounded-lg p-2.5 text-sm text-gray-700 font-medium" readonly>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Serie</label>
                        <input type="text" value="E001" class="w-full bg-gray-100 border border-gray-200 rounded-lg p-2.5 text-sm text-gray-700 font-bold" readonly>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Fecha de Emisión</label>
                        <input type="date" name="fecha_emision" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Moneda</label>
                        <select name="moneda" id="sel_moneda" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="USD" selected>🇺🇸 USD — Dólar Americano</option>
                            <option value="EUR">🇪🇺 EUR — Euro</option>
                            <option value="GBP">🇬🇧 GBP — Libra Esterlina</option>
                            <option value="PEN">🇵🇪 PEN — Sol Peruano</option>
                        </select>
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                            Tipo de Cambio (PEN / moneda)
                            <button type="button" id="btn_tc_bcrp" class="ml-2 text-blue-600 text-xs font-normal underline hover:text-blue-800">
                                <i class="fa-solid fa-rotate-right mr-1"></i>Actualizar desde BCRP
                            </button>
                        </label>
                        <div class="flex items-center gap-3">
                            <input type="number" name="tipo_cambio" id="inp_tc" value="<?php echo $tipo_cambio_actual; ?>" step="0.0001" min="0.0001" class="w-40 bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                            <span class="text-xs text-gray-400">Fuente: BCRP — actualizado automáticamente</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== SECCIÓN B: COMPRADOR EXTRANJERO ===== -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-5 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3 bg-slate-50">
                    <span class="w-7 h-7 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">B</span>
                    <h4 class="font-semibold text-gray-800">Comprador Extranjero (No Domiciliado)</h4>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Razón Social / Nombre del Comprador *</label>
                        <input type="text" name="cliente_razon_social" placeholder="Ej: BUYER COMPANY LLC" required class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Dirección del Comprador</label>
                        <input type="text" name="cliente_direccion" placeholder="Ej: 123 Main St, New York, NY" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">País de Residencia del Comprador</label>
                        <input type="text" name="cliente_pais_residencia" placeholder="Ej: Estados Unidos" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="md:col-span-2 bg-blue-50 border border-blue-100 rounded-lg p-3 flex items-start gap-2">
                        <i class="fa-solid fa-circle-info text-blue-500 mt-0.5 shrink-0"></i>
                        <p class="text-xs text-blue-700">En exportaciones, el tipo de documento del comprador se coloca como <strong>"-"</strong> (No Domiciliado) según la normativa SUNAT — R.S. 117-2017/SUNAT.</p>
                    </div>
                </div>
            </div>

            <!-- ===== SECCIÓN C: DATOS ADUANEROS ===== -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-5 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3 bg-slate-50">
                    <span class="w-7 h-7 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">C</span>
                    <h4 class="font-semibold text-gray-800">Datos Aduaneros — SUNAT / ADUANAS</h4>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">País Destino *</label>
                        <select name="pais_destino_codigo" id="sel_pais" required class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Seleccione país --</option>
                            <option value="US" data-nombre="Estados Unidos">🇺🇸 Estados Unidos</option>
                            <option value="CN" data-nombre="China">🇨🇳 China</option>
                            <option value="JP" data-nombre="Japón">🇯🇵 Japón</option>
                            <option value="DE" data-nombre="Alemania">🇩🇪 Alemania</option>
                            <option value="ES" data-nombre="España">🇪🇸 España</option>
                            <option value="GB" data-nombre="Reino Unido">🇬🇧 Reino Unido</option>
                            <option value="FR" data-nombre="Francia">🇫🇷 Francia</option>
                            <option value="CA" data-nombre="Canadá">🇨🇦 Canadá</option>
                            <option value="AU" data-nombre="Australia">🇦🇺 Australia</option>
                            <option value="BR" data-nombre="Brasil">🇧🇷 Brasil</option>
                            <option value="CL" data-nombre="Chile">🇨🇱 Chile</option>
                            <option value="CO" data-nombre="Colombia">🇨🇴 Colombia</option>
                            <option value="MX" data-nombre="México">🇲🇽 México</option>
                            <option value="AR" data-nombre="Argentina">🇦🇷 Argentina</option>
                            <option value="NL" data-nombre="Países Bajos">🇳🇱 Países Bajos</option>
                            <option value="BE" data-nombre="Bélgica">🇧🇪 Bélgica</option>
                            <option value="IT" data-nombre="Italia">🇮🇹 Italia</option>
                            <option value="KR" data-nombre="Corea del Sur">🇰🇷 Corea del Sur</option>
                            <option value="IN" data-nombre="India">🇮🇳 India</option>
                            <option value="SG" data-nombre="Singapur">🇸🇬 Singapur</option>
                            <option value="HK" data-nombre="Hong Kong">🇭🇰 Hong Kong</option>
                            <option value="CH" data-nombre="Suiza">🇨🇭 Suiza</option>
                            <option value="SE" data-nombre="Suecia">🇸🇪 Suecia</option>
                            <option value="NO" data-nombre="Noruega">🇳🇴 Noruega</option>
                            <option value="DK" data-nombre="Dinamarca">🇩🇰 Dinamarca</option>
                            <option value="TR" data-nombre="Turquía">🇹🇷 Turquía</option>
                            <option value="ZA" data-nombre="Sudáfrica">🇿🇦 Sudáfrica</option>
                            <option value="BO" data-nombre="Bolivia">🇧🇴 Bolivia</option>
                            <option value="EC" data-nombre="Ecuador">🇪🇨 Ecuador</option>
                            <option value="PY" data-nombre="Paraguay">🇵🇾 Paraguay</option>
                            <option value="UY" data-nombre="Uruguay">🇺🇾 Uruguay</option>
                            <option value="VE" data-nombre="Venezuela">🇻🇪 Venezuela</option>
                            <option value="PA" data-nombre="Panamá">🇵🇦 Panamá</option>
                            <option value="GT" data-nombre="Guatemala">🇬🇹 Guatemala</option>
                            <option value="CR" data-nombre="Costa Rica">🇨🇷 Costa Rica</option>
                        </select>
                        <input type="hidden" name="pais_destino_nombre" id="inp_pais_nombre">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Incoterm</label>
                        <select name="incoterm" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Seleccione --</option>
                            <optgroup label="Cualquier modo de transporte">
                                <option value="EXW">EXW — Ex Works</option>
                                <option value="FCA">FCA — Free Carrier</option>
                                <option value="CPT">CPT — Carriage Paid To</option>
                                <option value="CIP">CIP — Carriage & Insurance Paid</option>
                                <option value="DAP">DAP — Delivered At Place</option>
                                <option value="DPU">DPU — Delivered at Place Unloaded</option>
                                <option value="DDP">DDP — Delivered Duty Paid</option>
                            </optgroup>
                            <optgroup label="Transporte marítimo/fluvial">
                                <option value="FAS">FAS — Free Alongside Ship</option>
                                <option value="FOB" selected>FOB — Free On Board</option>
                                <option value="CFR">CFR — Cost and Freight</option>
                                <option value="CIF">CIF — Cost, Insurance and Freight</option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Aduana de Partida</label>
                        <select name="aduana_partida" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Seleccione --</option>
                            <option value="Callao">Callao (Marítima y Aérea)</option>
                            <option value="Paita">Paita</option>
                            <option value="Ilo">Ilo</option>
                            <option value="Tacna">Tacna</option>
                            <option value="Tumbes">Tumbes</option>
                            <option value="Puno">Puno</option>
                            <option value="Salaverry">Salaverry</option>
                            <option value="Pisco">Pisco</option>
                            <option value="Chimbote">Chimbote</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Puerto de Embarque</label>
                        <input type="text" name="puerto_embarque" placeholder="Ej: Puerto del Callao" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                            N° DAM (Declaración Aduanera)
                            <span class="text-gray-400 font-normal">(Opcional al emitir)</span>
                        </label>
                        <input type="text" name="numero_dam" placeholder="Ej: 118-2025-10-123456" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Agente de Aduana — Nombre</label>
                        <input type="text" name="nombre_agente_aduana" placeholder="Ej: AGENCIA ADUANERA XYZ SAC" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Agente de Aduana — RUC</label>
                        <input type="text" name="ruc_agente_aduana" maxlength="11" placeholder="20XXXXXXXXX" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
            </div>

            <!-- ===== SECCIÓN D: MERCANCÍAS ===== -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-5 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3 bg-slate-50">
                    <span class="w-7 h-7 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">D</span>
                    <h4 class="font-semibold text-gray-800">Detalle de Mercancías y Partidas Arancelarias</h4>
                </div>
                <div class="p-6">
                    <div class="overflow-x-auto mb-4 border border-gray-100 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[900px]">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 text-xs uppercase tracking-wider">
                                    <th class="px-3 py-3 font-semibold w-16">Cant.</th>
                                    <th class="px-3 py-3 font-semibold">Descripción</th>
                                    <th class="px-3 py-3 font-semibold w-28">Unidad</th>
                                    <th class="px-3 py-3 font-semibold w-32">P. Unit. (<span class="lbl-moneda">USD</span>)</th>
                                    <th class="px-3 py-3 font-semibold w-28">Partida SA</th>
                                    <th class="px-3 py-3 font-semibold w-24">Peso Bruto</th>
                                    <th class="px-3 py-3 font-semibold w-24">Peso Neto</th>
                                    <th class="px-3 py-3 font-semibold w-28">Total (<span class="lbl-moneda">USD</span>)</th>
                                    <th class="px-3 py-3 w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="items_body">
                                <tr class="border-b border-gray-100 item-row">
                                    <td class="px-3 py-2"><input type="number" name="qty[]" value="1" min="1" step="0.001" class="item-qty w-full border border-gray-300 rounded p-1.5 text-sm text-center" required></td>
                                    <td class="px-3 py-2"><input type="text" name="desc[]" placeholder="Descripción de la mercancía..." class="item-desc w-full border border-gray-300 rounded p-1.5 text-sm" required></td>
                                    <td class="px-3 py-2">
                                        <select name="unidad[]" class="w-full border border-gray-300 rounded p-1.5 text-xs">
                                            <option value="KGM">KG</option>
                                            <option value="TNE">TN</option>
                                            <option value="LTR">LT</option>
                                            <option value="NIU" selected>UND</option>
                                            <option value="MTR">MT</option>
                                            <option value="ZZ">Otros</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2"><input type="number" name="price[]" placeholder="0.00" min="0" step="0.01" class="item-price w-full border border-gray-300 rounded p-1.5 text-sm text-right" required></td>
                                    <td class="px-3 py-2"><input type="text" name="partida[]" placeholder="0901.21.00.00" class="w-full border border-gray-300 rounded p-1.5 text-xs font-mono" pattern="\d{4}\.\d{2}\.\d{2}\.\d{2}" title="Formato: 0000.00.00.00"></td>
                                    <td class="px-3 py-2"><input type="number" name="peso_bruto[]" placeholder="0.000" min="0" step="0.001" class="w-full border border-gray-300 rounded p-1.5 text-sm text-right"></td>
                                    <td class="px-3 py-2"><input type="number" name="peso_neto[]" placeholder="0.000" min="0" step="0.001" class="w-full border border-gray-300 rounded p-1.5 text-sm text-right"></td>
                                    <td class="px-3 py-2 text-right font-semibold text-gray-800 item-total whitespace-nowrap text-sm">0.00</td>
                                    <td class="px-3 py-2 text-red-400 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none text-xs"></i></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="btn_add_row" class="text-blue-600 text-sm font-medium hover:text-blue-700 flex items-center gap-1">
                        <i class="fa-solid fa-plus"></i> Agregar Línea
                    </button>
                </div>
            </div>

            <!-- ===== SECCIÓN E: TOTALES ===== -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-5 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3 bg-slate-50">
                    <span class="w-7 h-7 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">E</span>
                    <h4 class="font-semibold text-gray-800">Resumen de Totales</h4>
                </div>
                <div class="p-6 flex flex-col md:flex-row md:justify-between gap-6">
                    <div class="flex-1 bg-amber-50 border border-amber-100 rounded-lg p-4 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5 shrink-0"></i>
                        <div>
                            <p class="text-sm font-semibold text-amber-800">Operación de Exportación — IGV 0%</p>
                            <p class="text-xs text-amber-700 mt-1">Las exportaciones están exoneradas del IGV según el Art. 33° del TUO de la Ley del IGV e ISC. El comprobante se emitirá sin impuesto.</p>
                        </div>
                    </div>
                    <div class="w-full md:w-72 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Valor de Exportación:</span>
                            <span class="font-semibold" id="lbl_subtotal">0.00</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">IGV (Exportación):</span>
                            <span class="font-semibold text-gray-400">0.00 (Exonerado)</span>
                        </div>
                        <div class="flex justify-between border-t border-gray-200 pt-2 mt-2">
                            <span class="font-bold text-gray-800 text-lg">Total:</span>
                            <span class="font-bold text-blue-600 text-lg" id="lbl_total">0.00</span>
                        </div>
                        <div class="flex justify-between text-xs text-gray-400">
                            <span>Equivalente PEN:</span>
                            <span id="lbl_pen">S/ 0.00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== BOTONES ===== -->
            <div class="flex flex-col sm:flex-row justify-end gap-3">
                <a href="exportaciones.php" class="px-6 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors text-center">
                    Cancelar
                </a>
                <button type="submit" name="enviar_sunat" value="" class="px-6 py-2.5 bg-gray-700 text-white font-medium rounded-lg hover:bg-gray-800 transition-colors flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Solo Guardar Borrador
                </button>
                <button type="submit" name="enviar_sunat" value="1" id="btn_emitir" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all shadow-md flex items-center justify-center gap-2">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Emitir y Enviar a SUNAT
                </button>
            </div>

            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selMoneda  = document.getElementById('sel_moneda');
    const selPais    = document.getElementById('sel_pais');
    const inpPaisNom = document.getElementById('inp_pais_nombre');
    const inpTc      = document.getElementById('inp_tc');
    const tbody      = document.getElementById('items_body');
    const lblSub     = document.getElementById('lbl_subtotal');
    const lblTot     = document.getElementById('lbl_total');
    const lblPen     = document.getElementById('lbl_pen');

    // --- Actualizar etiquetas de moneda ---
    function actualizarMoneda() {
        document.querySelectorAll('.lbl-moneda').forEach(el => el.textContent = selMoneda.value);
        calcularTotales();
    }
    selMoneda.addEventListener('change', actualizarMoneda);

    // --- País destino → nombre oculto ---
    selPais.addEventListener('change', function () {
        const opt = selPais.options[selPais.selectedIndex];
        inpPaisNom.value = opt.dataset.nombre || opt.text;
    });

    // --- Tipo de cambio BCRP ---
    document.getElementById('btn_tc_bcrp').addEventListener('click', async function () {
        this.disabled = true;
        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Obteniendo...';
        try {
            const res  = await fetch('https://api.apis.net.pe/v2/sunat/tipo-cambio');
            const data = await res.json();
            if (data.venta) {
                inpTc.value = parseFloat(data.venta).toFixed(4);
                calcularTotales();
            }
        } catch (e) { alert('No se pudo obtener el tipo de cambio del BCRP.'); }
        this.disabled = false;
        this.innerHTML = '<i class="fa-solid fa-rotate-right mr-1"></i>Actualizar desde BCRP';
    });

    // --- Cálculo de totales ---
    function calcularTotales() {
        let subtotal = 0;
        tbody.querySelectorAll('.item-row').forEach(row => {
            const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            const tot   = qty * price;
            row.querySelector('.item-total').textContent = tot.toFixed(2);
            subtotal += tot;
        });
        const tc = parseFloat(inpTc.value) || 1;
        lblSub.textContent = selMoneda.value + ' ' + subtotal.toFixed(2);
        lblTot.textContent = selMoneda.value + ' ' + subtotal.toFixed(2);
        lblPen.textContent = 'S/ ' + (subtotal * tc).toFixed(2);
    }

    // --- Agregar fila ---
    document.getElementById('btn_add_row').addEventListener('click', function () {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-gray-100 item-row';
        tr.innerHTML = `
            <td class="px-3 py-2"><input type="number" name="qty[]" value="1" min="1" step="0.001" class="item-qty w-full border border-gray-300 rounded p-1.5 text-sm text-center" required></td>
            <td class="px-3 py-2"><input type="text" name="desc[]" placeholder="Descripción de la mercancía..." class="item-desc w-full border border-gray-300 rounded p-1.5 text-sm" required></td>
            <td class="px-3 py-2">
                <select name="unidad[]" class="w-full border border-gray-300 rounded p-1.5 text-xs">
                    <option value="KGM">KG</option>
                    <option value="TNE">TN</option>
                    <option value="LTR">LT</option>
                    <option value="NIU" selected>UND</option>
                    <option value="MTR">MT</option>
                    <option value="ZZ">Otros</option>
                </select>
            </td>
            <td class="px-3 py-2"><input type="number" name="price[]" placeholder="0.00" min="0" step="0.01" class="item-price w-full border border-gray-300 rounded p-1.5 text-sm text-right" required></td>
            <td class="px-3 py-2"><input type="text" name="partida[]" placeholder="0901.21.00.00" class="w-full border border-gray-300 rounded p-1.5 text-xs font-mono"></td>
            <td class="px-3 py-2"><input type="number" name="peso_bruto[]" placeholder="0.000" min="0" step="0.001" class="w-full border border-gray-300 rounded p-1.5 text-sm text-right"></td>
            <td class="px-3 py-2"><input type="number" name="peso_neto[]" placeholder="0.000" min="0" step="0.001" class="w-full border border-gray-300 rounded p-1.5 text-sm text-right"></td>
            <td class="px-3 py-2 text-right font-semibold text-gray-800 item-total whitespace-nowrap text-sm">0.00</td>
            <td class="px-3 py-2 text-red-400 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none text-xs"></i></td>
        `;
        tbody.appendChild(tr);
    });

    // --- Delegación de eventos ---
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
                alert('Debe haber al menos una línea.');
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
