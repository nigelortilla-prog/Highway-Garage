<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

include __DIR__ . '/db.php';
$userId = $_SESSION['user']['id'];
$vehicleInfo = $_GET['vehicle'] ?? '';

if (empty($vehicleInfo)) {
    header("Location: vehicle_monitoring.php");
    exit();
}

// Get detailed vehicle information with performance data
$vehicleDetails = [];
$detailQuery = $conn->prepare("
    SELECT vhr.*, vp.fuel_efficiency, vp.engine_performance, vp.brake_efficiency, vp.overall_condition, vp.notes
    FROM vehicle_health_records vhr
    LEFT JOIN vehicle_performance vp ON vhr.user_id = vp.user_id 
        AND vhr.vehicle_info = vp.vehicle_info 
        AND vhr.service_date = vp.recorded_date
    WHERE vhr.user_id = ? AND vhr.vehicle_info = ?
    ORDER BY vhr.service_date DESC
");
$detailQuery->bind_param("is", $userId, $vehicleInfo);
$detailQuery->execute();
$result = $detailQuery->get_result();
while ($row = $result->fetch_assoc()) {
    $vehicleDetails[] = $row;
}
$detailQuery->close();

// Get vehicle summary stats
$vehicleStats = [];
if (!empty($vehicleDetails)) {
    $vehicleStats = [
        'total_services' => count($vehicleDetails),
        'avg_health_score' => round(array_sum(array_column($vehicleDetails, 'health_score')) / count($vehicleDetails)),
        'max_health_score' => max(array_column($vehicleDetails, 'health_score')),
        'min_health_score' => min(array_column($vehicleDetails, 'health_score')),
        'first_service' => end($vehicleDetails)['service_date'],
        'last_service' => $vehicleDetails[0]['service_date'],
        'avg_fuel_efficiency' => round(array_sum(array_filter(array_column($vehicleDetails, 'fuel_efficiency'))) / max(1, count(array_filter(array_column($vehicleDetails, 'fuel_efficiency')))), 1),
        'avg_engine_performance' => round(array_sum(array_filter(array_column($vehicleDetails, 'engine_performance'))) / max(1, count(array_filter(array_column($vehicleDetails, 'engine_performance'))))),
        'avg_brake_efficiency' => round(array_sum(array_filter(array_column($vehicleDetails, 'brake_efficiency'))) / max(1, count(array_filter(array_column($vehicleDetails, 'brake_efficiency')))))
    ];
}

// Get upcoming service date
$nextServiceDate = null;
if (!empty($vehicleDetails)) {
    $nextServiceDate = $vehicleDetails[0]['next_service_due'];
}

// Get related alerts
$vehicleAlerts = [];
$alertQuery = $conn->prepare("
    SELECT * FROM vehicle_alerts 
    WHERE user_id = ? AND vehicle_info = ? AND is_resolved = FALSE 
    ORDER BY severity DESC, due_date ASC
");
$alertQuery->bind_param("is", $userId, $vehicleInfo);
$alertQuery->execute();
$result = $alertQuery->get_result();
while ($row = $result->fetch_assoc()) {
    $vehicleAlerts[] = $row;
}
$alertQuery->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Details - <?= htmlspecialchars($vehicleInfo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            height: 100%;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #495057;
            margin: 10px 0;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        .progress-custom {
            height: 25px;
            border-radius: 15px;
            margin-bottom: 15px;
        }
        .alert-vehicle {
            border-left: 4px solid;
            margin-bottom: 15px;
        }
        .alert-high { border-left-color: #dc3545; }
        .alert-medium { border-left-color: #ffc107; }
        .alert-low { border-left-color: #17a2b8; }
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        .timeline-item {
            border-left: 3px solid #dee2e6;
            padding: 15px 20px;
            margin-bottom: 20px;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 10px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #007bff;
            border: 3px solid #fff;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="text-primary mb-1">
                                    <i class="fas fa-car me-2"></i><?= htmlspecialchars($vehicleInfo) ?>
                                </h2>
                                <p class="text-muted mb-0">Detailed health and maintenance tracking</p>
                            </div>
                            <a href="vehicle_monitoring.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-value"><?= $vehicleStats['total_services'] ?></div>
                        <div class="stat-label">Total Services</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-value"><?= $vehicleStats['avg_health_score'] ?>%</div>
                        <div class="stat-label">Avg. Health Score</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service History -->
        <div class="row">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Service History for <?= htmlspecialchars($vehicleInfo) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($vehicleDetails)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No service history found</h5>
                            <p class="text-muted">This vehicle has no recorded services yet.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Service Date</th>
                                        <th>Service Type</th>
                                        <th>Health Score</th>
                                        <th>Mileage</th>
                                        <th>Next Service Due</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicleDetails as $detail): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($detail['service_date'])) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= htmlspecialchars($detail['service_type']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $detail['health_score'] >= 90 ? 'success' : ($detail['health_score'] >= 75 ? 'info' : 'warning') ?>">
                                                <?= $detail['health_score'] ?>%
                                            </span>
                                        </td>
                                        <td><?= $detail['mileage_at_service'] ? number_format($detail['mileage_at_service']) . ' km' : 'N/A' ?></td>
                                        <td>
                                            <?php if ($detail['next_service_due']): ?>
                                                <?= date('M d, Y', strtotime($detail['next_service_due'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($detail['notes'] ?? 'No notes') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts Section -->
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>Vehicle Alerts
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($vehicleAlerts)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No alerts</h5>
                            <p class="text-muted">This vehicle has no active alerts.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($vehicleAlerts as $alert): ?>
                        <div class="alert alert-vehicle <?= $alert['severity'] == 'high' ? 'alert-high' : ($alert['severity'] == 'medium' ? 'alert-medium' : 'alert-low') ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?= htmlspecialchars($alert['title']) ?></h6>
                                    <p class="mb-0"><?= htmlspecialchars($alert['description']) ?></p>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark"><?= date('M d, Y', strtotime($alert['due_date'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Charts -->
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Performance Overview
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Timeline -->
        <div class="row">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-timeline me-2"></i>Service Timeline
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($vehicleDetails)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No service timeline available</h5>
                            <p class="text-muted">This vehicle has no recorded services to display a timeline.</p>
                        </div>
                        <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($vehicleDetails as $index => $detail): ?>
                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <h6 class="mb-1"><?= date('M d, Y', strtotime($detail['service_date'])) ?></h6>
                                    <p class="mb-0"><?= htmlspecialchars($detail['service_type']) ?> - 
                                        <span class="text-<?= $detail['health_score'] >= 75 ? 'success' : 'danger' ?>">
                                            <?= $detail['health_score'] ?>%
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Performance Chart
        const ctx = document.getElementById('performanceChart').getContext('2d');
        const performanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($detail) { return date('M d, Y', strtotime($detail['service_date'])); }, $vehicleDetails)) ?>,
                datasets: [{
                    label: 'Health Score',
                    data: <?= json_encode(array_column($vehicleDetails, 'health_score')) ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Fuel Efficiency',
                    data: <?= json_encode(array_column($vehicleDetails, 'fuel_efficiency')) ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Engine Performance',
                    data: <?= json_encode(array_column($vehicleDetails, 'engine_performance')) ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Brake Efficiency',
                    data: <?= json_encode(array_column($vehicleDetails, 'brake_efficiency')) ?>,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                interaction: {
                    mode: 'nearest',
                    intersect: true
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Service Date'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Performance Metrics'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>