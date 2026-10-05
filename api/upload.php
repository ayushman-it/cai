<?php
/**
 * CUBOIDPILOT — CHAT ATTACHMENTS & MEDIA UPLOAD API
 * Handles file and media uploads from the embeddable chat widget with security checks.
 */

error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Company-Key");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/db.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['file']['error'] ?? 'missing';
        $errorMsg = 'No file uploaded or upload error occurred.';
        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            $errorMsg = 'File exceeds maximum upload limit (15MB).';
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit;
    }

    $file = $_FILES['file'];
    $originalName = basename($file['name']);
    $fileSize = (int)$file['size'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // 15MB limit
    if ($fileSize > 15 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File size exceeds maximum allowed limit of 15MB.']);
        exit;
    }

    $allowedExts = [
        // Images (SVG removed to prevent Stored XSS attacks)
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        // Documents
        'pdf', 'doc', 'docx', 'txt', 'csv', 'xlsx', 'xls', 'rtf'
    ];

    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File format .' . htmlspecialchars($ext) . ' is not supported. Allowed formats: PNG, JPG, WEBP, GIF, PDF, DOC, DOCX, TXT, CSV.']);
        exit;
    }

    // Verify MIME type using Fileinfo
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain', 'text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/rtf', 'text/rtf'
        ];

        if (!in_array($mime, $allowedMimes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Uploaded file type does not match its extension. Upload rejected.']);
            exit;
        }
    }

    $uploadDir = __DIR__ . '/../assets/uploads/attachments/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $safeHash = substr(bin2hex(random_bytes(8)), 0, 10);
    $savedFilename = 'att_' . time() . '_' . $safeHash . '.' . $ext;
    $targetPath = $uploadDir . $savedFilename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded attachment on server.']);
        exit;
    }

    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
    $webPath = 'assets/uploads/attachments/' . $savedFilename;

    echo json_encode([
        'success' => true,
        'file_url' => $webPath,
        'file_name' => $originalName,
        'file_size' => $fileSize,
        'file_ext' => $ext,
        'is_image' => $isImage,
        'message' => 'Attachment uploaded successfully'
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Upload failed: ' . $e->getMessage()]);
    exit;
}
