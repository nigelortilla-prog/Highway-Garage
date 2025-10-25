<?php
session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $date = trim($_POST['date']);
    $time = trim($_POST['time']);
    $car = trim($_POST['car']);
    $service = trim($_POST['service']);
    $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
    $odometer = isset($_POST['odometer']) && $_POST['odometer'] !== '' ? (int)$_POST['odometer'] : null;
    $user_id = $_SESSION['user']['id']; // logged in user id from session

    // ✅ Check if slot is full (max 5 bookings per date+time)
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE date=? AND time=? AND status!='Canceled'");
    $stmt->bind_param("ss", $date, $time);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result['cnt'] >= 5) {
        header("Location: index.php?error=slot_taken#appointment");
        exit();
    }

    // Service price mapping
    $service_prices = [
        'Aircon Cleaning' => 1200,
        'Air Filter Replacement' => 800,
        'Brake Service' => 1500,
        'Check Engine' => 1000,
        'Check Wiring' => 900,
        'Oil Change' => 1100,
        'PMS' => 2000,
        'Wheel Alignment' => 1300
    ];
    $price = isset($service_prices[$service]) ? $service_prices[$service] : 0;

    // Ensure schema for comments and odometer exists (portable)
    $res = $conn->query("SHOW COLUMNS FROM appointments LIKE 'comments'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE appointments ADD COLUMN comments TEXT NULL");
    }
    $res2 = $conn->query("SHOW COLUMNS FROM appointments LIKE 'odometer'");
    if ($res2 && $res2->num_rows === 0) {
        $conn->query("ALTER TABLE appointments ADD COLUMN odometer INT NULL");
    }

    // Insert appointment with price, comments, and odometer
    $stmt = $conn->prepare("INSERT INTO appointments (user_id, name, car, service, date, time, status, price, comments, odometer) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)");
    // Types: i (user_id), s (name), s (car), s (service), s (date), s (time), i (price), s (comments), i (odometer or NULL)
    $stmt->bind_param("isssssisi", $user_id, $name, $car, $service, $date, $time, $price, $comments, $odometer);
    $stmt->execute();

    // Do not redirect to payment immediately; wait for mechanic availability confirmation
    header("Location: index.php?success=1#appointment");
    exit();
}
?>
