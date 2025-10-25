<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include __DIR__ . '/db.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user']['id'];

// Handle clear all notifications
if (isset($_POST['clear_all'])) {
    $clearQuery = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
    $clearStmt = $conn->prepare($clearQuery);
    if ($clearStmt) {
        $clearStmt->bind_param("i", $userId);
        $clearStmt->execute();
        $clearStmt->close();
    }
    header('Location: notifications.php');
    exit();
}

// Handle delete a single notification (owned by user)
if (isset($_POST['delete_id']) && is_numeric($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    $delStmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    if ($delStmt) {
        $delStmt->bind_param("ii", $delId, $userId);
        $delStmt->execute();
        $delStmt->close();
    }
    header('Location: notifications.php');
    exit();
}

// Handle delete all notifications for this user
if (isset($_POST['delete_all'])) {
    $delAllStmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    if ($delAllStmt) {
        $delAllStmt->bind_param("i", $userId);
        $delAllStmt->execute();
        $delAllStmt->close();
    }
    header('Location: notifications.php');
    exit();
}

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notifId = (int)$_GET['mark_read'];
    $markQuery = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $markStmt = $conn->prepare($markQuery);
    if ($markStmt) {
        $markStmt->bind_param("ii", $notifId, $userId);
        $markStmt->execute();
        $markStmt->close();
    }
    header('Location: notifications.php');
    exit();
}

// Create notifications table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    related_id INT NULL,
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($createTableQuery);

// Function to create sample notifications if none exist
function createSampleNotifications($conn, $userId) {
    $checkQuery = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    if ($checkStmt) {
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['count'] == 0) {
            $sampleNotifications = [
                ['appointment_approved', 'Appointment Approved', 'Your brake service appointment has been approved for September 24, 2025 at 09:00 AM.', 1],
                ['service_reminder', 'Service Reminder', 'Your vehicle is due for PMS (Preventive Maintenance Service). Book an appointment today!', null],
                ['appointment_pending', 'Appointment Pending', 'Your oil change appointment is pending approval. We will notify you once confirmed.', 2],
                ['promotion', 'Special Offer', 'Get 20% off on Air Filter Replacement this month! Limited time offer.', null],
                ['service_completed', 'Service Completed', 'Your wheel alignment service has been completed. Thank you for choosing our service!', 3]
            ];
            
            $insertQuery = "INSERT INTO notifications (user_id, type, title, message, related_id) VALUES (?, ?, ?, ?, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            
            if ($insertStmt) {
                foreach ($sampleNotifications as $notification) {
                    $insertStmt->bind_param("isssi", $userId, $notification[0], $notification[1], $notification[2], $notification[3]);
                    $insertStmt->execute();
                }
                $insertStmt->close();
            }
        }
    }
}

// Optional: seed sample notifications only when explicitly requested
// Visit notifications.php?seed=1 once if you want demo data
if (isset($_GET['seed']) && $_GET['seed'] === '1') {
    createSampleNotifications($conn, $userId);
    header('Location: notifications.php');
    exit();
}

// Get all notifications for the user
$notifications = [];
$query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($query);

if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $notification) {
    if (!$notification['is_read']) {
        $unreadCount++;
    }
}

// Function to get notification icon
function getNotificationIcon($type) {
    $icons = [
        'appointment_approved' => 'fas fa-check-circle',
        'appointment_pending' => 'fas fa-clock',
        'appointment_canceled' => 'fas fa-times-circle',
        'service_reminder' => 'fas fa-wrench',
        'service_completed' => 'fas fa-thumbs-up',
        'promotion' => 'fas fa-tag',
        'system' => 'fas fa-cog',
        'payment' => 'fas fa-credit-card'
    ];
    return isset($icons[$type]) ? $icons[$type] : 'fas fa-bell';
}

// Function to get notification color
function getNotificationColor($type) {
    $colors = [
        'appointment_approved' => '#28a745',
        'appointment_pending' => '#ffc107',
        'appointment_canceled' => '#dc3545',
        'service_reminder' => '#17a2b8',
        'service_completed' => '#28a745',
        'promotion' => '#f0c040',
        'system' => '#6c757d',
        'payment' => '#17a2b8'
    ];
    return isset($colors[$type]) ? $colors[$type] : '#6c757d';
}

