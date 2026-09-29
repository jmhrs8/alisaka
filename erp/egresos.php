<?php
require_once 'includes/header.php';

$mensajeExito = '';
$mensajeError = '';

// Helper para limpiar comprobantes
function borrarComprobanteLocal(?string $path): void {
    if (!empty($path) && file_exists($path)) {
        @unlink($path);
    }
}

// 1. REGISTRAR EGRESO DIRECTO / MANUAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_egreso'])) {
    $proveedorId = !empty($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : null;
    $concepto    = trim($_POST['concepto'] ?? '');
    $monto       = floatval($_POST['monto'] ?? 0);
    $metodoPago  = $_POST['metodo_pago'] ?? 'efectivo';
    $comprobanteUrl = null;

    if ($monto > 0 && !empty($concepto)) {
        try {
            if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
                $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xml'];

                if (in_array($ext, $permitidas)) {
                    $dirSubida = 'uploads/comprobantes_egresos/';
                    if (!is_dir($dirSubida)) {
                        mkdir($dirSubida, 0777, true);
                    }
                    $comprobanteUrl = $dirSubida . 'egr_' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['comprobante']['tmp_name'], $comprobanteUrl);
                }
            }

            $tipoComprobante = !empty($comprobanteUrl) ? 'comprobante_directo' : 'sin_comprobante';

            $stmtIns = $pdo->prepare("INSERT INTO egresos 
                (proveedor_id, concepto, monto, metodo_pago, tipo_comprobante, comprobante_url, fecha_pago)
                VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmtIns->execute([$proveedorId, $concepto, $monto, $metodoPago, $tipoComprobante, $comprobanteUrl]);

            $mensajeExito = "Egreso registrado correctamente.";
        } catch (\PDOException $e) {
            $mensajeError = "Error al registrar el egreso: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Por favor ingresa un concepto y un monto mayor a cero.";
    }
}

