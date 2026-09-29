<?php
require_once 'includes/header.php';

// Inicializar sesión si no está activa para manejo de mensajes PRG
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mensajeExito = $_SESSION['mensajeExito'] ?? '';
$mensajeError = $_SESSION['mensajeError'] ?? '';
unset($_SESSION['mensajeExito'], $_SESSION['mensajeError']);

// Helper para eliminar comprobantes
function borrarComprobanteLocal(?string $path): void {
    if (!empty($path) && file_exists($path) && is_file($path)) {
        @unlink($path);
    }
}

// 1. CREAR NUEVA CUENTA POR PAGAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_cuenta'])) {
    $proveedorId      = !empty($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : null;
    $concepto         = trim($_POST['concepto'] ?? '');
    $monto            = floatval($_POST['monto'] ?? 0);
    $fechaVencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
    $comprobanteUrl   = null;

    if ($monto > 0 && !empty($concepto)) {
        try {
            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas, true)) {
                    $dirSubida = 'uploads/comprobantes/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0755, true);
                    }
                    $comprobanteUrl = $dirSubida . 'cxp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($_FILES['comprobante']['tmp_name'], $comprobanteUrl);
                }
            }

            $stmtIns = $pdo->prepare("INSERT INTO cuentas_pagar
                (proveedor_id, concepto, monto, estatus, comprobante_url, fecha_emision, fecha_vencimiento)
                VALUES (?, ?, ?, 'pendiente', ?, NOW(), ?)");
            $stmtIns->execute([$proveedorId, $concepto, $monto, $comprobanteUrl, $fechaVencimiento]);

            $_SESSION['mensajeExito'] = "Cuenta por pagar registrada exitosamente.";
            header("Location: cuentas_pagar.php");
            exit;
        } catch (\PDOException $e) {
            if (!empty($comprobanteUrl)) { borrarComprobanteLocal($comprobanteUrl); }
            $_SESSION['mensajeError'] = "Error al registrar la cuenta por pagar: " . $e->getMessage();
            header("Location: cuentas_pagar.php");
            exit;
        }
    } else {
        $_SESSION['mensajeError'] = "Por favor, ingresa un concepto válido y un monto mayor a cero.";
        header("Location: cuentas_pagar.php");
        exit;
    }
}

// 2. MODIFICAR / EDITAR CUENTA POR PAGAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_cuenta'])) {
    $cuentaId         = intval($_POST['cuenta_id'] ?? 0);
    $proveedorId      = !empty($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : null;
    $concepto         = trim($_POST['concepto'] ?? '');
    $monto            = floatval($_POST['monto'] ?? 0);
    $fechaVencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;

    if ($cuentaId > 0 && $monto > 0 && !empty($concepto)) {
        try {
            $stmtVerif = $pdo->prepare("SELECT estatus, comprobante_url FROM cuentas_pagar WHERE id = ?");
            $stmtVerif->execute([$cuentaId]);
            $cuentaActual = $stmtVerif->fetch(PDO::FETCH_ASSOC);

            if (!$cuentaActual) {
                throw new Exception("La cuenta seleccionada no existe.");
            }

            if ($cuentaActual['estatus'] === 'pagado') {
                throw new Exception("No se puede editar una cuenta liquidada.");
            }

            $comprobanteUrl = $cuentaActual['comprobante_url'];
            $archivoAnteriorABorrar = null;

            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas, true)) {
                    $dirSubida = 'uploads/comprobantes/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0755, true);
                    }
                    $nuevaUrl = $dirSubida . 'cxp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

                    if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $nuevaUrl)) {
                        $archivoAnteriorABorrar = $comprobanteUrl;
                        $comprobanteUrl = $nuevaUrl;
                    }
                }
            }

            $stmtUpd = $pdo->prepare("UPDATE cuentas_pagar
                                      SET proveedor_id = ?, concepto = ?, monto = ?, comprobante_url = ?, fecha_vencimiento = ?
                                      WHERE id = ?");
            $stmtUpd->execute([$proveedorId, $concepto, $monto, $comprobanteUrl, $fechaVencimiento, $cuentaId]);

            if ($archivoAnteriorABorrar) {
                borrarComprobanteLocal($archivoAnteriorABorrar);
            }

            $_SESSION['mensajeExito'] = "La cuenta por pagar se actualizó correctamente.";
            header("Location: cuentas_pagar.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['mensajeError'] = "Error al actualizar la cuenta: " . $e->getMessage();
            header("Location: cuentas_pagar.php");
            exit;
        }
    } else {
        $_SESSION['mensajeError'] = "Datos inválidos para actualizar la cuenta.";
        header("Location: cuentas_pagar.php");
        exit;
    }
}

