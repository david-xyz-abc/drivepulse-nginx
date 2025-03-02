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
                // Set additional headers for video streaming
                header("X-Content-Duration: $fileSize");
                header("Content-Duration: $fileSize");
                
                // Use a larger buffer for initial chunk if this is a starting segment
                if ($start < 1048576) { // 1MB
                    $bufferSize = 524288; // 512KB for initial segment
                }
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
                        'icon' => getIconClass($one)
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
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4', 'webm', 'ogg', 'mkv']);
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
  <title>Explorer with Previews</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
  
  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="drivepulse.svg">
  <!-- Apple Touch Icon -->
  <link rel="apple-touch-icon" href="drivepulse.svg">
  <!-- MS Tile Icon -->
  <meta name="msapplication-TileImage" content="drivepulse.svg">
  <meta name="msapplication-TileColor" content="#ff4444">
  <!-- Theme Color -->
  <meta name="theme-color" content="#ff4444">
  
  <style>
:root {
  --background: #121212;
  --text-color: #fff;
  --sidebar-bg: linear-gradient(135deg, #1e1e1e, #2a2a2a);
  --content-bg: #1e1e1e;
  --border-color: #333;
  --border-color-rgb: 51, 51, 51; /* Added RGB values for border color */
  --button-bg: linear-gradient(135deg, #555, #777);
  --button-hover: linear-gradient(135deg, #777, #555);
  --accent-red: #d32f2f;
  --dropzone-bg: rgba(211, 47, 47, 0.1);
  --dropzone-border: #d32f2f;
  --texture-color: rgba(255, 255, 255, 0.03);
  --red-glow: rgba(211, 47, 47, 0.05);
}

body.light-mode {
  --background: #f5f5f5;
  --text-color: #333;
  --sidebar-bg: linear-gradient(135deg, #e0e0e0, #fafafa);
  --content-bg: #fff;
  --border-color: #ccc;
  --border-color-rgb: 204, 204, 204; /* Added RGB values for border color in light mode */
  --button-bg: linear-gradient(135deg, #888, #aaa);
  --button-hover: linear-gradient(135deg, #aaa, #888);
  --accent-red: #f44336;
  --dropzone-bg: rgba(244, 67, 54, 0.1);
  --dropzone-border: #f44336;
  --texture-color: rgba(0, 0, 0, 0.03);
  --red-glow: rgba(244, 67, 54, 0.08);
}

html, body {
  margin: 0;
  padding: 0;
  width: 100%;
  height: 100%;
  background: var(--background);
  color: var(--text-color);
  font-family: 'Poppins', sans-serif;
  overflow: hidden;
  transition: background 0.3s, color 0.3s;
}

.app-container {
  display: flex;
  width: 100%;
  height: 100%;
  position: relative;
}

.sidebar {
  width: 270px;
  background: var(--sidebar-bg);
  border-right: 1px solid var(--border-color);
  display: flex;
  flex-direction: column;
  z-index: 9998;
  position: sticky;
  top: 0;
  height: 100vh;
  transform: translateX(-100%);
  transition: transform 0.3s ease;
}

@media (min-width: 1024px) {
  .sidebar { transform: none; }
}

.sidebar.open { transform: translateX(0); }

@media (max-width: 1023px) {
  .sidebar { position: fixed; top: 0; left: 0; height: 100%; }
}

.sidebar-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  z-index: 9997;
}

.sidebar-overlay.show { display: block; }

@media (min-width: 1024px) { .sidebar-overlay { display: none !important; } }

.folders-container {
  padding: 20px;
  display: flex;
  flex-direction: column;
  height: 100%;
  box-sizing: border-box;
}

.folder-list-container {
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  margin-bottom: 10px;
  /* Custom scrollbar styling */
  scrollbar-width: thin;
  scrollbar-color: var(--accent-red) var(--background);
}

/* For Webkit browsers (Chrome, Safari, etc.) */
.folder-list-container::-webkit-scrollbar {
  width: 8px;
}

.folder-list-container::-webkit-scrollbar-track {
  background: var(--background);
  border-radius: 4px;
}

.folder-list-container::-webkit-scrollbar-thumb {
  background: var(--accent-red);
  border-radius: 4px;
}

.folder-list-container::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--accent-red), #b71c1c);
}

.top-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top:2px;
  margin-bottom: 2px;
  justify-content: flex-start;
}

.top-row h2 {
  font-size: 18px;
  font-weight: 500;
  margin: 0;
  color: var(--text-color);
}

.storage-indicator {
  margin-top: 10px;
  padding: 10px;
  background: var(--content-bg);
  border: 1px solid var(--border-color);
  border-radius: 4px;
  font-size: 12px;
  color: var(--text-color);
  width: calc(100% - 22px);
  box-sizing: border-box;
}

.storage-indicator p {
  margin: 0 0 5px 0;
  text-align: center;
}

.storage-bar {
  width: 100%;
  height: 10px;
  background: var(--border-color);
  border-radius: 5px;
  overflow: hidden;
}

.storage-progress {
  height: 100%;
  background: var(--accent-red);
  border-radius: 5px;
  transition: width 0.3s ease;
}

.btn {
  background: var(--button-bg);
  color: var(--text-color);
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.3s, transform 0.2s;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  text-decoration: none;
}

.btn:hover {
  background: var(--button-hover);
  transform: scale(1.05);
}

.btn:active { transform: scale(0.95); }

.btn i { color: var(--text-color); margin: 0; }

.btn-back {
  background: var(--button-bg);
  color: var(--text-color);
  border: none;
  border-radius: 4px;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s, transform 0.2s;
  text-decoration: none;
}

.btn-back i { color: var(--text-color); margin: 0; }

.btn-back:hover {
  background: var(--button-hover);
  transform: scale(1.05);
}

.btn-back:active { transform: scale(0.95); }

.logout-btn {
  background: linear-gradient(135deg, var(--accent-red), #b71c1c) !important;
}

.logout-btn:hover {
  background: linear-gradient(135deg, #b71c1c, var(--accent-red)) !important;
}

.folder-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.folder-item {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  margin-bottom: 0;
  position: relative;
  background-color: transparent;
  border: none;
  border-bottom: 2px solid rgba(var(--border-color-rgb), 0.3);
  box-shadow: none;
  transition: all 0.2s ease;
}

.folder-item:hover {
  background-color: rgba(var(--hover-color-rgb), 0.1);
  transform: translateY(-1px);
  border-bottom-color: var(--accent-red);
  border-bottom-width: 2px;
}

.folder-item i { margin-right: 6px; }

.folder-item.selected {
  background: rgba(var(--border-color-rgb), 0.2); /* Changed to semi-transparent selection */
}

/* Add styles for folder actions */
.folder-actions {
  display: flex;
  margin-left: auto;
}

.folder-more-options-btn {
  background: none;
  border: none;
  color: var(--text-color);
  cursor: pointer;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background-color 0.2s;
}

.folder-more-options-btn:hover {
  background-color: var(--border-color);
}

.main-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}

.header-area {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px;
  border-bottom: 1px solid var(--border-color);
  background: var(--background);
  z-index: 10;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 10px;
}

.header-area h1 {
  font-size: 18px;
  font-weight: 500;
  margin: 0;
  color: var(--text-color);
}

.hamburger {
  background: none;
  border: none;
  color: var(--text-color);
  font-size: 24px;
  cursor: pointer;
}

@media (min-width: 1024px) { .hamburger { display: none; } }

.content-inner {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  position: relative;
  background-color: var(--content-bg);
  background-image: 
    linear-gradient(to bottom, var(--red-glow) 0%, transparent 70%),
    linear-gradient(var(--texture-color) 1px, transparent 1px),
    linear-gradient(90deg, var(--texture-color) 1px, transparent 1px);
  background-size: 100% 100%, 20px 20px, 20px 20px;
  background-position: center top;
}

.file-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.file-list.grid-view {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 15px;
}

.file-row {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  margin-bottom: 0;
  position: relative;
  background-color: transparent;
  border: none;
  border-bottom: 2px solid rgba(var(--border-color-rgb), 0.3);
  box-shadow: none;
  transition: all 0.2s ease;
}

.file-row:hover {
  background-color: rgba(var(--hover-color-rgb), 0.1);
  transform: translateY(-1px);
  border-bottom-color: var(--accent-red);
  border-bottom-width: 2px;
}

.file-list.grid-view .file-row {
  flex-direction: column;
  align-items: center;
  padding: 10px;
  height: 180px;
  text-align: center;
  overflow: hidden;
  position: relative;
  cursor: pointer;
  background: transparent;
  border: 2px solid var(--border-color);
  border-radius: 4px;
  margin: 0;
}

.file-icon {
  font-size: 20px;
  margin-right: 10px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
}

.file-list.grid-view .file-icon {
  font-size: 20px;
  position: absolute;
  top: 5px;
  right: 5px;
  margin: 0;
  background: rgba(0, 0, 0, 0.5);
  padding: 5px;
  border-radius: 4px;
  width: 24px;
  height: 24px;
}

.file-icon-large {
  font-size: 60px;
  margin-bottom: 10px;
  display: none;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 120px;
  color: var(--text-color);
}

.file-list.grid-view .file-icon-large {
  display: flex;
}

.file-preview {
  display: none;
}

.file-list.grid-view .file-preview {
  display: block;
  width: 100%;
  height: 120px;
  object-fit: cover;
  border-radius: 4px;
  margin-bottom: 10px;
}

.file-list.grid-view .file-icon:not(.no-preview) {
  display: none;
}

.file-name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-right: 20px;
  cursor: pointer;
}

.file-list.grid-view .file-name {
  margin: 0;
  font-size: 14px;
  white-space: normal;
  word-wrap: break-word;
  max-height: 40px;
  overflow: hidden;
}

.file-name:hover { border-bottom: 2px solid var(--accent-red); }

.file-list.grid-view .file-name:hover { border-bottom: none; }

.file-actions {
  display: flex;
  margin-left: auto;
}

.more-options-btn {
  background: none;
  border: none;
  color: var(--text-color);
  cursor: pointer;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background-color 0.2s;
}

.more-options-btn:hover {
  background-color: var(--border-color);
}

.file-list.grid-view .file-actions {
  position: absolute;
  top: 5px;
  left: 5px;
}

.file-list.grid-view .more-options-btn {
  width: 18px;
  height: 18px;
  font-size: 10px;
}

#fileInput { display: none; }

#uploadProgressContainer {
  display: none;
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 300px;
  background: var(--content-bg);
  border: 1px solid var(--border-color);
  padding: 10px;
  border-radius: 4px;
  z-index: 9999;
}

#uploadProgressBar {
  height: 20px;
  width: 0%;
  background: var(--accent-red);
  border-radius: 4px;
  transition: width 0.1s ease;
}

