<?php
// smartCardMaker.php
session_start();
require '../config.php'; // must create $conn (mysqli)

// ---------------------------
// Basic helper
// ---------------------------
function clean($s)
{
    return trim(htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

// ---------------------------
// Company Info
// ---------------------------
$companyName = "Accofinda Limited";
$companyLogo = "../Images/AccofindaLogo1.jpg";
$companyWebsite = "www.accofinda.com";

// ---------------------------
// Fetch all existing users for dropdown
// ---------------------------
$users = [];
$userQuery = "SELECT id, full_name, role FROM users ORDER BY full_name ASC";
$userResult = $conn->query($userQuery);
if ($userResult && $userResult->num_rows > 0) {
    $users = $userResult->fetch_all(MYSQLI_ASSOC);
}

// ---------------------------
// Handle AJAX actions (update/fetch/delete)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    header('Content-Type: application/json; charset=utf-8');

    // Inline update
    if ($action === 'update_card_field') {
        $id = intval($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        $allowed = ['role', 'allocated_area', 'national_id', 'profile_photo'];
        if (!$id || !in_array($field, $allowed, true)) {
            echo json_encode(['status' => 'error', 'msg' => 'Invalid request']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE generated_cards SET {$field} = ? WHERE id = ?");
        $stmt->bind_param("si", $value, $id);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode(['status' => $ok ? 'ok' : 'error', 'msg' => $ok ? 'Updated' : 'Failed']);
        exit;
    }

    // Fetch card
    if ($action === 'fetch_card') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['status' => 'error', 'msg' => 'Invalid ID']);
            exit;
        }
        $stmt = $conn->prepare("SELECT * FROM generated_cards WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode(['status' => 'ok', 'data' => $res]);
        exit;
    }

    // Delete cards
    if ($action === 'delete_cards') {
        if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'executive'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
            exit;
        }

        if (!empty($_POST['all'])) {
            $conn->query("DELETE FROM generated_cards");
            echo json_encode(['status' => 'ok', 'msg' => 'All cards deleted']);
            exit;
        }

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['status' => 'error', 'msg' => 'No cards selected']);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("DELETE FROM generated_cards WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'ok', 'msg' => 'Selected cards deleted']);
        exit;
    }
}
// ---------------------------
// Handle Form Submission (POST-Redirect-GET to prevent resubmission)
// ---------------------------
$errors = [];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $mode = ($_POST['mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
    $assignment = clean($_POST['assignment'] ?? '');

    // Handle photo upload
    $uploadedPath = '';
    if (!empty($_FILES['passport_photo']['name'])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $maxBytes = 2 * 1024 * 1024;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['passport_photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedTypes, true)) $errors[] = "Passport photo must be JPG/PNG/WebP.";
        elseif ($_FILES['passport_photo']['size'] > $maxBytes) $errors[] = "Passport photo must be under 2MB.";
        else {
            $targetDir = __DIR__ . "/uploads/passports/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
            $fileName = "passport_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['passport_photo']['tmp_name'], $targetFile)) $uploadedPath = "uploads/passports/" . $fileName;
            else $errors[] = "Failed to store uploaded photo.";
        }
    }

    if (empty($errors)) {
        if ($mode === 'existing') {
            $userId = intval($_POST['user_id'] ?? 0);
            $roleUpdate = clean($_POST['role_update'] ?? '');

            if (!$userId) {
                $errors[] = "Please select an existing user.";
            } else {
                $stmt = $conn->prepare("SELECT id, full_name, email, phone_number, role, national_id, profile_photo FROM users WHERE id=? LIMIT 1");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user) {
                    if ($uploadedPath) {
                        $uup = $conn->prepare("UPDATE users SET profile_photo=? WHERE id=?");
                        $uup->bind_param("si", $uploadedPath, $userId);
                        $uup->execute();
                        $uup->close();
                        $user['profile_photo'] = $uploadedPath;
                    }

                    $cardRole = $roleUpdate !== '' ? $roleUpdate : ($user['role'] ?? '');
                    $ins = $conn->prepare(
                        "INSERT INTO generated_cards
                        (user_type, reference_user_id, full_name, email, phone, role, national_id, profile_photo, allocated_area)
                        VALUES ('existing', ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $ins->bind_param(
                        "isssssss",
                        $userId,
                        $user['full_name'],
                        $user['email'],
                        $user['phone_number'],
                        $cardRole,
                        $user['national_id'],
                        $user['profile_photo'],
                        $assignment
                    );

                    if ($ins->execute()) {
                        $newId = $conn->insert_id;
                        $serial = "ACCFND-" . str_pad($newId, 4, "0", STR_PAD_LEFT);
                        $conn->query("UPDATE generated_cards SET serial_number='$serial' WHERE id=$newId");

                        $_SESSION['preview'] = $conn->query("SELECT * FROM generated_cards WHERE id=$newId")->fetch_assoc();
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    } else {
                        $errors[] = "Failed to create generated card (DB error).";
                    }
                    $ins->close();
                } else {
                    $errors[] = "User not found.";
                }
            }
        } else {
            $new_full_name = clean($_POST['new_full_name'] ?? '');
            $new_email = clean($_POST['new_email'] ?? '');
            $new_phone = clean($_POST['new_phone'] ?? '');
            $new_role = clean($_POST['new_role'] ?? '');
            $new_id = clean($_POST['new_id'] ?? '');
            $new_gender = clean($_POST['new_gender'] ?? '');

            if ($new_full_name === '' || $new_email === '' || $new_phone === '' || $new_role === '' || $new_id === '') {
                $errors[] = "Please fill all required new user fields.";
            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please provide a valid email address for the new user.";
            } else {
                $photo = $uploadedPath ?: '';
                $ins = $conn->prepare(
                    "INSERT INTO generated_cards
                    (user_type, reference_user_id, full_name, email, phone, role, national_id, profile_photo, allocated_area)
                    VALUES ('new', 0, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->bind_param(
                    "sssssss",
                    $new_full_name,
                    $new_email,
                    $new_phone,
                    $new_role,
                    $new_id,
                    $photo,
                    $assignment
                );

                if ($ins->execute()) {
                    $newId = $conn->insert_id;
                    $serial = "ACCFND-" . str_pad($newId, 4, "0", STR_PAD_LEFT);
                    $conn->query("UPDATE generated_cards SET serial_number='$serial' WHERE id=$newId");

                    $_SESSION['preview'] = $conn->query("SELECT * FROM generated_cards WHERE id=$newId")->fetch_assoc();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $errors[] = "Failed to create new generated card.";
                }
                $ins->close();
            }
        }
    }
}

// ---------------------------
// Load generated cards list
// ---------------------------
$generated = [];
$gRes = $conn->query("SELECT * FROM generated_cards ORDER BY created_at DESC");
if ($gRes) $generated = $gRes->fetch_all(MYSQLI_ASSOC);

// ---------------------------
// Helper for JS-safe encoding
// ---------------------------
function jsEncode($val)
{
    return json_encode($val, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Accofinda | Smart Card Maker</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <!-- CDN: Bootstrap, jQuery, DataTables, Select2, qrcodejs, html2canvas, jspdf -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <style>
        body {
            background: #f3f6f9;
            color: #0f172a;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            overflow-x: hidden;
        }

        .navbar {
            background: linear-gradient(90deg, #0b1220, #0d6efd);
        }

        .container {
            max-width: 1550px;
        }

        /* -----------------------------
   CARD PREVIEW STYLES (COMPACT PROFESSIONAL)
----------------------------- */
        .card-preview {
            width: 360px;
            height: 200px;
            background: #ffffff;
            color: #000;
            border-radius: 14px;
            /* softer, subtle curve */
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
            position: relative;
            overflow: hidden;
            padding: 15px;
            display: flex;
            flex-direction: row;
            align-items: center;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        /* ✅ Company name centered at top */
        .company-name {
            position: absolute;
            top: 8px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 17px;
            /* larger font for name */
            font-weight: 700;
            color: #006d6f;
            /* dark teal tone */
            letter-spacing: 1px;
            margin: 0;
            text-transform: capitalize;
            font-family: 'Segoe UI Semibold', Arial, sans-serif;
        }

        /* Company logo top-right */
        .company-logo {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 70px;
            height: 70px;
            object-fit: contain;
            background-color: #000;
            padding: 4px;
            border-radius: 10px;
        }

        /* Left section: photo + details */
        .left-section {
            display: flex;
            flex-direction: row;
            gap: 8px;
            align-items: flex-start;
            margin-top: 30px;
            /* push down slightly under company name */
        }

        /* Profile photo */
        .passport-photo {
            top: 10px;
            width: 75px;
            height: 95px;
            border: 2px solid #0f172a;
            object-fit: cover;
            border-radius: 6px;
        }

        /* Details section */
        .details {
            display: flex;
            flex-direction: column;
            font-size: 10px;
            gap: 2px;
        }

        .details p {
            margin: 0;
            line-height: 1.2;
        }

        /* Name emphasized */
        .details .name {
            font-weight: 600;
            font-size: 13px;
            color: #0f172a;
        }

        /* Role, phone, etc. smaller and clean */
        .details .role,
        .details .area,
        .details .phone,
        .details .email,
        .details .national-id,
        .details .serial {
            font-weight: 400;
            color: #374151;
        }

        /* QR Code bottom-right */
        .qr-wrapper {
            position: absolute;
            bottom: 15px;
            right: 12px;
            width: 50px;
            height: 50px;
        }

        /* Company website bottom-left */
        .company-website {
            position: absolute;
            bottom: 8px;
            left: 15px;
            font-size: 10px;
            color: #006d6f;
            font-weight: 500;
        }

        .badge-existing {
            background: linear-gradient(90deg, #22c55e, #16a34a);
            color: #fff;
            font-weight: 700;
            padding: 6px 8px;
            border-radius: 8px;
            font-size: 12px;
        }

        .badge-new {
            background: linear-gradient(90deg, #3b82f6, #2563eb);
            color: #fff;
            font-weight: 700;
            padding: 6px 8px;
            border-radius: 8px;
            font-size: 12px;
        }

        .small-photo {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e6e9ee;
        }

        /* Errors */
        .errors {
            color: #721c24;
            background: #f8d7da;
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 12px;
        }


        /* -----------------------------
   TABLE STYLES
----------------------------- */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
            margin-bottom: 0;
            table-layout: fixed;
        }

        .table thead th {
            background: #000;
            color: #fff;
            font-weight: 600;
            font-size: 0.75rem;
            text-align: left;
            /* changed from center */
            padding: 8px 6px;
            border: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .table td {
            vertical-align: middle;
            padding: 6px 6px;
            font-size: 0.72rem;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: left;
            /* added left alignment */
        }

        .table-hover tbody tr:hover {
            background: #f1f5fa;
            transition: 0.2s;
        }

        /* -----------------------------
   BUTTONS IN TABLE
----------------------------- */
        .btn-sm {
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 3px;
            min-width: unset;
        }

        .d-flex.flex-nowrap {
            flex-wrap: nowrap;
            /* ensures buttons stay in a single row */
            justify-content: flex-start;
            /* left aligned buttons */
            gap: 2px;
        }

        /* -----------------------------
   TABLE RESPONSIVE
----------------------------- */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-left: -8px;
            /* cancel card-body padding */
            margin-right: -8px;
        }

        /* Truncate email column */
        .table td.text-truncate {
            max-width: 180px;
        }

        /* -----------------------------
   DATATABLE STYLING
----------------------------- */
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            font-size: 0.7rem;
            padding: 2px 6px;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            font-size: 0.7rem;
            padding: 2px 4px;
        }

        /* -----------------------------
   MEDIA QUERIES
----------------------------- */
        @media (max-width: 992px) {

            .table td,
            .table th {
                font-size: 0.7rem;
                padding: 5px 4px;
                text-align: left;
                /* maintain left alignment */
            }

            .btn-sm {
                font-size: 0.6rem;
                padding: 2px 4px;
            }
        }

        @media (max-width: 768px) {
            .table {
                table-layout: auto;
            }

            td img {
                width: 24px;
                height: 24px;
            }
        }

        @media (max-width: 480px) {
            .btn-sm {
                font-size: 0.55rem;
                padding: 1px 3px;
            }
        }

        .national-id .id-hidden {
            color: transparent;
            /* hide text */
            background: #ddd;
            /* optional grey background */
            border-radius: 3px;
            cursor: pointer;
            padding: 2px 4px;
            display: inline-block;
            user-select: none;
            /* prevent copy */
        }

        .national-id.revealed .id-hidden {
            color: #0f172a;
            /* show actual text */
            background: none;
        }
    </style>

</head>

<body>
    <nav class="navbar navbar-dark px-3 mb-4">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1 text-white"><?= htmlspecialchars($companyName) ?> Smart Card Maker</span>
            <div class="text-white small">Generated cards · Manage · Download</div>
        </div>
    </nav>

    <div class="container">
        <!-- messages -->
        <?php if (!empty($errors)): ?>
            <div class="errors mb-3">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Generated Cards</h4>
            <div>
                <button class="btn btn-primary" id="toggleGeneratorBtn">➕ Generate New Card</button>
            </div>
        </div>

        <!-- ✅ Generator Form (toggle between Existing & New user) -->
        <div id="generatorForm" class="bg-dark p-4 rounded shadow mb-4 text-white" style="display:none;">
            <form method="post" enctype="multipart/form-data" id="genForm" class="row g-3 align-items-end">

                <!-- Mode Selector -->
                <div class="col-md-3">
                    <label class="form-label text-white">Mode</label>
                    <select name="mode" id="modeSelect" class="form-select">
                        <option value="existing" selected>Existing User</option>
                        <option value="new">New User</option>
                    </select>
                </div>

                <!-- Existing User Section -->
                <div class="col-md-5 mode-existing">
                    <label class="form-label text-white">Select Existing User</label>
                    <select name="user_id" id="userSelect" class="form-select" style="width:100%;">
                        <option value="">-- Choose user --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['full_name']) ?> - <?= htmlspecialchars($u['role']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 mode-existing">
                    <label class="form-label text-white">Edit Role for Card (optional)</label>
                    <input type="text" name="role_update" class="form-control" placeholder="e.g. Resident / Manager">
                </div>

                <!-- New User Section (hidden by default) -->
                <div class="col-md-9 mode-new d-none">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" name="new_full_name" class="form-control" placeholder="Full Name">
                        </div>
                        <div class="col-md-4">
                            <input type="email" name="new_email" class="form-control" placeholder="Email">
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="new_phone" class="form-control" placeholder="Phone">
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="new_role" class="form-control" placeholder="Role">
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="new_id" class="form-control" placeholder="National ID">
                        </div>
                        <div class="col-md-4">
                            <select name="new_gender" class="form-select">
                                <option value="">-- Gender --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Shared Inputs -->
                <div class="col-md-3">
                    <label class="form-label text-white">Passport Photo (optional)</label>
                    <input type="file" name="passport_photo" class="form-control">
                    <div class="form-text text-white-50">JPG/PNG/WebP &lt; 2MB</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label text-white">Allocated Area</label>
                    <input type="text" name="assignment" class="form-control" placeholder="e.g. Block A - Unit 12">
                </div>

                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-danger">Generate Card</button>
                </div>
            </form>
        </div>

        <!-- Table of Generated Smart Cards -->
        <div class="container-fluid card mb-4 shadow-sm border-0 p-2">
            <div class="card-body p-2">
                <h5 class="mb-2 fw-bold text-dark">
                    Generated Smart Cards
                    <span class="badge bg-info text-dark ms-2"><?= count($generated) ?> Total</span>
                </h5>

                <!-- Search Input -->
                <div class="mb-2">
                    <input type="text" id="cardSearch" class="form-control form-control-sm" placeholder="Search cards...">
                </div>

                <div class="table-responsive">
                    <table id="generatedTable" class="table table-striped table-hover align-middle mb-0 w-100">
                        <colgroup>
                            <col style="width: 3%;">
                            <col style="width: 15%;">
                            <col style="width: 8%;">
                            <col style="width: 7%;">
                            <col style="width: 7%;">
                            <col style="width: 15%;">
                            <col style="width: 7%;">
                            <col style="width: 6%;">
                            <col style="width: 7%;">
                            <col style="width: 8%;">
                            <col style="width: 12%;">
                        </colgroup>

                        <thead class="table-dark text-left">
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Allocated Area</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>National ID</th>
                                <th>User Type</th>
                                <th>Serial</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($generated as $g): ?>
                                <tr data-id="<?= (int)$g['id'] ?>">
                                    <td><input type="checkbox" class="select-card" value="<?= (int)$g['id'] ?>"></td>
                                    <td class="fw-semibold small"><?= htmlspecialchars($g['full_name']) ?></td>
                                    <td class="text-primary small"><?= htmlspecialchars($g['role'] ?? '') ?></td>
                                    <td class="text-success small"><?= htmlspecialchars($g['allocated_area'] ?? '') ?></td>
                                    <td class="small"><?= htmlspecialchars($g['phone'] ?? '') ?></td>
                                    <td class="small text-truncate" style="max-width:200px;" title="<?= htmlspecialchars($g['email'] ?? '') ?>">
                                        <?= htmlspecialchars($g['email'] ?? '') ?>
                                    </td>
                                    <td class="small national-id" title="Click to reveal">
                                        <span class="id-hidden"><?= htmlspecialchars($g['national_id'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <?php if ($g['user_type'] === 'existing'): ?>
                                            <span class="badge bg-danger rounded-1 small">Existing</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark rounded-1 small">New</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark text-light border small px-2">
                                            <?= htmlspecialchars($g['serial_number'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($g['created_at'] ?? '') ?></small></td>
                                    <td>
                                        <div class="d-flex justify-content-start gap-1 flex-nowrap">
                                            <button class="btn btn-success btn-sm download-btn" data-id="<?= (int)$g['id'] ?>">Download</button>
                                            <button class="btn btn-warning btn-sm update-btn"
                                                data-id="<?= (int)$g['id'] ?>"
                                                data-role="<?= htmlspecialchars($g['role']) ?>"
                                                data-area="<?= htmlspecialchars($g['allocated_area']) ?>"
                                                data-fullname="<?= htmlspecialchars($g['full_name']) ?>">
                                                Update
                                            </button>
                                            <button class="btn btn-danger btn-sm delete-btn" data-id="<?= (int)$g['id'] ?>">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination Controls -->
                    <nav aria-label="Card pagination" class="mt-2">
                        <ul id="cardPagination" class="pagination pagination-sm justify-content-center mb-0"></ul>
                    </nav>

                    <div id="deleteSuccess" class="alert alert-success text-center d-none mt-2" role="alert">
                        Action completed successfully!
                    </div>
                    <div class="mt-2">
                        <button id="deleteSelectedBtn" class="btn btn-danger btn-sm">Delete Selected</button>
                        <button id="deleteAllBtn" class="btn btn-danger btn-sm">Delete All</button>
                    </div>
                </div>

                <?php if (empty($generated)): ?>
                    <div class="text-center text-muted small mt-3">No cards generated yet.</div>
                <?php endif; ?>
            </div>

            <!-- Hidden Card Template -->
            <div id="cardTemplate" class="card-preview" style="display:none;">
                <p class="company-name">Accofinda Limited</p> <!-- 👈 Added company name -->

                <img src="<?= $companyLogo ?>" alt="Company Logo" class="company-logo">

                <div class="left-section">
                    <img src="https://via.placeholder.com/80x100" alt="Passport Photo" class="passport-photo">
                    <div class="details">
                        <p><strong>Name:</strong> <span class="name">Accofinda Limited</span></p>
                        <p><strong>Role:</strong> <span class="role">Resident</span></p>
                        <p><strong>Phone:</strong> <span class="phone">+254700000000</span></p>
                        <p><strong>Email:</strong> <span class="email">john@example.com</span></p>
                        <p><strong>Allocated Area:</strong> <span class="area">Block A - Unit 12</span></p>
                        <p><strong>ID No:</strong> <span class="national-id">987654321</span></p>
                        <p><strong>Serial No:</strong> <span class="serial">00001</span></p>
                    </div>
                </div>

                <div class="qr-wrapper"></div>
                <p class="company-website">www.accofinda.com</p>
            </div>


        </div>
        <!-- ✅ Update Modal -->
        <div class="modal fade" id="updateModal" tabindex="-1" aria-labelledby="updateModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="updateModalLabel">Update Smart Card Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="updateForm" method="POST" action="updateCard.php" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="id" id="updateId">

                            <!-- Full Name -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Full Name</label>
                                <input type="text" name="full_name" id="updateName" class="form-control" placeholder="Enter full name">
                            </div>

                            <!-- Role -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Role</label>
                                <input type="text" name="role" id="updateRole" class="form-control" placeholder="Enter new role">
                            </div>

                            <!-- Allocated Area -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Allocated Area</label>
                                <input type="text" name="allocated_area" id="updateArea" class="form-control" placeholder="Enter allocated area">
                            </div>

                            <!-- Phone Number -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Phone Number</label>
                                <input type="text" name="phone" id="updatePhone" class="form-control" placeholder="Enter new phone number">
                            </div>

                            <!-- National ID -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">National ID</label>
                                <input type="text" name="national_id" id="updateNationalId" class="form-control" placeholder="Enter new ID (optional)">
                            </div>

                            <!-- Profile Photo -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Profile Photo</label>
                                <div class="d-flex align-items-center gap-2">
                                    <img id="updateProfilePreview" src="https://via.placeholder.com/60x80" alt="Profile Preview" class="rounded border" style="width:60px; height:80px; object-fit:cover;">
                                    <input type="file" name="profile_photo" id="updateProfilePhoto" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="updateSuccess" class="alert alert-success text-center d-none mt-2" role="alert">
                Smart card updated successfully!
            </div>
        </div>

    </div> <!-- /container -->

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <!-- Pagination & Search JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const table = document.getElementById('generatedTable');
            const rows = Array.from(table.tBodies[0].rows);
            const searchInput = document.getElementById('cardSearch');
            const pagination = document.getElementById('cardPagination');
            const rowsPerPage = 20;
            let currentPage = 1;

            function renderTable() {
                const filter = searchInput.value.toLowerCase();
                const filteredRows = rows.filter(row => row.innerText.toLowerCase().includes(filter));
                const pageCount = Math.ceil(filteredRows.length / rowsPerPage);
                currentPage = Math.min(currentPage, pageCount) || 1;

                // Hide all rows
                rows.forEach(r => r.style.display = 'none');

                // Show rows for current page
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;
                filteredRows.slice(start, end).forEach(r => r.style.display = '');

                renderPagination(pageCount);
            }

            function renderPagination(pageCount) {
                pagination.innerHTML = '';
                for (let i = 1; i <= pageCount; i++) {
                    const li = document.createElement('li');
                    li.className = `page-item ${i === currentPage ? 'active' : ''}`;
                    li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                    li.addEventListener('click', function(e) {
                        e.preventDefault();
                        currentPage = i;
                        renderTable();
                    });
                    pagination.appendChild(li);
                }
            }

            searchInput.addEventListener('input', () => {
                currentPage = 1;
                renderTable();
            });

            renderTable();
        });
    </script>
    <script>
        // ✅ Expose PHP-generated cards to JS
        const generated = <?= json_encode($generated) ?>;

        $(function() {
            // Initialize Select2 dropdown
            $('#userSelect').select2({
                theme: 'classic',
                width: '100%'
            });

            // Toggle generator form
            $('#toggleGeneratorBtn').on('click', function() {
                $('#generatorForm').toggle();
                if ($('#generatorForm').is(':visible')) {
                    $(window).scrollTop($('#generatorForm').offset().top - 80);
                }
            });

            // Switch between "Existing" and "New" user mode
            $('#modeSelect').on('change', function() {
                const mode = $(this).val();
                if (mode === 'new') {
                    $('.mode-existing').addClass('d-none');
                    $('.mode-new').removeClass('d-none');
                } else {
                    $('.mode-existing').removeClass('d-none');
                    $('.mode-new').addClass('d-none');
                }
            });

            // Download card button
            $(document).on('click', '.download-btn', async function() {
                const id = $(this).data('id');
                const item = generated.find(g => parseInt(g.id) === parseInt(id));
                if (!item) return alert('Card data not found!');

                // Clone template and fill data
                const el = buildCardElement(item);
                el.style.position = 'fixed';
                el.style.left = '-9999px';
                document.body.appendChild(el);

                // Download PNG + PDF
                await downloadElement(el);

                el.remove();
            });

            // Build card element from hidden template
            function buildCardElement(item) {
                const template = document.getElementById('cardTemplate');
                const card = template.cloneNode(true);
                card.style.display = 'block';
                card.id = '';

                // Fill data
                card.querySelector('.passport-photo').src = item.profile_photo || 'https://via.placeholder.com/80x100';
                card.querySelector('.name').textContent = item.full_name || '';
                card.querySelector('.role').textContent = item.role || '';
                card.querySelector('.phone').textContent = item.phone || '';
                card.querySelector('.email').textContent = item.email || '';
                card.querySelector('.area').textContent = item.allocated_area || '';
                card.querySelector('.national-id').textContent = item.national_id || '';
                card.querySelector('.serial').textContent = item.serial_number || '';

                // QR Code
                const qrWrap = card.querySelector('.qr-wrapper');
                qrWrap.innerHTML = '';
                const qrText = `Name: ${item.full_name || ''} | Email: ${item.email || ''} | Phone: ${item.phone || ''} | Role: ${item.role || ''} | ID: ${item.national_id || ''} | Serial: ${item.serial_number || ''}`;
                new QRCode(qrWrap, {
                    text: qrText,
                    width: 55,
                    height: 55,
                    correctLevel: QRCode.CorrectLevel.H
                });

                return card;
            }


            // Download helper (PNG + PDF)
            async function downloadElement(element) {
                const {
                    jsPDF
                } = window.jspdf;

                const canvas = await html2canvas(element, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff'
                });
                const imgData = canvas.toDataURL('image/png');

                // PNG download
                const a = document.createElement('a');
                a.href = imgData;
                a.download = 'Accofinda-Card.png';
                a.click();

                // PDF download
                const pdf = new jsPDF({
                    orientation: 'landscape',
                    unit: 'pt',
                    format: [canvas.width, canvas.height]
                });
                pdf.addImage(imgData, 'PNG', 0, 0, canvas.width, canvas.height);
                pdf.save('Accofinda-Card.pdf');
            }

            // Escape HTML to prevent XSS
            function escapeHtml(str) {
                if (!str) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
        });

        $(document).ready(function() {

            // Fill modal when clicking update button
            $(document).on('click', '.update-btn', function() {
                $('#updateId').val($(this).data('id'));
                $('#updateName').val($(this).data('fullname'));
                $('#updateRole').val($(this).data('role'));
                $('#updateArea').val($(this).data('area'));
                $('#updateNationalId').val($(this).data('nationalid') || '');
                $('#updateModal').modal('show');
            });

            // Handle form submission via AJAX
            $('#updateForm').submit(function(e) {
                e.preventDefault(); // prevent default form submit

                // Use FormData to handle file uploads
                var formData = new FormData(this);

                $.ajax({
                    url: $(this).attr('action'),
                    type: 'POST',
                    data: formData,
                    contentType: false, // required for FormData
                    processData: false, // required for FormData
                    success: function(response) {
                        // Ensure JSON parsing
                        try {
                            var resp = typeof response === 'string' ? JSON.parse(response) : response;
                        } catch (e) {
                            alert('Invalid response from server.');
                            return;
                        }

                        if (resp.status === 'ok') {
                            // Close modal
                            $('#updateModal').modal('hide');

                            // Show 5-second success message
                            $('#updateSuccess').removeClass('d-none').fadeIn();
                            setTimeout(() => {
                                $('#updateSuccess').fadeOut();
                            }, 5000);

                            // Optional: refresh table row dynamically or reload table
                            // For now, simply reload page to reflect changes
                            location.reload();
                        } else {
                            alert('Update failed: ' + resp.msg);
                        }
                    },
                    error: function() {
                        alert('AJAX request failed. Please try again.');
                    }
                });
            });
        });
        $(document).ready(function() {
            function showSuccess(msg = 'Action completed successfully!') {
                $('#deleteSuccess').text(msg).removeClass('d-none').fadeIn();
                setTimeout(() => {
                    $('#deleteSuccess').fadeOut();
                }, 5000);
            }

            // Single delete
            $(document).on('click', '.delete-btn', function() {
                if (!confirm('Delete this card?')) return;
                var id = $(this).data('id');
                $.post('<?= basename(__FILE__) ?>', {
                    action: 'delete_cards',
                    ids: [id]
                }, function(resp) {
                    if (resp.status === 'ok') {
                        $('tr[data-id="' + id + '"]').remove();
                        showSuccess(resp.msg);
                    } else alert(resp.msg);
                }, 'json');
            });

            // Delete selected
            $('#deleteSelectedBtn').click(function() {
                var ids = $('.select-card:checked').map(function() {
                    return $(this).val();
                }).get();
                if (ids.length === 0) return alert('No cards selected!');
                if (!confirm('Delete selected cards?')) return;
                $.post('<?= basename(__FILE__) ?>', {
                    action: 'delete_cards',
                    ids: ids
                }, function(resp) {
                    if (resp.status === 'ok') {
                        ids.forEach(id => $('tr[data-id="' + id + '"]').remove());
                        showSuccess(resp.msg);
                    } else alert(resp.msg);
                }, 'json');
            });

            // Delete all
            $('#deleteAllBtn').click(function() {
                if (!confirm('Delete all cards?')) return;
                $.post('<?= basename(__FILE__) ?>', {
                    action: 'delete_cards',
                    all: true
                }, function(resp) {
                    if (resp.status === 'ok') {
                        $('#generatedTable tbody').empty();
                        showSuccess(resp.msg);
                    } else alert(resp.msg);
                }, 'json');
            });

            // Select all toggle
            $('#selectAll').change(function() {
                $('.select-card').prop('checked', this.checked);
            });
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const updateModal = new bootstrap.Modal(document.getElementById('updateModal'));
            const updateForm = document.getElementById('updateForm');

            document.querySelectorAll('.update-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('updateId').value = btn.dataset.id;
                    document.getElementById('updateRole').value = btn.dataset.role;
                    document.getElementById('updateArea').value = btn.dataset.area;
                    document.getElementById('updateName').value = btn.dataset.fullname;
                    updateModal.show();
                });
            });
        });
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.national-id').forEach(function(td) {
                td.addEventListener('click', function() {
                    td.classList.toggle('revealed');
                });
            });
        });
    </script>
    <!-- Auto Search Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('cardSearch');
            const table = document.getElementById('generatedTable');
            const tbody = table.querySelector('tbody');
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                Array.from(tbody.rows).forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    row.style.display = rowText.includes(query) ? '' : 'none';
                });
            });
        });
    </script>

</body>

</html>