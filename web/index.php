<?php
// Load configuration
$config = [];
if (file_exists(__DIR__ . '/config.php')) {
    $config = include __DIR__ . '/config.php';
}
$baseDir = $config['upload_dir'] ?? __DIR__ . '/files';

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

// Get query parameters for enhanced functionality
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'asc';
$view = $_GET['view'] ?? 'table';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;  // Items per page

// Helper function to detect browser requests
function isBrowserRequest()
{
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
function formatSize($bytes)
{
    if ($bytes < 1024)
        return $bytes . ' B';
    elseif ($bytes < 1048576)
        return round($bytes / 1024, 2) . ' KB';
    elseif ($bytes < 1073741824)
        return round($bytes / 1048576, 2) . ' MB';
    else
        return round($bytes / 1073741824, 2) . ' GB';
}

// Enhanced helper functions
function getFileIcon($filename, $isDir = false)
{
    if ($isDir)
        return '[DIR]';

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => '[PDF]',
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff' => '[IMG]',
        'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a' => '[AUD]',
        'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm' => '[VID]',
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz' => '[ARC]',
        'txt', 'md', 'rtf' => '[TXT]',
        'js', 'php', 'py', 'html', 'css', 'cpp', 'c', 'java', 'rb', 'go', 'rs' => '[CODE]',
        'exe', 'bin', 'msi', 'deb', 'rpm' => '[EXE]',
        'doc', 'docx', 'odt' => '[DOC]',
        'xls', 'xlsx', 'ods' => '[XLS]',
        'ppt', 'pptx', 'odp' => '[PPT]',
        'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'cfg' => '[CONF]',
        'sql', 'db', 'sqlite' => '[DB]',
        'iso', 'img' => '[ISO]',
        default => '[FILE]'
    };
}

