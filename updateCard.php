<?php
require '../config.php';
session_start();

// ✅ Check permissions
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'executive'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

// ✅ Validate ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid card ID']);
    exit;
}

// ✅ Fetch existing card to preserve old values
$stmt = $conn->prepare("SELECT full_name, role, allocated_area, phone, national_id, profile_photo FROM generated_cards WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing) {
    echo json_encode(['status' => 'error', 'msg' => 'Card not found']);
    exit;
}

// ✅ Use new value if provided, otherwise keep old
$full_name = trim($_POST['full_name'] ?? '') ?: $existing['full_name'];
$role = trim($_POST['role'] ?? '') ?: $existing['role'];
$allocated_area = trim($_POST['allocated_area'] ?? '') ?: $existing['allocated_area'];
$phone = trim($_POST['phone'] ?? '') ?: $existing['phone'];
$national_id = trim($_POST['national_id'] ?? '') ?: $existing['national_id'];

// ✅ Handle profile photo upload if exists
$profile_photo_path = $existing['profile_photo'];
if (!empty($_FILES['profile_photo']['name'])) {
    $uploadDir = '../uploads/profile_photos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = time() . '_' . basename($_FILES['profile_photo']['name']);
    $targetFile = $uploadDir . $filename;

    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetFile)) {
        $profile_photo_path = 'uploads/profile_photos/' . $filename;
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Failed to upload profile photo']);
        exit;
    }
}

// ✅ Update query (now includes phone)
$sql = "UPDATE generated_cards 
        SET full_name = ?, role = ?, allocated_area = ?, phone = ?, national_id = ?, profile_photo = ? 
        WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssssi", $full_name, $role, $allocated_area, $phone, $national_id, $profile_photo_path, $id);
$stmt->execute();

if ($stmt->affected_rows >= 0) {
    echo json_encode(['status' => 'ok', 'msg' => 'Card updated successfully']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'No changes made']);
}
$stmt->close();
?>