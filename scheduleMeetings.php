<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ✅ Restrict access (only Admins can schedule meetings)
if (!isset($_SESSION["email"]) || strtolower($_SESSION["role"]) !== "admin") {
    header("Location: ../index");
    exit();
}

require '../config.php';
date_default_timezone_set('Africa/Nairobi');
$success = $error = "";

// ✅ Handle meeting cancellation
if (isset($_GET['cancel_id'])) {
    $id = intval($_GET['cancel_id']);
    $conn->query("DELETE FROM meetings WHERE id=$id");
    $_SESSION['success_msg'] = "✅ Meeting cancelled successfully!";
    header("Location: scheduleMeetings");
    exit();
}

// ✅ Handle new meeting creation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submitted'])) {
    $title        = trim($_POST['title']);
    $agenda       = trim($_POST['agenda']);
    $date         = $_POST['date'];
    $time         = $_POST['time'];
    $platform     = $_POST['platform'];
    $meeting_link = ($platform === "In-Person") ? "" : trim($_POST['meeting_link']);
    $notes        = trim($_POST['notes']);

    if (empty($title) || empty($agenda) || empty($date) || empty($time) || empty($platform)) {
        $error = "❌ All required fields must be filled.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO meetings (title, meeting_date, meeting_time, audience, agenda, platform, meeting_link, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $audience = "Admins"; // 🔹 Fixed to Admins only
        $stmt->bind_param("ssssssss", $title, $date, $time, $audience, $agenda, $platform, $meeting_link, $notes);

        if ($stmt->execute()) {
            // ✅ Send Email Notification to Admins
            $meetingDateTime = date("F j, Y", strtotime($date)) . " at " . date("g:i A", strtotime($time));
            $subject = "Accofinda Meeting Scheduled: $title";

            $body = "Hello Admin,\n\n" .
                "A new meeting has been scheduled:\n\n" .
                "Title: $title\n" .
                "Date & Time: $meetingDateTime\n" .
                "Agenda: $agenda\n" .
                "Platform: $platform\n";

            if ($platform !== "In-Person" && $meeting_link) {
                $body .= "Meeting Link: $meeting_link\n";
            }
            if (!empty($notes)) {
                $body .= "Notes: $notes\n";
            }
            $body .= "\nAudience: Admins\n";
            $body .= "Visit the portal for more details.\n\n";
            $body .= "Regards,\nAccofinda Team";

            $headers  = "From: Accofinda <noreply@accofinda.com>\r\n";
            $headers .= "Reply-To: support@accofinda.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            // 🔹 Fetch Admin emails from Accofinda users table
            $res = $conn->query("SELECT email FROM users WHERE role = 'Admin' AND status = 'active'");
            while ($row = $res->fetch_assoc()) {
                @mail($row['email'], $subject, $body, $headers);
            }

            $_SESSION['success_msg'] = "✅ Meeting scheduled successfully and notifications sent!";
            header("Location: scheduleMeetings");
            exit();
        } else {
            $error = "❌ Failed to schedule meeting.";
        }
        $stmt->close();
    }
}

