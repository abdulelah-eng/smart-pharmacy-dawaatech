<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require 'db_connection.php';

$loginError = '';
$signupSuccess = '';
$activeTab = 'patient';

if (isset($_POST['doctor_login'])) {
    $email = $_POST['doctor_email'];
    $password = $_POST['doctor_password'];
    $stmt = $conn->prepare("SELECT DoctorID, FirstName FROM Doctors WHERE Email = ? AND Password = ?");
    $stmt->bind_param("ss", $email, $password);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($doctorID, $firstName);
        $stmt->fetch();
        $_SESSION['DoctorID'] = $doctorID;
        $_SESSION['DoctorName'] = $firstName;
        header("Location: doctor.php");
        exit();
    } else {
        $loginError = "❌ Email or password is incorrect.";
        $activeTab = 'doctor';
    }
    $stmt->close();
}

if (isset($_POST['patient_login'])) {
    $email = $_POST['patient_email'];
    $password = $_POST['patient_password'];
    $stmt = $conn->prepare("SELECT PatientID, FirstName FROM Patients WHERE Email = ? AND Password = ?");
    $stmt->bind_param("ss", $email, $password);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($patientID, $firstName);
        $stmt->fetch();
        $_SESSION['PatientID'] = $patientID;
        $_SESSION['PatientName'] = $firstName;
        header("Location: patient.php");
        exit();
    } else {
        $loginError = "❌ Email or password is incorrect.";
        $activeTab = 'patient';
    }
    $stmt->close();
}

