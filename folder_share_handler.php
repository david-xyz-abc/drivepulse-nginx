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
    send_json_response(['success' => true, 'message' => 'Folder share handler is working']);
}

// Debug endpoint to check folder shares
if ((isset($_GET['debug_shares']) || isset($_GET['debug'])) && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $folderShares = load_folder_shares();
    $sessionFolderShares = $_SESSION['folder_shares'] ?? [];
    $inactiveFolderShares = load_inactive_folder_shares();
    
    $userFolderShares = [];
    foreach ($folderShares as $key => $id) {
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            $userFolderShares[$path] = [
                'id' => $id,
                'foldername' => basename($path),
                'in_session' => isset($sessionFolderShares[$key]),
                'status' => 'active'
            ];
        }
    }
    
    // Add inactive shares
    $userInactiveFolderShares = [];
    foreach ($inactiveFolderShares as $key => $id) {
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            $userInactiveFolderShares[$path] = [
                'id' => $id,
                'foldername' => basename($path),
                'status' => 'inactive'
            ];
        }
    }
    
    // Check for session shares not in persistent storage
    $sessionOnlyFolderShares = [];
    foreach ($sessionFolderShares as $key => $id) {
        if (strpos($key, $username . ':') === 0 && !isset($folderShares[$key])) {
            $path = substr($key, strlen($username) + 1);
            $sessionOnlyFolderShares[$path] = [
                'id' => $id,
                'foldername' => basename($path)
            ];
        }
    }
    
    send_json_response([
        'success' => true,
        'username' => $username,
        'total_active_folder_shares' => count($folderShares),
        'total_inactive_folder_shares' => count($inactiveFolderShares),
        'active_folder_shares' => $userFolderShares,
        'inactive_folder_shares' => $userInactiveFolderShares,
        'session_only_folder_shares' => $sessionOnlyFolderShares,
        'folder_shares_file' => $folder_shares_file,
        'inactive_folder_shares_file' => __DIR__ . '/inactive_folder_shares.json',
        'folder_shares_file_exists' => file_exists($folder_shares_file),
        'inactive_folder_shares_file_exists' => file_exists(__DIR__ . '/inactive_folder_shares.json')
    ]);
}

// Set proper content type for JSON responses
header('Content-Type: application/json');

// Debug logging
define('DEBUG', true);
$debug_log = __DIR__ . '/folder_share_debug.log';
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
$folder_shares_file = __DIR__ . '/folder_shares.json';

// Load existing folder shares
function load_folder_shares() {
    global $folder_shares_file;
    if (file_exists($folder_shares_file)) {
        $content = file_get_contents($folder_shares_file);
        if (!empty($content)) {
            return json_decode($content, true) ?: [];
        }
    }
    return [];
}

// Save folder shares
function save_folder_shares($shares) {
    global $folder_shares_file;
    return file_put_contents($folder_shares_file, json_encode($shares, JSON_PRETTY_PRINT));
}

// Add a new function to store inactive folder shares
function load_inactive_folder_shares() {
    $inactive_folder_shares_file = __DIR__ . '/inactive_folder_shares.json';
    if (file_exists($inactive_folder_shares_file)) {
        $content = file_get_contents($inactive_folder_shares_file);
        if (!empty($content)) {
            return json_decode($content, true) ?: [];
        }
    }
    return [];
}

