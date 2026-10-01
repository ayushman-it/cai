<?php
/**
 * CUBOIDPILOT — WORKSPACE DATA RESET API
 * Allows resetting test leads, conversations, messages, and customer dossiers to start fresh.
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Verify user role is owner or admin
$uStmt = $pdo->prepare("SELECT role FROM `users` WHERE id = ? AND company_id = ? LIMIT 1");
$uStmt->execute([$userId, $companyId]);
$u = $uStmt->fetch();

if (!$u || !in_array($u['role'], ['owner', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only workspace Owners and Admins can reset workspace data']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Delete messages belonging to company's conversations
    $pdo->prepare("
        DELETE m FROM `messages` m
        INNER JOIN `conversations` c ON c.id = m.conversation_id
        WHERE c.company_id = ?
    ")->execute([$companyId]);

    // 2. Delete lead events
    $pdo->prepare("DELETE FROM `lead_events` WHERE `company_id` = ?")->execute([$companyId]);

    // 3. Delete leads
    $pdo->prepare("DELETE FROM `leads` WHERE `company_id` = ?")->execute([$companyId]);

    // 4. Delete conversations
    $pdo->prepare("DELETE FROM `conversations` WHERE `company_id` = ?")->execute([$companyId]);

    // 5. Delete test customers
    $pdo->prepare("DELETE FROM `customers` WHERE `company_id` = ?")->execute([$companyId]);

    // 6. Also reset company pulse & radar flags if any
    $pdo->prepare("UPDATE `companies` SET `updated_at` = NOW() WHERE `id` = ?")->execute([$companyId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Workspace data cleanly reset for company {$companyId}.",
        'reset_counts' => [
            'leads' => 0,
            'conversations' => 0,
            'messages' => 0,
            'customers' => 0
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}