// 3. PROCESAR PAGO / LIQUIDACIÓN DE CUENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagar_cuenta'])) {
    $cuentaId   = intval($_POST['cuenta_id'] ?? 0);
    $metodoPago = $_POST['metodo_pago'] ?? 'efectivo';

    if ($cuentaId > 0) {
        try {
            $pdo->beginTransaction();

            $stmtC = $pdo->prepare("SELECT * FROM cuentas_pagar WHERE id = ? FOR UPDATE");
            $stmtC->execute([$cuentaId]);
            $cuenta = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                throw new Exception("La cuenta por pagar no existe.");
            }

            if ($cuenta['estatus'] === 'pagado') {
                throw new Exception("Esta cuenta ya está liquidada.");
            }

            $montoTotal = floatval($cuenta['monto']);

            // Actualizar cuenta como PAGADA
            $stmtUpdC = $pdo->prepare("UPDATE cuentas_pagar SET estatus = 'pagado', metodo_pago = ?, fecha_pago = NOW() WHERE id = ?");
            $stmtUpdC->execute([$metodoPago, $cuentaId]);

            // Si está ligada a entrada de inventario
            if (!empty($cuenta['entrada_id'])) {
                $stmtUpdE = $pdo->prepare("UPDATE entradas_inventario SET estatus_pago = 'pagado', fecha_pago = NOW() WHERE id = ?");
                $stmtUpdE->execute([$cuenta['entrada_id']]);
            }

            // Inserción en Egresos
            $comprobanteUrl  = $cuenta['comprobante_url'] ?? null;
            $tipoComprobante = !empty($comprobanteUrl) ? 'comprobante_cxp' : 'sin_comprobante';

            $stmtEgr = $pdo->prepare("INSERT INTO egresos
                (entrada_id, proveedor_id, concepto, monto, metodo_pago, tipo_comprobante, comprobante_url, fecha_pago)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmtEgr->execute([
                $cuenta['entrada_id'] ?? null,
                $cuenta['proveedor_id'] ?? null,
                "Pago CxP (Liquidación): " . $cuenta['concepto'],
                $montoTotal,
                $metodoPago,
                $tipoComprobante,
                $comprobanteUrl
            ]);

            $pdo->commit();
            $_SESSION['mensajeExito'] = "Cuenta liquidada en su totalidad con éxito.";
            header("Location: cuentas_pagar.php");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['mensajeError'] = "Error al procesar el pago: " . $e->getMessage();
            header("Location: cuentas_pagar.php");
            exit;
        }
    } else {
        $_SESSION['mensajeError'] = "Identificador de cuenta inválido.";
        header("Location: cuentas_pagar.php");
        exit;
    }
}

