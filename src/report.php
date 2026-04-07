<?php
session_start();

// Get questions from the session or file
$json = isset($_SESSION['questionFile']) ? $_SESSION['questionFile'] : file_get_contents('questions_template.json');
$exam_data = json_decode($json, true);
$questions = $exam_data['questions'];
$exam_name = $exam_data['exam'];

function normalize_question_type($question) {
    return ($question['type'] ?? '') === 'open' ? 'multiline_text' : ($question['type'] ?? '');
}

function decode_answer_value($rawAnswer) {
    if ($rawAnswer === null || $rawAnswer === '') {
        return null;
    }

    if (!is_string($rawAnswer)) {
        return $rawAnswer;
    }

    $decoded = json_decode($rawAnswer, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }

    return $rawAnswer;
}

function extract_selected_options($decodedAnswer) {
    if (is_array($decodedAnswer) && array_keys($decodedAnswer) === range(0, count($decodedAnswer) - 1)) {
        return $decodedAnswer;
    }

    if (is_array($decodedAnswer) && isset($decodedAnswer['selectedOptions']) && is_array($decodedAnswer['selectedOptions'])) {
        return $decodedAnswer['selectedOptions'];
    }

    return [];
}

function extract_afterward_answers($decodedAnswer) {
    if (is_array($decodedAnswer) && isset($decodedAnswer['afterwardAnswers']) && is_array($decodedAnswer['afterwardAnswers'])) {
        return $decodedAnswer['afterwardAnswers'];
    }

    return [];
}

function check_question_correctness($question, $decodedAnswer) {
    $type = normalize_question_type($question);

    if ($decodedAnswer === null || $decodedAnswer === '') {
        return null;
    }

    if (($type === 'multiple_choice' || $type === 'image' || $type === 'code') && isset($question['options']) && is_array($question['options'])) {
        $hasCorrectMetadata = false;
        $correctOptionNumbers = [];

        foreach ($question['options'] as $idx => $option) {
            if (is_array($option) && isset($option['isCorrect'])) {
                $hasCorrectMetadata = true;
                if ($option['isCorrect']) {
                    $correctOptionNumbers[] = $idx + 1;
                }
            }
        }

        if (!$hasCorrectMetadata) {
            return null;
        }

        $selected = array_map('intval', extract_selected_options($decodedAnswer));
        sort($selected);
        sort($correctOptionNumbers);
        return $selected === $correctOptionNumbers;
    }

    if ($type === 'allocation' && isset($question['keywords']) && isset($question['correct']) && is_array($question['keywords'])) {
        if (!is_array($decodedAnswer)) {
            return false;
        }

        foreach ($question['keywords'] as $keyword => $value) {
            $correct = $question['correct'][(string)$value] ?? '';
            $user = $decodedAnswer[$keyword] ?? '';
            if ($user !== $correct) {
                return false;
            }
        }

        return true;
    }

    if ($type === 'order' && isset($question['options']) && is_array($question['options'])) {
        if (!is_array($decodedAnswer)) {
            return false;
        }

        foreach ($question['options'] as $text => $position) {
            if (!isset($decodedAnswer[$text]) || (int)$decodedAnswer[$text] !== (int)$position) {
                return false;
            }
        }

        return true;
    }

    return null;
}