#uploadProgressPercent {
  text-align: center;
  margin-top: 5px;
  font-weight: 500;
}

.cancel-upload-btn {
  margin-top: 5px;
  padding: 6px 10px;
  background: linear-gradient(135deg, var(--accent-red), #b71c1c);
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.3s, transform 0.2s;
  color: var(--text-color);
}

.cancel-upload-btn:hover {
  background: linear-gradient(135deg, #b71c1c, var(--accent-red));
  transform: scale(1.05);
}

.cancel-upload-btn:active { transform: scale(0.95); }

#previewModal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background: rgba(0, 0, 0, 0.8);
  justify-content: center;
  align-items: center;
  z-index: 9998;
  overflow: hidden;
  box-sizing: border-box;
}

#previewContent {
  position: relative;
  width: auto;
  max-width: 90vw;
  height: auto;
  max-height: 90vh;
  background: var(--content-bg);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 20px;
  margin: 0 auto;
}

#previewContent.image-preview {
  background: none;
  border: none;
  padding: 0;
  max-width: 100vw;
  max-height: 100vh;
  margin: 0 auto;
}

#previewContent.pdf-preview {
  background: none;
  border: none;
  padding: 0;
  max-width: 100vw;
  max-height: 100vh;
  margin: 0 auto;
}

