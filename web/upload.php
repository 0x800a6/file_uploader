<?php
header('Content-Type: application/json');

$baseDir = __DIR__ . '/files';

$response = [
    'success' => false,
    'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Method not allowed. Use POST.';
    echo json_encode($response);
    exit;
}

// Load configuration
$config = [];
if (file_exists(__DIR__ . '/config.php')) {
    $config = include __DIR__ . '/config.php';
}


$requiredKey = $config['SEC_KEY'] ?? null;
$providedKey = $_GET['key'] ?? '';

if (empty($requiredKey)) {
    http_response_code(500);
    $response['message'] = 'Server configuration error: SEC_KEY not set.';
    echo json_encode($response);
    exit;
}

if ($providedKey !== $requiredKey) {
    http_response_code(401);
    $response['message'] = 'Unauthorized: Invalid or missing key.';
    echo json_encode($response);
    exit;
}

$subdir = $_POST['subdir'] ?? '';
$subdir = str_replace(['..', './', '\\'], '', $subdir); // prevent path traversal
$subdir = trim($subdir, '/'); // remove leading/trailing slashes

// Build target directory path
if (!empty($subdir)) {
    $targetDir = $baseDir . '/' . $subdir;
} else {
    $targetDir = $baseDir;
}

// Security check: ensure the target path is within baseDir
// Normalize the path by removing any remaining path traversal attempts
$normalizedPath = str_replace(['../', '../', '..\\'], '', $targetDir);
if (!str_starts_with($normalizedPath, $baseDir)) {
    $targetDir = $baseDir;
} else {
    $targetDir = $normalizedPath;
}

// Ensure directory exists
if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        $response['message'] = 'Failed to create target directory.';
        echo json_encode($response);
        exit;
    }
}

// Check file upload
if (!isset($_FILES['file'])) {
    $response['message'] = 'No file uploaded.';
    echo json_encode($response);
    exit;
}

$file = $_FILES['file'];
$originalFilename = basename($file['name']);
$destination = $targetDir . '/' . $originalFilename;

// Check if file already exists
if (file_exists($destination)) {
    // Calculate hash of uploaded file
    $uploadedFileHash = hash_file('sha256', $file['tmp_name']);
    
    // Calculate hash of existing file
    $existingFileHash = hash_file('sha256', $destination);
    
    // If hashes are the same, fail the upload
    if ($uploadedFileHash === $existingFileHash) {
        http_response_code(409); // Conflict status code
        $response['message'] = 'File already exists with identical content.';
        echo json_encode($response);
        exit;
    }
    
    // If hashes differ, generate a GUID and append to filename
    $pathInfo = pathinfo($originalFilename);
    $guid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $newFilename = $pathInfo['filename'] . '_' . $guid;
    if (isset($pathInfo['extension'])) {
        $newFilename .= '.' . $pathInfo['extension'];
    }
    
    $destination = $targetDir . '/' . $newFilename;
}

if (move_uploaded_file($file['tmp_name'], $destination)) {
    $response['success'] = true;
    $response['message'] = 'File uploaded successfully.';
    $response['file'] = [
        'name' => basename($destination), // Use actual filename (may include GUID)
        'original_name' => $file['name'], // Include original filename for reference
        'size' => $file['size'],
        'path' => str_replace(__DIR__, '', $destination)
    ];
} else {
    $response['message'] = 'Failed to move uploaded file.';
}

echo json_encode($response);