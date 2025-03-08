<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    // Set secure cookies if using HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_httponly', 1);
    }
    session_start();
}

define('DEBUG', true);
$debug_log = __DIR__ . '/folder_share_debug.log';
function log_debug($message) {
    global $debug_log;
    if (DEBUG) {
        @file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}

function formatFileSize($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function get_base_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script_name = dirname($_SERVER['PHP_SELF']);
    return $protocol . $host . $script_name;
}

log_debug("=== Shared Folder Access Request ===");
log_debug("PHP Version: " . PHP_VERSION);
log_debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
log_debug("GET params: " . var_export($_GET ?? [], true));

function display_error($message, $status_code = 404) {
    http_response_code($status_code);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error</title>
    <style>
        :root {
            --primary-red: #e63946;
            --dark-red: #b32d38;
            --shadow: rgba(230, 57, 70, 0.2);
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8f8f8, #fff);
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .error-container {
            background: white;
            padding: 3rem;
            border-radius: 20px;
            box-shadow: 0 10px 30px var(--shadow);
            text-align: center;
            animation: bounceIn 0.8s ease-out;
        }
        h1 {
            color: var(--primary-red);
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        p {
            color: #666;
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }
        .back-link {
            display: inline-block;
            padding: 0.8rem 2rem;
            background: var(--primary-red);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
        }
        .back-link:hover {
            background: var(--dark-red);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--shadow);
        }
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Oops!</h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <a href="javascript:history.back()" class="back-link">Go Back</a>
    </div>
</body>
</html>
<?php
    exit;
}

function create_folder_content_page($folderName, $folderPath, $username, $shareId, $subPath = '', $folderContents = []) {
    $currentPath = $subPath ? $subPath : '';
    $parentPath = dirname($currentPath);
    
    if ($parentPath === '.' && $currentPath !== '') {
        $parentPath = '';
    }
    
    $breadcrumbs = [];
    $tempPath = $currentPath;
    $breadcrumbs[] = ['name' => basename($tempPath) ?: $folderName, 'path' => $tempPath];
    
    while ($tempPath !== '') {
        $tempPath = dirname($tempPath);
        if ($tempPath === '.') {
            $tempPath = '';
        }
        $breadcrumbs[] = ['name' => basename($tempPath) ?: $folderName, 'path' => $tempPath];
    }
    $breadcrumbs = array_reverse($breadcrumbs);
    
    $debugInfo = '';
    if (DEBUG) {
        // Simplified debug info with folder details
        $debugInfo = '
        <div class="debug-info">
            <h3>Details</h3>
            <p><strong>Folder Name:</strong> ' . htmlspecialchars($folderName) . '</p>
            <p><strong>Current Path:</strong> ' . htmlspecialchars($currentPath) . '</p>
            <p><strong>Share ID:</strong> ' . htmlspecialchars($shareId) . '</p>
        </div>';
    }
    
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared Folder: <?php echo htmlspecialchars($folderName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <link rel="icon" type="image/svg+xml" href="drivepulse.svg">
    <style>
        :root {
            --background: #121212;
            --text-color: #fff;
            --sidebar-bg: linear-gradient(135deg, #1e1e1e, #2a2a2a);
            --content-bg: #1e1e1e;
            --border-color: #333;
            --border-color-rgb: 51, 51, 51;
            --button-bg: linear-gradient(135deg, #555, #777);
            --button-hover: linear-gradient(135deg, #777, #555);
            --accent-red: #d32f2f;
            --dropzone-bg: rgba(211, 47, 47, 0.1);
            --dropzone-border: #d32f2f;
            --texture-color: rgba(255, 255, 255, 0.03);
            --red-glow: rgba(211, 47, 47, 0.05);
            --item-hover: rgba(211, 47, 47, 0.1);
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Roboto Mono', monospace;
            background: var(--background);
            min-height: 100vh;
            color: var(--text-color);
            overflow-x: hidden;
            background-image: 
                linear-gradient(to bottom, var(--red-glow) 0%, transparent 70%),
                linear-gradient(var(--texture-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--texture-color) 1px, transparent 1px);
            background-size: 100% 100%, 20px 20px, 20px 20px;
            background-position: center top;
        }
        .container {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 2rem;
        }
        .folder-info {
            padding: 1.5rem;
            background: var(--sidebar-bg);
            color: var(--text-color);
            border-radius: 0;
            margin-bottom: 2rem;
            animation: fadeInDown 0.8s ease-out;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .folder-name {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            padding: 1rem;
            margin: 0 0 1rem 0;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0;
            list-style: none;
            border: 1px solid var(--border-color);
        }
        .breadcrumb-item {
            display: flex;
            align-items: center;
        }
        .breadcrumb-item + .breadcrumb-item::before {
            content: "/";
            padding: 0 0.5rem;
            color: #666;
        }
        .breadcrumb-item a {
            color: var(--accent-red);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .breadcrumb-item a:hover {
            text-decoration: underline;
            color: #b71c1c;
        }
        .breadcrumb-item.active {
            color: var(--text-color);
        }
        .folder-contents {
            border-radius: 0;
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 2rem;
            background: var(--content-bg);
        }
        .list-header {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            background: rgba(0, 0, 0, 0.3);
            font-weight: 600;
        }
        .list-item {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            align-items: center;
            transition: all 0.3s ease;
        }
        .list-item:last-child {
            border-bottom: none;
        }
        .list-item:hover {
            background: var(--item-hover);
        }
        .item-icon {
            color: var(--accent-red);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
        }
        .item-name {
            font-weight: 500;
            word-break: break-all;
        }
        .item-name a {
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .item-name a:hover {
            color: var(--accent-red);
        }
        .item-size {
            color: #888;
            text-align: right;
        }
        .item-actions a {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--accent-red);
            color: var(--text-color);
            text-decoration: none;
            border-radius: 0;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        .item-actions a:hover {
            background: #b71c1c;
            transform: translateY(-2px);
        }
        .empty-folder {
            padding: 3rem;
            text-align: center;
            color: #666;
            font-style: italic;
        }
        .download-all {
            display: block;
            width: 100%;
            padding: 1rem;
            background: var(--accent-red);
            color: var(--text-color);
            text-align: center;
            text-decoration: none;
            border-radius: 0;
            font-weight: 600;
            margin-top: 1rem;
            transition: all 0.3s ease;
        }
        .download-all:hover {
            background: #b71c1c;
            transform: translateY(-2px);
        }
        .debug-info {
            margin-top: 2rem;
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0;
            animation: slideLeft 0.8s ease-out;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .debug-info h3 {
            color: var(--accent-red);
            margin-bottom: 1rem;
        }
        @keyframes fadeInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes slideLeft {
            from { transform: translateX(30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            .folder-name {
                font-size: 1.5rem;
            }
            .list-header, .list-item {
                grid-template-columns: auto 1fr auto;
            }
            .item-size {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="folder-info">
            <div class="folder-name"><?php echo htmlspecialchars($folderName); ?></div>
        </div>

        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                <?php if ($index < count($breadcrumbs) - 1): ?>
                <li class="breadcrumb-item">
                    <a href="?id=<?php echo urlencode($shareId); ?>&path=<?php echo rawurlencode($crumb['path']); ?>">
                        <?php echo htmlspecialchars($crumb['name']); ?>
                    </a>
                </li>
                <?php else: ?>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($crumb['name']); ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>

        <div class="folder-contents">
            <div class="list-header">
                <div class="item-icon"></div>
                <div>Name</div>
                <div class="item-size">Size</div>
                <div class="item-actions">Actions</div>
            </div>
            
            <?php if ($parentPath !== $currentPath && $currentPath !== ''): ?>
            <div class="list-item">
                <div class="item-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="item-name">
                    <a href="?id=<?php echo urlencode($shareId); ?>&path=<?php echo rawurlencode($parentPath); ?>">
                        ..
                    </a>
                </div>
                <div class="item-size"></div>
                <div class="item-actions"></div>
            </div>
            <?php endif; ?>
            
            <?php if (empty($folderContents)): ?>
            <div class="empty-folder">This folder is empty</div>
            <?php else: ?>
                <?php 
                // Sort folders first, then files
                $folders = [];
                $files = [];
                
                foreach ($folderContents as $item) {
                    if ($item['type'] === 'dir') {
                        $folders[] = $item;
                    } else {
                        $files[] = $item;
                    }
                }
                
                // Sort folders and files by name
                usort($folders, function($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
                
                usort($files, function($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
                
                // Display folders first, then files
                foreach ($folders as $folder): 
                    $folderRelativePath = ($currentPath ? $currentPath . '/' : '') . $folder['name'];
                ?>
                <div class="list-item">
                    <div class="item-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="item-name">
                        <a href="?id=<?php echo urlencode($shareId); ?>&path=<?php echo rawurlencode($folderRelativePath); ?>">
                            <?php echo htmlspecialchars($folder['name']); ?>
                        </a>
                    </div>
                    <div class="item-size">
                        <?php echo $folder['size'] ? formatFileSize($folder['size']) : '-'; ?>
                    </div>
                    <div class="item-actions">
                        <a href="?id=<?php echo urlencode($shareId); ?>&download=1&path=<?php echo rawurlencode($folderRelativePath); ?>">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php foreach ($files as $file): 
                    $fileRelativePath = ($currentPath ? $currentPath . '/' : '') . $file['name'];
                    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $canPreview = in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'html', 'htm', 'mp4', 'mp3']);
                ?>
                <div class="list-item">
                    <div class="item-icon">
                        <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                            <i class="fas fa-image"></i>
                        <?php elseif ($fileExt === 'pdf'): ?>
                            <i class="fas fa-file-pdf"></i>
                        <?php elseif (in_array($fileExt, ['mp4', 'avi', 'mov', 'mkv'])): ?>
                            <i class="fas fa-film"></i>
                        <?php elseif (in_array($fileExt, ['mp3', 'wav', 'ogg'])): ?>
                            <i class="fas fa-music"></i>
                        <?php elseif (in_array($fileExt, ['txt', 'doc', 'docx', 'rtf'])): ?>
                            <i class="fas fa-file-alt"></i>
                        <?php elseif (in_array($fileExt, ['zip', 'rar', '7z', 'tar', 'gz'])): ?>
                            <i class="fas fa-file-archive"></i>
                        <?php else: ?>
                            <i class="fas fa-file"></i>
                        <?php endif; ?>
                    </div>
                    <div class="item-name">
                        <?php if ($canPreview): ?>
                        <a href="?id=<?php echo urlencode($shareId); ?>&file=<?php echo rawurlencode($fileRelativePath); ?>&preview=1">
                            <?php echo htmlspecialchars($file['name']); ?>
                        </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($file['name']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="item-size">
                        <?php echo formatFileSize($file['size']); ?>
                    </div>
                    <div class="item-actions">
                        <a href="?id=<?php echo urlencode($shareId); ?>&file=<?php echo rawurlencode($fileRelativePath); ?>&download=1">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($folderContents)): ?>
        <a href="?id=<?php echo urlencode($shareId); ?>&download=1&path=<?php echo rawurlencode($currentPath); ?>" class="download-all">
            <i class="fas fa-download"></i> Download All Files
        </a>
        <?php endif; ?>
        
        <?php echo $debugInfo; ?>
    </div>
</body>
</html>
<?php
    exit;
}

function serve_file($filePath, $fileName, $fileType = 'application/octet-stream', $forceDownload = false) {
    if (!file_exists($filePath)) {
        log_debug("File not found: $filePath");
        display_error("File not found or has been removed.");
        return;
    }
    
    if (!is_readable($filePath)) {
        log_debug("File not readable: $filePath");
        log_debug("File permissions: " . substr(sprintf('%o', fileperms($filePath)), -4));
        @chmod($filePath, 0644);
        if (!is_readable($filePath)) {
            display_error("Permission denied: Cannot read the file.");
            return;
        }
    }
    
    $fileSize = filesize($filePath);
    log_debug("Serving file: $filePath ($fileSize bytes)");
    
    header('Content-Type: ' . $fileType);
    header('Content-Length: ' . $fileSize);
    if ($forceDownload) {
        header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    } else {
        header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    }
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($filePath);
    exit;
}

function create_zip_archive($sourceDir, $zipFile) {
    $rootPath = realpath($sourceDir);
    
    if (file_exists($zipFile)) {
        @unlink($zipFile);
    }
    
    // Check if ZipArchive class is available
    if (class_exists('ZipArchive')) {
        log_debug("Using ZipArchive to create zip file");
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            log_debug("Failed to create zip archive using ZipArchive");
            return fallback_create_zip($sourceDir, $zipFile);
        }
        
        $folderName = basename($rootPath);
        
        if (is_dir($rootPath)) {
            // Create recursive directory iterator
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            $rootPathLength = strlen($rootPath);
            $hasFiles = false;
            
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $hasFiles = true;
                    // Get real and relative path for current file
                    $filePath = $file->getRealPath();
                    
                    // Get relative path starting from folder name
                    $relativePath = substr($filePath, $rootPathLength + 1);
                    
                    // Add current file to archive
                    $zip->addFile($filePath, $folderName . '/' . $relativePath);
                    log_debug("Added to zip: " . $filePath . " as " . $folderName . '/' . $relativePath);
                }
            }
            
            // If the folder is empty, add an empty directory
            if (!$hasFiles) {
                $zip->addEmptyDir($folderName);
                log_debug("Added empty directory to zip: " . $folderName);
            }
        }
        
        $zip->close();
        return file_exists($zipFile);
    } else {
        log_debug("ZipArchive class not found, using fallback method");
        return fallback_create_zip($sourceDir, $zipFile);
    }
}

// Fallback method using command-line zip utility
function fallback_create_zip($sourceDir, $zipFile) {
    if (empty($sourceDir) || !is_dir($sourceDir)) {
        log_debug("Invalid source directory for zip: " . ($sourceDir ?: "NULL"));
        return false;
    }
    
    $rootPath = realpath($sourceDir);
    if (!$rootPath) {
        log_debug("Could not resolve real path for: $sourceDir");
        return false;
    }
    
    $folderName = basename($rootPath);
    log_debug("Creating zip for folder: $folderName from path: $rootPath");
    
    if (file_exists($zipFile)) {
        @unlink($zipFile);
    }
    
    // Make sure the target directory for the zip file exists
    $zipDir = dirname($zipFile);
    if (!is_dir($zipDir)) {
        log_debug("Creating directory for zip file: $zipDir");
        if (!mkdir($zipDir, 0755, true)) {
            log_debug("Failed to create directory for zip file");
            return false;
        }
    }
    
    // Check if command-line zip is available
    $zipCommand = trim(shell_exec('which zip 2>/dev/null') ?: '');
    if (empty($zipCommand)) {
        log_debug("Command-line zip utility not found");
        
        // Try explicit paths where zip might be located
        foreach (['/usr/bin/zip', '/bin/zip', '/usr/local/bin/zip'] as $possiblePath) {
            if (file_exists($possiblePath) && is_executable($possiblePath)) {
                $zipCommand = $possiblePath;
                log_debug("Found zip at: $zipCommand");
                break;
            }
        }
        
        if (empty($zipCommand)) {
            log_debug("Could not find zip command in common locations");
            return false;
        }
    }
    
    log_debug("Using command-line zip: $zipCommand");
    
    // Create a temporary directory for the zip process
    $tempDir = sys_get_temp_dir() . '/' . uniqid('zip_');
    log_debug("Creating temp directory: $tempDir");
    
    if (!mkdir($tempDir, 0755, true)) {
        log_debug("Failed to create temp directory: $tempDir");
        return false;
    }
    
    try {
        // Create a directory in the temp directory to have the proper folder name in the zip
        $linkTarget = $tempDir . '/' . $folderName;
        
        log_debug("Creating directory structure for zip in: $linkTarget");
        mkdir($linkTarget, 0755, true);
        
        // Copy files to the temp structure
        if (is_dir($rootPath)) {
            // Check if we can access the directory
            if (!is_readable($rootPath)) {
                log_debug("Source directory is not readable: $rootPath");
                deleteDir($tempDir);
                return false;
            }
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            $hasFiles = false;
            foreach ($iterator as $item) {
                $relPath = substr($item->getPathname(), strlen($rootPath) + 1);
                
                if (empty($relPath)) {
                    continue;
                }
                
                $targetPath = $linkTarget . '/' . $relPath;
                
                if ($item->isDir()) {
                    if (!is_dir($targetPath)) {
                        log_debug("Creating directory: $relPath");
                        mkdir($targetPath, 0755, true);
                    }
                } else {
                    $hasFiles = true;
                    $targetDir = dirname($targetPath);
                    
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }
                    
                    log_debug("Copying file: $relPath");
                    copy($item->getPathname(), $targetPath);
                }
            }
            
            // If no files were found, create an empty directory marker
            if (!$hasFiles) {
                touch($linkTarget . '/.empty');
                log_debug("Created .empty marker for empty directory");
            }
        } else {
            log_debug("Not a directory: $rootPath");
            deleteDir($tempDir);
            return false;
        }
        
        // Change to the temp directory and run the zip command
        $currentDir = getcwd();
        chdir($tempDir);
        
        // Create the zip command with proper escaping
        $command = sprintf('%s -r %s %s 2>&1', 
            escapeshellcmd($zipCommand),
            escapeshellarg(basename($zipFile)),
            escapeshellarg($folderName)
        );
        
        log_debug("Executing zip command: $command");
        $output = shell_exec($command);
        log_debug("Zip command output: " . ($output ?: "No output"));
        
        // Move the zip file to the target location
        $tempZipFile = $tempDir . '/' . basename($zipFile);
        if (file_exists($tempZipFile)) {
            log_debug("Temporary zip file exists: $tempZipFile");
            
            if (copy($tempZipFile, $zipFile)) {
                log_debug("Successfully copied zip file to target location: $zipFile");
            } else {
                log_debug("Failed to copy zip file to target location");
                chdir($currentDir);
                deleteDir($tempDir);
                return false;
            }
        } else {
            log_debug("Error: Temporary zip file not created: $tempZipFile");
            chdir($currentDir);
            deleteDir($tempDir);
            return false;
        }
        
        // Change back to original directory
        chdir($currentDir);
        
        // Clean up
        deleteDir($tempDir);
        
        return file_exists($zipFile);
    } catch (Exception $e) {
        log_debug("Error in fallback_create_zip: " . $e->getMessage());
        
        // Clean up in case of error
        if (isset($currentDir) && is_dir($currentDir)) {
            chdir($currentDir);
        }
        
        if (is_dir($tempDir)) {
            deleteDir($tempDir);
        }
        
        return false;
    }
}

// Helper function to delete a directory and its contents
function deleteDir($dirPath) {
    if (empty($dirPath) || !is_dir($dirPath)) {
        log_debug("Tried to delete non-existent directory: " . ($dirPath ?: "NULL"));
        return;
    }
    
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $filePath = $fileinfo->getRealPath();
            try {
                if ($fileinfo->isDir()) {
                    if (is_writable($filePath)) {
                        rmdir($filePath);
                    } else {
                        @chmod($filePath, 0755);
                        @rmdir($filePath);
                    }
                } else {
                    if (is_writable($filePath)) {
                        unlink($filePath);
                    } else {
                        @chmod($filePath, 0644);
                        @unlink($filePath);
                    }
                }
            } catch (Exception $e) {
                log_debug("Error deleting path: $filePath - " . $e->getMessage());
            }
        }
        
        // Finally remove the base directory
        if (is_writable($dirPath)) {
            rmdir($dirPath);
        } else {
            @chmod($dirPath, 0755);
            @rmdir($dirPath);
        }
    } catch (Exception $e) {
        log_debug("Error in deleteDir: " . $e->getMessage());
    }
}

try {
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        log_debug("No folder share ID provided");
        display_error("Invalid share link.", 400);
    }
    
    $shareId = $_GET['id'];
    log_debug("Requested folder share ID: $shareId");
    
    if (!isset($_SESSION['folder_shares'])) {
        $_SESSION['folder_shares'] = [];
    }
    
    $folder_shares_file = __DIR__ . '/folder_shares.json';

    function load_folder_shares() {
        global $folder_shares_file;
        if (file_exists($folder_shares_file)) {
            $content = file_get_contents($folder_shares_file);
            if (!empty($content)) {
                return json_decode($content, true) ?: [];
            }
        }
        return [];
    }

    $folderPath = null;
    $username = null;
    $folderShares = load_folder_shares();
    
    log_debug("Looking for folder share ID: $shareId in " . count($folderShares) . " folder shares");
    
    // First pass: Look for exact match
    foreach ($folderShares as $key => $id) {
        if ($id === $shareId) {
            list($username, $folderPath) = explode(':', $key, 2);
            log_debug("Found exact match for folder share ID: $shareId -> $key");
            break;
        }
    }
    
    // Second pass: Check session shares if not found in persistent shares
    if (!$folderPath && isset($_SESSION['folder_shares']) && is_array($_SESSION['folder_shares'])) {
        log_debug("Checking session folder shares for ID: $shareId");
        foreach ($_SESSION['folder_shares'] as $key => $id) {
            if ($id === $shareId) {
                list($username, $folderPath) = explode(':', $key, 2);
                log_debug("Found match in session folder shares: $shareId -> $key");
                
                // Add to persistent shares for consistency
                $folderShares[$key] = $id;
                @file_put_contents($folder_shares_file, json_encode($folderShares, JSON_PRETTY_PRINT));
                break;
            }
        }
    }
    
    // If still not found, try case-insensitive comparison as a fallback
    if (!$folderPath) {
        log_debug("Trying case-insensitive comparison for folder share ID: $shareId");
        foreach ($folderShares as $key => $id) {
            if (strcasecmp($id, $shareId) === 0) {
                list($username, $folderPath) = explode(':', $key, 2);
                log_debug("Found case-insensitive match: $id -> $key");
                break;
            }
        }
    }
    
    if (!$folderPath) {
        log_debug("Folder share not found for ID: $shareId");
        log_debug("Session folder shares: " . var_export($_SESSION['folder_shares'] ?? [], true));
        log_debug("File folder shares: " . var_export($folderShares ?? [], true));
        display_error("Shared folder not found or link has expired.");
    }
    
    log_debug("Folder share found - User: $username, Folder: $folderPath");
    $folderName = basename($folderPath);
    $baseDir = "/var/www/html/webdav/users/$username/Home";
    
    $possiblePaths = [
        $baseDir . '/' . $folderPath,
        $baseDir . '/' . ltrim($folderPath, '/'),
        $baseDir . ltrim($folderPath, 'Home/'),
        $baseDir . ltrim($folderPath, '/Home/'),
        realpath($baseDir) . '/' . ltrim($folderPath, '/')
    ];
    
    $actualFolderPath = null;
    foreach ($possiblePaths as $path) {
        log_debug("Trying path: $path");
        if (file_exists($path) && is_dir($path)) {
            $actualFolderPath = $path;
            log_debug("Found folder at: $actualFolderPath");
            break;
        }
    }
    
    if (!$actualFolderPath) {
        log_debug("Folder not found with standard paths, trying to find folder by name");
        $folderName = basename($folderPath);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $pathObj => $file) {
            if ($file->isDir() && !$file->isDot() && $file->getFilename() === $folderName) {
                $actualFolderPath = $pathObj;
                log_debug("Found folder by name at: $actualFolderPath");
                break;
            }
        }
    }
    
    if (!$actualFolderPath || !is_dir($actualFolderPath)) {
        log_debug("Folder not found: Tried multiple paths but couldn't locate the folder");
        display_error("Shared folder not found or has been moved.", 404);
    }
    
    $realBaseDir = realpath($baseDir);
    $realFolderPath = realpath($actualFolderPath);
    
    if ($realFolderPath === false || strpos($realFolderPath, $realBaseDir) !== 0) {
        log_debug("Security violation - attempted access outside user directory: $actualFolderPath");
        log_debug("Base directory: $realBaseDir");
        log_debug("Resolved folder path: " . ($realFolderPath ?: "Invalid path"));
        display_error("Access denied: Folder is outside the allowed directory.", 403);
    }
    
    log_debug("Security check passed - folder is within user's directory");
    log_debug("Actual folder path: $actualFolderPath");
    
    // Handle different actions based on query parameters
    $currentPath = isset($_GET['path']) ? rawurldecode(trim($_GET['path'], '/')) : '';
    log_debug("Current path within shared folder: $currentPath");
    
    $targetPath = $actualFolderPath;
    if (!empty($currentPath)) {
        $targetPath = $actualFolderPath . '/' . $currentPath;
        $targetPath = realpath($targetPath);
        
        if ($targetPath === false || strpos($targetPath, $realFolderPath) !== 0) {
            log_debug("Security violation - attempted to access path outside shared folder: $targetPath");
            display_error("Access denied: Path is outside the shared folder.", 403);
        }
    }
    
    // Handle file download or preview
    if (isset($_GET['file'])) {
        $requestedFile = rawurldecode(trim($_GET['file'], '/'));
        log_debug("File requested: $requestedFile");
        
        // Security check: prevent path traversal attacks
        if (strpos($requestedFile, '../') !== false || strpos($requestedFile, '..\\') !== false) {
            log_debug("Path traversal attempt detected in file request: $requestedFile");
            display_error("Invalid file path. Access denied.", 403);
        }
        
        $filePath = $actualFolderPath . '/' . $requestedFile;
        $filePath = realpath($filePath);
        
        if ($filePath === false || strpos($filePath, $realFolderPath) !== 0 || !file_exists($filePath) || is_dir($filePath)) {
            log_debug("Invalid file requested: $filePath");
            display_error("File not found or access denied.", 404);
        }
        
        $fileName = basename($filePath);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileType = 'application/octet-stream';
        
        switch ($fileExt) {
            case 'jpg':
            case 'jpeg':
                $fileType = 'image/jpeg';
                break;
            case 'png':
                $fileType = 'image/png';
                break;
            case 'gif':
                $fileType = 'image/gif';
                break;
            case 'pdf':
                $fileType = 'application/pdf';
                break;
            case 'mp4':
                $fileType = 'video/mp4';
                break;
            case 'mp3':
                $fileType = 'audio/mpeg';
                break;
            case 'txt':
                $fileType = 'text/plain';
                break;
            case 'html':
            case 'htm':
                $fileType = 'text/html';
                break;
        }
        
        // Check if it's a download or preview request
        $isDownload = isset($_GET['download']) && $_GET['download'] == 1;
        $isPreview = isset($_GET['preview']) && $_GET['preview'] == 1 && !$isDownload;
        
        serve_file($filePath, $fileName, $fileType, $isDownload);
    }
    
    // Handle folder download
    if (isset($_GET['download']) && $_GET['download'] == 1) {
        // Get and validate path parameter
        $downloadPath = '';
        if (isset($_GET['path'])) {
            $downloadPath = (string)$_GET['path'];
            $downloadPath = trim($downloadPath, '/');
        }
        
        log_debug("Folder download requested. Path: " . ($downloadPath ?: "root folder"));
        
        // Security check: prevent path traversal attacks
        if (strpos($downloadPath, '../') !== false || strpos($downloadPath, '..\\') !== false) {
            log_debug("Path traversal attempt detected in download request: $downloadPath");
            display_error("Invalid folder path. Access denied.", 403);
        }
        
        // Determine the path to zip
        $pathToZip = empty($downloadPath) ? $actualFolderPath : realpath($actualFolderPath . '/' . $downloadPath);
        
        // Security check: make sure the path is within the shared folder
        if ($pathToZip === false || strpos($pathToZip, $realFolderPath) !== 0) {
            log_debug("Security violation - attempted to access path outside shared folder: " . ($pathToZip ?: "Invalid path"));
            display_error("Access denied: Path is outside the shared folder.", 403);
        }
        
        // Check if the path exists and is a directory
        if (!is_dir($pathToZip)) {
            log_debug("Path is not a directory or doesn't exist: " . ($pathToZip ?: "NULL"));
            display_error("Invalid directory path", 400);
            exit;
        }
        
        // Create a temporary zip file
        $tempZipFile = sys_get_temp_dir() . '/' . uniqid('folder_') . '.zip';
        log_debug("Creating zip archive of folder: $pathToZip to $tempZipFile");
        
        // Try to create the zip archive
        $success = create_zip_archive($pathToZip, $tempZipFile);
        
        if ($success && file_exists($tempZipFile)) {
            $zipFileName = basename($pathToZip) . '.zip';
            log_debug("Serving zip archive: $tempZipFile as $zipFileName");
            
            serve_file($tempZipFile, $zipFileName, 'application/zip', true);
            @unlink($tempZipFile); // Clean up after serving
        } else {
            log_debug("Failed to create zip archive from: $pathToZip");
            display_error("Failed to create download archive", 500);
        }
    }
    
    // Default: Display folder contents
    if (is_dir($targetPath)) {
        // Read folder contents
        $folderContents = [];
        $dir = new DirectoryIterator($targetPath);
        
        foreach ($dir as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }
            
            $item = [
                'name' => $fileInfo->getFilename(),
                'type' => $fileInfo->isDir() ? 'dir' : 'file',
                'size' => $fileInfo->isFile() ? $fileInfo->getSize() : 0,
                'modified' => $fileInfo->getMTime()
            ];
            
            // Calculate directory size
            if ($fileInfo->isDir()) {
                try {
                    $size = 0;
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($fileInfo->getPathname()),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $size += $file->getSize();
                        }
                    }
                    $item['size'] = $size;
                } catch (Exception $e) {
                    log_debug("Error calculating directory size: " . $e->getMessage());
                }
            }
            
            $folderContents[] = $item;
        }
        
        // If we're at the root of the shared folder, use the folder name
        // Otherwise use the current path's basename as folder name
        $displayFolderName = $folderName;
        if (!empty($currentPath)) {
            $displayFolderName = basename($targetPath);
        }
        
        create_folder_content_page($displayFolderName, $folderPath, $username, $shareId, $currentPath, $folderContents);
    } else {
        log_debug("Target path is not a directory: $targetPath");
        display_error("Invalid path or not a folder", 400);
    }
    
} catch (Exception $e) {
    log_debug("Unexpected error: " . $e->getMessage());
    display_error("Server error: " . $e->getMessage(), 500);
}
?> 