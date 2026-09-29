<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Si ya hay sesión activa, redirigir al Dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_rol'] = $user['rol'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Credenciales incorrectas. Verifique correo y contraseña.';
        }
    } else {
        $error = 'Por favor complete todos los campos.';
    }
}

// Obtener datos de la empresa/configuración (Con respaldo de tabla)
try {
    $stmtCfg = $pdo->query("SELECT logo_url, bg_url FROM configuracion LIMIT 1");
    $cfg = $stmtCfg->fetch();
} catch (\PDOException $e) {
    try {
        $stmtCfg = $pdo->query("SELECT logo_url, bg_url FROM empresa LIMIT 1");
        $cfg = $stmtCfg->fetch();
    } catch (\PDOException $e) {
        $cfg = [];
    }
}

$bgUrl = !empty($cfg['bg_url']) && file_exists(__DIR__ . '/' . $cfg['bg_url']) ? $cfg['bg_url'] : '';
$logoUrl = !empty($cfg['logo_url']) && file_exists(__DIR__ . '/' . $cfg['logo_url']) ? $cfg['logo_url'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - ERP System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            <?php if ($bgUrl): ?>
            background-image: url('<?= htmlspecialchars($bgUrl) ?>?v=<?= filemtime(__DIR__ . '/' . $bgUrl) ?>') !important;
            background-repeat: no-repeat !important;
            background-position: center center !important;
            background-attachment: fixed !important;
            background-size: cover !important;
            <?php else: ?>
            background-color: #f8f9fa;
            <?php endif; ?>
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(5px);
            border-radius: 12px;
        }
    </style>
</head>
<body>
<div class="card login-card shadow-lg p-4">
    <div class="text-center mb-3">
        <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>?v=<?= filemtime(__DIR__ . '/' . $logoUrl) ?>" style="max-height: 80px;" class="mb-2">
        <?php endif; ?>
        <h4 class="fw-bold">Control de Inventario, Insumos y Ventas ALISAKA</h4>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Correo Electrónico</label>
            <input type="email" name="email" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Ingresar</button>
    </form>
</div>
</body>
</html>
