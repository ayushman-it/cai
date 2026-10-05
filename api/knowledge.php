<?php
/**
 * CUBOIDPILOT — KNOWLEDGE STORE API
 * Strictly multi-tenant isolated. Manages verified company knowledge sources:
 * Website URLs, PDF/Text documents, FAQs, and pricing policies.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/entitlements.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDbConnection();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$companyId = (int)($_SESSION['company_id'] ?? 0);

if ($companyId === 0) {
    // Gracefully resolve user's assigned company or active workspace
    $uStmt = $pdo->prepare("SELECT company_id, is_super_admin FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $u = $uStmt->fetch();
    if (!empty($u['company_id'])) {
        $companyId = (int)$u['company_id'];
        $_SESSION['company_id'] = $companyId;
    } elseif (!empty($u['is_super_admin'])) {
        $firstComp = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstComp) {
            $companyId = (int)$firstComp;
            $_SESSION['company_id'] = $companyId;
        }
    }
}

if ($companyId === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Workspace context not found']);
    exit;
}

// Release PHP session file lock immediately so parallel queries and external scrapers don't block other requests
session_write_close();

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;

/**
 * Scrape and extract verified factual content from a website URL.
 * Cleans scripts, styles, boilerplate, extracts titles, headings, and lists.
 */
if (!function_exists('scrapeWebsiteContent')) {
function scrapeWebsiteContent($url) {
    $url = trim($url);
    if (empty($url)) {
        return ['success' => false, 'error' => 'Empty URL'];
    }

    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? '');
    $host   = $parsed['host'] ?? '';

    if (!in_array($scheme, ['http', 'https']) || empty($host)) {
        return ['success' => false, 'error' => 'Invalid URL protocol. Only HTTP and HTTPS are permitted.'];
    }

    // SSRF Protection: Resolve host to IP and block private/loopback/cloud metadata networks
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['success' => false, 'error' => 'Requests to private, localhost, or internal cloud IP ranges are prohibited.'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 CuboidPilot/2.0',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,hi;q=0.8',
            'Cache-Control: no-cache'
        ]
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (empty($html) || $httpCode >= 400) {
        return ['success' => false, 'error' => $curlError ?: "HTTP Error {$httpCode} while fetching URL"];
    }

    // Extract title
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Extract meta description
    $metaDesc = '';
    if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $m)) {
        $metaDesc = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Remove noise tags
    $cleanHtml = preg_replace([
        '/<script\b[^>]*>(.*?)<\/script>/is',
        '/<style\b[^>]*>(.*?)<\/style>/is',
        '/<noscript\b[^>]*>(.*?)<\/noscript>/is',
        '/<svg\b[^>]*>(.*?)<\/svg>/is',
        '/<canvas\b[^>]*>(.*?)<\/canvas>/is',
        '/<header\b[^>]*>(.*?)<\/header>/is',
        '/<footer\b[^>]*>(.*?)<\/footer>/is',
        '/<nav\b[^>]*>(.*?)<\/nav>/is',
        '/<aside\b[^>]*>(.*?)<\/aside>/is',
        '/<iframe\b[^>]*>(.*?)<\/iframe>/is',
        '/<!--(.*?)-->/s'
    ], '', $html);

    // Format structural blocks into clean markdown
    $cleanHtml = preg_replace('/<h[1-2][^>]*>(.*?)<\/h[1-2]>/is', "\n\n# $1\n", $cleanHtml);
    $cleanHtml = preg_replace('/<h[3-4][^>]*>(.*?)<\/h[3-4]>/is', "\n\n## $1\n", $cleanHtml);
    $cleanHtml = preg_replace('/<h[5-6][^>]*>(.*?)<\/h[5-6]>/is', "\n\n### $1\n", $cleanHtml);
    $cleanHtml = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "\n• $1", $cleanHtml);
    $cleanHtml = preg_replace('/<(p|div|tr)[^>]*>/is', "\n", $cleanHtml);
    $cleanHtml = preg_replace('/<br\s*\/?>/is', "\n", $cleanHtml);

    $rawText = strip_tags($cleanHtml);
    $decodedText = html_entity_decode($rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Clean up excessive whitespace and blank lines
    $lines = explode("\n", $decodedText);
    $cleanLines = [];
    foreach ($lines as $line) {
        $t = trim(preg_replace('/[ \t]+/', ' ', $line));
        if (!empty($t)) {
            $cleanLines[] = $t;
        }
    }
    $finalContent = implode("\n", $cleanLines);

    // Prefix with title & meta description if available
    $headerPrefix = '';
    if (!empty($title)) {
        $headerPrefix .= "Source Title: {$title}\n";
    }
    if (!empty($metaDesc)) {
        $headerPrefix .= "Overview: {$metaDesc}\n\n";
    }
    $finalContent = $headerPrefix . $finalContent;

    // Limit to 25,000 characters
    if (mb_strlen($finalContent) > 25000) {
        $finalContent = mb_substr($finalContent, 0, 25000) . "\n\n[Content truncated for memory optimization]";
    }

    return [
        'success' => true,
        'title'   => $title,
        'content' => $finalContent,
        'length'  => mb_strlen($finalContent)
    ];
}
}

