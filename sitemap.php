<?php
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/xml; charset=utf-8');
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'cai.cuboidsoft.in';

if ($isCli || preg_match('/(^|\.)cai\.cuboidsoft\.in$/i', $host)) {
    $baseUrl = 'https://cai.cuboidsoft.in';
} else {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = rtrim(str_replace('\\', '/', $scriptDir), '/');
    $baseUrl = $scheme . '://' . $host . $basePath;
}

function staticLastmod(string $file): string {
    $path = __DIR__ . '/' . ltrim($file, '/');
    return file_exists($path) ? date('Y-m-d', filemtime($path)) : date('Y-m-d');
}

function loadSitemapEnv(): void {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function fetchPublishedBlogArticles(): array {
    loadSitemapEnv();

    $dbName = getenv('DB_NAME') ?: '';
    $dbUser = getenv('DB_USER') ?: '';
    if ($dbName === '' || $dbUser === '') {
        return [];
    }

    try {
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbPort = getenv('DB_PORT') ?: '3306';
        $dbPass = getenv('DB_PASS') ?: '';
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2,
            ]
        );
        $stmt = $pdo->query("SELECT `slug`, COALESCE(`updated_at`, `created_at`, NOW()) as `mod_time` FROM `blogs` WHERE `status` = 'published' ORDER BY `created_at` DESC");
        $articles = [];
        while ($row = $stmt->fetch()) {
            $articles[$row['slug']] = substr($row['mod_time'], 0, 10);
        }
        return $articles;
    } catch (Throwable $e) {
        return [];
    }
}

$urls = [
    [
        'loc' => $baseUrl . '/',
        'lastmod' => staticLastmod('index.html'),
        'changefreq' => 'weekly',
        'priority' => '1.0'
    ],
    [
        'loc' => $baseUrl . '/pricing.html',
        'lastmod' => staticLastmod('pricing.html'),
        'changefreq' => 'weekly',
        'priority' => '0.9'
    ],
    [
        'loc' => $baseUrl . '/blog.php',
        'lastmod' => max(staticLastmod('blog.php'), staticLastmod('blog.html')),
        'changefreq' => 'daily',
        'priority' => '0.9'
    ],
    [
        'loc' => $baseUrl . '/why-choose-cai.html',
        'lastmod' => staticLastmod('why-choose-cai.html'),
        'changefreq' => 'monthly',
        'priority' => '0.8'
    ],
    [
        'loc' => $baseUrl . '/docs.html',
        'lastmod' => staticLastmod('docs.html'),
        'changefreq' => 'monthly',
        'priority' => '0.7'
    ],
    [
        'loc' => $baseUrl . '/security.html',
        'lastmod' => staticLastmod('security.html'),
        'changefreq' => 'monthly',
        'priority' => '0.7'
    ],
    [
        'loc' => $baseUrl . '/privacy.html',
        'lastmod' => staticLastmod('privacy.html'),
        'changefreq' => 'monthly',
        'priority' => '0.6'
    ],
    [
        'loc' => $baseUrl . '/terms.html',
        'lastmod' => staticLastmod('terms.html'),
        'changefreq' => 'monthly',
        'priority' => '0.6'
    ]
];

$fallbackArticles = [
    'why-cai-is-important-for-you' => '2026-10-05',
    'gandhi-jayanti-truth-technology-self-reliance' => '2026-10-02',
    'meet-cai-autonomous-ai-agent' => '2026-10-02'
];

$blogArticles = fetchPublishedBlogArticles();

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
