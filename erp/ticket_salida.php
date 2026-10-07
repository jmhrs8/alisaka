<?php
session_start();

// Carga directa de la conexión oficial
require_once __DIR__ . '/config/db.php';

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    die("ID de venta/salida no válido.");
}

// 1. Obtener la información general de la salida
$stmt = $pdo->prepare("SELECT * FROM salidas WHERE id = ?");
$stmt->execute([$id]);
$venta = $stmt->fetch();

if (!$venta) {
    die("La nota o salida #{$id} no existe en la base de datos.");
}

// 2. Obtener los productos de esta salida
$stmtDetalle = $pdo->prepare("
    SELECT ds.*, p.nombre AS producto_nombre
    FROM detalle_salidas ds
    LEFT JOIN productos p ON ds.producto_id = p.id
    WHERE ds.salida_id = ?
");
$stmtDetalle->execute([$id]);
$detalles = $stmtDetalle->fetchAll();

// 3. Cálculos de importes
$subtotal = floatval($venta['subtotal'] ?? 0);
$iva = floatval($venta['iva'] ?? 0);
$total = floatval($venta['total'] ?? $venta['monto_total'] ?? 0);
$fecha = !empty($venta['fecha']) ? date('d/m/Y H:i', strtotime($venta['fecha'])) : date('d/m/Y H:i');
$metodoPago = ucfirst(htmlspecialchars($venta['metodo_cobro'] ?? $venta['metodo_pago'] ?? 'Efectivo'));
$estatusCobro = (($venta['estado_cobro'] ?? '') === 'credito') ? '(Crédito)' : '(Cobrado)';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket de Salida #<?= htmlspecialchars($venta['id']) ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.8rem;
            background-color: #f4f6f9;
            color: #000;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
        }
        .ticket-container {
            width: 280px;
            background: #fff;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }
        .empresa-title {
            font-size: 1rem;
            font-weight: bold;
            display: block;
        }
        .info-sec {
            font-size: 0.75rem;
            margin-bottom: 8px;
        }
        .tabla-productos {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
            margin-bottom: 8px;
        }
        .tabla-productos th {
            text-align: left;
            border-bottom: 1px dashed #000;
            padding-bottom: 4px;
        }
        .tabla-productos td {
            padding: 2px 0;
        }
        .tabla-productos .prod-row {
            font-weight: bold;
            padding-top: 4px;
        }
        .tabla-productos .det-row {
            border-bottom: 1px dashed #ccc;
            padding-bottom: 4px;
        }
        .totales-sec {
            font-size: 0.8rem;
            text-align: right;
        }
        .totales-sec .total-final {
            font-weight: bold;
            font-size: 0.9rem;
            margin-top: 4px;
            border-top: 1px dashed #000;
            padding-top: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 12px;
            padding-top: 8px;
            border-top: 1px dashed #000;
            font-size: 0.65rem;
            color: #555;
        }
        .btn-imprimir {
            display: block;
            width: 100%;
            margin-top: 15px;
            padding: 8px;
            background-color: #0d6efd;
            color: white;
            text-align: center;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            font-family: sans-serif;
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .ticket-container {
                box-shadow: none;
                border: none;
                width: 100%;
                padding: 0;
            }
            .btn-imprimir {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="ticket-container">
    <div class="header">
        <strong class="empresa-title">PLASTICOS ALISAKA</strong>
        <small>Ticket de Venta / Salida #<?= htmlspecialchars($venta['id']) ?></small>
    </div>

    <div class="info-sec">
        <div><strong>Fecha:</strong> <?= $fecha ?></div>
        <div><strong>Cliente:</strong> <?= htmlspecialchars($venta['cliente'] ?? 'Público General') ?></div>
        <div><strong>Pago:</strong> <?= $metodoPago ?> <?= $estatusCobro ?></div>
    </div>

    <table class="tabla-productos">
        <thead>
            <tr>
                <th style="text-align: left;">Cant/Prod</th>
                <th style="text-align: right;">P.U.</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($detalles)): ?>
                <?php foreach ($detalles as $det): ?>
                    <tr>
                        <td colspan="3" class="prod-row"><?= htmlspecialchars($det['producto_nombre'] ?? 'Producto General') ?></td>
                    </tr>
                    <tr class="det-row">
                        <td><?= number_format(floatval($det['cantidad'] ?? 0), 2) ?></td>
                        <td style="text-align: right;">$<?= number_format(floatval($det['precio_unitario'] ?? 0), 2) ?></td>
                        <td style="text-align: right;">$<?= number_format(floatval($det['subtotal'] ?? ($det['cantidad'] * $det['precio_unitario'])), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3" class="prod-row"><?= htmlspecialchars($venta['concepto'] ?? 'Venta General') ?></td>
                </tr>
                <tr class="det-row">
                    <td><?= number_format(floatval($venta['cantidad'] ?? 1), 2) ?></td>
                    <td style="text-align: right;">$<?= number_format(floatval($venta['precio_unitario'] ?? $subtotal), 2) ?></td>
                    <td style="text-align: right;">$<?= number_format($subtotal, 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totales-sec">
        <div>Subtotal: $<?= number_format($subtotal, 2) ?></div>
        <div>IVA (<?= ($venta['requiere_factura'] ?? 0) == 1 ? '16%' : '0%' ?>): $<?= number_format($iva, 2) ?></div>
        <div class="total-final">
            TOTAL: $<?= number_format($total, 2) ?>
        </div>
    </div>

    <div class="footer">
        ¡Gracias por su compra!<br>
        PLASTICOS ALISAKA
    </div>

    <button class="btn-imprimir" onclick="window.print();">
        Imprimir Ticket
    </button>
</div>

</body>
</html>
