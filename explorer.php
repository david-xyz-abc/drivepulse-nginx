<?php
session_start();

// Configure session for persistent login
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60); // 30 days
ini_set('session.cookie_lifetime', 30 * 24 * 60 * 60); // 30 days
session_set_cookie_params(30 * 24 * 60 * 60); // 30 days

// Debug log setup with toggle
define('DEBUG', false);
$debug_log = '/var/www/html/selfhostedgdrive/debug.log';
function log_debug($message) {
    if (DEBUG) {
        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}

// Ensure debug log exists and has correct permissions (run once during setup)
if (!file_exists($debug_log)) {
    file_put_contents($debug_log, "Debug log initialized\n");
    chown($debug_log, 'www-data');
    chmod($debug_log, 0666);
}

// Log request basics
log_debug("=== New Request ===");
log_debug("Session ID: " . session_id());
log_debug("Loggedin: " . (isset($_SESSION['loggedin']) ? var_export($_SESSION['loggedin'], true) : "Not set"));
log_debug("Username: " . (isset($_SESSION['username']) ? $_SESSION['username'] : "Not set"));
log_debug("GET params: " . var_export($_GET, true));

// Add this near the top of the file with other includes
if (!function_exists('imagecreatefromheic')) {
    function imagecreatefromheic($filename) {
        // Use ImageMagick as fallback if PHP's HEIC support is not available
        $output = '/tmp/' . uniqid() . '.jpg';
        exec("convert '$filename' '$output'");
        $image = imagecreatefromjpeg($output);
        unlink($output);
        return $image;
    }
}

// Optimized file serving with range support (no video-specific handling)
if (isset($_GET['action']) && $_GET['action'] === 'serve' && isset($_GET['file'])) {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
        log_debug("Unauthorized file request, redirecting to index.php");
        header("Location: /selfhostedgdrive/index.php", true, 302);
        exit;
    }

    $username = $_SESSION['username'];
    $baseDir = realpath("/var/www/html/webdav/users/$username/Home");
    if ($baseDir === false) {
        log_debug("Base directory not found for user: $username");
        header("HTTP/1.1 500 Internal Server Error");
        echo "Server configuration error.";
        exit;
    }

    $requestedFile = urldecode($_GET['file']);
    if (strpos($requestedFile, 'Home/') === 0) {
        $requestedFile = substr($requestedFile, 5);
    }
    $filePath = realpath($baseDir . '/' . $requestedFile);

    if ($filePath === false || strpos($filePath, $baseDir) !== 0 || !file_exists($filePath)) {
        log_debug("File not found or access denied: " . ($filePath ?: "Invalid path") . " (Requested: " . $_GET['file'] . ")");
        header("HTTP/1.1 404 Not Found");
        echo "File not found.";
        exit;
    }

    $fileSize = filesize($filePath);
    $fileName = basename($filePath);

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
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = $mime_types[$ext] ?? mime_content_type($filePath) ?? 'application/octet-stream';

    // If it's a HEIC file and preview is requested, convert to JPEG
    if ($ext === 'heic' && isset($_GET['preview'])) {
        header('Content-Type: image/jpeg');
        $output = '/tmp/' . uniqid() . '.jpg';
        exec("convert '$filePath' '$output'");
        readfile($output);
        unlink($output);
        exit;
    }

    // Add Content-Disposition header for downloads
    if (!isset($_GET['preview'])) {
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
    }

    header("Content-Type: $mime");
    header("Accept-Ranges: bytes");
    header("Content-Length: $fileSize");
    header("Cache-Control: private, max-age=31536000");
    header("X-Content-Type-Options: nosniff");

    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        log_debug("Failed to open file: $filePath");
        header("HTTP/1.1 500 Internal Server Error");
        echo "Unable to serve file.";
        exit;
    }

    ob_clean();

    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d+)-(\d*)?/', $range, $matches)) {
            $start = (int)$matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                log_debug("Invalid range request: $range for file size $fileSize");
                header("HTTP/1.1 416 Range Not Satisfiable");
                header("Content-Range: bytes */$fileSize");
                fclose($fp);
                exit;
            }

            $length = $end - $start + 1;
            header("HTTP/1.1 206 Partial Content");
            header("Content-Length: $length");
            header("Content-Range: bytes $start-$end/$fileSize");

            fseek($fp, $start);
            $remaining = $length;
            // Increase buffer size for video streaming
            $bufferSize = 262144; // 256KB chunks for better streaming
            
            // Check if this is a video file to apply optimized streaming
            $isVideo = in_array($ext, ['mp4', 'webm', 'ogg', 'mkv']);
            if ($isVideo) {
                // Force video MIME type
                header('Content-Type: video/mp4');
                header('Accept-Ranges: bytes');
                
                // Use larger buffer for video
                $bufferSize = 524288; // 512KB chunks
            }
            
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $chunk = min($remaining, $bufferSize);
                $data = fread($fp, $chunk);
                echo $data;
                flush();
                $remaining -= strlen($data);
                
                // Add a small delay for very large chunks to prevent overwhelming the network
                if ($chunk > 1048576 && connection_status() === CONNECTION_NORMAL) {
                    usleep(10000); // 10ms delay for very large chunks
                }
            }
        } else {
            log_debug("Malformed range header: $range");
            header("HTTP/1.1 416 Range Not Satisfiable");
            header("Content-Range: bytes */$fileSize");
            fclose($fp);
            exit;
        }
    } else {
        // For regular downloads, use a larger buffer but not too large
        $bufferSize = 8192;
        if (filesize($filePath) > 1048576) { // 1MB
            $bufferSize = 131072; // 128KB for larger files
        }
        
        while (!feof($fp) && !connection_aborted()) {
            echo fread($fp, $bufferSize);
            flush();
        }
    }

    fclose($fp);
    log_debug("Successfully served file: $filePath");
    exit;
}

// Check login for page access
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
    log_debug("Redirecting to index.php due to no login");
    header("Location: /selfhostedgdrive/index.php", true, 302);
    exit;
}

/************************************************
 * 1. Define the "Home" directory as base
 ************************************************/
$username = $_SESSION['username'];
$homeDirPath = "/var/www/html/webdav/users/$username/Home";
if (!file_exists($homeDirPath)) {
    log_debug("Creating home directory for user: $username");
    if (!mkdir($homeDirPath, 0777, true)) {
        log_debug("Failed to create home directory: $homeDirPath");
        header("HTTP/1.1 500 Internal Server Error");
        echo "Failed to create home directory.";
        exit;
    }
    chown($homeDirPath, 'www-data');
    chgrp($homeDirPath, 'www-data');
}
$baseDir = realpath($homeDirPath);
if ($baseDir === false) {
    log_debug("Base directory resolution failed for: $homeDirPath");
    header("HTTP/1.1 500 Internal Server Error");
    echo "Server configuration error.";
    exit;
}
log_debug("BaseDir: $baseDir (User: $username)");

// Redirect to Home if no folder specified
if (!isset($_GET['folder'])) {
    header("Location: /selfhostedgdrive/explorer.php?folder=Home");
    exit;
}

// Get current relative path
$currentRel = isset($_GET['folder']) ? trim($_GET['folder']) : 'Home';
if ($currentRel === '') $currentRel = 'Home';

// Resolve the current directory
$currentDir = realpath($baseDir . '/' . $currentRel);
if ($currentDir === false || strpos($currentDir, $baseDir) !== 0) {
    log_debug("Invalid directory requested: $currentRel");
    $currentRel = 'Home';
    $currentDir = $baseDir;
}

// Function to calculate directory size
function getDirSize($dir) {
    static $cache = [];
    if (isset($cache[$dir])) {
        return $cache[$dir];
    }

    $size = 0;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $size += $item->getSize();
    }

    $cache[$dir] = $size;
    return $size;
}

// Calculate storage usage for all users (pooled storage)
$webdavRoot = "/var/www/html/webdav";
$totalStorage = disk_total_space($webdavRoot); // Get total disk space
$usedStorage = getDirSize($webdavRoot); // Get used space for all users
$usedStorageGB = round($usedStorage / (1024 * 1024 * 1024), 2);
$totalStorageGB = round($totalStorage / (1024 * 1024 * 1024), 2);
$storagePercentage = round(($usedStorage / $totalStorage) * 100, 2);

/************************************************
 * 3. Create Folder
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder'])) {
    $folderName = trim($_POST['folder_name'] ?? '');
    if ($folderName !== '') {
        $targetPath = $currentDir . '/' . $folderName;
        if (!file_exists($targetPath)) {
            if (!mkdir($targetPath, 0777)) {
                log_debug("Failed to create folder: $targetPath");
                $_SESSION['error'] = "Failed to create folder.";
            } else {
                chown($targetPath, 'www-data');
                chgrp($targetPath, 'www-data');
                log_debug("Created folder: $targetPath");
            }
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 4. Upload Files
 ************************************************/
function getUniqueFilename($path, $filename, $isChunk = false) {
    // If this is a chunk upload and a file is in progress, use the existing filename
    if ($isChunk && isset($_POST['file_id']) && !empty($_POST['file_id'])) {
        $existingFile = $path . '/' . $filename;
        if (file_exists($existingFile) && filesize($existingFile) < ($_POST['total_size'] ?? 0)) {
            return $filename;
        }
    }

    $originalName = pathinfo($filename, PATHINFO_FILENAME);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $counter = 1;
    $newFilename = $filename;
    
    while (file_exists($path . '/' . $newFilename)) {
        $newFilename = $originalName . ' (' . $counter . ').' . $extension;
        $counter++;
    }
    
    return $newFilename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_files'])) {
    $totalFiles = count($_FILES['upload_files']['name']);
    $uploadedFiles = 0;
    
    // Check if there's enough space in the pooled storage
    $webdavRoot = "/var/www/html/webdav";
    $totalStorage = disk_total_space($webdavRoot);
    $usedStorage = getDirSize($webdavRoot);
    $freeStorage = $totalStorage - $usedStorage;
    
    // Calculate total size of files to be uploaded
    $totalUploadSize = 0;
    foreach ($_FILES['upload_files']['name'] as $i => $fname) {
        if ($_FILES['upload_files']['error'][$i] === UPLOAD_ERR_OK) {
            $totalUploadSize += $_POST['total_size'] ?? filesize($_FILES['upload_files']['tmp_name'][$i]);
        }
    }
    
    // Check if there's enough space
    if ($totalUploadSize > $freeStorage) {
        $_SESSION['error'] = "Not enough space in shared storage. Available: " . round($freeStorage / (1024 * 1024 * 1024), 2) . " GB, Required: " . round($totalUploadSize / (1024 * 1024 * 1024), 2) . " GB";
        header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel));
        exit;
    }

    foreach ($_FILES['upload_files']['name'] as $i => $fname) {
        if ($_FILES['upload_files']['error'][$i] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['upload_files']['tmp_name'][$i];
            $originalName = $_POST['file_name'] ?? basename($fname);
            $chunkStart = (int)($_POST['chunk_start'] ?? 0);
            $totalSize = (int)($_POST['total_size'] ?? filesize($tmpPath));
            
            // Only get a unique filename for the first chunk
            $uniqueName = $chunkStart === 0 ? 
                getUniqueFilename($currentDir, $originalName, true) : 
                $originalName;
            
            $dest = $currentDir . '/' . $uniqueName;

            // For first chunk, create new file. For subsequent chunks, append
            $output = fopen($dest, $chunkStart === 0 ? 'wb' : 'ab');
            if (!$output) {
                log_debug("Failed to open destination file: $dest");
                $_SESSION['error'] = "Failed to open file for writing: $uniqueName";
                continue;
            }

            $input = fopen($tmpPath, 'rb');
            if (!$input) {
                log_debug("Failed to open temp file: $tmpPath");
                $_SESSION['error'] = "Failed to read uploaded file: $uniqueName";
                fclose($output);
                continue;
            }

            // Seek to the correct position for chunks
            if ($chunkStart > 0) {
                fseek($output, $chunkStart);
            }

            // Write the chunk
            while (!feof($input)) {
                $data = fread($input, 8192);
                if ($data === false || fwrite($output, $data) === false) {
                    log_debug("Failed to write chunk for $uniqueName at offset $chunkStart");
                    $_SESSION['error'] = "Failed to write chunk for $uniqueName";
                    fclose($input);
                    fclose($output);
                    if ($chunkStart === 0) {
                        unlink($dest);
                    }
                    continue 2;
                }
            }

            fclose($input);
            fclose($output);

            // Set permissions only when the file is complete
            if (filesize($dest) >= $totalSize) {
                chown($dest, 'www-data');
                chgrp($dest, 'www-data');
                chmod($dest, 0664);
                log_debug("Completed upload for: $dest");
                $uploadedFiles++;
            } else {
                log_debug("Chunk uploaded for: $dest at offset $chunkStart");
            }
        }
    }

    if ($uploadedFiles > 0) {
        $_SESSION['success'] = "$uploadedFiles file(s) uploaded successfully.";
    }

    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 5. Delete an item (folder or file)
 ************************************************/
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemToDelete = $_GET['delete'];
    $targetPath = realpath($currentDir . '/' . $itemToDelete);

    if ($targetPath && strpos($targetPath, $currentDir) === 0) {
        if (is_dir($targetPath)) {
            deleteRecursive($targetPath);
            log_debug("Deleted folder: $targetPath");
        } elseif (unlink($targetPath)) {
            log_debug("Deleted file: $targetPath");
        } else {
            log_debug("Failed to delete item: $targetPath");
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 6. Recursively delete a folder
 ************************************************/
function deleteRecursive($dirPath) {
    $items = scandir($dirPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $dirPath . '/' . $item;
        if (is_dir($full)) {
            deleteRecursive($full);
        } else {
            unlink($full);
        }
    }
    rmdir($dirPath);
}

/************************************************
 * 7. Rename a folder
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_folder'])) {
    $oldFolderName = $_POST['old_folder_name'] ?? '';
    $newFolderName = $_POST['new_folder_name'] ?? '';
    $oldPath = realpath($currentDir . '/' . $oldFolderName);

    if ($oldPath && is_dir($oldPath)) {
        $newPath = $currentDir . '/' . $newFolderName;
        if (!file_exists($newPath) && rename($oldPath, $newPath)) {
            log_debug("Renamed folder: $oldPath to $newPath");
        } else {
            log_debug("Failed to rename folder: $oldPath to $newPath");
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 8. Rename a file (prevent extension change)
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_file'])) {
    $oldFileName = $_POST['old_file_name'] ?? '';
    $newFileName = $_POST['new_file_name'] ?? '';
    
    if ($oldFileName !== '' && $newFileName !== '' && $oldFileName !== $newFileName) {
        $oldPath = $currentDir . '/' . $oldFileName;
        $newPath = $currentDir . '/' . $newFileName;
        
        if (file_exists($oldPath) && !file_exists($newPath)) {
            if (rename($oldPath, $newPath)) {
                log_debug("Renamed file: $oldPath to $newPath");
            } else {
                log_debug("Failed to rename file: $oldPath to $newPath");
                $_SESSION['error'] = "Failed to rename file.";
            }
        } else {
            if (file_exists($newPath)) {
                $_SESSION['error'] = "A file with that name already exists.";
            } else {
                $_SESSION['error'] = "File not found.";
            }
        }
    }
    
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 9. Gather folders & files
 ************************************************/
$folders = [];
$files = [];
$previewableFiles = [];
if (is_dir($currentDir)) {
    $all = scandir($currentDir);
    if ($all !== false) {
        foreach ($all as $one) {
            if ($one === '.' || $one === '..') continue;
            $path = $currentDir . '/' . $one;
            if (is_dir($path)) {
                $folders[] = $one;
            } else {
                $files[] = $one;
                // Generate previewable files array
                $relativePath = $currentRel . '/' . $one;
                $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
                $ext = strtolower(pathinfo($one, PATHINFO_EXTENSION));
                
                if (isImage($one)) {
                    $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
                    $previewUrl = $fileURL;
                    if (strtolower(pathinfo($one, PATHINFO_EXTENSION)) === 'heic') {
                        $previewUrl = $fileURL . '&preview=1';
                    }
                    $previewableFiles[] = [
                        'name' => $one,
                        'url' => $fileURL,
                        'previewUrl' => $previewUrl,
                        'type' => 'image',
                        'icon' => getIconClass($one)
                    ];
                } elseif (isVideo($one)) {
                    $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
                    $previewableFiles[] = [
                        'name' => $one,
                        'url' => $fileURL,
                        'type' => 'video',
                        'icon' => getIconClass($one),
                        'previewUrl' => "/selfhostedgdrive/video_preview.php?video=" . urlencode($relativePath),
                        'thumbnailUrl' => "/selfhostedgdrive/video_preview.php?video=" . urlencode($relativePath)
                    ];
                } elseif (isPDF($one)) {
                    $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
                    $previewUrl = $fileURL . '&preview=1';
                    $previewableFiles[] = [
                        'name' => $one,
                        'url' => $fileURL,
                        'previewUrl' => $previewUrl,
                        'type' => 'pdf',
                        'icon' => getIconClass($one)
                    ];
                } else {
                    $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
                    $previewableFiles[] = [
                        'name' => $one,
                        'url' => $fileURL,
                        'type' => 'other',
                        'icon' => getIconClass($one)
                    ];
                }
            }
        }
    }
}
sort($folders);
sort($files);
log_debug("Folders: " . implode(", ", $folders));
log_debug("Files: " . implode(", ", $files));

/************************************************
 * 10. "Back" link if not at Home
 ************************************************/
$parentLink = '';
if ($currentDir !== $baseDir) {
    $parts = explode('/', $currentRel);
    array_pop($parts);
    $parentRel = implode('/', $parts);
    $parentLink = '/selfhostedgdrive/explorer.php?folder=' . urlencode($parentRel);
}

/************************************************
 * 11. Helper: Decide which FA icon to show
 ************************************************/
function getIconClass($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (isVideo($fileName)) return 'fas fa-file-video';
    if (isImage($fileName)) return 'fas fa-file-image';
    if ($ext === 'pdf') return 'fas fa-file-pdf';
    if ($ext === 'exe') return 'fas fa-file-exclamation';
    return 'fas fa-file';
}

/************************************************
 * 12. Helper: Check if file is "previewable"
 ************************************************/
function isImage($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'heic', 'webp']);
}

