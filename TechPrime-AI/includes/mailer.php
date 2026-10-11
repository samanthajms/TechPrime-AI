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

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($smtpFrom, 'EasyPC One Oasis');
        $mail->addAddress($userEmail, $userName);
        $mail->isHTML(true);
        $mail->Subject = 'Activate your EasyPC account';
        $mail->Body    = ias_activation_email_html((string)$userName, (string)$activationLink);
        $mail->AltBody = ias_activation_email_text((string)$userName, (string)$activationLink);

        return $mail->send();
    } catch (Exception $e) {
        error_log('sendActivationEmail: ' . $mail->ErrorInfo);
        return false;
    }
}

/** How long an activation link stays valid, in hours (IAS_ACTIVATION_TTL lives in security.php). */
function ias_activation_email_hours(): int {
    return defined('IAS_ACTIVATION_TTL') ? max(1, (int)round(IAS_ACTIVATION_TTL / 3600)) : 24;
}

/**
 * Activation email body. Email clients ignore <style> blocks and external CSS,
 * so the layout is tables with inline styles only; colors are the storefront's
 * --ep-green tokens. No images: Gmail blocks localhost URLs and hides images by default.
 */
function ias_activation_email_html(string $userName, string $activationLink): string {
    $name  = htmlspecialchars(trim($userName) !== '' ? trim($userName) : 'there', ENT_QUOTES, 'UTF-8');
    $link  = htmlspecialchars($activationLink, ENT_QUOTES, 'UTF-8');
    $hours = ias_activation_email_hours();
    $year  = date('Y');
    $font  = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";

    $features = [
        ['&#128722;', 'Shop PCs, laptops &amp; accessories', 'Browse the One Oasis catalog with live stock and prices.'],
        ['&#128295;', 'Build a PC',                         'Pick parts and get an instant compatibility check before you buy.'],
        ['&#129302;', 'Ask Primo, our AI assistant',        'Get product suggestions and answers to your questions anytime.'],
        ['&#128666;', 'Order &amp; track',                  'Pay cash on delivery or online, then follow your orders from your account.'],
    ];
    $rows = '';
    foreach ($features as [$icon, $title, $text]) {
        $rows .= <<<HTML
<tr>
  <td width="44" valign="top" style="padding:0 0 14px 0;">
    <div style="width:34px;height:34px;line-height:34px;border-radius:10px;background:#eef8e6;text-align:center;font-size:17px;">$icon</div>
  </td>
  <td valign="top" style="padding:0 0 14px 0;{$font}">
    <div style="font-size:14px;font-weight:600;color:#1f2a1c;">$title</div>
    <div style="font-size:13px;line-height:19px;color:#5f6b5a;">$text</div>
  </td>
</tr>
HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<title>Activate your EasyPC account</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f3;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f6f3;">Welcome to EasyPC One Oasis, $name! Activate your account to start shopping, building PCs and tracking orders.&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f3;">
<tr><td align="center" style="padding:32px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e3e8e0;">
    <tr>
      <td style="background:#62b236;background-image:linear-gradient(135deg,#62b236,#4b8b2a);padding:28px 32px;{$font}">
        <div style="font-size:26px;font-weight:800;letter-spacing:4px;color:#ffffff;">EASYPC</div>
        <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#e4f5d8;margin-top:4px;">One Oasis &middot; Rosario, Pasig</div>
      </td>
    </tr>
    <tr>
      <td style="padding:32px 32px 8px 32px;{$font}">
        <div style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4b8b2a;">One last step</div>
        <h1 style="margin:8px 0 12px 0;font-size:24px;line-height:30px;color:#1f2a1c;font-weight:700;">Hi $name, welcome to EasyPC!</h1>
        <p style="margin:0 0 24px 0;font-size:15px;line-height:23px;color:#4a5546;">Thanks for creating an account. Confirm your email address to activate it and start shopping at EasyPC One Oasis.</p>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
          <tr><td align="center" style="border-radius:10px;background:#62b236;">
            <a href="$link" target="_blank" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;{$font}">Activate my account</a>
          </td></tr>
        </table>
        <p style="margin:14px 0 0 0;font-size:13px;color:#6b7666;">&#9201; This link expires in $hours hours.</p>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 32px 6px 32px;">
        <div style="border-top:1px solid #eef1ec;padding-top:24px;font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#1f2a1c;{$font}">What you can do once you're in</div>
      </td>
    </tr>
    <tr>
      <td style="padding:12px 32px 10px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">$rows</table>
      </td>
    </tr>
    <tr>
      <td style="padding:0 32px 28px 32px;{$font}">
        <div style="background:#f4f6f3;border-radius:10px;padding:14px 16px;font-size:12px;line-height:18px;color:#6b7666;">
          Button not working? Copy this link into your browser:<br>
          <a href="$link" target="_blank" style="color:#4b8b2a;word-break:break-all;">$link</a>
        </div>
        <p style="margin:16px 0 0 0;font-size:12px;line-height:18px;color:#8a9486;">Didn't sign up for EasyPC? You can safely ignore this email &mdash; the account won't be activated.</p>
      </td>
    </tr>
  </table>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
    <tr><td align="center" style="padding:20px 12px;font-size:12px;line-height:18px;color:#8a9486;{$font}">
      &copy; $year EasyPC One Oasis &middot; Rosario, Pasig City<br>
      Powered by TechPrime AI &middot; This is an automated message, please don't reply.
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/** Plain-text version for clients that don't render HTML (also helps spam scoring). */
function ias_activation_email_text(string $userName, string $activationLink): string {
    $name  = trim($userName) !== '' ? trim($userName) : 'there';
    $hours = ias_activation_email_hours();
    return "Hi $name, welcome to EasyPC!\n\n"
        . "Thanks for creating an account. Confirm your email address to activate it:\n"
        . "$activationLink\n\n"
        . "This link expires in $hours hours.\n\n"
        . "Once you're in, you can:\n"
        . "- Shop PCs, laptops & accessories from the One Oasis catalog\n"
        . "- Build a PC with an instant compatibility check\n"
        . "- Ask Primo, our AI assistant, for product suggestions\n"
        . "- Pay cash on delivery or online, and track your orders\n\n"
        . "Didn't sign up for EasyPC? You can safely ignore this email.\n\n"
        . "EasyPC One Oasis - Rosario, Pasig City\n"
        . "Powered by TechPrime AI";
}