#previewNav {
  position: fixed;
  top: 50%;
  transform: translateY(-50%);
  width: 100vw;
  display: flex;
  justify-content: space-between;
  padding: 0 20px;
  box-sizing: border-box;
  z-index: 9999;
}

#previewNav button {
  background: rgba(0, 0, 0, 0.5);
  border: none;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  transition: background 0.3s;
}

#previewNav button:hover {
  background: rgba(0, 0, 0, 0.7);
}

#previewNav button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

#previewClose {
  position: fixed;
  top: 20px;
  right: 20px;
  cursor: pointer;
  font-size: 30px;
  color: #fff;
  z-index: 9999;
  background: rgba(0, 0, 0, 0.5);
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s;
}

#previewClose:hover {
  background: rgba(0, 0, 0, 0.8);
}

#iconPreviewContainer {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  max-width: 90vw;
  max-height: 90vh;
}

#iconPreviewContainer i {
  font-size: 100px;
  color: var(--text-color);
}

#imagePreviewContainer {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  max-width: 90vw;
  max-height: 90vh;
  overflow: hidden;
  margin: 0 auto;
}

#imagePreviewContainer img {
  max-width: 100%;
  max-height: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
  display: block;
  margin: 0 auto;
}

/* Add media query for desktop screens */
@media (min-width: 769px) {
  #previewModal {
    padding: 0; /* Remove padding from modal */
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  #previewContent.image-preview {
    max-height: none; /* Remove height restriction on desktop */
    height: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
  }
  
  #imagePreviewContainer {
    max-height: none; /* Remove height restriction on desktop */
    height: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
  }
  
  #imagePreviewContainer img {
    max-height: 90vh; /* Use viewport height instead of calc */
    object-fit: contain;
    width: auto;
    height: auto;
    margin: 0 auto;
  }
  
  #pdfPreviewContainer {
    max-height: none; /* Remove height restriction on desktop */
    height: 95vh;
    width: 95vw; /* Make it wider */
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    position: relative;
    z-index: 1001;
  }
  
  #pdfViewer {
    max-height: 95vh;
    max-width: 95vw; /* Make it wider */
    height: 100%;
    width: 100%;
    border: none; /* Remove border */
    box-shadow: 0 5px 25px rgba(0, 0, 0, 0.4); /* Add stronger shadow */
    border-radius: 4px; /* Add rounded corners */
    transform: scale(1); /* Ensure no scaling is applied */
  }
}

#dialogModal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.8);
  justify-content: center;
  align-items: center;
  z-index: 10000;
}

#dialogModal.show { display: flex; }

.dialog-content {
  background: var(--content-bg);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 20px;
  max-width: 90%;
  width: 400px;
  text-align: center;
}

.dialog-message {
  margin-bottom: 20px;
  font-size: 16px;
}

.dialog-buttons {
  display: flex;
  justify-content: center;
  gap: 10px;
}

.dialog-button {
  background: var(--button-bg);
  color: var(--text-color);
  border: none;
  border-radius: 4px;
  padding: 6px 10px;
  cursor: pointer;
  transition: background 0.3s, transform 0.2s;
}