// Cargar catálogo de proveedores
try {
    $proveedores = $pdo->query("SELECT id, nombre FROM proveedores ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $proveedores = [];
}

// Consulta de Cuentas por Pagar
try {
    $sqlCxP = "SELECT cp.*, pr.nombre AS proveedor_nombre
               FROM cuentas_pagar cp
               LEFT JOIN proveedores pr ON cp.proveedor_id = pr.id
               ORDER BY FIELD(cp.estatus, 'pendiente', 'pagado'), cp.fecha_vencimiento ASC, cp.id DESC";
    $cuentas = $pdo->query($sqlCxP)->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $cuentas = [];
    $mensajeError = "Error al consultar Cuentas por Pagar: " . $e->getMessage();
}

// Métricas generales
$totalPendiente = 0;
$totalLiquidado = 0;
$totalPasivo = 0;
$proveedoresAcreedores = [];
$hoy = new DateTime();

foreach ($cuentas as $c) {
    $montoTotal = floatval($c['monto']);
    $totalPasivo += $montoTotal;

    if ($c['estatus'] !== 'pagado') {
        $totalPendiente += $montoTotal;
        if (!empty($c['proveedor_id'])) {
            $proveedoresAcreedores[$c['proveedor_id']] = true;
        }
    } else {
        $totalLiquidado += $montoTotal;
    }
}
$cantProveedoresAcreedores = count($proveedoresAcreedores);
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">

        <h3 class="mb-4 text-dark fw-bold">
            <i class="bi bi-wallet2 text-danger me-2"></i> Cuentas por Pagar
        </h3>

        <?php if ($mensajeExito): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- TARJETAS SUPERIORES -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="p-3 bg-danger text-white rounded-3 shadow-sm">
                    <small class="text-uppercase fw-bold opacity-75">SALDO PENDIENTE REAL</small>
                    <h3 class="fw-bold mb-0 mt-1">$<?= number_format($totalPendiente, 2) ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-success text-white rounded-3 shadow-sm">
                    <small class="text-uppercase fw-bold opacity-75">TOTAL LIQUIDADO</small>
                    <h3 class="fw-bold mb-0 mt-1">$<?= number_format($totalLiquidado, 2) ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 text-white rounded-3 shadow-sm" style="background-color: #435ebe;">
                    <small class="text-uppercase fw-bold opacity-75">TOTAL PASIVO EMITIDO</small>
                    <h3 class="fw-bold mb-0 mt-1">$<?= number_format($totalPasivo, 2) ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 bg-dark text-white rounded-3 shadow-sm">
                    <small class="text-uppercase fw-bold opacity-75">PROVEEDORES ACREEDORES</small>
                    <h3 class="fw-bold mb-0 mt-1"><?= $cantProveedoresAcreedores ?></h3>
                </div>
            </div>
        </div>

        <!-- FORMULARIO NUEVA CUENTA -->
        <div class="card border mb-4">
            <div class="card-header bg-light fw-bold">
                <i class="bi bi-plus-circle me-1"></i> Registrar Nueva Cuenta por Pagar
            </div>
            <div class="card-body">
                <form method="POST" action="cuentas_pagar.php" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="crear_cuenta" value="1">

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Proveedor:</label>
                        <select name="proveedor_id" class="form-select">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Concepto / Descripción:</label>
                        <input type="text" name="concepto" class="form-control" placeholder="Ej. Factura A-123" required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Monto Total ($):</label>
                        <input type="number" step="0.01" min="0.01" name="monto" class="form-control" placeholder="0.00" required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Vencimiento:</label>
                        <input type="date" name="fecha_vencimiento" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Comprobante:</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    </div>

                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar Cuenta</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- FILTROS DINÁMICOS -->
        <div class="row mb-3">
            <div class="col-md-6">
                <input type="text" id="filtroTabla" class="form-control" placeholder="🔍 Buscar por proveedor, concepto o entrada...">
            </div>
            <div class="col-md-3">
                <select id="filtroEstatus" class="form-select">
                    <option value="">-- Todos los estatus --</option>
                    <option value="pendiente">Pendientes</option>
                    <option value="pagado">Liquidados</option>
                </select>
            </div>
        </div>

        <!-- TABLA PRINCIPAL -->
        <div class="card border shadow-sm">
            <div class="card-header bg-dark text-white fw-bold d-flex align-items-center">
                <i class="bi bi-list-task me-2"></i> Control y Semaforización de Deudas
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaCuentas">
                        <thead class="table-dark">
                            <tr>
                                <th>Emisión / Vencimiento</th>
                                <th>Proveedor</th>
                                <th>Concepto</th>
                                <th>Monto Total</th>
                                <th>Saldo Pendiente</th>
                                <th class="text-center">Comprobante</th>
                                <th class="text-center">Estatus</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cuentas)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">Sin cuentas registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cuentas as $c):
                                    $monto = floatval($c['monto']);
                                    $saldo = ($c['estatus'] === 'pagado') ? 0.00 : $monto;

                                    // Semaforización de Vencimiento
                                    $alertaClase = '';
                                    $diasTexto = '';
                                    if ($c['estatus'] !== 'pagado' && !empty($c['fecha_vencimiento'])) {
                                        $fVenc = new DateTime($c['fecha_vencimiento']);
                                        $diff = $hoy->diff($fVenc);
                                        $dias = (int)$diff->format("%r%a");

                                        if ($dias < 0) {
                                            $alertaClase = 'table-danger';
                                            $diasTexto = '<span class="badge bg-danger">Vencido (' . abs($dias) . 'd)</span>';
                                        } elseif ($dias <= 3) {
                                            $alertaClase = 'table-warning';
                                            $diasTexto = '<span class="badge bg-warning text-dark">Vence en ' . $dias . 'd</span>';
                                        } else {
                                            $diasTexto = '<small class="text-muted">(' . $dias . ' días restantes)</small>';
                                        }
                                    }
                                ?>
                                    <tr class="<?= $alertaClase ?>" data-estatus="<?= $c['estatus'] ?>">
                                        <td>
                                            <div><?= !empty($c['fecha_emision']) ? date('d/m/Y', strtotime($c['fecha_emision'])) : 'N/A' ?></div>
                                            <?php if (!empty($c['fecha_vencimiento'])): ?>
                                                <small class="d-block fw-bold text-secondary">Vence: <?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></small>
                                                <?= $diasTexto ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold">
                                            <?= htmlspecialchars($c['proveedor_nombre'] ?? 'Sin Proveedor', ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($c['entrada_id'])): ?>
                                                <small class="d-block text-muted">Entrada #<?= intval($c['entrada_id']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($c['concepto'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>$<?= number_format($monto, 2) ?></td>
                                        <td class="fw-bold <?= $saldo > 0 ? 'text-danger' : 'text-success' ?>">
                                            $<?= number_format($saldo, 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($c['comprobante_url'])): ?>
                                                <a href="<?= htmlspecialchars($c['comprobante_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($c['estatus'] === 'pagado'): ?>
                                                <span class="badge bg-success">Liquidado</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($c['estatus'] !== 'pagado'): ?>
                                                <button class="btn btn-sm btn-outline-warning me-1" data-bs-toggle="modal" data-bs-target="#modalEditar<?= $c['id'] ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalPagar<?= $c['id'] ?>">
                                                    <i class="bi bi-cash-stack me-1"></i> Pagar
                                                </button>

                                                <!-- MODAL EDITAR -->
                                                <div class="modal fade" id="modalEditar<?= $c['id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content text-start">
                                                            <form method="POST" action="cuentas_pagar.php" enctype="multipart/form-data">
                                                                <input type="hidden" name="editar_cuenta" value="1">
                                                                <input type="hidden" name="cuenta_id" value="<?= $c['id'] ?>">

                                                                <div class="modal-header bg-warning text-dark">
                                                                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i> Editar Cuenta</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">Proveedor:</label>
                                                                        <select name="proveedor_id" class="form-select">
                                                                            <option value="">-- Seleccionar --</option>
                                                                            <?php foreach ($proveedores as $prov): ?>
                                                                                <option value="<?= $prov['id'] ?>" <?= ($prov['id'] == $c['proveedor_id']) ? 'selected' : '' ?>>
                                                                                    <?= htmlspecialchars($prov['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">Concepto:</label>
                                                                        <input type="text" name="concepto" class="form-control" value="<?= htmlspecialchars($c['concepto'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">Monto Total ($):</label>
                                                                        <input type="number" step="0.01" min="0.01" name="monto" class="form-control" value="<?= htmlspecialchars($monto, ENT_QUOTES, 'UTF-8') ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">Fecha Vencimiento:</label>
                                                                        <input type="date" name="fecha_vencimiento" class="form-control" value="<?= htmlspecialchars($c['fecha_vencimiento'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">Comprobante:</label>
                                                                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                    <button type="submit" class="btn btn-warning">Guardar Cambios</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- MODAL PAGAR -->
                                                <div class="modal fade" id="modalPagar<?= $c['id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content text-start">
                                                            <form method="POST" action="cuentas_pagar.php">
                                                                <input type="hidden" name="pagar_cuenta" value="1">
                                                                <input type="hidden" name="cuenta_id" value="<?= $c['id'] ?>">

                                                                <div class="modal-header bg-success text-white">
                                                                    <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i> Registrar Pago de Cuenta</h5>
                                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <p class="mb-1"><strong>Concepto:</strong> <?= htmlspecialchars($c['concepto'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                                                    <p class="mb-3"><strong>Monto a Pagar:</strong> <span class="badge bg-danger fs-6">$<?= number_format($monto, 2) ?></span></p>

                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-bold">Método de Pago:</label>
                                                                        <select name="metodo_pago" class="form-select" required>
                                                                            <option value="efectivo">Efectivo</option>
                                                                            <option value="transferencia">Transferencia Bancaria</option>
                                                                            <option value="tarjeta">Tarjeta Débito/Crédito</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Confirmar Pago</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">Sin acciones</span>
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

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputTexto = document.getElementById('filtroTabla');
    const selectEstatus = document.getElementById('filtroEstatus');
    const filas = document.querySelectorAll('#tablaCuentas tbody tr');

    function filtrar() {
        const texto = inputTexto ? inputTexto.value.toLowerCase() : '';
        const estatus = selectEstatus ? selectEstatus.value : '';

        filas.forEach(fila => {
            const contenido = fila.innerText.toLowerCase();
            const estatusFila = fila.getAttribute('data-estatus');

            const coincideTexto = contenido.includes(texto);
            const coincideEstatus = estatus === '' || estatusFila === estatus;

            if (coincideTexto && coincideEstatus) {
                fila.style.display = '';
            } else {
                fila.style.display = 'none';
            }
        });
    }

    if (inputTexto) inputTexto.addEventListener('keyup', filtrar);
    if (selectEstatus) selectEstatus.addEventListener('change', filtrar);
});
</script>

<?php require_once 'includes/footer.php'; ?>