function getFileType($filename, $isDir = false)
{
    if ($isDir)
        return 'DIR';
    return strtoupper(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'FILE';
}

function buildUrl($params = [])
{
    $currentParams = $_GET;
    $currentParams = array_merge($currentParams, $params);
    return '?' . http_build_query($currentParams);
}

function formatDate($timestamp)
{
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 60)
        return 'just now';
    if ($diff < 3600)
        return floor($diff / 60) . 'm ago';
    if ($diff < 86400)
        return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000)
        return floor($diff / 86400) . 'd ago';

    return date('Y-m-d H:i', $timestamp);
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
$fileCount = count(array_filter($files, function ($f) {
    return $f !== '.' && $f !== '..';
}));
$description = empty($currentPath)
    ? 'Secure file hosting and sharing service by Lexi. Browse, upload, and download files with ease. Fast, reliable, and user-friendly file storage solution.'
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
<style>
/* Enhanced styles for better UX while maintaining retro theme */
.controls {
    background: #f0f0f0;
    border: 2px solid #000080;
    padding: 10px;
    margin: 10px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.search-box {
    flex: 1;
    min-width: 200px;
    padding: 5px;
    border: 1px solid #000080;
    font-family: "Courier New", Courier, monospace;
    background: white;
}

.sort-controls, .view-controls {
    display: flex;
    gap: 5px;
    align-items: center;
}

.sort-controls select, .view-controls select {
    padding: 5px;
    border: 1px solid #000080;
    font-family: "Courier New", Courier, monospace;
    background: white;
}


.pagination {
    text-align: center;
    margin: 20px 0;
}

.pagination a, .pagination span {
    display: inline-block;
    padding: 5px 10px;
    margin: 0 2px;
    border: 1px solid #000080;
    text-decoration: none;
    color: #0000ff;
    background: white;
}

.pagination a:hover {
    background: #000080;
    color: white;
}

.pagination .current {
    background: #000080;
    color: white;
}

.file-stats {
    background: #f0f0f0;
    border: 1px solid #000080;
    padding: 10px;
    margin: 10px 0;
    font-size: 0.9em;
}

.upload-area {
    border: 2px solid #000080;
    margin: 10px 0;
    background: #f8f8f8;
    border-radius: 5px;
    overflow: hidden;
}

.upload-header {
    background: #000080;
    color: white;
    padding: 10px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.upload-header h3 {
    margin: 0;
    font-size: 14px;
    font-family: "Courier New", Courier, monospace;
}

.close-btn {
    background: none;
    border: none;
    color: white;
    font-size: 18px;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 3px;
}

.upload-main {
    padding: 20px;
}

.drop-zone {
    border: 2px dashed #000080;
    border-radius: 5px;
    padding: 30px;
    text-align: center;
    background: white;
    transition: all 0.3s ease;
    cursor: pointer;
}

.drop-zone:hover {
    background: #f0f8ff;
    border-color: #0066cc;
}

.drop-zone.dragover {
    background: #e8f4f8;
    border-color: #ff0000;
    transform: scale(1.02);
}

.drop-zone-content {
    pointer-events: none;
}

.drop-icon {
    font-size: 48px;
    margin-bottom: 10px;
}

.drop-text {
    font-size: 16px;
    margin: 10px 0;
    color: #000080;
}

.drop-subtext {
    font-size: 12px;
    color: #666;
    margin: 5px 0 15px 0;
}

.browse-btn {
    background: #000080;
    color: white;
    border: none;
    padding: 10px 20px;
    font-family: "Courier New", Courier, monospace;
    cursor: pointer;
    border-radius: 3px;
    pointer-events: all;
}

.browse-btn:hover {
    background: #0066cc;
}

.upload-settings {
    margin: 20px 0;
    padding: 15px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.setting-group {
    margin-bottom: 15px;
}

.setting-label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    font-size: 12px;
}

.label-text {
    color: #000080;
}

.required {
    color: #ff0000;
    margin-left: 3px;
}

.optional {
    color: #666;
    font-weight: normal;
    margin-left: 3px;
}

.setting-input {
    width: 100%;
    max-width: 300px;
    padding: 8px;
    border: 1px solid #000080;
    font-family: "Courier New", Courier, monospace;
    background: white;
    border-radius: 3px;
}

.setting-input:focus {
    outline: none;
    border-color: #0066cc;
    box-shadow: 0 0 5px rgba(0, 102, 204, 0.3);
}

.input-help {
    font-size: 10px;
    color: #666;
    margin-top: 3px;
}

.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-size: 12px;
}

.checkbox-label input[type="checkbox"] {
    margin-right: 8px;
    transform: scale(1.2);
}

.file-list {
    margin: 20px 0;
    padding: 15px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.file-list h4 {
    margin: 0 0 15px 0;
    font-size: 14px;
    color: #000080;
    font-family: "Courier New", Courier, monospace;
}

.file-items {
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 15px;
}

.file-item {
    display: flex;
    align-items: center;
    padding: 8px;
    border-bottom: 1px solid #eee;
    font-size: 12px;
}

.file-item:last-child {
    border-bottom: none;
}

.file-icon {
    margin-right: 8px;
    font-size: 16px;
}

.file-info {
    flex: 1;
}

.file-name {
    font-weight: bold;
    color: #000080;
}

.file-size {
    color: #666;
    font-size: 10px;
}

.file-status {
    margin-left: 10px;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: bold;
}

.file-status.ready {
    background: #e8f5e8;
    color: #2d5a2d;
}

.file-status.error {
    background: #ffe8e8;
    color: #cc0000;
}

.security-warning {
    font-size: 10px;
    color: #ff8800;
    margin-top: 2px;
}

.content-warning {
    font-size: 10px;
    color: #0066cc;
    margin-top: 2px;
}

.file-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.action-btn {
    padding: 8px 16px;
    border: 1px solid #000080;
    background: white;
    color: #000080;
    font-family: "Courier New", Courier, monospace;
    cursor: pointer;
    border-radius: 3px;
    font-size: 11px;
    transition: all 0.2s ease;
}

.action-btn:hover {
    background: #000080;
    color: white;
}

.action-btn.primary {
    background: #000080;
    color: white;
}

.action-btn.primary:hover {
    background: #0066cc;
}

.action-btn.secondary {
    background: #f0f0f0;
    color: #666;
    border-color: #ccc;
}

.action-btn.secondary:hover {
    background: #e0e0e0;
}

.action-btn.danger {
    background: #ff0000;
    color: white;
    border-color: #cc0000;
}

.action-btn.danger:hover {
    background: #cc0000;
}

.upload-progress {
    margin: 20px 0;
    padding: 15px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-size: 12px;
}

.progress-details {
    margin-top: 10px;
    font-size: 11px;
    color: #666;
    max-height: 100px;
    overflow-y: auto;
}

.upload-actions {
    margin-top: 15px;
    text-align: right;
}

.upload-info {
    margin-top: 20px;
    padding: 15px;
    background: #f0f8ff;
    border: 1px solid #b0d4f1;
    border-radius: 5px;
}

.info-item {
    margin-bottom: 5px;
    font-size: 11px;
    color: #000080;
}

.info-item:last-child {
    margin-bottom: 0;
}

.quick-actions {
    display: flex;
    gap: 10px;
    margin: 10px 0;
    flex-wrap: wrap;
}

.quick-actions button {
    padding: 5px 10px;
    border: 1px solid #000080;
    background: white;
    color: #0000ff;
    font-family: "Courier New", Courier, monospace;
    cursor: pointer;
}

.quick-actions button:hover {
    background: #000080;
    color: white;
}

.delete-btn {
    padding: 2px 6px;
    border: 1px solid #ff0000;
    background: white;
    color: #ff0000;
    font-family: "Courier New", Courier, monospace;
    font-size: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.delete-btn:hover {
    background: #ff0000;
    color: white;
}

.delete-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.file-info {
    font-size: 0.8em;
    color: #666;
    margin-top: 5px;
}

@media screen and (max-width: 768px) {
    .controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-box {
        min-width: auto;
    }
    
    .sort-controls, .view-controls {
        justify-content: center;
    }
    
    .quick-actions {
        justify-content: center;
    }
}

/* Print styles */
@media print {
    .controls, .quick-actions, .upload-area, .pagination {
        display: none !important;
    }
    
    body {
        background: white !important;
        color: black !important;
    }
    
    table {
        border: 1px solid black !important;
    }
    
    th, td {
        border: 1px solid black !important;
        padding: 2px !important;
    }
    
    a {
        color: black !important;
        text-decoration: underline !important;
    }
}

/* Loading animation */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #000080;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Tooltip styles */
.tooltip {
    position: relative;
    display: inline-block;
}

.tooltip .tooltiptext {
    visibility: hidden;
    width: 200px;
    background-color: #000080;
    color: white;
    text-align: center;
    border-radius: 6px;
    padding: 5px;
    position: absolute;
    z-index: 1;
    bottom: 125%;
    left: 50%;
    margin-left: -100px;
    opacity: 0;
    transition: opacity 0.3s;
    font-size: 12px;
}

.tooltip:hover .tooltiptext {
    visibility: visible;
    opacity: 1;
}

/* Enhanced file icons */
.file-icon {
    display: inline-block;
    width: 16px;
    height: 16px;
    margin-right: 5px;
    vertical-align: middle;
}

/* Progress bar for uploads */
.progress-bar {
    width: 100%;
    height: 20px;
    background-color: #f0f0f0;
    border: 1px solid #000080;
    margin: 10px 0;
}

.progress-fill {
    height: 100%;
    background-color: #000080;
    width: 0%;
    transition: width 0.3s ease;
}

/* Notification styles */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background: #000080;
    color: white;
    padding: 10px 15px;
    border-radius: 5px;
    z-index: 1000;
    font-family: "Courier New", Courier, monospace;
    font-size: 12px;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
}
</style>
</head>
<body>
<header>
    <h1>File Hosting</h1>
    <?php if (!empty($currentPath)): ?>
        <h2>Directory: /<?= htmlspecialchars($currentPath) ?></h2>
    <?php endif; ?>
</header>

<main>
    <!-- Enhanced Controls -->
    <div class="controls">
        <form method="GET" style="display: flex; flex: 1; gap: 10px; align-items: center;">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                   placeholder="Search files..." class="search-box" 
                   aria-label="Search files and directories">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($requestedDir) ?>">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <button type="submit">[SEARCH]</button>
            <?php if ($search): ?>
                <a href="<?= buildUrl(['search' => '']) ?>" class="nav-up">[CLEAR]</a>
            <?php endif; ?>
        </form>
        
        <div class="sort-controls">
            <label for="sort">Sort:</label>
            <select name="sort" id="sort" onchange="updateSort()">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
                <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>Size</option>
                <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Date</option>
                <option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>Type</option>
            </select>
            <a href="<?= buildUrl(['order' => $order === 'asc' ? 'desc' : 'asc']) ?>" 
               class="nav-up">[<?= $order === 'asc' ? '↑' : '↓' ?>]</a>
        </div>
        
        <div class="view-controls">
            <label for="view">View:</label>
            <select name="view" id="view" onchange="updateView()">
                <option value="table" <?= $view === 'table' ? 'selected' : '' ?>>Table</option>
                <option value="grid" <?= $view === 'grid' ? 'selected' : '' ?>>Grid</option>
                <option value="list" <?= $view === 'list' ? 'selected' : '' ?>>List</option>
            </select>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <button onclick="refreshPage()">[REFRESH]</button>
        <button onclick="toggleAutoRefresh()" id="autoRefreshBtn">[AUTO REFRESH: OFF]</button>
        <button onclick="selectAll()">[SELECT ALL]</button>
        <button onclick="downloadSelected()" id="downloadBtn" disabled>[DOWNLOAD]</button>
        <button onclick="downloadAllFiles()" id="downloadAllBtn">[DOWNLOAD ALL]</button>
        <button onclick="toggleUpload()">[UPLOAD]</button>
        <button onclick="window.print()">[PRINT]</button>
        <?php if ($path !== $baseDir): ?>
            <a href="<?= htmlspecialchars(empty($parentDir) ? '/' : '/' . trim($parentDir, '/')) ?>" 
               class="nav-up">[UP]</a>
        <?php endif; ?>
    </div>

    <!-- Enhanced Upload Area (initially hidden) -->
    <div id="uploadArea" class="upload-area" style="display: none;">
        <div class="upload-header">
            <h3>[FILE UPLOAD]</h3>
            <button onclick="toggleUpload()" class="close-btn" title="Close upload area">[×]</button>
        </div>
        
        <div class="upload-main">
            <div class="drop-zone" id="dropZone">
                <div class="drop-zone-content">
                    <div class="drop-icon">📁</div>
                    <p class="drop-text"><strong>[DRAG & DROP FILES HERE]</strong></p>
                    <p class="drop-subtext">or click to browse files</p>
                    <input type="file" id="fileInput" multiple style="display: none;" accept="*/*">
                    <button onclick="document.getElementById('fileInput').click()" class="browse-btn">[BROWSE FILES]</button>
                </div>
            </div>
            
            <div class="upload-settings">
                <div class="setting-group">
                    <label for="uploadKey" class="setting-label">
                        <span class="label-text">Security Key:</span>
                        <span class="required">*</span>
                    </label>
                    <input type="password" id="uploadKey" placeholder="Enter upload key..." 
                           class="setting-input" required>
                    <div class="input-help">Required for file uploads</div>
                </div>
                
                <div class="setting-group">
                    <label for="uploadSubdir" class="setting-label">
                        <span class="label-text">Subdirectory:</span>
                        <span class="optional">(optional)</span>
                    </label>
                    <input type="text" id="uploadSubdir" placeholder="subfolder" 
                           class="setting-input" pattern="[a-zA-Z0-9._-]+" title="Only letters, numbers, dots, underscores, and dashes allowed">
                    <div class="input-help">Create files in a subfolder</div>
                </div>
                
                <div class="setting-group">
                    <label class="setting-label">
                        <span class="label-text">Upload Options:</span>
                    </label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="overwriteFiles" checked>
                            <span class="checkmark"></span>
                            Overwrite existing files
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="validateFiles" checked>
                            <span class="checkmark"></span>
                            Validate file types
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="enableSecurityScan" checked>
                            <span class="checkmark"></span>
                            Enable security scanning
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="enableContentScan" checked>
                            <span class="checkmark"></span>
                            Scan file content for threats
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="file-list" id="fileList" style="display: none;">
                <h4>[SELECTED FILES]</h4>
                <div class="file-items" id="fileItems"></div>
                <div class="file-actions">
                    <button onclick="clearFileList()" class="action-btn secondary">[CLEAR ALL]</button>
                    <button onclick="startUpload()" class="action-btn primary" id="startUploadBtn">[START UPLOAD]</button>
                </div>
            </div>
            
            <div id="uploadProgress" class="upload-progress" style="display: none;">
                <div class="progress-header">
                    <span id="uploadStatus">Preparing upload...</span>
                    <span id="uploadStats">0/0 files</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="progress-details" id="progressDetails"></div>
                <div class="upload-actions">
                    <button onclick="cancelUpload()" class="action-btn danger" id="cancelBtn">[CANCEL]</button>
                </div>
            </div>
            
            <div class="upload-info">
                <div class="info-item">
                    <strong>Supported:</strong> All common file types
                </div>
                <div class="info-item">
                    <strong>Max size:</strong> 100MB per file
                </div>
                <div class="info-item">
                    <strong>Multiple files:</strong> Yes, drag & drop or browse
                </div>
                <div class="info-item">
                    <strong>Security:</strong> SHA-256 validation, MIME type checking, content scanning
                </div>
                <div class="info-item">
                    <strong>Features:</strong> Duplicate detection, progress tracking, error recovery
                </div>
            </div>
        </div>
    </div>

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
        
        <?php
        // Process and filter files
        $allFiles = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..')
                continue;

            $filePath = $path . '/' . $file;
            $isDir = is_dir($filePath);

            // Apply search filter
            if ($search && stripos($file, $search) === false)
                continue;

            $allFiles[] = [
                'name' => $file,
                'path' => $filePath,
                'isDir' => $isDir,
                'size' => $isDir ? 0 : filesize($filePath),
                'modified' => filemtime($filePath),
                'type' => getFileType($file, $isDir),
                'icon' => getFileIcon($file, $isDir)
            ];
        }

        // Sort files
        usort($allFiles, function ($a, $b) use ($sort, $order) {
            $result = 0;
            switch ($sort) {
                case 'size':
                    $result = $a['size'] <=> $b['size'];
                    break;
                case 'date':
                    $result = $a['modified'] <=> $b['modified'];
                    break;
                case 'type':
                    $result = $a['type'] <=> $b['type'];
                    break;
                default:  // name
                    $result = strcasecmp($a['name'], $b['name']);
            }
            return $order === 'desc' ? -$result : $result;
        });

        // Pagination
        $totalFiles = count($allFiles);
        $totalPages = ceil($totalFiles / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedFiles = array_slice($allFiles, $offset, $perPage);
        ?>
        
        <!-- File Statistics -->
        <div class="file-stats">
            <strong>[STATS]</strong> 
            <?php
            $dirCount = count(array_filter($allFiles, fn($f) => $f['isDir']));
            $fileCount = count(array_filter($allFiles, fn($f) => !$f['isDir']));
            $totalSize = array_sum(array_column($allFiles, 'size'));
            echo "$dirCount directories, $fileCount files, " . formatSize($totalSize) . ' total';
            ?>
            <?php if ($search): ?>
                | <strong>Search:</strong> "<?= htmlspecialchars($search) ?>" (<?= $totalFiles ?> results)
            <?php endif; ?>
        </div>
        
        <?php if ($view === 'table'): ?>
        <table role="table" aria-label="Files and directories">
        <thead>
        <tr>
            <th scope="col"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
            <th scope="col"><a href="<?= buildUrl(['sort' => 'name']) ?>">Name</a></th>
            <th scope="col"><a href="<?= buildUrl(['sort' => 'type']) ?>">Type</a></th>
            <th scope="col"><a href="<?= buildUrl(['sort' => 'size']) ?>">Size</a></th>
            <th scope="col"><a href="<?= buildUrl(['sort' => 'date']) ?>">Last Modified</a></th>
            <th scope="col">SHA-256</th>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($paginatedFiles as $file): ?>
            <tr class="<?= $file['isDir'] ? 'dir' : 'file' ?>">
                <td><input type="checkbox" class="file-checkbox" value="<?= htmlspecialchars($file['name']) ?>"></td>
                <td>
                    <?php
                    $relativePath = trim(str_replace($baseDir, '', $file['path']), '/');
                    $urlPath = '/' . $relativePath;
                    ?>
                    <a href="<?= htmlspecialchars($urlPath) ?>" 
                       aria-label="<?= $file['isDir'] ? 'Browse directory' : 'Download or view file' ?>: <?= htmlspecialchars($file['name']) ?>">
                        <?= $file['icon'] ?> <?= htmlspecialchars($file['name']) ?>
                    </a>
                </td>
                <td><?= $file['type'] ?></td>
                <td><?= $file['isDir'] ? '—' : formatSize($file['size']) ?></td>
                <td title="<?= date('Y-m-d H:i:s', $file['modified']) ?>">
                    <?= formatDate($file['modified']) ?>
                </td>
                <td>
                    <?php if (!$file['isDir']): ?>
                        <?php
                        $sha256 = hash_file('sha256', $file['path']);
                        $shortSha = substr($sha256, 0, 16) . '...';
                        ?>
                        <span title="<?= htmlspecialchars($sha256) ?>" class="sha-hash">
                            <?= htmlspecialchars($shortSha) ?>
                        </span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$file['isDir']): ?>
                        <button onclick="downloadSingleFile('<?= htmlspecialchars($file['name']) ?>')" 
                                class="action-btn primary" style="padding: 2px 6px; font-size: 10px; margin-right: 5px;" 
                                title="Download file">
                            [DL]
                        </button>
                    <?php endif; ?>
                    <button onclick="deleteFile('<?= htmlspecialchars($file['name']) ?>', <?= $file['isDir'] ? 'true' : 'false' ?>)" 
                            class="delete-btn" title="Delete <?= $file['isDir'] ? 'directory' : 'file' ?>">
                        [DEL]
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        
        <?php elseif ($view === 'grid'): ?>
        <div class="grid-view" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
            <?php foreach ($paginatedFiles as $file): ?>
                <div class="grid-item" style="border: 1px solid #000080; padding: 10px; background: white; position: relative;">
                    <input type="checkbox" class="file-checkbox" value="<?= htmlspecialchars($file['name']) ?>" style="float: right;">
                    <div style="position: absolute; top: 5px; left: 5px; display: flex; gap: 2px;">
                        <?php if (!$file['isDir']): ?>
                            <button onclick="downloadSingleFile('<?= htmlspecialchars($file['name']) ?>')" 
                                    class="action-btn primary" style="font-size: 10px; padding: 2px 4px;" 
                                    title="Download file">[DL]</button>
                        <?php endif; ?>
                        <button onclick="deleteFile('<?= htmlspecialchars($file['name']) ?>', <?= $file['isDir'] ? 'true' : 'false' ?>)" 
                                class="delete-btn" style="font-size: 10px; padding: 2px 4px;" 
                                title="Delete <?= $file['isDir'] ? 'directory' : 'file' ?>">[DEL]</button>
                    </div>
                    <?php
                    $relativePath = trim(str_replace($baseDir, '', $file['path']), '/');
                    $urlPath = '/' . $relativePath;
                    ?>
                    <a href="<?= htmlspecialchars($urlPath) ?>" style="text-decoration: none; color: #0000ff;">
                        <div style="text-align: center; margin-top: 20px;">
                            <div style="font-weight: bold; margin: 5px 0;">
                                <?= $file['icon'] ?> <?= htmlspecialchars($file['name']) ?>
                            </div>
                            <div class="file-info">
                                <?= $file['type'] ?> | 
                                <?= $file['isDir'] ? 'DIR' : formatSize($file['size']) ?> | 
                                <?= formatDate($file['modified']) ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php else: // list view ?>
        <div class="list-view">
            <?php foreach ($paginatedFiles as $file): ?>
                <div class="list-item" style="border-bottom: 1px dotted #000080; padding: 5px 0; display: flex; align-items: center;">
                    <input type="checkbox" class="file-checkbox" value="<?= htmlspecialchars($file['name']) ?>" style="margin-right: 10px;">
                    <?php
                    $relativePath = trim(str_replace($baseDir, '', $file['path']), '/');
                    $urlPath = '/' . $relativePath;
                    ?>
                    <a href="<?= htmlspecialchars($urlPath) ?>" style="flex: 1; text-decoration: none; color: #0000ff;">
                        <?= $file['icon'] ?> <?= htmlspecialchars($file['name']) ?>
                    </a>
                    <span style="margin-left: 10px; color: #666; font-size: 0.9em;">
                        <?= $file['type'] ?> | 
                        <?= $file['isDir'] ? 'DIR' : formatSize($file['size']) ?> | 
                        <?= formatDate($file['modified']) ?>
                    </span>
                    <div style="margin-left: 10px; display: flex; gap: 5px;">
                        <?php if (!$file['isDir']): ?>
                            <button onclick="downloadSingleFile('<?= htmlspecialchars($file['name']) ?>')" 
                                    class="action-btn primary" style="font-size: 10px; padding: 2px 6px;" 
                                    title="Download file">[DL]</button>
                        <?php endif; ?>
                        <button onclick="deleteFile('<?= htmlspecialchars($file['name']) ?>', <?= $file['isDir'] ? 'true' : 'false' ?>)" 
                                class="delete-btn" style="font-size: 10px; padding: 2px 6px;" 
                                title="Delete <?= $file['isDir'] ? 'directory' : 'file' ?>">[DEL]</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= buildUrl(['page' => $page - 1]) ?>">[PREV]</a>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            if ($startPage > 1):
                ?>
                <a href="<?= buildUrl(['page' => 1]) ?>">1</a>
                <?php if ($startPage > 2): ?>...<?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= buildUrl(['page' => $i]) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>...<?php endif; ?>
                <a href="<?= buildUrl(['page' => $totalPages]) ?>"><?= $totalPages ?></a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="<?= buildUrl(['page' => $page + 1]) ?>">[NEXT]</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</main>

<footer>
    <p>
        &copy; <?= date('Y') ?> File server by <a href="https://www.lrr.sh" rel="author">Lexi</a> | 
        <a href="https://github.com/0x800a6/file_uploader" rel="external noopener">Open Source on GitHub</a>
    </p>
    <p class="file-stats">
        <strong>[QUICK STATS]</strong> 
        <?php
        $dirCount = count(array_filter($files, function ($f) use ($path) {
            return $f !== '.' && $f !== '..' && is_dir($path . '/' . $f);
        }));
        $fileCount = count(array_filter($files, function ($f) use ($path) {
            return $f !== '.' && $f !== '..' && !is_dir($path . '/' . $f);
        }));
        echo "$dirCount directories, $fileCount files";
        ?>
        | <strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?>
        | <strong>PHP:</strong> <?= PHP_VERSION ?>
    </p>
</footer>

<script>
// Enhanced JavaScript functionality
let selectedFiles = new Set();
let uploadQueue = [];
let isUploading = false;
let uploadAbortController = null;

function updateSort() {
    const sort = document.getElementById('sort').value;
    const url = new URL(window.location);
    url.searchParams.set('sort', sort);
    window.location.href = url.toString();
}

function updateView() {
    const view = document.getElementById('view').value;
    const url = new URL(window.location);
    url.searchParams.set('view', view);
    window.location.href = url.toString();
}

function refreshPage() {
    window.location.reload();
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.file-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
        if (selectAll.checked) {
            selectedFiles.add(checkbox.value);
        } else {
            selectedFiles.delete(checkbox.value);
        }
    });
    
    updateDownloadButton();
}

