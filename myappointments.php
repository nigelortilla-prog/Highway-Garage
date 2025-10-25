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

// Ensure created_at column exists for timing cancellation window (2 minutes)
$colCreated = $conn->query("SHOW COLUMNS FROM appointments LIKE 'created_at'");
if ($colCreated && $colCreated->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
// Ensure cancellation metadata columns exist
$colCancelDate = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_date'");
if ($colCancelDate && $colCancelDate->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_date DATE NULL");
}
$colCancelReason = $conn->query("SHOW COLUMNS FROM appointments LIKE 'cancel_reason'");
if ($colCancelReason && $colCancelReason->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancel_reason TEXT NULL");
}
// Ensure recommended time column exists for display
$colRebookTime = $conn->query("SHOW COLUMNS FROM appointments LIKE 'rebook_recommended_time'");
if ($colRebookTime && $colRebookTime->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN rebook_recommended_time VARCHAR(20) NULL");
}
// Cancellation window in seconds (updated to 1 minute 30 seconds)
$CANCEL_WINDOW_SECONDS = 90; // 1 minute 30 seconds

// Process appointment cancellation
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $appId = (int)$_GET['cancel'];

    // Enforce 1-minute window server-side: only cancel if within 60 seconds of creation and status still pending or approved
    $query = "UPDATE appointments SET status = 'Canceled', cancel_date = CURDATE() WHERE id = ? AND user_id = ? AND TIMESTAMPDIFF(SECOND, created_at, NOW()) <= ? AND status IN ('pending','approved')";
    $stmt = $conn->prepare($query);

    if ($stmt === false) {
        $cancelError = 'Database error: ' . htmlspecialchars($conn->error);
    } else {
        $stmt->bind_param("iii", $appId, $userId, $CANCEL_WINDOW_SECONDS);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $cancelSuccess = true;
        } else {
            // Determine if due to window expiry or other condition
            $check = $conn->prepare("SELECT created_at, status FROM appointments WHERE id = ? AND user_id = ?");
            if ($check) {
                $check->bind_param("ii", $appId, $userId);
                $check->execute();
                $res = $check->get_result()->fetch_assoc();
                        if ($res) {
                    $age = time() - strtotime($res['created_at']);
                            if ($age > $CANCEL_WINDOW_SECONDS) {
                                $cancelError = 'Cancellation window (1 minute and 30 seconds) has passed.';
                    } else if (!in_array(strtolower($res['status']), ['pending','approved'])) {
                        $cancelError = 'Appointment can no longer be canceled.';
                    } else {
                        $cancelError = 'Cancellation failed.';
                    }
                } else {
                    $cancelError = 'Appointment not found.';
                }
                $check->close();
            } else {
                $cancelError = 'Unable to verify cancellation.';
            }
        }
        $stmt->close();
    }
}

// Function to get service price
function getServicePrice($service) {
    $servicePrices = array(
        "Aircon Cleaning" => 1200,
        "Air Filter Replacement" => 800,
        "Brake Service" => 1500,
        "Check Engine" => 1000,
        "Check Wiring" => 900,
        "Oil Change" => 1100,
        "PMS" => 2000,
        "Wheel Alignment" => 1300
    );
    return isset($servicePrices[$service]) ? $servicePrices[$service] : 0;
}

// Get user's pending appointments (without mechanics table)
$pendingAppointments = [];
// Ensure payment_ready column exists (compatible across MySQL versions)
$colCheck = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_ready'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN payment_ready TINYINT(1) DEFAULT 0");
}
$query = "SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds FROM appointments WHERE user_id = ? AND status = 'pending' ORDER BY date, time";
$stmt = $conn->prepare($query);

if ($stmt !== false) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pendingAppointments[] = $row;
    }
    $stmt->close();
}

// Get user's approved appointments
$approvedAppointments = [];
$query = "SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds FROM appointments WHERE user_id = ? AND status = 'approved' ORDER BY date, time";
$stmt = $conn->prepare($query);

