<?php
// includes/image_storage.php
// Highly compressed image storage with WebP compression and Cloudinary CDN support.

require_once __DIR__ . '/../config/db.php';

/**
 * Fetch image storage settings.
 * If $admin_id is provided or current session belongs to a tenant admin:
 * - Tenant admins only get their own custom Cloudinary settings (super-admin keys are strictly hidden).
 * - If admin is super admin (or no admin specified), returns system-level settings.
 */
function get_image_storage_config($pdo = null, $admin_id = null) {
    if (!$pdo) {
        global $pdo;
        if (!$pdo) {
            $pdo = require __DIR__ . '/../config/db.php';
        }
    }

    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }

    $systemDefaults = [
        'driver'            => getenv('IMAGE_STORAGE_DRIVER') ?: 'local', // 'local' or 'cloudinary'
        'quality'           => (int)(getenv('IMAGE_COMPRESS_QUALITY') ?: 75),
        'max_width'         => (int)(getenv('IMAGE_MAX_WIDTH') ?: 1200),
        'cloudinary_name'   => getenv('CLOUDINARY_CLOUD_NAME') ?: '',
        'cloudinary_key'    => getenv('CLOUDINARY_API_KEY') ?: '',
        'cloudinary_secret' => getenv('CLOUDINARY_API_SECRET') ?: '',
        'cloudinary_folder' => getenv('CLOUDINARY_FOLDER') ?: 'questions',
        'is_tenant'         => false,
        'has_custom_cloud'  => false
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'image_%' OR setting_key LIKE 'cloudinary_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows) {
            if (!empty($rows['image_storage_driver'])) $systemDefaults['driver'] = $rows['image_storage_driver'];
            if (!empty($rows['image_compress_quality'])) $systemDefaults['quality'] = (int)$rows['image_compress_quality'];
            if (!empty($rows['image_max_width'])) $systemDefaults['max_width'] = (int)$rows['image_max_width'];
            if (!empty($rows['cloudinary_cloud_name'])) $systemDefaults['cloudinary_name'] = $rows['cloudinary_cloud_name'];
            if (!empty($rows['cloudinary_api_key'])) $systemDefaults['cloudinary_key'] = $rows['cloudinary_api_key'];
            if (!empty($rows['cloudinary_api_secret'])) $systemDefaults['cloudinary_secret'] = $rows['cloudinary_api_secret'];
            if (!empty($rows['cloudinary_folder'])) $systemDefaults['cloudinary_folder'] = $rows['cloudinary_folder'];
        }
    } catch (\PDOException $e) {
        // system_settings table might not be initialized yet
    }

    if (!$admin_id) {
        return $systemDefaults;
    }

    try {
        $stmtAdmin = $pdo->prepare("SELECT role, cloudinary_settings FROM admins WHERE id = ?");
        $stmtAdmin->execute([$admin_id]);
        $adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if (!$adminRow) {
            return $systemDefaults;
        }

        $isSuper = ($adminRow['role'] === 'super_admin');
        if ($isSuper) {
            return $systemDefaults;
        }

        // For Tenant Admin: Super-admin credentials are strictly hidden and isolated!
        $tenantConfig = [
            'driver'            => 'local',
            'quality'           => $systemDefaults['quality'],
            'max_width'         => $systemDefaults['max_width'],
            'cloudinary_name'   => '',
            'cloudinary_key'    => '',
            'cloudinary_secret' => '',
            'cloudinary_folder' => 'questions',
            'is_tenant'         => true,
            'has_custom_cloud'  => false
        ];

        if (!empty($adminRow['cloudinary_settings'])) {
            $parsed = json_decode($adminRow['cloudinary_settings'], true);
            if (is_array($parsed)) {
                if (!empty($parsed['driver'])) $tenantConfig['driver'] = $parsed['driver'];
                if (isset($parsed['quality'])) $tenantConfig['quality'] = (int)$parsed['quality'];
                if (isset($parsed['max_width'])) $tenantConfig['max_width'] = (int)$parsed['max_width'];
                if (!empty($parsed['cloudinary_name'])) $tenantConfig['cloudinary_name'] = $parsed['cloudinary_name'];
                if (!empty($parsed['cloudinary_key'])) $tenantConfig['cloudinary_key'] = $parsed['cloudinary_key'];
                if (!empty($parsed['cloudinary_secret'])) $tenantConfig['cloudinary_secret'] = $parsed['cloudinary_secret'];
                if (!empty($parsed['cloudinary_folder'])) $tenantConfig['cloudinary_folder'] = $parsed['cloudinary_folder'];

                if (!empty($parsed['cloudinary_name']) && !empty($parsed['cloudinary_key'])) {
                    $tenantConfig['has_custom_cloud'] = true;
                }
            }
        }

        return $tenantConfig;
    } catch (\PDOException $e) {
        return $systemDefaults;
    }
}