function toggleFileSelection(checkbox) {
    if (checkbox.checked) {
        selectedFiles.add(checkbox.value);
    } else {
        selectedFiles.delete(checkbox.value);
    }
    
    // Update select all checkbox
    const checkboxes = document.querySelectorAll('.file-checkbox');
    const checkedCount = document.querySelectorAll('.file-checkbox:checked').length;
    const selectAll = document.getElementById('selectAll');
    
    if (checkedCount === 0) {
        selectAll.indeterminate = false;
        selectAll.checked = false;
    } else if (checkedCount === checkboxes.length) {
        selectAll.indeterminate = false;
        selectAll.checked = true;
    } else {
        selectAll.indeterminate = true;
    }
    
    updateDownloadButton();
}

function updateDownloadButton() {
    const downloadBtn = document.getElementById('downloadBtn');
    downloadBtn.disabled = selectedFiles.size === 0;
    downloadBtn.textContent = `[DOWNLOAD${selectedFiles.size > 0 ? ` (${selectedFiles.size})` : ''}]`;
}

function downloadSelected() {
    if (selectedFiles.size === 0) return;
    
    if (selectedFiles.size === 1) {
        // Single file download
        const fileName = Array.from(selectedFiles)[0];
        downloadSingleFile(fileName);
    } else {
        // Multiple files - download each file individually
        downloadMultipleFiles(Array.from(selectedFiles));
    }
}