if ($stmt !== false) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $approvedAppointments[] = $row;
    }
    $stmt->close();
}

// Get user's canceled appointments
$canceledAppointments = [];
$query = "SELECT * FROM appointments WHERE user_id = ? AND status = 'Canceled' ORDER BY date DESC LIMIT 10";
$stmt = $conn->prepare($query);

if ($stmt !== false) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Prefer snapshot stored on appointment at cancellation time
        $row['latest_notification'] = null;
        $row['latest_notification_time'] = null;
        if (!empty($row['cancel_notice_title']) || !empty($row['cancel_notice_message'])) {
            $row['latest_notification'] = trim(($row['cancel_notice_title'] ?? '') . ' — ' . strip_tags($row['cancel_notice_message'] ?? ''));
            $row['latest_notification_time'] = $row['cancel_notice_at'] ?? null;
        }
        $canceledAppointments[] = $row;
    }
    $stmt->close();
}

// Compute mechanic availability for user's appointments (capacity-based)
$maxSlotsPerTime = 5;
$timeSlots = ["08:00 AM","09:00 AM","10:00 AM","01:00 PM","02:00 PM","03:00 PM"];

// Helper to compute availability
function computeAvailabilityFor($conn, $date, $time, $maxSlotsPerTime, $timeSlots) {
    // Count booked for the selected slot
    $q = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date = ? AND time = ? AND LOWER(status) <> 'canceled'");
    $q->bind_param("ss", $date, $time);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    $booked = (int)($r['cnt'] ?? 0);
    $availableCount = max(0, $maxSlotsPerTime - $booked);

    // Compute available times for that date
    $availableTimes = [];
    foreach ($timeSlots as $t) {
        $qt = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date = ? AND time = ? AND LOWER(status) <> 'canceled'");
        $qt->bind_param("ss", $date, $t);
        $qt->execute();
        $rt = $qt->get_result()->fetch_assoc();
        $qt->close();
        $free = max(0, $maxSlotsPerTime - (int)($rt['cnt'] ?? 0));
        if ($free > 0) { $availableTimes[] = $t; }
    }

    return [
        'mech_avail' => $availableCount > 0,
        'mech_avail_count' => $availableCount,
        'available_times' => $availableTimes,
    ];
}

for ($i = 0; $i < count($pendingAppointments); $i++) {
    $date = $pendingAppointments[$i]['date'];
    $time = $pendingAppointments[$i]['time'];
    $res = computeAvailabilityFor($conn, $date, $time, $maxSlotsPerTime, $timeSlots);
    $pendingAppointments[$i] = array_merge($pendingAppointments[$i], $res);
}
for ($i = 0; $i < count($approvedAppointments); $i++) {
    $date = $approvedAppointments[$i]['date'];
    $time = $approvedAppointments[$i]['time'];
    $res = computeAvailabilityFor($conn, $date, $time, $maxSlotsPerTime, $timeSlots);
    $approvedAppointments[$i] = array_merge($approvedAppointments[$i], $res);
}

// Count appointments for each category
$pendingCount = count($pendingAppointments);
$approvedCount = count($approvedAppointments);
$canceledCount = count($canceledAppointments);

// Function to format appointment time
function formatAppointmentTime($date, $time) {
    $appointmentDate = date('F j, Y', strtotime($date));
    return $appointmentDate . ' at ' . $time;
}

// Get active tab from URL or default to pending
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';

