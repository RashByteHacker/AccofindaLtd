<?php
session_start();
require 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// ✅ Always use UTC for consistency
date_default_timezone_set("UTC");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // ✅ Basic validations
    if (empty($full_name) || empty($email) || empty($phone) || empty($password) || empty($confirm_password)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: register");
        exit();
    }

    // ✅ Full name validation (letters and spaces only)
    if (!preg_match("/^[a-zA-Z ]{3,50}$/", $full_name)) {
        $_SESSION['error'] = "Full name must be 3-50 characters long and contain only letters and spaces.";
        header("Location: register");
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
        header("Location: register");
        exit();
    }

    if (!preg_match("/^[0-9]{7,15}$/", $phone)) {
        $_SESSION['error'] = "Invalid phone number format.";
        header("Location: register");
        exit();
    }

    // ✅ Strong Password Check
    $passwordPattern = "/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]{8,}$/";
    if (!preg_match($passwordPattern, $password)) {
        $_SESSION['error'] = "Password must be at least 8 characters long, include upper & lowercase letters, a number, and a special character.";
        header("Location: register");
        exit();
    }

    if ($password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match.";
        header("Location: register");
        exit();
    }

    // ✅ Check if email exists
    $stmt = $conn->prepare("SELECT id, email_verified FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing) {
        if ($existing['email_verified'] == 0) {
            $_SESSION['error'] = "Email already registered but not verified. Please check your inbox or request a new verification link.";
        } else {
            $_SESSION['error'] = "Email is already registered. Please log in.";
        }
        header("Location: register");
        exit();
    }

    // ✅ Create verification token (unique, secure)
    $token = bin2hex(random_bytes(32));
    $token_expires = time() + 86400; // 24 hours
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $role = "Tenant";

    // ✅ Insert new user
    $stmt = $conn->prepare("
        INSERT INTO users (full_name, email, phone_number, password, role, email_verified, email_token, email_token_expires)
        VALUES (?, ?, ?, ?, ?, 0, ?, ?)
    ");
    $stmt->bind_param("ssssssi", $full_name, $email, $phone, $hashed_password, $role, $token, $token_expires);

    if ($stmt->execute()) {
        // ✅ Build verification link (ensure correct path)
        $baseURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
            "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/');
        $verifyLink = $baseURL . "/verifyEmail.php?email=" . urlencode($email) . "&token=" . urlencode($token);

        // ✅ Styled HTML email body
        $subject = "Verify Your Accofinda Account";
        $message_body = "
<html>
<body style='font-family: Arial, sans-serif; background-color:#f4f4f4; margin:0; padding:0;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; margin:40px auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.1);'>
        <tr>
            <td style='background:#364458; padding:20px; color:white; text-align:left;'>
                <h2 style='margin:0; font-weight:600; font-size:1.5rem;'>Accofinda</h2>
                <p style='margin:5px 0 0; font-size:0.95rem; opacity:0.85;'>Verify Your Account</p>
            </td>
        </tr>
        <tr>
            <td style='padding:30px; text-align:left; color:#333;'>
                <p style='font-size:1rem;'>Hello <strong>$full_name</strong>,</p>
                <p style='font-size:0.95rem; color:#555; margin:15px 0;'>
                    Please click the button below to verify your email address and activate your account:
                </p>
                <a href='$verifyLink' style='display:inline-block; padding:12px 25px; background:#0d6efd; color:#fff; text-decoration:none; border-radius:6px; font-weight:500; margin:15px 0;'>Verify Email</a>
                <p style='font-size:0.85rem; color:#777; margin:20px 0 5px;'>If the button doesn't work, copy and paste this link into your browser:</p>
                <p style='word-break:break-word; color:#0d6efd; font-size:0.9rem;'><a href='$verifyLink' style='color:#0d6efd; text-decoration:none;'>$verifyLink</a></p>
                <p style='font-size:0.85rem; color:#999; margin-top:25px;'>This link will expire in 24 hours for your security.</p>
            </td>
        </tr>
        <tr>
            <td style='background:#f4f4f4; padding:15px; text-align:left; font-size:0.8rem; color:#555;'>
                &copy; " . date('Y') . " Accofinda. All rights reserved.
            </td>
        </tr>
    </table>
</body>
</html>";


        // ✅ Send verification email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.hostinger.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'support@accofinda.com';
            $mail->Password   = 'AccofindaMail2024!';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            // --- From & Recipient ---
            $mail->setFrom('support@accofinda.com', 'Accofinda Support');
            $mail->addAddress($email, $full_name);
            $mail->addReplyTo('support@accofinda.com', 'Support');

            // --- Email content ---
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $message_body;
            $mail->AltBody = strip_tags($message_body);
            $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error: " . $mail->ErrorInfo);
        }

        $_SESSION['success'] = "Registration successful! Please check your email to verify your account.";
        header("Location: register");
        exit();
    } else {
        $_SESSION['error'] = "Something went wrong. Please try again.";
        header("Location: register");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accofinda | Tenant Sign Up</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #34445dff, #4e7158ff);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }

        .register-card {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            padding: 2rem;
            width: 100%;
            max-width: 450px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 1rem;
        }

        .register-header h4 {
            font-weight: bold;
        }

        .form-control {
            border-radius: 10px;
        }

        .btn-primary {
            border-radius: 10px;
            font-weight: bold;
        }

        /* Password strength bar + name validation messages */
        #password-strength {
            height: 6px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }

        #password-strength>div {
            height: 100%;
            width: 0%;
            transition: width 0.25s ease;
        }

        .strength-weak {
            background: #dc3545;
        }

        /* red */
        .strength-medium {
            background: #fd7e14;
        }

        /* orange */
        .strength-strong {
            background: #198754;
        }

        /* green */

        .field-hint {
            font-size: 0.85rem;
            margin-top: 6px;
            display: block;
        }

        .hint-ok {
            color: #198754;
        }

        .hint-bad {
            color: #dc3545;
        }

        .name-valid-indicator {
            font-size: 0.85rem;
            margin-top: 6px;
            display: block;
        }
    </style>
