<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Debug log setup with toggle
define('DEBUG', true);
$debug_log = __DIR__ . '/share_debug.log';
function log_debug($message) {
    global $debug_log;
    if (DEBUG) {
        // Try to write to the log file
        @file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}

/**
 * Format file size in human-readable format
 * @param int $bytes File size in bytes
 * @param int $precision Decimal precision
 * @return string Formatted file size
 */
function formatFileSize($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Create a test share if requested
if (isset($_GET['create_test_share'])) {
    // File-based storage for shares
    $shares_file = __DIR__ . '/shares.json';
    
    // Function to load shares from file
    function load_test_shares() {
        global $shares_file;
        if (file_exists($shares_file)) {
            $content = file_get_contents($shares_file);
            if (!empty($content)) {
                return json_decode($content, true) ?: [];
            }
        }
        return [];
    }
    
    // Function to save shares to file
    function save_test_shares($shares) {
        global $shares_file;
        file_put_contents($shares_file, json_encode($shares));
    }
    
    // Create a test share
    $testShareId = 'test' . bin2hex(random_bytes(8));
    $testFilePath = 'test_file.txt';
    $testUsername = 'test_user';
    $testKey = $testUsername . ':' . $testFilePath;
    
    $shares = load_test_shares();
    $shares[$testKey] = $testShareId;
    save_test_shares($shares);
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Test Share Created</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }
            .container {
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                padding: 20px;
            }
            .success {
                color: #4CAF50;
                font-weight: bold;
            }
            .link {
                margin: 20px 0;
                padding: 10px;
                background-color: #f5f5f5;
                border-radius: 4px;
                word-break: break-all;
            }
            .btn {
                display: inline-block;
                background-color: #4CAF50;
                color: white;
                padding: 10px 20px;
                text-decoration: none;
                border-radius: 4px;
                font-weight: bold;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Test Share Created</h1>
            <p class="success">A test share has been successfully created!</p>
            <p><strong>Share ID:</strong> ' . htmlspecialchars($testShareId) . '</p>
            <p><strong>File Path:</strong> ' . htmlspecialchars($testFilePath) . '</p>
            <p><strong>Username:</strong> ' . htmlspecialchars($testUsername) . '</p>
            <p><strong>Share Link:</strong></p>
            <div class="link">
                <a href="shared.php?id=' . htmlspecialchars($testShareId) . '">
                    ' . htmlspecialchars($_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/shared.php?id=' . $testShareId) . '
                </a>
            </div>
            <a href="shared.php?id=' . htmlspecialchars($testShareId) . '" class="btn">Open Share</a>
        </div>
    </body>
    </html>';
    exit;
}

// View all shares if requested (debug only)
if (isset($_GET['view_all_shares']) && DEBUG) {
    // File-based storage for shares
    $shares_file = __DIR__ . '/shares.json';
    
    // Function to load shares from file
    function load_all_shares() {
        global $shares_file;
        if (file_exists($shares_file)) {
            $content = file_get_contents($shares_file);
            if (!empty($content)) {
                return json_decode($content, true) ?: [];
            }
        }
        return [];
    }
    
    $shares = load_all_shares();
    $sessionShares = $_SESSION['file_shares'] ?? [];
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>All Shares</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 1000px;
                margin: 0 auto;
                padding: 20px;
            }
            .container {
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                padding: 20px;
            }
            h1, h2 {
                color: #444;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            th, td {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            th {
                background-color: #f2f2f2;
            }
            tr:hover {
                background-color: #f5f5f5;
            }
            .btn {
                display: inline-block;
                background-color: #4CAF50;
                color: white;
                padding: 8px 16px;
                text-decoration: none;
                border-radius: 4px;
                font-size: 14px;
            }
            .btn-small {
                padding: 4px 8px;
                font-size: 12px;
            }
            .btn-blue {
                background-color: #2196F3;
            }
            .empty {
                color: #999;
                font-style: italic;
                padding: 20px;
                text-align: center;
            }
            .debug-info {
                margin-top: 30px;
                padding: 15px;
                background-color: #f8f8f8;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>All Shares</h1>
            
            <div class="debug-info">
                <p><strong>Session ID:</strong> ' . htmlspecialchars(session_id()) . '</p>
                <p><strong>Shares File:</strong> ' . htmlspecialchars($shares_file) . ' (Exists: ' . (file_exists($shares_file) ? 'Yes' : 'No') . ')</p>
                <a href="shared.php?create_test_share=1" class="btn">Create Test Share</a>
            </div>
            
            <h2>File-Based Shares</h2>';
            
    if (empty($shares)) {
        echo '<p class="empty">No file-based shares found.</p>';
    } else {
        echo '<table>
                <tr>
                    <th>User</th>
                    <th>File Path</th>
                    <th>Share ID</th>
                    <th>Actions</th>
                </tr>';
        
        foreach ($shares as $key => $id) {
            list($user, $path) = explode(':', $key, 2);
            echo '<tr>
                    <td>' . htmlspecialchars($user) . '</td>
                    <td>' . htmlspecialchars($path) . '</td>
                    <td>' . htmlspecialchars($id) . '</td>
                    <td>
                        <a href="shared.php?id=' . htmlspecialchars($id) . '" class="btn btn-small btn-blue" target="_blank">View</a>
                    </td>
                </tr>';
        }
        
        echo '</table>';
    }
    
    echo '<h2>Session-Based Shares</h2>';
    
    if (empty($sessionShares)) {
        echo '<p class="empty">No session-based shares found.</p>';
    } else {
        echo '<table>
                <tr>
                    <th>User</th>
                    <th>File Path</th>
                    <th>Share ID</th>
                    <th>Actions</th>
                </tr>';
        
        foreach ($sessionShares as $key => $id) {
            list($user, $path) = explode(':', $key, 2);
            echo '<tr>
                    <td>' . htmlspecialchars($user) . '</td>
                    <td>' . htmlspecialchars($path) . '</td>
                    <td>' . htmlspecialchars($id) . '</td>
                    <td>
                        <a href="shared.php?id=' . htmlspecialchars($id) . '" class="btn btn-small btn-blue" target="_blank">View</a>
                    </td>
                </tr>';
        }
        
        echo '</table>';
    }
    
    echo '</div>
    </body>
    </html>';
    exit;
}

// Log request details
log_debug("=== Shared File Access Request ===");
log_debug("PHP Version: " . PHP_VERSION);
log_debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
log_debug("GET params: " . var_export($_GET ?? [], true));

// Function to display error page
function display_error($message, $status_code = 404) {
    http_response_code($status_code);
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background-color: #f5f5f5;
                color: #333;
                margin: 0;
                padding: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }
            .error-container {
                background-color: #fff;
                border-radius: 5px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 30px;
                max-width: 500px;
                text-align: center;
            }
            h1 {
                color: #e74c3c;
                margin-top: 0;
            }
            p {
                font-size: 16px;
                line-height: 1.5;
            }
            .back-link {
                display: inline-block;
                margin-top: 20px;
                color: #3498db;
                text-decoration: none;
            }
            .back-link:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>Error</h1>
            <p>' . htmlspecialchars($message) . '</p>
            <a href="javascript:history.back()" class="back-link">Go Back</a>
        </div>
    </body>
    </html>';
    exit;
}

