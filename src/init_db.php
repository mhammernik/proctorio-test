<?php
session_start();

// Get the POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['exam_name'])) {
    echo json_encode(['success' => false, 'error' => 'No exam name provided']);
    exit;
}

$exam_name = $data['exam_name'];
// Sanitize exam name for file name (remove special characters)
$safe_exam_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $exam_name);

try {
    // Create or connect to SQLite database
    $db = new SQLite3('answers.db');
    
    // Create answers table if it doesn't exist
    $db->exec('CREATE TABLE IF NOT EXISTS answers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        question_number INTEGER NOT NULL,
        answer TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Clear existing answers for fresh start
    $db->exec('DELETE FROM answers');
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>