if (isset($_POST['patient_signup'])) {
    $id = $_POST['signup_id'];
    $first = $_POST['signup_first'];
    $last = $_POST['signup_last'];
    $age = $_POST['signup_age'];
    $gender = $_POST['signup_gender'];
    $email = $_POST['signup_email'];
    $password = $_POST['signup_password'];
    $phone = $_POST['signup_phone'];
    $address = $_POST['signup_address'];
    $stmt = $conn->prepare("INSERT INTO Patients (PatientID, FirstName, LastName, Age, Email, Password, Gender, PhoneNumber, Address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississsss", $id, $first, $last, $age, $email, $password, $gender, $phone, $address);
    if ($stmt->execute()) {
        $signupSuccess = "✅ Account created successfully. You can now sign in.";
        $activeTab = 'patient';
    } else {
        $loginError = "❌ Email or ID already exists.";
        $activeTab = 'patient';
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DawaaTech - Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Tajawal', sans-serif;
    }
    body {
      height: 100vh;
      display: flex;
      background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
    }
    .left-panel {
      width: 50%;
      background: linear-gradient(312deg, #20317c 0%, #ffffff 100%);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      color: white;
      position: relative;
      overflow: hidden;
    }
    .left-panel h1 {
      font-size: 36px;
      font-weight: 700;
      z-index: 2;
      margin-bottom: 10px;
    }
    .left-panel p {
      font-size: 16px;
      opacity: 0.85;
      text-align: center;
      max-width: 400px;
      z-index: 2;
    }
    .decoration {
      position: absolute;
      width: 100%;
      height: 100%;
      top: 0; left: 0;
      overflow: hidden;
      z-index: 1;
    }
    .circle {
      position: absolute;
      border-radius: 50%;
      background: rgba(255,255,255,0.05);
    }
    .circle1 {
      width: 250px;
      height: 250px;
      top: -80px;
      right: -80px;
    }
    .circle2 {
      width: 200px;
      height: 200px;
      bottom: -50px;
      left: -50px;
    }
    .waves {
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 100px;
    }

    .right-panel {
      width: 50%;
      background: #dee3ea;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 40px;
    }

    .card {
      width: 100%;
      max-width: 400px;
      background: #fff;
      border-radius: 16px;
      padding: 30px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    }

    .logo-box {
      text-align: center;
      margin-bottom: 20px;
    }
    .logo-box img {
      transform: scale(2); 
      width: 90px;
    }
    .logo-box h2 {
      font-size: 22px;
      font-weight: bold;
      color: #1b2656;
      text-align: left;
      padding-left: 103px;
    }

    .tabs {
      display: flex;
      justify-content: space-between;
      margin-bottom: 20px;
    }

    .tabs button {
      flex: 1;
      padding: 10px;
      border: none;
      background: none;
      font-weight: bold;
      color: #aaa;
      cursor: pointer;
      border-bottom: 2px solid transparent;
    }

    .tabs button.active {
      color: #1b2656;
      border-color: #1b2656;
    }

    .form-section {
      display: none;
    }
    .form-section.active {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    input, select {
      width: 100%;
      padding: 12px;
      margin: 8px 0;
      border-radius: 8px;
      border: 1px solid #ccc;
    }

    button[type="submit"] {
      width: 100%;
      background: linear-gradient(135deg, #1b2656 0%, #2f3d6a 100%);
      color: white;
      padding: 12px;
      border: none;
      border-radius: 8px;
      margin-top: 10px;
      font-weight: bold;
      cursor: pointer;
    }

    .msg {
      margin-bottom: 10px;
      padding: 10px;
      border-radius: 6px;
      text-align: center;
      font-weight: bold;
    }
    .error {
      background: #fdecea;
      color: #d32f2f;
    }
    .success {
      background: #e8f5e9;
      color: #388e3c;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 768px) {
      body { flex-direction: column; }
      .left-panel, .right-panel { width: 100%; }
    }
  </style>
</head>
<body>

<div class="left-panel">
  <div class="decoration">
    <div class="circle circle1"></div>
    <div class="circle circle2"></div>
    <svg class="waves" viewBox="0 0 500 150" preserveAspectRatio="none">
      <path d="M0.00,49.98 C150.00,150.00 349.91,-49.98 500.00,49.98 L500.00,150.00 L0.00,150.00 Z"
            style="stroke: none; fill: rgba(255,255,255,0.05);"></path>
    </svg>
  </div>
  <h1>Welcome back!</h1>
  <p>You can sign in to access with your existing account.</p>
</div>

<div class="right-panel">
  <div class="card">
    <div class="logo-box">
      <img src="logo.png" alt="Logo">
      <h2>DAWAA TECH</h2>
    </div>

    <?php if ($loginError): ?>
      <div class="msg error"><?= $loginError ?></div>
    <?php elseif ($signupSuccess): ?>
      <div class="msg success"><?= $signupSuccess ?></div>
    <?php endif; ?>

    <div class="tabs">
      <button id="doctorTab">Doctor</button>
      <button id="patientTab" class="active">Patient</button>
    </div>

    <div class="form-section" id="doctorForm">
      <form method="POST">
        <input type="email" name="doctor_email" placeholder="Doctor Email" required>
        <input type="password" name="doctor_password" placeholder="Password" required>
        <button type="submit" name="doctor_login">Login</button>
      </form>
    </div>

    <div class="form-section active" id="patientLogin">
      <form method="POST">
        <input type="email" name="patient_email" placeholder="Patient Email" required>
        <input type="password" name="patient_password" placeholder="Password" required>
        <button type="submit" name="patient_login">Login</button>
      </form>
      <p style="text-align:center;"><a href="#" onclick="switchToSignup()">Don't have an account? Sign Up</a></p>
    </div>

    <div class="form-section" id="patientSignup">
      <form method="POST">
        <input type="text" name="signup_id" placeholder="National ID (5 digits)" pattern="\d{5}" required>
        <input type="text" name="signup_first" placeholder="First Name" required>
        <input type="text" name="signup_last" placeholder="Last Name" required>
        <input type="number" name="signup_age" placeholder="Age" required>
        <select name="signup_gender" required>
          <option disabled selected>Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
        <input type="email" name="signup_email" placeholder="Email" required>
        <input type="password" name="signup_password" placeholder="Password" required>
        <input type="text" name="signup_phone" placeholder="Phone (10 digits)" pattern="\d{10}" required>
        <input type="text" name="signup_address" placeholder="Address" required>
        <button type="submit" name="patient_signup">Sign Up</button>
      </form>
      <p style="text-align:center;"><a href="#" onclick="switchToLogin()">Already have an account? Sign In</a></p>
    </div>
  </div>
</div>

<script>
  const doctorTab = document.getElementById('doctorTab');
  const patientTab = document.getElementById('patientTab');
  const doctorForm = document.getElementById('doctorForm');
  const patientLogin = document.getElementById('patientLogin');
  const patientSignup = document.getElementById('patientSignup');

  doctorTab.onclick = () => {
    doctorTab.classList.add('active');
    patientTab.classList.remove('active');
    doctorForm.classList.add('active');
    patientLogin.classList.remove('active');
    patientSignup.classList.remove('active');
  };

  patientTab.onclick = () => {
    doctorTab.classList.remove('active');
    patientTab.classList.add('active');
    doctorForm.classList.remove('active');
    patientLogin.classList.add('active');
    patientSignup.classList.remove('active');
  };

  function switchToSignup() {
    patientLogin.classList.remove('active');
    patientSignup.classList.add('active');
  }

  function switchToLogin() {
    patientSignup.classList.remove('active');
    patientLogin.classList.add('active');
  }

  window.addEventListener("DOMContentLoaded", () => {
    <?php if ($activeTab === 'doctor'): ?>
      doctorTab.click();
    <?php else: ?>
      patientTab.click();
    <?php endif; ?>
  });
</script>

</body>
</html>
