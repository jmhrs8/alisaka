<?php
require_once 'includes/header.php';

// Control de permisos estrictos por rol
$userRol = $_SESSION['user_rol'] ?? 'usuario';
$puedeEliminar = ($userRol === 'admin');

// Asegurar que PDO lance excepciones
if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$mensajeExito = '';
$mensajeError = '';

$usuarioId = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 1;

// 1. REGISTRAR NUEVA SALIDA / VENTA (SOPORTA MÚLTIPLES PRODUCTOS EN EL MISMO TICKET)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_salida'])) {
    $clienteNombre    = !empty(trim($_POST['cliente'] ?? '')) ? trim($_POST['cliente']) : 'Público General';
    $estadoCobro      = $_POST['estado_cobro'] ?? 'cobrado';
    $fechaVencimiento = ($estadoCobro === 'credito' && !empty($_POST['fecha_vencimiento'])) ? $_POST['fecha_vencimiento'] : null;
    $metodoCobro      = $_POST['metodo_cobro'] ?? 'efectivo';
    $requiereFactura  = isset($_POST['requiere_factura']) ? 1 : 0;
    $facturaUrl       = null;

    // Recibir arreglo de ítems del ticket (o fallback a un solo producto si se envía directo)
    $itemsJson = $_POST['items_ticket_json'] ?? '';
    $itemsTicket = [];

    if (!empty($itemsJson)) {
        $decoded = json_decode($itemsJson, true);
        if (is_array($decoded)) {
            $itemsTicket = $decoded;
        }
    }

    if (empty($itemsTicket) && !empty($_POST['producto_id'])) {
        $itemsTicket[] = [
            'producto_id'     => intval($_POST['producto_id']),
            'modalidad_venta' => $_POST['modalidad_venta'] ?? 'unidad',
            'cantidad'        => floatval($_POST['cantidad'] ?? 0),
            'precio_venta'    => floatval($_POST['precio_venta'] ?? 0),
        ];
    }

    // Filtrar ítems válidos
    $itemsValidos = [];
    foreach ($itemsTicket as $it) {
        $pId   = intval($it['producto_id'] ?? 0);
        $cant  = floatval($it['cantidad'] ?? 0);
        $prec  = floatval($it['precio_venta'] ?? 0);
        $mod   = ($it['modalidad_venta'] ?? 'unidad') === 'empaque' ? 'empaque' : 'unidad';
        if ($pId > 0 && $cant > 0 && $prec >= 0) {
            $itemsValidos[] = [
                'producto_id'     => $pId,
                'modalidad_venta' => $mod,
                'cantidad'        => $cant,
                'precio_venta'    => $prec,
            ];
        }
    }

    if (!empty($itemsValidos)) {
        try {
            $pdo->beginTransaction();

            // Subida de comprobante
            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas)) {
                    $dirSubida = 'uploads/ventas/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0777, true);
                    }
                    $nombreArchivo = 'salida_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $facturaUrl = $dirSubida . $nombreArchivo;
                    move_uploaded_file($_FILES['comprobante']['tmp_name'], $facturaUrl);
                }
            }

            $subtotalGeneral = 0.0;
            $detallesPreparados = [];
            $nombresResumen = [];

            foreach ($itemsValidos as $item) {
                $stmtP = $pdo->prepare("SELECT id, nombre, stock_actual, tipo_unidad, unidades_por_empaque FROM productos WHERE id = ? FOR UPDATE");
                $stmtP->execute([$item['producto_id']]);
                $producto = $stmtP->fetch(PDO::FETCH_ASSOC);

                if (!$producto) {
                    throw new Exception("Uno de los productos seleccionados no existe (ID #{$item['producto_id']}).");
                }

                $unidadesPorEmpaque = floatval($producto['unidades_por_empaque'] ?? 1);
                if ($unidadesPorEmpaque <= 0) $unidadesPorEmpaque = 1;

                if ($item['modalidad_venta'] === 'empaque') {
                    $cantidadBaseDescontar = $item['cantidad'] * $unidadesPorEmpaque;
                    $precioUnitarioBase   = $item['precio_venta'] / $unidadesPorEmpaque;
                } else {
                    $cantidadBaseDescontar = $item['cantidad'];
                    $precioUnitarioBase   = $item['precio_venta'];
                }

                $stockDisponible = floatval($producto['stock_actual'] ?? 0);
                if ($stockDisponible < $cantidadBaseDescontar) {
                    throw new Exception("Stock insuficiente para '{$producto['nombre']}'. Disponible: " . number_format($stockDisponible, 2) . " unidades base.");
                }

                $subtotalItem = $item['cantidad'] * $item['precio_venta'];
                $subtotalGeneral += $subtotalItem;

                $detallesPreparados[] = [
                    'producto_id'            => $item['producto_id'],
                    'nombre'                 => $producto['nombre'],
                    'cantidad_ingresada'     => $item['cantidad'],
                    'precio_ingresado'       => $item['precio_venta'],
                    'cantidad_base'          => $cantidadBaseDescontar,
                    'precio_unitario_base'   => $precioUnitarioBase,
                    'subtotal_item'          => $subtotalItem,
                ];

                $nombresResumen[] = $producto['nombre'] . " (" . $item['cantidad'] . ")";
            }

            $ivaTotal = $requiereFactura ? ($subtotalGeneral * 0.16) : 0.00;
            $totalGeneral = $subtotalGeneral + $ivaTotal;
            $tipoPago = ($estadoCobro === 'credito') ? 'credito' : 'contado';

            // Insertar encabezado de Salida
            $stmtIns = $pdo->prepare("INSERT INTO salidas
                (usuario_id, cliente, subtotal, iva, total, monto_total, estado_cobro, fecha_vencimiento, metodo_cobro, requiere_factura, con_factura, factura_url, tipo_pago, metodo_pago, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            $stmtIns->execute([
                $usuarioId,
                $clienteNombre,
                $subtotalGeneral,
                $ivaTotal,
                $totalGeneral,
                $totalGeneral,
                $estadoCobro,
                $fechaVencimiento,
                $metodoCobro,
                $requiereFactura,
                $requiereFactura,
                $facturaUrl,
                $tipoPago,
                $metodoCobro
            ]);

            $salidaId = $pdo->lastInsertId();

            $stmtDet = $pdo->prepare("INSERT INTO detalle_salidas
                (salida_id, producto_id, cantidad, precio_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?)");

            $stmtUpdStk = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?");

            foreach ($detallesPreparados as $det) {
                $stmtDet->execute([
                    $salidaId,
                    $det['producto_id'],
                    $det['cantidad_base'],
                    $det['precio_unitario_base'],
                    $det['subtotal_item']
                ]);

                $stmtUpdStk->execute([
                    $det['cantidad_base'],
                    $det['producto_id']
                ]);
            }

            // Registrar en INGRESOS
            if ($estadoCobro === 'cobrado') {
                $resumenStr = implode(', ', $nombresResumen);
                if (mb_strlen($resumenStr) > 180) {
                    $resumenStr = mb_substr($resumenStr, 0, 177) . '...';
                }
                $conceptoIngreso = "Venta / Salida #" . $salidaId . " - " . $resumenStr . " (" . $clienteNombre . ")" . ($requiereFactura ? " [Facturado 16% IVA]" : "");

                $primerDet = $detallesPreparados[0];
                $stmtIng = $pdo->prepare("INSERT INTO ingresos
                    (salida_id, usuario_id, producto_id, cantidad, costo_unitario, concepto, monto_subtotal, monto_iva, monto_total, metodo_pago, comprobante_url, fecha)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                $stmtIng->execute([
                    $salidaId,
                    $usuarioId,
                    $primerDet['producto_id'],
                    $primerDet['cantidad_base'],
                    $primerDet['precio_unitario_base'],
                    $conceptoIngreso,
                    $subtotalGeneral,
                    $ivaTotal,
                    $totalGeneral,
                    $metodoCobro,
                    $facturaUrl
                ]);
            }

            // Registrar en CUENTAS_COBRAR
            if ($estadoCobro === 'credito') {
                $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar
                    (salida_id, cliente, monto, estatus, fecha_vencimiento, fecha_emision)
                    VALUES (?, ?, ?, 'pendiente', ?, NOW())");

                $stmtCxC->execute([
                    $salidaId,
                    $clienteNombre,
                    $totalGeneral,
                    $fechaVencimiento
                ]);
            }

            $pdo->commit();
            $mensajeExito = "Salida / Venta #" . $salidaId . " (" . count($detallesPreparados) . " producto(s)) registrada exitosamente. Total: $" . number_format($totalGeneral, 2);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al registrar la salida: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Agrega al menos un producto válido al ticket antes de guardar la salida.";
    }
}

// 2. PROCESAR EDICIÓN DE SALIDA / VENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_editar_salida'])) {
    $salidaId         = intval($_POST['salida_id'] ?? 0);
    $clienteNombre    = !empty(trim($_POST['cliente'] ?? '')) ? trim($_POST['cliente']) : 'Público General';
    $nuevaCantidad    = floatval($_POST['cantidad'] ?? 0);
    $nuevoPrecio      = floatval($_POST['precio_venta'] ?? 0);
    $estadoCobro      = $_POST['estado_cobro'] ?? 'cobrado';
    $fechaVencimiento = ($estadoCobro === 'credito' && !empty($_POST['fecha_vencimiento'])) ? $_POST['fecha_vencimiento'] : null;
    $metodoCobro      = $_POST['metodo_cobro'] ?? 'efectivo';
    $requiereFactura  = isset($_POST['requiere_factura']) ? 1 : 0;
    $fecha            = $_POST['fecha'] ?? '';

    if ($salidaId > 0 && $nuevaCantidad > 0 && $nuevoPrecio >= 0) {
        try {
            $pdo->beginTransaction();

            $stmtDetActual = $pdo->prepare("SELECT producto_id, cantidad FROM detalle_salidas WHERE salida_id = ? FOR UPDATE");
            $stmtDetActual->execute([$salidaId]);
            $detalleActual = $stmtDetActual->fetch(PDO::FETCH_ASSOC);

            if ($detalleActual) {
                $productoId       = intval($detalleActual['producto_id']);
                $cantidadAnterior = floatval($detalleActual['cantidad']);
                $diferenciaCant   = $nuevaCantidad - $cantidadAnterior;

                if ($diferenciaCant > 0) {
                    $stmtStk = $pdo->prepare("SELECT stock_actual FROM productos WHERE id = ? FOR UPDATE");
                    $stmtStk->execute([$productoId]);
                    $stockDisp = floatval($stmtStk->fetchColumn() ?? 0);

                    if ($stockDisp < $diferenciaCant) {
                        throw new Exception("Stock insuficiente para aumentar la venta. Stock actual disponible: " . number_format($stockDisp, 2));
                    }
                }

                $stmtAdjStk = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?");
                $stmtAdjStk->execute([$diferenciaCant, $productoId]);

                $subtotalNuevo = $nuevaCantidad * $nuevoPrecio;
                $stmtUpdDet = $pdo->prepare("UPDATE detalle_salidas SET cantidad = ?, precio_unitario = ?, subtotal = ? WHERE salida_id = ?");
                $stmtUpdDet->execute([$nuevaCantidad, $nuevoPrecio, $subtotalNuevo, $salidaId]);
            } else {
                $subtotalNuevo = $nuevaCantidad * $nuevoPrecio;
            }

            $ivaNuevo   = $requiereFactura ? ($subtotalNuevo * 0.16) : 0.00;
            $totalNuevo = $subtotalNuevo + $ivaNuevo;
            $tipoPago   = ($estadoCobro === 'credito') ? 'credito' : 'contado';

            $sqlComprobante = "";
            $paramsComprobante = [];

            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas)) {
                    $dirSubida = 'uploads/ventas/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0777, true);
                    }

                    $stmtFile = $pdo->prepare("SELECT factura_url FROM salidas WHERE id = ?");
                    $stmtFile->execute([$salidaId]);
                    $oldFile = $stmtFile->fetchColumn();
                    if ($oldFile && file_exists(__DIR__ . '/' . $oldFile)) {
                        @unlink(__DIR__ . '/' . $oldFile);
                    }

                    $nombreArchivo = 'salida_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $facturaUrl = $dirSubida . $nombreArchivo;
                    if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $facturaUrl)) {
                        $sqlComprobante = ", factura_url = ?";
                        $paramsComprobante[] = $facturaUrl;
                    }
                }
            }

            $sqlSalida = "UPDATE salidas SET
                cliente = ?, subtotal = ?, iva = ?, total = ?, monto_total = ?, estado_cobro = ?,
                fecha_vencimiento = ?, metodo_cobro = ?, requiere_factura = ?, con_factura = ?,
                tipo_pago = ?, metodo_pago = ?";

            $paramsSalida = [
                $clienteNombre, $subtotalNuevo, $ivaNuevo, $totalNuevo, $totalNuevo,
                $estadoCobro, $fechaVencimiento, $metodoCobro, $requiereFactura, $requiereFactura,
                $tipoPago, $metodoCobro
            ];

            if (!empty($fecha)) {
                $sqlSalida .= ", fecha = ?";
                $paramsSalida[] = $fecha;
            }

            if (!empty($sqlComprobante)) {
                $sqlSalida .= $sqlComprobante;
                $paramsSalida = array_merge($paramsSalida, $paramsComprobante);
            }

            $sqlSalida .= " WHERE id = ?";
            $paramsSalida[] = $salidaId;

            $stmtUpdSal = $pdo->prepare($sqlSalida);
            $stmtUpdSal->execute($paramsSalida);

            $pdo->commit();
            $mensajeExito = "La salida #{$salidaId} fue actualizada exitosamente.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al editar la salida: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Datos de edición no válidos.";
    }
}

