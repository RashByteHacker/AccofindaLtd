<?php
require 'config.php';

// --- Pagination setup ---
$limit = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $limit;

// --- Search Filters ---
$whereClauses = [];
$params = [];
$types = "";

// Location filter
if (!empty($_GET['location'])) {
    $whereClauses[] = "(p.location LIKE ? OR p.city LIKE ? OR p.county LIKE ? OR p.state LIKE ?)";
    $location = "%" . $_GET['location'] . "%";
    $params = array_merge($params, [$location, $location, $location, $location]);
    $types .= "ssss";
}

// Property Type filter
if (!empty($_GET['property_type'])) {
    $whereClauses[] = "p.property_type = ?";
    $params[] = $_GET['property_type'];
    $types .= "s";
}

// Room Type filter (House Type)
if (!empty($_GET['room_type'])) {
    $whereClauses[] = "u.room_type = ?";
    $params[] = $_GET['room_type'];
    $types .= "s";
}

// Price filter
if (!empty($_GET['max_price'])) {
    $whereClauses[] = "u.price <= ?";
    $params[] = (int)$_GET['max_price'];
    $types .= "i";
}

// --- Build WHERE SQL ---
$whereSql = "";
if (count($whereClauses) > 0) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

// --- Count total rows ---
$countSql = "SELECT COUNT(DISTINCT p.property_id) AS total 
             FROM properties p
             LEFT JOIN property_units u ON p.property_id = u.property_id
             $whereSql";

$stmt = $conn->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$countResult = $stmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// --- Fetch properties with filters ---
$sql = "
    SELECT 
        p.property_id,
        p.title,
        p.location,
        p.city,
        p.county,
        p.state,
        p.address,
        p.latitude,
        p.longitude,
        p.property_type,
        p.amenities,
        u.unit_id,
        u.room_type,
        u.price,
        u.units_available,
        COALESCE(MIN(i.image_path), 'assets/img/default-property.jpg') AS image_path
    FROM properties p
    LEFT JOIN property_units u ON p.property_id = u.property_id
    LEFT JOIN unit_images i ON u.unit_id = i.unit_id
    $whereSql
    GROUP BY p.property_id
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

// Add pagination params
$paramsWithLimit = $params;
$paramsWithLimit[] = $limit;
$paramsWithLimit[] = $offset;

// Add types for LIMIT/OFFSET
$typesWithLimit = $types . "ii";

$stmt->bind_param($typesWithLimit, ...$paramsWithLimit);

$stmt->execute();
$result = $stmt->get_result();

// Store properties
$properties = [];
while ($row = $result->fetch_assoc()) {
    $properties[] = $row;
}

