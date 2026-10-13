<?php
/**
 * Cai AI usage analytics and usage-based billing API.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key, X-Widget-Token");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_usage_service.php';
require_once __DIR__ . '/../includes/widget_security.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);
$data = array_merge($_GET, $_POST, is_array($jsonData) ? $jsonData : []);
$action = trim($data['action'] ?? 'summary');

function aiUsageRespond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function aiUsageResolveCompanyByKey(PDO $pdo, string $companyKey): ?array {
    $companyKey = trim($companyKey);
    if ($companyKey === 'cp_live_cuboidpilot' || $companyKey === 'cuboidpilot') {
        $companyKey = 'cp_live_cuboidsoft';
    }
    if ($companyKey === '' || $companyKey === 'default') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM `companies`
        WHERE `company_key` = ?
           OR `slug` = ?
           OR (slug = 'cuboidsoft' AND ? IN ('cp_live_cuboidsoft', 'cp_live_cuboidpilot', 'cuboidsoft', 'cuboidpilot'))
        LIMIT 1
    ");
    $stmt->execute([$companyKey, $companyKey, $companyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function aiUsageRequireCompanySession(): int {
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        aiUsageRespond(['success' => false, 'error' => 'Authentication required'], 401);
    }
    return (int)$_SESSION['company_id'];
}

function aiUsageRequireSuperAdmin(PDO $pdo): void {
    if (empty($_SESSION['user_id'])) {
        aiUsageRespond(['success' => false, 'error' => 'Authentication required'], 401);
    }
    $stmt = $pdo->prepare("SELECT is_super_admin FROM `users` WHERE `id` = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    if ((int)($stmt->fetchColumn() ?: 0) !== 1) {
        aiUsageRespond(['success' => false, 'error' => 'Super admin authorization required'], 403);
    }
}

function aiUsageCanAccessCompany(PDO $pdo, int $companyId): bool {
    if ($companyId <= 0 || empty($_SESSION['user_id'])) {
        return false;
    }
    if (!empty($_SESSION['company_id']) && (int)$_SESSION['company_id'] === $companyId) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT is_super_admin FROM `users` WHERE `id` = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return (int)($stmt->fetchColumn() ?: 0) === 1;
}

function aiUsagePdfText(string $text): string {
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function aiUsageBuildInvoicePdf(array $lines): string {
    $content = "BT\n/F1 11 Tf\n14 TL\n50 790 Td\n";
    foreach ($lines as $idx => $line) {
        if ($idx > 0) {
            $content .= "T*\n";
        }
        $content .= '(' . aiUsagePdfText($line) . ") Tj\n";
    }
    $content .= "ET\n";

    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
        "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream\nendobj\n"
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function aiUsageDownloadInvoice(PDO $pdo, int $companyId, string $month): void {
    [$monthKey, $start, $end] = aiUsageMonthBounds($month);
    if (!aiUsageCanAccessCompany($pdo, $companyId)) {
        aiUsageRespond(['success' => false, 'error' => 'Not authorized for this invoice.'], 403);
    }

    $companyStmt = $pdo->prepare("SELECT id, name, email, slug, plan_tier FROM `companies` WHERE `id` = ? LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        aiUsageRespond(['success' => false, 'error' => 'Company not found.'], 404);
    }

    $subscriptionAmount = aiUsageGetSubscriptionAmount($pdo, $companyId);
    $aiAmount = aiUsageScalar($pdo, "SELECT COALESCE(SUM(`amount_inr`), 0) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
    $billable = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
    $settings = aiUsageGetSettings($pdo, $companyId);
    $total = round($subscriptionAmount + $aiAmount, 2);

    $invoiceStmt = $pdo->prepare("SELECT status, issued_at, paid_at FROM `ai_billing_invoices` WHERE `company_id` = ? AND `invoice_month` = ? LIMIT 1");
    $invoiceStmt->execute([$companyId, $monthKey]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $status = $invoice['status'] ?? ($monthKey === date('Y-m') ? 'draft' : 'issued');

    $lines = [
        'Cai AI Usage Invoice',
        'Invoice: CAI-' . $monthKey . '-' . str_pad((string)$companyId, 4, '0', STR_PAD_LEFT),
        'Month: ' . $monthKey,
        'Status: ' . strtoupper($status),
        'Generated: ' . date('Y-m-d H:i:s'),
        '',
        'Bill To: ' . ($company['name'] ?? 'Workspace'),
        'Email: ' . ($company['email'] ?? ''),
        'Plan: ' . ($company['plan_tier'] ?? ''),
        '',
        'Subscription / seat fees: INR ' . number_format((float)$subscriptionAmount, 2),
        'Billable AI resolutions: ' . (int)$billable,
        'Price per confirmed resolution: INR ' . number_format((float)($settings['outcome_price_inr'] ?? 1), 2),
        'AI usage charges: INR ' . number_format((float)$aiAmount, 2),
        'Total payable: INR ' . number_format($total, 2),
        '',
        'Billing rule: AI usage is charged only for visitor-confirmed resolved conversations.',
        'Unresolved, abandoned, or human-handoff conversations are not counted as billable AI outcomes.',
        '',
        'CuboidSoft Technologies',
        'support@cuboidsoft.in'
    ];

    $pdf = aiUsageBuildInvoicePdf($lines);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="CAI-' . $monthKey . '-' . $companyId . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function aiUsageDateRange(array $data): array {
    $from = trim($data['from'] ?? '');
    $to = trim($data['to'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }
    if (strtotime($from) > strtotime($to)) {
        [$from, $to] = [$to, $from];
    }

    $toExclusive = date('Y-m-d', strtotime($to . ' +1 day'));
    return [$from, $to, $toExclusive];
}

function aiUsageScalar(PDO $pdo, string $sql, array $params = []): float {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetchColumn() ?: 0);
}

function aiUsageMonthBounds(string $month): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $start = $month . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    return [$month, $start, $end];
}

function aiUsageCompanySummary(PDO $pdo, int $companyId, string $from, string $toExclusive): array {
    $totalConversations = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `conversations` WHERE `company_id` = ? AND `created_at` >= ? AND `created_at` < ?", [$companyId, $from, $toExclusive]);
    $aiMessages = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `messages` WHERE `company_id` = ? AND `sender_type` = 'ai' AND `created_at` >= ? AND `created_at` < ?", [$companyId, $from, $toExclusive]);
    $visitorMessages = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `messages` WHERE `company_id` = ? AND `sender_type` = 'visitor' AND `created_at` >= ? AND `created_at` < ?", [$companyId, $from, $toExclusive]);
    $resolved = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $from, $toExclusive]);
    $unresolved = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `status` = 'unresolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $from, $toExclusive]);
    $humanHandoffs = aiUsageScalar($pdo, "SELECT COUNT(DISTINCT `conversation_id`) FROM `ai_usage_events` WHERE `company_id` = ? AND `event_type` = 'human_handoff' AND `occurred_at` >= ? AND `occurred_at` < ?", [$companyId, $from, $toExclusive]);
    $billableOutcomes = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `status` = 'resolved' AND `billable` = 1 AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $from, $toExclusive]);
    $aiCharges = aiUsageScalar($pdo, "SELECT COALESCE(SUM(`amount_inr`), 0) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `status` = 'resolved' AND `billable` = 1 AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $from, $toExclusive]);
    $openUnconfirmed = max(0, $totalConversations - $resolved - $unresolved - $humanHandoffs);
    $resolutionRate = $totalConversations > 0 ? round(($resolved / $totalConversations) * 100, 1) : 0.0;

    return [
        'total_conversations' => (int)$totalConversations,
        'ai_messages' => (int)$aiMessages,
        'visitor_messages' => (int)$visitorMessages,
        'ai_interactions' => (int)($aiMessages + $visitorMessages),
        'resolved_conversations' => (int)$resolved,
        'unresolved_conversations' => (int)$unresolved,
        'human_handoffs' => (int)$humanHandoffs,
        'open_unconfirmed' => (int)$openUnconfirmed,
        'resolution_rate' => $resolutionRate,
        'billable_outcomes' => (int)$billableOutcomes,
        'ai_charges_inr' => round($aiCharges, 2)
    ];
}

function aiUsageDailySeries(PDO $pdo, int $companyId, string $from, string $to): array {
    $start = new DateTime($from);
    $end = new DateTime($to);
    $days = [];
    $maxDays = 62;
    for ($i = 0; $i < $maxDays && $start <= $end; $i++, $start->modify('+1 day')) {
        $key = $start->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'conversations' => 0,
            'ai_messages' => 0,
            'resolved' => 0,
            'unresolved' => 0,
            'billable_outcomes' => 0,
            'charges_inr' => 0.0
        ];
    }

    $toExclusive = date('Y-m-d', strtotime($to . ' +1 day'));
    $queries = [
        ['conversations', "SELECT DATE(`created_at`) d, COUNT(*) v FROM `conversations` WHERE `company_id` = ? AND `created_at` >= ? AND `created_at` < ? GROUP BY DATE(`created_at`)"],
        ['ai_messages', "SELECT DATE(`created_at`) d, COUNT(*) v FROM `messages` WHERE `company_id` = ? AND `sender_type` = 'ai' AND `created_at` >= ? AND `created_at` < ? GROUP BY DATE(`created_at`)"],
        ['resolved', "SELECT DATE(`confirmed_at`) d, COUNT(*) v FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ? GROUP BY DATE(`confirmed_at`)"],
        ['unresolved', "SELECT DATE(`confirmed_at`) d, COUNT(*) v FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `status` = 'unresolved' AND `confirmed_at` >= ? AND `confirmed_at` < ? GROUP BY DATE(`confirmed_at`)"],
        ['billable_outcomes', "SELECT DATE(`confirmed_at`) d, COUNT(*) v FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ? GROUP BY DATE(`confirmed_at`)"]
    ];

    foreach ($queries as [$field, $sql]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$companyId, $from, $toExclusive]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($days[$row['d']])) {
                $days[$row['d']][$field] = (int)$row['v'];
            }
        }
    }

    $chargeStmt = $pdo->prepare("
        SELECT DATE(`confirmed_at`) d, COALESCE(SUM(`amount_inr`), 0) v
        FROM `ai_resolution_ledger`
        WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?
        GROUP BY DATE(`confirmed_at`)
    ");
    $chargeStmt->execute([$companyId, $from, $toExclusive]);
    foreach ($chargeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($days[$row['d']])) {
            $days[$row['d']]['charges_inr'] = round((float)$row['v'], 2);
        }
    }

    return array_values($days);
}

function aiUsageInvoices(PDO $pdo, int $companyId, int $months = 6): array {
    $subscriptionAmount = aiUsageGetSubscriptionAmount($pdo, $companyId);
    $items = [];
    for ($i = 0; $i < $months; $i++) {
        $month = date('Y-m', strtotime("first day of -{$i} months"));
        [$monthKey, $start, $end] = aiUsageMonthBounds($month);
        $aiAmount = aiUsageScalar($pdo, "SELECT COALESCE(SUM(`amount_inr`), 0) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
        $billable = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
        $invoiceStmt = $pdo->prepare("SELECT id, status, issued_at, paid_at FROM `ai_billing_invoices` WHERE `company_id` = ? AND `invoice_month` = ? LIMIT 1");
        $invoiceStmt->execute([$companyId, $monthKey]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = $invoice['status'] ?? ($monthKey === date('Y-m') ? 'draft' : 'issued');

        $items[] = [
            'invoice_id' => !empty($invoice['id']) ? (int)$invoice['id'] : null,
            'invoice_month' => $monthKey,
            'billable_outcomes' => (int)$billable,
            'subscription_amount_inr' => round($subscriptionAmount, 2),
            'ai_amount_inr' => round($aiAmount, 2),
            'total_payable_inr' => round($subscriptionAmount + $aiAmount, 2),
            'status' => $status,
            'issued_at' => $invoice['issued_at'] ?? null,
            'paid_at' => $invoice['paid_at'] ?? null
        ];
    }
    return $items;
}

function aiUsageCurrentMonthCharges(PDO $pdo, int $companyId): array {
    [$month, $start, $end] = aiUsageMonthBounds(date('Y-m'));
    $aiAmount = aiUsageScalar($pdo, "SELECT COALESCE(SUM(`amount_inr`), 0) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
    $billable = aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
    $subscriptionAmount = aiUsageGetSubscriptionAmount($pdo, $companyId);

    return [
        'invoice_month' => $month,
        'billable_outcomes' => (int)$billable,
        'ai_charges_inr' => round($aiAmount, 2),
        'subscription_amount_inr' => round($subscriptionAmount, 2),
        'estimated_total_inr' => round($subscriptionAmount + $aiAmount, 2)
    ];
}

function aiUsageSuperCompanyRows(PDO $pdo, string $from, string $toExclusive): array {
    $companies = $pdo->query("
        SELECT c.id, c.name, c.slug, c.status, c.plan_tier,
               COALESCE(bs.outcome_price_inr, 1.00) AS outcome_price_inr,
               COALESCE(bs.billing_status, 'active') AS ai_billing_status
        FROM `companies` c
        LEFT JOIN `ai_billing_settings` bs ON bs.company_id = c.id
        ORDER BY c.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        $summary = aiUsageCompanySummary($pdo, $companyId, $from, $toExclusive);
        $subscriptionAmount = aiUsageGetSubscriptionAmount($pdo, $companyId);
        $invStmt = $pdo->prepare("SELECT `status` FROM `ai_billing_invoices` WHERE `company_id` = ? AND `invoice_month` = ? LIMIT 1");
        $invStmt->execute([$companyId, date('Y-m')]);
        $currentInvoiceStatus = $invStmt->fetchColumn() ?: 'draft';
        $rows[] = [
            'company_id' => $companyId,
            'company_name' => $company['name'],
            'company_slug' => $company['slug'],
            'company_status' => $company['status'],
            'plan_tier' => $company['plan_tier'],
            'outcome_price_inr' => round((float)$company['outcome_price_inr'], 2),
            'ai_billing_status' => $company['ai_billing_status'],
            'current_invoice_status' => $currentInvoiceStatus,
            'subscription_amount_inr' => round($subscriptionAmount, 2),
            'total_payable_inr' => round($subscriptionAmount + (float)$summary['ai_charges_inr'], 2)
        ] + $summary;
    }
    return $rows;
}

try {
    switch ($action) {
        case 'mark_resolution':
            $companyKey = trim($data['company_key'] ?? $_SERVER['HTTP_X_COMPANY_KEY'] ?? '');
            cpRateLimit($pdo, 'ai_mark_resolution', 60, 60, [$companyKey, $data['session_id'] ?? $data['session_token'] ?? '']);
            $company = aiUsageResolveCompanyByKey($pdo, $companyKey);
            if (!$company) {
                aiUsageRespond(['success' => false, 'error' => 'Workspace not found.'], 404);
            }
            cpValidateWidgetInstallToken($company, cpGetWidgetRequestToken($data));

            $conversationId = (int)($data['conversation_id'] ?? 0);
            $sessionId = trim($data['session_id'] ?? $data['session_token'] ?? '');
            $rawResolved = $data['resolved'] ?? $data['status'] ?? false;
            $resolved = is_bool($rawResolved)
                ? $rawResolved
                : in_array(strtolower((string)$rawResolved), ['1', 'true', 'yes', 'resolved'], true);

            if ($conversationId <= 0 || $sessionId === '') {
                aiUsageRespond(['success' => false, 'error' => 'Conversation and session are required.'], 400);
            }

            $result = aiUsageMarkResolution($pdo, (int)$company['id'], $conversationId, $sessionId, $resolved, 'visitor', [
                'source' => 'widget_resolution_prompt',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
            ]);

            aiUsageRespond([
                'success' => true,
                'status' => $result['status'],
                'billable' => $result['billable'],
                'already_recorded' => $result['already_recorded'],
                'amount_inr' => $result['amount_inr'],
                'message' => $result['status'] === 'resolved'
                    ? 'Conversation marked as resolved.'
                    : 'Conversation marked as needing more help.'
            ]);
            break;

        case 'download_invoice':
            $requestedCompanyId = (int)($data['company_id'] ?? 0);
            if ($requestedCompanyId <= 0) {
                $requestedCompanyId = aiUsageRequireCompanySession();
            }
            aiUsageDownloadInvoice($pdo, $requestedCompanyId, trim($data['invoice_month'] ?? date('Y-m')));
            break;

        case 'summary':
            $companyId = aiUsageRequireCompanySession();
            [$from, $to, $toExclusive] = aiUsageDateRange($data);
            $summary = aiUsageCompanySummary($pdo, $companyId, $from, $toExclusive);
            $settings = aiUsageGetSettings($pdo, $companyId);
            $month = aiUsageCurrentMonthCharges($pdo, $companyId);

            aiUsageRespond([
                'success' => true,
                'range' => ['from' => $from, 'to' => $to],
                'settings' => [
                    'outcome_price_inr' => round((float)$settings['outcome_price_inr'], 2),
                    'currency' => $settings['currency'] ?? 'INR',
                    'billing_status' => $settings['billing_status'] ?? 'active'
                ],
                'metrics' => $summary,
                'current_month' => $month,
                'daily' => aiUsageDailySeries($pdo, $companyId, $from, $to),
                'invoices' => aiUsageInvoices($pdo, $companyId, 6)
            ]);
            break;

        case 'super_summary':
            aiUsageRequireSuperAdmin($pdo);
            [$from, $to, $toExclusive] = aiUsageDateRange($data);
            $rows = aiUsageSuperCompanyRows($pdo, $from, $toExclusive);

            $totals = [
                'companies' => count($rows),
                'total_conversations' => 0,
                'ai_messages' => 0,
                'resolved_conversations' => 0,
                'unresolved_conversations' => 0,
                'human_handoffs' => 0,
                'billable_outcomes' => 0,
                'ai_revenue_inr' => 0.0,
                'subscription_revenue_inr' => 0.0,
                'total_payable_inr' => 0.0,
                'paid_amount_inr' => 0.0,
                'outstanding_amount_inr' => 0.0
            ];

            foreach ($rows as $row) {
                $totals['total_conversations'] += (int)$row['total_conversations'];
                $totals['ai_messages'] += (int)$row['ai_messages'];
                $totals['resolved_conversations'] += (int)$row['resolved_conversations'];
                $totals['unresolved_conversations'] += (int)$row['unresolved_conversations'];
                $totals['human_handoffs'] += (int)$row['human_handoffs'];
                $totals['billable_outcomes'] += (int)$row['billable_outcomes'];
                $totals['ai_revenue_inr'] += (float)$row['ai_charges_inr'];
                $totals['subscription_revenue_inr'] += (float)$row['subscription_amount_inr'];
                $totals['total_payable_inr'] += (float)$row['total_payable_inr'];
            }
            $totals['resolution_rate'] = $totals['total_conversations'] > 0
                ? round(($totals['resolved_conversations'] / $totals['total_conversations']) * 100, 1)
                : 0.0;

            $month = date('Y-m');
            $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(`total_payable_inr`), 0) FROM `ai_billing_invoices` WHERE `invoice_month` = ? AND `status` = 'paid'");
            $paidStmt->execute([$month]);
            $totals['paid_amount_inr'] = round((float)$paidStmt->fetchColumn(), 2);
            $totals['outstanding_amount_inr'] = max(0, round($totals['total_payable_inr'] - $totals['paid_amount_inr'], 2));

            $trend = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("first day of -{$i} months"));
                [$mk, $start, $end] = aiUsageMonthBounds($m);
                $trend[] = [
                    'month' => $mk,
                    'billable_outcomes' => (int)aiUsageScalar($pdo, "SELECT COUNT(*) FROM `ai_resolution_ledger` WHERE `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$start, $end]),
                    'revenue_inr' => round(aiUsageScalar($pdo, "SELECT COALESCE(SUM(`amount_inr`), 0) FROM `ai_resolution_ledger` WHERE `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$start, $end]), 2)
                ];
            }

            aiUsageRespond([
                'success' => true,
                'range' => ['from' => $from, 'to' => $to],
                'metrics' => $totals,
                'companies' => $rows,
                'trend' => $trend
            ]);
            break;

        case 'configure_pricing':
            aiUsageRequireSuperAdmin($pdo);
            $companyId = (int)($data['company_id'] ?? 0);
            $price = round(max(0, (float)($data['outcome_price_inr'] ?? 1)), 2);
            if ($companyId <= 0 || $price > 9999) {
                aiUsageRespond(['success' => false, 'error' => 'Invalid company or price.'], 400);
            }
            $stmt = $pdo->prepare("
                INSERT INTO `ai_billing_settings` (`company_id`, `outcome_price_inr`, `currency`, `billing_status`, `created_at`, `updated_at`)
                VALUES (?, ?, 'INR', 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE `outcome_price_inr` = VALUES(`outcome_price_inr`), `updated_at` = NOW()
            ");
            $stmt->execute([$companyId, $price]);
            aiUsageRespond(['success' => true, 'company_id' => $companyId, 'outcome_price_inr' => $price]);
            break;

        case 'update_invoice_status':
            aiUsageRequireSuperAdmin($pdo);
            $companyId = (int)($data['company_id'] ?? 0);
            [$month, $start, $end] = aiUsageMonthBounds(trim($data['invoice_month'] ?? date('Y-m')));
            $status = strtolower(trim($data['status'] ?? 'draft'));
            if ($companyId <= 0 || !in_array($status, ['draft', 'issued', 'paid', 'overdue', 'waived'], true)) {
                aiUsageRespond(['success' => false, 'error' => 'Invalid invoice update.'], 400);
            }
            $subscriptionAmount = aiUsageGetSubscriptionAmount($pdo, $companyId);
            $aiAmount = aiUsageScalar($pdo, "SELECT COALESCE(SUM(`amount_inr`), 0) FROM `ai_resolution_ledger` WHERE `company_id` = ? AND `billable` = 1 AND `status` = 'resolved' AND `confirmed_at` >= ? AND `confirmed_at` < ?", [$companyId, $start, $end]);
            $total = $subscriptionAmount + $aiAmount;
            $stmt = $pdo->prepare("
                INSERT INTO `ai_billing_invoices`
                (`company_id`, `invoice_month`, `subscription_amount_inr`, `ai_amount_inr`, `total_payable_inr`, `status`, `issued_at`, `paid_at`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, IF(? IN ('issued','paid','overdue'), NOW(), NULL), IF(? = 'paid', NOW(), NULL), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    `subscription_amount_inr` = VALUES(`subscription_amount_inr`),
                    `ai_amount_inr` = VALUES(`ai_amount_inr`),
                    `total_payable_inr` = VALUES(`total_payable_inr`),
                    `status` = VALUES(`status`),
                    `issued_at` = IF(VALUES(`issued_at`) IS NULL, `issued_at`, VALUES(`issued_at`)),
                    `paid_at` = IF(? = 'paid', NOW(), IF(? = 'waived', NULL, `paid_at`)),
                    `updated_at` = NOW()
            ");
            $stmt->execute([$companyId, $month, $subscriptionAmount, $aiAmount, $total, $status, $status, $status, $status, $status]);
            aiUsageRespond(['success' => true, 'company_id' => $companyId, 'invoice_month' => $month, 'status' => $status]);
            break;

        default:
            aiUsageRespond(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    aiUsageRespond([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}
