<?php
require_once 'includes/header.php';

$mensajeExito = '';
$mensajeError = '';

// Obtener ID del usuario en sesión
$usuarioId = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 1;

// 1. REGISTRAR NUEVA SALIDA / VENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_salida'])) {
    $productoId      = intval($_POST['producto_id'] ?? 0);
    $clienteNombre   = !empty(trim($_POST['cliente'] ?? '')) ? trim($_POST['cliente']) : 'Público General';
    $cantidad        = floatval($_POST['cantidad'] ?? 0);
    $precioVenta     = floatval($_POST['precio_venta'] ?? 0);
    $estadoCobro     = $_POST['estado_cobro'] ?? 'cobrado'; // cobrado | credito
    $metodoCobro     = $_POST['metodo_cobro'] ?? 'efectivo';
    $requiereFactura = isset($_POST['requiere_factura']) ? 1 : 0;
    $facturaUrl      = null;

    if ($productoId > 0 && $cantidad > 0 && $precioVenta >= 0) {
        try {
            $pdo->beginTransaction();

            // Verificar existencias usando stock_actual
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

            // --- LÓGICA DE CÁLCULO DE IVA Y TOTAL ---
            $subtotal = $cantidad * $precioVenta;
            $iva = $requiereFactura ? ($subtotal * 0.16) : 0.00;
            $total = $subtotal + $iva;

            $tipoPago = ($estadoCobro === 'credito') ? 'credito' : 'contado';

            // Insertar encabezado de Salida
            $stmtIns = $pdo->prepare("INSERT INTO salidas
                (usuario_id, cliente, subtotal, iva, total, monto_total, estado_cobro, metodo_cobro, requiere_factura, con_factura, factura_url, tipo_pago, metodo_pago, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            $stmtIns->execute([
                $usuarioId,
                $clienteNombre,
                $subtotal,
                $iva,
                $total,
                $total,
                $estadoCobro,
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

            // Descontar inventario de la columna stock_actual
            $stmtUpdStk = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?");
            $stmtUpdStk->execute([$cantidad, $productoId]);

            // Registrar en INGRESOS
            if ($estadoCobro === 'cobrado') {
                $conceptoIngreso = "Venta / Salida #" . $salidaId . " - " . $producto['nombre'] . " (" . $clienteNombre . ")" . ($requiereFactura ? " [Facturado 16% IVA]" : "");
                try {
                    $stmtIng = $pdo->prepare("INSERT INTO ingresos (concepto, monto_total, metodo_pago, comprobante_url, fecha_pago) VALUES (?, ?, ?, ?, NOW())");
                    $stmtIng->execute([$conceptoIngreso, $total, $metodoCobro, $facturaUrl]);
                } catch (\PDOException $exIng1) {
                    try {
                        $stmtIng = $pdo->prepare("INSERT INTO ingresos (concepto, total, metodo_pago, comprobante_url, fecha_pago) VALUES (?, ?, ?, ?, NOW())");
                        $stmtIng->execute([$conceptoIngreso, $total, $metodoCobro, $facturaUrl]);
                    } catch (\PDOException $exIng2) {
                        // Salta si hay inconsistencia estructural opcional
                    }
                }
            }

            // Registrar en CUENTAS_COBRAR
            if ($estadoCobro === 'credito') {
                $conceptoCxC = "Venta #" . $salidaId . ": " . $producto['nombre'] . ($requiereFactura ? " [Facturado 16% IVA]" : "");
                try {
                    $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar (cliente, concepto, monto_total, estatus, comprobante_url, fecha_registro) VALUES (?, ?, ?, 'pendiente', ?, NOW())");
                    $stmtCxC->execute([$clienteNombre, $conceptoCxC, $total, $facturaUrl]);
                } catch (\PDOException $exCxC1) {
                    try {
                        $stmtCxC = $pdo->prepare("INSERT INTO cuentas_cobrar (cliente, concepto, monto, estatus, comprobante_url, fecha_registro) VALUES (?, ?, ?, 'pendiente', ?, NOW())");
                        $stmtCxC->execute([$clienteNombre, $conceptoCxC, $total, $facturaUrl]);
                    } catch (\PDOException $exCxC2) {
                        // Salta si hay inconsistencia estructural opcional
                    }
                }
            }

            $pdo->commit();
            $mensajeExito = "Salida / Venta #" . $salidaId . " registrada exitosamente. Total cobrado/registrado: $" . number_format($total, 2);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_editar_salida'])) {
    $salidaId        = intval($_POST['salida_id'] ?? 0);
    $clienteNombre   = !empty(trim($_POST['cliente'] ?? '')) ? trim($_POST['cliente']) : 'Público General';
    $nuevaCantidad   = floatval($_POST['cantidad'] ?? 0);
    $nuevoPrecio     = floatval($_POST['precio_venta'] ?? 0);
    $estadoCobro     = $_POST['estado_cobro'] ?? 'cobrado';
    $metodoCobro     = $_POST['metodo_cobro'] ?? 'efectivo';
    $requiereFactura = isset($_POST['requiere_factura']) ? 1 : 0;
    $fecha           = $_POST['fecha'] ?? '';

    if ($salidaId > 0 && $nuevaCantidad > 0 && $nuevoPrecio >= 0) {
        try {
            $pdo->beginTransaction();

            // Obtener detalle actual
            $stmtDetActual = $pdo->prepare("SELECT producto_id, cantidad FROM detalle_salidas WHERE salida_id = ? FOR UPDATE");
            $stmtDetActual->execute([$salidaId]);
            $detalleActual = $stmtDetActual->fetch(PDO::FETCH_ASSOC);

            if ($detalleActual) {
                $productoId       = intval($detalleActual['producto_id']);
                $cantidadAnterior = floatval($detalleActual['cantidad']);
                $diferenciaCant   = $nuevaCantidad - $cantidadAnterior;

                // Verificar stock si incrementó la cantidad
                if ($diferenciaCant > 0) {
                    $stmtStk = $pdo->prepare("SELECT stock_actual FROM productos WHERE id = ? FOR UPDATE");
                    $stmtStk->execute([$productoId]);
                    $stockDisp = floatval($stmtStk->fetchColumn() ?? 0);

                    if ($stockDisp < $diferenciaCant) {
                        throw new Exception("Stock insuficiente para aumentar la venta. Stock actual disponible: " . number_format($stockDisp, 2));
                    }
                }

                // Actualizar inventario según la diferencia
                $stmtAdjStk = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?");
                $stmtAdjStk->execute([$diferenciaCant, $productoId]);

                // Actualizar detalle_salidas
                $subtotalNuevo = $nuevaCantidad * $nuevoPrecio;
                $stmtUpdDet = $pdo->prepare("UPDATE detalle_salidas SET cantidad = ?, precio_unitario = ?, subtotal = ? WHERE salida_id = ?");
                $stmtUpdDet->execute([$nuevaCantidad, $nuevoPrecio, $subtotalNuevo, $salidaId]);
            } else {
                $subtotalNuevo = $nuevaCantidad * $nuevoPrecio;
            }

            // Calcular totales
            $ivaNuevo   = $requiereFactura ? ($subtotalNuevo * 0.16) : 0.00;
            $totalNuevo = $subtotalNuevo + $ivaNuevo;
            $tipoPago   = ($estadoCobro === 'credito') ? 'credito' : 'contado';

            // Manejo de archivo/comprobante
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

                    // Borrar anterior si existe
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

            // Actualizar encabezado de Salida
            $sqlSalida = "UPDATE salidas SET
                cliente = ?,
                subtotal = ?,
                iva = ?,
                total = ?,
                monto_total = ?,
                estado_cobro = ?,
                metodo_cobro = ?,
                requiere_factura = ?,
                con_factura = ?,
                tipo_pago = ?,
                metodo_pago = ?";

            $paramsSalida = [
                $clienteNombre,
                $subtotalNuevo,
                $ivaNuevo,
                $totalNuevo,
                $totalNuevo,
                $estadoCobro,
                $metodoCobro,
                $requiereFactura,
                $requiereFactura,
                $tipoPago,
                $metodoCobro
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

// 3. ELIMINAR REGISTRO DE SALIDA / VENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_eliminar_salida'])) {
    $salidaId = intval($_POST['salida_id'] ?? 0);

    if ($salidaId > 0) {
        try {
            $pdo->beginTransaction();

            // 1. Reintegrar stock de los productos de la salida
            $stmtDet = $pdo->prepare("SELECT producto_id, cantidad FROM detalle_salidas WHERE salida_id = ?");
            $stmtDet->execute([$salidaId]);
            $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            $stmtRestaurar = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
            foreach ($detalles as $item) {
                $stmtRestaurar->execute([$item['cantidad'], $item['producto_id']]);
            }

            // 2. Eliminar archivo adjunto si existe
            $stmtImg = $pdo->prepare("SELECT factura_url FROM salidas WHERE id = ?");
            $stmtImg->execute([$salidaId]);
            $archivo = $stmtImg->fetchColumn();

            if ($archivo && file_exists(__DIR__ . '/' . $archivo)) {
                @unlink(__DIR__ . '/' . $archivo);
            }

            // 3. Eliminar detalle y registro
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

// Cargar Catálogo de Productos
$productos = [];
try {
    $stmtProd = $pdo->query("SELECT id, nombre, stock_actual, tipo_unidad, precio_venta FROM productos ORDER BY nombre ASC");
    if ($stmtProd) {
        $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\PDOException $e) {
    $mensajeError = "Error al consultar catálogo de productos: " . $e->getMessage();
}

// Cargar Historial
$salidas = [];
try {
    $sqlSalidas = "SELECT s.*, ds.producto_id, ds.cantidad, ds.precio_unitario, p.nombre AS producto_nombre
                   FROM salidas s
                   LEFT JOIN detalle_salidas ds ON s.id = ds.salida_id
                   LEFT JOIN productos p ON ds.producto_id = p.id
                   ORDER BY s.id DESC";
    $stmtSal = $pdo->query($sqlSalidas);
    if ($stmtSal) {
        $salidas = $stmtSal->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\PDOException $e) {
    $mensajeError = "Error al consultar historial de salidas: " . $e->getMessage();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-arrow-up-right text-danger me-2"></i> Salidas / Ventas de Producto</h2>
</div>

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

<!-- FORMULARIO REGISTRAR SALIDA -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-dash-circle me-1"></i> Registrar Nueva Salida
    </div>
    <div class="card-body">
        <form method="POST" action="salidas.php" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="guardar_salida" value="1">

            <div class="col-md-3">
                <label class="form-label fw-bold">Producto (*):</label>
                <select name="producto_id" id="select_producto" class="form-select" required>
                    <option value="" data-precio="" data-stock="0" data-unidad="">-- Seleccionar Producto --</option>
                    <?php if (!empty($productos)): ?>
                        <?php foreach ($productos as $p): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>"
                                    data-precio="<?= htmlspecialchars($p['precio_venta'] ?? 0) ?>"
                                    data-stock="<?= htmlspecialchars($p['stock_actual'] ?? 0) ?>"
                                    data-unidad="<?= htmlspecialchars($p['tipo_unidad'] ?? 'unidades') ?>">
                                <?= htmlspecialchars($p['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No hay productos disponibles</option>
                    <?php endif; ?>
                </select>

                <!-- TARJETA VISUAL DE STOCK Y DETALLES -->
                <div id="card_info_stock" class="card mt-2 d-none border-primary bg-light">
                    <div class="card-body p-2 text-center">
                        <small class="text-muted d-block fw-bold mb-1">DISPONIBILIDAD EN ALMACÉN</small>
                        <span id="badge_stock_status" class="badge bg-success fs-6 mb-1">
                            <i class="bi bi-boxes me-1"></i> <span id="lbl_stock_cant">0</span> <span id="lbl_stock_unidad"></span>
                        </span>
                        <div class="small text-muted">
                            Precio Base: <strong id="lbl_stock_precio" class="text-dark">$0.00</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Cliente:</label>
                <input type="text" name="cliente" class="form-control" placeholder="Público General / Mostrador">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Cantidad (*):</label>
                <input type="number" step="0.01" min="0.01" name="cantidad" id="input_cantidad" class="form-control" placeholder="0.00" required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Precio Venta ($) (*):</label>
                <input type="number" step="0.01" min="0" name="precio_venta" id="input_precio_venta" class="form-control" placeholder="0.00" required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold">Estatus del Cobro (*):</label>
                <select name="estado_cobro" class="form-select" required>
                    <option value="cobrado">Cobrado (Contado)</option>
                    <option value="credito">A Crédito (Manda a CxC)</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Método de Cobro:</label>
                <select name="metodo_cobro" class="form-select">
                    <option value="efectivo">Efectivo</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="tarjeta">Tarjeta</option>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label fw-bold">Comprobante / Ticket (PDF/XML/Imagen):</label>
                <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
            </div>

            <div class="col-md-4 d-flex align-items-center mt-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="requiere_factura" id="requiere_factura" value="1">
                    <label class="form-check-label fw-bold" for="requiere_factura">
                        ¿Requiere Factura (+16% IVA)?
                    </label>
                </div>
            </div>

            <!-- Previsualización de Totales en Tiempo Real -->
            <div class="col-12 bg-light p-3 rounded border">
                <div class="row text-center">
                    <div class="col-md-4">
                        <span class="text-muted d-block">Subtotal:</span>
                        <strong id="lbl_subtotal" class="fs-5">$0.00</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted d-block">IVA (16%):</span>
                        <strong id="lbl_iva" class="fs-5 text-warning">$0.00</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted d-block">Total Final:</span>
                        <strong id="lbl_total" class="fs-4 text-success">$0.00</strong>
                    </div>
                </div>
            </div>

            <div class="col-12 text-end">
                <button type="submit" class="btn btn-danger"><i class="bi bi-box-arrow-up-right me-1"></i> Guardar Salida</button>
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
                        <th class="text-end">Cant.</th>
                        <th class="text-end">Precio U.</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">IVA</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Estatus</th>
                        <th class="text-center">Comprobante</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salidas)): ?>
                        <tr><td colspan="12" class="text-center py-3 text-muted">No hay registros de salidas aún.</td></tr>
                    <?php else: ?>
                        <?php foreach ($salidas as $s): ?>
                            <?php
                                $fecha = $s['fecha'] ?? $s['fecha_salida'] ?? null;
                                $subtotalMostrar = floatval($s['subtotal'] ?? 0);
                                $ivaMostrar = floatval($s['iva'] ?? 0);
                                $totalMostrar = floatval($s['total'] ?? $s['monto_total'] ?? 0);
                                $metodo = $s['metodo_cobro'] ?? $s['metodo_pago'] ?? 'efectivo';
                            ?>
                            <tr>
                                <td>#<?= $s['id'] ?></td>
                                <td><?= $fecha ? date('d/m/Y H:i', strtotime($fecha)) : 'N/A' ?></td>
                                <td><?= htmlspecialchars($s['producto_nombre'] ?? 'Varios / N/A') ?></td>
                                <td><?= htmlspecialchars($s['cliente'] ?? 'Público General') ?></td>
                                <td class="text-end fw-bold"><?= number_format(floatval($s['cantidad'] ?? 0), 2) ?></td>
                                <td class="text-end">$<?= number_format(floatval($s['precio_unitario'] ?? 0), 2) ?></td>
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
                                        <!-- Botón Editar -->
                                        <button type="button" class="btn btn-outline-warning"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarSalida"
                                                data-id="<?= $s['id'] ?>"
                                                data-cliente="<?= htmlspecialchars($s['cliente'] ?? '') ?>"
                                                data-cantidad="<?= $s['cantidad'] ?? 0 ?>"
                                                data-precio="<?= $s['precio_unitario'] ?? 0 ?>"
                                                data-estado="<?= $s['estado_cobro'] ?? 'cobrado' ?>"
                                                data-metodo="<?= $metodo ?>"
                                                data-factura="<?= $s['requiere_factura'] ?? ($s['iva'] > 0 ? 1 : 0) ?>"
                                                data-fecha="<?= $fecha ? date('Y-m-d\TH:i', strtotime($fecha)) : '' ?>"
                                                data-producto="<?= htmlspecialchars($s['producto_nombre'] ?? '') ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- Botón Eliminar -->
                                        <form method="POST" action="salidas.php" class="d-inline" onsubmit="return confirm('¿Confirmas eliminar esta salida #<?= $s['id'] ?>? Las cantidades vendidas regresarán al inventario.');">
                                            <input type="hidden" name="accion_eliminar_salida" value="1">
                                            <input type="hidden" name="salida_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Eliminar Salida">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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

<!-- MODAL PARA EDITAR SALIDA -->
<div class="modal fade" id="modalEditarSalida" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="salidas.php" enctype="multipart/form-data">
                <input type="hidden" name="accion_editar_salida" value="1">
                <input type="hidden" name="salida_id" id="edit_salida_id">

                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Editar Registro de Salida / Venta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Estatus del Cobro (*):</label>
                        <select name="estado_cobro" id="edit_estado_cobro" class="form-select" required>
                            <option value="cobrado">Cobrado (Contado)</option>
                            <option value="credito">A Crédito (Manda a CxC)</option>
                        </select>
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
                        <label class="form-label fw-bold">Adjuntar / Reemplazar Comprobante / Ticket:</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                        <small class="text-muted">Acepta archivos PDF, XML, JPG, PNG o WEBP.</small>
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
    const selectProducto   = document.getElementById('select_producto');
    const inputPrecio      = document.getElementById('input_precio_venta');
    const inputCantidad    = document.getElementById('input_cantidad');
    const chkFactura       = document.getElementById('requiere_factura');

    // Elementos del Card informativo de Stock
    const cardStock        = document.getElementById('card_info_stock');
    const badgeStockStatus = document.getElementById('badge_stock_status');
    const lblStockCant     = document.getElementById('lbl_stock_cant');
    const lblStockUnidad   = document.getElementById('lbl_stock_unidad');
    const lblStockPrecio   = document.getElementById('lbl_stock_precio');

    // Totales de vista previa
    const lblSubtotal      = document.getElementById('lbl_subtotal');
    const lblIva           = document.getElementById('lbl_iva');
    const lblTotal         = document.getElementById('lbl_total');

    function calcularTotales() {
        const cantidad        = parseFloat(inputCantidad.value) || 0;
        const precio          = parseFloat(inputPrecio.value) || 0;
        const requiereFactura = chkFactura.checked;

        const subtotal = cantidad * precio;
        const iva      = requiereFactura ? (subtotal * 0.16) : 0;
        const total    = subtotal + iva;

        lblSubtotal.textContent = '$' + subtotal.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        lblIva.textContent      = '$' + iva.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        lblTotal.textContent    = '$' + total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    if (selectProducto) {
        selectProducto.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            const stock  = parseFloat(selectedOption.getAttribute('data-stock') || 0);
            const unidad = selectedOption.getAttribute('data-unidad') || '';

            if (this.value !== "") {
                // Asignar el precio base
                inputPrecio.value = precio ? parseFloat(precio).toFixed(2) : '0.00';

                // Mostrar tarjeta informativa
                cardStock.classList.remove('d-none');
                lblStockCant.textContent   = stock.toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                lblStockUnidad.textContent = unidad;
                lblStockPrecio.textContent = '$' + (precio ? parseFloat(precio).toFixed(2) : '0.00');

                // Cambiar el color de la etiqueta según la cantidad de stock
                if (stock <= 0) {
                    badgeStockStatus.className = 'badge bg-danger fs-6 mb-1';
                } else if (stock <= 5) {
                    badgeStockStatus.className = 'badge bg-warning text-dark fs-6 mb-1';
                } else {
                    badgeStockStatus.className = 'badge bg-success fs-6 mb-1';
                }
            } else {
                cardStock.classList.add('d-none');
                inputPrecio.value = '';
            }

            calcularTotales();
        });
    }

    if (inputCantidad) inputCantidad.addEventListener('input', calcularTotales);
    if (inputPrecio)   inputPrecio.addEventListener('input', calcularTotales);
    if (chkFactura)    chkFactura.addEventListener('change', calcularTotales);

    // Llenar Modal de Edición de Salidas
    var modalEditar = document.getElementById('modalEditarSalida');
    if (modalEditar) {
        modalEditar.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;

            document.getElementById('edit_salida_id').value = button.getAttribute('data-id');
            document.getElementById('edit_producto_nombre').value = button.getAttribute('data-producto');
            document.getElementById('edit_cliente').value = button.getAttribute('data-cliente');
            document.getElementById('edit_cantidad').value = button.getAttribute('data-cantidad');
            document.getElementById('edit_precio_venta').value = button.getAttribute('data-precio');
            document.getElementById('edit_estado_cobro').value = button.getAttribute('data-estado');
            document.getElementById('edit_metodo_cobro').value = button.getAttribute('data-metodo');
            document.getElementById('edit_fecha').value = button.getAttribute('data-fecha') || '';
            document.getElementById('edit_requiere_factura').checked = (button.getAttribute('data-factura') === '1');
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