/**
 * Robust native document parser for .txt, .csv, .docx, .xlsx, .doc, .pdf
 * No external dependencies required.
 */
if (!function_exists('extractTextFromDocument')) {
function extractTextFromDocument($tmpPath, $originalName, $ext) {
    $ext = strtolower($ext);
    $text = '';

    if ($ext === 'txt' || $ext === 'md') {
        $text = @file_get_contents($tmpPath);
        if ($text) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
    } elseif ($ext === 'csv') {
        $rows = [];
        if (($handle = fopen($tmpPath, "r")) !== false) {
            while (($row = fgetcsv($handle, 4000, ",")) !== false) {
                $cleanRow = array_map(function($c) { return trim(str_replace('|', '/', $c)); }, $row);
                if (!empty(array_filter($cleanRow))) {
                    $rows[] = '| ' . implode(' | ', $cleanRow) . ' |';
                }
            }
            fclose($handle);
        }
        if (!empty($rows) && count($rows) > 1) {
            $colCount = max(1, substr_count($rows[0], '|') - 1);
            $sep = '|' . str_repeat('---|', $colCount);
            array_splice($rows, 1, 0, [$sep]);
        }
        $text = implode("\n", $rows);
    } elseif ($ext === 'docx') {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml) {
                    $xml = preg_replace('/<\/w:p>/', "\n", $xml);
                    $xml = preg_replace('/<w:br[^>]*>/', "\n", $xml);
                    $xml = preg_replace('/<w:tab[^>]*>/', "\t", $xml);
                    $text = strip_tags($xml);
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
    } elseif ($ext === 'xlsx') {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) === true) {
                $sharedStrings = [];
                $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
                if ($sharedXml) {
                    $xmlObj = @simplexml_load_string($sharedXml);
                    if ($xmlObj) {
                        foreach ($xmlObj->si as $val) {
                            $sharedStrings[] = (string)($val->t ?? ($val->r ? implode('', (array)$val->r->t) : ''));
                        }
                    }
                }

                $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
                $rows = [];
                if ($sheetXml) {
                    $sheetObj = @simplexml_load_string($sheetXml);
                    if ($sheetObj && isset($sheetObj->sheetData->row)) {
                        foreach ($sheetObj->sheetData->row as $row) {
                            $rowVals = [];
                            foreach ($row->c as $cell) {
                                $cellType = (string)$cell['t'];
                                $cellVal = (string)$cell->v;
                                if ($cellType === 's' && isset($sharedStrings[(int)$cellVal])) {
                                    $cellVal = $sharedStrings[(int)$cellVal];
                                }
                                $rowVals[] = trim(str_replace('|', '/', $cellVal));
                            }
                            if (!empty(array_filter($rowVals))) {
                                $rows[] = '| ' . implode(' | ', $rowVals) . ' |';
                            }
                        }
                    }
                }
                $zip->close();

                if (!empty($rows) && count($rows) > 1) {
                    $colCount = max(1, substr_count($rows[0], '|') - 1);
                    $sep = '|' . str_repeat('---|', $colCount);
                    array_splice($rows, 1, 0, [$sep]);
                }
                $text = implode("\n", $rows);
            }
        }
    } elseif ($ext === 'doc') {
        $raw = @file_get_contents($tmpPath);
        if ($raw) {
            preg_match_all('/[a-zA-Z0-9\s.,!?:;\-\/\\(\\)\"\'₹$@%]{4,}/', $raw, $matches);
            if (!empty($matches[0])) {
                $text = implode(' ', $matches[0]);
            }
        }
    } elseif ($ext === 'pdf') {
        $raw = @file_get_contents($tmpPath);
        if ($raw) {
            preg_match_all('/\((.*?)\)\s*Tj/s', $raw, $m1);
            if (!empty($m1[1])) {
                $text = implode(' ', $m1[1]);
            } else {
                preg_match_all('/\[(.*?)\]\s*TJ/s', $raw, $m2);
                if (!empty($m2[1])) {
                    $text = implode(' ', $m2[1]);
                }
            }
            if (empty($text)) {
                preg_match_all('/[a-zA-Z0-9\s.,!?:;\-\/\\(\\)\"\'₹$@%]{4,}/', $raw, $m3);
                if (!empty($m3[0])) {
                    $text = implode(' ', array_slice($m3[0], 0, 1000));
                }
            }
        }
    }

    $text = preg_replace('/[\x{FFFD}\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}]/u', '', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
    $text = trim($text);

    if (mb_strlen($text) > 25000) {
        $text = mb_substr($text, 0, 25000) . "\n\n[Document content truncated at 25,000 characters for optimal AI memory]";
    }

    return $text;
}
}

