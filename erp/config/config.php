<?php
// Cargar la conexión PDO a la base de datos
require_once __DIR__ . '/db.php';

// Cargar la configuración general de la empresa desde la BD
$stmt = $pdo->query("SELECT * FROM configuracion LIMIT 1");
$empresa = $stmt->fetch();

// Valores por defecto si la tabla está vacía
if (!$empresa) {
    $empresa = [
        'nombre_empresa' => 'Mi Empresa S.A. de C.V.',
        'rfc' => 'XAXX010101000',
        'email_notificaciones' => 'admin@empresa.com',
        'logo_url' => 'uploads/logo.png'
    ];
}
?>