// Fetch total property count from DB
$totalProperties = 0;
$sql = "SELECT COUNT(*) AS total FROM properties";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    $totalProperties = $row['total'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Accofinda - House Hunting & Property Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <!-- Add Leaflet CSS & JS in <head> -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <!-- Leaflet Control Geocoder -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />

    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f9f9f9;
            color: #333;
            line-height: 1.6;
        }

        /* Navbar */
        .navbar {
            background: rgba(0, 0, 0, 0.85);
            padding: 0.6rem 1rem;
        }

        .navbar-brand {
            font-weight: 600;
            font-size: 1.4rem;
            color: #fff !important;
            letter-spacing: 0.5px;
        }

        .navbar-nav .nav-link {
            color: #ddd !important;
            transition: all 0.3s ease-in-out;
            font-weight: 500;
        }

        .navbar-nav .nav-link:hover {
            color: #ffc107 !important;
            transform: translateY(-2px);
        }

        /* Hero */
        .hero {
            min-height: 38vh !important;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            padding: 1rem;
        }

        .hero-overlay {
            background: rgba(0, 0, 0, 0.65);
            border-radius: 12px;
            padding: 2rem;
            animation: fadeIn 1.5s ease-in;
            color: #fff;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* Property Cards */
        .property-card {
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease-in-out;
            background: #fff;
        }

        .property-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.15);
        }

        .property-card img {
            transition: transform 0.3s ease-in-out;
            width: 100%;
            height: auto;
        }

        .property-card:hover img {
            transform: scale(1.05);
        }

        .badge-featured {
            position: absolute;
            top: 12px;
            left: 12px;
            background: #ffc107;
            color: #000;
            font-weight: 600;
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        /* Icon Boxes */
        .icon-box {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            background: #f8f9fa;
            transition: all 0.3s;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }

        .icon-box:hover {
            background: #e9ecef;
            transform: translateY(-4px);
        }

        /* Call-to-Action */
        .cta {
            background: #0d6efd;
            color: white;
            padding: 60px 20px;
            text-align: center;
            border-radius: 12px;
        }

        .cta h2 {
            font-size: 2rem;
            margin-bottom: 15px;
        }

        /* Newsletter */
        .newsletter {
            background: #f1f1f1;
            padding: 40px;
            text-align: center;
            border-radius: 12px;
        }

        .newsletter input {
            max-width: 400px;
            border-radius: 8px;
            width: 100%;
        }

        /* Footer */
        footer {
            background: #212529;
            color: #bbb;
            padding: 50px 20px;
            font-size: 0.9rem;
        }

        footer a {
            color: #bbb;
            text-decoration: none;
            transition: color 0.3s ease-in-out;
        }

        footer a:hover {
            color: #fff;
        }

        /* Floating WhatsApp */
        .whatsapp-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: #25D366;
            color: white;
            border-radius: 50%;
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            font-size: 26px;
            transition: all 0.3s ease-in-out;
        }

        .whatsapp-btn:hover {
            background: #20b955;
            transform: scale(1.1);
        }

        /* Hover Shadows */
        .hover-shadow {
            transition: all 0.3s ease-in-out;
        }

        .hover-shadow:hover {
            box-shadow: 0px 6px 16px rgba(0, 0, 0, 0.25);
            transform: translateY(-4px);
        }

        .section-heading-wrapper {
            width: 100%;
            padding: 0 2%;
        }

        .section-heading {
            background: #1a1a1a;
            width: 100%;
            max-width: 1290px;
            /* instead of fixed width */
            margin: 0 auto;
            padding: 1.5rem 1rem;
            border-radius: 20px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .section-heading h2 {
            color: #ffffff;
            font-size: 2rem;
            position: relative;
            display: inline-block;
        }

        .highlight-text {
            color: #00c6ff;
            position: relative;
        }

        .highlight-text::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -6px;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, #007bff, #00c6ff);
            border-radius: 3px;
        }

        .section-subtitle {
            color: #ccc;
            font-size: 1.1rem;
        }

        /* 📱 Responsive Styles */
        @media (max-width: 768px) {
            .cta h2 {
                font-size: 1.5rem;
            }

            .hero-overlay {
                padding: 1.2rem;
                font-size: 0.9rem;
            }

            .section-heading h2 {
                font-size: 1.6rem;
            }

            .section-subtitle {
                font-size: 1rem;
            }

            footer {
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .navbar-brand {
                font-size: 1.1rem;
            }

            .hero {
                min-height: 25vh;
            }

            .cta {
                padding: 40px 15px;
            }

            .cta h2 {
                font-size: 1.3rem;
            }

            .section-heading h2 {
                font-size: 1.4rem;
            }
        }
    </style>

</head>
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

<body>

    <!-- Navbar -->
    <nav class="navbar navbar-dark fixed-top shadow-sm" style="background: rgba(0,0,0,0.85); z-index: 1030;">
        <div class="container d-flex justify-content-between align-items-center">

            <!-- Left: Small Hamburger + Brand -->
            <div class="d-flex align-items-center">
                <!-- Small Hamburger (Dropdown) -->
                <div class="dropdown me-2">
                    <button class="btn btn-sm btn-dark p-1" type="button" id="menuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="navbar-toggler-icon" style="width: 20px; height: 20px;"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-start" aria-labelledby="menuDropdown">
                        <li><a class="dropdown-item" href="#">Home</a></li>
                        <li><a class="dropdown-item" href="#properties">Properties</a></li>
                        <li><a class="dropdown-item" href="#services">Services</a></li>
                        <li><a class="dropdown-item" href="#about">About</a></li>
                        <li><a class="dropdown-item" href="#contact">Contact</a></li>
                    </ul>
                </div>

                <!-- Brand Name -->
                <span class="navbar-brand mb-0 h1 fw-bold text-white" style="font-size: 1.6rem;">
                    <span style="background: linear-gradient(90deg, #0d6efd, #ffc107); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                        Accofinda
                    </span>
                </span>
            </div>

            <!-- Right: Sign In -->
            <div>
                <a href="login" class="btn btn-outline-light btn-sm px-3 rounded-2 fw-semibold">
                    <i class="fa fa-sign-in-alt me-1"></i> Sign In
                </a>
            </div>
        </div>
    </nav>

    <!-- Left Offcanvas Menu -->
    <div class="offcanvas offcanvas-start text-bg-dark" tabindex="-1" id="offcanvasMenu" aria-labelledby="offcanvasMenuLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="offcanvasMenuLabel">Menu</h5>
            <button type="button" class="btn-close btn-close-white text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">
            <ul class="navbar-nav flex-column text-start ms-3">
                <li class="nav-item"><a class="nav-link text-white fw-semibold py-2" href="#">Home</a></li>
                <li class="nav-item"><a class="nav-link text-white fw-semibold py-2" href="#properties">Properties</a></li>
                <li class="nav-item"><a class="nav-link text-white fw-semibold py-2" href="#services">Services</a></li>
                <li class="nav-item"><a class="nav-link text-white fw-semibold py-2" href="#about">About</a></li>
                <li class="nav-item"><a class="nav-link text-white fw-semibold py-2" href="#contact">Contact</a></li>
            </ul>
        </div>
    </div>

    <div style="height: 60px;"></div>

    <!-- Hero -->
    <section class="hero d-flex align-items-center justify-content-center"
        style="min-height: 50vh; width: 100%; position: relative; background: #818181;">

        <!-- Logo -->
        <img src="Images/AccofindaLogo1.jpg" alt="Accofinda Logo"
            class="img-fluid position-absolute top-50 start-50 translate-middle opacity-25"
            style="max-height: 280px; width: auto; object-fit: contain; z-index:0;">

        <!-- Dark overlay -->
        <div class="hero-overlay position-absolute top-0 start-0 w-100 h-100"
            style="background: rgba(0,0,0,0.55); z-index:1;"></div>

        <div class="container text-center position-relative text-white" style="z-index:2;">
            <!-- Title -->
            <h1 class="fw-bold mb-2 animate__animated animate__fadeInDown"
                style="font-size: clamp(1.2rem, 3vw, 2rem);">
                Find Your Perfect Home with <span style="color:#ffc107;">Accofinda</span>
            </h1>
            <p class="mb-3 animate__animated animate__fadeInUp"
                style="font-size: clamp(0.8rem, 2vw, 1rem);">
                Trusted house hunting & property management services at your fingertips.
            </p>

            <!-- Search Bar -->
            <form id="searchForm"
                class="row g-2 justify-content-center bg-white p-3 rounded-3 shadow-sm mx-auto"
                style="max-width: 900px; font-size: 0.85rem;">

                <!-- Location -->
                <div class="col-12 col-md-3 position-relative">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa fa-map-marker-alt text-muted"></i></span>
                        <input type="text" name="location" class="form-control" id="locationInput"
                            placeholder="Enter location..."
                            value="<?php echo isset($_GET['location']) ? htmlspecialchars($_GET['location']) : ''; ?>">
                    </div>
                    <ul id="locationList" class="list-group position-absolute w-100 mt-1"
                        style="z-index: 1000; display:none; max-height: 200px; overflow-y:auto; font-size: 0.75rem;"></ul>
                </div>

                <!-- Property Type -->
                <div class="col-6 col-md-2">
                    <select name="property_type" id="propertyTypeInput" class="form-select" style="font-size: 0.8rem;">
                        <option value="">🏠 Property Type</option>
                        <option value="Apartment">🏢 Apartment</option>
                        <option value="Flats">🏬 Flats</option>
                        <option value="House">🏡 House</option>
                        <option value="Own Compound">🌳 Own Compound</option>
                        <option value="Other">✨ Other</option>
                    </select>
                </div>

                <!-- Room Type -->
                <div class="col-6 col-md-2">
                    <select name="room_type" id="roomTypeInput" class="form-select" style="font-size: 0.8rem;">
                        <option value="">🛋️ House Type</option>
                        <option value="Single Room">🚪 Single Room</option>
                        <option value="Bedsitter">🛏️ Bedsitter / Studio</option>
                        <option value="One Bedroom">🏠 One Bedroom</option>
                        <option value="Two Bedrooms">🏘️ Two Bedrooms</option>
                        <option value="Three Bedrooms">🏢 Three Bedrooms</option>
                        <option value="Other">✨ Other</option>
                    </select>
                </div>

                <!-- Price -->
                <div class="col-6 col-md-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="fa fa-dollar-sign"></i></span>
                        <input type="number" name="max_price" id="priceInput"
                            class="form-control"
                            placeholder="Max Price (KES)"
                            style="font-size: 0.8rem;"
                            value="<?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>">
                    </div>
                </div>

                <!-- Search Button -->
                <div class="col-6 col-md-2">
                    <button type="submit" class="btn btn-warning w-100 fw-semibold"
                        style="border-radius: 6px; font-size:0.85rem;">
                        <i class="fa fa-search me-1"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- Featured Properties -->
    <section id="properties" class="py-5 container">
        <h2 class="text-center fw-bold mb-3">Featured Properties</h2>
        <p class="text-center text-muted mb-5">Find your next home from our latest listings</p>

        <div class="row g-4">
            <?php if (!empty($properties)): ?>
                <?php foreach ($properties as $prop): ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="card property-card shadow-sm border rounded-3 h-100 position-relative">

                            <!-- Property Type Badge -->
                            <span class="badge position-absolute top-0 start-0 m-2 px-2 py-1 rounded-pill text-white"
                                style="background: #4e73df; font-size: 0.7rem;">
                                <?= htmlspecialchars($prop['property_type']) ?>
                            </span>

                            <!-- Wishlist/Share Icons -->
                            <div class="position-absolute top-0 end-0 m-2 d-flex gap-1">
                                <a href="#" class="btn btn-light btn-sm rounded-circle"><i class="fa fa-heart"></i></a>
                                <a href="#" class="btn btn-light btn-sm rounded-circle"><i class="fa fa-share-alt"></i></a>
                            </div>

                            <!-- Property Image -->
                            <img src="<?= !empty($prop['image_path']) ? htmlspecialchars($prop['image_path']) : 'assets/img/default-property.jpg' ?>"
                                class="card-img-top rounded-top"
                                alt="<?= htmlspecialchars($prop['title']) ?>"
                                style="height: 160px; object-fit: cover;">

                            <!-- Property Info -->
                            <div class="card-body bg-dark text-light p-3 d-flex justify-content-between gap-2 position-relative"
                                style="font-size: 0.8rem; min-height: 140px;">

                                <!-- Left Info -->
                                <div class="flex-grow-1 d-flex flex-column">
                                    <!-- Title -->
                                    <h6 class="fw-bold mb-1"><?= htmlspecialchars($prop['title']) ?></h6>

                                    <!-- Location -->
                                    <p class="small mb-2">
                                        <i class="fa fa-map-marker-alt me-1 text-danger"></i>
                                        <?= htmlspecialchars($prop['location'] . ', ' . $prop['city']) ?>
                                    </p>

                                    <!-- Room Type & Units -->
                                    <p class="mb-1"><i class="fa fa-door-open me-1"></i> <?= htmlspecialchars($prop['room_type']) ?></p>
                                    <p class="small text-muted mb-2"><?= $prop['units_available'] ?> unit(s) available</p>

                                    <!-- Price Bottom Left -->
                                    <p class="fw-bold text-light mb-0 mt-auto" style="font-size: 0.85rem;">
                                        KSh <?= number_format($prop['price']) ?> <span class="text-light small">/month</span>
                                    </p>
                                </div>

                                <!-- Right Side Amenities -->
                                <?php $amenities = !empty($prop['amenities']) ? explode(',', $prop['amenities']) : []; ?>
                                <?php if (!empty($amenities)): ?>
                                    <div style="width: 90px; max-height: 72px; overflow-y:auto; overflow-x:hidden; 
                                     padding-right:4px; margin-left:4px;" class="text-end">
                                        <p class="small fw-bold text-warning mb-1">Amenities</p>
                                        <?php foreach ($amenities as $amenity): ?>
                                            <span class="badge bg-secondary small d-block mb-1">
                                                <?= htmlspecialchars(trim($amenity)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- View Button Bottom Right -->
                                <a href="login?id=<?= $prop['property_id'] ?>"
                                    class="btn btn-sm btn-outline-light position-absolute"
                                    style="right: 8px; bottom: 8px; border-radius: 4px;">
                                    View Details
                                </a>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-center">No properties found.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        <nav>
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a></li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>">Next</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    </section>
    <!-- Why Choose Us -->
    <section id="services" class="py-5">
        <div class="container">
            <!-- Section Heading -->
            <div class="section-heading-wrapper mb-5">
                <div class="section-heading text-center">
                    <h2 class="fw-bold mb-0">
                        Why Choose <span class="highlight-text">Accofinda?</span>
                    </h2>
                    <p class="section-subtitle mt-2">
                        The smart choice for tenants and landlords.
                    </p>
                </div>
            </div>
            <div class="row g-4">

                <!-- Verified Listings -->
                <div class="col-md-3">
                    <div class="card shadow-lg h-100 border-0"
                        style="background: linear-gradient(135deg, #3c4d45ff, #5c765fff); border-radius: 16px; overflow:hidden;">

                        <!-- Card Header (icon + title in one row) -->
                        <div class="card-header d-flex align-items-center justify-content-center gap-2"
                            style="background: rgba(47, 51, 47, 0.99); padding: 1rem; border: none;">
                            <i class="fa fa-check-circle fa-2x text-light"></i>
                            <h5 class="fw-bold mb-0 text-light">Verified Properties</h5>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body d-flex flex-column align-items-center text-center text-light">
                            <p class="mb-3">
                                All properties are carefully vetted to ensure <br>
                                <span class="fw-semibold text-light">security, accuracy, and trust.</span>
                            </p>

                            <!-- Subcards List -->
                            <div class="w-100">
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-check text-success me-2"></i>
                                    <span class="small fw-semibold text-dark">Fraud-free listings</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-refresh text-info me-2"></i>
                                    <span class="small fw-semibold text-dark">Updated daily</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-users text-primary me-2"></i>
                                    <span class="small fw-semibold text-dark">Trusted landlords & agents</span>
                                </div>
                            </div>

                            <!-- Live Property Counter -->
                            <h6 class="fw-bold text-dark mt-auto pt-3">
                                <span id="propertyCount" data-count="<?= $totalProperties ?>">0</span>+ Properties
                            </h6>
                        </div>
                    </div>
                </div>

                <!-- Fast Booking -->
                <div class="col-md-3">
                    <div class="card shadow-lg h-100 border-0"
                        style="background: linear-gradient(135deg, #525455ff, #393b3cff); border-radius: 16px; overflow:hidden;">

                        <!-- Card Header -->
                        <div class="card-header d-flex align-items-center justify-content-center gap-2 text-light"
                            style="background: rgba(63, 48, 4, 1); padding: 1rem; border: none;">
                            <i class="fa fa-bolt fa-2x text-warning"></i>
                            <h5 class="fw-bold mb-0">Fast Booking</h5>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body d-flex flex-column align-items-center text-center">
                            <p class="mb-3 text-light">
                                Secure your dream property with <br>
                                <span class="fw-semibold text-light">a quick, hassle-free process.</span>
                            </p>

                            <!-- Subcards List -->
                            <div class="w-100">
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-clock text-warning me-2"></i>
                                    <span class="small fw-semibold text-dark">Instant Reservations</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-lock text-danger me-2"></i>
                                    <span class="small fw-semibold text-dark">Secure Payments</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-mobile text-success me-2"></i>
                                    <span class="small fw-semibold text-dark">One-Click Mobile Booking</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Wide Range -->
                <div class="col-md-3">
                    <div class="card shadow-lg h-100 border-0"
                        style="background: linear-gradient(135deg, #797772ff, #717a7cff); border-radius: 16px; overflow:hidden;">

                        <!-- Card Header -->
                        <div class="card-header d-flex align-items-center justify-content-center gap-2 text-light"
                            style="background: rgba(47, 51, 47, 0.99); padding: 1rem; border: none;">
                            <i class="fa fa-home fa-2x text-light"></i>
                            <h5 class="fw-bold mb-0">Wide Range</h5>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body d-flex flex-column align-items-center text-center text-light">
                            <p class="mb-3">
                                A variety of homes designed to <br>
                                <span class="fw-semibold text-light">fit every lifestyle and budget.</span>
                            </p>

                            <!-- Subcards List -->
                            <div class="w-100">
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-building text-success me-2"></i>
                                    <span class="small fw-semibold text-dark">Apartments & Condos</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-home text-primary me-2"></i>
                                    <span class="small fw-semibold text-dark">Family Homes</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-map-marker text-danger me-2"></i>
                                    <span class="small fw-semibold text-dark">Urban & Suburban Options</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Trusted Support -->
                <div class="col-md-3">
                    <div class="card shadow-lg h-100 border-0"
                        style="background: linear-gradient(135deg, #4E4F4B, #4E4F4B); border-radius: 16px; overflow:hidden;">

                        <!-- Card Header -->
                        <div class="card-header d-flex align-items-center justify-content-center gap-2"
                            style="background: rgba(81, 35, 32, 1); padding: 1rem; border: none;">
                            <i class="fa fa-handshake fa-2x text-light"></i>
                            <h5 class="fw-bold mb-0 text-light">Trusted Support</h5>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body d-flex flex-column align-items-center text-center text-light">
                            <p class="mb-3">
                                Always available to guide tenants <br>
                                and landlords with <span class="fw-semibold text-light">dedicated help.</span>
                            </p>

                            <!-- Subcards List -->
                            <div class="w-100">
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-headset text-primary me-2"></i>
                                    <span class="small fw-semibold text-dark">24/7 Live Chat</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-envelope text-success me-2"></i>
                                    <span class="small fw-semibold text-dark">Email Assistance</span>
                                </div>
                                <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                    <i class="fa fa-phone text-danger me-2"></i>
                                    <span class="small fw-semibold text-dark">Direct Call Support</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>
    <!-- Explore on Map Section -->
    <section class="py-5 bg-dark">
        <div class="container">
            <div class="container bg-secondary text-light">
                <h2 class="mb-0 fw-bold">Explore Properties on Map</h2>
                <p class="mt-2" style="opacity: 0.9; font-size: 1.1rem;">
                    Find properties by location and view them on an interactive map
                </p>
            </div>
            <div class="row g-4 align-items-stretch">

                <!-- Left Side: Filters -->
                <div class="col-lg-4">
                    <div class="card shadow-lg border-0 rounded-4 h-100">
                        <div class="card-body p-4">
                            <h4 class="mb-4 text-primary"><i class="fa fa-search me-2"></i> Find Properties</h4>

                            <form id="mapFilterForm">
                                <!-- City / Location -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">City / Location</label>
                                    <input type="text" id="locationInput" class="form-control shadow-sm" placeholder="Nairobi, Westlands">
                                </div>

                                <!-- Coordinates -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Coordinates</label>
                                    <div class="input-group mb-2">
                                        <span class="input-group-text">Lat</span>
                                        <input type="number" step="any" id="latInput" class="form-control shadow-sm" placeholder="-1.286389">
                                    </div>
                                    <div class="input-group">
                                        <span class="input-group-text">Lng</span>
                                        <input type="number" step="any" id="lngInput" class="form-control shadow-sm" placeholder="36.817223">
                                    </div>
                                </div>

                                <!-- Price Range -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Price Range</label>
                                    <select class="form-select shadow-sm" id="priceFilter">
                                        <option value="">Any</option>
                                        <option value="0-20000">Below KSh 20,000</option>
                                        <option value="20000-50000">KSh 20,000 - 50,000</option>
                                        <option value="50000-100000">KSh 50,000 - 100,000</option>
                                        <option value="100000+">Above KSh 100,000</option>
                                    </select>
                                </div>

                                <!-- Property Type -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Property Type</label>
                                    <select class="form-select shadow-sm" id="typeFilter">
                                        <option value="">Any</option>
                                        <option value="Apartment">Apartment</option>
                                        <option value="House">House</option>
                                        <option value="Studio">Bedsitter</option>
                                        <option value="Commercial">Commercial</option>
                                    </select>
                                </div>

                                <!-- Apply Filters button -->
                                <button type="button" id="applyFilters" class="btn btn-dark w-100 mt-3">
                                    <i class="fa fa-map-marker-alt me-2"></i> Apply Filters
                                </button>

                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Side: Map -->
                <div class="col-lg-8">
                    <div class="card shadow-lg border-0 rounded-4 h-100">
                        <div class="card-body p-0">
                            <div id="map" style="height: 500px; width: 100%;"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>
    <!-- Testimonials Section -->
    <section class="py-5" style="background: #747877;"> <!-- Light bluish background -->
        <div class="container">
            <h2 class="text-center mb-5 fw-bold text-light">What Our Clients Say</h2>
            <!-- Testimonials Section -->
            <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner">

                    <!-- First 4 Testimonials -->
                    <div class="carousel-item active">
                        <div class="row g-4 justify-content-center">

                            <!-- Testimonial 1 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#e8f7ff;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-primary fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">Accofinda helped me find my dream apartment in Nairobi.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-primary me-2"></i>
                                                <span class="small fw-semibold text-dark">James K. (Client)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-star text-warning me-2"></i>
                                                <span class="small fw-semibold text-dark">Smooth & Reliable</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Testimonial 2 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#fff7e6;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-warning fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">Great service and excellent landlord support.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-warning me-2"></i>
                                                <span class="small fw-semibold text-dark">Sarah M. (Tenant)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-thumbs-up text-success me-2"></i>
                                                <span class="small fw-semibold text-dark">Highly Recommended</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Testimonial 3 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#f3e8ff;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-purple fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">The best platform for house hunting in Kenya!</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-purple me-2"></i>
                                                <span class="small fw-semibold text-dark">Brian O. (Landlord)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-home text-primary me-2"></i>
                                                <span class="small fw-semibold text-dark">Trusted Platform</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Testimonial 4 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#e6fff0;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-success fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">Super easy to use, I got tenants quickly.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-success me-2"></i>
                                                <span class="small fw-semibold text-dark">Grace L. (Landlord)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-bolt text-danger me-2"></i>
                                                <span class="small fw-semibold text-dark">Fast Results</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Next 4 Testimonials -->
                    <div class="carousel-item">
                        <div class="row g-4 justify-content-center">

                            <!-- Testimonial 5 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#f0f8ff;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-info fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">Professional and reliable service.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-info me-2"></i>
                                                <span class="small fw-semibold text-dark">Kevin P. (Agent)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-thumbs-up text-success me-2"></i>
                                                <span class="small fw-semibold text-dark">Reliable Support</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Testimonial 6 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#fff0f5;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-danger fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">I was impressed with the quick response from landlords.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-danger me-2"></i>
                                                <span class="small fw-semibold text-dark">Lydia W. (Tenant)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-clock text-primary me-2"></i>
                                                <span class="small fw-semibold text-dark">Quick Responses</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Testimonial 7 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#f5fffa;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-success fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">I recommend this to anyone looking for houses fast.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-success me-2"></i>
                                                <span class="small fw-semibold text-dark">Patrick M. (Client)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-star text-warning me-2"></i>
                                                <span class="small fw-semibold text-dark">Trusted Choice</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Testimonial 8 -->
                            <div class="col-md-3 d-flex">
                                <div class="card border-0 shadow-lg rounded-4 h-100 w-100" style="background:#fffaf0;">
                                    <div class="card-body d-flex flex-column align-items-center text-center p-4">
                                        <i class="fa fa-quote-left text-warning fs-2 mb-3"></i>
                                        <p class="mb-3 small fst-italic">The interface is so simple and easy to use.</p>
                                        <div class="w-100 mt-auto">
                                            <div class="subcard d-flex align-items-center mb-2 p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-user text-warning me-2"></i>
                                                <span class="small fw-semibold text-dark">Diana K. (Tenant)</span>
                                            </div>
                                            <div class="subcard d-flex align-items-center p-2 shadow-sm rounded bg-white">
                                                <i class="fa fa-smile text-success me-2"></i>
                                                <span class="small fw-semibold text-dark">User Friendly</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

                <!-- Carousel Controls -->
                <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon bg-dark rounded-circle p-2"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon bg-dark rounded-circle p-2"></span>
                </button>
            </div>


        </div>
    </section>


    <!-- Newsletter -->
    <section class="newsletter">
        <h2>Stay Updated</h2>
        <p>Subscribe to get the latest property listings and offers.</p>
        <form class="d-flex justify-content-center mt-3">
            <input type="email" class="form-control me-2" placeholder="Enter your email">
            <button class="btn btn-primary">Subscribe</button>
        </form>
    </section>

    <!-- Call to Action -->
    <section class="cta py-5 text-center text-white" style="background: #343a40;">
        <div class="container">
            <h2 class="fw-bold">Start Your House Hunt Today!</h2>
            <p class="mb-4">Find, book, and manage your perfect home with Accofinda.</p>
            <a href="#" class="btn btn-light btn-lg">Browse Listings</a>
            <a href="#" class="btn btn-warning btn-lg ms-2">List Your Property</a>
        </div>

        <!-- Footer Columns -->
        <div class="container mt-5">
            <div class="row text-start text-white">

                <!-- Quick Links -->
                <div class="col-md-4 mb-4">
                    <h5 class="fw-bold mb-3">Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white text-decoration-none">Home</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Browse Properties</a></li>
                        <li><a href="#" class="text-white text-decoration-none">List Your Property</a></li>
                        <li><a href="#" class="text-white text-decoration-none">About Us</a></li>
                    </ul>
                </div>

                <!-- Support -->
                <div class="col-md-4 mb-4">
                    <h5 class="fw-bold mb-3">Support</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white text-decoration-none">Help Center</a></li>
                        <li><a href="#" class="text-white text-decoration-none">FAQs</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Terms & Conditions</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Privacy Policy</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <section id="contact" class="mb-5">
                    <h2 class="section-title display-5 fw-bold text-white text-middle">Contact Us</h2>

                    <?php if (isset($_SESSION['form_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show mt-3" role="alert" id="autoDismissAlert">
                            <?= $_SESSION['form_success'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['form_success']); ?>
                    <?php elseif (isset($_SESSION['form_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert" id="autoDismissAlert">
                            <?= $_SESSION['form_error'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['form_error']); ?>
                    <?php endif; ?>

                    <script>
                        // Auto-hide alert after 5 seconds
                        setTimeout(() => {
                            const alert = document.getElementById('autoDismissAlert');
                            if (alert) {
                                // Bootstrap dismiss using JS
                                const alertInstance = bootstrap.Alert.getOrCreateInstance(alert);
                                alertInstance.close();
                            }
                        }, 5000);
                    </script>

                    <div class="row g-4 align-items-stretch mt-4">
                        <div class="col-lg-7">
                            <div class="bg-dark border border-info rounded p-4 h-100 shadow">
                                <form action="contactMessage.php" method="post" novalidate>
                                    <div class="mb-3">
                                        <label for="name" class="form-label text-light">Full Name</label>
                                        <input type="text" name="name" class="form-control" placeholder="Your full name" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label text-light">Email Address</label>
                                        <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="subject" class="form-label text-light">Subject</label>
                                        <input type="text" name="subject" class="form-control" placeholder="Message subject" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="message" class="form-label text-light">Message</label>
                                        <textarea name="message" rows="4" class="form-control" placeholder="Write your message here..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm px-3">
                                        <i class="fa fa-paper-plane me-1"></i> Send Message
                                    </button>

                                </form>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="bg-dark text-light rounded p-4 h-100 shadow">
                                <h5>📍 Our Address</h5>
                                <p class="mb-3">Accofinda<br>Nairobi, Kenya</p>

                                <h5>📞 Phone</h5>
                                <p><a href="tel:+254700000000" class="text-info text-decoration-underline">+2547xxxxxxx</a></p>

                                <h5>📧 Email</h5>
                                <p><a href="mailto:support@accofinda.com" class="text-info text-decoration-underline">support@accofinda.com</a></p>

                                <h5>🕐 Office Hours</h5>
                                <p>Mon to Fri: 8:00 AM to 5:00 PM</p>
                                <div class="mt-2">
                                    <a href="#" class="text-white me-3"><i class="fab fa-facebook fs-5"></i></a>
                                    <a href="#" class="text-white me-3"><i class="fab fa-twitter fs-5"></i></a>
                                    <a href="#" class="text-white"><i class="fab fa-instagram fs-5"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </section>


    <!-- Footer -->
    <footer id="contact">
        <div class="container text-center">
            <p>&copy; <?= date("Y") ?> Accofinda. All Rights Reserved.</p>
        </div>
    </footer>

    <!-- WhatsApp Floating Button -->
    <a href="https://wa.me/254700000000" target="_blank" class="whatsapp-btn"><i class="fab fa-whatsapp"></i></a>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setupAutoSuggest(inputId, listId, type) {
            const input = document.getElementById(inputId);
            const list = document.getElementById(listId);
            let debounceTimeout;

            input.addEventListener("keyup", function() {
                clearTimeout(debounceTimeout);
                const query = this.value.trim();

                if (query.length < 2) {
                    list.style.display = "none";
                    return;
                }

                // debounce API calls (300ms)
                debounceTimeout = setTimeout(() => {
                    fetch(`?ajax=1&type=${type}&term=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            list.innerHTML = "";
                            if (data.length > 0) {
                                data.forEach(item => {
                                    const li = document.createElement("li");
                                    li.className = "list-group-item list-group-item-action";
                                    li.textContent = item;
                                    li.onclick = () => {
                                        input.value = item;
                                        list.style.display = "none";
                                        triggerAutoSearch(); // ✅ auto fetch results
                                    };
                                    list.appendChild(li);
                                });
                                list.style.display = "block";
                            } else {
                                list.style.display = "none";
                            }
                        })
                        .catch(err => {
                            console.error("Auto-suggest error:", err);
                            list.style.display = "none";
                        });
                }, 300);
            });

            // Hide list if clicked outside
            document.addEventListener("click", function(e) {
                if (!input.contains(e.target) && !list.contains(e.target)) {
                    list.style.display = "none";
                }
            });
        }

        // ✅ Universal auto-search (used by all filters)
        function triggerAutoSearch() {
            const form = document.getElementById("searchForm");
            const formData = new FormData(form);

            fetch("<?php echo $_SERVER['PHP_SELF']; ?>?" + new URLSearchParams(formData), {
                    method: "GET"
                })
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, "text/html");
                    const newListings = doc.querySelector("#property-listings");
                    if (newListings) {
                        document.querySelector("#property-listings").innerHTML = newListings.innerHTML;
                    }
                })
                .catch(err => console.error("Auto search error:", err));
        }

        // ✅ Setup autosuggest only for Location
        setupAutoSuggest("locationInput", "locationList", "location");

        // ✅ Trigger auto search on filters (selects & price input)
        document.querySelectorAll("#searchForm select, #searchForm input[name='max_price']")
            .forEach(el => {
                el.addEventListener("change", triggerAutoSearch);
                el.addEventListener("keyup", function() {
                    if (this.name === "max_price" && this.value.trim() === "") return; // avoid spam empty calls
                    triggerAutoSearch();
                });
            });
    </script>
    <script>
        // Initialize map centered on Nairobi
        var map = L.map('map').setView([-1.286389, 36.817223], 12);

        // Load OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Pass PHP data to JS
        var properties = <?php echo json_encode($properties); ?>;

        // Add markers
        var markers = [];
        properties.forEach(function(prop) {
            if (prop.latitude && prop.longitude) {
                var marker = L.marker([prop.latitude, prop.longitude]).addTo(map)
                    .bindPopup(
                        "<b>" + prop.title + "</b><br>" +
                        prop.location + ", " + prop.city + "<br>" +
                        "KSh " + new Intl.NumberFormat().format(prop.price) + "/month<br>" +
                        "<a href='property-details.php?id=" + prop.property_id + "'>View Details</a>"
                    );
                markers.push(marker);
            }
        });

        // Auto fit map to markers if available
        if (markers.length > 0) {
            var group = new L.featureGroup(markers);
            map.fitBounds(group.getBounds().pad(0.2));
        }
    </script>

    <!-- Counter Animation Script -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const counter = document.getElementById("propertyCount");
            const target = parseInt(counter.getAttribute("data-count"));
            let count = 0;
            const speed = Math.ceil(target / 100); // animation speed factor

            const updateCount = () => {
                count += speed;
                if (count < target) {
                    counter.innerText = count.toLocaleString();
                    requestAnimationFrame(updateCount);
                } else {
                    counter.innerText = target.toLocaleString();
                }
            };

            updateCount();
        });
    </script>

</body>

</html>