/**
 * Save image storage settings.
 * Saves to system_settings if super-admin or no admin, or to admins.cloudinary_settings if tenant admin.
 */
function save_image_storage_config($pdo, array $settings, $admin_id = null) {
    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }

    $isSuper = true;
    if ($admin_id) {
        $stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $role = $stmt->fetchColumn();
        $isSuper = ($role === 'super_admin');
    }

    if ($isSuper && $admin_id === null) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        
        $mapping = [
            'driver'            => 'image_storage_driver',
            'quality'           => 'image_compress_quality',
            'max_width'         => 'image_max_width',
            'cloudinary_name'   => 'cloudinary_cloud_name',
            'cloudinary_key'    => 'cloudinary_api_key',
            'cloudinary_secret' => 'cloudinary_api_secret',
            'cloudinary_folder' => 'cloudinary_folder'
        ];

        foreach ($mapping as $paramKey => $dbKey) {
            if (isset($settings[$paramKey])) {
                $stmt->execute([$dbKey, (string)$settings[$paramKey]]);
            }
        }
    } else {
        // Save to specific admin's personal record
        $existing = [];
        if ($admin_id) {
            $stmt = $pdo->prepare("SELECT cloudinary_settings FROM admins WHERE id = ?");
            $stmt->execute([$admin_id]);
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $existing = json_decode($raw, true) ?: [];
            }
        }

        $fields = [
            'driver'            => $settings['driver'] ?? ($existing['driver'] ?? 'local'),
            'quality'           => (int)($settings['quality'] ?? ($existing['quality'] ?? 75)),
            'max_width'         => (int)($settings['max_width'] ?? ($existing['max_width'] ?? 1200)),
            'cloudinary_name'   => trim($settings['cloudinary_name'] ?? ($existing['cloudinary_name'] ?? '')),
            'cloudinary_key'    => trim($settings['cloudinary_key'] ?? ($existing['cloudinary_key'] ?? '')),
            'cloudinary_secret' => trim($settings['cloudinary_secret'] ?? ($existing['cloudinary_secret'] ?? '')),
            'cloudinary_folder' => trim($settings['cloudinary_folder'] ?? ($existing['cloudinary_folder'] ?? 'questions')),
        ];

        $stmtUp = $pdo->prepare("UPDATE admins SET cloudinary_settings = ? WHERE id = ?");
        $stmtUp->execute([json_encode($fields), $admin_id]);

        // If this is super admin configuring their own settings, also update system_settings
        if ($isSuper) {
            $stmtSys = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $mapping = [
                'driver'            => 'image_storage_driver',
                'quality'           => 'image_compress_quality',
                'max_width'         => 'image_max_width',
                'cloudinary_name'   => 'cloudinary_cloud_name',
                'cloudinary_key'    => 'cloudinary_api_key',
                'cloudinary_secret' => 'cloudinary_api_secret',
                'cloudinary_folder' => 'cloudinary_folder'
            ];
            foreach ($mapping as $paramKey => $dbKey) {
                if (isset($fields[$paramKey])) {
                    $stmtSys->execute([$dbKey, (string)$fields[$paramKey]]);
                }
            }
        }
    }
}

/**
 * Gets the upload directory path for a specific admin's question photos.
 * Ensures the directory exists with proper permissions.
 */