// 3. ELIMINAR REGISTRO DE SALIDA / VENTA (RESTRINGIDO A ADMINISTRADOR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_eliminar_salida'])) {
    if (!$puedeEliminar) {
        $mensajeError = "Acceso denegado. El rol '{$userRol}' no tiene permisos para eliminar salidas o ventas.";
    } else {
        $salidaId = intval($_POST['salida_id'] ?? 0);

        if ($salidaId > 0) {
            try {
                $pdo->beginTransaction();

                $stmtDet = $pdo->prepare("SELECT producto_id, cantidad FROM detalle_salidas WHERE salida_id = ?");
                $stmtDet->execute([$salidaId]);
                $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

                $stmtRestaurar = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
                foreach ($detalles as $item) {
                    $stmtRestaurar->execute([$item['cantidad'], $item['producto_id']]);
                }

                $stmtImg = $pdo->prepare("SELECT factura_url FROM salidas WHERE id = ?");
                $stmtImg->execute([$salidaId]);
                $archivo = $stmtImg->fetchColumn();

                if ($archivo && file_exists(__DIR__ . '/' . $archivo)) {
                    @unlink(__DIR__ . '/' . $archivo);
                }

                $stmtDelIng = $pdo->prepare("DELETE FROM ingresos WHERE salida_id = ?");
                $stmtDelIng->execute([$salidaId]);

                $stmtDelDet = $pdo->prepare("DELETE FROM detalle_salidas WHERE salida_id = ?");
                $stmtDelDet->execute([$salidaId]);

                $stmtDelSal = $pdo->prepare("DELETE FROM salidas WHERE id = ?");
                $stmtDelSal->execute([$salidaId]);

                $pdo->commit();
                $mensajeExito = "La salida #{$salidaId} fue eliminada y los productos regresaron al inventario.";
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensajeError = "Error al eliminar la salida: " . $e->getMessage();
            }
        }
    }
}

