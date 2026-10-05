<?php
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/xml; charset=utf-8');
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

if ($isCli) {
    $baseUrl = 'http://localhost/cuboidpilot';
} else {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = rtrim(str_replace('\\', '/', $scriptDir), '/');
    $baseUrl = $scheme . '://' . $host . $basePath;
}

$today = date('Y-m-d');

$urls = [
    [
        'loc' => $baseUrl . '/index.html',
        'lastmod' => $today,
        'changefreq' => 'weekly',
        'priority' => '1.0'
    ],
    [
        'loc' => $baseUrl . '/pricing.html',
        'lastmod' => $today,
        'changefreq' => 'weekly',
        'priority' => '0.9'
    ],
    [
        'loc' => $baseUrl . '/blog.php',
        'lastmod' => $today,
        'changefreq' => 'daily',
        'priority' => '0.9'
    ],
    [
        'loc' => $baseUrl . '/why-choose-cai.html',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.8'
    ],
    [
        'loc' => $baseUrl . '/docs.html',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.7'
    ],
    [
        'loc' => $baseUrl . '/privacy.html',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.6'
    ],
    [
        'loc' => $baseUrl . '/terms.html',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.6'
    ],
    [
        'loc' => $baseUrl . '/security.html',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.6'
    ]
];

$fallbackArticles = [
    'gandhi-jayanti-truth-technology-self-reliance' => '2026-10-02',
    'meet-cai-autonomous-ai-agent' => '2026-10-02'
];

$blogArticles = [];

if (file_exists(__DIR__ . '/config/db.php')) {
    try {
        require_once __DIR__ . '/config/db.php';
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT `slug`, COALESCE(`updated_at`, `created_at`, NOW()) as `mod_time` FROM `blogs` WHERE `status` = 'published' ORDER BY `created_at` DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $date = substr($row['mod_time'], 0, 10);
            $blogArticles[$row['slug']] = $date;
        }
    } catch (Throwable $e) {}
}

foreach ($fallbackArticles as $slug => $date) {
    if (!isset($blogArticles[$slug])) {
        $blogArticles[$slug] = $date;
    }
}

foreach ($blogArticles as $slug => $date) {
    $urls[] = [
        'loc' => $baseUrl . '/blog.php?slug=' . urlencode($slug),
        'lastmod' => $date,
        'changefreq' => 'monthly',
        'priority' => '0.8'
    ];
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $xml .= "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    $xml .= "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
    $xml .= "    <changefreq>" . $u['changefreq'] . "</changefreq>\n";
    $xml .= "    <priority>" . $u['priority'] . "</priority>\n";
    $xml .= "  </url>\n";
}
$xml .= '</urlset>' . "\n";

echo $xml;