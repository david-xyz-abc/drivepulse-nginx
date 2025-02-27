<?php
session_start();

// Debug log setup with toggle
define('DEBUG', false);
$debug_log = '/var/www/html/selfhostedgdrive/debug.log';
function log_debug($message) {
    if (DEBUG) {
        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}

// Connect to the database
$db_path = '/var/www/html/selfhostedgdrive/shared_files.db';
$db = new SQLite3($db_path);

// Process the share code
if (isset($_GET['code'])) {
    $shareCode = $_GET['code'];
    $stmt = $db->prepare('SELECT owner, filepath, expires_at FROM shared_files WHERE share_code = ? AND (expires_at IS NULL OR expires_at > datetime("now"))');
    $stmt->bindParam(1, $shareCode);
    $result = $stmt->execute();
    
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $owner = $row['owner'];
        $filepath = $row['filepath'];
        $fullPath = "/var/www/html/webdav/users/$owner/Home/$filepath";
        
        if (file_exists($fullPath)) {
            $filename = basename($fullPath);
            $mime_types = [
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'heic' => 'image/heic',
                'mkv' => 'video/x-matroska',
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                'ogg' => 'video/ogg',
                'txt' => 'text/plain',
            ];
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mime = $mime_types[$ext] ?? mime_content_type($fullPath) ?? 'application/octet-stream';
            
            // Check if preview is requested
            if (isset($_GET['preview']) && in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'pdf', 'mp4', 'webm', 'ogg'])) {
                header("Content-Type: $mime");
                readfile($fullPath);
                exit;
            } else {
                // Otherwise serve as download
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header("Content-Type: $mime");
                header("Content-Length: " . filesize($fullPath));
                readfile($fullPath);
                exit;
            }
        } else {
            echo "Shared file no longer exists.";
        }
    } else {
        echo "Invalid or expired share link.";
    }
} else {
    echo "No share code provided.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shared File</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .message {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="message">
        <h2>Shared File</h2>
        <p>This shared file is invalid or has expired.</p>
    </div>
</body>
</html> 