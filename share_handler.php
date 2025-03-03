<?php
// Set error reporting for debugging
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

// Log request details for debugging
log_debug("=== Share Handler Request ===");
log_debug("PHP Version: " . PHP_VERSION);
log_debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
log_debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));
log_debug("GET params: " . var_export($_GET ?? [], true));
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    log_debug("POST params: " . var_export($_POST ?? [], true));
}

// Function to send JSON response
function send_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

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

// Function to save shares to file
function save_shares($shares) {
    global $shares_file;
    file_put_contents($shares_file, json_encode($shares));
}

// Get username from session or use a default
$username = $_SESSION['username'] ?? 'default_user';

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
log_debug("Processing method: $method");

try {
    switch ($method) {
        case 'GET':
            // Check if a file is shared
            if (isset($_GET['action']) && $_GET['action'] === 'check_share' && isset($_GET['file_path'])) {
                $filePath = $_GET['file_path'];
                log_debug("Checking share for file: $filePath");
                
                // Check if file is shared in file storage
                $fileKey = $username . ':' . $filePath;
                $shares = load_shares();
                
                if (isset($shares[$fileKey])) {
                    // File is shared
                    $shareId = $shares[$fileKey];
                    log_debug("File is shared with ID: $shareId");
                    send_json_response([
                        'success' => true,
                        'is_shared' => true,
                        'share_url' => 'shared.php?id=' . $shareId
                    ]);
                } else {
                    // File is not shared
                    log_debug("File is not shared");
                    send_json_response([
                        'success' => true,
                        'is_shared' => false
                    ]);
                }
            } else {
                log_debug("Invalid GET request");
                send_json_response(['success' => false, 'message' => 'Invalid request'], 400);
            }
            break;
            
        case 'POST':
            // Create a new share
            if (isset($_POST['file_path'])) {
                $filePath = $_POST['file_path'];
                log_debug("Creating share for file: $filePath");
                
                // Generate a unique share ID
                $shareId = bin2hex(random_bytes(16));
                
                // Store in file
                $fileKey = $username . ':' . $filePath;
                $shares = load_shares();
                $shares[$fileKey] = $shareId;
                save_shares($shares);
                
                // Also store in session for backward compatibility
                $_SESSION['file_shares'][$fileKey] = $shareId;
                
                log_debug("File shared successfully with ID: $shareId");
                send_json_response([
                    'success' => true,
                    'message' => 'File shared successfully',
                    'share_url' => 'shared.php?id=' . $shareId
                ]);
            } else {
                log_debug("Missing file path in POST request");
                send_json_response(['success' => false, 'message' => 'Missing file path'], 400);
            }
            break;
            
        case 'DELETE':
            log_debug("DELETE request received");
            log_debug("Query string: " . ($_SERVER['QUERY_STRING'] ?? 'NONE'));
            
            // Try different methods to get the file path
            $filePath = null;
            
            // Method 1: Parse query string
            if (isset($_SERVER['QUERY_STRING'])) {
                parse_str($_SERVER['QUERY_STRING'], $query);
                if (isset($query['file_path'])) {
                    $filePath = $query['file_path'];
                }
            }
            
            // Method 2: Check GET parameters
            if ($filePath === null && isset($_GET['file_path'])) {
                $filePath = $_GET['file_path'];
            }
            
            // Method 3: Parse raw input
            if ($filePath === null) {
                $input = file_get_contents('php://input');
                log_debug("Raw input: $input");
                parse_str($input, $data);
                if (isset($data['file_path'])) {
                    $filePath = $data['file_path'];
                }
            }
            
            if ($filePath !== null) {
                log_debug("Deleting share for file: $filePath");
                
                // Remove from file storage
                $fileKey = $username . ':' . $filePath;
                $shares = load_shares();
                
                if (isset($shares[$fileKey])) {
                    unset($shares[$fileKey]);
                    save_shares($shares);
                    
                    // Also remove from session for backward compatibility
                    if (isset($_SESSION['file_shares'][$fileKey])) {
                        unset($_SESSION['file_shares'][$fileKey]);
                    }
                    
                    log_debug("File sharing disabled successfully");
                    send_json_response([
                        'success' => true,
                        'message' => 'File sharing disabled'
                    ]);
                } else {
                    log_debug("File was not shared");
                    send_json_response([
                        'success' => true,
                        'message' => 'File was not shared'
                    ]);
                }
            } else {
                log_debug("Missing file path in DELETE request");
                send_json_response(['success' => false, 'message' => 'Missing file path'], 400);
            }
            break;
            
        default:
            log_debug("Method not allowed: $method");
            send_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
            break;
    }
} catch (Exception $e) {
    // Handle any unexpected errors
    log_debug("Unexpected error: " . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
} 