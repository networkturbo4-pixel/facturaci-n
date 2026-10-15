<?php 
require_once 'includes/auth.php';
requireRole('Administrador');

$success_msg = '';
$error_msg = '';
$curU = currentUser();

// Procesar acciones de usuarios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o expirado.";
    } elseif (isset($_POST['crear_usuario'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol = trim($_POST['rol'] ?? 'Ventas');
        $estado = trim($_POST['estado'] ?? 'Activo');

        if (empty($nombre) || empty($email) || empty($password)) {
            $error_msg = "Todos los campos obligatorios deben completarse.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "El correo electrónico no tiene un formato válido.";
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($nombre) . '&background=4f46e5&color=fff';
                $stmt = $conn->prepare("INSERT INTO usuarios (nombre, email, password, rol, avatar, estado) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $email, $hash, $rol, $avatar, $estado]);
                $success_msg = "Usuario creado exitosamente.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_msg = "El correo ya se encuentra registrado por otro usuario.";
                } else {
                    $error_msg = "Error al crear usuario: " . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['toggle_estado'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $nuevoEstado = ($_POST['nuevo_estado'] === 'Activo') ? 'Activo' : 'Inactivo';
        if ($userId === (int)$curU['id']) {
            $error_msg = "No puedes cambiar el estado de tu propia cuenta activa.";
        } else {
            $upd = $conn->prepare("UPDATE usuarios SET estado = ? WHERE id = ?");
            $upd->execute([$nuevoEstado, $userId]);
            $success_msg = "Estado de usuario actualizado a $nuevoEstado.";
        }
    }
}

try {
    $stmt = $conn->query("SELECT id, nombre, email, rol, avatar, estado, ultimo_acceso, created_at FROM usuarios ORDER BY id ASC");
    $dbUsuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $dbUsuarios = [];
}

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Usuarios y Roles';
    $topbarAction = [
        'label'   => 'Nuevo Usuario',
        'onclick' => "document.getElementById('modalNuevoUsuario').classList.remove('hidden')",
        'icon'    => 'fa-solid fa-user-plus text-[11px]'
    ];
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">
        <div class="max-w-6xl mx-auto space-y-6">

            <?php if ($success_msg): ?>
                <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-500"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-xs flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-red-500"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- Users Table Card -->
            <div class="app-card overflow-hidden">
                <div class="p-5 sm:px-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <h3 class="text-sm font-bold text-slate-900">Operadores Registrados (<?php echo count($dbUsuarios); ?>)</h3>
                    <div class="relative w-full sm:w-64">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                        </span>
                        <input type="text" id="searchUsuarios" placeholder="Buscar usuario..." class="w-full bg-white border border-slate-200 rounded-xl py-1.5 pl-8 pr-3 text-xs text-slate-700 placeholder-slate-400 focus:outline-none focus:border-brand-500">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs" id="tableUsuarios">
                        <thead>
                            <tr class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-200/80 uppercase tracking-wider text-[11px]">
                                <th class="px-6 py-3.5">Usuario</th>
                                <th class="px-6 py-3.5">Rol de Acceso</th>
                                <th class="px-6 py-3.5">Último Acceso</th>
                                <th class="px-6 py-3.5">Estado</th>
                                <th class="px-6 py-3.5 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <?php foreach ($dbUsuarios as $u): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <img src="<?php echo htmlspecialchars($u['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($u['nombre']).'&background=4f46e5&color=fff'); ?>" class="w-9 h-9 rounded-xl border border-slate-200 object-cover">
                                        <div>
                                            <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($u['nombre']); ?></div>
                                            <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($u['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 text-[11px] font-semibold rounded-lg <?php echo ($u['rol'] === 'Administrador') ? 'bg-brand-50 text-brand-700 border border-brand-200' : 'bg-blue-50 text-blue-700 border border-blue-200'; ?>">
                                        <?php echo htmlspecialchars($u['rol']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-500 font-mono text-[11px]">
                                    <?php echo !empty($u['ultimo_acceso']) ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : 'Nunca'; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($u['estado'] === 'Activo'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Activo
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Inactivo
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <?php if ((int)$u['id'] !== (int)$curU['id']): ?>
                                    <form method="POST" action="" class="inline" onsubmit="return confirm('¿Deseas cambiar el estado de este usuario?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="toggle_estado" value="1">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="nuevo_estado" value="<?php echo $u['estado'] === 'Activo' ? 'Inactivo' : 'Activo'; ?>">
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-colors" title="<?php echo $u['estado'] === 'Activo' ? 'Desactivar' : 'Activar'; ?>">
                                            <i class="fa-solid <?php echo $u['estado'] === 'Activo' ? 'fa-user-slash text-amber-500' : 'fa-user-check text-emerald-500'; ?> text-xs"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span class="text-[10px] text-slate-400 font-semibold italic">Tú</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Roles Info Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="app-card p-6">
                    <h4 class="text-sm font-bold text-slate-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-shield-halved text-brand-600"></i> Permisos del Administrador
                    </h4>
                    <ul class="text-xs text-slate-600 space-y-2.5">
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-emerald-500"></i> Acceso total e irrestricto al sistema.</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-emerald-500"></i> Configuración de credenciales SUNAT / Nubefact.</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-emerald-500"></i> Emisión y anulación de facturas y notas.</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-emerald-500"></i> Creación y control de cuentas de usuarios.</li>
                    </ul>
                </div>
                <div class="app-card p-6">
                    <h4 class="text-sm font-bold text-slate-900 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-user-tag text-blue-500"></i> Permisos del Operador de Ventas
                    </h4>
                    <ul class="text-xs text-slate-600 space-y-2.5">
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-emerald-500"></i> Emisión de Facturas, Boletas y Proformas.</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-emerald-500"></i> Gestión de Clientes en el módulo CRM.</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-xmark text-slate-400"></i> Restringida anulación o eliminación de registros.</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-xmark text-slate-400"></i> Sin acceso a credenciales de conexión OSE.</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</main>

<!-- Modal: Nuevo Usuario -->
<div id="modalNuevoUsuario" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-bold text-slate-900 text-sm">Registrar Nuevo Usuario</h3>
            <button type="button" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" action="" class="p-6 space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="crear_usuario" value="1">
            
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Nombre Completo</label>
                <input type="text" name="nombre" placeholder="Ej. Juan Pérez" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Correo Electrónico (Login)</label>
                <input type="email" name="email" placeholder="usuario@empresa.com" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Contraseña Inicial</label>
                <input type="password" name="password" minlength="6" placeholder="Mínimo 6 caracteres" required class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Rol de Acceso</label>
                    <select name="rol" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="Ventas">Ventas</option>
                        <option value="Administrador">Administrador</option>
                        <option value="Contabilidad">Contabilidad</option>
                        <option value="Almacén">Almacén</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Estado</label>
                    <select name="estado" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs text-slate-800 focus:outline-none focus:border-brand-500">
                        <option value="Activo">Activo</option>
                        <option value="Inactivo">Inactivo</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('modalNuevoUsuario').classList.add('hidden')" class="px-4 py-2 border border-slate-300 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-50">
                    Cancelar
                </button>
                <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold rounded-xl shadow-sm">
                    Guardar Usuario
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('searchUsuarios')?.addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#tableUsuarios tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
