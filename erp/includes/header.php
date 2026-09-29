<?php
session_start();

// Control de Acceso: Redirigir a login si no hay sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/config.php';

// Obtener la URL de la imagen de fondo guardada
$bgUrl = !empty($empresa['bg_url']) ? $empresa['bg_url'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($empresa['nombre_empresa'] ?? 'ERP System') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            <?php if ($bgUrl && file_exists(__DIR__ . '/../' . $bgUrl)): ?>
            background: url('<?= htmlspecialchars($bgUrl) ?>?v=<?= filemtime(__DIR__ . '/../' . $bgUrl) ?>') no-repeat center center fixed;
            background-size: cover;
            <?php else: ?>
            background-color: #f4f6f9;
            <?php endif; ?>
            min-height: 100vh;
        }

        /* Contenedor principal estilizado sobre el fondo */
        .main-content {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        /* Asegurar bordes y visibilidad en tarjetas genéricas */
        .card:not([class*="bg-"]) {
            background-color: #ffffff;
            border: 1px solid #e3e6f0;
        }

        /* Respetar colores de fondo de Bootstrap y su texto */
        .card.bg-primary { background-color: #0d6efd !important; color: #ffffff !important; }
        .card.bg-success { background-color: #198754 !important; color: #ffffff !important; }
        .card.bg-danger { background-color: #dc3545 !important; color: #ffffff !important; }
        .card.bg-warning { background-color: #ffc107 !important; color: #212529 !important; }
        .card.bg-info { background-color: #0dcaf0 !important; color: #000000 !important; }
        .card.bg-dark { background-color: #212529 !important; color: #ffffff !important; }

        /* Estilo para los inputs del formulario para que resalten */
        .form-control, .form-select {
            background-color: #f8f9fa;
            border: 1px solid #ced4da;
            color: #212529;
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <?php if (!empty($empresa['logo_url']) && file_exists(__DIR__ . '/../' . $empresa['logo_url'])): ?>
        <img src="<?= htmlspecialchars($empresa['logo_url']) ?>" alt="Logo" style="max-height: 35px;" class="me-2">
      <?php endif; ?>
      <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'ERP System') ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="inventario.php"><i class="bi bi-box-seam"></i> Inventario</a></li>
        <li class="nav-item"><a class="nav-link" href="proveedores.php"><i class="bi bi-truck text-warning"></i> Proveedores</a></li>
        <li class="nav-item"><a class="nav-link" href="entradas.php"><i class="bi bi-box-arrow-in-down text-success"></i> Resustir/Producto</a></li>
        <li class="nav-item"><a class="nav-link" href="salidas.php"><i class="bi bi-cart-check text-info"></i> Ventas/Salidas</a></li>
        <li class="nav-item"><a class="nav-link" href="ingresos.php"><i class="bi bi-cash-coin text-success"></i> Ingresos</a></li>
        <li class="nav-item"><a class="nav-link" href="egresos.php"><i class="bi bi-wallet2 text-danger"></i> Egresos</a></li>
        <li class="nav-item"><a class="nav-link" href="cuentas_pagar.php"><i class="bi bi-credit-card"></i> CxP</a></li>
        <li class="nav-item"><a class="nav-link" href="cuentas_cobrar.php"><i class="bi bi-receipt"></i> CxC</a></li>
        <li class="nav-item"><a class="nav-link" href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a></li>
        <?php if (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'admin'): ?>
            <li class="nav-item"><a class="nav-link" href="usuarios.php"><i class="bi bi-people"></i> Usuarios</a></li>
            <li class="nav-item"><a class="nav-link" href="configuracion.php"><i class="bi bi-gear"></i> Configuración</a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center text-white">
        <span class="me-3"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_nombre'] ?? 'Usuario') ?></span>
        <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Salir</a>
      </div>
    </div>
  </div>
</nav>

<div class="container main-content">
