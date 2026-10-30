<?php
// includes/ai_generator.php
// OpenRouter AI Integration for Automated Question Generation.

require_once __DIR__ . '/../config/db.php';

/**
 * Fetch OpenRouter configuration settings from database or environment.
 * If $admin_id is provided or current session belongs to a tenant admin:
 * - Tenant admins only get their own custom OpenRouter settings (super-admin keys are strictly hidden).
 * - If admin is super admin (or no admin specified), returns system-level settings.
 */
function get_openrouter_config($pdo = null, $admin_id = null) {
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
        'api_key'        => getenv('OPENROUTER_API_KEY') ?: '',
        'model'          => getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.0-flash-001',
        'temperature'    => (float)(getenv('OPENROUTER_TEMPERATURE') ?: 0.7),
        'site_url'       => getenv('BASE_URL') ?: 'http://localhost:8080/shreyansir',
        'app_name'       => 'ShreyanSir Question Bank',
        'is_tenant'      => false,
        'has_custom_key' => false
    ];

    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'openrouter_%'");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($rows) {
                if (!empty($rows['openrouter_api_key'])) $systemDefaults['api_key'] = $rows['openrouter_api_key'];
                if (!empty($rows['openrouter_model'])) $systemDefaults['model'] = $rows['openrouter_model'];
                if (isset($rows['openrouter_temperature'])) $systemDefaults['temperature'] = (float)$rows['openrouter_temperature'];
            }
        } catch (\PDOException $e) {
            // Fallback safely to defaults if table not ready
        }
    }

    $systemDefaults['has_custom_key'] = !empty($systemDefaults['api_key']);

    if (!$admin_id || !$pdo) {
        return $systemDefaults;
    }

    try {
        $stmtAdmin = $pdo->prepare("SELECT role, openrouter_settings FROM admins WHERE id = ?");
        $stmtAdmin->execute([$admin_id]);
        $adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if (!$adminRow) {
            return $systemDefaults;
        }

        $isSuper = ($adminRow['role'] === 'super_admin');
        if ($isSuper) {
            if (!empty($adminRow['openrouter_settings'])) {
                $parsed = json_decode($adminRow['openrouter_settings'], true);
                if (is_array($parsed)) {
                    if (isset($parsed['api_key'])) $systemDefaults['api_key'] = trim($parsed['api_key']);
                    if (!empty($parsed['model'])) $systemDefaults['model'] = trim($parsed['model']);
                    if (isset($parsed['temperature'])) $systemDefaults['temperature'] = (float)$parsed['temperature'];
                }
            }
            $systemDefaults['is_tenant'] = false;
            $systemDefaults['has_custom_key'] = !empty($systemDefaults['api_key']);
            return $systemDefaults;
        }

        // For Tenant Admin: Super-admin credentials are strictly hidden and isolated!
        $tenantConfig = [
            'api_key'        => '',
            'model'          => 'google/gemini-2.0-flash-001',
            'temperature'    => 0.7,
            'site_url'       => getenv('BASE_URL') ?: 'http://localhost:8080/shreyansir',
            'app_name'       => 'ShreyanSir Question Bank',
            'is_tenant'      => true,
            'has_custom_key' => false
        ];

        if (!empty($adminRow['openrouter_settings'])) {
            $parsed = json_decode($adminRow['openrouter_settings'], true);
            if (is_array($parsed)) {
                if (isset($parsed['api_key'])) $tenantConfig['api_key'] = trim($parsed['api_key']);
                if (!empty($parsed['model'])) $tenantConfig['model'] = trim($parsed['model']);
                if (isset($parsed['temperature'])) $tenantConfig['temperature'] = (float)$parsed['temperature'];

                if (!empty($tenantConfig['api_key'])) {
                    $tenantConfig['has_custom_key'] = true;
                }
            }
        }

        return $tenantConfig;
    } catch (\PDOException $e) {
        return $systemDefaults;
    }
}

/**
 * Save OpenRouter configuration.
 * Saves to system_settings if super-admin or no admin, or to admins.openrouter_settings if specific admin.
 */
