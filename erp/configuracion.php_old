<?php
require_once 'includes/header.php';

$mensajeExito = '';
$mensajeError = '';

$dirUploads = __DIR__ . '/uploads/';
$dirFondo = __DIR__ . '/uploads/fondo/';

// 1. ACTUALIZAR DATOS DE LA EMPRESA (NOMBRE Y LOGO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_empresa'])) {
    $nombreEmpresa = trim($_POST['nombre_empresa'] ?? '');

    if (!empty($nombreEmpresa)) {
        $logoUrl = $empresa['logo_url'] ?? '';

        // Subir nuevo logo si se selecciona uno
        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $extsPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

            if (in_array($ext, $extsPermitidas)) {
                if (!is_dir($dirUploads)) {
                    mkdir($dirUploads, 0755, true);
                }

                $nombreLogo = 'logo_' . time() . '.' . $ext;
                $destinoLogo = $dirUploads . $nombreLogo;

                if (move_uploaded_file($file['tmp_name'], $destinoLogo)) {
                    $logoUrl = 'uploads/' . $nombreLogo;
                } else {
                    $mensajeError = "No se pudo subir el logo al servidor.";
                }
            } else {
                $mensajeError = "Formato de logo no válido (solo JPG, PNG, WEBP, SVG).";
            }
        }

        if (empty($mensajeError)) {
            try {
                $stmt = $pdo->prepare("UPDATE configuracion SET nombre_empresa = ?, logo_url = ? WHERE id = 1");
                $stmt->execute([$nombreEmpresa, $logoUrl]);
                $mensajeExito = "Datos de la empresa actualizados correctamente.";
            } catch (\PDOException $e) {
                try {
                    $stmt = $pdo->prepare("UPDATE empresa SET nombre_empresa = ?, logo_url = ? WHERE id = 1");
                    $stmt->execute([$nombreEmpresa, $logoUrl]);
                    $mensajeExito = "Datos de la empresa actualizados correctamente.";
                } catch (\PDOException $e) {
                    $mensajeError = "Error en la base de datos al guardar los cambios.";
                }
            }

            // Actualizar datos locales de la variable
            $empresa['nombre_empresa'] = $nombreEmpresa;
            $empresa['logo_url'] = $logoUrl;
        }
    } else {
        $mensajeError = "El nombre de la empresa no puede estar vacío.";
    }
}

// 2. SUBIR O CAMBIAR IMAGEN DE FONDO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_fondo'])) {
    if (!empty($_FILES['imagen_fondo']['name'])) {
        $file = $_FILES['imagen_fondo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $extsPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'];

        if (in_array($ext, $extsPermitidas)) {
            if (!is_dir($dirFondo)) {
                mkdir($dirFondo, 0755, true);
            }

            // Eliminar fondos anteriores
            array_map('unlink', glob($dirFondo . 'background.*'));

            $nombreArchivo = 'background.' . $ext;
            $destino = $dirFondo . $nombreArchivo;
            $rutaRelativa = 'uploads/fondo/' . $nombreArchivo;

            if (move_uploaded_file($file['tmp_name'], $destino)) {
                try {
                    $stmt = $pdo->prepare("UPDATE configuracion SET bg_url = ? WHERE id = 1");
                    $stmt->execute([$rutaRelativa]);
                } catch (\PDOException $e) {
                    try {
                        $stmt = $pdo->prepare("UPDATE empresa SET bg_url = ? WHERE id = 1");
                        $stmt->execute([$rutaRelativa]);
                    } catch (\PDOException $e) {}
                }

                $mensajeExito = "¡Imagen de fondo actualizada con éxito!";
                $empresa['bg_url'] = $rutaRelativa;
            } else {
                $mensajeError = "Error al guardar el fondo en el servidor.";
            }
        } else {
            $mensajeError = "Formato de fondo no válido.";
        }
    } else {
        $mensajeError = "Selecciona una imagen para el fondo.";
    }
}

// 3. ELIMINAR / RESTABLECER IMAGEN DE FONDO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_fondo'])) {
    array_map('unlink', glob($dirFondo . 'background.*'));

    try {
        $pdo->query("UPDATE configuracion SET bg_url = '' WHERE id = 1");
    } catch (\PDOException $e) {
        try {
            $pdo->query("UPDATE empresa SET bg_url = '' WHERE id = 1");
        } catch (\PDOException $e) {}
    }

    $mensajeExito = "Fondo eliminado. Se restableció el color por defecto.";
    $empresa['bg_url'] = '';
}

$bgActual = !empty($empresa['bg_url']) && file_exists(__DIR__ . '/' . $empresa['bg_url']) ? $empresa['bg_url'] : '';
$logoActual = !empty($empresa['logo_url']) && file_exists(__DIR__ . '/' . $empresa['logo_url']) ? $empresa['logo_url'] : '';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-gear-fill"></i> Configuración General del Sistema</h2>
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

<div class="row">
    <!-- SECCIÓN 1: DATOS DE LA EMPRESA Y LOGO -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-building me-2"></i> Información de la Empresa
            </div>
            <div class="card-body">
                <form method="POST" action="configuracion.php" enctype="multipart/form-data">
                    <input type="hidden" name="guardar_empresa" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre Comercial / Razón Social:</label>
                        <input type="text" name="nombre_empresa" class="form-control" value="<?= htmlspecialchars($empresa['nombre_empresa'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Cambiar Logo de la Empresa:</label>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">Formatos aceptados: PNG, JPG, WEBP, SVG.</div>
                    </div>

                    <?php if ($logoActual): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Logo actual:</label>
                            <div class="border rounded p-2 bg-light text-center">
                                <img src="<?= htmlspecialchars($logoActual) ?>?v=<?= time() ?>" style="max-height: 80px;" class="img-fluid">
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save"></i> Guardar Cambios de la Empresa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 2: PERSONALIZACIÓN DEL FONDO COMPLETO -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold">
                <i class="bi bi-image me-2"></i> Fondo Personalizado (Pantalla Completa)
            </div>
            <div class="card-body">
                <form method="POST" action="configuracion.php" enctype="multipart/form-data">
                    <input type="hidden" name="subir_fondo" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Seleccionar imagen de fondo:</label>
                        <input type="file" name="imagen_fondo" class="form-control" accept="image/*" required>
                        <div class="form-text">Soporta cualquier formato (<strong>JPG, PNG, WEBP, GIF, SVG</strong>).</div>
                    </div>

                    <?php if ($bgActual): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Vista previa del fondo actual:</label>
                            <div class="border rounded p-2 bg-light text-center">
                                <img src="<?= htmlspecialchars($bgActual) ?>?v=<?= time() ?>" style="max-height: 120px; width: 100%; object-fit: cover;" class="rounded shadow-sm">
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-upload"></i> Aplicar Fondo
                        </button>
                </form>

                <?php if ($bgActual): ?>
                    <form method="POST" action="configuracion.php" onsubmit="return confirm('¿Seguro que deseas quitar la imagen de fondo?');">
                        <input type="hidden" name="eliminar_fondo" value="1">
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="bi bi-trash"></i> Quitar
                        </button>
                    </form>
                <?php endif; ?>
                    </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
