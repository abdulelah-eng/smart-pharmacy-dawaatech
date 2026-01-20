<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require 'db_connection.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if(!isset($_SESSION['PatientID'])){
    header('Location: login.php');
    exit;
}
$patientID = (int)$_SESSION['PatientID'];
$patientName = htmlspecialchars($_SESSION['PatientName'] ?? 'Patient');

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax'])){
    $otp = rand(100000,999999);
    $stmt = $conn->prepare("INSERT INTO Prescriptions (PatientID,DoctorID,Status,OTP,Date) VALUES (?,?,?,?,CURDATE())");
    $status='pending';
    $doctorNull = null;
    $stmt->bind_param('iiss',$patientID,$doctorNull,$status,$otp);
    $stmt->execute();
    $pid=$stmt->insert_id;
    $stmt->close();

    $meds = explode(',',$_POST['medicines']);
    $qtys = explode(',',$_POST['quantities']);
    foreach($meds as $i=>$mid){
        $q=(int)$qtys[$i];
       $conn->query("INSERT INTO PrescriptionItems (PrescriptionID, MedicineID, DispensedQuantity, InitialDispenseQty) VALUES ($pid, $mid, $q, $q)");

        $conn->query("UPDATE Medicines SET StockQuantity=StockQuantity-$q WHERE MedicineID=$mid");
    }

    $stmt = $conn->prepare("SELECT Email FROM Patients WHERE PatientID=?");
    $stmt->bind_param("i", $patientID);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $stmt->close();

    $mail = new PHPMailer(true);
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
        $mail->Body = "Your prescription OTP is: $otp";
        $mail->send();
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
    }

    echo json_encode(['status'=>'success']);
    exit;
}

