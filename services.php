<?php
session_start();
require '../config.php';

// ✅ Access Control
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'tenant', 'admin', 'manager', 'service provider'])) {
    header("Location: ../login.php");
    exit();
}

// ✅ Helper function
function safe($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$userId = $_SESSION['id']; // Current logged-in user

// ✅ Fetch approved service providers
$query = "SELECT id, full_name, username, email, phone_number, role, service_type, serviceprovider_status, gender, date_of_birth, 
                 national_id, verification_status, bio, address_line1, address_line2, city, state, country, postal_code 
          FROM users 
          WHERE role = 'service provider' AND serviceprovider_status = 'approved'";
$result = $conn->query($query);

// ✅ Group providers by service_type
$providers = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $service = !empty($row['service_type']) ? $row['service_type'] : 'Other';

        // ✅ Fetch unread messages count for this provider
        $stmt = $conn->prepare("SELECT COUNT(*) AS unread FROM service_messages WHERE sender_id=? AND receiver_id=? AND is_read=0");
        $stmt->bind_param("ii", $row['id'], $userId);
        $stmt->execute();
        $unread = $stmt->get_result()->fetch_assoc()['unread'] ?? 0;
        $stmt->close();

        $row['unread_messages'] = $unread;

        $providers[$service][] = $row;
    }
}