// Add this at the top of myappointments.php after session_start()
if (isset($_SESSION['booking_confirmed'])) {
    $bookingMessage = $_SESSION['booking_message'];
    $transactionId = $_SESSION['booking_transaction'];
    unset($_SESSION['booking_confirmed'], $_SESSION['booking_message'], $_SESSION['booking_transaction']);
    // Display success message here
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments | Vehicle Service</title>
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
        }
        
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: -1;
        }
        
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }
        
        .page-header h1 {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            animation: fadeInDown 0.7s ease-out;
        }
        
        .page-header::after {
            content: "";
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 4px;
            background: var(--primary);
            border-radius: 2px;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            padding: 10px 18px;
            background: var(--primary);
            color: var(--dark);
            border-radius: 30px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            margin-bottom: 20px;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
        }
        
        .back-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        }
        
        .back-button i {
            margin-right: 8px;
        }
        
        .tab-nav {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            border-radius: 50px;
            background: rgba(0,0,0,0.5);
            padding: 5px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }
        
        .tab-link {
            padding: 12px 30px;
            margin: 0 5px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex: 1;
            text-align: center;
        }
        
        .tab-link.active {
            background: var(--primary);
            color: var(--dark);
            box-shadow: 0 4px 10px rgba(240, 192, 64, 0.3);
            transform: scale(1.05);
        }
        
        .tab-link:hover:not(.active) {
            background: rgba(255,255,255,0.1);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .appointment-count {
            background: var(--dark);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 25px;
            min-height: 25px;
        }
        
        .tab-link.active .appointment-count {
            background: var(--dark);
            color: var(--primary);
        }
        
        .appointment-card {
            background: rgba(0,0,0,0.7);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            border-left: 5px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .appointment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.4);
        }
        
        .appointment-card.pending {
            border-left-color: var(--warning);
        }
        
        .appointment-card.approved {
            border-left-color: var(--success);
        }
        
        .appointment-card.canceled {
            border-left-color: var(--danger);
        }
        
        .appointment-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, rgba(255,255,255,0.05), transparent);
            z-index: 0;
        }
        
        .appointment-card > * {
            position: relative;
            z-index: 1;
        }
        
        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .appointment-title {
            font-size: 1.4rem;
            font-weight: bold;
            color: var(--primary);
            margin: 0;
        }
        
        .appointment-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: var(--warning);
            border: 1px solid var(--warning);
        }
        
        .status-approved {
            background: rgba(40, 167, 69, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }
        
        .status-canceled {
            background: rgba(220, 53, 69, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .appointment-time {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .appointment-time i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 1.1rem;
        }
        
        .appointment-details {
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .appointment-info {
            display: flex;
            margin-bottom: 10px;
            align-items: flex-start;
        }
        
        .appointment-info:last-child {
            margin-bottom: 0;
        }
        
        .info-label {
            flex: 0 0 120px;
            color: var(--muted);
            font-size: 0.9rem;
        }
        
        .info-value {
            flex: 1;
            font-weight: 500;
        }
        
        .appointment-mechanic {
            display: flex;
            align-items: center;
            background: rgba(23, 162, 184, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 3px solid var(--info);
            margin-bottom: 15px;
        }
        
        .mechanic-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--dark);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: var(--info);
            font-size: 1.2rem;
        }
        
        .mechanic-info {
            flex: 1;
        }
        
        .mechanic-name {
            font-weight: bold;
            color: var(--info);
        }
        
        .mechanic-title {
            font-size: 0.85rem;
            color: var(--muted);
        }
        
        .appointment-price {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--primary);
            text-align: right;
            margin: 15px 0;
        }
        
        .appointment-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 30px;
            border: none;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: var(--dark);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: rgba(240, 192, 64, 0.1);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .btn-danger:hover {
            background: rgba(220, 53, 69, 0.2);
            transform: translateY(-2px);
        }
        
        .no-appointments {
            text-align: center;
            padding: 40px 20px;
            background: rgba(0,0,0,0.5);
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .no-appointments i {
            font-size: 3rem;
            color: var(--muted);
            margin-bottom: 15px;
            display: block;
        }
        
        .no-appointments p {
            font-size: 1.2rem;
            color: var(--light);
            margin-bottom: 20px;
        }
        
        .price-section {
            background: rgba(0,0,0,0.3);
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .price-label {
            font-size: 0.9rem;
            color: var(--muted);
        }
        
        .price-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }
        
        .cancel-note {
            color: var(--muted);
            font-size: 0.85rem;
            font-style: italic;
            margin-top: 5px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background: #222;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            position: relative;
            animation: scaleIn 0.3s ease;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            color: white;
            transform: rotate(90deg);
        }
        
        .modal-title {
            color: var(--danger);
            font-size: 1.5rem;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        /* Notification for cancellation success */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: rgba(40, 167, 69, 0.9);
            color: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            z-index: 1000;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.5s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .notification.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .notification i {
            font-size: 1.2rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0 15px;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
            
            .tab-nav {
                flex-direction: column;
                background: none;
                box-shadow: none;
                padding: 0;
                gap: 10px;
            }
            
            .tab-link {
                width: 100%;
                border-radius: 8px;
                background: rgba(0,0,0,0.5);
                margin: 0;
            }
            
            .appointment-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .appointment-status {
                align-self: flex-start;
            }
            
            .appointment-info {
                flex-direction: column;
            }
            
            .info-label {
                margin-bottom: 5px;
                flex: none;
            }
            
            .appointment-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <a href="index.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
        
        <div class="page-header">
            <h1>My Appointments</h1>
        </div>
        
        <?php if(isset($cancelSuccess)): ?>
        <div class="notification" id="successNotification">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>Success!</strong> Your appointment has been canceled.
            </div>
        </div>
        <?php elseif(isset($cancelError)): ?>
        <div class="notification" id="errorNotification" style="background:rgba(220,53,69,0.9);">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <strong>Error:</strong> <?= htmlspecialchars($cancelError) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="tab-nav">
            <a href="?tab=pending" class="tab-link <?= $activeTab == 'pending' ? 'active' : '' ?>">
                <i class="fas fa-hourglass-half"></i> Pending
                <span class="appointment-count"><?= $pendingCount ?></span>
            </a>
            <a href="?tab=approved" class="tab-link <?= $activeTab == 'approved' ? 'active' : '' ?>">
                <i class="fas fa-check-circle"></i> Approved
                <span class="appointment-count"><?= $approvedCount ?></span>
            </a>
            <a href="?tab=canceled" class="tab-link <?= $activeTab == 'canceled' ? 'active' : '' ?>">
                <i class="fas fa-times-circle"></i> Canceled
                <span class="appointment-count"><?= $canceledCount ?></span>
            </a>
        </div>
        
        <!-- Pending Appointments Tab -->
        <div class="tab-content <?= $activeTab == 'pending' ? 'active' : '' ?>" id="pendingTab">
            <?php if ($pendingCount > 0): ?>
                <?php foreach ($pendingAppointments as $appointment): 
                    $ageSeconds = isset($appointment['age_seconds']) ? (int)$appointment['age_seconds'] : 9999;
                    $remaining = max(0, $CANCEL_WINDOW_SECONDS - $ageSeconds);
                    $canCancelNow = $remaining > 0; ?>
                    <div class="appointment-card pending">
                        <div class="appointment-header">
                            <h3 class="appointment-title"><?= htmlspecialchars($appointment['service']) ?></h3>
                            <span class="appointment-status status-pending">
                                <i class="fas fa-clock"></i> Awaiting Approval
                            </span>
                        </div>
                        
                        <div class="appointment-time">
                            <i class="fas fa-calendar-alt"></i>
                            <span><?= formatAppointmentTime($appointment['date'], $appointment['time']) ?></span>
                        </div>
                        
                        <div class="appointment-details">
                            <div class="appointment-info">
                                <div class="info-label">Vehicle:</div>
                                <div class="info-value"><?= htmlspecialchars($appointment['car']) ?></div>
                            </div>
                            <div class="appointment-info">
                                <div class="info-label">Mechanic Availability:</div>
                                <div class="info-value">
                                    <?php 
                                        $paymentReady = isset($appointment['payment_ready']) ? (int)$appointment['payment_ready'] : 0;
                                        $hasComputed = array_key_exists('mech_avail', $appointment);
                                        $isAvailableByCapacity = $hasComputed ? (bool)$appointment['mech_avail'] : null;
                                        if ($paymentReady === 1) {
                                            echo '<span style="color:#28a745; font-weight:600;">Available</span>';
                                        } else if ($hasComputed && $isAvailableByCapacity === false) {
                                            echo '<span style="color:#ffc107; font-weight:600;">No mechanic available</span>';
                                            if (!empty($appointment['available_times'])) {
                                                echo '<br><small>Try: ' . htmlspecialchars(implode(', ', $appointment['available_times'])) . '</small>';
                                            }
                                        } else if ($hasComputed) {
                                            echo '<span class="text-muted">Awaiting confirmation</span>';
                                        } else {
                                            echo '<span class="text-muted">Checking...</span>';
                                        }
                                    ?>
                                </div>
                            </div>
                            <div class="appointment-info">
                                <div class="info-label">Booked On:</div>
                                <div class="info-value"><?= date('F j, Y \a\t g:i A', strtotime($appointment['created_at'])) ?></div>
                            </div>
                            <?php if (!empty($appointment['comments'])): ?>
                            <div class="appointment-info">
                                <div class="info-label">Comments:</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($appointment['comments'])) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="price-section">
                            <div class="price-label">Reservation Fee</div>
                            <div class="price-value">₱<?= number_format(getServicePrice($appointment['service'])) ?></div>
                        </div>
                        
                        <div class="appointment-actions">
                            <?php if (!empty($canCancelNow) && $canCancelNow): ?>
                                <a href="#" class="btn btn-danger cancel-btn" onclick="confirmCancel(<?= (int)$appointment['id'] ?>); return false;">
                                    <i class="fas fa-times-circle"></i>
                                    Cancel (<span class="countdown"><?= (int)$remaining ?></span>s)
                                </a>
                            <?php endif; ?>
                            <?php 
                                $pmSelected = isset($appointment['payment_method_selected']) && $appointment['payment_method_selected'] !== '';
                            ?>
                            <?php if (($paymentReady ?? 0) === 1 && !$pmSelected): ?>
                                <a href="payment.php?appointment_id=<?= (int)$appointment['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-credit-card"></i> Proceed to Payment
                                </a>
                            <?php elseif (($paymentReady ?? 0) === 1 && $pmSelected): ?>
                                <span style="display:inline-block; padding:10px 14px; border:1px solid rgba(255,255,255,0.2); border-radius:25px; color:#ddd;">Payment method selected: <strong style="text-transform:capitalize; color:#fff;"><?= htmlspecialchars($appointment['payment_method_selected']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-appointments">
                    <i class="fas fa-calendar-times"></i>
                    <p>No pending appointments</p>
                    <a href="index.php#appointment" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Book New Appointment
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Approved Appointments Tab -->
        <div class="tab-content <?= $activeTab == 'approved' ? 'active' : '' ?>" id="approvedTab">
            <?php if ($approvedCount > 0): ?>
                <?php foreach ($approvedAppointments as $appointment): 
                    $ageSeconds = isset($appointment['age_seconds']) ? (int)$appointment['age_seconds'] : 9999;
                    $remaining = max(0, $CANCEL_WINDOW_SECONDS - $ageSeconds);
                    $canCancelNow = $remaining > 0; ?>
                    <div class="appointment-card approved">
                        <div class="appointment-header">
                            <h3 class="appointment-title"><?= htmlspecialchars($appointment['service']) ?></h3>
                            <span class="appointment-status status-approved">
                                <i class="fas fa-check-circle"></i> Approved
                            </span>
                        </div>
                        
                        <div class="appointment-time">
                            <i class="fas fa-calendar-check"></i>
                            <span><?= formatAppointmentTime($appointment['date'], $appointment['time']) ?></span>
                        </div>
                        
                        <div class="appointment-details">
                            <div class="appointment-info">
                                <div class="info-label">Vehicle:</div>
                                <div class="info-value"><?= htmlspecialchars($appointment['car']) ?></div>
                            </div>
                            <div class="appointment-info">
                                <div class="info-label">Mechanic Availability:</div>
                                <div class="info-value">
                                    <?php 
                                        $paymentReady = isset($appointment['payment_ready']) ? (int)$appointment['payment_ready'] : 0;
                                        $hasComputed = array_key_exists('mech_avail', $appointment);
                                        $isAvailableByCapacity = $hasComputed ? (bool)$appointment['mech_avail'] : null;
                                        if ($paymentReady === 1) {
                                            echo '<span style="color:#28a745; font-weight:600;">Available</span>';
                                        } else if ($hasComputed && $isAvailableByCapacity === false) {
                                            echo '<span style="color:#ffc107; font-weight:600;">No mechanic available</span>';
                                            if (!empty($appointment['available_times'])) {
                                                echo '<br><small>Try: ' . htmlspecialchars(implode(', ', $appointment['available_times'])) . '</small>';
                                            }
                                        } else if ($hasComputed) {
                                            echo '<span class="text-muted">Awaiting confirmation</span>';
                                        } else {
                                            echo '<span class="text-muted">Checking...</span>';
                                        }
                                    ?>
                                </div>
                            </div>
                            <div class="appointment-info">
                                <div class="info-label">Booked On:</div>
                                <div class="info-value"><?= date('F j, Y \a\t g:i A', strtotime($appointment['created_at'])) ?></div>
                            </div>
                            <div class="appointment-info">
                                <div class="info-label">Status:</div>
                                <div class="info-value">Ready for service</div>
                            </div>
                            <?php if (!empty($appointment['comments'])): ?>
                            <div class="appointment-info">
                                <div class="info-label">Comments:</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($appointment['comments'])) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="price-section">
                            <div class="price-label">Reservation Fee</div>
                            <div class="price-value">₱<?= number_format(getServicePrice($appointment['service'])) ?></div>
                        </div>
                        
                        <div class="appointment-actions">
                            <?php if (!empty($canCancelNow) && $canCancelNow): ?>
                                <a href="#" class="btn btn-danger cancel-btn" onclick="confirmCancel(<?= (int)$appointment['id'] ?>); return false;">
                                    <i class="fas fa-times-circle"></i>
                                    Cancel (<span class="countdown"><?= (int)$remaining ?></span>s)
                                </a>
                            <?php endif; ?>
                            <?php 
                                $pmSelected = isset($appointment['payment_method_selected']) && $appointment['payment_method_selected'] !== '';
                            ?>
                            <?php if (($paymentReady ?? 0) === 1 && !$pmSelected): ?>
                                <a href="payment.php?appointment_id=<?= (int)$appointment['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-credit-card"></i> Proceed to Payment
                                </a>
                            <?php elseif (($paymentReady ?? 0) === 1 && $pmSelected): ?>
                                <span style="display:inline-block; padding:10px 14px; border:1px solid rgba(255,255,255,0.2); border-radius:25px; color:#ddd;">Payment method selected: <strong style="text-transform:capitalize; color:#fff;"><?= htmlspecialchars($appointment['payment_method_selected']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-appointments">
                    <i class="fas fa-clipboard-check"></i>
                    <p>No approved appointments</p>
                    <a href="index.php#appointment" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Book New Appointment
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Canceled Appointments Tab -->
        <div class="tab-content <?= $activeTab == 'canceled' ? 'active' : '' ?>" id="canceledTab">
            <?php if ($canceledCount > 0): ?>
                <?php foreach ($canceledAppointments as $appointment): ?>
                    <div class="appointment-card canceled">
                        <div class="appointment-actions"></div>
                            <div class="appointment-info">
                                <div class="info-label">Booking Info:</div>
                                <div class="info-value">
                                    <?= htmlspecialchars($appointment['service']) ?> — <?= htmlspecialchars($appointment['car']) ?>
                                    <br><small class="text-muted">Originally set for <?= formatAppointmentTime($appointment['date'], $appointment['time']) ?></small>
                                    <?php if (!empty($appointment['rebook_recommended_time'])): ?>
                                        <br><small class="text-muted">Recommended time: <strong><?= htmlspecialchars($appointment['rebook_recommended_time']) ?></strong></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                                <div class="info-label">Canceled On:</div>
                                <div class="info-value">
                                    <?= !empty($appointment['cancel_date']) 
                                        ? date('F j, Y', strtotime($appointment['cancel_date'])) 
                                        : 'Not specified' ?>
                                </div>
                            </div>
                            <?php if (!empty($appointment['cancel_reason'])): ?>
                            <div class="appointment-info">
                                <div class="info-label">Reason:</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($appointment['cancel_reason'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php /* Recommended time now shown under Booking Info to keep it together */ ?>
                            <?php if (!empty($appointment['latest_notification'])): ?>
                            <div class="appointment-info" style="margin-top:8px;">
                                <div class="info-label">Notification:</div>
                                <div class="info-value">
                                    <?php 
                                        $parts = explode(' — ', $appointment['latest_notification'], 2);
                                        $ntitle = $parts[0] ?? '';
                                        $nmsg = $parts[1] ?? '';
                                    ?>
                                    <div style="font-weight:600; color:#fff;"><?= htmlspecialchars($ntitle) ?></div>
                                    <div style="opacity:.95; line-height:1.5;">
                                        <?= htmlspecialchars($nmsg) ?>
                                    </div>
                                    <?php if (!empty($appointment['latest_notification_time'])): ?>
                                        <small class="text-muted">Sent on <?= date('M d, Y g:i A', strtotime($appointment['latest_notification_time'])) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($appointment['comments'])): ?>
                            <div class="appointment-info">
                                <div class="info-label">Comments:</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($appointment['comments'])) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="appointment-actions">
                            <a href="index.php#appointment" class="btn btn-primary">
                                <i class="fas fa-redo"></i> Book Again
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-appointments">
                    <i class="fas fa-ban"></i>
                    <p>No canceled appointments</p>
                    <a href="index.php#appointment" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Book New Appointment
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Cancel Confirmation Modal -->
    <div class="modal" id="cancelModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h3 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Cancel Appointment</h3>
            <div class="modal-body">
                <p>Are you sure you want to cancel this appointment? This action cannot be undone.</p>
                <p class="cancel-note">Note: Cancellations within 24 hours of the appointment time may be subject to a cancellation fee.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">
                    <i class="fas fa-times"></i> No, Keep It
                </button>
                <a href="#" id="confirmCancelBtn" class="btn btn-danger">
                    <i class="fas fa-check"></i> Yes, Cancel Appointment
                </a>
            </div>
        </div>
    </div>
    
    <script>
    // Modal functions
    function confirmCancel(appointmentId) {
        const modal = document.getElementById("cancelModal");
        const confirmBtn = document.getElementById("confirmCancelBtn");
        confirmBtn.href = `myappointments.php?cancel=${appointmentId}&tab=<?= $activeTab ?>`;
        modal.style.display = "flex";
    }
    
    function closeModal() {
        const modal = document.getElementById("cancelModal");
        modal.style.display = "none";
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById("cancelModal");
        if (event.target === modal) {
            closeModal();
        }
    };
    
    // Auto-hide notification after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const success = document.getElementById('successNotification');
        const errorN = document.getElementById('errorNotification');
        [success, errorN].forEach(el => { if (el) { el.classList.add('show'); setTimeout(()=>{ el.classList.remove('show'); }, 5000); }});
        // Countdown for cancel buttons
        const cancelButtons = document.querySelectorAll('.cancel-btn');
        if (cancelButtons.length) {
            const tick = () => {
                cancelButtons.forEach(btn => {
                    const span = btn.querySelector('.countdown');
                    if (!span) return;
                    let remaining = parseInt(span.textContent,10);
                    if (remaining > 0) {
                        remaining -= 1;
                        span.textContent = remaining;
                        if (remaining === 0) {
                            // Remove button entirely when window passes
                            btn.parentElement.removeChild(btn);
                        }
                    }
                });
                // Continue ticking while at least one active countdown remains
                if (document.querySelectorAll('.cancel-btn .countdown').length) {
                    setTimeout(tick, 1000);
                }
            };
            setTimeout(tick, 1000);
        }
    });
    </script>
</body>
</html>

