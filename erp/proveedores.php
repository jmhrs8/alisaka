<?php
require_once 'includes/header.php';

$mensajeExito = '';
$mensajeError = '';

// Directoria de carga para archivos RFC
$directorioUpload = 'uploads/rfc/';
if (!is_dir($directorioUpload)) {
    mkdir($directorioUpload, 0755, true);
}

// Función auxiliar para procesar la subida del archivo RFC
function procesarSubidaRFC($fileInput, $directorioUpload, $archivoAntiguo = null) {
    if (isset($_FILES[$fileInput]) && $_FILES[$fileInput]['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES[$fileInput]['tmp_name'];
        $fileName      = $_FILES[$fileInput]['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $extensionesPermitidas = ['pdf', 'jpg', 'jpeg', 'png'];

        if (in_array($fileExtension, $extensionesPermitidas)) {
            // Nombre único para evitar colisiones
            $nuevoNombreArchivo = 'RFC_' . uniqid() . '_' . time() . '.' . $fileExtension;
            $destPath = $directorioUpload . $nuevoNombreArchivo;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                // Eliminar archivo antiguo si existe
                if ($archivoAntiguo && file_exists($directorioUpload . $archivoAntiguo)) {
                    @unlink($directorioUpload . $archivoAntiguo);
                }
                return $nuevoNombreArchivo;
            } else {
                throw new Exception("Error al mover el archivo de RFC al directorio de destino.");
            }
        } else {
            throw new Exception("Formato de archivo de RFC no permitido. Solo se admiten PDF, JPG y PNG.");
        }
    }
    return $archivoAntiguo; // Retorna el anterior si no se subió uno nuevo
}

// 1. REGISTRAR PROVEEDOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_proveedor'])) {
    $nombre        = trim($_POST['nombre'] ?? '');
    $rfc           = trim($_POST['rfc'] ?? '');
    $contacto      = trim($_POST['contacto'] ?? '');
    $telefono      = trim($_POST['telefono'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $direccion     = trim($_POST['direccion'] ?? '');
    $banco         = trim($_POST['banco'] ?? '');
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');

    if (!empty($nombre)) {
        try {
            $rfc_archivo = procesarSubidaRFC('rfc_archivo', $directorioUpload);

            $stmt = $pdo->prepare("INSERT INTO proveedores (nombre, rfc, contacto, telefono, email, direccion, banco, numero_cuenta, rfc_archivo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $rfc, $contacto, $telefono, $email, $direccion, $banco, $numero_cuenta, $rfc_archivo]);
            $mensajeExito = "Proveedor registrado correctamente.";
        } catch (\PDOException $e) {
            $mensajeError = "Error al guardar el proveedor: " . $e->getMessage();
        } catch (Exception $e) {
            $mensajeError = $e->getMessage();
        }
    } else {
        $mensajeError = "El nombre del proveedor es obligatorio.";
    }
}

// 2. EDITAR PROVEEDOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_proveedor'])) {
    $id            = intval($_POST['proveedor_id'] ?? 0);
    $nombre        = trim($_POST['nombre'] ?? '');
    $rfc           = trim($_POST['rfc'] ?? '');
    $contacto      = trim($_POST['contacto'] ?? '');
    $telefono      = trim($_POST['telefono'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $direccion     = trim($_POST['direccion'] ?? '');
    $banco         = trim($_POST['banco'] ?? '');
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');
    $archivoActual = $_POST['rfc_archivo_actual'] ?? null;

    if ($id > 0 && !empty($nombre)) {
        try {
            $rfc_archivo = procesarSubidaRFC('rfc_archivo', $directorioUpload, $archivoActual);

            $stmt = $pdo->prepare("UPDATE proveedores SET nombre = ?, rfc = ?, contacto = ?, telefono = ?, email = ?, direccion = ?, banco = ?, numero_cuenta = ?, rfc_archivo = ? WHERE id = ?");
            $stmt->execute([$nombre, $rfc, $contacto, $telefono, $email, $direccion, $banco, $numero_cuenta, $rfc_archivo, $id]);
            $mensajeExito = "Proveedor actualizado correctamente.";
        } catch (\PDOException $e) {
            $mensajeError = "Error al actualizar el proveedor: " . $e->getMessage();
        } catch (Exception $e) {
            $mensajeError = $e->getMessage();
        }
    } else {
        $mensajeError = "Datos inválidos para actualizar el proveedor.";
    }
}

// 3. ELIMINAR PROVEEDOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_proveedor'])) {
    $id = intval($_POST['proveedor_id'] ?? 0);

    if ($id > 0) {
        try {
            // Verificar si el proveedor está vinculado en cuentas por pagar
            $stmtVerif = $pdo->prepare("SELECT COUNT(*) FROM cuentas_pagar WHERE proveedor_id = ?");
            $stmtVerif->execute([$id]);
            $vinculadoCxP = $stmtVerif->fetchColumn();

            if ($vinculadoCxP > 0) {
                throw new Exception("No se puede eliminar el proveedor porque tiene cuentas por pagar registradas.");
            }

            // Obtener el archivo para borrarlo del servidor
            $stmtFile = $pdo->prepare("SELECT rfc_archivo FROM proveedores WHERE id = ?");
            $stmtFile->execute([$id]);
            $archivoRfc = $stmtFile->fetchColumn();

            $stmtDel = $pdo->prepare("DELETE FROM proveedores WHERE id = ?");
            $stmtDel->execute([$id]);

            if ($archivoRfc && file_exists($directorioUpload . $archivoRfc)) {
                @unlink($directorioUpload . $archivoRfc);
            }

            $mensajeExito = "Proveedor eliminado correctamente.";
        } catch (Exception $e) {
            $mensajeError = "Error al eliminar proveedor: " . $e->getMessage();
        }
    } else {
        $mensajeError = "ID de proveedor inválido.";
    }
}

// OBTENER LISTA DE PROVEEDORES
try {
    $proveedores = $pdo->query("SELECT * FROM proveedores ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $proveedores = [];
    $mensajeError = "Error al consultar la tabla proveedores: " . $e->getMessage();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-truck text-primary me-2"></i> Gestión de Proveedores</h2>
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

<!-- FORMULARIO REGISTRO -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-plus-circle me-1"></i> Registrar Nuevo Proveedor
    </div>
    <div class="card-body">
        <form method="POST" action="proveedores.php" enctype="multipart/form-data">
            <input type="hidden" name="guardar_proveedor" value="1">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Nombre / Razón Social (*):</label>
                    <input type="text" name="nombre" class="form-control" required placeholder="Ej. Distribuidora MX">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">RFC:</label>
                    <input type="text" name="rfc" class="form-control" placeholder="XAXX010101000">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Persona de Contacto:</label>
                    <input type="text" name="contacto" class="form-control" placeholder="Ej. Lic. Juan Pérez">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Teléfono:</label>
                    <input type="text" name="telefono" class="form-control" placeholder="55 1234 5678">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Correo Electrónico:</label>
                    <input type="email" name="email" class="form-control" placeholder="contacto@empresa.com">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold">Dirección:</label>
                    <input type="text" name="direccion" class="form-control" placeholder="Calle, Número, Colonia, Ciudad">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Banco:</label>
                    <input type="text" name="banco" class="form-control" placeholder="Ej. BBVA, Banorte">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">N° Cuenta / CLABE:</label>
                    <input type="text" name="numero_cuenta" class="form-control" placeholder="0123456789...">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Documento RFC (PDF / Imagen):</label>
                    <input type="file" name="rfc_archivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="col-md-8 text-end align-self-end">
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-save me-1"></i> Guardar Proveedor
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- LISTADO CON ACCIONES EDITAR Y ELIMINAR -->
<div class="card shadow-sm">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="bi bi-list-ul me-1"></i> Lista de Proveedores Registrados
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Nombre / Razón Social</th>
                        <th>RFC</th>
                        <th>RFC Doc</th>
                        <th>Contacto</th>
                        <th>Teléfono</th>
                        <th>Correo</th>
                        <th>Dirección</th>
                        <th>Banco</th>
                        <th>N° Cuenta / CLABE</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($proveedores)): ?>
                        <tr><td colspan="11" class="text-center py-3 text-muted">No hay proveedores registrados aún.</td></tr>
                    <?php else: ?>
                        <?php foreach ($proveedores as $prov): ?>
                            <tr>
                                <td><?= $prov['id'] ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($prov['nombre']) ?></td>
                                <td><?= htmlspecialchars($prov['rfc'] ?? 'N/A') ?></td>
                                <td class="text-center">
                                    <?php if (!empty($prov['rfc_archivo'])): ?>
                                        <a href="<?= $directorioUpload . htmlspecialchars($prov['rfc_archivo']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Ver RFC">
                                            <i class="bi bi-file-earmark-pdf-fill"></i> Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Sin archivo</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($prov['contacto'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($prov['telefono'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($prov['email'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($prov['direccion'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($prov['banco'] ?? 'N/A') ?></span></td>
                                <td class="fw-bold text-secondary"><?= htmlspecialchars($prov['numero_cuenta'] ?? 'N/A') ?></td>
                                <td class="text-center">
                                    <!-- Botón Editar -->
                                    <button class="btn btn-sm btn-outline-warning me-1" title="Editar Proveedor" data-bs-toggle="modal" data-bs-target="#modalEditarProv<?= $prov['id'] ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>

                                    <!-- Botón Eliminar -->
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar Proveedor" data-bs-toggle="modal" data-bs-target="#modalEliminarProv<?= $prov['id'] ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>

                                    <!-- MODAL EDITAR -->
                                    <div class="modal fade" id="modalEditarProv<?= $prov['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content text-start">
                                                <form method="POST" action="proveedores.php" enctype="multipart/form-data">
                                                    <input type="hidden" name="editar_proveedor" value="1">
                                                    <input type="hidden" name="proveedor_id" value="<?= $prov['id'] ?>">
                                                    <input type="hidden" name="rfc_archivo_actual" value="<?= htmlspecialchars($prov['rfc_archivo'] ?? '') ?>">
                                                    <div class="modal-header bg-warning text-dark">
                                                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i> Editar Proveedor</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Nombre / Razón Social (*):</label>
                                                            <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($prov['nombre']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">RFC:</label>
                                                            <input type="text" name="rfc" class="form-control" value="<?= htmlspecialchars($prov['rfc'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Documento RFC (PDF / Imagen):</label>
                                                            <input type="file" name="rfc_archivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                                            <?php if (!empty($prov['rfc_archivo'])): ?>
                                                                <small class="text-muted d-block mt-1">
                                                                    Archivo actual: <a href="<?= $directorioUpload . htmlspecialchars($prov['rfc_archivo']) ?>" target="_blank">Ver documento actual</a>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Contacto:</label>
                                                            <input type="text" name="contacto" class="form-control" value="<?= htmlspecialchars($prov['contacto'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Teléfono:</label>
                                                            <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($prov['telefono'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Correo Electrónico:</label>
                                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($prov['email'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Dirección:</label>
                                                            <input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($prov['direccion'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Banco:</label>
                                                            <input type="text" name="banco" class="form-control" value="<?= htmlspecialchars($prov['banco'] ?? '') ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">N° Cuenta / CLABE:</label>
                                                            <input type="text" name="numero_cuenta" class="form-control" value="<?= htmlspecialchars($prov['numero_cuenta'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn btn-warning fw-bold"><i class="bi bi-check-circle me-1"></i> Guardar Cambios</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL ELIMINAR -->
                                    <div class="modal fade" id="modalEliminarProv<?= $prov['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content text-start">
                                                <form method="POST" action="proveedores.php">
                                                    <input type="hidden" name="eliminar_proveedor" value="1">
                                                    <input type="hidden" name="proveedor_id" value="<?= $prov['id'] ?>">
                                                    <div class="modal-header bg-danger text-white">
                                                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i> Confirmar Eliminación</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        ¿Estás seguro de que deseas eliminar al proveedor <strong><?= htmlspecialchars($prov['nombre']) ?></strong>?
                                                        <br><small class="text-muted">Esta acción no se puede deshacer si el proveedor no tiene movimientos asociados.</small>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-trash me-1"></i> Eliminar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
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

<?php require_once 'includes/footer.php'; ?>