// Cargar Proveedores
try {
    $proveedores = $pdo->query("SELECT id, nombre FROM proveedores ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $proveedores = [];
}

// CONSULTA OPTIMIZADA: Evita duplicaciones visuales y rescata la relación con Proveedores y Entradas
try {
    $sqlEgresos = "SELECT eg.*, 
                          pr.nombre AS proveedor_nombre,
                          ei.id AS entrada_folio
                   FROM egresos eg
                   LEFT JOIN proveedores pr ON eg.proveedor_id = pr.id
                   LEFT JOIN entradas_inventario ei ON eg.entrada_id = ei.id
                   ORDER BY eg.fecha_pago DESC, eg.id DESC";
    $egresos = $pdo->query($sqlEgresos)->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $egresos = [];
    $mensajeError = "Error al consultar los egresos: " . $e->getMessage();
}

// Métricas generales
$totalEgresos = 0;
$egresosHoy = 0;
$hoy = date('Y-m-d');

foreach ($egresos as $e) {
    $monto = floatval($e['monto']);
    $totalEgresos += $monto;

    if (!empty($e['fecha_pago']) && date('Y-m-d', strtotime($e['fecha_pago'])) === $hoy) {
        $egresosHoy += $monto;
    }
}
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">

        <h3 class="mb-4 text-dark fw-bold">
            <i class="bi bi-arrow-down-circle-fill text-danger me-2"></i> Módulo de Egresos y Salidas de Caja
        </h3>

        <?php if ($mensajeExito): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($mensajeExito) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($mensajeError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- TARJETAS METRICAS -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="p-3 bg-danger text-white rounded-3 shadow-sm">
                    <small class="text-uppercase fw-bold opacity-75">EGRESOS DE HOY</small>
                    <h3 class="fw-bold mb-0 mt-1">$<?= number_format($egresosHoy, 2) ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 text-white rounded-3 shadow-sm" style="background-color: #435ebe;">
                    <small class="text-uppercase fw-bold opacity-75">TOTAL ACUMULADO EGRESADO</small>
                    <h3 class="fw-bold mb-0 mt-1">$<?= number_format($totalEgresos, 2) ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-dark text-white rounded-3 shadow-sm">
                    <small class="text-uppercase fw-bold opacity-75">TOTAL TRANSACCIONES</small>
                    <h3 class="fw-bold mb-0 mt-1"><?= count($egresos) ?></h3>
                </div>
            </div>
        </div>

        <!-- FORMULARIO NUEVO EGRESO DIRECTO -->
        <div class="card border mb-4">
            <div class="card-header bg-light fw-bold">
                <i class="bi bi-plus-circle me-1"></i> Registrar Egreso Directo / Gastos Varios
            </div>
            <div class="card-body">
                <form method="POST" action="egresos.php" enctype="multipart/form-data" class="row g-3" id="formEgreso">
                    <input type="hidden" name="crear_egreso" value="1">

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Proveedor (Opcional):</label>
                        <select name="proveedor_id" class="form-select">
                            <option value="">-- Sin Proveedor / Varios --</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= $prov['id'] ?>"><?= htmlspecialchars($prov['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold">Concepto:</label>
                        <input type="text" name="concepto" class="form-control" placeholder="Ej. Pago de Servicios, Papelería..." required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Monto ($):</label>
                        <input type="number" step="0.01" min="0.01" name="monto" class="form-control" placeholder="0.00" required>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Método de Pago:</label>
                        <select name="metodo_pago" class="form-select" required>
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">Comprobante:</label>
                        <input type="file" name="comprobante" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml">
                    </div>

                    <div class="col-12 text-end">
                        <button type="submit" id="btnSubmitEgreso" class="btn btn-danger">
                            <i class="bi bi-dash-circle me-1"></i> Registrar Salida
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- FILTROS EN VIVO -->
        <div class="row mb-3">
            <div class="col-md-8">
                <input type="text" id="filtroTexto" class="form-control" placeholder="🔍 Buscar por concepto, proveedor o folio de entrada...">
            </div>
            <div class="col-md-4">
                <select id="filtroMetodo" class="form-select">
                    <option value="">-- Todos los métodos de pago --</option>
                    <option value="efectivo">Efectivo</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="tarjeta">Tarjeta</option>
                </select>
            </div>
        </div>

        <!-- TABLA DE HISTORIAL DE EGRESOS -->
        <div class="card border shadow-sm">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-receipt me-2"></i> Historial de Salidas y Pagos Registrados
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaEgresos">
                        <thead class="table-dark">
                            <tr>
                                <th># ID</th>
                                <th>Fecha y Hora</th>
                                <th>Proveedor / Origen</th>
                                <th>Concepto</th>
                                <th>Método Pago</th>
                                <th>Monto Salida</th>
                                <th class="text-center">Comprobante</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($egresos)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No hay salidas ni egresos registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($egresos as $e): ?>
                                    <tr data-metodo="<?= strtolower($e['metodo_pago']) ?>">
                                        <td class="fw-bold">#<?= $e['id'] ?></td>
                                        <td><?= !empty($e['fecha_pago']) ? date('d/m/Y H:i', strtotime($e['fecha_pago'])) : 'N/A' ?></td>
                                        <td>
                                            <span class="fw-semibold"><?= htmlspecialchars($e['proveedor_nombre'] ?? 'Gasto Directo') ?></span>
                                            <?php if (!empty($e['entrada_folio'])): ?>
                                                <small class="d-block text-muted">Origen: Entrada #<?= $e['entrada_folio'] ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($e['concepto']) ?></td>
                                        <td>
                                            <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($e['metodo_pago']) ?></span>
                                        </td>
                                        <td class="fw-bold text-danger">
                                            -$<?= number_format(floatval($e['monto']), 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($e['comprobante_url'])): ?>
                                                <a href="<?= htmlspecialchars($e['comprobante_url']) ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
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
    // 1. Prevención de doble Submit
    const formEgreso = document.getElementById('formEgreso');
    const btnSubmit = document.getElementById('btnSubmitEgreso');

    if (formEgreso) {
        formEgreso.addEventListener('submit', function() {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Registrando...';
        });
    }

    // 2. Filtros de búsqueda instantánea
    const inputTexto = document.getElementById('filtroTexto');
    const selectMetodo = document.getElementById('filtroMetodo');
    const filas = document.querySelectorAll('#tablaEgresos tbody tr');

    function filtrar() {
        const texto = inputTexto.value.toLowerCase();
        const metodo = selectMetodo.value.toLowerCase();

        filas.forEach(fila => {
            const contenido = fila.innerText.toLowerCase();
            const metodoFila = fila.getAttribute('data-metodo') || '';

            const coincideTexto = contenido.includes(texto);
            const coincideMetodo = metodo === '' || metodoFila === metodo;

            if (coincideTexto && coincideMetodo) {
                fila.style.display = '';
            } else {
                fila.style.display = 'none';
            }
        });
    }

    inputTexto.addEventListener('keyup', filtrar);
    selectMetodo.addEventListener('change', filtrar);
});
</script>

<?php require_once 'includes/footer.php'; ?>
