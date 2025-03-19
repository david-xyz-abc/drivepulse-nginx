<?php
session_start();

// Security check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
    header("HTTP/1.1 403 Forbidden");
    exit;
}

// Function to generate video thumbnail
function generateVideoThumbnail($videoPath, $thumbnailPath) {
    // Create thumbnails directory if it doesn't exist
    $thumbnailDir = dirname($thumbnailPath);
    if (!file_exists($thumbnailDir)) {
        mkdir($thumbnailDir, 0777, true);
    }

    // Generate thumbnail using FFmpeg
    $command = "ffmpeg -i " . escapeshellarg($videoPath) . 
               " -ss 00:00:01 -vframes 1 " .
               escapeshellarg($thumbnailPath) . " 2>&1";
    
    exec($command, $output, $returnCode);
    return $returnCode === 0;
}

// Handle thumbnail request
if (isset($_GET['video'])) {
    $username = $_SESSION['username'];
    $baseDir = realpath("/var/www/html/webdav/users/$username/Home");
    
    if ($baseDir === false) {
        header("HTTP/1.1 500 Internal Server Error");
        exit;
    }

    $requestedVideo = urldecode($_GET['video']);
    if (strpos($requestedVideo, 'Home/') === 0) {
        $requestedVideo = substr($requestedVideo, 5);
    }
    
    $videoPath = realpath($baseDir . '/' . $requestedVideo);
    
    // Security check - ensure the video is within the user's directory
    if ($videoPath === false || strpos($videoPath, $baseDir) !== 0 || !file_exists($videoPath)) {
        header("HTTP/1.1 404 Not Found");
        exit;
    }

    // Create thumbnails directory in the user's home
    $thumbnailsDir = $baseDir . '/.thumbnails';
    if (!file_exists($thumbnailsDir)) {
        mkdir($thumbnailsDir, 0777, true);
    }

    // Generate unique thumbnail name based on video path and modification time
    $thumbnailName = md5($requestedVideo . filemtime($videoPath)) . '.jpg';
    $thumbnailPath = $thumbnailsDir . '/' . $thumbnailName;

    // Generate thumbnail if it doesn't exist or is outdated
    if (!file_exists($thumbnailPath)) {
        if (!generateVideoThumbnail($videoPath, $thumbnailPath)) {
            // If thumbnail generation fails, return a default video icon
            header("Location: /selfhostedgdrive/img/video-icon.png");
            exit;
        }
    }

    // Serve the thumbnail
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    readfile($thumbnailPath);
    exit;
} 