function get_admin_question_upload_dir($admin_id = null) {
    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $admin_id = $admin_id ? (int)$admin_id : 1;

    $uploadBaseDir = __DIR__ . '/../uploads';
    $uploadQuestionsDir = $uploadBaseDir . '/questions';
    $targetDir = $uploadQuestionsDir . '/admin_' . $admin_id;

    if (!is_dir($uploadBaseDir)) {
        @mkdir($uploadBaseDir, 0777, true);
        @chmod($uploadBaseDir, 0777);
    }
    if (!is_dir($uploadQuestionsDir)) {
        @mkdir($uploadQuestionsDir, 0777, true);
        @chmod($uploadQuestionsDir, 0777);
    }
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
        @chmod($targetDir, 0777);
    }
    return $targetDir;
}

/**
 * Compresses an image binary and saves it to local disk as optimized WebP or JPEG
 * partitioned into the admin's separate uploads/questions/admin_{admin_id}/ folder.
 */
function compress_and_save_locally($binaryData, $origExt = 'png', $quality = 75, $maxWidth = 1200, $admin_id = null) {
    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $admin_id = $admin_id ? (int)$admin_id : 1;

    $uploadDir = get_admin_question_upload_dir($admin_id);
    $adminFolder = 'admin_' . $admin_id;

    $rawLength = strlen($binaryData);
    $uniqueId = bin2hex(random_bytes(6)) . '_' . time();

    // Check if GD library is available
    if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($binaryData);
        if ($img !== false) {
            $origWidth = imagesx($img);
            $origHeight = imagesy($img);

            // Scale down if larger than max width while keeping aspect ratio
            if ($maxWidth > 0 && $origWidth > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int)round(($origHeight / $origWidth) * $maxWidth);
                $scaledImg = imagescale($img, $newWidth, $newHeight);
                if ($scaledImg !== false) {
                    imagedestroy($img);
                    $img = $scaledImg;
                }
            }

            // Prefer WebP compression (gives superior quality at fraction of size)
            if (function_exists('imagewebp')) {
                if (function_exists('imagepalettetotruecolor')) {
                    imagepalettetotruecolor($img);
                }
                imagealphablending($img, true);
                imagesavealpha($img, true);

                $filename = 'qimg_' . $uniqueId . '.webp';
                $destPath = $uploadDir . '/' . $filename;

                if (imagewebp($img, $destPath, $quality)) {
                    imagedestroy($img);
                    $compressedSize = filesize($destPath);
                    return [
                        'success'     => true,
                        'filename'    => $filename,
                        'url'         => BASE_URL . '/uploads/questions/' . $adminFolder . '/' . $filename,
                        'admin_id'    => $admin_id,
                        'size'        => $compressedSize,
                        'orig_size'   => $rawLength,
                        'savings_pct' => $rawLength > 0 ? round((1 - ($compressedSize / $rawLength)) * 100, 1) : 0,
                        'format'      => 'webp'
                    ];
                }
            }

            // Fallback to JPEG if WebP is not available
            if (function_exists('imagejpeg')) {
                $filename = 'qimg_' . $uniqueId . '.jpg';
                $destPath = $uploadDir . '/' . $filename;

                if (imagejpeg($img, $destPath, $quality)) {
                    imagedestroy($img);
                    $compressedSize = filesize($destPath);
                    return [
                        'success'     => true,
                        'filename'    => $filename,
                        'url'         => BASE_URL . '/uploads/questions/' . $adminFolder . '/' . $filename,
                        'admin_id'    => $admin_id,
                        'size'        => $compressedSize,
                        'orig_size'   => $rawLength,
                        'savings_pct' => $rawLength > 0 ? round((1 - ($compressedSize / $rawLength)) * 100, 1) : 0,
                        'format'      => 'jpeg'
                    ];
                }
            }

            imagedestroy($img);
        }
    }

    // Direct binary write fallback if GD is unavailable or fails
    $safeExt = in_array(strtolower($origExt), ['png', 'jpg', 'jpeg', 'webp', 'gif']) ? strtolower($origExt) : 'png';
    $filename = 'qimg_' . $uniqueId . '.' . $safeExt;
    $destPath = $uploadDir . '/' . $filename;

    if (file_put_contents($destPath, $binaryData) !== false) {
        return [
            'success'     => true,
            'filename'    => $filename,
            'url'         => BASE_URL . '/uploads/questions/' . $adminFolder . '/' . $filename,
            'admin_id'    => $admin_id,
            'size'        => strlen($binaryData),
            'orig_size'   => $rawLength,
            'savings_pct' => 0,
            'format'      => $safeExt
        ];
    }

    return ['success' => false, 'error' => 'Failed to write image file to disk.'];
}