/**
 * Create a preview page for the shared file
 * @param string $fileName The name of the file
 * @param string $fileType The MIME type of the file
 * @param string $shareId The share ID
 * @param string $username The username of the file owner
 * @param string $filePath The relative path to the file
 */
function create_preview_page($fileName, $fileType, $shareId, $username = null, $filePath = null) {
    // Get file extension
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Determine if we can preview this file type
    $canPreview = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'html', 'htm', 'mp4', 'mp3']);
    
    // Add debug information if DEBUG is enabled
    $debugInfo = '';
    if (DEBUG) {
        global $shares_file;
        
        // Get actual file information
        if ($username && $filePath) {
            $baseDir = "/var/www/html/webdav/users/$username/Home";
            $actualFilePath = realpath($baseDir . '/' . $filePath);
            $fileExists = file_exists($actualFilePath);
            $fileSize = $fileExists ? filesize($actualFilePath) : 0;
            $fileTime = $fileExists ? date('Y-m-d H:i:s', filemtime($actualFilePath)) : 'N/A';
        } else {
            $actualFilePath = 'Unknown';
            $fileExists = false;
            $fileSize = 0;
            $fileTime = 'N/A';
        }
        
        $debugInfo = '
        <div class="debug-info" style="margin-top: 30px; padding: 15px; background-color: #f8f8f8; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin-top: 0; color: #666;">File Information</h3>
            <p><strong>Share ID:</strong> ' . htmlspecialchars($shareId) . '</p>
            <p><strong>File Name:</strong> ' . htmlspecialchars($fileName) . '</p>
            <p><strong>File Type:</strong> ' . htmlspecialchars($fileType) . '</p>
            <p><strong>File Size:</strong> ' . ($fileExists ? number_format($fileSize) . ' bytes (' . formatFileSize($fileSize) . ')' : 'Unknown') . '</p>
            <p><strong>Last Modified:</strong> ' . $fileTime . '</p>
            <p><strong>File Path:</strong> ' . htmlspecialchars($actualFilePath) . ' (Exists: ' . ($fileExists ? 'Yes' : 'No') . ')</p>
            <p><strong>Session ID:</strong> ' . htmlspecialchars(session_id()) . '</p>
            <p><strong>Shares File:</strong> ' . htmlspecialchars($shares_file) . ' (Exists: ' . (file_exists($shares_file) ? 'Yes' : 'No') . ')</p>
            
            <div style="margin-top: 15px;">
                <button id="refreshBtn" class="download-btn" style="background-color: #007bff;">Refresh Page</button>
                <a href="shared.php?view_all_shares=1" class="download-btn" style="background-color: #6c757d; margin-left: 10px;">View All Shares</a>
            </div>
        </div>
        <script>
            document.getElementById("refreshBtn").addEventListener("click", function() {
                window.location.reload();
            });
        </script>';
    }
    
    // Start HTML output
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Preview: ' . htmlspecialchars($fileName) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .file-info {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .file-name {
            font-size: 24px;
            font-weight: bold;
            word-break: break-all;
        }
        .file-type {
            color: #666;
            font-size: 14px;
        }
        .preview-container {
            margin: 20px 0;
            text-align: center;
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 4px;
        }
        .preview-container img {
            max-width: 100%;
            max-height: 500px;
        }
        .preview-container video {
            max-width: 100%;
            max-height: 500px;
        }
        .preview-container audio {
            width: 100%;
        }
        .preview-text {
            white-space: pre-wrap;
            text-align: left;
            padding: 15px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: auto;
            max-height: 500px;
        }
        .download-btn {
            display: inline-block;
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 20px;
            border: none;
            cursor: pointer;
        }
        .download-btn:hover {
            background-color: #45a049;
        }
        .no-preview {
            padding: 40px;
            text-align: center;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="file-info">
            <div class="file-name">' . htmlspecialchars($fileName) . '</div>
            <div class="file-type">File type: ' . htmlspecialchars($fileType) . '</div>
        </div>';
        
    // Create preview based on file type
    echo '<div class="preview-container">';
    
    if ($canPreview) {
        // Create a preview URL that will serve the actual file content
        $previewUrl = "?id=" . urlencode($shareId) . "&preview=1";
        
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            echo '<img src="' . htmlspecialchars($previewUrl) . '" alt="' . htmlspecialchars($fileName) . '">';
        } elseif ($extension === 'pdf') {
            echo '<iframe src="' . htmlspecialchars($previewUrl) . '" width="100%" height="500px" style="border: none;"></iframe>';
        } elseif (in_array($extension, ['txt', 'html', 'htm'])) {
            // For text files, try to show the actual content
            if ($username && $filePath) {
                $baseDir = "/var/www/html/webdav/users/$username/Home";
                $actualFilePath = realpath($baseDir . '/' . $filePath);
                
                if (file_exists($actualFilePath)) {
                    // Read the first 50KB of the file for preview
                    $content = file_get_contents($actualFilePath, false, null, 0, 50 * 1024);
                    $isPartial = filesize($actualFilePath) > 50 * 1024;
                    
                    if ($extension === 'txt') {
                        echo '<div class="preview-text">' . htmlspecialchars($content) . ($isPartial ? "\n\n[File truncated for preview...]" : "") . '</div>';
                    } else {
                        // For HTML, sanitize the content before displaying
                        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "<!-- script removed -->", $content);
                        echo '<div class="preview-text">' . $content . ($isPartial ? "\n\n<!-- File truncated for preview... -->" : "") . '</div>';
                    }
                } else {
                    echo '<div class="no-preview">File not found or cannot be read.</div>';
                }
            } else {
                echo '<div class="no-preview">File information not available for preview.</div>';
            }
        } elseif ($extension === 'mp4') {
            echo '<video controls>
                <source src="' . htmlspecialchars($previewUrl) . '" type="video/mp4">
                Your browser does not support the video tag.
            </video>';
        } elseif ($extension === 'mp3') {
            echo '<audio controls>
                <source src="' . htmlspecialchars($previewUrl) . '" type="audio/mpeg">
                Your browser does not support the audio tag.
            </audio>';
        }
    } else {
        echo '<div class="no-preview">No preview available for this file type.</div>';
    }
    
    echo '</div>';
    
    // Download button
    echo '<a href="?id=' . htmlspecialchars($shareId) . '&download=1" class="download-btn">Download File</a>';
    
    // Add debug info at the end
    echo $debugInfo;
    
    echo '</div>
