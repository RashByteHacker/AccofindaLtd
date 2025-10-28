<?php
session_start();
require '../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

// ✅ Restrict to admin only
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    die("<h3 style='color:red;'>Access denied. Admins only.</h3>");
}

// ✅ When the admin clicks the button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $conn->query("SELECT id, full_name, email FROM users WHERE email_verified = 0");

    if (!$result || $result->num_rows === 0) {
        echo "<h3 style='color:green;'>✅ All users are already verified or no users found.</h3>";
        exit;
    }

    $count = 0;

    while ($user = $result->fetch_assoc()) {
        $email_token = bin2hex(random_bytes(32));
        $email_token_expires = gmdate("Y-m-d H:i:s", time() + 86400); // 24 hrs UTC

        // ✅ Update user token + expiry
        $stmt = $conn->prepare("UPDATE users SET email_token=?, email_token_expires=? WHERE id=?");
        $stmt->bind_param("ssi", $email_token, $email_token_expires, $user['id']);
        $stmt->execute();
        $stmt->close();

        // ✅ Verification link (change domain if needed)
        $verification_link = "https://accofinda.com/verifyEmail?token=" . urlencode($email_token);

        $subject = "Verify Your Accofinda Account";
        $body = "
        Hello {$user['full_name']},<br><br>
        We have added a new security feature that requires all users to verify their email addresses.<br><br>
        Please click the button below to verify your account:<br><br>
        <a href='$verification_link' 
           style='background:#007bff;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;'>
           Verify My Email
        </a><br><br>
        This link will expire in 24 hours.<br><br>
        If you have any questions, contact us at <b>support@accofinda.com</b>.<br><br>
        The Accofinda Team
        ";

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'rashidartificiali@gmail.com';
            $mail->Password   = 'uxvtarleuiaciuri'; // Gmail app password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('rashidartificiali@gmail.com', 'Accofinda');
            $mail->addAddress($user['email'], $user['full_name']);
            $mail->addReplyTo('support@accofinda.com', 'Support');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
            $mail->send();

            $count++;
        } catch (Exception $e) {
            echo "<p style='color:red;'>❌ Could not send to {$user['email']}. Error: {$mail->ErrorInfo}</p>";
        }
    }

    echo "<h3 style='color:green;'>✅ Successfully sent verification emails to $count users.</h3>";
    echo "<p><a href='sendVerificationToExisting.php' style='color:#007bff;'>← Back</a></p>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Send Verification Links | Admin</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 50px auto;
            max-width: 600px;
            text-align: center;
        }

        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 25px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background: #0056b3;
        }

        .notice {
            background: #fff8e1;
            color: #795548;
            border: 1px solid #ffe0b2;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <h2>📧 Send Verification Links to Unverified Users</h2>
    <div class="notice">
        <p>This action will send new email verification links to all users who have not yet verified their accounts.</p>
        <p><b>Use this only once</b> unless you want to reissue tokens to everyone again.</p>
    </div>
    <form method="POST" onsubmit="return confirm('Are you sure you want to send verification links to all unverified users?');">
        <button type="submit">Send Verification Emails</button>
    </form>
</body>

</html>