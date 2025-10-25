<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

include __DIR__ . '/db.php';

$recordId = intval($_GET['record_id'] ?? 0);

if ($recordId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
    exit();
}

// Get the monitoring record with performance data
$stmt = $conn->prepare("
    SELECT vhr.*, vp.fuel_efficiency, vp.engine_performance, vp.brake_efficiency, vp.overall_condition, vp.notes
    FROM vehicle_health_records vhr
    LEFT JOIN vehicle_performance vp ON vhr.user_id = vp.user_id 
        AND vhr.vehicle_info = vp.vehicle_info 
        AND vhr.service_date = vp.recorded_date
    WHERE vhr.id = ?
");
$stmt->bind_param("i", $recordId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $record = $result->fetch_assoc();
    echo json_encode(['success' => true, 'record' => $record]);
} else {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
}

$stmt->close();
?>