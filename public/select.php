<?php
/**
 * public/select.php (NEW FILE)
 * - Handles the user's selection from the results page.
 * - Saves the choice to the 'selections' table.
 * - Triggers the analytics module to record pairwise results and update Elo ratings.
 * - Redirects the user back to the main page.
 */

declare(strict_types=1);

// 1. Bootstrap the application
require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\Analytics;

// 2. Start session and perform validation
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// 3. Get and validate inputs from the form
$sessionId = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
$reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
$chosenSlot = $_POST['chosen_slot'] ?? null;
$feedbackReason = $_POST['feedback_reason'] ?? null;

if (!$sessionId || !$reportId || !in_array($chosenSlot, ['A', 'B', 'C', 'D'], true)) {
    // Redirect if essential data is missing
    header('Location: index.php?error=invalid_selection');
    exit;
}

try {
    $db = new DB($config['db']);

    // 4. Verify that the current user owns this report/session
    $report = $db->one('SELECT user_id FROM dream_reports WHERE id = ?', [$reportId]);
    if (!$report || (int)$report['user_id'] !== (int)$_SESSION['user_id']) {
        throw new \Exception('Access denied to this report.');
    }

    // 5. Find the ID of the chosen option
    $session = $db->one(
        'SELECT A_option_id, B_option_id, C_option_id, D_option_id FROM presentation_sessions WHERE id = ?',
        [$sessionId]
    );

    if (!$session) {
        throw new \Exception('Presentation session not found.');
    }
    
    // Map slot ('A', 'B', etc.) to the actual option ID
    $winnerOptionId = (int)$session[$chosenSlot . '_option_id'];

    // 6. Save the user's selection to the database
    $db->execStmt(
        'INSERT INTO selections (report_id, session_id, chosen_slot, chosen_option_id, feedback_reason) VALUES (?, ?, ?, ?, ?)',
        [$reportId, $sessionId, $chosenSlot, $winnerOptionId, $feedbackReason]
    );

    // 7. Trigger the analytics engine
    $allOptionIds = [
        (int)$session['A_option_id'],
        (int)$session['B_option_id'],
        (int)$session['C_option_id'],
        (int)$session['D_option_id'],
    ];
    
    // Identify the losers
    $loserOptionIds = array_values(array_filter($allOptionIds, fn($id) => $id !== $winnerOptionId));

    // A. Record pairwise results (winner vs each loser)
    Analytics::recordPairwise($db, $sessionId, $winnerOptionId, $loserOptionIds);

    // B. Update Elo ratings based on this selection
    Analytics::applyEloForSelection($db, $sessionId, $winnerOptionId);

    // 8. Redirect back to the homepage after successful submission
    header('Location: index.php?selection=success');
    exit;

} catch (\Throwable $e) {
    // For a real application, you might want a more user-friendly error page
    // and to log the actual error message.
    error_log($e->getMessage()); // Log error for debugging
    header('Location: index.php?error=processing_failed');
    exit;
}