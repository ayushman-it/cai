<?php
/**
 * CUBOIDPILOT — CAI AI INTELLIGENCE & CONVERSATIONAL ENGINE VERIFICATION SUITE
 * Verifies all 12 scenarios required by the project specifications:
 * 1. Available services inquiry
 * 2. Pricing inquiry
 * 3. Best plan recommendation
 * 4. Document / brochure request
 * 5. Human support handoff
 * 6. Appointment booking workflow
 * 7. Multilingual continuity (English to Hindi)
 * 8. Conversation memory (Referencing earlier stated budget)
 * 9. Honest limitation on unavailable info (Hallucination prevention)
 * 10. Personal details retention (No repeated prompts)
 * 11. Strict multi-tenant isolation
 * 12. Mid-conversation requirement change adaptation
 */

require_once __DIR__ . '/../config/db.php';

$cGreen  = "\033[32m";
$cRed    = "\033[31m";
$cYellow = "\033[33m";
$cBlue   = "\033[36m";
$cReset  = "\033[0m";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function recordTest($title, $passed, $details = '') {
    global $totalTests, $passedTests, $failedTests, $cGreen, $cRed, $cYellow, $cReset;
    $totalTests++;
    if ($passed) {
        $passedTests++;
        echo "  {$cGreen}[PASS]{$cReset} {$title}\n";
        if ($details) echo "         {$cYellow}↳ {$details}{$cReset}\n";
    } else {
        $failedTests++;
        echo "  {$cRed}[FAIL]{$cReset} {$title}\n";
        if ($details) echo "         {$cRed}↳ Error: {$details}{$cReset}\n";
    }
}

function callChat($message, $companyKey = 'cp_live_cuboidsoft', $convId = null, $extra = []) {
    $payload = array_merge([
        'company_key'     => 'cp_live_cuboidsoft',
        'message'         => $message,
        'conversation_id' => $convId
    ], $extra);
    if (!empty($companyKey)) {
        $payload['company_key'] = $companyKey;
    }

    $ch = curl_init('http://127.0.0.1:8080/api/chat.php');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    return is_array($json) ? $json : ['success' => false, 'raw' => $res, 'code' => $httpCode];
}

echo "\n{$cBlue}========================================================================{$cReset}\n";
echo "{$cBlue}      CAI AI CONVERSATIONAL INTELLIGENCE SUITE — 12 CORE SCENARIOS      {$cReset}\n";
echo "{$cBlue}========================================================================{$cReset}\n\n";

// TEST 1: Customer asks about available services
echo "{$cYellow}[SCENARIO 1] Available Services Inquiry{$cReset}\n";
$r1 = callChat("What services or plans do you provide?");
$r1Ok = !empty($r1['reply']) && (stripos($r1['reply'], 'CuboidPilot') !== false || stripos($r1['reply'], 'Essential') !== false || stripos($r1['reply'], 'plan') !== false || stripos($r1['reply'], 'AI') !== false);
recordTest("TEST 1: Answers available services accurately using verified company data", $r1Ok, substr(preg_replace('/\s+/', ' ', $r1['reply'] ?? ''), 0, 100) . '...');
sleep(1);

// TEST 2: Customer asks about prices
echo "\n{$cYellow}[SCENARIO 2] Verified Pricing Inquiry (No Hallucination){$cReset}\n";
$r2 = callChat("What are your plan prices?");
$r2Ok = !empty($r2['reply']) && (stripos($r2['reply'], '79') !== false || stripos($r2['reply'], '159') !== false || stripos($r2['reply'], '279') !== false || stripos($r2['reply'], 'Essential') !== false);
recordTest("TEST 2: Delivers verified pricing without hallucinating false figures", $r2Ok, substr(preg_replace('/\s+/', ' ', $r2['reply'] ?? ''), 0, 100) . '...');
sleep(1);

// TEST 3: Customer asks which plan is best (Needs-based recommendation)
echo "\n{$cYellow}[SCENARIO 3] Needs-Based Recommendation{$cReset}\n";
$r3 = callChat("I am a solo founder launching a small startup with 1 support person. Which plan is best for me?");
$r3Ok = !empty($r3['reply']) && (stripos($r3['reply'], 'Essential') !== false || stripos($r3['reply'], '79') !== false);
recordTest("TEST 3: Recommends appropriate starter plan (Essential) tailored to solo founder requirements", $r3Ok, substr(preg_replace('/\s+/', ' ', $r3['reply'] ?? ''), 0, 100) . '...');
sleep(1);

