<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = 'Token de seguridad inválido o sesión expirada. Intente de nuevo.';
    } else {
        $tipo_doc = $_POST['tipo_doc'] ?? '';
        $num_doc = trim($_POST['num_doc'] ?? '');
        $razon_social = trim($_POST['razon_social'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $es_credito = isset($_POST['es_credito']) ? 1 : 0;

        if (!empty($tipo_doc) && !empty($num_doc) && !empty($razon_social)) {
            try {
                $stmt = $conn->prepare("INSERT INTO clientes (tipo_documento, numero_documento, razon_social, correo_electronico, telefono, direccion_fiscal, es_credito) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$tipo_doc, $num_doc, $razon_social, $correo, $telefono, $direccion, $es_credito]);
                $success_msg = "Cliente registrado exitosamente.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Integridad referencial (Duplicate entry)
                    $error_msg = "El número de documento ya está registrado en el sistema.";
                } else {
                    $error_msg = "Error al guardar el cliente: " . $e->getMessage();
                }
            }
        } else {
            $error_msg = "Por favor, complete los datos obligatorios (Tipo, Número y Razón Social).";
        }
    }
}

include 'includes/header.php'; 
?>
<?php include 'includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php
    $pageTitle = 'Nuevo Cliente';
    $topbarAction = [
        'label' => 'Directorio Clientes',
        'icon'  => 'fa-solid fa-users text-xs',
        'url'   => 'clientes.php'
    ];
    include 'includes/topbar.php';
    ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
        
        <div class="max-w-4xl mx-auto">
            <div class="mb-8">
                <h3 class="text-2xl font-bold text-gray-800">Registrar Nuevo Cliente</h3>
                <p class="text-gray-500 mt-1">Completa la información para agregar al cliente a tu CRM.</p>
            </div>

            <!-- Form Card -->
            <?php if (!empty($success_msg)): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center">
                <i class="fa-solid fa-check-circle mr-3 text-lg"></i>
                <p class="font-medium"><?php echo $success_msg; ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center">
                <i class="fa-solid fa-triangle-exclamation mr-3 text-lg"></i>
                <p class="font-medium"><?php echo $error_msg; ?></p>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <form action="cliente_nuevo.php" method="POST" class="p-8">
                    <?php echo csrf_field(); ?>
                    
                    <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">1. Datos Principales</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Documento</label>
                            <select id="tipo_doc" name="tipo_doc" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary p-2.5" required>
                                <option value="RUC">RUC (Registro Único de Contribuyentes)</option>
                                <option value="DNI">DNI (Documento Nacional de Identidad)</option>
                                <option value="CE">CE (Carné de Extranjería)</option>
                                <option value="PASAPORTE">Pasaporte</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Número de Documento</label>
                            <div class="flex">
                                <input type="text" id="num_doc" name="num_doc" class="flex-1 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-l-lg focus:ring-primary focus:border-primary p-2.5" placeholder="Ej. 20123456789" required>
                                <button type="button" id="btn_buscar_doc" class="bg-gray-100 hover:bg-gray-200 border border-l-0 border-gray-300 px-4 rounded-r-lg text-sm font-medium text-gray-600 flex items-center transition-colors">
                                    <i id="icon_buscar" class="fa-solid fa-magnifying-glass mr-2"></i> <span id="text_buscar">Buscar</span>
                                </button>
                            </div>
                            <p class="text-xs text-gray-400 mt-1"><i class="fa-solid fa-circle-info mr-1"></i> Puedes buscar el RUC directamente en SUNAT.</p>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Razón Social o Nombre Completo</label>
                            <input type="text" id="razon_social" name="razon_social" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary p-2.5" placeholder="Nombre de la empresa o persona" required>
                        </div>
                    </div>

                    <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">2. Datos de Contacto y Dirección</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Correo Electrónico</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fa-solid fa-envelope text-gray-400"></i>
                                </div>
                                <input type="email" id="correo" name="correo" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary p-2.5 pl-10" placeholder="correo@empresa.com">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Teléfono / Celular</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fa-solid fa-phone text-gray-400"></i>
                                </div>
                                <input type="text" id="telefono" name="telefono" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary p-2.5 pl-10" placeholder="987 654 321">
                            </div>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Dirección Fiscal</label>
                            <input type="text" id="direccion" name="direccion" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary focus:border-primary p-2.5" placeholder="Av. Principal 123, Distrito, Provincia, Departamento">
                        </div>
                    </div>
                    
                    <h4 class="text-sm font-semibold text-primary uppercase tracking-wider mb-4 border-b pb-2">3. Configuración Adicional</h4>
                    
                    <div class="mb-8">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="es_credito" name="es_credito" value="1" class="w-4 h-4 text-primary bg-gray-100 border-gray-300 rounded focus:ring-primary focus:ring-2">
                            <span class="ml-2 text-sm font-medium text-gray-700">Asignar como cliente de crédito (permite emitir comprobantes a crédito)</span>
                        </label>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-4 pt-4 border-t border-gray-100">
                        <a href="clientes.php" class="px-5 py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg shadow-sm hover:bg-gray-50 transition-colors">
                            Cancelar
                        </a>
                        <button type="submit" class="px-5 py-2.5 bg-primary text-white text-sm font-medium rounded-lg shadow-sm hover:bg-blue-600 transition-colors flex items-center gap-2">
                            <i class="fa-solid fa-save"></i> Guardar Cliente
                        </button>
                    </div>
                    
                </form>
            </div>
        </div>
    </div>
</main>

<script>
    document.getElementById('btn_buscar_doc').addEventListener('click', async function() {
        const tipoDoc = document.getElementById('tipo_doc').value;
        const numDoc = document.getElementById('num_doc').value.trim();
        const iconBuscar = document.getElementById('icon_buscar');
        const textBuscar = document.getElementById('text_buscar');

        if (!numDoc) {
            alert('Por favor, ingresa un número de documento.');
            return;
        }

        // Animación de carga
        iconBuscar.className = 'fa-solid fa-spinner fa-spin mr-2';
        textBuscar.innerText = 'Buscando...';
        this.disabled = true;

        try {
            const response = await fetch('api/consultar_documento.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    tipo_doc: tipoDoc,
                    num_doc: numDoc
                })
            });

            const jsonResponse = await response.json();
            
            if (!response.ok || !jsonResponse.success) {
                throw new Error(jsonResponse.message || 'Documento no encontrado.');
            }

            const data = jsonResponse.data;

            if (data.razon_social) {
                document.getElementById('razon_social').value = data.razon_social;
            }
            if (data.direccion && data.direccion !== '-') {
                document.getElementById('direccion').value = data.direccion;
            }

            // Feedback visual
            iconBuscar.className = 'fa-solid fa-check text-green-500 mr-2';
            textBuscar.innerText = (jsonResponse.source === 'local_database') ? 'Local' : 'Encontrado';
            
            setTimeout(() => resetBtn(), 2000);

        } catch (error) {
            console.error(error);
            alert(error.message || 'Error al consultar el documento. Verifica el número.');
            resetBtn();
        }
        
        function resetBtn() {
            iconBuscar.className = 'fa-solid fa-magnifying-glass mr-2';
            textBuscar.innerText = 'Buscar';
            document.getElementById('btn_buscar_doc').disabled = false;
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