/************************************************
 * Helper: Check if file is a video
 ************************************************/
function isVideo($fileName) {
    $videoExtensions = ['mp4', 'webm', 'ogg', 'mkv', 'avi', 'mov'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, $videoExtensions);
}

/************************************************
 * Helper: Check if file is a PDF
 ************************************************/
function isPDF($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $ext === 'pdf';
}

/************************************************
 * 13. Handle AJAX file info request
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_file_info') {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    $fileName = isset($_POST['file_name']) ? $_POST['file_name'] : '';
    $currentFolder = isset($_POST['current_folder']) ? $_POST['current_folder'] : 'Home';
    
    if (empty($fileName)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No file specified']);
        exit;
    }
    
    // Build the file path
    $filePath = realpath($baseDir . '/' . $currentFolder . '/' . $fileName);
    
    // Security check - make sure the file is within the user's directory
    if ($filePath === false || strpos($filePath, $baseDir) !== 0 || !file_exists($filePath) || is_dir($filePath)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File not found or access denied']);
        exit;
    }
    
    // Get file information
    $fileInfo = [
        'success' => true,
        'name' => basename($filePath),
        'size' => filesize($filePath),
        'type' => mime_content_type($filePath),
        'modified' => filemtime($filePath)
    ];
    
    header('Content-Type: application/json');
    echo json_encode($fileInfo);
    exit;
}

/************************************************
 * 6. Delete Files
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    if (isset($_POST['files_to_delete']) && is_array($_POST['files_to_delete'])) {
        $deletedCount = 0;
        $failedCount = 0;
        
        foreach ($_POST['files_to_delete'] as $fileName) {
            $filePath = $currentDir . '/' . $fileName;
            
            // Security check to ensure the file is within the current directory
            $realFilePath = realpath($filePath);
            if ($realFilePath === false || strpos($realFilePath, $currentDir) !== 0) {
                log_debug("Security violation: Attempted to delete file outside current directory: $filePath");
                $failedCount++;
                continue;
            }
            
            if (file_exists($filePath)) {
                if (is_file($filePath)) {
                    if (unlink($filePath)) {
                        log_debug("Deleted file: $filePath");
                        $deletedCount++;
                    } else {
                        log_debug("Failed to delete file: $filePath");
                        $failedCount++;
                    }
                } else {
                    // If it's a directory, use the recursive delete function
                    if (deleteRecursive($filePath)) {
                        log_debug("Deleted directory: $filePath");
                        $deletedCount++;
                    } else {
                        log_debug("Failed to delete directory: $filePath");
                        $failedCount++;
                    }
                }
            } else {
                log_debug("File not found for deletion: $filePath");
                $failedCount++;
            }
        }
        
        if ($deletedCount > 0) {
            $_SESSION['success'] = "Successfully deleted $deletedCount " . ($deletedCount === 1 ? "file" : "files") . ".";
        }
        
        if ($failedCount > 0) {
            $_SESSION['error'] = "Failed to delete $failedCount " . ($failedCount === 1 ? "file" : "files") . ".";
        }
    }
    
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 7. Download Multiple Files
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_files'])) {
    if (isset($_POST['files_to_download']) && is_array($_POST['files_to_download']) && count($_POST['files_to_download']) > 0) {
        // Create a temporary directory for the zip file
        $tempDir = sys_get_temp_dir() . '/' . uniqid('download_', true);
        if (!mkdir($tempDir, 0777, true)) {
            log_debug("Failed to create temporary directory for zip: $tempDir");
            $_SESSION['error'] = "Failed to prepare download.";
            header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
            exit;
        }
        
        // Create a unique zip filename
        $zipFilename = 'files_' . date('Y-m-d_H-i-s') . '.zip';
        $zipPath = $tempDir . '/' . $zipFilename;
        
        // Create a new zip archive
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            log_debug("Failed to create zip archive: $zipPath");
            $_SESSION['error'] = "Failed to create download archive.";
            header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
            exit;
        }
        
        // Add each file to the zip
        $fileCount = 0;
        foreach ($_POST['files_to_download'] as $fileName) {
            $filePath = $currentDir . '/' . $fileName;
            
            // Security check to ensure the file is within the current directory
            $realFilePath = realpath($filePath);
            if ($realFilePath === false || strpos($realFilePath, $currentDir) !== 0) {
                log_debug("Security violation: Attempted to download file outside current directory: $filePath");
                continue;
            }
            
            if (file_exists($filePath) && is_file($filePath)) {
                if ($zip->addFile($filePath, $fileName)) {
                    $fileCount++;
                } else {
                    log_debug("Failed to add file to zip: $filePath");
                }
            } else {
                log_debug("File not found for download: $filePath");
            }
        }
        
        // Close the zip file
        $zip->close();
        
        if ($fileCount > 0) {
            // Send the zip file to the browser
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Pragma: no-cache');
            header('Expires: 0');
            
            readfile($zipPath);
            
            // Clean up
            unlink($zipPath);
            rmdir($tempDir);
            exit;
        } else {
            // No files were added to the zip
            unlink($zipPath);
            rmdir($tempDir);
            
            log_debug("No files were added to the download archive");
            $_SESSION['error'] = "No files were found for download.";
            header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
            exit;
        }
    } else {
        $_SESSION['error'] = "No files selected for download.";
        header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DrivePulse</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
  <link href="styles.css" rel="stylesheet"/>
  
  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="drivepulse.svg">
  <!-- Apple Touch Icon -->
  <link rel="apple-touch-icon" href="drivepulse.svg">
  <!-- MS Tile Icon -->
  <meta name="msapplication-TileImage" content="drivepulse.svg">
  <meta name="msapplication-TileColor" content="#ff4444">
  <!-- Theme Color -->
  <meta name="theme-color" content="#ff4444">
  
  
</head>
<body>
  <div id="popupOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 99998;"></div>
  
  <div id="popupMenu" style="display: none; position: fixed; bottom: -100%; left: 0; right: 0; background: #1e1e1e; z-index: 99999; transition: bottom 0.3s ease; border-radius: 15px 15px 0 0;">
    <div style="padding: 20px;">
      <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
        <span style="color: white; font-size: 18px;">Options</span>
        <button onclick="hidePopup()" style="background: none; border: none; color: white; font-size: 20px;">×</button>
      </div>
      
      <button onclick="handlePopupAction('open')" style="width: 100%; padding: 15px; margin: 5px 0; background: none; border: 1px solid #333; color: white; text-align: left; font-size: 16px; border-radius: 8px;">
        <i class="fas fa-eye" style="margin-right: 10px;"></i> Open
      </button>
      
      <button onclick="handlePopupAction('download')" style="width: 100%; padding: 15px; margin: 5px 0; background: none; border: 1px solid #333; color: white; text-align: left; font-size: 16px; border-radius: 8px;">
        <i class="fas fa-download" style="margin-right: 10px;"></i> Download
      </button>
      
      <button onclick="handlePopupAction('share')" style="width: 100%; padding: 15px; margin: 5px 0; background: none; border: 1px solid #333; color: white; text-align: left; font-size: 16px; border-radius: 8px;">
        <i class="fas fa-share" style="margin-right: 10px;"></i> Share
      </button>
      
      <button onclick="handlePopupAction('rename')" style="width: 100%; padding: 15px; margin: 5px 0; background: none; border: 1px solid #333; color: white; text-align: left; font-size: 16px; border-radius: 8px;">
        <i class="fas fa-edit" style="margin-right: 10px;"></i> Rename
      </button>
      
      <button onclick="handlePopupAction('delete')" style="width: 100%; padding: 15px; margin: 5px 0; background: none; border: 1px solid #333; color: red; text-align: left; font-size: 16px; border-radius: 8px;">
        <i class="fas fa-trash" style="margin-right: 10px;"></i> Delete
      </button>
    </div>
  </div>

  <script>
  let selectedItem = null;
  const popup = document.getElementById('popupMenu');
  const overlay = document.getElementById('popupOverlay');

  function showPopup(item) {
    selectedItem = item;
    overlay.style.display = 'block';
    popup.style.display = 'block';
    setTimeout(() => popup.style.bottom = '0', 10);
  }

  function hidePopup() {
    popup.style.bottom = '-100%';
    overlay.style.display = 'none';
    setTimeout(() => popup.style.display = 'none', 300);
    selectedItem = null;
  }

  function handlePopupAction(action) {
    if (!selectedItem) return;
    
    switch(action) {
      case 'open':
        if (selectedItem.classList.contains('file-item')) {
          openFile(selectedItem);
        } else {
          openFolder(selectedItem);
        }
        break;
      case 'download':
        downloadFile(selectedItem);
        break;
      case 'share':
        if (selectedItem.classList.contains('file-item')) {
          shareFile(selectedItem);
        } else {
          shareFolder(selectedItem);
        }
        break;
      case 'rename':
        if (selectedItem.classList.contains('file-item')) {
          renameFile(selectedItem);
        } else {
          renameFolder(selectedItem);
        }
        break;
      case 'delete':
        if (selectedItem.classList.contains('file-item')) {
          deleteFile(selectedItem);
        } else {
          deleteFolder(selectedItem);
        }
        break;
    }
    hidePopup();
  }

  // Show popup when clicking three dots
  document.addEventListener('click', function(e) {
    const threeDots = e.target.closest('.three-dots, .more-options-btn, .folder-more-options-btn');
    if (threeDots) {
      e.preventDefault();
      e.stopPropagation();
      const item = threeDots.closest('.file-item, .folder-item');
      if (item) showPopup(item);
    }
  });

  // Hide popup when clicking overlay
  overlay.addEventListener('click', hidePopup);
  </script>

  <div class="app-container">
    <div class="sidebar" id="sidebar">
      <div id="sidebar-particles-js"></div>
      <div class="folders-container">
        <div class="drivepulse-header">
          <i class="fas fa-cloud-upload-alt drivepulse-logo"></i>
          <span class="drivepulse-title">DrivePulse</span>
        </div>
        <div class="separator-line"></div>
        <div class="top-row">
          <button type="button" class="btn" title="Create New Folder" onclick="createFolder()">
            <i class="fas fa-folder-plus"></i>
          </button>
        </div>
        <div class="separator-line"></div>
        <div class="folder-list-container">
          <ul class="folder-list">
            <?php foreach ($folders as $folderName): ?>
              <?php $folderPath = ($currentRel === 'Home' ? '' : $currentRel . '/') . $folderName; 
                    log_debug("Folder path for $folderName: $folderPath"); ?>
              <li class="folder-item"
                  data-folder-path="<?php echo urlencode($folderPath); ?>"
                  data-folder-name="<?php echo addslashes($folderName); ?>">
                <i class="fas fa-folder"></i> <?php echo htmlspecialchars($folderName); ?>
                <div class="folder-actions">
                  <button class="folder-more-options-btn" title="More options">
                    <i class="fas fa-ellipsis-v small-dots"></i>
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="storage-indicator">
          <p><i class="fas fa-database storage-icon"></i> <?php echo "$usedStorageGB GB used of $totalStorageGB GB"; ?></p>
          <div class="storage-bar">
            <div class="storage-progress" style="width: <?php echo $storagePercentage; ?>%;"></div>
          </div>
          <div class="storage-details">
            <span><?php echo $storagePercentage; ?>% used</span>
            <span><?php echo round($totalStorageGB - $usedStorageGB, 2); ?> GB free</span>
          </div>
        </div>
      </div>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
      <div class="header-area">
        <div class="header-title" style="display: flex; flex: 1; width: 100%; gap: 20px; align-items: center; padding: 8px 20px;">
          <!-- Hamburger menu button -->
          <button type="button" class="btn" id="sidebarToggle" onclick="toggleSidebar()" style="display: none; margin-right: 10px;">
            <i class="fas fa-bars"></i>
          </button>

          <!-- Fixed multi-select controls container -->
          <div class="multi-select-controls-container" style="display: flex; flex: 1; background: var(--sidebar-bg); opacity: 0.8; backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); border-radius: 8px; padding: 8px 15px; margin-bottom: 0; margin-top: 0; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
            <div class="multi-select-controls" style="display: flex; align-items: center; width: 100%; justify-content: space-between; flex-wrap: nowrap;">
              <!-- Left side - Select All button -->
              <button type="button" class="multi-select-btn" id="selectAllBtn" title="Select All Files" style="font-size: 13px; padding: 8px 16px; min-width: 80px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500; flex-shrink: 0;">
                <i class="fas fa-check-square" style="margin-right: 8px;"></i>All
              </button>
              
              <!-- Right side - Delete and Download buttons -->
              <div style="display: flex; gap: 20px; flex-shrink: 0;">
                <button type="button" class="multi-select-btn" id="deleteSelectedBtn" title="Delete Selected Files" style="font-size: 13px; padding: 8px; width: 36px; height: 36px; background: rgba(211, 47, 47, 0.2); color: var(--accent-red); border: 1px solid var(--accent-red); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; display: flex; align-items: center; justify-content: center;">
                  <i class="fas fa-trash-alt"></i>
                </button>
                <button type="button" class="multi-select-btn" id="downloadSelectedBtn" title="Download Selected Files" style="font-size: 13px; padding: 8px; width: 36px; height: 36px; background: rgba(0, 0, 0, 0.1); color: var(--text-color); border: 1px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500; display: flex; align-items: center; justify-content: center;">
                  <i class="fas fa-download"></i>
                </button>
              </div>
            </div>
          </div>
          <div style="display: flex; gap: 10px; margin-right: 20px;">
            <a href="/selfhostedgdrive/logout.php" class="btn logout-btn" title="Logout">
              <i class="fa fa-sign-out" aria-hidden="true"></i>
            </a>
          </div>
        </div>
      </div>
      <div class="content-inner" style="backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding-top: 20px; padding-bottom: 50px; display: flex; flex-direction: column; overflow: hidden;">
        <div class="breadcrumb-navigation">
          <a href="/selfhostedgdrive/explorer.php?folder=Home" class="breadcrumb-item">Home</a>
          <?php if ($currentRel !== 'Home'): ?>
            <?php
              $pathParts = explode('/', $currentRel);
              $currentPath = '';
              
              foreach ($pathParts as $index => $part):
                $currentPath .= ($index > 0 ? '/' : '') . $part;
            ?>
              <span class="breadcrumb-separator">/</span>
              <?php if ($index === count($pathParts) - 1): ?>
                <span class="breadcrumb-item current"><?php echo htmlspecialchars($part); ?></span>
              <?php else: ?>
                <a href="/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentPath); ?>" class="breadcrumb-item"><?php echo htmlspecialchars($part); ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        
        <!-- Scrollable container for files -->
        <div class="files-container" style="flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: var(--accent-red) var(--background);">
          
          <ul class="folder-list" id="fileList">
            <?php foreach ($files as $fileName): ?>
              <?php 
                  $relativePath = $currentRel . '/' . $fileName;
                  $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
                  $iconClass = getIconClass($fileName);
                  $isImageFile = isImage($fileName);
                  $filePath = $currentDir . '/' . $fileName;
                  $fileSize = filesize($filePath);
                  $fileType = mime_content_type($filePath);
                  $fileModified = filemtime($filePath);
                  
                  log_debug("File URL for $fileName: $fileURL");
              ?>
              <li class="folder-item"
                  data-file-url="<?php echo htmlspecialchars($fileURL); ?>" 
                  data-file-name="<?php echo addslashes($fileName); ?>"
                  data-file-size="<?php echo $fileSize; ?>"
                  data-file-type="<?php echo htmlspecialchars($fileType); ?>"
                  data-file-modified="<?php echo $fileModified; ?>">
                  <?php if (isImage($fileName)): ?>
                  <i class="<?php echo $iconClass; ?>"></i>
                  <div class="thumbnail-container image-preview-container">
                      <img class="thumbnail" src="<?php echo htmlspecialchars($fileURL); ?>" alt="<?php echo htmlspecialchars($fileName); ?>" loading="lazy">
                  </div>
                  <?php elseif (isVideo($fileName)): ?>
                  <div class="thumbnail-container video-preview-container">
                      <img class="thumbnail" src="/selfhostedgdrive/video_preview.php?video=<?php echo urlencode($relativePath); ?>" alt="<?php echo htmlspecialchars($fileName); ?>" loading="lazy">
                      <div class="video-overlay">
                          <i class="fas fa-play-circle"></i>
                      </div>
                  </div>
                  <?php else: ?>
                  <i class="<?php echo $iconClass; ?>"></i>
                  <?php endif; ?>
                  <span class="file-name"><?php echo htmlspecialchars($fileName); ?></span>
                  <div class="folder-actions">
                    <?php
                      // Check if file is shared
                      $fileKey = $username . ':' . $relativePath;
                      $shares = [];
                      $shares_file = __DIR__ . '/shares.json';
                      if (file_exists($shares_file)) {
                          $content = file_get_contents($shares_file);
                          if (!empty($content)) {
                              $shares = json_decode($content, true) ?: [];
                          }
                      }
                      $isShared = isset($shares[$fileKey]);
                    ?>
                    <?php if ($isShared): ?>
                      <i class="fas fa-globe share-icon hide-in-grid" title="This file is shared" style="display: var(--share-icon-display, inline-block);"></i>
                    <?php endif; ?>
                    <button class="folder-more-options-btn" title="More options">
                      <i class="fas fa-ellipsis-v small-dots"></i>
                    </button>
                  </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <div id="previewModal">
    <span id="previewClose" onclick="closePreviewModal()"><i class="fas fa-times"></i></span>
    <div id="previewContent">
        <div id="previewNav">
            <button id="prevBtn" onclick="navigatePreview(-1)"><i class="fas fa-arrow-left"></i></button>
            <button id="nextBtn" onclick="navigatePreview(1)"><i class="fas fa-arrow-right"></i></button>
        </div>
        <div id="imagePreviewContainer" style="display: none;"></div>
        <div id="iconPreviewContainer" style="display: none;"></div>
        <div id="videoPreviewContainer" style="display: none;">
            <video id="videoPlayer" preload="auto"></video>
            <div id="bufferingIndicator">
                <div class="spinner"></div>
            </div>
            <div class="video-controls">
                <div class="video-controls-inner">
                    <button id="playPauseBtn" onclick="togglePlay(event)"><i class="fas fa-play"></i></button>
                    <div id="videoProgress" onclick="seekVideo(event)">
                        <div id="videoProgressBar"></div>
                        <div id="videoBufferBar"></div>
                    </div>
                    <span id="videoDuration" class="duration-display">0:00 / 0:00</span>
                    <button id="fullscreenBtn" onclick="toggleFullscreen(event)"><i class="fas fa-expand"></i></button>
                </div>
            </div>
        </div>
        <div id="pdfPreviewContainer" style="display: none;">
            <iframe id="pdfViewer" width="100%" height="100%" frameborder="0" style="border: none; outline: none;"></iframe>
            <div id="pdfLoadingIndicator">
                <div class="spinner"></div>
                <div class="loading-text">Loading PDF...</div>
            </div>
        </div>
    </div>
  </div>

  <div id="dialogModal">
    <div class="dialog-content">
      <div class="dialog-message" id="dialogMessage"></div>
      <div class="dialog-buttons" id="dialogButtons"></div>
    </div>
  </div>

  <!-- Add context menu at the bottom of the page -->
  <div id="contextMenu" class="context-menu">
    <!-- File-specific options -->
    <div class="file-context-options" style="display: none;">
      <div class="context-menu-item" id="contextMenuOpen">
          <i class="fas fa-eye"></i> Open
      </div>
      <div class="context-menu-item" id="contextMenuInfo">
          <i class="fas fa-info-circle"></i> Info
      </div>
      <div class="context-menu-item" id="contextMenuDownload">
          <i class="fas fa-download"></i> Download
      </div>
      <div class="context-menu-item" id="contextMenuShare">
          <i class="fas fa-globe" style="color: red;"></i> Share
      </div>
      <div class="context-menu-divider"></div>
      <div class="context-menu-item" id="contextMenuRename">
          <i class="fas fa-edit"></i> Rename
      </div>
      <div class="context-menu-item" id="contextMenuDelete">
          <i class="fas fa-trash"></i> Delete
      </div>
    </div>
    
    <!-- Folder-specific options -->
    <div class="folder-context-options" style="display: none;">
      <div class="context-menu-item" id="contextMenuOpenFolder">
          <i class="fas fa-folder-open"></i> Open
      </div>
      <div class="context-menu-item" id="contextMenuShareFolder">
          <i class="fas fa-globe" style="color: red;"></i> Share
      </div>
      <div class="context-menu-divider"></div>
      <div class="context-menu-item" id="contextMenuRenameFolder">
          <i class="fas fa-edit"></i> Rename
      </div>
      <div class="context-menu-item" id="contextMenuDeleteFolder">
          <i class="fas fa-trash"></i> Delete
      </div>
    </div>
  </div>

  <div class="dock">
    <form id="uploadForm" method="POST" enctype="multipart/form-data" action="/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>">
      <input type="file" name="upload_files[]" multiple id="fileInput" style="display:none;" />
      <button type="button" class="btn" id="uploadBtn" title="Upload">
        <i class="fas fa-cloud-upload-alt"></i>
      </button>
    </form>
    <button type="button" class="btn" id="gridToggleBtn" title="Toggle Grid View">
      <i class="fas fa-th"></i>
    </button>
    <button type="button" class="btn theme-toggle-btn" id="themeToggleBtn" title="Toggle Theme">
      <i class="fas fa-moon"></i>
    </button>
  </div>

  <div id="uploadProgressContainer" style="display: none;">
    <div style="background:var(--border-color); width:100%; height:20px; border-radius:4px; overflow:hidden;">
      <div id="uploadProgressBar"></div>
    </div>
    <div id="uploadProgressPercent">0.0%</div>
    <button class="cancel-upload-btn" id="cancelUploadBtn">Cancel</button>
  </div>

  <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
<script>
let selectedFolder = null;
let currentXhr = null;
let previewFiles = <?php echo json_encode($previewableFiles); ?>;
let currentPreviewIndex = -1;
let isLoadingImage = false;
let currentPath = '<?php echo htmlspecialchars($currentRel); ?>'; // Add the current path from PHP

function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  sb.classList.toggle('open');
  overlay.classList.toggle('show');
}
document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

function selectFolder(element, folderName) {
  document.querySelectorAll('.folder-item.selected').forEach(item => item.classList.remove('selected'));
  element.classList.add('selected');
  selectedFolder = folderName;
}

function openFolder(folderPath) {
  console.log("Opening folder: " + folderPath);
  window.location.href = '/selfhostedgdrive/explorer.php?folder=' + folderPath;
}

function showPrompt(message, defaultValue, callback) {
  const dialogModal = document.getElementById('dialogModal');
  const dialogMessage = document.getElementById('dialogMessage');
  const dialogButtons = document.getElementById('dialogButtons');
  dialogMessage.innerHTML = '';
  dialogButtons.innerHTML = '';
  const msgEl = document.createElement('div');
  msgEl.textContent = message;
  msgEl.style.marginBottom = '10px';
  dialogMessage.appendChild(msgEl);
  const inputField = document.createElement('input');
  inputField.type = 'text';
  inputField.value = defaultValue || '';
  inputField.style.width = '100%';
  inputField.style.padding = '8px';
  inputField.style.border = '1px solid #555';
  inputField.style.borderRadius = '4px';
  inputField.style.background = '#2a2a2a';
  inputField.style.color = '#fff';
  inputField.style.marginBottom = '15px';
  dialogMessage.appendChild(inputField);
  const okBtn = document.createElement('button');
  okBtn.className = 'dialog-button';
  okBtn.textContent = 'OK';
  okBtn.onclick = () => { closeDialog(); if (callback) callback(inputField.value); };
  dialogButtons.appendChild(okBtn);
  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'dialog-button';
  cancelBtn.textContent = 'Cancel';
  cancelBtn.onclick = () => { closeDialog(); if (callback) callback(null); };
  dialogButtons.appendChild(cancelBtn);
  dialogModal.classList.add('show');
}

function closeDialog() {
  document.getElementById('dialogModal').classList.remove('show');
}

function showAlert(message, callback) {
  const dialogModal = document.getElementById('dialogModal');
  const dialogMessage = document.getElementById('dialogMessage');
  const dialogButtons = document.getElementById('dialogButtons');
  dialogMessage.innerHTML = message;
  dialogButtons.innerHTML = '';
  const okBtn = document.createElement('button');
  okBtn.className = 'dialog-button';
  okBtn.textContent = 'OK';
  okBtn.onclick = () => { closeDialog(); if (callback) callback(); };
  dialogButtons.appendChild(okBtn);
  dialogModal.classList.add('show');
}

function showConfirm(message, onYes, onNo) {
  const dialogModal = document.getElementById('dialogModal');
  const dialogMessage = document.getElementById('dialogMessage');
  const dialogButtons = document.getElementById('dialogButtons');
  dialogMessage.textContent = message;
  dialogButtons.innerHTML = '';
  const yesBtn = document.createElement('button');
  yesBtn.className = 'dialog-button';
  yesBtn.textContent = 'Yes';
  yesBtn.onclick = () => { closeDialog(); if (onYes) onYes(); };
  dialogButtons.appendChild(yesBtn);
  const noBtn = document.createElement('button');
  noBtn.className = 'dialog-button';
  noBtn.textContent = 'No';
  noBtn.onclick = () => { closeDialog(); if (onNo) onNo(); };
  dialogButtons.appendChild(noBtn);
  dialogModal.classList.add('show');
}

function createFolder() {
  showPrompt("Enter new folder name:", "", function(folderName) {
    if (folderName && folderName.trim() !== "") {
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
      let inputCreate = document.createElement('input');
      inputCreate.type = 'hidden';
      inputCreate.name = 'create_folder';
      inputCreate.value = '1';
      form.appendChild(inputCreate);
      let inputName = document.createElement('input');
      inputName.type = 'hidden';
      inputName.name = 'folder_name';
      inputName.value = folderName.trim();
      form.appendChild(inputName);
      document.body.appendChild(form);
      form.submit();
    }
  });
}

function renameFilePrompt(fileName) {
  let dotIndex = fileName.lastIndexOf(".");
  let baseName = fileName;
  let ext = "";
  if (dotIndex > 0) {
    baseName = fileName.substring(0, dotIndex);
    ext = fileName.substring(dotIndex);
  }
  showPrompt("Enter new file name:", baseName, function(newBase) {
    if (newBase && newBase.trim() !== "" && newBase.trim() !== baseName) {
      let finalName = newBase.trim() + ext;
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
      let inputAction = document.createElement('input');
      inputAction.type = 'hidden';
      inputAction.name = 'rename_file';
      inputAction.value = '1';
      form.appendChild(inputAction);
      let inputOld = document.createElement('input');
      inputOld.type = 'hidden';
      inputOld.name = 'old_file_name';
      inputOld.value = fileName;
      form.appendChild(inputOld);
      let inputNew = document.createElement('input');
      inputNew.type = 'hidden';
      inputNew.name = 'new_file_name';
      inputNew.value = finalName;
      form.appendChild(inputNew);
      document.body.appendChild(form);
      form.submit();
    }
  });
}

function confirmFileDelete(fileName) {
  showConfirm(`Delete file "${fileName}"?`, () => {
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>&delete=' + encodeURIComponent(fileName);
    document.body.appendChild(form);
    form.submit();
  });
}

function downloadFile(fileURL) {
  console.log("Downloading: " + fileURL);
  const a = document.createElement('a');
  a.href = fileURL;
  a.download = '';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/* ============================================================================
   Modified Preview Functions with Video Support
   ----------------------------------------------------------------------------
   openPreviewModal now handles both image and video previews. For videos,
   it sets the video source, displays the video container, and initializes
   custom video controls via setupVideoControls().
============================================================================ */
function openPreviewModal(fileURL, fileName) {
    if (isLoadingImage) return;
    console.log("Previewing: " + fileURL);
    
    const previewModal = document.getElementById('previewModal');
    const imageContainer = document.getElementById('imagePreviewContainer');
    const iconContainer = document.getElementById('iconPreviewContainer');
    const videoContainer = document.getElementById('videoPreviewContainer');
    const pdfContainer = document.getElementById('pdfPreviewContainer');
    const pdfViewer = document.getElementById('pdfViewer');
    const pdfLoadingIndicator = document.getElementById('pdfLoadingIndicator');
    const videoPlayer = document.getElementById('videoPlayer');
    const previewContent = document.getElementById('previewContent');
    const previewClose = document.getElementById('previewClose');

    // Add fade out effect
    previewContent.classList.add('fade-out');
    
    setTimeout(() => {
        // Reset all containers
        imageContainer.style.display = 'none';
        imageContainer.innerHTML = '';
        iconContainer.style.display = 'none';
        iconContainer.innerHTML = '';
        videoContainer.style.display = 'none';
        pdfContainer.style.display = 'none';
        
        // Reset video player
        if (videoPlayer) {
            videoPlayer.pause();
            videoPlayer.removeAttribute('src');
            videoPlayer.load();
        }
        
        // Reset PDF viewer
        if (pdfViewer) {
            pdfViewer.src = '';
        }
        
        // Reset classes
        previewContent.classList.remove('image-preview');
        previewContent.classList.remove('video-preview');
        previewContent.classList.remove('pdf-preview');
        previewModal.classList.remove('fullscreen');

        // Always show the close button
        previewClose.style.display = 'block';

        currentPreviewIndex = previewFiles.findIndex(file => file.name === fileName);
        let file = previewFiles.find(f => f.name === fileName);

        if (!file) {
            console.error('File not found in previewFiles array:', fileName);
            return;
        }

        if (file.type === 'video') {
            videoContainer.classList.remove('loaded');
            
            // Force video playback settings
            videoPlayer.preload = "auto";
            videoPlayer.innerHTML = '';
            
            // Force MP4/AAC playback
            const source = document.createElement('source');
            source.src = file.url;
            source.type = 'video/mp4';
            videoPlayer.appendChild(source);
            
            videoContainer.style.display = 'block';
            previewContent.classList.add('video-preview');
            setupVideoControls(videoPlayer);
            
            // Add buffering indicator
            const bufferingIndicator = document.getElementById('bufferingIndicator');
            bufferingIndicator.style.display = 'flex';
            
            videoPlayer.oncanplay = () => {
                videoContainer.classList.add('loaded');
                bufferingIndicator.style.display = 'none';
                
                // Attempt autoplay
                const playPromise = videoPlayer.play();
                if (playPromise !== undefined) {
                    playPromise.then(() => {
                        document.getElementById('playPauseBtn').innerHTML = '<i class="fas fa-pause"></i>';
                    }).catch(error => {
                        console.error('Autoplay prevented:', error);
                        document.getElementById('playPauseBtn').innerHTML = '<i class="fas fa-play"></i>';
                    });
                }
            };
            
            // Add waiting event listener for rebuffering
            videoPlayer.onwaiting = () => {
                bufferingIndicator.style.display = 'flex';
            };
            
            videoPlayer.onplaying = () => {
                bufferingIndicator.style.display = 'none';
            };
            
            // For older browsers that don't support oncanplay well
            setTimeout(() => {
                if (!videoContainer.classList.contains('loaded')) {
                    videoContainer.classList.add('loaded');
                    bufferingIndicator.style.display = 'none';
                }
            }, 1000);
        } else if (file.type === 'image') {
            isLoadingImage = true;
            const img = new Image();
            img.onload = () => {
                imageContainer.innerHTML = ''; // Clear any existing content
                imageContainer.appendChild(img);
                imageContainer.style.display = 'flex';
                previewContent.classList.add('image-preview');
                // Add small delay before showing image
                setTimeout(() => {
                    img.classList.add('loaded');
                    isLoadingImage = false;
                }, 50);
            };
            img.onerror = () => {
                console.error('Failed to load image:', file.url);
                showAlert('Failed to load image preview');
                isLoadingImage = false;
            };
            img.src = file.previewUrl || file.url;
        } else if (file.type === 'pdf') {
            // Show PDF container and loading indicator
            pdfContainer.style.display = 'flex';
            pdfLoadingIndicator.style.display = 'flex';
            previewContent.classList.add('pdf-preview');
            
            // Set iframe source to PDF URL with preview parameter
            pdfViewer.src = file.previewUrl || file.url;
            
            // Hide loading indicator when PDF is loaded
            pdfViewer.onload = () => {
                pdfLoadingIndicator.style.display = 'none';
                pdfContainer.classList.add('loaded'); // Add loaded class for animation
                
                // Ensure PDF is displayed at full size
                pdfViewer.style.width = '100%';
                pdfViewer.style.height = '100%';
                
                // Show zoom controls
                document.getElementById('pdfControls').style.display = 'block';
                
                // Try to inject custom scrollbar styles into the iframe
                try {
                    const iframeDoc = pdfViewer.contentDocument || pdfViewer.contentWindow.document;
                    if (iframeDoc) {
                        // Create a style element
                        const style = iframeDoc.createElement('style');
                        style.textContent = `
                            ::-webkit-scrollbar {
                                width: 10px !important;
                                height: 10px !important;
                            }
                            ::-webkit-scrollbar-track {
                                background: rgba(0, 0, 0, 0.1) !important;
                                border-radius: 4px !important;
                            }
                            ::-webkit-scrollbar-thumb {
                                background: #d32f2f !important;
                                border-radius: 4px !important;
                            }
                            ::-webkit-scrollbar-thumb:hover {
                                background: #b71c1c !important;
                            }
                            * {
                                scrollbar-width: thin !important;
                                scrollbar-color: #d32f2f rgba(0, 0, 0, 0.1) !important;
                            }
                        `;
                        iframeDoc.head.appendChild(style);
                    }
                } catch (e) {
                    console.log('Could not inject styles into iframe due to security restrictions', e);
                }
            };
            
            // Handle load error
            pdfViewer.onerror = () => {
            };
            
            // Handle load error
            pdfViewer.onerror = () => {
                console.error('Failed to load PDF:', file.previewUrl || file.url);
                showAlert('Failed to load PDF preview');
                pdfLoadingIndicator.style.display = 'none';
            };
            
            // Fallback for browsers that don't support iframe onload for PDFs
            setTimeout(() => {
                pdfLoadingIndicator.style.display = 'none';
            }, 2000);
        } else {
            const icon = document.createElement('i');
            icon.className = file.icon;
            iconContainer.appendChild(icon);
            iconContainer.style.display = 'flex';
        }

        // Ensure the modal is centered
        previewModal.style.display = 'flex';
        previewModal.style.justifyContent = 'center';
        previewModal.style.alignItems = 'center';
        
        // Fade in the content
        setTimeout(() => {
            previewContent.classList.remove('fade-out');
            previewContent.classList.add('fade-in');
        }, 50);

        updateNavigationButtons();

        // Add click handler for closing when clicking outside
        previewModal.onclick = function(e) {
            if (e.target === previewModal) {
                closePreviewModal();
            }
        };
    }, 300); // Wait for fade out
}