function downloadSingleFile(fileName) {
    showNotification(`Downloading ${fileName}...`, 'info');
    
    // Create a temporary link to trigger download
    const link = document.createElement('a');
    link.href = `download.php?file=${encodeURIComponent(fileName)}`;
    link.download = fileName;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Show success notification after a short delay
    setTimeout(() => {
        showNotification(`Downloaded ${fileName}`, 'success');
    }, 1000);
}

function downloadMultipleFiles(fileNames) {
    showNotification(`Starting download of ${fileNames.length} files...`, 'info');
    
    // Download files with a small delay between each to avoid overwhelming the browser
    fileNames.forEach((fileName, index) => {
        setTimeout(() => {
            downloadSingleFile(fileName);
        }, index * 500); // 500ms delay between downloads
    });
    
    // Show completion notification
    setTimeout(() => {
        showNotification(`Completed downloading ${fileNames.length} files`, 'success');
    }, (fileNames.length * 500) + 1000);
}

function downloadAllFiles() {
    // Get all file checkboxes and collect file names (excluding directories)
    const checkboxes = document.querySelectorAll('.file-checkbox');
    const allFiles = [];
    
    checkboxes.forEach(checkbox => {
        const fileName = checkbox.value;
        // Check if this is a file (not a directory) by looking at the parent row
        const row = checkbox.closest('tr, .grid-item, .list-item');
        if (row && !row.classList.contains('dir') && !row.querySelector('.file-info')?.textContent.includes('DIR')) {
            allFiles.push(fileName);
        }
    });
    
    if (allFiles.length === 0) {
        showNotification('No files to download', 'warning');
        return;
    }
    
    // Confirm download of all files
    if (!confirm(`Download all ${allFiles.length} files? This may take a while.`)) {
        return;
    }
    
    downloadMultipleFiles(allFiles);
}

