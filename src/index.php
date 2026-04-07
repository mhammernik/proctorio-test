<?php
    // Start the session at the very beginning
    session_start();
    
    // Get question number from URL parameter (default to 1 if not set)
    $question_number = isset($_GET['q']) ? (int)$_GET['q'] : 1;
    
    // Initialize timer if not set
    if (!isset($_SESSION['timer_start'])) {
        $_SESSION['timer_start'] = time();
    }
    
    // Get questions from uploaded file or default to questions_template.json
    $json = isset($_SESSION['questionFile']) ? $_SESSION['questionFile'] : file_get_contents('questions_template.json');
    $questions = json_decode($json, true)['questions'];
    
    // Arrays are 0-based, but we want questions to start at 1
    $question_index = $question_number - 1;
    
    // Check if question exists
    if (!isset($questions[$question_index])) {
        header('Location: index.php?q=1');
        exit;
    }
    
    $current_question = $questions[$question_index];
    $total_questions = count($questions);
    $normalized_question_type = $current_question['type'] === 'open' ? 'multiline_text' : $current_question['type'];

    
    
    $exam_title = json_decode($json, true)['exam'];
    $exam_subtitle = $current_question['title'];
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prüfungsraum: <?php echo json_decode($json, true)['exam']; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="iCheck.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
</head>
<body>
    <div class="container">
        <input type="file" id="questionFile" accept=".json" onchange="loadQuestions(this)"
               nwdirectory nwworkingdir="/" hidden/>
        <label class="jsonInput" for="questionFile"> ◥ </label>
        <h1>Prüfungsraum: <?php echo json_decode($json, true)['exam']; ?></h1>
    </div>
    <script>
    function loadQuestions(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                // Validate JSON format
                const jsonContent = JSON.parse(e.target.result);
                
                // First initialize the database for this exam
                fetch('init_db.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        exam_name: jsonContent.exam
                    })
                })
                .then(response => response.json())
                .then(initData => {
                    if (initData.success) {
                        // After successful initialization, save the questions
                        return fetch('save_questions.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                questionFile: e.target.result
                            })
                        });
                    } else {
                        throw new Error(initData.error || 'Database initialization failed');
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'index.php?q=1';
                    } else {
                        alert('Error saving questions: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error: ' + error.message);
                });
            } catch (e) {
                alert('Invalid JSON file format: ' + e.message);
            }
        };
        reader.readAsText(input.files[0]);
    }
}
</script>    
    <div class="timer">Zeit verbleibend: <span id="countdown">01:30:00</span></div>
    <script>
        // Get start time from PHP session
        const startTime = <?php echo $_SESSION['timer_start']; ?>;
        const totalTime = 90 * 60; // 90 minutes in seconds
        
        function updateTimer() {
            const currentTime = Math.floor(Date.now() / 1000);
            const elapsedTime = currentTime - startTime;
            const timeLeft = Math.max(0, totalTime - elapsedTime);
            
            const hours = Math.floor(timeLeft / 3600);
            const minutes = Math.floor((timeLeft % 3600) / 60);
            const seconds = timeLeft % 60;
            
            // Format time as HH:MM:SS
            const timeString = `${hours.toString().padStart(2,'0')}:${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}`;
            
            document.getElementById('countdown').textContent = timeString;
            
            if (timeLeft > 0) {
                setTimeout(updateTimer, 1000);
            } else {
                // Show reset popup when timer ends
                const reset = confirm('Time is up! Would you like to reset the timer?');
                if (reset) {
                    // Reset session timer and reload page
                    fetch('reset_timer.php')
                        .then(() => window.location.reload());
                }
            }
        }
        
        // Start the countdown
        updateTimer();
    </script>
    <div class="content">
    <div class="question-header">
            <h2><?= $question_number ?>. Aufgabe (<?= $current_question['points'] ?> Punkte)</h2>
            <span><?=$exam_title?></span>
            <span><?=$exam_subtitle?></span>
        </div>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_questions; $i++): ?>
                <a href="?q=<?= $i ?>"><div <?= $i === $question_number ? 'class="active"' : '' ?>><?= $i ?></div></a>
            <?php endfor; ?>
        </div>
        <p>
            <?php
                // Ensure the question text is properly handled
                $questionText = $current_question['text'];
                if (is_array($questionText)) {
                    $questionText = implode("\n", $questionText); // Join array elements into a string
                }
                echo nl2br(htmlspecialchars($questionText)); // Safely output the text
            ?>
        </p>
        
        <?php if ($normalized_question_type === 'multiple_choice'): ?>
    <div>
        <?php foreach ($current_question['options'] as $key => $option): ?>
            <?php $optionText = is_array($option) && isset($option['text']) ? $option['text'] : $option; ?>
            <label class="c-custom-checkbox">
                <input name="q<?=$question_number?>_option<?=$key+1?>"
                       value="<?=$key+1?>" 
                       type="checkbox" 
                      onchange="saveOptionAnswer(<?=$question_number?>)" />
                <svg width="32" height="32" viewBox="-4 -4 39 39" aria-hidden="true" focusable="false">
                    <rect class="checkbox__background" width="35" height="35" x="-2" y="-2" stroke="currentColor" fill="none" stroke-width="3" rx="6" ry="6"></rect>
                    <polyline class="checkbox__checkmark" points="4,14 12,23 28,5" stroke="transparent" stroke-width="4" fill="none"></polyline>
                </svg>
                <span class="checkbox-label"><?= htmlspecialchars($optionText) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php elseif ($normalized_question_type === 'order'): ?>
    <div class="order-container">
        <ul id="sortable-list" class="sortable-list">
            <?php $i=0; foreach ($current_question['options'] as $text => $position): $i++; ?>
                <li class="sortable-item" data-text="<?= htmlspecialchars($text) ?>">
                    <div class="drag-handle">⋮⋮</div>
                    <span><?= htmlspecialchars($text) ?></span>
                    <div id="numbering" class="order-number"><?=$i?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <script>
        new Sortable(document.getElementById('sortable-list'), {
            animation: 150,
            handle: '.drag-handle',
            onEnd: function() {
                // Create an object with text:position pairs
                const orderObject = {};
                document.querySelectorAll('.sortable-item').forEach((item, index) => {
                    orderObject[item.dataset.text] = index + 1;
                });
                
                // Update the order numbers
                document.querySelectorAll('.order-number').forEach((num, index) => {
                    num.textContent = index + 1;
                });
                
                // Save the order
                saveAnswer(<?= $question_number ?>, orderObject);
            }
        });
    </script>
    <?php elseif ($normalized_question_type === 'allocation'): ?>
    <div class="allocation-container">
        <?php foreach ($current_question['keywords'] as $keyword => $value): ?>
            <div class="allocation-item">
                <span class="keyword"><?= htmlspecialchars($keyword) ?></span>
                <div class="select-wrapper">
                    <select 
                        class="allocation-select" 
                        data-keyword="<?= htmlspecialchars($keyword) ?>"
                        data-correct="<?= htmlspecialchars($current_question['correct'][(string)$value]) ?>"
                        onchange="saveAllocationAnswer(<?= $question_number ?>, this)">
                        <option value="">-- Auswählen --</option>
                        <?php foreach ($current_question['choices'] as $index => $choice): ?>
                            <option value="<?= htmlspecialchars($choice) ?>">
                                <?= htmlspecialchars($choice) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="feedback-icon"></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        function saveAllocationAnswer(questionNumber, selectElement) {
            const answers = {};
            document.querySelectorAll('.allocation-select').forEach(select => {
                answers[select.dataset.keyword] = select.value;
            });
            saveAnswer(questionNumber, answers);
        }
    </script>
    <?php elseif ($normalized_question_type === 'image' || $normalized_question_type === 'code'): ?>
