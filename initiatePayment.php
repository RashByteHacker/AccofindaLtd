<?php
session_start();
require '../config.php';

// ✅ Check access
if (!isset($_SESSION['id']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin', 'manager'])) {
    header("Location: ../login.php");
    exit();
}

$landlord_id = $_SESSION['id'];
$landlord_email = $_SESSION['email']; // Keep email for Paystack

// Fetch all properties for this landlord
$sql = "
    SELECT p.property_id, p.title, 
           SUM(u.units_available * u.price) AS property_total
    FROM properties p
    INNER JOIN property_units u ON p.property_id = u.property_id
    WHERE p.landlord_id = ?
    GROUP BY p.property_id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$result = $stmt->get_result();

$total_amount = 0;
$properties = [];
while ($row = $result->fetch_assoc()) {
    $total_amount += $row['property_total'];
    $properties[] = $row;
}
$stmt->close();

// If landlord has no properties
if ($total_amount <= 0) {
    die("No properties found or units not priced.");
}

// Insert each property's commission into payments table
$stmt2 = $conn->prepare("
    INSERT INTO payments (landlord_id, property_id, amount, percentage_rate, status, method, created_at)
    VALUES (?, ?, ?, 2.00, 'pending', 'paystack', NOW())
");

foreach ($properties as $prop) {
    // Calculate 2% commission per property
    $commission = round($prop['property_total'] * 0.02, 2);

    $stmt2->bind_param("iid", $landlord_id, $prop['property_id'], $commission);
    $stmt2->execute();
}
$stmt2->close();

// Convert to kobo (Paystack requires lowest currency unit)
$paystack_amount = $total_amount * 0.02 * 100; // total 2% of all properties in kobo

// Localhost callback URL
$callback_url = "http://localhost/Accofinda/Shared/verifyPayment.php"; // change 'yourproject' to your folder
$paystack_url = "https://api.paystack.co/transaction/initialize";

$fields = [
    'email' => $landlord_email,
    'amount' => $paystack_amount,
    'callback_url' => $callback_url
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $paystack_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer YOUR_PAYSTACK_SECRET_KEY",
    "Cache-Control: no-cache",
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$response_data = json_decode($response, true);

if ($response_data && isset($response_data['data']['authorization_url'])) {
    header("Location: " . $response_data['data']['authorization_url']);
    exit();
} else {
    die("Paystack initialization failed: " . $response);
}