// Cargar Catálogo de Productos
$productos = [];
try {
    $stmtProd = $pdo->query("SELECT id, nombre, stock_actual, tipo_unidad, unidades_por_empaque, precio_venta FROM productos ORDER BY nombre ASC");
    if ($stmtProd) {
        $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\PDOException $e) {
    $mensajeError = "Error al consultar catálogo de productos: " . $e->getMessage();
}

// Cargar Historial (agrupando múltiples productos si la salida tiene más de uno)
$salidas = [];
try {
    $sqlSalidas = "SELECT s.*,
                          GROUP_CONCAT(CONCAT(COALESCE(p.nombre, 'Varios / N/A'), ' (', FORMAT(ds.cantidad, 2), ')') SEPARATOR ', ') AS producto_nombre,
                          COALESCE(SUM(ds.cantidad), 0) AS cantidad,
                          CASE WHEN COUNT(ds.id) = 1 THEN MAX(ds.precio_unitario) ELSE NULL END AS precio_unitario
                   FROM salidas s
                   LEFT JOIN detalle_salidas ds ON s.id = ds.salida_id
                   LEFT JOIN productos p ON ds.producto_id = p.id
                   GROUP BY s.id
                   ORDER BY s.id DESC";
    $stmtSal = $pdo->query($sqlSalidas);
    if ($stmtSal) {
        $salidas = $stmtSal->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\PDOException $e) {
    $mensajeError = "Error al consultar historial de salidas: " . $e->getMessage();
}

// Alertas de Cobro
$alertasVencidas = [];
$alertasPorVencer = [];
$fechaHoy = date('Y-m-d');

foreach ($salidas as $s) {
    if (($s['estado_cobro'] ?? '') === 'credito' && !empty($s['fecha_vencimiento'])) {
        $fVenc = date('Y-m-d', strtotime($s['fecha_vencimiento']));
        if ($fVenc < $fechaHoy) {
            $alertasVencidas[] = $s;
        } elseif ($fVenc <= date('Y-m-d', strtotime('+3 days'))) {
            $alertasPorVencer[] = $s;
        }
    }
}
?>

<!-- Librería html2pdf para descarga directa en PDF del ticket previsualizado -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-arrow-up-right text-danger me-2"></i> Salidas / Ventas de Producto</h2>
</div>

<!-- ALERTAS DE CRÉDITO Y COBRO -->
<?php if (!empty($alertasVencidas)): ?>
    <div class="alert alert-danger shadow-sm border-2 border-danger alert-dismissible fade show" role="alert">
        <h5 class="alert-heading fw-bold mb-2">
            <i class="bi bi-exclamation-diamond-fill me-2 fs-4"></i>
            ¡Atención! Hay <?= count($alertasVencidas) ?> venta(s) a crédito VENCIDAS pendientes de cobrar:
        </h5>
        <ul class="mb-0 ps-3">
            <?php foreach ($alertasVencidas as $aV): ?>
                <li>
                    <strong>Venta #<?= $aV['id'] ?></strong> - Cliente: <u><?= htmlspecialchars($aV['cliente']) ?></u> -
                    Monto: <strong>$<?= number_format(floatval($aV['total'] ?? $aV['monto_total'] ?? 0), 2) ?></strong> -
                    Venció el: <span class="badge bg-danger"><?= date('d/m/Y', strtotime($aV['fecha_vencimiento'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($alertasPorVencer)): ?>
    <div class="alert alert-warning shadow-sm border-2 border-warning alert-dismissible fade show" role="alert">
        <h5 class="alert-heading fw-bold mb-2">
            <i class="bi bg-warning text-dark bi-bell-fill me-2 fs-5 p-1 rounded"></i>
            Próximos cobros a crédito (Por vencer pronto u hoy):
        </h5>
        <ul class="mb-0 ps-3">
            <?php foreach ($alertasPorVencer as $aP): ?>
                <li>
                    <strong>Venta #<?= $aP['id'] ?></strong> - Cliente: <u><?= htmlspecialchars($aP['cliente']) ?></u> -
                    Monto: <strong>$<?= number_format(floatval($aP['total'] ?? $aP['monto_total'] ?? 0), 2) ?></strong> -
                    Vence el: <span class="badge bg-dark"><?= date('d/m/Y', strtotime($aP['fecha_vencimiento'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($mensajeExito): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($mensajeExito) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($mensajeError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($mensajeError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- FORMULARIO REGISTRAR SALIDA CON MULTI-PRODUCTO Y PREVISUALIZADOR INTEGRADO -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-dash-circle me-1"></i> Registrar Nueva Salida
    </div>
    <div class="card-body">
        <form method="POST" action="salidas.php" id="formSalida" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="guardar_salida" value="1">
            <input type="hidden" name="items_ticket_json" id="items_ticket_json" value="[]">

            <!-- LADO IZQUIERDO: FORMULARIO DE CAPTURA -->
            <div class="col-lg-7 row g-3 m-0 p-0 pe-lg-3 border-end">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Producto (*):</label>
                    <select name="producto_id" id="select_producto" class="form-select">
                        <option value="" data-precio="" data-stock="0" data-unidad="Pieza" data-empaque="1">-- Seleccionar Producto --</option>
                        <?php if (!empty($productos)): ?>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= htmlspecialchars($p['id']) ?>"
                                        data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
                                        data-precio="<?= htmlspecialchars($p['precio_venta'] ?? 0) ?>"
                                        data-stock="<?= htmlspecialchars($p['stock_actual'] ?? 0) ?>"
                                        data-unidad="<?= htmlspecialchars($p['tipo_unidad'] ?? 'Pieza') ?>"
                                        data-empaque="<?= htmlspecialchars($p['unidades_por_empaque'] ?? 1) ?>">
                                    <?= htmlspecialchars($p['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <div id="card_info_stock" class="card mt-2 d-none border-primary bg-light">
                        <div class="card-body p-2 text-center">
                            <small class="text-muted d-block fw-bold mb-1">DISPONIBILIDAD EN ALMACÉN</small>
                            <span id="badge_stock_status" class="badge bg-success fs-6 mb-1">
                                <i class="bi bi-boxes me-1"></i> <span id="lbl_stock_cant">0</span> <span id="lbl_stock_unidad"></span>
                            </span>
                            <div class="small text-muted">
                                Precio Base Unitario: <strong id="lbl_stock_precio" class="text-dark">$0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold">Cliente:</label>
                    <input type="text" name="cliente" id="input_cliente" class="form-control" placeholder="Público General / Mostrador">
                </div>

                <!-- SELECTOR DE MODALIDAD DE VENTA -->
                <div class="col-md-6">
                    <label class="form-label fw-bold text-primary"><i class="bi bi-aspect-ratio me-1"></i> Modulo / Forma de Venta (*):</label>
                    <select name="modalidad_venta" id="select_modalidad_venta" class="form-select border-primary fw-bold">
                        <option value="unidad">Por Unidad / Pieza Individual</option>
                        <option value="empaque" id="opt_empaque">Por Empaque Completo (Caja / Millar)</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold" id="lbl_input_cantidad">Cantidad (*):</label>
                    <input type="number" step="0.01" min="0.01" name="cantidad" id="input_cantidad" class="form-control" placeholder="0.00">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold" id="lbl_input_precio">Precio ($) (*):</label>
                    <input type="number" step="0.01" min="0" name="precio_venta" id="input_precio_venta" class="form-control" placeholder="0.00">
                </div>

                <!-- BOTÓN PARA AGREGAR MÚLTIPLES PRODUCTOS AL MISMO TICKET -->
                <div class="col-12">
                    <button type="button" class="btn btn-primary fw-bold" id="btnAgregarItem">
                        <i class="bi bi-plus-circle me-1"></i> Agregar Producto al Ticket
                    </button>
                </div>

                <!-- TABLA DE PRODUCTOS AGREGADOS AL TICKET -->
                <div class="col-12">
                    <div class="table-responsive border rounded">
                        <table class="table table-sm table-striped align-middle mb-0" id="tablaItemsTicket">
                            <thead class="table-light">
                                <tr>
                                    <th>Producto</th>
                                    <th class="text-end">Cant.</th>
                                    <th class="text-end">Precio</th>
                                    <th class="text-end">Importe</th>
                                    <th class="text-center" style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="tbodyItemsTicket">
                                <tr id="filaVaciaTicket">
                                    <td colspan="5" class="text-center text-muted py-2 small">
                                        Sin productos agregados aún (puedes agregar uno o varios al mismo ticket).
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Estatus del Cobro (*):</label>
                    <select name="estado_cobro" id="select_estado_cobro" class="form-select" required>
                        <option value="cobrado">Cobrado (Contado)</option>
                        <option value="credito">A Crédito (Manda a CxC)</option>
                    </select>
                </div>

                <!-- PLAZO AUTOMÁTICO (30, 60, 90 DÍAS) + CALENDARIO CUANDO ES A CRÉDITO -->
                <div class="col-md-4 d-none" id="div_plazo_vencimiento">
                    <label class="form-label fw-bold text-danger"><i class="bi bi-clock-history me-1"></i> Plazo Crédito:</label>
                    <select id="select_plazo_vencimiento" class="form-select border-danger">
                        <option value="">-- Manual / Libre --</option>
                        <option value="30">30 Días</option>
                        <option value="60">60 Días</option>
                        <option value="90">90 Días</option>
                    </select>
                </div>

                <div class="col-md-4 d-none" id="div_fecha_vencimiento">
                    <label class="form-label fw-bold text-danger"><i class="bi bi-calendar-event me-1"></i> Vencimiento Crédito (*):</label>
                    <input type="date" name="fecha_vencimiento" id="input_fecha_vencimiento" class="form-control border-danger">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold">Método de Cobro:</label>
                    <select name="metodo_cobro" id="select_metodo_cobro" class="form-select">
                        <option value="efectivo">Efectivo</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="tarjeta">Tarjeta</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-bold">Comprobante Adjunto:</label>
                    <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                </div>

                <div class="col-12 mt-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="requiere_factura" id="requiere_factura" value="1">
                        <label class="form-check-label fw-bold" for="requiere_factura">
                            ¿Requiere Factura (+16% IVA)?
                        </label>
                    </div>
                </div>

                <!-- TOTALES EN TIEMPO REAL -->
                <div class="col-12 bg-light p-3 rounded border mt-2">
                    <div class="row text-center">
                        <div class="col-4">
                            <span class="text-muted d-block small">Subtotal:</span>
                            <strong id="lbl_subtotal" class="fs-6">$0.00</strong>
                        </div>
                        <div class="col-4">
                            <span class="text-muted d-block small">IVA (16%):</span>
                            <strong id="lbl_iva" class="fs-6 text-warning">$0.00</strong>
                        </div>
                        <div class="col-4">
                            <span class="text-muted d-block small">Total Final:</span>
                            <strong id="lbl_total" class="fs-5 text-success">$0.00</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LADO DERECHO: PREVISUALIZADOR DE TICKET EN TIEMPO REAL (ÁREA AMARILLA) -->
            <div class="col-lg-5 d-flex flex-column align-items-center justify-content-between p-3 bg-light rounded border">
                <div class="w-100 text-center mb-2">
                    <span class="badge bg-dark text-white uppercase px-3 py-2"><i class="bi bi-eye me-1"></i> Vista Previa de Ticket Térmico</span>
                </div>

                <!-- CONTENEDOR DEL TICKET EN VIVO (Formato 80mm) -->
                <div id="ticket_preview_container" style="width: 280px; background: #fff; padding: 15px; border: 1px solid #ccc; font-family: 'Courier New', Courier, monospace; font-size: 0.8rem; border-radius: 4px; box-shadow: 0 2px 6px rgba(0,0,0,0.15);">
                    <div class="text-center border-bottom pb-2 mb-2">
                        <strong style="font-size: 1rem; display: block;">PLASTICOS ALISAKA</strong>
                        <small>Ticket de Venta / Salida</small>
                    </div>
                    <div style="font-size: 0.75rem; margin-bottom: 8px;">
                        <div><strong>Fecha:</strong> <span id="pv_fecha"><?= date('d/m/Y H:i') ?></span></div>
                        <div><strong>Cliente:</strong> <span id="pv_cliente">Público General</span></div>
                        <div><strong>Pago:</strong> <span id="pv_pago">Efectivo (Cobrado)</span></div>
                        <div id="pv_venc_row" style="display: none;"><strong>Vence:</strong> <span id="pv_venc">-</span></div>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.75rem; margin-bottom: 8px;">
                        <thead>
                            <tr style="border-bottom: 1px dashed #000;">
                                <th style="text-align: left;">Cant/Prod</th>
                                <th style="text-align: right;">P.U.</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="pv_items_tbody">
                            <tr>
                                <td colspan="3" style="padding: 6px 0; text-align: center; color: #666;">-- Sin productos --</td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="font-size: 0.8rem; text-align: right;">
                        <div>Subtotal: <span id="pv_subtotal">$0.00</span></div>
                        <div>IVA (16%): <span id="pv_iva">$0.00</span></div>
                        <div style="font-weight: bold; font-size: 0.9rem; margin-top: 4px; border-top: 1px dashed #000; padding-top: 4px;">
                            TOTAL: <span id="pv_total">$0.00</span>
                        </div>
                    </div>
                    <div class="text-center mt-3 pt-2 border-top" style="font-size: 0.65rem; color: #555;">
                        ¡Gracias por su compra!<br>PLASTICOS ALISAKA
                    </div>
                </div>

                <!-- ACCIONES Y BOTÓN DE GUARDAR SALIDA -->
                <div class="w-100 mt-3 d-flex flex-column gap-2">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-dark btn-sm w-50 fw-bold" onclick="imprimirVistaPrevia()">
                            <i class="bi bi-printer me-1"></i> Imprimir Ticket
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm w-50 fw-bold" onclick="descargarTicketPDF()">
                            <i class="bi bi-file-earmark-pdf me-1"></i> Descargar PDF
                        </button>
                    </div>
                    <button type="submit" class="btn btn-danger btn-lg fw-bold w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Guardar Salida
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- HISTORIAL DE SALIDAS -->
<div class="card shadow-sm">
    <div class="card-header bg-secondary text-white fw-bold">
        <i class="bi bi-journal-text me-1"></i> Historial de Salidas Recientes
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Fecha</th>
                        <th>Producto</th>
                        <th>Cliente</th>
                        <th class="text-end">Cant. Base</th>
                        <th class="text-end">Precio U.</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">IVA</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Estatus</th>
                        <th class="text-center">Vencimiento</th>
                        <th class="text-center">Comprobante</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salidas)): ?>
                        <tr><td colspan="13" class="text-center py-3 text-muted">No hay registros de salidas aún.</td></tr>
                    <?php else: ?>
                        <?php foreach ($salidas as $s): ?>
                            <?php
                                $fecha = $s['fecha'] ?? $s['fecha_salida'] ?? null;
                                $subtotalMostrar = floatval($s['subtotal'] ?? 0);
                                $ivaMostrar = floatval($s['iva'] ?? 0);
                                $totalMostrar = floatval($s['total'] ?? $s['monto_total'] ?? 0);
                                $metodo = $s['metodo_cobro'] ?? $s['metodo_pago'] ?? 'efectivo';

                                $vencimientoTexto = '-';
                                $badgeVencimiento = 'secondary';
                                if (($s['estado_cobro'] ?? '') === 'credito' && !empty($s['fecha_vencimiento'])) {
                                    $fVenc = date('Y-m-d', strtotime($s['fecha_vencimiento']));
                                    $vencimientoTexto = date('d/m/Y', strtotime($fVenc));

                                    if ($fVenc < $fechaHoy) {
                                        $badgeVencimiento = 'danger';
                                    } elseif ($fVenc <= date('Y-m-d', strtotime('+3 days'))) {
                                        $badgeVencimiento = 'warning text-dark';
                                    } else {
                                        $badgeVencimiento = 'info text-dark';
                                    }
                                }
                            ?>
                            <tr>
                                <td>#<?= $s['id'] ?></td>
                                <td><?= $fecha ? date('d/m/Y H:i', strtotime($fecha)) : 'N/A' ?></td>
                                <td><?= htmlspecialchars($s['producto_nombre'] ?? 'Varios / N/A') ?></td>
                                <td><?= htmlspecialchars($s['cliente'] ?? 'Público General') ?></td>
                                <td class="text-end fw-bold"><?= number_format(floatval($s['cantidad'] ?? 0), 2) ?></td>
                                <td class="text-end">
                                    <?= ($s['precio_unitario'] !== null) ? '$' . number_format(floatval($s['precio_unitario']), 2) : '<span class="text-muted small">Varios</span>' ?>
                                </td>
                                <td class="text-end">$<?= number_format($subtotalMostrar, 2) ?></td>
                                <td class="text-end text-muted">$<?= number_format($ivaMostrar, 2) ?></td>
                                <td class="text-end fw-bold text-success">$<?= number_format($totalMostrar, 2) ?></td>
                                <td class="text-center">
                                    <?php if (($s['estado_cobro'] ?? '') === 'cobrado'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Cobrado</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Crédito</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($vencimientoTexto !== '-'): ?>
                                        <span class="badge bg-<?= $badgeVencimiento ?>">
                                            <i class="bi bi-calendar-event me-1"></i><?= $vencimientoTexto ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($s['factura_url']) && file_exists(__DIR__ . '/' . $s['factura_url'])): ?>
                                        <a href="<?= htmlspecialchars($s['factura_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark-arrow-down"></i> Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Sin archivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="ticket_salida.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-outline-info" title="Imprimir Ticket">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-warning"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarSalida"
                                                data-id="<?= $s['id'] ?>"
                                                data-cliente="<?= htmlspecialchars($s['cliente'] ?? '') ?>"
                                                data-cantidad="<?= $s['cantidad'] ?? 0 ?>"
                                                data-precio="<?= $s['precio_unitario'] ?? 0 ?>"
                                                data-estado="<?= $s['estado_cobro'] ?? 'cobrado' ?>"
                                                data-vencimiento="<?= $s['fecha_vencimiento'] ?? '' ?>"
                                                data-metodo="<?= $metodo ?>"
                                                data-factura="<?= $s['requiere_factura'] ?? ($s['iva'] > 0 ? 1 : 0) ?>"
                                                data-fecha="<?= $fecha ? date('Y-m-d\TH:i', strtotime($fecha)) : '' ?>"
                                                data-producto="<?= htmlspecialchars($s['producto_nombre'] ?? '') ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- CONDICIONAL: SOLO EL ADMINISTRADOR PUEDE VER Y EJECUTAR LA ELIMINACIÓN -->
                                        <?php if ($puedeEliminar): ?>
                                            <form method="POST" action="salidas.php" class="d-inline" onsubmit="return confirm('¿Confirmas eliminar esta salida #<?= $s['id'] ?>? Las cantidades vendidas regresarán al inventario.');">
                                                <input type="hidden" name="accion_eliminar_salida" value="1">
                                                <input type="hidden" name="salida_id" value="<?= $s['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Eliminar Salida">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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

<!-- MODAL EDITAR SALIDA -->
<div class="modal fade" id="modalEditarSalida" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="salidas.php" enctype="multipart/form-data">
                <input type="hidden" name="accion_editar_salida" value="1">
                <input type="hidden" name="salida_id" id="edit_salida_id">

                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Editar Registro de Salida / Venta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Producto Registrado:</label>
                        <input type="text" id="edit_producto_nombre" class="form-control bg-light" readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Cliente:</label>
                        <input type="text" name="cliente" id="edit_cliente" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Cantidad (*):</label>
                        <input type="number" step="0.01" min="0.01" name="cantidad" id="edit_cantidad" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Precio Unitario ($) (*):</label>
                        <input type="number" step="0.01" min="0" name="precio_venta" id="edit_precio_venta" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Fecha del Registro:</label>
                        <input type="datetime-local" name="fecha" id="edit_fecha" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Estatus del Cobro (*):</label>
                        <select name="estado_cobro" id="edit_estado_cobro" class="form-select" required>
                            <option value="cobrado">Cobrado (Contado)</option>
                            <option value="credito">A Crédito (Manda a CxC)</option>
                        </select>
                    </div>

                    <div class="col-md-4 d-none" id="edit_div_plazo">
                        <label class="form-label fw-bold text-danger"><i class="bi bi-clock-history me-1"></i> Plazo Crédito:</label>
                        <select id="edit_select_plazo" class="form-select border-danger">
                            <option value="">-- Manual / Libre --</option>
                            <option value="30">30 Días</option>
                            <option value="60">60 Días</option>
                            <option value="90">90 Días</option>
                        </select>
                    </div>

                    <div class="col-md-4 d-none" id="edit_div_vencimiento">
                        <label class="form-label fw-bold text-danger"><i class="bi bi-calendar-event me-1"></i> Fecha Vencimiento Crédito:</label>
                        <input type="date" name="fecha_vencimiento" id="edit_fecha_vencimiento" class="form-control border-danger">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Método de Cobro:</label>
                        <select name="metodo_cobro" id="edit_metodo_cobro" class="form-select">
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Adjuntar / Reemplazar Comprobante:</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="requiere_factura" id="edit_requiere_factura" value="1">
                            <label class="form-check-label fw-bold" for="edit_requiere_factura">
                                ¿Requiere Factura (+16% IVA)?
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning fw-bold"><i class="bi bi-check-lg me-1"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const formSalida       = document.getElementById('formSalida');
    const selectProducto   = document.getElementById('select_producto');
    const selectModalidad  = document.getElementById('select_modalidad_venta');
    const optEmpaque       = document.getElementById('opt_empaque');
    const inputPrecio      = document.getElementById('input_precio_venta');
    const inputCantidad    = document.getElementById('input_cantidad');
    const inputCliente     = document.getElementById('input_cliente');
    const chkFactura       = document.getElementById('requiere_factura');

    const btnAgregarItem   = document.getElementById('btnAgregarItem');
    const tbodyItems       = document.getElementById('tbodyItemsTicket');
    const inputItemsJson   = document.getElementById('items_ticket_json');

    const selectEstadoCobro = document.getElementById('select_estado_cobro');
    const selectMetodoCobro = document.getElementById('select_metodo_cobro');
    const divPlazo          = document.getElementById('div_plazo_vencimiento');
    const selectPlazo       = document.getElementById('select_plazo_vencimiento');
    const divVencimiento    = document.getElementById('div_fecha_vencimiento');
    const inputVencimiento  = document.getElementById('input_fecha_vencimiento');

    const cardStock        = document.getElementById('card_info_stock');
    const badgeStockStatus = document.getElementById('badge_stock_status');
    const lblStockCant     = document.getElementById('lbl_stock_cant');
    const lblStockUnidad   = document.getElementById('lbl_stock_unidad');
    const lblStockPrecio   = document.getElementById('lbl_stock_precio');

    const lblSubtotal      = document.getElementById('lbl_subtotal');
    const lblIva           = document.getElementById('lbl_iva');
    const lblTotal         = document.getElementById('lbl_total');

    // Elementos de la previsualización del ticket en tiempo real
    const pvCliente    = document.getElementById('pv_cliente');
    const pvPago       = document.getElementById('pv_pago');
    const pvVencRow    = document.getElementById('pv_venc_row');
    const pvVenc       = document.getElementById('pv_venc');
    const pvItemsTbody = document.getElementById('pv_items_tbody');
    const pvSubtotal   = document.getElementById('pv_subtotal');
    const pvIva        = document.getElementById('pv_iva');
    const pvTotal      = document.getElementById('pv_total');

    let itemsTicket = [];

    function calcularFechaMasDias(dias) {
        const d = parseInt(dias, 10);
        if (isNaN(d) || d <= 0) return '';
        const fecha = new Date();
        fecha.setDate(fecha.getDate() + d);
        const anio = fecha.getFullYear();
        const mes = String(fecha.getMonth() + 1).padStart(2, '0');
        const dia = String(fecha.getDate()).padStart(2, '0');
        return `${anio}-${mes}-${dia}`;
    }

    function toggleVencimiento() {
        if (selectEstadoCobro && selectEstadoCobro.value === 'credito') {
            if (divPlazo) divPlazo.classList.remove('d-none');
            if (divVencimiento) divVencimiento.classList.remove('d-none');
            if (inputVencimiento) inputVencimiento.setAttribute('required', 'required');
        } else {
            if (divPlazo) divPlazo.classList.add('d-none');
            if (selectPlazo) selectPlazo.value = '';
            if (divVencimiento) divVencimiento.classList.add('d-none');
            if (inputVencimiento) {
                inputVencimiento.removeAttribute('required');
                inputVencimiento.value = '';
            }
        }
        renderizarTodo();
    }

    if (selectEstadoCobro) {
        selectEstadoCobro.addEventListener('change', toggleVencimiento);
    }

    if (selectPlazo) {
        selectPlazo.addEventListener('change', function() {
            const nuevaFecha = calcularFechaMasDias(this.value);
            if (nuevaFecha && inputVencimiento) {
                inputVencimiento.value = nuevaFecha;
                renderizarTodo();
            }
        });
    }

    function obtenerItemBorrador() {
        const pId = parseInt(selectProducto.value || '0', 10);
        const cant = parseFloat(inputCantidad.value || '0');
        const prec = parseFloat(inputPrecio.value || '0');
        if (pId > 0 && cant > 0 && prec >= 0) {
            const opt = selectProducto.options[selectProducto.selectedIndex];
            const nombre = opt.getAttribute('data-nombre') || 'Producto';
            const mod = selectModalidad.value === 'empaque' ? 'empaque' : 'unidad';
            const unidad = opt.getAttribute('data-unidad') || 'Pieza';
            const etiqCant = mod === 'empaque' ? `${cant} ${unidad}(s)` : `${cant} unid(s)`;
            return {
                producto_id: pId,
                nombre: nombre,
                modalidad_venta: mod,
                cantidad: cant,
                precio_venta: prec,
                etiq_cant: etiqCant,
                importe: cant * prec
            };
        }
        return null;
    }

    function renderizarTodo() {
        // Actualizar tabla de ítems agregados
        tbodyItems.innerHTML = '';
        if (itemsTicket.length === 0) {
            tbodyItems.innerHTML = `<tr id="filaVaciaTicket"><td colspan="5" class="text-center text-muted py-2 small">Sin productos agregados aún (puedes agregar uno o varios al mismo ticket).</td></tr>`;
        } else {
            itemsTicket.forEach((it, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><small class="fw-bold">${it.nombre}</small></td>
                    <td class="text-end small">${it.etiq_cant}</td>
                    <td class="text-end small">$${it.precio_venta.toFixed(2)}</td>
                    <td class="text-end small fw-bold">$${it.importe.toFixed(2)}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="quitarItemTicket(${idx})" title="Quitar">
                            <i class="bi bi-x"></i>
                        </button>
                    </td>
                `;
                tbodyItems.appendChild(tr);
            });
        }

        // Guardar JSON oculto (si no hay ítems agregados pero sí un borrador válido, incluirlo)
        const listaEfectiva = itemsTicket.length > 0 ? itemsTicket : (obtenerItemBorrador() ? [obtenerItemBorrador()] : []);
        inputItemsJson.value = JSON.stringify(itemsTicket.length > 0 ? itemsTicket : []);

        const subtotal = listaEfectiva.reduce((acc, item) => acc + item.importe, 0);
        const requiereFactura = chkFactura.checked;
        const iva = requiereFactura ? (subtotal * 0.16) : 0;
        const total = subtotal + iva;

        const subtotalFmt = '$' + subtotal.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const ivaFmt      = '$' + iva.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const totalFmt    = '$' + total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        lblSubtotal.textContent = subtotalFmt;
        lblIva.textContent      = ivaFmt;
        lblTotal.textContent    = totalFmt;

        // Actualizar vista previa del Ticket en vivo
        const clienteTxt = inputCliente.value.trim() !== '' ? inputCliente.value.trim() : 'Público General';
        const metodoTxt  = selectMetodoCobro.options[selectMetodoCobro.selectedIndex].text + (selectEstadoCobro.value === 'credito' ? ' (Crédito)' : ' (Cobrado)');

        pvCliente.textContent = clienteTxt;
        pvPago.textContent    = metodoTxt;

        if (selectEstadoCobro.value === 'credito' && inputVencimiento && inputVencimiento.value) {
            pvVencRow.style.display = 'block';
            pvVenc.textContent = inputVencimiento.value;
        } else {
            pvVencRow.style.display = 'none';
            pvVenc.textContent = '-';
        }

        pvItemsTbody.innerHTML = '';
        if (listaEfectiva.length === 0) {
            pvItemsTbody.innerHTML = `<tr><td colspan="3" style="padding: 6px 0; text-align: center; color: #666;">-- Sin productos --</td></tr>`;
        } else {
            listaEfectiva.forEach(item => {
                const trProd = document.createElement('tr');
                trProd.innerHTML = `<td colspan="3" style="padding-top: 4px; font-weight: bold;">${item.nombre}</td>`;
                const trDet = document.createElement('tr');
                trDet.style.borderBottom = '1px dashed #ccc';
                trDet.innerHTML = `
                    <td style="padding-bottom: 4px;">${item.cantidad.toFixed(2)}</td>
                    <td style="text-align: right; padding-bottom: 4px;">$${item.precio_venta.toFixed(2)}</td>
                    <td style="text-align: right; padding-bottom: 4px;">$${item.importe.toFixed(2)}</td>
                `;
                pvItemsTbody.appendChild(trProd);
                pvItemsTbody.appendChild(trDet);
            });
        }

        pvSubtotal.textContent = subtotalFmt;
        pvIva.textContent      = ivaFmt;
        pvTotal.textContent    = totalFmt;
    }

    window.quitarItemTicket = function(index) {
        itemsTicket.splice(index, 1);
        renderizarTodo();
    };

    if (btnAgregarItem) {
        btnAgregarItem.addEventListener('click', function() {
            const borrador = obtenerItemBorrador();
            if (!borrador) {
                alert("Selecciona un producto, ingresa una cantidad mayor a 0 y un precio válido para agregarlo al ticket.");
                return;
            }
            itemsTicket.push(borrador);
            // Limpiar selección rápida para seguir agregando más productos
            selectProducto.value = '';
            cardStock.classList.add('d-none');
            inputCantidad.value = '';
            inputPrecio.value = '';
            renderizarTodo();
        });
    }

    if (formSalida) {
        formSalida.addEventListener('submit', function(e) {
            if (itemsTicket.length === 0) {
                const borrador = obtenerItemBorrador();
                if (!borrador) {
                    e.preventDefault();
                    alert("Agrega al menos un producto al ticket o completa los campos de producto, cantidad y precio.");
                    return;
                }
                itemsTicket.push(borrador);
            }
            inputItemsJson.value = JSON.stringify(itemsTicket);
        });
    }

    function actualizarModalidadVenta() {
        const selectedOption = selectProducto.options[selectProducto.selectedIndex];
        if (!selectedOption || selectProducto.value === "") return;

        const precioBase = parseFloat(selectedOption.getAttribute('data-precio') || 0);
        const tipoUnidad = selectedOption.getAttribute('data-unidad') || 'Pieza';
        const empaque    = parseFloat(selectedOption.getAttribute('data-empaque') || 1);

        if (selectModalidad.value === 'empaque') {
            const precioEmpaque = precioBase * empaque;
            inputPrecio.value = precioEmpaque.toFixed(2);
            document.getElementById('lbl_input_cantidad').textContent = `Cantidad (${tipoUnidad}s) (*):`;
            document.getElementById('lbl_input_precio').textContent   = `Precio / ${tipoUnidad} ($) (*):`;
        } else {
            inputPrecio.value = precioBase.toFixed(2);
            document.getElementById('lbl_input_cantidad').textContent = "Cantidad (Unidades) (*):";
            document.getElementById('lbl_input_precio').textContent   = "Precio Unitario ($) (*):";
        }

        renderizarTodo();
    }

    if (selectProducto) {
        selectProducto.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const precioBase = selectedOption.getAttribute('data-precio');
            const stock      = parseFloat(selectedOption.getAttribute('data-stock') || 0);
            const unidad     = selectedOption.getAttribute('data-unidad') || 'Pieza';
            const empaque    = parseFloat(selectedOption.getAttribute('data-empaque') || 1);

            if (this.value !== "") {
                cardStock.classList.remove('d-none');
                lblStockCant.textContent   = stock.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                lblStockUnidad.textContent = 'unids base';
                lblStockPrecio.textContent = '$' + (precioBase ? parseFloat(precioBase).toFixed(2) : '0.00');

                if (empaque > 1) {
                    optEmpaque.textContent = `Por ${unidad} Completo (${empaque} unids)`;
                    optEmpaque.disabled = false;
                } else {
                    optEmpaque.textContent = "Por Empaque Completo (N/A)";
                    optEmpaque.disabled = true;
                    selectModalidad.value = 'unidad';
                }

                if (stock <= 0) {
                    badgeStockStatus.className = 'badge bg-danger fs-6 mb-1';
                } else if (stock <= 5) {
                    badgeStockStatus.className = 'badge bg-warning text-dark fs-6 mb-1';
                } else {
                    badgeStockStatus.className = 'badge bg-success fs-6 mb-1';
                }

                actualizarModalidadVenta();
            } else {
                cardStock.classList.add('d-none');
                inputPrecio.value = '';
                renderizarTodo();
            }
        });
    }

    if (selectModalidad)   selectModalidad.addEventListener('change', actualizarModalidadVenta);
    if (inputCantidad)     inputCantidad.addEventListener('input', renderizarTodo);
    if (inputPrecio)       inputPrecio.addEventListener('input', renderizarTodo);
    if (inputCliente)      inputCliente.addEventListener('input', renderizarTodo);
    if (chkFactura)        chkFactura.addEventListener('change', renderizarTodo);
    if (selectMetodoCobro) selectMetodoCobro.addEventListener('change', renderizarTodo);
    if (inputVencimiento)  inputVencimiento.addEventListener('change', renderizarTodo);

    // Modal Editar Salida
    var modalEditar = document.getElementById('modalEditarSalida');
    const editEstadoCobro  = document.getElementById('edit_estado_cobro');
    const editDivPlazo     = document.getElementById('edit_div_plazo');
    const editSelectPlazo  = document.getElementById('edit_select_plazo');
    const editDivVenc      = document.getElementById('edit_div_vencimiento');
    const editInputVenc    = document.getElementById('edit_fecha_vencimiento');

    function toggleEditVencimiento() {
        if (editEstadoCobro && editEstadoCobro.value === 'credito') {
            if (editDivPlazo) editDivPlazo.classList.remove('d-none');
            if (editDivVenc) editDivVenc.classList.remove('d-none');
            if (editInputVenc) editInputVenc.setAttribute('required', 'required');
        } else {
            if (editDivPlazo) editDivPlazo.classList.add('d-none');
            if (editSelectPlazo) editSelectPlazo.value = '';
            if (editDivVenc) editDivVenc.classList.add('d-none');
            if (editInputVenc) editInputVenc.removeAttribute('required');
        }
    }

    if (editEstadoCobro) {
        editEstadoCobro.addEventListener('change', toggleEditVencimiento);
    }

    if (editSelectPlazo) {
        editSelectPlazo.addEventListener('change', function() {
            const nuevaFecha = calcularFechaMasDias(this.value);
            if (nuevaFecha && editInputVenc) {
                editInputVenc.value = nuevaFecha;
            }
        });
    }

    if (modalEditar) {
        modalEditar.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;

            document.getElementById('edit_salida_id').value = button.getAttribute('data-id');
            document.getElementById('edit_producto_nombre').value = button.getAttribute('data-producto');
            document.getElementById('edit_cliente').value = button.getAttribute('data-cliente');
            document.getElementById('edit_cantidad').value = button.getAttribute('data-cantidad');
            document.getElementById('edit_precio_venta').value = button.getAttribute('data-precio');

            const estadoVal = button.getAttribute('data-estado');
            editEstadoCobro.value = estadoVal;
            if (editSelectPlazo) editSelectPlazo.value = '';
            editInputVenc.value   = button.getAttribute('data-vencimiento') || '';
            toggleEditVencimiento();

            document.getElementById('edit_metodo_cobro').value = button.getAttribute('data-metodo');
            document.getElementById('edit_fecha').value = button.getAttribute('data-fecha') || '';
            document.getElementById('edit_requiere_factura').checked = (button.getAttribute('data-factura') === '1');
        });
    }

    renderizarTodo();
});

// Función para imprimir la vista previa del ticket
function imprimirVistaPrevia() {
    const contenido = document.getElementById('ticket_preview_container').outerHTML;
    const ventana = window.open('', '_blank', 'width=400,height=600');

    ventana.document.write('<html><head><title>Imprimir Ticket - PLASTICOS ALISAKA</title>');
    ventana.document.write('<style>');
    ventana.document.write('body { font-family: "Courier New", Courier, monospace; display: flex; justify-content: center; padding: 10px; margin: 0; }');
    ventana.document.write('@media print { body { padding: 0; } }');
    ventana.document.write('</style>');
    ventana.document.write('</head><body>');
    ventana.document.write(contenido);
    ventana.document.write('</body></html>');
    ventana.document.close();

    setTimeout(function() {
        ventana.focus();
        ventana.print();
        ventana.close();
    }, 250);
}

// Función para descargar la vista previa en formato PDF
function descargarTicketPDF() {
    const elemento = document.getElementById('ticket_preview_container');
    const opciones = {
        margin:       5,
        filename:     'ticket_previo_alisaka.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: [80, 150], orientation: 'portrait' }
    };
    html2pdf().set(opciones).from(elemento).save();
}
</script>

<?php require_once 'includes/footer.php'; ?>
