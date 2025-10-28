<?php
session_start();
require '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['email']) || strtolower($_SESSION['role']) !== 'landlord') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$booking_id = intval($_POST['booking_id'] ?? 0);
$unit_id = intval($_POST['unit_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$booking_id || !$unit_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $conn->begin_transaction();

    if ($action === 'approve') {
        // Update booking_requests
        $stmt = $conn->prepare("UPDATE booking_requests SET status='Approved', payment_status='Completed' WHERE booking_id=?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();

        // Update property_unit_details
        $stmt = $conn->prepare("UPDATE property_unit_details SET status='Occupied', last_updated=NOW() WHERE unit_detail_id=?");
        $stmt->bind_param("i", $unit_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Booking approved successfully']);
    } else { // reject
        $stmt = $conn->prepare("UPDATE booking_requests SET status='Rejected', payment_status='Cancelled' WHERE booking_id=?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE property_unit_details SET status='Vacant', last_updated=NOW() WHERE unit_detail_id=?");
        $stmt->bind_param("i", $unit_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Booking rejected successfully']);
    }
} catch (Exception $e) {
    $conn->rollback();
    // Return actual error for debugging
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>