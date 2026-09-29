<?php
require_once __DIR__ . '/db.php';

// Obtener datos globales de la empresa
$stmtConfig = $pdo->query("SELECT * FROM configuracion LIMIT 1");
$empresa = $stmtConfig->fetch();
?>
