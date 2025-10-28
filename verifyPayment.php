<?php
session_start();
require '../config.php';

// ✅ Check access
if (!isset($_SESSION['id']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin'])) {
    header("Location: ../login.php");
    exit();
}

$landlord_id = $_SESSION['id'];

// Get the Paystack reference from the callback
$reference = $_GET['reference'] ?? '';
if (!$reference) {
    die("No transaction reference provided.");
}

// Initialize cURL to verify transaction
$verify_url = "https://api.paystack.co/transaction/verify/" . urlencode($reference);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $verify_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer pk_test_2ebe7af5bae8f3236c7c05ba6e97ccbff843ea4b",
    "Cache-Control: no-cache",
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!$data || !isset($data['status'])) {
    die("Invalid response from Paystack.");
}

if ($data['status'] && isset($data['data']['status']) && $data['data']['status'] === 'success') {
    // ✅ Payment successful
    $amount_paid = $data['data']['amount'] / 100; // Convert back from kobo

    // Update payments table
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'paid', paid_at = NOW()
        WHERE landlord_id = ? AND amount = ? AND status = 'pending'
    ");
    $stmt->bind_param("id", $landlord_id, $amount_paid);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "Payment verified successfully!";
    header("Location: landlord_dashboard.php");
    exit();
} else {
    // Payment failed or not successful
    $_SESSION['error'] = "Payment verification failed. Please try again.";
    header("Location: landlord");
    exit();
}