/**
 * Uploads an image binary directly to Cloudinary CDN via REST API,
 * partitioned under questions/admin_{admin_id}/ folder.
 */
function upload_to_cloudinary($binaryData, array $config, $origExt = 'png', $admin_id = null) {
    if (empty($config['cloudinary_name']) || empty($config['cloudinary_key']) || empty($config['cloudinary_secret'])) {
        return ['success' => false, 'error' => 'Cloudinary credentials are not fully configured.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'PHP cURL extension is required for Cloudinary upload.'];
    }

    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $admin_id = $admin_id ? (int)$admin_id : 1;

    $cloudName = trim($config['cloudinary_name']);
    $apiKey    = trim($config['cloudinary_key']);
    $apiSecret = trim($config['cloudinary_secret']);
    $baseFolder = trim($config['cloudinary_folder'] ?: 'questions');
    // Partition photos separately for each admin in Cloudinary
    $folder    = rtrim($baseFolder, '/') . '/admin_' . $admin_id;

    $timestamp = time();
    $safeExt = in_array(strtolower($origExt), ['png', 'jpg', 'jpeg', 'webp', 'gif']) ? strtolower($origExt) : 'png';
    $mimeType = ($safeExt === 'jpg') ? 'image/jpeg' : 'image/' . $safeExt;

    // Create Base64 Data URI for direct payload transfer
    $filePayload = 'data:' . $mimeType . ';base64,' . base64_encode($binaryData);

    // Build signature parameters in alphabetical order
    $paramsToSign = [];
    if (!empty($folder)) {
        $paramsToSign['folder'] = $folder;
    }
    $paramsToSign['timestamp'] = $timestamp;
    ksort($paramsToSign);

    $paramString = '';
    foreach ($paramsToSign as $k => $v) {
        $paramString .= ($paramString ? '&' : '') . "{$k}={$v}";
    }
    $signature = sha1($paramString . $apiSecret);

    $postFields = [
        'file'      => $filePayload,
        'api_key'   => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature,
    ];
    if (!empty($folder)) {
        $postFields['folder'] = $folder;
    }

    $apiUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => 'Cloudinary connection error: ' . $curlErr];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['secure_url'])) {
        return [
            'success'   => true,
            'url'       => $data['secure_url'],
            'public_id' => $data['public_id'] ?? '',
            'bytes'     => $data['bytes'] ?? strlen($binaryData),
            'format'    => $data['format'] ?? $safeExt,
            'admin_id'  => $admin_id
        ];
    }

    $errorMsg = $data['error']['message'] ?? "Upload failed with HTTP status {$httpCode}";
    return ['success' => false, 'error' => 'Cloudinary API error: ' . $errorMsg];
}

/**
 * High-level function: Stores a question image according to active driver configuration
 * (Local Compressed WebP or Cloudinary CDN) scoped specifically to the given admin.
 */
function store_question_image($binaryData, $origExt = 'png', $pdo = null, $admin_id = null) {
    if (!$pdo) {
        global $pdo;
        if (!$pdo) {
            $pdo = require __DIR__ . '/../config/db.php';
        }
    }

    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $admin_id = $admin_id ? (int)$admin_id : 1;

    $config = get_image_storage_config($pdo, $admin_id);

    if ($config['driver'] === 'cloudinary') {
        if (!empty($config['cloudinary_name']) && !empty($config['cloudinary_key']) && !empty($config['cloudinary_secret'])) {
            $cloudResult = upload_to_cloudinary($binaryData, $config, $origExt, $admin_id);
            if ($cloudResult['success']) {
                $cloudResult['driver'] = 'cloudinary';
                $cloudResult['admin_id'] = $admin_id;
                return $cloudResult;
            }
            error_log("Cloudinary upload failed (" . ($cloudResult['error'] ?? 'unknown') . "). Falling back to local compressed storage.");
        } else {
            error_log("Cloudinary driver selected but credentials missing for admin {$admin_id}. Falling back to local compressed storage.");
        }
    }

    $localResult = compress_and_save_locally($binaryData, $origExt, $config['quality'], $config['max_width'], $admin_id);
    $localResult['driver'] = 'local';
    return $localResult;
}

