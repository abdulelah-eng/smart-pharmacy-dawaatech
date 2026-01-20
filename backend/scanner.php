<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'db_connection.php';

$response = 'false';

function cleanCode($code) {
    return strtolower(preg_replace('/[^a-z0-9]/', '', $code));
}

function get_expected_item($conn, $prescriptionID) {
    $sql = "
        SELECT 
            pi.ItemID,
            pi.MedicineID,
            m.code AS code,
            m.MedicineName,
            pi.InitialDispenseQty
        FROM PrescriptionItems pi
        JOIN Medicines m ON pi.MedicineID = m.MedicineID
        WHERE pi.PrescriptionID = ?
          AND pi.ScanBarcode IN ('Not dispensed','partially_dispensed')
          AND pi.InitialDispenseQty > 0
          AND m.StatusOfShelf = 'NoneBlocked'
        ORDER BY pi.ItemID ASC
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $st->bind_param("i", $prescriptionID);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function get_processing_prescription($conn) {
    $stmt = $conn->prepare("
        SELECT PrescriptionID
        FROM Prescriptions
        WHERE Status = 'Processing'
        ORDER BY PrescriptionID ASC
        LIMIT 1
    ");
    $stmt->execute();
    $stmt->bind_result($prescriptionID);
    $id = null;
    if ($stmt->fetch()) { $id = (int)$prescriptionID; }
    $stmt->close();
    return $id;
}

function reply_to_arduino($resp) {
    $arduino_ip = "http://192.168.57.187/" . $resp;
    $ch = curl_init();
    $curl_opt_url = CURLOPT_URL;
    curl_setopt($ch, CURLOPT_URL, $arduino_ip);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $prescriptionID = get_processing_prescription($conn);

    if ($prescriptionID) {

        if (isset($_POST['timeout'])) {
            $expected = get_expected_item($conn, $prescriptionID);

            if ($expected) {
                $block = $conn->prepare("
                    UPDATE Medicines
                    SET StatusOfShelf = 'Blocked'
                    WHERE MedicineID = ?
                    LIMIT 1
                ");
                $block->bind_param("i", $expected['MedicineID']);
                $block->execute();
                $block->close();

                $response = 'false';
            } else {
                $response = 'false';
            }

            reply_to_arduino($response);
            echo $response;
            exit;
        }

        if (isset($_POST['scanned_code'])) {
            $scannedRaw  = $_POST['scanned_code'];
            $hasInput    = strlen(trim($scannedRaw)) > 0;
            $scannedCode = $hasInput ? cleanCode($scannedRaw) : '';

            $expected = get_expected_item($conn, $prescriptionID);

            if ($expected) {
                $expectedItemID  = (int)$expected['ItemID'];
                $expectedMedID   = (int)$expected['MedicineID'];
                $expectedCode    = cleanCode($expected['code'] ?? '');
                $expectedInitQty = (int)$expected['InitialDispenseQty'];

                if ($hasInput && $expectedCode !== '' && $scannedCode === $expectedCode) {
                    if ($expectedInitQty > 1) {
                        $upd = $conn->prepare("
                            UPDATE PrescriptionItems
                            SET InitialDispenseQty = InitialDispenseQty - 1,
                                ScanBarcode = 'partially_dispensed'
                            WHERE ItemID = ?
                        ");
                    } else {
                        $upd = $conn->prepare("
                            UPDATE PrescriptionItems
                            SET InitialDispenseQty = 0,
                                ScanBarcode = 'dispensed'
                            WHERE ItemID = ?
                        ");
                    }
                    $upd->bind_param("i", $expectedItemID);
                    $upd->execute();
                    $upd->close();

                    $response = 'true';
                } else {
                    if ($hasInput) {
                        $block = $conn->prepare("
                            UPDATE Medicines
                            SET StatusOfShelf = 'Blocked'
                            WHERE MedicineID = ?
                            LIMIT 1
                        ");
                        $block->bind_param("i", $expectedMedID);
                        $block->execute();
                        $block->close();
                    }
                    $response = 'false';
                }
            } else {
                $response = 'false';
            }

            reply_to_arduino($response);
            echo $response;
            exit;
        }
    } else {
        reply_to_arduino('false');
        echo 'false';
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Barcode Scanner</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; display:flex; justify-content:center; align-items:center; height:100vh; }
        form { background:#fff; padding:30px; border-radius:10px; box-shadow:0 0 15px rgba(0,0,0,0.1); text-align:center; }
        input[type="text"] { font-size:20px; padding:10px; width:300px; text-align:center; }
        label { display:block; margin-bottom:10px; font-size:18px; }
    </style>
    <script>
    window.addEventListener('load', function () {
        const input = document.querySelector('input[name="scanned_code"]');
        let timeoutId = null;
        const TIMEOUT_MS = 20000;

        function scheduleTimeout() {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                const fd = new FormData();
                fd.append('timeout', '1');
                fetch('', { method: 'POST', body: fd })
                  .then(r => r.text())
                  .then(res => {
                      console.log('Auto-timeout response:', res);
                      scheduleTimeout();
                  })
                  .catch(() => {
                      scheduleTimeout();
                  });
            }, TIMEOUT_MS);
        }

        scheduleTimeout();

        input.addEventListener('input', () => scheduleTimeout());

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                const code = this.value.trim();
                const fd = new FormData();
                fd.append('scanned_code', code);
                clearTimeout(timeoutId);

                fetch('', { method: 'POST', body: fd })
                  .then(r => r.text())
                  .then(result => {
                      console.log('Server response:', result);
                      this.value = '';
                      this.focus();
                      scheduleTimeout();
                  })
                  .catch(() => {
                      this.value = '';
                      this.focus();
                      scheduleTimeout();
                  });
            }
        });

        input.focus();
    });
    </script>
</head>
<body>
    <form onsubmit="return false;">
        <label>Scan Medicine Barcode:</label>
        <input type="text" name="scanned_code" autofocus required>
    </form>
</body>
</html>
