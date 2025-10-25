<?php
<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

include __DIR__ . '/db.php';
$userId = $_SESSION['user']['id'];

// Get all vehicle data for the user
$vehicles = [];
$vehicleQuery = $conn->prepare("
    SELECT DISTINCT vhr.vehicle_info,
           COUNT(vhr.id) as total_services,
           MAX(vhr.service_date) as last_service,
           MIN(vhr.service_date) as first_service,
           AVG(vhr.health_score) as avg_health_score,
           MAX(vhr.health_score) as max_health_score,
           MIN(vhr.health_score) as min_health_score,
           AVG(vp.fuel_efficiency) as avg_fuel_efficiency,
           AVG(vp.engine_performance) as avg_engine_performance,
           AVG(vp.brake_efficiency) as avg_brake_efficiency,
           AVG(vp.overall_condition) as avg_overall_condition
    FROM vehicle_health_records vhr
    LEFT JOIN vehicle_performance vp ON vhr.user_id = vp.user_id 
        AND vhr.vehicle_info = vp.vehicle_info 
        AND vhr.service_date = vp.recorded_date
    WHERE vhr.user_id = ?
    GROUP BY vhr.vehicle_info
    ORDER BY vhr.vehicle_info
");
$vehicleQuery->bind_param("i", $userId);
$vehicleQuery->execute();
$result = $vehicleQuery->get_result();
while ($row = $result->fetch_assoc()) {
    $vehicles[] = $row;
}
$vehicleQuery->close();

// Get detailed service history
$serviceHistory = [];
$historyQuery = $conn->prepare("
    SELECT vhr.*, vp.fuel_efficiency, vp.engine_performance, vp.brake_efficiency, vp.overall_condition, vp.notes
    FROM vehicle_health_records vhr
    LEFT JOIN vehicle_performance vp ON vhr.user_id = vp.user_id 
        AND vhr.vehicle_info = vp.vehicle_info 
        AND vhr.service_date = vp.recorded_date
    WHERE vhr.user_id = ?
    ORDER BY vhr.service_date DESC
");
$historyQuery->bind_param("i", $userId);
$historyQuery->execute();
$result = $historyQuery->get_result();
while ($row = $result->fetch_assoc()) {
    $serviceHistory[] = $row;
}
$historyQuery->close();

// Get active alerts
$alerts = [];
$alertQuery = $conn->prepare("
    SELECT * FROM vehicle_alerts 
    WHERE user_id = ? AND is_resolved = FALSE 
    ORDER BY severity DESC, due_date ASC
");
$alertQuery->bind_param("i", $userId);
$alertQuery->execute();
$result = $alertQuery->get_result();
while ($row = $result->fetch_assoc()) {
    $alerts[] = $row;
}
$alertQuery->close();

$reportDate = date('F d, Y');
$userName = $_SESSION['user']['name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Health Report - <?= htmlspecialchars($userName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white !important; }
            .card { border: 1px solid #ddd !important; box-shadow: none !important; }
            .btn { display: none !important; }
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        .report-header {
            background: linear-gradient(135deg, #343a40 0%, #495057 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card-header {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
        }
        
        .summary-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .summary-excellent { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
        .summary-good { background: linear-gradient(135deg, #17a2b8, #6f42c1); color: white; }
        .summary-fair { background: linear-gradient(135deg, #ffc107, #fd7e14); color: white; }
        .summary-poor { background: linear-gradient(135deg, #dc3545, #e83e8c); color: white; }
        
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .metric-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .progress-custom {
            height: 25px;
            border-radius: 15px;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,0.1);
        }
        
        .alert-severity-high { border-left: 4px solid #dc3545; }
        .alert-severity-medium { border-left: 4px solid #ffc107; }
        .alert-severity-low { border-left: 4px solid #17a2b8; }
        
        .report-footer {
            margin-top: 50px;
            padding: 20px 0;
            border-top: 2px solid #dee2e6;
            text-align: center;
            color: #6c757d;
        }
        
        .signature-section {
            margin-top: 40px;
            border: 1px dashed #dee2e6;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="report-header no-print">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="mb-0">
                        <i class="fas fa-file-medical-alt me-2"></i>Vehicle Health Report
                    </h1>
                    <p class="mb-0 opacity-75">Comprehensive vehicle maintenance and performance analysis</p>
                </div>
                <div class="col-auto">
                    <button class="btn btn-light me-2" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print Report
                    </button>
                    <a href="vehicle_monitoring.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Report Info -->
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h2 class="text-primary mb-3">Vehicle Health Report</h2>
                        <p><strong>Customer:</strong> <?= htmlspecialchars($userName) ?></p>
                        <p><strong>Report Date:</strong> <?= $reportDate ?></p>
                        <p><strong>Total Vehicles:</strong> <?= count($vehicles) ?></p>
                        <p><strong>Total Services:</strong> <?= array_sum(array_column($vehicles, 'total_services')) ?></p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="bg-light p-3 rounded">
                            <h5 class="text-muted mb-0">Report ID</h5>
                            <h4 class="text-primary"><?= strtoupper(substr(md5($userId . date('Y-m-d')), 0, 8)) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($vehicles)): ?>
        <!-- No Data -->
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">No Vehicle Data Available</h4>
                <p class="text-muted">Complete a service appointment to generate your vehicle health report.</p>
            </div>
        </div>
        
        <?php else: ?>
        
        <!-- Executive Summary -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Executive Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($vehicles as $vehicle): 
                        $healthClass = '';
                        $healthStatus = '';
                        if ($vehicle['avg_health_score'] >= 90) {
                            $healthClass = 'summary-excellent';
                            $healthStatus = 'Excellent';
                        } elseif ($vehicle['avg_health_score'] >= 75) {
                            $healthClass = 'summary-good';
                            $healthStatus = 'Good';
                        } elseif ($vehicle['avg_health_score'] >= 60) {
                            $healthClass = 'summary-fair';
                            $healthStatus = 'Fair';
                        } else {
                            $healthClass = 'summary-poor';
                            $healthStatus = 'Poor';
                        }
                    ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="summary-card <?= $healthClass ?>">
                            <h6 class="mb-2"><?= htmlspecialchars($vehicle['vehicle_info']) ?></h6>
                            <div class="metric-value"><?= round($vehicle['avg_health_score']) ?>%</div>
                            <div class="metric-label"><?= $healthStatus ?> Condition</div>
                            <hr class="my-2" style="border-color: rgba(255,255,255,0.3);">
                            <small><?= $vehicle['total_services'] ?> Services Completed</small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Active Alerts -->
        <?php if (!empty($alerts)): ?>
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>Active Alerts & Recommendations
                    <span class="badge bg-dark"><?= count($alerts) ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-severity-<?= $alert['severity'] ?> mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="alert-heading mb-1">
                                <i class="fas fa-wrench me-2"></i><?= htmlspecialchars($alert['vehicle_info']) ?>
                            </h6>
                            <p class="mb-1"><?= htmlspecialchars($alert['alert_message']) ?></p>
                            <small class="text-muted">
                                <i class="fas fa-calendar me-1"></i>
                                Due: <?= $alert['due_date'] ? date('M d, Y', strtotime($alert['due_date'])) : 'No specific date' ?>
                            </small>
                        </div>
                        <span class="badge bg-<?= $alert['severity'] === 'high' ? 'danger' : ($alert['severity'] === 'medium' ? 'warning' : 'info') ?> fs-6">
                            <?= strtoupper($alert['severity']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detailed Vehicle Analysis -->
        <?php foreach ($vehicles as $vehicle): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-car me-2"></i>Detailed Analysis: <?= htmlspecialchars($vehicle['vehicle_info']) ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="metric-value text-primary"><?= round($vehicle['avg_health_score']) ?>%</div>
                            <div class="metric-label text-muted">Average Health Score</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="metric-value text-success"><?= $vehicle['total_services'] ?></div>
                            <div class="metric-label text-muted">Total Services</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="metric-value text-info"><?= number_format($vehicle['avg_fuel_efficiency'] ?? 0, 1) ?></div>
                            <div class="metric-label text-muted">Avg Fuel Efficiency (km/L)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="metric-value text-warning"><?= date('M d, Y', strtotime($vehicle['last_service'])) ?></div>
                            <div class="metric-label text-muted">Last Service</div>
                        </div>
                    </div>
                </div>

                <!-- Performance Metrics -->
                <h6 class="mb-3">Performance Metrics</h6>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Engine Performance</label>
                        <div class="progress progress-custom">
                            <div class="progress-bar bg-primary" style="width: <?= $vehicle['avg_engine_performance'] ?? 0 ?>%">
                                <?= round($vehicle['avg_engine_performance'] ?? 0) ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Brake Efficiency</label>
                        <div class="progress progress-custom">
                            <div class="progress-bar bg-success" style="width: <?= $vehicle['avg_brake_efficiency'] ?? 0 ?>%">
                                <?= round($vehicle['avg_brake_efficiency'] ?? 0) ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Overall Condition</label>
                        <div class="progress progress-custom">
                            <div class="progress-bar bg-info" style="width: <?= $vehicle['avg_overall_condition'] ?? 0 ?>%">
                                <?= round($vehicle['avg_overall_condition'] ?? 0) ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Health Score Range</label>
                        <div class="progress progress-custom">
                            <div class="progress-bar bg-warning" style="width: <?= $vehicle['avg_health_score'] ?>%">
                                <?= round($vehicle['min_health_score']) ?>% - <?= round($vehicle['max_health_score']) ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Service History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Complete Service History</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Vehicle</th>
                                <th>Service Type</th>
                                <th>Mileage</th>
                                <th>Health Score</th>
                                <th>Engine</th>
                                <th>Brakes</th>
                                <th>Fuel Eff.</th>
                                <th>Next Service</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serviceHistory as $record): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($record['service_date'])) ?></td>
                                <td><?= htmlspecialchars($record['vehicle_info']) ?></td>
                                <td>
                                    <span class="badge bg-primary"><?= htmlspecialchars($record['service_type']) ?></span>
                                </td>
                                <td><?= $record['mileage_at_service'] ? number_format($record['mileage_at_service']) . ' km' : 'N/A' ?></td>
                                <td>
                                    <span class="badge bg-<?= $record['health_score'] >= 90 ? 'success' : ($record['health_score'] >= 75 ? 'info' : 'warning') ?>">
                                        <?= $record['health_score'] ?>%
                                    </span>
                                </td>
                                <td><?= $record['engine_performance'] ? $record['engine_performance'] . '%' : 'N/A' ?></td>
                                <td><?= $record['brake_efficiency'] ? $record['brake_efficiency'] . '%' : 'N/A' ?></td>
                                <td><?= $record['fuel_efficiency'] ? number_format($record['fuel_efficiency'], 1) . ' km/L' : 'N/A' ?></td>
                                <td>
                                    <?php if ($record['next_service_due']): ?>
                                        <?= date('M d, Y', strtotime($record['next_service_due'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['notes']): ?>
                                        <small><?= htmlspecialchars(substr($record['notes'], 0, 50)) ?><?= strlen($record['notes']) > 50 ? '...' : '' ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">No notes</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recommendations -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Maintenance Recommendations</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($vehicles as $vehicle): 
                        $recommendations = [];
                        
                        if ($vehicle['avg_health_score'] < 75) {
                            $recommendations[] = "Schedule comprehensive inspection";
                        }
                        if (($vehicle['avg_engine_performance'] ?? 90) < 85) {
                            $recommendations[] = "Engine performance check recommended";
                        }
                        if (($vehicle['avg_brake_efficiency'] ?? 95) < 90) {
                            $recommendations[] = "Brake system inspection needed";
                        }
                        if (($vehicle['avg_fuel_efficiency'] ?? 12) < 10) {
                            $recommendations[] = "Fuel system optimization suggested";
                        }
                        
                        // Check if last service was more than 6 months ago
                        $daysSinceLastService = (time() - strtotime($vehicle['last_service'])) / (60 * 60 * 24);
                        if ($daysSinceLastService > 180) {
                            $recommendations[] = "Regular maintenance overdue";
                        }
                        
                        if (empty($recommendations)) {
                            $recommendations[] = "Vehicle is in good condition - continue regular maintenance";
                        }
                    ?>
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title"><?= htmlspecialchars($vehicle['vehicle_info']) ?></h6>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($recommendations as $rec): ?>
                                    <li class="mb-1">
                                        <i class="fas fa-check-circle text-success me-2"></i>
                                        <?= $rec ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="row">
                <div class="col-md-6">
                    <h6>Service Advisor</h6>
                    <div style="height: 50px; border-bottom: 1px solid #dee2e6; margin-bottom: 10px;"></div>
                    <small class="text-muted">Name & Signature</small>
                </div>
                <div class="col-md-6">
                    <h6>Customer Acknowledgment</h6>
                    <div style="height: 50px; border-bottom: 1px solid #dee2e6; margin-bottom: 10px;"></div>
                    <small class="text-muted">Name & Signature</small>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Footer -->
        <div class="report-footer">
            <p class="mb-1">
                <strong>Vehicle Service Center</strong> | Professional Automotive Care
            </p>
            <p class="mb-0">
                Report generated on <?= $reportDate ?> | 
                <i class="fas fa-phone me-1"></i>(123) 456-7890 | 
                <i class="fas fa-envelope me-1"></i>service@vehiclecenter.com
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>