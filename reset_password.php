<?php
require 'config.php';
date_default_timezone_set('Africa/Nairobi'); 

$token = $_GET['token'] ?? '';

if (!$token) {
    die("❌ No token provided in the URL.");
}

$stmt = $conn->prepare("SELECT email, reset_expires FROM users WHERE reset_token = ? AND reset_expires > NOW()");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    $email = $user['email'];
} else {
    $debug = $conn->prepare("SELECT email, reset_expires FROM users WHERE reset_token = ?");
    $debug->bind_param("s", $token);
    $debug->execute();
    $res = $debug->get_result();

    if ($d = $res->fetch_assoc()) {
        echo "❌ Token has expired.<br>🕒 It expired at: " . $d['reset_expires'];
    } else {
        echo "❌ Invalid token. Please make sure you clicked a valid reset link.";
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<link rel="icon" href="../Images/AccofindaLogo1.png" type="image/png">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .reset-box {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
            font-weight: normal;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            font-size: 14px;
            color: #333;
        }

        input[type=password],
        input[readonly],
        button {
            width: 100%;
            padding: 12px;
            font-size: 15px;
            border-radius: 8px;
            border: 1px solid #ccc;
            margin-top: 5px;
        }

        input[readonly] {
            background-color: #f0f0f0;
            color: #666;
        }

        button {
            margin-top: 20px;
            background-color: #2c3e50;
            color: white;
            border: none;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background-color: #1e2e3f;
        }

        @media (max-width: 480px) {
            .reset-box {
                padding: 25px 20px;
                margin: 0 15px;
            }
        }
    </style>
</head>
<body>

<div class="reset-box">
    <h2>🔁 Reset Your Password</h2>
    <form method="POST" action="update_password">
        <label>Email (read-only)</label>
        <input type="text" name="email" value="<?= htmlspecialchars($email) ?>" readonly>

        <label>New Password</label>
        <input type="password" name="new_password" placeholder="Enter new password" required>

        <button type="submit">Update Password</button>
    </form>
</div>

</body>
</html>
