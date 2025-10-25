<?php
// ✅ Cleaner Script for appointments.txt
$file = "appointments.txt";
$backupFile = "appointments_backup_" . date("Y-m-d_H-i-s") . ".txt";

if (!file_exists($file)) {
    die("⚠ appointments.txt not found.");
}

// ✅ Backup first
copy($file, $backupFile);

// ✅ Load and clean
$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$cleanedLines = [];
$removedCount = 0;

foreach ($lines as $line) {
    $fields = explode(" | ", $line);
    if (count($fields) >= 5) {
        // ✅ Trim spaces and rebuild consistent line
        $fields = array_map('trim', $fields);
        $cleanedLines[] = implode(" | ", $fields);
    } else {
        $removedCount++;
    }
}

// ✅ Save cleaned file
file_put_contents($file, implode("\n", $cleanedLines) . "\n");

echo "✅ Cleaning complete!<br>";
echo "📂 Backup created: $backupFile<br>";
echo "🧹 Removed malformed rows: $removedCount<br>";
echo "💾 Remaining valid rows: " . count($cleanedLines) . "<br>";
?>
