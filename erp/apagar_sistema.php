<?php
session_start();

// Validar sesión activa antes de procesar
if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Control de estado para verificar si la orden ya fue procesada
$ejecutado = isset($_GET['estado']) && $_GET['estado'] === 'apagando';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_apagado'])) {
    // 1. Ejecutar el comando de apagado en segundo plano (permite enviar la respuesta HTTP completa)
    shell_exec('sudo /sbin/shutdown -h now > /dev/null 2>&1 &');

    // 2. Destruir la sesión activa
    session_destroy();

    // 3. Redirigir por GET para convertir la solicitud y bloquear el reenvío por F5
    header("Location: apagar_sistema.php?estado=apagando");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apagado del Servidor - PLASTICOS ALISAKA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-dark text-white d-flex align-items-center justify-content-center vh-100">
    <div class="card bg-secondary text-white shadow-lg p-4 text-center" style="max-width: 480px; border-radius: 12px;">
        
        <?php if ($ejecutado): ?>
            <!-- PANTALLA POST-APAGADO (PROTEGIDA CONTRA F5 / REFRESCAR) -->
            <i class="bi bi-power text-danger display-1 mb-3 d-block"></i>
            <h3 class="fw-bold text-warning mb-3">Servidor Apagándose...</h3>
            <div class="alert alert-warning text-dark fw-bold text-start mb-3" role="alert">
                <i class="bi bi-info-circle-fill me-1"></i> El servidor ha iniciado la secuencia de cierre seguro.
            </div>
            <p class="small text-light text-start mb-4">
                <strong>Instrucciones físicas:</strong>
                <ol class="text-start small ps-3 mb-0">
                    <li>Espera a que el LED verde de actividad de la Raspberry Pi deje de parpadear.</li>
                    <li>Una vez que solo quede encendido el LED rojo fijo, puedes apagar el UPS con total seguridad.</li>
                </ol>
            </p>
            <a href="login.php" class="btn btn-light w-100 fw-bold">Volver a Iniciar Sesión</a>

        <?php else: ?>
            <!-- FORMULARIO DE CONFIRMACIÓN -->
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