function save_openrouter_config($pdo, array $settings, $admin_id = null) {
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
            'api_key'     => 'openrouter_api_key',
            'model'       => 'openrouter_model',
            'temperature' => 'openrouter_temperature'
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
            $stmt = $pdo->prepare("SELECT openrouter_settings FROM admins WHERE id = ?");
            $stmt->execute([$admin_id]);
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $existing = json_decode($raw, true) ?: [];
            }
        }

        $fields = [
            'api_key'     => isset($settings['api_key']) ? trim((string)$settings['api_key']) : ($existing['api_key'] ?? ''),
            'model'       => !empty($settings['model']) ? trim((string)$settings['model']) : ($existing['model'] ?? 'google/gemini-2.0-flash-001'),
            'temperature' => isset($settings['temperature']) ? (float)$settings['temperature'] : ($existing['temperature'] ?? 0.7)
        ];

        if ($admin_id) {
            $stmtUp = $pdo->prepare("UPDATE admins SET openrouter_settings = ? WHERE id = ?");
            $stmtUp->execute([json_encode($fields), $admin_id]);
        }

        // If this is super admin configuring their own settings, also update system_settings
        if ($isSuper) {
            $stmtSys = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $mapping = [
                'api_key'     => 'openrouter_api_key',
                'model'       => 'openrouter_model',
                'temperature' => 'openrouter_temperature'
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
 * Resolves SSL configuration for cURL across Windows XAMPP, Docker, and Linux.
 */
function get_openrouter_curl_ssl_options() {
    $opts = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ];

    $caInfo = ini_get('curl.cainfo');
    $caFile = ini_get('openssl.cafile');

    if (!empty($caInfo) && file_exists($caInfo)) {
        $opts[CURLOPT_CAINFO] = $caInfo;
        return $opts;
    }

    if (!empty($caFile) && file_exists($caFile)) {
        $opts[CURLOPT_CAINFO] = $caFile;
        return $opts;
    }

    $commonBundles = [
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/perl/vendor/lib/Mozilla/mk-ca-bundle.pl',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/ca-bundle.pem'
    ];

    foreach ($commonBundles as $bundle) {
        if (file_exists($bundle)) {
            $opts[CURLOPT_CAINFO] = $bundle;
            return $opts;
        }
    }

    // In local dev/XAMPP environments where local issuer certificates are missing:
    // Allow secure communication without hard failure
    $opts[CURLOPT_SSL_VERIFYPEER] = false;
    $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    return $opts;
}

/**
 * Test OpenRouter connection with a quick ping
 */
function test_openrouter_connection($apiKey, $model = 'google/gemini-2.0-flash-001') {
    $apiKey = trim((string)$apiKey, " \t\n\r\0\x0B\"'");
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'OpenRouter API key is required.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'PHP cURL extension is required to connect to OpenRouter.'];
    }

    $sslOptions = get_openrouter_curl_ssl_options();
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: http://localhost:8080/shreyansir',
            'X-Title: ShreyanSir Question Bank'
        ],
        CURLOPT_TIMEOUT        => 15
    ] + $sslOptions;

    $ch = curl_init('https://openrouter.ai/api/v1/auth/key');
    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    // If local SSL certificate verification failed, retry once with SSL_VERIFYPEER disabled
    if ($curlErr && (in_array($curlErrNo, [60, 77, 35]) || stripos($curlErr, 'certificate') !== false)) {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        unset($curlOptions[CURLOPT_CAINFO]);

        $ch = curl_init('https://openrouter.ai/api/v1/auth/key');
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
    }

    if ($curlErr) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlErr];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        $info = $data['data'];
        $label = !empty($info['label']) ? $info['label'] : 'Active OpenRouter Key';
        $usage = isset($info['usage']) ? ' (Usage: $' . number_format((float)$info['usage'], 4) . ')' : '';
        return [
            'success' => true,
            'message' => "OpenRouter authentication successful! {$label}{$usage}",
            'data'    => $info
        ];
    }

    $errMsg = $data['error']['message'] ?? "OpenRouter request failed with HTTP status {$httpCode}";
    return ['success' => false, 'error' => $errMsg];
}

/**
 * Generate a Multiple Choice Question via OpenRouter AI
 */
