<?php

/**
 * Enhanced File Upload Handler
 * Provides secure, robust file upload functionality with comprehensive validation
 *
 * Features:
 * - Advanced security validation
 * - File type and content validation
 * - Comprehensive error handling
 * - Upload progress tracking
 * - Duplicate detection with hash comparison
 * - Detailed response metadata
 * - Logging capabilities
 */

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);  // Don't display errors in JSON response

// Initialize response structure
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'errors' => [],
    'timestamp' => date('c'),
    'request_id' => uniqid('req_', true)
];

// Configuration
$baseDir = __DIR__ . '/files';
$logFile = __DIR__ . '/logs/upload.log';

// Ensure logs directory exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * Log function for debugging and monitoring
 */
function logMessage($message, $level = 'INFO')
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Send JSON response and exit
 */
function sendResponse($response, $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validate file extension against allowed types
 */
function validateFileExtension($filename, $allowedExtensions = null)
{
    if ($allowedExtensions === null) {
        // Default allowed extensions (can be configured)
        $allowedExtensions = [
            'txt', 'md', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff',
            'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a',
            'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm',
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
            'js', 'css', 'html', 'php', 'py', 'java', 'cpp', 'c', 'h', 'rb', 'go', 'rs', 'ts', 'jsx', 'tsx',
            'json', 'xml', 'csv', 'yml', 'yaml', 'ini', 'conf', 'cfg', 'toml',
            'sql', 'sh', 'bat', 'ps1', 'dockerfile', 'gitignore'
        ];
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions);
}

/**
 * Validate MIME type
 */
function validateMimeType($filePath, $filename)
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    // Get expected MIME type from extension
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $expectedMimeTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'svg' => ['image/svg+xml'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'json' => ['application/json', 'text/plain'],
        'xml' => ['application/xml', 'text/xml'],
        'zip' => ['application/zip'],
        'mp3' => ['audio/mpeg'],
        'mp4' => ['video/mp4'],
        'js' => ['application/javascript', 'text/javascript'],
        'css' => ['text/css'],
        'html' => ['text/html']
    ];

    if (isset($expectedMimeTypes[$extension])) {
        return in_array($mimeType, $expectedMimeTypes[$extension]);
    }

    // For unknown extensions, allow common safe types
    $safeMimeTypes = [
        'text/', 'image/', 'audio/', 'video/', 'application/json', 'application/pdf'
    ];

    foreach ($safeMimeTypes as $safeType) {
        if (str_starts_with($mimeType, $safeType)) {
            return true;
        }
    }

    return false;
}

/**
 * Sanitize filename
 */
function sanitizeFilename($filename)
{
    // Remove or replace dangerous characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    $filename = preg_replace('/_{2,}/', '_', $filename);  // Replace multiple underscores with single
    $filename = trim($filename, '._-');  // Remove leading/trailing dots, underscores, dashes

    // Ensure filename isn't empty
    if (empty($filename)) {
        $filename = 'uploaded_file_' . time();
    }

    return $filename;
}

// Log the request
logMessage('Upload request started - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Validate HTTP method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Method not allowed. Use POST.';
    $response['errors'][] = 'Invalid HTTP method';
    logMessage('Invalid HTTP method: ' . $_SERVER['REQUEST_METHOD'], 'WARNING');
    sendResponse($response, 405);
}

// Load configuration
$config = [];
if (file_exists(__DIR__ . '/config.php')) {
    $config = include __DIR__ . '/config.php';
} else {
    $response['message'] = 'Configuration file not found.';
    $response['errors'][] = 'Missing config.php';
    logMessage('Configuration file not found', 'ERROR');
    sendResponse($response, 500);
}

// Validate security key
$requiredKey = $config['SEC_KEY'] ?? null;
$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';

if (empty($requiredKey)) {
    $response['message'] = 'Server configuration error: SEC_KEY not set.';
    $response['errors'][] = 'Missing security key configuration';
    logMessage('SEC_KEY not configured', 'ERROR');
    sendResponse($response, 500);
}

if ($providedKey !== $requiredKey) {
    $response['message'] = 'Unauthorized: Invalid or missing key.';
    $response['errors'][] = 'Invalid security key';
    logMessage('Invalid security key provided', 'WARNING');
    sendResponse($response, 401);
}

// Validate and sanitize subdirectory
$subdir = $_POST['subdir'] ?? '';
$subdir = str_replace(['..', './', '\\', '../', '..\\'], '', $subdir);  // Prevent path traversal
$subdir = preg_replace('/[^a-zA-Z0-9._-]/', '_', $subdir);  // Only allow safe characters
$subdir = trim($subdir, '/._-');  // Remove leading/trailing unsafe characters

// Build target directory path
if (!empty($subdir)) {
    $targetDir = $baseDir . '/' . $subdir;
} else {
    $targetDir = $baseDir;
}

