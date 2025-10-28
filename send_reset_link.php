<?php
date_default_timezone_set('Africa/Nairobi');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'config.php';
require 'vendor/autoload.php'; // or include PHPMailer classes manually if no composer

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    $message = "";
    $alertClass = "";

    if ($stmt->num_rows > 0) {
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", time() + 3600); // 1 hour

        $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $stmt->bind_param("sss", $token, $expires, $email);
        $stmt->execute();

        $resetLink = "https://accofinda.com/reset_password.php?token=$token";

        // Send email using PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'rashidartificiali@gmail.com';
            $mail->Password = 'rcimcgwyalejhpjg';    // App password
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('rashidartificiali@gmail.com', 'Accofinda');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Link';
            $mail->Body = "
                <p>Click the link below to reset your password:</p>
                <p><a href='$resetLink'>$resetLink</a></p>
                <p><b>Note:</b> This link expires in 1 hour.</p>
            ";

            $mail->send();
            $message = "✅ A reset link has been sent to <b>$email</b>. Please check your inbox.";
            $alertClass = "alert-success";
        } catch (Exception $e) {
            $message = "❌ Email failed to send. Error: {$mail->ErrorInfo}";
            $alertClass = "alert-danger";
        }
    } else {
        $message = "❌ No account found with that email address.";
        $alertClass = "alert-warning";
    }

    // Bootstrap styled output
    echo "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>
            .fade-out {
                opacity: 1;
                transition: opacity 1s ease-out;
            }
            .fade-out.hide {
                opacity: 0;
            }
        </style>
    </head>
    <body class='bg-light d-flex justify-content-center align-items-center vh-100'>
        <div class='container text-center'>
            <div id='alertBox' class='alert $alertClass alert-dismissible fade show fade-out shadow-lg rounded-3' role='alert'>
                $message
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
            </div>
        </div>

        <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'></script>
        <script>
            // Auto-dismiss after 10 seconds
            setTimeout(() => {
                const alert = document.getElementById('alertBox');
                if(alert){
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 1000);
                }
            }, 10000);
        </script>
    </body>
    </html>
    ";
}
?>