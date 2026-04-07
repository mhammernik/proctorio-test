<?php
session_start();

header('Content-Type: application/json');

// Get the POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || !isset($data['question_number'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request payload']);
    exit;
}

$question_number = (int)$data['question_number'];
$answer = $data['answer'] ?? '';

// Keep database values normalized: arrays/objects as JSON, scalars as plain text.
if (is_array($answer) || is_object($answer)) {
    $storedAnswer = json_encode($answer);
} elseif (is_string($answer)) {
    $trimmed = trim($answer);
    if ($trimmed === '') {
        $storedAnswer = '';
    } else {
        $decoded = json_decode($trimmed, true);
        $storedAnswer = (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded)))
            ? json_encode($decoded)
            : $answer;
    }
} else {
    $storedAnswer = (string)$answer;
}

// Debug log
error_log("Saving answer for question $question_number: " . print_r($answer, true));

try {
    $db = new SQLite3('answers.db');
    
    // Check if answer exists
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM answers WHERE question_number = :question_number');
    $stmt->bindValue(':question_number', $question_number, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray();
    
    if ($result['count'] > 0) {
        // Update existing answer
        $stmt = $db->prepare('UPDATE answers SET answer = :answer WHERE question_number = :question_number');
    } else {
        // Insert new answer
        $stmt = $db->prepare('INSERT INTO answers (question_number, answer) VALUES (:question_number, :answer)');
    }
    
    $stmt->bindValue(':question_number', $question_number, SQLITE3_INTEGER);
    $stmt->bindValue(':answer', $storedAnswer, SQLITE3_TEXT);
    $success = $stmt->execute();
    error_log("Save " . ($success ? "successful" : "failed") . " for question $question_number");
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Error saving answer: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>