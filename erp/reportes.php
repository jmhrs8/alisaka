<?php
require_once 'includes/header.php';

// =========================================================================
// PARÁMETROS DE FILTRO (Periodo y Cliente)
// =========================================================================
$periodoSeleccionado = $_GET['periodo'] ?? 'mes'; // semana, quincena, mes, ano
$clienteSeleccionado = $_GET['cliente'] ?? 'todos';

$fechaInicio = '';
$fechaFin    = date('Y-m-d 23:59:59');

switch ($periodoSeleccionado) {
    case 'semana':
        $fechaInicio = date('Y-m-d 00:00:00', strtotime('monday this week'));
        break;
    case 'quincena':
        $diaActual = intval(date('j'));
        if ($diaActual <= 15) {
            $fechaInicio = date('Y-m-01 00:00:00');
            $fechaFin    = date('Y-m-15 23:59:59');
        } else {
            $fechaInicio = date('Y-m-16 00:00:00');
            $fechaFin    = date('Y-m-t 23:59:59');
        }
        break;
    case 'ano':
        $fechaInicio = date('Y-01-01 00:00:00');
        break;
    case 'mes':
    default:
        $fechaInicio = date('Y-m-01 00:00:00');
        break;
}

// =========================================================================
// CONSULTAS SQL Y DATOS
// =========================================================================

// 1. Obtener lista de clientes únicos
$listaClientes = [];
try {
    $listaClientes = $pdo->query("SELECT DISTINCT cliente FROM salidas WHERE cliente IS NOT NULL AND cliente != '' ORDER BY cliente ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {}

// 2. Construir cláusula WHERE corregida
$whereSalidas = " WHERE COALESCE(fecha, fecha_salida) BETWEEN :f_inicio AND :f_fin ";
$paramsSalidas = [':f_inicio' => $fechaInicio, ':f_fin' => $fechaFin];

if ($clienteSeleccionado !== 'todos') {
    $whereSalidas .= " AND cliente = :cliente ";
    $paramsSalidas[':cliente'] = $clienteSeleccionado;
}

// 3. Numerología por Cliente (Ventas, Cobrado, Pendiente)
$resumenClientes = [];
$montoTotalVentas    = 0;
$montoTotalCobrado   = 0;
$montoTotalPendiente = 0;

try {
    $sqlResumenClientes = "SELECT 
            s.cliente,
            SUM(COALESCE(s.monto_total, s.total, 0)) AS total_ventas,
            SUM(CASE 
                WHEN s.tipo_pago = 'contado' OR s.estado_cobro = 'cobrado' THEN COALESCE(s.monto_total, s.total, 0)
                ELSE COALESCE(s.monto_total, s.total, 0) - COALESCE(cxc.monto, 0)
            END) AS total_pagado,
            SUM(CASE 
                WHEN s.tipo_pago != 'contado' AND (s.estado_cobro IS NULL OR s.estado_cobro != 'cobrado') THEN COALESCE(cxc.monto, COALESCE(s.monto_total, s.total, 0))
                ELSE 0 
            END) AS total_pendiente
        FROM salidas s
        LEFT JOIN cuentas_cobrar cxc ON s.id = cxc.salida_id
        $whereSalidas
        GROUP BY s.cliente 
        ORDER BY total_ventas DESC";

    $stmtRC = $pdo->prepare($sqlResumenClientes);
    $stmtRC->execute($paramsSalidas);
    $resumenClientes = $stmtRC->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resumenClientes as $rc) {
        $montoTotalVentas    += floatval($rc['total_ventas']);
        $montoTotalCobrado   += floatval($rc['total_pagado']);
        $montoTotalPendiente += floatval($rc['total_pendiente']);
    }
} catch (\PDOException $e) {}

// 4. Detalle de Ventas para la tabla individual
$ventasDetalle = [];
try {
    $sqlVentas = "SELECT s.id, s.cliente, COALESCE(s.monto_total, s.total, 0) AS monto, 
                         s.tipo_pago, s.estado_cobro, COALESCE(s.fecha, s.fecha_salida) AS fecha_registro,
                         COALESCE(cxc.monto, 0) AS saldo_pendiente_cxc
                  FROM salidas s
                  LEFT JOIN cuentas_cobrar cxc ON s.id = cxc.salida_id
                  $whereSalidas 
                  ORDER BY s.id DESC";
    $stmtVentas = $pdo->prepare($sqlVentas);
    $stmtVentas->execute($paramsSalidas);
    $ventasDetalle = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}

// 5. Numerología por Proveedor (Cuentas por Pagar)
$resumenProveedores = [];
$montoTotalCxP = 0;
try {
    $sqlCxP = "SELECT 
            COALESCE(p.nombre, 'Proveedor General') AS proveedor,
            SUM(cp.monto) AS monto_total,
            SUM(CASE WHEN cp.estatus = 'pagado' THEN cp.monto ELSE 0 END) AS pagado,
            SUM(CASE WHEN cp.estatus = 'pendiente' THEN cp.monto ELSE 0 END) AS pendiente
        FROM cuentas_pagar cp
        LEFT JOIN proveedores p ON cp.proveedor_id = p.id
        GROUP BY p.nombre 
        ORDER BY monto_total DESC";
    $resumenProveedores = $pdo->query($sqlCxP)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resumenProveedores as $pr) {
        $montoTotalCxP += floatval($pr['pendiente']);
    }
} catch (\PDOException $e) {}
?>

