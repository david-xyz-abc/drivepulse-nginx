<?php
session_start();

// Check for admin password in session or verify it
if (!isset($_SESSION['admin']) && (!isset($_POST['password']) || $_POST['password'] !== '2254')) {
    header("Location: /selfhostedgdrive/index.php");
    exit;
} else if (isset($_POST['password']) && $_POST['password'] === '2254') {
    $_SESSION['admin'] = true;
}

// Function to get directory size
function getDirSize($dir) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

// Handle user deletion if requested
if (isset($_POST['delete_user']) && !empty($_POST['delete_user'])) {
    $userToDelete = $_POST['delete_user'];
    $userDir = "/var/www/html/webdav/users/" . $userToDelete;
    if (is_dir($userDir)) {
        // Delete user directory and all contents
        shell_exec("rm -rf " . escapeshellarg($userDir));
    }
    // Redirect to refresh the page
    header("Location: /selfhostedgdrive/console.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Console</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
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

        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .users-table th, .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .users-table th {
            color: var(--accent-red);
            font-weight: 500;
        }

        .delete-btn {
            background: var(--accent-red);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: opacity 0.3s;
        }

        .delete-btn:hover {
            opacity: 0.8;
        }

        .storage-bar {
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .storage-used {
            height: 100%;
            background: var(--accent-red);
            transition: width 0.3s ease;
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
                    $users = glob("/var/www/html/webdav/users/*", GLOB_ONLYDIR);
                    $userCount = count($users);
                    echo $userCount;
                    ?>
                </div>
            </div>
            
            <div class="stat-box">
                <h3>Total Storage Used</h3>
                <div class="stat-value">
                    <?php
                    $totalSize = 0;
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

        <table class="users-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Storage Used</th>
                    <th>Files</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $userDir): ?>
                    <?php
                    $username = basename($userDir);
                    $userSize = getDirSize($userDir);
                    $userSizeGB = round($userSize / (1024 * 1024 * 1024), 2);
                    $fileCount = iterator_count(new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS)
                    ));
                    $storagePercentage = ($userSize / $totalSize) * 100;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($username); ?></td>
                        <td>
                            <?php echo $userSizeGB; ?> GB
                            <div class="storage-bar">
                                <div class="storage-used" style="width: <?php echo $storagePercentage; ?>%"></div>
                            </div>
                        </td>
                        <td><?php echo $fileCount; ?></td>
                        <td>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user and all their files?');">
                                <input type="hidden" name="delete_user" value="<?php echo htmlspecialchars($username); ?>">
                                <button type="submit" class="delete-btn">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html> 
BLUD