<?php 
require_once 'includes/header.php';

// Validar que solo un administrador pueda gestionar usuarios
if ($_SESSION['user_rol'] !== 'admin') {
    echo "<div class='alert alert-danger'>Acceso denegado. Se requieren permisos de Administrador.</div>";
    require_once 'includes/footer.php';
    exit;
}

$mensaje = '';
$error = '';

// Registrar Usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $rol = $_POST['rol'];

    if (!empty($nombre) && !empty($email) && !empty($password)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $hash, $rol]);
            $mensaje = "Usuario registrado correctamente.";
        } catch (\PDOException $e) {
            $error = "Error: El correo electrónico ya está registrado.";
        }
    } else {
        $error = "Todos los campos son obligatorios.";
    }
}

// Eliminar Usuario
if (isset($_GET['eliminar'])) {
    $idEliminar = intval($_GET['eliminar']);
    if ($idEliminar !== $_SESSION['user_id']) {
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$idEliminar]);
        header('Location: usuarios.php');
        exit;
    } else {
        $error = "No puedes eliminar tu propia cuenta en uso.";
    }
}

$usuarios = $pdo->query("SELECT id, nombre, email, rol, creado_en FROM usuarios ORDER BY id DESC")->fetchAll();
?>

<h2>Gestión de Usuarios</h2>

<?php if ($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="row">
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="m-0">Agregar Nuevo Usuario</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="crear_usuario" value="1">
                    <div class="mb-3">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Correo Electrónico</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Contraseña</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Rol de Acceso</label>
                        <select name="rol" class="form-select">
                            <option value="usuario">Usuario Estándar</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success w-100">Guardar Usuario</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Registro</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['nombre']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge bg-<?= $u['rol'] === 'admin' ? 'danger' : 'secondary' ?>"><?= strtoupper($u['rol']) ?></span></td>
                            <td><?= $u['creado_en'] ?></td>
                            <td>
                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                    <a href="usuarios.php?eliminar=<?= $u['id'] ?>" onclick="return confirm('¿Eliminar usuario?');" class="btn btn-sm btn-outline-danger">Eliminar</a>
                                <?php else: ?>
                                    <span class="text-muted">En línea</span>
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

<?php require_once 'includes/footer.php'; ?>
