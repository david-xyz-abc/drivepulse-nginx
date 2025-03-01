<?php
session_start();

// ... [PHP code remains the same as original] ...

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
            --background: #0f0f0f;
            --text-color: rgba(255, 255, 255, 0.92);
            --content-bg: rgba(255, 255, 255, 0.05);
            --border-color: rgba(255, 255, 255, 0.1);
            --accent: #FF4D4D;
            --accent-gradient: linear-gradient(135deg, #FF4D4D 0%, #F9CB28 100%);
            --glass-bg: rgba(255, 255, 255, 0.05);
        }
        
        body {
            background: var(--background);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 2rem;
            min-height: 100vh;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .console-container {
            background: var(--content-bg);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
            animation: fadeIn 0.6s cubic-bezier(0.23, 1, 0.32, 1);
            border: 1px solid var(--border-color);
        }
        
        h1 {
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
            font-size: 2.5rem;
            margin-bottom: 2rem;
            position: relative;
            padding-left: 1rem;
        }

        h1::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 80%;
            background: var(--accent);
            border-radius: 4px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .stat-box {
            background: var(--glass-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: blur(8px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }
        
        .stat-box h3 {
            margin: 0 0 1rem 0;
            color: var(--text-color);
            font-weight: 500;
            font-size: 1rem;
            opacity: 0.8;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .user-card {
            background: var(--glass-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: blur(8px);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .user-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .user-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.05), transparent);
            transform: rotate(45deg);
            pointer-events: none;
        }

        .user-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: var(--accent-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }

        .user-avatar i {
            color: white;
            font-size: 1.2rem;
        }

        .user-name {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .storage-info {
            margin-bottom: 1.5rem;
        }

        .storage-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .storage-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }

        @keyframes barAnimation {
            0% { background-position: 100% 0; }
            100% { background-position: 0 0; }
        }

        .storage-used {
            height: 100%;
            background: var(--accent-gradient);
            width: <?php echo $storagePercentage; ?>%;
            border-radius: 4px;
            transition: width 0.6s cubic-bezier(0.23, 1, 0.32, 1);
            background-size: 200% 100%;
            animation: barAnimation 2s linear infinite;
        }

        .file-count {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .file-count i {
            margin-right: 0.5rem;
            color: var(--accent);
        }

        .delete-btn {
            background: rgba(255, 77, 77, 0.15);
            color: var(--accent);
            border: 1px solid var(--accent);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            margin-top: 1.5rem;
            width: 100%;
            justify-content: center;
        }

        .delete-btn i {
            margin-right: 0.5rem;
        }

        .delete-btn:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.05);
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .console-container {
                border-radius: 16px;
                padding: 1.5rem;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="console-container">
        <h1>Admin Dashboard</h1>
        
        <div class="stats-container">
            <!-- ... [Stats boxes same as before] ... -->
        </div>

        <div class="users-grid">
            <?php foreach ($users as $userDir): ?>
                <?php
                // ... [PHP user data processing] ... 
                ?>
                <div class="user-card">
                    <div class="user-header">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                    </div>
                    
                    <div class="storage-info">
                        <div class="storage-label">
                            <span>Storage Used</span>
                            <span><?php echo $userSizeGB; ?> GB</span>
                        </div>
                        <div class="storage-bar">
                            <div class="storage-used"></div>
                        </div>
                    </div>
                    
                    <div class="file-count">
                        <i class="fas fa-file"></i>
                        <?php echo $fileCount; ?> Files
                    </div>
                    
                    <form method="POST" onsubmit="return confirmDeletion()">
                        <input type="hidden" name="delete_user" value="<?php echo htmlspecialchars($username); ?>">
                        <button type="submit" class="delete-btn">
                            <i class="fas fa-trash"></i> Delete Account
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function confirmDeletion() {
            return Swal.fire({
                title: 'Delete User?',
                text: "This will permanently delete all user data!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#FF4D4D',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Delete',
                background: '#1a1a1a',
                customClass: {
                    title: 'swal-title',
                    content: 'swal-text'
                }
            }).then((result) => {
                return result.isConfirmed;
            });
        }

        // Add animation to user cards on load
        document.querySelectorAll('.user-card').forEach((card, index) => {
            card.style.animation = `fadeIn 0.5s ease ${index * 0.1}s forwards`;
            card.style.opacity = 0;
        });
    </script>
    <script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>