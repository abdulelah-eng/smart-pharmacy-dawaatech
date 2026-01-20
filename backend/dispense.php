<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';
require 'db_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$senderEmail = 'DawaaTech@gmail.com';
$senderPassword = 'vjby qmot okak wqzs';

$message = '';
$success = false;
$dispensed = [];
$notDispensed = [];
$partiallyDispensed = [];
$previouslyDispensed = [];
$unavailable = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $patientID = $_POST['patient_id'];
    $inputOtp = $_POST['otp'];

    $stmt = $conn->prepare("
        SELECT p.PrescriptionID, pt.Email, p.OTP, pt.FirstName
        FROM Prescriptions p 
        JOIN Patients pt ON p.PatientID = pt.PatientID 
        WHERE p.PatientID = ? 
          AND p.Status IN ('pending', 'partially_dispensed') 
          AND p.OTP = ?
        LIMIT 1
    ");
    $stmt->bind_param("is", $patientID, $inputOtp);
    $stmt->execute();
    $stmt->bind_result($prescription_id, $email, $storedOtp, $patientName);

    if ($stmt->fetch()) {
        $stmt->close();

        $processingUpdate = $conn->prepare("UPDATE Prescriptions SET Status = 'Processing' WHERE PrescriptionID = ?");
        $processingUpdate->bind_param("i", $prescription_id);
        $processingUpdate->execute();
        $processingUpdate->close();

        $queryBefore = "
            SELECT m.MedicineName, pi.InitialDispenseQty, pi.DispensedQuantity, pi.ScanBarcode
            FROM PrescriptionItems pi 
            JOIN Medicines m ON pi.MedicineID = m.MedicineID 
            WHERE pi.PrescriptionID = ?
        ";
        $stmtBefore = $conn->prepare($queryBefore);
        $stmtBefore->bind_param("i", $prescription_id);
        $stmtBefore->execute();
        $resultBefore = $stmtBefore->get_result();

        while ($row = $resultBefore->fetch_assoc()) {
            if ($row['ScanBarcode'] === 'dispensed') {
                $previouslyDispensed[] = [
                    'name'  => $row['MedicineName'],
                    'qty'   => $row['InitialDispenseQty'],
                    'total' => $row['DispensedQuantity']
                ];
            }
        }
        $stmtBefore->close();

        $lines = [];

        $qUnavailable = "
            SELECT m.MedicineName, pi.InitialDispenseQty, pi.DispensedQuantity
            FROM PrescriptionItems pi
            JOIN Medicines m ON pi.MedicineID = m.MedicineID
            WHERE pi.PrescriptionID = ?
              AND pi.ScanBarcode != 'dispensed'
              AND pi.InitialDispenseQty > 0
              AND m.StatusOfShelf = 'Blocked'
            ORDER BY pi.ItemID ASC
        ";
        $stUn = $conn->prepare($qUnavailable);
        $stUn->bind_param("i", $prescription_id);
        $stUn->execute();
        $rsUn = $stUn->get_result();
        while ($r = $rsUn->fetch_assoc()) {
            $unavailable[] = [
                'name'  => $r['MedicineName'],
                'qty'   => (int)$r['InitialDispenseQty'],
                'total' => (int)$r['DispensedQuantity']
            ];
        }
        $stUn->close();

        $queryToSend = "
            SELECT m.MedicineName, pi.DispensedQuantity, pi.InitialDispenseQty
            FROM PrescriptionItems pi 
            JOIN Medicines m ON pi.MedicineID = m.MedicineID 
            WHERE pi.PrescriptionID = ?
              AND pi.ScanBarcode != 'dispensed'
              AND pi.InitialDispenseQty > 0
              AND m.StatusOfShelf = 'NoneBlocked'
            ORDER BY pi.ItemID ASC
        ";
        $stmtSend = $conn->prepare($queryToSend);
        $stmtSend->bind_param("i", $prescription_id);
        $stmtSend->execute();
        $resSend = $stmtSend->get_result();
        while ($row = $resSend->fetch_assoc()) {
            $lines[] = $row['MedicineName'] . '|' . (int)$row['InitialDispenseQty'];
        }
        $stmtSend->close();

        $arduino_ip = "http://192.168.57.187";
        $response = "done";

        if (!empty($lines)) {
            $payload = implode("\n", $lines);

            $ch = curl_init($arduino_ip);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                $message = "❌ cURL Error: " . curl_error($ch);
                $success = false;

                $stmt = $conn->prepare("UPDATE Prescriptions SET Status = 'pending' WHERE PrescriptionID = ?");
                $stmt->bind_param("i", $prescription_id);
                $stmt->execute();
                $stmt->close();

                curl_close($ch);
            } else {
                curl_close($ch);
            }
        }

        if (trim($response) === "done") {
            $fixDispensed = $conn->prepare("
                UPDATE PrescriptionItems 
                SET ScanBarcode = 'dispensed' 
                WHERE PrescriptionID = ? AND InitialDispenseQty = 0
            ");
            $fixDispensed->bind_param("i", $prescription_id);
            $fixDispensed->execute();
            $fixDispensed->close();

            $prevNames = array_column($previouslyDispensed, 'name');

            $queryFull = "
                SELECT pi.ItemID, m.MedicineName, pi.DispensedQuantity, pi.InitialDispenseQty, pi.ScanBarcode
                FROM PrescriptionItems pi
                JOIN Medicines m ON pi.MedicineID = m.MedicineID
                WHERE pi.PrescriptionID = ?
            ";
            $stmt2 = $conn->prepare($queryFull);
            $stmt2->bind_param("i", $prescription_id);
            $stmt2->execute();
            $result = $stmt2->get_result();

            while ($row = $result->fetch_assoc()) {
                $med = [
                    'name'  => $row['MedicineName'],
                    'qty'   => (int)$row['InitialDispenseQty'],
                    'total' => (int)$row['DispensedQuantity']
                ];

                if ($row['InitialDispenseQty'] == 0) {
                    if (!in_array($row['MedicineName'], $prevNames)) {
                        $dispensed[] = $med;
                    }
                } elseif ($row['InitialDispenseQty'] < $row['DispensedQuantity']) {
                    $partiallyDispensed[] = $med;
                } else {
                    $notDispensed[] = $med;
                }
            }
            $stmt2->close();

            $hasPartial      = count($partiallyDispensed) > 0;
            $hasNotDispensed = count($notDispensed) > 0;
            $hasUnavailable  = count($unavailable) > 0;

            if (!$hasPartial && !$hasNotDispensed && !$hasUnavailable) {
                $newStatus = 'completed';
                $message = "✅ OTP is correct. Prescription fully dispensed.";
            } else {
                $newStatus = 'partially_dispensed';
                $message = "⚠️ OTP is correct. Prescription dispensed partially.";
            }

            $updateStatus = $conn->prepare("UPDATE Prescriptions SET Status = ? WHERE PrescriptionID = ?");
            $updateStatus->bind_param("si", $newStatus, $prescription_id);
            $updateStatus->execute();
            $updateStatus->close();

            $success = true;

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $senderEmail;
                $mail->Password = $senderPassword;
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom($senderEmail, 'DawaaTech');
                $mail->addAddress($email, $patientName);
                $mail->AddEmbeddedImage('logo.png', 'logoimg');
                $mail->isHTML(true);
                $mail->Subject = 'Medication Dispensed Status';

                $body = "<div style='font-family:Arial,sans-serif'>";
                $body .= "<h2 style='color:#1b2656'>Hello $patientName,</h2>";
                $body .= "<p>Your prescription has been processed.</p>";

                if (!empty($dispensed)) {
                    $body .= "<h3 style='color:#1b2656'>✔ Dispensed Items:</h3><ul>";
                    foreach ($dispensed as $med) {
                        $body .= "<li>" . htmlspecialchars($med['name']) . " – " . $med['qty'] . " (remaining) of " . $med['total'] . " pcs</li>";
                    }
                    $body .= "</ul>";
                }

                if (!empty($previouslyDispensed)) {
                    $body .= "<h3 style='color:#1b2656'>↺ Previously Dispensed:</h3><ul>";
                    foreach ($previouslyDispensed as $med) {
                        $body .= "<li>" . htmlspecialchars($med['name']) . " – " . $med['qty'] . " (remaining) of " . $med['total'] . " pcs</li>";
                    }
                    $body .= "</ul>";
                }

                if (!empty($partiallyDispensed)) {
                    $body .= "<h3 style='color:#d39e00'>⚠️ Partially Dispensed:</h3><ul>";
                    foreach ($partiallyDispensed as $med) {
                        $body .= "<li>" . htmlspecialchars($med['name']) . " – " . $med['qty'] . " (remaining) of " . $med['total'] . " pcs</li>";
                    }
                    $body .= "</ul>";
                }

                if (!empty($unavailable)) {
                    $body .= "<h3 style='color:#b02a37'>🚫 Unavailable:</h3><ul>";
                    foreach ($unavailable as $med) {
                        $body .= "<li>" . htmlspecialchars($med['name']) . " – " . $med['qty'] . " (requested) of " . $med['total'] . " pcs</li>";
                    }
                    $body .= "</ul>";
                }

                if (!empty($notDispensed)) {
                    $body .= "<h3 style='color:#b02a37'>✖ Not Dispensed:</h3><ul>";
                    foreach ($notDispensed as $med) {
                        $body .= "<li>" . htmlspecialchars($med['name']) . " – " . $med['qty'] . " (remaining) of " . $med['total'] . " pcs</li>";
                    }
                    $body .= "</ul>";
                }

                $body .= "<p style='margin-top:20px'>Thank you for using <strong>DawaaTech</strong>.</p>";
                $body .= "<img src='cid:logoimg' alt='DawaaTech Logo' style='width:100px;margin-top:15px'>";
                $body .= "</div>";

                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("❌ Email sending failed: " . $mail->ErrorInfo);
            }

        } else {
            $stmt = $conn->prepare("UPDATE Prescriptions SET Status = 'pending' WHERE PrescriptionID = ?");
            $stmt->bind_param("i", $prescription_id);
            $stmt->execute();
            $stmt->close();
            $message = "⚠️ Arduino did not confirm dispensing. Response: " . htmlspecialchars($response);
            $success = false;
        }

    } else {
        $message = "❌ OTP is incorrect or prescription not found.";
        $success = false;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>OTP Verification</title>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Tajawal', sans-serif; }
    body {
      background: linear-gradient(135deg, #f5f7fa, #e4e8f0);
      display: flex; justify-content: center; align-items: center; height: 100vh;
    }
    .card {
      background: white; padding: 30px; border-radius: 20px; width: 420px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.1); position: relative; text-align: center;
    }
    .card img { transform: scale(2); width: 100px; margin-bottom: 15px; }
    h2 { margin-bottom: 20px; color: #1b2656; }
    input[type="text"] {
      width: 100%; padding: 12px; margin: 12px 0;
      border-radius: 10px; border: 1px solid #ccc; font-size: 16px;
    }
    .otp-boxes {
      display: flex; justify-content: space-between; margin-top: 12px; margin-bottom: 16px;
    }
    .otp-boxes input {
      width: 48px; height: 50px; text-align: center; font-size: 20px;
      border: 1px solid #ccc; border-radius: 8px; background: rgba(0,0,0,0.03);
    }
    button {
      width: 100%; padding: 14px;
      background: linear-gradient(135deg, #1b2656, #2f3d6a); color: white;
      border: none; border-radius: 10px; font-size: 16px; cursor: pointer;
      transition: 0.3s ease;
    }
    button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 15px rgba(27, 38, 86, 0.3);
    }
    .success { color: green; margin: 15px 0; }
    .error { color: red; margin: 15px 0; }
    .checkmark { font-size: 40px; color: green; }
    ul { text-align: left; margin-top: 10px; }
  </style>
</head>
<body>
<div class="card">
  <img src="logo.png" alt="DawaaTech Logo">
  <h2>Enter National ID</h2>

  <?php if ($message): ?>
    <p class="<?= $success ? 'success' : 'error' ?>"><?= $message ?></p>
  <?php endif; ?>

  <?php if (!$success): ?>
    <form method="POST" id="otpForm">
      <input type="text" name="patient_id" placeholder="National ID" pattern="\d{5}" required>
      <label style="text-align:left;display:block;margin-top:10px;font-weight:500;color:#1b2656">OTP Code</label>
      <div class="otp-boxes">
        <input type="text" maxlength="1" oninput="moveToNext(this, 1)">
        <input type="text" maxlength="1" oninput="moveToNext(this, 2)">
        <input type="text" maxlength="1" oninput="moveToNext(this, 3)">
        <input type="text" maxlength="1" oninput="moveToNext(this, 4)">
        <input type="text" maxlength="1" oninput="moveToNext(this, 5)">
        <input type="text" maxlength="1" oninput="moveToNext(this, 6)">
      </div>
      <input type="hidden" name="otp" id="hiddenOtp">
      <button type="submit" name="verify_otp">Verify</button>
    </form>
  <?php else: ?>
    <div class="checkmark">✔</div>
    <p class="success">Medication processed.</p>

    <?php if (!empty($dispensed)): ?>
      <h3 style="margin-top:15px; color:#1b2656;">✔ Dispensed Items</h3>
      <ul>
        <?php foreach ($dispensed as $med): ?>
          <li><?= htmlspecialchars($med['name']) ?> – <?= $med['qty'] ?> (remaining) of <?= $med['total'] ?> pcs</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($previouslyDispensed)): ?>
      <h3 style="margin-top:15px; color:#1b2656;">↺ Previously Dispensed</h3>
      <ul>
        <?php foreach ($previouslyDispensed as $med): ?>
          <li><?= htmlspecialchars($med['name']) ?> – <?= $med['qty'] ?> (remaining) of <?= $med['total'] ?> pcs</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($partiallyDispensed)): ?>
      <h3 style="margin-top:15px; color:#d39e00;">⚠️ Partially Dispensed</h3>
      <ul>
        <?php foreach ($partiallyDispensed as $med): ?>
          <li><?= htmlspecialchars($med['name']) ?> – <?= $med['qty'] ?> (remaining) of <?= $med['total'] ?> pcs</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($unavailable)): ?>
      <h3 style="margin-top:15px; color:#b02a37;">🚫 Unavailable</h3>
      <ul>
        <?php foreach ($unavailable as $med): ?>
          <li><?= htmlspecialchars($med['name']) ?> – <?= $med['qty'] ?> (requested) of <?= $med['total'] ?> pcs</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($notDispensed)): ?>
      <h3 style="margin-top:15px; color:#b02a37;">✖ Not Dispensed</h3>
      <ul>
        <?php foreach ($notDispensed as $med): ?>
          <li><?= htmlspecialchars($med['name']) ?> – <?= $med['qty'] ?> (remaining) of <?= $med['total'] ?> pcs</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <button onclick="window.location.href=window.location.pathname" style="margin-top: 20px;">Back</button>
  <?php endif; ?>
</div>

<script>
function moveToNext(current, index) {
    const inputs = document.querySelectorAll('.otp-boxes input');
    if (current.value.length === 1 && index < inputs.length) {
        inputs[index].focus();
    }
    let otpValue = '';
    inputs.forEach(i => otpValue += i.value);
    document.getElementById('hiddenOtp').value = otpValue;
}

document.getElementById('otpForm')?.addEventListener('submit', function(e) {
    setTimeout(function() {
        document.querySelector('.card').innerHTML = `
            <h2>Please wait...</h2>
            <p style="font-size:18px; margin-top:15px;">⏳ Dispensing your medicine. This may take up to 40 seconds...</p>
        `;
    }, 100);
});
</script>
</body>
</html>
