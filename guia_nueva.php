<?php 
require_once 'includes/auth.php';
requireLogin();

// Cargar catálogo de productos activos para autocompletado y selección rápida
$productosCatalogo = [];
try {
    $stmtProd = $conn->query("SELECT id, codigo, descripcion, unidad_medida, precio_unitario, stock, stock_minimo, lote_actual FROM productos WHERE estado = 'Activo' ORDER BY descripcion ASC");
    if ($stmtProd) {
        $productosCatalogo = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

include 'includes/header.php'; 
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php
    $pageTitle = 'Nueva Guía de Remisión';
    $topbarAction = [
        'label' => 'Ver Guías',
        'icon'  => 'fa-solid fa-truck-fast text-xs',
        'url'   => 'guias.php'
    ];
    include 'includes/topbar.php';
    ?>

    <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
        <div class="max-w-5xl mx-auto">
            <h3 class="text-2xl font-bold text-gray-800 mb-6">Emitir Guía de Remisión Electrónica</h3>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden p-8">
                
                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">1. Datos Generales</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Motivo del Traslado</label>
                        <select class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                            <option>Venta</option>
                            <option>Venta sujeta a confirmación</option>
                            <option>Compra</option>
                            <option>Traslado entre establecimientos de la misma empresa</option>
                            <option>Devolución</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de Emisión / Traslado</label>
                        <input type="date" value="<?php echo date('Y-m-d'); ?>" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Peso Bruto Total (KGM)</label>
                        <input type="number" step="0.01" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary" placeholder="Ej. 15.50">
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">2. Datos del Destinatario</h4>
                <div class="mb-8">
                    <div class="flex gap-4">
                        <div class="flex-1 relative flex">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <i class="fa-solid fa-magnifying-glass text-gray-400"></i>
                            </div>
                            <input type="text" id="num_doc" class="flex-1 bg-white border border-gray-300 rounded-l-lg pl-10 p-2.5 text-sm focus:ring-primary focus:border-primary shadow-sm" placeholder="Ingrese RUC o DNI...">
                            <button type="button" id="btn_buscar_doc" class="bg-gray-100 hover:bg-gray-200 border border-l-0 border-gray-300 px-4 rounded-r-lg text-sm font-medium text-gray-600 flex items-center transition-colors">
                                <i id="icon_buscar" class="fa-solid fa-magnifying-glass mr-2"></i> <span id="text_buscar">Buscar</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 hidden" id="datos_cliente_encontrado">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Razón Social / Nombres</label>
                            <input type="text" id="razon_social" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-sm text-gray-800 focus:border-brand-500 focus:outline-none" placeholder="Editable...">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm text-gray-600">Dirección (Llegada sugerida)</label>
                                <button type="button" id="btn_usar_dir_dest" class="text-[11px] font-bold text-emerald-600 hover:underline">
                                    <i class="fa-solid fa-arrow-down mr-1"></i>Copiar a Punto de Llegada
                                </button>
                            </div>
                            <input type="text" id="direccion" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-sm text-gray-800 focus:border-brand-500 focus:outline-none" placeholder="Editable...">
                        </div>
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">3. Direcciones de Traslado</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- PUNTO DE PARTIDA -->
                    <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-200/80 space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <h5 class="font-bold text-blue-900 text-sm"><i class="fa-solid fa-location-dot mr-1.5 text-blue-600"></i>Punto de Partida</h5>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="abrirMapaPara('partida')" class="px-2.5 py-1 rounded-lg bg-white border border-blue-200 hover:bg-blue-100 text-blue-700 text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Elegir en mapa con pin">
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    <span>Mapa</span>
                                </button>
                                <button type="button" onclick="abrirModalGuardarPunto('partida')" class="px-2.5 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Registrar nuevo punto de partida">
                                    <i class="fa-solid fa-plus"></i>
                                    <span>Nuevo</span>
                                </button>
                            </div>
                        </div>
                        <select id="sel_punto_partida" class="w-full bg-white border border-blue-200 rounded-lg p-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-blue-500">
                            <option value="">— Elegir local / punto registrado —</option>
                        </select>
                        <input type="text" id="partida_direccion" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm" placeholder="Dirección detallada de partida...">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" id="partida_ubigeo" maxlength="6" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-xs font-mono font-bold" placeholder="Ubigeo (Ej. 150101)">
                            <input type="text" id="partida_coords" class="w-full bg-white/80 border border-gray-300 rounded-lg p-2 text-[11px] font-mono text-slate-600" placeholder="Lat, Lng (opcional)">
                        </div>
                    </div>

                    <!-- PUNTO DE LLEGADA -->
                    <div class="bg-green-50/50 p-4 rounded-xl border border-green-200/80 space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <h5 class="font-bold text-green-900 text-sm"><i class="fa-solid fa-flag-checkered mr-1.5 text-green-600"></i>Punto de Llegada</h5>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="abrirMapaPara('llegada')" class="px-2.5 py-1 rounded-lg bg-white border border-green-200 hover:bg-green-100 text-green-700 text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Elegir en mapa con pin">
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    <span>Mapa</span>
                                </button>
                                <button type="button" onclick="abrirModalGuardarPunto('llegada')" class="px-2.5 py-1 rounded-lg bg-green-600 hover:bg-green-700 text-white text-[11px] font-bold flex items-center gap-1 shadow-2xs transition-colors" title="Registrar nuevo punto de llegada">
                                    <i class="fa-solid fa-plus"></i>
                                    <span>Nuevo</span>
                                </button>
                            </div>
                        </div>
                        <select id="sel_punto_llegada" class="w-full bg-white border border-green-200 rounded-lg p-2 text-xs font-semibold text-slate-700 focus:outline-none focus:border-green-500">
                            <option value="">— Elegir punto de llegada frecuente —</option>
                        </select>
                        <input type="text" id="llegada_direccion" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm" placeholder="Dirección detallada de entrega...">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" id="llegada_ubigeo" maxlength="6" class="w-full bg-white border border-gray-300 rounded-lg p-2 text-xs font-mono font-bold" placeholder="Ubigeo de llegada">
                            <input type="text" id="llegada_coords" class="w-full bg-white/80 border border-gray-300 rounded-lg p-2 text-[11px] font-mono text-slate-600" placeholder="Lat, Lng (opcional)">
                        </div>
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">4. Datos del Transporte Público o Privado</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Modalidad de Transporte</label>
                        <select class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary">
                            <option>Transporte Privado (Propios)</option>
                            <option>Transporte Público (Terceros)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">DNI/Licencia del Conductor</label>
                        <input type="text" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary" placeholder="Número">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Placa del Vehículo</label>
                        <input type="text" class="w-full bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-primary focus:border-primary" placeholder="Ej. ABC-123">
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">5. Bienes a Transportar</h4>
                <div class="mb-8">
                    <div class="overflow-x-auto mb-4 border border-gray-100 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[600px]">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 text-xs uppercase tracking-wider">
                                    <th class="px-4 py-3 font-semibold w-20">Cant.</th>
                                    <th class="px-4 py-3 font-semibold w-32">Unidad</th>
                                    <th class="px-4 py-3 font-semibold min-w-[200px]">Descripción del Bien</th>
                                    <th class="px-4 py-3 font-semibold w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="items_table_body">
                                <tr class="border-b border-gray-100 item-row">
                                    <td class="px-4 py-3"><input type="number" value="1" min="0.01" step="any" class="item-qty w-full border border-gray-300 rounded p-1.5 text-sm text-center"></td>
                                    <td class="px-4 py-3">
                                        <select class="item-unit w-full border border-gray-300 rounded p-1.5 text-sm">
                                            <option value="NIU">NIU (Unidades)</option>
                                            <option value="KGM">KGM (Kilos)</option>
                                            <option value="LTR">LTR (Litros)</option>
                                            <option value="MTR">MTR (Metros)</option>
                                            <option value="ZZ">ZZ (Servicios / Otros)</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="product-dropdown-wrapper relative w-full">
                                            <div class="relative flex items-center">
                                                <input type="text" placeholder="Escribe o busca del catálogo..." autocomplete="off" class="item-desc w-full border border-gray-300 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-blue-500">
                                                <button type="button" tabindex="-1" class="btn-toggle-dropdown absolute right-2 text-gray-400 hover:text-blue-600 p-1 rounded transition-transform" title="Ver catálogo">
                                                    <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200"></i>
                                                </button>
                                            </div>
                                            <div class="product-dropdown-menu hidden fixed z-50 min-w-[300px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                                                <div class="px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                                    <div class="flex items-center gap-2">
                                                        <div class="w-5 h-5 rounded-md bg-blue-500/10 text-blue-600 flex items-center justify-center text-[10px]">
                                                            <i class="fa-solid fa-boxes-stacked"></i>
                                                        </div>
                                                        <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Catálogo de Productos</span>
                                                    </div>
                                                    <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">
                                                        <?php echo count($productosCatalogo); ?> ítems
                                                    </span>
                                                </div>
                                                <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                                                <div class="px-3 py-2 bg-slate-50/60 dark:bg-slate-950/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
                                                    <span><i class="fa-regular fa-keyboard mr-1"></i> Navega con ↑ ↓ y Enter</span>
                                                    <a href="productos.php" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1 font-semibold">
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none"></i></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="btn_add_row" class="text-primary text-sm font-medium hover:text-blue-700"><i class="fa-solid fa-plus mr-1"></i> Agregar Bien</button>
                </div>

                <div class="flex justify-end gap-4 mt-8 pt-6 border-t border-gray-100">
                    <a href="guias.php" class="px-6 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors">Cancelar</a>
                    <button class="px-6 py-2.5 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors shadow-sm flex items-center">
                        <i class="fa-solid fa-paper-plane mr-2"></i> Emitir Guía a SUNAT
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal de Guardado Directo de Punto de Partida / Llegada -->
<div id="modal_nuevo_punto_guia" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h4 id="modal_punto_titulo" class="text-sm font-extrabold text-slate-900 dark:text-white">Registrar Punto de Traslado</h4>
            <button type="button" onclick="cerrarModalPuntoGuia()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-5 space-y-3">
            <input type="hidden" id="mp_tipo" value="partida">
            <div>
                <label class="block text-[11px] font-bold uppercase text-slate-500 mb-1">Nombre / Alias del Local *</label>
                <input type="text" id="mp_alias" placeholder="Ej. Almacén Ate, Tienda Miraflores..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-semibold">
            </div>
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-[11px] font-bold uppercase text-slate-500">Dirección Exacta *</label>
                    <button type="button" onclick="elegirEnMapaParaModal()" class="text-[11px] font-bold text-brand-600 hover:underline flex items-center gap-1">
                        <i class="fa-solid fa-map-location-dot"></i> Elegir en Mapa
                    </button>
                </div>
                <input type="text" id="mp_direccion" placeholder="Av. / Jr. / Calle..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs">
            </div>
            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <label class="block text-[11px] font-bold uppercase text-slate-500 mb-1">Ubigeo (6 dígitos)</label>
                    <input type="text" id="mp_ubigeo" maxlength="6" placeholder="150101" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono font-bold">
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase text-slate-500 mb-1">Coordenadas</label>
                    <input type="text" id="mp_coords" placeholder="-12.0464, -77.0428" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-[11px] font-mono">
                </div>
            </div>
        </div>
        <div class="px-5 py-3.5 bg-slate-50 border-t border-slate-100 flex justify-end gap-2">
            <button type="button" onclick="cerrarModalPuntoGuia()" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600">Cancelar</button>
            <button type="button" id="btn_guardar_punto_guia" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold">Guardar y Usar</button>
        </div>
    </div>
</div>

<script src="js/mapa_ubigeo.js?v=<?php echo time(); ?>"></script>
<script src="js/product_dropdown.js?v=<?php echo time(); ?>"></script>
<script>
const catalogoProductos = <?php echo json_encode($productosCatalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let PUNTOS_TRASLADO = { partida: [], llegada: [], empresa_default: null };

async function cargarPuntosTraslado() {
    try {
        const res = await fetch('api/puntos_traslado.php');
        const json = await res.json();
        if (!json || !json.success) return;
        PUNTOS_TRASLADO = json;

        const selP = document.getElementById('sel_punto_partida');
        const selL = document.getElementById('sel_punto_llegada');

        if (selP) {
            selP.innerHTML = '<option value="">— Elegir local / punto de partida —</option>';
            if (json.empresa_default && json.empresa_default.direccion) {
                const optDef = document.createElement('option');
                optDef.value = 'empresa_default';
                optDef.textContent = '🏢 ' + json.empresa_default.alias + ' — ' + json.empresa_default.direccion;
                selP.appendChild(optDef);
            }
            (json.partida || []).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = `📍 ${p.alias} (${p.ubigeo || ''}) — ${p.direccion}`;
                selP.appendChild(opt);
            });
        }

        if (selL) {
            selL.innerHTML = '<option value="">— Elegir punto de llegada frecuente —</option>';
            (json.llegada || []).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = `🏁 ${p.alias} (${p.ubigeo || ''}) — ${p.direccion}`;
                selL.appendChild(opt);
            });
        }
    } catch (e) {}
}

