<?php
session_start();

// Reset the timer start time to the current timestamp
$_SESSION['timer_start'] = time();

// You can also send a JSON response if needed
echo json_encode(['status' => 'success', 'message' => 'Timer reset successfully']);
?>