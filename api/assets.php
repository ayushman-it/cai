<?php
/**
 * CUBOIDPILOT — DIGITAL ASSETS REPOSITORY API
 * Multi-tenant isolated storage and retrieval for brochures, syllabi, fee charts,
 * curricula, PDFs, and media assets shareable via Cai AI in widget & email.
 */

header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';

$pdo = getDbConnection();

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

// Download action can be accessed by public visitors or widget users
if ($action === 'download') {
    $assetId = (int)($_GET['id'] ?? 0);
    if ($assetId <= 0) {
        http_response_code(404);
        die('Asset not found');
    }

    $stmt = $pdo->prepare("SELECT * FROM company_assets WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        http_response_code(404);
        die('Asset not found or inactive');
    }

    $filePath = __DIR__ . '/../' . ltrim($asset['file_path'], '/');
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found on server');
    }

    // Increment download counter
    try {
        $upStmt = $pdo->prepare("UPDATE company_assets SET download_count = download_count + 1 WHERE id = ?");
        $upStmt->execute([$assetId]);
    } catch (Exception $e) {}

    // Stream file
    $mimeType = $asset['file_type'] ?: 'application/octet-stream';
    $downloadName = $asset['file_name'] ?: basename($filePath);

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($filePath);
    exit;
}

// All other actions require admin authentication
header("Content-Type: application/json; charset=UTF-8");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$companyId = (int)($_SESSION['company_id'] ?? 0);

if ($companyId === 0) {
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

session_write_close();

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? $_POST;

switch ($action) {
    case 'list':
        $category = trim($_GET['category'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT * FROM company_assets WHERE company_id = ?";
        $params = [$companyId];

        if ($category !== '' && $category !== 'all') {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if ($search !== '') {
            $sql .= " AND (title LIKE ? OR keywords LIKE ? OR description LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate summary stats
        $totalDownloads = 0;
        $activeCount = 0;
        foreach ($assets as $a) {
            $totalDownloads += (int)$a['download_count'];
            if ((int)$a['is_active'] === 1) $activeCount++;
        }

        echo json_encode([
            'success' => true,
            'assets' => $assets,
            'stats' => [
                'total' => count($assets),
                'active' => $activeCount,
                'total_downloads' => $totalDownloads
            ]
        ]);
        break;

    case 'upload':
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['file']['error'] ?? 'no_file';
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File upload error (Code: ' . $errCode . ')']);
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'document');
        $description = trim($_POST['description'] ?? '');
        $keywords = trim($_POST['keywords'] ?? '');

        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Document title is required']);
            exit;
        }

        $allowedCategories = ['syllabus', 'brochure', 'fee_chart', 'curriculum', 'guide', 'document'];
        if (!in_array($category, $allowedCategories)) {
            $category = 'document';
        }

        $file = $_FILES['file'];
        $originalName = basename($file['name']);
        $fileSize = (int)$file['size'];
        $fileType = $file['type'] ?: 'application/octet-stream';

        // Size check (max 25MB)
        if ($fileSize > 25 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File size exceeds maximum allowed limit of 25MB']);
            exit;
        }

        // Extension check
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'csv'];

        if (!in_array($ext, $allowedExtensions)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid file extension. Allowed formats: PDF, DOC, DOCX, XLS, PPT, PNG, JPG, WEBP, TXT, CSV']);
            exit;
        }

        // Disallow dangerous extensions
        if (preg_match('/^(php|phtml|php3|php4|php5|php7|phps|cgi|pl|exe|sh|bat|cmd|vbs|js|html|htm)/i', $ext)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Security violation: executable file types not permitted']);
            exit;
        }

        // Target directory: assets/uploads/company_assets/comp_{company_id}/
        $uploadSubDir = 'assets/uploads/company_assets/comp_' . $companyId;
        $targetDir = __DIR__ . '/../' . $uploadSubDir;

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $uniqueName = 'asset_' . $companyId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $destination = $targetDir . '/' . $uniqueName;
        $dbPath = $uploadSubDir . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file on server']);
            exit;
        }

        // Automatically enrich keywords if empty
        if ($keywords === '') {
            $extracted = array_filter(explode(' ', strtolower($title)));
            $extracted[] = $category;
            $keywords = implode(', ', array_unique($extracted));
        }

        $stmt = $pdo->prepare("
            INSERT INTO company_assets 
            (company_id, title, category, description, keywords, file_name, file_path, file_size, file_type, download_count, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)
        ");

        $stmt->execute([
            $companyId,
            $title,
            $category,
            $description,
            $keywords,
            $originalName,
            $dbPath,
            $fileSize,
            $fileType
        ]);

        $newId = $pdo->lastInsertId();

        $getStmt = $pdo->prepare("SELECT * FROM company_assets WHERE id = ?");
        $getStmt->execute([$newId]);
        $newAsset = $getStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Asset uploaded and added to AI library successfully',
            'asset' => $newAsset
        ]);
        break;

    case 'delete':
        $assetId = (int)($data['id'] ?? 0);
        if ($assetId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT file_path FROM company_assets WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$assetId, $companyId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Asset not found']);
            exit;
        }

        // Remove disk file safely
        if (!empty($asset['file_path'])) {
            $filePath = __DIR__ . '/../' . ltrim($asset['file_path'], '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        $delStmt = $pdo->prepare("DELETE FROM company_assets WHERE id = ? AND company_id = ?");
        $delStmt->execute([$assetId, $companyId]);

        echo json_encode(['success' => true, 'message' => 'Asset deleted successfully']);
        break;

    case 'toggle_status':
        $assetId = (int)($data['id'] ?? 0);
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        if ($assetId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE company_assets SET is_active = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$isActive ? 1 : 0, $assetId, $companyId]);

        echo json_encode(['success' => true, 'is_active' => $isActive ? 1 : 0]);
        break;

    case 'update':
        $assetId = (int)($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');
        $category = trim($data['category'] ?? 'document');
        $description = trim($data['description'] ?? '');
        $keywords = trim($data['keywords'] ?? '');

        if ($assetId <= 0 || $title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid Asset ID and Title are required']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE company_assets 
            SET title = ?, category = ?, description = ?, keywords = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$title, $category, $description, $keywords, $assetId, $companyId]);

        echo json_encode(['success' => true, 'message' => 'Asset details updated successfully']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        break;
}
