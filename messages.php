<?php
session_start();
require '../config.php';

// ✅ Ensure tenant logged in
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit();
}

$tenant_id = $_SESSION['user_id'];

/** ✅ Find tenant’s current booking (landlord + property + unit) **/
$booking  = null;
$landlord = null;

$bookingQuery = $conn->query("
    SELECT br.booking_id, br.unit_detail_id, pud.property_id,
           l.id AS landlord_id, l.full_name AS landlord_name
    FROM booking_requests br
    JOIN property_unit_details pud ON pud.unit_detail_id = br.unit_detail_id
    JOIN users l ON l.id = br.owner_id
    WHERE br.tenant_id = $tenant_id
      AND br.status = 'approved'
    LIMIT 1
");

if ($bookingQuery && $bookingQuery->num_rows > 0) {
    $booking  = $bookingQuery->fetch_assoc();
    $landlord = [
        "id"        => $booking['landlord_id'],
        "full_name" => $booking['landlord_name']
    ];
}

// ✅ Track alerts
$send_success   = false;
$cancel_success = false;
$error_message  = null;

/** ✅ Handle cancelling message **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_message_id'])) {
    $cancel_id = intval($_POST['cancel_message_id']);
    $check = $conn->query("SELECT * FROM messages WHERE message_id = $cancel_id AND sender_id = $tenant_id AND status = 'unread'");
    if ($check && $check->num_rows > 0) {
        $conn->query("DELETE FROM messages WHERE message_id = $cancel_id");
        $cancel_success = true;
    }
}

/** ✅ Handle sending message **/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $receiver_role = $_POST['receiver_role'];
    $receiver_id   = !empty($_POST['receiver_id']) ? intval($_POST['receiver_id']) : null;
    $subject       = trim($_POST['subject']);
    $message_text  = trim($_POST['message_text']);

    $property_id    = $booking ? $booking['property_id'] : null;
    $unit_detail_id = $booking ? $booking['unit_detail_id'] : null;

    // 🚨 Security check: only allow landlord if tenant has valid booking
    if ($receiver_role === "landlord" && !$booking) {
        $error_message = "You cannot message a landlord unless you have an approved booking.";
    } else {
        $attachment = null;
        if (!empty($_FILES['attachment']['name'])) {
            $targetDir  = "../uploads/";
            $attachment = time() . "_" . basename($_FILES["attachment"]["name"]);
            move_uploaded_file($_FILES["attachment"]["tmp_name"], $targetDir . $attachment);
        }

        $stmt = $conn->prepare("INSERT INTO messages 
            (sender_id, receiver_id, property_id, unit_detail_id, subject, message_text, attachment, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'unread', NOW(), NOW())");

        $stmt->bind_param(
            "iiiisss",
            $tenant_id,
            $receiver_id,
            $property_id,
            $unit_detail_id,
            $subject,
            $message_text,
            $attachment
        );

        $stmt->execute();
        $send_success = true;
    }
}

/** ✅ Fetch tenant messages **/
$messages = $conn->query("
    SELECT m.*, u.full_name AS sender_name, u.role AS sender_role 
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    WHERE m.sender_id = $tenant_id 
       OR m.receiver_id = $tenant_id 
    ORDER BY m.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Tenant Messages</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding-top: 70px;
            padding-bottom: 70px;
        }

        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
        }

        .inbox-table-container {
            max-height: 400px;
            overflow-y: auto;
        }

        .inbox-table thead {
            position: sticky;
            top: 0;
            background-color: #343a40;
            color: white;
        }
    </style>
</head>

<body class="bg-light">

    <!-- ✅ Top Navigation -->
    <nav class="navbar navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="#">Accofinda</a>
        </div>
    </nav>

    <div class="container">
        <!-- ✅ Heading -->
        <h3 class="text-center fw-bold mb-4">Direct Messaging</h3>

        <!-- ✅ Success & Cancel Alerts -->
        <?php if ($send_success): ?>
            <div class="alert alert-success text-center" id="successAlert">
                Message sent successfully!
            </div>
        <?php elseif ($cancel_success): ?>
            <div class="alert alert-warning text-center" id="cancelAlert">
                Message cancelled successfully.
            </div>
        <?php endif; ?>

        <!-- ✅ Send Message Form -->
        <div class="card shadow-sm mb-4 col-md-8 offset-md-2">
            <div class="card-header bg-primary text-white fw-bold">Send Message</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Send To</label>
                        <select name="receiver_role" class="form-select form-select-sm" required>
                            <option value="">Select Receiver</option>
                            <option value="admin" data-id="0">Admin</option>
                            <?php if ($landlord): ?>
                                <option value="landlord" data-id="<?= $landlord['id'] ?>">
                                    <?= $landlord['full_name'] ?> (Landlord)
                                </option>
                            <?php endif; ?>
                        </select>
                        <input type="hidden" name="receiver_id" id="receiver_id">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="message_text" class="form-control form-control-sm" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Attachment</label>
                        <input type="file" name="attachment" class="form-control form-control-sm">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="send" class="btn btn-success btn-sm ">Send</button>
                    </div>

                </form>
            </div>
        </div>
        <!-- ✅ Messages Inbox as Table -->
        <div class="card shadow-sm col-md-10 offset-md-1">
            <div class="card-header bg-dark text-white fw-bold">Your Messages</div>
            <div class="card-body inbox-table-container">
                <table class="table table-striped table-hover inbox-table table-sm" style="font-size: 0.80rem;">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>From</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Message</th>
                            <th>Attachment</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($msg = $messages->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($msg['subject']) ?></td>
                                <td><?= htmlspecialchars($msg['sender_name'] ?? 'System') ?>
                                    (<?= ucfirst($msg['sender_role'] ?? 'Admin') ?>)</td>
                                <td><?= date("M d, Y H:i", strtotime($msg['created_at'])) ?></td>
                                <td>
                                    <span class="badge <?= $msg['status'] == 'unread' ? 'bg-warning text-dark' : 'bg-success' ?>">
                                        <?= ucfirst($msg['status']) ?>
                                    </span>
                                </td>
                                <td><?= nl2br(htmlspecialchars($msg['message_text'])) ?></td>
                                <td>
                                    <?php if ($msg['attachment']): ?>
                                        <a href="../uploads/<?= $msg['attachment'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($msg['sender_id'] == $tenant_id && $msg['status'] == 'unread'): ?>
                                        <form method="post">
                                            <input type="hidden" name="cancel_message_id" value="<?= $msg['message_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ✅ Sticky Footer -->
        <footer class="bg-dark text-white text-center py-2">
            &copy; <?= date('Y') ?> Accofinda. Rashid Dev
        </footer>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            // JS to set landlord id when selected
            document.querySelector("select[name='receiver_role']").addEventListener("change", function() {
                let option = this.selectedOptions[0];
                document.getElementById("receiver_id").value = option.dataset.id ?? "";
            });

            // Auto hide success/cancel messages after 5s
            setTimeout(() => {
                let success = document.getElementById("successAlert");
                let cancel = document.getElementById("cancelAlert");
                if (success) success.style.display = "none";
                if (cancel) cancel.style.display = "none";
            }, 5000);
        </script>
</body>

</html>