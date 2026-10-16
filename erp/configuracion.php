<?php
session_start();

// Validar que exista sesión activa
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Control de estado para evitar reenvíos de formulario
$ejecutado = isset($_GET['estado']) && $_GET['estado'] === 'apagando';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_apagado'])) {
    // 1. Ejecutar el comando de apagado en segundo plano para dar tiempo a responder el HTTP
    shell_exec('sudo /sbin/shutdown -h now > /dev/null 2>&1 &');

    // 2. Destruir la sesión para limpiar credenciales
    session_destroy();

    // 3. Redirigir por GET (evita que el botón F5/Refrescar vuelva a enviar el POST de apagado)
    header("Location: apagar_sistema.php?estado=apagando");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apagar Servidor ERP - PLASTICOS ALISAKA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-dark text-white d-flex align-items-center justify-content-center vh-100">
    <div class="card bg-secondary text-white shadow-lg p-4 text-center" style="max-width: 450px; border-radius: 12px;">
        
        <?php if ($ejecutado): ?>
            <!-- PANTALLA TRAS EJECUTAR EL APAGADO (Protegida contra F5) -->
            <i class="bi bi-power text-danger display-1 mb-3 d-block"></i>
            <h3 class="fw-bold text-warning mb-3">Servidor Apagándose...</h3>
            <div class="alert alert-warning text-dark fw-bold text-start mb-3" role="alert">
                <i class="bi bi-info-circle-fill me-1"></i> El servidor se está apagando de forma segura. Puedes desconectarlo o apagar el UPS en 30 segundos.
            </div>
            <p class="small text-light text-start mb-4">
                <strong>Instrucciones físicas:</strong>
                <ol class="text-start small ps-3 mb-0">
                    <li>Espera a que el LED verde de la Raspberry Pi deje de parpadear.</li>
                    <li>Una vez que solo quede el LED rojo encendido fijo, apaga el UPS con seguridad.</li>
                </ol>
            </p>
            <a href="login.php" class="btn btn-light w-100 fw-bold">Volver a Iniciar Sesión</a>

        <?php else: ?>
            <!-- FORMULARIO ORIGINAL DE CONFIRMACIÓN -->
            <h3 class="fw-bold mb-3"><i class="bi bi-power text-danger me-2"></i> Apagado del Servidor</h3>
            <p>¿Estás seguro de que deseas apagar la Raspberry Pi? Esto detendrá el sistema ERP para todos los usuarios.</p>
            
            <form method="POST" action="apagar_sistema.php">
                <button type="submit" name="confirmar_apagado" value="1" class="btn btn-danger btn-lg w-100 fw-bold mb-2 shadow">
                    Si, Apagar Servidor
                </button>
                <a href="configuracion.php" class="btn btn-light w-100 fw-bold">Cancelar</a>
            </form>
        <?php endif; ?>

    </div>
</body>
</html>
