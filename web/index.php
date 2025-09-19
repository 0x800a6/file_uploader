<?php
$baseDir = __DIR__ . '/files';

// Get the requested path from URL
$requestPath = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestPath, PHP_URL_PATH);
$requestPath = trim($requestPath, '/');

// If there's still a 'dir' query parameter, use it (for backwards compatibility)
if (isset($_GET['dir'])) {
    $requestedDir = $_GET['dir'];
} else {
    $requestedDir = $requestPath;
}

// Security: prevent path traversal
$path = realpath($baseDir . '/' . $requestedDir);
if ($path === false || !str_starts_with($path, $baseDir)) {
    $path = $baseDir;
}

// Helper function to detect browser requests
function isBrowserRequest() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($accept, 'text/html') !== false || stripos($userAgent, 'Mozilla') !== false;
}

// If the requested path is a file, serve it directly
if (is_file($path)) {
    $mimeType = mime_content_type($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (isBrowserRequest()) {
        if (str_starts_with($mimeType, 'image/')) {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif (str_starts_with($mimeType, 'video/')) {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif (str_starts_with($mimeType, 'audio/')) {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif ($mimeType === 'application/pdf') {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif ($mimeType === 'image/svg+xml') {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif (in_array($ext, ['json', 'xml', 'js', 'css', 'csv', 'md', 'yml', 'yaml', 'php', 'py', 'java', 'cpp', 'c', 'h', 'rb', 'go', 'rs', 'ts', 'jsx', 'tsx', 'vue', 'svelte', 'sql', 'sh', 'bat', 'ps1', 'dockerfile', 'gitignore', 'log', 'ini', 'conf', 'cfg', 'toml'])) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif ($mimeType === 'text/plain' || $mimeType === 'text/html' || str_starts_with($mimeType, 'text/')) {
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } elseif ($mimeType === 'text/csv' || $mimeType === 'application/csv') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            readfile($path);
        } else {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        }
    } else {
        // Not a browser, force download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
    exit;
}

// If we reach here, it's a directory - continue with the normal directory listing

$files = scandir($path);
$parentDir = dirname(str_replace($baseDir, '', $path));

// Helper function to format file sizes
function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    elseif ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    elseif ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    else return round($bytes / 1073741824, 2) . ' GB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
$currentPath = str_replace($baseDir, '', $path);
$currentPath = trim($currentPath, '/');
$pageTitle = empty($currentPath) ? "Lexi's File Hosting - Secure File Storage & Sharing" : "Files in /{$currentPath} - Lexi's File Hosting";
$fileCount = count(array_filter($files, function($f) { return $f !== '.' && $f !== '..'; }));
$description = empty($currentPath) 
    ? "Secure file hosting and sharing service by Lexi. Browse, upload, and download files with ease. Fast, reliable, and user-friendly file storage solution."
    : "Browse {$fileCount} files and folders in /{$currentPath}. Secure file hosting with instant preview and download capabilities.";
?>
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($description) ?>">
<meta name="keywords" content="file hosting, file sharing, file storage, file upload, file download, secure storage, cloud storage, file browser">
<meta name="author" content="Lexi">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($description) ?>">
<meta property="og:url" content="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
<meta property="og:site_name" content="Lexi's File Hosting">

<!-- Twitter -->
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">

<link rel="stylesheet" href="/assets/main.css">
</head>
<body>
<header>
    <h1>File Hosting</h1>
    <?php if (!empty($currentPath)): ?>
        <h2>Directory: /<?= htmlspecialchars($currentPath) ?></h2>
    <?php endif; ?>
</header>

<main>
    <nav class="breadcrumb" aria-label="Directory navigation">
        <?php if ($path !== $baseDir): ?>
            <?php 
            $parentPath = trim($parentDir, '/');
            $parentUrl = empty($parentPath) ? '/' : '/' . $parentPath;
            ?>
            <a href="<?= htmlspecialchars($parentUrl) ?>" class="nav-up" aria-label="Go to parent directory">[Up]</a>
        <?php endif; ?>
    </nav>

    <section class="file-listing" aria-labelledby="files-heading">
        <h3 id="files-heading" class="sr-only">File and Directory Listing</h3>
        <table role="table" aria-label="Files and directories">
        <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Type</th>
            <th scope="col">Size</th>
            <th scope="col">Last Modified</th>
            <th scope="col">SHA-256</th>
        </tr>
        </thead>
        <tbody>
<?php
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $filePath = $path . '/' . $file;
    
    $isDir = is_dir($filePath);
    $type = $isDir ? 'DIR' : pathinfo($file, PATHINFO_EXTENSION);
    $size = $isDir ? '—' : formatSize(filesize($filePath));
    $modified = date("Y-m-d H:i:s", filemtime($filePath));

    if ($isDir) {
        // Generate clean URL for directories
        $relativePath = trim(str_replace($baseDir, '', $filePath), '/');
        $urlPath = '/' . $relativePath;
        echo "<tr class='dir'><td><a href='" . htmlspecialchars($urlPath) . "' aria-label='Browse directory: $file'>[DIR] " . htmlspecialchars($file) . "</a></td><td>$type</td><td>$size</td><td>$modified</td><td>—</td></tr>";
    } else {
        $fileExt = strtolower($type);
        $icon = match($fileExt) {
            'pdf' => '[PDF]',
            'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp' => '[IMG]',
            'mp3', 'wav', 'ogg', 'flac' => '[AUD]',
            'mp4', 'avi', 'mkv', 'mov' => '[VID]',
            'zip', 'rar', '7z', 'tar', 'gz' => '[ARC]',
            'txt', 'md' => '[TXT]',
            'js', 'php', 'py', 'html', 'css' => '[CODE]',
            'exe', 'bin' => '[EXE]',
            'doc', 'docx' => '[DOC]',
            default => '[FILE]'
        };
        // Calculate SHA-256 hash for files
        $sha256 = hash_file('sha256', $filePath);
        $shortSha = substr($sha256, 0, 16) . '...'; // Display first 16 chars with ellipsis
        
        // Generate clean URL for files
        $relativePath = trim(str_replace($baseDir, '', $filePath), '/');
        $fileUrl = '/' . $relativePath;
        echo "<tr class='file'><td><a href='" . htmlspecialchars($fileUrl) . "' aria-label='Download or view file: $file'>$icon " . htmlspecialchars($file) . "</a></td><td>$type</td><td>$size</td><td>$modified</td><td title='" . htmlspecialchars($sha256) . "' class='sha-hash'>" . htmlspecialchars($shortSha) . "</td></tr>";
    }
}
?>
        </tbody>
        </table>
    </section>
</main>

<footer>
    <p>
        &copy; <?= date('Y') ?> File server by <a href="https://www.0x800a6.dev" rel="author">Lexi</a> | 
        <a href="https://github.com/0x800a6/file_uploader" rel="external noopener">Open Source on GitHub</a>
    </p>
    <p class="file-stats">
        <?php 
        $dirCount = count(array_filter($files, function($f) use ($path) { 
            return $f !== '.' && $f !== '..' && is_dir($path . '/' . $f); 
        }));
        $fileCount = count(array_filter($files, function($f) use ($path) { 
            return $f !== '.' && $f !== '..' && !is_dir($path . '/' . $f); 
        }));
        echo "$dirCount directories, $fileCount files";
        ?>
    </p>
</footer>

<!-- JSON-LD Structured Data -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "Lexi's File Hosting",
    "description": "Secure file hosting and sharing service with instant preview and download capabilities",
    "url": "<?= htmlspecialchars($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>",
    "author": {
        "@type": "Person",
        "name": "Lexi",
        "url": "https://www.0x800a6.dev"
    },
    "provider": {
        "@type": "Organization",
        "name": "Lexi's File Hosting",
        "url": "https://www.0x800a6.dev"
    },
    "potentialAction": {
        "@type": "SearchAction",
        "target": "<?= htmlspecialchars($_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI'])) ?>?dir={search_term_string}",
        "query-input": "required name=search_term_string"
    }
}
</script>
</body>
</html>