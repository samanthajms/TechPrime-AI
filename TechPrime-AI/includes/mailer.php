<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Go up one level (..) then into backend/vendor
require __DIR__ . '/../backend/vendor/PHPMailer-master/src/Exception.php';
require __DIR__ . '/../backend/vendor/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../backend/vendor/PHPMailer-master/src/SMTP.php';
// Loads .env (SMTP_USER / SMTP_PASS / SMTP_FROM) — credentials never live in this file.
require_once __DIR__ . '/../backend/config/database.php';

function sendActivationEmail($userEmail, $userName, $activationLink) {
    $smtpUser = (string)($_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?: '');
    $smtpPass = (string)($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: '');
    $smtpFrom = (string)($_ENV['SMTP_FROM'] ?? getenv('SMTP_FROM') ?: $smtpUser);
    if ($smtpUser === '' || $smtpPass === '') {
        error_log('sendActivationEmail: SMTP_USER / SMTP_PASS are not set in .env');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($smtpFrom, 'IAS Marketplace');
        $mail->addAddress($userEmail, $userName);
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Account - IAS';
        $safeName = htmlspecialchars((string)$userName, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars((string)$activationLink, ENT_QUOTES, 'UTF-8');
        $mail->Body    = "<h1>Hi $safeName!</h1><p>Please click below to activate your account:</p><br><a href='$safeLink' style='background:#0998a8; color:white; padding:10px; text-decoration:none;'>Activate Account</a>";

        return $mail->send();
    } catch (Exception $e) {
        error_log('sendActivationEmail: ' . $mail->ErrorInfo);
        return false;
    }
}