function aplicarPuntoEnCampos(tipo, p) {
    if (!p) return;
    document.getElementById(tipo + '_direccion').value = p.direccion || '';
    document.getElementById(tipo + '_ubigeo').value    = p.ubigeo || '';
    document.getElementById(tipo + '_coords').value    = (p.lat && p.lng) ? `${p.lat}, ${p.lng}` : '';
}

function abrirMapaPara(tipo) {
    const coordsVal = (document.getElementById(tipo + '_coords').value || '').split(',');
    window.abrirModalMapaUbigeo({
        titulo: tipo === 'partida' ? 'Ubicar Punto de Partida en el Mapa' : 'Ubicar Punto de Llegada en el Mapa',
        direccion: document.getElementById(tipo + '_direccion').value,
        ubigeo: document.getElementById(tipo + '_ubigeo').value,
        lat: coordsVal[0] ? parseFloat(coordsVal[0]) : null,
        lng: coordsVal[1] ? parseFloat(coordsVal[1]) : null,
        onConfirm: (res) => {
            aplicarPuntoEnCampos(tipo, res);
            if (window.showToast) window.showToast('Dirección, ubigeo y coordenadas completados desde el mapa.', 'success');
        }
    });
}

function abrirModalGuardarPunto(tipo) {
    document.getElementById('mp_tipo').value = tipo;
    document.getElementById('modal_punto_titulo').textContent = tipo === 'partida' ? 'Registrar Nuevo Punto de Partida' : 'Registrar Nuevo Punto de Llegada';
    document.getElementById('mp_alias').value = '';
    document.getElementById('mp_direccion').value = document.getElementById(tipo + '_direccion').value || '';
    document.getElementById('mp_ubigeo').value    = document.getElementById(tipo + '_ubigeo').value || '150101';
    document.getElementById('mp_coords').value    = document.getElementById(tipo + '_coords').value || '';
    document.getElementById('modal_nuevo_punto_guia').classList.remove('hidden');
}