.dialog-button:hover {
  background: var(--button-hover);
  transform: scale(1.05);
}

.dialog-button:active { transform: scale(0.95); }

.theme-toggle-btn i { color: var(--text-color); }

#dropZone {
  display: none;
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--dropzone-bg);
  border: 3px dashed var(--dropzone-border);
  z-index: 5;
  justify-content: center;
  align-items: center;
  font-size: 18px;
  font-weight: 500;
  color: var(--accent-red);
  text-align: center;
  padding: 20px;
  box-sizing: border-box;
}

#dropZone.active { display: flex; }

@media (max-width: 768px) {
  .file-list.grid-view { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
  .file-list.grid-view .file-row { height: 150px; }
  .file-list.grid-view .file-preview { height: 100px; }
  .file-list.grid-view .file-icon { font-size: 16px; }
  .file-list.grid-view .file-icon-large { font-size: 50px; height: 100px; }
  #iconPreviewContainer i { font-size: 80px; }
  #previewNav button { width: 30px; height: 30px; font-size: 16px; }
  #previewClose { top: 10px; right: 10px; font-size: 25px; }
}
#videoPreviewContainer {
  display: none;
  width: 100%;
  height: 100%;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #000;
}

#videoPlayer {
  width: 100%;
  height: 100%;
  max-width: 100vw;
  max-height: 100vh;
  object-fit: contain;
}

#pdfPreviewContainer {
  display: none;
  width: 100%;
  height: 100%;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--content-bg);
  z-index: 1001;
}

#pdfViewer {
  width: 100%;
  height: 100%;
  max-width: 100vw;
  max-height: 100vh;
  background: white;
  box-shadow: 0 5px 25px rgba(0, 0, 0, 0.4); /* Add stronger shadow */
  transform: scale(1); /* Ensure no scaling is applied */
  border-radius: 4px; /* Add rounded corners */
  /* Add custom scrollbar styling for the iframe */
  scrollbar-width: thin;
  scrollbar-color: var(--accent-red) rgba(0, 0, 0, 0.1);
}

/* Style the iframe scrollbars for webkit browsers */
#pdfViewer::-webkit-scrollbar {
  width: 10px;
  height: 10px;
}

#pdfViewer::-webkit-scrollbar-track {
  background: rgba(0, 0, 0, 0.1);
  border-radius: 4px;
}

#pdfViewer::-webkit-scrollbar-thumb {
  background: var(--accent-red);
  border-radius: 4px;
}

#pdfViewer::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--accent-red), #b71c1c);
}

#pdfLoadingIndicator {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.5);
  z-index: 2;
}

#pdfLoadingIndicator .loading-text {
  color: white;
  margin-top: 10px;
  font-size: 16px;
}

.video-controls {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 20px;
  background: linear-gradient(transparent, rgba(0,0,0,0.7));
  opacity: 0;
  transition: opacity 0.3s ease;
}

#videoPreviewContainer:hover .video-controls {
  opacity: 1;
}

.video-controls-inner {
  display: flex;
  align-items: center;
  gap: 15px;
  max-width: 1200px;
  margin: 0 auto;
  padding: 10px;
}

.video-controls button {
  background: none;
  border: none;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.2s ease;
}

.video-controls button:hover {
  transform: scale(1.1);
}

#videoProgress {
  flex: 1;
  height: 5px;
  background: rgba(255,255,255,0.3);
  border-radius: 2.5px;
  cursor: pointer;
  position: relative;
}

#videoProgressBar {
  height: 100%;
  background: var(--accent-red);
  border-radius: 2.5px;
  width: 0%;
  transition: width 0.1s linear, transform 0.3s ease;
  position: relative;
}

#videoProgress:hover #videoProgressBar {
  transform: scaleY(1.5);
}

#previewContent.video-preview {
  background: none;
  border: none;
  padding: 0;
  max-width: 100vw;
  max-height: 100vh;
}

/* General transitions for all interactive elements */
button, .btn, .file-row, .folder-item, img, i {
    transition: all 0.3s ease;
}

/* Smooth preview transitions */
#previewContent {
    transition: opacity 0.3s ease;
}

#previewContent.fade-out {
    opacity: 0;
}

#previewContent.fade-in {
    opacity: 1;
}

/* Image preview animations */
#imagePreviewContainer img {
    opacity: 0;
    transform: scale(0.95);
    transition: opacity 0.3s ease, transform 0.3s ease;
}

#imagePreviewContainer img.loaded {
    opacity: 1;
    transform: scale(1);
}

/* Video preview animations */
#videoPreviewContainer {
    opacity: 0;
    transform: scale(0.95);
    transition: opacity 0.3s ease, transform 0.3s ease;
}

#videoPreviewContainer.loaded {
    opacity: 1;
    transform: scale(1);
}

/* Navigation button animations */
#previewNav button {
    transform: translateX(0);
    transition: transform 0.3s ease, background 0.3s ease;
}

#previewNav button:hover {
    transform: scale(1.1);
}

#prevBtn.slide-out {
    transform: translateX(-100px);
}

#nextBtn.slide-out {
    transform: translateX(100px);
}

/* File list animations */
.file-row {
    animation: fadeIn 0.3s ease;
}

