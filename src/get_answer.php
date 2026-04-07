<?php
session_start();

$question_number = isset($_GET['q']) ? (int)$_GET['q'] : 1;

try {
    $db = new SQLite3('answers.db');
    
    // Get the question type from the session or questions file
    $json = isset($_SESSION['questionFile']) ? $_SESSION['questionFile'] : file_get_contents('questions_template.json');
    $questions = json_decode($json, true)['questions'];
    $question_type = $questions[$question_number - 1]['type'];
    
    $stmt = $db->prepare('SELECT answer FROM answers WHERE question_number = :question_number');
    $stmt->bindValue(':question_number', $question_number, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($result) {
        $answer = $result['answer'];
        // For order type, ensure the answer is valid JSON
        if ($question_type === 'order') {
            if (!json_decode($answer)) {
                $answer = '[]'; // Return empty array if invalid JSON
            }
        }
        echo json_encode(['success' => true, 'answer' => $answer, 'type' => $question_type]);
    } else {
        echo json_encode(['success' => true, 'answer' => '', 'type' => $question_type]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>