<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user = $_SESSION['user'];
$userEmail = $user['email'];
$cleanEmail = preg_replace("/[^a-zA-Z0-9]/", "_", $userEmail);
$userCarFile = "cars_" . $cleanEmail . ".txt";

// Handle Car Registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['car_model'])) {
    $car_model = trim($_POST['car_model']);
    $plate = trim($_POST['plate']);
    
    if (!empty($car_model) && !empty($plate)) {
        $carImage = "";
        if (!empty($_FILES['car_image']['name'])) {
            $targetDir = "car_images/";
            if (!file_exists($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            $carImage = $targetDir . basename($_FILES["car_image"]["name"]);
            move_uploaded_file($_FILES["car_image"]["tmp_name"], $carImage);
        }
        $line = "$car_model | $plate | $carImage\n";
        file_put_contents($userCarFile, $line, FILE_APPEND);
    }
}

// Load Registered Cars
$userCars = [];
if (file_exists($userCarFile)) {
    $lines = file($userCarFile, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        $fields = explode(" | ", $line);
        $userCars[] = $fields;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile - Vehicle Service</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <div class="logo">
    <img src="22.png" alt="Logo">
  </div>
  <nav>
    <ul class="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="myappointments.php">Track Appointment</a></li>
      <li><a href="profile.php">My Profile</a></li>
      <li><a href="logout.php" style="color:red;">Logout</a></li>
    </ul>
  </nav>
</header>

<section class="profile-container">
    <h2>My Profile</h2>
    <p><strong>Name:</strong> <?= htmlspecialchars($user['name']) ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
    <p><strong>Contact:</strong> <?= htmlspecialchars($user['contact']) ?></p>

    <hr style="margin:20px 0;">

    <h3>Register a Car</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="text" name="car_model" placeholder="Car Model" required>
        <input type="text" name="plate" placeholder="Plate Number" required>
        <input type="file" name="car_image" accept="image/*">
        <button type="submit">Register Car</button>
    </form>

    <hr style="margin:20px 0;">
    <h3>My Cars</h3>

    <?php if(count($userCars) > 0): ?>
        <table>
            <tr>
                <th>Car Model</th>
                <th>Plate Number</th>
                <th>Image</th>
            </tr>
            <?php foreach($userCars as $car): ?>
                <tr>
                    <td><?= htmlspecialchars($car[0]) ?></td>
                    <td><?= htmlspecialchars($car[1]) ?></td>
                    <td>
                        <?php if(!empty($car[2])): ?>
                            <img src="<?= htmlspecialchars($car[2]) ?>" width="120">
                        <?php else: ?>
                            No Image
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No cars registered yet.</p>
    <?php endif; ?>
</section>

<footer>
  <p>© 2025 Vehicle Service Center | All Rights Reserved</p>
</footer>

</body>
</html>
