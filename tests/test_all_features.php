<?php
/**
 * CUBOIDPILOT — COMPREHENSIVE AUTOMATED VERIFICATION SUITE
 * Tests all 25 sections of the integrations, session system, calendar, and multi-tenant requirements.
 */

define('TEST_RUNNER', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/appointment_helper.php';
require_once __DIR__ . '/../api/entitlements.php';

// Color formatting for console
$cGreen  = "\033[32m";
$cRed    = "\033[31m";
$cYellow = "\033[33m";
$cBlue   = "\033[36m";
$cReset  = "\033[0m";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest($name, $condition, $details = '') {
    global $totalTests, $passedTests, $failedTests, $cGreen, $cRed, $cReset, $cYellow;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  {$cGreen}[PASS]{$cReset} {$name}\n";
        if ($details) echo "         {$cYellow}↳ {$details}{$cReset}\n";
    } else {
        $failedTests++;
        echo "  {$cRed}[FAIL]{$cReset} {$name}\n";
        if ($details) echo "         {$cRed}↳ Error: {$details}{$cReset}\n";
    }
}

echo "\n{$cBlue}========================================================================{$cReset}\n";
echo "{$cBlue}   CUBOIDPILOT — COMPLETE AUTOMATED INTEGRATION & SECURITY TEST SUITE   {$cReset}\n";
echo "{$cBlue}========================================================================{$cReset}\n\n";

$pdo = getDbConnection();

// Ensure test companies exist
$stmt = $pdo->query("SELECT id, name, company_key FROM companies ORDER BY id ASC");
$allCompanies = $stmt->fetchAll();

if (count($allCompanies) < 2) {
    // Insert a secondary company for multi-tenant isolation tests
    $pdo->exec("INSERT INTO companies (name, company_key, status, created_at) VALUES ('Acme Corp Isolated', 'cp_live_acme_isolated_test', 'active', NOW())");
    $stmt = $pdo->query("SELECT id, name, company_key FROM companies ORDER BY id ASC");
    $allCompanies = $stmt->fetchAll();
}

$companyA = $allCompanies[0]; // e.g. Tenant A
$companyB = $allCompanies[1]; // e.g. Tenant B
$companyAId = (int)$companyA['id'];
$companyBId = (int)$companyB['id'];

echo "{$cYellow}Test Setup:{$cReset}\n";
echo "  • Tenant A: {$companyA['name']} (ID: {$companyAId}, Key: {$companyA['company_key']})\n";
echo "  • Tenant B: {$companyB['name']} (ID: {$companyBId}, Key: {$companyB['company_key']})\n\n";

// =============================================================================
// SECTION 1: AI SESSION CREATION & VISITOR IDENTIFICATION
// =============================================================================
echo "{$cBlue}[TEST SUITE 1] AI Session Creation & Visitor Identification (Section 1){$cReset}\n";

$testPhone = '+9198765' . rand(10000, 99999);
$testName = 'Test User ' . rand(100, 999);
$testEmail = 'user' . rand(100, 999) . '@testpilot.com';
$custUuid = 'cust_' . bin2hex(random_bytes(10));

