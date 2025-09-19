<?php
$file = $_GET['file'] ?? '';
$filePath = realpath($file);
$baseDir = __DIR__ . '/files';

function isBrowserRequest() {
    // Check for common browser headers
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($accept, 'text/html') !== false || stripos($userAgent, 'Mozilla') !== false;
}

if ($filePath && str_starts_with($filePath, $baseDir) && is_file($filePath)) {
    $mimeType = mime_content_type($filePath);
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if (isBrowserRequest()) {
        if (str_starts_with($mimeType, 'image/')) {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif (str_starts_with($mimeType, 'video/')) {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif (str_starts_with($mimeType, 'audio/')) {
            // Audio files - MP3, WAV, OGG, etc.
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif ($mimeType === 'application/pdf') {
            // PDF files
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif ($mimeType === 'image/svg+xml') {
            // SVG files
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif (in_array($ext, ['json', 'xml', 'js', 'css', 'csv', 'md', 'yml', 'yaml', 'php', 'py', 'java', 'cpp', 'c', 'h', 'rb', 'go', 'rs', 'ts', 'jsx', 'tsx', 'vue', 'svelte', 'sql', 'sh', 'bat', 'ps1', 'dockerfile', 'gitignore', 'log', 'ini', 'conf', 'cfg', 'toml'])) {
            // Code and configuration files - display as text
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif ($mimeType === 'text/plain' || $mimeType === 'text/html' || str_starts_with($mimeType, 'text/')) {
            // Text files
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } elseif ($mimeType === 'text/csv' || $mimeType === 'application/csv') {
            // CSV files
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
        } else {
            // Default to download for other types
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
        }
    } else {
        // Not a browser, force download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
    }
    exit;
} else {
    echo "Invalid file.";
}