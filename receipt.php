<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user']['id'];

// Helpers
function getServicePrice($service) {
    $servicePrices = array(
        'Aircon Cleaning' => 1200,
        'Air Filter Replacement' => 800,
        'Brake Service' => 1500,
        'Check Engine' => 1000,
        'Check Wiring' => 900,
        'Oil Change' => 1100,
        'PMS' => 2000,
        'Wheel Alignment' => 1300,
    );
    return isset($servicePrices[$service]) ? $servicePrices[$service] : 0;
}

// Create receipts table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    user_id INT NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    address TEXT NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    tin VARCHAR(50) NULL,
    notes TEXT NULL,
    amount DECIMAL(10,2) NOT NULL,
    mechanic_name VARCHAR(150) NULL,
    work_date DATE NULL,
    work_start_time TIME NULL,
    work_end_time TIME NULL,
    parts_json LONGTEXT NULL,
    receipt_number VARCHAR(40) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Ensure columns exist and receipt_number index (for older DBs)
$__cols = [
    'mechanic_name' => "ALTER TABLE receipts ADD COLUMN mechanic_name VARCHAR(150) NULL",
    'work_date' => "ALTER TABLE receipts ADD COLUMN work_date DATE NULL",
    'work_start_time' => "ALTER TABLE receipts ADD COLUMN work_start_time TIME NULL",
    'work_end_time' => "ALTER TABLE receipts ADD COLUMN work_end_time TIME NULL",
    'parts_json' => "ALTER TABLE receipts ADD COLUMN parts_json LONGTEXT NULL",
    'receipt_number' => "ALTER TABLE receipts ADD COLUMN receipt_number VARCHAR(40) NULL"
];
foreach ($__cols as $c => $sql) {
    $chk = $conn->query("SHOW COLUMNS FROM receipts LIKE '".$conn->real_escape_string($c)."'");
    if ($chk && $chk->num_rows === 0) { $conn->query($sql); }
}
$idx = $conn->query("SHOW INDEX FROM receipts WHERE Key_name='idx_receipt_number'");
if ($idx && $idx->num_rows === 0) {
    @$conn->query("ALTER TABLE receipts ADD UNIQUE KEY idx_receipt_number (receipt_number)");
}

