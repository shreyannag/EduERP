<?php
// admin/questions/check_duplicate_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$text = trim($_POST['question_text'] ?? '');
if (empty($text)) {
    echo json_encode(['duplicate_found' => false]);
    exit;
}

$cleanText = trim(strip_tags($text));
if (mb_strlen($cleanText) < 10) {
    echo json_encode(['duplicate_found' => false]);
    exit;
}

$effective_admin_id = get_effective_admin_id();
$is_super = is_super_admin();

// Check for exact matching question text or matching stripped text
if ($is_super) {
    $stmt = $pdo->prepare("
        SELECT q.id, q.class_id, q.question_text, c.name as class_name, a.username as author_name
        FROM questions q
        JOIN classes c ON q.class_id = c.id
        LEFT JOIN admins a ON q.admin_id = a.id
        WHERE q.is_archived = 0 
          AND (TRIM(q.question_text) = ? OR TRIM(q.question_text) LIKE ?)
        LIMIT 3
    ");
    $stmt->execute([$text, '%' . $cleanText . '%']);
} else {
    $stmt = $pdo->prepare("
        SELECT q.id, q.class_id, q.question_text, c.name as class_name, a.username as author_name
        FROM questions q
        JOIN classes c ON q.class_id = c.id
        LEFT JOIN admins a ON q.admin_id = a.id
        WHERE q.is_archived = 0 
          AND q.admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)
          AND (TRIM(q.question_text) = ? OR TRIM(q.question_text) LIKE ?)
        LIMIT 3
    ");
    $stmt->execute([$effective_admin_id, $effective_admin_id, $text, '%' . $cleanText . '%']);
}
$matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($matches)) {
    echo json_encode([
        'duplicate_found' => true,
        'count' => count($matches),
        'matches' => array_map(function($m) {
            return [
                'id' => $m['id'],
                'class_name' => $m['class_name'],
                'author' => $m['author_name'] ?? 'Admin',
                'snippet' => mb_substr(strip_tags($m['question_text']), 0, 70) . '...'
            ];
        }, $matches)
    ]);
} else {
    echo json_encode(['duplicate_found' => false]);
}
