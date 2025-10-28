<?php
session_start();
require 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// ✅ Use UTC for consistent timestamps
date_default_timezone_set("UTC");

// ✅ Validate email parameter
if (!isset($_GET['email']) || empty(trim($_GET['email']))) {
    $_SESSION['error_html'] = "Invalid or missing email address.";
    header("Location: login");
    exit();
}

$email = trim($_GET['email']);

// ✅ Check if user exists
$stmt = $conn->prepare("SELECT id, full_name, email_verified FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['error_html'] = "No account found with that email address.";
    header("Location: register");
    exit();
}

if ((int)$user['email_verified'] === 1) {
    $_SESSION['success_html'] = "Your email is already verified. Please log in.";
    header("Location: login");
    exit();
}

// ✅ Generate new token and expiry (1 hour)
$token = bin2hex(random_bytes(32));
$expiry = gmdate("Y-m-d H:i:s", time() + 3600); // ⏰ 1 hour

$update = $conn->prepare("UPDATE users SET email_token = ?, email_token_expires = ? WHERE id = ?");
$update->bind_param("ssi", $token, $expiry, $user['id']);
$update->execute();
$update->close();

// ✅ Build dynamic verification link
$baseURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
    . "://" . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['PHP_SELF']), '/');
$verifyLink = $baseURL . "/verifyEmail?token=" . urlencode($token);

// ✅ Prepare email message
$subject = "Verify Your Accofinda Account";
$body = "
<html>
<head><title>Verify Your Accofinda Account</title></head>
<body style='font-family: Arial, sans-serif; background-color:#f9f9f9; padding: 20px;'>
    <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; padding:20px; border:1px solid #eee;'>
        <h2 style='color:#364458ff;'>Email Verification Required</h2>
        <p>Hello <strong>{$user['full_name']}</strong>,</p>
        <p>Please verify your email to activate your Accofinda account:</p>
        <p>
            <a href='{$verifyLink}' 
               style='background:#364458ff; color:white; padding:10px 20px; text-decoration:none; border-radius:6px;'>
               Verify My Email
            </a>
        </p>
        <p>If the button above does not work, copy and paste this link into your browser:</p>
        <p><a href='{$verifyLink}'>{$verifyLink}</a></p>
        <hr>
        <p style='font-size:0.9em;color:#555;'>This link expires in 1 hour for your security.</p>
        <p style='color:#364458ff;'><b>Accofinda Team</b></p>
    </div>
</body>
</html>
";

// ✅ Send verification email
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'smtp.hostinger.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'support@accofinda.com';
    $mail->Password = '#$RashidQN13$#';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;

    $mail->setFrom('support@accofinda.com', 'Accofinda');
    $mail->addAddress($email, $user['full_name']);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->AltBody = strip_tags("Hello {$user['full_name']},\n\nPlease verify your account using this link:\n$verifyLink\n\nThis link expires in 1 hour.");

    $mail->send();

    $_SESSION['success_html'] = "A new verification link has been sent to <strong>$email</strong>. It expires in 1 hour.";
    header("Location: login");
    exit();
} catch (Exception $e) {
    error_log("Resend Mailer Error: " . $mail->ErrorInfo);
    $_SESSION['error_html'] = "Failed to send verification link. Please try again or contact support.";
    header("Location: login");
    exit();
}
?>