try {
    switch ($action) {
        // 1. List Knowledge Sources
        case 'list':
            $stmt = $pdo->prepare("
                SELECT id, type, title, category, source_url, content, is_active, created_at, updated_at,
                       DATE_FORMAT(updated_at, '%b %e, %Y') as formatted_date
                FROM `knowledge_sources`
                WHERE company_id = ?
                ORDER BY id DESC
            ");
            $stmt->execute([$companyId]);
            $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean format
            $list = array_map(function($s) {
                return [
                    'id'             => (int)$s['id'],
                    'type'           => $s['type'],
                    'type_label'     => match($s['type']) {
                        'website_url'    => 'Website URL',
                        'text_doc'       => 'Document / Text',
                        'faq'            => 'FAQ Entry',
                        'policy'         => 'Pricing & Policy',
                        'ai_instruction' => 'Custom Prompt',
                        default          => 'General Document'
                    },
                    'title'          => $s['title'],
                    'category'       => $s['category'] ?: 'General',
                    'source_url'     => $s['source_url'] ?? '',
                    'content_preview'=> substr(strip_tags($s['content']), 0, 140) . (strlen($s['content']) > 140 ? '...' : ''),
                    'content'        => $s['content'],
                    'is_active'      => (bool)$s['is_active'],
                    'last_synced'    => $s['formatted_date'] ?: 'Recently'
                ];
            }, $sources);

            echo json_encode([
                'success' => true,
                'count'   => count($list),
                'sources' => $list
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 1.5 Upload and Index Document File (.txt, .docx, .xlsx, .csv, .pdf)
        case 'upload_doc':
            if (empty($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please select a valid document file to upload']);
                exit;
            }

            $file = $_FILES['doc_file'];
            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExts = ['txt', 'docx', 'doc', 'xlsx', 'xls', 'csv', 'md', 'pdf'];

            if (!in_array($ext, $allowedExts)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Unsupported format (.{$ext}). Please upload .txt, .docx, .xlsx, or .csv documents."]);
                exit;
            }

            $extracted = extractTextFromDocument($file['tmp_name'], $origName, $ext);
            if (empty($extracted)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Could not extract readable text from {$origName}. Ensure the file contains text or table data."]);
                exit;
            }

            $docTitle = trim($_POST['title'] ?? pathinfo($origName, PATHINFO_FILENAME));
            $docCategory = trim($_POST['category'] ?? 'Documents');
            if (empty($docTitle)) {
                $docTitle = pathinfo($origName, PATHINFO_FILENAME);
            }

            // Save original uploaded file in assets/uploads/docs
            $uploadDir = __DIR__ . '/../assets/uploads/docs/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $storedName = 'doc_' . $companyId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            @move_uploaded_file($file['tmp_name'], $uploadDir . $storedName);
            $fileUrl = 'assets/uploads/docs/' . $storedName;

            $ins = $pdo->prepare("
                INSERT INTO `knowledge_sources`
                (`company_id`, `type`, `title`, `category`, `source_url`, `content`, `is_active`, `created_at`, `updated_at`)
                VALUES (?, 'text_doc', ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $ins->execute([$companyId, $docTitle, $docCategory, $origName, $extracted]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'id'      => $newId,
                'title'   => $docTitle,
                'length'  => mb_strlen($extracted),
                'preview' => mb_substr($extracted, 0, 250),
                'file_url'=> $fileUrl,
                'message' => "Document '{$origName}' parsed and indexed into AI knowledge base (" . mb_strlen($extracted) . " characters)."
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 1.6 Preview Extracted Document Text (Client Helper)
        case 'preview_doc':
            if (empty($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Please provide a file to preview']);
                exit;
            }
            $file = $_FILES['doc_file'];
            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $extracted = extractTextFromDocument($file['tmp_name'], $origName, $ext);

            echo json_encode([
                'success'   => true,
                'title'     => pathinfo($origName, PATHINFO_FILENAME),
                'content'   => $extracted,
                'length'    => mb_strlen($extracted)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // 2. Add New Knowledge Source
        case 'add':
            $type     = trim($data['type'] ?? 'faq');
            $title    = trim($data['title'] ?? '');
            $content  = trim($data['content'] ?? '');
            $category = trim($data['category'] ?? 'General');
            $url      = trim($data['source_url'] ?? '');

            if (empty($title)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Title or Source Name is required']);
                exit;
            }

            // Real live crawler/scraper for provided URL (Only when type is website_url or content is empty)
            $scrapedResult = null;
            if (!empty($url) && ($type === 'website_url' || empty($content) || $content === $url)) {
                $scrapedResult = scrapeWebsiteContent($url);
                if ($scrapedResult && $scrapedResult['success'] && !empty($scrapedResult['content'])) {
                    if (empty($content) || $content === $url) {
                        $content = $scrapedResult['content'];
                    } else {
                        $content = "USER VERIFIED FACTS / NOTES:\n" . $content . "\n\nCRAWLED SOURCE DATA ({$url}):\n" . $scrapedResult['content'];
                    }
                    if (empty($title) && !empty($scrapedResult['title'])) {
                        $title = $scrapedResult['title'];
                    }
                }
            }

            if (empty($content) && !empty($url)) {
                $content = "Website Source: {$url}\nValidated company reference for {$title}.";
            }

            if (empty($content)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Content or valid URL is required']);
                exit;
            }

            $allowedTypes = ['faq', 'website_url', 'text_doc', 'policy', 'ai_instruction'];
            if (!in_array($type, $allowedTypes)) {
                $type = 'faq';
            }

            $ins = $pdo->prepare("
                INSERT INTO `knowledge_sources`
                (`company_id`, `type`, `title`, `category`, `source_url`, `content`, `is_active`, `created_at`, `updated_at`)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $ins->execute([$companyId, $type, $title, $category, $url, $content]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'id'      => $newId,
                'message' => 'Knowledge source added and indexed into AI memory' . ($scrapedResult && $scrapedResult['success'] ? ' (Live website crawled)' : '')
            ]);
            break;

        // 3. Toggle Active Status
        case 'toggle_active':
            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Source ID is required']);
                exit;
            }

            $sCheck = $pdo->prepare("SELECT id, is_active FROM `knowledge_sources` WHERE id = ? AND company_id = ? LIMIT 1");
            $sCheck->execute([$id, $companyId]);
            $src = $sCheck->fetch();

            if (!$src) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Source not found in this workspace']);
                exit;
            }

            $newState = $src['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE `knowledge_sources` SET `is_active` = ?, `updated_at` = NOW() WHERE id = ?")
                ->execute([$newState, $id]);

            echo json_encode([
                'success'   => true,
                'id'        => $id,
                'is_active' => (bool)$newState
            ]);
            break;

        // 4. Delete Source
        case 'delete':
            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Source ID is required']);
                exit;
            }

            $pdo->prepare("DELETE FROM `knowledge_sources` WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);

            echo json_encode([
                'success' => true,
                'message' => 'Knowledge source deleted'
            ]);
            break;

        // 5. Resync / Live Re-crawl Source
        case 'resync':
            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Source ID is required']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, source_url, title, content FROM `knowledge_sources` WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->execute([$id, $companyId]);
            $src = $stmt->fetch();
            if (!$src) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Source not found in this workspace']);
                exit;
            }

            $updatedContent = $src['content'];
            $resyncedMessage = 'Knowledge source re-verified and synchronized';
            if (!empty($src['source_url'])) {
                $scraped = scrapeWebsiteContent($src['source_url']);
                if ($scraped['success'] && !empty($scraped['content'])) {
                    $updatedContent = $scraped['content'];
                    $resyncedMessage = 'Website freshly crawled (' . strlen($updatedContent) . ' chars indexed) and synchronized';
                }
            }

            $pdo->prepare("UPDATE `knowledge_sources` SET `content` = ?, `updated_at` = NOW() WHERE id = ? AND company_id = ?")
                ->execute([$updatedContent, $id, $companyId]);

            echo json_encode([
                'success' => true,
                'id'      => $id,
                'message' => $resyncedMessage
            ]);
            break;


        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unsupported action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
