<?php
// admin/change_password.php
// Forward to unified settings panel for account & password management
require_once __DIR__ . '/../config/db.php';
header('Location: ' . BASE_URL . '/admin/settings.php#password');
exit;