// Security check: ensure the target path is within baseDir
$realBaseDir = realpath($baseDir);
$realTargetDir = realpath($targetDir);

if ($realTargetDir === false || !str_starts_with($realTargetDir, $realBaseDir)) {
    $targetDir = $baseDir;
    $subdir = '';
}

// Ensure directory exists
if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        $response['message'] = 'Failed to create target directory.';
        $response['errors'][] = 'Directory creation failed';
        logMessage("Failed to create directory: $targetDir", 'ERROR');
        sendResponse($response, 500);
    }
    logMessage("Created directory: $targetDir");
}

// Validate file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];

    $response['message'] = $errorMessages[$uploadError] ?? 'Unknown upload error';
    $response['errors'][] = "Upload error code: $uploadError";
    logMessage('Upload error: ' . ($errorMessages[$uploadError] ?? 'Unknown'), 'ERROR');
    sendResponse($response, 400);
}

$file = $_FILES['file'];
$originalFilename = $file['name'];
$fileSize = $file['size'];
$tmpPath = $file['tmp_name'];

// Validate file size
$maxFileSize = $config['max_file_size'] ?? (100 * 1024 * 1024);  // Default 100MB
if ($fileSize > $maxFileSize) {
    $response['message'] = 'File too large. Maximum size: ' . round($maxFileSize / (1024 * 1024), 2) . 'MB';
    $response['errors'][] = 'File size exceeds limit';
    logMessage('File too large: ' . round($fileSize / (1024 * 1024), 2) . 'MB', 'WARNING');
    sendResponse($response, 413);
}

// Validate file extension
if (!validateFileExtension($originalFilename)) {
    $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $response['message'] = 'File type not allowed. Extension: ' . $extension;
    $response['errors'][] = 'Invalid file extension';
    logMessage("Invalid file extension: $extension", 'WARNING');
    sendResponse($response, 415);
}

// Validate MIME type
if (!validateMimeType($tmpPath, $originalFilename)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    $response['message'] = 'File type validation failed. Detected MIME type: ' . $mimeType;
    $response['errors'][] = 'MIME type validation failed';
    logMessage("MIME type validation failed: $mimeType", 'WARNING');
    sendResponse($response, 415);
}

// Sanitize filename
$sanitizedFilename = sanitizeFilename($originalFilename);
$destination = $targetDir . '/' . $sanitizedFilename;

// Check if file already exists and handle duplicates
if (file_exists($destination)) {
    // Calculate hash of uploaded file
    $uploadedFileHash = hash_file('sha256', $tmpPath);

    // Calculate hash of existing file
    $existingFileHash = hash_file('sha256', $destination);

    // If hashes are the same, return success with existing file info
    if ($uploadedFileHash === $existingFileHash) {
        $response['success'] = true;
        $response['message'] = 'File already exists with identical content.';
        $response['data'] = [
            'name' => basename($destination),
            'original_name' => $originalFilename,
            'size' => filesize($destination),
            'path' => str_replace(__DIR__, '', $destination),
            'sha256' => $uploadedFileHash,
            'duplicate' => true,
            'created_at' => date('c', filectime($destination)),
            'modified_at' => date('c', filemtime($destination))
        ];
        logMessage("Duplicate file detected: $sanitizedFilename");
        sendResponse($response, 200);
    }

    // If hashes differ, generate unique filename
    $pathInfo = pathinfo($sanitizedFilename);
    $timestamp = time();
    $randomSuffix = substr(md5(uniqid()), 0, 8);

    $newFilename = $pathInfo['filename'] . '_' . $timestamp . '_' . $randomSuffix;
    if (isset($pathInfo['extension'])) {
        $newFilename .= '.' . $pathInfo['extension'];
    }

    $destination = $targetDir . '/' . $newFilename;
    logMessage("File name collision resolved: $sanitizedFilename -> $newFilename");
}

// Move uploaded file
if (move_uploaded_file($tmpPath, $destination)) {
    // Calculate file hash
    $fileHash = hash_file('sha256', $destination);

    // Get file metadata
    $fileInfo = [
        'name' => basename($destination),
        'original_name' => $originalFilename,
        'size' => filesize($destination),
        'path' => str_replace(__DIR__, '', $destination),
        'sha256' => $fileHash,
        'mime_type' => mime_content_type($destination),
        'created_at' => date('c'),
        'modified_at' => date('c', filemtime($destination)),
        'subdirectory' => $subdir
    ];

    $response['success'] = true;
    $response['message'] = 'File uploaded successfully.';
    $response['data'] = $fileInfo;

    logMessage('File uploaded successfully: ' . basename($destination) . ' (' . round($fileSize / 1024, 2) . 'KB)');
} else {
    $response['message'] = 'Failed to move uploaded file.';
    $response['errors'][] = 'File move operation failed';
    logMessage("Failed to move uploaded file to: $destination", 'ERROR');
    sendResponse($response, 500);
}

// Send successful response
sendResponse($response, 200);