// Add this function back if it's missing
function updateNavigationButtons() {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    prevBtn.disabled = previewFiles.length <= 1;
    nextBtn.disabled = previewFiles.length <= 1;
}

function setupVideoControls(video) {
    const videoContainer = document.getElementById('videoPreviewContainer');
    const controls = videoContainer.querySelector('.video-controls');
    const progressBar = document.getElementById('videoProgressBar');
    const playPauseBtn = document.getElementById('playPauseBtn');
    const bufferingIndicator = document.getElementById('bufferingIndicator');
    const durationDisplay = document.getElementById('videoDuration');
    
    let hideTimeout;

    if ('ontouchstart' in window) {
        // Touch device - ultra simple approach
        videoContainer.addEventListener('touchstart', (e) => {
            // Don't handle touches on controls
            if (e.target.closest('.video-controls')) {
                return;
            }

            e.preventDefault();
            
            // Show controls
            controls.classList.add('active');
            
            // Clear any existing timeout
            clearTimeout(hideTimeout);
            
            // Set new timeout to hide controls after 1 second
            hideTimeout = setTimeout(() => {
                if (!video.paused) {
                    controls.classList.remove('active');
                }
            }, 1000);
        });

        // Keep controls visible when video is paused
        video.addEventListener('pause', () => {
            clearTimeout(hideTimeout);
            controls.classList.add('active');
        });

        // Start hide timer when video plays
        video.addEventListener('play', () => {
            clearTimeout(hideTimeout);
            hideTimeout = setTimeout(() => {
                controls.classList.remove('active');
            }, 1000);
        });
    } else {
        // Desktop behavior
        videoContainer.addEventListener('mousemove', () => {
            controls.classList.add('active');
            clearTimeout(hideTimeout);
            
            if (!video.paused) {
                hideTimeout = setTimeout(() => {
                    controls.classList.remove('active');
                }, 2000);
            }
        });

        videoContainer.addEventListener('mouseleave', () => {
            if (!video.paused) {
                controls.classList.remove('active');
            }
        });

        controls.addEventListener('mouseenter', () => {
            clearTimeout(hideTimeout);
            controls.classList.add('active');
        });

        controls.addEventListener('mouseleave', () => {
            if (!video.paused) {
                hideTimeout = setTimeout(() => {
                    controls.classList.remove('active');
                }, 2000);
            }
        });
    }

    // Store timeout reference for cleanup
    controls._hideTimeout = hideTimeout;

    // Format time helper function
    function formatTime(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        if (h > 0) {
            return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        }
        return `${m}:${s.toString().padStart(2, '0')}`;
    }
    
    // Update progress bar and duration
    video.ontimeupdate = () => {
        if (video.duration) {
            const percent = (video.currentTime / video.duration) * 100;
            progressBar.style.width = percent + '%';
            
            // Update duration display
            durationDisplay.textContent = `${formatTime(video.currentTime)} / ${formatTime(video.duration)}`;
            
            // Update buffered progress
            updateBufferProgress(video);
        }
    };
    
    // Add buffer progress indicator function
    function updateBufferProgress(video) {
        if (video.buffered.length > 0) {
            const bufferEnd = video.buffered.end(video.buffered.length - 1);
            const duration = video.duration;
            const bufferPercent = (bufferEnd / duration) * 100;
            document.getElementById('videoBufferBar').style.width = bufferPercent + '%';
        }
    }
    
    // Call initially and periodically
    updateBufferProgress(video);
    setInterval(() => updateBufferProgress(video), 3000);
    
    // Video buffering events
    video.onprogress = () => updateBufferProgress(video);

    // Video ended
    video.onended = () => {
        playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
    };

    // Autoplay when loaded
    video.oncanplay = () => {
        videoContainer.classList.add('loaded');
        bufferingIndicator.style.display = 'none';
        // Attempt to autoplay
        const playPromise = video.play();
        if (playPromise !== undefined) {
            playPromise.then(() => {
                playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
            }).catch(error => {
                console.error('Autoplay prevented:', error);
                // Don't show alert for autoplay restriction
                playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
            });
        }
    };

    // Improve error handling
    video.onerror = (e) => {
        console.error('Video error:', video.error);
        bufferingIndicator.style.display = 'none';
        
        let errorMessage = "Video playback error";
        if (video.error) {
            switch (video.error.code) {
                case 1: // MEDIA_ERR_ABORTED
                    errorMessage = "Video playback aborted";
                    break;
                case 2: // MEDIA_ERR_NETWORK
                    errorMessage = "Network error occurred. Please try again";
                    break;
                case 3: // MEDIA_ERR_DECODE
                    errorMessage = "Video decoding error";
                    break;
                case 4: // MEDIA_ERR_SRC_NOT_SUPPORTED
                    errorMessage = "Video format not supported by your browser";
                    break;
            }
        }
        
        showAlert(errorMessage);
    };

    // Enhanced keyboard shortcuts
    document.onkeydown = (e) => {
        // Only process if video is displayed
        if (document.getElementById('videoPreviewContainer').style.display !== 'none') {
            switch (e.code) {
                case 'Space':
                    e.preventDefault();
                    togglePlay(e);
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    video.currentTime = Math.max(video.currentTime - 5, 0);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    video.currentTime = Math.min(video.currentTime + 5, video.duration);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    video.volume = Math.min(video.volume + 0.1, 1);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    video.volume = Math.max(video.volume - 0.1, 0);
                    break;
                case 'KeyM':
                    e.preventDefault();
                    video.muted = !video.muted;
                    break;
                case 'KeyF':
                    e.preventDefault();
                    toggleFullscreen(e);
                    break;
                case 'Escape':
                    if (!document.fullscreenElement) {
                        closePreviewModal();
                    }
                    break;
            }
        }
    };

    // Handle fullscreen change
    document.onfullscreenchange = () => {
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        const previewModal = document.getElementById('previewModal');
        if (!document.fullscreenElement) {
            previewModal.classList.remove('fullscreen');
            fullscreenBtn.innerHTML = '<i class="fas fa-expand"></i>';
        }
    };
    
    // Add double-click to toggle fullscreen
    video.ondblclick = (e) => {
        e.preventDefault();
        toggleFullscreen(e);
    };
}