function generate_mcq_question(array $params, $pdo = null, $admin_id = null) {
    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $aiConfig = get_openrouter_config($pdo, $admin_id);

    $apiKey = !empty($params['api_key']) ? trim((string)$params['api_key'], " \t\n\r\0\x0B\"'") : trim((string)$aiConfig['api_key'], " \t\n\r\0\x0B\"'");
    if (empty($apiKey)) {
        $errMsg = !empty($aiConfig['is_tenant'])
            ? 'OpenRouter API Key is not configured for your institute account. Please enter your personal OpenRouter API key in AI Settings or Account Settings to generate questions.'
            : 'OpenRouter API Key is not configured. Please enter your API key in AI Settings or Account Settings.';
        return [
            'success' => false,
            'error'   => $errMsg
        ];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'PHP cURL extension is required.'];
    }

    $model       = !empty($params['model']) ? trim($params['model']) : $aiConfig['model'];
    $className   = $params['class_name'] ?? 'General';
    $boardName   = $params['board_name'] ?? 'General';
    $subjectName = $params['subject_name'] ?? 'General';
    $topicName   = $params['topic_name'] ?? '';
    $userPrompt  = trim($params['prompt'] ?? '');
    $difficulty  = !empty($params['difficulty']) ? $params['difficulty'] : 'Medium';
    $marks       = !empty($params['marks']) ? (int)$params['marks'] : 1;

    $systemPrompt = "You are an elite academic curriculum specialist and examination author.
Your task is to generate a rigorous, unambiguous multiple-choice question (MCQ) strictly formatted as a JSON object.
Follow these academic quality requirements:
1. Question text must be clear, precise, and academically accurate. Wrap paragraphs in standard HTML <p>...</p> tags.
2. Mathematical, scientific, and physics formulas, equations, expressions, and Greek variables MUST be formatted using standard LaTeX syntax enclosed in single dollar signs (e.g. \$E = mc^2\$, \$\\frac{a}{b}\$, \$\\sqrt{x^2+y^2}\$, \$\\sin(\\theta)\$) for inline formulas or double dollar signs (\$\$ ... \$\$) for block equations. This applies to question_text, option1..option4, and explanation. Never output raw plain-text approximations like 'a/b' or 'sqrt(x)' when mathematical notation is needed.
3. Provide exactly four distinct options (option1, option2, option3, option4).
4. The correct option MUST be indicated as an integer: 1 for option1, 2 for option2, 3 for option3, or 4 for option4.
5. The remaining three options must be realistic, plausible distractors reflecting common student misconceptions.
6. Provide a concise explanation justifying why the correct option is right.

Respond ONLY with a single valid JSON object containing NO markdown wrappers, strictly matching this structure:
{
  \"question_text\": \"<p>Question prompt text here...</p>\",
  \"option1\": \"First option\",
  \"option2\": \"Second option\",
  \"option3\": \"Third option\",
  \"option4\": \"Fourth option\",
  \"correct_option\": 1,
  \"explanation\": \"Step-by-step reasoning or justification\",
  \"marks\": {$marks}
}";

    $userInstructions = "Target Criteria:
