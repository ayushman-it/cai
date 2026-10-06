<?php
/**
 * Test products API cleanly
 */
require_once __DIR__ . '/../config/db.php';
$pdo = getDbConnection();
$company = $pdo->query("SELECT id, company_key FROM companies WHERE slug='cuboidsoft' OR company_key='cp_live_cuboidsoft' LIMIT 1")->fetch();
$companyId = (int)$company['id'];
$companyKey = $company['company_key'];

// Verify direct database contents
$stmt = $pdo->prepare("SELECT id, name, category, price_inr, emi_available, emi_starting_at_inr, duration FROM products WHERE company_id = ?");
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total products in DB for Company ID {$companyId}: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  • [#{$r['id']}] [{$r['category']}] {$r['name']} - ₹{$r['price_inr']} ({$r['duration']}) | EMI: " . ($r['emi_available'] ? 'Yes (₹' . $r['emi_starting_at_inr'] . '/mo)' : 'No') . "\n";
}