function togglePlay(e) {
    e.stopPropagation();
    const video = document.getElementById('videoPlayer');
    const playPauseBtn = document.getElementById('playPauseBtn');
    
    if (video.paused) {
        const playPromise = video.play();
        if (playPromise !== undefined) {
            playPromise.then(() => {
                playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
            }).catch(error => {
                console.error('Error playing video:', error);
                playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
                
                // Handle autoplay restrictions
                if (error.name === 'NotAllowedError') {
                    showAlert('Autoplay restricted. Please click play to start video.');
                }
            });
        }
    } else {
        video.pause();
        playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
    }
}

function seekVideo(e) {
    e.stopPropagation();
    const video = document.getElementById('videoPlayer');
    const progress = document.getElementById('videoProgress');
    const rect = progress.getBoundingClientRect();
    const pos = (e.clientX - rect.left) / rect.width;
    
    // Check if we're seeking to a buffered area
    let targetTime = pos * video.duration;
    let isBuffered = false;
    
    for (let i = 0; i < video.buffered.length; i++) {
        if (targetTime >= video.buffered.start(i) && 
            targetTime <= video.buffered.end(i)) {
            isBuffered = true;
            break;
        }
    }
    
    // Show buffering indicator if seeking to unbuffered area
    if (!isBuffered) {
        document.getElementById('bufferingIndicator').style.display = 'flex';
    }
    
    video.currentTime = targetTime;
    
    // Update progress bar immediately for better UX
    const percent = (targetTime / video.duration) * 100;
    document.getElementById('videoProgressBar').style.width = percent + '%';
}

function toggleFullscreen(e) {
    e.stopPropagation();
    const videoContainer = document.getElementById('videoPreviewContainer');
    const fullscreenBtn = document.getElementById('fullscreenBtn');
    const previewModal = document.getElementById('previewModal');

    if (!document.fullscreenElement) {
        videoContainer.requestFullscreen().then(() => {
            previewModal.classList.add('fullscreen');
            fullscreenBtn.innerHTML = '<i class="fas fa-compress"></i>';
        }).catch(err => {
            console.error('Fullscreen error:', err);
        });
    } else {
        document.exitFullscreen().then(() => {
            previewModal.classList.remove('fullscreen');
            fullscreenBtn.innerHTML = '<i class="fas fa-expand"></i>';
        }).catch(err => {
            console.error('Exit fullscreen error:', err);
        });
    }
}

