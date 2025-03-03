<?php
// Start output buffering to ensure clean JSON responses
ob_start();

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

// Test endpoint
if (isset($_GET['test'])) {
    send_json_response(['success' => true, 'message' => 'Share handler is working']);
}

// Set proper content type for JSON responses
header('Content-Type: application/json');

// Debug logging
define('DEBUG', true);
$debug_log = __DIR__ . '/share_debug.log';
function log_debug($message) {
    global $debug_log;
    if (DEBUG) {
        @file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}

// Helper function to get the base URL with correct protocol
function get_base_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script_name = dirname($_SERVER['SCRIPT_NAME']);
    return $protocol . $host . $script_name;
}

// Log request details for debugging
log_debug("Request Method: " . $_SERVER['REQUEST_METHOD']);
log_debug("Request URI: " . $_SERVER['REQUEST_URI']);
log_debug("GET params: " . json_encode($_GET));
log_debug("POST params: " . json_encode($_POST));
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    log_debug("DELETE input: " . file_get_contents('php://input'));
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    send_json_response(['success' => false, 'error' => 'Not logged in']);
}

$username = $_SESSION['username'];
$shares_file = __DIR__ . '/shares.json';

// Load existing shares
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

// Save shares
function save_shares($shares) {
    global $shares_file;
    return file_put_contents($shares_file, json_encode($shares, JSON_PRETTY_PRINT));
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Handle different actions based on request method
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'check_share') {
        check_share();
    } else {
        send_json_response(['success' => false, 'error' => 'Invalid action']);
    }
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? 'create_share';
    if ($action === 'create_share') {
        create_share();
    } else {
        send_json_response(['success' => false, 'error' => 'Invalid action']);
    }
} elseif ($method === 'DELETE') {
    // For DELETE requests, parse the input
    parse_str(file_get_contents('php://input'), $_DELETE);
    
    // Also check query parameters for file_path
    $filePath = $_GET['file_path'] ?? ($_DELETE['file_path'] ?? '');
    
    if (!empty($filePath)) {
        delete_share($filePath);
    } else {
        send_json_response(['success' => false, 'error' => 'No file specified']);
    }
} else {
    send_json_response(['success' => false, 'error' => 'Invalid request method']);
}

// Helper function to send a JSON response
function send_json_response($data) {
    global $debug_log;
    // Log the response
    if (DEBUG) {
        log_debug("Response: " . json_encode($data));
    }
    
    // Clean any previous output
    ob_clean();
    
    // Set headers
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    
    // Send JSON response
    echo json_encode($data);
    exit;
}

// Check if a file is shared
function check_share() {
    global $username;
    
    if (!isset($_GET['file_path']) || empty($_GET['file_path'])) {
        send_json_response(['success' => false, 'error' => 'No file specified']);
    }
    
    $filePath = $_GET['file_path'];
    $fileKey = $username . ':' . $filePath;
    
    $shares = load_shares();
    $sessionShares = $_SESSION['file_shares'] ?? [];
    
    $isShared = false;
    $shareId = null;
    
    // Check in persistent shares
    if (isset($shares[$fileKey])) {
        $isShared = true;
        $shareId = $shares[$fileKey];
    }
    
    // Check in session shares
    if (!$isShared && isset($sessionShares[$fileKey])) {
        $isShared = true;
        $shareId = $sessionShares[$fileKey];
    }
    
    if ($isShared && $shareId) {
        send_json_response([
            'success' => true,
            'is_shared' => true,
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
        ]);
    } else {
        send_json_response([
            'success' => true,
            'is_shared' => false
        ]);
    }
}

// Create a share for a file
function create_share() {
    global $username;
    
    if (!isset($_POST['file_path']) || empty($_POST['file_path'])) {
        send_json_response(['success' => false, 'error' => 'No file specified']);
    }
    
    $filePath = $_POST['file_path'];
    $fileKey = $username . ':' . $filePath;
    
    // Generate a unique share ID
    $shareId = bin2hex(random_bytes(16));
    
    // Save to persistent shares
    $shares = load_shares();
    $shares[$fileKey] = $shareId;
    
    if (save_shares($shares)) {
        // Also save to session for redundancy
        if (!isset($_SESSION['file_shares'])) {
            $_SESSION['file_shares'] = [];
        }
        $_SESSION['file_shares'][$fileKey] = $shareId;
        
        send_json_response([
            'success' => true,
            'message' => 'File shared successfully',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
        ]);
    } else {
        send_json_response(['success' => false, 'error' => 'Failed to save share']);
    }
}

// Delete a share
function delete_share($filePath) {
    global $username;
    
    if (empty($filePath)) {
        send_json_response(['success' => false, 'error' => 'No file specified']);
    }
    
    $fileKey = $username . ':' . $filePath;
    
    $shares = load_shares();
    $sessionShares = $_SESSION['file_shares'] ?? [];
    
    $deleted = false;
    
    // Remove from persistent shares
    if (isset($shares[$fileKey])) {
        unset($shares[$fileKey]);
        save_shares($shares);
        $deleted = true;
    }
    
    // Remove from session shares
    if (isset($sessionShares[$fileKey])) {
        unset($_SESSION['file_shares'][$fileKey]);
        $deleted = true;
    }
    
    if ($deleted) {
        send_json_response([
            'success' => true,
            'message' => 'Share removed successfully'
        ]);
    } else {
        send_json_response([
            'success' => false,
            'error' => 'Share not found'
        ]);
    }
} 