<div class="<?= $current_question['type'] ?>-question">
    <?php if ($normalized_question_type === 'image'): ?>
        <img src="<?= htmlspecialchars($current_question['imageUrl']) ?>" alt="Question Image" class="question-image">
    <?php elseif ($normalized_question_type === 'code'): ?>
        <pre class="code-block"><code class="language-sql"><?= htmlspecialchars(implode("\n", $current_question['code'])) ?></code></pre>
    <?php endif; ?>

    <?php if (isset($current_question['options']) && is_array($current_question['options'])): ?>
        <div class="multiple-choice-options">
            <?php foreach ($current_question['options'] as $key => $option): ?>
                <?php $optionText = is_array($option) && isset($option['text']) ? $option['text'] : $option; ?>
                <label class="c-custom-checkbox">
                    <input name="q<?=$question_number?>_option<?=$key+1?>"
                           value="<?=$key+1?>" 
                           type="checkbox" 
                              onchange="saveOptionAnswer(<?=$question_number?>)" />
                    <svg width="32" height="32" viewBox="-4 -4 39 39" aria-hidden="true" focusable="false">
                        <rect class="checkbox__background" width="35" height="35" x="-2" y="-2" stroke="currentColor" fill="none" stroke-width="3" rx="6" ry="6"></rect>
                        <polyline class="checkbox__checkmark" points="4,14 12,23 28,5" stroke="transparent" stroke-width="4" fill="none"></polyline>
                    </svg>
                    <span class="checkbox-label"><?= htmlspecialchars($optionText) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($current_question['afterwardQuestions']) && is_array($current_question['afterwardQuestions'])): ?>
        <div class="afterward-questions">
            <?php foreach ($current_question['afterwardQuestions'] as $index => $afterwardQuestion): ?>
                <div class="afterward-question">
                    <p><?= htmlspecialchars($afterwardQuestion) ?></p>
                    <textarea id="afterwardAnswer<?= $question_number ?>_<?= $index ?>" 
                              placeholder="Antworten hier eingeben..." 
                              oninput="saveAfterwardAnswer(<?= $question_number ?>, <?= $index ?>, this.value)"></textarea>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php elseif ($normalized_question_type === 'multiline_text'): ?>
    <div class="multiline-text-question">

    <p class="question-text">
        </p>
        <textarea id="answer<?= $question_number ?>" 
                  placeholder="Antwort:" 
                  oninput="saveAnswer(<?= $question_number ?>, this.value)"></textarea>
    </div>
