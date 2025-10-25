<?php
session_start();

// Require user login; admins can also access
if (!isset($_SESSION['user']) && !isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

include __DIR__ . '/db.php';

$userId = isset($_SESSION['user']) ? (int)$_SESSION['user']['id'] : 0;

// Handle URL parameters for filtering
$filterUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $userId;
$filterVehicle = isset($_GET['vehicle']) ? $_GET['vehicle'] : '';

// If admin is viewing, allow viewing other users' data
if (isset($_SESSION['admin_logged_in'])) {
    // Admins can browse any user; if none specified, leave as 0 to avoid leaking data
    $userId = $filterUserId ?: 0;
} else {
    // Regular users can only see their own data
    $userId = isset($_SESSION['user']) ? (int)$_SESSION['user']['id'] : 0;
}

// Build WHERE clause for filtering
$whereClause = "1=1";
$queryParams = [];
$paramTypes = "";

if ($userId > 0) {
    $whereClause .= " AND vhr.user_id = ?";
    $queryParams[] = $userId;
    $paramTypes .= "i";
}

if (!empty($filterVehicle)) {
    $whereClause .= " AND vhr.vehicle_info LIKE ?";
    $queryParams[] = "%$filterVehicle%";
    $paramTypes .= "s";
}

// Get vehicle health records with performance data
$healthQuery = "
    SELECT vhr.*, vp.notes,
           u.name as customer_name, u.email as customer_email
    FROM vehicle_health_records vhr
    LEFT JOIN (
        SELECT t.*
        FROM vehicle_performance t
        JOIN (
            SELECT user_id, vehicle_info, recorded_date, MAX(id) AS max_id
            FROM vehicle_performance
            GROUP BY user_id, vehicle_info, recorded_date
        ) x ON t.id = x.max_id
    ) vp
      ON vhr.user_id = vp.user_id
     AND vhr.vehicle_info = vp.vehicle_info
     AND vhr.service_date = vp.recorded_date
    LEFT JOIN users u ON vhr.user_id = u.id
    WHERE $whereClause
    ORDER BY vhr.service_date DESC
";

$stmt = $conn->prepare($healthQuery);
if (!empty($paramTypes)) {
    $stmt->bind_param($paramTypes, ...$queryParams);
}
$stmt->execute();
$healthRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get alerts
$alertQuery = "
    SELECT * FROM vehicle_alerts 
    WHERE user_id = ? AND is_resolved = FALSE 
    ORDER BY severity DESC, created_at DESC
";
$stmt = $conn->prepare($alertQuery);
if ($userId > 0) {
    $stmt->bind_param("i", $userId);
} else {
    // No alerts when no user selected
    $alerts = [];
}
if ($userId > 0) {
    $stmt->execute();
    $alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Calculate summary statistics
$totalRecords = count($healthRecords);
$avgHealthScore = $totalRecords > 0 ? array_sum(array_column($healthRecords, 'health_score')) / $totalRecords : 0;
$criticalAlerts = count(array_filter($alerts, function($alert) { return $alert['severity'] === 'critical' || $alert['severity'] === 'high'; }));

// Get user info for display (may be null for admin without selection)
$userInfo = ['name' => 'All Customers', 'email' => ''];
if ($userId > 0) {
    $userQuery = "SELECT name, email FROM users WHERE id = ?";
    $stmt = $conn->prepare($userQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $tmp = $stmt->get_result()->fetch_assoc();
    if ($tmp) { $userInfo = $tmp; }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Health Monitoring | Vehicle Service Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #f0c040;
            --secondary: #333;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }
        
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .header-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 3px solid var(--primary);
            padding: 20px 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .monitoring-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.18);
        }
        
        .health-score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            color: white;
            margin: 0 auto 15px;
        }
        
        .performance-meter {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .performance-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .alert-card {
            border-left: 5px solid;
            margin-bottom: 15px;
        }
        
        .alert-critical { border-left-color: #dc3545; }
        .alert-high { border-left-color: #fd7e14; }
        .alert-medium { border-left-color: #ffc107; }
        .alert-low { border-left-color: #28a745; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            color: white;
        }
        
        .filter-section {
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Header Section -->
    <div class="header-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="text-primary mb-1">
                        <i class="fas fa-heartbeat me-2"></i>Vehicle Health Monitoring
                    </h1>
                    <p class="text-muted mb-0">
                        Tracking health data for: <strong><?= htmlspecialchars($userInfo['name']) ?></strong>
                        <?php if (!empty($filterVehicle)): ?>
                            - Vehicle: <strong><?= htmlspecialchars($filterVehicle) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-car fa-2x mb-2"></i>
                <h3><?= $totalRecords ?></h3>
                <p>Total Records</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-heart fa-2x mb-2"></i>
                <h3><?= number_format($avgHealthScore, 1) ?>%</h3>
                <p>Avg Health Score</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <i class="fas fa-bell fa-2x mb-2"></i>
                <h3><?= count($alerts) ?></h3>
                <p>Active Alerts</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                <h3><?= $criticalAlerts ?></h3>
                <p>Critical Issues</p>
            </div>
        </div>

        <!-- Alerts Section -->
        <?php if (!empty($alerts)): ?>
        <div class="monitoring-card">
            <h4 class="text-danger mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>Active Alerts
            </h4>
            <?php foreach ($alerts as $alert): ?>
            <div class="alert-card alert-<?= $alert['severity'] ?> card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="card-title">
                                <i class="fas fa-car me-2"></i><?= htmlspecialchars($alert['vehicle_info']) ?>
                            </h6>
                            <p class="card-text"><?= htmlspecialchars($alert['alert_message']) ?></p>
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                <?= date('M d, Y g:i A', strtotime($alert['created_at'])) ?>
                            </small>
                        </div>
                        <span class="badge bg-<?= $alert['severity'] === 'critical' ? 'danger' : ($alert['severity'] === 'high' ? 'warning' : 'info') ?>">
                            <?= ucfirst($alert['severity']) ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Vehicle Health Records -->
        <?php if (!empty($healthRecords)): ?>
        <div class="monitoring-card">
            <h4 class="text-primary mb-4">
                <i class="fas fa-chart-line me-2"></i>Vehicle Health Records
            </h4>
            
            <div class="row">
                <?php foreach ($healthRecords as $record): ?>
                <div class="col-lg-6 col-xl-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0">
                                <i class="fas fa-car me-2"></i><?= htmlspecialchars($record['vehicle_info']) ?>
                            </h6>
                            <small><?= htmlspecialchars($record['service_type']) ?></small>
                        </div>
                        <div class="card-body">
                            <!-- Health Score Circle -->
                            <div class="health-score-circle bg-<?= $record['health_score'] >= 90 ? 'success' : ($record['health_score'] >= 75 ? 'info' : 'warning') ?>">
                                <?= $record['health_score'] ?>%
                            </div>
                            <p class="text-center text-muted mb-3">Overall Health Score</p>
                            
                            <!-- Performance metrics removed by request -->
                            
                            <!-- Service Info -->
                            <hr>
                            <div class="row text-center">
                                <div class="col-6">
                                    <small class="text-muted d-block">Service Date</small>
                                    <strong><?= date('M d, Y', strtotime($record['service_date'])) ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Next Service</small>
                                    <strong class="text-<?= strtotime($record['next_service_due']) <= time() ? 'danger' : 'success' ?>">
                                        <?= $record['next_service_due'] ? date('M d, Y', strtotime($record['next_service_due'])) : 'TBD' ?>
                                    </strong>
                                </div>
                            </div>
                            
                            <?php if ($record['mileage_at_service']): ?>
                            <div class="text-center mt-2">
                                <small class="text-muted">Mileage: <?= number_format($record['mileage_at_service']) ?> km</small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($record['notes']): ?>
                            <div class="mt-3">
                                <small class="text-muted d-block">Notes:</small>
                                <small><?= htmlspecialchars($record['notes']) ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer text-muted text-center">
                            <small>
                                <i class="fas fa-clock me-1"></i>
                                Updated: <?= date('M d, Y', strtotime($record['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="monitoring-card text-center">
            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
            <h4 class="text-muted">No Vehicle Health Data Available</h4>
            <p class="text-muted">Complete some service appointments to see your vehicle health monitoring data here.</p>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>