</body>
</html>';
    exit;
}

/**
 * Serve the actual file for download or preview
 * @param string $filePath The full path to the file
 * @param string $fileName The name of the file
 * @param string $fileType The MIME type of the file
 * @param bool $forceDownload Whether to force download or allow inline viewing
 */
function serve_actual_file($filePath, $fileName, $fileType = 'application/octet-stream', $forceDownload = false) {
    // Check if file exists
    if (!file_exists($filePath)) {
        log_debug("File not found: $filePath");
        display_error("File not found or has been removed.");
        return;
    }
    
    // Check if file is readable
    if (!is_readable($filePath)) {
        log_debug("File not readable: $filePath");
        log_debug("File permissions: " . substr(sprintf('%o', fileperms($filePath)), -4));
        
        // Get PHP process user if possible
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            log_debug("PHP process user: " . posix_getpwuid(posix_geteuid())['name'] ?? 'unknown');
        } else {
            log_debug("PHP process user: unknown (posix functions not available)");
        }
        
        // Try to make the file readable
        @chmod($filePath, 0644);
        
        if (!is_readable($filePath)) {
            display_error("Permission denied: Cannot read the file.");
            return;
        }
    }
    
    // Get file size
    $fileSize = filesize($filePath);
    log_debug("Serving actual file: $filePath ($fileSize bytes)");
    
    // Set headers
    header('Content-Type: ' . $fileType);
    header('Content-Length: ' . $fileSize);
    
    // Set content disposition
    if ($forceDownload) {
        header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
    } else {
        header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    }
    
    // Disable output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Try different methods to serve the file
    
    // Method 1: readfile (most efficient if it works)
    if ($fileSize < 10 * 1024 * 1024) { // Less than 10MB
        try {
            $success = @readfile($filePath);
            if ($success !== false) {
                exit;
            }
            log_debug("readfile failed, trying alternative methods");
        } catch (Exception $e) {
            log_debug("readfile exception: " . $e->getMessage());
        }
    }
    
    // Method 2: fopen/fread in chunks
    try {
        $handle = @fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle) && !connection_aborted()) {
                echo @fread($handle, 8192); // 8KB chunks
                flush();
            }
            fclose($handle);
            exit;
        }
        log_debug("fopen failed, trying alternative methods");
    } catch (Exception $e) {
        log_debug("fopen/fread exception: " . $e->getMessage());
    }
    
    // Method 3: file_get_contents (last resort, not good for large files)
    try {
        if ($fileSize < 5 * 1024 * 1024) { // Less than 5MB
            $content = @file_get_contents($filePath);
            if ($content !== false) {
                echo $content;
                exit;
            }
        }
        log_debug("file_get_contents failed or file too large");
    } catch (Exception $e) {
        log_debug("file_get_contents exception: " . $e->getMessage());
    }
    
    // If we get here, all methods failed
    log_debug("All file serving methods failed");
    display_error("Failed to read file. Please try again later.");
}