function navigatePreview(direction) {
    if (previewFiles.length === 0 || currentPreviewIndex === -1 || isLoadingImage) return;
    
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    
    // Slide out navigation buttons
    if (direction > 0) {
        nextBtn.classList.add('slide-out');
    } else {
        prevBtn.classList.add('slide-out');
    }
    
    // Update content with transition
    const previewContent = document.getElementById('previewContent');
    previewContent.classList.add('fade-out');
    
    setTimeout(() => {
        currentPreviewIndex += direction;
        if (currentPreviewIndex < 0) currentPreviewIndex = previewFiles.length - 1;
        if (currentPreviewIndex >= previewFiles.length) currentPreviewIndex = 0;
        
        const file = previewFiles[currentPreviewIndex];
        openPreviewModal(file.url, file.name);
        
        // Reset navigation buttons
        setTimeout(() => {
            prevBtn.classList.remove('slide-out');
            nextBtn.classList.remove('slide-out');
        }, 300);
    }, 300);
}

// Upload and drag-and-drop functionality
const uploadForm = document.getElementById('uploadForm');
const fileInput = document.getElementById('fileInput');
const uploadBtn = document.getElementById('uploadBtn');
const uploadProgressContainer = document.getElementById('uploadProgressContainer');
const uploadProgressBar = document.getElementById('uploadProgressBar');
const uploadProgressPercent = document.getElementById('uploadProgressPercent');
const cancelUploadBtn = document.getElementById('cancelUploadBtn');
const dropZone = document.getElementById('dropZone');
const mainContent = document.querySelector('.main-content');
const fileList = document.getElementById('fileList');
const gridToggleBtn = document.getElementById('gridToggleBtn');

uploadBtn.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => {
  if (fileInput.files.length) startUpload(fileInput.files);
});

mainContent.addEventListener('dragover', (e) => {
  e.preventDefault();
  dropZone.classList.add('active');
});
mainContent.addEventListener('dragleave', (e) => {
  e.preventDefault();
  dropZone.classList.remove('active');
});
mainContent.addEventListener('drop', (e) => {
  e.preventDefault();
  dropZone.classList.remove('active');
  const files = e.dataTransfer.files;
  if (files.length > 0) startUpload(files);
});

function startUpload(fileList) {
  for (let file of fileList) {
    let totalUploaded = 0;
    uploadChunk(file, 0, file.name, totalUploaded);
  }
}

function uploadChunk(file, startByte, fileName, totalUploaded) {
  const chunkSize = 10 * 1024 * 1024; // 10 MB
  const endByte = Math.min(startByte + chunkSize, file.size);
  const chunk = file.slice(startByte, endByte);
  const formData = new FormData();
  formData.append('upload_files[]', chunk, file.name);
  formData.append('file_name', file.name);
  formData.append('chunk_start', startByte);
  formData.append('chunk_end', endByte - 1);
  formData.append('total_size', file.size);
  uploadProgressContainer.style.display = 'block';
  uploadProgressPercent.textContent = `0.0% - Uploading ${fileName}`;
  let attempts = 0;
  const maxAttempts = 3;
  function attemptUpload() {
    const xhr = new XMLHttpRequest();
    currentXhr = xhr;
    xhr.open('POST', uploadForm.action, true);
    xhr.timeout = 3600000;
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        const chunkUploaded = e.loaded;
        const totalBytesUploaded = totalUploaded + chunkUploaded;
        const totalPercent = Math.round((totalBytesUploaded / file.size) * 1000) / 10;
        uploadProgressBar.style.width = totalPercent + '%';
        uploadProgressPercent.textContent = `${totalPercent}% - Uploading ${fileName}`;
      }
    };
    xhr.onload = () => {
      if (xhr.status === 200) {
        totalUploaded += (endByte - startByte);
        if (endByte < file.size) {
          uploadChunk(file, endByte, fileName, totalUploaded);
        } else {
          showAlert('Upload completed successfully.');
          uploadProgressContainer.style.display = 'none';
          location.reload();
        }
      } else {
        handleUploadError(xhr, attempts, maxAttempts);
      }
    };
    xhr.onerror = () => handleUploadError(xhr, attempts, maxAttempts);
    xhr.ontimeout = () => handleUploadError(xhr, attempts, maxAttempts);
    xhr.send(formData);
  }
  function handleUploadError(xhr, attempts, maxAttempts) {
    attempts++;
    if (attempts < maxAttempts) {
      showAlert(`Upload failed for ${fileName} (Attempt ${attempts}). Retrying in 5 seconds... Status: ${xhr.status} - ${xhr.statusText}`);
      setTimeout(attemptUpload, 5000);
    } else {
      showAlert(`Upload failed for ${fileName} after ${maxAttempts} attempts. Status: ${xhr.status} - ${xhr.statusText}. Please check server logs or network connection.`);
      uploadProgressContainer.style.display = 'none';
    }
  }
  attemptUpload();
}

cancelUploadBtn.addEventListener('click', () => {
  if (currentXhr) {
    currentXhr.abort();
    uploadProgressContainer.style.display = 'none';
    fileInput.value = "";
    showAlert('Upload canceled.');
  }
});

// Theme toggling
const themeToggleBtn = document.getElementById('themeToggleBtn');
const body = document.body;
const savedTheme = localStorage.getItem('theme') || 'dark';
if (savedTheme === 'light') {
  body.classList.add('light-mode');
  themeToggleBtn.querySelector('i').classList.replace('fa-moon', 'fa-sun');
} else {
  body.classList.remove('light-mode');
  themeToggleBtn.querySelector('i').classList.replace('fa-sun', 'fa-moon');
}
themeToggleBtn.addEventListener('click', () => {
  body.classList.toggle('light-mode');
  const isLightMode = body.classList.contains('light-mode');
  themeToggleBtn.querySelector('i').classList.toggle('fa-moon', !isLightMode);
  themeToggleBtn.querySelector('i').classList.toggle('fa-sun', isLightMode);
  localStorage.setItem('theme', isLightMode ? 'light' : 'dark');
});

// Grid view toggle
let isGridView = localStorage.getItem('gridView') === 'true';
function updateGridView() {
  fileList.classList.toggle('grid-view', isGridView);
  gridToggleBtn.querySelector('i').classList.toggle('fa-th', isGridView);
  gridToggleBtn.querySelector('i').classList.toggle('fa-list', !isGridView);
  gridToggleBtn.title = isGridView ? 'Switch to List View' : 'Switch to Grid View';
  
  // Handle image previews based on view mode
  const imagePreviewContainers = document.querySelectorAll('.image-preview-container');
  imagePreviewContainers.forEach(container => {
    // Show/hide thumbnails based on view mode
    container.style.display = isGridView ? 'block' : 'none';
    
    // Get the parent folder item
    const parentItem = container.closest('.folder-item');
    if (parentItem) {
      // Find all icons except the ellipsis icon in the more options button
      const icons = parentItem.querySelectorAll('i:not(.fa-ellipsis-v)');
      
      // In grid view, hide the file type icon for image files
      // In list view, show the file type icon for all files
      icons.forEach(icon => {
        // Skip the ellipsis icon in the more options button
        if (icon.closest('.folder-more-options-btn')) return;
        
        // Toggle visibility based on view mode
        icon.style.display = isGridView ? 'none' : 'inline-block';
      });
    }
  });
  
  // Hide share icons in grid view
  const shareIcons = document.querySelectorAll('.share-icon');
  shareIcons.forEach(icon => {
    icon.style.display = isGridView ? 'none' : 'inline-block';
  });
}
updateGridView();
gridToggleBtn.addEventListener('click', () => {
  isGridView = !isGridView;
  localStorage.setItem('gridView', isGridView);
  updateGridView();
});

function closePreviewModal() {
    const previewModal = document.getElementById('previewModal');
    const imageContainer = document.getElementById('imagePreviewContainer');
    const iconContainer = document.getElementById('iconPreviewContainer');
    const videoContainer = document.getElementById('videoPreviewContainer');
    const pdfContainer = document.getElementById('pdfPreviewContainer');
    const pdfViewer = document.getElementById('pdfViewer');
    const pdfControls = document.getElementById('pdfControls');
    const pdfLoadingIndicator = document.getElementById('pdfLoadingIndicator');
    const videoPlayer = document.getElementById('videoPlayer');
    const bufferingIndicator = document.getElementById('bufferingIndicator');
    const previewContent = document.getElementById('previewContent');
    
    // Reset cursor style to default
    document.body.style.cursor = 'default';
    
    // Clear video properly
    try {
        if (videoPlayer) {
            videoPlayer.oncanplay = null;
            videoPlayer.onwaiting = null;
            videoPlayer.onplaying = null;
            videoPlayer.ontimeupdate = null;
            videoPlayer.onprogress = null;
            videoPlayer.onerror = null;
            videoPlayer.onended = null;
            videoPlayer.ondblclick = null;
            videoPlayer.pause();
            videoPlayer.removeAttribute('src');
            videoPlayer.load();
            
            // Reset video controls
            const controls = videoContainer.querySelector('.video-controls');
            if (controls) {
                controls.classList.remove('active');
            }
        }
        
        // Reset buffering indicator
        if (bufferingIndicator) {
            bufferingIndicator.style.display = 'none';
        }
        
        // Reset PDF viewer
        if (pdfViewer) {
            pdfViewer.onload = null;
            pdfViewer.onerror = null;
            pdfViewer.src = '';
        }
        
        // Hide PDF controls
        if (pdfControls) {
            pdfControls.style.display = 'none';
        }
        
        // Reset PDF loading indicator
        if (pdfLoadingIndicator) {
            pdfLoadingIndicator.style.display = 'none';
        }
    } catch (err) {
        console.error('Media cleanup error:', err);
    }
    
    // Clear containers
    imageContainer.innerHTML = '';
    iconContainer.innerHTML = '';
    
    // Reset classes
    previewContent.classList.remove('image-preview');
    previewContent.classList.remove('video-preview');
    previewContent.classList.remove('pdf-preview');
    previewContent.classList.remove('fade-in');
    previewContent.classList.remove('fade-out');
    
    // Remove loaded classes
    if (videoContainer) videoContainer.classList.remove('loaded');
    if (pdfContainer) pdfContainer.classList.remove('loaded');
    
    // Hide all containers
    imageContainer.style.display = 'none';
    iconContainer.style.display = 'none';
    videoContainer.style.display = 'none';
    pdfContainer.style.display = 'none';
    
    // Hide the modal
    previewModal.style.display = 'none';
    
    // Reset loading state
    isLoadingImage = false;
    
    // Remove keyboard event handler
    document.onkeydown = null;
    
    // Clear any remaining timeouts
    const videoControls = videoContainer.querySelector('.video-controls');
    if (videoControls && videoControls._controlsTimeout) {
        clearTimeout(videoControls._controlsTimeout);
    }
}

// Context Menu Functionality
const contextMenu = document.getElementById('contextMenu');
const fileContextOptions = document.querySelector('.file-context-options');
const folderContextOptions = document.querySelector('.folder-context-options');
let currentFileElement = null;
let currentFileName = '';
let currentFileURL = '';

// Prevent default context menu on the entire document
document.addEventListener('contextmenu', function(e) {
  // Only prevent default on folder items
  if (e.target.closest('.folder-item')) {
    e.preventDefault();
  }
});

// Function to position the context menu
function positionContextMenu(x, y) {
  // First make the menu visible but off-screen to calculate its dimensions
  contextMenu.style.display = 'block';
  contextMenu.style.left = '-9999px';
  contextMenu.style.top = '-9999px';
  
  const menuWidth = contextMenu.offsetWidth;
  const menuHeight = contextMenu.offsetHeight;
  const windowWidth = window.innerWidth;
  const windowHeight = window.innerHeight;
  
  // Check if menu goes beyond right edge
  if (x + menuWidth > windowWidth) {
    x = Math.max(5, windowWidth - menuWidth - 5);
  }
  
  // Check if menu goes beyond bottom edge
  if (y + menuHeight > windowHeight) {
    y = windowHeight - menuHeight - 5;
  }
  
  contextMenu.style.left = `${x}px`;
  contextMenu.style.top = `${y}px`;
  contextMenu.style.bottom = 'auto';
  contextMenu.style.width = '';
  contextMenu.style.borderRadius = '5px';
  contextMenu.style.boxShadow = '0 2px 10px rgba(0, 0, 0, 0.2)';
}

