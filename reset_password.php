<?php
session_start();
include __DIR__ . '/db.php';

// Generate CSRF token
if (empty($_SESSION['rp_token'])) {
    $_SESSION['rp_token'] = bin2hex(random_bytes(32));
}

$error = '';
$info = '';
$showForm = false;
$token = $_GET['token'] ?? '';

if ($token) {
    $tokenHash = hash('sha256', $token);

    // Validate token
    $stmt = $conn->prepare("SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.email FROM password_resets pr JOIN users u ON pr.user_id = u.id WHERE pr.token_hash = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if (!$res) {
            $stmt->bind_result($pr_id, $user_id, $expires_at, $used, $email);
            if ($stmt->fetch()) {
                $row = [
                    'id' => $pr_id,
                    'user_id' => $user_id,
                    'expires_at' => $expires_at,
                    'used' => $used,
                    'email' => $email,
                ];
            }
        }
        $stmt->close();

        if ($row) {
            $now = new DateTime();
            $exp = new DateTime($row['expires_at']);
            if ((int)$row['used'] === 1) {
                $error = 'This reset link has already been used.';
            } elseif ($now > $exp) {
                $error = 'This reset link has expired. Please request a new one.';
            } else {
                $showForm = true;
                $_SESSION['rp_record_id'] = $row['id'];
                $_SESSION['rp_user_id'] = $row['user_id'];
                $_SESSION['rp_email'] = strtolower($row['email']);
            }
        } else {
            $error = 'Invalid reset link.';
        }
    } else {
        $error = 'Unable to validate reset token.';
    }
} else {
    $error = 'Missing reset token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['rp_token']) || $_POST['rp_token'] !== ($_SESSION['rp_token'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $emailPosted = strtolower(trim($_POST['email'] ?? ''));
        $newpass = trim($_POST['password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');
        if ($emailPosted === '' || !filter_var($emailPosted, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } elseif (!isset($_SESSION['rp_email']) || $emailPosted !== $_SESSION['rp_email']) {
            $error = 'Email does not match this reset request.';
        } elseif ($newpass === '' || strlen($newpass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($newpass !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (empty($_SESSION['rp_user_id']) || empty($_SESSION['rp_record_id'])) {
            $error = 'Reset session expired. Please use the link again.';
        } else {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $uid = (int)$_SESSION['rp_user_id'];
            $rid = (int)$_SESSION['rp_record_id'];

            // Update user password and mark token used in a simple sequence
            $ok = true;
            $stmt1 = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            if ($stmt1) {
                $stmt1->bind_param('si', $hash, $uid);
                $ok = $stmt1->execute();
                $stmt1->close();
            } else { $ok = false; }

            if ($ok) {
                $stmt2 = $conn->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
                if ($stmt2) {
                    $stmt2->bind_param('i', $rid);
                    $stmt2->execute();
                    $stmt2->close();
                }
                // Cleanup session
                unset($_SESSION['rp_user_id'], $_SESSION['rp_record_id']);
                $_SESSION['success'] = 'Your password has been reset. You can now log in.';
                header('Location: login.php');
                exit();
            } else {
                $error = 'Unable to reset password at this time.';
            }
        }
    }
    // Keep form visible on error if we still have a valid reset session
    if ($error && isset($_SESSION['rp_user_id'], $_SESSION['rp_record_id'])) {
        $showForm = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .auth-container { max-width: 420px; }
    .message { text-align:center; margin:10px 0; }
    .message.error { color:#ff6b6b; }
  </style>
</head>
<body class="auth-page">
  <div class="auth-container">
    <h2>Reset Password</h2>
    <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($showForm): ?>
            <form method="POST" action="">
        <input type="hidden" name="rp_token" value="<?= htmlspecialchars($_SESSION['rp_token']) ?>" />
                <input type="email" name="email" placeholder="Enter your email" required />
        <input type="password" name="password" placeholder="New password" required />
        <input type="password" name="confirm_password" placeholder="Confirm new password" required />
        <button type="submit">Set New Password</button>
      </form>
    <?php else: ?>
      <p><a href="forgot_password.php">Request a new reset link</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
