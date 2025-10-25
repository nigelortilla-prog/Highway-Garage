<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Create payments table if it doesn't exist
$createPaymentTable = "CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    payment_status VARCHAR(20) DEFAULT 'pending',
    transaction_id VARCHAR(100) UNIQUE,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_details JSON,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($createPaymentTable);

// Add payment_status column to appointments if it doesn't exist (portable)
$colCheckStatus = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_status'");
if ($colCheckStatus && $colCheckStatus->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN payment_status VARCHAR(20) DEFAULT 'unpaid'");
}

// Add selected payment method column to appointments (portable)
$colCheck = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_method_selected'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN payment_method_selected VARCHAR(50) NULL");
}

$user_id = $_SESSION['user']['id'];
$errorMessage = '';
$paymentSuccess = false;

// Handle payment processing (record chosen method but keep unpaid)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $appointmentId = (int)$_POST['appointment_id'];
    $amount = (float)$_POST['amount'];
    $paymentMethod = $_POST['payment_method'];
    $transactionId = 'TXN_' . strtoupper(substr(md5(time() . rand()), 0, 8));
    
    // Payment details based on method
    $paymentDetails = [];
    $paymentStatus = 'completed';
    
        if ($paymentMethod === 'cash') {
        $paymentDetails = [
            'payment_type' => 'cash_on_service',
            'instructions' => 'Pay at service center',
            'due_amount' => $amount
        ];
        $paymentStatus = 'pending';
    }
    
    // Only proceed if no validation errors (for GCash) or if it's cash
    if (empty($errorMessage) || $paymentMethod === 'cash') {
        // Record user's chosen method on the appointment, keep as unpaid
        $upd = $conn->prepare("UPDATE appointments SET payment_status = 'unpaid', payment_method_selected = ? WHERE id = ? AND user_id = ?");
        $upd->bind_param("sii", $paymentMethod, $appointmentId, $user_id);
        if ($upd->execute()) {
            $paymentSuccess = true;
            $successMethod = $paymentMethod;
            $successAmount = $amount;
            $successTransactionId = $transactionId;
            // Optionally, we could also create a notification for admin later if an admin user id is modeled
        } else {
            $errorMessage = 'Failed to record payment choice. Please try again.';
        }
        $upd->close();
    }
}


// Get appointment details
$appointment = null;
if (isset($_GET['appointment_id'])) {
    $appointmentId = (int)$_GET['appointment_id'];
} else {
    // Get latest appointment for this user
    $stmt = $conn->prepare("SELECT * FROM appointments WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    $stmt->close();
    
    if ($appointment) {
        $appointmentId = $appointment['id'];
    }
}

if (!isset($appointment) && isset($appointmentId)) {
    $query = "SELECT * FROM appointments WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $appointmentId, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    $stmt->close();
}

if (!$appointment) {
    header("Location: index.php");
    exit();
}

// Enforce that payment is only available when unlocked by admin notification
// Ensure column exists (compatible across MySQL versions)
$colCheck = $conn->query("SHOW COLUMNS FROM appointments LIKE 'payment_ready'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE appointments ADD COLUMN payment_ready TINYINT(1) DEFAULT 0");
}

// Enforce payment_ready only on normal page load (not after successful POST submission)
if (!isset($paymentSuccess) || $paymentSuccess !== true) {
    if (!empty($appointment['id'])) {
        $chk = $conn->prepare("SELECT payment_ready FROM appointments WHERE id = ? AND user_id = ?");
        $chk->bind_param("ii", $appointment['id'], $user_id);
        $chk->execute();
        $cr = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cr || (int)$cr['payment_ready'] !== 1) {
            header("Location: myappointments.php?tab=pending&msg=payment_locked");
            exit();
        }
    }
}

// Service prices
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
    return isset($servicePrices[$service]) ? $servicePrices[$service] : 1000;
}

