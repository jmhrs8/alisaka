<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Si usas composer:
// require 'vendor/autoload.php';

// Si descargaste PHPMailer manualmente:
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

/**
 * Función para enviar correos del sistema.
 * 
 * @param string $destinatario Correo destino
 * @param string $asunto Asunto del correo
 * @param string $cuerpoHTML Mensaje en formato HTML
 * @param string|null $rutaAdjunto Ruta local del archivo PDF/documento a adjuntar
 * @return bool True si se envió correctamente, False si falló
 */
function enviarCorreoSistema($destinatario, $asunto, $cuerpoHTML, $rutaAdjunto = null) {
    $mail = new PHPMailer(true);

    try {
        // Configuración del servidor SMTP de Gmail
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // Configura tu correo personal y la contraseña de aplicación de Google
        $mail->Username   = 'jmhrs8@gmail.com'; 
        // Elimina los espacios del código generado por Google: "czyy devg pokx kljm" -> "czyydevgpokxkljm"
        $mail->Password   = 'czyydevgpokxkljm'; 
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
        $mail->Port       = 587;                            // Puerto seguro SMTP

        // Remitente y Destinatario
        $mail->setFrom('alisaka@gmail.com', 'Sistema de Control ERP');
        $mail->addAddress($destinatario);

        // Archivo adjunto (Ejemplo: PDF del reporte del día)
        if ($rutaAdjunto && file_exists($rutaAdjunto)) {
            $mail->addAttachment($rutaAdjunto);
        }

        // Contenido del correo
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpoHTML;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error al enviar correo: {$mail->ErrorInfo}");
        return false;
    }
}
