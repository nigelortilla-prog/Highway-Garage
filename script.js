// Smooth Scroll to Appointment
function scrollToAppointment() {
  document.getElementById('appointment').scrollIntoView({ behavior: 'smooth' });
}

// Dropdown Fix: Keep menu visible when hovering
document.querySelectorAll('.dropdown').forEach(dropdown => {
  dropdown.addEventListener('mouseenter', () => {
    dropdown.querySelector('.dropdown-menu').style.display = 'block';
  });
  dropdown.addEventListener('mouseleave', () => {
    dropdown.querySelector('.dropdown-menu').style.display = 'none';
  });
});

// Appointment Form Validation
document.getElementById('appointmentForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  alert('✅ Your appointment has been submitted!');
  this.reset();
});

// Login Validation Example
document.getElementById('loginForm')?.addEventListener('submit', function(e) {
  let username = this.username.value.trim();
  let password = this.password.value.trim();
  if (username === "" || password === "") {
    e.preventDefault();
    alert("⚠ Please fill in both fields.");
  }
});

// Register Form Validation Example
document.getElementById('registerForm')?.addEventListener('submit', function(e) {
  let pass = this.password.value;
  let confirmPass = this.confirm_password.value;
  if (pass !== confirmPass) {
    e.preventDefault();
    alert("❌ Passwords do not match!");
  }
});
