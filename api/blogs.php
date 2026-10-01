<?php
/**
 * CUBOIDPILOT — BLOGS & EDITORIAL CONTENT API
 * Handles public blog listing, SEO single-post retrieval, and SuperAdmin CRUD operations.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    $pdo = getDbConnection();

    // 1. PUBLIC: List Published Blogs
    if ($action === 'list') {
        $category = trim($_GET['category'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 12;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        $where = ["`status` = 'published'"];
        $params = [];

        if (!empty($category) && $category !== 'all') {
            $where[] = "`category` = ?";
            $params[] = $category;
        }

        if (!empty($search)) {
            $where[] = "(`title` LIKE ? OR `excerpt` LIKE ? OR `tags` LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `blogs` WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch items
        $stmt = $pdo->prepare("
            SELECT `id`, `slug`, `title`, `excerpt`, `cover_image`, `author_name`, `author_role`, `author_avatar`, `category`, `tags`, `views_count`, `published_at`, `created_at`
            FROM `blogs`
            WHERE {$whereClause}
            ORDER BY `published_at` DESC, `id` DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate read time for each
        foreach ($blogs as &$b) {
            $words = str_word_count(strip_tags($b['excerpt'] ?? ''));
            $b['read_time'] = max(2, ceil($words / 40)) . ' min read';
            $b['formatted_date'] = date('M d, Y', strtotime($b['published_at'] ?: $b['created_at']));
        }

        echo json_encode([
            'success' => true,
            'blogs' => $blogs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ]);
        exit;
    }

    // 2. PUBLIC: Single Blog by Slug or ID (Increments view counter)
    if ($action === 'get') {
        $slug = trim($_GET['slug'] ?? '');
        $id = (int)($_GET['id'] ?? 0);

        if (empty($slug) && $id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Article slug or ID is required']);
            exit;
        }

        if (!empty($slug)) {
            $stmt = $pdo->prepare("SELECT * FROM `blogs` WHERE `slug` = ? AND `status` = 'published' LIMIT 1");
            $stmt->execute([$slug]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `blogs` WHERE `id` = ? AND `status` = 'published' LIMIT 1");
            $stmt->execute([$id]);
        }

        $blog = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$blog) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Article not found']);
            exit;
        }

        // Increment view counter
        $pdo->prepare("UPDATE `blogs` SET `views_count` = `views_count` + 1 WHERE `id` = ?")->execute([$blog['id']]);
        $blog['views_count']++;

        $wordCount = str_word_count(strip_tags($blog['content']));
        $blog['read_time'] = max(3, ceil($wordCount / 200)) . ' min read';
        $blog['formatted_date'] = date('F d, Y', strtotime($blog['published_at'] ?: $blog['created_at']));

        // Related articles
        $relStmt = $pdo->prepare("
            SELECT `id`, `slug`, `title`, `excerpt`, `cover_image`, `category`, `published_at`
            FROM `blogs`
            WHERE `id` != ? AND `status` = 'published'
            ORDER BY `published_at` DESC
            LIMIT 3
        ");
        $relStmt->execute([$blog['id']]);
        $related = $relStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($related as &$r) {
            $r['formatted_date'] = date('M d, Y', strtotime($r['published_at']));
        }

        echo json_encode([
            'success' => true,
            'blog' => $blog,
            'related' => $related
        ]);
        exit;
    }

    // ---------------------------------------------------------
    // AUTHENTICATED ADMIN ENDPOINTS
    // ---------------------------------------------------------
    $isAdmin = false;
    if (!empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        $uStmt = $pdo->prepare("SELECT is_super_admin, role FROM `users` WHERE id = ? LIMIT 1");
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        if ($uRow && (!empty($uRow['is_super_admin']) || $uRow['role'] === 'owner' || $uRow['role'] === 'super_admin' || $userId === 1)) {
            $isAdmin = true;
        }
    } elseif (!empty($_SESSION['is_super_admin']) || !empty($_SESSION['super_admin'])) {
        $isAdmin = true;
    }

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized. Super Admin access required.']);
        exit;
    }

    // 3. ADMIN: List all blogs with stats
    if ($action === 'admin_list') {
        $totalBlogs = (int)$pdo->query("SELECT COUNT(*) FROM `blogs`")->fetchColumn();
        $publishedCount = (int)$pdo->query("SELECT COUNT(*) FROM `blogs` WHERE `status` = 'published'")->fetchColumn();
        $draftCount = (int)$pdo->query("SELECT COUNT(*) FROM `blogs` WHERE `status` = 'draft'")->fetchColumn();
        $totalViews = (int)$pdo->query("SELECT COALESCE(SUM(`views_count`), 0) FROM `blogs`")->fetchColumn();

        $stmt = $pdo->query("
            SELECT `id`, `title`, `slug`, `category`, `author_name`, `status`, `views_count`, `published_at`, `created_at`, `cover_image`
            FROM `blogs`
            ORDER BY `id` DESC
        ");
        $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blogs as &$b) {
            $b['formatted_date'] = date('M d, Y', strtotime($b['published_at'] ?: $b['created_at']));
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'total' => $totalBlogs,
                'published' => $publishedCount,
                'drafts' => $draftCount,
                'total_views' => $totalViews
            ],
            'blogs' => $blogs
        ]);
        exit;
    }

    // 4. ADMIN: Single blog edit data
    if ($action === 'admin_get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM `blogs` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$id]);
        $blog = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$blog) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Blog not found']);
            exit;
        }

        echo json_encode(['success' => true, 'blog' => $blog]);
        exit;
    }

    // 5. ADMIN: Save (Create or Update) Blog Post
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $coverImage = trim($_POST['cover_image'] ?? 'assets/blog/meet-cai-founder.png');
        $authorName = trim($_POST['author_name'] ?? 'Ayush');
        $authorRole = trim($_POST['author_role'] ?? 'Founder & AI Architect');
        $category = trim($_POST['category'] ?? 'AI & Product Updates');
        $tags = trim($_POST['tags'] ?? 'Cai, AI Agents, Helpdesk');
        $metaTitle = trim($_POST['meta_title'] ?? $title);
        $metaDescription = trim($_POST['meta_description'] ?? $excerpt);
        $status = in_array($_POST['status'] ?? '', ['published', 'draft']) ? $_POST['status'] : 'published';

        if (empty($title)) {
            echo json_encode(['success' => false, 'error' => 'Blog title is required']);
            exit;
        }

        if (empty($content)) {
            echo json_encode(['success' => false, 'error' => 'Blog content is required']);
            exit;
        }

        // Auto generate slug if empty
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        } else {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug), '-'));
        }

        // Check slug uniqueness
        $chkSlug = $pdo->prepare("SELECT id FROM `blogs` WHERE `slug` = ? AND `id` != ? LIMIT 1");
        $chkSlug->execute([$slug, $id]);
        if ($chkSlug->fetch()) {
            $slug .= '-' . rand(100, 999);
        }

        if ($id > 0) {
            // Update
            $sql = "UPDATE `blogs` SET 
                `title` = ?, `slug` = ?, `excerpt` = ?, `content` = ?, `cover_image` = ?,
                `author_name` = ?, `author_role` = ?, `category` = ?, `tags` = ?,
                `meta_title` = ?, `meta_description` = ?, `status` = ?,
                `published_at` = CASE WHEN `status` = 'published' AND `published_at` IS NULL THEN NOW() ELSE `published_at` END
                WHERE `id` = ?";
            $pdo->prepare($sql)->execute([
                $title, $slug, $excerpt, $content, $coverImage,
                $authorName, $authorRole, $category, $tags,
                $metaTitle, $metaDescription, $status, $id
            ]);
            $blogId = $id;
        } else {
            // Insert
            $sql = "INSERT INTO `blogs` 
                (`company_id`, `title`, `slug`, `excerpt`, `content`, `cover_image`, `author_name`, `author_role`, `category`, `tags`, `meta_title`, `meta_description`, `status`, `views_count`, `published_at`, `created_at`)
                VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())";
            $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s') : null;
            $pdo->prepare($sql)->execute([
                $title, $slug, $excerpt, $content, $coverImage,
                $authorName, $authorRole, $category, $tags,
                $metaTitle, $metaDescription, $status, $publishedAt
            ]);
            $blogId = (int)$pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Blog post saved successfully',
            'blog_id' => $blogId,
            'slug' => $slug
        ]);
        exit;
    }

    // 6. ADMIN: Delete Blog Post
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid blog ID']);
            exit;
        }

        $pdo->prepare("DELETE FROM `blogs` WHERE `id` = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Blog deleted successfully']);
        exit;
    }

    // 7. ADMIN: Upload Cover Image
    if ($action === 'upload_cover') {
        if (empty($_FILES['cover_file']) || $_FILES['cover_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'No image uploaded or upload error']);
            exit;
        }

        $file = $_FILES['cover_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid image format. Supported: PNG, JPG, WEBP, SVG']);
            exit;
        }

        $uploadDir = __DIR__ . '/../assets/blog/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $fileName = 'blog_' . time() . '_' . substr(md5(uniqid()), 0, 8) . '.' . $ext;
        $destPath = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $webPath = 'assets/blog/' . $fileName;
            echo json_encode([
                'success' => true,
                'image_url' => $webPath,
                'message' => 'Image uploaded successfully'
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded image']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}