function selectAll() {
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = true;
    toggleSelectAll();
}

function toggleUpload() {
    const uploadArea = document.getElementById('uploadArea');
    uploadArea.style.display = uploadArea.style.display === 'none' ? 'block' : 'none';
}

// Enhanced drag and drop functionality
function setupDragAndDrop() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    
    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    // Highlight drop zone
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight(e) {
        dropZone.classList.add('dragover');
    }
    
    function unhighlight(e) {
        dropZone.classList.remove('dragover');
    }
    
    // Handle dropped files
    dropZone.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length > 0) {
            addFilesToQueue(Array.from(files));
        }
    }
    
    // Handle file input change
    fileInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            addFilesToQueue(Array.from(e.target.files));
            // Clear the input to allow selecting the same files again
            e.target.value = '';
        }
    });
    
    // Click to browse
    dropZone.addEventListener('click', function() {
        fileInput.click();
    });
}

// Enhanced file upload functionality
function addFilesToQueue(files) {
    const maxFileSize = 100 * 1024 * 1024; // 100MB
    const validateFiles = document.getElementById('validateFiles').checked;
    
    files.forEach(file => {
        // Validate file size
        if (file.size > maxFileSize) {
            showNotification(`File "${file.name}" is too large (${formatBytes(file.size)}). Max size: 100MB`, 'error');
            return;
        }
        
        // Validate file type if enabled
        if (validateFiles && !isValidFileType(file)) {
            showNotification(`File type not allowed: "${file.name}"`, 'error');
            return;
        }
        
        // Check for duplicates
        const existingFile = uploadQueue.find(f => f.name === file.name && f.size === file.size);
        if (existingFile) {
            showNotification(`File "${file.name}" is already in queue`, 'warning');
            return;
        }
        
        // Security validation (if enabled)
        const enableSecurityScan = document.getElementById('enableSecurityScan').checked;
        const enableContentScan = document.getElementById('enableContentScan').checked;
        
        let securityCheck = { warnings: [], errors: [], isValid: true };
        if (enableSecurityScan) {
            securityCheck = validateFileSecurity(file);
            if (!securityCheck.isValid) {
                securityCheck.errors.forEach(error => {
                    showNotification(error, 'error');
                });
                return;
            }
            
            // Show security warnings
            securityCheck.warnings.forEach(warning => {
                showNotification(warning, 'warning');
            });
        }
        
        // Add to queue with security info
        const queueItem = {
            file: file,
            name: file.name,
            size: file.size,
            status: 'ready',
            id: Date.now() + Math.random(),
            securityWarnings: securityCheck.warnings,
            securityErrors: securityCheck.errors
        };
        
        uploadQueue.push(queueItem);
        
        // Perform content scanning for smaller files (if enabled)
        if (enableContentScan && file.size < 5 * 1024 * 1024) { // 5MB limit for content scanning
            scanFileContent(file, (result) => {
                queueItem.contentScanResult = result;
                if (!result.safe) {
                    queueItem.status = 'error';
                    result.errors.forEach(error => {
                        showNotification(`Content scan failed for "${file.name}": ${error}`, 'error');
                    });
                }
                updateFileList();
            });
        }
    });
    
    updateFileList();
    updateUploadButton();
}

