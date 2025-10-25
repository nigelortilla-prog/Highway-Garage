<?php


session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

include __DIR__ . '/db.php';

// Get user info from database (not just session)
$userId = $_SESSION['user']['id'];
$stmt = $conn->prepare("SELECT name, email, contact FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($dbName, $dbEmail, $dbContact);
$stmt->fetch();
$stmt->close();

// Use DB values for owner info
$user = [
    'name' => $dbName,
    'email' => $dbEmail,    
    'contact' => $dbContact
];

$cleanEmail = preg_replace("/[^a-zA-Z0-9]/", "_", $user['email']);
$ownerImagePattern = "owner_" . $cleanEmail . ".*";

// ✅ Handle Owner Image Upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_owner_image'])) {
    if (!empty($_FILES['owner_image']['name'])) {
        foreach (glob($ownerImagePattern, GLOB_BRACE) as $oldFile) unlink($oldFile);
        $ext = strtolower(pathinfo($_FILES['owner_image']['name'], PATHINFO_EXTENSION));
        $target = "owner_" . $cleanEmail . "." . $ext;
        move_uploaded_file($_FILES['owner_image']['tmp_name'], $target);
    }
    header("Location: myprofile.php");
    exit();
}

// ✅ Handle Owner Info Update (update DB and session)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_owner_info'])) {
    $newName = $_POST['owner_name'];
    $newEmail = $_POST['owner_email'];
    $newContact = $_POST['owner_contact'];

    // Check if owner exists
    $stmt = $conn->prepare("SELECT id FROM owners WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Update owner info
        $stmt->close();
        $stmt = $conn->prepare("UPDATE owners SET owner_name=?, email=?, contact=? WHERE id=?");
        $stmt->bind_param("sssi", $newName, $newEmail, $newContact, $userId);
        $stmt->execute();
        $stmt->close();
    } else {
        // Insert new owner info
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO owners (id, owner_name, email, contact) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $newName, $newEmail, $newContact);
        $stmt->execute();
        $stmt->close();
    }

    // Optionally update session
    $_SESSION['user']['name'] = $newName;
    $_SESSION['user']['email'] = $newEmail;
    $_SESSION['user']['contact'] = $newContact;

    header("Location: myprofile.php");
    exit();
}

