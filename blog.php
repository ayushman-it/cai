<?php
/**
 * CUBOIDPILOT — THE CAI BLOG & SOCIAL SHARING CARD HANDLER
 * Dynamically serves Open Graph, Twitter Cards, and Schema.org JSON-LD for rich link previews
 * on WhatsApp, Twitter/X, LinkedIn, Facebook, Slack, iMessage, and search engines.
 */

// 1. Resolve Absolute Base URL for Production & Localhost
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$basePath = rtrim(str_replace('\\', '/', $scriptDir), '/');
$baseUrl = $scheme . '://' . $host . $basePath;

// 2. Identify Requested Article Slug
$slug = trim($_GET['slug'] ?? '');
$post = null;

// 3. Fallback Editorial Posts (Guaranteed availability even if DB is fresh)
$FALLBACK_POSTS = [
    'why-cai-is-important-for-you' => [
        'title' => 'Why Cai is Important for You: The Complete Guide (आसान शब्दों में समझें)',
        'excerpt' => 'Customer queries ka instant reply nahi milta? Leads miss ho rahi hain? Jaaniye kaise Cai aapke business ko 24/7 active rakh kar sales aur customer support ko superfast bana deta hai.',
        'cover_image' => 'assets/blog/why-cai-is-important-for-you.png',
        'author_name' => 'Ayush',
        'author_role' => 'Founder & AI Architect',
        'published_at' => '2026-10-05 10:00:00'
    ],
    'gandhi-jayanti-truth-technology-self-reliance' => [
        'title' => 'Gandhi Jayanti Special: Truth, Decentralized Technology & The Spirit of Self-Reliance',
        'excerpt' => "On October 2nd, we honor Mahatma Gandhi's enduring ideals — Satya (Truth), Swavalamban (Self-Reliance), and Sarvodaya (Welfare of All). Here is how these principles guide the future of autonomous, grounded AI at CuboidPilot.",
        'cover_image' => 'assets/blog/gandhi-jayanti-2026.png',
        'author_name' => 'Ayush',
        'author_role' => 'Founder & AI Architect',
        'published_at' => '2026-10-02 09:00:00'
    ],
    'meet-cai-autonomous-ai-agent' => [
        'title' => 'Meet Cai: Why We Built the World’s First Autonomous AI Helpdesk & SDR Agent',
        'excerpt' => 'Discover how Cai transforms visitor conversations into qualified pipeline, cuts ticket volume by 70%, and bridges autonomous AI support with instant WhatsApp sales handoffs.',
        'cover_image' => 'assets/blog/meet-cai-founder.png',
        'author_name' => 'Ayush',
        'author_role' => 'Founder & AI Architect',
        'published_at' => '2026-10-02 10:00:00'
    ]
];

// 4. Attempt Database Lookup if DB Config exists
if (!empty($slug)) {
    if (file_exists(__DIR__ . '/config/db.php')) {
        try {
            require_once __DIR__ . '/config/db.php';
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT * FROM `blogs` WHERE `slug` = ? AND `status` = 'published' LIMIT 1");
            $stmt->execute([$slug]);
            $dbPost = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dbPost) {
                $post = $dbPost;
            }
        } catch (Throwable $e) {
            // Silently fall through to fallback posts
        }
    }

    if (!$post && isset($FALLBACK_POSTS[$slug])) {
        $post = $FALLBACK_POSTS[$slug];
        $post['slug'] = $slug;
    }
}

