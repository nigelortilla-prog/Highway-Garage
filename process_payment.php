<?php
session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['appointment_id']) && !empty($_POST['method'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $method = $_POST['method'] === 'gcash' ? 'GCash' : 'Cash on Hand';

    // Update appointment with payment method
    $stmt = $conn->prepare("UPDATE appointments SET payment_method = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sii", $method, $appointment_id, $_SESSION['user']['id']);
    $stmt->execute();

    // Show confirmation
    echo "<div style='background:#2ecc71;color:#fff;padding:20px;border-radius:8px;text-align:center;margin:40px auto;max-width:400px;'>
            <h2>Payment Successful!</h2>
            <p>Your payment method: <strong>$method</strong></p>
            <a href='myappointments.php' style='color:#fff;text-decoration:underline;'>Go to My Appointments</a>
          </div>";
    exit();
} else {
    // Redirect to payment page if accessed directly
    header("Location: payment.php");
    exit();
}
?>