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

// Debug endpoint to check shares
if ((isset($_GET['debug_shares']) || isset($_GET['debug'])) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $shares = load_shares();
    $sessionShares = $_SESSION['file_shares'] ?? [];
    $inactiveShares = load_inactive_shares();
    
    $userShares = [];
    foreach ($shares as $key => $id) {
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            $userShares[$path] = [
                'id' => $id,
                'filename' => basename($path),
                'in_session' => isset($sessionShares[$key]),
                'status' => 'active'
            ];
        }
    }
    
    // Add inactive shares
    $userInactiveShares = [];
    foreach ($inactiveShares as $key => $id) {
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            $userInactiveShares[$path] = [
                'id' => $id,
                'filename' => basename($path),
                'status' => 'inactive'
            ];
        }
    }
    
    // Check for session shares not in persistent storage
    $sessionOnlyShares = [];
    foreach ($sessionShares as $key => $id) {
        if (strpos($key, $username . ':') === 0 && !isset($shares[$key])) {
            $path = substr($key, strlen($username) + 1);
            $sessionOnlyShares[$path] = [
                'id' => $id,
                'filename' => basename($path)
            ];
        }
    }
    
    send_json_response([
        'success' => true,
        'username' => $username,
        'total_active_shares' => count($shares),
        'total_inactive_shares' => count($inactiveShares),
        'active_shares' => $userShares,
        'inactive_shares' => $userInactiveShares,
        'session_only_shares' => $sessionOnlyShares,
        'shares_file' => $shares_file,
        'inactive_shares_file' => __DIR__ . '/inactive_shares.json',
        'shares_file_exists' => file_exists($shares_file),
        'inactive_shares_file_exists' => file_exists(__DIR__ . '/inactive_shares.json')
    ]);
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

// Add a new function to store inactive shares
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