// 5. Construct Dynamic SEO & Open Graph Parameters
if ($post) {
    $cleanTitle = htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8');
    $cleanExcerpt = htmlspecialchars(strip_tags($post['excerpt']), ENT_QUOTES, 'UTF-8');
    $pageTitle = $cleanTitle . ' — The Cai Blog | CuboidPilot';
    $pageDesc = $cleanExcerpt;
    $articleUrl = $baseUrl . '/blog.php?slug=' . urlencode($slug);
    $ogType = 'article';

    // Absolute Cover Image URL (Mandatory for WhatsApp and Social Link Previews)
    $rawCover = $post['cover_image'] ?? 'assets/blog/meet-cai-founder.png';
    if (preg_match('#^https?://#i', $rawCover)) {
        $coverImageUrl = $rawCover;
    } else {
        $coverImageUrl = $baseUrl . '/' . ltrim($rawCover, '/');
    }

    $authorName = htmlspecialchars($post['author_name'] ?? 'Ayush', ENT_QUOTES, 'UTF-8');
    $publishedDate = date('c', strtotime($post['published_at'] ?? 'now'));

    // Schema.org BlogPosting JSON-LD
    $schemaJson = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $articleUrl
        ],
        'headline' => $post['title'],
        'description' => strip_tags($post['excerpt']),
        'image' => [
            '@type' => 'ImageObject',
            'url' => $coverImageUrl,
            'width' => 1200,
            'height' => 630
        ],
        'datePublished' => $publishedDate,
        'dateModified' => $publishedDate,
        'author' => [
            '@type' => 'Person',
            'name' => $authorName,
            'jobTitle' => $post['author_role'] ?? 'Founder & AI Architect'
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'CuboidPilot',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $baseUrl . '/assets/logo-black.png'
            ]
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} else {
    // Directory / Publication View
    $pageTitle = 'The Cai Blog — Autonomous AI, Product & Customer Service | CuboidPilot';
    $pageDesc = 'Discover how Cai transforms visitor conversations into qualified pipeline, cuts ticket volume by 70%, and bridges autonomous AI support with instant WhatsApp sales handoffs.';
    $articleUrl = $baseUrl . '/blog.php';
    $coverImageUrl = $baseUrl . '/assets/blog/meet-cai-founder.png';
    $ogType = 'website';
    $authorName = 'CuboidPilot Editorial Team';
    $publishedDate = date('c');

    $schemaJson = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => 'The Cai Blog',
        'url' => $articleUrl,
        'description' => $pageDesc,
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'CuboidPilot',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $baseUrl . '/assets/logo-black.png'
            ]
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// 6. Read Base blog.html Template
$htmlPath = __DIR__ . '/blog.html';
if (!file_exists($htmlPath)) {
    http_response_code(500);
    echo "Blog template file not found.";
    exit;
}

$html = file_get_contents($htmlPath);

// 7. Dynamic Replacement Chunk for Head Meta & Social Cards
$dynamicHead = <<<HTML
  <title id="page-title">{$pageTitle}</title>
  <meta name="description" id="page-desc" content="{$pageDesc}">
  <meta name="keywords" content="Cai, CuboidPilot, AI Helpdesk, Autonomous Customer Service, SDR Agent, WhatsApp Business AI, AI Lead Generation">
  <meta name="author" content="{$authorName}">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <link rel="canonical" href="{$articleUrl}">

  <!-- OpenGraph / Facebook / WhatsApp Rich Sharing Previews -->
  <meta property="og:type" content="{$ogType}">
  <meta property="og:site_name" content="CuboidPilot">
  <meta property="og:url" content="{$articleUrl}">
  <meta property="og:title" id="og-title" content="{$pageTitle}">
  <meta property="og:description" id="og-desc" content="{$pageDesc}">
  <meta property="og:image" id="og-image" content="{$coverImageUrl}">
  <meta property="og:image:secure_url" content="{$coverImageUrl}">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="{$pageTitle}">
  <meta property="article:published_time" content="{$publishedDate}">
  <meta property="article:author" content="{$authorName}">

  <!-- Twitter / X Rich Card Meta -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:site" content="@CuboidPilot">
  <meta name="twitter:creator" content="@CuboidPilot">
  <meta name="twitter:url" content="{$articleUrl}">
  <meta name="twitter:title" content="{$pageTitle}">
  <meta name="twitter:description" content="{$pageDesc}">
  <meta name="twitter:image" content="{$coverImageUrl}">
  <meta name="twitter:image:alt" content="{$pageTitle}">

  <!-- Schema.org JSON-LD Structured Data for Search Engine Rich Snippets -->
  <script type="application/ld+json">
{$schemaJson}
  </script>
HTML;

// Replace title & existing OG tags in the template
$pattern = '/<title id="page-title">.*?<meta name="twitter:card" content="summary_large_image">/s';
if (preg_match($pattern, $html)) {
    $html = preg_replace($pattern, trim($dynamicHead), $html, 1);
} else {
    // Fallback: inject right after <head>
    $html = str_replace('<head>', "<head>\n" . $dynamicHead, $html);
}

// 8. Output Fully Rendered Page with Dynamic Metadata
echo $html;
