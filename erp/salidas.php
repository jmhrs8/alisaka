<?php
require_once 'includes/header.php';

$mensajeExito = '';
$mensajeError = '';

// Obtener ID del usuario en sesión
$usuarioId = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 1;

// 1. REGISTRAR NUEVA SALIDA / VENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_salida'])) {
    $productoId       = intval($_POST['producto_id'] ?? 0);
    $clienteNombre    = !empty(trim($_POST['cliente'] ?? '')) ? trim($_POST['cliente']) : 'Público General';
    $cantidad         = floatval($_POST['cantidad'] ?? 0);
    $precioVenta      = floatval($_POST['precio_venta'] ?? 0);
    $estadoCobro      = $_POST['estado_cobro'] ?? 'cobrado'; // cobrado | credito
    $fechaVencimiento = ($estadoCobro === 'credito' && !empty($_POST['fecha_vencimiento'])) ? $_POST['fecha_vencimiento'] : null;
    $metodoCobro      = $_POST['metodo_cobro'] ?? 'efectivo';
    $requiereFactura  = isset($_POST['requiere_factura']) ? 1 : 0;
    $facturaUrl       = null;

    if ($productoId > 0 && $cantidad > 0 && $precioVenta >= 0) {
        try {
            $pdo->beginTransaction();

            // Verificar existencias usando stock_actual con bloqueo pesimista
            $stmtP = $pdo->prepare("SELECT id, nombre, stock_actual FROM productos WHERE id = ? FOR UPDATE");
            $stmtP->execute([$productoId]);
            $producto = $stmtP->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                throw new Exception("El producto seleccionado no existe.");
            }

            $stockDisponible = floatval($producto['stock_actual'] ?? 0);

            if ($stockDisponible < $cantidad) {
                throw new Exception("Stock insuficiente. Disponible: " . number_format($stockDisponible, 2));
            }

            // Subida y validación estricta de comprobante
            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $_FILES['comprobante']['tmp_name']);
                    finfo_close($finfo);

                    $mimesPermitidos = [
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'text/xml',
                        'application/xml'
                    ];

                    if (!in_array($mimeType, $mimesPermitidos)) {
                        throw new Exception("El tipo de archivo subido ($mimeType) no es un formato válido.");
                    }

                    $dirSubida = 'uploads/ventas/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0755, true);
                    }
                    $nombreArchivo = 'salida_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $facturaUrl = $dirSubida . $nombreArchivo;
                    if (!move_uploaded_file($_FILES['comprobante']['tmp_name'], $facturaUrl)) {
                        throw new Exception("Error al mover el archivo subido al servidor.");
                    }
                } else {
                    throw new Exception("Extensión de archivo no permitida.");
                }
            }

            // Cálculo de Subtotal, IVA y Total
            $subtotal = $cantidad * $precioVenta;
            $iva = $requiereFactura ? ($subtotal * 0.16) : 0.00;
            $total = $subtotal + $iva;

            $tipoPago = ($estadoCobro === 'credito') ? 'credito' : 'contado';

            // Insertar encabezado de Salida
            $stmtIns = $pdo->prepare("INSERT INTO salidas
                (usuario_id, cliente, subtotal, iva, total, monto_total, estado_cobro, fecha_vencimiento, metodo_cobro, requiere_factura, con_factura, factura_url, tipo_pago, metodo_pago, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            $stmtIns->execute([
                $usuarioId,
                $clienteNombre,
                $subtotal,
                $iva,
                $total,
                $total,
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

            // Insertar en detalle_salidas
            $stmtDet = $pdo->prepare("INSERT INTO detalle_salidas
                (salida_id, producto_id, cantidad, precio_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?)");
            $stmtDet->execute([
                $salidaId,
                $productoId,
                $cantidad,
                $precioVenta,
                $subtotal
            ]);

            // Descontar inventario
            $stmtUpdStk = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?");
            $stmtUpdStk->execute([$cantidad, $productoId]);

            // Registrar en INGRESOS
            if ($estadoCobro === 'cobrado') {
                $conceptoIngreso = "Venta / Salida #" . $salidaId . " - " . $producto['nombre'] . " (" . $clienteNombre . ")" . ($requiereFactura ? " [Facturado 16% IVA]" : "");
                try {
                    $stmtIng = $pdo->prepare("INSERT INTO ingresos (concepto, monto_total, metodo_pago, comprobante_url, fecha_pago) VALUES (?, ?, ?, ?, NOW())");
                    $stmtIng->execute([$conceptoIngreso, $total, $metodoCobro, $facturaUrl]);
                } catch (\PDOException $exIng1) {
                    $stmtIng = $pdo->prepare("INSERT INTO ingresos (concepto, total, metodo_pago, comprobante_url, fecha_pago) VALUES (?, ?, ?, ?, NOW())");
                    $stmtIng->execute([$conceptoIngreso, $total, $metodoCobro, $facturaUrl]);
                }
            }

            // Registrar en CUENTAS_COBRAR
            if ($estadoCobro === 'credito') {
                $conceptoCxC = "Venta #" . $salidaId . ": " . $producto['nombre'] . ($requiereFactura ? " [Facturado 16% IVA]" : "");
                try {
                    $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar (cliente, concepto, monto_total, estatus, comprobante_url, fecha_vencimiento, fecha_registro) VALUES (?, ?, ?, 'pendiente', ?, ?, NOW())");
                    $stmtCxC->execute([$clienteNombre, $conceptoCxC, $total, $facturaUrl, $fechaVencimiento]);
                } catch (\PDOException $exCxC1) {
                    $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar (cliente, concepto, monto, estatus, comprobante_url, fecha_registro) VALUES (?, ?, ?, 'pendiente', ?, NOW())");
                    $stmtCxC->execute([$clienteNombre, $conceptoCxC, $total, $facturaUrl]);
                }
            }

            $pdo->commit();
            $mensajeExito = "Salida / Venta #" . $salidaId . " registrada exitosamente. Total: $" . number_format($total, 2);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al registrar la salida: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Por favor completa los campos requeridos con datos válidos.";
    }
}

// 2. PROCESAR EDICIÓN DE SALIDA / VENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_salida'])) {
    $salidaId         = intval($_POST['salida_id'] ?? 0);
    $clienteNombre    = !empty(trim($_POST['cliente'] ?? '')) ? trim($_POST['cliente']) : 'Público General';
    $estadoCobro      = $_POST['estado_cobro'] ?? 'cobrado';
    $fechaVencimiento = ($estadoCobro === 'credito' && !empty($_POST['fecha_vencimiento'])) ? $_POST['fecha_vencimiento'] : null;
    $metodoCobro      = $_POST['metodo_cobro'] ?? 'efectivo';

    if ($salidaId > 0) {
        try {
            $pdo->beginTransaction();

            $stmtSal = $pdo->prepare("SELECT * FROM salidas WHERE id = ? FOR UPDATE");
            $stmtSal->execute([$salidaId]);
            $salidaActual = $stmtSal->fetch(PDO::FETCH_ASSOC);

            if (!$salidaActual) {
                throw new Exception("La salida especificada no existe.");
            }

            $facturaUrl = $salidaActual['factura_url'];

            // Actualizar comprobante si se subió uno nuevo
            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $_FILES['comprobante']['tmp_name']);
                    finfo_close($finfo);

                    $mimesPermitidos = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/xml', 'application/xml'];

                    if (!in_array($mimeType, $mimesPermitidos)) {
                        throw new Exception("Tipo de archivo no permitido.");
                    }

                    $dirSubida = 'uploads/ventas/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0755, true);
                    }
                    $nombreArchivo = 'salida_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $nuevaRuta = $dirSubida . $nombreArchivo;

                    if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $nuevaRuta)) {
                        if (!empty($facturaUrl) && file_exists($facturaUrl)) {
                            @unlink($facturaUrl);
                        }
                        $facturaUrl = $nuevaRuta;
                    }
                }
            }

            $tipoPago = ($estadoCobro === 'credito') ? 'credito' : 'contado';

            $stmtUpd = $pdo->prepare("UPDATE salidas SET
                cliente = ?,
                estado_cobro = ?,
                fecha_vencimiento = ?,
                metodo_cobro = ?,
                factura_url = ?,
                tipo_pago = ?,
                metodo_pago = ?
                WHERE id = ?");

            $stmtUpd->execute([
                $clienteNombre,
                $estadoCobro,
                $fechaVencimiento,
                $metodoCobro,
                $facturaUrl,
                $tipoPago,
                $metodoCobro,
                $salidaId
            ]);

            // Sincronización contable en caso de cambio de estado de cobro
            $estadoAnterior = $salidaActual['estado_cobro'];
            $montoTotal = floatval($salidaActual['total'] ?? $salidaActual['monto_total'] ?? 0);

            if ($estadoAnterior !== $estadoCobro) {
                // Si pasa de Crédito a Cobrado: Eliminar de CxC y agregar a Ingresos
                if ($estadoAnterior === 'credito' && $estadoCobro === 'cobrado') {
                    $stmtDelCxC = $pdo->prepare("DELETE FROM cuentas_cobrar WHERE concepto LIKE ?");
                    $stmtDelCxC->execute(["Venta #{$salidaId}:%"]);

                    $conceptoIngreso = "Venta / Salida #" . $salidaId . " - (" . $clienteNombre . ") [Cobrado]";
                    try {
                        $stmtIng = $pdo->prepare("INSERT INTO ingresos (concepto, monto_total, metodo_pago, comprobante_url, fecha_pago) VALUES (?, ?, ?, ?, NOW())");
                        $stmtIng->execute([$conceptoIngreso, $montoTotal, $metodoCobro, $facturaUrl]);
                    } catch (\PDOException $ex) {
                        $stmtIng = $pdo->prepare("INSERT INTO ingresos (concepto, total, metodo_pago, comprobante_url, fecha_pago) VALUES (?, ?, ?, ?, NOW())");
                        $stmtIng->execute([$conceptoIngreso, $montoTotal, $metodoCobro, $facturaUrl]);
                    }
                }
                // Si pasa de Cobrado a Crédito: Eliminar de Ingresos y agregar a CxC
                elseif ($estadoAnterior === 'cobrado' && $estadoCobro === 'credito') {
                    $stmtDelIng = $pdo->prepare("DELETE FROM ingresos WHERE concepto LIKE ?");
                    $stmtDelIng->execute(["Venta / Salida #{$salidaId}%"]);

                    $conceptoCxC = "Venta #" . $salidaId . " (" . $clienteNombre . ")";
                    try {
                        $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar (cliente, concepto, monto_total, estatus, comprobante_url, fecha_vencimiento, fecha_registro) VALUES (?, ?, ?, 'pendiente', ?, ?, NOW())");
                        $stmtCxC->execute([$clienteNombre, $conceptoCxC, $montoTotal, $facturaUrl, $fechaVencimiento]);
                    } catch (\PDOException $ex) {
                        $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar (cliente, concepto, monto, estatus, comprobante_url, fecha_registro) VALUES (?, ?, ?, 'pendiente', ?, NOW())");
                        $stmtCxC->execute([$clienteNombre, $conceptoCxC, $montoTotal, $facturaUrl]);
                    }
                }
            }

            $pdo->commit();
            $mensajeExito = "Salida / Venta #" . $salidaId . " actualizada correctamente.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al actualizar la salida: " . $e->getMessage();
        }
    }
}