// Save inactive folder shares
function save_inactive_folder_shares($shares) {
    $inactive_folder_shares_file = __DIR__ . '/inactive_folder_shares.json';
    return file_put_contents($inactive_folder_shares_file, json_encode($shares, JSON_PRETTY_PRINT));
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Handle different actions based on request method
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'check_folder_share') {
        check_folder_share();
    } else {
        send_json_response(['success' => false, 'error' => 'Invalid action']);
    }
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? 'create_folder_share';
    if ($action === 'create_folder_share') {
        create_folder_share();
    } else {
        send_json_response(['success' => false, 'error' => 'Invalid action']);
    }
} elseif ($method === 'DELETE') {
    // For DELETE requests, parse the input
    parse_str(file_get_contents('php://input'), $_DELETE);
    
    // Also check query parameters for folder_path
    $folderPath = $_GET['folder_path'] ?? ($_DELETE['folder_path'] ?? '');
    
    if (!empty($folderPath)) {
        delete_folder_share($folderPath);
    } else {
        send_json_response(['success' => false, 'error' => 'No folder specified']);
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

// Check if a folder is shared
function check_folder_share() {
    global $username;
    
    if (!isset($_GET['folder_path']) || empty($_GET['folder_path'])) {
        send_json_response(['success' => false, 'error' => 'No folder specified']);
    }
    
    $folderPath = $_GET['folder_path'];
    $folderKey = $username . ':' . $folderPath;
    $folderName = basename($folderPath);
    
    log_debug("Checking share for folder: $folderPath (foldername: $folderName)");
    
    $folderShares = load_folder_shares();
    $sessionFolderShares = $_SESSION['folder_shares'] ?? [];
    
    $isShared = false;
    $shareId = null;
    
    // FIRST PRIORITY: Check in persistent shares - exact match
    if (isset($folderShares[$folderKey])) {
        $isShared = true;
        $shareId = $folderShares[$folderKey];
        log_debug("Found folder share with exact key: $folderKey -> $shareId");
    }
    
    // SECOND PRIORITY: Check if the same foldername is already shared by this user
    if (!$isShared) {
        log_debug("Checking if folder with name '$folderName' is already shared by user '$username'");
        
        foreach ($folderShares as $key => $id) {
            // Only check shares belonging to this user
            if (strpos($key, $username . ':') === 0) {
                $path = substr($key, strlen($username) + 1);
                $existingFolderName = basename($path);
                
                if ($existingFolderName === $folderName) {
                    $isShared = true;
                    $shareId = $id;
                    log_debug("Found existing share for same foldername: $existingFolderName (ID: $shareId, Key: $key)");
                    
                    // Update with the current key format for consistency
                    $folderShares[$folderKey] = $shareId;
                    save_folder_shares($folderShares);
                    
                    // Update session
                    if (!isset($_SESSION['folder_shares'])) {
                        $_SESSION['folder_shares'] = [];
                    }
                    $_SESSION['folder_shares'][$folderKey] = $shareId;
                    break;
                }
            }
        }
    }
    
    // THIRD PRIORITY: Check alternate path formats
    if (!$isShared) {
        $normalizedFolderPath = ltrim($folderPath, '/');
        $alternateKeys = [
            $username . ':/' . $normalizedFolderPath,
            $username . ':Home/' . $normalizedFolderPath,
            $username . ':/Home/' . $normalizedFolderPath,
            $username . ':' . 'Home/' . $normalizedFolderPath
        ];
        
        log_debug("Checking alternate keys: " . json_encode($alternateKeys));
        
        foreach ($alternateKeys as $altKey) {
            if (isset($folderShares[$altKey])) {
                $isShared = true;
                $shareId = $folderShares[$altKey];
                log_debug("Found folder share with alternate key: $altKey -> $shareId");
                
                // Update with the current key format for consistency
                $folderShares[$folderKey] = $shareId;
                save_folder_shares($folderShares);
                
                // Update session
                if (!isset($_SESSION['folder_shares'])) {
                    $_SESSION['folder_shares'] = [];
                }
                $_SESSION['folder_shares'][$folderKey] = $shareId;
                break;
            }
        }
    }
    
    // FOURTH PRIORITY: Check in session shares
    if (!$isShared && isset($sessionFolderShares[$folderKey])) {
        $isShared = true;
        $shareId = $sessionFolderShares[$folderKey];
        log_debug("Found folder share in session: $folderKey -> $shareId");
        
        // Save to persistent shares for consistency
        $folderShares[$folderKey] = $shareId;
        save_folder_shares($folderShares);
    }
    
    if ($isShared && $shareId) {
        send_json_response([
            'success' => true,
            'is_shared' => true,
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
        ]);
    } else {
        send_json_response([
            'success' => true,
            'is_shared' => false
        ]);
    }
}

// Create a share for a folder
function create_folder_share() {
    global $username;
    
    if (!isset($_POST['folder_path']) || empty($_POST['folder_path'])) {
        send_json_response(['success' => false, 'error' => 'No folder specified']);
    }
    
    $folderPath = $_POST['folder_path'];
    $folderKey = $username . ':' . $folderPath;
    $folderName = basename($folderPath);
    
    log_debug("Creating share for folder: $folderPath (foldername: $folderName)");
    
    // Verify that the folder exists
    $baseDir = "/var/www/html/webdav/users/$username/Home";
    $possiblePaths = [
        $baseDir . '/' . $folderPath,
        $baseDir . '/' . ltrim($folderPath, '/'),
        $baseDir . ltrim($folderPath, 'Home/'),
        $baseDir . ltrim($folderPath, '/Home/'),
        realpath($baseDir) . '/' . ltrim($folderPath, '/')
    ];
    
    $folderExists = false;
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_dir($path)) {
            $folderExists = true;
            break;
        }
    }
    
    if (!$folderExists) {
        log_debug("Folder does not exist: $folderPath");
        send_json_response(['success' => false, 'error' => 'Folder does not exist or cannot be accessed']);
        return;
    }
    
    // Load all folder shares
    $folderShares = load_folder_shares();
    $inactiveFolderShares = load_inactive_folder_shares();
    log_debug("Loaded " . count($folderShares) . " active folder shares and " . count($inactiveFolderShares) . " inactive folder shares");
    
    // HIGHEST PRIORITY: Check if this folder was previously shared and is now inactive
    if (isset($inactiveFolderShares[$folderKey])) {
        $shareId = $inactiveFolderShares[$folderKey];
        log_debug("Found inactive folder share with ID: $shareId (exact match)");
        
        // Move from inactive to active shares
        $folderShares[$folderKey] = $shareId;
        unset($inactiveFolderShares[$folderKey]);
        
        // Save both share lists
        save_folder_shares($folderShares);
        save_inactive_folder_shares($inactiveFolderShares);
        
        // Update session
        if (!isset($_SESSION['folder_shares'])) {
            $_SESSION['folder_shares'] = [];
        }
        $_SESSION['folder_shares'][$folderKey] = $shareId;
        
        send_json_response([
            'success' => true,
            'message' => 'Folder sharing re-enabled',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
        ]);
        return;
    }
    
    // Check if any folder with the same name has an inactive share
    foreach ($inactiveFolderShares as $key => $id) {
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            if (basename($path) === $folderName) {
                $shareId = $id;
                log_debug("Found inactive share for same foldername: $key -> $shareId");
                
                // Move from inactive to active shares
                $folderShares[$folderKey] = $shareId;
                unset($inactiveFolderShares[$key]);
                
                // Save both share lists
                save_folder_shares($folderShares);
                save_inactive_folder_shares($inactiveFolderShares);
                
                // Update session
                if (!isset($_SESSION['folder_shares'])) {
                    $_SESSION['folder_shares'] = [];
                }
                $_SESSION['folder_shares'][$folderKey] = $shareId;
                
                send_json_response([
                    'success' => true,
                    'message' => 'Folder sharing re-enabled',
                    'share_id' => $shareId,
                    'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
                ]);
                return;
            }
        }
    }
    
    // FIRST PRIORITY: Check if this exact folder is already shared
    if (isset($folderShares[$folderKey])) {
        $shareId = $folderShares[$folderKey];
        log_debug("Folder already shared with ID: $shareId (exact match)");
        
        // Ensure it's in the session
        if (!isset($_SESSION['folder_shares'])) {
            $_SESSION['folder_shares'] = [];
        }
        $_SESSION['folder_shares'][$folderKey] = $shareId;
        
        send_json_response([
            'success' => true,
            'message' => 'Folder is already shared',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
        ]);
        return;
    }
    
    // SECOND PRIORITY: Check if the same foldername is already shared by this user
    log_debug("Checking if folder with name '$folderName' is already shared by user '$username'");
    $existingShareId = null;
    
    foreach ($folderShares as $key => $id) {
        // Only check shares belonging to this user
        if (strpos($key, $username . ':') === 0) {
            $path = substr($key, strlen($username) + 1);
            $existingFolderName = basename($path);
            
            if ($existingFolderName === $folderName) {
                $existingShareId = $id;
                $existingKey = $key;
                log_debug("Found existing share for same foldername: $existingFolderName (ID: $existingShareId, Key: $existingKey)");
                break;
            }
        }
    }
    
    if ($existingShareId) {
        // Use the existing share ID for this folder
        $folderShares[$folderKey] = $existingShareId;
        save_folder_shares($folderShares);
        
        // Update session
        if (!isset($_SESSION['folder_shares'])) {
            $_SESSION['folder_shares'] = [];
        }
        $_SESSION['folder_shares'][$folderKey] = $existingShareId;
        
        send_json_response([
            'success' => true,
            'message' => 'Folder is already shared',
            'share_id' => $existingShareId,
            'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($existingShareId)
        ]);
        return;
    }
    
    // THIRD PRIORITY: Check alternate path formats
    $normalizedFolderPath = ltrim($folderPath, '/');
    $alternateKeys = [
        $username . ':/' . $normalizedFolderPath,
        $username . ':Home/' . $normalizedFolderPath,
        $username . ':/Home/' . $normalizedFolderPath,
        $username . ':' . 'Home/' . $normalizedFolderPath
    ];
    
    log_debug("Checking alternate keys: " . json_encode($alternateKeys));
    
    foreach ($alternateKeys as $altKey) {
        if (isset($folderShares[$altKey])) {
            $shareId = $folderShares[$altKey];
            log_debug("Folder already shared with ID: $shareId (alternate path: $altKey)");
            
            // Update with the current key format for consistency
            $folderShares[$folderKey] = $shareId;
            save_folder_shares($folderShares);
            
            // Update session
            if (!isset($_SESSION['folder_shares'])) {
                $_SESSION['folder_shares'] = [];
            }
            $_SESSION['folder_shares'][$folderKey] = $shareId;
            
            send_json_response([
                'success' => true,
                'message' => 'Folder is already shared',
                'share_id' => $shareId,
                'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
            ]);
            return;
        }
    }
    
    // FOURTH PRIORITY: Check session shares
    $sessionFolderShares = $_SESSION['folder_shares'] ?? [];
    if (isset($sessionFolderShares[$folderKey])) {
        $shareId = $sessionFolderShares[$folderKey];
        log_debug("Folder already shared in session with ID: $shareId");
        
        // Save to persistent shares
        $folderShares[$folderKey] = $shareId;
        save_folder_shares($folderShares);
        
        send_json_response([
            'success' => true,
            'message' => 'Folder is already shared',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
        ]);
        return;
    }
    
    // If we get here, the folder is not shared yet, so create a new share
    $shareId = bin2hex(random_bytes(16));
    log_debug("Creating new share with ID: $shareId for folder: $folderPath");
    
    // Save to persistent shares
    $folderShares[$folderKey] = $shareId;
    
    if (save_folder_shares($folderShares)) {
        // Also save to session
        if (!isset($_SESSION['folder_shares'])) {
            $_SESSION['folder_shares'] = [];
        }
        $_SESSION['folder_shares'][$folderKey] = $shareId;
        
        send_json_response([
            'success' => true,
            'message' => 'Folder shared successfully',
            'share_id' => $shareId,
            'share_url' => get_base_url() . '/shared_folder.php?id=' . urlencode($shareId)
        ]);
    } else {
        send_json_response(['success' => false, 'error' => 'Failed to save folder share']);
    }
}

