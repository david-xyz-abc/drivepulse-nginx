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
$users_file = '/var/www/html/selfhostedgdrive/users.json';

// Verify token
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if (empty($token)) {
    $_SESSION['error'] = "Invalid or missing reset token.";
    header("Location: index.php");
    exit;
}

// Check if token exists and is valid
$token_file = $reset_tokens_dir . '/' . $token . '.json';
if (!file_exists($token_file)) {
    $_SESSION['error'] = "Invalid or expired reset token. Please request a new password reset.";
    header("Location: index.php");
    exit;
}

// Read token data
$token_data = json_decode(file_get_contents($token_file), true);

// Check if token has expired
if (time() > $token_data['expires']) {
    // Delete expired token file
    unlink($token_file);
    $_SESSION['error'] = "Your password reset link has expired. Please request a new one.";
    header("Location: index.php");
    exit;
}

$username = $token_data['username'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Validate password
    if (empty($password) || strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Update password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            // Read users file
            if (!file_exists($users_file)) {
                throw new Exception("Users file not found.");
            }
            
            $users = json_decode(file_get_contents($users_file), true);
            
            if (!isset($users[$username])) {
                throw new Exception("User not found.");
            }
            
            // Update password
            $users[$username]['password'] = $hashed_password;
            
            // Save updated users file
            file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
            
            // Delete the reset token file
            unlink($token_file);
            
            $_SESSION['message'] = "Your password has been successfully reset. You can now log in with your new password.";
            header("Location: index.php");
            exit;
        } catch (Exception $e) {
            log_debug("Password update failed: " . $e->getMessage());
            $error = "Failed to update password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DrivePulse - Reset Password</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>

  <style>
    :root {
      --background: #121212;
      --text-color: #fff;
      --content-bg: #1e1e1e;
      --border-color: #333;
      --button-bg: linear-gradient(135deg, #555, #777);
      --button-hover: linear-gradient(135deg, #777, #555);
      --accent-red: #ff4444;
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }
    body {
      background: var(--background);
      color: var(--text-color);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      transition: background 0.3s, color 0.3s;
      position: relative;
      overflow: hidden;
    }
    #particles-js {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 0;
      opacity: 0.9;
      background: var(--background);
    }
    .reset-container {
      backdrop-filter: blur(8px);
      background: rgba(30, 30, 30, 0.9);
      border: 1px solid rgba(255, 68, 68, 0.3);
      border-radius: 16px;
      padding: 40px;
      width: 380px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.4);
      text-align: center;
      transform: translateY(0);
      opacity: 0;
      animation: containerEntrance 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
      transition: transform 0.3s, box-shadow 0.3s;
      position: relative;
      z-index: 1;
    }
    @keyframes containerEntrance {
      0% { opacity: 0; transform: translateY(40px); }
      100% { opacity: 1; transform: translateY(0); }
    }
    .reset-container:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 40px rgba(0,0,0,0.5);
    }
    .logo-icon {
      font-size: 56px;
      margin-bottom: 20px;
      color: var(--accent-red);
      animation: float 4s ease-in-out infinite;
      cursor: pointer;
      filter: drop-shadow(0 0 8px rgba(255, 68, 68, 0.3));
    }
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-8px); }
    }
    .project-name {
      font-size: 18px;
      font-weight: 500;
      margin-bottom: 25px;
      color: var(--text-color);
      text-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    .error {
      color: var(--accent-red);
      margin-bottom: 15px;
      animation: shake 0.4s ease-in-out;
    }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(8px); }
      50% { transform: translateX(-8px); }
      75% { transform: translateX(4px); }
    }
    .form-group {
      text-align: left;
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-size: 14px;
      color: #ccc;
    }
    .form-group input {
      width: 100%;
      padding: 12px;
      background: rgba(42, 42, 42, 0.8);
      border: 2px solid transparent;
      border-radius: 6px;
      color: var(--text-color);
      font-size: 14px;
      transition: all 0.3s ease;
    }
    .form-group input:hover {
      border-color: var(--accent-red);
    }
    .form-group input:focus {
      outline: none;
      border-color: var(--accent-red);
      box-shadow: 0 0 16px rgba(255, 68, 68, 0.3);
    }
    .button {
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, var(--accent-red), #ff2222);
      border: none;
      border-radius: 6px;
      color: var(--text-color);
      font-size: 15px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
      position: relative;
      overflow: hidden;
    }
    .button::after {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: linear-gradient(45deg, 
        transparent, 
        rgba(255,255,255,0.15), 
        transparent);
      transform: rotate(45deg);
      transition: all 0.6s ease;
    }
    .button:hover::after {
      left: 50%;
      top: 50%;
    }
    .button:hover {
      background: linear-gradient(135deg, #ff2222, var(--accent-red));
      transform: scale(1.03);
    }
    .button:active {
      transform: scale(0.98);
    }
    .back-link {
      color: var(--accent-red);
      cursor: pointer;
      text-decoration: none;
      margin-top: 15px;
      display: inline-block;
      transition: color 0.3s;
      position: relative;
    }
    .back-link::after {
      content: '';
      position: absolute;
      bottom: -2px;
      left: 0;
      width: 0;
      height: 1px;
      background: currentColor;
      transition: width 0.3s ease;
    }
    .back-link:hover::after {
      width: 100%;
    }
  </style>
</head>
<body>
  <div id="particles-js"></div>
  
  <div class="reset-container">
    <i class="fas fa-cloud-upload-alt logo-icon"></i>
    <div class="project-name">DrivePulse</div>
    <h2 style="margin-bottom: 20px;">Reset Your Password</h2>
    
    <?php if (isset($error)): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form action="complete_reset.php?token=<?php echo htmlspecialchars(urlencode($token)); ?>" method="post">
      <div class="form-group">
        <label for="password">New Password</label>
        <input type="password" id="password" name="password" required minlength="8">
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
      </div>

      <button type="submit" class="button">Reset Password</button>
    </form>

    <a href="index.php" class="back-link">Back to Login</a>
  </div>

  <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
  <script>
    // Enhanced particle configuration
    particlesJS('particles-js', {
      particles: {
        number: { 
          value: 120,
          density: { 
            enable: true, 
            value_area: 1500
          }
        },
        color: { 
          value: "#ff4444"
        },
        shape: {
          type: "circle",
          stroke: {
            width: 0,
            color: "#ff0000"
          }
        },
        opacity: {
          value: 0.9,
          random: true,
          anim: {
            enable: true,
            speed: 0.8,
            opacity_min: 0.4,
            sync: false
          }
        },
        size: {
          value: 4,
          random: {
            enable: true,
            minimumValue: 2
          }
        },
        line_linked: {
          enable: true,
          distance: 120,
          color: "#ff8888",
          opacity: 0.6,
          width: 1.5
        },
        move: {
          enable: true,
          speed: 3.5,
          direction: "none",
          random: true,
          straight: false,
          out_mode: "out",
          bounce: false,
          attract: {
            enable: true,
            rotateX: 600,
            rotateY: 1200
          }
        }
      },
      interactivity: {
        detect_on: "canvas",
        events: {
          onhover: {
            enable: true,
            mode: "repulse"
          },
          onclick: {
            enable: true,
            mode: "push"
          },
          resize: true
        }
      },
      retina_detect: true
    });
  </script>
</body>
</html> 
</html> 