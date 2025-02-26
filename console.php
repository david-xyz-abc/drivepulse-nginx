<?php
session_start();

// Check for admin password in session or verify it
if (!isset($_SESSION['admin']) && (!isset($_POST['password']) || $_POST['password'] !== '2254')) {
    header("Location: /selfhostedgdrive/index.php");
    exit;
} else if (isset($_POST['password']) && $_POST['password'] === '2254') {
    $_SESSION['admin'] = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Console</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --background: #121212;
            --text-color: #fff;
            --content-bg: #1e1e1e;
            --border-color: #333;
            --accent-red: #d32f2f;
        }
        
        body {
            background: var(--background);
            color: var(--text-color);
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 20px;
        }
        
        .console-container {
            background: var(--content-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: var(--accent-red);
            margin-bottom: 20px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-box {
            background: rgba(211, 47, 47, 0.1);
            border: 1px solid var(--accent-red);
            border-radius: 4px;
            padding: 15px;
        }
        
        .stat-box h3 {
            margin: 0 0 10px 0;
            color: var(--accent-red);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="console-container">
        <h1>Admin Console</h1>
        
        <div class="stats-container">
            <div class="stat-box">
                <h3>Total Users</h3>
                <div class="stat-value">
                    <?php
                    $userCount = count(glob("/var/www/html/webdav/users/*", GLOB_ONLYDIR));
                    echo $userCount;
                    ?>
                </div>
            </div>
            
            <div class="stat-box">
                <h3>Total Storage Used</h3>
                <div class="stat-value">
                    <?php
                    $totalSize = 0;
                    $users = glob("/var/www/html/webdav/users/*", GLOB_ONLYDIR);
                    foreach ($users as $userDir) {
                        $totalSize += getDirSize($userDir);
                    }
                    echo round($totalSize / (1024 * 1024 * 1024), 2) . " GB";
                    ?>
                </div>
            </div>
            
            <div class="stat-box">
                <h3>Server Storage</h3>
                <div class="stat-value">
                    <?php
                    $totalStorage = disk_total_space("/var/www/html/webdav");
                    echo round($totalStorage / (1024 * 1024 * 1024), 2) . " GB";
                    ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 
BLUD