<?php
// share.php - Handles displaying shared files to users

// Start session
session_start();

// Include configuration
require_once 'config.php';

// Get share ID from URL
$shareId = $_GET['id'] ?? '';

if (empty($shareId)) {
    die('Invalid share link');
}

// Database connection
$db = new SQLite3($dbPath);

// Get share information
$stmt = $db->prepare('SELECT * FROM shares WHERE id = :id');
$stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
$result = $stmt->execute();
$share = $result->fetchArray(SQLITE3_ASSOC);

// Check if share exists
if (!$share) {
    die('Share not found or has been removed');
}

// Check if share has expired
if ($share['expiry_time'] > 0 && $share['expiry_time'] < time()) {
    die('This share link has expired');
}

// Check if file exists
$filePath = $share['file_path'];
if (!file_exists($filePath)) {
    die('The shared file no longer exists');
}

// Get file information
$fileName = basename($filePath);
$fileSize = filesize($filePath);
$fileType = mime_content_type($filePath);
$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);

// Check if password is required
$passwordRequired = !empty($share['password']);
$passwordVerified = isset($_SESSION['verified_shares'][$shareId]) && $_SESSION['verified_shares'][$shareId] === true;

// Handle password verification
if ($passwordRequired && !$passwordVerified) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $password = $_POST['password'];
        
        if (password_verify($password, $share['password'])) {
            // Password is correct, mark as verified
            $_SESSION['verified_shares'][$shareId] = true;
            $passwordVerified = true;
        } else {
            $passwordError = 'Incorrect password';
        }
    }
}

// If password is required and not verified, show password form
if ($passwordRequired && !$passwordVerified) {
    // Show password form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Password Protected Share</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
        <style>
            :root {
                --accent-color: #3498db;
                --accent-red: #e74c3c;
                --text-color: #333;
                --bg-color: #f5f5f5;
                --content-bg: #fff;
                --border-color: #ddd;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: var(--bg-color);
                color: var(--text-color);
                margin: 0;
                padding: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }
            
            .password-container {
                background-color: var(--content-bg);
                border-radius: 8px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                padding: 30px;
                width: 90%;
                max-width: 400px;
                text-align: center;
            }
            
            .lock-icon {
                font-size: 48px;
                color: var(--accent-red);
                margin-bottom: 20px;
            }
            
            h1 {
                margin: 0 0 20px;
                font-size: 24px;
                color: var(--text-color);
            }
            
            p {
                margin-bottom: 20px;
                color: #666;
            }
            
            .file-info {
                background-color: rgba(0, 0, 0, 0.03);
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
                text-align: left;
            }
            
            .file-info p {
                margin: 5px 0;
                display: flex;
                align-items: center;
            }
            
            .file-info i {
                width: 20px;
                margin-right: 10px;
                color: var(--accent-color);
            }
            
            form {
                margin-top: 20px;
            }
            
            input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                margin-bottom: 15px;
                box-sizing: border-box;
            }
            
            button {
                background-color: var(--accent-color);
                color: white;
                border: none;
                padding: 12px 20px;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 500;
                width: 100%;
            }
            
            button:hover {
                background-color: #2980b9;
            }
            
            .error-message {
                color: var(--accent-red);
                margin-bottom: 15px;
            }
        </style>
    </head>
    <body>
        <div class="password-container">
            <i class="fas fa-lock lock-icon"></i>
            <h1>Password Protected File</h1>
            <p>This file is password protected. Please enter the password to access it.</p>
            
            <div class="file-info">
                <p><i class="fas fa-file"></i> <?php echo htmlspecialchars($fileName); ?></p>
                <p><i class="fas fa-weight"></i> <?php echo formatFileSize($fileSize); ?></p>
            </div>
            
            <?php if (isset($passwordError)): ?>
                <div class="error-message"><?php echo $passwordError; ?></div>
            <?php endif; ?>
            
            <form method="post">
                <input type="password" name="password" placeholder="Enter password" required>
                <button type="submit">Access File</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Increment access count