$pendingQ = $conn->query("
    SELECT p.PrescriptionID, p.Date, p.OTP, p.DoctorID,
            SUM(pi.DispensedQuantity * m.price) AS total,
            SUM(CASE WHEN pi.ScanBarcode = 'dispensed' THEN 1 ELSE 0 END) AS dispensed_count,
            COUNT(*) AS total_items
    FROM Prescriptions p
    JOIN PrescriptionItems pi ON p.PrescriptionID = pi.PrescriptionID
    JOIN Medicines m ON pi.MedicineID = m.MedicineID
   WHERE p.PatientID = $patientID
AND p.Status IN ('pending', 'partially_dispensed')

    GROUP BY p.PrescriptionID
  
");

$compRes = $conn->query("SELECT p.PrescriptionID,p.Date,pi.DispensedQuantity,m.MedicineName,m.price,p.DoctorID
  FROM Prescriptions p
  JOIN PrescriptionItems pi ON p.PrescriptionID=pi.PrescriptionID
  JOIN Medicines m ON pi.MedicineID=m.MedicineID
  WHERE p.PatientID=$patientID AND p.Status='completed'
  ORDER BY p.Date DESC");
$completed = [];
while($r=$compRes->fetch_assoc()){
    $id=$r['PrescriptionID'];
   if(!isset($completed[$id])) $completed[$id]=[
  'date'=>$r['Date'],
  'items'=>[],
  'total'=>0,
  'DoctorID'=>$r['DoctorID']
];
    $line = $r['DispensedQuantity'].' × '.$r['MedicineName'];
    $completed[$id]['items'][]=$line;
    $completed[$id]['total']+=($r['DispensedQuantity']*$r['price']);
}

$medQ=$conn->query("SELECT MedicineID,MedicineName,price,StockQuantity FROM Medicines");
$meds=[];
while($m=$medQ->fetch_assoc()){
    $meds[]= ['id'=>$m['MedicineID'],'name'=>$m['MedicineName'],'price'=>$m['price'],'stock'=>$m['StockQuantity']];
}
$sec='home'; if(!empty($_GET['section'])) $sec=$_GET['section'];
$pendingDetails = [];
$ids = [];
if ($pendingQ->num_rows) {
    while($row = $pendingQ->fetch_assoc()) {
   $statusLabel = 'pending';
$presID = $row['PrescriptionID'];
$res = $conn->query("SELECT InitialDispenseQty, DispensedQuantity FROM PrescriptionItems WHERE PrescriptionID = $presID");
$all = true; $some = false;
while($r = $res->fetch_assoc()){
    if ($r['InitialDispenseQty'] < $r['DispensedQuantity']) $some = true;
    if ($r['InitialDispenseQty'] > 0) $all = false;
}
if ($some && !$all) $statusLabel = 'partial';
elseif ($some && $all) $statusLabel = 'completed';

        $pendingDetails[$row['PrescriptionID']] = [
            'Date' => $row['Date'],
            'OTP' => $row['OTP'],
            'DoctorID' => $row['DoctorID'],
            'Total' => $row['total'],
            'Status' => $statusLabel,
            'Items' => []
        ];
        $ids[] = $row['PrescriptionID'];
    }
}

if (!empty($ids)) {
    $idList = implode(',', $ids);
    $itemsRes = $conn->query("
        SELECT pi.PrescriptionID, pi.DispensedQuantity, m.MedicineName, m.price
        FROM PrescriptionItems pi
        JOIN Medicines m ON pi.MedicineID = m.MedicineID
        WHERE pi.PrescriptionID IN ($idList)
    ");
    while($r = $itemsRes->fetch_assoc()){
        $line = $r['DispensedQuantity'].' × '.$r['MedicineName'];

        $pendingDetails[$r['PrescriptionID']]['Items'][] = $line;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{margin:0;font-family:'Tajawal',sans-serif;display:flex;height:100vh;background:#f4f6f9;}
aside{width:240px;background:#fff;border-right:1px solid #ddd;padding:20px;}
aside img{width:100px;margin-bottom:20px; transform: scale(2); margin-left: 1rem; }
aside h2{font-size:22px;margin-bottom:30px;color:#20317c;}
aside ul{list-style:none;padding:0;}
aside li{display:flex;align-items:center;padding:10px;cursor:pointer;border-left:4px solid transparent;transition:0.3s;}
aside li.active,aside li:hover{background:rgba(32,49,124,0.06);border-left:4px solid #20317c;}
.main{flex:1;overflow:auto;padding:40px;max-width:1000px;}
.topbar{display:flex;justify-content:space-between;align-items:center;}
.topbar h1{color:#20317c;margin:0;font-size:24px;}
.topbar .welcome{color:#20317c;font-weight:bold;}
.topbar a.btn{background:#20317c;color:#fff;padding:8px 12px;border-radius:6px;text-decoration:none;}
.search-bar{width:100%;padding:12px;border:1px solid #ccc;border-radius:6px;margin:20px 0;}
.prescription-card{background:#fff;padding:20px;margin-bottom:20px;border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.05);}
.prescription-card h3{margin:0 0 10px;color:#20317c;display:flex;align-items:center;}
.prescription-card .status{margin-left:8px;font-size:12px;font-weight:bold;text-transform:uppercase;padding:4px 8px;border-radius:4px;}
.status.pending{background:#ffeeba;color:#856404;}
.status.partial {
  background: #cce5ff;
  color: #004085;
}

.status.free{background:#d4edda;color:#155724;}
.form-section{background:#fff;padding:30px;border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.06);}
.form-group{margin-bottom:20px;}
.form-group label{display:block;margin-bottom:6px;color:#333;font-weight:600;}
.form-group input{width:100%;padding:12px;border:1px solid #ccc;border-radius:6px;}
.backlog-item{display:flex;justify-content:space-between;align-items:center;background:#f9f9f9;padding:12px;border-radius:6px;margin-bottom:10px;}
.backlog-item button{background:#e74c3c;border:none;color:#fff;padding:6px 10px;border-radius:4px;cursor:pointer;}
.btn{background:#20317c;color:#fff;padding:10px 15px;border:none;border-radius:6px;cursor:pointer;transition:0.3s;margin-top:10px;}
.btn:hover{background:#162458;}
#paymentModal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;}
.modal-content{background:#fff;width:400px;border-radius:8px;overflow:hidden;}
.modal-header{background:#0073bb;color:#fff;padding:16px;font-size:18px;}
.modal-body{padding:20px;}
.modal-body .loader{display:none;margin:20px auto;border:4px solid #f3f3f3;border-top:4px solid #20317c;border-radius:50%;width:40px;height:40px;animation:spin 1s linear infinite;}
.modal-body .loader.active{display:block;}
.modal-body label, .modal-body input {
  display:block;
  width:100%;
  margin-bottom:10px;
}
.modal-footer{padding:16px;text-align:right;}
#suggestions div:hover { background:#eee; }
@keyframes spin{100%{transform:rotate(360deg);}}
</style>
</head>
<body>
<aside>
  <img src="logo.png" alt="Logo">
  <h2>DawaaTech</h2>
  <ul>
    <li onclick="show('home')" class="<?= $sec==='home'?'active':'' ?>"><i class="fa fa-chart-line" style="margin-right:8px;"></i>Dashboard</li>
    <li onclick="show('prescription')" class="<?= $sec==='prescription'?'active':'' ?>"><i class="fa fa-prescription-bottle-medical" style="margin-right:8px;"></i>Prescription</li>
    <li onclick="show('history')" class="<?= $sec==='history'?'active':'' ?>"><i class="fa fa-history" style="margin-right:8px;"></i>History</li>
  </ul>
</aside>
<main class="main">
  <div class="topbar">
    <div>
      <h1>Patient Dashboard</h1>
      <div class="welcome">Welcome, <?= $patientName ?></div>
    </div>
    <a href="login.php" class="btn">Log Out</a>
  </div>

  <section id="home">
    <input type="text" id="searchHome" class="search-bar" placeholder="Search pending..." onkeyup="filterHome()">
   <?php if(!empty($pendingDetails)): foreach($pendingDetails as $id => $r): ?>
<div class="prescription-card home-item">
<h3>Prescription #<?= $id ?>
  <span class="status <?= $r['Status'] === 'partial' ? 'partial' : 'pending' ?>">
    <?= $r['Status'] === 'partial' ? 'Partially Dispensed' : 'Pending' ?>
  </span>
</h3>

  <p>Date: <?= $r['Date'] ?></p>
  <p>OTP: <?= $r['OTP'] ?></p>

  <p><strong>Contents:</strong></p>
  <?php
$stmt2 = $conn->prepare("
  SELECT pi.InitialDispenseQty, pi.DispensedQuantity, m.MedicineName, pi.ScanBarcode
  FROM PrescriptionItems pi
  JOIN Medicines m ON pi.MedicineID = m.MedicineID
  WHERE pi.PrescriptionID = ?
");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$stmt2->bind_result($initQty, $dispQty, $medName, $scanStatus);

while ($stmt2->fetch()):
    if ($initQty === $dispQty) {
    $status = '❌ Not Dispensed';
    $color = '#d9534f';
} elseif ($initQty < $dispQty && $initQty > 0) {
    $status = '🟡 Partially Dispensed';
    $color = '#007bff';
} elseif ($initQty === 0) {
    $status = '✅ Dispensed';
    $color = 'green';
} else {
    $status = '❓ Unknown';
    $color = '#888';
}

$remaining = $initQty - $dispQty;

echo "<p style='color:$color;'>".htmlspecialchars($medName)." - $initQty (remaining) of $dispQty pcs - $status</p>";

endwhile;

$stmt2->close();
?>

  <?php if($r['DoctorID'] === null): ?>
    <p><strong>Total: <?= number_format($r['Total'], 2) ?> SAR</strong></p>
  <?php else: ?>
    <p><span class="status free">Free dispensing by the doctor</span></p>
  <?php endif; ?>
</div>

<?php endforeach; else: ?><p>No pending found.</p><?php endif; ?>

  </section>

  <section id="prescription" style="display:none;">
    <div class="form-section">
      <h2>Create Prescription</h2>
      <div class="form-group" style="position:relative;">
        <label>Medicine</label>
        <input type="text" id="medicine" autocomplete="off" oninput="showSuggestions(this.value)">
        <div id="suggestions" style="position:absolute;top:100%;left:0;right:0;z-index:10;background:#fff;border:1px solid #ccc;border-top:none;max-height:150px;overflow-y:auto;"></div>
      </div>
      <div class="form-group">
        <label>Quantity</label>
        <input type="number" id="quantity" value="1" min="1">
      </div>
      <button class="btn" onclick="addMedicine()">Add</button>
      <div id="backlog"></div>
      <button class="btn" onclick="showPaymentModal()">Proceed to Payment</button>
    </div>
  </section>

  <section id="history" style="display:none;">
    <input type="text" id="searchHistory" class="search-bar" placeholder="Search completed..." onkeyup="filterHistory()">
    <?php if(!empty($completed)): foreach($completed as $id=>$c): ?>
    <div class="prescription-card history-item">
      <h3>Prescription #<?= $id ?></h3>
      <p>Date: <?= $c['date'] ?></p>
    <?php foreach($c['items'] as $line): ?><p><?= $line ?></p><?php endforeach; ?>
<?php if (empty($c['DoctorID'])): ?>
  <p><strong>Total: <?= number_format($c['total'], 2) ?> SAR</strong></p>
<?php else: ?>
  <p><span class="status free">Free dispensing by the doctor</span></p>
<?php endif; ?>

    </div>
    <?php endforeach; else: ?><p>No completed found.</p><?php endif; ?>
  </section>
</main>

<div id="paymentModal">
  <div class="modal-content">
    <div class="modal-header">Payment Details</div>
    <div class="modal-body">
      <div style="display:flex;gap:10px;justify-content:center;margin-bottom:15px;">
        <img src="visa.png" alt="Visa" style="height:30px;">
        <img src="applepay.png" alt="Apple Pay" style="height:30px;">
        <img src="mada.png" alt="Mada" style="height:30px;">
      </div>
      <label>Card Number</label><input type="text" id="cardNumber">
      <label>Expiry (MM/YY)</label><input type="text" id="cardExpiry">
      <label>CVV</label><input type="text" id="cardCVV">
      <div class="loader" id="loader"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closePaymentModal()" style="background:#ccc;color:#333;">Cancel</button>
      <button class="btn" id="payBtn">Pay Now</button>
    </div>
  </div>
</div>

<script>
const medsData = <?= json_encode($meds) ?>;
let backlog=[];

function show(sec){
  ['home','prescription','history'].forEach(s=>document.getElementById(s).style.display=s===sec?'block':'none');
  document.querySelectorAll('aside li').forEach(li=>li.classList.remove('active'));
  const map = {'home':'Dashboard','prescription':'Prescription','history':'History'};
  document.querySelectorAll('aside li').forEach(li=>{
    if(li.textContent.toLowerCase().includes(map[sec].toLowerCase())) li.classList.add('active');
  });
}

function showSuggestions(val) {
  const suggBox = document.getElementById('suggestions');
  suggBox.innerHTML = '';
  if (!val.trim()) return;
  const matches = medsData.filter(m => m.name.toLowerCase().startsWith(val.toLowerCase()));
  matches.forEach(m => {
    const item = document.createElement('div');
    item.style.padding = '8px 12px';
    item.style.cursor = m.stock > 0 ? 'pointer' : 'not-allowed';
    item.style.color = m.stock > 0 ? '#333' : 'gray';
    item.innerHTML = m.stock > 0
      ? `${m.id} - ${m.name} - ${m.price} SAR`
      : `<s>${m.id} - ${m.name} - ${m.price} SAR</s> (Out of Stock)`;
    if (m.stock > 0) {
      item.addEventListener('click', () => {
        document.getElementById('medicine').value = `${m.id} - ${m.name} - ${m.price} SAR`;
        suggBox.innerHTML = '';
      });
    }
    suggBox.appendChild(item);
  });
}

document.addEventListener('click', function(e){
  if (!document.getElementById('medicine').contains(e.target) && !document.getElementById('suggestions').contains(e.target)) {
    document.getElementById('suggestions').innerHTML = '';
  }
});

function addMedicine(){
  const mi = document.getElementById('medicine');
  const q = parseInt(document.getElementById('quantity').value);
  const inputVal = mi.value.trim();

  if (!inputVal || q < 1) return;

  const matched = medsData.find(m => {
    const label = `${m.id} - ${m.name} - ${m.price} SAR`;
    return inputVal === label;
  });

  if (!matched) {
    alert('Invalid medicine selected.');
    return;
  }

  if (matched.stock === 0) {
    alert('This medicine is out of stock and cannot be added.');
    return;
  }

  const existing = backlog.find(item => item.id === matched.id);
  if (existing) {
    const newTotalQty = existing.qty + q;
    if (newTotalQty > matched.stock) {
      alert(`Cannot add. Total quantity exceeds available stock (${matched.stock}).`);
      return;
    }
    existing.qty = newTotalQty;
  } else {
    if (q > matched.stock) {
      alert(`Only ${matched.stock} units available in stock.`);
      return;
    }
    backlog.push({
      id: matched.id,
      qty: q,
      medName: matched.name + ' - ' + matched.price + ' SAR'
    });
  }

  renderBacklog();
}
function renderBacklog(){
  const c=document.getElementById('backlog');
  c.innerHTML='';
  backlog.forEach((it,i)=>{
    c.innerHTML+=`<div class="backlog-item">${it.medName} × ${it.qty}<button onclick="removeItem(${i})">Remove</button></div>`;
  });
}
function removeItem(i){backlog.splice(i,1);renderBacklog();}
function filterHome(){const t=document.getElementById('searchHome').value.toLowerCase();document.querySelectorAll('.home-item').forEach(el=>el.style.display=el.textContent.toLowerCase().includes(t)?'block':'none');}
function filterHistory(){const t=document.getElementById('searchHistory').value.toLowerCase();document.querySelectorAll('.history-item').forEach(el=>el.style.display=el.textContent.toLowerCase().includes(t)?'block':'none');}
function showPaymentModal(){if(!backlog.length)return alert('Add one');document.getElementById('paymentModal').style.display='flex';}
function closePaymentModal(){document.getElementById('paymentModal').style.display='none';}

document.getElementById('payBtn').addEventListener('click',()=>{
  document.getElementById('loader').classList.add('active');
  fetch('patient.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`ajax=1&medicines=${encodeURIComponent(backlog.map(it=>it.id).join(','))}&quantities=${encodeURIComponent(backlog.map(it=>it.qty).join(','))}`
  }).then(r=>r.json()).then(d=>{
    if(d.status==='success') {
      alert("Payment successful. OTP has been sent.");
      location.reload();
    }
  });
});
</script>
</body>
</html>
