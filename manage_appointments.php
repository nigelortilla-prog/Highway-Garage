<?php
session_start();

// --- Simple admin login with slight security improvement ---
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
        if ($_POST['username'] === 'admin' && $_POST['password'] === 'admin') {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_last_activity'] = time();
            header("Location: manage_appointments.php");
            exit();
        } else {
            $login_error = "Invalid username or password.";
        }
    }
    // Show login form and exit
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Admin Login | Vehicle Service Center</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { 
                background: linear-gradient(135deg, #1c1c1c 0%, #323232 100%);
                color: #fff; 
                font-family: 'Segoe UI', Arial, sans-serif;
                margin: 0;
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-box {
                background: rgba(40, 40, 40, 0.9);
                padding: 35px;
                border-radius: 15px;
                width: 360px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.5);
                border: 1px solid rgba(255,255,255,0.1);
            }
            h2 {
                margin-top: 0;
                color: #f0c040;
                text-align: center;
                font-size: 28px;
                margin-bottom: 25px;
            }
            .input-group {
                position: relative;
                margin-bottom: 20px;
            }
            input[type="text"], input[type="password"] {
                width: 100%;
                padding: 12px;
                padding-left: 40px;
                border-radius: 8px;
                border: 1px solid #444;
                background: rgba(0,0,0,0.3);
                color: #fff;
                font-size: 16px;
                transition: all 0.3s;
                box-sizing: border-box;
            }
            input:focus {
                border-color: #f0c040;
                outline: none;
                box-shadow: 0 0 0 2px rgba(240,192,64,0.2);
            }
            .input-icon {
                position: absolute;
                left: 12px;
                top: 12px;
                color: #f0c040;
                font-size: 18px;
            }
            button { 
                width: 100%;
                padding: 12px;
                background: #f0c040;
                color: #222;
                font-weight: bold;
                border-radius: 8px;
                border: none;
                cursor: pointer;
                font-size: 16px;
                transition: all 0.3s;
            }
            button:hover {
                background: #ffd966;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            }
            button:active {
                transform: translateY(0);
            }
            .error { 
                color: #ff6b6b;
                text-align: center;
                margin-bottom: 20px;
                background: rgba(255,107,107,0.1);
                padding: 10px;
                border-radius: 5px;
                border-left: 4px solid #ff6b6b;
            }
            .logo {
                text-align: center;
                margin-bottom: 20px;
            }
            .logo span {
                display: inline-block;
                font-size: 32px;
                font-weight: bold;
                color: #f0c040;
                background: rgba(0,0,0,0.2);
                width: 60px;
                height: 60px;
                line-height: 60px;
                border-radius: 50%;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <div class="logo">
                <span>VS</span>
            </div>
            <h2>Admin Panel</h2>
            <?php if (!empty($login_error)) echo "<div class='error'><i class='fas fa-exclamation-circle'></i> $login_error</div>"; ?>
            <form method="POST">
                <div class="input-group">
                    <span class="input-icon">👤</span>
                    <input type="text" name="username" placeholder="Username" required autofocus>
                </div>
                <div class="input-group">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" placeholder="Password" required>
                </div>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Admin session timeout after 30 minutes
if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header("Location: manage_appointments.php");
    exit();
}
$_SESSION['admin_last_activity'] = time();

include __DIR__ . '/db.php';
$maxSlotsPerTime = 5; // max mechanics per timeslot

// Initialize message variable
$msg = "";
$msgType = "success";

// Ensure columns for cancellation metadata exist
$colCancelReason = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_reason'");
if ($colCancelReason && $colCancelReason->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_reason TEXT NULL");
}
$colCancelDate = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_date'");
if ($colCancelDate && $colCancelDate->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_date DATE NULL");
}
// Ensure soft delete columns exist
$colIsDeleted = $conn->query("SHOW COLUMNS FROM appointments LIKE 'is_deleted'");
if ($colIsDeleted && $colIsDeleted->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
}
$colDeletedAt = $conn->query("SHOW COLUMNS FROM appointments LIKE 'deleted_at'");
if ($colDeletedAt && $colDeletedAt->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN deleted_at DATETIME NULL");
}
// Ensure columns to persist the notification snapshot at cancel time
$colCnTitle = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_notice_title'");
if ($colCnTitle && $colCnTitle->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_notice_title VARCHAR(255) NULL");
}
$colCnMsg = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_notice_message'");
if ($colCnMsg && $colCnMsg->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_notice_message TEXT NULL");
}
$colCnAt = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_notice_at'");
if ($colCnAt && $colCnAt->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_notice_at DATETIME NULL");
}
// Ensure recommended time column exists
$colRebookTime = $conn->query("SHOW COLUMNS FROM appointments LIKE 'rebook_recommended_time'");
if ($colRebookTime && $colRebookTime->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN rebook_recommended_time VARCHAR(20) NULL");
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle CRUD operations
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Create new appointment
        if ($action === 'create_appointment') {
            $userName = $_POST['user_name'];
            $userEmail = $_POST['user_email'];
            $date = $_POST['date'];
            $time = $_POST['time'];
            $car = $_POST['car'];
            $service = $_POST['service'];
            
            // Find or create user
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $userEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $userId = $user['id'];
            } else {
                // Create user with temporary password
                $tempPassword = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $userName, $userEmail, $tempPassword);
                $stmt->execute();
                $userId = $conn->insert_id;
            }
            $stmt->close();
            
            // Create appointment
            $stmt = $conn->prepare("INSERT INTO appointments (user_id, name, date, time, car, service) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $userId, $userName, $date, $time, $car, $service);
            $stmt->execute();
            $stmt->close();
            
            $msg = "✅ New appointment created successfully!";
            $msgType = "success";
        }
        // Delete appointment
        else if ($action === 'delete') {
            $apptId = intval($_POST['line']);
            // Soft delete: mark as deleted
            $stmt = $conn->prepare("UPDATE appointments SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $stmt->close();

            $msg = "🗑️ Appointment #$apptId moved to Deleted.";
            $msgType = "success";
        } 
        // Update appointment status
        else if ($action === 'status') {
            $apptId = intval($_POST['line']);
            $newStatus = $_POST['status'];
            $createReceipt = isset($_POST['create_receipt']) ? (int)$_POST['create_receipt'] : 0;
            
            // Get appointment and user details first
            $getApptDetails = $conn->prepare("SELECT a.*, u.name as customer_name, u.email as customer_email FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
            $getApptDetails->bind_param("i", $apptId);
            $getApptDetails->execute();
            $apptDetails = $getApptDetails->get_result()->fetch_assoc();
            $getApptDetails->close();
            
            // Update appointment status (capture cancel reason/date if canceled)
            if (strtolower($newStatus) === 'canceled') {
                $cancelReason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : null;
                $stmt = $conn->prepare("UPDATE appointments SET status = 'canceled', cancel_reason = ?, cancel_date = CURDATE() WHERE id = ?");
                $stmt->bind_param("si", $cancelReason, $apptId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $newStatus, $apptId);
                $stmt->execute();
                $stmt->close();
            }
            
            // AUTO-CREATE VEHICLE MONITORING DATA WHEN COMPLETED
            if ($newStatus === 'completed' && $apptDetails) {
                // Create monitoring tables if they don't exist
                $createTables = [
                    "CREATE TABLE IF NOT EXISTS vehicle_health_records (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        appointment_id INT,
                        vehicle_info VARCHAR(500) NOT NULL,
                        service_type VARCHAR(100) NOT NULL,
                        service_date DATE NOT NULL,
                        next_service_due DATE,
                        mileage_at_service INT,
                        health_score INT DEFAULT 85,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
                    )",
                    "CREATE TABLE IF NOT EXISTS vehicle_performance (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        vehicle_info VARCHAR(500) NOT NULL,
                        fuel_efficiency DECIMAL(5,2),
                        engine_performance INT DEFAULT 90,
                        brake_efficiency INT DEFAULT 95,
                        overall_condition INT DEFAULT 88,
                        recorded_date DATE NOT NULL,
                        notes TEXT,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )",
                    "CREATE TABLE IF NOT EXISTS vehicle_alerts (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        vehicle_info VARCHAR(500) NOT NULL,
                        alert_type ENUM('maintenance_due', 'inspection_required', 'warranty_expiring', 'performance_issue') DEFAULT 'maintenance_due',
                        alert_message TEXT NOT NULL,
                        severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                        due_date DATE,
                        is_resolved BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )"
                ];
                
                foreach ($createTables as $sql) {
                    $conn->query($sql);
                }
                
                // Calculate next service date
                $serviceIntervals = [
                    'OIL CHANGE' => 90,
                    'PMS' => 180,
                    'BRAKE CHECK' => 365,
                    'AIRCON' => 180,
                    'CHECK ENGINE' => 120,
                    'WHEEL ALIGNMENT' => 180,
                    'AIR FILTER' => 90,
                    'CHECK WIRING' => 365
                ];
                
                $intervalDays = $serviceIntervals[normalizeServiceName($apptDetails['service'])] ?? 120;
                $nextServiceDate = date('Y-m-d', strtotime($apptDetails['date'] . ' + ' . $intervalDays . ' days'));
                
                // Monitoring data: prefer user's odometer if provided; otherwise generate realistic fallback
                $healthScore = rand(82, 96);
                if (isset($apptDetails['odometer']) && $apptDetails['odometer'] !== null && $apptDetails['odometer'] !== '') {
                    $mileage = (int)$apptDetails['odometer'];
                } else {
                    $mileage = rand(15000, 80000);
                }
                // Removed performance metric randomization; we only track Health Score + Notes
                
                // Upsert health record by appointment_id to keep it unique
                $checkVhr = $conn->prepare("SELECT id FROM vehicle_health_records WHERE appointment_id = ?");
                $checkVhr->bind_param("i", $apptId);
                $checkVhr->execute();
                $vhrRes = $checkVhr->get_result();
                $existingVhr = $vhrRes ? $vhrRes->fetch_assoc() : null;
                $checkVhr->close();

                if ($existingVhr) {
                    // Preserve any manually edited health_score; do not overwrite on update
                    $updRecord = $conn->prepare(
                        "UPDATE vehicle_health_records
                         SET user_id = ?, vehicle_info = ?, service_type = ?, service_date = ?, next_service_due = ?, mileage_at_service = ?
                         WHERE id = ?"
                    );
                    $updRecord->bind_param(
                        "issssii",
                        $apptDetails['user_id'], $apptDetails['car'], $apptDetails['service'],
                        $apptDetails['date'], $nextServiceDate, $mileage, $existingVhr['id']
                    );
                    $updRecord->execute();
                    $updRecord->close();
                } else {
                    $insertRecord = $conn->prepare("
                        INSERT INTO vehicle_health_records 
                        (user_id, appointment_id, vehicle_info, service_type, service_date, next_service_due, mileage_at_service, health_score) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertRecord->bind_param("iissssii", 
                        $apptDetails['user_id'], $apptId, $apptDetails['car'], $apptDetails['service'], 
                        $apptDetails['date'], $nextServiceDate, $mileage, $healthScore
                    );
                    $insertRecord->execute();
                    $insertRecord->close();
                }
                
                // Upsert performance record for same user/vehicle/recorded_date
                // Upsert a simple notes record (no performance metrics)
                $delPerf = $conn->prepare("DELETE FROM vehicle_performance WHERE user_id = ? AND vehicle_info = ? AND recorded_date = ?");
                $delPerf->bind_param("iss", $apptDetails['user_id'], $apptDetails['car'], $apptDetails['date']);
                $delPerf->execute();
                $delPerf->close();

                $insertPerf = $conn->prepare(
                    "INSERT INTO vehicle_performance 
                     (user_id, vehicle_info, recorded_date, notes) 
                     VALUES (?, ?, ?, ?)"
                );
                $notes = "Service completed: {$apptDetails['service']} on " . date('M d, Y', strtotime($apptDetails['date'])) .
                         (isset($apptDetails['odometer']) && $apptDetails['odometer'] !== null && $apptDetails['odometer'] !== '' ? ", Odometer: {$apptDetails['odometer']} km" : "");
                $insertPerf->bind_param(
                    "isss",
                    $apptDetails['user_id'], $apptDetails['car'], $apptDetails['date'], $notes
                );
                $insertPerf->execute();
                $insertPerf->close();
                
                // Create alert if next service is due soon (within 30 days for testing)
                $daysUntilService = (strtotime($nextServiceDate) - time()) / (60 * 60 * 24);
                if ($daysUntilService <= 30) {
                    $alertMessage = "Your {$apptDetails['car']} is due for {$apptDetails['service']} maintenance on " . date('M d, Y', strtotime($nextServiceDate));
                    $severity = $daysUntilService <= 7 ? 'high' : 'medium';
                    
                    $insertAlert = $conn->prepare("
                        INSERT INTO vehicle_alerts 
                        (user_id, vehicle_info, alert_type, alert_message, severity, due_date) 
                        VALUES (?, ?, 'maintenance_due', ?, ?, ?)
                    ");
                    $insertAlert->bind_param("issss", $apptDetails['user_id'], $apptDetails['car'], $alertMessage, $severity, $nextServiceDate);
                    $insertAlert->execute();
                    $insertAlert->close();
                }
            }
            
            // Create notification based on status change
            if ($apptDetails) {
                $notificationTitle = "";
                $notificationMessage = "";
                $notificationType = "info";
                
                switch ($newStatus) {
                    case 'approved':
                        $notificationTitle = "Appointment Approved";
                        $notificationMessage = "Great news! Your {$apptDetails['service']} appointment on " . 
                                              date('M d, Y', strtotime($apptDetails['date'])) . 
                                              " at " . date('g:i A', strtotime($apptDetails['time'])) . 
                                              " has been approved. Please arrive 15 minutes early.";
                        $notificationType = "success";
                        break;
                        
                    case 'completed':
                        $notificationTitle = "Service Completed";
                        $serviceNameSafe = htmlspecialchars($apptDetails['service'], ENT_QUOTES);
                        $monitorLink = (isset($_SERVER['HTTP_HOST'])
                            ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://')
                              . $_SERVER['HTTP_HOST']
                              . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\')
                              . "/vehicle_monitoring.php"
                            : "vehicle_monitoring.php");
                        $monitorHref = htmlspecialchars($monitorLink, ENT_QUOTES);
                        $notificationMessage = "Excellent! Your {$serviceNameSafe} service has been completed successfully. " .
                                              "Thank you for choosing our vehicle service center! We hope you're satisfied with our service. " .
                                              "Your vehicle health monitoring data has been updated. <a href='" . $monitorHref . "'>View Vehicle Monitoring</a>.";
        
                        $notificationType = "success";
                        break;
                        
                    case 'canceled':
                        $notificationTitle = "Appointment Canceled";
                        $notificationMessage = "Your {$apptDetails['service']} appointment on " . 
                                              date('M d, Y', strtotime($apptDetails['date'])) . 
                                              " at " . date('g:i A', strtotime($apptDetails['time'])) . 
                                              " has been canceled. Please contact us if you have any questions.";
                        $notificationType = "warning";
                        break;
                }
                
                // Insert notification if we have a message
                if (!empty($notificationMessage)) {
                    // Create notifications table if it doesn't exist
                    $createNotificationTable = "CREATE TABLE IF NOT EXISTS notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        message TEXT NOT NULL,
                        type VARCHAR(50) DEFAULT 'info',
                        is_read BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )";
                    $conn->query($createNotificationTable);
                    
                    $insertNotification = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                    $insertNotification->bind_param("isss", $apptDetails['user_id'], $notificationTitle, $notificationMessage, $notificationType);
                    $insertNotification->execute();
                    $insertNotification->close();
                }
            }
            
            $msg = "✅ Status for appointment #$apptId updated to $newStatus" . ($newStatus === 'completed' ? " and vehicle monitoring data created" : "") . ". Customer notified.";
            $msgType = "success";

            // If admin clicked Complete with receipt creation intent, redirect to admin receipt page
            if ($newStatus === 'completed' && $createReceipt === 1) {
                header('Location: admin/create_receipt.php?appointment_id=' . $apptId);
                exit();
            }
        }
        // Update appointment
        else if ($action === 'edit_appointment') {
            $apptId = intval($_POST['appointment_id']);
            $date = $_POST['date'];
            $time = $_POST['time'];
            $car = $_POST['car'];
            $service = $_POST['service'];
            $status = $_POST['status'];
            
            $stmt = $conn->prepare("UPDATE appointments SET date=?, time=?, car=?, service=?, status=? WHERE id=?");
            $stmt->bind_param("sssssi", $date, $time, $car, $service, $status, $apptId);
            $stmt->execute();
            $stmt->close();
            
            $msg = "✏️ Appointment #$apptId updated successfully!";
            $msgType = "success";
        }
        // Add this new action for marking as paid
        else if ($action === 'mark_paid') {
            $apptId = intval($_POST['appointment_id']);
            
            // Get appointment and user details first
            $getApptDetails = $conn->prepare("SELECT a.*, u.name as customer_name, u.email as customer_email FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
            $getApptDetails->bind_param("i", $apptId);
            $getApptDetails->execute();
            $apptDetails = $getApptDetails->get_result()->fetch_assoc();
            $getApptDetails->close();
            
            if (!$apptDetails) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
                exit();
            }
            
            // Update payment status to completed
            $stmt = $conn->prepare("UPDATE payments SET payment_status = 'completed' WHERE appointment_id = ?");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $stmt->close();
            
            // If no payment record exists, create one
            $checkPayment = $conn->prepare("SELECT id FROM payments WHERE appointment_id = ?");
            $checkPayment->bind_param("i", $apptId);
            $checkPayment->execute();
            $paymentResult = $checkPayment->get_result();
            
            if ($paymentResult->num_rows === 0) {
                $amount = getServicePrice($apptDetails['service']);
                $transactionId = 'CASH_' . strtoupper(substr(md5(time() . rand()), 0, 8));
                
                $insertPayment = $conn->prepare("INSERT INTO payments (appointment_id, user_id, amount, payment_method, payment_status, transaction_id) VALUES (?, ?, ?, 'cash', 'completed', ?)");
                $insertPayment->bind_param("iids", $apptId, $apptDetails['user_id'], $amount, $transactionId);
                $insertPayment->execute();
                $insertPayment->close();
            }
            $checkPayment->close();
            
            // Create notification for the user
            $notificationTitle = "Payment Confirmed";
            $notificationMessage = "Your payment for {$apptDetails['service']} appointment on " . 
                                  date('M d, Y', strtotime($apptDetails['date'])) . 
                                  " at " . date('g:i A', strtotime($apptDetails['time'])) . 
                                  " has been confirmed by admin. Thank you for your payment!";
            
            // Create notifications table if it doesn't exist
            $createNotificationTable = "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            $conn->query($createNotificationTable);
            
            // Insert notification
            $insertNotification = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'success')");
            $insertNotification->bind_param("iss", $apptDetails['user_id'], $notificationTitle, $notificationMessage);
            $insertNotification->execute();
            $insertNotification->close();
            
            echo json_encode(['success' => true, 'message' => 'Payment marked as completed and customer notified successfully']);
            exit();
        }
    // Notify availability for an appointment (admin-triggered)
        else if ($action === 'notify_availability') {
            header('Content-Type: application/json');
            $apptId = intval($_POST['appointment_id'] ?? 0);
            if (!$apptId) { echo json_encode(['success' => false, 'message' => 'Missing appointment id']); exit(); }

            // Look up appointment and user
            $stmt = $conn->prepare("SELECT a.*, u.id AS uid, u.name, u.email FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $appt = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$appt) { echo json_encode(['success' => false, 'message' => 'Appointment not found']); exit(); }

            $date = $appt['date'];
            $time = $appt['time'];
            $service = $appt['service'];
            $userId = (int)$appt['uid'];

            // Capacity-based availability
            $maxSlotsPerTime = 5;
            $q = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date = ? AND time = ? AND LOWER(status) <> 'canceled'");
            $q->bind_param("ss", $date, $time);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
            $booked = (int)($r['cnt'] ?? 0);
            $available = max(0, $maxSlotsPerTime - $booked);

            // Suggested alternative times for the date
            $timeSlots = ["08:00 AM","09:00 AM","10:00 AM","01:00 PM","02:00 PM","03:00 PM"];
            $availableTimes = [];
            foreach ($timeSlots as $t) {
                $qs = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date = ? AND time = ? AND LOWER(status) <> 'canceled'");
                $qs->bind_param("ss", $date, $t);
                $qs->execute();
                $rs = $qs->get_result()->fetch_assoc();
                $qs->close();
                $free = max(0, $maxSlotsPerTime - (int)($rs['cnt'] ?? 0));
                if ($free > 0) { $availableTimes[] = $t; }
            }

            // Ensure notifications table exists
            $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            $prettyDate = date('M d, Y', strtotime($date));
            $prettyTime = date('g:i A', strtotime($time));
            // Ensure appointments has payment_ready column (compatible with older MySQL/MariaDB)
            $colCheck = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_ready'");
            if ($colCheck && $colCheck->num_rows === 0) {
                $conn->query("ALTER TABLE appointments ADD COLUMN payment_ready TINYINT(1) DEFAULT 0");
            }

            if ($available > 0) {
                $title = 'Mechanic Availability: Confirmed';
                $message = "Good news! We have mechanics available for your {$service} appointment on {$prettyDate} at {$prettyTime}.";
                $type = 'success';
                // Unlock payment for this appointment
                $up = $conn->prepare("UPDATE appointments SET payment_ready = 1 WHERE id = ?");
                $up->bind_param("i", $apptId);
                $up->execute();
                $up->close();
            } else {
                $title = 'Mechanic Availability: Unavailable';
                $alts = empty($availableTimes) ? 'Currently fully booked for the day.' : ('Available times today: ' . implode(', ', $availableTimes));
                $message = "We are currently fully booked for {$prettyTime} on {$prettyDate}. {$alts} You can keep your booking or reschedule to one of the available times.";
                $type = 'warning';
                // Lock payment
                $up = $conn->prepare("UPDATE appointments SET payment_ready = 0 WHERE id = ?");
                $up->bind_param("i", $apptId);
                $up->execute();
                $up->close();
            }

            $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
            $ins->bind_param("isss", $userId, $title, $message, $type);
            $ok = $ins->execute();
            $ins->close();
            // Snapshot notification in appointment row
            $snap = $conn->prepare("UPDATE appointments SET cancel_notice_title = ?, cancel_notice_message = ?, cancel_notice_at = NOW(), rebook_recommended_time = ? WHERE id = ?");
            if ($snap) {
                $snap->bind_param("sssi", $title, $message, $recommendedTime, $apptId);
                $snap->execute();
                $snap->close();
            }

            echo json_encode(['success' => $ok, 'message' => $ok ? 'Notification sent to customer' : 'Failed to create notification', 'payment_ready' => ($available > 0 ? 1 : 0)]);
            exit();
        }
        // Restore a soft-deleted appointment
        else if ($action === 'restore_deleted') {
            $apptId = intval($_POST['line']);
            $stmt = $conn->prepare("UPDATE appointments SET is_deleted = 0, deleted_at = NULL WHERE id = ?");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $stmt->close();
            $msg = "✅ Appointment #$apptId restored.";
            $msgType = "success";
        }
        // Permanently delete a soft-deleted appointment
        else if ($action === 'purge_deleted') {
            $apptId = intval($_POST['line']);
            $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ? AND is_deleted = 1");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $stmt->close();
            $msg = "🗑️ Appointment #$apptId permanently deleted.";
            $msgType = "success";
        }
        // Get available times for the appointment's date (admin UI helper)
        else if ($action === 'get_available_times') {
            header('Content-Type: application/json');
            $apptId = intval($_POST['appointment_id'] ?? 0);
            if (!$apptId) { echo json_encode(['success' => false, 'message' => 'Missing appointment id']); exit(); }

            $stmt = $conn->prepare("SELECT date FROM appointments WHERE id = ?");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Appointment not found']); exit(); }

            $date = $row['date'];
            $maxSlotsPerTime = 5;
            $timeSlots = ["08:00 AM","09:00 AM","10:00 AM","01:00 PM","02:00 PM","03:00 PM"];
            $availableTimes = [];
            foreach ($timeSlots as $t) {
                $qs = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date = ? AND time = ? AND LOWER(status) <> 'canceled'");
                $qs->bind_param("ss", $date, $t);
                $qs->execute();
                $rs = $qs->get_result()->fetch_assoc();
                $qs->close();
                $free = max(0, $maxSlotsPerTime - (int)($rs['cnt'] ?? 0));
                if ($free > 0) { $availableTimes[] = $t; }
            }

            echo json_encode(['success' => true, 'available_times' => $availableTimes]);
            exit();
        }
        // Suggest rebooking (send available times for the selected date)
    else if ($action === 'suggest_rebook') {
            header('Content-Type: application/json');
            $apptId = intval($_POST['appointment_id'] ?? 0);
            if (!$apptId) { echo json_encode(['success' => false, 'message' => 'Missing appointment id']); exit(); }

            // Look up appointment and user
            $stmt = $conn->prepare("SELECT a.*, u.id AS uid, u.name, u.email FROM appointments a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
            $stmt->bind_param("i", $apptId);
            $stmt->execute();
            $appt = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$appt) { echo json_encode(['success' => false, 'message' => 'Appointment not found']); exit(); }

            $date = $appt['date'];
            $time = $appt['time'];
            $service = $appt['service'];
            $userId = (int)$appt['uid'];

            // Capacity-based times for that date
            $maxSlotsPerTime = 5;
            $timeSlots = ["08:00 AM","09:00 AM","10:00 AM","01:00 PM","02:00 PM","03:00 PM"];
            $availableTimes = [];
            foreach ($timeSlots as $t) {
                $qs = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date = ? AND time = ? AND LOWER(status) <> 'canceled'");
                $qs->bind_param("ss", $date, $t);
                $qs->execute();
                $rs = $qs->get_result()->fetch_assoc();
                $qs->close();
                $free = max(0, $maxSlotsPerTime - (int)($rs['cnt'] ?? 0));
                if ($free > 0) { $availableTimes[] = $t; }
            }

            // Ensure notifications table exists
            $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            $prettyDate = date('M d, Y', strtotime($date));
            $prettyTime = date('g:i A', strtotime($time));
            // Optional admin reason and recommended time
            $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
            $recommendedTime = isset($_POST['recommended_time']) ? trim($_POST['recommended_time']) : '';
            // If admin didn't pick a time, pick the earliest available as a sensible default
            if ($recommendedTime === '' && !empty($availableTimes)) {
                $recommendedTime = $availableTimes[0];
            }

            if (!empty($availableTimes)) {
                $title = 'Please Rebook';
                $message = "<strong>No mechanic available</strong> for {$service} at {$prettyTime} on {$prettyDate}.";
                if ($reason !== '') { $message .= " Reason: " . htmlspecialchars($reason, ENT_QUOTES) . "."; }
                if ($recommendedTime !== '') { $message .= " Recommended time: <strong>{$recommendedTime}</strong>."; }
                $message .= " Please rebook to a different time.";
                $type = 'warning';
            } else {
                $title = 'Please Rebook';
                $message = "<strong>Fully booked</strong> on {$prettyDate} for {$service}.";
                if ($reason !== '') { $message .= " Reason: " . htmlspecialchars($reason, ENT_QUOTES) . "."; }
                $message .= " Please select another date in My Appointments.";
                $type = 'warning';
            }

            $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
            $ins->bind_param("isss", $userId, $title, $message, $type);
            $ok = $ins->execute();
            $ins->close();

            // Auto-cancel the original appointment and reset payment flags
            // Ensure payment_ready column exists
            $colCheck = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_ready'");
            if ($colCheck && $colCheck->num_rows === 0) {
                $conn->query("ALTER TABLE appointments ADD COLUMN payment_ready TINYINT(1) DEFAULT 0");
            }
            $upd = $conn->prepare("UPDATE appointments SET status = 'Canceled', payment_ready = 0, cancel_date = CURDATE(), cancel_reason = ? WHERE id = ?");
            $upd->bind_param("si", $reason, $apptId);
            $upd->execute();
            $upd->close();

            echo json_encode([
                'success' => $ok,
                'message' => $ok ? 'Rebook suggestion sent and appointment canceled' : 'Failed to create suggestion',
                'available_times' => $availableTimes
            ]);
            exit();
        }
        // Add vehicle monitoring data
        else if ($action === 'add_monitoring_data') {
            $appointmentId = intval($_POST['appointment_id']);
            $userId = intval($_POST['user_id']);
            $vehicleInfo = $_POST['vehicle_info'];
            $serviceType = $_POST['service_type'];
            $serviceDate = $_POST['service_date'];
            $nextServiceDue = $_POST['next_service_due'];
            $mileageAtService = intval($_POST['mileage_at_service']);
            $healthScore = intval($_POST['health_score']);
            // performance metrics removed; keep notes only
            $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
            
            // Create monitoring tables if they don't exist - COMPLETE VERSION
            $createTables = [
                "CREATE TABLE IF NOT EXISTS vehicle_health_records (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    appointment_id INT,
                    vehicle_info VARCHAR(500) NOT NULL,
                    service_type VARCHAR(100) NOT NULL,
                    service_date DATE NOT NULL,
                    next_service_due DATE,
                    mileage_at_service INT,
                    health_score INT DEFAULT 85,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
                )",
                "CREATE TABLE IF NOT EXISTS vehicle_performance (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    vehicle_info VARCHAR(500) NOT NULL,
                    fuel_efficiency DECIMAL(5,2),
                    engine_performance INT DEFAULT 90,
                    brake_efficiency INT DEFAULT 95,
                    overall_condition INT DEFAULT 88,
                    recorded_date DATE NOT NULL,
                    notes TEXT,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )",
                "CREATE TABLE IF NOT EXISTS vehicle_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    vehicle_info VARCHAR(500) NOT NULL,
                    alert_type ENUM('maintenance_due', 'inspection_required', 'warranty_expiring', 'performance_issue') DEFAULT 'maintenance_due',
                    alert_message TEXT NOT NULL,
                    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                    due_date DATE,
                    is_resolved BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )"
            ];
            
            foreach ($createTables as $sql) {
                $conn->query($sql);
            }
            
            // Insert health record
            $insertRecord = $conn->prepare("
                INSERT INTO vehicle_health_records 
                (user_id, appointment_id, vehicle_info, service_type, service_date, next_service_due, mileage_at_service, health_score) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertRecord->bind_param("iissssii", 
                $userId, $appointmentId, $vehicleInfo, $serviceType, 
                $serviceDate, $nextServiceDue, $mileageAtService, $healthScore
            );
            
            if ($insertRecord->execute()) {
                // Insert performance row (notes only)
                $insertPerf = $conn->prepare(
                    "INSERT INTO vehicle_performance (user_id, vehicle_info, recorded_date, notes) VALUES (?, ?, ?, ?)"
                );
                $insertPerf->bind_param("isss", $userId, $vehicleInfo, $serviceDate, $notes);
                $insertPerf->execute();
                $insertPerf->close();
                
                // Create alert if next service is due soon
                if (!empty($nextServiceDue)) {
                    $daysUntilService = (strtotime($nextServiceDue) - time()) / (60 * 60 * 24);
                    if ($daysUntilService <= 30) {
                        $alertMessage = "Your {$vehicleInfo} is due for {$serviceType} maintenance on " . date('M d, Y', strtotime($nextServiceDue));
                        $severity = $daysUntilService <= 7 ? 'high' : 'medium';
                        
                        $insertAlert = $conn->prepare("
                            INSERT INTO vehicle_alerts 
                            (user_id, vehicle_info, alert_type, alert_message, severity, due_date) 
                            VALUES (?, ?, 'maintenance_due', ?, ?, ?)
                        ");
                        $insertAlert->bind_param("issss", $userId, $vehicleInfo, $alertMessage, $severity, $nextServiceDue);
                        $insertAlert->execute();
                        $insertAlert->close();
                    }
                }
                
                // Send notification to customer
                $notificationTitle = "Vehicle Health Monitoring Added";
                $notificationMessage = "Your {$vehicleInfo} service ({$serviceType}) on " . 
                                      date('M d, Y', strtotime($serviceDate)) . 
                                      " has been logged in our monitoring system. Health Score: {$healthScore}%. " .
                                      "Your next service is recommended on " . date('M d, Y', strtotime($nextServiceDue)) . ".";
                
                $insertNotification = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'info')");
                $insertNotification->bind_param("iss", $userId, $notificationTitle, $notificationMessage);
                $insertNotification->execute();
                $insertNotification->close();
                
                $msg = "✅ Vehicle monitoring data added successfully for appointment #$appointmentId!";
                $msgType = "success";
            } else {
                $msg = "❌ Error adding monitoring data: " . $insertRecord->error;
                $msgType = "danger";
            }
            $insertRecord->close();
        }
        // Update vehicle monitoring data
        else if ($action === 'update_monitoring_data') {
            $recordId = intval($_POST['record_id']);
            $healthScore = intval($_POST['health_score']);
            // performance metrics removed; keep notes only
            $nextServiceDue = $_POST['next_service_due'];
            $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
            
            // Update health record
            $updateRecord = $conn->prepare("
                UPDATE vehicle_health_records 
                SET health_score = ?, next_service_due = ?
                WHERE id = ?
            ");
            $updateRecord->bind_param("isi", $healthScore, $nextServiceDue, $recordId);
            
            if ($updateRecord->execute()) {
                // Get vehicle info for performance update
                $getVehicleInfo = $conn->prepare("SELECT user_id, vehicle_info, service_date FROM vehicle_health_records WHERE id = ?");
                $getVehicleInfo->bind_param("i", $recordId);
                $getVehicleInfo->execute();
                $vehicleData = $getVehicleInfo->get_result()->fetch_assoc();
                $getVehicleInfo->close();
                
                if ($vehicleData) {
                    // Ensure a single performance row per (user_id, vehicle_info, recorded_date)
                    $chk = $conn->prepare("SELECT 1 FROM vehicle_performance WHERE user_id=? AND vehicle_info=? AND recorded_date=? LIMIT 1");
                    $chk->bind_param("iss", $vehicleData['user_id'], $vehicleData['vehicle_info'], $vehicleData['service_date']);
                    $chk->execute();
                    $exists = $chk->get_result()->fetch_row();
                    $chk->close();

                    if ($exists) {
                        $updatePerf = $conn->prepare("\n                            UPDATE vehicle_performance \n                            SET notes = ?\n                            WHERE user_id = ? AND vehicle_info = ? AND recorded_date = ?\n                        ");
                        $updatePerf->bind_param("siss", $notes, $vehicleData['user_id'], $vehicleData['vehicle_info'], $vehicleData['service_date']);
                        $updatePerf->execute();
                        $updatePerf->close();
                    } else {
                        $insertPerf = $conn->prepare("INSERT INTO vehicle_performance (user_id, vehicle_info, recorded_date, notes) VALUES (?, ?, ?, ?)");
                        $insertPerf->bind_param("isss", $vehicleData['user_id'], $vehicleData['vehicle_info'], $vehicleData['service_date'], $notes);
                        $insertPerf->execute();
                        $insertPerf->close();
                    }
                    
                    // Update alerts if needed
                    if (!empty($nextServiceDue)) {
                        // Remove old alerts for this vehicle
                        $removeOldAlerts = $conn->prepare("
                            DELETE FROM vehicle_alerts 
                            WHERE user_id = ? AND vehicle_info = ? AND alert_type = 'maintenance_due'
                        ");
                        $removeOldAlerts->bind_param("is", $vehicleData['user_id'], $vehicleData['vehicle_info']);
                        $removeOldAlerts->execute();
                        $removeOldAlerts->close();
                        
                        // Create new alert if due soon
                        $daysUntilService = (strtotime($nextServiceDue) - time()) / (60 * 60 * 24);
                        if ($daysUntilService <= 30) {
                            $alertMessage = "Your {$vehicleData['vehicle_info']} maintenance is due on " . date('M d, Y', strtotime($nextServiceDue));
                            $severity = $daysUntilService <= 7 ? 'high' : 'medium';
                            
                            $insertAlert = $conn->prepare("
                                INSERT INTO vehicle_alerts 
                                (user_id, vehicle_info, alert_type, alert_message, severity, due_date) 
                                VALUES (?, ?, 'maintenance_due', ?, ?, ?)
                            ");
                            $insertAlert->bind_param("issss", $vehicleData['user_id'], $vehicleData['vehicle_info'], $alertMessage, $severity, $nextServiceDue);
                            $insertAlert->execute();
                            $insertAlert->close();
                        }
                    }
                    
                    // Send notification to customer
                    $notificationTitle = "Vehicle Health Data Updated";
                    $notificationMessage = "Your {$vehicleData['vehicle_info']} monitoring data has been updated. " .
                                          "New health score: {$healthScore}%. " .
                                          "Next service scheduled for " . date('M d, Y', strtotime($nextServiceDue)) . ".";
                    
                    $insertNotification = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'info')");
                    $insertNotification->bind_param("iss", $vehicleData['user_id'], $notificationTitle, $notificationMessage);
                    $insertNotification->execute();
                    $insertNotification->close();
                }
                
                $msg = "✅ Vehicle monitoring data updated successfully for record #$recordId!";
                $msgType = "success";
            } else {
                $msg = "❌ Error updating monitoring data: " . $updateRecord->error;
                $msgType = "danger";
            }
            $updateRecord->close();
        }
        // DELETE vehicle monitoring record
        else if ($action === 'delete_monitoring_record') {
            $recordId = intval($_POST['record_id']);
            
            // Get record details first for cleanup
            $getRecord = $conn->prepare("SELECT user_id, vehicle_info, service_date FROM vehicle_health_records WHERE id = ?");
            $getRecord->bind_param("i", $recordId);
            $getRecord->execute();
            $recordData = $getRecord->get_result()->fetch_assoc();
            $getRecord->close();
            
            if ($recordData) {
                // Delete related performance record
                $deletePerf = $conn->prepare("
                    DELETE FROM vehicle_performance 
                    WHERE user_id = ? AND vehicle_info = ? AND recorded_date = ?
                ");
                $deletePerf->bind_param("iss", $recordData['user_id'], $recordData['vehicle_info'], $recordData['service_date']);
                $deletePerf->execute();
                $deletePerf->close();
                
                // Delete related alerts
                $deleteAlerts = $conn->prepare("
                    DELETE FROM vehicle_alerts 
                    WHERE user_id = ? AND vehicle_info = ?
                ");
                $deleteAlerts->bind_param("is", $recordData['user_id'], $recordData['vehicle_info']);
                $deleteAlerts->execute();
                $deleteAlerts->close();
                
                // Delete main health record
                $deleteRecord = $conn->prepare("DELETE FROM vehicle_health_records WHERE id = ?");
                $deleteRecord->bind_param("i", $recordId);
                
                if ($deleteRecord->execute()) {
                    $msg = "✅ Vehicle monitoring record deleted successfully!";
                    $msgType = "success";
                } else {
                    $msg = "❌ Error deleting record: " . $deleteRecord->error;
                    $msgType = "danger";
                }
                $deleteRecord->close();
            } else {
                $msg = "❌ Record not found!";
                $msgType = "danger";
            }
        }
        // DUPLICATE monitoring record
        else if ($action === 'duplicate_monitoring_record') {
            $recordId = intval($_POST['record_id']);
            
            // Get original record
            $getOriginal = $conn->prepare("
                SELECT vhr.*, vp.fuel_efficiency, vp.engine_performance, vp.brake_efficiency, vp.overall_condition, vp.notes
                FROM vehicle_health_records vhr
                LEFT JOIN vehicle_performance vp ON vhr.user_id = vp.user_id 
                    AND vhr.vehicle_info = vp.vehicle_info 
                    AND vhr.service_date = vp.recorded_date
                WHERE vhr.id = ?
            ");
            $getOriginal->bind_param("i", $recordId);
            $getOriginal->execute();
            $original = $getOriginal->get_result()->fetch_assoc();
            $getOriginal->close();
            
            if ($original) {
                // Create new record with current date
                $newServiceDate = date('Y-m-d');
                $newNextService = date('Y-m-d', strtotime('+90 days'));
                
                $duplicateRecord = $conn->prepare("
                    INSERT INTO vehicle_health_records 
                    (user_id, appointment_id, vehicle_info, service_type, service_date, next_service_due, mileage_at_service, health_score) 
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
                ");
                $duplicateRecord->bind_param("issssii", 
                    $original['user_id'], 
                    $original['vehicle_info'], 
                    $original['service_type'] . ' (Copy)', 
                    $newServiceDate, 
                    $newNextService, 
                    $original['mileage_at_service'], 
                    $original['health_score']
                );
                
                if ($duplicateRecord->execute()) {
                    // Duplicate performance record (notes only)
                    $duplicatePerf = $conn->prepare(
                        "INSERT INTO vehicle_performance (user_id, vehicle_info, recorded_date, notes) VALUES (?, ?, ?, ?)"
                    );
                    $duplicatePerf->bind_param(
                        "isss",
                        $original['user_id'],
                        $original['vehicle_info'],
                        $newServiceDate,
                        ($original['notes'] ?? '') . ' (Duplicated record)'
                    );
                    $duplicatePerf->execute();
                    $duplicatePerf->close();
                    
                    $msg = "✅ Monitoring record duplicated successfully!";
                    $msgType = "success";
                } else {
                    $msg = "❌ Error duplicating record: " . $duplicateRecord->error;
                    $msgType = "danger";
                }
                $duplicateRecord->close();
            } else {
                $msg = "❌ Original record not found!";
                $msgType = "danger";
            }
        }
    }
}

// Handle search/filter
$whereClause = "a.is_deleted = 0";
$searchParams = [];

if (isset($_GET['search'])) {
    $search = $_GET['search'];
    if (!empty($search)) {
        $whereClause .= " AND (a.car LIKE ? OR a.service LIKE ? OR u.email LIKE ? OR u.name LIKE ?)";
        $searchTerm = "%$search%";
        array_push($searchParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status = $_GET['status'];
    $whereClause .= " AND a.status = ?";
    array_push($searchParams, $status);
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $dateFrom = $_GET['date_from'];
    $whereClause .= " AND a.date >= ?";
    array_push($searchParams, $dateFrom);
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $dateTo = $_GET['date_to'];
    $whereClause .= " AND a.date <= ?";
    array_push($searchParams, $dateTo);
}

// Ensure schema additions exist for admin UI
$colCheck = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_method_selected'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN payment_method_selected VARCHAR(50) NULL");
}
// Ensure odometer column exists
$colCheckOdo = $conn->query("SHOW COLUMNS FROM appointments LIKE 'odometer'");
if ($colCheckOdo && $colCheckOdo->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN odometer INT NULL");
}
// Ensure payment_ready column exists early (used to gate Approvals after Notify Availability)
$colCheckReady = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_ready'");
if ($colCheckReady && $colCheckReady->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN payment_ready TINYINT(1) DEFAULT 0");
}

// Load appointments with filters (UPDATE THIS SECTION)
$appointments = [];
$query = "SELECT a.id, a.date, a.time, CONCAT(a.date, ' ', a.time) AS datetime, 
           a.car, a.service, a.status, a.comments, a.odometer, a.payment_method_selected, a.payment_ready, u.id AS user_id, u.email, u.name as username,
           p.payment_status, p.payment_method, p.transaction_id, p.amount
          FROM appointments a
          JOIN users u ON a.user_id = u.id
          LEFT JOIN payments p ON a.id = p.appointment_id
          WHERE $whereClause
          ORDER BY a.date DESC, a.time DESC";

if (!empty($searchParams)) {
    $stmt = $conn->prepare($query);
    $types = str_repeat("s", count($searchParams));
    $stmt->bind_param($types, ...$searchParams);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

if ($result === false) {
    // Fallback: avoid fatal error and surface diagnostic info minimally
    $appointments = [];
} else {
while ($row = $result->fetch_assoc()) {
    // Normalize service naming to canonical set
    $normalizedService = normalizeServiceName($row['service']);
    if (!isset($serviceCatalog[$normalizedService])) {
        // dynamically add if appears but not in catalog (edge case)
        $serviceCatalog[$normalizedService] = [
            'description' => $normalizedService . ' service',
            'price' => getServicePrice($normalizedService)
        ];
    }
    $appointments[] = [
        'id' => $row['id'],
        'date' => $row['date'],
        'time' => $row['time'],
        'datetime' => $row['datetime'],
        'car' => $row['car'],
        'service' => $normalizedService,
        'status' => $row['status'],
    'comments' => $row['comments'] ?? '',
    'odometer' => $row['odometer'] ?? null,
        'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
        'email' => $row['email'],
        'username' => $row['username'],
        'payment_status' => $row['payment_status'],
        'payment_method' => $row['payment_method'],
        'payment_method_selected' => $row['payment_method_selected'],
        'payment_ready' => isset($row['payment_ready']) ? (int)$row['payment_ready'] : 0,
        'transaction_id' => $row['transaction_id'],
        'amount' => $row['amount']
    ];
}
}

// Calculate remaining slots per datetime
$slotsCount = [];
foreach ($appointments as $appt) {
    if (strtolower($appt['status']) !== 'canceled') {
        $slotsCount[$appt['datetime']] = ($slotsCount[$appt['datetime']] ?? 0) + 1;
    }
}

// Build per-service stats from loaded appointments
foreach ($appointments as $appt) {
    $sName = $appt['service'];
    if (!isset($serviceStats[$sName])) continue; // skip unknown
    $serviceStats[$sName]['total']++;
    $lower = strtolower($appt['status']);
    if (isset($serviceStats[$sName][$lower])) {
        $serviceStats[$sName][$lower]++;
    }
}

// Group appointments by service for per-service tables
$appointmentsByService = [];
foreach ($appointments as $appt) {
    $appointmentsByService[$appt['service']][] = $appt;
}

// Get all users for appointment creation
$users = [];
$userResult = $conn->query("SELECT id, name, email FROM users ORDER BY name");
while ($row = $userResult->fetch_assoc()) {
    $users[] = $row;
}

// Get service types for dropdowns
// Canonical service names as requested (uppercase representation in UI kept consistent)
$services = [
    'PMS',
    'BRAKE CHECK',
    'OIL CHANGE',
    'CHECK ENGINE',
    'WHEEL ALIGNMENT',
    'AIR FILTER',
    'AIRCON',
    'CHECK WIRING'
];

// Descriptions (short for admin clarity)
$serviceDescriptions = [
    'PMS' => 'Periodic Maintenance Service multi-point inspection.',
    'BRAKE CHECK' => 'Inspection of pads, rotors & hydraulic components.',
    'OIL CHANGE' => 'Replace engine oil & filter; quick health check.',
    'CHECK ENGINE' => 'Diagnostic scan & engine system evaluation.',
    'WHEEL ALIGNMENT' => 'Adjust wheel angles for proper tracking & tire wear.',
    'AIR FILTER' => 'Replace / inspect engine air filter element.',
    'AIRCON' => 'Air-conditioning system cleaning & performance check.',
    'CHECK WIRING' => 'Electrical harness & connector integrity inspection.'
];

// Normalization map to handle legacy / alternate names coming from existing appointments
function normalizeServiceName($raw) {
    $map = [
        'aircon cleaning' => 'AIRCON',
        'aircon' => 'AIRCON',
        'air filter replacement' => 'AIR FILTER',
        'air filter' => 'AIR FILTER',
        'oil change' => 'OIL CHANGE',
        'brake service' => 'BRAKE CHECK',
        'brake check' => 'BRAKE CHECK',
        'check engine' => 'CHECK ENGINE',
        'wheel alignment' => 'WHEEL ALIGNMENT',
        'pms' => 'PMS',
        'check wiring' => 'CHECK WIRING'
    ];
    $key = strtolower(trim($raw));
    return $map[$key] ?? strtoupper($raw);
}

// Build catalog from canonical list
$serviceCatalog = [];
foreach ($services as $svc) {
    $serviceCatalog[$svc] = [
        'description' => $serviceDescriptions[$svc] ?? 'No description.',
        'price' => getServicePrice($svc)
    ];
}

// Initialize per-service stats structure
$serviceStats = [];
foreach ($serviceCatalog as $name => $_meta) {
    $serviceStats[$name] = [ 'pending'=>0,'approved'=>0,'completed'=>0,'canceled'=>0,'total'=>0 ];
}

// Function to get service price (dummy implementation)
function getServicePrice($service) {
    $service = normalizeServiceName($service);
    $servicePrices = [
        'AIRCON' => 1200,
        'AIR FILTER' => 800,
        'BRAKE CHECK' => 1500,
        'CHECK ENGINE' => 1000,
        'CHECK WIRING' => 900,
        'OIL CHANGE' => 1100,
        'PMS' => 2000,
        'WHEEL ALIGNMENT' => 1300
    ];
    return $servicePrices[$service] ?? 1000;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="appointments_export_'.date('Y-m-d').'.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Time', 'Customer', 'Email', 'Car', 'Service', 'Odometer (km)', 'Status', 'Payment Status', 'Amount']);
    
    foreach ($appointments as $appt) {
        $servicePrice = getServicePrice($appt['service']);
        fputcsv($output, [
            $appt['id'],
            $appt['date'],
            $appt['time'],
            $appt['username'],
            $appt['email'],
            $appt['car'],
            $appt['service'],
            isset($appt['odometer']) && $appt['odometer'] !== null ? (int)$appt['odometer'] : '',
            $appt['status'],
            $appt['payment_status'] ?? 'unpaid',
            $servicePrice
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Vehicle Service Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #f0c040;
            --secondary: #333;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f4f4;
            color: #333;
            padding-bottom: 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #000 100%);
            color: white;
            padding: 15px 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .admin-title {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
            margin: 0;
        }
        
        .admin-subtitle {
            opacity: 0.8;
            font-size: 14px;
            margin: 0;
        }
        
        .admin-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }
        
        .status-badge {
            padding: 6px 10px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background-color: rgba(255, 193, 7, 0.15);
            color: #d1a000;
        }
        
        .status-approved {
            background-color: rgba(40, 167, 69, 0.15);
            color: #1e7e34;
        }
        
        .status-completed {
            background-color: rgba(23, 162, 184, 0.15);
            color: #117a8b;
        }
        
        .status-canceled {
            background-color: rgba(220, 53, 69, 0.15);
            color: #bd2130;
        }
        
        .btn-custom {
            padding: 8px 16px;
            border-radius: 5px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-primary-custom {
            background-color: var(--primary);
            border-color: var(--primary);
            color: var(--dark);
        }
        
        .btn-primary-custom:hover {
            background-color: #e3b53b;
            border-color: #e3b53b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .btn-danger-custom {
            background-color: var(--danger);
            border-color: var(--danger);
            color: white;
        }
        
        .btn-danger-custom:hover {
            background-color: #c82333;
            border-color: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .btn-success-custom {
            background-color: var(--success);
            border-color: var(--success);
            color: white;
        }
        
        .btn-success-custom:hover {
            background-color: #218838;
            border-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .btn-warning-custom {
            background-color: var(--warning);
            border-color: var(--warning);
            color: var(--dark);
        }
        
        .btn-warning-custom:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .btn-sm-custom {
            padding: 4px 10px;
            font-size: 12px;
        }
        
        .search-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: var(--secondary);
            color: white;
            font-weight: 500;
            border: none;
            padding: 12px 15px;
            font-size: 14px;
        }
        
        .table tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,0.02);
        }
        
        .table tbody td {
            padding: 12px 15px;
            vertical-align: middle;
            font-size: 14px;
            border-color: #eee;
        }
        
        .alert-message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.15);
            border-left: 4px solid var(--success);
            color: #1e7e34;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.15);
            border-left: 4px solid var(--danger);
            color: #bd2130;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            height: 100%;
        }
        
        .stats-icon {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .stats-number {
            font-size: 28px;
            font-weight: bold;
            color: var(--secondary);
            margin: 0;
        }
        
        .stats-label {
            color: #888;
            font-size: 14px;
            margin: 0;
        }
        
        .stats-pending .stats-icon { color: var(--warning); }
        .stats-approved .stats-icon { color: var(--success); }
        .stats-completed .stats-icon { color: var(--info); }
        .stats-canceled .stats-icon { color: var(--danger); }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--secondary);
            font-weight: 500;
            padding: 10px 15px;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: none;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(240, 192, 64, 0.25);
            border-color: var(--primary);
        }
        
        .modal-header {
            background: var(--secondary);
            color: white;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .btn-close {
            filter: invert(1) brightness(200%);
        }
        
        .checkmark-animation {
            display: inline-block;
            vertical-align: middle;
        }
        
        @media (max-width: 768px) {
            .admin-header .text-end {
                text-align: left !important;
                margin-top: 10px;
            }
            
            .btn-sm-custom {
                padding: 3px 6px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="admin-title">Vehicle Service Admin</h1>
                    <p class="admin-subtitle">Manage appointments, users, and services</p>
                </div>
                <div class="col-md-6 text-end">
                    <span class="me-3"><i class="fas fa-user me-1"></i> Admin</span>
                    <a href="?logout=1" class="btn btn-sm btn-outline-light"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Suggest Rebook Modal -->
    <div class="modal fade" id="suggestRebookModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="background:#111;color:#fff;border:1px solid rgba(255,255,255,0.1)">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Suggest Rebook</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rebook_appt_id" value="">
                    <div class="mb-3">
                        <label class="form-label">Reason to customer</label>
                        <textarea id="rebook_reason" class="form-control" rows="3" placeholder="Explain there’s no available mechanic at the chosen time..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recommend a time (same date)</label>
                        <select id="rebook_time" class="form-select">
                            <option value="">Loading available times...</option>
                        </select>
                        <div class="form-text text-warning">We’ll include this time in the notification.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" onclick="submitSuggestRebook()"><i class="fas fa-paper-plane me-1"></i>Send Suggestion</button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Cancel feature removed by request -->
        <!-- Alert Message -->
        <?php if (!empty($msg)): ?>
        <div class="alert-message alert-<?= $msgType ?>">
            <i class="fas <?= $msgType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
            <?= $msg ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <?php
            $statuses = ['pending', 'approved', 'completed', 'canceled'];
            $icons = ['fa-clock', 'fa-check-circle', 'fa-flag-checkered', 'fa-ban'];
            
            foreach ($statuses as $index => $status) {
                $count = 0;
                foreach ($appointments as $appt) {
                    if (strtolower($appt['status']) === $status) $count++;
                }
                ?>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="stats-card stats-<?= $status ?>">
                        <div class="stats-icon"><i class="fas <?= $icons[$index] ?>"></i></div>
                        <p class="stats-number"><?= $count ?></p>
                        <p class="stats-label"><?= ucfirst($status) ?></p>
                    </div>
                </div>
            <?php } ?>
        </div>
        
        <!-- Search & Filter -->
        <div class="search-container mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Car, service, email..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Statuses</option>

                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= $status ?>" <?= (isset($_GET['status']) && $_GET['status'] === $status) ? 'selected' : '' ?>>
                                <?= ucfirst($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <button type="submit" class="btn btn-primary-custom me-2">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                            <a href="manage_appointments.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-1"></i> Reset
                            </a>
                        </div>
                        <div>
                            <button type="button" class="btn btn-success-custom me-2" data-bs-toggle="modal" data-bs-target="#createAppointmentModal">
                                <i class="fas fa-plus me-1"></i> Create Appointment
                            </button>
                            <a href="?export=csv<?= isset($_GET['search']) ? '&search='.urlencode($_GET['search']) : '' ?><?= isset($_GET['status']) ? '&status='.urlencode($_GET['status']) : '' ?>" class="btn btn-outline-primary">
                                <i class="fas fa-file-export me-1"></i> Export to CSV
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Appointments Table -->
        <div class="admin-card">
            <h2 class="section-title">Appointments</h2>
            
            <div class="table-responsive">
                <table class="table">
                    <!-- Update the table headers -->
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Customer</th>
                            <th>Car</th>
                            <th>Service</th>
                            <th>Odometer</th>
                            <th>Comments</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Amount</th>
                            <!-- Remove the Slots column -->
                            <th width="250">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr>
                                <td colspan="12" class="text-center py-4">No appointments found</td>
                            </tr>
                        <?php else: ?>
                            <!-- Update the table body -->
                            <?php foreach ($appointments as $appt):
                                if (isset($appt['status']) && in_array(strtolower($appt['status']), ['completed','canceled'])) { continue; }
                                $datetime = $appt['datetime'];
                                $servicePrice = getServicePrice($appt['service']);
                            ?>
                                <tr>
                                    <td><?= $appt['id'] ?></td>
                                    <td><?= htmlspecialchars($appt['date']) ?></td>
                                    <td><?= htmlspecialchars($appt['time']) ?></td>
                                    <td>
                                        <div><?= htmlspecialchars($appt['username']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($appt['email']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($appt['car']) ?></td>
                                    <td><?= htmlspecialchars($appt['service']) ?></td>
                                    <td>
                                        <?php if (isset($appt['odometer']) && $appt['odometer'] !== null && $appt['odometer'] !== ''): ?>
                                            <?= number_format((int)$appt['odometer']) ?> km
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($appt['comments'])): ?>
                                            <?php 
                                                $c = strip_tags($appt['comments']);
                                                $short = mb_strlen($c) > 50 ? mb_substr($c, 0, 50) . '…' : $c;
                                            ?>
                                            <span title="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($short) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        switch(strtolower($appt['status'])) {
                                            case 'pending': $statusClass = 'status-pending'; break;
                                            case 'approved': $statusClass = 'status-approved'; break;
                                            case 'completed': $statusClass = 'status-completed'; break;
                                            case 'canceled': $statusClass = 'status-canceled'; break;
                                        }
                                        ?>
                                        <span class="status-badge <?= $statusClass ?>">
                                            <?= htmlspecialchars($appt['status']) ?>
                                            <?php if(strtolower($appt['status']) === 'approved'): ?>
                                                <i class="fas fa-check-circle ms-1"></i>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $paymentStatus = $appt['payment_status'] ?? 'unpaid';
                                        $paymentClass = '';
                                        $paymentText = '';
                                        
                                        switch($paymentStatus) {
                                            case 'completed':
                                                $paymentClass = 'bg-success';
                                                $paymentText = 'Paid';
                                                break;
                                            case 'pending':
                                                $paymentClass = 'bg-warning';
                                                $paymentText = $appt['payment_method'] === 'cash' ? 'Cash Pending' : 'Pending';
                                                break;
                                            default:
                                                $paymentClass = 'bg-danger';
                                                $paymentText = 'Unpaid';
                                        }
                                        ?>
                                        <span class="badge <?= $paymentClass ?>"><?= $paymentText ?></span>
                                        <?php if (!$appt['payment_method'] && !empty($appt['payment_method_selected'])): ?>
                                            <br><small class="text-muted">Method: <?= htmlspecialchars(ucfirst($appt['payment_method_selected'])) ?> (chosen)</small>
                                        <?php endif; ?>
                                        <?php if ($appt['payment_method']): ?>
                                            <br><small class="text-muted"><?= ucfirst($appt['payment_method']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($appt['transaction_id']): ?>
                                            <br><small class="text-muted"><?= substr($appt['transaction_id'], 0, 8) ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>₱<?= number_format($servicePrice, 2) ?></strong>
                                        <?php if ($appt['amount'] && $appt['amount'] != $servicePrice): ?>
                                            <br><small class="text-muted">Paid: ₱<?= number_format($appt['amount'], 2) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Remove slots cell -->
                                    <td>
                                            <div class="btn-group-vertical btn-group-sm">
                                                <?php 
                                                    $paymentStatus = $appt['payment_status'] ?? 'unpaid';
                                                    $hasChosenMethod = !empty($appt['payment_method_selected']) || !empty($appt['payment_method']);
                                                    $canMarkPaid = ($appt['payment_ready'] ?? 0) == 1 && $hasChosenMethod && !($paymentStatus === 'completed');
                                                    $markPaidTitle = !$hasChosenMethod ? 'Customer has not chosen payment method yet' : ((($appt['payment_ready'] ?? 0) != 1) ? 'Notify availability first' : 'Mark as Paid');
                                                ?>
                                                <!-- 1. Notify Availability (always first) -->
                                                <button type="button" class="btn btn-primary-custom btn-sm-custom mb-1"
                                                        onclick="notifyAvailability(<?= $appt['id'] ?>)"
                                                        title="Notify customer about mechanic availability">
                                                    <i class="fas fa-bell"></i> Notify Avail.
                                                </button>

                                                <!-- 2. Mark Paid (gated) -->
                                                <button type="button" class="btn btn-success-custom btn-sm-custom mb-1" 
                                                    <?= $canMarkPaid ? '' : 'disabled style="opacity:.55;cursor:not-allowed;"' ?>
                                                    onclick="<?= $canMarkPaid ? 'markAsPaid('.$appt['id'].')' : 'return false' ?>"
                                                    title="<?= htmlspecialchars($markPaidTitle) ?>">
                                                    <i class="fas fa-money-check-alt"></i> Mark Paid
                                                </button>
                                                <?php if(!$canMarkPaid && $paymentStatus !== 'completed'): ?>
                                                    <small class="text-muted mb-1" style="max-width:130px;">
                                                        <?php if(!($appt['payment_ready'] ?? 0)): ?>Need Notify Avail.
                                                        <?php elseif(!$hasChosenMethod): ?>Need Method
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>

                                                <!-- 3. Approve (after payment) -->
                                                <?php if (strtolower($appt['status']) === 'pending'):
                                                    $canApprove = ($appt['payment_ready'] ?? 0) == 1 && strtolower($paymentStatus) === 'completed';
                                                    $approveTitle = !$appt['payment_ready'] ? 'Notify availability first' : (strtolower($paymentStatus) !== 'completed' ? 'Payment not completed' : 'Approve Appointment');
                                                ?>
                                                    <form method="POST" style="display:inline" class="mb-1">
                                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                                        <input type="hidden" name="action" value="status">
                                                        <button type="submit" name="status" value="approved" 
                                                                class="btn btn-success-custom btn-sm-custom" <?= $canApprove ? '' : 'disabled style="opacity:0.55;cursor:not-allowed;"' ?>
                                                                title="<?= htmlspecialchars($approveTitle) ?>">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    <?php if(!$canApprove): ?>
                                                        <small class="text-muted d-block mb-1" style="max-width:130px;">
                                                            <?php if(!($appt['payment_ready'] ?? 0)): ?>Need Notify Avail.
                                                            <?php elseif(strtolower($paymentStatus) !== 'completed'): ?>Await Payment
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <!-- 4. Complete (approved only) -->
                                                <?php if (strtolower($appt['status']) === 'approved'): ?>
                                                    <form method="POST" style="display:inline" class="mb-1">
                                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                                        <input type="hidden" name="action" value="status">
                                                        <input type="hidden" name="create_receipt" value="1">
                                                        <button type="submit" name="status" value="completed" class="btn btn-info btn-sm-custom" title="Mark as Completed">
                                                            <i class="fas fa-flag-checkered"></i> Complete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- 5. Cancel (if not canceled/completed) -->
                                                <?php /* Cancel removed by request */ ?>

                                                <!-- 6. Suggest Rebook -->
                                                <button type="button" class="btn btn-warning-custom btn-sm-custom mb-1" onclick="openSuggestRebook(<?= $appt['id'] ?>)" title="Suggest Rebook">
                                                    <i class="fas fa-calendar-alt"></i> Rebook
                                                </button>

                                                <!-- 7. Edit -->
                                                <button type="button" class="btn btn-outline-secondary btn-sm-custom mb-1" 
                                                    data-bs-toggle="modal" data-bs-target="#editAppointmentModal" 
                                                    data-id="<?= $appt['id'] ?>"
                                                    data-date="<?= htmlspecialchars($appt['date']) ?>"
                                                    data-time="<?= htmlspecialchars($appt['time']) ?>"
                                                    data-car="<?= htmlspecialchars($appt['car']) ?>"
                                                    data-service="<?= htmlspecialchars($appt['service']) ?>"
                                                    data-status="<?= htmlspecialchars($appt['status']) ?>"
                                                    title="Edit Appointment">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>

                                                <!-- 8. Delete -->
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this appointment?')">
                                                    <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn btn-danger-custom btn-sm-custom" title="Delete Appointment">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Completed Appointments (separate view) -->
        <?php 
            $completedAppointments = array_values(array_filter($appointments, function($a){
                return isset($a['status']) && strtolower($a['status']) === 'completed';
            }));
        ?>
        <?php if (!empty($completedAppointments)): ?>
        <div class="admin-card mt-4" id="completed-appointments">
            <h2 class="section-title">Completed Appointments</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Customer</th>
                            <th>Car</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Amount</th>
                            <th style="width: 240px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completedAppointments as $appt): 
                            $servicePrice = getServicePrice($appt['service']);
                            $paymentStatus = $appt['payment_status'] ?? 'unpaid';
                            $statusClass = 'status-completed';
                            $paymentClass='bg-danger'; $paymentText='Unpaid';
                            if($paymentStatus==='completed'){ $paymentClass='bg-success'; $paymentText='Paid'; }
                            elseif($paymentStatus==='pending'){ $paymentClass='bg-warning'; $paymentText= ($appt['payment_method']==='cash' ? 'Cash Pending' : 'Pending'); }
                        ?>
                        <tr>
                            <td><?= $appt['id'] ?></td>
                            <td><?= htmlspecialchars($appt['date']) ?></td>
                            <td><?= htmlspecialchars($appt['time']) ?></td>
                            <td>
                                <div><?= htmlspecialchars($appt['username']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($appt['email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($appt['car']) ?></td>
                            <td><?= htmlspecialchars($appt['service']) ?></td>
                            <td><span class="status-badge <?= $statusClass ?>">Completed</span></td>
                            <td>
                                <span class="badge <?= $paymentClass ?>"><?= $paymentText ?></span>
                                <?php if (!empty($appt['transaction_id'])): ?><br><small class="text-muted"><?= substr($appt['transaction_id'],0,8) ?>...</small><?php endif; ?>
                            </td>
                            <td><strong>₱<?= number_format($servicePrice,2) ?></strong></td>
                            <td>
                                <div class="btn-group-vertical btn-group-sm">
                                    <!-- View vehicle monitoring history for this vehicle -->
                                    <button type="button" class="btn btn-info btn-sm-custom mb-1" onclick="viewVehicleHistory(<?= (int)$appt['user_id'] ?>, '<?= htmlspecialchars($appt['car']) ?>')" title="View Vehicle Monitoring"><i class="fas fa-history"></i></button>
                                    <!-- Open Receipt -->
                                    <a class="btn btn-warning btn-sm-custom mb-1" href="admin/create_receipt.php?appointment_id=<?= (int)$appt['id'] ?>" title="Receipt"><i class="fas fa-receipt"></i></a>
                                    <!-- Delete -->
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this appointment?')">
                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger-custom btn-sm-custom" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Deleted Appointments (separate list) -->
        <?php 
            $deletedAppointments = [];
            $delRes = $conn->query("SELECT a.id, a.date, a.time, a.car, a.service, a.status, u.name as username, u.email, a.deleted_at 
                                     FROM appointments a JOIN users u ON a.user_id=u.id 
                                     WHERE a.is_deleted = 1 ORDER BY a.deleted_at DESC LIMIT 100");
            if ($delRes) { while($r=$delRes->fetch_assoc()){ $deletedAppointments[]=$r; } }
        ?>
        <?php if (!empty($deletedAppointments)): ?>
        <div class="admin-card mt-4" id="deleted-appointments">
            <h2 class="section-title">Deleted Appointments</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Deleted At</th>
                            <th>Customer</th>
                            <th>Car</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th style="width: 240px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deletedAppointments as $d): ?>
                        <tr>
                            <td><?= (int)$d['id'] ?></td>
                            <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($d['deleted_at']))) ?></td>
                            <td>
                                <div><?= htmlspecialchars($d['username']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($d['email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($d['car']) ?></td>
                            <td><?= htmlspecialchars($d['service']) ?></td>
                            <td><?= htmlspecialchars($d['status']) ?></td>
                            <td>
                                <div class="btn-group-vertical btn-group-sm">
                                    <form method="POST" style="display:inline" class="mb-1">
                                        <input type="hidden" name="line" value="<?= (int)$d['id'] ?>">
                                        <input type="hidden" name="action" value="restore_deleted">
                                        <button type="submit" class="btn btn-success-custom btn-sm-custom" title="Restore Appointment"><i class="fas fa-undo"></i> Restore</button>
                                    </form>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete this appointment? This cannot be undone.')">
                                        <input type="hidden" name="line" value="<?= (int)$d['id'] ?>">
                                        <input type="hidden" name="action" value="purge_deleted">
                                        <button type="submit" class="btn btn-danger-custom btn-sm-custom" title="Permanently Delete"><i class="fas fa-trash"></i> Purge</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Canceled Appointments (separate view) -->
        <?php 
            $canceledAppointments = array_values(array_filter($appointments, function($a){
                return isset($a['status']) && strtolower($a['status']) === 'canceled';
            }));
        ?>
        <?php if (!empty($canceledAppointments)): ?>
        <div class="admin-card mt-4" id="canceled-appointments">
            <h2 class="section-title">Canceled Appointments</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Customer</th>
                            <th>Car</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($canceledAppointments as $appt): ?>
                        <tr>
                            <td><?= $appt['id'] ?></td>
                            <td><?= htmlspecialchars($appt['date']) ?></td>
                            <td><?= htmlspecialchars($appt['time']) ?></td>
                            <td>
                                <div><?= htmlspecialchars($appt['username']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($appt['email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($appt['car']) ?></td>
                            <td><?= htmlspecialchars($appt['service']) ?></td>
                            <td><span class="status-badge status-canceled">Canceled</span></td>
                            <td>
                                <div class="btn-group-vertical btn-group-sm">
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this appointment?')">
                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger-custom btn-sm-custom" title="Delete"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vehicle Health Monitoring -->
        <!-- Per-Service Detailed Tables -->
        <div class="admin-card mt-4" id="service-tables">
            <h2 class="section-title">Appointments by Service</h2>
            <p class="small text-muted mb-3">Expandable list of all services. Each panel shows only the appointments for that service with full action controls.</p>
            <div class="mb-2">
                <?php foreach(array_keys($serviceCatalog) as $svc): ?>
                    <a href="#" class="badge bg-secondary text-decoration-none me-1 mb-1" onclick="return expandService('<?= md5($svc) ?>')"><?= htmlspecialchars($svc) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="accordion" id="serviceAccordion">
                <?php foreach($serviceCatalog as $svcName => $meta): 
                    $panelId = 'svc_' . md5($svcName);
                    $list = $appointmentsByService[$svcName] ?? [];
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="head_<?= $panelId ?>">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?= $panelId ?>" aria-expanded="false" aria-controls="collapse_<?= $panelId ?>">
                            <span class="me-2 fw-semibold"><?= htmlspecialchars($svcName) ?></span>
                            <span class="badge bg-dark me-2"><?= count($list) ?> appt(s)</span>
                            <small class="text-muted"><?= htmlspecialchars($meta['description']) ?></small>
                        </button>
                    </h2>
                    <div id="collapse_<?= $panelId ?>" class="accordion-collapse collapse" aria-labelledby="head_<?= $panelId ?>" data-bs-parent="#serviceAccordion">
                        <div class="accordion-body p-2">
                            <?php if(empty($list)): ?>
                                <div class="text-center text-muted py-3 small">No appointments for this service.</div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Customer</th>
                                            <th>Car</th>
                                            <th>Status</th>
                                            <th>Payment</th>
                                            <th>Amount</th>
                                            <th style="width:240px">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($list as $appt): 
                                            $servicePrice = getServicePrice($appt['service']);
                                            $paymentStatus = $appt['payment_status'] ?? 'unpaid';
                                            // replicate status/payment display logic
                                            $statusClass = '';
                                            switch(strtolower($appt['status'])) {
                                                case 'pending': $statusClass='status-pending'; break;
                                                case 'approved': $statusClass='status-approved'; break;
                                                case 'completed': $statusClass='status-completed'; break;
                                                case 'canceled': $statusClass='status-canceled'; break;
                                            }
                                            $paymentClass='bg-danger'; $paymentText='Unpaid';
                                            if($paymentStatus==='completed'){ $paymentClass='bg-success'; $paymentText='Paid'; }
                                            elseif($paymentStatus==='pending'){ $paymentClass='bg-warning'; $paymentText= ($appt['payment_method']==='cash' ? 'Cash Pending' : 'Pending'); }
                                            $canApprove = (strtolower($appt['status'])==='pending') && (($appt['payment_ready'] ?? 0)==1) && strtolower($paymentStatus)==='completed';
                                            $approveTitle = !$appt['payment_ready'] ? 'Notify availability first' : (strtolower($paymentStatus)!=='completed' ? 'Awaiting payment' : 'Approve Appointment');
                                        ?>
                                        <tr>
                                            <td><?= $appt['id'] ?></td>
                                            <td><?= htmlspecialchars($appt['date']) ?></td>
                                            <td><?= htmlspecialchars($appt['time']) ?></td>
                                            <td>
                                                <div><?= htmlspecialchars($appt['username']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($appt['email']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($appt['car']) ?></td>
                                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($appt['status']) ?></span></td>
                                            <td>
                                                <span class="badge <?= $paymentClass ?>"><?= $paymentText ?></span>
                                                <?php if ($appt['transaction_id']): ?><br><small class="text-muted"><?= substr($appt['transaction_id'],0,8) ?>...</small><?php endif; ?>
                                            </td>
                                            <td><strong>₱<?= number_format($servicePrice,2) ?></strong></td>
                                            <td>
                                                <div class="btn-group-vertical btn-group-sm">
                                                    <?php 
                                                        $hasChosenMethod = !empty($appt['payment_method_selected']) || !empty($appt['payment_method']);
                                                        $canMarkPaid = ($appt['payment_ready'] ?? 0) == 1 && $hasChosenMethod && strtolower($paymentStatus)!=='completed';
                                                        $markPaidTitle = !$hasChosenMethod ? 'No payment method chosen' : ((($appt['payment_ready'] ?? 0) != 1) ? 'Notify availability first' : 'Mark as Paid');
                                                    ?>
                                                    <!-- Notify Availability -->
                                                    <button type="button" class="btn btn-primary-custom btn-sm-custom mb-1" onclick="notifyAvailability(<?= $appt['id'] ?>)" title="Notify Availability"><i class="fas fa-bell"></i></button>
                                                    <!-- Mark Paid (gated) -->
                                                    <button type="button" class="btn btn-success-custom btn-sm-custom mb-1" <?= $canMarkPaid? '' : 'disabled style="opacity:.55;cursor:not-allowed;"' ?> onclick="<?= $canMarkPaid? 'markAsPaid('.$appt['id'].')':'return false' ?>" title="<?= htmlspecialchars($markPaidTitle) ?>"><i class="fas fa-money-check-alt"></i></button>
                                                    <!-- Approve (after payment) -->
                                                    <?php if(strtolower($appt['status'])==='pending'):
                                                        $canApprove = ($appt['payment_ready'] ?? 0)==1 && strtolower($paymentStatus)==='completed';
                                                        $approveTitle = !$appt['payment_ready'] ? 'Notify availability first' : (strtolower($paymentStatus)!=='completed' ? 'Awaiting payment' : 'Approve Appointment');
                                                    ?>
                                                    <form method="POST" style="display:inline" class="mb-1">
                                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                                        <input type="hidden" name="action" value="status">
                                                        <button type="submit" name="status" value="approved" class="btn btn-success-custom btn-sm-custom" <?= $canApprove? '' : 'disabled style="opacity:.55;cursor:not-allowed;"' ?> title="<?= htmlspecialchars($approveTitle) ?>"><i class="fas fa-check"></i></button>
                                                    </form>
                                                    <?php endif; ?>
                                                    <!-- Complete -->
                                                    <?php if(strtolower($appt['status'])==='approved'): ?>
                                                    <form method="POST" style="display:inline" class="mb-1">
                                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                                        <input type="hidden" name="action" value="status">
                                                        <input type="hidden" name="create_receipt" value="1">
                                                        <button type="submit" name="status" value="completed" class="btn btn-info btn-sm-custom" title="Complete"><i class="fas fa-flag-checkered"></i></button>
                                                    </form>
                                                    <?php endif; ?>
                                                    <!-- Cancel -->
                                                    <?php /* Cancel removed by request */ ?>
                                                    <!-- Rebook -->
                                                    <button type="button" class="btn btn-warning-custom btn-sm-custom mb-1" onclick="openSuggestRebook(<?= $appt['id'] ?>)" title="Suggest Rebook"><i class="fas fa-calendar-alt"></i></button>
                                                    <!-- Delete -->
                                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this appointment?')">
                                                        <input type="hidden" name="line" value="<?= $appt['id'] ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button type="submit" class="btn btn-danger-custom btn-sm-custom" title="Delete"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-heartbeat me-2"></i>Vehicle Health Monitoring
                </h5>
            </div>
            <div class="card-body">
                <?php
                // Get all vehicle monitoring records
                $monitoringQuery = $conn->query(
                    "\n                    SELECT vhr.*, vp.fuel_efficiency, vp.engine_performance, vp.brake_efficiency, vp.overall_condition, vp.notes,\n                           u.name as customer_name, u.email as customer_email\n                    FROM vehicle_health_records vhr\n                    LEFT JOIN vehicle_performance vp ON vhr.user_id = vp.user_id \n                        AND vhr.vehicle_info = vp.vehicle_info \n                        AND vhr.service_date = vp.recorded_date\n                    LEFT JOIN users u ON vhr.user_id = u.id\n                    ORDER BY vhr.created_at DESC\n                    LIMIT 50\n                ");
                
                if ($monitoringQuery && $monitoringQuery->num_rows > 0):
                ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Customer</th>
                                <th>Vehicle</th>
                                <th>Service</th>
                                <th>Health Score</th>
                                <th>Notes</th>
                                <th>Next Service</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($monitor = $monitoringQuery->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($monitor['customer_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($monitor['customer_email']) ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($monitor['vehicle_info']) ?></strong><br>
                                    <small class="text-muted">
                                        Service: <?= date('M d, Y', strtotime($monitor['service_date'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?= htmlspecialchars($monitor['service_type']) ?></span>
                                    <?php if ($monitor['mileage_at_service']): ?>
                                    <br><small class="text-muted"><?= number_format($monitor['mileage_at_service']) ?> km</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <h5 class="mb-1">
                                            <span class="badge bg-<?= $monitor['health_score'] >= 90 ? 'success' : ($monitor['health_score'] >= 75 ? 'info' : 'warning') ?> fs-6">
                                                <?= $monitor['health_score'] ?>%
                                            </span>
                                        </h5>
                                        <small class="text-muted">Health Score</small>
                                    </div>
                                </td>
                                <td>
                                    <div style="max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($monitor['notes'] ?? '') ?>">
                                        <?= htmlspecialchars($monitor['notes'] ?? '—') ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($monitor['next_service_due']): ?>
                                        <?php
                                        $daysUntil = ceil((strtotime($monitor['next_service_due']) - time()) / (60 * 60 * 24));
                                        $badgeClass = $daysUntil <= 0 ? 'danger' : ($daysUntil <= 7 ? 'warning' : 'success');
                                        ?>
                                        <span class="badge bg-<?= $badgeClass ?>"><?= date('M d, Y', strtotime($monitor['next_service_due'])) ?></span>
                                        <br><small class="text-muted"><?= $daysUntil ?> days</small>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- Edit Button -->
                                        <button class="btn btn-warning" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editMonitoringModal"
                                                data-record-id="<?= $monitor['id'] ?>"
                                                data-vehicle="<?= htmlspecialchars($monitor['vehicle_info']) ?>"
                                                data-health-score="<?= $monitor['health_score'] ?>"
                                                
                                                data-next-service="<?= $monitor['next_service_due'] ?>"
                                                data-notes="<?= htmlspecialchars($monitor['notes']) ?>"
                                                title="Edit Monitoring Data">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <!-- View History Button -->
                                        <button class="btn btn-info" 
                                                onclick="viewVehicleHistory(<?= $monitor['user_id'] ?>, '<?= htmlspecialchars($monitor['vehicle_info']) ?>')" 
                                                title="View Vehicle History">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        
                                        <!-- Duplicate Button -->
                                        <button class="btn btn-success" 
                                                onclick="duplicateRecord(<?= $monitor['id'] ?>)" 
                                                title="Duplicate Record">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        
                                        <!-- Delete Button -->
                                        <button class="btn btn-danger" 
                                                onclick="deleteRecord(<?= $monitor['id'] ?>)" 
                                                title="Delete Record">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                    <p class="text-muted">No vehicle monitoring data available.</p>
                    <p class="text-muted">Complete appointments and add monitoring data to see records here.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Appointment Modal -->
    <div class="modal fade" id="createAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Appointment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="createAppointmentForm">
                        <input type="hidden" name="action" value="create_appointment">
                        
                        <div class="mb-3">
                            <label for="user_name" class="form-label">Customer Name</label>
                            <input type="text" class="form-control" id="user_name" name="user_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="user_email" class="form-label">Customer Email</label>
                            <input type="email" class="form-control" id="user_email" name="user_email" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="date" name="date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="time" class="form-label">Time</label>
                                <select class="form-select" id="time" name="time" required>
                                    <?php
                                    $startTime = strtotime('08:00');
                                    $endTime = strtotime('17:00');
                                    $timeStep = 30 * 60; // 30 minutes
                                    
                                    for ($i = $startTime; $i <= $endTime; $i += $timeStep) {
                                        $timeFormatted = date('h:i A', $i);
                                        echo "<option value=\"$timeFormatted\">$timeFormatted</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="car" class="form-label">Car (Model & Plate)</label>
                            <input type="text" class="form-control" id="car" name="car" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="service" class="form-label">Service</label>
                            <select class="form-select" id="service" name="service" required>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= $service ?>"><?= $service ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-plus-circle me-1"></i> Create Appointment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Appointment Modal -->
    <div class="modal fade" id="editAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Appointment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <form method="POST" id="editAppointmentForm">
                        <input type="hidden" name="action" value="edit_appointment">
                        <input type="hidden" name="appointment_id" id="edit_appointment_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="edit_date" name="date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <input type="date" class="form-control" id="edit_date" name="date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_time" class="form-label">Time</label>
                                <select class="form-select" id="edit_time" name="time" required>
                                    <?php
                                    $startTime = strtotime('08:00');
                                    $endTime = strtotime('17:00');
                                    $timeStep = 30 * 60; // 30 minutes
                                    
                                    for ($i = $startTime; $i <= $endTime; $i += $timeStep) {
                                        $timeFormatted = date('h:i A', $i);
                                        echo "<option value=\"$timeFormatted\">$timeFormatted</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_car" class="form-label">Car (Model & Plate)</label>
                            <input type="text" class="form-control" id="edit_car" name="car" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_service" class="form-label">Service</label>
                            <select class="form-select" id="edit_service" name="service" required>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= $service ?>"><?= $service ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= $status ?>"><?= ucfirst($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-1"></i> Update Appointment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Monitoring Data Modal -->
    <div class="modal fade" id="addMonitoringModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-line me-2"></i>Add Vehicle Monitoring Data
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addMonitoringForm">
                        <input type="hidden" name="action" value="add_monitoring_data">
                        
                        <!-- Select Appointment -->
                        <div class="mb-3">
                            <label for="select_appointment" class="form-label">Select Completed Appointment</label>
                            <select class="form-select" id="select_appointment" onchange="populateAppointmentData()" required>
                                <option value="">Choose an appointment...</option>
                                <?php
                                // Get completed appointments without monitoring data
                                $completedAppts = $conn->query("
                                    SELECT a.id, a.user_id, a.name, a.car, a.service, a.date, u.email 
                                    FROM appointments a 
                                    JOIN users u ON a.user_id = u.id 
                                    WHERE a.status = 'completed' 
                                    AND a.id NOT IN (SELECT COALESCE(appointment_id, 0) FROM vehicle_health_records WHERE appointment_id IS NOT NULL)
                                    ORDER BY a.date DESC
                                ");
                                
                                while ($appt = $completedAppts->fetch_assoc()):
                                ?>
                                <option value="<?= $appt['id'] ?>" 
                                        data-user-id="<?= $appt['user_id'] ?>"
                                        data-vehicle="<?= htmlspecialchars($appt['car']) ?>"
                                        data-service="<?= htmlspecialchars($appt['service']) ?>"
                                        data-date="<?= $appt['date'] ?>"
                                        data-customer="<?= htmlspecialchars($appt['name']) ?>">
                                    <?= htmlspecialchars($appt['name']) ?> - <?= htmlspecialchars($appt['car']) ?> - <?= htmlspecialchars($appt['service']) ?> (<?= date('M d, Y', strtotime($appt['date'])) ?>)
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <input type="hidden" name="appointment_id" id="appointment_id">
                                <input type="hidden" name="user_id" id="user_id">
                                
                                <div class="mb-3">
                                    <label for="vehicle_info" class="form-label">Vehicle Info</label>
                                    <input type="text" class="form-control" id="vehicle_info" name="vehicle_info" required readonly>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="service_type" class="form-label">Service Type</label>
                                    <input type="text" class="form-control" id="service_type" name="service_type" required readonly>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="service_date" class="form-label">Service Date</label>
                                    <input type="date" class="form-control" id="service_date" name="service_date" required readonly>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="next_service_due" class="form-label">Next Service Due</label>
                                    <input type="date" class="form-control" id="next_service_due" name="next_service_due" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="mileage_at_service" class="form-label">Mileage at Service (km)</label>
                                    <input type="number" class="form-control" id="mileage_at_service" name="mileage_at_service" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="health_score" class="form-label">Health Score (%)</label>
                                    <input type="range" class="form-range" id="health_score" name="health_score" min="0" max="100" value="85" oninput="updateScoreDisplay('health')">
                                    <div class="text-center"><span id="health_score_display">85</span>%</div>
                                </div>
                                
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes about the vehicle condition..."></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-1"></i>Add Monitoring Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Vehicle Monitoring Data Modal -->
    <div class="modal fade" id="editMonitoringModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Vehicle Monitoring Data
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editMonitoringForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_record_id" name="record_id">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Vehicle:</strong> <span id="edit_vehicle_info"></span>
                        </div>

                        <div class="row">
                            <!-- Health Score -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Health Score (%)</label>
                                <input type="range" class="form-range" id="edit_health_score" name="health_score" 
                                       min="0" max="100" value="85" oninput="updateEditScoreDisplay('health')">
                                <div class="text-center">
                                    <span class="badge bg-primary fs-6" id="edit_health_score_display">85</span>
                                </div>
                            </div>


                            <!-- Next Service Due -->
                            <div class="col-md-6 mb-3">
                                <label for="edit_next_service_due" class="form-label">Next Service Due</label>
                                <input type="date" class="form-control" id="edit_next_service_due" name="next_service_due" required>
                            </div>

                            <!-- Notes -->
                            <div class="col-12 mb-3">
                                <label for="edit_notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="edit_notes" name="notes" rows="3" 
                                          placeholder="Additional notes about the vehicle condition..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-warning" onclick="submitEditMonitoringForm()">
                            <i class="fas fa-save me-1"></i>Update Monitoring Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Set min date to today for date inputs
            const today = new Date().toISOString().split('T')[0];
            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.setAttribute('min', today);
            }
            
            // Handle edit appointment modal
            const editModal = document.getElementById('editAppointmentModal');
            if (editModal) {
                editModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const id = button.getAttribute('data-id');
                    const date = button.getAttribute('data-date');
                    const time = button.getAttribute('data-time');
                    const car = button.getAttribute('data-car');
                    const service = button.getAttribute('data-service');
                    const status = button.getAttribute('data-status');
                    
                    // Set form values
                    document.getElementById('edit_appointment_id').value = id;
                    document.getElementById('edit_date').value = date;
                    document.getElementById('edit_time').value = time;
                    document.getElementById('edit_car').value = car;
                    document.getElementById('edit_service').value = service;
                    document.getElementById('edit_status').value = status.toLowerCase();
                });
            }
            
            // Handle edit monitoring modal
            const editMonitoringModal = document.getElementById('editMonitoringModal');
            if (editMonitoringModal) {
                editMonitoringModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    document.getElementById('edit_record_id').value = button.getAttribute('data-record-id');
                    document.getElementById('edit_vehicle_info').textContent = button.getAttribute('data-vehicle');
                    
                    const healthScore = button.getAttribute('data-health-score') || 85;
                    const notes = button.getAttribute('data-notes') || '';
                    const nextService = button.getAttribute('data-next-service') || '';
                    
                    document.getElementById('edit_health_score').value = healthScore;
                    document.getElementById('edit_health_score_display').textContent = healthScore;
                    document.getElementById('edit_notes').value = notes;
                    document.getElementById('edit_next_service_due').value = nextService;
                });
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert-message');
                alerts.forEach(function(alert) {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 500);
                });
            }, 5000);
        });
        
        // Mark as Paid function
        function markAsPaid(appointmentId) {
            if (confirm('Mark this appointment as paid?')) {
                fetch('manage_appointments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=mark_paid&appointment_id=${appointmentId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('success', data.message);
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert('danger', data.message || 'Failed to mark as paid');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('danger', 'An error occurred while processing the payment');
                });
            }
        }

        // Vehicle History function
        function viewVehicleHistory(userId, vehicleInfo) {
            window.open(`vehicle_monitoring.php?user_id=${userId}&vehicle=${encodeURIComponent(vehicleInfo)}`, '_blank');
        }

        // Expand a specific service panel in the accordion when a badge is clicked
        function expandService(hash) {
            const accordion = document.getElementById('serviceAccordion');
            if (!accordion) return false;

            const targetId = 'collapse_svc_' + hash;
            const target = document.getElementById(targetId);
            if (!target) return false;

            // Collapse any open panels except the target
            const openPanels = accordion.querySelectorAll('.accordion-collapse.show');
            openPanels.forEach(el => {
                if (el.id !== targetId) {
                    const inst = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
                    inst.hide();
                }
            });

            // Show the target panel
            const collapse = bootstrap.Collapse.getOrCreateInstance(target, { toggle: false });
            collapse.show();

            // Scroll into view for usability
            setTimeout(() => {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 150);

            return false; // allow callers to prevent default if used with `return expandService(...)`
        }

        // Notify availability function (admin)
        function notifyAvailability(appointmentId) {
            if (!appointmentId) return;
            fetch('manage_appointments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=notify_availability&appointment_id=${appointmentId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message || 'Customer notified');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showAlert('danger', data.message || 'Failed to notify');
                }
            })
            .catch(() => showAlert('danger', 'Network error sending notification'));
        }

        function openSuggestRebook(appointmentId) {
            document.getElementById('rebook_appt_id').value = appointmentId;
            const select = document.getElementById('rebook_time');
            select.innerHTML = '<option>Loading available times...</option>';
            fetch('manage_appointments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_available_times&appointment_id=${appointmentId}`
            }).then(r => r.json()).then(data => {
                select.innerHTML = '';
                if (data.success && Array.isArray(data.available_times) && data.available_times.length) {
                    select.appendChild(new Option('Select a time to recommend', ''));
                    data.available_times.forEach(t => select.appendChild(new Option(t, t)));
                } else {
                    select.appendChild(new Option('No available times today', ''));
                }
            }).catch(() => {
                select.innerHTML = '<option>Error loading times</option>';
            });
            const modal = new bootstrap.Modal(document.getElementById('suggestRebookModal'));
            modal.show();
        }

        function submitSuggestRebook() {
            const apptId = document.getElementById('rebook_appt_id').value;
            const reason = encodeURIComponent(document.getElementById('rebook_reason').value.trim());
            const time = encodeURIComponent(document.getElementById('rebook_time').value);
            fetch('manage_appointments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=suggest_rebook&appointment_id=${apptId}&reason=${reason}&recommended_time=${time}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('warning', data.message || 'Rebook suggestion sent');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('danger', data.message || 'Failed to send rebook suggestion');
                }
            })
            .catch(() => showAlert('danger', 'Network error sending suggestion'));
        }

        // Duplicate Record function
        function duplicateRecord(recordId) {
            if (confirm('Create a duplicate of this monitoring record?')) {
                fetch('manage_appointments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=duplicate_monitoring_record&record_id=${recordId}`
                })
                .then(response => response.text())
                .then(data => {
                    showAlert('success', 'Record duplicated successfully!');
                    setTimeout(() => location.reload(), 1000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('danger', 'Failed to duplicate record');
                });
            }
        }

        // Delete Record function
        function deleteRecord(recordId) {
            if (confirm('Are you sure you want to delete this monitoring record? This action cannot be undone.')) {
                fetch('manage_appointments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_monitoring_record&record_id=${recordId}`
                })
                .then(response => response.text())
                .then(data => {
                    showAlert('success', 'Record deleted successfully!');
                    setTimeout(() => location.reload(), 1000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('danger', 'Failed to delete record');
                });
            }
        }

        // Cancel feature removed by request

        // Submit Edit Monitoring Form function
        function submitEditMonitoringForm() {
            const form = document.getElementById('editMonitoringForm');
            const formData = new FormData(form);
            formData.append('action', 'update_monitoring_data');
            
            fetch('manage_appointments.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('editMonitoringModal'));
                modal.hide();
                
                showAlert('success', 'Monitoring data updated successfully!');
                setTimeout(() => location.reload(), 1000);
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'Failed to update monitoring data');
            });
        }

        // Populate Appointment Data function
        function populateAppointmentData() {
            const select = document.getElementById('select_appointment');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                document.getElementById('appointment_id').value = selectedOption.value;
                document.getElementById('user_id').value = selectedOption.getAttribute('data-user-id');
                document.getElementById('vehicle_info').value = selectedOption.getAttribute('data-vehicle');
                document.getElementById('service_type').value = selectedOption.getAttribute('data-service');
                document.getElementById('service_date').value = selectedOption.getAttribute('data-date');
                
                // Calculate next service due date
                const serviceDate = new Date(selectedOption.getAttribute('data-date'));
                const serviceType = selectedOption.getAttribute('data-service');
                
                const intervals = {
                    'OIL CHANGE': 90,
                    'PMS': 180,
                    'BRAKE CHECK': 365,
                    'AIRCON': 180,
                    'CHECK ENGINE': 120,
                    'WHEEL ALIGNMENT': 180,
                    'AIR FILTER': 90,
                    'CHECK WIRING': 365
                };
                
                const intervalDays = intervals[serviceType.toUpperCase()] || 120;
                const nextServiceDate = new Date(serviceDate);
                nextServiceDate.setDate(nextServiceDate.getDate() + intervalDays);
                
                document.getElementById('next_service_due').value = nextServiceDate.toISOString().split('T')[0];
            }
        }

        // Update Score Display functions
        function updateScoreDisplay(type) {
            let elementId = '';
            let displayId = '';
            
            switch(type) {
                case 'health':
                    elementId = 'health_score';
                    displayId = 'health_score_display';
                    break;
                case 'engine':
                    elementId = 'engine_performance';
                    displayId = 'engine_performance_display';
                    break;
                case 'brake':
                    elementId = 'brake_efficiency';
                    displayId = 'brake_efficiency_display';
                    break;
                case 'overall':
                    elementId = 'overall_condition';
                    displayId = 'overall_condition_display';
                    break;
            }
            
            const value = document.getElementById(elementId).value;
            document.getElementById(displayId).textContent = value;
        }

        function updateEditScoreDisplay(type) {
            const elementId = 'edit_' + type + (type === 'health' ? '_score' : '_' + type);
            const displayId = elementId + '_display';
            const value = document.getElementById(elementId).value;
            document.getElementById(displayId).textContent = value;
        }

        // Show Alert function
        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert-message alert-${type}`;
            alertDiv.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} me-2"></i>${message}`;
            
            const container = document.querySelector('.container');
            container.insertBefore(alertDiv, container.firstChild);
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                setTimeout(() => alertDiv.remove(), 500);
            }, 3000);
        }
    </script>
</body>
</html>