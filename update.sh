<?php
session_start();

// Check for admin password in session or verify it
if (!isset($_SESSION['admin']) && (!isset($_POST['password']) || $_POST['password'] !== '123')) {
    header("Location: /selfhostedgdrive/index.php");
    exit;
} else if (isset($_POST['password']) && $_POST['password'] === '123') {
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
    
    // Network Stats
    $network_in = 0;
    $network_out = 0;
    
    // Try different network interfaces
    $interfaces = ['eth0', 'ens33', 'ens160', 'wlan0', 'wifi0'];
    foreach ($interfaces as $interface) {
        $rx_file = "/sys/class/net/$interface/statistics/rx_bytes";
        $tx_file = "/sys/class/net/$interface/statistics/tx_bytes";
        
        if (file_exists($rx_file) && file_exists($tx_file)) {
            $network_in = (int)file_get_contents($rx_file);
            $network_out = (int)file_get_contents($tx_file);
            break;
        }
    }
    
    $stats['network_in'] = $network_in;
    $stats['network_out'] = $network_out;
    
    // System Uptime
    $stats['uptime'] = shell_exec("uptime -p");
    
    return $stats;
}

// Function to get file type statistics
function getFileTypeStats($dir) {
    $stats = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!isset($stats[$ext])) {
                $stats[$ext] = ['count' => 0, 'size' => 0];
            }
            $stats[$ext]['count']++;
            $stats[$ext]['size'] += $file->getSize();
        }
    }
    
    return $stats;
}

// Function to get activity logs
function getActivityLogs() {
    $logFile = __DIR__ . '/activity.log';
    if (file_exists($logFile)) {
        return array_slice(file($logFile), -50); // Get last 50 lines
    }
    return [];
}

// Handle AJAX request for system stats
if (isset($_GET['get_stats'])) {
    header('Content-Type: application/json');
    echo json_encode(getSystemStats());
    exit;
}