function isValidFileType(file) {
    const allowedExtensions = [
        'txt', 'md', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff',
        'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a',
        'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm',
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
        'js', 'css', 'html', 'php', 'py', 'java', 'cpp', 'c', 'h', 'rb', 'go', 'rs', 'ts', 'jsx', 'tsx',
        'json', 'xml', 'csv', 'yml', 'yaml', 'ini', 'conf', 'cfg', 'toml',
        'sql', 'sh', 'bat', 'ps1', 'dockerfile', 'gitignore'
    ];
    
    const extension = file.name.split('.').pop().toLowerCase();
    return allowedExtensions.includes(extension);
}

function validateFileSecurity(file) {
    const warnings = [];
    const errors = [];
    
    // Check for suspicious file names
    const suspiciousPatterns = [
        /\.(exe|scr|bat|cmd|com|pif|vbs|js|jar)$/i,
        /\.(php|asp|jsp|py|pl|sh|ps1)$/i,
        /\.(lnk|url|reg)$/i
    ];
    
    const fileName = file.name.toLowerCase();
    suspiciousPatterns.forEach(pattern => {
        if (pattern.test(fileName)) {
            warnings.push(`File "${file.name}" has a potentially executable extension`);
        }
    });
    
    // Check for double extensions (common malware technique)
    const parts = file.name.split('.');
    if (parts.length > 2) {
        const lastTwo = parts.slice(-2).join('.');
        if (suspiciousPatterns.some(pattern => pattern.test('.' + lastTwo))) {
            errors.push(`File "${file.name}" has suspicious double extension`);
        }
    }
    
    // Check for very long filenames (potential buffer overflow)
    if (file.name.length > 255) {
        errors.push(`File "${file.name}" has an excessively long name`);
    }
    
    // Check for hidden characters or unicode issues
    if (/[^\x20-\x7E]/.test(file.name)) {
        warnings.push(`File "${file.name}" contains non-ASCII characters`);
    }
    
    // Check file size for potential issues
    if (file.size === 0) {
        warnings.push(`File "${file.name}" is empty`);
    } else if (file.size > 50 * 1024 * 1024) { // 50MB warning
        warnings.push(`File "${file.name}" is very large (${formatBytes(file.size)})`);
    }
    
    return { warnings, errors, isValid: errors.length === 0 };
}

