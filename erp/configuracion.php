<?php
session_start();

// Validar que exista sesión activa
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Control de paso
$ejecutado = isset($_GET['estado']) && $_GET['estado'] === 'apagando';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_apagado'])) {
    // 1. Ejecutar el comando de apagado en segundo plano
    shell_exec('sudo /sbin/shutdown -h now > /dev/null 2>&1 &');

    // 2. Destruir la sesión actual para evitar reenvíos
    session_destroy();

    // 3. Redirigir por GET a una vista final limpia (evita que F5 vuelva a enviar el POST)
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
    <div class="card bg-secondary text-white shadow-lg p-4 text-center" style="max-width: 500px; border-radius: 12px;">
        
        <?php if ($ejecutado): ?>
            <!-- PANTALLA TRAS EJECUTAR EL APAGADO -->
            <div class="my-3">
                <i class="bi bi-power text-danger display-1 mb-3 d-block"></i>
                <h3 class="fw-bold text-warning mb-3">Servidor Apagándose...</h3>
                <div class="alert alert-danger fw-bold text-start" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> El sistema operativo ha iniciado la secuencia de cierre seguro.
                </div>
                <p class="small text-light text-start mb-3">
                    <strong>Instrucciones físicas:</strong>
                    <ol class="text-start small ps-3">
                        <li>Espera a que el LED verde de la Raspberry Pi deje de parpadear.</li>
                        <li>Una vez que solo quede el LED rojo fijo, puedes apagar el UPS de forma segura.</li>
                    </ol>
                </p>
                <a href="login.php" class="btn btn-outline-light btn-sm w-100">Ir a Login al Encender</a>
            </div>
        <?php else: ?>
            <!-- FORMULARIO DE CONFIRMACIÓN -->
            <div class="my-2">
                <i class="bi bi-shield-exclamation text-warning display-3 mb-2 d-block"></i>
                <h3 class="fw-bold mb-3">Confirmar Apagado</h3>
                <p class="text-light small mb-4">
                    ¿Estás seguro de que deseas apagar la Raspberry Pi? Esto cerrará las conexiones activas y detendrá el sistema ERP.
                </p>
                <form method="POST" action="apagar_sistema.php">
                    <button type="submit" name="confirmar_apagado" value="1" class="btn btn-danger btn-lg w-100 fw-bold mb-3 shadow">
                        <i class="bi bi-power me-2"></i> Sí, Apagar Servidor Ahora
                    </button>
                    <a href="configuracion.php" class="btn btn-dark w-100">
                        <i class="bi bi-arrow-left me-1"></i> Cancelar
                    </a>
                </form>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
