<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include __DIR__ . '/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$alertId = intval($input['alert_id']);

if ($alertId > 0) {
    $stmt = $conn->prepare("UPDATE vehicle_alerts SET is_resolved = TRUE WHERE id = ?");
    $stmt->bind_param("i", $alertId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid alert ID']);
}
?>