// ✅ Handle Add Car (to database)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_car'])) {
    $carModel = $_POST['car_model'];
    $plateNumber = $_POST['plate_number'];
    $carColor = $_POST['car_color'];
    $carVersion = $_POST['car_version'] ?? '';

    $carImage = "";
    if (!empty($_FILES['car_image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['car_image']['name'], PATHINFO_EXTENSION));
        $carImage = "car_" . $cleanEmail . "_" . time() . "." . $ext;
        move_uploaded_file($_FILES['car_image']['tmp_name'], $carImage);
    }

    $stmt = $conn->prepare("INSERT INTO cars (user_id, car_model, plate_number, car_color, car_version, car_image) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $userId, $carModel, $plateNumber, $carColor, $carVersion, $carImage);
    $stmt->execute();
    $stmt->close();

    header("Location: myprofile.php");
    exit();
}

// ✅ Handle Delete Car (from database)
if (isset($_GET['delete_car'])) {
    $carId = intval($_GET['delete_car']);
    // Get car image filename to delete file
    $stmt = $conn->prepare("SELECT car_image FROM cars WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $carId, $userId);
    $stmt->execute();
    $stmt->bind_result($carImage);
    $stmt->fetch();
    $stmt->close();

    if ($carImage && file_exists($carImage)) unlink($carImage);

    $stmt = $conn->prepare("DELETE FROM cars WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $carId, $userId);
    $stmt->execute();
    $stmt->close();

    header("Location: myprofile.php");
    exit();
}

// Find existing owner image
$ownerImage = "";
foreach (glob($ownerImagePattern, GLOB_BRACE) as $file) {
    if (is_file($file)) {
        $ownerImage = $file;
        break;
    }
}

// Load cars from database
$cars = [];
$stmt = $conn->prepare("SELECT id, car_model, plate_number, car_color, car_version, car_image FROM cars WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cars[] = $row;
}
$stmt->close();

// Handle Edit Car
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_car'])) {
    $carId = intval($_POST['edit_car_id']);
    $carModel = $_POST['edit_car_model'];
    $plateNumber = $_POST['edit_plate_number'];
    $carColor = $_POST['edit_car_color'];
    $carVersion = $_POST['edit_car_version'];
    $stmt = $conn->prepare("UPDATE cars SET car_model=?, plate_number=?, car_color=?, car_version=? WHERE id=? AND user_id=?");
    $stmt->bind_param("ssssii", $carModel, $plateNumber, $carColor, $carVersion, $carId, $userId);
    $stmt->execute();
    $stmt->close();
    header("Location: myprofile.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <style>
        body {
            background: url('33.png') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }
        .profile-container {
            max-width: 1200px;
            margin: 40px auto;
            background: rgba(255,255,255,0.95);
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            padding: 30px;
            border-radius: 20px;
        }
        .profile-section {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .profile-card {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            flex: 1;
            min-width: 300px;
        }
        .profile-card h3 {
            color: #2a5dff;
            margin-bottom: 15px;
        }
        .image-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
            max-width: 220px;
            margin-bottom: 15px;
        }
        .image-wrapper img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-radius: 12px;
            border: 3px solid #2a5dff;
        }
        .overlay-info {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(42,93,255,0.75);
            color: white;
            font-size: 12px;
            padding: 5px;
            border-radius: 0 0 12px 12px;
        }
        .placeholder {
            width: 100%;
            max-width: 220px;
            height: 220px;
            background: #f0f4ff;
            border-radius: 12px;
            border: 2px dashed #2a5dff;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #2a5dff;
            font-weight: bold;
            margin: 0 auto 15px auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table td {
            padding: 6px;
            font-size: 14px;
        }
        table tr td:first-child {
            width: 40%;
            font-weight: bold;
            background: #f0f4ff;
        }
        .profile-card input, .profile-card button {
            margin-top: 5px;
            padding: 8px;
            width: 95%;
            border-radius: 6px;
            border: 1px solid #ccd8ff;
            font-size: 14px;
        }
        .profile-card button {
            background: #2a5dff;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }
        .profile-card button:hover { background: #1d48cc; }
        .car-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .car-card {
            border: 1px solid #ccd8ff;
            border-radius: 12px;
            background: #f9fbff;
            padding: 10px;
            text-align: center;
        }
        .car-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
        }
        .delete-btn {
            display: inline-block;
            margin-top: 5px;
            color: red;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="profile-container">
    <h2 style="text-align:center; color:#2a5dff;">My Profile</h2>

    <div class="profile-section">
        <!-- Owner Profile Card -->
        <div class="profile-card">
            <h3>Owner Info</h3>
            <div class="image-wrapper">
                <?php if($ownerImage): ?>
                    <img src="<?= $ownerImage ?>" alt="Owner Image">
                    <div class="overlay-info">
                        <strong><?= htmlspecialchars($user['name']) ?></strong><br>
                        <?= htmlspecialchars($user['email']) ?><br>
                        <?= htmlspecialchars($user['contact']) ?>
                    </div>
                <?php else: ?>
                    <div class="placeholder">No owner image</div>
                <?php endif; ?>
            </div>

            <form method="POST" enctype="multipart/form-data" style="text-align:left;">
                <table>
                    <tr><td>Owner Name:</td>
                        <td><input type="text" name="owner_name" value="<?= htmlspecialchars($user['name']) ?>" required></td></tr>
                    <tr><td>Email:</td>
                        <td><input type="email" name="owner_email" value="<?= htmlspecialchars($user['email']) ?>" required></td></tr>
                    <tr><td>Contact:</td>
                        <td><input type="text" name="owner_contact" value="<?= htmlspecialchars($user['contact']) ?>" required></td></tr>
                    <tr><td>Owner Image:</td>
                        <td><input type="file" name="owner_image" accept="image/*"></td></tr>
                    <tr><td colspan="2">
                        <button type="submit" name="update_owner_info">Save Info</button>
                        <button type="submit" name="save_owner_image" style="margin-top:5px;">Save Image</button>
                    </td></tr>
                </table>
            </form>
        </div>

        <!-- Cars Card -->
        <div class="profile-card" style="flex:2;">
            <h3>My Cars</h3>

            <div class="car-grid">
                <?php if($cars): ?>
                    <?php foreach($cars as $car): ?>
                        <div class="car-card">
                            <img src="<?= $car['car_image'] ?: 'placeholder_car.png' ?>" alt="Car Image">
                            <p><strong><?= htmlspecialchars($car['car_model']) ?></strong></p>
                            <p>Plate: <?= htmlspecialchars($car['plate_number']) ?></p>
                            <p>Color: <?= htmlspecialchars($car['car_color']) ?></p>
                            <p>Version: <?= htmlspecialchars($car['car_version']) ?></p>
                            <a class="delete-btn" href="?delete_car=<?= $car['id'] ?>" onclick="return confirm('Delete this car?')">Delete</a>
                            <button onclick="showEditCarModal('<?= $car['id'] ?>','<?= htmlspecialchars($car['car_model'],ENT_QUOTES) ?>','<?= htmlspecialchars($car['plate_number'],ENT_QUOTES) ?>','<?= htmlspecialchars($car['car_color'],ENT_QUOTES) ?>','<?= htmlspecialchars($car['car_version'],ENT_QUOTES) ?>')" style="margin-top:5px;background:#2a5dff;color:#fff;border:none;padding:5px 10px;border-radius:5px;cursor:pointer;">Edit</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="grid-column:1/-1;text-align:center;color:#666;">No cars registered yet.</p>
                <?php endif; ?>
            </div>

<!-- Edit Car Modal -->
<div id="editCarModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;padding:30px;border-radius:10px;max-width:400px;margin:auto;color:#222;">
        <h2>Edit Car</h2>
        <form method="POST">
            <input type="hidden" name="edit_car" value="1">
            <input type="hidden" id="edit_car_id" name="edit_car_id">
            <label>Car Model:</label><br><input type="text" id="edit_car_model" name="edit_car_model" required><br>
            <label>Plate Number:</label><br><input type="text" id="edit_plate_number" name="edit_plate_number" required><br>
            <label>Color:</label><br><input type="text" id="edit_car_color" name="edit_car_color" required><br>
            <label>Version:</label><br><input type="text" id="edit_car_version" name="edit_car_version"><br><br>
            <button type="submit" style="background:#2a5dff;color:#fff;border:none;padding:10px 20px;border-radius:5px;">Save Changes</button>
            <button type="button" onclick="document.getElementById('editCarModal').style.display='none'" style="margin-left:10px;">Cancel</button>
        </form>
    </div>
</div>

<script>
function showEditCarModal(id, model, plate, color, version) {
    var modal = document.getElementById('editCarModal');
    modal.style.display = 'flex';
    document.getElementById('edit_car_id').value = id;
    document.getElementById('edit_car_model').value = model;
    document.getElementById('edit_plate_number').value = plate;
    document.getElementById('edit_car_color').value = color;
    document.getElementById('edit_car_version').value = version;
}
window.onclick = function(event) {
    var modal = document.getElementById('editCarModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

            <h4 style="margin-top:20px;">Add New Car</h4>
            <form method="POST" enctype="multipart/form-data" style="text-align:left; margin-top:10px;">
                <table>
                    <tr><td>Car Model:</td>
                        <td><input type="text" name="car_model" placeholder="e.g. Toyota Vios" required></td></tr>
                    <tr><td>Plate Number:</td>
                        <td><input type="text" name="plate_number" placeholder="e.g. ABC1234" required></td></tr>
                    <tr><td>Car Color:</td>
                        <td><input type="text" name="car_color" placeholder="e.g. Red" required></td></tr>
                    <tr><td>Year/Version:</td>
                        <td><input type="text" name="car_version" placeholder="e.g. 2023"></td></tr>
                    <tr><td>Car Image:</td>
                        <td><input type="file" name="car_image" accept="image/*"></td></tr>
                    <tr><td colspan="2">
                        <button type="submit" name="add_car">Register Car</button>
                    </td></tr>
                </table>
            </form>
        </div>
    </div>

    <p style="margin-top:20px;text-align:center;"><a href="index.php">⬅ Back to Homepage</a></p>
</div>

</body>
</html>