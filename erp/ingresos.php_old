<?php
require_once 'includes/header.php';

$mensajeExito = '';
$mensajeError = '';

$dirComprobantes = __DIR__ . '/uploads/comprobantes_ingresos/';

// --- PROCESAR ELIMINACIÓN DE REGISTRO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_eliminar'])) {
    $idEliminar = intval($_POST['registro_id'] ?? 0);
    $origen     = $_POST['origen'] ?? '';

    if ($idEliminar > 0) {
        try {
            $pdo->beginTransaction();

            if ($origen === 'ingreso') {
                // Borrar archivo adjunto si existe
                $stmtImg = $pdo->prepare("SELECT comprobante_url FROM ingresos WHERE id = ?");
                $stmtImg->execute([$idEliminar]);
                $archivo = $stmtImg->fetchColumn();

                if ($archivo && file_exists(__DIR__ . '/' . $archivo)) {
                    @unlink(__DIR__ . '/' . $archivo);
                }

                $stmtDel = $pdo->prepare("DELETE FROM ingresos WHERE id = ?");
                $stmtDel->execute([$idEliminar]);

            } else if ($origen === 'salida') {
                // 1. Reintegrar el inventario de las mercancías vendidas en esta salida
                $stmtDet = $pdo->prepare("SELECT producto_id, cantidad FROM detalle_salidas WHERE salida_id = ?");
                $stmtDet->execute([$idEliminar]);
                $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

                $stmtRestaurar = $pdo->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
                foreach ($detalles as $item) {
                    $stmtRestaurar->execute([$item['cantidad'], $item['producto_id']]);
                }

                // 2. Eliminar archivo adjunto de la venta si existe
                $stmtImg = $pdo->prepare("SELECT factura_url FROM salidas WHERE id = ?");
                $stmtImg->execute([$idEliminar]);
                $archivo = $stmtImg->fetchColumn();

                if ($archivo && file_exists(__DIR__ . '/' . $archivo)) {
                    @unlink(__DIR__ . '/' . $archivo);
                }

                // 3. Eliminar detalle y registro de salida
                $stmtDelDet = $pdo->prepare("DELETE FROM detalle_salidas WHERE salida_id = ?");
                $stmtDelDet->execute([$idEliminar]);

                $stmtDelSal = $pdo->prepare("DELETE FROM salidas WHERE id = ?");
                $stmtDelSal->execute([$idEliminar]);
            }

            $pdo->commit();
            $mensajeExito = "El registro #{$idEliminar} ({$origen}) fue eliminado correctamente y el stock fue ajustado.";
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al eliminar el registro: " . $e->getMessage();
        }
    }
}

