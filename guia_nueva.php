<?php 
require_once 'includes/auth.php';
requireLogin();
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
                            <input type="text" id="razon_social" class="w-full bg-gray-50 border border-gray-300 rounded p-2 text-sm text-gray-800" readonly>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Dirección (Llegada sugerida)</label>
                            <input type="text" id="direccion" class="w-full bg-gray-50 border border-gray-300 rounded p-2 text-sm text-gray-800" readonly>
                        </div>
                    </div>
                </div>

                <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">3. Direcciones de Traslado</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="bg-blue-50/50 p-4 rounded-lg border border-blue-100">
                        <h5 class="font-medium text-blue-800 mb-3"><i class="fa-solid fa-location-dot mr-2"></i>Punto de Partida</h5>
                        <input type="text" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm mb-2" placeholder="Dirección detallada...">
                        <input type="text" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm" placeholder="Ubigeo (Ej. 150101)">
                    </div>
                    <div class="bg-green-50/50 p-4 rounded-lg border border-green-100">
                        <h5 class="font-medium text-green-800 mb-3"><i class="fa-solid fa-flag-checkered mr-2"></i>Punto de Llegada</h5>
                        <input type="text" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm mb-2" placeholder="Dirección detallada de entrega...">
                        <input type="text" class="w-full bg-white border border-gray-300 rounded-lg p-2.5 text-sm" placeholder="Ubigeo de llegada">
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
                                    <td class="px-4 py-3"><input type="number" value="1" min="1" class="item-qty w-full border border-gray-300 rounded p-1 text-sm text-center"></td>
                                    <td class="px-4 py-3">
                                        <select class="w-full border border-gray-300 rounded p-1 text-sm">
                                            <option>NIU (Unidades)</option>
                                            <option>KGM (Kilos)</option>
                                            <option>LTR (Litros)</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3"><input type="text" placeholder="Nombre del producto..." class="item-desc w-full border border-gray-300 rounded p-1 text-sm"></td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
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

    // ---- AGREGAR LÍNEAS DINÁMICAS ----
    const tbody = document.getElementById('items_table_body');
    const btnAddRow = document.getElementById('btn_add_row');

    if (btnAddRow && tbody) {
        btnAddRow.addEventListener('click', function() {
            const tr = document.createElement('tr');
            tr.className = 'border-b border-gray-100 item-row';
            tr.innerHTML = `
                <td class="px-4 py-3"><input type="number" value="1" min="1" class="item-qty w-full border border-gray-300 rounded p-1 text-sm text-center"></td>
                <td class="px-4 py-3">
                    <select class="w-full border border-gray-300 rounded p-1 text-sm">
                        <option>NIU (Unidades)</option>
                        <option>KGM (Kilos)</option>
                        <option>LTR (Litros)</option>
                    </select>
                </td>
                <td class="px-4 py-3"><input type="text" placeholder="Nombre del producto..." class="item-desc w-full border border-gray-300 rounded p-1 text-sm"></td>
                <td class="px-4 py-3 text-red-500 cursor-pointer text-center btn-remove"><i class="fa-solid fa-trash pointer-events-none"></i></td>
            `;
            tbody.appendChild(tr);
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
