<?php
session_start();

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['questionFile'])) {
    $_SESSION['questionFile'] = $data['questionFile'];
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}

if (isset($data['questions']) && is_array($data['questions'])) {
    foreach ($data['questions'] as $question) {
        if (isset($question['options']) && is_array($question['options'])) {
            $question['type'] = 'multiple_choice';
            // Save logic for multiple-choice questions
            saveMultipleChoiceQuestion($question);
        }
    }
}