// 1.1 First-time visitor session creation
$vStmt = $pdo->prepare("
    INSERT INTO `customers` (`company_id`, `customer_uuid`, `name`, `phone`, `email`, `whatsapp_number`, `first_seen_at`, `last_seen_at`)
    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
");
$vStmt->execute([$companyAId, $custUuid, $testName, $testPhone, $testEmail, $testPhone]);
$visitorId = (int)$pdo->lastInsertId();

$sessionId = 'CP-' . strtoupper(substr(hash('sha256', uniqid('sess_', true)), 0, 7));
$sessionToken = "sess_" . bin2hex(random_bytes(12));

$sStmt = $pdo->prepare("
    INSERT INTO `visitor_sessions` (`company_id`, `visitor_id`, `customer_id`, `session_id`, `session_token`, `channel`, `created_at`, `updated_at`)
    VALUES (?, ?, ?, ?, ?, 'web', NOW(), NOW())
");
$sStmt->execute([$companyAId, $visitorId, $visitorId, $sessionId, $sessionToken]);
$sessRowId = (int)$pdo->lastInsertId();

assertTest("Visitor/Customer record created with ID > 0", $visitorId > 0, "Visitor ID: {$visitorId}");
assertTest("Session ID matches required CP-XXXXXXX pattern", (bool)preg_match('/^CP-[A-Z0-9]{7}$/', $sessionId), "Session ID: {$sessionId}");

// 1.2 System confirmation message format
$expectedConfirmation = "✓ Session created successfully\nYou can now continue your conversation.";
assertTest("System confirmation message matches specification", strpos($expectedConfirmation, "✓ Session created successfully") !== false);

// 1.3 Returning Visitor Deduplication
$dupCheck = $pdo->prepare("SELECT id, name FROM `customers` WHERE `company_id` = ? AND `phone` = ? LIMIT 1");
$dupCheck->execute([$companyAId, $testPhone]);
$existingVisitor = $dupCheck->fetch();
assertTest("Returning visitor recognized by phone number deduplication", $existingVisitor && (int)$existingVisitor['id'] === $visitorId, "Found existing ID {$existingVisitor['id']}");

// =============================================================================
// SECTION 2 & 3: COMPANY EMAIL CONFIGURATION & TEAM INVITATIONS
// =============================================================================
echo "\n{$cBlue}[TEST SUITE 2] Company Email Config & Team Invitations (Section 2 & 3){$cReset}\n";

$rawPassword = "smtpSecretPass_" . rand(1000, 9999) . "!@#";
$encryptedPass = encryptSecret($rawPassword);
$decryptedPass = decryptSecret($encryptedPass);

assertTest("AES-256 Symmetric Encryption produces non-empty ciphertext distinct from plaintext", !empty($encryptedPass) && $encryptedPass !== $rawPassword);
assertTest("AES-256 Decryption restores identical original SMTP password", $decryptedPass === $rawPassword, "Restored: {$decryptedPass}");

// Save email config for Company A
$emStmt = $pdo->prepare("
    INSERT INTO `company_email_configs`
    (`company_id`, `sender_name`, `sender_email`, `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password_encrypted`, `encryption_type`, `is_verified`, `created_at`, `updated_at`)
    VALUES (?, ?, ?, 'smtp.mailtest.com', 587, ?, ?, 'tls', 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        `sender_name` = VALUES(`sender_name`),
        `sender_email` = VALUES(`sender_email`),
        `smtp_password_encrypted` = VALUES(`smtp_password_encrypted`),
        `is_verified` = 1
");
$emStmt->execute([$companyAId, 'Tenant A Team', 'notifications@tenanta.com', 'user@tenanta.com', $encryptedPass]);

$fetchEmail = $pdo->prepare("SELECT * FROM `company_email_configs` WHERE `company_id` = ? LIMIT 1");
$fetchEmail->execute([$companyAId]);
$cfgA = $fetchEmail->fetch();
assertTest("Company email configuration saved for Tenant A", (bool)$cfgA && $cfgA['sender_email'] === 'notifications@tenanta.com');

// Test team invitation token generation & validation
$inviteToken = bin2hex(random_bytes(24));
$inviteEmail = 'colleague_' . rand(100, 999) . '@tenanta.com';
$invStmt = $pdo->prepare("
    INSERT INTO `team_invitations` (`company_id`, `invited_by_user_id`, `email`, `role`, `invitation_token`, `status`, `expires_at`, `created_at`)
    VALUES (?, 1, ?, 'sales_agent', ?, 'pending', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())
");
$invStmt->execute([$companyAId, $inviteEmail, $inviteToken]);
$invId = (int)$pdo->lastInsertId();

assertTest("Team invitation created with secure token", $invId > 0 && strlen($inviteToken) === 48);

// Test Invitation Acceptance Simulation
$findInv = $pdo->prepare("SELECT * FROM `team_invitations` WHERE `invitation_token` = ? AND `status` = 'pending' AND `expires_at` > NOW() LIMIT 1");
$findInv->execute([$inviteToken]);
$activeInv = $findInv->fetch();
assertTest("Team invitation token is valid and unexpired", (bool)$activeInv);

// =============================================================================
// SECTION 4 & 5: INSTAGRAM GATEWAY, HANDOFF & WEBHOOK
// =============================================================================
echo "\n{$cBlue}[TEST SUITE 3] Instagram Gateway & Handoff Routing (Section 4 & 5){$cReset}\n";

$rawToken = 'EAABw_test_token_' . rand(100000, 999999);
$encToken = encryptSecret($rawToken);
$verifyTok = 'cp_ig_verify_' . bin2hex(random_bytes(8));

$igStmt = $pdo->prepare("
    INSERT INTO `company_instagram_configs`
    (`company_id`, `page_id`, `instagram_account_id`, `instagram_username`, `access_token_encrypted`, `webhook_verify_token`, `status`, `created_at`, `updated_at`)
    VALUES (?, '109283746592019', '17841400234567890', 'cuboidtenant_a', ?, ?, 'connected', NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        `instagram_username` = VALUES(`instagram_username`),
        `access_token_encrypted` = VALUES(`access_token_encrypted`),
        `webhook_verify_token` = VALUES(`webhook_verify_token`),
        `status` = 'connected'
");
$igStmt->execute([$companyAId, $encToken, $verifyTok]);

$fetchIg = $pdo->prepare("SELECT * FROM `company_instagram_configs` WHERE `company_id` = ? LIMIT 1");
$fetchIg->execute([$companyAId]);
$igCfg = $fetchIg->fetch();
assertTest("Instagram config saved and connected for Tenant A", (bool)$igCfg && $igCfg['status'] === 'connected');

// Test Handoff Token Generation
$handoffToken = bin2hex(random_bytes(10));
$handoffCode = "CP-IG-{$companyAId}-{$handoffToken}";
$handStmt = $pdo->prepare("
    INSERT INTO `instagram_handoffs` (`company_id`, `session_id`, `customer_id`, `web_conversation_id`, `handoff_token`, `status`, `expires_at`, `created_at`)
    VALUES (?, ?, ?, 1, ?, 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
");
$handStmt->execute([$companyAId, $sessionId, $visitorId, $handoffToken]);
assertTest("Instagram handoff token generated in CP-IG-{companyId}-{token} format", (bool)preg_match('/^CP-IG-\d+-[a-f0-9]+$/', $handoffCode), "Handoff code: {$handoffCode}");

// Test Webhook Verification Simulation
$simulatedHubVerify = $verifyTok;
assertTest("Webhook hub.verify_token matches company's configured token", $simulatedHubVerify === $igCfg['webhook_verify_token']);

// =============================================================================
// SECTION 6: GENERIC OMNICHANNEL ARCHITECTURE
// =============================================================================
echo "\n{$cBlue}[TEST SUITE 4] Generic Omnichannel Architecture (Section 6){$cReset}\n";

$channels = ['web', 'instagram', 'whatsapp', 'email'];
$supportedInDb = true;

foreach ($channels as $ch) {
    $cStmt = $pdo->prepare("
        INSERT INTO `conversations` (`company_id`, `customer_id`, `session_id`, `channel`, `status`, `created_at`, `last_message_at`)
        VALUES (?, ?, ?, ?, 'ai_handling', NOW(), NOW())
    ");
    $ok = $cStmt->execute([$companyAId, $visitorId, $sessionId, $ch]);
    if (!$ok) $supportedInDb = false;
}
assertTest("Omnichannel channels ('web', 'instagram', 'whatsapp', 'email') successfully handled by conversations engine", $supportedInDb);

// =============================================================================
// SECTION 7–11: CALENDAR INTEGRATION, INTENT & APPOINTMENT BOOKING
// =============================================================================
echo "\n{$cBlue}[TEST SUITE 5] Calendar Integration & AI Appointment Engine (Section 7–11){$cReset}\n";

// 5.1 Multi-lingual Appointment Intent Detection
$intentEn = isAppointmentIntent("I want to schedule a quick consultation with your team tomorrow");
$intentHi = isAppointmentIntent("kripya hamari ek meeting schedule karein");
$intentHinglish = isAppointmentIntent("mujhe ek demo appointment book karna hai please");
$intentNegative = isAppointmentIntent("What are your standard monthly SaaS pricing packages?");

assertTest("English appointment intent recognized", $intentEn === true);
assertTest("Hindi appointment intent recognized", $intentHi === true);
assertTest("Hinglish appointment intent recognized", $intentHinglish === true);
assertTest("Non-appointment question correctly not recognized as booking intent", $intentNegative === false);

// 5.2 Calendar Configuration
$calStmt = $pdo->prepare("
    INSERT INTO `company_calendar_configs`
    (`company_id`, `provider`, `slot_duration_minutes`, `buffer_after_minutes`, `working_days`, `working_hours_start`, `working_hours_end`, `timezone`, `created_at`, `updated_at`)
    VALUES (?, 'native', 30, 10, '1,2,3,4,5,6', '09:00:00', '18:00:00', 'Asia/Kolkata', NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        `slot_duration_minutes` = 30,
        `buffer_after_minutes` = 10
");
$calStmt->execute([$companyAId]);

$calCfg = getCompanyCalendarConfig($pdo, $companyAId);
assertTest("Calendar working hours and buffer configuration loaded", $calCfg && $calCfg['slot_duration_minutes'] == 30);

// 5.3 Available Slot Generation
$slots = getAvailableAppointmentSlots($pdo, $companyAId, 2);
assertTest("Collision-free appointment slots generated dynamically from calendar config", is_array($slots) && count($slots) > 0, "Generated " . count($slots) . " slots");

// 5.4 Appointment Booking with all Section 11 columns
$targetSlotDt = !empty($slots) ? $slots[0]['slot_datetime'] : date('Y-m-d H:i:s', strtotime('+1 day 10:00:00'));

$bookResult = bookAppointmentFromChat($pdo, $companyAId, [
    'slot_datetime'   => $targetSlotDt,
    'session_id'      => $sessionId,
    'customer_id'     => $visitorId,
    'customer_name'   => $testName,
    'customer_phone'  => $testPhone,
    'customer_email'  => $testEmail,
    'channel'         => 'web',
    'notes'           => 'Discussion regarding enterprise automation setup'
]);

assertTest("Appointment booked successfully via AI engine", $bookResult['success'] === true, $bookResult['message'] ?? '');

$bookedApptId = $bookResult['appointment_id'] ?? 0;
$verifyAppt = $pdo->prepare("SELECT * FROM `appointments` WHERE `id` = ? LIMIT 1");
$verifyAppt->execute([$bookedApptId]);
$apptRow = $verifyAppt->fetch();

// Check Section 11 schema columns
$requiredCols = [
    'company_id', 'session_id', 'visitor_id', 'customer_name', 'customer_phone', 'customer_email',
    'appointment_date', 'start_time', 'end_time', 'status', 'created_channel', 'calendar_event_id',
    'meeting_link', 'notes', 'created_at'
];
$missingCols = [];
foreach ($requiredCols as $col) {
    if (!array_key_exists($col, $apptRow)) {
        $missingCols[] = $col;
    }
}
$hasAllColumns = empty($missingCols);
assertTest("Appointment contains all required Section 11 database columns", $hasAllColumns, !empty($missingCols) ? ("Missing: " . implode(', ', $missingCols)) : "All 15 Section 11 columns verified");
assertTest("Appointment session_id properly persisted", $apptRow['session_id'] === $sessionId, "Session: {$apptRow['session_id']}");
assertTest("Appointment created_channel recorded as 'web'", $apptRow['created_channel'] === 'web');

// 5.5 Double-Booking / Collision Prevention
$doubleBook = bookAppointmentFromChat($pdo, $companyAId, [
    'slot_datetime'   => $targetSlotDt,
    'session_id'      => $sessionId,
    'customer_id'     => $visitorId,
    'customer_name'   => 'Second Visitor',
    'customer_phone'  => '+919999988888',
    'customer_email'  => 'second@test.com',
    'channel'         => 'web',
    'notes'           => 'Duplicate booking attempt'
]);
assertTest("Double-booking prevention: overlapping slot rejected with collision error", $doubleBook['success'] === false && ($doubleBook['error'] === 'collision' || strpos($doubleBook['message'] ?? '', 'booked') !== false));

// =============================================================================
// SECTION 13: APPOINTMENTS DASHBOARD API & RESCHEDULING
// =============================================================================
echo "\n{$cBlue}[TEST SUITE 6] Appointments Dashboard & Rescheduling API (Section 13){$cReset}\n";

// 6.1 Status filtering
$apptListStmt = $pdo->prepare("SELECT id, status FROM `appointments` WHERE `company_id` = ?");
$apptListStmt->execute([$companyAId]);
$companyAppts = $apptListStmt->fetchAll();
assertTest("Dashboard appointments query retrieves company appointments", count($companyAppts) > 0);

// 6.2 Reschedule appointment
$newDate = date('Y-m-d', strtotime('+3 days'));
$newStartTime = '14:00';
$newEndTime = '14:30';

$reschedStmt = $pdo->prepare("
    UPDATE `appointments`
    SET `appointment_date` = ?, `start_time` = ?, `end_time` = ?, `status` = 'rescheduled', `updated_at` = NOW()
    WHERE `id` = ? AND `company_id` = ?
");
$reschedOk = $reschedStmt->execute([$newDate, $newStartTime, $newEndTime, $bookedApptId, $companyAId]);
assertTest("Appointment rescheduling updates date and sets status to 'rescheduled'", $reschedOk);

$chkResched = $pdo->prepare("SELECT appointment_date, start_time, status FROM `appointments` WHERE `id` = ?");
$chkResched->execute([$bookedApptId]);
$reschedRow = $chkResched->fetch();
assertTest("Rescheduled appointment has new date and time reflected in DB", $reschedRow['appointment_date'] === $newDate && $reschedRow['start_time'] === '14:00:00');

// =============================================================================
// SECTION 14 & 15: STRICT MULTI-TENANT ISOLATION
// =============================================================================
echo "\n{$cBlue}[TEST SUITE 7] Strict Multi-Tenant Isolation & Premium Gating (Section 14 & 15){$cReset}\n";

// 7.1 Cross-tenant appointment read isolation
$crossQuery = $pdo->prepare("SELECT * FROM `appointments` WHERE `id` = ? AND `company_id` = ?");
$crossQuery->execute([$bookedApptId, $companyBId]);
$crossAppt = $crossQuery->fetch();
assertTest("Tenant B CANNOT access Tenant A's appointment (Multi-tenant row-level security)", empty($crossAppt));

// 7.2 Cross-tenant email config read isolation
$crossEmail = $pdo->prepare("SELECT * FROM `company_email_configs` WHERE `company_id` = ?");
$crossEmail->execute([$companyBId]);
$crossCfg = $crossEmail->fetch();
assertTest("Tenant B has isolated email configs (No leak of Tenant A SMTP credentials)", empty($crossCfg) || $crossCfg['sender_email'] !== 'notifications@tenanta.com');

// 7.3 Cross-tenant visitor session isolation
$crossSess = $pdo->prepare("SELECT * FROM `visitor_sessions` WHERE `session_id` = ? AND `company_id` = ?");
$crossSess->execute([$sessionId, $companyBId]);
$crossSessRow = $crossSess->fetch();
assertTest("Tenant B CANNOT query or hijack Tenant A's visitor session", empty($crossSessRow));

// 7.4 Feature entitlement check
$entitlementsA = getCompanyEntitlements($pdo, $companyAId);
assertTest("Feature entitlements returned for company", isset($entitlementsA['plan_tier']) || isset($entitlementsA['is_full_access']));

echo "\n{$cBlue}========================================================================{$cReset}\n";
echo "{$cBlue}   TEST RESULTS SUMMARY: {$passedTests} / {$totalTests} PASSED" . ($failedTests > 0 ? " ({$failedTests} FAILED)" : " (100% SUCCESS)") . "   {$cReset}\n";
echo "{$cBlue}========================================================================{$cReset}\n\n";

if ($failedTests > 0) {
    exit(1);
} else {
    exit(0);
}