<?php endif; ?>

        <div class="buttons">
            <?php if ($question_number > 1): ?>
                <button class="back" onclick="window.location.href='?q=<?= $question_number - 1 ?>'">Zurück</button>
            <?php endif; ?>
            <button class="submit" onclick="submitExam()">Zur Abgabe</button>
            <?php if ($question_number < $total_questions): ?>
                <button class="next" onclick="window.location.href='?q=<?= $question_number + 1 ?>'">Weiter</button>
            <?php endif; ?>
        </div>

        <script>
        let saveTimeout;
        let afterwardAnswersState = {};
        const currentQuestionType = '<?= $normalized_question_type ?>';
        const currentQuestionNumber = <?= $question_number ?>;
        const hasOptionAnswers = <?= (isset($current_question['options']) && is_array($current_question['options'])) ? 'true' : 'false' ?>;
        const hasAfterwardQuestions = <?= (isset($current_question['afterwardQuestions']) && is_array($current_question['afterwardQuestions'])) ? 'true' : 'false' ?>;

        function parseStoredAnswer(rawAnswer) {
            if (typeof rawAnswer !== 'string' || rawAnswer.trim() === '') {
                return rawAnswer;
            }

            try {
                return JSON.parse(rawAnswer);
            } catch (e) {
                return rawAnswer;
            }
        }

        function getMultipleChoiceAnswers(questionNumber) {
            const checkboxes = document.querySelectorAll(`input[name^="q${questionNumber}_option"]:checked`);
            return Array.from(checkboxes).map(checkbox => parseInt(checkbox.value, 10));
        }

        function saveAnswer(questionNumber, answer) {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => {
                fetch('save_answer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question_number: questionNumber, answer: answer })
                }).then(response => response.json())
                  .then(data => {
                      if (!data.success) {
                          console.error('Error saving answer:', data.error);
                      }
                  }).catch(error => console.error('Error:', error));
            }, 500);
        }

        function saveAfterwardAnswer(questionNumber, index, answer) {
            afterwardAnswersState[index] = answer;

            if (hasOptionAnswers) {
                saveAnswer(questionNumber, {
                    selectedOptions: getMultipleChoiceAnswers(questionNumber),
                    afterwardAnswers: afterwardAnswersState
                });
                return;
            }

            saveAnswer(questionNumber, {
                afterwardAnswers: afterwardAnswersState
            });
        }

        function saveOptionAnswer(questionNumber) {
            if (hasAfterwardQuestions) {
                saveAnswer(questionNumber, {
                    selectedOptions: getMultipleChoiceAnswers(questionNumber),
                    afterwardAnswers: afterwardAnswersState
                });
                return;
            }

            saveAnswer(questionNumber, getMultipleChoiceAnswers(questionNumber));
        }

        function submitExam() {
            if (confirm('Möchten Sie die Prüfung wirklich abgeben?')) {
                window.location.href = 'report.php';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            fetch(`get_answer.php?q=${currentQuestionNumber}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !data.answer) {
                        return;
                    }

                    const parsedAnswer = parseStoredAnswer(data.answer);

                    if (currentQuestionType === 'multiple_choice' || ((currentQuestionType === 'image' || currentQuestionType === 'code') && hasOptionAnswers)) {
                        const selectedOptions = Array.isArray(parsedAnswer)
                            ? parsedAnswer
                            : (parsedAnswer && Array.isArray(parsedAnswer.selectedOptions) ? parsedAnswer.selectedOptions : []);

                        selectedOptions.forEach(optionNum => {
                            const checkbox = document.querySelector(`input[name="q${currentQuestionNumber}_option${optionNum}"]`);
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                    }

                    if (currentQuestionType === 'allocation' && parsedAnswer && typeof parsedAnswer === 'object' && !Array.isArray(parsedAnswer)) {
                        Object.entries(parsedAnswer).forEach(([keyword, answer]) => {
                            const select = document.querySelector(`.allocation-select[data-keyword="${keyword}"]`);
                            if (select) {
                                select.value = answer;
                            }
                        });

                        // Ensure no correctness styling is shown in exam mode.
                        document.querySelectorAll('.select-wrapper').forEach(wrapper => {
                            wrapper.classList.remove('correct', 'incorrect');
                        });
                    }

                    if (currentQuestionType === 'order' && parsedAnswer && typeof parsedAnswer === 'object' && !Array.isArray(parsedAnswer)) {
                        const list = document.getElementById('sortable-list');
                        if (list) {
                            const items = Array.from(list.children);
                            items.sort((a, b) => {
                                const aPos = parsedAnswer[a.dataset.text] ?? Number.MAX_SAFE_INTEGER;
                                const bPos = parsedAnswer[b.dataset.text] ?? Number.MAX_SAFE_INTEGER;
                                return aPos - bPos;
                            });

                            items.forEach(item => list.appendChild(item));
                            document.querySelectorAll('.order-number').forEach((num, index) => {
                                num.textContent = index + 1;
                            });
                        }
                    }

                    if (hasAfterwardQuestions) {
                        afterwardAnswersState = parsedAnswer && typeof parsedAnswer === 'object' && parsedAnswer.afterwardAnswers && typeof parsedAnswer.afterwardAnswers === 'object'
                            ? parsedAnswer.afterwardAnswers
                            : {};

                        Object.entries(afterwardAnswersState).forEach(([idx, value]) => {
                            const field = document.getElementById(`afterwardAnswer${currentQuestionNumber}_${idx}`);
                            if (field) {
                                field.value = value;
                            }
                        });
                    }

                    if (currentQuestionType === 'multiline_text') {
                        const textarea = document.getElementById(`answer${currentQuestionNumber}`);
                        if (textarea) {
                            textarea.value = typeof parsedAnswer === 'string' ? parsedAnswer : '';
                        }
                    }
                })
                .catch(error => console.error('Error loading answer:', error));
        });

        function toggleHint() {
            const hintText = document.getElementById('hint-text');
            const hintButton = document.querySelector('.hint-button');
            
            if (hintText.style.display === 'none') {
                hintText.style.display = 'block';
                hintButton.textContent = '- Hinweis';
            } else {
                hintText.style.display = 'none';
                hintButton.textContent = '+ Hinweis';
            }
        }
        </script>
    </div>
</body>
</html>