// ✅ Handle deleting past meetings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_past_meeting_id'])) {
    $deleteId = intval($_POST['delete_past_meeting_id']);
    $stmt = $conn->prepare("DELETE FROM meetings WHERE id = ?");
    $stmt->bind_param("i", $deleteId);

    if ($stmt->execute()) {
        $_SESSION['success_msg'] = "✅ Past meeting deleted successfully.";
    } else {
        $_SESSION['error_msg'] = "❌ Failed to delete past meeting.";
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ Fetch upcoming & past meetings
$now = date("Y-m-d H:i:s");
$upcoming = $past = [];
$result = $conn->query("SELECT * FROM meetings ORDER BY meeting_date DESC, meeting_time DESC");
while ($m = $result->fetch_assoc()) {
    $dt = "{$m['meeting_date']} {$m['meeting_time']}";
    if ($dt >= $now) $upcoming[] = $m;
    else $past[] = $m;
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Schedule Meetings | Accofinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(to right, #f9f9f9, #e6e6ff);
            font-family: 'Segoe UI', sans-serif;
            padding: 20px;
            margin: 0;
        }

        .panel {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .platform-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .platform-btn {
            border: 2px solid #ccc;
            border-radius: 8px;
            padding: 16px 10px;
            text-align: center;
            cursor: pointer;
            background: #f9f9f9;
            transition: background 0.3s, border-color 0.3s;
            flex: 1 1 100%;
            user-select: none;
        }

        .platform-btn.active {
            border-color: #0d6efd;
            background: #e7f0ff;
        }

        .platform-btn input[type="radio"] {
            display: none;
        }

        .icon {
            font-size: 1.8rem;
            display: block;
            margin-bottom: 6px;
        }

        .small-btn {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
        }

        @media (min-width: 576px) {
            .platform-btn {
                flex: 1 1 calc(50% - 10px);
            }
        }

        @media (min-width: 768px) {
            .platform-btn {
                flex: 1 1 calc(25% - 10px);
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success" id="msgSuccess">
                <?= htmlspecialchars($_SESSION['success_msg']) ?><br>
                📧 Email notifications sent to Admins.
            </div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php elseif (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger" id="msgError">
                <?= htmlspecialchars($_SESSION['error_msg']); ?>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Schedule Form -->
            <div class="col-md-6 panel">
                <h4>📅 Schedule New Meeting</h4>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="submitted" value="true">
                    <label>Title*</label>
                    <input type="text" name="title" class="form-control mb-2" required>
                    <label>Agenda*</label>
                    <textarea name="agenda" class="form-control mb-2" required></textarea>
                    <div class="row mb-2 d-flex flex-wrap platform-grid">
                        <div class="col">
                            <label>Date*</label>
                            <input type="date" name="date" class="form-control" required>
                        </div>
                        <div class="col">
                            <label>Time*</label>
                            <input type="time" name="time" class="form-control" required>
                        </div>
                    </div>
                    <label>Platform*</label>
                    <div class="platform-grid mb-3">
                        <?php
                        $platforms = [
                            'Zoom' => '📹 Zoom',
                            'Teams' => '💼 Teams',
                            'Google Meet' => '🧑‍💻 Google Meet',
                            'In-Person' => '🏢 In-Person'
                        ];
                        foreach ($platforms as $val => $lbl):
                            $sel = (isset($_POST['platform']) && $_POST['platform'] === $val) ? 'active' : '';
                        ?>
                            <label class="platform-btn <?= $sel ?>">
                                <input type="radio" name="platform" value="<?= $val ?>" onchange="togglePlatform(this)" required <?= $sel ? 'checked' : '' ?>>
                                <span class="icon"><?= explode(' ', $lbl)[0] ?></span><br><?= explode(' ', $lbl, 2)[1] ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label>Meeting Link*</label>
                    <input type="url" name="meeting_link" id="meeting_link" class="form-control mb-2" placeholder="https://your-meeting-link">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control mb-2"></textarea>
                    <button type="submit" class="btn btn-primary">✅ Schedule Meeting</button>
                    <a href="" class="btn btn-secondary">⬅️ Back</a>
                </form>
            </div>

            <!-- Upcoming Meetings -->
            <div class="col-md-6 panel">
                <h5 class="mb-3">✅ Upcoming Meetings</h5>
                <table class="table table-bordered text-center align-middle table-sm" style="font-size: 0.78rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Platform</th>
                            <th>Countdown</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $m):
                            $ts = strtotime("{$m['meeting_date']} {$m['meeting_time']}");
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($m['title']) ?></td>
                                <td><?= $m['meeting_date'] ?></td>
                                <td><?= $m['meeting_time'] ?></td>
                                <td><?= htmlspecialchars($m['platform']) ?></td>
                                <td><span class="countdown" data-time="<?= $ts ?>"></span></td>
                                <td class="p-0 align-middle text-center">
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <?php if (!empty($m['meeting_link'])): ?>
                                            <a href="<?= htmlspecialchars($m['meeting_link']) ?>" target="_blank" class="btn btn-info btn-sm px-2 py-1" style="font-size: 0.7rem;">🔗 Join</a>
                                        <?php endif; ?>
                                        <a href="?cancel_id=<?= $m['id'] ?>" class="btn btn-danger btn-sm px-2 py-1" style="font-size: 0.7rem;" onclick="return confirm('Cancel this meeting?')">❌ Cancel</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($upcoming)): ?>
                            <tr>
                                <td colspan="6" class="text-muted">No upcoming meetings</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Past Meetings -->
        <div class="panel">
            <h4>📌 Past Meetings</h4>
            <table class="table table-bordered text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Platform</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($past as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['title']) ?></td>
                            <td><?= $m['meeting_date'] ?></td>
                            <td><?= $m['meeting_time'] ?></td>
                            <td><?= htmlspecialchars($m['platform']) ?></td>
                            <td><?= htmlspecialchars($m['notes']) ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('⚠️ Delete this past meeting?');" class="d-inline">
                                    <input type="hidden" name="delete_past_meeting_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($past)): ?>
                        <tr>
                            <td colspan="6" class="text-muted">No past meetings</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- JS -->
    <script>
        function togglePlatform(el) {
            document.querySelectorAll('.platform-btn').forEach(b => b.classList.remove('active'));
            el.closest('.platform-btn').classList.add('active');
            handleLinkRequirement(el.value);

            const urls = {
                "Zoom": "https://zoom.us/start/videomeeting",
                "Teams": "https://teams.microsoft.com/l/meeting/new",
                "Google Meet": "https://meet.google.com/new"
            };
            if (urls[el.value]) window.open(urls[el.value], '_blank');
        }

        function handleLinkRequirement(platform) {
            const linkField = document.getElementById('meeting_link');
            if (platform === "In-Person") {
                linkField.value = '';
                linkField.disabled = true;
                linkField.removeAttribute('required');
                linkField.placeholder = 'No link required for in-person meeting';
            } else {
                linkField.disabled = false;
                linkField.setAttribute('required', 'required');
                linkField.placeholder = 'https://your-meeting-link';
            }
        }

        function updateCountdowns() {
            const now = Math.floor(Date.now() / 1000);
            document.querySelectorAll('.countdown').forEach(el => {
                const target = parseInt(el.dataset.time);
                const diff = target - now;
                if (diff <= 0) {
                    el.textContent = "⏱️ Started";
                } else {
                    const days = Math.floor(diff / 86400);
                    const hours = Math.floor((diff % 86400) / 3600);
                    const minutes = Math.floor((diff % 3600) / 60);
                    el.textContent = `${days}d : ${String(hours).padStart(2, '0')}hrs : ${String(minutes).padStart(2, '0')}min`;
                }
            });
        }
        setInterval(updateCountdowns, 1000);
        updateCountdowns();
        setTimeout(() => {
            document.getElementById('msgSuccess')?.remove();
            document.getElementById('msgError')?.remove();
        }, 5000);
    </script>
</body>

</html>