try {
    // Check if share ID is provided
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        log_debug("No share ID provided");
        display_error("Invalid share link.", 400);
    }
    
    $shareId = $_GET['id'];
    log_debug("Requested share ID: $shareId");
    
    // Initialize shares in session if not exists
    if (!isset($_SESSION['file_shares'])) {
        $_SESSION['file_shares'] = [];
    }
    
    // File-based storage for shares
    $shares_file = __DIR__ . '/shares.json';

    // Function to load shares from file
    function load_shares() {
        global $shares_file;
        if (file_exists($shares_file)) {
            $content = file_get_contents($shares_file);
            if (!empty($content)) {
                return json_decode($content, true) ?: [];
            }
        }
        return [];
    }

    // Find the file path for this share ID
    $filePath = null;
    $username = null;
    
    // First try to find in file storage
    $shares = load_shares();
    foreach ($shares as $key => $id) {
        if ($id === $shareId) {
            list($username, $filePath) = explode(':', $key, 2);
            break;
        }
    }
    
    // If not found in file storage, try session as fallback
    if (!$filePath) {
        foreach ($_SESSION['file_shares'] as $key => $id) {
            if ($id === $shareId) {
                list($username, $filePath) = explode(':', $key, 2);
                break;
            }
        }
    }
    
    if (!$filePath) {
        log_debug("Share not found for ID: $shareId");
        log_debug("Session shares: " . var_export($_SESSION['file_shares'] ?? [], true));
        log_debug("File shares: " . var_export($shares ?? [], true));
        display_error("Shared file not found or link has expired.");
    }
    
    log_debug("Share found - User: $username, File: $filePath");
    
    // Get file name from path
    $fileName = basename($filePath);
    
    // Construct the actual file path - try multiple approaches
    $baseDir = "/var/www/html/webdav/users/$username/Home";
    
    // Try different path combinations
    $possiblePaths = [
        $baseDir . '/' . $filePath,                    // Standard path
        $baseDir . '/' . ltrim($filePath, '/'),        // Remove leading slash
        $baseDir . ltrim($filePath, 'Home/'),          // If path includes 'Home/'
        $baseDir . ltrim($filePath, '/Home/'),         // If path includes '/Home/'
        realpath($baseDir) . '/' . ltrim($filePath, '/') // Using realpath for base
    ];
    
    $actualFilePath = null;
    foreach ($possiblePaths as $path) {
        log_debug("Trying path: $path");
        if (file_exists($path)) {
            $actualFilePath = $path;
            log_debug("Found file at: $actualFilePath");
            break;
        }
    }
    
    // If file not found, try a more permissive approach
    if (!$actualFilePath) {
        log_debug("File not found with standard paths, trying to find file by name");
        
        // Try to find the file by name in the user's directory
        $fileName = basename($filePath);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $fileName) {
                $actualFilePath = $file->getPathname();
                log_debug("Found file by name at: $actualFilePath");
                break;
            }
        }
    }
    
    if (!$actualFilePath || !file_exists($actualFilePath)) {
        log_debug("File not found: Tried multiple paths but couldn't locate the file");
        display_error("Shared file not found or has been moved.", 404);
    }
    
    // More permissive security check - just make sure it's somewhere in the user's directory
    $realBaseDir = realpath($baseDir);
    $realFilePath = realpath($actualFilePath);
    
    if ($realFilePath === false || strpos($realFilePath, $realBaseDir) !== 0) {
        log_debug("Security violation - attempted access outside user directory: $actualFilePath");
        log_debug("Base directory: $realBaseDir");
        log_debug("Resolved file path: " . ($realFilePath ?: "Invalid path"));
        display_error("Access denied: File is outside the allowed directory.", 403);
    }
    
    log_debug("Security check passed - file is within user's directory");
    log_debug("Actual file path: $actualFilePath");
    
    // Check if this is a preview page request or direct download
    $isPreviewPage = !isset($_GET['preview']) && !isset($_GET['download']);
    $isPreview = isset($_GET['preview']);
    $isDownload = isset($_GET['download']);
    
    // Get file extension and determine content type
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Try to guess the file type from extension
    $fileType = 'application/octet-stream';
    switch ($extension) {
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
        default:
            $fileType = 'application/octet-stream';
    }
    
    if ($isPreviewPage) {
        // Show the preview page with file info and download button
        create_preview_page($fileName, $fileType, $shareId, $username, $filePath);
    } else {
        // Serve the actual file (either for preview or download)
        serve_actual_file($actualFilePath, $fileName, $fileType, $isDownload);
    }
    
} catch (Exception $e) {
    log_debug("Unexpected error: " . $e->getMessage());
    display_error("Server error: " . $e->getMessage(), 500);
} 