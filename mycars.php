<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$userEmail = $_SESSION['user']['email'];
$cleanEmail = preg_replace("/[^a-zA-Z0-9]/", "_", $userEmail); // safe filename
$userCarFile = "cars_" . $cleanEmail . ".txt";
$message = "";

// ✅ Create file if not exist
if (!file_exists($userCarFile)) {
    file_put_contents($userCarFile, "");
}

// ✅ Handle car registration with image
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_car'])) {
    $car_year = trim($_POST['car_year']);
    $car_model = trim($_POST['car_model']);
    $image_name = "";

    // Handle file upload if provided
    if (!empty($_FILES['car_image']['name'])) {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $imageExt = pathinfo($_FILES['car_image']['name'], PATHINFO_EXTENSION);
        $image_name = uniqid("car_", true) . "." . strtolower($imageExt);
        $imagePath = $uploadDir . $image_name;

        if (move_uploaded_file($_FILES['car_image']['tmp_name'], $imagePath)) {
            $message = "✅ Car image uploaded successfully!";
        } else {
            $message = "⚠ Car registered but image upload failed!";
        }
    }

    if ($car_year && $car_model) {
        file_put_contents($userCarFile, "$car_year | $car_model | $image_name\n", FILE_APPEND);
        $message = "✅ Car registered successfully!";
    }
}

// ✅ Handle delete car + image
if (isset($_GET['delete'])) {
    $deleteIndex = intval($_GET['delete']);
    $lines = file($userCarFile, FILE_IGNORE_NEW_LINES);
    if (isset($lines[$deleteIndex])) {
        $fields = explode(" | ", $lines[$deleteIndex]);
        if (isset($fields[2]) && $fields[2] != "") {
            $imagePath = "uploads/" . trim($fields[2]);
            if (file_exists($imagePath)) {
                unlink($imagePath); // delete image
            }
        }
        unset($lines[$deleteIndex]);
        file_put_contents($userCarFile, implode(PHP_EOL, $lines) . PHP_EOL);
        $message = "✅ Car deleted successfully!";
    }
}

// ✅ Load current user's cars
$userCars = [];
if (file_exists($userCarFile)) {
    $lines = file($userCarFile, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $index => $line) {
        $fields = explode(" | ", $line);
        if (count($fields) >= 2) {
            $userCars[] = [
                'year' => $fields[0],
                'model' => $fields[1],
                'image' => $fields[2] ?? "",
                'index' => $index
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Cars - Vehicle Service</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">

<div class="auth-container">
    <h2>My Cars</h2>
    <?php if ($message) echo "<p style='color:yellow;'>$message</p>"; ?>

    <!-- Car Registration Form -->
    <form method="POST" enctype="multipart/form-data">
        <input type="text" name="car_year" placeholder="Car Year" required>
        <input type="text" name="car_model" placeholder="Car Model" required>
        <input type="file" name="car_image" accept="image/*">
        <button type="submit" name="add_car">Register Car</button>
    </form>

    <h3 style="margin-top:20px;">Your Registered Cars</h3>
    <?php if (count($userCars) > 0): ?>
        <ul style="list-style:none; padding:0;">
            <?php foreach ($userCars as $car): ?>
                <li style="margin-bottom:15px;">
                    <strong><?= htmlspecialchars($car['year']) ?> - <?= htmlspecialchars($car['model']) ?></strong><br>
                    <?php if ($car['image'] != ""): ?>
                        <img src="uploads/<?= htmlspecialchars($car['image']) ?>" alt="Car Image" style="width:150px; height:auto; margin-top:5px;">
                    <?php else: ?>
                        <em>No image</em>
                    <?php endif; ?>
                    <br>
                    <a href="mycars.php?delete=<?= $car['index'] ?>" style="color:red; margin-top:5px; display:inline-block;">Delete</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No cars registered yet.</p>
    <?php endif; ?>

    <p style="margin-top:20px;"><a href="index.php">⬅ Back to Homepage</a></p>
</div>

</body>
</html>
