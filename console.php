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

// Function to get system stats
function getSystemStats() {
    $stats = [];
    
    // CPU Usage
    $load = sys_getloadavg();
    $stats['cpu'] = $load[0];
    
    // Memory Usage
    $free = shell_exec('free');
    $free = (string)trim($free);
    $free_arr = explode("\n", $free);
    $mem = explode(" ", $free_arr[1]);
    $mem = array_filter($mem);
    $mem = array_merge($mem);
    $stats['memory_used'] = $mem[2];
    $stats['memory_total'] = $mem[1];
    
    // Disk Usage
    $disk_total = disk_total_space("/");
    $disk_free = disk_free_space("/");
    $stats['disk_used'] = $disk_total - $disk_free;
    $stats['disk_total'] = $disk_total;
    
    return $stats;
}

// Handle AJAX request for system stats
if (isset($_GET['get_stats'])) {
    header('Content-Type: application/json');
    echo json_encode(getSystemStats());
    exit;
}

// Handle user deletion if requested
if (isset($_POST['delete_user']) && !empty($_POST['delete_user'])) {
    $userToDelete = $_POST['delete_user'];
    $userDir = "/var/www/html/webdav/users/" . $userToDelete;
    $usersFile = __DIR__ . '/users.json';

    // Remove user from users.json
    if (file_exists($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true);
        if (isset($users[$userToDelete])) {
            unset($users[$userToDelete]);
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        }
    }

    // Delete user directory and all contents
    if (is_dir($userDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        // Remove the empty directory itself
        rmdir($userDir);
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <style>
        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #1a1a1a;
            --accent-primary: #dc2626;
            --accent-secondary: #991b1b;
            --text-primary: #f5f5f5;
            --text-secondary: #a3a3a3;
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            padding: 2rem;
            min-height: 100vh;
        }

        .admin-container {
            max-width: 1440px;
            margin: 0 auto;
            background: var(--bg-secondary);
            border-radius: var(--border-radius);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            padding: 2rem;
            background: linear-gradient(135deg, var(--accent-secondary) 0%, var(--accent-primary) 100%);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .header h1 {
            font-weight: 700;
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(220, 38, 38, 0.2);
        }

        .stat-title {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .users-section {
            padding: 0 2rem 2rem;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-secondary);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .users-table thead {
            background: rgba(220, 38, 38, 0.1);
        }

        .users-table th {
            padding: 1.2rem;
            text-align: left;
            font-weight: 600;
            color: var(--accent-primary);
        }

        .users-table td {
            padding: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .user-storage {
            min-width: 200px;
        }

        .storage-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .storage-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            transition: var(--transition);
        }

        .delete-btn {
            background: none;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .delete-btn:hover {
            background: var(--accent-primary);
            color: white;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .users-table thead {
                display: none;
            }

            .users-table tr {
                display: block;
                margin-bottom: 1.5rem;
                background: rgba(255, 255, 255, 0.02);
                border-radius: var(--border-radius);
            }

            .users-table td {
                display: grid;
                grid-template-columns: 100px 1fr;
                gap: 1rem;
                padding: 1rem;
                border-bottom: none;
            }

            .users-table td::before {
                content: attr(data-label);
                color: var(--text-secondary);
                font-weight: 500;
            }
        }

        @media (max-width: 480px) {
            .stat-value {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .progress-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
            transition: var(--transition);
        }

        .stat-subtitle {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function formatBytes(bytes) {
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            if (bytes === 0) return '0 B';
            const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
            return Math.round(bytes / Math.pow(1024, i), 2) + ' ' + sizes[i];
        }

        function updateSystemStats() {
            $.get('?get_stats', function(data) {
                // Update CPU
                $('#cpu-value').text(data.cpu.toFixed(2) + '%');
                $('#cpu-bar').css('width', Math.min(data.cpu * 10, 100) + '%');
                
                // Update RAM
                const ramUsedGB = data.memory_used / 1024 / 1024;
                const ramTotalGB = data.memory_total / 1024 / 1024;
                const ramPercent = (ramUsedGB / ramTotalGB) * 100;
                $('#ram-value').text(ramUsedGB.toFixed(1) + ' GB');
                $('#ram-total').text('/ ' + ramTotalGB.toFixed(1) + ' GB');
                $('#ram-bar').css('width', ramPercent + '%');
                
                // Update Disk
                const diskUsedGB = data.disk_used / 1024 / 1024 / 1024;
                const diskTotalGB = data.disk_total / 1024 / 1024 / 1024;
                const diskPercent = (diskUsedGB / diskTotalGB) * 100;
                $('#disk-value').text(diskUsedGB.toFixed(1) + ' GB');
                $('#disk-total').text('/ ' + diskTotalGB.toFixed(1) + ' GB');
                $('#disk-bar').css('width', diskPercent + '%');
            });
        }

        // Update stats every 5 seconds
        $(document).ready(function() {
            updateSystemStats();
            setInterval(updateSystemStats, 5000);
        });
    </script>
</head>
<body>
    <div class="admin-container">
        <div class="header">
            <h1><i class="fas fa-terminal"></i> Storage Admin Console</h1>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-microchip"></i> CPU Usage</div>
                <div class="stat-value" id="cpu-value">0%</div>
                <div class="progress-bar">
                    <div class="progress-fill" id="cpu-bar" style="width: 0%"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-memory"></i> RAM Usage</div>
                <div class="stat-value">
                    <span id="ram-value">0 GB</span>
                    <span class="stat-subtitle" id="ram-total">/ 0 GB</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="ram-bar" style="width: 0%"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-hdd"></i> Disk Usage</div>
                <div class="stat-value">
                    <span id="disk-value">0 GB</span>
                    <span class="stat-subtitle" id="disk-total">/ 0 GB</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="disk-bar" style="width: 0%"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-users"></i> Total Users</div>
                <div class="stat-value">
                    <?php
                    $usersFile = __DIR__ . '/users.json';
                    $users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];
                    $userCount = count($users);
                    echo $userCount;
                    ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-database"></i> Storage Used</div>
                <div class="stat-value">
                    <?php
                    $totalSize = 0;
                    foreach ($users as $username => $hash) {
                        $userDir = "/var/www/html/webdav/users/" . $username;
                        if (is_dir($userDir)) {
                            $totalSize += getDirSize($userDir);
                        }
                    }
                    echo round($totalSize / (1024 * 1024 * 1024), 2) . " GB";
                    ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-title"><i class="fas fa-server"></i> Total Storage</div>
                <div class="stat-value">
                    <?php
                    $totalStorage = disk_total_space("/var/www/html/webdav");
                    echo round($totalStorage / (1024 * 1024 * 1024), 2) . " GB";
                    ?>
                </div>
            </div>
        </div>

        <div class="users-section">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Storage</th>
                        <th>Files</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $username => $hash): ?>
                        <?php
                        $userDir = "/var/www/html/webdav/users/" . $username;
                        $userSize = is_dir($userDir) ? getDirSize($userDir) : 0;
                        $userSizeGB = round($userSize / (1024 * 1024 * 1024), 2);
                        $fileCount = is_dir($userDir) ? iterator_count(new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS)
                        )) : 0;
                        $storagePercentage = $totalSize > 0 ? ($userSize / $totalSize) * 100 : 0;
                        ?>
                        <tr>
                            <td data-label="User"><?php echo htmlspecialchars($username); ?></td>
                            <td data-label="Storage" class="user-storage">
                                <?php echo $userSizeGB; ?> GB
                                <div class="storage-bar">
                                    <div class="storage-fill" style="width: <?php echo $storagePercentage; ?>%"></div>
                                </div>
                            </td>
                            <td data-label="Files"><?php echo $fileCount; ?></td>
                            <td data-label="Actions">
                                <form method="POST" onsubmit="return confirm('Permanently delete user <?php echo htmlspecialchars($username); ?>?');">
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
    </div>
</body>
</html>
