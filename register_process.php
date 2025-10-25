<?php
session_start();
include __DIR__ . '/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify token
    if (!isset($_SESSION['form_token']) || !isset($_POST['form_token']) ||
        $_SESSION['form_token'] !== $_POST['form_token']) {
        $_SESSION['error'] = "Invalid form submission. Please try again.";
        header("Location: register.php");
        exit();
    }

    $email            = trim($_POST['email']);
    $name             = trim($_POST['name']);
    $contact          = trim($_POST['contact']);
    $password         = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    // Password check
    if ($password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match!";
        header("Location: register.php");
        exit();
    }

    // Normalize & validate email
    $normalizedEmail = strtolower($email);
    if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format!";
        header("Location: register.php");
        exit();
    }

    // Validate contact number (11 digits PH format)
    if (!preg_match('/^[0-9]{11}$/', $contact)) {
        $_SESSION['error'] = "Contact number must be exactly 11 digits!";
        header("Location: register.php");
        exit();
    }

    // Check if email exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    if (!$stmt) {
        $_SESSION['error'] = "Prepare failed (SELECT): " . $conn->error;
        header("Location: register.php");
        exit();
    }
    $stmt->bind_param("s", $normalizedEmail);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $_SESSION['error'] = "Email already registered!";
        $stmt->close();
        header("Location: register.php");
        exit();
    }
    $stmt->close();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (email, name, contact, password) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        $_SESSION['error'] = "Prepare failed (INSERT): " . $conn->error;
        header("Location: register.php");
        exit();
    }
    $stmt->bind_param("ssss", $normalizedEmail, $name, $contact, $hashedPassword);

    if ($stmt->execute()) {
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        $_SESSION['success'] = "Registration successful! You can now log in.";
        $stmt->close();
        $conn->close();
        header("Location: login.php");
        exit();
    } else {
        $_SESSION['error'] = "Error during registration: " . $stmt->error;
        $stmt->close();
        $conn->close();
        header("Location: register.php");
        exit();
    }
} else {
    header("Location: register.php");
    exit();
}
?>
