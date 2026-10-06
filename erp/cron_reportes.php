<?php
// =========================================================================
// SCRIPT DE EJECUCIÓN EN SEGUNDO PLANO PARA REPORTES AUTOMÁTICOS
// Sistema: ERP PLÁSTICOS ALISAKA
// =========================================================================

// Permitir ejecución desde consola (CLI) o localhost
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    die("Acceso no autorizado.\n");
}

// Cargar la conexión ($pdo) y la función de envío de correos
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

// Permite pasar el argumento --force desde la terminal para pruebas: php cron_reportes.php --force
$forzarEnvio = in_array('--force', $argv ?? []);

try {
    // 1. Obtener la configuración actual de los reportes
    $stmt = $pdo->query("SELECT * FROM configuracion_reportes WHERE id = 1 AND activo = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        exit("El envío automático de reportes está DESACTIVADO en el sistema.\n");
    }

    $frecuencia    = $config['frecuencia'];       // diario, semanal, mensual
    $periodoRep    = $config['periodo_reporte'];  // semana, quincena, mes, ano
    $rawDestino    = $config['destinatarios'];

    // Convertir la lista de correos separados por comas en un array limpio
    $destinatarios = array_filter(array_map('trim', explode(',', $rawDestino)));

    if (empty($destinatarios)) {
        exit("No hay destinatarios configurados para el envío.\n");
    }

    // 2. Verificar la frecuencia de envío
    $hoy       = date('Y-m-d');
    $diaSemana = date('N'); // 1 = Lunes, 7 = Domingo
    $diaMes    = date('j'); // 1 a 31

    $debeEnviar = false;

    if ($forzarEnvio) {
        $debeEnviar = true;
        echo "[MODO PRUEBA] Forzando el envío del correo...\n";
    } else {
        switch ($frecuencia) {
            case 'diario':
                $debeEnviar = true;
                break;
            case 'semanal':
                // Se ejecuta automáticamente los días Lunes
                if ($diaSemana == 1) {
                    $debeEnviar = true;
                }
                break;
            case 'mensual':
                // Se ejecuta el primer día de cada mes
                if ($diaMes == 1) {
                    $debeEnviar = true;
                }
                break;
        }
    }

    if (!$debeEnviar) {
        exit("Hoy ($hoy) no corresponde el envío según la frecuencia '$frecuencia'. (Usa '--force' para probarlo hoy mismo).\n");
    }

    // 3. Determinar rango de fechas para las métricas del informe
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

    $params = [':f_inicio' => $fechaInicio, ':f_fin' => $fechaFin];

    // 4. Consultar métricas del ERP
    // A. Total Ventas Emitidas
    $stmtV = $pdo->prepare("SELECT SUM(COALESCE(monto_total, total, 0)) FROM salidas WHERE COALESCE(fecha, fecha_salida) BETWEEN :f_inicio AND :f_fin");
    $stmtV->execute($params);
    $montoTotalVentas = floatval($stmtV->fetchColumn() ?? 0);

    // B. Total Cobrado vs Pendiente (CxC)
    $sqlBalance = "SELECT 
        SUM(CASE WHEN tipo_pago = 'contado' OR estado_cobro = 'cobrado' THEN COALESCE(monto_total, total, 0) ELSE COALESCE(monto_total, total, 0) - COALESCE(cxc.monto, 0) END) AS cobrado,
        SUM(CASE WHEN tipo_pago != 'contado' AND (estado_cobro IS NULL OR estado_cobro != 'cobrado') THEN COALESCE(cxc.monto, COALESCE(monto_total, total, 0)) ELSE 0 END) AS pendiente
        FROM salidas s 
        LEFT JOIN cuentas_cobrar cxc ON s.id = cxc.salida_id
        WHERE COALESCE(s.fecha, s.fecha_salida) BETWEEN :f_inicio AND :f_fin";
    $stmtB = $pdo->prepare($sqlBalance);
    $stmtB->execute($params);
    $rowB = $stmtB->fetch(PDO::FETCH_ASSOC);

    $montoTotalCobrado   = floatval($rowB['cobrado'] ?? 0);
    $montoTotalPendiente = floatval($rowB['pendiente'] ?? 0);

    // C. Total Pasivos a Proveedores (CxP)
    $stmtP = $pdo->query("SELECT SUM(monto) FROM cuentas_pagar WHERE estatus = 'pendiente'");
    $montoTotalCxP = floatval($stmtP->fetchColumn() ?? 0);

    // 5. Construir plantilla HTML del correo
    $asunto = "Informe Financiero ERP ALISAKA (" . ucfirst($frecuencia) . ") - " . date('d/m/Y');
    
    $cuerpoHTML = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 650px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
        <div style='background-color: #0d6efd; padding: 20px; text-align: center; color: #ffffff;'>
            <h2 style='margin: 0; font-size: 22px;'>PLÁSTICOS ALISAKA</h2>
            <p style='margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;'>Reporte Ejecutivo Automático</p>
        </div>
        
        <div style='padding: 25px; background-color: #ffffff;'>
            <p style='font-size: 15px; line-height: 1.5;'>Estimado usuario,</p>
            <p style='font-size: 14px; color: #555;'>Adjunto la síntesis financiera consolidada con frecuencia <strong>" . strtoupper($frecuencia) . "</strong> correspondiente al período seleccionado (<strong>" . strtoupper($periodoRep) . "</strong>):</p>
            
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr style='background-color: #f8f9fa;'>
                    <td style='padding: 12px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Ventas Emitidas:</strong></td>
                    <td style='padding: 12px; border: 1px solid #dee2e6; color: #0d6efd; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalVentas, 2) . "</td>
                </tr>
                <tr>
                    <td style='padding: 12px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Total Cobrado / Liquidado:</strong></td>
                    <td style='padding: 12px; border: 1px solid #dee2e6; color: #198754; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalCobrado, 2) . "</td>
                </tr>
                <tr style='background-color: #f8f9fa;'>
                    <td style='padding: 12px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Saldo por Cobrar (Clientes - CxC):</strong></td>
                    <td style='padding: 12px; border: 1px solid #dee2e6; color: #ffc107; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalPendiente, 2) . "</td>
                </tr>
                <tr>
                    <td style='padding: 12px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Deuda Pendiente (Proveedores - CxP):</strong></td>
                    <td style='padding: 12px; border: 1px solid #dee2e6; color: #dc3545; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalCxP, 2) . "</td>
                </tr>
            </table>

            <p style='font-size: 12px; color: #888; text-align: center; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                Mensaje generado de forma automática por el sistema ERP ALISAKA en Raspberry Pi 4.<br>Fecha de emisión: " . date('d/m/Y H:i:s') . "
            </p>
        </div>
    </div>
    ";

    // 6. Enviar a cada dirección registrada
    foreach ($destinatarios as $correo) {
        if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $exito = enviarCorreoSistema($correo, $asunto, $cuerpoHTML);
            if ($exito) {
                echo "[OK] Reporte enviado correctamente a: $correo\n";
            } else {
                echo "[ERROR] Falló el envío del correo a: $correo (Revisar logs en /var/log/apache2/error.log)\n";
            }
        } else {
            echo "[WARN] Correo no válido omitido: $correo\n";
        }
    }

} catch (Exception $e) {
    error_log("Error crítico en cron_reportes.php: " . $e->getMessage());
    echo "Error crítico: " . $e->getMessage() . "\n";
}