// Load appointment
$appointment = null;
if (isset($_GET['appointment_id']) && is_numeric($_GET['appointment_id'])) {
    $aid = (int)$_GET['appointment_id'];
    $stmt = $conn->prepare('SELECT * FROM appointments WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $aid, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $appointment = $res->fetch_assoc();
    $stmt->close();
}

if (!$appointment) {
    header('Location: myappointments.php');
    exit();
}

// Check if a receipt already exists
$existingReceipt = null;
$chk = $conn->prepare('SELECT * FROM receipts WHERE appointment_id = ? AND user_id = ? LIMIT 1');
$chk->bind_param('ii', $appointment['id'], $userId);
$chk->execute();
$existingReceipt = $chk->get_result()->fetch_assoc();
$chk->close();

$servicePrice = getServicePrice($appointment['service']);

$errors = [];
$success = false;
$receiptId = $existingReceipt['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $tin = trim($_POST['tin'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($customer_name === '') { $errors[] = 'Name is required'; }
    if ($address === '') { $errors[] = 'Address is required'; }

    if (empty($errors)) {
        if (!$existingReceipt) {
            // Create new receipt with default service price
            $ins = $conn->prepare('INSERT INTO receipts (appointment_id, user_id, customer_name, address, email, phone, tin, notes, amount) VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->bind_param('iissssssd', $appointment['id'], $userId, $customer_name, $address, $email, $phone, $tin, $notes, $servicePrice);
            $success = $ins->execute();
            $receiptId = $ins->insert_id;
            $ins->close();

            if ($success) {
                // Generate unique receipt number
                $rn = 'RS-' . date('Ymd') . '-' . str_pad((string)$appointment['id'], 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(uniqid((string)$appointment['id'], true)), 0, 6));
                $ur = $conn->prepare('UPDATE receipts SET receipt_number=? WHERE id=?');
                $ur->bind_param('si', $rn, $receiptId);
                $ur->execute();
                $ur->close();
                // Link to appointment if column exists/ensure exists
                if ($conn->query("SHOW COLUMNS FROM appointments LIKE 'receipt_id'")->num_rows === 0) {
                    $conn->query("ALTER TABLE appointments ADD COLUMN receipt_id INT NULL");
                }
                $upda = $conn->prepare('UPDATE appointments SET receipt_id = ? WHERE id = ? AND user_id = ?');
                $upda->bind_param('iii', $receiptId, $appointment['id'], $userId);
                $upda->execute();
                $upda->close();
            }
        } else {
            // Update existing receipt fields (do not change amount/parts/mechanic from here)
            $receiptId = (int)$existingReceipt['id'];
            $upd = $conn->prepare('UPDATE receipts SET customer_name=?, address=?, email=?, phone=?, tin=?, notes=? WHERE id=? AND user_id=?');
            $upd->bind_param('ssssssii', $customer_name, $address, $email, $phone, $tin, $notes, $receiptId, $userId);
            $success = $upd->execute();
            $upd->close();
            // Refresh existingReceipt after update
            if ($success) {
                $re = $conn->prepare('SELECT * FROM receipts WHERE id = ? AND user_id = ?');
                $re->bind_param('ii', $receiptId, $userId);
                $re->execute();
                $existingReceipt = $re->get_result()->fetch_assoc();
                $re->close();
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt | Vehicle Service</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    body { background: url('33.png') no-repeat center center fixed; background-size: cover; }
    body::before { content:''; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:-1; }
    .container { max-width: 900px; margin: 30px auto; padding: 0 20px; color:#fff; }
    .card { background: rgba(0,0,0,0.8); border:2px solid var(--primary); border-radius: 16px; padding: 24px; }
    .title { color: var(--primary); font-size: 1.8rem; margin: 0 0 10px; }
    .subtitle { color:#ddd; margin:0 0 20px; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    .grid-1 { display:grid; grid-template-columns: 1fr; gap:16px; }
    label { display:block; margin-bottom:8px; color:#eee; }
    input, textarea { width:100%; padding:12px 14px; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:8px; color:#fff; box-sizing:border-box; }
    textarea { min-height:100px; }
    .row { display:flex; gap:16px; }
    .btn { display:inline-flex; align-items:center; gap:8px; padding:12px 18px; border-radius:24px; text-decoration:none; cursor:pointer; font-weight:600; border:none; }
    .btn-primary { background: var(--primary); color: var(--dark); }
    .btn-outline { background: transparent; color:#fff; border:1px solid rgba(255,255,255,0.3); }
    .btn:hover { transform: translateY(-1px); }
    .notice { background: rgba(23,162,184,.1); border-left:4px solid var(--info); padding:14px; border-radius:10px; margin:16px 0; }
    .receipt { background: rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2); border-radius:12px; padding:16px; margin-top:16px; }
    .kv { display:flex; justify-content:space-between; margin:6px 0; }
    .print-btn { background: linear-gradient(135deg, #28a745, #1e7e34); color:#fff; }
    /* Printable styled receipt */
    .receipt-doc { background:#fff; color:#222; border:1px solid #ddd; border-radius:10px; padding:24px; max-width:900px; margin:24px auto; box-shadow:0 4px 20px rgba(0,0,0,.08); }
    .receipt-head { display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #f0c040; padding-bottom:12px; margin-bottom:16px; }
    .brand { font-weight:800; font-size:22px; color:#111; }
    .brand small { display:block; color:#666; font-weight:500; font-size:12px; }
    .r-meta { text-align:right; }
    .r-meta div { font-size:13px; color:#444; }
    .badge-gold { background:#f0c040; color:#111; border-radius:20px; padding:4px 10px; font-weight:700; }
    .kvp { display:flex; gap:12px; font-size:13px; color:#333; }
    .kvp > div { flex:1; }
    .items { width:100%; border-collapse:collapse; margin-top:10px; font-size:14px; }
    .items th, .items td { border:1px solid #e8e8e8; padding:8px 10px; }
    .items thead th { background:#fafafa; font-weight:700; }
    .totals { margin-top:8px; width:100%; }
    .totals td { padding:6px 8px; }
    .totals .label { text-align:right; color:#666; }
    .totals .value { text-align:right; font-weight:700; }
    @media print { .no-print { display:none !important; } .receipt-doc{ box-shadow:none; border:1px solid #000; margin:0; page-break-inside:avoid; } body{ background:#fff; } }
</style>
</head>
<body>
<div class="container">
    <a href="myappointments.php" class="btn btn-outline no-print" style="margin-bottom:16px;"><i class="fas fa-arrow-left"></i> Back to Appointments</a>

    <div class="card">
        <h2 class="title"><i class="fas fa-receipt"></i> Receipt Details</h2>
    <p class="subtitle">Fill in the information below to generate your receipt for <strong><?= htmlspecialchars($appointment['service']) ?></strong></p>

        <?php if (!empty($errors)): ?>
            <div class="notice" style="border-left-color: var(--danger); background: rgba(220,53,69,.1);">
                <strong><i class="fas fa-exclamation-triangle"></i> Please fix the following:</strong>
                <ul>
                    <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

    <?php if (!$existingReceipt): ?>
    <form method="POST" class="no-print">
            <div class="grid">
                <div>
                    <label>Full Name *</label>
                    <input type="text" name="customer_name" value="<?= htmlspecialchars($existingReceipt['customer_name'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($existingReceipt['email'] ?? '') ?>">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($existingReceipt['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="grid-1" style="margin-top:16px;">
                <div>
                    <label>Address *</label>
                    <textarea name="address" required><?= htmlspecialchars($existingReceipt['address'] ?? '') ?></textarea>
                </div>
                <div>
                    <label>Notes</label>
                    <textarea name="notes"><?= htmlspecialchars($existingReceipt['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="row" style="margin-top:18px; align-items:center; justify-content:flex-end; gap:12px;">
                <div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Receipt</button>
                    <?php if ($success || $existingReceipt): ?>
                        <a href="#" onclick="window.print(); return false;" class="btn print-btn no-print"><i class="fas fa-print"></i> Print</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php else: ?>
            <div class="row no-print" style="margin-top:12px; justify-content:flex-end;">
                <a href="#" onclick="window.print(); return false;" class="btn print-btn"><i class="fas fa-print"></i> Print</a>
            </div>
        <?php endif; ?>

        <?php if ($success || $existingReceipt): ?>
            <?php
                $r = $existingReceipt;
                if ($success) {
                    if (!$receiptId && $existingReceipt) { $receiptId = (int)$existingReceipt['id']; }
                    if ($receiptId) {
                        $qr = $conn->prepare('SELECT * FROM receipts WHERE id = ? AND user_id = ?');
                        $qr->bind_param('ii', $receiptId, $userId);
                        $qr->execute();
                        $r = $qr->get_result()->fetch_assoc();
                        $qr->close();
                        // Keep in sync for form fields
                        $existingReceipt = $r;
                    }
                }
            ?>
            <div class="receipt-doc">
                <div class="receipt-head">
                    <div class="brand">
                        Vehicle Service Center
                        <small>Quality Maintenance and Repairs</small>
                    </div>
                    <div class="r-meta">
                        <div><span class="badge-gold">Receipt</span></div>
                        <div>No: <strong><?= htmlspecialchars($r['receipt_number'] ?? ('#'.$r['id'])) ?></strong></div>
                        <div>Date: <strong><?= date('M d, Y g:i A', strtotime($r['created_at'])) ?></strong></div>
                        <div style="margin-top:4px; color:#666;">Appointment #: <strong><?= (int)$appointment['id'] ?></strong></div>
                    </div>
                </div>
                <div class="kvp">
                    <div>
                        <div><strong>Customer</strong>: <?= htmlspecialchars($r['customer_name']) ?></div>
                        <div><strong>Address</strong>: <?= htmlspecialchars($r['address']) ?></div>
                        <?php $showTin = isset($r['tin']) && trim($r['tin']) !== '' && strtolower(trim($r['tin'])) !== 'n/a'; ?>
                        <?php if ($showTin): ?><div><strong>TIN</strong>: <?= htmlspecialchars($r['tin']) ?></div><?php endif; ?>
                    </div>
                    <div>
                        <div><strong>Vehicle</strong>: <?= htmlspecialchars($appointment['car']) ?></div>
                        <div><strong>Service</strong>: <?= htmlspecialchars($appointment['service']) ?></div>
                    </div>
                    <div>
                        <?php if (!empty($r['mechanic_name'])): ?><div><strong>Mechanic</strong>: <?= htmlspecialchars($r['mechanic_name']) ?></div><?php endif; ?>
                        <?php if (!empty($r['work_date'])): ?><div><strong>Work Date</strong>: <?= htmlspecialchars($r['work_date']) ?></div><?php endif; ?>
                        <?php if (!empty($r['work_start_time']) || !empty($r['work_end_time'])): ?><div><strong>Time</strong>: <?= htmlspecialchars($r['work_start_time'] ?? '') ?><?= (!empty($r['work_start_time']) && !empty($r['work_end_time'])) ? ' - ' : '' ?><?= htmlspecialchars($r['work_end_time'] ?? '') ?></div><?php endif; ?>
                    </div>
                </div>
                <?php 
                    $parts = [];
                    if (!empty($r['parts_json'])) { $tmp = json_decode($r['parts_json'], true); if (is_array($tmp)) $parts = $tmp; }
                    $partsSum = 0.0; foreach ($parts as $_p){ $q = isset($_p['qty'])?(float)$_p['qty']:1; $u = isset($_p['unit_price'])?(float)$_p['unit_price']:(float)($_p['price']??0); $partsSum += $q*$u; }
                    $serviceLine = max(0.0, (float)($r['amount'] ?? $servicePrice) - $partsSum);
                ?>
                <table class="items">
                    <thead>
                        <tr>
                            <th style="width:55%">Item</th>
                            <th style="width:10%">Qty</th>
                            <th style="width:15%">Unit (₱)</th>
                            <th style="width:20%">Line Total (₱)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Service - <?= htmlspecialchars($appointment['service']) ?></td>
                            <td>1</td>
                            <td><?= number_format($serviceLine,2) ?></td>
                            <td><?= number_format($serviceLine,2) ?></td>
                        </tr>
                        <?php foreach ($parts as $pi): 
                            $qty = isset($pi['qty']) ? (float)$pi['qty'] : 1; 
                            $unit = isset($pi['unit_price']) ? (float)$pi['unit_price'] : (float)($pi['price'] ?? 0); 
                            $line = isset($pi['line_total']) ? (float)$pi['line_total'] : $qty*$unit; ?>
                        <tr>
                            <td><?= htmlspecialchars($pi['name']) ?></td>
                            <td><?= rtrim(rtrim(number_format($qty,2,'.',''), '0'), '.') ?></td>
                            <td><?= number_format($unit,2) ?></td>
                            <td><?= number_format($line,2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php $computedSubtotal = $serviceLine + $partsSum; ?>
                <table class="totals">
                    <tr>
                        <td class="label">Subtotal:</td>
                        <td class="value" style="width:180px">₱<?= number_format($computedSubtotal,2) ?></td>
                    </tr>
                    <?php if (abs(((float)($r['amount'] ?? $servicePrice)) - $computedSubtotal) > 0.009): ?>
                    <tr>
                        <td class="label">Adjusted Total:</td>
                        <td class="value">₱<?= number_format((float)($r['amount'] ?? $servicePrice),2) ?></td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td class="label">Total:</td>
                        <td class="value">₱<?= number_format((float)($r['amount'] ?? $servicePrice),2) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php if (!empty($r['notes'])): ?><div style="margin-top:8px; color:#555;"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($r['notes'])) ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>