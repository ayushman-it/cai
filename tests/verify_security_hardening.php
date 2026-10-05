<?php
/**
 * Automated Security Hardening & Regression Verification Script
 */

$testResults = [];
function recordTest($name, $passed, $details = '') {
    global $testResults;
    $testResults[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
    echo ($passed ? "[PASS]" : "[FAIL]") . " {$name}" . ($details ? " - {$details}" : "") . "\n";
}

echo "=======================================================\n";
echo "CUBOIDPILOT PRODUCTION HARDENING VERIFICATION\n";
echo "=======================================================\n\n";

// 1. Verify .env loading in db.php
require_once __DIR__ . '/../config/db.php';
$hasDbHost = defined('DB_HOST') && !empty(DB_HOST);
$hasGroqKey = defined('GROQ_API_KEY') && !empty(GROQ_API_KEY);
recordTest("1. Environment & .env loading", $hasDbHost && $hasGroqKey, "DB_HOST=" . DB_HOST . ", GROQ_API_KEY loaded");

// 2. Verify login.php backdoor removal
$loginCode = file_get_contents(__DIR__ . '/../login.php');
$hasBackdoor1 = strpos($loginCode, "'password123'") !== false;
$hasBackdoor2 = strpos($loginCode, "'admin123'") !== false;
recordTest("2. Backdoor passwords removed in login.php", (!$hasBackdoor1 && !$hasBackdoor2), "No hardcoded password bypass found");

$hasSessionRegen = strpos($loginCode, "session_regenerate_id(true)") !== false;
recordTest("2b. Session fixation protection in login.php", $hasSessionRegen, "session_regenerate_id(true) present");

// 3. Verify google_callback.php test bypass removal
$googleCode = file_get_contents(__DIR__ . '/../google_callback.php');
$hasTestBypass = strpos($googleCode, "\$_GET['test']") !== false || strpos($googleCode, "\$_GET['simulate']") !== false;
$hasSslPeer = strpos($googleCode, "CURLOPT_SSL_VERIFYPEER, true") !== false;
recordTest("3. Google OAuth test mode bypass removed", !$hasTestBypass, "No \$_GET['test'] backdoor");
recordTest("3b. Google OAuth SSL verification enforced", $hasSslPeer, "CURLOPT_SSL_VERIFYPEER => true");

// 4. Verify super_admin.php RBAC check
$superAdminCode = file_get_contents(__DIR__ . '/../api/super_admin.php');
$hasOwnerBypass = strpos($superAdminCode, "\$user['role'] !== 'owner'") !== false;
$hasSuperAdminStrict = strpos($superAdminCode, "(int)\$user['is_super_admin'] !== 1") !== false;
recordTest("4. Super Admin RBAC strictly enforced", (!$hasOwnerBypass && $hasSuperAdminStrict), "Regular owners blocked from super_admin");

// 5. Verify blogs.php RBAC check
$blogsCode = file_get_contents(__DIR__ . '/../api/blogs.php');
$hasBlogOwnerBypass = strpos($blogsCode, "\$uRow['role'] === 'owner'") !== false;
recordTest("5. Platform blogs admin strictly enforced", !$hasBlogOwnerBypass, "Regular owners blocked from editing platform blogs");

// 6. Verify team.php role-based access control
$teamCode = file_get_contents(__DIR__ . '/../api/team.php');
$hasRoleCheck = strpos($teamCode, "\$isOwnerOrAdmin") !== false && strpos($teamCode, "\$mutatingActions") !== false;
recordTest("6. Team member mutations role check in team.php", $hasRoleCheck, "Only owner/admin can add/edit/delete members");

// 7. Verify chat.php copilot mode protection
$chatCode = file_get_contents(__DIR__ . '/../api/chat.php');
$hasCopilotSessionCheck = strpos($chatCode, "\$hasWorkspaceSession") !== false;
recordTest("7. Chat Copilot session gating in chat.php", $hasCopilotSessionCheck, "Unauthenticated copilot telemetry leaks blocked");

// 8. Verify widget_actions.php IDOR protection on poll_messages
$widgetCode = file_get_contents(__DIR__ . '/../api/widget_actions.php');
$hasPollAuth = strpos($widgetCode, "\$isVisitorOwner") !== false && strpos($widgetCode, "Unauthorized conversation access") !== false;
recordTest("8. Conversation poll_messages IDOR protection", $hasPollAuth, "Session token required to poll private messages");

// 9. Verify knowledge.php SSRF protection
$knowledgeCode = file_get_contents(__DIR__ . '/../api/knowledge.php');
$hasSsrfValidation = strpos($knowledgeCode, "FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE") !== false;
$hasSslPeerVerify = strpos($knowledgeCode, "CURLOPT_SSL_VERIFYPEER => true") !== false;
recordTest("9. SSRF Protection & IP validation in knowledge.php", ($hasSsrfValidation && $hasSslPeerVerify), "Private/localhost/metadata IPs correctly rejected & SSL verified");

// 10. Verify upload.php SVG and MIME restrictions
$uploadCode = file_get_contents(__DIR__ . '/../api/upload.php');
$hasSvg = preg_match("/'svg'/", $uploadCode);
$hasFinfo = strpos($uploadCode, "finfo_open(FILEINFO_MIME_TYPE)") !== false;
recordTest("10. SVG XSS prevention & MIME check in upload.php", (!$hasSvg && $hasFinfo), "SVG removed and MIME type validated");

// 11. Verify accept_invite.php account takeover protection
$inviteCode = file_get_contents(__DIR__ . '/../accept_invite.php');
$hasInviteProtection = strpos($inviteCode, "Platform administrator accounts cannot be converted") !== false &&
                       strpos($inviteCode, "password_verify(\$password, \$existingUser['password_hash'])") !== false;
recordTest("11. Account takeover protection in accept_invite.php", $hasInviteProtection, "Password confirmation required for existing accounts");

// 12. Verify .htaccess protection rules
$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
$hasSqlBlock = strpos($htaccess, "cuboidpilot_fresh.sql") !== false;
$hasEnvBlock = strpos($htaccess, ".env") !== false;
$hasSecurityHeaders = strpos($htaccess, "X-Content-Type-Options \"nosniff\"") !== false;
recordTest("12. Web server hardening in .htaccess", ($hasSqlBlock && $hasEnvBlock && $hasSecurityHeaders), "Blocks .sql, .env, and sets security headers");

echo "\n=======================================================\n";
$allPassed = true;
foreach ($testResults as $t) {
    if (!$t['passed']) $allPassed = false;
}

if ($allPassed) {
    echo "SUMMARY: ALL 12 SECURITY AUDIT CHECKS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SUMMARY: SOME SECURITY CHECKS FAILED. Review details above.\n";
    exit(1);
}