/**
 * Parses question HTML, detects any embedded base64 images, compresses and stores them,
 * and replaces the base64 data URIs with the optimized URLs scoped to the admin.
 * Ensures that base64 blobs NEVER get saved into the database!
 */
function process_question_images($html, $pdo = null, $admin_id = null) {
    if (!$html || strpos($html, 'data:image/') === false) {
        return $html;
    }

    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $admin_id = $admin_id ? (int)$admin_id : 1;

    // Matches <img ... src="data:image/{type};base64,{data}" ...>
    $pattern = '/<img([^>]+)src=["\']data:image\/([a-zA-Z0-9\+\-]+);base64,([A-Za-z0-9\/+=\s]+)["\']([^>]*)>/i';

    return preg_replace_callback($pattern, function($matches) use ($pdo, $admin_id) {
        $prefix = $matches[1];
        $type   = strtolower($matches[2]);
        $base64 = str_replace([' ', "\r", "\n"], '', $matches[3]);
        $suffix = $matches[4];

        $binary = base64_decode($base64);
        if (!$binary) {
            return $matches[0];
        }

        $res = store_question_image($binary, $type, $pdo, $admin_id);
        if (!empty($res['success']) && !empty($res['url'])) {
            return '<img' . $prefix . 'src="' . htmlspecialchars($res['url'], ENT_QUOTES, 'UTF-8') . '"' . $suffix . '>';
        }

        return $matches[0];
    }, $html);
}

/**
 * Scan database for questions still containing base64 images, migrate them to disk/Cloudinary,
 * and update the question_text rows.
 */
function migrate_db_questions_images($pdo) {
    $stmt = $pdo->query("SELECT id, question_text, admin_id FROM questions WHERE question_text LIKE '%data:image/%'");
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $migrated = 0;
    $bytesSaved = 0;
    $errors = [];

    $updateStmt = $pdo->prepare("UPDATE questions SET question_text = ? WHERE id = ?");

    foreach ($questions as $q) {
        $origLen = strlen($q['question_text']);
        $newText = process_question_images($q['question_text'], $pdo, $q['admin_id'] ?? 1);
        $newLen  = strlen($newText);

        if ($newText !== $q['question_text']) {
            try {
                $updateStmt->execute([$newText, $q['id']]);
                $migrated++;
                $bytesSaved += max(0, $origLen - $newLen);
            } catch (\PDOException $e) {
                $errors[] = "Question ID {$q['id']}: " . $e->getMessage();
            }
        }
    }

    return [
        'total_found' => count($questions),
        'migrated'    => $migrated,
        'bytes_saved' => $bytesSaved,
        'errors'      => $errors
    ];
}

/**
 * Test Cloudinary connection by uploading a 1x1 transparent PNG pixel
 */
function test_cloudinary_connection($cloudName, $apiKey, $apiSecret, $folder = 'questions') {
    // 1x1 transparent PNG base64
    $pixelBinary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    
    $config = [
        'cloudinary_name'   => $cloudName,
        'cloudinary_key'    => $apiKey,
        'cloudinary_secret' => $apiSecret,
        'cloudinary_folder' => $folder ?: 'test'
    ];

    $res = upload_to_cloudinary($pixelBinary, $config, 'png');
    if ($res['success']) {
        return [
            'success' => true,
            'message' => 'Connection verified successfully! Image successfully uploaded to Cloudinary.',
            'url'     => $res['url']
        ];
    }

    return [
        'success' => false,
        'message' => $res['error'] ?? 'Connection test failed.'
    ];
}
