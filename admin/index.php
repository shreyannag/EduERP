<?php
// admin/index.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers();
start_secure_session('ADMIN_SESSION');

// If already authenticated as administrator, forward straight to dashboard
if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Unauthenticated: render the login portal directly under the clean /admin URL
require_once __DIR__ . '/login.php';