// Function to format time ago
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Vehicle Service</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #f0c040;
            --primary-dark: #d4a830;
            --dark: #222;
            --light: #f8f9fa;
            --gray: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --muted: #868e96;
        }
        
        body {
            background: url('33.png') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Segoe UI', sans-serif;
            color: white;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.75);
            z-index: -1;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            padding: 12px 20px;
            background: var(--primary);
            color: var(--dark);
            border-radius: 30px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            border: none;
            cursor: pointer;
        }
        
        .back-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        }
        
        .back-button i {
            margin-right: 8px;
        }
        
        .page-title {
            flex: 1;
            text-align: center;
        }
        
        .page-title h1 {
            font-size: 2.5rem;
            color: var(--primary);
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            position: relative;
        }
        
        .notification-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .notifications-header {
            background: rgba(0,0,0,0.6);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .notifications-stats {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            background: rgba(255,255,255,0.1);
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .clear-all-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .clear-all-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .notification-item {
            background: rgba(0,0,0,0.7);
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: rgba(0,0,0,0.8);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.4);
        }
        
        .notification-item.unread {
            background: rgba(240, 192, 64, 0.1);
            border-left-color: var(--primary);
        }
        
        .notification-item.unread::before {
            content: "";
            position: absolute;
            top: 15px;
            right: 15px;
            width: 10px;
            height: 10px;
            background: var(--primary);
            border-radius: 50%;
        }
        
        .notification-content {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        
        .notification-text {
            flex: 1;
        }
        
        .notification-title {
            font-size: 1.1rem;
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--light);
        }
        
        .notification-message {
            color: #ddd;
            line-height: 1.5;
            margin-bottom: 8px;
        }
        
        .notification-message a {
            color: var(--primary);
            font-weight:600;
            text-decoration: underline;
            transition: color .2s ease, background .2s ease;
        }
        .notification-message a:visited {
            color: var(--primary);
        }
        .notification-message a:hover, .notification-message a:focus {
            color:#fff;
            background:rgba(240,192,64,0.15);
            border-radius:3px;
            outline:none;
        }
        
        .notification-time {
            color: var(--muted);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-small {
            padding: 5px 12px;
            font-size: 0.8rem;
            border-radius: 15px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-primary-small {
            background: var(--primary);
            color: var(--dark);
        }
        
        .btn-outline-small {
            background: transparent;
            border: 1px solid var(--muted);
            color: var(--muted);
        }
        
        .btn-small:hover {
            transform: translateY(-1px);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: rgba(0,0,0,0.5);
            border-radius: 12px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--muted);
            margin-bottom: 20px;
            display: block;
        }
        
        .empty-state h3 {
            color: var(--light);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--muted);
            margin-bottom: 25px;
        }
        
        .filter-tabs {
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
            background: rgba(0,0,0,0.5);
            border-radius: 30px;
            padding: 5px;
            gap: 5px;
        }
        
        .filter-tab {
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: white;
            border: none;
            background: transparent;
        }
        
        .filter-tab.active {
            background: var(--primary);
            color: var(--dark);
        }
        
        .filter-tab:hover:not(.active) {
            background: rgba(255,255,255,0.1);
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .notification-item {
            animation: slideInDown 0.3s ease;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .page-title h1 {
                font-size: 2rem;
            }
            
            .notifications-header {
                flex-direction: column;
                text-align: center;
            }
            
            .notifications-stats {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .notification-content {
                flex-direction: column;
                gap: 10px;
            }
            
            .notification-icon {
                align-self: flex-start;
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
            
            .filter-tabs {
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
            
            <div class="page-title">
                <h1>
                    <i class="fas fa-bell"></i> Notifications
                    <?php if ($unreadCount > 0): ?>
                        <span class="notification-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </h1>
            </div>
        </div>
        
        <div class="notifications-header">
            <div class="notifications-stats">
                <div class="stat-item">
                    <i class="fas fa-inbox"></i>
                    <span><?= count($notifications) ?> Total</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-circle" style="color: var(--primary);"></i>
                    <span><?= $unreadCount ?> Unread</span>
                </div>
            </div>
            
            <div style="display:flex; gap:10px; align-items:center;">
                <?php if ($unreadCount > 0): ?>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="clear_all" class="clear-all-btn">
                            <i class="fas fa-check-double"></i> Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (count($notifications) > 0): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Clear all notifications? This cannot be undone.');">
                        <button type="submit" name="delete_all" class="clear-all-btn" style="background:#ff6b6b;">
                            <i class="fas fa-trash"></i> Clear All
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="filter-tabs">
            <button class="filter-tab active" onclick="filterNotifications('all')">
                <i class="fas fa-list"></i> All
            </button>
            <button class="filter-tab" onclick="filterNotifications('unread')">
                <i class="fas fa-circle"></i> Unread
            </button>
            <button class="filter-tab" onclick="filterNotifications('appointments')">
                <i class="fas fa-calendar"></i> Appointments
            </button>
            <button class="filter-tab" onclick="filterNotifications('promotions')">
                <i class="fas fa-tag"></i> Promotions
            </button>
        </div>
        
        <div class="notifications-list" id="notificationsList">
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= !$notification['is_read'] ? 'unread' : '' ?>" 
                         data-type="<?= $notification['type'] ?>"
                         data-read="<?= $notification['is_read'] ?>">
                        <div class="notification-content">
                            <div class="notification-icon" 
                                 style="background: <?= getNotificationColor($notification['type']) ?>20; 
                                        color: <?= getNotificationColor($notification['type']) ?>;">
                                <i class="<?= getNotificationIcon($notification['type']) ?>"></i>
                            </div>
                            
                            <div class="notification-text">
                                <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                <div class="notification-message"><?php
                                    // Allow limited safe tags in message
                                    $allowed = '<a><strong><em><b><i><u><br>';
                                    echo strip_tags($notification['message'], $allowed);
                                ?></div>
                                <div class="notification-time">
                                    <i class="fas fa-clock"></i>
                                    <?= timeAgo($notification['created_at']) ?>
                                </div>
                                
                                <div class="notification-actions">
                                    <?php if (!$notification['is_read']): ?>
                                        <a href="?mark_read=<?= $notification['id'] ?>" class="btn-small btn-primary-small">
                                            <i class="fas fa-check"></i> Mark as Read
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($notification['type'] === 'appointment_approved' && $notification['related_id']): ?>
                                        <a href="myappointments.php?tab=approved" class="btn-small btn-outline-small">
                                            <i class="fas fa-eye"></i> View Appointment
                                        </a>
                                    <?php elseif ($notification['type'] === 'receipt' && $notification['related_id']): ?>
                                        <a href="receipt.php?appointment_id=<?= (int)$notification['related_id'] ?>" class="btn-small btn-outline-small">
                                            <i class="fas fa-receipt"></i> View Receipt
                                        </a>
                                    <?php elseif ($notification['type'] === 'service_reminder'): ?>
                                        <a href="index.php#appointment" class="btn-small btn-outline-small">
                                            <i class="fas fa-calendar-plus"></i> Book Service
                                        </a>
                                    <?php endif; ?>

                                    <!-- Delete single notification -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?');">
                                        <input type="hidden" name="delete_id" value="<?= $notification['id'] ?>">
                                        <button type="submit" class="btn-small btn-outline-small" style="border-color:#ff6b6b; color:#ff6b6b;">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No Notifications Yet</h3>
                    <p>You'll see updates about your appointments and services here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function filterNotifications(filter) {
            const notifications = document.querySelectorAll('.notification-item');
            const tabs = document.querySelectorAll('.filter-tab');
            
            // Update active tab
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            // Filter notifications
            notifications.forEach(notification => {
                let show = false;
                
                switch(filter) {
                    case 'all':
                        show = true;
                        break;
                    case 'unread':
                        show = notification.dataset.read === '0';
                        break;
                    case 'appointments':
                        show = notification.dataset.type.includes('appointment');
                        break;
                    case 'promotions':
                        show = notification.dataset.type === 'promotion';
                        break;
                }
                
                notification.style.display = show ? 'block' : 'none';
            });
        }
        
        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            // You can implement AJAX refresh here if needed
        }, 30000);
        
        // Mark notification as read when clicked
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (e.target.closest('.btn-small')) return;
                
                if (this.classList.contains('unread')) {
                    const notificationId = this.querySelector('[href*="mark_read"]')?.href.split('=')[1];
                    if (notificationId) {
                        window.location.href = `?mark_read=${notificationId}`;
                    }
                }
            });
        });
    </script>
</body>
</html>
