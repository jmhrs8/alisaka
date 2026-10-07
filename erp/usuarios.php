<?php
require_once 'includes/header.php';

// Validar que solo un administrador pueda gestionar usuarios
if (($_SESSION['user_rol'] ?? '') !== 'admin') {
    echo "<div class='alert alert-danger shadow-sm border-danger m-3'><i class='bi bi-shield-lock-fill me-2'></i> Acceso denegado. Se requieren permisos de Administrador.</div>";
    require_once 'includes/footer.php';
    exit;
}

$mensaje = '';
$error = '';

// 1. REGISTRAR USUARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    $nombre   = trim($_POST['nombre'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol      = $_POST['rol'] ?? 'cajero';

    if (!empty($nombre) && !empty($email) && !empty($password)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $hash, $rol]);
            $mensaje = "Usuario '{$nombre}' registrado correctamente con rol " . strtoupper($rol) . ".";
        } catch (\PDOException $e) {
            $error = "Error: El correo electrónico ya está registrado en el sistema.";
        }
    } else {
        $error = "Todos los campos obligatorios deben ser completados.";
    }
}

// 2. ACTUALIZAR ROL / DATOS DE USUARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modificar_usuario'])) {
    $idEdit   = intval($_POST['usuario_id'] ?? 0);
    $nombre   = trim($_POST['nombre'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $rol      = $_POST['rol'] ?? 'cajero';
    $password = $_POST['password'] ?? '';

    if ($idEdit > 0 && !empty($nombre) && !empty($email)) {
        try {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ?, password = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $hash, $idEdit]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $idEdit]);
            }

            // Si se editó a sí mismo, actualizar sesión activa
            if ($idEdit === ($_SESSION['user_id'] ?? 0)) {
                $_SESSION['user_rol'] = $rol;
            }

            $mensaje = "Información del usuario actualizada correctamente.";
        } catch (\PDOException $e) {
            $error = "Error al actualizar usuario: " . $e->getMessage();
        }
    }
}

// 3. ELIMINAR USUARIO
if (isset($_GET['eliminar'])) {
    $idEliminar = intval($_GET['eliminar']);
    $myId = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 0;

    if ($idEliminar !== $myId) {
        try {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$idEliminar]);
            header('Location: usuarios.php?msg=deleted');
            exit;
        } catch (\PDOException $e) {
            $error = "No se puede eliminar el usuario porque tiene registros vinculados (ventas, compras o egresos).";
        }
    } else {
        $error = "No puedes eliminar tu propia cuenta en uso.";
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $mensaje = "Usuario eliminado del sistema.";
}

$usuarios = $pdo->query("SELECT id, nombre, email, rol, creado_en FROM usuarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people-fill text-primary me-2"></i> Gestión de Usuarios y Permisos</h2>
</div>

<?php if ($mensaje): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($mensaje) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- REGISTRO DE NUEVO USUARIO -->
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="bi bi-person-plus-fill me-1"></i> Agregar Nuevo Usuario
            </div>
            <div class="card-body">
                <form method="POST" action="usuarios.php">
                    <input type="hidden" name="crear_usuario" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre Completo (*):</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej. Ana María López" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Correo Electrónico (*):</label>
                        <input type="email" name="email" class="form-control" placeholder="caja@alisaka.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Contraseña (*):</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Rol / Privilegio de Acceso (*):</label>
                        <select name="rol" class="form-select border-primary fw-bold" required>
                            <option value="cajero">🛒 Cajero / Ventas (Solo Salidas e Ingresos)</option>
                            <option value="almacen">📦 Encargado Almacén (Solo Inventario y Compras)</option>
                            <option value="admin">🔑 Administrador (Acceso Total)</option>
                            <option value="usuario">👁️ Usuario Estándar (Lectura General)</option>
                        </select>
                        <small class="text-muted d-block mt-1">El rol define las pantallas visibles en el menú principal.</small>
                    </div>

                    <button type="submit" class="btn btn-success w-100 fw-bold">
                        <i class="bi bi-check-lg me-1"></i> Guardar Usuario
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- LISTADO DE USUARIOS REGISTRADOS -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-shield-lock me-1"></i> Usuarios del Sistema
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Rol / Privilegio</th>
                                <th>Registro</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php
                                    $rolBadge = 'bg-secondary';
                                    $rolNombre = 'USUARIO';
                                    
                                    switch ($u['rol']) {
                                        case 'admin':
                                            $rolBadge = 'bg-danger';
                                            $rolNombre = 'ADMINISTRADOR';
                                            break;
                                        case 'cajero':
                                            $rolBadge = 'bg-success';
                                            $rolNombre = 'CAJERO / VENTAS';
                                            break;
                                        case 'almacen':
                                            $rolBadge = 'bg-warning text-dark';
                                            $rolNombre = 'ALMACÉN / BODEGA';
                                            break;
                                    }

                                    $myId = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 0;
                                ?>
                                <tr>
                                    <td>#<?= $u['id'] ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($u['nombre']) ?></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td><span class="badge <?= $rolBadge ?>"><?= $rolNombre ?></span></td>
                                    <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($u['creado_en'])) ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-warning me-1"
                                                onclick='abrirModalEditarUsuario(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <?php if ($u['id'] !== $myId): ?>
                                            <a href="usuarios.php?eliminar=<?= $u['id'] ?>" 
                                               onclick="return confirm('¿Confirma eliminar a este usuario del sistema?');" 
                                               class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">En línea</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PARA EDITAR USUARIO / ROL -->
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="usuarios.php">
                <input type="hidden" name="modificar_usuario" value="1">
                <input type="hidden" name="usuario_id" id="edit_user_id">

                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Modificar Privilegios de Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Nombre Completo:</label>
                        <input type="text" name="nombre" id="edit_user_nombre" class="form-control" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Correo Electrónico:</label>
                        <input type="email" name="email" id="edit_user_email" class="form-control" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Privilegio de Acceso (Rol):</label>
                        <select name="rol" id="edit_user_rol" class="form-select border-warning fw-bold" required>
                            <option value="cajero">🛒 Cajero / Ventas (Solo Salidas e Ingresos)</option>
                            <option value="almacen">📦 Encargado Almacén (Solo Inventario y Compras)</option>
                            <option value="admin">🔑 Administrador (Acceso Total)</option>
                            <option value="usuario">👁️ Usuario Estándar (Lectura General)</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Cambiar Contraseña (Opcional):</label>
                        <input type="password" name="password" class="form-control" placeholder="Dejar en blanco para mantener la actual">
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning fw-bold"><i class="bi bi-check-circle me-1"></i> Actualizar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModalEditarUsuario(u) {
    document.getElementById('edit_user_id').value = u.id;
    document.getElementById('edit_user_nombre').value = u.nombre;
    document.getElementById('edit_user_email').value = u.email;
    document.getElementById('edit_user_rol').value = u.rol;

    var modal = new bootstrap.Modal(document.getElementById('modalEditarUsuario'));
    modal.show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