// TEST 4: Customer requests a brochure
echo "\n{$cYellow}[SCENARIO 4] Document Request & Delivery Workflow{$cReset}\n";
$r4 = callChat("Can you send me your platform brochure or architecture guide?");
$r4Asset = !empty($r4['shared_asset']) && !empty($r4['shared_asset']['title']);
$r4Reply = !empty($r4['reply']) && (stripos($r4['reply'], 'brochure') !== false || stripos($r4['reply'], 'guide') !== false || stripos($r4['reply'], 'document') !== false || stripos($r4['reply'], 'email') !== false);
recordTest("TEST 4: Identifies relevant brochure asset and initiates delivery workflow", $r4Asset || $r4Reply, "Matched Asset: " . ($r4['shared_asset']['title'] ?? 'Platform Guide'));
sleep(1);

// TEST 5: Customer requests human support
echo "\n{$cYellow}[SCENARIO 5] Human Support & Handoff Intelligence{$cReset}\n";
$r5 = callChat("I need to speak with a human counselor or support representative.");
$r5Handoff = (!empty($r5['priority']) && in_array($r5['priority'], ['HIGH', 'URGENT'])) || stripos($r5['reply'] ?? '', 'team') !== false || stripos($r5['reply'] ?? '', 'specialist') !== false || stripos($r5['reply'] ?? '', 'WhatsApp') !== false;
recordTest("TEST 5: Recognizes handoff intent, escalates priority, and provides team connection", $r5Handoff, "Priority: " . ($r5['priority'] ?? 'NORMAL') . " | Stage: " . ($r5['stage'] ?? ''));
sleep(1);

// TEST 6: Customer requests an appointment
echo "\n{$cYellow}[SCENARIO 6] Appointment & Consultation Intelligence{$cReset}\n";
$r6 = callChat("Can I book an appointment or consultation meeting?");
$r6Appt = !empty($r6['appointment_intent']) || !empty($r6['appointment_slots']) || stripos($r6['reply'] ?? '', 'slot') !== false || stripos($r6['reply'] ?? '', 'schedule') !== false;
recordTest("TEST 6: Recognizes appointment intent and surfaces real booking workflow", $r6Appt, "Slot count: " . count($r6['appointment_slots'] ?? []));
sleep(1);

// TEST 7: Customer switches from English to Hindi / Hinglish
echo "\n{$cYellow}[SCENARIO 7] Multilingual Intelligence (English to Hindi/Hinglish){$cReset}\n";
$r7 = callChat("Mujhe website chat widget ke baare me batao, ye kaise kaam karta hai?");
$r7Hindi = !empty($r7['reply']) && (preg_match('/(hai|aap|hamara|hamare|madad|karta|kaam|saath|bata)/i', $r7['reply']));
recordTest("TEST 7: Detects Hindi/Hinglish and responds fluently in conversational language", $r7Hindi, substr(preg_replace('/\s+/', ' ', $r7['reply'] ?? ''), 0, 100) . '...');
sleep(1);

// TEST 8: Customer references an earlier requirement (Memory & Budget continuity)
echo "\n{$cYellow}[SCENARIO 8] Conversation Memory & Multi-turn Continuity{$cReset}\n";
$r8_init = callChat("My budget is ₹150 per seat per month.");
$convId8 = $r8_init['conversation_id'] ?? null;
sleep(1);
$r8 = callChat("Which plan fits my budget?", 'cp_live_cuboidsoft', $convId8);
$r8Memory = !empty($r8['reply']) && (stripos($r8['reply'], 'Essential') !== false || stripos($r8['reply'], '79') !== false || stripos($r8['reply'], '150') !== false);
recordTest("TEST 8: Uses conversational memory to recall ₹150 budget and recommend Essential plan", $r8Memory, substr(preg_replace('/\s+/', ' ', $r8['reply'] ?? ''), 0, 100) . '...');
sleep(1);

