<?php
session_start();
require '../config.php';
require '../vendor/autoload.php'; // Dompdf autoload

use Dompdf\Dompdf;

// ✅ Access control: only admins/managers
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: ../login.php");
    exit();
}

// --- Safe escape function
function e($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// ✅ Handle PDF export for a specific landlord
if (isset($_GET['download_pdf']) && is_numeric($_GET['download_pdf'])) {
    $landlord_id = intval($_GET['download_pdf']);

    // Fetch landlord info
    $landlord = $conn->query("SELECT * FROM users WHERE id=$landlord_id AND role='landlord'")->fetch_assoc();
    if (!$landlord) die("Landlord not found.");

    // Fetch all properties for this landlord
    $properties = $conn->query("SELECT * FROM properties WHERE landlord_id=$landlord_id ORDER BY created_at DESC");

    // Build PDF HTML
    ob_start();
?>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }

        h2,
        p {
            text-align: center;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 15px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
        }

        th {
            background: #ddd;
        }

        .signature {
            margin-top: 50px;
        }
    </style>

    <h2>Property Report for <?= e($landlord['full_name']) ?></h2>
    <p><strong>Email:</strong> <?= e($landlord['email']) ?> | <strong>Phone:</strong> <?= e($landlord['phone_number']) ?></p>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Title</th>
                <th>Address</th>
                <th>City</th>
                <th>Type</th>
                <th>Status</th>
                <th>Amenities</th>
                <th>Units</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php $count = 1;
            while ($p = $properties->fetch_assoc()): ?>
                <tr>
                    <td><?= $count++ ?></td>
                    <td><?= e($p['title']) ?></td>
                    <td><?= e($p['address']) ?></td>
                    <td><?= e($p['city']) ?></td>
                    <td><?= e($p['property_type']) ?></td>
                    <td><?= ucfirst($p['status']) ?></td>
                    <td><?= e($p['amenities']) ?></td>
                    <td><?= e($p['availability_status']) ?></td>
                    <td><?= date("M d, Y", strtotime($p['created_at'])) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div class="signature">
        <p><strong>Signature:</strong> _________________________</p>
        <p>Date: <?= date("M d, Y") ?></p>
    </div>
<?php
    $html = ob_get_clean();

    // ✅ Generate PDF
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("landlord_properties_report.pdf", ["Attachment" => true]);
    exit();
}

// ✅ Fetch landlords and their cities/properties count
$query = "
SELECT u.id, u.full_name, u.email, u.phone_number,
       COALESCE(MAX(p.city), '') AS city,
       COUNT(p.property_id) as total_properties
FROM users u
LEFT JOIN properties p ON u.id = p.landlord_id
WHERE u.role='landlord'
GROUP BY u.id
ORDER BY total_properties DESC
";
$result = $conn->query($query);
$landlords = [];
while ($row = $result->fetch_assoc()) $landlords[] = $row;
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Landlords & Properties - Accofinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        #searchInput {
            max-width: 400px;
            margin-bottom: 15px;
        }

        /* ✅ Full width table and horizontal scroll for small screens */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #dee2e6;
            padding: .5rem;
            text-align: left;
        }

        thead {
            background-color: #343a40;
            color: #fff;
        }
    </style>
</head>

<body>

    <div class="container py-5">
        <h2 class="mb-4">📋 Landlords and Their Properties</h2>
        <input type="text" id="searchInput" class="form-control shadow-sm" placeholder="Search landlords by name, email, city...">

        <?php if (empty($landlords)): ?>
            <p class="text-muted mt-3">No landlords found.</p>
        <?php else: ?>
            <div class="table-responsive shadow-sm mt-3">
                <table id="landlordsTable" class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>City</th>
                            <th>Total Properties</th>
                            <th>PDF Report</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($landlords as $l): ?>
                            <tr>
                                <td><?= e($l['id']) ?></td>
                                <td><?= e($l['full_name']) ?></td>
                                <td><?= e($l['email']) ?></td>
                                <td><?= e($l['phone_number']) ?></td>
                                <td><?= e($l['city']) ?></td>
                                <td><span class="badge bg-primary"><?= $l['total_properties'] ?></span></td>
                                <td>
                                    <a href="?download_pdf=<?= $l['id'] ?>" class="btn btn-sm btn-danger">
                                        <i class="fa fa-file-pdf"></i> PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ✅ Search Filter -->
    <script>
        document.getElementById("searchInput").addEventListener("keyup", function() {
            let filter = this.value.toLowerCase();
            document.querySelectorAll("#landlordsTable tbody tr").forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(filter) ? "" : "none";
            });
        });
    </script>

</body>

</html>