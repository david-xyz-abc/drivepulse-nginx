<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- Critical styles to prevent flash - must be first in head -->
  <style>
    /* Immediate dark background application */
    html, body {
      background-color: #121212 !important;
      color: #e0e0e0 !important;
    }
  </style>
  <meta charset="UTF-8">
  <title>DrivePulse - Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Prevent white flash during page loads -->
  <meta name="theme-color" content="#121212">
  <meta name="color-scheme" content="dark">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
  
  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="drivepulse.svg">
  <!-- Apple Touch Icon -->
  <link rel="apple-touch-icon" href="drivepulse.svg">
  <!-- MS Tile Icon -->
  <meta name="msapplication-TileImage" content="drivepulse.svg">
  <meta name="msapplication-TileColor" content="#ff4444">

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
    body.light-mode {
      --background: #f5f5f5;
      --text-color: #333;
      --content-bg: #fff;
      --border-color: #ccc;
      --button-bg: linear-gradient(135deg, #888, #aaa);
      --button-hover: linear-gradient(135deg, #aaa, #888);
      --accent-red: #f44336;
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }
    body {
      background-color: var(--background);
      color: var(--text-color);
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      transition: background-color 0.3s ease;
    }
    
    .container {
      position: relative;
      z-index: 1;
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
    .login-container {
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
    }
    body.light-mode .login-container {
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(244, 67, 54, 0.3);
      box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    }
    @keyframes containerEntrance {
      0% { opacity: 0; transform: translateY(40px); }
      100% { opacity: 1; transform: translateY(0); }
    }
    .login-container:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 40px rgba(0,0,0,0.5);
    }
    body.light-mode .login-container:hover {
      box-shadow: 0 12px 40px rgba(0,0,0,0.3);
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
    body.light-mode .project-name {
      text-shadow: 0 1px 2px rgba(0,0,0,0.1);
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
    body.light-mode .form-group label {
      color: #666;
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
    body.light-mode .form-group input {
      background: rgba(240, 240, 240, 0.8);
      color: #333;
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
      background: var(--button-bg);
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
    body.light-mode .button {
      color: #fff;
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
      background: var(--button-hover);
      transform: scale(1.03);
    }
    .button:active {
      transform: scale(0.98);
    }
    .register-button {
      background: linear-gradient(135deg, var(--accent-red), #ff2222);
    }
    .register-button:hover {
      background: linear-gradient(135deg, #ff2222, var(--accent-red));
    }
    .toggle-link {
      color: var(--accent-red);
      cursor: pointer;
      text-decoration: none;
      margin-top: 15px;
      display: inline-block;
      transition: color 0.3s;
      position: relative;
    }
    .toggle-link::after {
      content: '';
      position: absolute;
      bottom: -2px;
      left: 0;
      width: 0;
      height: 1px;
      background: currentColor;
      transition: width 0.3s ease;
    }
    .toggle-link:hover::after {
      width: 100%;
    }
    .hidden {
      opacity: 0;
      max-height: 0;
      overflow: hidden;
      transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    form:not(.hidden) {
      animation: formSwitch 0.6s ease forwards;
    }
    @keyframes formSwitch {
      0% { opacity: 0; transform: translateX(20px); }
      100% { opacity: 1; transform: translateX(0); }
    }
    
    /* System message styling */
    .system-message {
      color: var(--accent-red);
      margin-bottom: 15px;
      padding: 10px;
      background: rgba(255, 68, 68, 0.1);
      border-radius: 4px;
      border-left: 3px solid var(--accent-red);
      text-align: left;
      line-height: 1.5;
      word-wrap: break-word;
    }
    .system-message a {
      color: var(--accent-red);
      font-weight: 500;
    }
  </style>
</head>
<body class="light-mode">
  <div id="particles-js"></div>
  <div class="container">
    <div class="login-container">
      <i class="fas fa-cloud-upload-alt logo-icon"></i>
      <div class="project-name">DrivePulse</div>

      <?php 
        if (isset($_SESSION['error'])) {
            echo '<div class="error">' . htmlspecialchars($_SESSION['error']) . '</div>';
            unset($_SESSION['error']);
        }
        if (isset($_SESSION['message'])) {
            echo '<div class="system-message">' . $_SESSION['message'] . '</div>';
            unset($_SESSION['message']);
        }
      ?>

      <!-- Login Form -->
      <form action="authenticate.php" method="post" id="loginForm">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="button">Sign In</button>
      </form>

      <span class="toggle-link" onclick="toggleForms()">Need an account? Register here</span>

      <!-- Registration Form -->
      <form action="register.php" method="post" id="registerForm" class="hidden">
        <div class="form-group">
          <label for="reg_username">Username</label>
          <input type="text" id="reg_username" name="username" required>
        </div>

        <div class="form-group">
          <label for="reg_password">Password</label>
          <input type="password" id="reg_password" name="password" required>
        </div>

        <button type="submit" class="button register-button">Register</button>
      </form>

      <span class="toggle-link hidden" onclick="toggleForms()" id="loginLink">Already have an account? Sign in</span>
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
            value: "#f44336"
          },
          shape: {
            type: "circle",
            stroke: {
              width: 0,
              color: "#ff0000"
            }
          },
          opacity: {
            value: 0.6,
            random: true,
            anim: {
              enable: true,
              speed: 0.8,
              opacity_min: 0.3,
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
            color: "#f44336",
            opacity: 0.4,
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

      function toggleForms() {
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');
        const loginLink = document.getElementById('loginLink');
        
        loginForm.classList.toggle('hidden');
        registerForm.classList.toggle('hidden');
        loginLink.classList.toggle('hidden');
        document.querySelectorAll('.toggle-link')[0].classList.toggle('hidden');
      }

      // Secret admin access
      let clicks = 0;
      let lastClick = 0;
      const CLICK_TIMEOUT = 3000;

      document.querySelector('.logo-icon').addEventListener('click', function(e) {
          const now = Date.now();
          if (now - lastClick > CLICK_TIMEOUT) clicks = 0;
          
          clicks++;
          lastClick = now;

          if (clicks === 3) {
              const password = prompt("Enter admin password:");
              if (password === "123") {
                  const form = document.createElement('form');
                  form.method = 'POST';
                  form.action = 'console.php';
                  
                  const passwordInput = document.createElement('input');
                  passwordInput.type = 'hidden';
                  passwordInput.name = 'password';
                  passwordInput.value = password;
                  
                  form.appendChild(passwordInput);
                  document.body.appendChild(form);
                  form.submit();
              }
              clicks = 0;
          }
      });
    </script>
  </div>
</body>
</html>