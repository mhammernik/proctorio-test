<?php
session_start();

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['current_exam_table'])) {
        throw new Exception('No active exam session');
    }

    $table_name = $_SESSION['current_exam_table'];
    $db = new SQLite3('answers.db');

    // Get all answers for the current exam
    $query = "SELECT question_number, answer FROM $table_name ORDER BY question_number";
    $result = $db->query($query);

    $answers = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $answers[$row['question_number']] = [
            'answer' => $row['answer'],
            'answered' => !empty($row['answer']) && $row['answer'] !== '[]' // Check if answer exists and is not empty array
        ];
    }

    // Get total number of questions from the session
    $json = isset($_SESSION['questionFile']) ? $_SESSION['questionFile'] : file_get_contents('questions_template.json');
    $questions = json_decode($json, true)['questions'];
    $total_questions = count($questions);

    // Initialize status for all questions
    $status = [];
    for ($i = 1; $i <= $total_questions; $i++) {
        $status[$i] = isset($answers[$i]) && $answers[$i]['answered'];
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'total_questions' => $total_questions,
        'answered_questions' => count(array_filter($status))
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>