.file-row:hover {
    transform: translateY(-2px);
    border-bottom-color: var(--accent-red);
}

.file-list.grid-view .file-row:hover {
    transform: translateY(-2px);
    border-color: var(--accent-red);
    border-width: 2px;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Modal animations */
#previewModal {
    transition: background-color 0.3s ease;
}

#previewClose {
    transition: transform 0.3s ease, background 0.3s ease;
}

#previewClose:hover {
    transform: rotate(90deg);
}

/* Progress bar animation */
#videoProgressBar {
    transition: width 0.1s linear, transform 0.3s ease;
}

/* Dialog modal animations */
#dialogModal .dialog-content {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#bufferingIndicator {
  display: none;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 80px;
  height: 80px;
  z-index: 100;
  justify-content: center;
  align-items: center;
}

.spinner {
  width: 60px;
  height: 60px;
  border: 4px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: var(--accent-red);
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

#videoBufferBar {
  position: absolute;
  height: 100%;
  background: rgba(255, 255, 255, 0.3);
  border-radius: 2.5px;
  width: 0%;
  pointer-events: none;
}

#videoProgress {
  flex: 1;
  height: 5px;
  background: rgba(255,255,255,0.3);
  border-radius: 2.5px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
}

#videoProgressBar {
  height: 100%;
  background: var(--accent-red);
  border-radius: 2.5px;
  width: 0%;
  transition: width 0.1s linear, transform 0.3s ease;
  position: relative;
  z-index: 2;
}

#videoProgress:hover #videoProgressBar {
  transform: scaleY(1.5);
}

/* Improve video controls for mobile devices */
@media (max-width: 768px) {
  .video-controls-inner {
    padding: 15px 5px;
  }
  
  .video-controls button {
    width: 32px;
    height: 32px;
    font-size: 16px;
  }
  
  #videoProgress {
    height: 8px;
  }
}

/* Context Menu Styles */
.context-menu {
    position: absolute;
    background-color: var(--content-bg);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    z-index: 10000;
    min-width: 150px;
    display: none;
}

.context-menu-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 2px solid rgba(var(--border-color-rgb), 0.3);
    background-color: transparent;
}

.context-menu-item:last-child {
    border-bottom: none;
}

.context-menu-item:hover {
    background-color: rgba(var(--hover-color-rgb), 0.1);
    border-bottom-color: var(--accent-red);
    border-bottom-width: 2px;
}

.context-menu-item i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
    color: var(--text-color);
}

.context-menu-divider {
    height: 1px;
    background-color: var(--border-color);
    margin: 0; /* Changed from 5px 0 to 0 */
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Make context menu items more touch-friendly on mobile */
@media (max-width: 768px) {
  .context-menu-item {
    padding: 15px;
    font-size: 16px;
  }
  
  .context-menu-item i {
    font-size: 18px;
    margin-right: 15px;
  }
}

/* Particles styles for sidebar */
#sidebar-particles-js {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 0;
  opacity: 0.9;
  pointer-events: none;
}

.folders-container {
  position: relative;
  z-index: 1;
  padding: 20px;
  display: flex;
  flex-direction: column;
  height: 100%;
}

.folder-list-container {
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  margin-bottom: 10px;
  /* Custom scrollbar styling */
  scrollbar-width: thin;
  scrollbar-color: var(--accent-red) var(--background);
}

/* For Webkit browsers (Chrome, Safari, etc.) */
.folder-list-container::-webkit-scrollbar {
  width: 8px;
}

.folder-list-container::-webkit-scrollbar-track {
  background: var(--background);
  border-radius: 4px;
}

.folder-list-container::-webkit-scrollbar-thumb {
  background: var(--accent-red);
  border-radius: 4px;
}

.folder-list-container::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--accent-red), #b71c1c);
}

.top-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 2px;
  justify-content: flex-start;
}

.drivepulse-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
  margin-top: 0px; /* Changed from 10px to 0px to move the logo and text upward */
  justify-content: center;
}

.drivepulse-logo {
  font-size: 24px;
  color: var(--accent-red);
}

.drivepulse-title {
  font-size: 20px;
  font-weight: 500;
  color: var(--text-color);
}

.separator-line {
  height: 1px;
  background-color: var(--border-color);
  margin: 10px 0;
}

.content-inner .folder-list .folder-item {
  border-bottom: 2px solid var(--border-color);
  padding: 12px 0;
  margin: 0 10px;
  font-size: 16px;
  background: transparent;
}

.content-inner .folder-list .folder-item i {
  font-size: 24px;
  margin-right: 12px;
  width: 30px;
  text-align: center;
  vertical-align: middle;
}

.content-inner .folder-list .folder-item:last-child {
  border-bottom: none;
}

/* Add styles for grid view of folder-list */
.folder-list.grid-view {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 15px;
  padding: 0;
  margin: 0;
}

.folder-list.grid-view .folder-item {
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 15px 10px;
  height: auto;
  background: transparent;
  border: 2px solid var(--border-color);
  border-radius: 4px;
  margin: 0;
  position: relative;
  cursor: pointer;
  display: flex;
  overflow: hidden;
}

