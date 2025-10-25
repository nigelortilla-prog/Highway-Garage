<?php
session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];
// Delete all appointments for this user (or you can add a WHERE for status if needed)
$stmt = $conn->prepare("DELETE FROM appointments WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();

header("Location: notifications.php?cleared=1");
exit();
?>