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
// CONSULTAS SQL Y DATOS (Estructura Original Respetada)
// =========================================================================

// 1. Obtener lista de clientes únicos
$listaClientes = [];
try {
    $listaClientes = $pdo->query("SELECT DISTINCT cliente FROM salidas WHERE cliente IS NOT NULL AND cliente != '' ORDER BY cliente ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {}

// 2. Construir cláusula WHERE base
$whereSalidas = " WHERE COALESCE(s.fecha, s.fecha_salida) BETWEEN :f_inicio AND :f_fin ";
$paramsBase = [':f_inicio' => $fechaInicio, ':f_fin' => $fechaFin];

if ($clienteSeleccionado !== 'todos') {
    $whereSalidas .= " AND s.cliente = :cliente ";
    $paramsBase[':cliente'] = $clienteSeleccionado;
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
    $stmtRC->execute($paramsBase);
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
    $stmtVentas->execute($paramsBase);
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

// =========================================================================
// 6. NUEVO: DETECCIÓN SEGURA DE TABLAS DE PRODUCTOS PARA TOP 50 Y ESTACIONALIDAD
// =========================================================================
$tablaDetalle = null;
$colSalidaId  = 'salida_id';
$colProdId    = 'producto_id';
$colCant      = 'cantidad';
$tablaProd    = null;
$colProdNombre = 'nombre';
$colProdCat    = null;

try {
    $tablasDB = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $posiblesDetalles = ['detalle_salidas', 'salidas_detalle', 'salida_detalles', 'ventas_detalle', 'detalle_ventas'];
    foreach ($posiblesDetalles as $td) {
        if (in_array($td, $tablasDB)) {
            $tablaDetalle = $td;
            $cols = $pdo->query("SHOW COLUMNS FROM `$td`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('id_salida', $cols)) $colSalidaId = 'id_salida';
            if (in_array('id_producto', $cols)) $colProdId = 'id_producto';
            if (in_array('cant', $cols)) $colCant = 'cant';
            break;
        }
    }
    $posiblesProductos = ['productos', 'inventario', 'articulos', 'catalogo'];
    foreach ($posiblesProductos as $tp) {
        if (in_array($tp, $tablasDB)) {
            $tablaProd = $tp;
            $colsP = $pdo->query("SHOW COLUMNS FROM `$tp`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('descripcion', $colsP) && !in_array('nombre', $colsP)) $colProdNombre = 'descripcion';
            if (in_array('producto', $colsP) && !in_array('nombre', $colsP)) $colProdNombre = 'producto';
            if (in_array('categoria', $colsP)) $colProdCat = 'categoria';
            elseif (in_array('tipo', $colsP)) $colProdCat = 'tipo';
            break;
        }
    }
} catch (\PDOException $e) {}

// 7. NUEVO: TOP 50 MEJORES CLIENTES (Consumo y Tipo de Producto Principal)
$top50Clientes = [];
try {
    $sqlTop50 = "SELECT 
                    s.cliente,
                    COUNT(s.id) AS num_compras,
                    SUM(COALESCE(s.monto_total, s.total, 0)) AS total_consumido,
                    MAX(COALESCE(s.fecha, s.fecha_salida)) AS ultima_compra
                 FROM salidas s
                 WHERE s.cliente IS NOT NULL AND s.cliente != ''
                 GROUP BY s.cliente
                 ORDER BY total_consumido DESC
                 LIMIT 50";
    $top50Clientes = $pdo->query($sqlTop50)->fetchAll(PDO::FETCH_ASSOC);

    // Enriquecer cada cliente con su producto/tipo más consumido
    foreach ($top50Clientes as &$cli) {
        $cli['producto_top'] = 'General / Varios';
        if ($tablaDetalle && $tablaProd) {
            try {
                $campoTipo = $colProdCat ? "CONCAT(p.`$colProdNombre`, ' (', COALESCE(p.`$colProdCat`, 'Sin cat.'), ')')" : "p.`$colProdNombre`";
                $sqlProdCli = "SELECT $campoTipo AS prod_info, SUM(d.`$colCant`) AS total_cant
                               FROM salidas s
                               INNER JOIN `$tablaDetalle` d ON s.id = d.`$colSalidaId`
                               INNER JOIN `$tablaProd` p ON d.`$colProdId` = p.id
                               WHERE s.cliente = :cli
                               GROUP BY prod_info
                               ORDER BY total_cant DESC
                               LIMIT 1";
                $stmtPC = $pdo->prepare($sqlProdCli);
                $stmtPC->execute([':cli' => $cli['cliente']]);
                if ($rowPC = $stmtPC->fetch(PDO::FETCH_ASSOC)) {
                    $cli['producto_top'] = $rowPC['prod_info'] . " [" . number_format($rowPC['total_cant'], 0) . " uds]";
                }
            } catch (\PDOException $e) {}
        } else {
            // Fallback si los productos están guardados en columna directa de salidas
            try {
                $colsS = $pdo->query("SHOW COLUMNS FROM salidas")->fetchAll(PDO::FETCH_COLUMN);
                $colDirecta = null;
                foreach (['producto', 'descripcion', 'concepto', 'tipo_producto'] as $cd) {
                    if (in_array($cd, $colsS)) { $colDirecta = $cd; break; }
                }
                if ($colDirecta) {
                    $stmtDirect = $pdo->prepare("SELECT `$colDirecta` AS prod_info, COUNT(*) AS c FROM salidas WHERE cliente = :cli AND `$colDirecta` IS NOT NULL AND `$colDirecta` != '' GROUP BY `$colDirecta` ORDER BY c DESC LIMIT 1");
                    $stmtDirect->execute([':cli' => $cli['cliente']]);
                    if ($rowD = $stmtDirect->fetch(PDO::FETCH_ASSOC)) {
                        $cli['producto_top'] = $rowD['prod_info'];
                    }
                }
            } catch (\PDOException $e) {}
        }
    }
    unset($cli);
} catch (\PDOException $e) {}

// 8. NUEVO: ANÁLISIS DE ESTACIONALIDAD MENSUAL (Temporada Alta vs Baja y Producto Líder)
$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

$ventasPorMes = [];
for ($m = 1; $m <= 12; $m++) {
    $ventasPorMes[$m] = [
        'mes_num' => $m,
        'mes_nombre' => $nombresMeses[$m],
        'total' => 0,
        'operaciones' => 0,
        'producto_lider' => 'Sin datos',
        'clasificacion' => 'Normal'
    ];
}

try {
    $sqlEstacionalidad = "SELECT 
                            MONTH(COALESCE(fecha, fecha_salida)) AS mes,
                            SUM(COALESCE(monto_total, total, 0)) AS total_mes,
                            COUNT(id) AS ops
                          FROM salidas
                          WHERE COALESCE(fecha, fecha_salida) IS NOT NULL
                          GROUP BY MONTH(COALESCE(fecha, fecha_salida))";
    $resEst = $pdo->query($sqlEstacionalidad)->fetchAll(PDO::FETCH_ASSOC);

    $sumaAnual = 0;
    $mesesConDatos = 0;
    foreach ($resEst as $re) {
        $m = intval($re['mes']);
        if ($m >= 1 && $m <= 12) {
            $ventasPorMes[$m]['total'] = floatval($re['total_mes']);
            $ventasPorMes[$m]['operaciones'] = intval($re['ops']);
            if ($ventasPorMes[$m]['total'] > 0) {
                $sumaAnual += $ventasPorMes[$m]['total'];
                $mesesConDatos++;
            }
        }
    }

    // Producto líder por cada mes
    if ($tablaDetalle && $tablaProd) {
        for ($m = 1; $m <= 12; $m++) {
            $campoTipo = $colProdCat ? "CONCAT(p.`$colProdNombre`, ' (', COALESCE(p.`$colProdCat`, 'Cat.'), ')')" : "p.`$colProdNombre`";
            $sqlProdMes = "SELECT $campoTipo AS prod_info, SUM(d.`$colCant`) AS cant_mes
                           FROM salidas s
                           INNER JOIN `$tablaDetalle` d ON s.id = d.`$colSalidaId`
                           INNER JOIN `$tablaProd` p ON d.`$colProdId` = p.id
                           WHERE MONTH(COALESCE(s.fecha, s.fecha_salida)) = $m
                           GROUP BY prod_info
                           ORDER BY cant_mes DESC
                           LIMIT 1";
            $rowPM = $pdo->query($sqlProdMes)->fetch(PDO::FETCH_ASSOC);
            if ($rowPM) {
                $ventasPorMes[$m]['producto_lider'] = $rowPM['prod_info'];
            }
        }
    }

    // Calcular umbrales de Temporada Alta y Temporada Baja
    $promedioMensual = $mesesConDatos > 0 ? ($sumaAnual / $mesesConDatos) : 0;
    $mesesTemporadaAlta = [];
    $mesesTemporadaBaja = [];

    foreach ($ventasPorMes as $m => &$datosM) {
        if ($promedioMensual > 0) {
            if ($datosM['total'] >= ($promedioMensual * 1.15)) {
                $datosM['clasificacion'] = 'Temporada Alta';
                $mesesTemporadaAlta[] = $m;
            } elseif ($datosM['total'] > 0 && $datosM['total'] <= ($promedioMensual * 0.85)) {
                $datosM['clasificacion'] = 'Temporada Baja';
                $mesesTemporadaBaja[] = $m;
            } elseif ($datosM['total'] == 0) {
                $datosM['clasificacion'] = 'Temporada Baja';
            }
        }
    }
    unset($datosM);

    // Lógica de Alerta Preventiva (Mes actual y Mes próximo)
    $mesActualNum = intval(date('n'));
    $mesProximoNum = ($mesActualNum == 12) ? 1 : ($mesActualNum + 1);
    $alertaTemporada = null;

    if (in_array($mesProximoNum, $mesesTemporadaAlta)) {
        $alertaTemporada = [
            'tipo' => 'warning',
            'titulo' => '¡AVISO PREVENTIVO DE TEMPORADA ALTA PRÓXIMA!',
            'mensaje' => "El próximo mes (<strong>{$nombresMeses[$mesProximoNum]}</strong>) está identificado históricamente como <strong>Temporada Alta</strong>. Se recomienda abastecer inventario y preparar logística, especialmente en: <strong>{$ventasPorMes[$mesProximoNum]['producto_lider']}</strong>."
        ];
    } elseif (in_array($mesActualNum, $mesesTemporadaAlta)) {
        $alertaTemporada = [
            'tipo' => 'success',
            'titulo' => '¡ESTÁS EN TEMPORADA ALTA ACTUALMENTE!',
            'mensaje' => "El mes en curso (<strong>{$nombresMeses[$mesActualNum]}</strong>) corresponde a un pico de <strong>Temporada Alta</strong>. Producto de mayor demanda en este periodo: <strong>{$ventasPorMes[$mesActualNum]['producto_lider']}</strong>."
        ];
    } elseif (in_array($mesProximoNum, $mesesTemporadaBaja)) {
        $alertaTemporada = [
            'tipo' => 'info',
            'titulo' => 'Aviso de Estacionalidad: Próximo Mes de Temporada Baja',
            'mensaje' => "El próximo mes (<strong>{$nombresMeses[$mesProximoNum]}</strong>) suele presentar menor demanda (<strong>Temporada Baja</strong>). Buen momento para campañas de reactivación con tus Top 50 clientes."
        ];
    }
} catch (\PDOException $e) {}
?>

<!-- Cargar Chart.js desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* Estilos para evitar distorsión de gráficas en pantalla e impresión */
.chart-container {
    position: relative;
    width: 100%;
    height: 290px;
    margin: auto;
}
.chart-container-wide {
    position: relative;
    width: 100%;
    height: 320px;
    margin: auto;
}

@media print {
    @page {
        size: landscape;
        margin: 10mm;
    }
    .sidebar, .btn-print, header, nav, .card-filter, .modal { display: none !important; }
    main, .container, .container-fluid { 
        width: 100% !important; 
        max-width: 100% !important; 
        margin: 0 !important; 
        padding: 0 !important; 
    }
    .card { 
        border: 1px solid #dee2e6 !important; 
        box-shadow: none !important; 
        break-inside: avoid !important;
        page-break-inside: avoid !important;
        margin-bottom: 15px !important;
    }
    .row {
        display: flex !important;
        flex-wrap: nowrap !important;
    }
    .chart-container, .chart-container-wide {
        height: 250px !important;
        max-width: 100% !important;
        break-inside: avoid !important;
    }
    canvas {
        max-width: 100% !important;
        max-height: 240px !important;
        width: auto !important;
        height: auto !important;
    }
    table {
        font-size: 11px !important;
    }
    tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
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

<!-- ALERTA PREVENTIVA DE TEMPORADA ALTA / BAJA -->
<?php if (!empty($alertaTemporada)): ?>
<div class="alert alert-<?= $alertaTemporada['tipo'] ?> border-start border-4 shadow-sm mb-4" role="alert">
    <div class="d-flex align-items-center">
        <i class="bi bi-bell-fill fs-3 me-3"></i>
        <div>
            <h5 class="alert-heading mb-1 fw-bold"><?= $alertaTemporada['titulo'] ?></h5>
            <p class="mb-0"><?= $alertaTemporada['mensaje'] ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

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

<!-- SECCIÓN DE GRÁFICAS FINANCIERAS -->
<div class="row mb-4">
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-pie-chart-fill me-1"></i> Balance de Cobro (Cobrado vs. Pendiente)
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div class="chart-container">
                    <canvas id="chartEstadoPago"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-bar-chart-grouped me-1"></i> Ventas y Saldos por Cliente
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="chartClientes"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- NUEVA SECCIÓN: ESTACIONALIDAD (TEMPORADA ALTA VS BAJA Y PRODUCTO LÍDER) -->
<div class="card shadow-sm mb-4 border-primary">
    <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-range me-1"></i> Análisis de Estacionalidad: Temporada Alta vs. Temporada Baja</span>
        <span class="badge bg-light text-dark">Promedio Mensual: $<?= number_format($promedioMensual ?? 0, 2) ?></span>
    </div>
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="chart-container-wide">
                    <canvas id="chartEstacionalidad"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                    <table class="table table-sm table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Mes</th>
                                <th>Temporada</th>
                                <th>Producto Líder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventasPorMes as $vm): 
                                $badgeClass = 'bg-secondary';
                                if ($vm['clasificacion'] === 'Temporada Alta') $badgeClass = 'bg-success';
                                elseif ($vm['clasificacion'] === 'Temporada Baja') $badgeClass = 'bg-danger';
                            ?>
                                <tr>
                                    <td class="fw-bold"><?= $vm['mes_nombre'] ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= $vm['clasificacion'] ?></span></td>
                                    <td class="small"><?= htmlspecialchars($vm['producto_lider']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- NUEVA SECCIÓN: TOP 50 MEJORES CLIENTES Y TIPO DE PRODUCTO QUE CONSUMEN -->
<div class="card shadow-sm mb-4 border-success">
    <div class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trophy-fill me-1"></i> Top 50 Mejores Clientes y Producto de Mayor Consumo</span>
        <span class="badge bg-light text-success">Histórico Acumulado</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="text-center" style="width: 60px;">#</th>
                        <th>Cliente</th>
                        <th class="text-center">Compras Realizadas</th>
                        <th>Producto / Categoría Principal Consumida</th>
                        <th class="text-center">Última Compra</th>
                        <th class="text-end">Consumo Total ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top50Clientes)): ?>
                        <tr><td colspan="6" class="text-center py-3 text-muted">Aún no hay suficientes datos históricos de clientes.</td></tr>
                    <?php else: ?>
                        <?php foreach ($top50Clientes as $idx => $topC): ?>
                            <tr>
                                <td class="text-center fw-bold">
                                    <?php if ($idx === 0): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-star-fill"></i> 1</span>
                                    <?php elseif ($idx === 1): ?>
                                        <span class="badge bg-secondary">2</span>
                                    <?php elseif ($idx === 2): ?>
                                        <span class="badge bg-danger">3</span>
                                    <?php else: ?>
                                        <?= $idx + 1 ?>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?= htmlspecialchars($topC['cliente']) ?></td>
                                <td class="text-center"><span class="badge bg-info text-dark"><?= number_format($topC['num_compras']) ?></span></td>
                                <td><i class="bi bi-box-seam text-primary me-1"></i> <?= htmlspecialchars($topC['producto_top']) ?></td>
                                <td class="text-center text-muted small"><?= $topC['ultima_compra'] ? date('d/m/Y', strtotime($topC['ultima_compra'])) : '-' ?></td>
                                <td class="text-end fw-bold text-success">$<?= number_format($topC['total_consumido'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
    const chartPie = new Chart(ctxPie, {
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
            maintainAspectRatio: false,
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

    const chartBar = new Chart(ctxBar, {
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
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true }
            },
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // 3. NUEVA GRÁFICA: Estacionalidad Mensual (Temporada Alta vs Baja)
    const ctxEst = document.getElementById('chartEstacionalidad').getContext('2d');
    const mesesLabels = [<?php foreach($ventasPorMes as $vm) echo "'" . $vm['mes_nombre'] . "',"; ?>];
    const ventasMensuales = [<?php foreach($ventasPorMes as $vm) echo $vm['total'] . ","; ?>];
    const productosLideres = [<?php foreach($ventasPorMes as $vm) echo "'" . addslashes($vm['producto_lider']) . "',"; ?>];
    const clasificacionesMes = [<?php foreach($ventasPorMes as $vm) echo "'" . $vm['clasificacion'] . "',"; ?>];
    const coloresEstacionalidad = [<?php 
        foreach($ventasPorMes as $vm) {
            if ($vm['clasificacion'] === 'Temporada Alta') echo "'#198754',";
            elseif ($vm['clasificacion'] === 'Temporada Baja') echo "'#dc3545',";
            else echo "'#0d6efd',";
        }
    ?>];

    const chartEst = new Chart(ctxEst, {
        type: 'bar',
        data: {
            labels: mesesLabels,
            datasets: [{
                label: 'Ventas Mensuales ($)',
                data: ventasMensuales,
                backgroundColor: coloresEstacionalidad,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: function(context) {
                            const idx = context.dataIndex;
                            return [
                                'Estado: ' + clasificacionesMes[idx],
                                'Producto Líder: ' + productosLideres[idx]
                            ];
                        }
                    }
                }
            }
        }
    });

    // Redimensionar gráficas limpiamente antes y después de imprimir para evitar distorsión
    window.addEventListener('beforeprint', () => {
        chartPie.resize();
        chartBar.resize();
        chartEst.resize();
    });
    window.addEventListener('afterprint', () => {
        chartPie.resize();
        chartBar.resize();
        chartEst.resize();
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
