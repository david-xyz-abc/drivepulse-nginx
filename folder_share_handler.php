<?php
// Start output buffering to ensure clean JSON responses
ob_start();

// Prevent any unwanted output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Set proper content type for JSON responses
header('Content-Type: application/json');

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
    try {
        // Log the response
        if (DEBUG) {
            log_debug("Response: " . json_encode($data));
        }
        
        // Clean any previous output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        
        // Send JSON response
        echo json_encode($data);
        exit;
    } catch (Exception $e) {
        log_debug("Error in send_json_response: " . $e->getMessage());
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Failed to generate response: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Check if a folder is shared
function check_folder_share() {
    try {
        global $username;
        
        if (!isset($_GET['folder_path']) || empty($_GET['folder_path'])) {
            send_json_response(['success' => false, 'error' => 'No folder specified']);
        }
        
        $rawFolderPath = rawurldecode($_GET['folder_path']);
        $folderPath = normalize_path($rawFolderPath);
        $encodedFolderPath = encode_path_for_storage($folderPath);
        $folderKey = $username . ':' . $encodedFolderPath;
        $folderName = basename($folderPath);
        
        log_debug("Checking share for folder: $folderPath (encoded: $encodedFolderPath)");
        
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
                if (strpos($key, $username . ':') === 0) {
                    $storedPath = substr($key, strlen($username) + 1);
                    $decodedPath = decode_path_from_storage($storedPath);
                    $existingFolderName = basename($decodedPath);
                    
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
        
        // Send a single response based on whether the folder is shared or not
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
    } catch (Exception $e) {
        log_debug("Error in check_folder_share: " . $e->getMessage());
        send_json_response([
            'success' => false,
            'error' => 'Internal server error: ' . $e->getMessage()
        ]);
    }
}

// Path handling functions
function normalize_path($path) {
    // First decode any URL encoding to handle spaces correctly
    $path = rawurldecode($path);
    // Convert backslashes to forward slashes
    $path = str_replace('\\', '/', $path);
    // Remove multiple consecutive slashes
    $path = preg_replace('#/+#', '/', trim($path));
    // Remove trailing slash
    $path = rtrim($path, '/');
    return $path;
}

function encode_path_for_storage($path) {
    // First normalize the path
    $path = normalize_path($path);
    // Split path into segments
    $segments = explode('/', $path);
    // Encode each segment individually, preserving spaces as %20
    $encoded = array_map(function($segment) {
        return rawurlencode($segment);
    }, $segments);
    return implode('/', $encoded);
}

function decode_path_from_storage($path) {
    // Split path into segments
    $segments = explode('/', $path);
    // Decode each segment individually
    $decoded = array_map(function($segment) {
        return rawurldecode($segment);
    }, $segments);
    return implode('/', $decoded);
}

// Create a share for a folder
function create_folder_share() {
    try {
        global $username;
        
        if (!isset($_POST['folder_path']) || empty($_POST['folder_path'])) {
            log_debug("No folder path specified in POST request.");
            throw new Exception('No folder specified');
        }
        
        // Get the raw folder path and decode it
        $rawFolderPath = rawurldecode($_POST['folder_path']);
        log_debug("Original folder path from POST: $rawFolderPath");
        
        if (!$rawFolderPath) {
            log_debug("Empty folder path received.");
            throw new Exception('Empty folder path received');
        }
        
        // Normalize and clean the path, preserving spaces
        $folderPath = normalize_path($rawFolderPath);
        log_debug("Normalized folder path: $folderPath");
        
        if (!$folderPath) {
            log_debug("Path normalization failed.");
            throw new Exception('Path normalization failed');
        }
        
        // Encode the path for storage, properly handling spaces
        $encodedFolderPath = encode_path_for_storage($folderPath);
        log_debug("Encoded folder path for storage: $encodedFolderPath");
        
        if (!$encodedFolderPath) {
            log_debug("Path encoding failed.");
            throw new Exception('Path encoding failed');
        }
        
        // Create the folder key
        $folderKey = $username . ':' . $encodedFolderPath;
        $folderName = basename($folderPath);
        
        if (!$folderName) {
            log_debug("Invalid folder name derived from path.");
            throw new Exception('Invalid folder name');
        }
        
        log_debug("Creating share - Key: $folderKey, Name: $folderName");
        
        // Verify that the folder exists
        $baseDir = normalize_path("/var/www/html/webdav/users/$username/Home");
        log_debug("Base directory: $baseDir");
        
        if (!is_dir($baseDir)) {
            log_debug("Base directory does not exist: $baseDir");
            throw new Exception("Base directory does not exist: $baseDir");
        }
        
        // Try different path combinations, properly handling spaces
        $possiblePaths = [
            $baseDir . '/' . $folderPath,
            $baseDir . '/' . ltrim($folderPath, '/'),
            $baseDir . '/' . ltrim($folderPath, 'Home/'),
            $baseDir . '/' . ltrim($folderPath, '/Home/')
        ];
        
        $folderExists = false;
        $actualPath = null;
        
        foreach ($possiblePaths as $path) {
            // Important: Use realpath to resolve the actual filesystem path
            $normalizedPath = normalize_path($path);
            $realPath = realpath($normalizedPath);
            log_debug("Checking path: $normalizedPath (real path: " . ($realPath ?: "N/A") . ")");
            
            if ($realPath && file_exists($realPath)) {
                if (is_dir($realPath)) {
                    $folderExists = true;
                    $actualPath = $realPath;
                    log_debug("Found folder at: $actualPath");
                    break;
                } else {
                    log_debug("Path exists but is not a directory: $realPath");
                }
            } else {
                log_debug("Path does not exist: $normalizedPath");
            }
        }
        
        if (!$folderExists) {
            $errorMsg = "Folder not found. Tried paths: " . implode(", ", $possiblePaths);
            log_debug($errorMsg);
            throw new Exception($errorMsg);
        }
        
        // Load existing shares
        $folderShares = load_folder_shares();
        log_debug("Loaded " . count($folderShares) . " existing folder shares");
        
        // Check if already shared
        if (isset($folderShares[$folderKey])) {
            $shareId = $folderShares[$folderKey];
            log_debug("Folder already shared with ID: $shareId");
            
            // Ensure it's in the session
            if (!isset($_SESSION['folder_shares'])) {
                $_SESSION['folder_shares'] = [];
            }
            $_SESSION['folder_shares'][$folderKey] = $shareId;
            
            $shareUrl = get_base_url() . '/shared_folder.php?id=' . urlencode($shareId);
            log_debug("Returning existing share URL: $shareUrl");
            
            send_json_response([
                'success' => true,
                'message' => 'Folder is already shared',
                'share_id' => $shareId,
                'share_url' => $shareUrl
            ]);
            return;
        }
        
        // Create new share
        $shareId = bin2hex(random_bytes(16));
        log_debug("Generated new share ID: $shareId");
        
        // Save to persistent shares
        $folderShares[$folderKey] = $shareId;
        
        if (!save_folder_shares($folderShares)) {
            log_debug("Failed to save folder shares to storage.");
            throw new Exception('Failed to save folder shares to storage');
        }
        
        // Save to session
        if (!isset($_SESSION['folder_shares'])) {
            $_SESSION['folder_shares'] = [];
        }
        $_SESSION['folder_shares'][$folderKey] = $shareId;
        
        $shareUrl = get_base_url() . '/shared_folder.php?id=' . urlencode($shareId);
        log_debug("Created new share URL: $shareUrl");
        
        send_json_response([
            'success' => true,
            'message' => 'Folder shared successfully',
            'share_id' => $shareId,
            'share_url' => $shareUrl
        ]);
        
    } catch (Exception $e) {
        log_debug("Error in create_folder_share: " . $e->getMessage());
        log_debug("Stack trace: " . $e->getTraceAsString());
        send_json_response([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// Delete a folder share (now just marks it as inactive)
function delete_folder_share($folderPath) {
    global $username;
    
    if (empty($folderPath)) {
        send_json_response(['success' => false, 'error' => 'No folder specified']);
    }
    
    $folderPath = rawurldecode($folderPath);
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