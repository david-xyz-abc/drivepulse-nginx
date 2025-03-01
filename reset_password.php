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

// Define the reset tokens directory
$reset_tokens_dir = '/var/www/html/selfhostedgdrive/reset_tokens';

// Create the directory if it doesn't exist
if (!file_exists($reset_tokens_dir)) {
    mkdir($reset_tokens_dir, 0755, true);
}

// Function to get user data
function getUserData($username) {
    $users_file = '/var/www/html/selfhostedgdrive/users.json';
    
    if (!file_exists($users_file)) {
        return null;
    }
    
    $users = json_decode(file_get_contents($users_file), true);
    
    if (isset($users[$username])) {
        return $users[$username];
    }
    
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    
    if (empty($username)) {
        $_SESSION['error'] = "Please enter your username.";
        header("Location: index.php");
        exit;
    }
    
    // Check if username exists
    $user = getUserData($username);
    
    if (!$user) {
        // For security reasons, don't reveal that the username doesn't exist
        $_SESSION['message'] = "If your account exists, a password reset link has been sent to your email.";
        header("Location: index.php");
        exit;
    }
    
    // Generate a unique reset token
    $token = bin2hex(random_bytes(32));
    $expires = time() + 3600; // 1 hour from now
    
    // Create token data
    $token_data = [
        'username' => $username,
        'expires' => $expires,
        'created_at' => time()
    ];
    
    // Store token in a file
    $token_file = $reset_tokens_dir . '/' . $token . '.json';
    file_put_contents($token_file, json_encode($token_data));
    
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