.folder-list.grid-view .folder-item i {
  font-size: 40px;
  margin: 0 0 10px 0;
  width: auto;
  height: auto;
}

.folder-list.grid-view .thumbnail-container {
  width: 100%;
  height: 100px;
  margin-bottom: 10px;
  border-radius: 4px;
  overflow: hidden;
  background-color: rgba(var(--border-color-rgb), 0.1);
}

.folder-list.grid-view .thumbnail {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
}

.folder-list.grid-view .folder-item:hover .thumbnail {
  transform: scale(1.05);
}

.folder-list.grid-view .folder-actions {
  position: absolute;
  top: 5px;
  right: 5px;
  margin: 0;
}

.folder-list.grid-view .folder-more-options-btn {
  width: 12px;
  height: 12px;
  font-size: 6px;
}

.content-inner .folder-list .folder-item {
  border-bottom: 2px solid var(--border-color);
  padding: 12px 0;
  margin: 0 10px;
  font-size: 16px;
  background: transparent;
}

.content-inner .folder-list .folder-item:hover {
  border-bottom-color: var(--accent-red);
  border-bottom-width: 2px;
}

.content-inner .folder-list.grid-view .folder-item {
  border: 2px solid var(--border-color);
  border-radius: 4px;
  margin: 0;
  padding: 15px 10px;
}

.content-inner .folder-list.grid-view .folder-item:hover {
  border-color: var(--accent-red);
  border-width: 2px;
}

/* Custom class for smaller ellipsis dots */
.small-dots {
  font-size: 1.2em !important;
  transform: scale(1.2);
  display: inline-block;
}

.thumbnail-container {
  width: 100%;
  height: 100%;
  overflow: hidden;
  border-radius: 4px;
  margin-bottom: 10px;
  display: none; /* Hide by default in stacked view */
}

.thumbnail {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* Show thumbnails only in grid view */
.folder-list.grid-view .thumbnail-container {
  display: block;
}

/* Make sure icons are visible in stacked view */
.folder-item i {
  margin-right: 6px;
  display: inline-block;
}

/* Hide icons in grid view when there's a thumbnail */
.folder-list.grid-view .folder-item i:not(.small-dots):not(.fa-ellipsis-v) {
  display: none;
}

/* We'll handle the specific case in JavaScript instead of using :has() */
// ... existing code ...

/* Hide image previews in stacked view */
.image-preview-container {
  display: none;
}

/* Show image previews only in grid view */
.folder-list.grid-view .image-preview-container {
  display: block;
}

/* Make sure icons are visible for images in stacked view */
.folder-item:has(.image-preview-container) i {
  display: inline-block !important;
}

/* In grid view, hide icons for items with image previews */
.folder-list.grid-view .folder-item:has(.image-preview-container) i:not(.small-dots):not(.fa-ellipsis-v) {
  display: none !important;
}
// ... existing code ...

/* Hide image previews in stacked view */
.image-preview-container {
  display: none;
}

/* Show image previews only in grid view */
.folder-list.grid-view .image-preview-container {
  display: block;
}

/* Ensure icons are properly displayed in stacked view */
.folder-item i {
  display: inline-block;
  margin-right: 10px;
  vertical-align: middle;
}

/* Remove any conflicting rules */
.folder-list.grid-view .folder-item i:not(.fa-ellipsis-v) {
  display: none;
}

/* Ensure the ellipsis icon in the more options button is always visible */
.folder-more-options-btn i {
  display: inline-block !important;
}

/* Ensure icons are properly displayed in stacked view */
.folder-item i:not(.fa-ellipsis-v) {
  display: inline-block;
  margin-right: 10px;
  vertical-align: middle;
  font-size: 20px;
}

/* In grid view, hide file type icons for items with thumbnails */
.folder-list.grid-view .folder-item .image-preview-container + i {
  display: none;
}

/* Ensure the ellipsis icon in the more options button is always visible */
.folder-more-options-btn i {
  display: inline-block !important;
  margin-right: 0;
}

/* PDF preview animations */
#pdfPreviewContainer {
    opacity: 0;
    transform: scale(0.95);
    transition: opacity 0.3s ease, transform 0.3s ease;
}

#pdfPreviewContainer.loaded {
    opacity: 1;
    transform: scale(1);
}

/* Navigation button animations */
#previewNav button {
    transform: translateX(0);
    transition: transform 0.3s ease, background 0.3s ease;
}

#previewNav button:hover {
    transform: scale(1.1);
}

#prevBtn.slide-out {
    transform: translateX(-100px);
}

#nextBtn.slide-out {
    transform: translateX(100px);
}

#previewContent.pdf-preview {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    max-width: 95vw;
    max-height: 95vh;
    overflow: hidden;
  }
  </style>
