<?php
session_start();
require '../config.php';

// Restrict only logged-in users
if (!isset($_SESSION['email'])) {
    header("Location: ../login.php");
    exit();
}

$userEmail = $_SESSION['email'];
$userRole  = ucfirst($_SESSION['role']);

// Fetch user info
$stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE email = ?");
$stmt->bind_param("s", $userEmail);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("User not found.");
}

$userId   = $user['id'];
$userName = $user['full_name'];
$userRole = ucfirst($user['role']);

$successMsg = $errorMsg = "";

// Delete request
if (isset($_GET['delete'])) {
    $msgId = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM support_messages WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $msgId, $userId);
    $stmt->execute();
    $stmt->close();
    header("Location: support.php?deleted=1");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $fileName = null;

    // Handle file upload (optional)
    if (!empty($_FILES['attachment']['name'])) {
        $targetDir = "../uploads/support/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $fileName = time() . "_" . basename($_FILES["attachment"]["name"]);
        $targetFile = $targetDir . $fileName;
        if (!move_uploaded_file($_FILES["attachment"]["tmp_name"], $targetFile)) {
            $errorMsg = "Failed to upload file.";
        }
    }

    if (!$errorMsg) {
        $status = "pending"; // default status
        $stmt = $conn->prepare("INSERT INTO support_messages (user_id, user_role, subject, message, attachment, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $userId, $userRole, $subject, $message, $fileName, $status);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: support.php?success=1");
            exit();
        } else {
            $errorMsg = "Error sending message. Please try again.";
        }
    }
}

// Show success message on redirect
if (isset($_GET['success'])) {
    $successMsg = "Your message has been sent successfully! (Status: Pending)";
}
if (isset($_GET['deleted'])) {
    $successMsg = "Message deleted successfully.";
}

// Fetch user messages
$stmt = $conn->prepare("SELECT id, subject, message, status, admin_response, created_at 
                        FROM support_messages 
                        WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$messagesResult = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Support - Accofinda</title>
    <link rel="icon" type="image/jpg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            font-family: 'Segoe UI', sans-serif;
        }

        .navbar {
            background: linear-gradient(90deg, #334047, #515652);
        }

        .contact-options .card {
            border-radius: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .contact-options .card:hover {
            transform: translateY(-5px);
            box-shadow: 0px 8px 20px rgba(0, 0, 0, 0.15);
        }

        .form-section {
            background: #fff;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.08);
        }

        .alert {
            border-radius: 12px;
        }

        .hover-card {
            border-radius: 15px;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }

        .hover-card:hover {
            transform: translateY(-6px);
            box-shadow: 0px 10px 25px rgba(0, 0, 0, 0.3);
        }

        .text-muted {
            color: #adb5bd !important;
        }

        .messages-section {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            margin-top: 30px;
            box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.08);
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark px-3">
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="../Images/AccofindaLogo1.jpg" alt="Logo" width="35" height="35" class="me-2">
            <span>Accofinda Support</span>
        </a>
        <div class="text-white">
            <i class="fa fa-user-circle me-1"></i> <?= htmlspecialchars($userName) ?> (<?= htmlspecialchars($userRole) ?>)
        </div>
    </nav>

    <div class="container my-4">
        <h3 class="text-center mb-4">How would you like to reach us?</h3>

        <!-- Contact Options -->
        <div class="row contact-options mb-5">
            <!-- Direct Message First -->
            <div class="col-md-4">
                <div class="card text-center p-4 bg-dark text-white shadow hover-card">
                    <i class="fa fa-comments fa-2x text-warning mb-2"></i>
                    <h5>Send Direct Message</h5>
                    <p class="text-muted">Use the form below</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card text-center p-4 bg-dark text-white shadow hover-card" onclick="window.location.href='mailto:support@accofinda.com'">
                    <i class="fa fa-envelope fa-2x text-info mb-2"></i>
                    <h5>Email Us</h5>
                    <p class="text-muted">support@accofinda.com</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card text-center p-4 bg-dark text-white shadow hover-card" onclick="window.location.href='tel:+15551234567'">
                    <i class="fa fa-phone fa-2x text-success mb-2"></i>
                    <h5>Call Us</h5>
                    <p class="text-muted">+1 (555) 123-4567</p>
                </div>
            </div>
        </div>

        <!-- Direct Message Form -->
        <div id="directMessageForm" class="form-section">
            <h5 class="mb-3"><i class="fa fa-paper-plane me-2"></i> Send Us a Message</h5>

            <?php if ($successMsg): ?>
                <div class="alert alert-success" id="successAlert"><?= $successMsg ?></div>
            <?php elseif ($errorMsg): ?>
                <div class="alert alert-danger"><?= $errorMsg ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="4" required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Attachment (optional)</label>
                    <input type="file" name="attachment" class="form-control">
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-paper-plane me-1"></i> Submit
                </button>
            </form>
        </div>

        <!-- Sent Messages -->
        <div class="messages-section">
            <h5 class="mb-3"><i class="fa fa-inbox me-2"></i> Your Messages</h5>
            <?php if ($messagesResult->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>Subject</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Admin Response</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $messagesResult->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['subject']) ?></td>
                                    <td><?= htmlspecialchars($row['message']) ?></td>
                                    <td><span class="badge bg-<?= $row['status'] == 'pending' ? 'warning' : 'success' ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span></td>
                                    <td><?= $row['admin_response'] ? htmlspecialchars($row['admin_response']) : '<em>No response yet</em>' ?></td>
                                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                                    <td>
                                        <a href="?delete=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this message?')">
                                            <i class="fa fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted">No messages sent yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-hide success message
        setTimeout(() => {
            let alertBox = document.getElementById("successAlert");
            if (alertBox) {
                alertBox.style.transition = "opacity 0.5s";
                alertBox.style.opacity = "0";
                setTimeout(() => alertBox.remove(), 500);
            }
        }, 6000);
    </script>
</body>

</html>