// Initialize context menu functionality after DOM is fully loaded
document.addEventListener('DOMContentLoaded', function() {
  // Ensure preview modal is properly hidden on page load
  const previewModal = document.getElementById('previewModal');
  const imageContainer = document.getElementById('imagePreviewContainer');
  const iconContainer = document.getElementById('iconPreviewContainer');
  const videoContainer = document.getElementById('videoPreviewContainer');
  
  // Force hide all preview elements
  previewModal.style.display = 'none';
  imageContainer.style.display = 'none';
  iconContainer.style.display = 'none';
  videoContainer.style.display = 'none';
  imageContainer.innerHTML = '';
  iconContainer.innerHTML = '';
  
  // Apply the correct view mode on page load
  updateGridView();
  
  // Add event listeners to all folder items in the main content area
  const contentFolderItems = document.querySelectorAll('.content-inner .folder-item');
  
  contentFolderItems.forEach(item => {
    const fileURL = item.getAttribute('data-file-url');
    const fileName = item.getAttribute('data-file-name');
    
    // If it has a file URL, it's a file item
    if (fileURL && fileName) {
      // Remove any existing click handlers by cloning the node
      const newItem = item.cloneNode(true);
      item.parentNode.replaceChild(newItem, item);
      item = newItem;
      
      // Add data attribute to mark as selectable
      item.setAttribute('data-selectable', 'true');
      
      // Add double-click event to open preview
      item.addEventListener('dblclick', function(e) {
        // Don't open preview if clicking on the more options button
        if (e.target.closest('.folder-more-options-btn')) {
          e.stopPropagation();
          return;
        }
        
        // Prevent default to avoid navigation issues in grid view
        e.preventDefault();
        
        // Open the preview modal
        openPreviewModal(fileURL, fileName);
      });
      
      // Add single-click event for selection
      item.addEventListener('click', function(e) {
        // Don't select if clicking on the more options button
        if (e.target.closest('.folder-more-options-btn')) {
          e.stopPropagation();
          return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        // Check if Ctrl key is pressed (for multi-select on desktop)
        const isMultiSelect = e.ctrlKey || e.metaKey || this.getAttribute('data-long-press') === 'true';
        
        if (!isMultiSelect) {
          // Single selection mode - deselect all other files first
          document.querySelectorAll('[data-selectable="true"]').forEach(el => {
            if (el !== this) {
              const elFileName = el.getAttribute('data-file-name');
              if (selectedFiles.has(elFileName)) {
                selectedFiles.delete(elFileName);
                el.style.backgroundColor = '';
                el.style.borderColor = '';
              }
            }
          });
        }
        
        // Toggle selection for the clicked item
        if (selectedFiles.has(fileName)) {
          selectedFiles.delete(fileName);
          this.style.backgroundColor = '';
          this.style.borderColor = '';
        } else {
          selectedFiles.add(fileName);
          this.style.backgroundColor = 'rgba(211, 47, 47, 0.1)';
          this.style.borderColor = '#d32f2f';
        }
        
        updateSelectedButtons();
      });
      
      // Add touch-and-hold detection for mobile multi-select
      let touchTimeout;
      let touchStartX;
      let touchStartY;
      
      item.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        
        touchTimeout = setTimeout(() => {
          this.setAttribute('data-long-press', 'true');
          // Visual feedback for long press
          this.style.backgroundColor = 'rgba(211, 47, 47, 0.1)';
          
          // Vibrate if supported (for tactile feedback)
          if (navigator.vibrate) {
            navigator.vibrate(50);
          }
        }, 500); // 500ms for long press
      });
      
      item.addEventListener('touchmove', function(e) {
        // Cancel long press if user moves finger more than a small threshold
        const moveThreshold = 10;
        const touchX = e.touches[0].clientX;
        const touchY = e.touches[0].clientY;
        
        if (Math.abs(touchX - touchStartX) > moveThreshold || 
            Math.abs(touchY - touchStartY) > moveThreshold) {
          clearTimeout(touchTimeout);
          this.removeAttribute('data-long-press');
        }
      });
      
      item.addEventListener('touchend', function() {
        clearTimeout(touchTimeout);
        // Reset long press state after a short delay (to allow the click event to use it)
        setTimeout(() => {
          this.removeAttribute('data-long-press');
        }, 50);
      });
      
      item.addEventListener('touchcancel', function() {
        clearTimeout(touchTimeout);
        this.removeAttribute('data-long-press');
      });
      
      // Add context menu event
      item.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Store the current file information
        currentFileElement = item;
        currentFileName = fileName;
        currentFileURL = fileURL;
        
        // Show file options, hide folder options
        fileContextOptions.style.display = 'block';
        folderContextOptions.style.display = 'none';
        
        // Position and show the context menu
        positionContextMenu(e.pageX, e.pageY);
      });
      
      // Add more options button click event
      const moreOptionsBtn = item.querySelector('.folder-more-options-btn');
      if (moreOptionsBtn) {
        moreOptionsBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          
          // Store the current file information
          currentFileElement = item;
          currentFileName = fileName;
          currentFileURL = fileURL;
          
          // Show file options, hide folder options
          fileContextOptions.style.display = 'block';
          folderContextOptions.style.display = 'none';
          
          // Get button position
          const rect = this.getBoundingClientRect();
          const x = rect.left;
          const y = rect.bottom;
          
          // Position and show the context menu
          positionContextMenu(x, y);
        });
      }
    } else {
      // It's a folder item
      const folderPath = item.getAttribute('data-folder-path');
      const folderName = item.getAttribute('data-folder-name');
      
      if (folderPath && folderName) {
        // Change click event to open folder directly
        item.addEventListener('click', function(e) {
          // Don't open folder if clicking on the more options button
          if (e.target.closest('.folder-more-options-btn')) {
            e.stopPropagation();
            return;
          }
          
          e.stopPropagation();
          // First select the folder (for visual feedback)
          selectFolder(this, folderName);
          // Then open the folder
          openFolder(folderPath);
        });
        
        // Add more options button click event
        const moreOptionsBtn = item.querySelector('.folder-more-options-btn');
        if (moreOptionsBtn) {
          moreOptionsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Select the folder first
            selectFolder(item, folderName);
            
            // Show folder options, hide file options
            fileContextOptions.style.display = 'none';
            folderContextOptions.style.display = 'block';
            
            // Get button position
            const rect = this.getBoundingClientRect();
            const x = rect.left;
            const y = rect.bottom;
            
            // Position and show the context menu
            positionContextMenu(x, y);
          });
        }
        
        // Add context menu event
        item.addEventListener('contextmenu', function(e) {
          e.preventDefault();
          e.stopPropagation();
          
          // Select the folder first
          selectFolder(this, folderName);
          
          // Show folder options, hide file options
          fileContextOptions.style.display = 'none';
          folderContextOptions.style.display = 'block';
          
          // Position and show the context menu
          positionContextMenu(e.pageX, e.pageY);
        });
      }
    }
  });

  // Add event listeners to sidebar folder items
  const sidebarFolderItems = document.querySelectorAll('.sidebar .folder-item');
  
  sidebarFolderItems.forEach(item => {
    const folderPath = item.getAttribute('data-folder-path');
    const folderName = item.getAttribute('data-folder-name');
    
    if (folderPath && folderName) {
      // Change click event to open folder directly
      item.addEventListener('click', function(e) {
        // Don't open folder if clicking on the more options button
        if (e.target.closest('.folder-more-options-btn')) {
          e.stopPropagation();
          return;
        }
        
        e.stopPropagation();
        // First select the folder (for visual feedback)
        selectFolder(this, folderName);
        // Then open the folder
        openFolder(folderPath);
      });
      
      // Add more options button click event
      const moreOptionsBtn = item.querySelector('.folder-more-options-btn');
      if (moreOptionsBtn) {
        moreOptionsBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          
          // Select the folder first
          selectFolder(item, folderName);
          
          // Show folder options, hide file options
          fileContextOptions.style.display = 'none';
          folderContextOptions.style.display = 'block';
          
          // Get button position
          const rect = this.getBoundingClientRect();
          const x = rect.left;
          const y = rect.bottom;
          
          // Position and show the context menu
          positionContextMenu(x, y);
        });
      }
      
      // Add context menu event
      item.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Select the folder first
        selectFolder(this, folderName);
        
        // Show folder options, hide file options
        fileContextOptions.style.display = 'none';
        folderContextOptions.style.display = 'block';
        
        // Position and show the context menu
        positionContextMenu(e.pageX, e.pageY);
      });
    }
  });

  // PDF Zoom Controls
  let currentZoom = 1.0;
  const zoomStep = 0.1;
  
  document.getElementById('zoomIn').addEventListener('click', function() {
    currentZoom += zoomStep;
    applyPdfZoom();
  });
  
  document.getElementById('zoomOut').addEventListener('click', function() {
    currentZoom = Math.max(0.5, currentZoom - zoomStep);
    applyPdfZoom();
  });
  
  document.getElementById('zoomReset').addEventListener('click', function() {
    currentZoom = 1.0;
    applyPdfZoom();
  });
  
  function applyPdfZoom() {
    const pdfViewer = document.getElementById('pdfViewer');
    if (pdfViewer) {
      try {
        const iframeDoc = pdfViewer.contentDocument || pdfViewer.contentWindow.document;
        if (iframeDoc && iframeDoc.body) {
          iframeDoc.body.style.transform = `scale(${currentZoom})`;
          iframeDoc.body.style.transformOrigin = 'center top';
        }
      } catch (e) {
        console.error('Error applying zoom:', e);
      }
    }
  }
  
  // Add keyboard shortcuts for PDF zooming
  document.addEventListener('keydown', function(e) {
    // Only handle keyboard shortcuts when PDF viewer is active
    if (document.getElementById('pdfPreviewContainer').style.display !== 'none') {
      // Ctrl/Cmd + Plus: Zoom in
      if ((e.ctrlKey || e.metaKey) && e.key === '+') {
        e.preventDefault();
        currentZoom += zoomStep;
        applyPdfZoom();
      }
      // Ctrl/Cmd + Minus: Zoom out
      else if ((e.ctrlKey || e.metaKey) && e.key === '-') {
        e.preventDefault();
        currentZoom = Math.max(0.5, currentZoom - zoomStep);
        applyPdfZoom();
      }
      // Ctrl/Cmd + 0: Reset zoom
      else if ((e.ctrlKey || e.metaKey) && e.key === '0') {
        e.preventDefault();
        currentZoom = 1.0;
        applyPdfZoom();
      }
    }
  });
});

// Hide context menu when clicking elsewhere
document.addEventListener('click', function() {
  contextMenu.style.display = 'none';
});

// Prevent context menu from closing when clicking inside it
contextMenu.addEventListener('click', function(e) {
  e.stopPropagation();
});

