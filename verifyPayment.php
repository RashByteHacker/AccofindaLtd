<?php
session_start();
require '../config.php';

// Only landlords or admin allowed
if (!isset($_SESSION['id']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin'])) {
    header("Location: ../login.php");
    exit();
}

$landlord_id = $_SESSION['id'];

// Helper: set flash message
function setFlash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// ----------------------------
// PAYSTACK VERIFICATION
// ----------------------------
if (!empty($_GET['reference'])) {
    $reference = $_GET['reference'];

    $ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer ", // Replace with your Paystack secret key
        "Cache-Control: no-cache"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!empty($data['status']) && $data['status'] && $data['data']['status'] === 'success') {
        $amount = $data['data']['amount'] / 100;
        $paystack_ref = $data['data']['reference'];
        $transaction_code = 'ACCFND-' . time() . '-' . rand(1000, 9999);

        // Insert into transactions
        $stmt = $conn->prepare("
            INSERT INTO transactions 
            (transaction_code, transaction_type, payer_id, payer_type, payee_id, payee_type, amount, currency, direction, payment_gateway, payment_method, gateway_reference, status, created_at) 
            VALUES (?, 'landlord_payment', ?, 'user', ?, 'platform', ?, 'KES', 'outflow', 'paystack', 'card', ?, 'completed', NOW())
        ");
        $stmt->bind_param("siids", $transaction_code, $landlord_id, $landlord_id, $amount, $paystack_ref);

        if ($stmt->execute()) {
            setFlash('success', 'Paystack payment successful!');
        } else {
            setFlash('danger', 'Payment verified but DB error.');
        }
        $stmt->close();
    } else {
        setFlash('danger', 'Paystack verification failed.');
    }

    header("Location: ../Landlords/payment");
    exit();
}

// ----------------------------
// M-PESA VERIFICATION
// ----------------------------
elseif (!empty($_GET['checkoutRequestID'])) {
    $checkoutRequestID = $_GET['checkoutRequestID'];

    $stmt = $conn->prepare("SELECT * FROM transactions WHERE gateway_reference=? AND payer_id=? AND status='pending'");
    $stmt->bind_param("si", $checkoutRequestID, $landlord_id);
    $stmt->execute();
    $txn = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($txn) {
        // Assuming callback already updated status
        switch ($txn['status']) {
            case 'completed':
                setFlash('success', 'M-Pesa payment successful!');
                break;
            case 'failed':
                setFlash('danger', 'M-Pesa payment failed or cancelled.');
                break;
            default:
                setFlash('warning', 'M-Pesa payment is still pending.');
        }
    } else {
        setFlash('danger', 'M-Pesa payment not found.');
    }

    header("Location: ../Landlords/payment");
    exit();
}

// ----------------------------
// No reference provided
// ----------------------------
else {
    setFlash('danger', 'No payment reference provided.');
    header("Location: ../Landlords/payment");
    exit();
}
