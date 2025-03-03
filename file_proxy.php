<?php
// file_proxy.php - Securely serves shared files

// Start session
session_start();

// Include configuration
require_once 'config.php';

// Get share ID from URL
$shareId = $_GET['share_id'] ?? '';

if (empty($shareId)) {
    header('HTTP/1.1 400 Bad Request');
    die('Missing share ID');
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
    header('HTTP/1.1 404 Not Found');
    die('Share not found or has been removed');
}

// Check if share has expired
if ($share['expiry_time'] > 0 && $share['expiry_time'] < time()) {
    header('HTTP/1.1 410 Gone');
    die('This share link has expired');
}

// Check if password is required and verified
$passwordRequired = !empty($share['password']);
$passwordVerified = isset($_SESSION['verified_shares'][$shareId]) && $_SESSION['verified_shares'][$shareId] === true;

if ($passwordRequired && !$passwordVerified) {
    header('HTTP/1.1 403 Forbidden');
    die('Password verification required');
}

// Check if file exists
$filePath = $share['file_path'];
if (!file_exists($filePath)) {
    header('HTTP/1.1 404 Not Found');
    die('The shared file no longer exists');
}

// Get file information
$fileName = basename($filePath);
$fileSize = filesize($filePath);
$fileType = mime_content_type($filePath);

// Set appropriate headers
header('Content-Type: ' . $fileType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . $fileName . '"');
header('Cache-Control: public, max-age=86400');

// Output file
readfile($filePath);

// Close database connection
$db->close();
?> 