</head>

<body>

    <div class="register-card">
        <div class="register-header">
            <i class="fa fa-home fa-3x text-primary mb-2"></i>
            <h4>Accofinda Tenant Registration</h4>
            <p class="text-muted">Find your perfect home easily</p>
        </div>

        <?php
        if (isset($_SESSION['error'])) {
            echo "<div class='alert alert-danger'>" . $_SESSION['error'] . "</div>";
            unset($_SESSION['error']);
        }
        if (isset($_SESSION['success'])) {
            echo "<div class='alert alert-success'>" . $_SESSION['success'] . "</div>";
            unset($_SESSION['success']);
        }
        ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label"><i class="fa fa-user"></i> Full Name</label>
                <input type="text" id="full_name" name="full_name" class="form-control" required>
                <span id="nameIndicator" class="name-valid-indicator"></span>
            </div>
            <div class="mb-3">
                <label class="form-label"><i class="fa fa-envelope"></i> Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label"><i class="fa fa-phone"></i> Phone Number</label>
                <input type="text" name="phone" class="form-control" placeholder="0712345678" required>
            </div>
            <div class="mb-3">
                <label class="form-label"><i class="fa fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="********" required>
                <div id="password-strength">
                    <div></div>
                </div>
                <small id="passwordHelp" class="field-hint text-muted">Must be 8+ characters, include uppercase, lowercase, number & symbol.</small>
                <span id="passwordIndicator" class="field-hint"></span>
            </div>
            <div class="mb-3">
                <label class="form-label"><i class="fa fa-lock"></i> Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="********" required>
                <span id="confirmIndicator" class="field-hint"></span>
            </div>
            <button type="submit" id="submitBtn" class="btn btn-primary w-100 mt-3"><i class="fa fa-user-plus"></i> Sign Up</button>
        </form>

        <div class="text-center mt-3">
            <p class="mb-0 text-muted">
                Already have an account?
                <a href="login.php" class="btn btn-dark btn-sm text-white px-3">
                    <i class="fa fa-sign-in-alt"></i> Log In
                </a>
            </p>
        </div>
    </div>

    <script>
        // Name realtime validation
        (function() {
            const nameInput = document.getElementById('full_name');
            const nameIndicator = document.getElementById('nameIndicator');
            const nameRegex = /^[a-zA-Z ]{3,50}$/;

            function validateName() {
                const v = nameInput.value.trim();
                if (v.length === 0) {
                    nameIndicator.textContent = '';
                    nameIndicator.className = 'name-valid-indicator';
                    return true;
                }
                if (!/^[A-Za-z\s]+$/.test(v)) {
                    nameIndicator.textContent = 'Name must contain only letters and spaces.';
                    nameIndicator.className = 'name-valid-indicator hint-bad';
                    return false;
                }
                if (v.length < 3) {
                    nameIndicator.textContent = 'Name is too short (min 3 characters).';
                    nameIndicator.className = 'name-valid-indicator hint-bad';
                    return false;
                }
                if (v.length > 50) {
                    nameIndicator.textContent = 'Name is too long (max 50 characters).';
                    nameIndicator.className = 'name-valid-indicator hint-bad';
                    return false;
                }
                if (!nameRegex.test(v)) {
                    nameIndicator.textContent = 'Invalid name format.';
                    nameIndicator.className = 'name-valid-indicator hint-bad';
                    return false;
                }
                nameIndicator.textContent = 'Looks good ✅';
                nameIndicator.className = 'name-valid-indicator hint-ok';
                return true;
            }

            nameInput.addEventListener('input', function() {
                validateName();
            });

            // expose for other checks
            window._validateName = validateName;
        })();

        // Password strength & match check
        (function() {
            const pwd = document.getElementById('password');
            const pwdHelp = document.getElementById('passwordHelp');
            const pwdBar = document.querySelector('#password-strength > div');
            const pwdIndicator = document.getElementById('passwordIndicator');
            const confirm = document.getElementById('confirm_password');
            const confirmIndicator = document.getElementById('confirmIndicator');
            const submitBtn = document.getElementById('submitBtn');

            function evaluatePasswordStrength(value) {
                let score = 0;
                if (value.length >= 8) score++;
                if (/[A-Z]/.test(value)) score++;
                if (/[a-z]/.test(value)) score++;
                if (/\d/.test(value)) score++;
                if (/[@$!%*?&#]/.test(value)) score++;
                return score; // 0-5
            }

            function updatePasswordUI() {
                const v = pwd.value;
                const score = evaluatePasswordStrength(v);

                // update bar
                if (score <= 2) {
                    pwdBar.style.width = '25%';
                    pwdBar.className = 'strength-weak';
                    pwdIndicator.textContent = 'Weak password';
                    pwdIndicator.className = 'field-hint hint-bad';
                } else if (score === 3 || score === 4) {
                    pwdBar.style.width = '65%';
                    pwdBar.className = 'strength-medium';
                    pwdIndicator.textContent = 'Medium strength';
                    pwdIndicator.className = 'field-hint hint-bad';
                } else if (score === 5) {
                    pwdBar.style.width = '100%';
                    pwdBar.className = 'strength-strong';
                    pwdIndicator.textContent = 'Strong password';
                    pwdIndicator.className = 'field-hint hint-ok';
                } else {
                    pwdBar.style.width = '0%';
                    pwdBar.className = '';
                    pwdIndicator.textContent = '';
                    pwdIndicator.className = 'field-hint';
                }

                // confirm match check (live)
                updateConfirmUI();
                updateSubmitState();
            }

            function updateConfirmUI() {
                const v = pwd.value;
                const c = confirm.value;
                if (c.length === 0) {
                    confirmIndicator.textContent = '';
                    confirmIndicator.className = 'field-hint';
                    return;
                }
                if (v === c) {
                    confirmIndicator.textContent = 'Passwords match ✅';
                    confirmIndicator.className = 'field-hint hint-ok';
                } else {
                    confirmIndicator.textContent = 'Passwords do not match';
                    confirmIndicator.className = 'field-hint hint-bad';
                }
            }

            function updateSubmitState() {
                // Check client-side validity before allowing submission (visual only)
                const nameOk = window._validateName ? window._validateName() : true;
                const pwdScore = evaluatePasswordStrength(pwd.value);
                const pwdOk = pwdScore === 5; // require full strength for enabling button (you can relax to >=3)
                const matchOk = pwd.value && (pwd.value === confirm.value);

                // We won't prevent server-side validation, but we can disable submit to nudge user
                if (nameOk && pwdOk && matchOk) {
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = false; // keep enabled so server still enforces rules; change to true to strictly block
                    // If you want to strictly block weak submissions client-side, set to true above.
                }
            }

            pwd.addEventListener('input', updatePasswordUI);
            confirm.addEventListener('input', updateConfirmUI);
            pwd.addEventListener('input', updateSubmitState);
            confirm.addEventListener('input', updateSubmitState);
            document.getElementById('full_name').addEventListener('input', updateSubmitState);

            // initialize visual state on load if browser autofills
            document.addEventListener('DOMContentLoaded', function() {
                updatePasswordUI();
                updateConfirmUI();
                updateSubmitState();
            });
        })();

        // Optional: prevent form submission if client-side checks fail (keeps server-side as primary)
        (function() {
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                // run client checks
                const nameValid = window._validateName ? window._validateName() : true;

                // password robustness (same regex as server)
                const pwd = document.getElementById('password').value;
                const pwdPattern = /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]{8,}$/;
                const pwdValid = pwdPattern.test(pwd);
                const confirm = document.getElementById('confirm_password').value;

                if (!nameValid || !pwdValid || pwd !== confirm) {
                    e.preventDefault();
                    alert('Please fix the highlighted fields before submitting the form.');
                    return false;
                }
                // otherwise allow submit; server-side validation will still run
            });
        })();
    </script>

</body>

</html>
