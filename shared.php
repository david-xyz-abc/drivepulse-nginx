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
$debug_log = __DIR__ . '/share_debug.log';
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

if (isset($_GET['create_test_share'])) {
    $shares_file = __DIR__ . '/shares.json';
    
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
    
    function save_test_shares($shares) {
        global $shares_file;
        file_put_contents($shares_file, json_encode($shares));
    }
    
    $testShareId = 'test' . bin2hex(random_bytes(8));
    $testFilePath = 'test_file.txt';
    $testUsername = 'test_user';
    $testKey = $testUsername . ':' . $testFilePath;
    
    $shares = load_test_shares();
    $shares[$testKey] = $testShareId;
    save_test_shares($shares);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Share Created</title>
    <style>
        :root {
            --primary-red: #e63946;
            --dark-red: #b32d38;
            --light-red: #f94144;
            --background: #f8f8f8;
            --shadow: rgba(230, 57, 70, 0.2);
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, var(--background), #fff);
            min-height: 100vh;
            margin: 0;
            padding: 2rem;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 10px 30px var(--shadow);
            animation: slideUp 0.6s ease-out;
        }
        h1 {
            color: var(--primary-red);
            margin-bottom: 1.5rem;
        }
        .success {
            color: var(--primary-red);
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .link {
            background: rgba(230, 57, 70, 0.1);
            padding: 1rem;
            border-radius: 10px;
            margin: 1.5rem 0;
            word-break: break-all;
        }
        .link a {
            color: var(--primary-red);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .link a:hover {
            color: var(--dark-red);
        }
        .btn {
            display: inline-block;
            background: var(--primary-red);
            color: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px var(--shadow);
        }
        .btn:hover {
            background: var(--dark-red);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--shadow);
        }
        p {
            margin: 0.5rem 0;
        }
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Share Created</h1>
        <p class="success">Successfully created a test share!</p>
        <p><strong>Share ID:</strong> <?php echo htmlspecialchars($testShareId); ?></p>
        <p><strong>File Path:</strong> <?php echo htmlspecialchars($testFilePath); ?></p>
        <p><strong>Username:</strong> <?php echo htmlspecialchars($testUsername); ?></p>
        <p><strong>Share Link:</strong></p>
        <div class="link">
            <a href="shared.php?id=<?php echo htmlspecialchars($testShareId); ?>">
                <?php echo htmlspecialchars(get_base_url() . '/shared.php?id=' . $testShareId); ?>
            </a>
        </div>
        <a href="shared.php?id=<?php echo htmlspecialchars($testShareId); ?>" class="btn">Open Share</a>
    </div>
</body>
</html>
<?php
    exit;
}

if (isset($_GET['view_all_shares']) && DEBUG) {
    $shares_file = __DIR__ . '/shares.json';
    
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Shares</title>
    <style>
        :root {
            --primary-red: #e63946;
            --dark-red: #b32d38;
            --light-red: #f94144;
            --shadow: rgba(230, 57, 70, 0.2);
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #f8f8f8, #fff);
            min-height: 100vh;
            margin: 0;
            padding: 2rem;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 10px 30px var(--shadow);
            animation: slideUp 0.6s ease-out;
        }
        h1, h2 {
            color: var(--primary-red);
            margin-bottom: 1.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(230, 57, 70, 0.1);
        }
        th {
            background: rgba(230, 57, 70, 0.1);
            font-weight: 600;
        }
        tr {
            transition: all 0.3s ease;
        }
        tr:hover {
            background: rgba(230, 57, 70, 0.05);
            transform: translateX(5px);
        }
        .btn {
            display: inline-block;
            background: var(--primary-red);
            color: white;
            padding: 0.8rem 1.5rem;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
        }
        .btn:hover {
            background: var(--dark-red);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--shadow);
        }
        .btn-blue {
            background: #007bff;
        }
        .btn-blue:hover {
            background: #0069d9;
        }
        .empty {
            padding: 2rem;
            text-align: center;
            color: #666;
            font-style: italic;
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
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes slideLeft {
            from { transform: translateX(30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes progressBar {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>All Shares</h1>
        
        <div class="debug-info">
            <p><strong>Session ID:</strong> <?php echo htmlspecialchars(session_id()); ?></p>
            <p><strong>Shares File:</strong> <?php echo htmlspecialchars($shares_file); ?> (Exists: <?php echo file_exists($shares_file) ? 'Yes' : 'No'; ?>)</p>
            <a href="shared.php?create_test_share=1" class="btn">Create Test Share</a>
        </div>
        
        <h2>File-Based Shares</h2>
        <?php if (empty($shares)): ?>
            <p class="empty">No file-based shares found.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>User</th>
                    <th>File Path</th>
                    <th>Share ID</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($shares as $key => $id):
                    list($user, $path) = explode(':', $key, 2); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user); ?></td>
                        <td><?php echo htmlspecialchars($path); ?></td>
                        <td><?php echo htmlspecialchars($id); ?></td>
                        <td>
                            <a href="shared.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-blue" target="_blank">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <h2>Session-Based Shares</h2>
        <?php if (empty($sessionShares)): ?>
            <p class="empty">No session-based shares found.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>User</th>
                    <th>File Path</th>
                    <th>Share ID</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($sessionShares as $key => $id):
                    list($user, $path) = explode(':', $key, 2); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user); ?></td>
                        <td><?php echo htmlspecialchars($path); ?></td>
                        <td><?php echo htmlspecialchars($id); ?></td>
                        <td>
                            <a href="shared.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-blue" target="_blank">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
    exit;
}

log_debug("=== Shared File Access Request ===");
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

function create_preview_page($fileName, $fileType, $shareId, $username = null, $filePath = null) {
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $canPreview = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'html', 'htm', 'mp4', 'mp3']);
    
    // Get file size if available
    $fileSize = "Unknown";
    
    // Find the actual file path using the same logic as in the main code
    $actualFilePath = null;
    if ($username && $filePath) {
        $baseDir = "/var/www/html/webdav/users/$username/Home";
        
        $possiblePaths = [
            $baseDir . '/' . $filePath,
            $baseDir . '/' . ltrim($filePath, '/'),
            $baseDir . ltrim($filePath, 'Home/'),
            $baseDir . ltrim($filePath, '/Home/'),
            realpath($baseDir) . '/' . ltrim($filePath, '/')
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $actualFilePath = $path;
                break;
            }
        }
        
        // If still not found, try to find by filename
        if (!$actualFilePath) {
            $fileBaseName = basename($filePath);
            if (is_dir($baseDir)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === $fileBaseName) {
                        $actualFilePath = $file->getPathname();
                        break;
                    }
                }
            }
        }
        
        // Now get the file size if we found the file
        if ($actualFilePath && file_exists($actualFilePath)) {
            $bytes = filesize($actualFilePath);
            if ($bytes !== false) {
                $fileSize = formatFileSize($bytes);
            }
        }
    }
    
    $debugInfo = '';
    if (DEBUG) {
        // Simplified debug info with file details
        $debugInfo = '
        <div class="debug-info">
            <h3>Details</h3>
            <p><strong>File Name:</strong> ' . htmlspecialchars($fileName) . '</p>
            <p><strong>File Size:</strong> ' . htmlspecialchars($fileSize) . '</p>
        </div>';
    }
    
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Preview: <?php echo htmlspecialchars($fileName); ?></title>
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
            padding: 2rem;
            background-image: 
                linear-gradient(to bottom, var(--red-glow) 0%, transparent 70%),
                linear-gradient(var(--texture-color) 1px, transparent 1px),
                linear-gradient(90deg, var(--texture-color) 1px, transparent 1px);
            background-size: 100% 100%, 20px 20px, 20px 20px;
            background-position: center top;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            max-width: 1200px;
            width: 100%;
            padding: 2rem;
            background: var(--content-bg);
            border-radius: 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.6s ease-out;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .file-info {
            padding: 1.5rem;
            background: var(--sidebar-bg);
            color: var(--text-color);
            border-radius: 0;
            margin-bottom: 2rem;
            animation: fadeInDown 0.8s ease-out;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .file-name {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }
        .preview-container {
            position: relative;
            margin: 2rem auto;
            padding: 2rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            animation: scaleIn 0.7s ease-out;
            overflow: hidden;
            border: 1px solid var(--border-color);
            max-width: 800px;
        }
        .preview-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-red), #b71c1c);
            animation: progressBar 2s infinite;
        }
        .preview-container img,
        .preview-container video {
            max-width: 100%;
            max-height: 500px;
            border-radius: 0;
            transition: transform 0.3s ease;
            margin: 0 auto;
            display: block;
        }
        .preview-container img:hover,
        .preview-container video:hover {
            transform: scale(1.02);
        }
        .preview-container audio {
            width: 100%;
            margin: 1rem auto;
            display: block;
        }
        .preview-text {
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0;
            max-height: 500px;
            overflow: auto;
            font-family: 'Roboto Mono', monospace;
            animation: fadeIn 1s ease-out;
            color: var(--text-color);
            border: 1px solid var(--border-color);
            text-align: left;
        }
        .download-btn-container {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        .download-btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: var(--accent-red);
            color: var(--text-color);
            text-decoration: none;
            border-radius: 0;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            border: none;
            cursor: pointer;
        }
        .download-btn:hover {
            background: #b71c1c;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }
        .no-preview {
            padding: 3rem;
            text-align: center;
            color: var(--text-color);
            font-style: italic;
            animation: fadeIn 1s ease-out;
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
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideLeft {
            from { transform: translateX(30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes progressBar {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes scaleIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        @keyframes fadeInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            .file-name {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="file-info">
            <div class="file-name"><?php echo htmlspecialchars($fileName); ?></div>
        </div>

        <div class="preview-container">
            <?php
            if ($canPreview) {
                $previewUrl = "?id=" . urlencode($shareId) . "&preview=1";
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    echo '<img src="' . htmlspecialchars($previewUrl) . '" alt="' . htmlspecialchars($fileName) . '">';
                } elseif ($extension === 'pdf') {
                    echo '<iframe src="' . htmlspecialchars($previewUrl) . '" width="100%" height="500px" style="border: none; border-radius: 0;"></iframe>';
                } elseif (in_array($extension, ['txt', 'html', 'htm'])) {
                    if ($username && $filePath) {
                        $baseDir = "/var/www/html/webdav/users/$username/Home";
                        $actualFilePath = realpath($baseDir . '/' . $filePath);
                        if (file_exists($actualFilePath)) {
                            $content = file_get_contents($actualFilePath, false, null, 0, 50 * 1024);
                            $isPartial = filesize($actualFilePath) > 50 * 1024;
                            if ($extension === 'txt') {
                                echo '<div class="preview-text">' . htmlspecialchars($content) . ($isPartial ? "\n\n[Truncated...]" : "") . '</div>';
                            } else {
                                $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "<!-- Script Removed -->", $content);
                                echo '<div class="preview-text">' . $content . ($isPartial ? "\n\n<!-- Truncated... -->" : "") . '</div>';
                            }
                        } else {
                            echo '<div class="no-preview">File not accessible</div>';
                        }
                    }
                } elseif ($extension === 'mp4') {
                    echo '<video controls><source src="' . htmlspecialchars($previewUrl) . '" type="video/mp4">Video not supported</video>';
                } elseif ($extension === 'mp3') {
                    echo '<audio controls><source src="' . htmlspecialchars($previewUrl) . '" type="audio/mpeg">Audio not supported</audio>';
                }
            } else {
                echo '<div class="no-preview">No preview available</div>';
            }
            ?>
        </div>

        <div class="download-btn-container">
            <a href="?id=<?php echo htmlspecialchars($shareId); ?>&download=1" class="download-btn">Download Now</a>
        </div>
        
        <?php echo $debugInfo; ?>
    </div>
</body>
</html>
<?php
    exit;
}

function serve_actual_file($filePath, $fileName, $fileType = 'application/octet-stream', $forceDownload = false) {
    if (!file_exists($filePath)) {
        log_debug("File not found: $filePath");
        display_error("File not found or has been removed.");
        return;
    }
    
    if (!is_readable($filePath)) {
        log_debug("File not readable: $filePath");
        log_debug("File permissions: " . substr(sprintf('%o', fileperms($filePath)), -4));
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            log_debug("PHP process user: " . posix_getpwuid(posix_geteuid())['name'] ?? 'unknown');
        }
        @chmod($filePath, 0644);
        if (!is_readable($filePath)) {
            display_error("Permission denied: Cannot read the file.");
            return;
        }
    }
    
    $fileSize = filesize($filePath);
    log_debug("Serving actual file: $filePath ($fileSize bytes)");
    
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
    
    if ($fileSize < 10 * 1024 * 1024) {
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
    
    try {
        $handle = @fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle) && !connection_aborted()) {
                echo @fread($handle, 8192);
                flush();
            }
            fclose($handle);
            exit;
        }
        log_debug("fopen failed, trying alternative methods");
    } catch (Exception $e) {
        log_debug("fopen/fread exception: " . $e->getMessage());
    }
    
    try {
        if ($fileSize < 5 * 1024 * 1024) {
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
    
    log_debug("All file serving methods failed");
    display_error("Failed to read file. Please try again later.");
}

function load_inactive_shares() {
    $inactive_shares_file = __DIR__ . '/inactive_shares.json';
    if (file_exists($inactive_shares_file)) {
        $content = file_get_contents($inactive_shares_file);
        if (!empty($content)) {
            return json_decode($content, true) ?: [];
        }
    }
    return [];
}

try {
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        log_debug("No share ID provided");
        display_error("Invalid share link.", 400);
    }
    
    $shareId = $_GET['id'];
    log_debug("Requested share ID: $shareId");
    
    if (!isset($_SESSION['file_shares'])) {
        $_SESSION['file_shares'] = [];
    }
    
    $shares_file = __DIR__ . '/shares.json';

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

    $filePath = null;
    $username = null;
    $shares = load_shares();
    $inactiveShares = load_inactive_shares();
    
    log_debug("Looking for share ID: $shareId in " . count($shares) . " shares");
    
    // Check if the share ID exists in inactive shares first
    $isInactive = false;
    foreach ($inactiveShares as $key => $id) {
        if ($id === $shareId) {
            log_debug("Share ID found in inactive shares: $shareId");
            display_error("This share link has been disabled by the owner.");
        }
    }
    
    // First pass: Look for exact match
    foreach ($shares as $key => $id) {
        if ($id === $shareId) {
            list($username, $filePath) = explode(':', $key, 2);
            log_debug("Found exact match for share ID: $shareId -> $key");
            break;
        }
    }
    
    // Second pass: Check session shares if not found in persistent shares
    if (!$filePath && isset($_SESSION['file_shares']) && is_array($_SESSION['file_shares'])) {
        log_debug("Checking session shares for ID: $shareId");
        foreach ($_SESSION['file_shares'] as $key => $id) {
            if ($id === $shareId) {
                list($username, $filePath) = explode(':', $key, 2);
                log_debug("Found match in session shares: $shareId -> $key");
                
                // Add to persistent shares for consistency
                $shares[$key] = $id;
                @file_put_contents($shares_file, json_encode($shares, JSON_PRETTY_PRINT));
                break;
            }
        }
    }
    
    // If still not found, try case-insensitive comparison as a fallback
    if (!$filePath) {
        log_debug("Trying case-insensitive comparison for share ID: $shareId");
        foreach ($shares as $key => $id) {
            if (strcasecmp($id, $shareId) === 0) {
                list($username, $filePath) = explode(':', $key, 2);
                log_debug("Found case-insensitive match: $id -> $key");
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
    $fileName = basename($filePath);
    $baseDir = "/var/www/html/webdav/users/$username/Home";
    
    $possiblePaths = [
        $baseDir . '/' . $filePath,
        $baseDir . '/' . ltrim($filePath, '/'),
        $baseDir . ltrim($filePath, 'Home/'),
        $baseDir . ltrim($filePath, '/Home/'),
        realpath($baseDir) . '/' . ltrim($filePath, '/')
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
    
    if (!$actualFilePath) {
        log_debug("File not found with standard paths, trying to find file by name");
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
    
    $isPreviewPage = !isset($_GET['preview']) && !isset($_GET['download']);
    $isPreview = isset($_GET['preview']);
    $isDownload = isset($_GET['download']);
    
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
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
    }
    
    if ($isPreviewPage) {
        create_preview_page($fileName, $fileType, $shareId, $username, $filePath);
    } else {
        serve_actual_file($actualFilePath, $fileName, $fileType, $isDownload);
    }
    
} catch (Exception $e) {
    log_debug("Unexpected error: " . $e->getMessage());
    display_error("Server error: " . $e->getMessage(), 500);
}