<!-- Cargar Chart.js desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
@media print {
    .sidebar, .btn-print, header, nav, .card-filter { display: none !important; }
    main { width: 100% !important; margin: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="bi bi-bar-chart-line-fill text-primary me-2"></i> Informe Ejecutivo y Reporte Financiero</h2>
    <button onclick="window.print();" class="btn btn-secondary btn-print"><i class="bi bi-printer"></i> Imprimir Reporte / PDF</button>
</div>

<!-- FILTROS DE BÚSQUEDA -->
<div class="card shadow-sm mb-4 card-filter border-dark">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-funnel me-1"></i> Filtros del Reporte
    </div>
    <div class="card-body">
        <form method="GET" action="reportes.php" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-bold">Periodo de Tiempo:</label>
                <select name="periodo" class="form-select">
                    <option value="semana" <?= $periodoSeleccionado === 'semana' ? 'selected' : '' ?>>Semana Actual (Lunes a Domingo)</option>
                    <option value="quincena" <?= $periodoSeleccionado === 'quincena' ? 'selected' : '' ?>>Quincena Actual</option>
                    <option value="mes" <?= $periodoSeleccionado === 'mes' ? 'selected' : '' ?>>Mes Actual</option>
                    <option value="ano" <?= $periodoSeleccionado === 'ano' ? 'selected' : '' ?>>Año Actual</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold">Cliente Específico:</label>
                <select name="cliente" class="form-select">
                    <option value="todos">-- Todos los clientes en conjunto --</option>
                    <?php foreach ($listaClientes as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $clienteSeleccionado === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-search me-1"></i> Filtrar</button>
            </div>
        </form>
    </div>
</div>

<!-- TARJETAS CON METRICAS GENERALES -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-primary text-center p-3 shadow-sm">
            <h6 class="text-muted fw-bold">Total Ventas Emitidas</h6>
            <h3 class="text-primary fw-bold">$<?= number_format($montoTotalVentas, 2) ?></h3>
            <small class="text-muted"><?= ucfirst($periodoSeleccionado) ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success text-center p-3 shadow-sm">
            <h6 class="text-muted fw-bold">Total Cobrado / Pagado</h6>
            <h3 class="text-success fw-bold">$<?= number_format($montoTotalCobrado, 2) ?></h3>
            <small class="text-muted">Ingreso Real Liquidado</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning text-center p-3 shadow-sm">
            <h6 class="text-muted fw-bold">Por Cobrar (Clientes)</h6>
            <h3 class="text-warning fw-bold">$<?= number_format($montoTotalPendiente, 2) ?></h3>
            <small class="text-muted">Saldo CxC Pendiente</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger text-center p-3 shadow-sm">
            <h6 class="text-muted fw-bold">Por Pagar (Proveedores)</h6>
            <h3 class="text-danger fw-bold">$<?= number_format($montoTotalCxP, 2) ?></h3>
            <small class="text-muted">Pasivos Pendientes CxP</small>
        </div>
    </div>
</div>

<!-- SECCIÓN DE GRÁFICAS -->
<div class="row mb-4">
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-pie-chart-fill me-1"></i> Balance de Cobro (Cobrado vs. Pendiente)
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartEstadoPago" style="max-height: 280px;"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-bar-chart-grouped me-1"></i> Ventas y Saldos por Cliente
            </div>
            <div class="card-body">
                <canvas id="chartClientes" style="max-height: 280px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- NUMEROLOGÍA POR CLIENTE -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-people-fill me-1"></i> Resumen Financiero por Cliente
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>Cliente</th>
                        <th class="text-end">Total Facturado / Vendido</th>
                        <th class="text-end text-success">Total Cobrado</th>
                        <th class="text-end text-danger">Total Pendiente por Cobrar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($resumenClientes)): ?>
                        <tr><td colspan="4" class="text-center py-3 text-muted">Sin movimientos registrados para el filtro seleccionado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($resumenClientes as $rc): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($rc['cliente']) ?></td>
                                <td class="text-end">$<?= number_format($rc['total_ventas'], 2) ?></td>
                                <td class="text-end text-success fw-bold">$<?= number_format($rc['total_pagado'], 2) ?></td>
                                <td class="text-end text-danger fw-bold">$<?= number_format($rc['total_pendiente'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- DESGLOSE DETALLADO DE VENTAS -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-secondary text-white fw-bold">
        <i class="bi bi-journal-text me-1"></i> Desglose Individual de Ventas en el Periodo
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Venta #</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Tipo Pago</th>
                        <th class="text-end">Monto Total</th>
                        <th class="text-center">Estado Cobro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ventasDetalle)): ?>
                        <tr><td colspan="6" class="text-center py-3 text-muted">Sin ventas detalladas para mostrar.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ventasDetalle as $v): 
                            $esPagado = ($v['tipo_pago'] === 'contado' || $v['estado_cobro'] === 'cobrado' || floatval($v['saldo_pendiente_cxc']) <= 0);
                        ?>
                            <tr>
                                <td><code>#<?= $v['id'] ?></code></td>
                                <td><?= date('d/m/Y H:i', strtotime($v['fecha_registro'])) ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($v['cliente']) ?></td>
                                <td><span class="badge bg-outline-dark text-uppercase"><?= htmlspecialchars($v['tipo_pago'] ?? 'contado') ?></span></td>
                                <td class="text-end fw-bold">$<?= number_format($v['monto'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ($esPagado): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Pagado</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Por Cobrar ($<?= number_format($v['saldo_pendiente_cxc'], 2) ?>)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- NUMEROLOGÍA POR PROVEEDOR (CUENTAS POR PAGAR) -->
<div class="card shadow-sm mb-4 border-danger">
    <div class="card-header bg-danger text-white fw-bold">
        <i class="bi bi-truck me-1"></i> Estado de Pasivos y Deudas por Proveedor (CxP)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Proveedor</th>
                        <th class="text-end">Monto Total Compras</th>
                        <th class="text-end text-success">Total Pagado</th>
                        <th class="text-end text-warning">Deuda Pendiente</th>
                        <th class="text-center">Porcentaje Liquidado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($resumenProveedores)): ?>
                        <tr><td colspan="5" class="text-center py-3 text-muted">No hay registros de cuentas por pagar a proveedores.</td></tr>
                    <?php else: ?>
                        <?php foreach ($resumenProveedores as $prov):
                            $mTotal = floatval($prov['monto_total']);
                            $mPend  = floatval($prov['pendiente']);
                            $mPag   = floatval($prov['pagado']);
                            $pct    = $mTotal > 0 ? round(($mPag / $mTotal) * 100) : 0;
                        ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($prov['proveedor']) ?></td>
                                <td class="text-end">$<?= number_format($mTotal, 2) ?></td>
                                <td class="text-end text-success">$<?= number_format($mPag, 2) ?></td>
                                <td class="text-end fw-bold text-danger">$<?= number_format($mPend, 2) ?></td>
                                <td class="text-center" style="width: 180px;">
                                    <div class="progress" style="height: 18px;">
                                        <div class="progress-bar bg-success" style="width: <?= $pct ?>%;"><?= $pct ?>%</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SCRIPT GENERADOR DE GRÁFICAS -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    // 1. Gráfica de Dona (Balance Pagado vs Pendiente)
    const ctxPie = document.getElementById('chartEstadoPago').getContext('2d');
    new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: ['Cobrado / Pagado', 'Pendiente por Cobrar'],
            datasets: [{
                data: [<?= $montoTotalCobrado ?>, <?= $montoTotalPendiente ?>],
                backgroundColor: ['#198754', '#dc3545'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // 2. Gráfica de Barras por Cliente
    const ctxBar = document.getElementById('chartClientes').getContext('2d');
    const clientesLabels = [<?php foreach($resumenClientes as $rc) echo "'" . addslashes($rc['cliente']) . "',"; ?>];
    const dataPagado = [<?php foreach($resumenClientes as $rc) echo $rc['total_pagado'] . ","; ?>];
    const dataPendiente = [<?php foreach($resumenClientes as $rc) echo $rc['total_pendiente'] . ","; ?>];

    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: clientesLabels,
            datasets: [
                {
                    label: 'Cobrado ($)',
                    data: dataPagado,
                    backgroundColor: '#198754'
                },
                {
                    label: 'Pendiente ($)',
                    data: dataPendiente,
                    backgroundColor: '#dc3545'
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true }
            },
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
