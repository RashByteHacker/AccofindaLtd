<?php
session_start();
require '../config.php';

// Allow only landlord and admin
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin'])) {
    header("Location: login.php");
    exit();
}

$loggedInEmail = $_SESSION['email'];
$userRole = strtolower($_SESSION['role']);

// Initialize message variables
$message = "";
$messageClass = "";

// Handle POST request for deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['property_id'])) {
    $property_id = intval($_POST['property_id']);

    if ($property_id <= 0) {
        $message = "Invalid property ID.";
        $messageClass = "danger";
    } else {
        $authorized = false;

        if ($userRole === 'admin') {
            // Admins can delete any property
            $authorized = true;
        } else {
            // Verify property ownership for landlord
            $stmt = $conn->prepare("SELECT property_id FROM properties WHERE property_id = ? AND landlord_email = ?");
            $stmt->bind_param("is", $property_id, $loggedInEmail);
            $stmt->execute();
            $stmt->store_result();

            $authorized = $stmt->num_rows > 0;
            $stmt->close();
        }

        if (!$authorized) {
            $message = "Property not found or you do not have permission to delete it.";
            $messageClass = "danger";
        } else {
            $conn->begin_transaction();

            try {
                // 🔹 Delete unit images first
                $stmt = $conn->prepare("
                    DELETE ui FROM unit_images ui
                    JOIN property_units pu ON ui.unit_id = pu.unit_id
                    WHERE pu.property_id = ?
                ");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                // Delete property unit details
                $stmt = $conn->prepare("DELETE FROM property_unit_details WHERE property_id = ?");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                // Delete related units
                $stmt = $conn->prepare("DELETE FROM property_units WHERE property_id = ?");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                // Delete related leases
                $stmt = $conn->prepare("DELETE FROM leases WHERE property_id = ?");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                // Delete related applications
                $stmt = $conn->prepare("DELETE FROM applications WHERE property_id = ?");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                // Delete rent payments linked to rental agreements
                $stmt = $conn->prepare("
                    DELETE rp FROM rent_payments rp
                    JOIN rental_agreements ra ON rp.agreement_id = ra.agreement_id
                    WHERE ra.property_id = ?
                ");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                // Finally delete the property
                $stmt = $conn->prepare("DELETE FROM properties WHERE property_id = ?");
                $stmt->bind_param("i", $property_id);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $message = "Property and all associated data (including unit images) deleted successfully.";
                $messageClass = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error deleting property: " . $e->getMessage();
                $messageClass = "danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Delete Property</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <style>
        #flashMessage {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1050;
            min-width: 250px;
        }
    </style>
</head>

<body>

    <?php if ($message): ?>
        <div id="flashMessage" class="alert alert-<?= htmlspecialchars($messageClass) ?>" role="alert">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Your existing page content goes here -->

    <script>
        // Auto-hide the flash message after 4 seconds
        window.addEventListener('DOMContentLoaded', () => {
            const flash = document.getElementById('flashMessage');
            if (flash) {
                setTimeout(() => {
                    flash.style.transition = 'opacity 0.5s ease';
                    flash.style.opacity = '0';
                    setTimeout(() => flash.remove(), 500);
                }, 4000);
            }
        });
    </script>

</body>

</html>