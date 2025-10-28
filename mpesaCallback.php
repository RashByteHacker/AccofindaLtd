<?php
require '../config.php';

// Read the POST JSON sent by Safaricom
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log the raw callback for debugging
file_put_contents('../logs/mpesa_callback.log', date('Y-m-d H:i:s') . " " . $input . PHP_EOL, FILE_APPEND);

// Check if this is an STK push callback
if (isset($data['Body']['stkCallback'])) {
    $callback = $data['Body']['stkCallback'];
    $checkoutRequestID = $callback['CheckoutRequestID'] ?? '';
    $resultCode = $callback['ResultCode'] ?? -1;
    $resultDesc = $callback['ResultDesc'] ?? 'Unknown';

    // Initialize variables
    $amount = 0;
    $mpesaReceiptNumber = null;
    $phoneNumber = null;

    if ($resultCode == 0) {
        // Payment successful
        $callbackMetadata = $callback['CallbackMetadata']['Item'] ?? [];
        foreach ($callbackMetadata as $item) {
            if ($item['Name'] == 'Amount') $amount = $item['Value'];
            if ($item['Name'] == 'MpesaReceiptNumber') $mpesaReceiptNumber = $item['Value'];
            if ($item['Name'] == 'PhoneNumber') $phoneNumber = $item['Value'];
        }

        // Update the payment record in DB
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'completed', mpesa_receipt = ?, paid_amount = ?, paid_phone = ?, updated_at = NOW()
            WHERE mpesa_checkout_id = ?
        ");
        $stmt->bind_param("sdss", $mpesaReceiptNumber, $amount, $phoneNumber, $checkoutRequestID);
        if (!$stmt->execute()) {
            file_put_contents('../logs/mpesa_callback.log', "DB Error: " . $stmt->error . PHP_EOL, FILE_APPEND);
        }
        $stmt->close();
    } else {
        // Payment failed or cancelled
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'failed', failure_desc = ?, updated_at = NOW()
            WHERE mpesa_checkout_id = ?
        ");
        $stmt->bind_param("ss", $resultDesc, $checkoutRequestID);
        if (!$stmt->execute()) {
            file_put_contents('../logs/mpesa_callback.log', "DB Error: " . $stmt->error . PHP_EOL, FILE_APPEND);
        }
        $stmt->close();
    }
}

// Return a 200 response to Safaricom
http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