// Save inactive shares
function save_inactive_shares($shares) {
    $inactive_shares_file = __DIR__ . '/inactive_shares.json';
    return file_put_contents($inactive_shares_file, json_encode($shares, JSON_PRETTY_PRINT));
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
    $fileName = basename($filePath);
    
    log_debug("Checking share for file: $filePath (filename: $fileName)");
    
    $shares = load_shares();
    $sessionShares = $_SESSION['file_shares'] ?? [];
    
    $isShared = false;
    $shareId = null;
    
    // FIRST PRIORITY: Check in persistent shares - exact match
    if (isset($shares[$fileKey])) {
        $isShared = true;
        $shareId = $shares[$fileKey];
        log_debug("Found share with exact key: $fileKey -> $shareId");
    }
    
    // SECOND PRIORITY: Check if the same filename is already shared by this user
    if (!$isShared) {
        log_debug("Checking if file with name '$fileName' is already shared by user '$username'");
        
        foreach ($shares as $key => $id) {
            // Only check shares belonging to this user
            if (strpos($key, $username . ':') === 0) {
                $path = substr($key, strlen($username) + 1);
                $existingFileName = basename($path);
                
                if ($existingFileName === $fileName) {
                    $isShared = true;
                    $shareId = $id;
                    log_debug("Found existing share for same filename: $existingFileName (ID: $shareId, Key: $key)");
                    
                    // Update with the current key format for consistency
                    $shares[$fileKey] = $shareId;
                    save_shares($shares);
                    
                    // Update session
                    if (!isset($_SESSION['file_shares'])) {
                        $_SESSION['file_shares'] = [];
                    }
                    $_SESSION['file_shares'][$fileKey] = $shareId;
                    break;
                }
            }
        }
    }
    
    // THIRD PRIORITY: Check alternate path formats
    if (!$isShared) {
        $normalizedFilePath = ltrim($filePath, '/');
        $alternateKeys = [
            $username . ':/' . $normalizedFilePath,
            $username . ':Home/' . $normalizedFilePath,
            $username . ':/Home/' . $normalizedFilePath,
            $username . ':' . 'Home/' . $normalizedFilePath
        ];
        
        log_debug("Checking alternate keys: " . json_encode($alternateKeys));
        
        foreach ($alternateKeys as $altKey) {
            if (isset($shares[$altKey])) {
                $isShared = true;
                $shareId = $shares[$altKey];
                log_debug("Found share with alternate key: $altKey -> $shareId");
                
                // Update with the current key format for consistency
                $shares[$fileKey] = $shareId;
                save_shares($shares);
                
                // Update session
                if (!isset($_SESSION['file_shares'])) {
                    $_SESSION['file_shares'] = [];
                }
                $_SESSION['file_shares'][$fileKey] = $shareId;
                break;
            }
        }
    }
    
    // FOURTH PRIORITY: Check in session shares
    if (!$isShared && isset($sessionShares[$fileKey])) {
        $isShared = true;
        $shareId = $sessionShares[$fileKey];
        log_debug("Found share in session: $fileKey -> $shareId");
        
        // Save to persistent shares for consistency
        $shares[$fileKey] = $shareId;
        save_shares($shares);
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
    $fileName = basename($filePath);
    
    log_debug("Creating share for file: $filePath (filename: $fileName)");
    
    // Load all shares
    $shares = load_shares();
    $inactiveShares = load_inactive_shares();
    log_debug("Loaded " . count($shares) . " active shares and " . count($inactiveShares) . " inactive shares");
    
    // HIGHEST PRIORITY: Check if this file was previously shared and is now inactive
    if (isset($inactiveShares[$fileKey])) {
        $shareId = $inactiveShares[$fileKey];
        log_debug("Found inactive share with ID: $shareId (exact match)");
        
        // Move from inactive to active shares
        $shares[$fileKey] = $shareId;
        unset($inactiveShares[$fileKey]);
        
        // Save both share lists
        save_shares($shares);
        save_inactive_shares($inactiveShares);
        
        // Update session
        if (!isset($_SESSION['file_shares'])) {
            $_SESSION['file_shares'] = [];
        }
        $_SESSION['file_shares'][$fileKey] = $shareId;
        
        send_json_response([
            'success' => true,
            'message' => 'File sharing re-enabled',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
        ]);
        return;
    }
    
    // Check if any file with the same name has an inactive share
    foreach ($inactiveShares as $key => $id) {
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            if (basename($path) === $fileName) {
                $shareId = $id;
                log_debug("Found inactive share for same filename: $key -> $shareId");
                
                // Move from inactive to active shares
                $shares[$fileKey] = $shareId;
                unset($inactiveShares[$key]);
                
                // Save both share lists
                save_shares($shares);
                save_inactive_shares($inactiveShares);
                
                // Update session
                if (!isset($_SESSION['file_shares'])) {
                    $_SESSION['file_shares'] = [];
                }
                $_SESSION['file_shares'][$fileKey] = $shareId;
                
                send_json_response([
                    'success' => true,
                    'message' => 'File sharing re-enabled',
                    'share_id' => $shareId,
                    'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
                ]);
                return;
            }
        }
    }
    
    // FIRST PRIORITY: Check if this exact file is already shared
    if (isset($shares[$fileKey])) {
        $shareId = $shares[$fileKey];
        log_debug("File already shared with ID: $shareId (exact match)");
        
        // Ensure it's in the session
        if (!isset($_SESSION['file_shares'])) {
            $_SESSION['file_shares'] = [];
        }
        $_SESSION['file_shares'][$fileKey] = $shareId;
        
        send_json_response([
            'success' => true,
            'message' => 'File is already shared',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
        ]);
        return;
    }
    
    // SECOND PRIORITY: Check if the same filename is already shared by this user
    log_debug("Checking if file with name '$fileName' is already shared by user '$username'");
    $existingShareId = null;
    
    foreach ($shares as $key => $id) {
        // Only check shares belonging to this user
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            $existingFileName = basename($path);
            
            if ($existingFileName === $fileName) {
                $existingShareId = $id;
                $existingKey = $key;
                log_debug("Found existing share for same filename: $existingFileName (ID: $existingShareId, Key: $existingKey)");
                break;
            }
        }
    }
    
    if ($existingShareId) {
        // Use the existing share ID for this file
        $shares[$fileKey] = $existingShareId;
        save_shares($shares);
        
        // Update session
        if (!isset($_SESSION['file_shares'])) {
            $_SESSION['file_shares'] = [];
        }
        $_SESSION['file_shares'][$fileKey] = $existingShareId;
        
        send_json_response([
            'success' => true,
            'message' => 'File is already shared',
            'share_id' => $existingShareId,
            'share_url' => get_base_url() . '/shared.php?id=' . urlencode($existingShareId)
        ]);
        return;
    }
    
    // THIRD PRIORITY: Check alternate path formats
    $normalizedFilePath = ltrim($filePath, '/');
    $alternateKeys = [
        $username . ':/' . $normalizedFilePath,
        $username . ':Home/' . $normalizedFilePath,
        $username . ':/Home/' . $normalizedFilePath,
        $username . ':' . 'Home/' . $normalizedFilePath
    ];
    
    log_debug("Checking alternate keys: " . json_encode($alternateKeys));
    
    foreach ($alternateKeys as $altKey) {
        if (isset($shares[$altKey])) {
            $shareId = $shares[$altKey];
            log_debug("File already shared with ID: $shareId (alternate path: $altKey)");
            
            // Update with the current key format for consistency
            $shares[$fileKey] = $shareId;
            save_shares($shares);
            
            // Update session
            if (!isset($_SESSION['file_shares'])) {
                $_SESSION['file_shares'] = [];
            }
            $_SESSION['file_shares'][$fileKey] = $shareId;
            
            send_json_response([
                'success' => true,
                'message' => 'File is already shared',
                'share_id' => $shareId,
                'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
            ]);
            return;
        }
    }
    
    // FOURTH PRIORITY: Check session shares
    $sessionShares = $_SESSION['file_shares'] ?? [];
    if (isset($sessionShares[$fileKey])) {
        $shareId = $sessionShares[$fileKey];
        log_debug("File already shared in session with ID: $shareId");
        
        // Save to persistent shares
        $shares[$fileKey] = $shareId;
        save_shares($shares);
        
        send_json_response([
            'success' => true,
            'message' => 'File is already shared',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared.php?id=' . urlencode($shareId)
        ]);
        return;
    }
    
    // If we get here, the file is not shared yet, so create a new share
    $shareId = bin2hex(random_bytes(16));
    log_debug("Creating new share with ID: $shareId for file: $filePath");
    
    // Save to persistent shares
    $shares[$fileKey] = $shareId;
    
    if (save_shares($shares)) {
        // Also save to session
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

// Delete a share (now just marks it as inactive)
function delete_share($filePath) {
    global $username;
    
    if (empty($filePath)) {
        send_json_response(['success' => false, 'error' => 'No file specified']);
    }
    
    $fileKey = $username . ':' . $filePath;
    $fileName = basename($filePath);
    
    log_debug("Disabling share for file: $filePath (key: $fileKey)");
    
    $shares = load_shares();
    $sessionShares = $_SESSION['file_shares'] ?? [];
    $inactiveShares = load_inactive_shares();
    
    $deleted = false;
    $shareId = null;
    
    // FIRST PRIORITY: Check if the file is shared with exact key
    if (isset($shares[$fileKey])) {
        $shareId = $shares[$fileKey];
        log_debug("Found share to disable: $fileKey -> $shareId");
        
        // Store in inactive shares before removing
        $inactiveShares[$fileKey] = $shareId;
        save_inactive_shares($inactiveShares);
        
        // Remove from active shares
        unset($shares[$fileKey]);
        save_shares($shares);
        $deleted = true;
    }
    
    // SECOND PRIORITY: Check if any file with the same name is shared
    if (!$deleted) {
        foreach ($shares as $key => $id) {
            if (strpos($key, $username . ':') === 0) {
                $path = substr($key, strlen($username) + 1);
                if (basename($path) === $fileName) {
                    $shareId = $id;
                    log_debug("Found share with same filename to disable: $key -> $shareId");
                    
                    // Store in inactive shares
                    $inactiveShares[$key] = $shareId;
                    save_inactive_shares($inactiveShares);
                    
                    // Remove from active shares
                    unset($shares[$key]);
                    save_shares($shares);
                    $deleted = true;
                    break;
                }
            }
        }
    }
    
    // THIRD PRIORITY: Check alternate path formats
    if (!$deleted) {
        $normalizedFilePath = ltrim($filePath, '/');
        $alternateKeys = [
            $username . ':/' . $normalizedFilePath,
            $username . ':Home/' . $normalizedFilePath,
            $username . ':/Home/' . $normalizedFilePath,
            $username . ':' . 'Home/' . $normalizedFilePath
        ];
        
        foreach ($alternateKeys as $altKey) {
            if (isset($shares[$altKey])) {
                $shareId = $shares[$altKey];
                log_debug("Found share with alternate key to disable: $altKey -> $shareId");
                
                // Store in inactive shares
                $inactiveShares[$altKey] = $shareId;
                save_inactive_shares($inactiveShares);
                
                // Remove from active shares
                unset($shares[$altKey]);
                save_shares($shares);
                $deleted = true;
                break;
            }
        }
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