<?php
require_once 'includes/header.php';

// Inicializar variables
$totalProductos   = 0;
$alertasStock     = 0;
$ventasHoy        = 0;
$numVentasTotal   = 0;
$ingresosNetos    = 0;
$totalProveedores = 0;
$cuentasPorCobrar = 0;
$cuentasPorPagar  = 0;

// 1. Total Productos e Insumos
try {
    $totalProductos = $pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn() ?? 0;
} catch (\PDOException $e) {}

// 2. Alertas de Stock Bajo
try {
    $alertasStock = $pdo->query("SELECT COUNT(*) FROM productos WHERE stock_actual <= stock_minimo")->fetchColumn() ?? 0;
} catch (\PDOException $e) {}

// 3. Ventas del Día
try {
    $ventasHoy = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM salidas WHERE DATE(fecha) = CURDATE()")->fetchColumn() ?? 0;
} catch (\PDOException $e) {
    $ventasHoy = 0;
}

// 4. Número Total de Ventas
try {
    $numVentasTotal = $pdo->query("SELECT COUNT(*) FROM salidas")->fetchColumn() ?? 0;
} catch (\PDOException $e) {}

// 5. Total Proveedores
try {
    $totalProveedores = $pdo->query("SELECT COUNT(*) FROM proveedores")->fetchColumn() ?? 0;
} catch (\PDOException $e) {}

// 6. Ingresos Acumulados
try {
    $ingresosNetos = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM salidas")->fetchColumn() ?? 0;
} catch (\PDOException $e) {
    $ingresosNetos = 0;
}

// 7. Cuentas por Cobrar (Suma de cuentas_cobrar pendientes + ventas en salidas clasificadas como credito)
try {
    $cxcTabla = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM cuentas_cobrar WHERE estatus = 'pendiente'")->fetchColumn() ?? 0;
    $cxcSalidas = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM salidas WHERE estado_cobro = 'credito' OR tipo_pago = 'credito'")->fetchColumn() ?? 0;
    
    $cuentasPorCobrar = floatval($cxcTabla) + floatval($cxcSalidas);
} catch (\PDOException $e) {
    $cuentasPorCobrar = 0;
}

// 8. Cuentas por Pagar (Suma de montos pendientes en cuentas_pagar)
try {
    $cuentasPorPagar = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM cuentas_pagar WHERE estatus = 'pendiente'")->fetchColumn() ?? 0;
} catch (\PDOException $e) {
    $cuentasPorPagar = 0;
}

$ventasHoy        = floatval($ventasHoy);
$ingresosNetos    = floatval($ingresosNetos);
$cuentasPorCobrar = floatval($cuentasPorCobrar);
$cuentasPorPagar  = floatval($cuentasPorPagar);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-speedometer2 me-2"></i>Panel de Control General</h1>
    <span class="badge bg-secondary p-2"><i class="bi bi-calendar-event me-1"></i> Hoy: <?= date('d/m/Y') ?></span>
</div>

<!-- FILA 1 -->
<div class="row">
    <!-- VENTAS DE HOY -->
    <div class="col-md-3 mb-4">
        <div class="card bg-info text-white shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Vendido Hoy</h6>
                    <i class="bi bi-currency-dollar fs-3"></i>
                </div>
                <h2 class="m-0 font-weight-bold">$<?= number_format($ventasHoy, 2) ?></h2>
            </div>
        </div>
    </div>

    <!-- TOTAL DE VENTAS REGISTRADAS -->
    <div class="col-md-3 mb-4">
        <div class="card bg-primary text-white shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Total N° Ventas</h6>
                    <i class="bi bi-bag-check fs-3"></i>
                </div>
                <h2 class="m-0"><?= number_format($numVentasTotal) ?></h2>
            </div>
        </div>
    </div>

    <!-- INGRESOS ACUMULADOS -->
    <div class="col-md-3 mb-4">
        <div class="card bg-success text-white shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Ingresos Acumulados</h6>
                    <i class="bi bi-graph-up-arrow fs-3"></i>
                </div>
                <h2 class="m-0">$<?= number_format($ingresosNetos, 2) ?></h2>
            </div>
        </div>
    </div>

    <!-- CUENTAS POR COBRAR -->
    <div class="col-md-3 mb-4">
        <div class="card text-white shadow-sm h-100" style="background-color: #20c997;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Cuentas por Cobrar</h6>
                    <i class="bi bi-wallet2 fs-3"></i>
                </div>
                <h2 class="m-0">$<?= number_format($cuentasPorCobrar, 2) ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- FILA 2 -->
<div class="row">
    <!-- POR PAGAR A PROVEEDORES -->
    <div class="col-md-3 mb-4">
        <div class="card bg-danger text-white shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Por Pagar a Proveedores</h6>
                    <i class="bi bi-credit-card fs-3"></i>
                </div>
                <h2 class="m-0">$<?= number_format($cuentasPorPagar, 2) ?></h2>
            </div>
        </div>
    </div>

    <!-- TOTAL PROVEEDORES -->
    <div class="col-md-3 mb-4">
        <div class="card bg-secondary text-white shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Total Proveedores</h6>
                    <i class="bi bi-truck fs-3"></i>
                </div>
                <h2 class="m-0"><?= number_format($totalProveedores) ?></h2>
            </div>
        </div>
    </div>

    <!-- TOTAL PRODUCTOS / INSUMOS -->
    <div class="col-md-3 mb-4">
        <div class="card bg-dark text-white shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Total Productos/Insumos</h6>
                    <i class="bi bi-box-seam fs-3"></i>
                </div>
                <h2 class="m-0"><?= number_format($totalProductos) ?></h2>
            </div>
        </div>
    </div>

    <!-- ALERTAS DE STOCK BAJO -->
    <div class="col-md-3 mb-4">
        <div class="card bg-warning text-dark shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-title mb-0">Alertas de Stock Bajo</h6>
                    <i class="bi bi-exclamation-triangle fs-3"></i>
                </div>
                <h2 class="m-0"><?= number_format($alertasStock) ?></h2>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