$servicePrice = getServicePrice($appointment['service']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment | Vehicle Service</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #f0c040;
            --primary-dark: #d4a830;
            --dark: #222;
            --light: #f8f9fa;
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
            background: rgba(0,0,0,0.85);
            z-index: -1;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .error-alert {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid var(--danger);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            color: #fff;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .payment-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, rgba(240, 192, 64, 0.2), rgba(212, 168, 48, 0.2));
            border-radius: 20px;
            border: 2px solid var(--primary);
        }
        
        .payment-header h1 {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        .payment-header p {
            font-size: 1.2rem;
            color: #ddd;
            margin: 0;
        }
        
        .payment-container {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .payment-form {
            background: rgba(0,0,0,0.8);
            border-radius: 20px;
            padding: 30px;
            border: 2px solid var(--primary);
        }
        
        .order-summary {
            background: rgba(0,0,0,0.8);
            border-radius: 20px;
            padding: 30px;
            border: 2px solid var(--info);
            height: fit-content;
            position: sticky;
            top: 20px;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 10px;
        }
        
        .appointment-details {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1), rgba(23, 162, 184, 0.05));
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 4px solid var(--info);
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            font-weight: bold;
            font-size: 1.3rem;
            color: var(--primary);
        }
        
        .detail-label {
            font-weight: 500;
            color: #ddd;
        }
        
        .detail-value {
            font-weight: bold;
            color: white;
        }
        
        .payment-methods {
            display: grid;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .payment-method {
            background: linear-gradient(135deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03));
            border: 2px solid transparent;
            border-radius: 15px;
            padding: 25px;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .payment-method::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(240, 192, 64, 0.1), transparent);
            transition: left 0.5s;
        }
        
        .payment-method:hover::before {
            left: 100%;
        }
        
        .payment-method:hover {
            background: rgba(255,255,255,0.12);
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(240, 192, 64, 0.25);
        }
        
        .payment-method.active {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(240, 192, 64, 0.2), rgba(240, 192, 64, 0.08));
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(240, 192, 64, 0.3);
        }
        
        /* Removed gcash-active styles */
        
        .payment-method input[type="radio"] {
            display: none;
        }
        
        .payment-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark);
            font-size: 1.8rem;
            flex-shrink: 0;
            box-shadow: 0 6px 20px rgba(240, 192, 64, 0.4);
        }
        
        /* Removed gcash icon style */
        
        .payment-details {
            flex: 1;
        }
        
        .payment-name {
            font-weight: bold;
            font-size: 1.3rem;
            margin-bottom: 8px;
            color: white;
        }
        
        .payment-description {
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.4;
        }
        
        .payment-fields {
            display: none;
            margin-top: 25px;
            padding: 25px;
            background: linear-gradient(135deg, rgba(0,0,0,0.4), rgba(0,0,0,0.2));
            border-radius: 15px;
            border: 1px solid rgba(255,255,255,0.1);
            animation: slideDown 0.3s ease;
        }
        
        .payment-fields.active {
            display: block;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 120px;
            gap: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--light);
        }
        
        .form-input {
            width: 100%;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255,255,255,0.15);
            box-shadow: 0 0 20px rgba(240, 192, 64, 0.2);
        }
        
        /* Removed gcash input focus style */
        
        .form-input::placeholder {
            color: rgba(255,255,255,0.5);
        }
        
        .pay-button {
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--dark);
            border: none;
            border-radius: 15px;
            font-size: 1.3rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }
        
        /* Removed gcash button style */
        
        .pay-button::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .pay-button:hover::before {
            left: 100%;
        }
        
        .pay-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(240, 192, 64, 0.4);
        }
        
        /* Removed gcash button hover */
        
        .pay-button:disabled {
            background: var(--muted);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .pay-button:disabled::before {
            display: none;
        }
        
        .processing-button {
            background: var(--muted) !important;
            cursor: not-allowed !important;
            transform: none !important;
        }
        
        .success-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .success-content {
            background: linear-gradient(135deg, rgba(0,0,0,0.9), rgba(0,0,0,0.8));
            border-radius: 25px;
            padding: 50px;
            text-align: center;
            border: 3px solid var(--success);
            max-width: 500px;
            width: 90%;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--success), #1e7e34);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 2.5rem;
            color: white;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .transaction-details {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            border-left: 4px solid var(--primary);
        }
        
        .continue-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: var(--dark);
            border: none;
            padding: 15px 30px;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .continue-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(240, 192, 64, 0.3);
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px;
            background: rgba(40, 167, 69, 0.2);
            border-radius: 10px;
            margin-top: 20px;
            color: var(--success);
            font-size: 1rem;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.2);
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        /* Removed gcash info block style */
        
        .input-icon {
            position: relative;
        }
        
        .input-icon .fas {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            z-index: 1;
        }
        
        .input-icon .form-input {
            padding-left: 45px;
        }
        
        @media (max-width: 768px) {
            .payment-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .order-summary {
                position: static;
                order: -1;
            }
            
            .payment-header h1 {
                font-size: 2rem;
            }
            
            .payment-method {
                flex-direction: column;
                text-align: center;
                gap: 15px;
                padding: 20px;
            }
            
            .success-content {
                padding: 30px;
            }
            
            .payment-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php if ($paymentSuccess): ?>
    <div class="success-modal" id="successModal">
        <div class="success-content">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h2 style="color: var(--success); margin-bottom: 15px;">Payment Successful!</h2>
            <p style="margin-bottom: 20px; color: #ddd;">
                Your payment has been processed successfully. 
                <?php if ($successMethod === 'cash'): ?>
                Please bring the exact amount when you arrive for your appointment.
                <?php else: ?>
                You will receive a confirmation message shortly.
                <?php endif; ?>
            </p>
            <div class="transaction-details">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span>Transaction ID:</span>
                    <strong><?= htmlspecialchars($successTransactionId) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span>Amount:</span>
                    <strong>₱<?= number_format($successAmount, 2) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span>Method:</span>
                    <strong style="text-transform: capitalize;"><?= str_replace('_', ' ', $successMethod) ?></strong>
                </div>
                <?php if ($successMethod === 'gcash' && isset($successReferenceNumber)): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span>GCash Reference:</span>
                    <strong><?= htmlspecialchars($successReferenceNumber) ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <button onclick="redirectToAppointments()" class="continue-btn">
                <i class="fas fa-calendar-check"></i> View My Appointments
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="container">
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
        
        <?php if (!empty($errorMessage)): ?>
        <div class="error-alert">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Payment Error:</strong> <?= htmlspecialchars($errorMessage) ?>
        </div>
        <?php endif; ?>
        
        <div class="payment-header">
            <h1><i class="fas fa-credit-card"></i> Complete Your Payment</h1>
            <p>Choose your preferred payment method for your vehicle service appointment</p>
        </div>
        
        <div class="payment-container">
            <div class="payment-form">
                <h2 class="section-title">
                    <i class="fas fa-wallet"></i> Payment Options
                </h2>
                
                <form method="POST" id="paymentForm">
                    <input type="hidden" name="appointment_id" value="<?= $appointment['id'] ?>">
                    <input type="hidden" name="amount" value="<?= $servicePrice ?>">
                    
                    <div class="payment-methods">
                        <div class="payment-method" onclick="selectPaymentMethod('cash')">
                            <input type="radio" name="payment_method" value="cash" id="cash">
                            <div class="payment-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="payment-details">
                                <div class="payment-name">Cash Payment</div>
                                <div class="payment-description">Pay with cash when you arrive at our service center - No additional fees required</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Cash Fields -->
                    <div class="payment-fields" id="cash_fields">
                        <div style="background: rgba(255, 193, 7, 0.15); padding: 25px; border-radius: 15px; border-left: 4px solid var(--warning);">
                            <h4 style="color: var(--warning); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-info-circle"></i> Cash Payment Instructions
                            </h4>
                            <ul style="color: #ddd; line-height: 1.6; margin: 0; padding-left: 20px;">
                                <li>Bring the exact amount: <strong style="color: var(--primary);">₱<?= number_format($servicePrice, 2) ?></strong></li>
                                <li>Payment is due when you arrive for your appointment</li>
                                <li>Please arrive 15 minutes early for payment processing</li>
                                <li>Receipt will be provided upon payment completion</li>
                                <li>No additional service charges for cash payments</li>
                            </ul>
                        </div>
                    </div>
                    
                    <button type="submit" name="process_payment" class="pay-button" id="payButton" disabled>
                        <i class="fas fa-lock"></i>
                        <span id="payButtonText">Select Payment Method</span>
                    </button>
                    
                    <div class="security-badge">
                        <i class="fas fa-shield-alt"></i>
                        Secured payment processing with advanced encryption
                    </div>
                </form>
            </div>
            
            <div class="order-summary">
                <h2 class="section-title" style="border-color: var(--info); color: var(--info);">
                    <i class="fas fa-file-invoice"></i> Order Summary
                </h2>
                
                <div class="appointment-details">
                    <div class="detail-row">
                        <span class="detail-label">Service:</span>
                        <span class="detail-value"><?= htmlspecialchars($appointment['service']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Vehicle:</span>
                        <span class="detail-value"><?= htmlspecialchars($appointment['car']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date:</span>
                        <span class="detail-value"><?= date('M j, Y', strtotime($appointment['date'])) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Time:</span>
                        <span class="detail-value"><?= date('g:i A', strtotime($appointment['time'])) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value" style="color: var(--warning);">Pending Payment</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total Amount:</span>
                        <span class="detail-value">₱<?= number_format($servicePrice, 2) ?></span>
                    </div>
                </div>
                
                <div style="background: rgba(23, 162, 184, 0.1); padding: 20px; border-radius: 15px; border-left: 4px solid var(--info);">
                    <h4 style="color: var(--info); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-info-circle"></i> Important Notes
                    </h4>
                    <ul style="color: #ddd; line-height: 1.6; margin: 0; padding-left: 20px; font-size: 0.9rem;">
                        <li>Arrive 15 minutes before your appointment</li>
                        <li>Cancellation allowed up to 24 hours before</li>
                        <li>Bring valid ID and payment confirmation</li>
                        <li>Service warranty included in all payments</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let isProcessing = false;
        
        function selectPaymentMethod(method) {
            if (isProcessing) return;
            
            // Remove active class from all payment methods
            document.querySelectorAll('.payment-method').forEach(pm => {
                pm.classList.remove('active');
            });
            
            // Hide all payment fields
            document.querySelectorAll('.payment-fields').forEach(pf => {
                pf.classList.remove('active');
            });
            
            // Activate selected payment method
            const selectedMethod = document.querySelector(`#${method}`);
            selectedMethod.checked = true;
            
            const methodElement = selectedMethod.closest('.payment-method');
            methodElement.classList.add('active');
            
            document.querySelector(`#${method}_fields`).classList.add('active');
            
            // Enable pay button and update text
            const payButton = document.getElementById('payButton');
            const payButtonText = document.getElementById('payButtonText');
            
            payButton.disabled = false;
            switch(method) {
                case 'cash':
                    payButtonText.innerHTML = '<i class="fas fa-handshake"></i> Confirm Cash Payment';
                    break;
            }
        }
        
        function redirectToAppointments() {
            window.location.href = 'myappointments.php';
        }
        
        // Phone number formatting
        // Removed GCash input helpers
        
        // Auto-close success modal after 8 seconds
        <?php if ($paymentSuccess): ?>
        setTimeout(() => {
            redirectToAppointments();
        }, 8000);
        <?php endif; ?>
        
        // Form validation and submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            if (isProcessing) {
                e.preventDefault();
                return;
            }
            
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            if (!selectedMethod) {
                e.preventDefault();
                alert('Please select a payment method');
                return;
            }
            
            const method = selectedMethod.value;
            
            // No extra validation needed for cash
            
            // Show processing state
            isProcessing = true;
            const button = document.getElementById('payButton');
            button.disabled = true;
            button.classList.add('processing-button');
            
            if (method === 'cash') {
                // Special handling for cash payment
                e.preventDefault();
                
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Confirming Cash Payment...';
                
                // Create loading overlay
                const loadingOverlay = document.createElement('div');
                loadingOverlay.innerHTML = `
                    <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); display: flex; align-items: center; justify-content: center; z-index: 1000; animation: fadeIn 0.3s ease;">
                        <div style="text-align: center; color: white;">
                            <div style="width: 80px; height: 80px; border: 4px solid #f0c040; border-top: 4px solid transparent; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
                            <h3 style="color: #f0c040; margin-bottom: 10px;">Processing Payment...</h3>
                            <p style="color: #ddd;">Please wait while we confirm your cash payment</p>
                        </div>
                    </div>
                    <style>
                        @keyframes spin {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }
                    </style>
                `;
                document.body.appendChild(loadingOverlay);
                
                // Submit form after animation
                setTimeout(() => {
                    // Create form data and submit
                    const formData = new FormData();
                    formData.append('process_payment', '1');
                    formData.append('appointment_id', document.querySelector('input[name="appointment_id"]').value);
                    formData.append('amount', document.querySelector('input[name="amount"]').value);
                    formData.append('payment_method', 'cash');
                    
                    fetch('payment.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        // Check if payment was successful by looking for the success modal marker
                        if (data.indexOf('successModal') !== -1 || data.indexOf('Payment Successful!') !== -1) {
                            // Show success message briefly
                            loadingOverlay.innerHTML = `
                                <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); display: flex; align-items: center; justify-content: center; z-index: 1000;">
                                    <div style="text-align: center; color: white;">
                                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #28a745, #1e7e34); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; animation: pulse 1s ease;">
                                            <i class="fas fa-check" style="font-size: 2rem; color: white;"></i>
                                        </div>
                                        <h3 style="color: #28a745; margin-bottom: 10px;">Payment Confirmed!</h3>
                                        <p style="color: #ddd;">Redirecting to your appointments...</p>
                                    </div>
                                </div>
                                <style>
                                    @keyframes pulse {
                                        0% { transform: scale(1); }
                                        50% { transform: scale(1.1); }
                                        100% { transform: scale(1); }
                                    }
                                </style>
                            `;
                            
                            // Redirect after showing success
                            setTimeout(() => {
                                window.location.href = 'myappointments.php';
                            }, 1500);
                        } else {
                            // Handle error
                            document.body.removeChild(loadingOverlay);
                            isProcessing = false;
                            button.disabled = false;
                            button.classList.remove('processing-button');
                            button.innerHTML = '<i class="fas fa-handshake"></i> Confirm Cash Payment';
                            alert('Payment confirmation failed. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.body.removeChild(loadingOverlay);
                        isProcessing = false;
                        button.disabled = false;
                        button.classList.remove('processing-button');
                        button.innerHTML = '<i class="fas fa-handshake"></i> Confirm Cash Payment';
                        alert('An error occurred. Please try again.');
                    });
                }, 1500); // 1.5 second delay for loading animation
                
                return; // Exit early for cash payment
            }
            
            // Prevent multiple submissions for other payment methods
            setTimeout(() => {
                if (isProcessing) {
                    button.innerHTML = '<i class="fas fa-clock"></i> Please wait...';
                }
            }, 3000);
        });
        
        // Auto-dismiss error alert after 10 seconds
        <?php if (!empty($errorMessage)): ?>
        setTimeout(() => {
            const errorAlert = document.querySelector('.error-alert');
            if (errorAlert) {
                errorAlert.style.opacity = '0';
                errorAlert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 500);
            }
        }, 10000);
        <?php endif; ?>
    </script>
</body>
</html>