// 3. ELIMINAR SALIDA Y RESTAURAR STOCK
if (isset($_GET['action']) && $_GET['action'] === 'eliminar') {
    $salidaId = intval($_GET['id'] ?? 0);

    if ($salidaId > 0) {
        try {
            $pdo->beginTransaction();

            $stmtSal = $pdo->prepare("SELECT * FROM salidas WHERE id = ? FOR UPDATE");
            $stmtSal->execute([$salidaId]);
            $salida = $stmtSal->fetch(PDO::FETCH_ASSOC);

            if ($salida) {
                // Obtener detalles para devolver el stock
                $stmtDet = $pdo->prepare("SELECT producto_id, cantidad FROM detalle_salidas WHERE salida_id = ?");
                $stmtDet->execute([$salidaId]);
                $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

                foreach ($detalles as $det) {
                    $stmtRestaurar = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
                    $stmtRestaurar->execute([$det['cantidad'], $det['producto_id']]);
                }

                // Eliminar comprobante físico
                if (!empty($salida['factura_url']) && file_exists($salida['factura_url'])) {
                    @unlink($salida['factura_url']);
                }

                // Limpiar registros contables asociados
                $stmtDelIng = $pdo->prepare("DELETE FROM ingresos WHERE concepto LIKE ?");
                $stmtDelIng->execute(["Venta / Salida #{$salidaId}%"]);

                $stmtDelCxC = $pdo->prepare("DELETE FROM cuentas_cobrar WHERE concepto LIKE ?");
                $stmtDelCxC->execute(["Venta #{$salidaId}:%"]);

                // Eliminar registro de salidas
                $stmtDelDet = $pdo->prepare("DELETE FROM detalle_salidas WHERE salida_id = ?");
                $stmtDelDet->execute([$salidaId]);

                $stmtDelSal = $pdo->prepare("DELETE FROM salidas WHERE id = ?");
                $stmtDelSal->execute([$salidaId]);

                $pdo->commit();
                $mensajeExito = "Salida #" . $salidaId . " eliminada y el stock fue restaurado correctamente.";
            } else {
                throw new Exception("La salida especificada no existe.");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al eliminar la salida: " . $e->getMessage();
        }
    }
}

// 4. CONSULTA DE PRODUCTOS ACTIVOS CON STOCK
$stmtProductos = $pdo->query("SELECT id, nombre, sku, stock_actual, precio_venta FROM productos WHERE estado = 'activo' ORDER BY nombre ASC");
$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

// 5. CONSULTA DE HISTORIAL DE SALIDAS
$stmtSalidas = $pdo->query("
    SELECT s.*, 
           ds.cantidad, 
           ds.precio_unitario, 
           p.nombre AS producto_nombre,
           p.sku AS producto_sku
    FROM salidas s
    LEFT JOIN detalle_salidas ds ON s.id = ds.salida_id
    LEFT JOIN productos p ON ds.producto_id = p.id
    ORDER BY s.fecha DESC
");
$salidas = $stmtSalidas->fetchAll(PDO::FETCH_ASSOC);

// Alert de créditos próximos a vencer o vencidos
$alertasCredito = [];
$hoy = date('Y-m-d');
foreach ($salidas as $s) {
    if ($s['estado_cobro'] === 'credito' && !empty($s['fecha_vencimiento'])) {
        $diasDiferencia = (strtotime($s['fecha_vencimiento']) - strtotime($hoy)) / 86400;
        if ($diasDiferencia < 0) {
            $alertasCredito[] = "La venta #" . $s['id'] . " a " . htmlspecialchars($s['cliente']) . " está VENCIDA desde hace " . abs(floor($diasDiferencia)) . " días.";
        } elseif ($diasDiferencia <= 3) {
            $alertasCredito[] = "La venta #" . $s['id'] . " a " . htmlspecialchars($s['cliente']) . " vence en " . ceil($diasDiferencia) . " día(s).";
        }
    }
}
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-box-arrow-right text-danger me-2"></i>Gestión de Salidas / Ventas</h2>
    </div>

    <?php if (!empty($mensajeExito)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($mensajeExito) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($mensajeError)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($mensajeError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($alertasCredito)): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <h5 class="alert-heading"><i class="bi bi-bell-fill me-2"></i>Alertas de Cuentas por Cobrar</h5>
            <ul class="mb-0">
                <?php foreach ($alertasCredito as $alerta): ?>
                    <li><?= htmlspecialchars($alerta) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- FORMULARIO NUEVA SALIDA -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="card-title mb-0"><i class="bi bi-plus-circle me-2"></i>Registrar Nueva Salida / Venta</h5>
        </div>
        <div class="card-body">
            <form action="salidas.php" method="POST" enctype="multipart/form-data" id="formSalida">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label font-weight-bold">Producto <span class="text-danger">*</span></label>
                        <select name="producto_id" id="producto_id" class="form-select" required>
                            <option value="">-- Seleccionar Producto --</option>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= $p['id'] ?>" 
                                        data-precio="<?= $p['precio_venta'] ?>" 
                                        data-stock="<?= $p['stock_actual'] ?>">
                                    <?= htmlspecialchars($p['nombre']) ?> (SKU: <?= htmlspecialchars($p['sku']) ?>) - Stock: <?= number_format($p['stock_actual'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1">Stock Disponible: <strong id="lblStock">0.00</strong></small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label font-weight-bold">Cliente</label>
                        <input type="text" name="cliente" class="form-control" placeholder="Público General">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label font-weight-bold">Cantidad <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="cantidad" id="cantidad" class="form-control" min="0.01" required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label font-weight-bold">Precio Unitario ($)</label>
                        <input type="number" step="0.01" name="precio_venta" id="precio_venta" class="form-control" min="0" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label font-weight-bold">Estado de Cobro</label>
                        <select name="estado_cobro" id="estado_cobro" class="form-select">
                            <option value="cobrado">Cobrado / Contado</option>
                            <option value="credito">Crédito / Pendiente</option>
                        </select>
                    </div>

                    <div class="col-md-3 d-none" id="group_vencimiento">
                        <label class="form-label font-weight-bold">Fecha de Vencimiento</label>
                        <input type="date" name="fecha_vencimiento" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label font-weight-bold">Método de Cobro</label>
                        <select name="metodo_cobro" class="form-select">
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label font-weight-bold">Comprobante / Remisión</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    </div>

                    <div class="col-md-12">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="requiere_factura" id="requiere_factura" value="1">
                            <label class="form-check-label font-weight-bold" for="requiere_factura">Requiere Factura (Aplica 16% IVA)</label>
                        </div>
                    </div>

                    <!-- Resumen financiero dinámico -->
                    <div class="col-12 mt-3">
                        <div class="p-3 bg-light rounded border d-flex justify-content-around text-center">
                            <div>Subtotal: <br><strong id="lblSubtotal" class="h5">$0.00</strong></div>
                            <div>IVA (16%): <br><strong id="lblIva" class="h5 text-warning">$0.00</strong></div>
                            <div>Total: <br><strong id="lblTotal" class="h4 text-success">$0.00</strong></div>
                        </div>
                    </div>

                    <div class="col-12 text-end mt-3">
                        <button type="submit" name="guardar_salida" class="btn btn-danger px-4">
                            <i class="bi bi-save me-1"></i> Registrar Salida
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLA DE HISTORIAL DE SALIDAS -->
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">
            <h5 class="card-title mb-0"><i class="bi bi-list-task me-2"></i>Historial de Salidas / Ventas</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Subtotal</th>
                            <th>IVA</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Comprobante</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($salidas)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">No hay salidas registradas.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salidas as $s): ?>
                                <tr>
                                    <td><strong>#<?= $s['id'] ?></strong></td>
                                    <td><?= date('d/m/Y H:i', strtotime($s['fecha'])) ?></td>
                                    <td><?= htmlspecialchars($s['cliente']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($s['producto_nombre'] ?? 'N/A') ?>
                                        <br><small class="text-muted">SKU: <?= htmlspecialchars($s['producto_sku'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><?= number_format($s['cantidad'], 2) ?></td>
                                    <td>$<?= number_format($s['subtotal'], 2) ?></td>
                                    <td>$<?= number_format($s['iva'], 2) ?></td>
                                    <td><strong class="text-success">$<?= number_format($s['total'], 2) ?></strong></td>
                                    <td>
                                        <?php if ($s['estado_cobro'] === 'cobrado'): ?>
                                            <span class="badge bg-success">Cobrado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Crédito</span>
                                            <?php if (!empty($s['fecha_vencimiento'])): ?>
                                                <br><small class="text-muted">Vence: <?= date('d/m/Y', strtotime($s['fecha_vencimiento'])) ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['factura_url']) && file_exists($s['factura_url'])): ?>
                                            <a href="<?= htmlspecialchars($s['factura_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-file-earmark-arrow-down"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-warning me-1 btn-editar" 
                                                data-id="<?= $s['id'] ?>"
                                                data-cliente="<?= htmlspecialchars($s['cliente']) ?>"
                                                data-estado="<?= $s['estado_cobro'] ?>"
                                                data-vencimiento="<?= $s['fecha_vencimiento'] ?>"
                                                data-metodo="<?= $s['metodo_cobro'] ?>"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEditarSalida">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="salidas.php?action=eliminar&id=<?= $s['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('¿Estás seguro de eliminar esta salida? Se restaurará el stock de producto.');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL EDICIÓN -->
<div class="modal fade" id="modalEditarSalida" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="salidas.php" method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar Salida / Venta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="salida_id" id="edit_salida_id">

                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Cliente</label>
                        <input type="text" name="cliente" id="edit_cliente" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Estado de Cobro</label>
                        <select name="estado_cobro" id="edit_estado_cobro" class="form-select">
                            <option value="cobrado">Cobrado / Contado</option>
                            <option value="credito">Crédito / Pendiente</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="edit_group_vencimiento">
                        <label class="form-label font-weight-bold">Fecha de Vencimiento</label>
                        <input type="date" name="fecha_vencimiento" id="edit_fecha_vencimiento" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Método de Cobro</label>
                        <select name="metodo_cobro" id="edit_metodo_cobro" class="form-select">
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Actualizar Comprobante (Opcional)</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="actualizar_salida" class="btn btn-warning">Guardar Cambios</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectProducto = document.getElementById('producto_id');
    const inputCantidad  = document.getElementById('cantidad');
    const inputPrecio    = document.getElementById('precio_venta');
    const chkFactura     = document.getElementById('requiere_factura');
    const selectEstado   = document.getElementById('estado_cobro');
    const groupVenc      = document.getElementById('group_vencimiento');
    const formSalida     = document.getElementById('formSalida');

    const lblStock    = document.getElementById('lblStock');
    const lblSubtotal = document.getElementById('lblSubtotal');
    const lblIva      = document.getElementById('lblIva');
    const lblTotal    = document.getElementById('lblTotal');

    // Validación al seleccionar producto
    selectProducto.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            const precio = parseFloat(option.getAttribute('data-precio') || 0);
            const stock = parseFloat(option.getAttribute('data-stock') || 0);
            inputPrecio.value = precio.toFixed(2);
            lblStock.textContent = stock.toFixed(2);
        } else {
            inputPrecio.value = '';
            lblStock.textContent = '0.00';
        }
        calcularTotales();
    });

    // Validación de cantidad contra stock antes de enviar
    if (formSalida) {
        formSalida.addEventListener('submit', function(e) {
            const option = selectProducto.options[selectProducto.selectedIndex];
            const stockDisponible = parseFloat(option.getAttribute('data-stock') || 0);
            const cantidadPedida = parseFloat(inputCantidad.value || 0);

            if (cantidadPedida > stockDisponible) {
                e.preventDefault();
                alert('La cantidad requerida (' + cantidadPedida + ') supera el stock disponible (' + stockDisponible + ').');
            }
        });
    }

    // Cálculo dinámico de importes
    function calcularTotales() {
        const cant = parseFloat(inputCantidad.value) || 0;
        const prec = parseFloat(inputPrecio.value) || 0;
        const subtotal = cant * prec;
        const iva = chkFactura.checked ? (subtotal * 0.16) : 0;
        const total = subtotal + iva;

        lblSubtotal.textContent = '$' + subtotal.toFixed(2);
        lblIva.textContent      = '$' + iva.toFixed(2);
        lblTotal.textContent    = '$' + total.toFixed(2);
    }

    inputCantidad.addEventListener('input', calcularTotales);
    inputPrecio.addEventListener('input', calcularTotales);
    chkFactura.addEventListener('change', calcularTotales);

    // Toggle de la fecha de vencimiento según el tipo de venta
    selectEstado.addEventListener('change', function() {
        if (this.value === 'credito') {
            groupVenc.classList.remove('d-none');
        } else {
            groupVenc.classList.add('d-none');
        }
    });

    // Toggle modal edición
    const editEstado = document.getElementById('edit_estado_cobro');
    const editGroupVenc = document.getElementById('edit_group_vencimiento');

    editEstado.addEventListener('change', function() {
        if (this.value === 'credito') {
            editGroupVenc.classList.remove('d-none');
        } else {
            editGroupVenc.classList.add('d-none');
        }
    });

    // Cargar datos en el Modal
    const btnsEditar = document.querySelectorAll('.btn-editar');
    btnsEditar.forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_salida_id').value = this.getAttribute('data-id');
            document.getElementById('edit_cliente').value = this.getAttribute('data-cliente');
            
            const estado = this.getAttribute('data-estado');
            editEstado.value = estado;

            if (estado === 'credito') {
                editGroupVenc.classList.remove('d-none');
                document.getElementById('edit_fecha_vencimiento').value = this.getAttribute('data-vencimiento');
            } else {
                editGroupVenc.classList.add('d-none');
                document.getElementById('edit_fecha_vencimiento').value = '';
            }

            document.getElementById('edit_metodo_cobro').value = this.getAttribute('data-metodo');
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
