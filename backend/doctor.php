<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
$success = false;
$openSection = 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['section']) && $_POST['section'] === 'history') {
    $openSection = 'history';
} elseif (isset($_GET['done']) && isset($_SESSION['prescription_sent'])) {
    $success = true;
    unset($_SESSION['prescription_sent']);
    $openSection = 'prescriptions';
} elseif (isset($_GET['new'])) {
    $openSection = 'prescriptions';
}

require 'db_connection.php';

$doctorID = $_SESSION['DoctorID'];
$doctorName = $_SESSION['DoctorName'] ?? 'Doctor';

$totalPatients = 0;
$completedPrescriptions = 0;
$pendingPrescriptions = 0;
$todayPrescriptions = 0;

$res1 = $conn->query("SELECT COUNT(*) FROM Patients");
$totalPatients = $res1->fetch_row()[0];

$res2 = $conn->query("SELECT COUNT(*) FROM Prescriptions WHERE Status='completed'");
$completedPrescriptions = $res2->fetch_row()[0];

$res3 = $conn->query("SELECT COUNT(*) FROM Prescriptions WHERE Status='pending'");
$pendingPrescriptions = $res3->fetch_row()[0];

$res4 = $conn->query("SELECT COUNT(*) FROM Prescriptions WHERE DoctorID='$doctorID' AND Date=CURDATE()");
$todayPrescriptions = $res4->fetch_row()[0];

