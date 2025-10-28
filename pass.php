<?php
session_start();
require '../config.php';

// ----------------------------
// Company Setup
// ----------------------------
$companyName = "Accofinda Limited";
$companyLogo = "../Images/AccofindaLogo1.jpg"; // fixed company logo path
$companyWebsite = "www.accofinda.com";

// ----------------------------
// Fetch all users
// ----------------------------
$users = [];
$sql = "SELECT id, full_name, email, phone_number, role, national_id, profile_photo, gender, date_of_birth 
        FROM users ORDER BY full_name ASC";
$result = $conn->query($sql);
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// ----------------------------
// Handle form
// ----------------------------
$selectedUser = null;
$newUser = null;
$assignment = "";
$mode = $_POST['mode'] ?? ($_GET['mode'] ?? "existing");
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignment = $_POST['assignment'] ?? "";

    if ($mode === "existing") {
        $userId = $_POST['user_id'] ?? null;

        if (!$userId) {
            $errors[] = "Please select an existing user.";
        } else {
            // Handle passport photo upload
            if (!empty($_FILES['passport_photo']['name'])) {
                $targetDir = "uploads/passports/";
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

                $fileName = "passport_" . $userId . "_" . time() . ".jpg";
                $targetFile = $targetDir . $fileName;

                if (move_uploaded_file($_FILES['passport_photo']['tmp_name'], $targetFile)) {
                    $stmt = $conn->prepare("UPDATE users SET profile_photo=? WHERE id=?");
                    $stmt->bind_param("si", $targetFile, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // Load user data
            $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $selectedUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif ($mode === "new") {
        // simple server-side validation for new user
        $new_full_name = trim($_POST['new_full_name'] ?? "");
        $new_email = trim($_POST['new_email'] ?? "");
        $new_phone = trim($_POST['new_phone'] ?? "");
        $new_role = trim($_POST['new_role'] ?? "");
        $new_id = trim($_POST['new_id'] ?? "");
        $new_gender = trim($_POST['new_gender'] ?? "");

        if ($new_full_name === "" || $new_email === "" || $new_phone === "" || $new_role === "" || $new_id === "" || $new_gender === "") {
            $errors[] = "Please fill all new user fields.";
        } else {
            $newUser = [
                "full_name"    => $new_full_name,
                "email"        => $new_email,
                "phone_number" => $new_phone,
                "role"         => $new_role,
                "national_id"  => $new_id,
                "gender"       => $new_gender,
                "profile_photo" => ""
            ];

            // Handle passport photo upload
            if (!empty($_FILES['passport_photo']['name'])) {
                $targetDir = "uploads/passports/";
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

                $fileName = "passport_new_" . time() . ".jpg";
                $targetFile = $targetDir . $fileName;

                if (move_uploaded_file($_FILES['passport_photo']['tmp_name'], $targetFile)) {
                    $newUser['profile_photo'] = $targetFile;
                }
            }
        }
    }
}

// ----------------------------
// Load user by GET (optional)
// ----------------------------
if (isset($_GET['user_id']) && !$selectedUser) {
    $userId = (int)$_GET['user_id'];
    $assignment = $_GET['assignment'] ?? "";

    $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $selectedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Accofinda | Business Card Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        body {
            background-color: #6c757d;
            color: #fff;
        }

        .navbar {
            background-color: #212529;
        }

        .container {
            max-width: 950px;
        }

        .card-preview {
            width: 450px;
            height: 270px;
            background: #fff;
            color: #000;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
            padding: 15px;
        }

        .company-logo {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 100px;
            height: 100px;
            object-fit: contain;

            /* Background box styling */
            background-color: #000;
            /* light gray or your preferred color */
            padding: 4px;
            /* space around the logo */
            border-radius: 10px;
            /* smooth rounded corners */
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
            /* optional shadow for depth */
        }

        .passport-photo {
            width: 80px;
            height: 100px;
            border: 2px solid #000;
            object-fit: cover;
        }

        .details {
            margin-left: 8px;
            font-size: 12px;
        }

        /* ✅ Super-small QR Code Styling */
        .qr-wrapper {
            position: absolute;
            bottom: 35px;
            right: 40px;
            width: 35px;
            /* was 55px — 9now tiny */
            height: 35px;
        }

        .qr-wrapper #qrCodeBox {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ✅ Optional: reduce overlay too */
        .qr-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 12px;
            /* was 18px — now proportionally small */
            height: 12px;
            transform: translate(-50%, -50%);
            border-radius: 3px;
            background: #fff;
            padding: 0.5px;
        }

        .company-website {
            position: absolute;
            bottom: 10px;
            left: 15px;
            font-size: 12px;
            color: #1f2937;
        }

        .errors {
            color: #ffdddd;
            background: rgba(0, 0, 0, 0.25);
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 12px;
        }
    </style>

</head>

<body>
    <nav class="navbar navbar-dark px-3 mb-4">
        <span class="navbar-brand mb-0 h1">Accofinda Business Card Generator</span>
    </nav>

    <div class="container">
        <?php if (!empty($errors)): ?>
            <div class="errors">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="bg-dark p-4 rounded shadow mb-4">
            <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end" id="cardForm">
                <input type="hidden" name="mode" id="modeHidden" value="<?= htmlspecialchars($mode) ?>">

                <div class="col-md-3">
                    <label class="form-label">Mode</label>
                    <select name="mode_ui" id="modeSelect" class="form-select">
                        <option value="existing" <?= ($mode === 'existing') ? 'selected' : '' ?>>Existing User</option>
                        <option value="new" <?= ($mode === 'new') ? 'selected' : '' ?>>New User</option>
                    </select>
                </div>

                <div class="col-md-4 mode-existing <?= ($mode === 'new') ? 'd-none' : '' ?>">
                    <label class="form-label">Select User</label>
                    <select name="user_id" id="userDropdown" class="form-select">
                        <option value="">-- Choose User --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($selectedUser && $selectedUser['id'] == $u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name'] ?? '') ?> (<?= htmlspecialchars($u['role'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-9 mode-new <?= ($mode === 'new') ? '' : 'd-none' ?>">
                    <div class="row g-2">
                        <div class="col-md-4"><input type="text" name="new_full_name" class="form-control" placeholder="Full Name" value="<?= htmlspecialchars($_POST['new_full_name'] ?? '') ?>"></div>
                        <div class="col-md-4"><input type="email" name="new_email" class="form-control" placeholder="Email" value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>"></div>
                        <div class="col-md-4"><input type="text" name="new_phone" class="form-control" placeholder="Phone" value="<?= htmlspecialchars($_POST['new_phone'] ?? '') ?>"></div>
                        <div class="col-md-4"><input type="text" name="new_role" class="form-control" placeholder="Role" value="<?= htmlspecialchars($_POST['new_role'] ?? '') ?>"></div>
                        <div class="col-md-4"><input type="text" name="new_id" class="form-control" placeholder="National ID" value="<?= htmlspecialchars($_POST['new_id'] ?? '') ?>"></div>
                        <div class="col-md-4">
                            <select name="new_gender" class="form-select">
                                <option value="">-- Gender --</option>
                                <option value="Male" <?= (($_POST['new_gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= (($_POST['new_gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Passport Photo (optional)</label>
                    <input type="file" name="passport_photo" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Allocated Area</label>
                    <input type="text" name="assignment" value="<?= htmlspecialchars($assignment ?? '') ?>" class="form-control">
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-danger">Generate Card</button>
                </div>
            </form>
        </div>

        <?php if ($selectedUser || $newUser):
            $cardUser = $selectedUser ?? $newUser; ?>
            <div id="card-area" class="card-preview mx-auto">
                <img src="<?= htmlspecialchars($companyLogo) ?>" class="company-logo" alt="Company Logo">
                <div class="d-flex">
                    <img src="<?= htmlspecialchars($cardUser['profile_photo'] ?? 'https://via.placeholder.com/80x100') ?>" class="passport-photo me-2" alt="Passport">
                    <div class="details">
                        <h4 class="fw-bold text-primary"><?= htmlspecialchars($companyName) ?></h4>
                        <h5 class="fw-bold"><?= htmlspecialchars($cardUser['full_name'] ?? '') ?></h5>
                        <p class="mb-1">Role: <?= htmlspecialchars($cardUser['role'] ?? '') ?></p>
                        <p class="mb-1">Phone: <?= htmlspecialchars($cardUser['phone_number'] ?? '') ?></p>
                        <p class="mb-1">Email: <?= htmlspecialchars($cardUser['email'] ?? '') ?></p>
                        <p class="mb-1">ID: <?= htmlspecialchars($cardUser['national_id'] ?? '') ?></p>
                        <p class="mb-1">Gender: <?= htmlspecialchars($cardUser['gender'] ?? '') ?></p>
                        <?php if (!empty($assignment)): ?>
                            <p class="mb-1">Allocated Area: <?= htmlspecialchars($assignment ?? '') ?></p>
                        <?php endif; ?>
                        <p class="mb-1">Card Serial No: <?= htmlspecialchars($cardUser['id'] ?? '') ?></p>
                    </div>
                </div>

                <div class="qr-wrapper">
                    <div id="qrCodeBox"></div>
                    <img src="<?= htmlspecialchars($companyLogo) ?>" class="qr-overlay" alt="Logo Overlay">
                </div>

                <div class="company-website">Website: <?= htmlspecialchars($companyWebsite) ?></div>
            </div>

            <div class="text-center mt-3">
                <button class="btn btn-success" onclick="downloadCard()">Download Card</button>
            </div>

            <script>
                (function() {
                    const qrBox = document.getElementById("qrCodeBox");
                    qrBox.innerHTML = "";

                    const qrText = "Name: <?= addslashes($cardUser['full_name'] ?? '') ?> | Email: <?= addslashes($cardUser['email'] ?? '') ?> | Phone: <?= addslashes($cardUser['phone_number'] ?? '') ?> | Role: <?= addslashes($cardUser['role'] ?? '') ?> | ID: <?= addslashes($cardUser['national_id'] ?? '') ?> | Company: Accofinda Limited | Website: www.Accofinda.com";

                    // 🔹 Step 1: Generate high-res QR (internal 400px for clarity)
                    const internalSize = 90;
                    const visibleSize = 40; // ultra-small visible size

                    const qr = new QRCode(qrBox, {
                        text: qrText,
                        width: internalSize,
                        height: internalSize,
                        correctLevel: QRCode.CorrectLevel.H
                    });

                    // 🔹 Step 2: Visually shrink while keeping sharpness
                    setTimeout(() => {
                        const canvas = qrBox.querySelector('canvas');
                        if (canvas) {
                            canvas.style.width = visibleSize + 'px';
                            canvas.style.height = visibleSize + 'px';
                            canvas.style.imageRendering = 'crisp-edges';
                            canvas.style.transform = 'translateZ(0)'; // smooth scaling
                        }
                    }, 150);
                })();
            </script>

        <?php endif; ?>
    </div>

    <script>
        // Mode select handler
        const modeSelect = document.getElementById("modeSelect");
        modeSelect.addEventListener("change", function() {
            const mode = this.value;
            document.querySelector(".mode-existing").classList.toggle("d-none", mode !== "existing");
            document.querySelector(".mode-new").classList.toggle("d-none", mode !== "new");
            document.getElementById("modeHidden").value = mode;
        });

        function downloadCard() {
            const card = document.getElementById("card-area");
            html2canvas(card, {
                scale: 2,
                useCORS: true
            }).then(canvas => {
                const link = document.createElement("a");
                link.download = "Accofindan Business Card.png";
                link.href = canvas.toDataURL("image/png");
                link.click();
            });
        }
    </script>
</body>

</html>