<?php
session_start();
// Generate a unique form token for login
$form_token = bin2hex(random_bytes(32));
$_SESSION['login_token'] = $form_token;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Vehicle Service</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .error-message {
      color: red;
      margin-bottom: 15px;
      text-align: center;
    }
    .success-message {
      color: green;
      margin-bottom: 15px;
      text-align: center;
    }
  </style>
</head>
<body class="auth-page">

  <div class="auth-container">
    <h2>Login to Your Account</h2>
    
    <?php
    // Display error/success messages
    if (isset($_SESSION['login_error'])) {
        echo '<div class="error-message">' . $_SESSION['login_error'] . '</div>';
        unset($_SESSION['login_error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div class="success-message">' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    ?>

    <!-- Login Form -->
    <form method="POST" action="login_process.php">
      <!-- Form Token -->
      <input type="hidden" name="login_token" value="<?php echo $form_token; ?>">
      
      <!-- Email -->
      <input type="email" name="email" placeholder="Email" required>

      <!-- Password -->
      <input type="password" name="password" placeholder="Password" required>

      <button type="submit">Log In</button>
    </form>

    <p>Don't have an account? <a href="register.php">Register</a></p>
  </div>

</body>
</html>