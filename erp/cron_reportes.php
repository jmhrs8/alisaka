<?php
// =========================================================================
// SCRIPT DE EJECUCIÓN EN SEGUNDO PLANO PARA REPORTES AUTOMÁTICOS
// Sistema: ERP PLÁSTICOS ALISAKA
// =========================================================================

if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    die("Acceso no autorizado.\n");
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

$forzarEnvio = in_array('--force', $argv ?? []);

try {
    // 1. Obtener la configuración actual de los reportes
    $stmt = $pdo->query("SELECT * FROM configuracion_reportes WHERE id = 1 AND activo = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config && !$forzarEnvio) {
        exit("El envío automático de reportes está DESACTIVADO en el sistema.\n");
    }

    $frecuencia    = $config['frecuencia'] ?? 'semanal';
    $periodoRep    = $config['periodo_reporte'] ?? 'mes';
    $rawDestino    = $config['destinatarios'] ?? '';

    $destinatarios = array_filter(array_map('trim', explode(',', $rawDestino)));

    if (empty($destinatarios) && !$forzarEnvio) {
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
                if ($diaSemana == 1) $debeEnviar = true;
                break;
            case 'mensual':
                if ($diaMes == 1) $debeEnviar = true;
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

    // 4. CONSULTAS A LA BASE DE DATOS

    // A. Ventas Totales y Cantidad de Transacciones
    $stmtV = $pdo->prepare("SELECT COUNT(*) as total_num, SUM(COALESCE(monto_total, total, 0)) as monto FROM salidas WHERE COALESCE(fecha, fecha_salida) BETWEEN :f_inicio AND :f_fin");
    $stmtV->execute($params);
    $resVentas = $stmtV->fetch(PDO::FETCH_ASSOC);

    $numVentas         = intval($resVentas['total_num'] ?? 0);
    $montoTotalVentas = floatval($resVentas['monto'] ?? 0);

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

    // D. Alerta de Productos con Stock Bajo / Agotado
    $stmtStock = $pdo->query("SELECT nombre, stock, stock_minimo FROM productos WHERE stock <= stock_minimo ORDER BY stock ASC LIMIT 10");
    $productosStockBajo = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

    // E. Próximos Cobros A Vencer o Vencidos (CxC)
    $stmtCxCVence = $pdo->query("SELECT cliente, monto, fecha_vencimiento FROM cuentas_cobrar WHERE estatus = 'pendiente' ORDER BY fecha_vencimiento ASC LIMIT 5");
    $cxcPorVencer = $stmtCxCVence->fetchAll(PDO::FETCH_ASSOC);

    // F. Próximos Pagos a Proveedores A Vencer o Vencidos (CxP)
    $stmtCxPVence = $pdo->query("SELECT p.nombre AS proveedor, cp.monto, cp.fecha_vencimiento FROM cuentas_pagar cp LEFT JOIN proveedores p ON cp.proveedor_id = p.id WHERE cp.estatus = 'pendiente' ORDER BY cp.fecha_vencimiento ASC LIMIT 5");
    $cxpPorVencer = $stmtCxPVence->fetchAll(PDO::FETCH_ASSOC);


    // 5. CONSTRUCCIÓN DE LA PLANTILLA HTML DE CORREO

    $asunto = "Informe General y Alertas ERP ALISAKA (" . ucfirst($frecuencia) . ") - " . date('d/m/Y');

    // Filas para Productos con Stock Bajo
    $htmlStock = "";
    if (empty($productosStockBajo)) {
        $htmlStock = "<tr><td colspan='3' style='padding: 8px; text-align: center; color: #198754;'>Todo el inventario está en niveles óptimos.</td></tr>";
    } else {
        foreach ($productosStockBajo as $prod) {
            $htmlStock .= "<tr>
                <td style='padding: 8px; border: 1px solid #dee2e6;'>" . htmlspecialchars($prod['nombre']) . "</td>
                <td style='padding: 8px; border: 1px solid #dee2e6; text-align: center; font-weight: bold; color: #dc3545;'>" . $prod['stock'] . "</td>
                <td style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>" . $prod['stock_minimo'] . "</td>
            </tr>";
        }
    }

    // Filas para Cuentas Por Cobrar por Vencer
    $htmlCxC = "";
    if (empty($cxcPorVencer)) {
        $htmlCxC = "<tr><td colspan='3' style='padding: 8px; text-align: center; color: #6c757d;'>No hay cobros pendientes registrados.</td></tr>";
    } else {
        foreach ($cxcPorVencer as $cxc) {
            $dias = (strtotime($cxc['fecha_vencimiento']) - strtotime($hoy)) / 86400;
            $alerta = $dias < 0 ? "<span style='color:red;'>(Vencido)</span>" : "($dias días)";
            $htmlCxC .= "<tr>
                <td style='padding: 8px; border: 1px solid #dee2e6;'>" . htmlspecialchars($cxc['cliente']) . "</td>
                <td style='padding: 8px; border: 1px solid #dee2e6; text-align: right; font-weight: bold;'>$" . number_format($cxc['monto'], 2) . "</td>
                <td style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>" . date('d/m/Y', strtotime($cxc['fecha_vencimiento'])) . " $alerta</td>
            </tr>";
        }
    }

    // Filas para Cuentas Por Pagar por Vencer
    $htmlCxP = "";
    if (empty($cxpPorVencer)) {
        $htmlCxP = "<tr><td colspan='3' style='padding: 8px; text-align: center; color: #6c757d;'>No hay pasivos pendientes de pago.</td></tr>";
    } else {
        foreach ($cxpPorVencer as $cxp) {
            $dias = (strtotime($cxp['fecha_vencimiento']) - strtotime($hoy)) / 86400;
            $alerta = $dias < 0 ? "<span style='color:red;'>(Vencido)</span>" : "($dias días)";
            $htmlCxP .= "<tr>
                <td style='padding: 8px; border: 1px solid #dee2e6;'>" . htmlspecialchars($cxp['proveedor'] ?? 'Proveedor General') . "</td>
                <td style='padding: 8px; border: 1px solid #dee2e6; text-align: right; font-weight: bold;'>$" . number_format($cxp['monto'], 2) . "</td>
                <td style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>" . date('d/m/Y', strtotime($cxp['fecha_vencimiento'])) . " $alerta</td>
            </tr>";
        }
    }

    // Cuerpo Completo HTML
    $cuerpoHTML = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 700px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
        <div style='background-color: #0d6efd; padding: 20px; text-align: center; color: #ffffff;'>
            <h2 style='margin: 0; font-size: 22px;'>PLÁSTICOS ALISAKA</h2>
            <p style='margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;'>Reporte Ejecutivo de Operaciones e Inventario</p>
        </div>
        
        <div style='padding: 25px; background-color: #ffffff;'>
            <p style='font-size: 14px; color: #555;'>Resumen financiero y operativo correspondiente al período (<strong>" . strtoupper($periodoRep) . "</strong>):</p>
            
            <h3 style='color: #0d6efd; border-bottom: 2px solid #0d6efd; padding-bottom: 5px; margin-top: 20px;'>1. Resumen Financiero y Ventas</h3>
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                <tr style='background-color: #f8f9fa;'>
                    <td style='padding: 10px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Número de Ventas Registradas:</strong></td>
                    <td style='padding: 10px; border: 1px solid #dee2e6; font-weight: bold; font-size: 15px; text-align: right;'>$numVentas transacción(es)</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Ventas Totales Emitidas:</strong></td>
                    <td style='padding: 10px; border: 1px solid #dee2e6; color: #0d6efd; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalVentas, 2) . "</td>
                </tr>
                <tr style='background-color: #f8f9fa;'>
                    <td style='padding: 10px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Total Cobrado / Liquidado:</strong></td>
                    <td style='padding: 10px; border: 1px solid #dee2e6; color: #198754; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalCobrado, 2) . "</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Saldo por Cobrar (Clientes):</strong></td>
                    <td style='padding: 10px; border: 1px solid #dee2e6; color: #ffc107; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalPendiente, 2) . "</td>
                </tr>
                <tr style='background-color: #f8f9fa;'>
                    <td style='padding: 10px; border: 1px solid #dee2e6; font-size: 14px;'><strong>Deuda Pendiente (Proveedores):</strong></td>
                    <td style='padding: 10px; border: 1px solid #dee2e6; color: #dc3545; font-weight: bold; font-size: 15px; text-align: right;'>$" . number_format($montoTotalCxP, 2) . "</td>
                </tr>
            </table>

            <h3 style='color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 5px; margin-top: 25px;'>2. Alerta de Stock Bajo / Agotado</h3>
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;'>
                <thead>
                    <tr style='background-color: #f8f9fa;'>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: left;'>Producto</th>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>Stock Actual</th>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>Mínimo Requerido</th>
                    </tr>
                </thead>
                <tbody>$htmlStock</tbody>
            </table>

            <h3 style='color: #ffc107; border-bottom: 2px solid #ffc107; padding-bottom: 5px; margin-top: 25px;'>3. Próximos Cobros Pendientes (Clientes)</h3>
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;'>
                <thead>
                    <tr style='background-color: #f8f9fa;'>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: left;'>Cliente</th>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: right;'>Monto</th>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>Fecha Vencimiento</th>
                    </tr>
                </thead>
                <tbody>$htmlCxC</tbody>
            </table>

            <h3 style='color: #6c757d; border-bottom: 2px solid #6c757d; padding-bottom: 5px; margin-top: 25px;'>4. Próximos Pagos Pendientes (Proveedores)</h3>
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;'>
                <thead>
                    <tr style='background-color: #f8f9fa;'>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: left;'>Proveedor</th>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: right;'>Monto</th>
                        <th style='padding: 8px; border: 1px solid #dee2e6; text-align: center;'>Fecha Vencimiento</th>
                    </tr>
                </thead>
                <tbody>$htmlCxP</tbody>
            </table>

            <p style='font-size: 12px; color: #888; text-align: center; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px;'>
                Mensaje generado de forma automática por el sistema ERP ALISAKA.<br>Fecha de emisión: " . date('d/m/Y H:i:s') . "
            </p>
        </div>
    </div>
    ";

    // 6. Enviar correos
    foreach ($destinatarios as $correo) {
        if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $exito = enviarCorreoSistema($correo, $asunto, $cuerpoHTML);
            if ($exito) {
                echo "[OK] Reporte completo enviado correctamente a: $correo\n";
            } else {
                echo "[ERROR] Falló el envío a: $correo\n";
            }
        }
    }

} catch (Exception $e) {
    error_log("Error crítico en cron_reportes.php: " . $e->getMessage());
    echo "Error crítico: " . $e->getMessage() . "\n";
}