// ✅ Define categories (colors & icons)
$services = [
    "Repairs" => ["color" => "#ffe0e0", "icon" => "fa-tools", "iconColor" => "#e53935"],
    "Plumbing" => ["color" => "#e0f7fa", "icon" => "fa-faucet", "iconColor" => "#0097a7"],
    "Electrical" => ["color" => "#fff3e0", "icon" => "fa-bolt", "iconColor" => "#f57c00"],
    "Cleaning" => ["color" => "#e8f5e9", "icon" => "fa-broom", "iconColor" => "#388e3c"],
    "Laundry" => ["color" => "#ede7f6", "icon" => "fa-shirt", "iconColor" => "#6a1b9a"],
    "Wi-Fi Setup" => ["color" => "#f3e5f5", "icon" => "fa-wifi", "iconColor" => "#8e24aa"],
    "Movers" => ["color" => "#f9fbe7", "icon" => "fa-truck", "iconColor" => "#827717"],
    "Furniture/Fittings" => ["color" => "#e1f5fe", "icon" => "fa-couch", "iconColor" => "#0277bd"],
    "Gas Refill" => ["color" => "#fbe9e7", "icon" => "fa-warehouse", "iconColor" => "#d84315"],
    "Transport Services" => ["color" => "#f1f8e9", "icon" => "fa-motorcycle", "iconColor" => "#2e7d32"],
    "Garbage Pickup" => ["color" => "#edeef2", "icon" => "fa-trash", "iconColor" => "#37474f"],
    "Security" => ["color" => "#fce4ec", "icon" => "fa-shield-alt", "iconColor" => "#c2185b"],
    "Saloon & Barber Sevices" => ["color" => "#f0f4c3", "icon" => "fa-wand-magic-sparkles", "iconColor" => "#000000ff"],
    "Fumigation Services" => ["color" => "#eecfbaff", "icon" => "fa-spider", "iconColor" => "#1976d2"],
    "Pedicure & Manicure" => ["color" => "#d19dccff", "icon" => "fa-hand-sparkles", "iconColor" => "#000000ff"],
    "Painters" => ["color" => "#88dee1ff", "icon" => "fa-paint-roller", "iconColor" => "#000000ff"],
    "Photography/Videography" => ["color" => "#e3f2fd", "icon" => "fa-camera", "iconColor" => "#660cbaff"],
    "Catering" => ["color" => "#accea4ff", "icon" => "fa-pizza-slice", "iconColor" => "#000000ff"],
    "Healthcare" => ["color" => "#97c1d8ff", "icon" => "fa-stethoscope", "iconColor" => "#000000ff"],
    "Touring" => ["color" => "#e3f2fd", "icon" => "fa-horse", "iconColor" => "#0c49baff"],
    "Gymnastics" => ["color" => "#eec48eff", "icon" => "fa-dumbbell", "iconColor" => "#ba3d0cff"],
    "Landscaping" => ["color" => "#c28997ff", "icon" => "fa-person-digging", "iconColor" => "#000000ff"],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Service Providers - AccoFinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #c0c0c0ff;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .category-card {
            border-radius: 18px;
            padding: 20px;
            height: 100%;
            min-height: 380px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
            transition: transform 0.2s;
            display: flex;
            flex-direction: column;
        }

        .category-card:hover {
            transform: translateY(-5px);
        }

        .category-header {
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .provider-list {
            max-height: 250px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .provider-card {
            background: #111;
            color: #eee;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            font-size: 0.85rem;
        }

        .provider-card small {
            font-size: 0.75rem;
        }

        .provider-card .btn {
            font-size: 0.7rem;
            padding: 3px 6px;
        }

        .sub-search {
            margin-bottom: 8px;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 6px;
        }

        footer {
            background: #000000ff;
            color: white;
            text-align: center;
            padding: 10px;
            margin-top: auto;
        }

        /* Scrollbar styling */
        .provider-list::-webkit-scrollbar {
            width: 6px;
        }

        .provider-list::-webkit-scrollbar-thumb {
            background: #666;
            border-radius: 5px;
        }

        /* ✅ Fix overlap + card width */
        .provider-card .d-flex {
            flex-wrap: wrap !important;
            gap: 5px !important;
        }

        @media (max-width: 767.98px) {
            .service-category {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg px-3 py-4" style="background: #000000ff; width:100%;">
        <a href="javascript:history.back()" class="btn btn-light me-3">
            <i class="fa fa-arrow-left"></i> Back
        </a>
        <div class="mx-auto text-white fw-bold fs-5">Our Services</div>
        <div class="ms-auto text-white fw-bold">AccoFinda</div>
    </nav>

    <!-- Search & Filters -->
    <div class="container my-4">
        <div class="search-section p-4 shadow-lg rounded-4"
            style="background: #ffffffff; max-width: 1350px; margin: 0 auto; border: 2px solid #52899aff;">

            <!-- Search Bar -->
            <div class="d-flex flex-wrap justify-content-start mb-3 gap-2">
                <input type="text" id="searchInput"
                    class="form-control shadow-sm"
                    placeholder="Search for services, providers, or locations..."
                    style="max-width: 1000px; border-radius: 10px; padding: 10px;"
                    onkeyup="filterServices()"> <!-- Auto search on typing -->
            </div>

            <!-- Filters -->
            <div class="d-flex justify-content-start gap-3 flex-wrap">

                <!-- Category Filter (dynamically generated) -->
                <select id="categoryFilter" class="form-select shadow-sm"
                    style="border-radius: 8px; max-width: 200px; text-align:center; font-size:0.8rem;"
                    onchange="filterServices()">
                    <option value="">All Categories</option>
                    <?php foreach ($services as $serviceName => $style): ?>
                        <option value="<?= htmlspecialchars($serviceName) ?>"><?= htmlspecialchars($serviceName) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Location Filter with Search -->
                <div class="county-filter-wrapper" style="max-width: 200px; position: relative;">
                    <!-- Search box -->
                    <input type="text" id="countySearch" class="form-control shadow-sm mb-1"
                        placeholder="Search county..." onkeyup="filterCountyOptions()"
                        style="border-radius: 8px; font-size:0.75rem; padding:4px 6px; text-align:center;">

                    <!-- Dropdown with 47 counties -->
                    <select id="locationFilter" class="form-select shadow-sm"
                        style="border-radius: 8px; font-size:0.75rem; text-align:center; height:32px;"
                        onchange="filterServices()">
                        <option value="">All Locations</option>
                        <?php
                        $counties = [
                            "Baringo",
                            "Bomet",
                            "Bungoma",
                            "Busia",
                            "Elgeyo Marakwet",
                            "Embu",
                            "Garissa",
                            "Homa Bay",
                            "Isiolo",
                            "Kajiado",
                            "Kakamega",
                            "Kericho",
                            "Kiambu",
                            "Kilifi",
                            "Kirinyaga",
                            "Kisii",
                            "Kisumu",
                            "Kitui",
                            "Kwale",
                            "Laikipia",
                            "Lamu",
                            "Machakos",
                            "Makueni",
                            "Mandera",
                            "Marsabit",
                            "Meru",
                            "Migori",
                            "Mombasa",
                            "Murang'a",
                            "Nairobi",
                            "Nakuru",
                            "Nandi",
                            "Narok",
                            "Nyamira",
                            "Nyandarua",
                            "Nyeri",
                            "Samburu",
                            "Siaya",
                            "Taita Taveta",
                            "Tana River",
                            "Tharaka Nithi",
                            "Trans Nzoia",
                            "Turkana",
                            "Uasin Gishu",
                            "Vihiga",
                            "Wajir",
                            "West Pokot"
                        ];
                        foreach ($counties as $county) {
                            echo '<option value="' . htmlspecialchars($county) . '">' . htmlspecialchars($county) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- Rating Filter -->
                <select id="ratingFilter" class="form-select shadow-sm"
                    style="border-radius: 8px; max-width: 150px; text-align:center; font-size:0.8rem;"
                    onchange="filterServices()">
                    <option value="">All Ratings</option>
                    <option value="5">5 Stars</option>
                    <option value="4">4+ Stars</option>
                    <option value="3">3+ Stars</option>
                </select>
            </div>
        </div>
    </div>
    <!-- Services Section -->
    <div class="container-fluid py-4">
        <div class="row g-3">
            <?php foreach ($services as $service => $style): ?>
                <div class="col-12 col-md-6 col-lg-4 service-category">
                    <div class="category-card h-100" style="background: <?= $style['color'] ?>;">
                        <div class="category-header" style="color: <?= $style['iconColor'] ?>;">
                            <i class="fa <?= $style['icon'] ?> fa-lg me-2"></i> <?= $service ?>
                        </div>

                        <!-- Sub-search within category -->
                        <input type="text" class="form-control sub-search mb-2"
                            placeholder="Search <?= $service ?>..."
                            onkeyup="filterCategory(this, '<?= $service ?>')">

                        <div class="provider-list" id="list-<?= $service ?>">
                            <?php if (!empty($providers[$service])): ?>
                                <?php foreach ($providers[$service] as $prov): ?>
                                    <div class="provider-card p-2 mb-3 border rounded position-relative">
                                        <!-- Status Badge -->
                                        <span class="badge bg-success text-light"
                                            style="position: absolute; top: 5px; right: 5px; font-size: 0.65rem; padding: 2px 6px;">
                                            <?= htmlspecialchars(ucfirst($prov['serviceprovider_status'] ?? 'pending')) ?>
                                        </span>

                                        <!-- Chat Notification -->
                                        <?php if ($prov['unread_messages'] > 0): ?>
                                            <span class="badge bg-danger position-absolute" style="top:5px; left:5px; font-size:0.65rem;">
                                                <?= $prov['unread_messages'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted position-absolute" style="top:5px; left:5px; font-size:0.65rem;">No chats yet</span>
                                        <?php endif; ?>

                                        <!-- Provider Info -->
                                        <strong><?= safe($prov['full_name']) ?></strong><br>

                                        <!-- Masked Email -->
                                        <?php
                                        $email = $prov['email'] ?? '';
                                        if ($email) {
                                            $parts = explode("@", $email);
                                            $namePart = $parts[0];
                                            $domainPart = $parts[1] ?? '';
                                            $maskedName = substr($namePart, 0, 2) . str_repeat('*', max(2, strlen($namePart) - 2));
                                            $maskedEmail = $maskedName . '@' . $domainPart;
                                        } else {
                                            $maskedEmail = 'N/A';
                                        }
                                        ?>
                                        <i class="fa fa-envelope me-1"></i> <?= htmlspecialchars($maskedEmail) ?><br>

                                        <i class="fa fa-map-marker-alt me-1"></i> <?= safe($prov['city']) ?><?= !empty($prov['country']) ? ', ' . safe($prov['country']) : '' ?><br>

                                        <?php if (!empty($prov['bio'])): ?>
                                            <small class="text-muted"><?= safe($prov['bio']) ?></small><br>
                                        <?php endif; ?>

                                        <!-- Action Buttons -->
                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <a href="mailto:support@accofinda.com" class="btn btn-sm btn-success">
                                                <i class="fa fa-envelope me-1"></i>Email
                                            </a>
                                            <a href="serviceChats?provider_id=<?= $prov['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fa fa-comments me-1"></i>Chat
                                            </a>
                                            <a href="viewServicesPage?provider_id=<?= $prov['id'] ?>" class="btn btn-sm btn-danger text-white">
                                                <i class="fa fa-user me-1"></i>View Services
                                            </a>
                                        </div>

                                        <!-- Request Service Button -->
                                        <a href="requestService?provider_id=<?= $prov['id'] ?>&service_type=<?= urlencode($service) ?>" class="btn btn-sm btn-warning" style="position: absolute; bottom: 10px; right: 10px; font-size: 0.75rem; padding: 3px 8px;">
                                            <i class="fa fa-plus-circle me-1"></i> Request Service
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">No approved providers yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>


    <footer>
        <p>&copy; <?= date("Y") ?> AccoFinda. All Rights Reserved</p>
    </footer>

    <script>
        function filterServices() {
            let search = document.getElementById("searchInput").value.toLowerCase();
            let category = document.getElementById("categoryFilter").value.toLowerCase();
            let location = document.getElementById("locationFilter").value.toLowerCase();
            let rating = document.getElementById("ratingFilter").value;

            let categories = document.querySelectorAll(".service-category");
            categories.forEach(cat => {
                let categoryName = cat.querySelector(".category-header").innerText.toLowerCase();
                let text = cat.innerText.toLowerCase(); // includes providers inside

                let matchesSearch = search === "" || text.includes(search) || categoryName.includes(search);
                let matchesCategory = category === "" || categoryName.includes(category);
                let matchesLocation = location === "" || text.includes(location);
                let matchesRating = rating === "" || text.includes(rating);

                cat.style.display = (matchesSearch && matchesCategory && matchesLocation && matchesRating) ? "block" : "none";
            });
        }
    </script>

    <script>
        function filterCategory(input, categoryName) {
            let filter = input.value.toLowerCase();
            let list = document.getElementById("list-" + categoryName);

            if (!list) return;

            let providers = list.querySelectorAll(".provider-card");
            providers.forEach(card => {
                let text = card.innerText.toLowerCase();
                if (text.includes(filter)) {
                    card.style.display = "block";
                } else {
                    card.style.display = "none";
                }
            });
        }
    </script>
    <!-- JS: Search functionality for counties -->
    <script>
        function filterCountyOptions() {
            const input = document.getElementById("countySearch").value.toLowerCase();
            const select = document.getElementById("locationFilter");
            for (let i = 0; i < select.options.length; i++) {
                let option = select.options[i];
                if (i === 0) continue; // Skip "All Locations"
                let txt = option.text.toLowerCase();
                option.style.display = txt.includes(input) ? "" : "none";
            }
        }
    </script>
</body>

</html>