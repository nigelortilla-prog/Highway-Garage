<?php
session_start();
include __DIR__ . '/db.php';
// Try to load Composer autoloader for PHPMailer if available
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}
// Optional SMTP config
$smtpCfg = __DIR__ . '/smtp_config.php';
if (file_exists($smtpCfg)) {
  require_once $smtpCfg; // expected to define $SMTP_HOST, $SMTP_USER, $SMTP_PASS, $SMTP_PORT, $SMTP_SECURE, $SMTP_FROM, $SMTP_FROM_NAME
}

// Generate CSRF token
if (empty($_SESSION['fp_token'])) {
    $_SESSION['fp_token'] = bin2hex(random_bytes(32));
}

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['fp_token']) || $_POST['fp_token'] !== $_SESSION['fp_token']) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Look up user
            $stmt = $conn->prepare("SELECT id, email, name FROM users WHERE email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
                if (!$res) {
                    $stmt->bind_result($id, $em, $nm);
                    if ($stmt->fetch()) {
                        $user = ['id' => $id, 'email' => $em, 'name' => $nm];
                    }
                }
                $stmt->close();
            }

            // Always show success message to avoid email enumeration
            $info = 'If an account exists for that email, a reset link has been sent.';

            if ($user) {
                // Create reset token
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expires = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

                // Invalidate previous tokens for this user (optional)
                $conn->query("UPDATE password_resets SET used = 1 WHERE user_id = " . (int)$user['id']);

                $stmt2 = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
                if ($stmt2) {
                    $stmt2->bind_param('iss', $user['id'], $tokenHash, $expires);
                    $stmt2->execute();
                    $stmt2->close();
                }

                // Build reset link
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $link = $scheme . '://' . $host . $path . '/reset_password.php?token=' . $token;

        // Prepare email content
        $subject = 'Password Reset Request';
        $textBody = "Hello " . ($user['name'] ?? 'User') . ",\n\n" .
               "We received a request to reset your password. Click the link below to set a new password (valid for 30 minutes):\n\n" .
               $link . "\n\n" .
               "If you did not request this, you can ignore this email.";
        $htmlBody = '<p>Hello ' . htmlspecialchars($user['name'] ?? 'User') . ',</p>' .
              '<p>We received a request to reset your password. Click the link below to set a new password (valid for 30 minutes):</p>' .
              '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>' .
              '<p>If you did not request this, you can ignore this email.</p>';

        $sent = false;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
          try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            // SMTP if configured
            if (isset($SMTP_HOST) && $SMTP_HOST) {
              $mail->isSMTP();
              $mail->Host = $SMTP_HOST;
              $mail->SMTPAuth = true;
              $mail->Username = $SMTP_USER ?? '';
              $mail->Password = $SMTP_PASS ?? '';
              $mail->SMTPSecure = $SMTP_SECURE ?? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
              $mail->Port = isset($SMTP_PORT) ? (int)$SMTP_PORT : 587;
            }
            $fromEmail = $SMTP_FROM ?? 'no-reply@' . ($host);
            $fromName  = $SMTP_FROM_NAME ?? 'Vehicle Service';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($user['email'], $user['name'] ?? '');
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();
            $sent = true;
          } catch (Throwable $e) {
            error_log('PHPMailer error: ' . $e->getMessage());
            $sent = false;
          }
        }

        // Fallback to native mail or file logging
        if (!$sent) {
          @mail($user['email'], $subject, $textBody);
        }
        // Always log a copy for testing
        $logFile = __DIR__ . '/password_reset_links.txt';
        $entry = date('c') . "\t" . $user['email'] . "\t" . $link . "\n";
        file_put_contents($logFile, $entry, FILE_APPEND);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .auth-container { max-width: 420px; }
    .message { text-align:center; margin:10px 0; }
    .message.error { color: #ff6b6b; }
    .message.info { color: #2ecc71; }
  </style>
</head>
<body class="auth-page">
  <div class="auth-container">
    <h2>Forgot Password</h2>
    <?php if ($error): ?>
      <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
      <div class="message info"><?= htmlspecialchars($info) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="hidden" name="fp_token" value="<?= htmlspecialchars($_SESSION['fp_token']) ?>" />
      <input type="email" name="email" placeholder="Enter your email" required />
      <button type="submit">Send Reset Link</button>
    </form>

    <p><a href="login.php">Back to Login</a></p>
  </div>
</body>
</html>