$patientList = [];
$medList = [];
$resP = $conn->query("SELECT PatientID, FirstName FROM Patients");
while ($row = $resP->fetch_assoc()) {
    $patientList[] = $row['PatientID'] . ' - ' . $row['FirstName'];
}
$resM = $conn->query("SELECT MedicineID, MedicineName, StockQuantity FROM Medicines");
while ($row = $resM->fetch_assoc()) {
    $stock = (int)$row['StockQuantity'];
    if ($stock > 0) {
        $medList[] = $row['MedicineID'] . ' - ' . $row['MedicineName'] . " ({$stock} pcs)";
    } else {
        $medList[] = $row['MedicineID'] . ' - <s style=\'color:gray;\'>' . $row['MedicineName'] . '</s> (Out of Stock)';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispense'])) {
    $patientInput = $_POST['patient_id'];
    $patientID = explode(' - ', $patientInput)[0];
    $medicines = $_POST['medicines'];
    $quantities = $_POST['quantities'];
    $otp = rand(100000, 999999);

    $stmt = $conn->prepare("INSERT INTO Prescriptions (PatientID, DoctorID, Status, OTP, Date) VALUES (?, ?, 'pending', ?, CURDATE())");
    $stmt->bind_param("iis", $patientID, $doctorID, $otp);
    $stmt->execute();
    $prescriptionID = $stmt->insert_id;
    $stmt->close();

    foreach ($medicines as $index => $medID) {
        $qty = $quantities[$index];
        $check = $conn->query("SELECT StockQuantity FROM Medicines WHERE MedicineID = $medID");
        $available = $check->fetch_row()[0];

        if ($qty > $available) {
            die("<script>alert('❌ Cannot dispense more than available stock.'); window.location='doctor.php?new=1';</script>");
        }
        $conn->query("INSERT INTO PrescriptionItems (PrescriptionID, MedicineID, DispensedQuantity, InitialDispenseQty) VALUES ($prescriptionID, $medID, $qty, $qty)");
        $conn->query("UPDATE Medicines SET StockQuantity = StockQuantity - $qty WHERE MedicineID = $medID");
    }

    $res = $conn->query("SELECT Email FROM Patients WHERE PatientID = $patientID");
    $email = $res->fetch_row()[0];

    require 'phpmailer/PHPMailer.php';
    require 'phpmailer/SMTP.php';
    require 'phpmailer/Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'DawaaTech@gmail.com';
        $mail->Password = 'vjby qmot okak wqzs';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('DawaaTech@gmail.com', 'DawaaTech');
        $mail->addAddress($email);
        $mail->Subject = 'Your OTP Code';
        $mail->Body = "Your OTP is: $otp";
        $mail->send();
    } catch (Exception $e) {}
    $_SESSION['prescription_sent'] = true;
    $_GET['done'] = 1;
    $openSection = 'prescriptions';

} elseif (isset($_POST['search_prescriptions'])) {
    $keyword = trim($_POST['search_keyword']);
    $query = "SELECT * FROM Prescriptions WHERE PatientID LIKE '%$keyword%' OR PatientID IN (SELECT PatientID FROM Patients WHERE FirstName LIKE '%$keyword%')";
    $searchResults = $conn->query($query);
    $openSection = 'history';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DawaaTech Doctor Panel</title>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body { margin: 0; font-family: 'Tajawal', sans-serif; display: flex; height: 100vh; background: #f4f6f9; }
    .sidebar { width: 220px; background: #fff; border-right: 1px solid #ddd; padding: 20px; }
    .sidebar img { width: 100px; margin-bottom: 20px; transform: scale(2); margin-left: 1rem; }
    .sidebar h2 { font-size: 22px; margin-bottom: 30px; color: #20317c; }
    .sidebar ul { list-style: none; padding: 0; }
    .sidebar li { margin: 15px 0; padding: 10px; cursor: pointer; border-left: 4px solid transparent; transition: 0.3s; }
    .sidebar li:hover, .sidebar li.active { border-left: 4px solid #20317c; background: rgba(32, 49, 124, 0.06); }
    .main { flex: 1; padding: 40px; overflow-y: auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; }
    .topbar h1 { color: #20317c; }
    .topbar .welcome { color: #20317c; font-weight: bold; margin-top: 10px; }
    .card-box { display: flex; gap: 20px; margin-top: 20px; }
    .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); flex: 1; border-top: 4px solid #20317c; transition: 0.3s; }
    .card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .card h3 { color: #20317c; margin-bottom: 10px; }
    .card p { font-size: 18px; font-weight: bold; }
    .form-section { margin-top: 30px; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 6px; color: #333; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; }
    .btn { background: #20317c; color: white; padding: 10px 15px; border: none; border-radius: 6px; cursor: pointer; transition: 0.3s; }
    .btn:hover { background: #162458; }
    .backlog { margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px; }
    .backlog-item { background: #f9f9f9; padding: 10px; margin-bottom: 10px; border-radius: 6px; display: flex; justify-content: space-between; }
    .success-box { margin-top: 20px; padding: 15px; background: #e0f8e9; color: #2d7a46; border-left: 4px solid #2d7a46; font-weight: bold; border-radius: 8px; }
    .autocomplete-items { position: absolute; border: 1px solid #d4d4d4; background-color: #fff; max-height: 200px; overflow-y: auto; z-index: 99; }
    .autocomplete-items div { padding: 10px; cursor: pointer; border-bottom: 1px solid #d4d4d4; }
    .autocomplete-items div:hover { background-color: #e9e9e9; }
    #loadingBox { display: none; margin-top: 20px; padding: 15px; background: #fff3cd; color: #856404; border-left: 4px solid #ffeeba; border-radius: 8px; font-weight: bold; }
  </style>
</head>
<body>
  <div class="sidebar">
    <img src="logo.png" alt="Logo">
    <h2>DawaaTech</h2>
    <ul>
      <li class="active" onclick="showSection('dashboard')"><i class="fas fa-chart-line" style="margin-right:8px;"></i>Dashboard</li>
      <li onclick="showSection('prescriptions')"><i class="fas fa-prescription-bottle-alt" style="margin-right:8px;"></i>Prescriptions</li>
      <li onclick="showSection('history')"><i class="fas fa-history" style="margin-right:8px;"></i>Prescriptions History</li>
    </ul>
  </div>
  <div class="main">
    <div class="topbar">
      <div>
        <h1>Doctor Dashboard</h1>
        <div class="welcome">Welcome Dr. <?= htmlspecialchars($doctorName) ?></div>
      </div>
      <a href="login.php" class="btn">Log Out</a>
    </div>
    <div id="section-dashboard">
      <div class="card-box">
        <div class="card"><h3>Total Patients</h3><p><?= $totalPatients ?></p></div>
        <div class="card"><h3>Completed Prescriptions</h3><p><?= $completedPrescriptions ?></p></div>
        <div class="card"><h3>Pending Prescriptions</h3><p><?= $pendingPrescriptions ?></p></div>
        <div class="card"><h3>Today’s Prescriptions</h3><p><?= $todayPrescriptions ?></p></div>
      </div>
    </div>

    <div id="section-prescriptions" style="display:none">
      <div class="form-section">
        <h2>Create Prescription</h2>
        <div id="loadingBox">⏳ Processing prescription, please wait...</div>

        <?php if ($success): ?>
          <div class="success-box">✔️ Prescription dispensed successfully. OTP sent to patient. <br><br>
          <a href="?new=1" class="btn" style="margin-top:10px">Create New Prescription</a>
          </div>
        <?php else: ?>
        <form method="POST" autocomplete="off">
          <div class="form-group autocomplete">
            <label>Patient ID or Name</label>
            <input type="text" name="patient_id" id="patient_id" required>
          </div>
          <div class="form-group autocomplete">
            <label>Select Medicine</label>
            <input type="text" id="medicine" required>
          </div>
          <div class="form-group">
            <label>Quantity</label>
            <input type="number" id="quantity" value="1" min="1" required>
          </div>
          <button type="button" onclick="addMedicine()" class="btn">Add</button>
          <div class="backlog" id="backlog"></div>
          <div id="medicinesInput"></div>
          <div id="quantitiesInput"></div>
          <button type="submit" name="dispense" class="btn" style="margin-top:20px" onclick="showLoading()">Dispense</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div id="section-history" style="display:none">
      <div class="form-section">
        <h2>Prescription History</h2>
        <form method="POST">
          <input type="hidden" name="section" value="history">
          <div class="form-group">
            <label>Search by Patient Name or ID</label>
            <input type="text" name="search_keyword" placeholder="Enter name or ID..." required>
          </div>
          <button type="submit" name="search_prescriptions" class="btn">Search</button>
        </form>
        <?php if (!empty($searchResults) && $searchResults->num_rows > 0): ?>
        <div style="margin-top: 20px; overflow-x: auto;">
          <table style="width:100%; border-collapse: collapse;">
            <thead>
              <tr style="background: #20317c; color:white;">
                <th style="padding: 10px; border: 1px solid #ddd;">PrescriptionID</th>
                <th style="padding: 10px; border: 1px solid #ddd;">PatientID</th>
                <th style="padding: 10px; border: 1px solid #ddd;">Status</th>
                <th style="padding: 10px; border: 1px solid #ddd;">OTP</th>
                <th style="padding: 10px; border: 1px solid #ddd;">Date</th>
              </tr>
            </thead>
            <tbody>
              <?php while($row = $searchResults->fetch_assoc()): ?>
              <tr>
                <td style="padding: 10px; border: 1px solid #ddd;"><?= $row['PrescriptionID'] ?></td>
                <td style="padding: 10px; border: 1px solid #ddd;"><?= $row['PatientID'] ?></td>
                <td style="padding: 10px; border: 1px solid #ddd;"><?= $row['Status'] ?></td>
                <td style="padding: 10px; border: 1px solid #ddd;"><?= $row['OTP'] ?></td>
                <td style="padding: 10px; border: 1px solid #ddd;"><?= $row['Date'] ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php elseif(isset($_POST['search_prescriptions'])): ?>
          <p style="margin-top:20px">No prescriptions found for your search.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

<script>
  const patients = <?= json_encode($patientList); ?>;
  const meds = <?= json_encode($medList); ?>;

  function setupCustomAutocomplete(inputId, dataArray) {
    const input = document.getElementById(inputId);
    input.addEventListener('focus', () => showDropdown(input, dataArray));
    input.addEventListener('input', () => showDropdown(input, dataArray));
    document.addEventListener('click', (e) => {
      if (!input.contains(e.target)) closeDropdown();
    });
  }

  function showDropdown(input, data) {
    closeDropdown();
    const val = input.value.toLowerCase();
    const list = document.createElement("div");
    list.classList.add("autocomplete-items");
    data.filter(item => item.toLowerCase().includes(val)).forEach(entry => {
      const div = document.createElement("div");
      div.innerHTML = entry;
      if (entry.includes("Out of Stock")) {
        div.style.pointerEvents = 'none';
        div.style.opacity = '0.6';
      } else {
        div.addEventListener("click", () => {
          input.value = entry;
          closeDropdown();
        });
      }
      list.appendChild(div);
    });
    input.parentNode.appendChild(list);
  }

  function closeDropdown() {
    const lists = document.querySelectorAll(".autocomplete-items");
    lists.forEach(l => l.remove());
  }

  let backlog = [];
  function addMedicine() {
    const medInput = document.getElementById('medicine');
    const qty = parseInt(document.getElementById('quantity').value);
    const medText = medInput.value;
    const medID = medText.split(' - ')[0];

    if (!meds.includes(medText) || !medID || !qty || qty < 1 || medText.includes('Out of Stock')) {
      alert("❌ Please select a valid medicine from the list.");
      return;
    }

    const match = medText.match(/\((\d+)\s*pcs\)/i);
    const availableStock = match ? parseInt(match[1]) : 0;

    const existing = backlog.find(item => item.medID === medID);
    const newTotalQty = existing ? parseInt(existing.qty) + qty : qty;

    if (newTotalQty > availableStock) {
      alert(`❌ Cannot add more than available stock (${availableStock} pcs).`);
      return;
    }

    if (existing) {
      existing.qty = newTotalQty;
    } else {
      backlog.push({ medID, qty, medName: medText });
    }
    renderBacklog();
  }

  document.addEventListener("DOMContentLoaded", () => {
    setupCustomAutocomplete("patient_id", patients);
    setupCustomAutocomplete("medicine", meds);
    showSection("<?= $openSection ?>");
  });

  function showSection(section) {
    const sections = ['dashboard', 'prescriptions', 'history'];
    sections.forEach(id => {
      document.getElementById('section-' + id).style.display = id === section ? 'block' : 'none';
    });
    const items = document.querySelectorAll('.sidebar li');
    items.forEach((item, idx) => {
      item.classList.toggle('active', sections[idx] === section);
    });
  }

  function showLoading() {
    document.getElementById('loadingBox').style.display = 'block';
  }

  function renderBacklog() {
    const container = document.getElementById('backlog');
    const medsDiv = document.getElementById('medicinesInput');
    const qtysDiv = document.getElementById('quantitiesInput');
    container.innerHTML = '';
    medsDiv.innerHTML = '';
    qtysDiv.innerHTML = '';

    if (backlog.length === 0) {
      container.innerHTML = "<div style='color:gray;'>No medicines added.</div>";
      return;
    }

    backlog.forEach((item, index) => {
      const itemDiv = document.createElement('div');
      itemDiv.className = 'backlog-item';
      itemDiv.innerHTML = `
        <span>${item.medName} - ${item.qty} pcs</span>
        <button onclick="removeBacklog(${index})" style="background:red; color:white; border:none; padding:3px 8px; border-radius:4px;">X</button>
      `;
      container.appendChild(itemDiv);

      const hiddenMed = document.createElement('input');
      hiddenMed.type = 'hidden';
      hiddenMed.name = 'medicines[]';
      hiddenMed.value = item.medID;

      const hiddenQty = document.createElement('input');
      hiddenQty.type = 'hidden';
      hiddenQty.name = 'quantities[]';
      hiddenQty.value = item.qty;

      medsDiv.appendChild(hiddenMed);
      qtysDiv.appendChild(hiddenQty);
    });
  }

  function removeBacklog(index) {
    backlog.splice(index, 1);
    renderBacklog();
  }
</script>
</body>
</html>
