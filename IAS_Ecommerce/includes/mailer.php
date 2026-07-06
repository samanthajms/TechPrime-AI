<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Go up one level (..) then into backend/vendor
require __DIR__ . '/../backend/vendor/PHPMailer-master/src/Exception.php';
require __DIR__ . '/../backend/vendor/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../backend/vendor/PHPMailer-master/src/SMTP.php';

function sendActivationEmail($userEmail, $userName, $activationLink) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ronagorrgiii@gmail.com'; // Enter your Gmail
        $mail->Password   = 'fyox nnvc eorq tmej';    // Enter your 16-char App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('YOUR_GMAIL@gmail.com', 'IAS Marketplace');
        $mail->addAddress($userEmail, $userName);
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Account - IAS';
        $mail->Body    = "<h1>Hi $userName!</h1><p>Please click below to activate your account:</p><br><a href='$activationLink' style='background:#0998a8; color:white; padding:10px; text-decoration:none;'>Activate Account</a>";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}