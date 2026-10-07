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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nota de Compra #<?= htmlspecialchars($venta['id']) ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f9;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .ticket-container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #2b3a4a;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .logo {
            max-width: 180px;
            height: auto;
            margin-bottom: 10px;
        }
        .empresa-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: #1a237e;
            margin: 0;
            text-transform: uppercase;
        }
        .nota-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: #555;
            margin-top: 5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
        }
        .info-grid div span {
            font-weight: bold;
            color: #555;
        }
        .tabla-productos {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .tabla-productos th {
            background-color: #2b3a4a;
            color: white;
            padding: 8px;
            text-align: left;
        }
        .tabla-productos td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .tabla-productos .text-right {
            text-align: right;
        }
        .tabla-productos .text-center {
            text-align: center;
        }
        .totales-container {
            width: 250px;
            margin-left: auto;
            font-size: 0.95rem;
        }
        .totales-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
        }
        .totales-row.total-final {
            border-top: 2px solid #2b3a4a;
            font-weight: bold;
            font-size: 1.1rem;
            color: #2e7d32;
            padding-top: 8px;
            margin-top: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 0.8rem;
            color: #777;
            border-top: 1px dashed #ccc;
            padding-top: 15px;
        }
        .btn-imprimir {
            display: block;
            width: 100%;
            max-width: 200px;
            margin: 20px auto 0 auto;
            padding: 10px;
            background-color: #0d6efd;
            color: white;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-imprimir:hover {
            background-color: #0b5ed7;
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
                max-width: 100%;
                padding: 10px;
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
        <img src="assets/img/ALISAKA.JPG" alt="ALISAKA Logo" class="logo" onerror="this.style.display='none'">
        <div class="empresa-title">ALISAKA</div>
        <div class="nota-title">COMPROBANTE DE VENTA / SALIDA</div>
    </div>

    <div class="info-grid">
        <div><span>Folio Nota:</span> #<?= htmlspecialchars($venta['id']) ?></div>
        <div><span>Fecha:</span> <?= $fecha ?></div>
        <div><span>Cliente:</span> <?= htmlspecialchars($venta['cliente'] ?? 'Público General') ?></div>
        <div><span>Estatus:</span> <?= strtoupper(htmlspecialchars($venta['estado_cobro'] ?? 'COBRADO')) ?></div>
        <div><span>Método de Pago:</span> <?= ucfirst(htmlspecialchars($venta['metodo_cobro'] ?? $venta['metodo_pago'] ?? 'Efectivo')) ?></div>
        <div><span>Tipo Pago:</span> <?= ucfirst(htmlspecialchars($venta['tipo_pago'] ?? 'Contado')) ?></div>
    </div>

    <table class="tabla-productos">
        <thead>
            <tr>
                <th class="text-center">ID</th>
                <th>Producto / Descripción</th>
                <th class="text-center">Cant.</th>
                <th class="text-right">P. Unit.</th>
                <th class="text-right">Importe</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($detalles)): ?>
                <?php foreach ($detalles as $det): ?>
                    <tr>
                        <td class="text-center">#<?= htmlspecialchars($det['producto_id'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($det['producto_nombre'] ?? 'Producto General') ?></td>
                        <td class="text-center"><?= number_format(floatval($det['cantidad'] ?? 0), 2) ?></td>
                        <td class="text-right">$<?= number_format(floatval($det['precio_unitario'] ?? 0), 2) ?></td>
                        <td class="text-right">$<?= number_format(floatval($det['subtotal'] ?? ($det['cantidad'] * $det['precio_unitario'])), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td class="text-center">#<?= htmlspecialchars($venta['producto_id'] ?? '1') ?></td>
                    <td><?= htmlspecialchars($venta['concepto'] ?? $venta['producto_nombre'] ?? 'Venta General') ?></td>
                    <td class="text-center"><?= number_format(floatval($venta['cantidad'] ?? 1), 2) ?></td>
                    <td class="text-right">$<?= number_format(floatval($venta['precio_unitario'] ?? $subtotal), 2) ?></td>
                    <td class="text-right">$<?= number_format($subtotal, 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totales-container">
        <div class="totales-row">
            <span>Subtotal:</span>
            <span>$<?= number_format($subtotal, 2) ?></span>
        </div>
        <div class="totales-row">
            <span>IVA (<?= ($venta['requiere_factura'] ?? 0) == 1 ? '16%' : '0%' ?>):</span>
            <span>$<?= number_format($iva, 2) ?></span>
        </div>
        <div class="totales-row total-final">
            <span>Total Pagado:</span>
            <span>$<?= number_format($total, 2) ?></span>
        </div>
    </div>

    <div class="footer">
        <p>¡Gracias por su preferencia!</p>
        <p>Este documento es un comprobante de compra sin valor fiscal.</p>
    </div>

    <button class="btn-imprimir" onclick="window.print();">
        Imprimir Nota
    </button>
</div>

</body>
</html>
