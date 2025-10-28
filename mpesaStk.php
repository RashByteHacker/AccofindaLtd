<?php
session_start();
require '../config.php';

// ✅ Only landlords
if (!isset($_SESSION['id']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: ../login.php");
    exit();
}

$landlord_id = $_SESSION['id'];
$landlord_email = $_SESSION['email'];

// Get POST data
$amount = floatval($_POST['amount'] ?? 0);
$phone  = preg_replace('/\D/', '', $_POST['phone']); // Keep digits only
$property_id = intval($_POST['property_id'] ?? 0);

if ($amount <= 0 || empty($phone)) {
    die("Invalid amount or phone number.");
}

// Safaricom Daraja API credentials (sandbox)
$consumer_key = '2AFLGTiawtwJ80asR6hKVMPwLWmLJULR1qpOJjfD9Jy12YCr';
$consumer_secret = 'Ykk8f71OcrknUmSWsmHZfyveTtAdK1ZzRymG5nx28plAFNaFKVXhHAaFDLk9t3nr';
$shortcode = '6998898'; // Sandbox shortcode
$passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'; 
$callback_url = 'https://accofinda.com/Shared/mpesaCallback';

// Step 1: Generate OAuth token
$credentials = base64_encode($consumer_key . ':' . $consumer_secret);
$ch = curl_init('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $credentials"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$token = json_decode($response)->access_token ?? null;
if (!$token) {
    die("Failed to get OAuth token");
}

// Step 2: Prepare STK Push request
$timestamp = date('YmdHis');
$password = base64_encode($shortcode . $passkey . $timestamp);
$stk_url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

$stk_payload = [
    "BusinessShortCode" => $shortcode,
    "Password"          => $password,
    "Timestamp"         => $timestamp,
    "TransactionType"   => "CustomerBuyGoodsOnline",
    "Amount"            => $amount,
    "PartyA"            => $phone,
    "PartyB"            => $shortcode,
    "PhoneNumber"       => $phone,
    "CallBackURL"       => $callback_url,
    "AccountReference"  => "Accofinda Rent",
    "TransactionDesc"   => "Landlord commission payment"
];

$ch = curl_init($stk_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stk_payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$response_data = json_decode($response, true);

// Step 3: Insert payment record as pending
$stmt = $conn->prepare("
    INSERT INTO payments (landlord_id, property_id, amount, commission, status, payment_method, created_at)
    VALUES (?, ?, ?, ?, 'pending', 'Mpesa', NOW())
");
$commission = $amount * 0.02;
$stmt->bind_param("iidd", $landlord_id, $property_id, $amount, $commission);
$stmt->execute();
$stmt->close();

// Step 4: Handle STK response
if (isset($response_data['ResponseCode']) && $response_data['ResponseCode'] == '0') {
    $checkoutRequestID = $response_data['CheckoutRequestID'];
    $_SESSION['mpesa_checkout_id'] = $checkoutRequestID;
    $_SESSION['success'] = "STK Push initiated! Complete the payment on your phone.";
} else {
    $_SESSION['error'] = "Mpesa STK Push failed: " . json_encode($response_data);
}

header("Location: ../Landlords/payment");
exit();
