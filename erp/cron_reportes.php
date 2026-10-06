<?php
// Asegurar ejecuciones únicamente desde consola (CLI) o localhost
if (php_sapi_name() !== 'cli' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    die("Acceso no autorizado.");
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

try {
    // 1. Obtener configuración
    $stmt = $pdo->query("SELECT * FROM configuracion_reportes WHERE id = 1 AND activo = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        exit("El envío automático de reportes está desactivado o no configurado.\n");
    }

    $frecuencia = $config['frecuencia']; // diario, semanal, mensual
    $periodoRep = $config['periodo_reporte']; // semana, quincena, mes, ano
    $destinatarios = array_map('trim', explode(',', $config['destinatarios']));

    // 2. Validar si corresponde enviar hoy según la frecuencia
    $hoy = date('Y-m-d');
    $diaSemana = date('N'); // 1 (Lunes) a 7 (Domingo)
    $diaMes = date('j');    // 1 a 31

    $debeEnviar = false;

    switch ($frecuencia) {
        case 'diario':
            $debeEnviar = true;
            break;
        case 'semanal':
            // Se envía los lunes (Día 1)
            if ($diaSemana == 1) {
                $debeEnviar = true;
            }
            break;
        case 'mensual':
            // Se envía el primer día del mes
            if ($diaMes == 1) {
                $debeEnviar = true;
            }
            break;
    }

    if (!$debeEnviar) {
        exit("Hoy ($hoy) no corresponde el envío según la frecuencia '$frecuencia'.\n");
    }

    // 3. Calcular rango de fechas para las métricas del reporte
    $fechaFin = date('Y-m-d 23:59:59');
    switch ($periodoRep) {
        case 'semana':
            $fechaInicio = date('Y-m-d 00:00:00', strtotime('monday this week'));
            break;
        case 'quincena':
            $fechaInicio = ($diaMes <= 15) ? date('Y-m-01 00:00:00') : date('Y-m-16 00:00:00');
            break;
        case 'ano':
            $fechaInicio = date('Y-01-01 00:00:00');
            break;
        case 'mes':
        default:
            $fechaInicio = date('Y-m-01 00:00:00');
            break;
    }

    // 4. Consultar métricas financieras
    $params = [':f_inicio' => $fechaInicio, ':f_fin' => $fechaFin];

    // Total Ventas
    $stmtV = $pdo->prepare("SELECT SUM(COALESCE(monto_total, total, 0)) FROM salidas WHERE COALESCE(fecha, fecha_salida) BETWEEN :f_inicio AND :f_fin");
    $stmtV->execute($params);
    $totalVentas = floatval($stmtV->fetchColumn() ?? 0);

    // Total Cobrado y Pendiente
    $sqlBalance = "SELECT 
        SUM(CASE WHEN tipo_pago = 'contado' OR estado_cobro = 'cobrado' THEN COALESCE(monto_total, total, 0) ELSE COALESCE(monto_total, total, 0) - COALESCE(cxc.monto, 0) END) AS cobrado,
        SUM(CASE WHEN tipo_pago != 'contado' AND (estado_cobro IS NULL OR estado_cobro != 'cobrado') THEN COALESCE(cxc.monto, COALESCE(monto_total, total, 0)) ELSE 0 END) AS pendiente
        FROM salidas s LEFT JOIN cuentas_cobrar cxc ON s.id = cxc.salida_id
        WHERE COALESCE(s.fecha, s.fecha_salida) BETWEEN :f_inicio AND :f_fin";
    $stmtB = $pdo->prepare($sqlBalance);
    $stmtB->execute($params);
    $rowB = $stmtB->fetch(PDO::FETCH_ASSOC);

    $totalCobrado = floatval($rowB['cobrado'] ?? 0);
    $totalPendiente = floatval($rowB['pendiente'] ?? 0);

    // Total Por Pagar (CxP)
    $totalCxP = floatval($pdo->query("SELECT SUM(monto) FROM cuentas_pagar WHERE estatus = 'pendiente'")->fetchColumn() ?? 0);

    // 5. Construir plantilla HTML del correo
    $asunto = "Reporte Automatizado ERP ALISAKA (" . ucfirst($frecuencia) . ") - " . date('d/m/Y');
    $cuerpoHTML = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; border: 1px solid #ddd; padding: 20px; border-radius: 8px;'>
        <h2 style='color: #0d6efd; border-bottom: 2px solid #0d6efd; padding-bottom: 10px;'>PLÁSTICOS ALISAKA - Informe Financiero</h2>
        <p>Se ha generado automáticamente el reporte correspondiente a la frecuencia <strong>" . strtoupper($frecuencia) . "</strong>.</p>
        
        <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
            <tr style='background-color: #f8f9fa;'>
                <td style='padding: 10px; border: 1px solid #dee2e6;'><strong>Ventas Totales Emitidas:</strong></td>
                <td style='padding: 10px; border: 1px solid #dee2e6; color: #0d6efd; font-weight: bold;'>$" . number_format($totalVentas, 2) . "</td>
            </tr>
            <tr>
                <td style='padding: 10px; border: 1px solid #dee2e6;'><strong>Total Cobrado / Pagado:</strong></td>
                <td style='padding: 10px; border: 1px solid #dee2e6; color: #198754; font-weight: bold;'>$" . number_format($totalCobrado, 2) . "</td>
            </tr>
            <tr style='background-color: #f8f9fa;'>
                <td style='padding: 10px; border: 1px solid #dee2e6;'><strong>Por Cobrar (Clientes - CxC):</strong></td>
                <td style='padding: 10px; border: 1px solid #dee2e6; color: #ffc107; font-weight: bold;'>$" . number_format($totalPendiente, 2) . "</td>
            </tr>
            <tr>
                <td style='padding: 10px; border: 1px solid #dee2e6;'><strong>Por Pagar (Proveedores - CxP):</strong></td>
                <td style='padding: 10px; border: 1px solid #dee2e6; color: #dc3545; font-weight: bold;'>$" . number_format($totalCxP, 2) . "</td>
            </tr>
        </table>

        <br>
        <p style='font-size: 12px; color: #6c757d; text-align: center;'>Este correo fue enviado de manera automática por el sistema ERP ALISAKA en la Raspberry Pi 4.</p>
    </div>
    ";

    // 6. Enviar correo a cada destinatario
    foreach ($destinatarios as $correo) {
        if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            enviarCorreoSistema($correo, $asunto, $cuerpoHTML);
            echo "Reporte enviado exitosamente a: $correo\n";
        }
    }

} catch (Exception $e) {
    error_log("Error en el cron de reportes: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
