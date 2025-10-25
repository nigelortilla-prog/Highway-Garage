<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: myappointments.php");
    exit;
}

$userEmail = $_SESSION['user']['email'];
$cleanEmail = preg_replace("/[^a-zA-Z0-9]/", "_", $userEmail);
$userAppointmentsFile = "appointments_" . $cleanEmail . ".txt";

$appointmentId = $_GET['id'];

if (file_exists($userAppointmentsFile)) {
    $lines = array_filter(file($userAppointmentsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $updatedLines = [];

    foreach ($lines as $line) {
        $fields = explode(" | ", $line);
        if (count($fields) >= 5) {
            // Only keep lines that are NOT the canceled one
            if (trim($fields[4]) !== $appointmentId) {
                $updatedLines[] = $line;
            }
        }
    }

    // Save the updated file
    file_put_contents($userAppointmentsFile, implode(PHP_EOL, $updatedLines) . PHP_EOL, LOCK_EX);
}

// Redirect back
header("Location: myappointments.php");
exit;
?>
