<?php
session_start();
include __DIR__ . '/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify form token
    if (!isset($_SESSION['login_token']) || !isset($_POST['login_token']) || 
        $_SESSION['login_token'] !== $_POST['login_token']) {
        $_SESSION['login_error'] = "Invalid form submission. Please try again.";
        header("Location: login.php");
        exit();
    }

    // Check if email and password are provided
    if (empty(trim($_POST['email'])) || empty(trim($_POST['password']))) {
        $_SESSION['login_error'] = "Please enter both email and password!";
        header("Location: login.php");
        exit();
    }

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    // Normalize email
    $normalizedEmail = strtolower($email);
    
    // Prepare and execute query (hardened with fallbacks)
    $sql = "SELECT id, email, password, name FROM users WHERE email = ? LIMIT 1";
    $user = null;

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $normalizedEmail);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res) {
                if ($res->num_rows === 1) {
                    $user = $res->fetch_assoc();
                }
            } else {
                // Fallback if mysqlnd is not available
                $stmt->bind_result($id, $em, $passHash, $name);
                if ($stmt->fetch()) {
                    $user = [
                        'id' => $id,
                        'email' => $em,
                        'password' => $passHash,
                        'name' => $name
                    ];
                }
            }
        }
        $stmt->close();
    } else {
        // Last-resort fallback using query() to avoid fatal error
        $safeEmail = $conn->real_escape_string($normalizedEmail);
        $sql2 = "SELECT id, email, password, name FROM users WHERE email = '" . $safeEmail . "' LIMIT 1";
        if ($res2 = $conn->query($sql2)) {
            if ($res2->num_rows === 1) {
                $user = $res2->fetch_assoc();
            }
            $res2->free();
        } else {
            error_log('Login query failed: ' . $conn->error);
        }
    }

    if ($user) {
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Password is correct, set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['logged_in'] = true;

            // Used by rest of the site
            $_SESSION['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name']
            ];
            
            // Regenerate token after successful login
            $_SESSION['login_token'] = bin2hex(random_bytes(32));
            
            // Redirect to home page
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['login_error'] = "Invalid email or password!";
            header("Location: login.php");
            exit();
        }
    } else {
        $_SESSION['login_error'] = "No account found with that email!";
        header("Location: login.php");
        exit();
    }
    
    // Optional: $conn->close();
} else {
    header("Location: login.php");
    exit();
}
?>