function cerrarModalPuntoGuia() {
    document.getElementById('modal_nuevo_punto_guia').classList.add('hidden');
}

function elegirEnMapaParaModal() {
    const coordsVal = (document.getElementById('mp_coords').value || '').split(',');
    window.abrirModalMapaUbigeo({
        titulo: 'Seleccionar Ubicación del Nuevo Punto',
        direccion: document.getElementById('mp_direccion').value,
        ubigeo: document.getElementById('mp_ubigeo').value,
        lat: coordsVal[0] ? parseFloat(coordsVal[0]) : null,
        lng: coordsVal[1] ? parseFloat(coordsVal[1]) : null,
        onConfirm: (res) => {
            document.getElementById('mp_direccion').value = res.direccion || '';
            document.getElementById('mp_ubigeo').value = res.ubigeo || '150101';
            document.getElementById('mp_coords').value = (res.lat && res.lng) ? `${res.lat.toFixed(6)}, ${res.lng.toFixed(6)}` : '';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    cargarPuntosTraslado();

    document.getElementById('sel_punto_partida')?.addEventListener('change', function() {
        const val = this.value;
        if (!val) return;
        if (val === 'empresa_default') {
            aplicarPuntoEnCampos('partida', PUNTOS_TRASLADO.empresa_default);
        } else {
            const found = (PUNTOS_TRASLADO.partida || []).find(p => p.id === val);
            aplicarPuntoEnCampos('partida', found);
        }
    });

    document.getElementById('sel_punto_llegada')?.addEventListener('change', function() {
        const val = this.value;
        if (!val) return;
        const found = (PUNTOS_TRASLADO.llegada || []).find(p => p.id === val);
        aplicarPuntoEnCampos('llegada', found);
    });

    document.getElementById('btn_usar_dir_dest')?.addEventListener('click', function() {
        const dir = document.getElementById('direccion')?.value || '';
        if (dir && dir !== '-') {
            document.getElementById('llegada_direccion').value = dir;
            if (window.showToast) window.showToast('Dirección del destinatario copiada a Punto de Llegada.', 'info');
        }
    });

    document.getElementById('btn_guardar_punto_guia')?.addEventListener('click', async function() {
        const tipo = document.getElementById('mp_tipo').value;
        const alias = document.getElementById('mp_alias').value.trim();
        const direccion = document.getElementById('mp_direccion').value.trim();
        const ubigeo = document.getElementById('mp_ubigeo').value.trim();
        const coordsParts = (document.getElementById('mp_coords').value || '').split(',');
        const lat = coordsParts[0] ? parseFloat(coordsParts[0]) : null;
        const lng = coordsParts[1] ? parseFloat(coordsParts[1]) : null;

        if (!direccion) {
            if (window.showToast) window.showToast('Ingrese la dirección del punto.', 'warning');
            return;
        }

        try {
            const r = await fetch('api/puntos_traslado.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save', tipo, alias, direccion, ubigeo, lat, lng })
            });
            const j = await r.json();
            if (!j.success) throw new Error(j.message || 'No se pudo guardar.');
            aplicarPuntoEnCampos(tipo, j.punto);
            await cargarPuntosTraslado();
            cerrarModalPuntoGuia();
            if (window.showToast) window.showToast('Punto guardado en configuración y aplicado a la guía.', 'success');
        } catch (err) {
            if (window.showToast) window.showToast(err.message, 'error');
        }
    });

    // ---- BÚSQUEDA DE DESTINATARIO (API) ----
    const btnBuscarDoc = document.getElementById('btn_buscar_doc');
    const iconBuscar = document.getElementById('icon_buscar');
    const textBuscar = document.getElementById('text_buscar');
    const numDocInput = document.getElementById('num_doc');
    const contenedorDatos = document.getElementById('datos_cliente_encontrado');

    if (btnBuscarDoc) {
        function resetBtn() {
            iconBuscar.className = 'fa-solid fa-magnifying-glass mr-2';
            textBuscar.innerText = 'Buscar';
            btnBuscarDoc.disabled = false;
        }

        btnBuscarDoc.addEventListener('click', async function() {
            const numDoc = numDocInput.value.trim();
            if (numDoc.length !== 8 && numDoc.length !== 11) {
                alert('El documento debe tener 8 dígitos (DNI) o 11 dígitos (RUC).');
                return;
            }
            const tipoDoc = numDoc.length === 8 ? 'DNI' : 'RUC';
            iconBuscar.className = 'fa-solid fa-spinner fa-spin mr-2';
            textBuscar.innerText = 'Buscando...';
            btnBuscarDoc.disabled = true;
            try {
                const response = await fetch('api/consultar_documento.php?documento=' + encodeURIComponent(numDoc));
                if (!response.ok) throw new Error('Error de red al consultar el documento.');
                const jsonResponse = await response.json();
                if (!jsonResponse.success) throw new Error(jsonResponse.message || 'Documento no encontrado.');
                const data = jsonResponse.data;
                contenedorDatos.classList.remove('hidden');
                document.getElementById('razon_social').value = data.razon_social || data.nombre_o_razon_social || data.nombre_completo || '';
                document.getElementById('direccion').value = data.direccion || data.direccion_completa || '-';

                iconBuscar.className = 'fa-solid fa-check text-green-500 mr-2';
                textBuscar.innerText = 'Encontrado';
                setTimeout(() => resetBtn(), 2000);
            } catch (error) {
                console.error(error);
                alert(error.message || 'Error al consultar el documento.');
                resetBtn();
                contenedorDatos.classList.add('hidden');
            }
        });
        numDocInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); btnBuscarDoc.click(); }
        });
    }

    // ---- AGREGAR LÍNEAS DINÁMICAS Y DESPLEGABLE DE PRODUCTOS ----
    const tbody = document.getElementById('items_table_body');
    const btnAddRow = document.getElementById('btn_add_row');

    function initGuiaRowDropdown(rowElement) {
        const wrapper = rowElement.querySelector('.product-dropdown-wrapper');
        if (!wrapper || typeof window.attachProductDropdown !== 'function') return;
        window.attachProductDropdown(wrapper, catalogoProductos, function(product, row) {
            if (!product || !row) return;
            const unitSel = row.querySelector('.item-unit');
            if (unitSel && product.unidad_medida) {
                const um = product.unidad_medida.toUpperCase();
                let found = false;
                for (let i = 0; i < unitSel.options.length; i++) {
                    if (unitSel.options[i].value.toUpperCase() === um) {
                        unitSel.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    unitSel.add(new Option(um, um, true, true));
                }
            }
        });
    }

    if (tbody) {
        tbody.querySelectorAll('.item-row').forEach(initGuiaRowDropdown);
    }

    if (btnAddRow && tbody) {
        btnAddRow.addEventListener('click', function() {
            const tr = document.createElement('tr');
            tr.className = 'border-b border-gray-100 item-row';
            tr.innerHTML = `
                <td class="px-4 py-3"><input type="number" value="1" min="0.01" step="any" class="item-qty w-full border border-gray-300 rounded p-1.5 text-sm text-center"></td>
                <td class="px-4 py-3">
                    <select class="item-unit w-full border border-gray-300 rounded p-1.5 text-sm">
                        <option value="NIU">NIU (Unidades)</option>
                        <option value="KGM">KGM (Kilos)</option>
                        <option value="LTR">LTR (Litros)</option>
                        <option value="MTR">MTR (Metros)</option>
                        <option value="ZZ">ZZ (Servicios / Otros)</option>
                    </select>
                </td>
                <td class="px-4 py-3">
                    <div class="product-dropdown-wrapper relative w-full">
                        <div class="relative flex items-center">
                            <input type="text" placeholder="Escribe o busca del catálogo..." autocomplete="off" class="item-desc w-full border border-gray-300 rounded-lg pl-3 pr-8 py-1.5 text-sm focus:outline-none focus:border-blue-500">
                            <button type="button" tabindex="-1" class="btn-toggle-dropdown absolute right-2 text-gray-400 hover:text-blue-600 p-1 rounded transition-transform" title="Ver catálogo">
                                <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200"></i>
                            </button>
                        </div>
                        <div class="product-dropdown-menu hidden fixed z-50 min-w-[300px] max-w-[460px] w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-xl transition-all">
                            <div class="px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="w-5 h-5 rounded-md bg-blue-500/10 text-blue-600 flex items-center justify-center text-[10px]">
                                        <i class="fa-solid fa-boxes-stacked"></i>
                                    </div>
                                    <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Catálogo de Productos</span>
                                </div>
                                <span class="dropdown-counter text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">
                                    ${catalogoProductos.length} ítems
                                </span>
                            </div>
                            <div class="product-items-list max-h-60 overflow-y-auto p-1.5 space-y-1"></div>
                            <div class="px-3 py-2 bg-slate-50/60 dark:bg-slate-950/60 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
                                <span><i class="fa-regular fa-keyboard mr-1"></i> Navega con ↑ ↓ y Enter</span>
                                <a href="productos.php" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1 font-semibold">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i> Catálogo
                                </a>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none"></i></td>
            `;
            tbody.appendChild(tr);
            initGuiaRowDropdown(tr);
            tr.querySelector('.item-desc')?.focus();
        });

        tbody.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-remove') || e.target.closest('.btn-remove')) {
                const row = e.target.closest('.item-row');
                if (tbody.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                } else {
                    alert('Debe haber al menos un bien a transportar.');
                }
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