function scanFileContent(file, callback) {
    if (file.size > 10 * 1024 * 1024) { // Skip content scanning for files > 10MB
        callback({ safe: true, warnings: ['File too large for content scanning'] });
        return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const content = e.target.result;
        const warnings = [];
        const errors = [];
        
        // Check for suspicious content patterns
        const suspiciousPatterns = [
            /<script[^>]*>/i,
            /javascript:/i,
            /vbscript:/i,
            /onload\s*=/i,
            /onerror\s*=/i,
            /eval\s*\(/i,
            /document\.write/i,
            /window\.location/i,
            /\.innerHTML/i
        ];
        
        suspiciousPatterns.forEach(pattern => {
            if (pattern.test(content)) {
                warnings.push('File contains potentially malicious script patterns');
            }
        });
        
        // Check for binary content in text files
        const textExtensions = ['txt', 'md', 'json', 'xml', 'csv', 'html', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'h'];
        const extension = file.name.split('.').pop().toLowerCase();
        
        if (textExtensions.includes(extension)) {
            // Check for null bytes or high binary content
            const nullBytes = (content.match(/\0/g) || []).length;
            const binaryRatio = nullBytes / content.length;
            
            if (binaryRatio > 0.01) { // More than 1% null bytes
                warnings.push('Text file contains binary content');
            }
        }
        
        callback({ 
            safe: errors.length === 0, 
            warnings: warnings,
            errors: errors
        });
    };
    
    reader.onerror = function() {
        callback({ safe: false, errors: ['Failed to read file content'] });
    };
    
    // Read as text for text files, as array buffer for others
    const textExtensions = ['txt', 'md', 'json', 'xml', 'csv', 'html', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'h'];
    const extension = file.name.split('.').pop().toLowerCase();
    
    if (textExtensions.includes(extension)) {
        reader.readAsText(file);
    } else {
        reader.readAsArrayBuffer(file);
    }
}

function updateFileList() {
    const fileList = document.getElementById('fileList');
    const fileItems = document.getElementById('fileItems');
    
    if (uploadQueue.length === 0) {
        fileList.style.display = 'none';
        return;
    }
    
    fileList.style.display = 'block';
    fileItems.innerHTML = '';
    
    uploadQueue.forEach((item, index) => {
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        
        // Build security info display
        let securityInfo = '';
        if (item.securityWarnings && item.securityWarnings.length > 0) {
            securityInfo += `<div class="security-warning">⚠️ ${item.securityWarnings.length} warning(s)</div>`;
        }
        if (item.contentScanResult && item.contentScanResult.warnings && item.contentScanResult.warnings.length > 0) {
            securityInfo += `<div class="content-warning">🔍 Content scan: ${item.contentScanResult.warnings.length} issue(s)</div>`;
        }
        
        fileItem.innerHTML = `
            <div class="file-icon">${getFileIconForUpload(item.name)}</div>
            <div class="file-info">
                <div class="file-name">${item.name}</div>
                <div class="file-size">${formatBytes(item.size)}</div>
                ${securityInfo}
            </div>
            <div class="file-status ${item.status}">${item.status.toUpperCase()}</div>
            <button onclick="removeFromQueue(${index})" class="action-btn danger" style="padding: 2px 6px; font-size: 10px;">[×]</button>
        `;
        fileItems.appendChild(fileItem);
    });
}

function getFileIconForUpload(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const iconMap = {
        'pdf': '📄', 'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'svg': '🖼️',
        'mp3': '🎵', 'wav': '🎵', 'mp4': '🎬', 'avi': '🎬', 'mkv': '🎬',
        'zip': '📦', 'rar': '📦', '7z': '📦', 'tar': '📦',
        'txt': '📝', 'md': '📝', 'doc': '📄', 'docx': '📄',
        'js': '⚡', 'css': '🎨', 'html': '🌐', 'php': '🐘', 'py': '🐍',
        'json': '📋', 'xml': '📋', 'csv': '📊'
    };
    return iconMap[ext] || '📁';
}

function removeFromQueue(index) {
    uploadQueue.splice(index, 1);
    updateFileList();
    updateUploadButton();
}

function clearFileList() {
    uploadQueue = [];
    updateFileList();
    updateUploadButton();
}

function updateUploadButton() {
    const startBtn = document.getElementById('startUploadBtn');
    const uploadKey = document.getElementById('uploadKey').value;
    
    startBtn.disabled = uploadQueue.length === 0 || !uploadKey || isUploading;
    startBtn.textContent = uploadQueue.length > 0 ? `[START UPLOAD (${uploadQueue.length})]` : '[START UPLOAD]';
}

function startUpload() {
    const uploadKey = document.getElementById('uploadKey').value;
    const uploadSubdir = document.getElementById('uploadSubdir').value;
    
    if (!uploadKey) {
        showNotification('Please enter the security key', 'error');
        return;
    }
    
    if (uploadQueue.length === 0) {
        showNotification('No files to upload', 'error');
        return;
    }
    
    isUploading = true;
    uploadAbortController = new AbortController();
    
    const progressDiv = document.getElementById('uploadProgress');
    const progressFill = document.getElementById('progressFill');
    const statusDiv = document.getElementById('uploadStatus');
    const statsDiv = document.getElementById('uploadStats');
    const detailsDiv = document.getElementById('progressDetails');
    
    progressDiv.style.display = 'block';
    statusDiv.textContent = 'Starting upload...';
    statsDiv.textContent = `0/${uploadQueue.length} files`;
    detailsDiv.innerHTML = '';
    
    let uploadedCount = 0;
    let failedCount = 0;
    let totalFiles = uploadQueue.length;
    
    // Update queue status
    uploadQueue.forEach(item => {
        item.status = 'uploading';
    });
    updateFileList();
    updateUploadButton();
    
    // Upload files sequentially for better control
    uploadNextFile(0);
    
    function uploadNextFile(index) {
        if (index >= uploadQueue.length || uploadAbortController.signal.aborted) {
            finishUpload();
            return;
        }
        
        const item = uploadQueue[index];
        item.status = 'uploading';
        updateFileList();
        
        statusDiv.textContent = `Uploading: ${item.name}`;
        statsDiv.textContent = `${uploadedCount + failedCount}/${totalFiles} files`;
        
        uploadSingleFile(item.file, uploadKey, uploadSubdir, (success, response) => {
            if (success) {
                item.status = 'completed';
                uploadedCount++;
                showNotification(`Uploaded: ${item.name}`, 'success');
            } else {
                item.status = 'error';
                failedCount++;
                showNotification(`Failed: ${item.name} - ${response.message}`, 'error');
            }
            
            updateFileList();
            
            const progress = ((uploadedCount + failedCount) / totalFiles) * 100;
            progressFill.style.width = progress + '%';
            
            // Update stats immediately
            statsDiv.textContent = `${uploadedCount + failedCount}/${totalFiles} files`;
            
            // Add to details
            const detailItem = document.createElement('div');
            detailItem.innerHTML = `${success ? '✓' : '✗'} ${item.name} (${formatBytes(item.size)})`;
            detailItem.style.color = success ? '#2d5a2d' : '#cc0000';
            detailsDiv.appendChild(detailItem);
            
            // Continue with next file
            setTimeout(() => uploadNextFile(index + 1), 500);
        });
    }
    
    function finishUpload() {
        isUploading = false;
        updateUploadButton();
        
        const totalProcessed = uploadedCount + failedCount;
        statusDiv.textContent = `Upload complete: ${uploadedCount} successful, ${failedCount} failed`;
        
        if (uploadedCount > 0) {
            setTimeout(() => {
                progressDiv.style.display = 'none';
                progressFill.style.width = '0%';
                refreshPage();
            }, 3000);
        }
    }
}

function uploadSingleFile(file, key, subdir, callback) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('key', key);
    if (subdir) {
        formData.append('subdir', subdir);
    }
    
    fetch('upload.php', {
        method: 'POST',
        body: formData,
        signal: uploadAbortController.signal
    })
    .then(response => response.json())
    .then(data => {
        callback(data.success, data);
    })
    .catch(error => {
        if (error.name === 'AbortError') {
            callback(false, { message: 'Upload cancelled' });
        } else {
            callback(false, { message: 'Network error: ' + error.message });
        }
    });
}

function cancelUpload() {
    if (uploadAbortController) {
        uploadAbortController.abort();
    }
    
    isUploading = false;
    uploadQueue.forEach(item => {
        if (item.status === 'uploading') {
            item.status = 'ready';
        }
    });
    
    updateFileList();
    updateUploadButton();
    
    const progressDiv = document.getElementById('uploadProgress');
    progressDiv.style.display = 'none';
    
    showNotification('Upload cancelled', 'warning');
}

