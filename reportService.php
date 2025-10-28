<?php
session_start();
require '../config.php';

// ✅ Access control: only logged-in users
$allowedRoles = ['admin', 'service provider', 'landlord', 'manager', 'tenant', 'client'];
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['id'];
$successMsg = "";

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $service_id = intval($_POST['service_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if ($service_id && $subject && $details) {
        // Get provider_id for this service
        $provStmt = $conn->prepare("SELECT provider_id FROM provider_services WHERE id = ?");
        $provStmt->bind_param("i", $service_id);
        $provStmt->execute();
        $provRes = $provStmt->get_result();
        $provRow = $provRes->fetch_assoc();
        $provider_id = $provRow ? $provRow['provider_id'] : null;
        $provStmt->close();

        if ($provider_id) {
            $stmt = $conn->prepare("
                INSERT INTO service_reports (service_id, reporter_id, provider_id, subject, details, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
            ");
            $stmt->bind_param("iiiss", $service_id, $user_id, $provider_id, $subject, $details);
            if ($stmt->execute()) {
                $successMsg = "Report submitted successfully!";
            }
            $stmt->close();
        }
    }
}

// Fetch previously reported services
$reports = [];
$res = $conn->prepare("
    SELECT r.id, s.title AS service_title, u.full_name AS provider_name, 
           r.subject, r.details, r.status, r.created_at
    FROM service_reports r
    JOIN provider_services s ON r.service_id = s.id
    JOIN users u ON r.provider_id = u.id
    WHERE r.reporter_id = ?
    ORDER BY r.created_at DESC
");
$res->bind_param("i", $user_id);
$res->execute();
$result = $res->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$res->close();

// Fetch available services for reporting
$services = [];
$svcRes = $conn->query("SELECT id, title FROM provider_services WHERE status='active'");
while ($row = $svcRes->fetch_assoc()) {
    $services[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Report a Service</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #9b9c9cff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #000;
        }

        .navbar a,
        .navbar .navbar-brand {
            color: #fff !important;
        }

        footer {
            margin-top: auto;
            background: #000;
            color: #fff;
            text-align: center;
            padding: 15px;
        }

        .report-card {
            border-radius: 15px;
            background: #000000ff;
            padding: 20px;
            color: #fff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .report-card input,
        .report-card select,
        .report-card textarea {
            border-radius: 8px;
            border: 1px solid #0073ffff;
            background: #ffffffff;
            color: #000000ff;
            padding: 10px;
            width: 100%;
            margin-bottom: 15px;
            transition: all 0.2s;
        }

        .report-card input:focus,
        .report-card select:focus,
        .report-card textarea:focus {
            outline: none;
            border-color: #007bff;
            background: #ffffffff;
        }

        .btn-submit {
            background-color: #28a745;
            border: none;
            color: white;
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background-color: #218838;
        }

        .alert-success {
            display: none;
        }

        table {
            background: #1c1c2e;
            color: #fff;
        }

        table th,
        table td {
            vertical-align: middle;
        }

        table th {
            background: #000000ff;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .container-custom {
            max-width: 1000px;
            margin: auto;
        }

        .btn-submit {
            background-color: #28a745;
            border: none;
            color: white;
            padding: 6px 12px;
            font-size: 0.9rem;
            transition: all 0.2s;
            border-radius: 6px;
            width: auto;
            /* override 100% width */
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg px-3 py-3">
        <a href="javascript:history.back()" class="btn btn-dark bg-secondary me-3"><i class="fa fa-arrow-left"></i> Back</a>
        <a class="navbar-brand mx-auto">Report a Service</a>
        <a href="../logout" class="btn btn-danger">Logout</a>
    </nav>

    <div class="container container-custom my-5">
        <!-- Success Message -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success" id="successMsg"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <!-- Report Form -->
        <div class="report-card mb-5">
            <h4 class="mb-4 text-center text-light"><i class="fa fa-flag me-2"></i> Submit a Report</h4>
            <form method="POST">
                <select name="service_id" required>
                    <option value="">Select Service</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="subject" placeholder="Subject (short title)" required>
                <textarea name="details" rows="4" placeholder="Describe the issue in detail..." required></textarea>
                <div class="text-start">
                    <button type="submit" name="submit_report" class="btn btn-submit btn-sm">
                        <i class="fa fa-paper-plane me-2"></i> Submit Report
                    </button>
                </div>
            </form>
        </div>

        <!-- Reported Services Table -->
        <div class="table-responsive">
            <table class="table table-striped table-hover text-white">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Service</th>
                        <th>Provider</th>
                        <th>Subject</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th>Date Reported</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($reports)): ?>
                        <?php foreach ($reports as $index => $r): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($r['service_title']) ?></td>
                                <td><?= htmlspecialchars($r['provider_name']) ?></td>
                                <td><?= htmlspecialchars($r['subject']) ?></td>
                                <td><?= htmlspecialchars($r['details']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $r['status'] === 'pending' ? 'warning' : 'success' ?>">
                                        <?= htmlspecialchars($r['status']) ?>
                                    </span>
                                </td>
                                <td><?= date("d M Y, H:i", strtotime($r['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No reports submitted yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <footer>
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const msg = document.getElementById('successMsg');
            if (msg) {
                msg.style.display = 'block';
                setTimeout(() => {
                    msg.style.display = 'none';
                    history.replaceState(null, '', window.location.pathname);
                }, 3000);
            }
        });
    </script>
</body>

</html>