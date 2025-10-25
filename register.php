<?php
session_start();
// Generate a unique form token
$form_token = bin2hex(random_bytes(32));
$_SESSION['form_token'] = $form_token;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register - Vehicle Service</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .error-message {
      color: red;
      margin-bottom: 15px;
    }
    .success-message {
      color: green;
      margin-bottom: 15px;
    }
  </style>
</head>
<body class="auth-page">

  <div class="auth-container">
    <h2>Create Account</h2>
    
    <?php
    // Display error/success messages
    if (isset($_SESSION['error'])) {
        echo '<div class="error-message">' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div class="success-message">' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    ?>

    <!-- Registration Form -->
    <form method="POST" action="register_process.php" onsubmit="return validateForm()">
      <!-- Form Token -->
      <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
      
      <!-- Email -->
      <input type="email" name="email" placeholder="Email" required>

      <!-- Full Name -->
      <input type="text" name="name" placeholder="Full Name" required>

      <!-- Contact Number -->
      <input type="text" name="contact" placeholder="Contact Number" 
             pattern="[0-9]{11}" 
             title="Enter a valid 11-digit contact number" required>

      <!-- Password -->
      <input type="password" name="password" placeholder="Password" minlength="6" required>

      <!-- Confirm Password -->
      <input type="password" name="confirm_password" placeholder="Confirm Password" minlength="6" required>

      <button type="submit">Register</button>
    </form>

    <p>Already have an account? <a href="login.php">Log In</a></p>
  </div>

  <script>
    // Simple JS check for matching passwords before submitting
    function validateForm() {
      let pass = document.querySelector('input[name="password"]').value;
      let confirm = document.querySelector('input[name="confirm_password"]').value;

      if (pass !== confirm) {
        alert("❌ Passwords do not match!");
        return false;
      }
      return true;
    }
  </script>

</body>
</html>