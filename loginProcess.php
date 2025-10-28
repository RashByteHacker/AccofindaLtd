<?php
session_start();
require 'config.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // ✅ Step 1: Check if email is verified
            if (empty($user['email_verified']) || $user['email_verified'] == 0) {
                $_SESSION['error_html'] = '
                    <div style="text-align:center; font-size:0.95rem; line-height:1.5;">
                        <strong>Email Not Verified!</strong> Please check your inbox for the verification link. 
                        If you did not receive it, you can resend below. 
                        <a href="resendVerification.php?email=' . urlencode($email) . '" 
                           class="btn btn-sm btn-primary"
                           style="display:inline-block; margin-left:6px; background-color:#1d3557; border:none; border-radius:6px; padding:4px 10px; font-size:0.82rem;">
                           <i class="fa fa-envelope"></i> Resend
                        </a>
                    </div>
                ';
                header("Location: login");
                exit();
            }

            // ✅ Step 2: Verify password
            if (password_verify($password, $user['password'])) {
                // ✅ Step 3: Set session variables
                $_SESSION['id']        = $user['id'];
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['role']      = strtolower($user['role']);

                // ✅ Step 4: Check Terms acceptance
                if (empty($user['terms_accepted']) || $user['terms_accepted'] == 0) {
                    header("Location: termsAndConditions");
                    exit();
                }

                // ✅ Step 5: Redirect based on role
                switch ($_SESSION['role']) {
                    case 'admin':
                        header("Location: Admins/adminDashboard");
                        break;
                    case 'manager':
                        header("Location: Managers/manager");
                        break;
                    case 'landlord':
                        header("Location: Landlords/landlord");
                        break;
                    case 'tenant':
                        header("Location: Tenants/tenant");
                        break;
                    case 'service provider':
                        header("Location: ServiceProviders/serviceProvider");
                        break;
                    case 'property owner':
                        header("Location: dashboard/propertyOwner");
                        break;
                    default:
                        header("Location: dashboard/general");
                        break;
                }
                exit();
            } else {
                $_SESSION['error_html'] = '
                    <div style="text-align:center; font-size:0.95rem;">
                        <i class="fa fa-lock"></i> Incorrect password. Please try again.
                    </div>
                ';
            }
        } else {
            $_SESSION['error_html'] = '
                <div style="text-align:center; font-size:0.95rem;">
                    <i class="fa fa-user-times"></i> No account found with that email.
                </div>
            ';
        }
    } else {
        $_SESSION['error_html'] = '
            <div style="text-align:center; font-size:0.95rem;">
                <i class="fa fa-exclamation-circle"></i> Please enter both email and password.
            </div>
        ';
    }
}

// Redirect back to login if failed
header("Location: login");
exit();
?>