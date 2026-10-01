<?php
/**
 * CUBOIDPILOT — ONBOARDING ENTRY POINT
 * Redirects to the appropriate onboarding step.
 */
require_once __DIR__ . '/onboarding_helper.php';
$ctx = getOnboardingContext();
$company = $ctx['company'];

if (!empty($company['onboarding_completed'])) {
    header('Location: ../app/overview.html');
    exit;
}

header('Location: business.php');
exit;
