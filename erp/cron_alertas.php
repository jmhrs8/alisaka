<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/config.php';

// 1. Obtener productos con stock bajo
$stmtStock = $pdo->query("SELECT * FROM productos WHERE stock_actual <= stock_minimo");
$productosBajos = $stmtStock->fetchAll();

// 2. Obtener cuentas por pagar próximas a vencer (próximos 3 días) o vencidas
$stmtCuentas = $pdo->query("SELECT *, DATEDIFF(fecha_promesa_pago, CURDATE()) as dias FROM cuentas_por_pagar WHERE estado != 'liquidado' AND DATEDIFF(fecha_promesa_pago, CURDATE()) <= 3");
$cuentasCriticas = $stmtCuentas->fetchAll();

if (empty($productosBajos) && empty($cuentasCriticas)) {
    exit("No hay alertas pendientes hoy.\n");
}

// Construcción del mensaje de correo (HTML)
$to = $empresa['email_notificaciones'];
$subject = "Alertas del Sistema ERP - " . $empresa['nombre_empresa'] . " (" . date('d/m/Y') . ")";

$message = "<html><head><style>body{font-family:Arial,sans-serif;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #ddd;padding:8px;}</style></head><body>";
$message .= "<h2>Reporte Automático de Alertas y Existencias</h2>";

if (!empty($productosBajos)) {
    $message .= "<h3 style='color:red;'>Atención: Productos con Stock Bajo / Por Agotarse</h3>";
    $message .= "<table><tr><th>Código</th><th>Producto</th><th>Medida</th><th>Stock Actual</th><th>Mínimo</th></tr>";
    foreach ($productosBajos as $p) {
        $message .= "<tr><td>{$p['codigo']}</td><td>{$p['nombre']}</td><td>{$p['tipo_unidad']}</td><td style='color:red;'><b>{$p['stock_actual']}</b></td><td>{$p['stock_minimo']}</td></tr>";
    }
    $message .= "</table>";
}

if (!empty($cuentasCriticas)) {
    $message .= "<h3 style='color:orange;'>Atención: Cuentas por Pagar Próximas o Vencidas</h3>";
    $message .= "<table><tr><th>Proveedor</th><th>Descripción</th><th>Monto Total</th><th>Pendiente</th><th>Vencimiento</th></tr>";
    foreach ($cuentasCriticas as $c) {
        $pend = $c['monto_total'] - $c['monto_pagado'];
        $message .= "<tr><td>{$c['proveedor_cliente']}</td><td>{$c['descripcion']}</td><td>$".number_format($c['monto_total'],2)."</td><td>$".number_format($pend,2)."</td><td>{$c['fecha_promesa_pago']}</td></tr>";
    }
    $message .= "</table>";
}

$message .= "<br><p>Mensaje generado automáticamente por ERP RPi4.</p></body></html>";

// Cabeceras para correo HTML
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: no-reply@" . $_SERVER['SERVER_NAME'] . "\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "Reporte enviado exitosamente a: $to\n";
} else {
    echo "Error al enviar el correo.\n";
}
?>
