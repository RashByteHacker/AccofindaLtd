<?php
session_start();
require '../config.php';

// ✅ Only landlords allowed
if (!isset($_SESSION['id']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: ../login.php");
    exit();
}

$landlord_id    = $_SESSION['id'];
$landlord_email = $_SESSION['email'];

// --- Fetch properties and units for this landlord
$sql = "
    SELECT p.property_id, p.title AS property_title,
           u.room_type, u.units_available, u.price,
           (u.units_available * u.price) AS unit_total
    FROM properties p
    INNER JOIN property_units u ON p.property_id = u.property_id
    WHERE p.landlord_id = ?
    ORDER BY p.property_id, u.room_type
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$result = $stmt->get_result();

$properties = [];
while ($row = $result->fetch_assoc()) {
    $prop_id = $row['property_id'];
    if (!isset($properties[$prop_id])) {
        $properties[$prop_id] = [
            'title' => $row['property_title'],
            'units' => [],
            'property_total' => 0
        ];
    }
    $properties[$prop_id]['units'][] = $row;
    $properties[$prop_id]['property_total'] += $row['unit_total'];
}
$stmt->close();

// --- Calculate total commission (2%)
$total_commission = 0;
foreach ($properties as $prop) {
    $total_commission += $prop['property_total'] * 0.02;
}

// --- Handle STK Push response flash messages
$stkMessageClass = $stkMessageText = '';
if (isset($_SESSION['stk_success'])) {
    $stkMessageClass = 'success';
    $stkMessageText  = $_SESSION['stk_success'];
    unset($_SESSION['stk_success']);
} elseif (isset($_SESSION['stk_error'])) {
    $stkMessageClass = 'danger';
    $stkMessageText  = $_SESSION['stk_error'];
    unset($_SESSION['stk_error']);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accofinda - Payments</title>
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        main {
            flex: 1;
        }

        .table-sm th,
        .table-sm td {
            font-size: 0.85rem;
        }

        .navbar-brand {
            font-weight: bold;
        }

        .payment-buttons button {
            min-width: 150px;
            margin: 5px;
        }

        footer {
            background: #212529;
            color: white;
            text-align: center;
            padding: 10px;
            margin-top: auto;
        }
    </style>
</head>

<body>
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <a class="btn btn-outline-light" href="landlord">&#8592; Back</a>
            </div>
            <div class="text-center flex-grow-1">
                <a class="navbar-brand mx-auto" href="#">Accofinda</a>
            </div>
            <div>
                <a class="btn btn-success btn-sm" href="#">
                    <span style="font-size: 0.85rem;"><?= htmlspecialchars($landlord_email) ?></span>
                </a>
            </div>
        </div>
    </nav>

    <main class="container mt-4">
        <h2 class="text-center mb-4">Your Billing & Payments</h2>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'];
                                                unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'];
                                            unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if ($stkMessageText): ?>
            <div class="alert alert-<?= $stkMessageClass ?>"><?= $stkMessageText ?></div>
        <?php endif; ?>

        <?php if (empty($properties)): ?>
            <div class="alert alert-warning text-center shadow-sm" style="border: 1px solid black;">
                <i class="fa fa-info-circle"></i> No Properties or Units Found.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover table-sm align-middle">
                    <thead class="table-dark text-center">
                        <tr>
                            <th>Property</th>
                            <th>Room Type</th>
                            <th>Units Available</th>
                            <th>Price per Unit (KES)</th>
                            <th>Total (KES)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($properties as $prop_id => $prop): ?>
                            <?php foreach ($prop['units'] as $unit): ?>
                                <tr class="text-center">
                                    <td><?= htmlspecialchars($prop['title']) ?></td>
                                    <td><?= htmlspecialchars($unit['room_type']) ?></td>
                                    <td><?= $unit['units_available'] ?></td>
                                    <td><?= number_format($unit['price'], 2) ?></td>
                                    <td><?= number_format($unit['unit_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary text-center">
                                <td colspan="4" class="text-end"><strong>Property Total:</strong></td>
                                <td><?= number_format($prop['property_total'], 2) ?></td>
                            </tr>
                            <tr class="table-primary text-center">
                                <td colspan="4" class="text-end"><strong>Commission (2%):</strong></td>
                                <td><?= number_format($prop['property_total'] * 0.02, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark text-center">
                            <td colspan="4" class="text-end"><strong>Total Commission:</strong></td>
                            <td><?= number_format($total_commission, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Payment Options -->
            <div class="text-center payment-buttons mt-4">
                <!-- Paystack -->
                <button type="button" class="btn btn-success btn-lg" onclick="payWithPaystack()">Pay with Paystack</button>

                <!-- M-Pesa STK Push Form -->
                <form method="POST" action="../Shared/mpesaStk" class="d-inline-block ms-2">
                    <input type="hidden" name="amount" value="<?= $total_commission ?>">
                    <input type="text" name="phone" id="mpesaPhone" class="form-control d-inline-block w-auto mb-2 mb-sm-0"
                        placeholder="Enter Mpesa phone (07XXXXXXXX)" required>
                    <button type="submit" class="btn btn-warning btn-lg">Pay with Mpesa</button>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer>
        <p>&copy; <?= date("Y") ?> Accofinda. All Rights Reserved.</p>
    </footer>

    <!-- Paystack JS -->
    <script src="https://js.paystack.co/v1/inline.js"></script>
    <script>
        function payWithPaystack() {
            var handler = PaystackPop.setup({
                key: 'pk_live_76e66ab019e73e953697e90aae6aeb7ce4767639',
                email: '<?= $landlord_email ?>',
                amount: <?= $total_commission * 100 ?>,
                currency: "KES",
                ref: 'ACCFND-' + Math.floor((Math.random() * 1000000000) + 1),
                onClose: function() {
                    alert('Payment window closed.');
                },
                callback: function(response) {
                    window.location.href = "../Shared/verifyPayment?reference=" + response.reference;
                }
            });
            handler.openIframe();
        }

        // Auto-format M-Pesa phone number before submit
        document.querySelector('form[action="../Shared/mpesaStk"]').addEventListener('submit', function(e) {
            let phoneInput = document.getElementById('mpesaPhone');
            let phone = phoneInput.value.trim();
            if (phone.startsWith('0')) phone = '254' + phone.substr(1);
            phoneInput.value = phone;
        });
    </script>
</body>

</html>