</head>
<body>
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
          <?php if ($parentLink): ?>
            <a class="btn-back" href="<?php echo htmlspecialchars($parentLink); ?>" title="Back">
              <i class="fas fa-arrow-left"></i>
            </a>
          <?php endif; ?>
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
          <p><?php echo "$usedStorageGB GB used of $totalStorageGB GB"; ?></p>
          <div class="storage-bar">
            <div class="storage-progress" style="width: <?php echo $storagePercentage; ?>%;"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
      <div class="header-area">
        <div class="header-title">
          <button class="hamburger" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
          </button>
          <h1><?php echo ($currentRel === 'Home') ? 'Home' : htmlspecialchars($currentRel); ?></h1>
        </div>
        <div style="display: flex; gap: 10px;">
          <form id="uploadForm" method="POST" enctype="multipart/form-data" action="/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>">
            <input type="file" name="upload_files[]" multiple id="fileInput" style="display:none;" />
            <button type="button" class="btn" id="uploadBtn" title="Upload" style="width:36px; height:36px;">
              <i class="fas fa-cloud-upload-alt"></i>
            </button>
          </form>
          <button type="button" class="btn" id="gridToggleBtn" title="Toggle Grid View" style="width:36px; height:36px;">
            <i class="fas fa-th"></i>
          </button>
          <button type="button" class="btn theme-toggle-btn" id="themeToggleBtn" title="Toggle Theme" style="width:36px; height:36px;">
            <i class="fas fa-moon"></i>
          </button>
          <a href="/selfhostedgdrive/logout.php" class="btn logout-btn" title="Logout">
            <i class="fa fa-sign-out" aria-hidden="true"></i>
          </a>
          <div id="uploadProgressContainer">
            <div style="background:var(--border-color); width:100%; height:20px; border-radius:4px; overflow:hidden;">
              <div id="uploadProgressBar"></div>
            </div>
            <div id="uploadProgressPercent">0.0%</div>
            <button class="cancel-upload-btn" id="cancelUploadBtn">Cancel</button>
          </div>
        </div>
      </div>
      <div class="content-inner" style="backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding-top: 20px; padding-bottom: 50px; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed multi-select controls container -->
        <div class="multi-select-controls-container" style="background: var(--sidebar-bg); opacity: 0.8; backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); flex-shrink: 0;">
          <div class="multi-select-controls" style="display: flex; align-items: center; justify-content: space-between;">
            <!-- Left side - Select All button -->
            <button type="button" class="multi-select-btn" id="selectAllBtn" title="Select All Files" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: transparent; color: var(--text-color); border: 2px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
              <i class="fas fa-check-square" style="margin-right: 8px;"></i>Select All
            </button>
            
            <!-- Right side - Delete and Download buttons -->
            <div style="display: flex; gap: 20px;">
              <button type="button" class="multi-select-btn" id="deleteSelectedBtn" title="Delete Selected Files" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: rgba(211, 47, 47, 0.2); color: var(--accent-red); border: 1px solid var(--accent-red); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">
                <i class="fas fa-trash-alt" style="margin-right: 8px;"></i>Delete
              </button>
              <button type="button" class="multi-select-btn" id="downloadSelectedBtn" title="Download Selected Files" style="font-size: 13px; padding: 10px 24px; min-width: 120px; background: rgba(0, 0, 0, 0.1); color: var(--text-color); border: 1px solid var(--border-color); border-radius: 0; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; font-weight: 500;">
                <i class="fas fa-download" style="margin-right: 8px;"></i>Download
              </button>
            </div>
          </div>
          
          <style>
            /* Hover and active states for multi-select buttons */
            #selectAllBtn:hover {
              border-color: var(--accent-red);
              background: rgba(var(--border-color-rgb), 0.3);
            }
            
            #deleteSelectedBtn:hover {
              background: var(--accent-red);
              color: white !important;
            }
            
            #downloadSelectedBtn:hover {
              background: var(--border-color);
              color: var(--text-color);
            }
            
            .multi-select-btn:active {
              transform: scale(0.98);
            }
            
            /* Ensure text is visible in both modes */
            body.light-mode .multi-select-btn {
              text-shadow: none;
            }
            
            body.light-mode #deleteSelectedBtn {
              color: var(--accent-red);
            }
          </style>
        </div>
        
        <hr style="border: 0; height: 2px; background: var(--accent-red); margin: 0 0 20px 0; opacity: 0.8; width: 100%; flex-shrink: 0;">
        
        <!-- Scrollable container for files -->
        <div class="files-container" style="flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: var(--accent-red) var(--background);">
          <style>
            /* Custom scrollbar styling for files container */
            .files-container::-webkit-scrollbar {
              width: 8px;
            }
            
            .files-container::-webkit-scrollbar-track {
              background: var(--background);
              border-radius: 4px;
            }
            
            .files-container::-webkit-scrollbar-thumb {
              background: var(--accent-red);
              border-radius: 4px;
            }
            
            .files-container::-webkit-scrollbar-thumb:hover {
              background: linear-gradient(135deg, var(--accent-red), #b71c1c);
            }
          </style>
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
                  <?php else: ?>
                  <i class="<?php echo $iconClass; ?>"></i>
                  <?php endif; ?>
                  <?php echo htmlspecialchars($fileName); ?>
                  <div class="folder-actions">
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
            <video id="videoPlayer" preload="auto" onclick="togglePlay(event)"></video>
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
                    <button id="fullscreenBtn" onclick="toggleFullscreen(event)"><i class="fas fa-expand"></i></button>
                </div>
            </div>
        </div>
        <div id="pdfPreviewContainer" style="display: none;">
            <iframe id="pdfViewer" width="100%" height="100%" frameborder="0"></iframe>
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

  <!-- Mobile menu overlay -->
  <div id="mobileMenuOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;"></div>

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
      <div class="context-menu-divider"></div>
      <div class="context-menu-item" id="contextMenuRenameFolder">
          <i class="fas fa-edit"></i> Rename
      </div>
      <div class="context-menu-item" id="contextMenuDeleteFolder">
          <i class="fas fa-trash"></i> Delete
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
<script>
let selectedFolder = null;
let currentXhr = null;
let previewFiles = <?php echo json_encode($previewableFiles); ?>;
let currentPreviewIndex = -1;
let isLoadingImage = false;

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
            
            // Set optimal video attributes for performance
            videoPlayer.preload = "auto";
            
            // Add adaptive playback attributes
            videoPlayer.setAttribute('playsinline', '');
            videoPlayer.setAttribute('crossorigin', 'anonymous');
            videoPlayer.setAttribute('controlsList', 'nodownload');
            
            // Handle source differently for better performance
            if (videoPlayer.src !== file.url) {
                videoPlayer.src = file.url;
                videoPlayer.load();
            }
            
            videoContainer.style.display = 'block';
            previewContent.classList.add('video-preview');
            setupVideoControls(videoPlayer);
            
            // Add buffering indicator
            const bufferingIndicator = document.getElementById('bufferingIndicator');
            bufferingIndicator.style.display = 'flex';
            
            videoPlayer.oncanplay = () => {
                videoContainer.classList.add('loaded');
                bufferingIndicator.style.display = 'none';
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
    const progressBar = document.getElementById('videoProgressBar');
    const playPauseBtn = document.getElementById('playPauseBtn');
    const bufferingIndicator = document.getElementById('bufferingIndicator');
    
    // Update progress bar
    video.ontimeupdate = () => {
        if (video.duration) {
            const percent = (video.currentTime / video.duration) * 100;
            progressBar.style.width = percent + '%';
            
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
}
updateGridView();
gridToggleBtn.addEventListener('click', () => {
  isGridView = !isGridView;
  localStorage.setItem('gridView', isGridView);
  updateGridView();
});

function closePreviewModal() {
    const previewModal = document.getElementById('previewModal');
    const videoPlayer = document.getElementById('videoPlayer');
    const imageContainer = document.getElementById('imagePreviewContainer');
    const iconContainer = document.getElementById('iconPreviewContainer');
    const videoContainer = document.getElementById('videoPreviewContainer');
    const pdfContainer = document.getElementById('pdfPreviewContainer');
    const pdfViewer = document.getElementById('pdfViewer');
    const pdfControls = document.getElementById('pdfControls');
    const bufferingIndicator = document.getElementById('bufferingIndicator');
    const pdfLoadingIndicator = document.getElementById('pdfLoadingIndicator');
    const previewContent = document.getElementById('previewContent');
    
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
  // Check if we're on mobile
  const isMobile = window.innerWidth <= 768;
  const overlay = document.getElementById('mobileMenuOverlay');
  
  if (isMobile) {
    // On mobile, position at the bottom of the screen
    contextMenu.style.left = '0';
    contextMenu.style.top = 'auto';
    contextMenu.style.bottom = '0';
    contextMenu.style.width = '100%';
    contextMenu.style.borderRadius = '10px 10px 0 0';
    contextMenu.style.boxShadow = '0 -2px 10px rgba(0, 0, 0, 0.2)';
    
    // Show overlay
    overlay.style.display = 'block';
  } else {
    // On desktop, position near the cursor
    const menuWidth = contextMenu.offsetWidth;
    const menuHeight = contextMenu.offsetHeight;
    const windowWidth = window.innerWidth;
    const windowHeight = window.innerHeight;
    
    // Check if menu goes beyond right edge
    if (x + menuWidth > windowWidth) {
      x = windowWidth - menuWidth - 5;
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
    
    // Hide overlay
    overlay.style.display = 'none';
  }
  
  contextMenu.style.display = 'block';
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
  document.getElementById('mobileMenuOverlay').style.display = 'none';
});

// Add click handler for the overlay
document.getElementById('mobileMenuOverlay').addEventListener('click', function() {
  contextMenu.style.display = 'none';
  this.style.display = 'none';
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

// Folder context menu actions
document.getElementById('contextMenuOpenFolder').addEventListener('click', function() {
  if (selectedFolder) {
    const folderPath = document.querySelector('.folder-item.selected').getAttribute('data-folder-path');
    openFolder(folderPath);
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
    selectAllBtn.innerHTML = '<i class="fas fa-times" style="margin-right: 5px;"></i>Cancel';
  } else {
    deleteSelectedBtn.style.display = 'none';
    downloadSelectedBtn.style.display = 'none';
    selectAllBtn.innerHTML = '<i class="fas fa-check-square" style="margin-right: 5px;"></i>Select All';
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
</script>

</body>
</html>