// --- PROCESAR EDICIÓN Y CARGA DE BÁUCHER / COMPROBANTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_editar'])) {
    $idEditar    = intval($_POST['registro_id'] ?? 0);
    $origen      = $_POST['origen'] ?? '';
    $concepto    = trim($_POST['concepto'] ?? '');
    $metodoPago  = $_POST['metodo_pago'] ?? 'efectivo';
    $montoTotal  = floatval($_POST['monto_total'] ?? 0);
    $fecha       = $_POST['fecha'] ?? '';
    $requiereIva = isset($_POST['calcular_iva']) ? 1 : 0;

    if ($idEditar > 0 && !empty($concepto) && $montoTotal >= 0) {
        try {
            $subtotal = $requiereIva ? ($montoTotal / 1.16) : $montoTotal;
            $iva      = $requiereIva ? ($montoTotal - $subtotal) : 0.00;

            // Procesar subida de báucher / factura / evidencia
            $comprobanteUrl = null;
            if (!empty($_FILES['comprobante']['name']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['comprobante'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $extsPermitidas = ['pdf', 'xml', 'jpg', 'jpeg', 'png', 'webp'];

                if (in_array($ext, $extsPermitidas)) {
                    if (!is_dir($dirComprobantes)) {
                        mkdir($dirComprobantes, 0755, true);
                    }
                    $nombreArchivo = 'ingreso_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $destino = $dirComprobantes . $nombreArchivo;

                    if (move_uploaded_file($file['tmp_name'], $destino)) {
                        $comprobanteUrl = 'uploads/comprobantes_ingresos/' . $nombreArchivo;
                    }
                }
            }

            $pdo->beginTransaction();

            if ($origen === 'ingreso') {
                $sqlUpd = "UPDATE ingresos SET
                            concepto = ?,
                            metodo_pago = ?,
                            monto_subtotal = ?,
                            monto_iva = ?,
                            monto_total = ?";
                $params = [$concepto, $metodoPago, $subtotal, $iva, $montoTotal];

                if (!empty($fecha)) {
                    $sqlUpd .= ", fecha_ingreso = ?";
                    $params[] = $fecha;
                }

                if ($comprobanteUrl) {
                    $sqlUpd .= ", comprobante_url = ?";
                    $params[] = $comprobanteUrl;
                }

                $sqlUpd .= " WHERE id = ?";
                $params[] = $idEditar;

                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute($params);

            } else if ($origen === 'salida') {
                $clienteLimpio = preg_replace('/^Venta #\d+ - /', '', $concepto);

                $sqlUpd = "UPDATE salidas SET
                            cliente = ?,
                            metodo_cobro = ?,
                            metodo_pago = ?,
                            subtotal = ?,
                            iva = ?,
                            total = ?,
                            monto_total = ?";
                $params = [$clienteLimpio, $metodoPago, $metodoPago, $subtotal, $iva, $montoTotal, $montoTotal];

                if (!empty($fecha)) {
                    $sqlUpd .= ", fecha = ?";
                    $params[] = $fecha;
                }

                if ($comprobanteUrl) {
                    $sqlUpd .= ", factura_url = ?";
                    $params[] = $comprobanteUrl;
                }

                $sqlUpd .= " WHERE id = ?";
                $params[] = $idEditar;

                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute($params);
            }

            $pdo->commit();
            $mensajeExito = "El registro #{$idEditar} ({$origen}) fue actualizado con éxito.";
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensajeError = "Error al actualizar el registro: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Por favor completa los campos requeridos con valores válidos.";
    }
}

// --- CONSULTA UNIFICADA ---
$sqlUnificado = "
    SELECT
        'ingreso' AS origen,
        id,
        fecha_ingreso AS fecha,
        concepto,
        COALESCE(metodo_pago, 'efectivo') AS metodo_pago,
        COALESCE(monto_subtotal, monto_total, 0.00) AS subtotal,
        COALESCE(monto_iva, 0.00) AS iva,
        COALESCE(monto_total, 0.00) AS total,
        comprobante_url
    FROM ingresos

    UNION ALL

    SELECT
        'salida' AS origen,
        s.id,
        s.fecha AS fecha,
        CONCAT('Venta #', s.id, ' - ', COALESCE(s.cliente, 'Público General')) AS concepto,
        COALESCE(s.metodo_cobro, s.metodo_pago, 'efectivo') AS metodo_pago,
        COALESCE(
            s.subtotal,
            IF(COALESCE(s.iva, 0) > 0, COALESCE(s.monto_total, s.total, 0) / 1.16, COALESCE(s.monto_total, s.total, 0)),
            0.00
        ) AS subtotal,
        COALESCE(s.iva, 0.00) AS iva,
        COALESCE(s.monto_total, s.total, 0.00) AS total,
        s.factura_url AS comprobante_url
    FROM salidas s
    WHERE s.estado_cobro = 'cobrado' OR s.tipo_pago = 'contado'
";

// Cargar Historial Unificado
try {
    $sqlFinal = "SELECT * FROM ({$sqlUnificado}) AS reporte_ingresos ORDER BY fecha DESC LIMIT 100";
    $ingresos = $pdo->query($sqlFinal)->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $ingresos = [];
    $mensajeError = "Error al consultar los ingresos: " . $e->getMessage();
}

// Calcular Totales por Método de Pago
try {
    $sqlTotales = "
        SELECT metodo_pago, SUM(total) AS total_metodo
        FROM ({$sqlUnificado}) AS reporte_totales
        GROUP BY metodo_pago
    ";
    $totalesMetodo = $pdo->query($sqlTotales)->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\PDOException $e) {
    $totalesMetodo = [];
}

$totalEfectivo = $totalesMetodo['efectivo'] ?? 0.00;
$totalTransfer = $totalesMetodo['transferencia'] ?? 0.00;
$totalTarjeta  = $totalesMetodo['tarjeta'] ?? 0.00;
$totalIngresos = array_sum($totalesMetodo);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-coin text-success me-2"></i> Reporte de Ingresos y Cobros</h2>
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

<!-- TARJETAS DE RESUMEN DE INGRESOS -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-success text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-uppercase mb-1">Total Ingresos</h6>
                <h3 class="fw-bold mb-0">$<?= number_format($totalIngresos, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-uppercase mb-1">Efectivo</h6>
                <h3 class="fw-bold mb-0">$<?= number_format($totalEfectivo, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-primary text-white shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-uppercase mb-1">Transferencias (SPEI)</h6>
                <h3 class="fw-bold mb-0">$<?= number_format($totalTransfer, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-dark shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-uppercase mb-1">Tarjetas</h6>
                <h3 class="fw-bold mb-0">$<?= number_format($totalTarjeta, 2) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- TABLA DE DETALLE DE INGRESOS -->
<div class="card shadow-sm">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-journal-text me-1"></i> Entradas de Dinero Registradas
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Fecha</th>
                        <th>Concepto / Cliente</th>
                        <th class="text-center">Vía de Cobro</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">IVA</th>
                        <th class="text-end">Total Cobrado</th>
                        <th class="text-center">Comprobante</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ingresos)): ?>
                        <tr><td colspan="8" class="text-center py-3 text-muted">No se han registrado ingresos aún.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ingresos as $ing): ?>
                            <tr>
                                <td><?= !empty($ing['fecha']) ? date('d/m/Y H:i', strtotime($ing['fecha'])) : 'N/A' ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($ing['concepto'] ?? 'Ingreso por Venta') ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= ucfirst($ing['metodo_pago'] ?? 'Efectivo') ?></span>
                                </td>
                                <td class="text-end">$<?= number_format(floatval($ing['subtotal'] ?? 0), 2) ?></td>
                                <td class="text-end">$<?= number_format(floatval($ing['iva'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold text-success">$<?= number_format(floatval($ing['total'] ?? 0), 2) ?></td>
                                <td class="text-center">
                                    <?php if (!empty($ing['comprobante_url']) && file_exists(__DIR__ . '/' . $ing['comprobante_url'])): ?>
                                        <a href="<?= htmlspecialchars($ing['comprobante_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-file-earmark-pdf"></i> Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Sin adjunto</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Botón Editar -->
                                        <button type="button" class="btn btn-outline-warning"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarRegistro"
                                                data-id="<?= $ing['id'] ?>"
                                                data-origen="<?= $ing['origen'] ?>"
                                                data-concepto="<?= htmlspecialchars($ing['concepto']) ?>"
                                                data-metodo="<?= $ing['metodo_pago'] ?>"
                                                data-total="<?= $ing['total'] ?>"
                                                data-fecha="<?= !empty($ing['fecha']) ? date('Y-m-d\TH:i', strtotime($ing['fecha'])) : '' ?>"
                                                data-iva="<?= $ing['iva'] > 0 ? 1 : 0 ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <!-- Botón Eliminar -->
                                        <form method="POST" action="ingresos.php" class="d-inline" onsubmit="return confirm('¿Confirmas eliminar este registro de ingreso? Si es una venta, la mercancía regresará al inventario.');">
                                            <input type="hidden" name="accion_eliminar" value="1">
                                            <input type="hidden" name="registro_id" value="<?= $ing['id'] ?>">
                                            <input type="hidden" name="origen" value="<?= $ing['origen'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Eliminar">
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

<!-- MODAL PARA EDITAR REGISTRO -->
<div class="modal fade" id="modalEditarRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="ingresos.php" enctype="multipart/form-data">
                <input type="hidden" name="accion_editar" value="1">
                <input type="hidden" name="registro_id" id="edit_registro_id">
                <input type="hidden" name="origen" id="edit_origen">

                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Editar Registro de Ingreso / Báucher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Concepto / Cliente (*):</label>
                        <input type="text" name="concepto" id="edit_concepto" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Vía / Método de Cobro (*):</label>
                        <select name="metodo_pago" id="edit_metodo_pago" class="form-select" required>
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Monto Total ($) (*):</label>
                        <input type="number" step="0.01" min="0" name="monto_total" id="edit_monto_total" class="form-control" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Fecha del Movimiento:</label>
                        <input type="datetime-local" name="fecha" id="edit_fecha" class="form-control">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Adjuntar / Reemplazar Báucher o Factura:</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.xml,.jpg,.jpeg,.png,.webp">
                        <small class="text-muted">Acepta archivos PDF, XML, JPG, PNG o WEBP.</small>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="calcular_iva" id="edit_calcular_iva" value="1">
                            <label class="form-check-label fw-bold" for="edit_calcular_iva">
                                Desglosar IVA (16%) del Total
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
document.addEventListener('DOMContentLoaded', function () {
    var modalEditar = document.getElementById('modalEditarRegistro');
    if (modalEditar) {
        modalEditar.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;

            document.getElementById('edit_registro_id').value = button.getAttribute('data-id');
            document.getElementById('edit_origen').value = button.getAttribute('data-origen');
            document.getElementById('edit_concepto').value = button.getAttribute('data-concepto');
            document.getElementById('edit_metodo_pago').value = button.getAttribute('data-metodo');
            document.getElementById('edit_monto_total').value = button.getAttribute('data-total');
            document.getElementById('edit_fecha').value = button.getAttribute('data-fecha') || '';
            document.getElementById('edit_calcular_iva').checked = button.getAttribute('data-iva') === '1';
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
