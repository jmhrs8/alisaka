<?php
session_start();
// Validar que exista sesión activa (opcionalmente restringir a rol 'admin')
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_apagado'])) {
    // Ejecutar el apagado del sistema operativo de forma segura
    shell_exec('sudo /sbin/shutdown -h now');
    $mensaje = "El servidor se está apagando de forma segura. Puedes desconectarlo o apagar el UPS en 30 segundos.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Apagar Servidor ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex align-items-center justify-content-center vh-100">
    <div class="card bg-secondary text-white shadow-lg p-4 text-center" style="max-width: 450px;">
        <h3 class="fw-bold mb-3"><i class="bi bi-power text-danger me-2"></i> Apagado del Servidor</h3>
        <?php if (isset($mensaje)): ?>
            <div class="alert alert-warning fw-bold"><?= $mensaje ?></div>
        <?php else: ?>
            <p>¿Estás seguro de que deseas apagar la Raspberry Pi? Esto detendrá el sistema ERP para todos los usuarios.</p>
            <form method="POST">
                <button type="submit" name="confirmar_apagado" value="1" class="btn btn-danger btn-lg w-100 fw-bold mb-2">
                    Si, Apagar Servidor
                </button>
                <a href="index.php" class="btn btn-light w-100">Cancelar</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
