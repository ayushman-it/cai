<?php
/**
 * Verifies the baseline AI quality checks used before broad production rollout.
 */

require_once __DIR__ . '/../config/db.php';

$pdo = getDbConnection();
$stmt = $pdo->query("
    SELECT case_key, category, question, expected_behavior
    FROM `ai_quality_checks`
    WHERE `is_active` = 1
    ORDER BY `id` ASC
");
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$required = [
    'global_unknown_price_fallback',
    'global_document_share_policy',
    'global_human_handoff',
    'global_resolution_confirmation',
    'global_tenant_isolation'
];

$keys = array_column($cases, 'case_key');
$missing = array_values(array_diff($required, $keys));

if (!empty($missing)) {
    fwrite(STDERR, "Missing AI quality baseline cases: " . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'success' => true,
    'active_cases' => count($cases),
    'required_cases' => count($required)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
