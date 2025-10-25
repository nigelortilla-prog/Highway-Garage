<?php
// time_slots.php
$date = $_GET['date'] ?? null;

if (!$date) {
    echo json_encode([]);
    exit;
}

// Example: predefined time slots (you can customize)
$allSlots = [
    "09:00 AM", "10:00 AM", "11:00 AM", "01:00 PM", "02:00 PM", "03:00 PM", "04:00 PM"
];

$bookedSlots = [];

// Load existing appointments to remove booked slots
$userAppointmentsPattern = "appointments_*.txt";
foreach (glob($userAppointmentsPattern) as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $fields = explode(" | ", $line);
        if (count($fields) >= 5) {
            $apptDate = explode(" ", $fields[0])[0]; // Extract date part
            $apptTime = explode(" ", $fields[0])[1] . " " . explode(" ", $fields[0])[2]; // Time + AM/PM
            if ($apptDate == $date && strtolower($fields[3]) != 'canceled') {
                $bookedSlots[] = $apptTime;
            }
        }
    }
}

// Calculate available slots
$availableSlots = array_values(array_diff($allSlots, $bookedSlots));

echo json_encode($availableSlots);
