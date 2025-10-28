<?php
session_start();
require '../config.php';

// Only allow Admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php?error=AccessDenied");
    exit();
}

// Handle Suspend Provider action
if (isset($_GET['suspend_provider_id'])) {
    $providerId = intval($_GET['suspend_provider_id']);
    $stmt = $conn->prepare("UPDATE users SET serviceprovider_status = 'suspended' WHERE id = ?");
    $stmt->bind_param("i", $providerId);
    if ($stmt->execute()) {
        $_SESSION['flash_message'] = "Provider ID $providerId suspended successfully!";
        $_SESSION['flash_type'] = "danger";
    }
    $stmt->close();
    header("Location: reports.php");
    exit();
}

// Handle Respond to Reporter action
if (isset($_POST['respond_report_id'], $_POST['response_text'])) {
    $reportId = intval($_POST['respond_report_id']);
    $responseText = trim($_POST['response_text']);
    $stmt = $conn->prepare("UPDATE service_reports SET admin_response = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $responseText, $reportId);
    $stmt->execute();
    $stmt->close();
    $_SESSION['flash_message'] = "Response sent for Report ID $reportId!";
    $_SESSION['flash_type'] = "success";
    header("Location: reports.php");
    exit();
}

// Fetch all reports
$result = $conn->query("
    SELECT r.*, u.full_name AS provider_name, rep.full_name AS reporter_name,
        (SELECT COUNT(*) FROM service_reports WHERE provider_id = r.provider_id) AS provider_report_count
    FROM service_reports r
    LEFT JOIN users u ON r.provider_id = u.id
    LEFT JOIN users rep ON r.reporter_id = rep.id
    ORDER BY r.created_at DESC
");
$reports = $result->fetch_all(MYSQLI_ASSOC);

// Flash messages
$message = $_SESSION['flash_message'] ?? '';
$messageType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reports | Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: "Times New Roman", serif;
            background-color: #f5f5f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
        }

        nav {
            background-color: #000;
            padding: 10px 20px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        nav a {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
        }

        footer {
            margin-top: auto;
            background-color: #000;
            color: #fff;
            text-align: center;
            padding: 15px;
        }

        .top-section {
            background-color: #1f1f1f;
            color: #fff;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 5px 5px 0 0;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .top-section h3 {
            margin: 0;
        }

        .top-section .controls {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        .top-section input,
        .top-section select {
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        .btn-refresh {
            background-color: #ac2b11ff;
            color: #fff;
            font-weight: 500;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-refresh:hover {
            background-color: #000;
        }

        .table-responsive {
            width: 100%;
        }

        table {
            font-family: "Times New Roman", serif;
            border-collapse: collapse;
            width: 100%;
            table-layout: auto;
        }

        table thead th {
            background-color: #1f1f1f;
            color: #0dcaf0;
            border: 1px solid #444;
            text-align: left;
        }

        table tbody td {
            border: 1px solid #ccc;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .highlight {
            background-color: #fff3cd !important;
        }

        @media (max-width:768px) {
            .container {
                width: 100% !important;
                padding: 5px !important;
            }

            .top-section {
                padding: 10px;
                flex-direction: column;
                align-items: stretch;
                border-radius: 0;
            }

            .top-section .controls {
                flex-direction: column;
                gap: 5px;
                align-items: stretch;
            }

            table {
                width: 100% !important;
                table-layout: fixed;
            }

            table thead th,
            table tbody td {
                white-space: normal;
                font-size: 14px;
            }
        }
    </style>
</head>

<body>

    <nav>
        <a href="adminDashboard" class="btn btn-success btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
        <span style="text-align:center; flex-grow:1;">Accofinda Admin Panel</span>
        <a href="../logout" class="btn btn-sm btn-danger"><i class="fa fa-sign-out-alt"></i> Logout</a>
    </nav>

    <div class="container my-4">

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="top-section">
            <h3>All Reports</h3>
            <div class="controls">
                <input type="text" id="searchInput" placeholder="Search by Subject, Reporter, or Provider">
                <button class="btn-refresh" onclick="location.reload();">Refresh (<?= count($reports) ?>)</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Provider</th>
                        <th>Reporter</th>
                        <th>Subject</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="reportsTable">
                    <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?= $r['id'] ?></td>
                            <td><?= htmlspecialchars($r['provider_name'] ?: 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['reporter_name'] ?: 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['subject']) ?></td>
                            <td><?= htmlspecialchars($r['details']) ?></td>
                            <td><?= htmlspecialchars($r['status']) ?></td>
                            <td><?= $r['created_at'] ?></td>
                            <td>
                                <?php if ($r['provider_report_count'] >= 3 && $r['provider_id']): ?>
                                    <a href="reports.php?suspend_provider_id=<?= $r['provider_id'] ?>" class="btn btn-danger btn-sm mb-1">
                                        <i class="fa fa-ban"></i> Suspend Provider
                                    </a>
                                <?php endif; ?>

                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="respond_report_id" value="<?= $r['id'] ?>">
                                    <input type="text" name="response_text" placeholder="Respond..." class="form-control form-control-sm mb-1" style="width:140px; display:inline-block;">
                                    <button type="submit" class="btn btn-primary btn-sm mb-1" style="padding:0.15rem 0.4rem; font-size:0.7rem;"><i class="fa fa-reply"></i> Send</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
    <footer>
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const tableRows = document.querySelectorAll('#reportsTable tr');
        const searchInput = document.getElementById('searchInput');

        function highlightRow(row) {
            row.classList.add('highlight');
        }

        function removeHighlight(row) {
            row.classList.remove('highlight');
        }

        searchInput.addEventListener('input', () => {
            const filter = searchInput.value.toLowerCase();
            tableRows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(filter)) {
                    row.style.display = '';
                    highlightRow(row);
                } else {
                    row.style.display = 'none';
                    removeHighlight(row);
                }
            });
        });
    </script>

</body>

</html>