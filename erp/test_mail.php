<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);

try {
    // Activar depuración completa
    $mail->SMTPDebug = 2;

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    
    // Tus credenciales reales
    $mail->Username   = 'jmhrs8@gmail.com'; 
    $mail->Password   = 'czyydevgpokxkljm'; 
    
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('jmhrs8@gmail.com', 'Prueba de Sistema');
    $mail->addAddress('jmhrs8@gmail.com'); 

    $mail->isHTML(true);
    $mail->Subject = 'Prueba de envio de correo SMTP';
    $mail->Body    = '<b>¡Funciona correctamente!</b> Este es un correo de prueba desde el ERP.';

    $mail->send();
    echo "<br><strong style='color:green;'>✅ Correo enviado exitosamente.</strong>";
} catch (Exception $e) {
    echo "<br><strong style='color:red;'>❌ Falló el envío: {$mail->ErrorInfo}</strong>";
}
?>