// Delete file functionality
function deleteFile(filename, isDir) {
    const uploadKey = prompt(`Enter security key to delete ${isDir ? 'directory' : 'file'}: "${filename}"`);
    
    if (!uploadKey) {
        return;
    }
    
    if (!confirm(`Are you sure you want to delete "${filename}"? This action cannot be undone.`)) {
        return;
    }
    
    showNotification(`Deleting ${isDir ? 'directory' : 'file'}...`, 'info');
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('filename', filename);
    formData.append('key', uploadKey);
    formData.append('is_dir', isDir ? '1' : '0');
    
    fetch('delete.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(`Successfully deleted "${filename}"`, 'success');
            setTimeout(() => refreshPage(), 1000);
        } else {
            showNotification(`Failed to delete "${filename}": ${data.message}`, 'error');
        }
    })
    .catch(error => {
        showNotification(`Error deleting "${filename}": ${error.message}`, 'error');
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+A to select all
    if (e.ctrlKey && e.key === 'a') {
        e.preventDefault();
        selectAll();
    }
    
    // Ctrl+R to refresh
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        refreshPage();
    }
    
    // Escape to clear selection
    if (e.key === 'Escape') {
        selectedFiles.clear();
        document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAll').checked = false;
        updateDownloadButton();
    }
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Event listeners for checkboxes will be added later to avoid duplicates
    
    // Setup drag and drop
    setupDragAndDrop();
    
    // Auto-focus search box if it has content
    const searchBox = document.querySelector('.search-box');
    if (searchBox && searchBox.value) {
        searchBox.focus();
    }
    
    // Add click handlers for file checkboxes (avoid duplicates)
    document.querySelectorAll('.file-checkbox').forEach(checkbox => {
        // Remove existing listeners to prevent duplicates
        checkbox.removeEventListener('change', toggleFileSelection);
        checkbox.addEventListener('change', function() {
            toggleFileSelection(this);
        });
    });
    
    // Add event listeners for upload form
    const uploadKey = document.getElementById('uploadKey');
    const uploadSubdir = document.getElementById('uploadSubdir');
    const validateFiles = document.getElementById('validateFiles');
    
    if (uploadKey) {
        uploadKey.addEventListener('input', updateUploadButton);
    }
    
    if (uploadSubdir) {
        uploadSubdir.addEventListener('input', function() {
            // Validate subdirectory input
            const value = this.value;
            const validPattern = /^[a-zA-Z0-9._-]*$/;
            if (value && !validPattern.test(value)) {
                this.setCustomValidity('Only letters, numbers, dots, underscores, and dashes allowed');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    if (validateFiles) {
        validateFiles.addEventListener('change', function() {
            // Re-validate files in queue if validation setting changes
            if (uploadQueue.length > 0) {
                const filesToRevalidate = uploadQueue.filter(item => item.status === 'ready');
                filesToRevalidate.forEach(item => {
                    if (this.checked && !isValidFileType(item.file)) {
                        item.status = 'error';
                        showNotification(`File type not allowed: "${item.name}"`, 'error');
                    } else if (!this.checked && item.status === 'error') {
                        item.status = 'ready';
                    }
                });
                updateFileList();
            }
        });
    }
});

// Real-time search (debounced)
let searchTimeout;
document.querySelector('.search-box')?.addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        if (e.target.value.length > 2 || e.target.value.length === 0) {
            e.target.form.submit();
        }
    }, 500);
});

// File context menu (right-click)
document.addEventListener('contextmenu', function(e) {
    if (e.target.closest('a[href]')) {
        e.preventDefault();
        const link = e.target.closest('a[href]');
        const fileName = link.textContent.trim().replace(/^\[.*?\]\s*/, '');
        
        const menu = document.createElement('div');
        menu.style.cssText = `
            position: fixed;
            top: ${e.clientY}px;
            left: ${e.clientX}px;
            background: white;
            border: 2px solid #000080;
            padding: 5px;
            z-index: 1000;
            font-family: "Courier New", Courier, monospace;
            font-size: 12px;
        `;
        
        menu.innerHTML = `
            <div style="padding: 2px 5px; cursor: pointer; border-bottom: 1px solid #ccc;" onclick="window.open('${link.href}', '_blank')">[OPEN IN NEW TAB]</div>
            <div style="padding: 2px 5px; cursor: pointer; border-bottom: 1px solid #ccc;" onclick="copyToClipboard('${link.href}')">[COPY LINK]</div>
            <div style="padding: 2px 5px; cursor: pointer;" onclick="downloadFile('${link.href}', '${fileName}')">[FORCE DOWNLOAD]</div>
        `;
        
        document.body.appendChild(menu);
        
        // Remove menu when clicking elsewhere
        setTimeout(() => {
            document.addEventListener('click', function removeMenu() {
                document.body.removeChild(menu);
                document.removeEventListener('click', removeMenu);
            });
        }, 100);
    }
});

// Utility functions
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('[COPIED TO CLIPBOARD]');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('[COPIED TO CLIPBOARD]');
    });
}

function downloadFile(url, filename) {
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Auto-refresh functionality
let autoRefreshInterval;
function toggleAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        document.getElementById('autoRefreshBtn').textContent = '[AUTO REFRESH: OFF]';
    } else {
        autoRefreshInterval = setInterval(() => {
            refreshPage();
        }, 30000); // 30 seconds
        document.getElementById('autoRefreshBtn').textContent = '[AUTO REFRESH: ON]';
    }
}

// File size formatting for display
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Enhanced file info display
function showFileInfo(fileName) {
    // This could be expanded to show detailed file information
    alert(`File: ${fileName}\nDetailed info feature coming soon!`);
}

// Notification system
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = 'notification';
    notification.textContent = `[${type.toUpperCase()}] ${message}`;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => {
            if (document.body.contains(notification)) {
                document.body.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// Enhanced clipboard functionality
function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Copied to clipboard!');
        }).catch(() => {
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showNotification('Copied to clipboard!');
    } catch (err) {
        showNotification('Failed to copy to clipboard', 'error');
    }
    
    document.body.removeChild(textArea);
}

// Enhanced download functionality
function downloadFile(url, filename) {
    showNotification(`Downloading ${filename}...`);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    setTimeout(() => {
        showNotification(`Downloaded ${filename}`, 'success');
    }, 1000);
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    // Arrow key navigation
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        const links = document.querySelectorAll('a[href]');
        const currentIndex = Array.from(links).indexOf(document.activeElement);
        
        if (e.key === 'ArrowDown' && currentIndex < links.length - 1) {
            e.preventDefault();
            links[currentIndex + 1].focus();
        } else if (e.key === 'ArrowUp' && currentIndex > 0) {
            e.preventDefault();
            links[currentIndex - 1].focus();
        }
    }
    
    // Enter key to open focused link
    if (e.key === 'Enter' && document.activeElement.tagName === 'A') {
        e.preventDefault();
        window.location.href = document.activeElement.href;
    }
});

// Performance monitoring
function logPerformance() {
    if (window.performance && window.performance.timing) {
        const timing = window.performance.timing;
        const loadTime = timing.loadEventEnd - timing.navigationStart;
        console.log(`[PERF] Page load time: ${loadTime}ms`);
        
        if (loadTime > 3000) {
            showNotification('Page loaded slowly', 'warning');
        }
    }
}

// Initialize performance monitoring
window.addEventListener('load', logPerformance);

// Service Worker registration (for future PWA features)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        // Service worker registration would go here
        console.log('[SW] Service Worker support detected');
    });
}
</script>

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
        "url": "https://www.lrr.sh"
    },
    "provider": {
        "@type": "Organization",
        "name": "Lexi's File Hosting",
        "url": "https://www.lrr.sh"
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
</html>