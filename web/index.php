<?php
$baseDir = __DIR__ . '/files';

// Security: prevent path traversal
$path = realpath($baseDir . '/' . ($_GET['dir'] ?? ''));
if ($path === false || !str_starts_with($path, $baseDir)) {
    $path = $baseDir;
}

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

<link rel="stylesheet" href="assets/main.css">
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
            <a href="?dir=<?= urlencode(trim($parentDir, '/')) ?>" class="nav-up" aria-label="Go to parent directory">[Up]</a>
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
        </tr>
        </thead>
        <tbody>
<?php
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $filePath = $path . '/' . $file;
    $urlPath = '?dir=' . urlencode(trim(str_replace($baseDir, '', $filePath), '/'));

    $isDir = is_dir($filePath);
    $type = $isDir ? 'DIR' : pathinfo($file, PATHINFO_EXTENSION);
    $size = $isDir ? '—' : formatSize(filesize($filePath));
    $modified = date("Y-m-d H:i:s", filemtime($filePath));

    if ($isDir) {
        echo "<tr class='dir'><td><a href='$urlPath' aria-label='Browse directory: $file'>[DIR] " . htmlspecialchars($file) . "</a></td><td>$type</td><td>$size</td><td>$modified</td></tr>";
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
        echo "<tr class='file'><td><a href='download.php?file=" . urlencode($filePath) . "' aria-label='Download or view file: $file'>$icon " . htmlspecialchars($file) . "</a></td><td>$type</td><td>$size</td><td>$modified</td></tr>";
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