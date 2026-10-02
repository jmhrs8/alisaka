<?php
require_once 'includes/header.php';

// =========================================================================
// GUARDAR CONFIGURACIÓN DE ENVÍO AUTOMÁTICO DE REPORTES
// =========================================================================
$mensajeConfig = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_config_reporte'])) {
    $activo = isset($_POST['activo_reporte']) ? 1 : 0;
    $destinatarios = trim($_POST['destinatarios_reporte'] ?? '');
    $frecuencia = $_POST['frecuencia_reporte'] ?? 'semanal';
    $periodoRep = $_POST['periodo_reporte_auto'] ?? 'mes';

    try {
        $stmtConf = $pdo->prepare("UPDATE configuracion_reportes SET activo = :act, destinatarios = :dest, frecuencia = :frec, periodo_reporte = :per WHERE id = 1");
        $stmtConf->execute([
            ':act' => $activo,
            ':dest' => $destinatarios,
            ':frec' => $frecuencia,
            ':per' => $periodoRep
        ]);
        $mensajeConfig = "<div class='alert alert-success alert-dismissible fade show my-3' role='alert'>
            <i class='bi bi-check-circle me-1'></i> Configuración de reporte automático guardada correctamente.
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    } catch (\PDOException $e) {
        $mensajeConfig = "<div class='alert alert-danger my-3'>Error al guardar configuración: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Obtener configuración actual para el modal
$configReporte = ['activo' => 0, 'destinatarios' => '', 'frecuencia' => 'semanal', 'periodo_reporte' => 'mes'];
try {
    $stmtGetConf = $pdo->query("SELECT * FROM configuracion_reportes WHERE id = 1");
    if ($rowC = $stmtGetConf->fetch(PDO::FETCH_ASSOC)) {
        $configReporte = $rowC;
    }
} catch (\PDOException $e) {}

// =========================================================================
// PARÁMETROS DE FILTRO (Periodo y Cliente)
// =========================================================================
$periodoSeleccionado = $_GET['periodo'] ?? 'mes';
$clienteSeleccionado = $_GET['cliente'] ?? 'todos';

$fechaInicio = date('Y-m-01 00:00:00');
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
// CONSULTAS SQL CORREGIDAS
// =========================================================================

// 1. Obtener lista de clientes únicos
$listaClientes = [];
try {
    $listaClientes = $pdo->query("SELECT DISTINCT cliente FROM salidas WHERE cliente IS NOT NULL AND TRIM(cliente) != '' ORDER BY cliente ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {}

// 2. Construcción de filtro SQL
$whereSalidas = " WHERE 1=1 ";
$paramsSalidas = [];

if ($periodoSeleccionado !== 'todos') {
    $whereSalidas .= " AND (s.fecha BETWEEN :f_inicio AND :f_fin OR s.fecha_salida BETWEEN :f_inicio AND :f_fin) ";
    $paramsSalidas[':f_inicio'] = $fechaInicio;
    $paramsSalidas[':f_fin']    = $fechaFin;
}

if ($clienteSeleccionado !== 'todos') {
    $whereSalidas .= " AND s.cliente = :cliente ";
    $paramsSalidas[':cliente'] = $clienteSeleccionado;
}

// 3. Obtener detalle de ventas con cálculo real de saldo
$ventasDetalle = [];
$resumenClientesDict = [];

$montoTotalVentas    = 0;
$montoTotalCobrado   = 0;
$montoTotalPendiente = 0;

try {
    $sqlVentas = "SELECT 
                    s.id, 
                    COALESCE(s.cliente, 'Cliente General') AS cliente, 
                    COALESCE(s.monto_total, s.total, 0) AS monto,
                    COALESCE(s.tipo_pago, 'contado') AS tipo_pago, 
                    COALESCE(s.estado_cobro, 'pendiente') AS estado_cobro, 
                    COALESCE(s.fecha, s.fecha_salida, CURRENT_TIMESTAMP) AS fecha_registro,
                    (SELECT COALESCE(SUM(cxc.monto), 0) FROM cuentas_cobrar cxc WHERE cxc.salida_id = s.id) AS saldo_pendiente_cxc
                  FROM salidas s
                  $whereSalidas
                  ORDER BY s.id DESC";

    $stmtVentas = $pdo->prepare($sqlVentas);
    $stmtVentas->execute($paramsSalidas);
    $ventasDetalle = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);

    // Procesar métricas en PHP para garantizar exactitud sin errores SQL
    foreach ($ventasDetalle as $v) {
        $montoVenta = floatval($v['monto']);
        $cliente    = $v['cliente'];
        $tipoPago   = strtolower($v['tipo_pago']);
        $estado     = strtolower($v['estado_cobro']);
        $saldoCxC   = floatval($v['saldo_pendiente_cxc']);

        if ($tipoPago === 'contado' || $estado === 'cobrado' || $estado === 'pagado') {
            $cobrado   = $montoVenta;
            $pendiente = 0;
        } else {
            $pendiente = ($saldoCxC > 0) ? $saldoCxC : $montoVenta;
            $cobrado   = max(0, $montoVenta - $pendiente);
        }

        $montoTotalVentas    += $montoVenta;
        $montoTotalCobrado   += $cobrado;
        $montoTotalPendiente += $pendiente;

        if (!isset($resumenClientesDict[$cliente])) {
            $resumenClientesDict[$cliente] = [
                'cliente' => $cliente,
                'total_ventas' => 0,
                'total_pagado' => 0,
                'total_pendiente' => 0
            ];
        }

        $resumenClientesDict[$cliente]['total_ventas']    += $montoVenta;
        $resumenClientesDict[$cliente]['total_pagado']    += $cobrado;
        $resumenClientesDict[$cliente]['total_pendiente'] += $pendiente;
    }
} catch (\PDOException $e) {
    echo "<div class='alert alert-danger'>Error en consulta de ventas: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$resumenClientes = array_values($resumenClientesDict);

// 4. Numerología por Proveedor (Cuentas por Pagar)
$resumenProveedores = [];
$montoTotalCxP = 0;
try {
    $sqlCxP = "SELECT
            COALESCE(p.nombre, 'Proveedor General') AS proveedor,
            SUM(COALESCE(cp.monto, 0)) AS monto_total,
            SUM(CASE WHEN LOWER(cp.estatus) IN ('pagado', 'cobrado') THEN COALESCE(cp.monto, 0) ELSE 0 END) AS pagado,
            SUM(CASE WHEN LOWER(cp.estatus) NOT IN ('pagado', 'cobrado') OR cp.estatus IS NULL THEN COALESCE(cp.monto, 0) ELSE 0 END) AS pendiente
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
    <div>
        <button type="button" class="btn btn-outline-dark btn-print me-2" data-bs-toggle="modal" data-bs-target="#modalConfigReportes">
            <i class="bi bi-gear-fill me-1"></i> Programar Envío
        </button>
        <button onclick="window.print();" class="btn btn-secondary btn-print"><i class="bi bi-printer"></i> Imprimir Reporte / PDF</button>
    </div>
</div>

<?= $mensajeConfig ?>

<!-- MODAL CONFIGURACIÓN REPORTES AUTOMÁTICOS -->
<div class="modal fade" id="modalConfigReportes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="reportes.php">
                <input type="hidden" name="action_config_reporte" value="1">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-envelope-paper me-2"></i> Envíos Automáticos por Correo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="activo_reporte" id="activo_reporte" value="1" <?= $configReporte['activo'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="activo_reporte">Activar envío automático de reportes</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Destinatarios (separados por coma):</label>
                        <input type="text" name="destinatarios_reporte" class="form-control" placeholder="ejemplo1@empresa.com, ejemplo2@empresa.com" value="<?= htmlspecialchars($configReporte['destinatarios']) ?>" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Frecuencia de envío:</label>
                            <select name="frecuencia_reporte" class="form-select">
                                <option value="diario" <?= $configReporte['frecuencia'] === 'diario' ? 'selected' : '' ?>>Diario</option>
                                <option value="semanal" <?= $configReporte['frecuencia'] === 'semanal' ? 'selected' : '' ?>>Semanal</option>
                                <option value="mensual" <?= $configReporte['frecuencia'] === 'mensual' ? 'selected' : '' ?>>Mensual</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Periodo a incluir:</label>
                            <select name="periodo_reporte_auto" class="form-select">
                                <option value="semana" <?= $configReporte['periodo_reporte'] === 'semana' ? 'selected' : '' ?>>Semana Actual</option>
                                <option value="quincena" <?= $configReporte['periodo_reporte'] === 'quincena' ? 'selected' : '' ?>>Quincena Actual</option>
                                <option value="mes" <?= $configReporte['periodo_reporte'] === 'mes' ? 'selected' : '' ?>>Mes Actual</option>
                                <option value="ano" <?= $configReporte['periodo_reporte'] === 'ano' ? 'selected' : '' ?>>Año Actual</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar Configuración</button>
                </div>
            </form>
        </div>
    </div>
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

<!-- METRICAS GENERALES -->
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

<!-- GRÁFICAS -->
<div class="row mb-4">
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-pie-chart-fill me-1"></i> Balance de Cobro
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

<!-- TABLA POR CLIENTE -->
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

<!-- DESGLOSE DE VENTAS -->
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
                            $montoV = floatval($v['monto']);
                            $tPago  = strtolower($v['tipo_pago']);
                            $estCob = strtolower($v['estado_cobro']);
                            $sPend  = floatval($v['saldo_pendiente_cxc']);
                            $esPag  = ($tPago === 'contado' || $estCob === 'cobrado' || $estCob === 'pagado' || ($sPend <= 0 && $tPago !== 'credito'));
                        ?>
                            <tr>
                                <td><code>#<?= $v['id'] ?></code></td>
                                <td><?= date('d/m/Y H:i', strtotime($v['fecha_registro'])) ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($v['cliente']) ?></td>
                                <td><span class="badge bg-dark text-uppercase"><?= htmlspecialchars($v['tipo_pago']) ?></span></td>
                                <td class="text-end fw-bold">$<?= number_format($montoV, 2) ?></td>
                                <td class="text-center">
                                    <?php if ($esPag): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Pagado</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Por Cobrar ($<?= number_format($sPend > 0 ? $sPend : $montoV, 2) ?>)</span>
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

<!-- CUENTAS POR PAGAR (PROVEEDORES) -->
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

<!-- CHART.JS SCRIPT -->
<script>
document.addEventListener("DOMContentLoaded", function () {
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
            plugins: { legend: { position: 'bottom' } }
        }
    });

    const ctxBar = document.getElementById('chartClientes').getContext('2d');
    const clientesLabels = [<?php foreach($resumenClientes as $rc) echo "'" . addslashes($rc['cliente']) . "',"; ?>];
    const dataPagado = [<?php foreach($resumenClientes as $rc) echo $rc['total_pagado'] . ","; ?>];
    const dataPendiente = [<?php foreach($resumenClientes as $rc) echo $rc['total_pendiente'] . ","; ?>];

    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: clientesLabels,
            datasets: [
                { label: 'Cobrado ($)', data: dataPagado, backgroundColor: '#198754' },
                { label: 'Pendiente ($)', data: dataPendiente, backgroundColor: '#dc3545' }
            ]
        },
        options: {
            responsive: true,
            scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } },
            plugins: { legend: { position: 'bottom' } }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
