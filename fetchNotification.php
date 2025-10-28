<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config.php'; // adjust if needed

// ✅ Ensure Nairobi timezone
date_default_timezone_set('Africa/Nairobi');

$userId = $_SESSION['id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';

if (!$userId) {
    return; // user not logged in
}

// Fetch latest unread (role OR user), ignoring already read
$stmt = $conn->prepare("
    SELECT sn.* 
    FROM system_notifications sn
    LEFT JOIN notification_reads nr 
        ON sn.id = nr.notification_id AND nr.user_id = ?
    WHERE (sn.user_id = ? OR sn.role = ?)
      AND nr.id IS NULL
    ORDER BY sn.created_at DESC
    LIMIT 1
");
$stmt->bind_param("iis", $userId, $userId, $userRole);
$stmt->execute();
$result = $stmt->get_result();
$notification = $result->fetch_assoc();

// Compute reliable URL for markNotificationRead.php
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$parentDir = dirname($scriptDir);
$markUrl = $parentDir . '/Shared/markNotificationRead';
$markUrl = preg_replace('#/+#', '/', $markUrl);
?>
<?php if ($notification): ?>
    <div id="notificationOverlay" class="overlay">
        <div id="notificationBanner" class="notification-banner">
            <div class="banner-content">
                <div class="banner-header">
                    <span class="icon">🔔</span>
                    <h2><?= htmlspecialchars($notification['title'] ?? "System Notification") ?></h2>
                </div>
                <div class="banner-body">
                    <p><?= nl2br(htmlspecialchars($notification['message'])) ?></p>
                </div>
                <div class="banner-footer">
                    <!-- ✅ Date as black badge button -->
                    <span class="timestamp btn btn-dark btn-sm disabled">
                        <?= date("M d, Y H:i", strtotime($notification['created_at'])) ?>
                    </span>
                    <button id="confirmBtn" onclick="markAsRead(<?= (int)$notification['id'] ?>)">✅ Confirm Read</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Success toast on TOP -->
    <div id="successToast" class="toast">✅ Notification marked as read!</div>

    <style>
        /* overlay */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 99999;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px 10px;
        }

        /* notification banner */
        .notification-banner {
            background: #fff;
            border-radius: 10px;
            width: 100%;
            max-width: 600px;
            margin-top: 20px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2);
            font-family: 'Segoe UI', sans-serif;
            animation: slideDown .4s ease;
            overflow: hidden;
        }

        .banner-content {
            display: flex;
            flex-direction: column;
        }

        .banner-header {
            display: flex;
            align-items: center;
            margin: 40px 30px 5px;
            background: #2b585f;
            color: #fff;
            padding: 16px 18px;
            /* ✅ increased height */
        }

        .banner-header .icon {
            font-size: 20px;
            /* a little bigger */
            margin-right: 25px;
        }

        .banner-header h2 {
            font-size: 18px;
            /* ✅ larger title font */
            font-weight: 600;
            margin: 0;
            line-height: 1.4;
            /* gives extra breathing space */
        }

        .banner-body {
            padding: 14px 16px;
            color: #333;
            font-size: 12px;
            line-height: 1.6;
            margin: 5px 30px;
            word-wrap: break-word;
        }

        .banner-footer {
            padding: 10px 14px;
            background: #f4f4f4;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        /* ✅ timestamp styled like badge on left */
        .banner-footer .timestamp {
            font-size: 10px;
            background: #000;
            color: #fff;
            padding: 4px 18px;
            border-radius: 4px;
        }

        /* ✅ button small + bottom-right aligned */
        .banner-footer button {
            background: #4270b4ff;
            border: none;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 5px;
            cursor: pointer;
            transition: background .3s;
        }

        .banner-footer button:hover {
            background: #333;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-40px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* ✅ toast now on TOP */
        .toast {
            position: fixed;
            top: 15px;
            right: 20px;
            background: #198754;
            color: #fff;
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            opacity: 0;
            pointer-events: none;
            transition: opacity .4s ease;
            z-index: 100000;
        }

        .toast.show {
            opacity: 1;
            pointer-events: auto;
        }

        /* ✅ mobile responsiveness */
        @media (max-width: 500px) {
            .notification-banner {
                width: 100%;
                max-width: 95%;
                font-size: 18px;
            }

            .banner-body {
                font-size: 13px;
            }

            .banner-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .banner-footer button {
                align-self: flex-end;
            }
        }
    </style>



    <script>
        const markEndpoint = '<?= htmlspecialchars($markUrl, ENT_QUOTES) ?>';

        function markAsRead(id) {
            const btn = document.getElementById('confirmBtn');
            if (btn) btn.disabled = true;

            fetch(markEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'id=' + encodeURIComponent(id)
                })
                .then(async response => {
                    const text = await response.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Non-JSON response:', text);
                        alert('Unexpected server response.');
                        if (btn) btn.disabled = false;
                        return;
                    }

                    if (response.ok && data.status === 'ok') {
                        const overlay = document.getElementById('notificationOverlay');
                        if (overlay) overlay.style.display = 'none';

                        const toast = document.getElementById('successToast');
                        toast.classList.add('show');
                        setTimeout(() => toast.classList.remove('show'), 5000);
                    } else {
                        alert('Error: ' + (data.message || 'Could not mark as read'));
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    alert('Network error.');
                    if (btn) btn.disabled = false;
                });
        }
    </script>
<?php endif; ?>