<?php 
require_once 'includes/header.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre_empresa'];
    $rfc = $_POST['rfc'];
    $email = $_POST['email_notificaciones'];

    $logoUrl = $empresa['logo_url'] ?? '';
    $bgUrl = $empresa['bg_url'] ?? '';

    // Manejo de carga de Logo
    if (!empty($_FILES['logo']['name'])) {
        $dir = 'uploads/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $logoUrl = $dir . time() . '_' . basename($_FILES['logo']['name']);
        move_uploaded_file($_FILES['logo']['tmp_name'], $logoUrl);
    }

    // Manejo de carga de Fondo de Pantalla
    if (!empty($_FILES['background']['name'])) {
        $dir = 'uploads/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $bgUrl = $dir . 'bg_' . time() . '_' . basename($_FILES['background']['name']);
        move_uploaded_file($_FILES['background']['tmp_name'], $bgUrl);
    }

    $stmt = $pdo->prepare("UPDATE configuracion SET nombre_empresa = ?, rfc = ?, email_notificaciones = ?, logo_url = ?, bg_url = ? WHERE id = ?");
    $stmt->execute([$nombre, $rfc, $email, $logoUrl, $bgUrl, $empresa['id'] ?? 1]);

    echo "<div class='alert alert-success'>Configuración e imágenes actualizadas correctamente.</div>";
    header("Refresh:1");
}
?>

<h2>Configuración del Sistema y Apariencia</h2>

<div class="card shadow-sm col-md-8 offset-md-2">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label>Nombre de la Empresa</label>
                <input type="text" name="nombre_empresa" value="<?= htmlspecialchars($empresa['nombre_empresa'] ?? '') ?>" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>RFC / Identificación Fiscal</label>
                <input type="text" name="rfc" value="<?= htmlspecialchars($empresa['rfc'] ?? '') ?>" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Correo para Notificaciones Automáticas</label>
                <input type="email" name="email_notificaciones" value="<?= htmlspecialchars($empresa['email_notificaciones'] ?? '') ?>" class="form-control" required>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label>Logo de la Empresa</label>
                    <input type="file" name="logo" class="form-control" accept="image/*">
                    <?php if (!empty($empresa['logo_url'])): ?>
                        <img src="<?= $empresa['logo_url'] ?>" style="max-height: 60px;" class="mt-2 border p-1">
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label>Fondo de Pantalla del Sistema</label>
                    <input type="file" name="background" class="form-control" accept="image/*">
                    <?php if (!empty($empresa['bg_url'])): ?>
                        <img src="<?= $empresa['bg_url'] ?>" style="max-height: 60px;" class="mt-2 border p-1">
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> Guardar Cambios</button>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
