<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
require '../vendor/autoload.php';
require '../config.php';

// Only allow Admin access
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
    header('Location: login');
    exit();
}

$message = "";
$messageClass = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password_plain = $_POST['password'];
    $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);
    $role = $_POST['role'];

    // Auto-generate a username (first part of email if no field provided)
    $username = explode('@', $email)[0];

    // Check if email already exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $message = "❌ Email already registered!";
        $messageClass = "alert-danger";
    } else {
        // Generate email verification token
        $email_token = bin2hex(random_bytes(32));
        $email_token_expires = date("Y-m-d H:i:s", strtotime("+1 day"));

        // Insert new user (minimal required fields + token)
        $stmt = $conn->prepare("INSERT INTO users 
            (full_name, username, email, phone_number, password, role, email_verified, email_token, email_token_expires, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())");

        $stmt->bind_param(
            "ssssssss",
            $full_name,
            $username,
            $email,
            $phone,
            $password_hashed,
            $role,
            $email_token,
            $email_token_expires
        );

        if ($stmt->execute()) {
            // Verification link
            $verification_link = "https://accofinda.com/verifyEmail.php?token=" . urlencode($email_token);

            // Email content
            $subject = "Verify Your Accofinda Account";
            $message_body = "Hello $full_name,\n\n" .
                "Your account has been created with the following details:\n" .
                "Role: $role\nEmail: $email\nPassword: $password_plain\n\n" .
                "Before logging in, please verify your email address using this link:\n" .
                "$verification_link\n\n" .
                "This link expires in 24 hours.\n\n" .
                "If you did not request this, please ignore this email.\n\n" .
                "Accofinda Team";

            // Send email using PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'rashidartificiali@gmail.com';
                $mail->Password   = 'uxvtarleuiaciuri';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('rashidartificiali@gmail.com', 'Accofinda');
                $mail->addAddress($email, $full_name);
                $mail->addReplyTo('support@accofinda.com', 'Support');

                $mail->Subject = $subject;
                $mail->Body    = $message_body;
                $mail->AltBody = $message_body;

                $mail->send();
                $message = "✅ User created and verification email sent to $email.";
                $messageClass = "alert-success";
            } catch (Exception $e) {
                $message = "✅ User created, but email could not be sent. Error: {$mail->ErrorInfo}";
                $messageClass = "alert-warning";
            }
        } else {
            $message = "❌ Failed to create user.";
            $messageClass = "alert-danger";
        }

        $stmt->close();
    }

    $check->close();
}

// Fetch unique roles for dropdown
$roles = [];
$result = $conn->query("SELECT DISTINCT role FROM users ORDER BY role ASC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $roles[] = $row['role'];
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Create User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <script src="https://kit.fontawesome.com/a076d05399.js"></script>
    <style>
        /* Background gradient with subtle texture */
        body {
            background: linear-gradient(135deg, #494d4fff, #3c595cff);
            min-height: 100vh;
            align-items: center;
            font-family: 'Times New Roman', Tahoma, Geneva, Verdana, sans-serif;

        }

        /* Card styling */
        .card {
            border-radius: 20px;
            box-shadow: 0 20px 40px rgb(30 136 229 / 0.4);
            background: #ffffffdd;
            backdrop-filter: saturate(180%) blur(10px);
            padding: 2.5rem 2rem;
            transition: box-shadow 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 30px 60px rgb(30 136 229 / 0.6);
        }

        /* Heading */
        h3 {
            color: #000000ff;
            font-weight: 700;
            margin-bottom: 2rem;
            letter-spacing: 1px;
            text-shadow: 0 1px 3px rgb(28 122 71 / 0.4);
        }

        /* Form labels */
        .form-label {
            font-weight: 600;
            color: #000000ff;
            margin-bottom: 0.5rem;
            user-select: none;
        }

        /* Input fields and selects */
        .form-control {
            border-radius: 12px;

            padding: 12px 15px;
            font-size: 1rem;
            color: #2c3e50;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 5px rgb(0 0 0 / 0.07);
        }

        .form-control:focus {
            border-color: #4e73df;
            box-shadow: 0 0 8px #000000aa;
            outline: none;
            color: #000000ff;
        }

        /* Button */
        .btn-primary {
            background: linear-gradient(135deg, #000000ff, #000000ff);
            border: none;
            border-radius: 15px;
            font-weight: 700;
            font-size: 1.1rem;
            padding: 12px 0;
            box-shadow: 0 8px 15px rgb(28 200 138 / 0.5);
            transition: background 0.4s ease, box-shadow 0.4s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .container {
            max-width: 1080px;
            width: 100%;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #352002ff, #1d1e18ff);
            box-shadow: 0 12px 20px rgb(23 165 115 / 0.7);
        }

        .btn-primary i {
            font-size: 1.25rem;
        }

        /* Alert messages */
        .alert {
            border-radius: 15px;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 4px 12px rgb(0 0 0 / 0.12);
            text-align: center;
            padding: 15px 20px;
        }

        .alert-danger {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #ff0019ff;
        }

        .alert-success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }

        /* Responsive tweaks */
        @media (max-width: 576px) {
            body {
                padding: 10px;
            }

            .card {
                padding: 2rem 1.2rem;
            }
        }

        .top-navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: linear-gradient(135deg, #141414ff, #000000ff);
            box-shadow: 0 3px 8px rgb(0 0 0 / 0.15);
            padding: 0.75rem 1rem;
            z-index: 1050;
            font-weight: 700;
            user-select: none;
        }

        .top-navbar .container {
            max-width: 480px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .navbar-brand {
            color: white;
            font-size: 1.5rem;
            text-decoration: none;
            letter-spacing: 1.2px;
            text-shadow: 0 1px 3px rgb(0 0 0 / 0.25);
            transition: color 0.3s ease;
        }

        .navbar-brand:hover {
            color: #e0f7ea;
            text-decoration: none;
        }

        /* Add top margin so content is not hidden under navbar */
        .mt-navbar {
            margin-top: 60px;
            /* height of navbar + some spacing */
        }
    </style>
</head>

<body>
    <nav class="top-navbar d-flex align-items-center justify-content-between px-3">
        <button type="button" class="btn btn-light btn-sm" onclick="history.back()" title="Go Back">
            ← Back
        </button>
        <a href="#" class="navbar-brand m-0">Accofinda</a>
        <div style="width: 60px;"><!-- placeholder to balance flex --></div>
    </nav>
    <div class="container" style="padding-top: 120px; padding-bottom: 120px;">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <?php if (!empty($message)): ?>
                    <div class="alert <?php echo $messageClass; ?> text-center" id="alertMessage">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <div class="card p-4">
                    <h3 class="text-center mb-4"><i class="fa fa-user-plus text-primary"></i> Create New User</h3>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" placeholder="Enter full name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="Enter email address" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="Enter phone number" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Starting Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Enter starting password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r); ?>">
                                        <?php echo htmlspecialchars($r); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa fa-plus"></i> Create User
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Footer -->
    <footer class="bg-dark text-light text-center py-3 mt-0" style="padding-top:10px;">
        &copy; <?= date('Y'); ?> Accofinda. All rights reserved.
    </footer>
    <script>
        // Auto-hide alert message
        setTimeout(() => {
            const alertMsg = document.getElementById('alertMessage');
            if (alertMsg) {
                alertMsg.style.display = 'none';
            }
        }, 5000);

        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>

</body>

</html>