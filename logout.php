<?php
session_start();
date_default_timezone_set("Africa/Nairobi");
// Unset all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login");
exit();
