<?php 
require_once 'includes/auth.php';
requireLogin();

$success_msg = '';
$error_msg = '';
$u = currentUser();

// Procesar actualización de perfil y cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error_msg = "Token de seguridad inválido o expirado.";
    } elseif (isset($_POST['actualizar_perfil'])) {
        $nuevoNombre = trim($_POST['nombre'] ?? '');
        if (empty($nuevoNombre)) {
            $error_msg = "El nombre no puede estar vacío.";
        } else {
            try {
                $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($nuevoNombre) . '&background=4f46e5&color=fff';
                $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, avatar = ? WHERE id = ?");
                $stmt->execute([$nuevoNombre, $avatar, $u['id']]);
                
                $_SESSION['user']['nombre'] = $nuevoNombre;
                $_SESSION['user']['avatar'] = $avatar;
                $u = $_SESSION['user'];
                $success_msg = "Nombre de perfil actualizado correctamente.";
            } catch (Exception $e) {
                $error_msg = "Error al actualizar perfil: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['cambiar_clave'])) {
        $actualClave = $_POST['clave_actual'] ?? '';
        $nuevaClave = $_POST['clave_nueva'] ?? '';
        $confirmarClave = $_POST['clave_confirmar'] ?? '';

        if (empty($actualClave) || empty($nuevaClave)) {
            $error_msg = "Debe ingresar su contraseña actual y la nueva.";
        } elseif (strlen($nuevaClave) < 6) {
            $error_msg = "La nueva contraseña debe tener al menos 6 caracteres.";
        } elseif ($nuevaClave !== $confirmarClave) {
            $error_msg = "La nueva contraseña y su confirmación no coinciden.";
        } else {
            // Verificar contraseña actual en base de datos
            $stmt = $conn->prepare("SELECT password FROM usuarios WHERE id = ?");
            $stmt->execute([$u['id']]);
            $currentHash = $stmt->fetchColumn();

            if (!password_verify($actualClave, $currentHash)) {
                $error_msg = "La contraseña actual no es correcta.";
            } else {
                $nuevoHash = password_hash($nuevaClave, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                $upd->execute([$nuevoHash, $u['id']]);
                $success_msg = "Contraseña modificada con éxito.";
            }
        }
    }
}

// Datos de empresa desde configuración
$empresaRuc   = getSystemConfig('empresa_ruc', '20000000001');
$empresaRazon = getSystemConfig('empresa_razon_social', 'MI EMPRESA S.A.C.');
$nubefactModo = getSystemConfig('nubefact_modo', 'beta');
$nubefactUrl  = getSystemConfig('nubefact_url', 'Configuración OSE pendiente');

include 'includes/header.php'; 
include 'includes/sidebar.php'; 
?>

<main class="flex-1 flex flex-col h-full bg-slate-50/70 overflow-hidden">
    <?php 
    $pageTitle = 'Mi Perfil de Usuario';
    include 'includes/topbar.php'; 
    ?>

    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">
        <div class="max-w-4xl mx-auto space-y-6">
            
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

            <!-- Perfil Header Card -->
            <div class="app-card overflow-hidden">
                <div class="p-6 sm:p-8 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-6 bg-gradient-to-r from-slate-900 to-indigo-950 text-white">
                    <div class="flex items-center gap-4">
                        <img src="<?php echo htmlspecialchars($u['avatar']); ?>" alt="User" class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl border-2 border-white/20 shadow-lg object-cover">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg sm:text-xl font-bold text-white"><?php echo htmlspecialchars($u['nombre']); ?></h3>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-brand-500/20 text-brand-300 border border-brand-500/30">
                                    <?php echo htmlspecialchars($u['rol']); ?>
                                </span>
                            </div>
                            <p class="text-xs text-slate-300 mt-1"><?php echo htmlspecialchars($u['email']); ?></p>
                        </div>
                    </div>
                    <a href="logout.php" onclick="return confirm('¿Deseas cerrar tu sesión?');" class="px-3.5 py-2 rounded-xl bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/30 text-xs font-semibold transition-all inline-flex items-center gap-2 self-start sm:self-auto">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        <span>Cerrar Sesión</span>
                    </a>
                </div>
                
                <div class="p-6 sm:p-8 space-y-8">
                    <!-- Form 1: Editar Nombre -->
                    <form method="POST" action="" class="space-y-4">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="actualizar_perfil" value="1">
                        
                        <h4 class="text-xs font-bold text-brand-600 uppercase tracking-wider mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-user"></i> Información Personal
                        </h4>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nombre Completo</label>
                                <input type="text" name="nombre" value="<?php echo htmlspecialchars($u['nombre']); ?>" required class="w-full bg-white border border-slate-200 text-slate-800 text-xs rounded-xl focus:border-brand-500 p-2.5">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Correo Electrónico (No editable)</label>
                                <input type="text" value="<?php echo htmlspecialchars($u['email']); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-500 text-xs rounded-xl p-2.5" disabled>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-semibold rounded-xl shadow-sm transition-all">
                                Guardar Cambios
                            </button>
                        </div>
                    </form>

                    <!-- Form 2: Cambiar Contraseña -->
                    <form method="POST" action="" class="space-y-4 pt-6 border-t border-slate-100">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="cambiar_clave" value="1">
                        
                        <h4 class="text-xs font-bold text-brand-600 uppercase tracking-wider mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-key"></i> Seguridad & Contraseña
                        </h4>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Contraseña Actual</label>
                                <input type="password" name="clave_actual" required class="w-full bg-white border border-slate-200 text-slate-800 text-xs rounded-xl focus:border-brand-500 p-2.5">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nueva Contraseña</label>
                                <input type="password" name="clave_nueva" minlength="6" required class="w-full bg-white border border-slate-200 text-slate-800 text-xs rounded-xl focus:border-brand-500 p-2.5" placeholder="Mínimo 6 caracteres">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Confirmar Nueva Contraseña</label>
                                <input type="password" name="clave_confirmar" minlength="6" required class="w-full bg-white border border-slate-200 text-slate-800 text-xs rounded-xl focus:border-brand-500 p-2.5">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-xl shadow-sm transition-all">
                                Actualizar Contraseña
                            </button>
                        </div>
                    </form>

                    <!-- Info Empresa -->
                    <div class="pt-6 border-t border-slate-100">
                        <h4 class="text-xs font-bold text-brand-600 uppercase tracking-wider mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-building"></i> Datos Fiscales de la Empresa Activa
                        </h4>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">RUC Emisor</label>
                                <input type="text" value="<?php echo htmlspecialchars($empresaRuc); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl p-2.5 font-mono" disabled>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Razón Social</label>
                                <input type="text" value="<?php echo htmlspecialchars($empresaRazon); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl p-2.5" disabled>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Ambiente SUNAT</label>
                                <input type="text" value="<?php echo strtoupper($nubefactModo); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl p-2.5 font-semibold" disabled>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Endpoint OSE</label>
                                <input type="text" value="<?php echo htmlspecialchars($nubefactUrl); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-500 text-xs rounded-xl p-2.5 font-mono truncate" disabled>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
