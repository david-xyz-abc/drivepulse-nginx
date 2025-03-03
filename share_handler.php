<?php
// share_handler.php - Handles file sharing functionality

// Start session
session_start();

// Include configuration
require_once 'config.php';

// Database connection
$db = new SQLite3($dbPath);

// Create shares table if it doesn't exist
$db->exec('
CREATE TABLE IF NOT EXISTS shares (
    id TEXT PRIMARY KEY,
    file_path TEXT NOT NULL,
    password TEXT,
    expiry_time INTEGER,
    created_at INTEGER,
    access_count INTEGER DEFAULT 0
)
');

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    // Create a new share
    if (isset($_POST['action']) && $_POST['action'] === 'create_share') {
        $filePath = $_POST['file_path'] ?? '';
        $shareId = $_POST['share_id'] ?? '';
        $password = $_POST['password'] ?? '';
        $expiry = $_POST['expiry'] ?? 'never';
        
        // Validate inputs
        if (empty($filePath) || empty($shareId)) {
            $response = ['success' => false, 'message' => 'Missing required parameters'];
            echo json_encode($response);
            exit;
        }
        
        // Check if file exists
        if (!file_exists($filePath)) {
            $response = ['success' => false, 'message' => 'File not found'];
            echo json_encode($response);
            exit;
        }
        
        // Calculate expiry time
        $expiryTime = 0; // 0 means never expires
        $now = time();
        
        switch ($expiry) {
            case '1h':
                $expiryTime = $now + 3600;
                break;
            case '24h':
                $expiryTime = $now + 86400;
                break;
            case '7d':
                $expiryTime = $now + 604800;
                break;
            case '30d':
                $expiryTime = $now + 2592000;
                break;
            default:
                $expiryTime = 0; // Never expires
        }
        
        // Hash password if provided
        if (!empty($password)) {
            $password = password_hash($password, PASSWORD_DEFAULT);
        }
        
        // Insert share into database
        $stmt = $db->prepare('
            INSERT INTO shares (id, file_path, password, expiry_time, created_at, access_count)
            VALUES (:id, :file_path, :password, :expiry_time, :created_at, 0)
        ');
        
        $stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
        $stmt->bindValue(':file_path', $filePath, SQLITE3_TEXT);
        $stmt->bindValue(':password', $password, SQLITE3_TEXT);
        $stmt->bindValue(':expiry_time', $expiryTime, SQLITE3_INTEGER);
        $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        
        if ($result) {
            $response = [
                'success' => true, 
                'message' => 'Share created successfully',
                'shareId' => $shareId
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to create share'];
        }
    }
    
    // Delete a share
    else if (isset($_POST['action']) && $_POST['action'] === 'delete_share') {
        $shareId = $_POST['share_id'] ?? '';
        
        if (empty($shareId)) {
            $response = ['success' => false, 'message' => 'Missing share ID'];
            echo json_encode($response);
            exit;
        }
        
        $stmt = $db->prepare('DELETE FROM shares WHERE id = :id');
        $stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result) {
            $response = ['success' => true, 'message' => 'Share deleted successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete share'];
        }
    }
    
    // Get share info
    else if (isset($_POST['action']) && $_POST['action'] === 'get_share_info') {
        $shareId = $_POST['share_id'] ?? '';
        
        if (empty($shareId)) {
            $response = ['success' => false, 'message' => 'Missing share ID'];
            echo json_encode($response);
            exit;
        }
        
        $stmt = $db->prepare('SELECT * FROM shares WHERE id = :id');
        $stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $share = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($share) {
            // Check if share has expired
            if ($share['expiry_time'] > 0 && $share['expiry_time'] < time()) {
                $response = ['success' => false, 'message' => 'Share has expired'];
            } else {
                $response = [
                    'success' => true,
                    'share' => [
                        'id' => $share['id'],
                        'file_path' => $share['file_path'],
                        'has_password' => !empty($share['password']),
                        'expiry_time' => $share['expiry_time'],
                        'created_at' => $share['created_at'],
                        'access_count' => $share['access_count']
                    ]
                ];
            }
        } else {
            $response = ['success' => false, 'message' => 'Share not found'];
        }
    }
    
    // Verify share password
    else if (isset($_POST['action']) && $_POST['action'] === 'verify_password') {
        $shareId = $_POST['share_id'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($shareId) || empty($password)) {
            $response = ['success' => false, 'message' => 'Missing required parameters'];
            echo json_encode($response);
            exit;
        }
        
        $stmt = $db->prepare('SELECT password FROM shares WHERE id = :id');
        $stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $share = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($share && password_verify($password, $share['password'])) {
            $response = ['success' => true, 'message' => 'Password verified'];
        } else {
            $response = ['success' => false, 'message' => 'Invalid password'];
        }
    }
    
    // Increment access count
    else if (isset($_POST['action']) && $_POST['action'] === 'increment_access') {
        $shareId = $_POST['share_id'] ?? '';
        
        if (empty($shareId)) {
            $response = ['success' => false, 'message' => 'Missing share ID'];
            echo json_encode($response);
            exit;
        }
        
        $stmt = $db->prepare('UPDATE shares SET access_count = access_count + 1 WHERE id = :id');
        $stmt->bindValue(':id', $shareId, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result) {
            $response = ['success' => true, 'message' => 'Access count updated'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update access count'];
        }
    }
    
    echo json_encode($response);
    exit;
}

// Close database connection
$db->close();
?> 