// Delete a folder share (now just marks it as inactive)
function delete_folder_share($folderPath) {
    global $username;
    
    if (empty($folderPath)) {
        send_json_response(['success' => false, 'error' => 'No folder specified']);
    }
    
    $folderKey = $username . ':' . $folderPath;
    $folderName = basename($folderPath);
    
    log_debug("Disabling share for folder: $folderPath (key: $folderKey)");
    
    $folderShares = load_folder_shares();
    $sessionFolderShares = $_SESSION['folder_shares'] ?? [];
    $inactiveFolderShares = load_inactive_folder_shares();
    
    $deleted = false;
    $shareId = null;
    
    // Check if the folder is shared with exact key
    if (isset($folderShares[$folderKey])) {
        $shareId = $folderShares[$folderKey];
        log_debug("Found folder share to disable: $folderKey -> $shareId");
        
        // Store in inactive shares before removing
        $inactiveFolderShares[$folderKey] = $shareId;
        save_inactive_folder_shares($inactiveFolderShares);
        
        // Remove from active shares
        unset($folderShares[$folderKey]);
        save_folder_shares($folderShares);
        $deleted = true;
    } else {
        // Check if any folder with the same name is shared
        foreach ($folderShares as $key => $id) {
            if (strpos($key, $username . ':') === 0) {
                $path = substr($key, strlen($username) + 1);
                if (basename($path) === $folderName) {
                    $shareId = $id;
                    log_debug("Found folder share with same foldername to disable: $key -> $shareId");
                    
                    // Store in inactive shares
                    $inactiveFolderShares[$folderKey] = $shareId;
                    save_inactive_folder_shares($inactiveFolderShares);
                    
                    // Remove from active shares
                    unset($folderShares[$key]);
                    save_folder_shares($folderShares);
                    $deleted = true;
                    break;
                }
            }
        }
    }
    
    // Remove from session shares
    if (isset($sessionFolderShares[$folderKey])) {
        unset($_SESSION['folder_shares'][$folderKey]);
        $deleted = true;
    }
    
    if ($deleted) {
        send_json_response([
            'success' => true,
            'message' => 'Folder share removed successfully'
        ]);
    } else {
        send_json_response([
            'success' => false,
            'error' => 'Folder share not found'
        ]);
    }
} 