- Class/Grade: {$className}
- Board/Curriculum: {$boardName}
- Subject: {$subjectName}" . (!empty($topicName) ? "\n- Topic/Chapter: {$topicName}" : "") . "
- Difficulty Level: {$difficulty}
- Assigned Marks: {$marks}";

    if (!empty($userPrompt)) {
        $userInstructions .= "\n\nSpecific Topic & Custom Instructions from Teacher:\n" . $userPrompt;
    } else {
        $userInstructions .= "\n\nGenerate an engaging, conceptual question testing core understanding in this topic.";
    }

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInstructions]
        ],
        'temperature' => $aiConfig['temperature'] ?: 0.7,
        'max_tokens'  => 1200
    ];

    $sslOptions = get_openrouter_curl_ssl_options();
    $curlOptions = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: http://localhost:8080/shreyansir',
            'X-Title: ShreyanSir Question Bank AI'
        ],
        CURLOPT_TIMEOUT        => 45
    ] + $sslOptions;

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    // If local SSL certificate verification failed, retry once with SSL_VERIFYPEER disabled
    if ($curlErr && (in_array($curlErrNo, [60, 77, 35]) || stripos($curlErr, 'certificate') !== false)) {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        unset($curlOptions[CURLOPT_CAINFO]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
    }

    if ($curlErr) {
        return ['success' => false, 'error' => 'cURL network error: ' . $curlErr];
    }

    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = $data['error']['message'] ?? "OpenRouter API returned HTTP status {$httpCode}";
        return ['success' => false, 'error' => $errMsg];
    }

    $rawContent = $data['choices'][0]['message']['content'] ?? '';
    if (empty($rawContent)) {
        return ['success' => false, 'error' => 'OpenRouter returned an empty response.'];
    }

    // Strip markdown code fences if model enclosed JSON in ```json ... ```
    $cleanContent = trim($rawContent);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $cleanContent, $m)) {
        $cleanContent = trim($m[1]);
    }

    $parsed = json_decode($cleanContent, true);
    if (!is_array($parsed)) {
        // Fallback: search for first { and last } in string
        $start = strpos($cleanContent, '{');
        $end = strrpos($cleanContent, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $extracted = substr($cleanContent, $start, $end - $start + 1);
            $parsed = json_decode($extracted, true);
        }
    }

    if (!is_array($parsed) || empty($parsed['question_text']) || empty($parsed['option1']) || empty($parsed['option2'])) {
        return [
            'success' => false,
            'error'   => 'AI returned invalid question formatting. Raw text: ' . substr($cleanContent, 0, 300)
        ];
    }

    $correctOption = (int)($parsed['correct_option'] ?? 1);
    if ($correctOption < 1 || $correctOption > 4) {
        $correctOption = 1;
    }

    return [
        'success' => true,
        'data'    => [
            'question_text'  => $parsed['question_text'],
            'option1'        => trim($parsed['option1']),
            'option2'        => trim($parsed['option2']),
            'option3'        => trim($parsed['option3'] ?? ''),
            'option4'        => trim($parsed['option4'] ?? ''),
            'correct_option' => $correctOption,
            'explanation'    => trim($parsed['explanation'] ?? ''),
            'marks'          => (int)($parsed['marks'] ?? $marks),
            'model_used'     => $data['model'] ?? $model
        ]
    ];
}

/**
 * Generate a Fill in the Blanks Question via OpenRouter AI
 */
function generate_fill_blank_question(array $params, $pdo = null, $admin_id = null) {
    if ($admin_id === null && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }
    $aiConfig = get_openrouter_config($pdo, $admin_id);

    $apiKey = !empty($params['api_key']) ? trim((string)$params['api_key'], " \t\n\r\0\x0B\"'") : trim((string)$aiConfig['api_key'], " \t\n\r\0\x0B\"'");
    if (empty($apiKey)) {
        $errMsg = !empty($aiConfig['is_tenant'])
            ? 'OpenRouter API Key is not configured for your institute account. Please enter your personal OpenRouter API key in AI Settings or Account Settings to generate questions.'
            : 'OpenRouter API Key is not configured. Please enter your API key in AI Settings or Account Settings.';
        return [
            'success' => false,
            'error'   => $errMsg
        ];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'PHP cURL extension is required.'];
    }

    $model       = !empty($params['model']) ? trim($params['model']) : $aiConfig['model'];
    $className   = $params['class_name'] ?? 'General';
    $boardName   = $params['board_name'] ?? 'General';
    $subjectName = $params['subject_name'] ?? 'General';
    $topicName   = $params['topic_name'] ?? '';
    $userPrompt  = trim($params['prompt'] ?? '');
    $difficulty  = !empty($params['difficulty']) ? $params['difficulty'] : 'Medium';
    $marks       = !empty($params['marks']) ? (int)$params['marks'] : 1;

    $systemPrompt = "You are an elite academic curriculum specialist and examination author.
