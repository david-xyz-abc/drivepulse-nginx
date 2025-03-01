<?php
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

// Check if directory exists
if (!file_exists($reset_tokens_dir)) {
    log_debug("Reset tokens directory does not exist. Nothing to clean up.");
    exit;
}

// Get all token files
$token_files = glob($reset_tokens_dir . '/*.json');
$current_time = time();
$cleaned = 0;

foreach ($token_files as $token_file) {
    // Read token data
    $token_data = json_decode(file_get_contents($token_file), true);
    
    // Check if token has expired
    if ($current_time > $token_data['expires']) {
        // Delete expired token file
        if (unlink($token_file)) {
            $cleaned++;
            log_debug("Deleted expired token: " . basename($token_file));
        } else {
            log_debug("Failed to delete expired token: " . basename($token_file));
        }
    }
}

log_debug("Cleanup completed. Removed $cleaned expired tokens.");
echo "Cleanup completed. Removed $cleaned expired tokens.";
?> 