// File context menu actions
document.getElementById('contextMenuOpen').addEventListener('click', function() {
  if (currentFileURL) {
    openPreviewModal(currentFileURL, currentFileName);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuInfo').addEventListener('click', function() {
  if (currentFileName) {
    showFileInfo(currentFileName);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuDownload').addEventListener('click', function() {
  if (currentFileURL) {
    downloadFile(currentFileURL);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuRename').addEventListener('click', function() {
  if (currentFileName) {
    renameFilePrompt(currentFileName);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuDelete').addEventListener('click', function() {
  if (currentFileName) {
    confirmFileDelete(currentFileName);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuShare').addEventListener('click', function() {
  if (currentFileName) {
    const filePath = currentPath + '/' + currentFileName;
    
    // Create a modal dialog for sharing
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
      <div class="modal-content" style="width: 500px;">
        <div class="modal-header">
          <h2>Share File</h2>
          <span class="close">&times;</span>
        </div>
        <div class="modal-body">
          <p>Share file: <strong>${currentFileName}</strong></p>
          
          <div class="share-toggle-container" style="margin: 20px 0; display: flex; align-items: center;">
            <label for="shareToggle" style="margin-right: 10px;">Enable sharing:</label>
            <label class="switch">
              <input type="checkbox" id="shareToggle">
              <span class="slider round"></span>
            </label>
            <span id="shareToggleStatus" style="margin-left: 10px; font-size: 14px;">Off</span>
          </div>
          
          <div id="shareStatus" style="margin: 15px 0; display: none;"></div>
          <div id="shareLink" style="display: none;">
            <p style="margin-bottom: 5px;">Share link:</p>
            <input type="text" id="shareLinkInput" readonly style="width: 100%; padding: 8px; margin-bottom: 10px; background-color: var(--content-bg); color: var(--text-color); border: 1px solid var(--border-color); outline: none; border-radius: 4px;">
            <div style="display: flex; gap: 20px;">
              <button id="copyShareLink" type="button" class="multi-select-btn" title="Copy Link" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-copy" style="margin-right: 8px;"></i>Copy Link
              </button>
              <button id="copyRawLink" type="button" class="multi-select-btn" title="Copy Raw Link" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-code" style="margin-right: 8px;"></i>Copy Raw
              </button>
              <button id="previewShareLink" type="button" class="multi-select-btn" title="Preview Link" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-external-link-alt" style="margin-right: 8px;"></i>Preview
              </button>
            </div>
          </div>
          <div id="shareLoading" style="display: none;">
            <div class="spinner" style="margin: 0 auto; width: 40px; height: 40px; border: 4px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: var(--accent-red); animation: spin 1s linear infinite;"></div>
            <p style="text-align: center; margin-top: 10px;">Processing...</p>
          </div>
        </div>
      </div>
    `;
    
    // Add spinner animation
    const style = document.createElement('style');
    style.textContent = `
      @keyframes spin {
        to { transform: rotate(360deg); }
      }
    `;
    document.head.appendChild(style);
    
    document.body.appendChild(modal);
    modal.style.display = 'block';
    
    const shareToggle = document.getElementById('shareToggle');
    const shareToggleStatus = document.getElementById('shareToggleStatus');
    const shareStatus = document.getElementById('shareStatus');
    const shareLink = document.getElementById('shareLink');
    const shareLoading = document.getElementById('shareLoading');
    const shareLinkInput = document.getElementById('shareLinkInput');
    
    // Helper function to handle fetch errors
    const handleFetchError = (error) => {
      console.error('Fetch error:', error);
      shareLoading.style.display = 'none';
      shareStatus.style.display = 'block';
      shareStatus.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
    };
    
    // Helper function to handle response parsing
    const handleResponse = async (response) => {
      console.log('Response status:', response.status);
      console.log('Response headers:', response.headers);
      
      // Check if response is ok (status in the range 200-299)
      if (!response.ok) {
        console.error('Response not OK:', response.status, response.statusText);
        return response.text().then(text => {
          console.error('Error response text:', text);
          try {
            return JSON.parse(text);
          } catch (e) {
            console.error('Failed to parse error response as JSON:', e);
            throw new Error('Server error: ' + response.status);
          }
        });
      }
      
      // Try to parse as JSON
      return response.text().then(text => {
        console.log('Response text:', text);
        try {
          return JSON.parse(text);
        } catch (e) {
          console.error('Failed to parse response as JSON:', e, 'Raw response:', text);
          throw new Error('Failed to parse JSON response');
        }
      });
    };
    
    // Check if file is shared
    shareLoading.style.display = 'block';
    
    fetch(`share_handler.php?action=check_share&file_path=${encodeURIComponent(filePath)}`)
      .then(response => {
        console.log('Check share response status:', response.status);
        return handleResponse(response);
      })
      .then(data => {
        console.log('Check share response data:', data);
        shareLoading.style.display = 'none';
        
        if (data && data.success) {
          if (data.is_shared) {
            // File is already shared, set toggle to on
            shareToggle.checked = true;
            shareToggleStatus.textContent = 'On';
            shareToggleStatus.style.color = '#4CAF50';
            
            // Show share link
            shareLink.style.display = 'block';
            // Use the full URL from the server response
            shareLinkInput.value = data.share_url;
            
            // Find the file item and add the share icon if it doesn't exist
            const fileItem = document.querySelector(`.folder-item[data-file-name="${currentFileName.replace(/"/g, '\\"')}"]`);
            if (fileItem) {
              const folderActions = fileItem.querySelector('.folder-actions');
              if (folderActions && !folderActions.querySelector('.share-icon')) {
                const shareIcon = document.createElement('i');
                shareIcon.className = 'fas fa-globe share-icon';
                shareIcon.title = 'This file is shared';
                folderActions.insertBefore(shareIcon, folderActions.firstChild);
              }
            }
          }
        } else if (data && data.message) {
          // Show error message
          shareStatus.style.display = 'block';
          shareStatus.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
        }
      })
      .catch(error => {
        console.error('Check share error:', error);
        handleFetchError(error);
      });
    
    // Toggle share functionality
    shareToggle.addEventListener('change', function() {
      shareLoading.style.display = 'block';
      shareStatus.style.display = 'none';
      shareLink.style.display = 'none';
      
      if (this.checked) {
        // Enable sharing
        shareToggleStatus.textContent = 'On';
        shareToggleStatus.style.color = '#4CAF50';
        
        console.log('Enabling sharing for:', filePath);
        
        // Create share
        const formData = new FormData();
        formData.append('file_path', filePath);
        formData.append('action', 'create_share');
        
        fetch('share_handler.php', {
          method: 'POST',
          body: formData
        })
        .then(response => {
          console.log('Create share response status:', response.status);
          return handleResponse(response);
        })
        .then(data => {
          console.log('Create share response data:', data);
          shareLoading.style.display = 'none';
          
          if (data && data.success) {
            shareStatus.style.display = 'block';
            shareStatus.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
            
            shareLink.style.display = 'block';
            // Use the full URL from the server response
            shareLinkInput.value = data.share_url;
            
            // Add share icon to the file item
            const fileItem = document.querySelector(`.folder-item[data-file-name="${currentFileName.replace(/"/g, '\\"')}"]`);
            if (fileItem) {
              const folderActions = fileItem.querySelector('.folder-actions');
              if (folderActions) {
                // Remove existing share icon if any
                const existingIcon = folderActions.querySelector('.share-icon');
                if (existingIcon) {
                  existingIcon.remove();
                }
                
                // Add new share icon
                const shareIcon = document.createElement('i');
                shareIcon.className = 'fas fa-globe share-icon';
                shareIcon.title = 'This file is shared';
                folderActions.insertBefore(shareIcon, folderActions.firstChild);
              }
            }
          } else {
            shareStatus.style.display = 'block';
            shareStatus.innerHTML = `<div class="alert alert-danger">${data.message || 'Unknown error'}</div>`;
            // Reset toggle if failed
            shareToggle.checked = false;
            shareToggleStatus.textContent = 'Off';
            shareToggleStatus.style.color = '';
          }
        })
        .catch(error => {
          console.error('Create share error:', error);
          shareLoading.style.display = 'none';
          shareStatus.style.display = 'block';
          shareStatus.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
          // Reset toggle if failed
          shareToggle.checked = false;
          shareToggleStatus.textContent = 'Off';
          shareToggleStatus.style.color = '';
        });
      } else {
        // Disable sharing
        shareToggleStatus.textContent = 'Off';
        shareToggleStatus.style.color = '';
        
        console.log('Disabling sharing for:', filePath);
        
        // Use fetch instead of XMLHttpRequest for better consistency
        fetch(`share_handler.php?file_path=${encodeURIComponent(filePath)}`, {
          method: 'DELETE',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(response => {
          console.log('Delete share response status:', response.status);
          return handleResponse(response);
        })
        .then(data => {
          console.log('Delete share response data:', data);
          shareLoading.style.display = 'none';
          
          if (data && data.success) {
            shareStatus.style.display = 'block';
            shareStatus.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
            shareLink.style.display = 'none';
            
            // Find the file item and remove the share icon
            const fileItem = document.querySelector(`.folder-item[data-file-name="${currentFileName.replace(/"/g, '\\"')}"]`);
            if (fileItem) {
              const shareIcon = fileItem.querySelector('.share-icon');
              if (shareIcon) {
                shareIcon.remove();
              }
            }
          } else {
            shareStatus.style.display = 'block';
            shareStatus.innerHTML = `<div class="alert alert-danger">${data.message || 'Unknown error'}</div>`;
            // Reset toggle if failed
            shareToggle.checked = true;
            shareToggleStatus.textContent = 'On';
            shareToggleStatus.style.color = '#4CAF50';
          }
        })
        .catch(error => {
          console.error('Delete share error:', error);
          shareLoading.style.display = 'none';
          shareStatus.style.display = 'block';
          shareStatus.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
          // Reset toggle if failed
          shareToggle.checked = true;
          shareToggleStatus.textContent = 'On';
          shareToggleStatus.style.color = '#4CAF50';
        });
      }
    });
    
    // Copy link button
    document.getElementById('copyShareLink').addEventListener('click', function() {
      shareLinkInput.select();
      document.execCommand('copy');
      const originalText = this.innerHTML;
      this.innerHTML = '<i class="fas fa-check" style="margin-right: 8px;"></i>Copied!';
      setTimeout(() => {
        this.innerHTML = originalText;
      }, 2000);
    });
    
    // Copy raw link button
    document.getElementById('copyRawLink').addEventListener('click', function() {
      const shareUrl = new URL(shareLinkInput.value);
      const rawUrl = `${shareUrl.origin}/selfhostedgdrive/shared.php?id=${shareUrl.searchParams.get('id')}&preview=1`;
      const tempInput = document.createElement('input');
      document.body.appendChild(tempInput);
      tempInput.value = rawUrl;
      tempInput.select();
      document.execCommand('copy');
      document.body.removeChild(tempInput);
      const originalText = this.innerHTML;
      this.innerHTML = '<i class="fas fa-check" style="margin-right: 8px;"></i>Copied!';
      setTimeout(() => {
        this.innerHTML = originalText;
      }, 2000);
    });
    
    // Preview link button
    document.getElementById('previewShareLink').addEventListener('click', function() {
      window.open(shareLinkInput.value, '_blank');
    });
    
    // Close modal when clicking on X
    modal.querySelector('.close').addEventListener('click', function() {
      modal.style.display = 'none';
      setTimeout(() => {
        document.body.removeChild(modal);
      }, 300);
    });
    
    // Close modal when clicking outside of it
    window.addEventListener('click', function(event) {
      if (event.target === modal) {
        modal.style.display = 'none';
        setTimeout(() => {
          document.body.removeChild(modal);
        }, 300);
      }
    });
    
    contextMenu.style.display = 'none';
  }
});

// Folder context menu actions
document.getElementById('contextMenuOpenFolder').addEventListener('click', function() {
  if (selectedFolder) {
    const folderPath = document.querySelector('.folder-item.selected').getAttribute('data-folder-path');
    openFolder(folderPath);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuShareFolder').addEventListener('click', function() {
  if (selectedFolder) {
    const folderPath = document.querySelector('.folder-item.selected').getAttribute('data-folder-path');
    
    // Create a modal dialog for sharing folder
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
      <div class="modal-content" style="width: 500px;">
        <div class="modal-header">
          <h2>Share Folder</h2>
          <span class="close">&times;</span>
        </div>
        <div class="modal-body">
          <p>Share folder: <strong>${selectedFolder}</strong></p>
          
          <div class="share-toggle-container" style="margin: 20px 0; display: flex; align-items: center;">
            <label for="folderShareToggle" style="margin-right: 10px;">Enable sharing:</label>
            <label class="switch">
              <input type="checkbox" id="folderShareToggle">
              <span class="slider round"></span>
            </label>
            <span id="folderShareToggleStatus" style="margin-left: 10px; font-size: 14px;">Off</span>
          </div>
          
          <div id="folderShareStatus" style="margin: 15px 0; display: none;"></div>
          <div id="folderShareLink" style="display: none;">
            <p style="margin-bottom: 5px;">Share link:</p>
            <input type="text" id="folderShareLinkInput" readonly style="width: 100%; padding: 8px; margin-bottom: 10px; background-color: var(--content-bg); color: var(--text-color); border: 1px solid var(--border-color); outline: none; border-radius: 4px;">
            <div style="display: flex; gap: 20px;">
              <button id="copyFolderShareLink" type="button" class="multi-select-btn" title="Copy Link" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-copy" style="margin-right: 8px;"></i>Copy Link
              </button>
              <button id="copyFolderRawLink" type="button" class="multi-select-btn" title="Copy Raw Link" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-code" style="margin-right: 8px;"></i>Copy Raw
              </button>
              <button id="previewFolderShareLink" type="button" class="multi-select-btn" title="Preview Link" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-external-link-alt" style="margin-right: 8px;"></i>Preview
              </button>
            </div>
          </div>
          <div id="folderShareLoading" style="display: none;">
            <div class="spinner" style="margin: 0 auto; width: 40px; height: 40px; border: 4px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: var(--accent-red); animation: spin 1s linear infinite;"></div>
            <p style="text-align: center; margin-top: 10px;">Processing...</p>
          </div>
        </div>
      </div>
    `;
    
    document.body.appendChild(modal);
    modal.style.display = 'block';
    
    const folderShareToggle = document.getElementById('folderShareToggle');
    const folderShareToggleStatus = document.getElementById('folderShareToggleStatus');
    const folderShareStatus = document.getElementById('folderShareStatus');
    const folderShareLink = document.getElementById('folderShareLink');
    const folderShareLoading = document.getElementById('folderShareLoading');
    const folderShareLinkInput = document.getElementById('folderShareLinkInput');
    
    // Helper function to handle fetch errors
    const handleFetchError = (error) => {
      console.error('Fetch error:', error);
      folderShareLoading.style.display = 'none';
      folderShareStatus.style.display = 'block';
      folderShareStatus.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
    };
    
    // Helper function to handle response parsing
    const handleResponse = async (response) => {
      console.log('Response status:', response.status);
      console.log('Response headers:', response.headers);
      
      // Check if response is ok (status in the range 200-299)
      if (!response.ok) {
        console.error('Response not OK:', response.status, response.statusText);
        return response.text().then(text => {
          console.error('Error response text:', text);
          try {
            return JSON.parse(text);
          } catch (e) {
            console.error('Failed to parse error response as JSON:', e);
            throw new Error('Server error: ' + response.status);
          }
        });
      }
      
      // Try to parse as JSON
      return response.text().then(text => {
        console.log('Response text:', text);
        try {
          return JSON.parse(text);
        } catch (e) {
          console.error('Failed to parse response as JSON:', e, 'Raw response:', text);
          throw new Error('Failed to parse JSON response');
        }
      });
    };
    
    // Check if folder is shared
    folderShareLoading.style.display = 'block';
    
    fetch(`folder_share_handler.php?action=check_folder_share&folder_path=${encodeURIComponent(folderPath)}`)
      .then(response => {
        console.log('Check folder share response status:', response.status);
        if (!response.ok) {
          if (response.status === 404) {
            throw new Error('Folder sharing handler not found. Please ensure folder_share_handler.php exists.');
          } else {
            throw new Error('Network response was not ok: ' + response.status);
          }
        }
        return handleResponse(response);
      })
      .then(data => {
        console.log('Check folder share response data:', data);
        folderShareLoading.style.display = 'none';
        
        if (data && data.success) {
          if (data.is_shared) {
            // Folder is already shared, set toggle to on
            folderShareToggle.checked = true;
            folderShareToggleStatus.textContent = 'On';
            folderShareToggleStatus.style.color = '#4CAF50';
            
            // Show share link
            folderShareLink.style.display = 'block';
            // Use the full URL from the server response
            folderShareLinkInput.value = data.share_url;
            
            // Find the folder item and add the share icon if it doesn't exist
            const folderItem = document.querySelector(`.folder-item[data-folder-name="${selectedFolder.replace(/"/g, '\\"')}"]`);
            if (folderItem) {
              const folderActions = folderItem.querySelector('.folder-actions');
              if (folderActions && !folderActions.querySelector('.share-icon')) {
                const shareIcon = document.createElement('i');
                shareIcon.className = 'fas fa-globe share-icon';
                shareIcon.title = 'This folder is shared';
                folderActions.insertBefore(shareIcon, folderActions.firstChild);
              }
            }
          }
        } else if (data && data.message) {
          // Show error message
          folderShareStatus.style.display = 'block';
          folderShareStatus.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
        }
      })
      .catch(error => {
        console.error('Check folder share error:', error);
        handleFetchError(error);
      });
    
    // Toggle share functionality
    folderShareToggle.addEventListener('change', function() {
      folderShareLoading.style.display = 'block';
      folderShareStatus.style.display = 'none';
      folderShareLink.style.display = 'none';
      
      if (this.checked) {
        // Enable sharing
        folderShareToggleStatus.textContent = 'On';
        folderShareToggleStatus.style.color = '#4CAF50';
        
        console.log('Enabling sharing for folder:', folderPath);
        
        // Create share
        const formData = new FormData();
        formData.append('folder_path', folderPath);
        formData.append('action', 'create_folder_share');
        
        fetch('folder_share_handler.php', {
          method: 'POST',
          body: formData
        })
        .then(response => {
          console.log('Create folder share response status:', response.status);
          return handleResponse(response);
        })
        .then(data => {
          console.log('Create folder share response data:', data);
          folderShareLoading.style.display = 'none';
          
          if (data && data.success) {
            folderShareStatus.style.display = 'block';
            folderShareStatus.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
            
            folderShareLink.style.display = 'block';
            // Use the full URL from the server response
            folderShareLinkInput.value = data.share_url;
            
            // Add share icon to the folder item
            const folderItem = document.querySelector(`.folder-item[data-folder-name="${selectedFolder.replace(/"/g, '\\"')}"]`);
            if (folderItem) {
              const folderActions = folderItem.querySelector('.folder-actions');
              if (folderActions) {
                // Remove existing share icon if any
                const existingIcon = folderActions.querySelector('.share-icon');
                if (existingIcon) {
                  existingIcon.remove();
                }
                
                // Add new share icon
                const shareIcon = document.createElement('i');
                shareIcon.className = 'fas fa-globe share-icon';
                shareIcon.title = 'This folder is shared';
                folderActions.insertBefore(shareIcon, folderActions.firstChild);
              }
            }
          } else {
            folderShareStatus.style.display = 'block';
            folderShareStatus.innerHTML = `<div class="alert alert-danger">${data.message || 'Unknown error'}</div>`;
            // Reset toggle if failed
            folderShareToggle.checked = false;
            folderShareToggleStatus.textContent = 'Off';
            folderShareToggleStatus.style.color = '';
          }
        })
        .catch(error => {
          console.error('Create folder share error:', error);
          folderShareLoading.style.display = 'none';
          folderShareStatus.style.display = 'block';
          folderShareStatus.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
          // Reset toggle if failed
          folderShareToggle.checked = false;
          folderShareToggleStatus.textContent = 'Off';
          folderShareToggleStatus.style.color = '';
        });
      } else {
        // Disable sharing
        folderShareToggleStatus.textContent = 'Off';
        folderShareToggleStatus.style.color = '';
        
        console.log('Disabling sharing for folder:', folderPath);
        
        // Use fetch instead of XMLHttpRequest for better consistency
        fetch(`folder_share_handler.php?folder_path=${encodeURIComponent(folderPath)}`, {
          method: 'DELETE',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(response => {
          console.log('Delete folder share response status:', response.status);
          return handleResponse(response);
        })
        .then(data => {
          console.log('Delete folder share response data:', data);
          folderShareLoading.style.display = 'none';
          
          if (data && data.success) {
            folderShareStatus.style.display = 'block';
            folderShareStatus.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
            folderShareLink.style.display = 'none';
            
            // Find the folder item and remove the share icon
            const folderItem = document.querySelector(`.folder-item[data-folder-name="${selectedFolder.replace(/"/g, '\\"')}"]`);
            if (folderItem) {
              const shareIcon = folderItem.querySelector('.share-icon');
              if (shareIcon) {
                shareIcon.remove();
              }
            }
          } else {
            folderShareStatus.style.display = 'block';
            folderShareStatus.innerHTML = `<div class="alert alert-danger">${data.message || 'Unknown error'}</div>`;
            // Reset toggle if failed
            folderShareToggle.checked = true;
            folderShareToggleStatus.textContent = 'On';
            folderShareToggleStatus.style.color = '#4CAF50';
          }
        })
        .catch(error => {
          console.error('Delete folder share error:', error);
          folderShareLoading.style.display = 'none';
          folderShareStatus.style.display = 'block';
          folderShareStatus.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
          // Reset toggle if failed
          folderShareToggle.checked = true;
          folderShareToggleStatus.textContent = 'On';
          folderShareToggleStatus.style.color = '#4CAF50';
        });
      }
    });
    
    // Copy link button
    document.getElementById('copyFolderShareLink').addEventListener('click', function() {
      folderShareLinkInput.select();
      document.execCommand('copy');
      const originalText = this.innerHTML;
      this.innerHTML = '<i class="fas fa-check" style="margin-right: 8px;"></i>Copied!';
      setTimeout(() => {
        this.innerHTML = originalText;
      }, 2000);
    });
    
    // Copy raw link button
    document.getElementById('copyFolderRawLink').addEventListener('click', function() {
      const shareUrl = new URL(folderShareLinkInput.value);
      const rawUrl = `${shareUrl.origin}/selfhostedgdrive/shared_folder.php?id=${shareUrl.searchParams.get('id')}&preview=1`;
      const tempInput = document.createElement('input');
      document.body.appendChild(tempInput);
      tempInput.value = rawUrl;
      tempInput.select();
      document.execCommand('copy');
      document.body.removeChild(tempInput);
      const originalText = this.innerHTML;
      this.innerHTML = '<i class="fas fa-check" style="margin-right: 8px;"></i>Copied!';
      setTimeout(() => {
        this.innerHTML = originalText;
      }, 2000);
    });
    
    // Preview link button
    document.getElementById('previewFolderShareLink').addEventListener('click', function() {
      window.open(folderShareLinkInput.value, '_blank');
    });
    
    // Close modal when clicking on X
    modal.querySelector('.close').addEventListener('click', function() {
      modal.style.display = 'none';
      setTimeout(() => {
        document.body.removeChild(modal);
      }, 300);
    });
    
    // Close modal when clicking outside of it
    window.addEventListener('click', function(event) {
      if (event.target === modal) {
        modal.style.display = 'none';
        setTimeout(() => {
          document.body.removeChild(modal);
        }, 300);
      }
    });
    
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuRenameFolder').addEventListener('click', function() {
  if (selectedFolder) {
    renameFolderPrompt(selectedFolder);
    contextMenu.style.display = 'none';
  }
});

document.getElementById('contextMenuDeleteFolder').addEventListener('click', function() {
  if (selectedFolder) {
    confirmFolderDelete(selectedFolder);
    contextMenu.style.display = 'none';
  }
});

// Initialize particles for sidebar
particlesJS('sidebar-particles-js', {
  particles: {
    number: { 
      value: 70,
      density: { 
        enable: true, 
        value_area: 800
      }
    },
    color: { 
      value: ["#ff4444", "#ff6b6b", "#ff8888", "#ff2222"]
    },
    shape: {
      type: "circle",
      stroke: {
        width: 0,
        color: "#ff0000"
      }
    },
    opacity: {
      value: 0.9,
      random: true,
      anim: {
        enable: true,
        speed: 0.8,
        opacity_min: 0.5,
        sync: false
      }
    },
    size: {
      value: 5,
      random: {
        enable: true,
        minimumValue: 2
      }
    },
    line_linked: {
      enable: true,
      distance: 80,
      color: "#ff6666",
      opacity: 0.7,
      width: 1.5
    },
    move: {
      enable: true,
      speed: 3,
      direction: "none",
      random: true,
      straight: false,
      out_mode: "out",
      bounce: false,
      attract: {
        enable: true,
        rotateX: 600,
        rotateY: 1200
      }
    }
  },
  interactivity: {
    detect_on: "canvas",
    events: {
      onhover: {
        enable: true,
        mode: "bubble"
      },
      onclick: {
        enable: false,
        mode: "push"
      },
      resize: true
    },
    modes: {
      bubble: {
        distance: 100,
        size: 6,
        duration: 2,
        opacity: 1,
        speed: 3
      }
    }
  },
  retina_detect: true
});

// Function to display file information
function showFileInfo(fileName) {
  // Get file information from data attributes
  const fileElement = document.querySelector(`.folder-item[data-file-name="${fileName.replace(/"/g, '\\"')}"]`);
  if (!fileElement) return;
  
  const fileSize = parseInt(fileElement.getAttribute('data-file-size'));
  const fileType = fileElement.getAttribute('data-file-type');
  const fileModified = parseInt(fileElement.getAttribute('data-file-modified'));
  
  // Format the file size
  let sizeStr = '';
  if (fileSize < 1024) {
    sizeStr = fileSize + ' B';
  } else if (fileSize < 1024 * 1024) {
    sizeStr = (fileSize / 1024).toFixed(2) + ' KB';
  } else if (fileSize < 1024 * 1024 * 1024) {
    sizeStr = (fileSize / (1024 * 1024)).toFixed(2) + ' MB';
  } else {
    sizeStr = (fileSize / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
  }
  
  // Format the date
  const date = new Date(fileModified * 1000);
  const dateStr = date.toLocaleString();
  
  // Create info message
  const infoMessage = `
    <div style="text-align: left;">
      <p><strong>Name:</strong> ${fileName}</p>
      <p><strong>Size:</strong> ${sizeStr}</p>
      <p><strong>Type:</strong> ${fileType}</p>
      <p><strong>Modified:</strong> ${dateStr}</p>
    </div>
  `;
  
  // Show the info in a dialog
  showAlert(infoMessage);
}

// Function to handle folder rename
function renameFolderPrompt(folderName) {
  showPrompt("Enter new folder name:", folderName, function(newName) {
    if (newName && newName.trim() !== "" && newName !== folderName) {
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
      let inputAction = document.createElement('input');
      inputAction.type = 'hidden';
      inputAction.name = 'rename_folder';
      inputAction.value = '1';
      form.appendChild(inputAction);
      let inputOld = document.createElement('input');
      inputOld.type = 'hidden';
      inputOld.name = 'old_folder_name';
      inputOld.value = folderName;
      form.appendChild(inputOld);
      let inputNew = document.createElement('input');
      inputNew.type = 'hidden';
      inputNew.name = 'new_folder_name';
      inputNew.value = newName.trim();
      form.appendChild(inputNew);
      document.body.appendChild(form);
      form.submit();
    }
  });
}

// Function to handle folder delete
function confirmFolderDelete(folderName) {
  showConfirm(`Delete folder "${folderName}"?`, () => {
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>&delete=' + encodeURIComponent(folderName);
    document.body.appendChild(form);
    form.submit();
  });
}

// Multi-select functionality
const selectAllBtn = document.getElementById('selectAllBtn');
const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');

// Initially hide the delete and download buttons
deleteSelectedBtn.style.display = 'none';
downloadSelectedBtn.style.display = 'none';

// Track selection state
let selectedFiles = new Set();

// Update the visibility of delete and download buttons
function updateSelectedButtons() {
  if (selectedFiles.size > 0) {
    deleteSelectedBtn.style.display = 'inline-flex';
    downloadSelectedBtn.style.display = 'inline-flex';
    selectAllBtn.innerHTML = '<i class="fas fa-times"></i>';
  } else {
    deleteSelectedBtn.style.display = 'none';
    downloadSelectedBtn.style.display = 'none';
    selectAllBtn.innerHTML = '<i class="fas fa-check-square" style="margin-right: 8px;"></i>All';
  }
}

// Select All button functionality
selectAllBtn.addEventListener('click', function() {
  if (selectedFiles.size > 0) {
    // If files are selected, deselect all
    selectedFiles.clear();
    document.querySelectorAll('[data-selectable="true"]').forEach(item => {
      item.style.backgroundColor = '';
      item.style.borderColor = '';
    });
  } else {
    // If no files are selected, select all
    document.querySelectorAll('[data-selectable="true"]').forEach(item => {
      const fileName = item.getAttribute('data-file-name');
      selectedFiles.add(fileName);
      item.style.backgroundColor = 'rgba(211, 47, 47, 0.1)';
      item.style.borderColor = '#d32f2f';
    });
  }
  
  updateSelectedButtons();
});

// Delete button click handler (placeholder for now)
deleteSelectedBtn.addEventListener('click', function() {
  if (selectedFiles.size === 0) return;
  
  // Create confirmation message based on number of files
  const fileCount = selectedFiles.size;
  const confirmMessage = fileCount === 1 
    ? `Are you sure you want to delete this file?` 
    : `Are you sure you want to delete these ${fileCount} files?`;
  
  showConfirm(confirmMessage, function() {
    // Create a form to submit the delete request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
    
    // Add a hidden input for the delete_files action
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'delete_files';
    actionInput.value = '1';
    form.appendChild(actionInput);
    
    // Add all selected files as hidden inputs
    let index = 0;
    selectedFiles.forEach(fileName => {
      const fileInput = document.createElement('input');
      fileInput.type = 'hidden';
      fileInput.name = 'files_to_delete[]';
      fileInput.value = fileName;
      form.appendChild(fileInput);
      index++;
    });
    
    // Append the form to the body and submit it
    document.body.appendChild(form);
    form.submit();
  });
});

// Download button click handler
downloadSelectedBtn.addEventListener('click', function() {
  if (selectedFiles.size === 0) return;
  
  if (selectedFiles.size === 1) {
    // If only one file is selected, download it directly
    const fileName = selectedFiles.values().next().value;
    const fileItem = document.querySelector(`[data-file-name="${fileName}"]`);
    const fileURL = fileItem.getAttribute('data-file-url');
    
    if (fileURL) {
      // Create a temporary link and click it to download
      const downloadLink = document.createElement('a');
      downloadLink.href = fileURL;
      downloadLink.setAttribute('download', fileName);
      downloadLink.style.display = 'none';
      document.body.appendChild(downloadLink);
      downloadLink.click();
      document.body.removeChild(downloadLink);
    }
  } else {
    // For multiple files, create a zip archive
    showAlert('Preparing files for download...', 'info');
    
    // Create a form to submit the download request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
    
    // Add a hidden input for the download_files action
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'download_files';
    actionInput.value = '1';
    form.appendChild(actionInput);
    
    // Add all selected files as hidden inputs
    let index = 0;
    selectedFiles.forEach(fileName => {
      const fileInput = document.createElement('input');
      fileInput.type = 'hidden';
      fileInput.name = 'files_to_download[]';
      fileInput.value = fileName;
      form.appendChild(fileInput);
      index++;
    });
    
    // Append the form to the body and submit it
    document.body.appendChild(form);
    form.submit();
  }
});

// ... existing code ...
    // Add keyboard shortcuts for PDF zooming
    document.addEventListener('keydown', function(e) {
      // Only handle keyboard shortcuts when PDF viewer is active
      if (document.getElementById('pdfPreviewContainer').style.display !== 'none') {
        // Ctrl/Cmd + Plus: Zoom in
        if ((e.ctrlKey || e.metaKey) && e.key === '+') {
          e.preventDefault();
          currentZoom += zoomStep;
          applyPdfZoom();
        }
        // Ctrl/Cmd + Minus: Zoom out
        else if ((e.ctrlKey || e.metaKey) && e.key === '-') {
          e.preventDefault();
          currentZoom = Math.max(0.5, currentZoom - zoomStep);
          applyPdfZoom();
        }
        // Ctrl/Cmd + 0: Reset zoom
        else if ((e.ctrlKey || e.metaKey) && e.key === '0') {
          e.preventDefault();
          currentZoom = 1.0;
          applyPdfZoom();
        }
      }
    });
// ... existing code ...

    // Add event listener for PDF viewer to remove any hover effects
    document.addEventListener('DOMContentLoaded', function() {
      const pdfViewer = document.getElementById('pdfViewer');
      if (pdfViewer) {
        pdfViewer.addEventListener('load', function() {
          try {
            const iframeDoc = pdfViewer.contentDocument || pdfViewer.contentWindow.document;
            if (iframeDoc) {
              // Create a style element to inject CSS into the iframe
              const style = iframeDoc.createElement('style');
              style.textContent = `
                * { 
                  outline: none !important;
                  border: none !important;
                }
                a:hover, a:focus {
                  outline: none !important;
                  border: none !important;
                }
              `;
              iframeDoc.head.appendChild(style);
            }
          } catch (e) {
            console.error('Error removing hover effects:', e);
          }
        });
      }
    });

    // Function to check and add share icons
    function refreshShareIcons() {
      console.log('Refreshing share icons...');
      
      // Get all folder items that don't already have share icons
      const folderItems = Array.from(document.querySelectorAll('.folder-item[data-folder-name]'))
        .filter(item => !item.querySelector('.share-icon'));
      
      // Get all file items that don't already have share icons
      const fileItems = Array.from(document.querySelectorAll('.folder-item[data-file-name]'))
        .filter(item => !item.querySelector('.share-icon'));
      
      if (folderItems.length === 0 && fileItems.length === 0) {
        console.log('No new items to check for sharing status.');
        return;
      }
      
      const currentPath = document.getElementById('currentPath')?.value || '';
      console.log(`Found ${folderItems.length} folders and ${fileItems.length} files to check. Current path: ${currentPath}`);
      
      // Check folders
      folderItems.forEach(folderItem => {
        const folderName = folderItem.getAttribute('data-folder-name');
        const folderPath = folderItem.getAttribute('data-folder-path') || 
                          (currentPath ? currentPath + '/' + folderName : folderName);
        
        console.log(`Checking folder: ${folderName}, path: ${folderPath}`);
        
        fetch(`folder_share_handler.php?action=check_folder_share&folder_path=${encodeURIComponent(folderPath)}`)
          .then(response => response.json())
          .then(data => {
            console.log(`Folder ${folderName} share response:`, data);
            if (data && data.success && data.is_shared) {
              const folderActions = folderItem.querySelector('.folder-actions');
              if (folderActions && !folderActions.querySelector('.share-icon')) {
                console.log(`Adding share icon to folder: ${folderName}`);
                const shareIcon = document.createElement('i');
                shareIcon.className = 'fas fa-globe share-icon';
                shareIcon.title = 'This folder is shared';
                shareIcon.style.cssText = 'color: red !important; margin-right: 8px !important; display: inline-block !important; visibility: visible !important; opacity: 1 !important;';
                folderActions.insertBefore(shareIcon, folderActions.firstChild);
              }
            }
          })
          .catch(error => console.error(`Error checking share status for folder ${folderName}:`, error));
      });
      
      // Check files
      fileItems.forEach(fileItem => {
        const fileName = fileItem.getAttribute('data-file-name');
        const filePath = currentPath ? currentPath + '/' + fileName : fileName;
        
        fetch(`share_handler.php?action=check_share&file_path=${encodeURIComponent(filePath)}`)
          .then(response => response.json())
          .then(data => {
            if (data && data.success && data.is_shared) {
              const folderActions = fileItem.querySelector('.folder-actions');
              if (folderActions && !folderActions.querySelector('.share-icon')) {
                console.log(`Adding share icon to file: ${fileName}`);
                const shareIcon = document.createElement('i');
                shareIcon.className = 'fas fa-globe share-icon';
                shareIcon.title = 'This file is shared';
                shareIcon.style.cssText = 'color: red !important; margin-right: 8px !important; display: inline-block !important; visibility: visible !important; opacity: 1 !important;';
                folderActions.insertBefore(shareIcon, folderActions.firstChild);
              }
            }
          })
          .catch(error => console.error(`Error checking share status for file ${fileName}:`, error));
      });
    }
    
    // Run when the page is fully loaded
    window.onload = function() {
      console.log('Window fully loaded - checking share status');
      setTimeout(refreshShareIcons, 500);
      
      // Set up a periodic check to ensure icons are always visible
      setInterval(refreshShareIcons, 5000);
      
      // Also check when view is toggled
      document.getElementById('viewToggle')?.addEventListener('click', () => {
        console.log('View toggled - checking share status');
        setTimeout(refreshShareIcons, 300);
      });
    };
    
    // Also run when DOM is initially loaded
    document.addEventListener('DOMContentLoaded', function() {
      console.log('DOM Content Loaded - checking share status');
      setTimeout(refreshShareIcons, 300);
    });
</script>

<style>
@media (max-width: 768px) {
  #sidebarToggle {
    display: flex !important;
  }
}
</style>

<div class="mobile-nav">
  <div class="mobile-nav-content">
    <div class="mobile-nav-header">
      <div class="mobile-nav-title">Menu</div>
      <button class="mobile-nav-close" onclick="toggleMobileNav()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="mobile-nav-body">
      <!-- Mobile navigation content -->
    </div>
  </div>
</div>

</body>
</html>