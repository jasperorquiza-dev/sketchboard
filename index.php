<?php
// index.php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = sketch_base_url();

// If user is not logged in, redirect to auth.php
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $baseUrl . '/auth.php');
    exit;
}

// User is logged in, show the dashboard
require_once __DIR__ . '/dashboard.php';