Your task is to generate an academically rigorous, engaging Fill in the Blanks question strictly formatted as a JSON object.
Follow these academic quality requirements:
1. Question text can contain one or multiple blanks, each indicated clearly by [blank] (e.g., \"Photosynthesis occurs in [blank] and produces [blank].\"). Wrap paragraphs in standard HTML <p>...</p> tags.
2. Mathematical, scientific, and physics formulas, equations, expressions, and Greek variables MUST be formatted using standard LaTeX syntax enclosed in single dollar signs (\$ ... \$) or double dollar signs (\$\$ ... \$\$).
3. For each blank, provide acceptable target answers and common synonyms separated by vertical bars (e.g. \"Chloroplast | Chloroplasts\"). Provide all blanks as a JSON array in \"correct_answers\", as well as a string in \"correct_answer\".
4. Provide a concise explanation justifying the answer.

Respond ONLY with a single valid JSON object containing NO markdown wrappers, strictly matching this structure:
{
  \"question_type\": \"fill_blank\",
  \"question_text\": \"<p>Question prompt with [blank]...</p>\",
  \"correct_answers\": [\"Accepted answer for blank 1\", \"Accepted answer for blank 2\"],
  \"correct_answer\": \"Accepted answer for blank 1 /// Accepted answer for blank 2\",
  \"explanation\": \"Step-by-step reasoning or justification\",
  \"marks\": {$marks}
}";

    $userInstructions = "Target Criteria:
- Question Format: Fill in the Blanks
- Class/Grade: {$className}
- Board/Curriculum: {$boardName}
- Subject: {$subjectName}" . (!empty($topicName) ? "\n- Topic/Chapter: {$topicName}" : "") . "
- Difficulty Level: {$difficulty}
- Assigned Marks: {$marks}";

    if (!empty($userPrompt)) {
        $userInstructions .= "\n\nSpecific Topic & Custom Instructions from Teacher:\n" . $userPrompt;
    } else {
        $userInstructions .= "\n\nGenerate an engaging, conceptual Fill in the Blanks question testing core understanding in this topic.";
    }

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInstructions]
        ],
        'temperature' => $aiConfig['temperature'] ?: 0.7,
        'max_tokens'  => 1200
    ];

    $sslOptions = get_openrouter_curl_ssl_options();
    $curlOptions = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: http://localhost:8080/shreyansir',
            'X-Title: ShreyanSir Question Bank AI'
        ],
        CURLOPT_TIMEOUT        => 45
    ] + $sslOptions;

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($curlErr && (in_array($curlErrNo, [60, 77, 35]) || stripos($curlErr, 'certificate') !== false)) {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
        $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        unset($curlOptions[CURLOPT_CAINFO]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
    }

    if ($curlErr) {
        return ['success' => false, 'error' => 'cURL network error: ' . $curlErr];
    }

    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = $data['error']['message'] ?? "OpenRouter API returned HTTP status {$httpCode}";
        return ['success' => false, 'error' => $errMsg];
    }

    $rawContent = $data['choices'][0]['message']['content'] ?? '';
    if (empty($rawContent)) {
        return ['success' => false, 'error' => 'OpenRouter returned an empty response.'];
    }

    $cleanContent = trim($rawContent);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $cleanContent, $m)) {
        $cleanContent = trim($m[1]);
    }

    $parsed = json_decode($cleanContent, true);
    if (!is_array($parsed)) {
        $start = strpos($cleanContent, '{');
        $end = strrpos($cleanContent, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $extracted = substr($cleanContent, $start, $end - $start + 1);
            $parsed = json_decode($extracted, true);
        }
    }

    if (!is_array($parsed) || empty($parsed['question_text']) || (empty($parsed['correct_answer']) && empty($parsed['correct_answers']))) {
        return [
            'success' => false,
            'error'   => 'AI returned invalid fill-in-the-blank question formatting. Raw text: ' . substr($cleanContent, 0, 300)
        ];
    }

    $answers = [];
    if (!empty($parsed['correct_answers']) && is_array($parsed['correct_answers'])) {
        $answers = array_values(array_filter(array_map('trim', $parsed['correct_answers']), 'strlen'));
    } elseif (!empty($parsed['correct_answer'])) {
        $answers = array_values(parse_fill_blank_answers($parsed['correct_answer']));
    }
    if (empty($answers)) $answers = [''];

    $ansText = count($answers) > 1 ? json_encode($answers, JSON_UNESCAPED_UNICODE) : $answers[0];

    return [
        'success'             => true,
        'question_type'       => 'fill_blank',
        'question_text'       => trim($parsed['question_text']),
        'correct_answers'     => $answers,
        'correct_answer_text' => $ansText,
        'correct_answer'      => implode(' /// ', $answers),
        'explanation'         => trim($parsed['explanation'] ?? ''),
        'marks'               => (int)($parsed['marks'] ?? $marks),
        'model_used'          => $model
    ];
}