// Connect to the database
try {
    $db = new SQLite3('answers.db');
    
    // Get saved answers from database
    $answers = [];
    $answer_meta = [];
    $total_points = 0;
    $achieved_points = 0;

    foreach ($questions as $index => $question) {
        $question_number = $index + 1;
        
        // Get answer from database
        $stmt = $db->prepare('SELECT answer FROM answers WHERE question_number = :question_number');
        $stmt->bindValue(':question_number', $question_number, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($result) {
            $rawAnswer = $result['answer'];
            if ($rawAnswer !== null && $rawAnswer !== '') {
                $answers[$question_number] = $rawAnswer;
            }

            $decodedAnswer = decode_answer_value($rawAnswer);
            $isCorrect = check_question_correctness($question, $decodedAnswer);

            $answer_meta[$question_number] = [
                'decoded' => $decodedAnswer,
                'selectedOptions' => extract_selected_options($decodedAnswer),
                'afterwardAnswers' => extract_afterward_answers($decodedAnswer),
                'isCorrect' => $isCorrect,
            ];

            if ($isCorrect !== null) {
                $total_points += $question['points'];
                if ($isCorrect) {
                    $achieved_points += $question['points'];
                }
            }
        }
    }

    // Calculate percentage
    $percentage = ($total_points > 0) ? ($achieved_points / $total_points) * 100 : 0;

} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prüfungsbericht</title>
    <link rel="stylesheet" href="report.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"></script>
</head>
<body>
    <div class="header">
        <h1>Prüfungsbericht</h1>
        <h2><?= htmlspecialchars($exam_name) ?></h2>
        <p>Automatisch bewertbar: <?= number_format($achieved_points, 2, ',', '.') ?> / <?= number_format($total_points, 2, ',', '.') ?> Punkte (<?= number_format($percentage, 1, ',', '.') ?>%)</p>
    </div>

    <button class="slider-nav prev" onclick="changeQuestion(-1)">←</button>
    <button class="slider-nav next" onclick="changeQuestion(1)">→</button>

    <div class="questions-container">
        <?php foreach ($questions as $index => $question): 
            $questionNum = $index + 1;
            $questionType = normalize_question_type($question);
            $decodedAnswer = $answer_meta[$questionNum]['decoded'] ?? null;
            $selectedOptions = $answer_meta[$questionNum]['selectedOptions'] ?? [];
            $afterwardAnswers = $answer_meta[$questionNum]['afterwardAnswers'] ?? [];
            $questionIsCorrect = $answer_meta[$questionNum]['isCorrect'] ?? null;
        ?>
            <div class="question-report" id="question<?= $questionNum ?>">
                <h3><?= $questionNum ?>. <?= htmlspecialchars($question['title']) ?> (<?= $question['points'] ?> Punkte)</h3>
                <p class="question-text">
                    <?php if (is_array($question['text'])): ?>
                        <?= implode('<br>', array_map('htmlspecialchars', $question['text'])) ?>
                    <?php else: ?>
                        <?= nl2br(htmlspecialchars($question['text'])) ?>
                    <?php endif; ?>
                </p>
                <div class="answer">
                    <h4>Ihre Antwort:</h4>
                    <?php if (isset($answers[$questionNum])): ?>
                        <?php if ($questionType === 'multiple_choice'): ?>
                            <?php
                                $userAnswers = $selectedOptions;
                                $allCorrect = true;
                                $hasCorrectAnswers = false;
                            ?>
                            <div class="mc-options-list">
                                <?php foreach ($question['options'] as $optIndex => $option): 
                                    $optionNum = $optIndex + 1;
                                    $optionText = is_array($option) ? $option['text'] : $option;
                                    $isCorrectOption = is_array($option) && isset($option['isCorrect']) ? $option['isCorrect'] : false;
                                    $wasSelected = in_array($optionNum, (array)$userAnswers) || in_array((string)$optionNum, (array)$userAnswers);
                                    
                                    if ($isCorrectOption) $hasCorrectAnswers = true;
                                    
                                    // Determine if this specific answer is correct
                                    $isAnswerCorrect = ($wasSelected && $isCorrectOption) || (!$wasSelected && !$isCorrectOption);
                                    if (!$isAnswerCorrect) $allCorrect = false;
                                    
                                    // Determine CSS class
                                    $optionClass = '';
                                    if ($wasSelected && $isCorrectOption) {
                                        $optionClass = 'selected-correct';
                                    } elseif ($wasSelected && !$isCorrectOption) {
                                        $optionClass = 'selected-incorrect';
                                    } elseif (!$wasSelected && $isCorrectOption) {
                                        $optionClass = 'missed-correct';
                                    }
                                ?>
                                    <div class="mc-option <?= $optionClass ?>">
                                        <span class="mc-checkbox"><?= $wasSelected ? '☑' : '☐' ?></span>
                                        <span class="mc-option-text"><?= htmlspecialchars($optionText) ?></span>
                                        <?php if ($wasSelected && $isCorrectOption): ?>
                                            <span class="status-icon correct">✓</span>
                                        <?php elseif ($wasSelected && !$isCorrectOption): ?>
                                            <span class="status-icon incorrect">✗</span>
                                        <?php elseif (!$wasSelected && $isCorrectOption): ?>
                                            <span class="status-icon missed">○</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($hasCorrectAnswers): ?>
                                <div class="result-summary <?= $allCorrect ? 'all-correct' : '' ?>">
                                    <?= $allCorrect ? '✓ Alle Antworten korrekt!' : '✗ Nicht alle Antworten korrekt' ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($questionType === 'allocation'): ?>
                            <?php
                                $userAllocations = is_array($decodedAnswer) ? $decodedAnswer : [];
                                $allCorrect = true;
                            ?>
                            <table class="allocation-table">
                                <thead>
                                    <tr>
                                        <th>Begriff</th>
                                        <th>Ihre Zuordnung</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($question['keywords'] as $keyword => $value): 
                                        $correctAnswer = isset($question['correct'][$value]) ? $question['correct'][$value] : '';
                                        $userAnswer = isset($userAllocations[$keyword]) ? $userAllocations[$keyword] : '';
                                        $isCorrect = ($userAnswer === $correctAnswer);
                                        if (!$isCorrect) $allCorrect = false;
                                    ?>
                                        <tr class="<?= $isCorrect ? 'correct' : 'incorrect' ?>">
                                            <td><strong><?= htmlspecialchars($keyword) ?></strong></td>
                                            <td>
                                                <?= htmlspecialchars($userAnswer ?: '(Keine Antwort)') ?>
                                                <?php if (!$isCorrect && $correctAnswer): ?>
                                                    <div class="correct-answer">
                                                        <em>Richtig: <?= htmlspecialchars($correctAnswer) ?></em>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-icon <?= $isCorrect ? 'correct' : 'incorrect' ?>">
                                                    <?= $isCorrect ? '✓' : '✗' ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="result-summary <?= $allCorrect ? 'all-correct' : '' ?>">
                                <?= $allCorrect ? '✓ Alle Zuordnungen korrekt!' : '✗ Nicht alle Zuordnungen korrekt' ?>
                            </div>
                        <?php elseif ($questionType === 'order'): ?>
                            <?php
                                $userOrder = is_array($decodedAnswer) ? $decodedAnswer : [];
                                $allCorrect = ($questionIsCorrect === true);
                            ?>
                            <table class="allocation-table">
                                <thead>
                                    <tr>
                                        <th>Element</th>
                                        <th>Ihre Position</th>
                                        <th>Korrekte Position</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($question['options'] as $text => $correctPos): 
                                        $userPos = $userOrder[$text] ?? '';
                                        $isCorrectPos = ((int)$userPos === (int)$correctPos);
                                    ?>
                                        <tr class="<?= $isCorrectPos ? 'correct' : 'incorrect' ?>">
                                            <td><strong><?= htmlspecialchars($text) ?></strong></td>
                                            <td><?= htmlspecialchars($userPos === '' ? '(Keine Antwort)' : (string)$userPos) ?></td>
                                            <td><?= htmlspecialchars((string)$correctPos) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="result-summary <?= $allCorrect ? 'all-correct' : '' ?>">
                                <?= $allCorrect ? '✓ Reihenfolge korrekt!' : '✗ Reihenfolge nicht korrekt' ?>
                            </div>
                        <?php elseif ($questionType === 'image'): ?>
                            <div class="image-container">
                                <img src="<?= htmlspecialchars($question['imageUrl']) ?>" alt="Question Image" class="question-image">
                            </div>
                            <?php if (isset($question['options']) && is_array($question['options'])): ?>
                                <?php
                                    $userAnswers = $selectedOptions;
                                    $allCorrect = true;
                                    $hasCorrectAnswers = false;
                                ?>
                                <div class="mc-options-list">
                                    <?php foreach ($question['options'] as $optIndex => $option): 
                                        $optionNum = $optIndex + 1;
                                        $optionText = is_array($option) ? $option['text'] : $option;
                                        $isCorrectOption = is_array($option) && isset($option['isCorrect']) ? $option['isCorrect'] : false;
                                        $wasSelected = in_array($optionNum, (array)$userAnswers) || in_array((string)$optionNum, (array)$userAnswers);

                                        if ($isCorrectOption) $hasCorrectAnswers = true;

                                        $isAnswerCorrect = ($wasSelected && $isCorrectOption) || (!$wasSelected && !$isCorrectOption);
                                        if (!$isAnswerCorrect) $allCorrect = false;

                                        $optionClass = '';
                                        if ($wasSelected && $isCorrectOption) {
                                            $optionClass = 'selected-correct';
                                        } elseif ($wasSelected && !$isCorrectOption) {
                                            $optionClass = 'selected-incorrect';
                                        } elseif (!$wasSelected && $isCorrectOption) {
                                            $optionClass = 'missed-correct';
                                        }
                                    ?>
                                        <div class="mc-option <?= $optionClass ?>">
                                            <span class="mc-checkbox"><?= $wasSelected ? '☑' : '☐' ?></span>
                                            <span class="mc-option-text"><?= htmlspecialchars($optionText) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($hasCorrectAnswers): ?>
                                    <div class="result-summary <?= $allCorrect ? 'all-correct' : '' ?>">
                                        <?= $allCorrect ? '✓ Alle Antworten korrekt!' : '✗ Nicht alle Antworten korrekt' ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ($questionType === 'code'): ?>
                            <div class="code-container">
                                <pre class="code-block"><code class="language-sql"><?= htmlspecialchars(implode("\n", $question['code'])) ?></code></pre>
                            </div>
                            <?php if (isset($question['options'])): ?>
                                <?php
                                    $userAnswers = $selectedOptions;
                                    $allCorrect = true;
                                    $hasCorrectAnswers = false;
                                ?>
                                <div class="mc-options-list">
                                    <?php foreach ($question['options'] as $optIndex => $option): 
                                        $optionNum = $optIndex + 1;
                                        $optionText = is_array($option) ? $option['text'] : $option;
                                        $isCorrectOption = is_array($option) && isset($option['isCorrect']) ? $option['isCorrect'] : false;
                                        $wasSelected = in_array($optionNum, (array)$userAnswers) || in_array((string)$optionNum, (array)$userAnswers);
                                        
                                        if ($isCorrectOption) $hasCorrectAnswers = true;
                                        
                                        $isAnswerCorrect = ($wasSelected && $isCorrectOption) || (!$wasSelected && !$isCorrectOption);
                                        if (!$isAnswerCorrect) $allCorrect = false;
                                        
                                        $optionClass = '';
                                        if ($wasSelected && $isCorrectOption) {
                                            $optionClass = 'selected-correct';
                                        } elseif ($wasSelected && !$isCorrectOption) {
                                            $optionClass = 'selected-incorrect';
                                        } elseif (!$wasSelected && $isCorrectOption) {
                                            $optionClass = 'missed-correct';
                                        }
                                    ?>
                                        <div class="mc-option <?= $optionClass ?>">
                                            <span class="mc-checkbox"><?= $wasSelected ? '☑' : '☐' ?></span>
                                            <span class="mc-option-text"><?= htmlspecialchars($optionText) ?></span>
                                            <?php if ($wasSelected && $isCorrectOption): ?>
                                                <span class="status-icon correct">✓</span>
                                            <?php elseif ($wasSelected && !$isCorrectOption): ?>
                                                <span class="status-icon incorrect">✗</span>
                                            <?php elseif (!$wasSelected && $isCorrectOption): ?>
                                                <span class="status-icon missed">○</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($hasCorrectAnswers): ?>
                                    <div class="result-summary <?= $allCorrect ? 'all-correct' : '' ?>">
                                        <?= $allCorrect ? '✓ Alle Antworten korrekt!' : '✗ Nicht alle Antworten korrekt' ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p><?= nl2br(htmlspecialchars((string)$answers[$questionNum])) ?></p>
                            <?php endif; ?>
                        <?php elseif ($questionType === 'multiline_text'): ?>
                            <p><?= nl2br(htmlspecialchars(is_string($decodedAnswer) ? $decodedAnswer : (string)$answers[$questionNum])) ?></p>
                        <?php else: ?>
                            <p><?= nl2br(htmlspecialchars($answers[$questionNum])) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($afterwardAnswers) && is_array($afterwardAnswers)): ?>
                            <h4>Zusatzantworten:</h4>
                            <?php foreach ($afterwardAnswers as $idx => $value): ?>
                                <p><strong><?= (int)$idx + 1 ?>.</strong> <?= nl2br(htmlspecialchars((string)$value)) ?></p>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="no-answer">Keine Antwort gegeben</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="navigation">
        <?php for ($i = 1; $i <= count($questions); $i++): ?>
            <div class="nav-dot" onclick="goToQuestion(<?= $i ?>)" data-question="<?= $i ?>"></div>
        <?php endfor; ?>
    </div>

    <div class="footer">
        <button onclick="window.location.href='index.php'">Zurück zur Prüfung</button>
    </div>

    <script>
        let currentQuestion = 1;
        const totalQuestions = <?= count($questions) ?>;
        
        function updateQuestionClasses() {
            document.querySelectorAll('.question-report').forEach((question, index) => {
                const questionNumber = index + 1;
                question.classList.remove('active', 'prev', 'next');
                
                if (questionNumber === currentQuestion) {
                    question.classList.add('active');
                } else if (questionNumber === currentQuestion - 1) {
                    question.classList.add('prev');
                } else if (questionNumber === currentQuestion + 1) {
                    question.classList.add('next');
                }
            });

            // Update navigation dots
            document.querySelectorAll('.nav-dot').forEach((dot, index) => {
                dot.classList.toggle('active', index + 1 === currentQuestion);
            });

            // Update navigation buttons
            document.querySelector('.slider-nav.prev').style.visibility = 
                currentQuestion === 1 ? 'hidden' : 'visible';
            document.querySelector('.slider-nav.next').style.visibility = 
                currentQuestion === totalQuestions ? 'hidden' : 'visible';
        }

        function changeQuestion(direction) {
            const newQuestion = currentQuestion + direction;
            if (newQuestion >= 1 && newQuestion <= totalQuestions) {
                currentQuestion = newQuestion;
                updateQuestionClasses();
            }
        }

        function goToQuestion(number) {
            if (number >= 1 && number <= totalQuestions) {
                currentQuestion = number;
                updateQuestionClasses();
            }
        }

        // Initialize the first question
        document.addEventListener('DOMContentLoaded', () => {
            updateQuestionClasses();
        });

        // Add keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                changeQuestion(-1);
            } else if (e.key === 'ArrowRight') {
                changeQuestion(1);
            }
        });

        // Add mouse wheel (jog dial) navigation
        document.addEventListener('wheel', (e) => {
            // Prevent default scrolling
            e.preventDefault();
            
            // Determine scroll direction
            if (e.deltaY > 0) {
                changeQuestion(1); // Scroll down/right
            } else if (e.deltaY < 0) {
                changeQuestion(-1); // Scroll up/left
            }
            }, { passive: false });

        function renderMath() {
            if (typeof renderMathInElement !== 'function') {
                return;
            }

            renderMathInElement(document.body, {
                delimiters: [
                    { left: '$$', right: '$$', display: true },
                    { left: '$', right: '$', display: false }
                ],
                throwOnError: false
            });
        }

        window.addEventListener('load', renderMath);
    </script>
</body>
</html>