$stmt = $db->prepare('UPDATE shares SET access_count = access_count + 1 WHERE id = :id');
$stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
$stmt->execute();

// Function to format file size
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Determine if file is viewable in browser
$isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
$isPdf = $fileExtension === 'pdf';
$isVideo = in_array($fileExtension, ['mp4', 'webm', 'ogg']);
$isAudio = in_array($fileExtension, ['mp3', 'wav', 'ogg']);
$isText = in_array($fileExtension, ['txt', 'md', 'html', 'css', 'js', 'json', 'xml']);

// Determine icon class based on file type
function getIconClass($extension) {
    $iconMap = [
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint',
        'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive', '7z' => 'fa-file-archive',
        'mp3' => 'fa-file-audio', 'wav' => 'fa-file-audio',
        'mp4' => 'fa-file-video', 'avi' => 'fa-file-video', 'mov' => 'fa-file-video',
        'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image', 
        'gif' => 'fa-file-image', 'svg' => 'fa-file-image',
        'txt' => 'fa-file-alt', 'md' => 'fa-file-alt',
        'html' => 'fa-file-code', 'css' => 'fa-file-code', 'js' => 'fa-file-code'
    ];
    
    return isset($iconMap[$extension]) ? $iconMap[$extension] : 'fa-file';
}

// Get icon class
$iconClass = getIconClass(strtolower($fileExtension));

// Handle direct download request
if (isset($_GET['download'])) {
    // Set headers for download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $fileSize);
    
    // Output file
    readfile($filePath);
    exit;
}