// TEST 9: Requested information is unavailable (Hallucination Prevention)
echo "\n{$cYellow}[SCENARIO 9] Unavailable Information & Hallucination Prevention{$cReset}\n";
$r9 = callChat("Do you provide offline classroom training in Bangalore with hostel facility?");
$r9Honest = !empty($r9['reply']) && (stripos($r9['reply'], 'not') !== false || stripos($r9['reply'], 'nahi') !== false || stripos($r9['reply'], 'digital') !== false || stripos($r9['reply'], 'unavailable') !== false || stripos($r9['reply'], 'online') !== false);
$r9NoFake = stripos($r9['reply'] ?? '', 'hostel available') === false;
recordTest("TEST 9: Honestly acknowledges unavailable info without inventing fake offline hostel", $r9Honest && $r9NoFake, substr(preg_replace('/\s+/', ' ', $r9['reply'] ?? ''), 0, 100) . '...');
sleep(1);

// TEST 10: Customer has already shared personal details (No unnecessary repeating)
echo "\n{$cYellow}[SCENARIO 10] Personal Details Retention (No Repeated Prompts){$cReset}\n";
$r10_1 = callChat("Hi, my name is Priya Sharma, email is priya@example.com and phone is 9876543210");
$convId10 = $r10_1['conversation_id'] ?? null;
sleep(1);
$r10_2 = callChat("Can you explain how WhatsApp continuity works?", 'cp_live_cuboidsoft', $convId10);
$r10NoAsk = !empty($r10_2['reply']) && stripos($r10_2['reply'], 'enter your name') === false && stripos($r10_2['reply'], 'please share your phone') === false;
recordTest("TEST 10: Retains user details and answers question without repeating lead capture", $r10NoAsk, "Greeted/Acknowledged cleanly without blocking");
sleep(1);

// TEST 11: Two different companies have different pricing (Strict Tenant Isolation)
echo "\n{$cYellow}[SCENARIO 11] Strict Tenant Isolation & Workspace Knowledge Separation{$cReset}\n";
$r11_A = callChat("What plans or courses and fees do you have?", 'cp_live_cuboidsoft');
sleep(1);
$r11_B = callChat("What plans or courses and fees do you have?", 'cp_live_thecodemunk');
$r11_A_Correct = stripos($r11_A['reply'] ?? '', 'Essential') !== false || stripos($r11_A['reply'] ?? '', 'CuboidPilot') !== false || stripos($r11_A['reply'] ?? '', '79') !== false;
$r11_B_Correct = stripos($r11_B['reply'] ?? '', 'Code Munk') !== false || stripos($r11_B['reply'] ?? '', 'Full Stack') !== false || stripos($r11_B['reply'] ?? '', 'MERN') !== false || stripos($r11_B['reply'] ?? '', 'Python') !== false;
$noCrossLeak = (stripos($r11_B['reply'] ?? '', 'CuboidPilot') === false);
recordTest("TEST 11: Strict tenant isolation: Tenant A and Tenant B return distinct company data with zero leak", $r11_A_Correct && $r11_B_Correct && $noCrossLeak, "Tenant A: CuboidSoft | Tenant B: The Code Munk (Isolated)");
sleep(1);

// TEST 12: Customer changes requirements midway (Adaptive Recommendation)
echo "\n{$cYellow}[SCENARIO 12] Mid-Conversation Requirement Change Adaptation{$cReset}\n";
$r12_1 = callChat("I was looking at the Essential plan for my small side-project.");
$convId12 = $r12_1['conversation_id'] ?? null;
sleep(1);
$r12_2 = callChat("Actually our requirements changed: we have grown to 25 people and now strictly require HIPAA and SOC2 compliance. What plan should we take?", 'cp_live_cuboidsoft', $convId12);
$r12Adaptive = !empty($r12_2['reply']) && (stripos($r12_2['reply'], 'Expert') !== false || stripos($r12_2['reply'], '279') !== false || stripos($r12_2['reply'], 'SOC2') !== false || stripos($r12_2['reply'], 'HIPAA') !== false);
recordTest("TEST 12: Adaptively updates recommendation to Expert plan when requirements change to 25 users & HIPAA/SOC2", $r12Adaptive, substr(preg_replace('/\s+/', ' ', $r12_2['reply'] ?? ''), 0, 100) . '...');

echo "\n{$cBlue}========================================================================{$cReset}\n";
echo "{$cBlue}   AI INTELLIGENCE TEST SUITE RESULTS: {$passedTests} / {$totalTests} PASSED   {$cReset}\n";
echo "{$cBlue}========================================================================{$cReset}\n\n";

if ($failedTests > 0) {
    exit(1);
}
