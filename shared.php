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
 */
function create_preview_page($fileName, $fileType, $shareId) {
    // Get file extension
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Determine if we can preview this file type
    $canPreview = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'html', 'htm', 'mp4', 'mp3']);
    
    // Add debug information if DEBUG is enabled
    $debugInfo = '';
    if (DEBUG) {
        global $shares_file;
        
        // Calculate file sizes for different formats
        $txtSize = number_format(10 * 1024 + rand(0, 490 * 1024));
        $imgSize = number_format(50 * 1024 + rand(0, 1950 * 1024));
        $docSize = number_format(100 * 1024 + rand(0, 2900 * 1024));
        $videoSize = number_format(2 * 1024 * 1024 + rand(0, 3 * 1024 * 1024));
        
        $debugInfo = '
        <div class="debug-info" style="margin-top: 30px; padding: 15px; background-color: #f8f8f8; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin-top: 0; color: #666;">Debug Information</h3>
            <p><strong>Share ID:</strong> ' . htmlspecialchars($shareId) . '</p>
            <p><strong>File Name:</strong> ' . htmlspecialchars($fileName) . '</p>
            <p><strong>File Type:</strong> ' . htmlspecialchars($fileType) . '</p>
            <p><strong>Session ID:</strong> ' . htmlspecialchars(session_id()) . '</p>
            <p><strong>Shares File:</strong> ' . htmlspecialchars($shares_file) . ' (Exists: ' . (file_exists($shares_file) ? 'Yes' : 'No') . ')</p>
            
            <h4 style="margin-top: 15px; color: #666;">Download Options</h4>
            <p>The file you download will be a dummy file with appropriate size and format.</p>
            <ul>
                <li>Text files: ~' . $txtSize . ' bytes</li>
                <li>Images: ~' . $imgSize . ' bytes</li>
                <li>Documents: ~' . $docSize . ' bytes</li>
                <li>Video/Audio: ~' . $videoSize . ' bytes</li>
            </ul>
            
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
        // Create a dummy preview URL that would serve the actual file content
        $previewUrl = "?id=" . urlencode($shareId) . "&preview=1";
        
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            echo '<img src="' . htmlspecialchars($previewUrl) . '" alt="' . htmlspecialchars($fileName) . '">';
        } elseif ($extension === 'pdf') {
            echo '<iframe src="' . htmlspecialchars($previewUrl) . '" width="100%" height="500px" style="border: none;"></iframe>';
        } elseif (in_array($extension, ['txt', 'html', 'htm'])) {
            // For text files, we'll show a placeholder
            echo '<div class="preview-text">This is a preview of the text content for ' . htmlspecialchars($fileName) . '.</div>';
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
 * Serve a dummy file for download or preview
 * @param string $fileName The name of the file
 * @param string $fileType The MIME type of the file
 */
function serve_dummy_file($fileName, $fileType = 'text/plain') {
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Generate a more realistic file size (between 10KB and 5MB)
    $minSize = 10 * 1024;        // 10KB
    $maxSize = 5 * 1024 * 1024;  // 5MB
    $defaultSize = 100 * 1024;   // 100KB default
    
    // Determine appropriate size based on file type
    switch ($extension) {
        case 'txt':
        case 'html':
        case 'htm':
        case 'css':
        case 'js':
        case 'json':
        case 'xml':
            $targetSize = rand($minSize, 500 * 1024); // Text files: 10KB - 500KB
            break;
        case 'pdf':
        case 'doc':
        case 'docx':
        case 'xls':
        case 'xlsx':
        case 'ppt':
        case 'pptx':
            $targetSize = rand(100 * 1024, 3 * 1024 * 1024); // Documents: 100KB - 3MB
            break;
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            $targetSize = rand(50 * 1024, 2 * 1024 * 1024); // Images: 50KB - 2MB
            break;
        case 'mp3':
        case 'wav':
            $targetSize = rand(1 * 1024 * 1024, $maxSize); // Audio: 1MB - 5MB
            break;
        case 'mp4':
        case 'avi':
        case 'mov':
        case 'webm':
            $targetSize = rand(2 * 1024 * 1024, $maxSize); // Video: 2MB - 5MB
            break;
        default:
            $targetSize = $defaultSize;
    }
    
    // Create appropriate dummy content based on file type
    switch ($extension) {
        case 'txt':
            // Generate Lorem Ipsum text
            $loremIpsum = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\n\n";
            $content = "This is a dummy text file for \"$fileName\".\n\nIn a production environment, this would be the actual content of the file.\n\n";
            
            // Repeat the lorem ipsum text until we reach the target size
            while (strlen($content) < $targetSize) {
                $content .= $loremIpsum;
            }
            
            // Trim to exact size
            $content = substr($content, 0, $targetSize);
            break;
            
        case 'html':
        case 'htm':
            $htmlTemplate = "<!DOCTYPE html>\n<html>\n<head>\n  <title>$fileName</title>\n  <style>\n    body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }\n    h1 { color: #333; }\n    .content { border: 1px solid #ddd; padding: 20px; }\n  </style>\n</head>\n<body>\n  <h1>HTML Preview</h1>\n  <div class='content'>\n    <p>This is a dummy HTML file for \"$fileName\".</p>\n    <p>In a production environment, this would be the actual content of the file.</p>\n";
            
            // Add paragraphs until we reach the target size
            $paragraph = "    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>\n";
            $htmlFooter = "  </div>\n</body>\n</html>";
            
            $content = $htmlTemplate;
            while (strlen($content . $htmlFooter) < $targetSize) {
                $content .= $paragraph;
            }
            $content .= $htmlFooter;
            break;
            
        case 'pdf':
            // For PDF, we'll create a binary file with PDF header
            $pdfHeader = "%PDF-1.5\n%¥±ë\n\n1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n\n2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n\n3 0 obj\n<</Type/Page/MediaBox[0 0 612 792]/Resources<<>>/Contents 4 0 R/Parent 2 0 R>>\nendobj\n\n4 0 obj\n<</Length 100>>\nstream\nBT\n/F1 12 Tf\n100 700 Td\n(Dummy PDF file for $fileName) Tj\nET\nendstream\nendobj\n\nxref\n0 5\n0000000000 65535 f\n0000000018 00000 n\n0000000065 00000 n\n0000000118 00000 n\n0000000217 00000 n\ntrailer\n<</Size 5/Root 1 0 R>>\nstartxref\n317\n%%EOF\n";
            
            // Add random binary data to reach target size
            $content = $pdfHeader;
            $bytesNeeded = $targetSize - strlen($content);
            if ($bytesNeeded > 0) {
                // Add random binary data
                $content .= random_bytes($bytesNeeded);
            }
            break;
            
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            // Generate a real image
            $width = 800;
            $height = 600;
            $image = imagecreatetruecolor($width, $height);
            
            // Fill with a gradient
            for ($i = 0; $i < $width; $i++) {
                $color = imagecolorallocate($image, 
                    255 - ($i / $width) * 155, 
                    100, 
                    100 + ($i / $width) * 155);
                imageline($image, $i, 0, $i, $height, $color);
            }
            
            // Add text
            $white = imagecolorallocate($image, 255, 255, 255);
            $font = 5; // Built-in font
            $text = "Dummy Image for: $fileName";
            $text_width = imagefontwidth($font) * strlen($text);
            $text_height = imagefontheight($font);
            $x = ($width - $text_width) / 2;
            $y = ($height - $text_height) / 2;
            
            // Add a background for the text
            imagefilledrectangle($image, $x - 10, $y - 10, $x + $text_width + 10, $y + $text_height + 10, imagecolorallocate($image, 0, 0, 0));
            imagestring($image, $font, $x, $y, $text, $white);
            
            // Output image
            ob_start();
            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    imagejpeg($image, null, 90);
                    break;
                case 'png':
                    imagepng($image, null, 6);
                    break;
                case 'gif':
                    imagegif($image);
                    break;
            }
            $content = ob_get_clean();
            imagedestroy($image);
            break;
            
        case 'mp3':
        case 'wav':
            // For audio files, create a binary file with appropriate header
            if ($extension == 'mp3') {
                // Simple MP3 header (not a valid MP3, but has the signature)
                $header = pack('H*', '494433030000000000');  // ID3v2 tag
            } else {
                // Simple WAV header (not a valid WAV, but has the signature)
                $header = pack('H*', '52494646'); // "RIFF"
                $header .= pack('V', $targetSize - 8); // File size - 8
                $header .= pack('H*', '57415645666D7420'); // "WAVEfmt "
            }
            
            // Add random binary data to reach target size
            $content = $header;
            $bytesNeeded = $targetSize - strlen($content);
            if ($bytesNeeded > 0) {
                $content .= random_bytes($bytesNeeded);
            }
            break;
            
        case 'mp4':
        case 'avi':
        case 'mov':
        case 'webm':
            // For video files, create a binary file with appropriate header
            if ($extension == 'mp4') {
                // Simple MP4 header (not a valid MP4, but has the signature)
                $header = pack('H*', '00000018667479706D703432'); // ftyp + mp42
            } elseif ($extension == 'avi') {
                // Simple AVI header
                $header = pack('H*', '52494646'); // "RIFF"
                $header .= pack('V', $targetSize - 8); // File size - 8
                $header .= pack('H*', '415649204C495354'); // "AVI LIST"
            } elseif ($extension == 'mov') {
                // Simple MOV header
                $header = pack('H*', '0000001466747970717420'); // ftyp + qt
            } else {
                // Simple WEBM header
                $header = pack('H*', '1A45DFA3');
            }
            
            // Add random binary data to reach target size
            $content = $header;
            $bytesNeeded = $targetSize - strlen($content);
            if ($bytesNeeded > 0) {
                $content .= random_bytes($bytesNeeded);
            }
            break;
            
        default:
            // For other file types, create a binary file with random data
            $content = "DUMMY FILE: $fileName\n\nThis is a dummy file generated for testing purposes.\nIn a production environment, this would be the actual content of the file.\n\n";
            
            // Add random binary data to reach target size
            $bytesNeeded = $targetSize - strlen($content);
            if ($bytesNeeded > 0) {
                $content .= random_bytes($bytesNeeded);
            }
    }
    
    // Set appropriate headers
    header('Content-Type: ' . $fileType);
    header('Content-Length: ' . strlen($content));
    
    // Output the content
    echo $content;
    exit;
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
        create_preview_page($fileName, $fileType, $shareId);
    } else {
        // Serve the dummy file (either for preview or download)
        if ($isDownload) {
            // Force download
            header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
        } else {
            // Inline display for preview
            header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
        }
        serve_dummy_file($fileName, $fileType);
    }
    
} catch (Exception $e) {
    log_debug("Unexpected error: " . $e->getMessage());
    display_error("Server error: " . $e->getMessage(), 500);
} 