// If file is viewable in browser, display it
if ($isImage || $isPdf || $isVideo || $isAudio || $isText) {
    // Display file in browser
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($fileName); ?> - Shared File</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
        <style>
            :root {
                --accent-color: #3498db;
                --accent-red: #e74c3c;
                --text-color: #333;
                --bg-color: #f5f5f5;
                --content-bg: #fff;
                --border-color: #ddd;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: var(--bg-color);
                color: var(--text-color);
                margin: 0;
                padding: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }
            
            .header {
                background-color: var(--content-bg);
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
                padding: 15px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .file-info {
                display: flex;
                align-items: center;
            }
            
            .file-icon {
                font-size: 24px;
                margin-right: 15px;
                color: var(--accent-color);
            }
            
            .file-details h1 {
                margin: 0;
                font-size: 18px;
                font-weight: 500;
            }
            
            .file-details p {
                margin: 5px 0 0;
                font-size: 14px;
                color: #666;
            }
            
            .actions {
                display: flex;
                gap: 10px;
            }
            
            .btn {
                background-color: var(--accent-color);
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 500;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
            }
            
            .btn i {
                margin-right: 8px;
            }
            
            .btn-download {
                background-color: #2ecc71;
            }
            
            .content {
                flex: 1;
                padding: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
                overflow: auto;
            }
            
            .preview-container {
                max-width: 100%;
                max-height: 100%;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            img.preview {
                max-width: 100%;
                max-height: 80vh;
                object-fit: contain;
            }
            
            video.preview, audio.preview {
                max-width: 100%;
            }
            
            .pdf-preview {
                width: 100%;
                height: 80vh;
            }
            
            .text-preview {
                width: 100%;
                max-width: 800px;
                background-color: var(--content-bg);
                padding: 20px;
                border-radius: 4px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
                white-space: pre-wrap;
                overflow: auto;
                font-family: monospace;
            }
            
            @media (max-width: 768px) {
                .header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                
                .actions {
                    margin-top: 15px;
                    width: 100%;
                }
                
                .btn {
                    flex: 1;
                    justify-content: center;
                }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="file-info">
                <i class="fas <?php echo $iconClass; ?> file-icon"></i>
                <div class="file-details">
                    <h1><?php echo htmlspecialchars($fileName); ?></h1>
                    <p><?php echo formatFileSize($fileSize); ?></p>
                </div>
            </div>
            <div class="actions">
                <a href="?id=<?php echo $shareId; ?>&download=1" class="btn btn-download">
                    <i class="fas fa-download"></i> Download
                </a>
            </div>
        </div>
        
        <div class="content">
            <div class="preview-container">
                <?php if ($isImage): ?>
                    <img src="file_proxy.php?share_id=<?php echo $shareId; ?>" class="preview" alt="<?php echo htmlspecialchars($fileName); ?>">
                <?php elseif ($isPdf): ?>
                    <iframe src="file_proxy.php?share_id=<?php echo $shareId; ?>" class="pdf-preview" frameborder="0"></iframe>
                <?php elseif ($isVideo): ?>
                    <video controls class="preview">
                        <source src="file_proxy.php?share_id=<?php echo $shareId; ?>" type="<?php echo $fileType; ?>">
                        Your browser does not support the video tag.
                    </video>
                <?php elseif ($isAudio): ?>
                    <audio controls class="preview">
                        <source src="file_proxy.php?share_id=<?php echo $shareId; ?>" type="<?php echo $fileType; ?>">
                        Your browser does not support the audio tag.
                    </audio>
                <?php elseif ($isText): ?>
                    <div class="text-preview"><?php echo htmlspecialchars(file_get_contents($filePath)); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    // Show download page for non-viewable files
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($fileName); ?> - Shared File</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
        <style>
            :root {
                --accent-color: #3498db;
                --accent-red: #e74c3c;
                --text-color: #333;
                --bg-color: #f5f5f5;
                --content-bg: #fff;
                --border-color: #ddd;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: var(--bg-color);
                color: var(--text-color);
                margin: 0;
                padding: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }
            
            .download-container {
                background-color: var(--content-bg);
                border-radius: 8px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                padding: 30px;
                width: 90%;
                max-width: 500px;
                text-align: center;
            }
            
            .file-icon {
                font-size: 64px;
                color: var(--accent-color);
                margin-bottom: 20px;
            }
            
            h1 {
                margin: 0 0 10px;
                font-size: 24px;
                color: var(--text-color);
                word-break: break-word;
            }
            
            p {
                margin-bottom: 20px;
                color: #666;
            }
            
            .file-info {
                background-color: rgba(0, 0, 0, 0.03);
                padding: 15px;
                border-radius: 4px;
                margin: 20px 0;
                text-align: left;
            }
            
            .file-info p {
                margin: 5px 0;
                display: flex;
                align-items: center;
            }
            
            .file-info i {
                width: 20px;
                margin-right: 10px;
                color: var(--accent-color);
            }
            
            .download-btn {
                background-color: #2ecc71;
                color: white;
                border: none;
                padding: 15px 30px;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 500;
                display: inline-flex;
                align-items: center;
                text-decoration: none;
                font-size: 16px;
            }
            
            .download-btn:hover {
                background-color: #27ae60;
            }
            
            .download-btn i {
                margin-right: 10px;
            }
        </style>
    </head>
    <body>
        <div class="download-container">
            <i class="fas <?php echo $iconClass; ?> file-icon"></i>
            <h1><?php echo htmlspecialchars($fileName); ?></h1>
            <p>This file has been shared with you</p>
            
            <div class="file-info">
                <p><i class="fas fa-file"></i> <?php echo htmlspecialchars($fileName); ?></p>
                <p><i class="fas fa-weight"></i> <?php echo formatFileSize($fileSize); ?></p>
                <p><i class="fas fa-file-alt"></i> <?php echo strtoupper($fileExtension); ?> File</p>
            </div>
            
            <a href="?id=<?php echo $shareId; ?>&download=1" class="download-btn">
                <i class="fas fa-download"></i> Download File
            </a>
        </div>
    </body>
    </html>
    <?php
}

// Close database connection
$db->close();
?> 