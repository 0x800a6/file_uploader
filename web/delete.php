<?php

/**
 * Enhanced File Delete Handler
 * Provides secure file and directory deletion functionality
 *
 * Features:
 * - Security key validation
 * - Comprehensive error handling
 * - Safe path validation
 * - Directory recursive deletion
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
$logFile = __DIR__ . '/logs/delete.log';

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
 * Recursively delete directory and its contents
 */
function deleteDirectory($dir)
{
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), ['.', '..']);

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

// Log the request
logMessage('Delete request started - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

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
$providedKey = $_POST['key'] ?? '';

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

// Validate required parameters
$filename = $_POST['filename'] ?? '';
$isDir = ($_POST['is_dir'] ?? '0') === '1';

if (empty($filename)) {
    $response['message'] = 'Filename is required.';
    $response['errors'][] = 'Missing filename parameter';
    logMessage('Missing filename parameter', 'WARNING');
    sendResponse($response, 400);
}

// Sanitize filename to prevent path traversal
$filename = basename($filename);
$targetPath = $baseDir . '/' . $filename;

// Security check: ensure the target path is within baseDir
$realBaseDir = realpath($baseDir);
$realTargetPath = realpath($targetPath);

if ($realTargetPath === false || !str_starts_with($realTargetPath, $realBaseDir)) {
    $response['message'] = 'Invalid file path.';
    $response['errors'][] = 'Path traversal attempt detected';
    logMessage("Path traversal attempt: $filename", 'WARNING');
    sendResponse($response, 400);
}

// Check if file/directory exists
if (!file_exists($targetPath)) {
    $response['message'] = 'File or directory not found.';
    $response['errors'][] = 'Target does not exist';
    logMessage("Target not found: $filename", 'WARNING');
    sendResponse($response, 404);
}

// Verify it's actually a directory if isDir is true
if ($isDir && !is_dir($targetPath)) {
    $response['message'] = 'Specified path is not a directory.';
    $response['errors'][] = 'Type mismatch: expected directory';
    logMessage("Type mismatch for directory: $filename", 'WARNING');
    sendResponse($response, 400);
}

// Verify it's actually a file if isDir is false
if (!$isDir && !is_file($targetPath)) {
    $response['message'] = 'Specified path is not a file.';
    $response['errors'][] = 'Type mismatch: expected file';
    logMessage("Type mismatch for file: $filename", 'WARNING');
    sendResponse($response, 400);
}

// Perform deletion
$success = false;
$deletedType = $isDir ? 'directory' : 'file';

if ($isDir) {
    $success = deleteDirectory($targetPath);
    logMessage("Directory deletion attempt: $filename - " . ($success ? 'SUCCESS' : 'FAILED'));
} else {
    $success = unlink($targetPath);
    logMessage("File deletion attempt: $filename - " . ($success ? 'SUCCESS' : 'FAILED'));
}

if ($success) {
    $response['success'] = true;
    $response['message'] = ucfirst($deletedType) . ' deleted successfully.';
    $response['data'] = [
        'filename' => $filename,
        'type' => $deletedType,
        'deleted_at' => date('c')
    ];

    logMessage("Successfully deleted $deletedType: $filename");
} else {
    $response['message'] = 'Failed to delete ' . $deletedType . '.';
    $response['errors'][] = 'Deletion operation failed';
    logMessage("Failed to delete $deletedType: $filename", 'ERROR');
    sendResponse($response, 500);
}

// Send successful response
sendResponse($response, 200);
