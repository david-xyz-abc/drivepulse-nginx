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

// Database connection
$db_host = 'localhost';
$db_name = 'webdav_users';
$db_user = 'webdav_admin';
$db_pass = 'webdav_password';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    log_debug("Database connection failed: " . $e->getMessage());
    $_SESSION['error'] = "System error. Please try again later.";
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    
    if (empty($username)) {
        $_SESSION['error'] = "Please enter your username.";
        header("Location: index.php");
        exit;
    }
    
    // Check if username exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // For security reasons, don't reveal that the username doesn't exist
        $_SESSION['message'] = "If your account exists, a password reset link has been sent to your email.";
        header("Location: index.php");
        exit;
    }
    
    // Generate a unique reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Store the token in the database
    $stmt = $pdo->prepare("INSERT INTO password_resets (username, token, expires) VALUES (?, ?, ?) 
                          ON DUPLICATE KEY UPDATE token = ?, expires = ?");
    $stmt->execute([$username, $token, $expires, $token, $expires]);
    
    // In a real application, you would send an email with the reset link
    // For this demo, we'll just show the reset link on the page
    
    // Create a reset URL (in a real app, this would be sent via email)
    $resetUrl = "http://{$_SERVER['HTTP_HOST']}/selfhostedgdrive/complete_reset.php?token=" . urlencode($token);
    
    // For demo purposes, store the reset URL in the session
    $_SESSION['reset_url'] = $resetUrl;
    $_SESSION['message'] = "Password reset initiated. In a real application, an email would be sent with a reset link. For demo purposes, you can <a href='$resetUrl'>click here</a> to reset your password.";
    
    header("Location: index.php");
    exit;
}
?> 