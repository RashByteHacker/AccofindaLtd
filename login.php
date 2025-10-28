<?php
session_start();
date_default_timezone_set("Africa/Nairobi");
require 'config.php';

// ✅ Collect all possible messages
$successMessage = $_SESSION['success'] ?? $_SESSION['success_html'] ?? '';
$errorMessage   = $_SESSION['error']   ?? $_SESSION['error_html']   ?? '';

// Clear messages so they don’t persist
unset($_SESSION['success'], $_SESSION['success_html'], $_SESSION['error'], $_SESSION['error_html']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accofinda | Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        body {
            background: linear-gradient(135deg, #535254ff, #56655dff);
            font-family: 'Segoe UI', sans-serif;
            padding-top: 56px;
        }

        .login-card {
            max-width: 380px;
            margin: 2rem auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            opacity: 0;
            transform: translateY(25px);
            transition: opacity 0.7s ease, transform 0.7s ease;
        }

        .login-card.show {
            opacity: 1;
            transform: translateY(0);
        }

        .login-header {
            background-color: #383938ff;
            color: #fff;
            padding: 20px;
            text-align: center;
        }

        .login-header h4 {
            margin: 0;
            font-weight: 600;
        }

        .login-header p {
            margin: 5px 0 0;
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .form-control {
            padding-left: 40px;
            border: 1px solid #ccc;
            border-radius: 6px;
            transition: 0.3s ease;
        }

        .form-control:focus {
            border-color: #4d524dff;
            box-shadow: 0 0 5px rgba(77, 82, 77, 0.5);
        }

        .input-icon {
            position: absolute;
            top: 10px;
            left: 12px;
            color: #000000ff;
        }

        .btn-green {
            background-color: #364458ff;
            color: #fff;
            font-weight: 500;
            border-radius: 6px;
        }

        .btn-green:hover {
            background-color: #353e47ff;
            color: #fff;
        }

        .login-footer {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            margin-top: 10px;
        }

        .navbar-brand {
            font-weight: bold;
            font-size: 1.3rem;
            color: white !important;
        }

        .custom-alert {
            text-align: center;
            font-size: 0.9rem;
            line-height: 1.4;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 10px 12px;
            word-wrap: break-word;
        }

        .alert-success {
            background-color: #d1e7dd;
            color: #0f5132;
            border-left: 5px solid #0f5132;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #842029;
            border-left: 5px solid #842029;
        }

        .custom-alert a.btn-sm {
            display: inline-block;
            margin-left: 6px;
            background-color: #1d3557;
            border: none;
            border-radius: 5px;
            padding: 3px 8px;
            font-size: 0.8rem;
            color: #fff;
            text-decoration: none;
        }

        .custom-alert a.btn-sm:hover {
            background-color: #0b243f;
        }

        @media (max-width: 576px) {

            body,
            html {
                height: 100%;
            }

            body {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0.5rem;
            }

            .login-card {
                margin: 0;
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark fixed-top" style="background-color: #020202ff;">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fa fa-home"></i> Accofinda
            </a>
            <button class="btn btn-danger btn-sm">
                <i class="fa fa-plug"></i> Offline
            </button>
        </div>
    </nav>

    <!-- Login Card -->
    <div class="login-card">
        <div class="login-header">
            <i class="fa fa-user fa-2x mb-2"></i>
            <h4>Accofinda</h4>
            <p>Welcome back! Please sign in to your account</p>
        </div>

        <div class="p-4">
            <!-- ✅ Show all possible messages -->
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success custom-alert py-2" id="loginAlert">
                    <i class="fa fa-check-circle"></i>
                    <?= $successMessage; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger custom-alert py-2" id="loginAlert">
                    <i class="fa fa-exclamation-circle"></i>
                    <?= $errorMessage; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="loginProcess">
                <div class="mb-3 position-relative">
                    <i class="fa fa-envelope input-icon"></i>
                    <input type="email" name="email" class="form-control" placeholder="Enter your email" required>
                </div>
                <div class="mb-3 position-relative">
                    <i class="fa fa-lock input-icon"></i>
                    <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="btn btn-green w-100 mb-2">
                    <i class="fa fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="login-footer">
                <a href="register" class="btn btn-success btn-sm text-white">
                    <i class="fa fa-user-plus"></i> Sign Up
                </a>
                <a href="forgot_password" class="btn btn-dark btn-sm text-white">
                    <i class="fa fa-key"></i> Forgot password?
                </a>
            </div>
        </div>
    </div>

    <script>
        // Fade-in card animation
        document.addEventListener('DOMContentLoaded', function() {
            const loginCard = document.querySelector('.login-card');
            if (loginCard) setTimeout(() => loginCard.classList.add('show'), 100);
        });

        // Auto-hide alert after 8s
        setTimeout(() => {
            const alertBox = document.getElementById('loginAlert');
            if (alertBox) {
                alertBox.style.transition = "opacity 0.5s ease";
                alertBox.style.opacity = "0";
                setTimeout(() => alertBox.remove(), 500);
            }
        }, 8000);
    </script>
</body>

</html>