// Handle user management
if (isset($_POST['action'])) {
    $usersFile = __DIR__ . '/users.json';
    $users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];
    
    switch ($_POST['action']) {
        case 'add_user':
            if (!empty($_POST['username']) && !empty($_POST['password'])) {
                $users[$_POST['username']] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
                // Create user directory
                $userDir = "/var/www/html/webdav/users/" . $_POST['username'];
                if (!is_dir($userDir)) {
                    mkdir($userDir, 0755, true);
                }
            }
            break;
            
        case 'edit_user':
            if (!empty($_POST['username']) && !empty($_POST['new_password'])) {
                $users[$_POST['username']] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            }
            break;
            
        case 'delete_user':
            if (!empty($_POST['username'])) {
                $userToDelete = $_POST['username'];
                $userDir = "/var/www/html/webdav/users/" . $userToDelete;
                
                // Remove user from users.json
                if (isset($users[$userToDelete])) {
                    unset($users[$userToDelete]);
                    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
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
                    rmdir($userDir);
                }
            }
            break;
    }
    
    header("Location: /selfhostedgdrive/console.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Advanced Admin Console</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <style>
        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #1a1a1a;
            --bg-tertiary: #2a2a2a;
            --accent-primary: #dc2626;
            --accent-secondary: #991b1b;
            --accent-success: #22c55e;
            --accent-warning: #f59e0b;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-weight: 700;
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--accent-primary);
            color: white;
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
        }

        .card {
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-icon {
            font-size: 1.5rem;
            color: var(--accent-primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 0.5rem;
        }

        .stat-subtitle {
            font-size: 0.9rem;
            color: var(--text-secondary);
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

        .tabs {
            display: flex;
            gap: 1rem;
            padding: 0 2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .tab {
            padding: 1rem 2rem;
            cursor: pointer;
            color: var(--text-secondary);
            font-weight: 500;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
        }

        .tab.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
        }

        .tab-content {
            padding: 2rem;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .users-table th {
            padding: 1.2rem;
            text-align: left;
            font-weight: 600;
            color: var(--accent-primary);
            background: rgba(220, 38, 38, 0.1);
        }

        .users-table td {
            padding: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--bg-secondary);
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .activity-log {
            max-height: 400px;
            overflow-y: auto;
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            padding: 1rem;
        }

        .log-entry {
            padding: 0.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.9rem;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .file-type-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .file-type-item {
            background: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .file-type-icon {
            font-size: 2rem;
            color: var(--accent-primary);
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .header-actions {
                width: 100%;
                justify-content: center;
            }

            .users-table {
                display: block;
                overflow-x: auto;
            }
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

                // Update Network
                if (data.network_in > 0 && data.network_out > 0) {
                    $('#network-in').text(formatBytes(data.network_in));
                    $('#network-out').text(formatBytes(data.network_out));
                } else {
                    $('#network-in').text('N/A');
                    $('#network-out').text('N/A');
                }

                // Update Uptime
                $('#uptime').text(data.uptime);
            });
        }

        function showModal(modalId) {
            $('#' + modalId).fadeIn();
        }

        function hideModal(modalId) {
            $('#' + modalId).fadeOut();
        }

        function switchTab(tabId) {
            $('.tab').removeClass('active');
            $('#' + tabId).addClass('active');
            $('.tab-content').hide();
            $('#' + tabId + '-content').show();
        }

        // Update stats every 5 seconds
        $(document).ready(function() {
            updateSystemStats();
            setInterval(updateSystemStats, 5000);
            
            // Initialize tabs
            switchTab('dashboard-tab');
        });
    </script>
</head>
<body>
    <div class="admin-container">
        <div class="header">
            <h1><i class="fas fa-terminal"></i> Advanced Storage Admin Console</h1>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="showModal('add-user-modal')">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
                <button class="btn btn-primary" onclick="showModal('backup-modal')">
                    <i class="fas fa-download"></i> Backup
                </button>
            </div>
        </div>

        <div class="tabs">
            <div id="dashboard-tab" class="tab active" onclick="switchTab('dashboard-tab')">
                <i class="fas fa-chart-line"></i> Dashboard
            </div>
            <div id="users-tab" class="tab" onclick="switchTab('users-tab')">
                <i class="fas fa-users"></i> Users
            </div>
            <div id="activity-tab" class="tab" onclick="switchTab('activity-tab')">
                <i class="fas fa-history"></i> Activity
            </div>
            <div id="files-tab" class="tab" onclick="switchTab('files-tab')">
                <i class="fas fa-file-alt"></i> Files
            </div>
            <div id="settings-tab" class="tab" onclick="switchTab('settings-tab')">
                <i class="fas fa-cog"></i> Settings
            </div>
        </div>

        <div id="dashboard-tab-content" class="tab-content">
            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">CPU Usage</div>
                        <div class="card-icon"><i class="fas fa-microchip"></i></div>
                    </div>
                    <div class="stat-value" id="cpu-value">0%</div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="cpu-bar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">RAM Usage</div>
                        <div class="card-icon"><i class="fas fa-memory"></i></div>
                    </div>
                    <div class="stat-value">
                        <span id="ram-value">0 GB</span>
                        <span class="stat-subtitle" id="ram-total">/ 0 GB</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="ram-bar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Disk Usage</div>
                        <div class="card-icon"><i class="fas fa-hdd"></i></div>
                    </div>
                    <div class="stat-value">
                        <span id="disk-value">0 GB</span>
                        <span class="stat-subtitle" id="disk-total">/ 0 GB</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="disk-bar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Network Traffic</div>
                        <div class="card-icon"><i class="fas fa-network-wired"></i></div>
                    </div>
                    <div class="stat-value">
                        <div>In: <span id="network-in">0 B</span></div>
                        <div>Out: <span id="network-out">0 B</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">System Uptime</div>
                        <div class="card-icon"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="stat-value" id="uptime">Loading...</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Total Users</div>
                        <div class="card-icon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php
                        $usersFile = __DIR__ . '/users.json';
                        $users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];
                        echo count($users);
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div id="users-tab-content" class="tab-content" style="display: none;">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Files</th>
                        <th>Last Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $username => $hash): ?>
                        <?php
                        $userDir = "/var/www/html/webdav/users/" . $username;
                        $fileCount = is_dir($userDir) ? iterator_count(new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS)
                        )) : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($username); ?></td>
                            <td><?php echo $fileCount; ?> files</td>
                            <td>Never</td>
                            <td>
                                <button class="btn btn-secondary" onclick="showModal('edit-user-modal')">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                                    <button type="submit" class="btn btn-secondary" onclick="return confirm('Delete user <?php echo htmlspecialchars($username); ?>?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="activity-tab-content" class="tab-content" style="display: none;">
            <div class="activity-log">
                <?php foreach (getActivityLogs() as $log): ?>
                    <div class="log-entry">
                        <?php echo htmlspecialchars($log); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="files-tab-content" class="tab-content" style="display: none;">
            <div class="file-type-stats">
                <?php
                $fileStats = getFileTypeStats("/var/www/html/webdav");
                foreach ($fileStats as $ext => $stats):
                    $icon = match($ext) {
                        'jpg', 'jpeg', 'png', 'gif' => 'fa-image',
                        'pdf' => 'fa-file-pdf',
                        'doc', 'docx' => 'fa-file-word',
                        'xls', 'xlsx' => 'fa-file-excel',
                        'zip', 'rar' => 'fa-file-archive',
                        default => 'fa-file'
                    };
                ?>
                    <div class="file-type-item">
                        <div class="file-type-icon">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="file-type-name"><?php echo strtoupper($ext); ?></div>
                        <div class="file-type-count"><?php echo $stats['count']; ?> files</div>
                        <div class="file-type-size"><?php echo round($stats['size'] / (1024 * 1024), 2); ?> MB</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="settings-tab-content" class="tab-content" style="display: none;">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">System Settings</div>
                </div>
                <form method="POST">
                    <div class="form-group">
                        <label>Maximum File Size (MB)</label>
                        <input type="number" name="max_file_size" value="100">
                    </div>
                    <div class="form-group">
                        <label>Allowed File Types</label>
                        <input type="text" name="allowed_types" value="jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip,rar">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="add-user-modal" class="modal">
        <div class="modal-content">
            <h2>Add New User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Add User</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('add-user-modal')">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="edit-user-modal" class="modal">
        <div class="modal-content">
            <h2>Edit User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password">
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('edit-user-modal')">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Backup Modal -->
    <div id="backup-modal" class="modal">
        <div class="modal-content">
            <h2>Backup System</h2>
            <form method="POST">
                <input type="hidden" name="action" value="backup">
                <div class="form-group">
                    <label>Backup Type</label>
                    <select name="backup_type">
                        <option value="full">Full Backup</option>
                        <option value="incremental">Incremental Backup</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Destination</label>
                    <input type="text" name="backup_destination" value="/backup">
                </div>
                <button type="submit" class="btn btn-primary">Start Backup</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('backup-modal')">Cancel</button>
            </form>
        </div>
    </div>
</body>
</html>
