<?php
session_start();

/*
|--------------------------------------------------------------------------
| PHARMACY MEDICINE INVENTORY SYSTEM  —  Supabase (Postgres) Edition
|--------------------------------------------------------------------------
| Features:
| - Medicine inventory stored in a Supabase (Postgres) database
| - Add / Edit / Delete
| - Individual low-stock threshold
| - 30-day expiration warning
| - Automatically zero expired medicine stock
| - Expiration reports
| - Dispensing / Stock-Out
| - Monthly dispensing report
| - Excel-compatible inventory export
| - Excel-compatible dispensing export
| - DARK PURPLE THEME
|--------------------------------------------------------------------------
*/

/* ============================================================
   CONFIGURATION
   DB credentials and basic settings live in config.php
============================================================ */

require_once __DIR__ . '/config.php';

$message = "";
$messageType = "";
$dbError = "";


/* ============================================================
   DATABASE CONNECTION (PDO, singleton)
   Uses the pgsql PDO driver to connect to Supabase's Postgres
   database instead of MySQL.
============================================================ */

function db()
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=require";

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    return $pdo;
}


/* ============================================================
   SCHEMA BOOTSTRAP
   Creates the tables (if missing) and seeds starter data
   (if the medicines table is empty) so the app works the
   moment the database itself exists.

   NOTE: You can also run supabase_schema.sql once in the
   Supabase SQL editor instead of relying on this. Either
   path is safe — this function only creates tables that
   don't already exist and only seeds empty tables.
============================================================ */

function ensureSchema()
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS medicines (
            sku VARCHAR(20) PRIMARY KEY,
            inventory_name VARCHAR(255) NOT NULL,
            strength VARCHAR(50) NOT NULL DEFAULT '',
            unit VARCHAR(20) NOT NULL DEFAULT 'mg',
            dosage_form VARCHAR(50) NOT NULL DEFAULT '',
            generic_name VARCHAR(255) NOT NULL DEFAULT '',
            quantity INT NOT NULL DEFAULT 0,
            batch_number VARCHAR(100) NOT NULL DEFAULT '',
            expiration_date DATE NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'General',
            low_stock_threshold INT NOT NULL DEFAULT 200,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispense_logs (
            id SERIAL PRIMARY KEY,
            dispense_date DATE NOT NULL,
            inventory_name VARCHAR(255) NOT NULL,
            batch_number VARCHAR(100) NOT NULL DEFAULT '',
            qty_out INT NOT NULL DEFAULT 0,
            recipient VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $userCount = $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];

    if (intval($userCount) === 0) {

        $pdo->prepare("
            INSERT INTO users (username, password_hash)
            VALUES (?, ?)
        ")->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }

    $count = $pdo->query("SELECT COUNT(*) AS c FROM medicines")->fetch()['c'];

    if (intval($count) === 0) {

        $seedMedicines = [
            ['M001', 'Paracetamol', '500', 'mg', 'Tablet', 'Paracetamol (Acetaminophen)', 150, 'BCH-2026-01A', '2028-05-15', 'Analgesics', 200],
            ['M002', 'Amoxicillin', '500', 'mg', 'Capsule', 'Amoxicillin Trihydrate', 12, 'BCH-2025-09C', '2027-11-20', 'Antibiotics', 20],
            ['M003', 'Cetirizine', '10', 'mg', 'Tablet', 'Cetirizine Dihydrochloride', 8, 'BCH-2026-03X', '2028-01-10', 'Antihistamines', 15]
        ];

        $stmt = $pdo->prepare("
            INSERT INTO medicines
                (sku, inventory_name, strength, unit, dosage_form, generic_name, quantity, batch_number, expiration_date, category, low_stock_threshold)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($seedMedicines as $row) {
            $stmt->execute($row);
        }

        $pdo->prepare("
            INSERT INTO dispense_logs (dispense_date, inventory_name, batch_number, qty_out, recipient)
            VALUES (?, ?, ?, ?, ?)
        ")->execute(['2026-05-20', 'Paracetamol 500 mg Tablet', 'BCH-2026-01A', 10, 'John Doe']);
    }
}


/* ============================================================
   HELPER FUNCTIONS
============================================================ */

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


/* ============================================================
   AUTHENTICATION HELPERS
   (unchanged — still your own users table + password_hash /
   password_verify, no Supabase Auth involved)
============================================================ */

function findUserByUsername($username)
{
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function isLoggedIn()
{
    return !empty($_SESSION['user_id']);
}

function currentUsername()
{
    return $_SESSION['username'] ?? '';
}

function renderLoginPage($loginError = '')
{
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login &mdash; Pharmacy Inventory System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body {
    font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
    background: linear-gradient(160deg, #2a1a4a 0%, #4c1d95 55%, #7c3aed 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.login-card {
    width: 100%;
    max-width: 400px;
    background: #ffffff;
    border-radius: 1rem;
    padding: 2.5rem;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}
.login-icon {
    width: 60px;
    height: 60px;
    border-radius: 1rem;
    background: linear-gradient(135deg, #7c3aed, #a78bfa);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 26px;
    margin: 0 auto 1rem;
}
.btn-purple {
    background: #7c3aed;
    border: none;
    color: #fff;
}
.btn-purple:hover {
    background: #6d28d9;
    color: #fff;
}
.form-control:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 .2rem rgba(124, 58, 237, .15);
}
</style>
</head>
<body>

<div class="login-card">
<div class="login-icon"><i class="fa-solid fa-pills"></i></div>
<h4 class="text-center fw-bold mb-1">Pharmacy Inventory</h4>
<p class="text-center text-muted mb-4">Authorized access only</p>

<?php if (!empty($loginError)): ?>
<div class="alert alert-danger py-2 small"><?php echo h($loginError); ?></div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="action" value="login">

<div class="mb-3">
<label class="form-label">Username</label>
<input type="text" name="username" class="form-control" required autofocus>
</div>

<div class="mb-3">
<label class="form-label">Password</label>
<input type="password" name="password" class="form-control" required>
</div>

<button type="submit" class="btn btn-purple w-100 mt-2">
<i class="fa-solid fa-right-to-bracket me-1"></i>Log In
</button>

</form>
</div>

</body>
</html>
<?php
}

function medicineFullName($med)
{
    return trim(
        ($med['inventory_name'] ?? '') . ' ' .
        ($med['strength'] ?? '') . ' ' .
        ($med['unit'] ?? '') . ' ' .
        ($med['dosage_form'] ?? '')
    );
}

function getThreshold($med)
{
    $threshold = intval($med['low_stock_threshold'] ?? 200);

    if ($threshold < 1) {
        $threshold = 200;
    }

    return $threshold;
}

function generateMedicineKey()
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM medicines WHERE sku = ?");

    do {
        $key = 'M' . rand(1000, 9999);
        $stmt->execute([$key]);
        $exists = intval($stmt->fetch()['c']) > 0;
    } while ($exists);

    return $key;
}


/* ============================================================
   DATA ACCESS — MEDICINES
============================================================ */

function fetchAllMedicines()
{
    $rows = db()->query("SELECT * FROM medicines ORDER BY inventory_name ASC")->fetchAll();

    $result = [];

    foreach ($rows as $row) {
        $row['quantity'] = intval($row['quantity']);
        $row['low_stock_threshold'] = intval($row['low_stock_threshold']);
        $result[$row['sku']] = $row;
    }

    return $result;
}

function fetchMedicine($sku)
{
    $stmt = db()->prepare("SELECT * FROM medicines WHERE sku = ?");
    $stmt->execute([$sku]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function insertMedicine($sku, $data)
{
    $stmt = db()->prepare("
        INSERT INTO medicines
            (sku, inventory_name, strength, unit, dosage_form, generic_name, quantity, batch_number, expiration_date, category, low_stock_threshold)
        VALUES
            (:sku, :inventory_name, :strength, :unit, :dosage_form, :generic_name, :quantity, :batch_number, :expiration_date, :category, :low_stock_threshold)
    ");

    $stmt->execute([
        'sku' => $sku,
        'inventory_name' => $data['inventory_name'],
        'strength' => $data['strength'],
        'unit' => $data['unit'],
        'dosage_form' => $data['dosage_form'],
        'generic_name' => $data['generic_name'],
        'quantity' => $data['quantity'],
        'batch_number' => $data['batch_number'],
        'expiration_date' => $data['expiration_date'] ?: null,
        'category' => $data['category'],
        'low_stock_threshold' => $data['low_stock_threshold']
    ]);
}

function updateMedicine($sku, $data)
{
    $stmt = db()->prepare("
        UPDATE medicines SET
            inventory_name = :inventory_name,
            strength = :strength,
            unit = :unit,
            dosage_form = :dosage_form,
            generic_name = :generic_name,
            quantity = :quantity,
            batch_number = :batch_number,
            expiration_date = :expiration_date,
            category = :category,
            low_stock_threshold = :low_stock_threshold
        WHERE sku = :sku
    ");

    $stmt->execute([
        'sku' => $sku,
        'inventory_name' => $data['inventory_name'],
        'strength' => $data['strength'],
        'unit' => $data['unit'],
        'dosage_form' => $data['dosage_form'],
        'generic_name' => $data['generic_name'],
        'quantity' => $data['quantity'],
        'batch_number' => $data['batch_number'],
        'expiration_date' => $data['expiration_date'] ?: null,
        'category' => $data['category'],
        'low_stock_threshold' => $data['low_stock_threshold']
    ]);

    return $stmt->rowCount() > 0;
}

function deleteMedicineDb($sku)
{
    $stmt = db()->prepare("DELETE FROM medicines WHERE sku = ?");
    $stmt->execute([$sku]);

    return $stmt->rowCount() > 0;
}

function decrementMedicineStock($sku, $qtyOut)
{
    $stmt = db()->prepare("UPDATE medicines SET quantity = quantity - ? WHERE sku = ?");
    $stmt->execute([$qtyOut, $sku]);
}

function processExpiredMedicinesDb()
{
    db()->exec("
        UPDATE medicines
        SET quantity = 0
        WHERE expiration_date IS NOT NULL
          AND expiration_date <= CURRENT_DATE
          AND quantity > 0
    ");
}


/* ============================================================
   DATA ACCESS — DISPENSE LOGS
============================================================ */

function fetchAllDispenseLogs()
{
    $rows = db()->query("SELECT * FROM dispense_logs ORDER BY dispense_date DESC, id DESC")->fetchAll();

    $result = [];

    foreach ($rows as $row) {
        $result[] = [
            'date' => date('d M Y', strtotime($row['dispense_date'])),
            'date_iso' => $row['dispense_date'],
            'inventory_name' => $row['inventory_name'],
            'batch_number' => $row['batch_number'],
            'qty_out' => intval($row['qty_out']),
            'recipient' => $row['recipient']
        ];
    }

    return $result;
}

function insertDispenseLog($dateIso, $inventoryName, $batchNumber, $qtyOut, $recipient)
{
    $stmt = db()->prepare("
        INSERT INTO dispense_logs (dispense_date, inventory_name, batch_number, qty_out, recipient)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([$dateIso, $inventoryName, $batchNumber, $qtyOut, $recipient]);
}


/* ============================================================
   SAVE INVENTORY EXCEL
============================================================ */

function saveInventoryExcel($medicineInventory)
{
    $file = __DIR__ . '/inventory_report.xls';

    $html = '
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; }
        th { background:#6d28d9; color:white; font-weight:bold; border:1px solid #999; padding:8px; }
        td { border:1px solid #999; padding:6px; }
    </style>
    </head>
    <body>

    <h2>Medicine Inventory Report</h2>
    <p>Generated: ' . date('d M Y H:i:s') . '</p>

    <table>
    <tr>
        <th>SKU</th><th>Inventory Name</th><th>Strength</th><th>Unit</th><th>Dosage Form</th>
        <th>Generic Name</th><th>Category</th><th>Batch Number</th><th>Expiration Date</th>
        <th>Quantity</th><th>Low Stock Threshold</th><th>Status</th>
    </tr>';

    foreach ($medicineInventory as $med) {

        $qty = intval($med['quantity'] ?? 0);
        $threshold = getThreshold($med);
        $expiration = $med['expiration_date'] ?? '';

        $status = 'Available';

        if (!empty($expiration)) {

            $exp = strtotime($expiration);
            $today = strtotime(date('Y-m-d'));
            $warningDate = strtotime('+30 days', $today);

            if ($exp <= $today) {
                $status = 'EXPIRED';
            } elseif ($exp <= $warningDate) {
                $status = 'EXPIRING SOON';
            } elseif ($qty <= $threshold) {
                $status = 'LOW STOCK';
            }

        } elseif ($qty <= $threshold) {

            $status = 'LOW STOCK';
        }

        $html .= '<tr>';

        $fields = [
            $med['sku'] ?? '', $med['inventory_name'] ?? '', $med['strength'] ?? '', $med['unit'] ?? '',
            $med['dosage_form'] ?? '', $med['generic_name'] ?? '', $med['category'] ?? '',
            $med['batch_number'] ?? '', $med['expiration_date'] ?? '', $qty, $threshold, $status
        ];

        foreach ($fields as $field) {
            $html .= '<td>' . h($field) . '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</table></body></html>';

    file_put_contents($file, $html);
}


/* ============================================================
   SAVE DISPENSE EXCEL
============================================================ */

function saveDispenseExcel($dispenseLogs)
{
    $file = __DIR__ . '/dispensing_report.xls';

    $html = '
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; }
        th { background:#4c1d95; color:white; font-weight:bold; border:1px solid #999; padding:8px; }
        td { border:1px solid #999; padding:6px; }
    </style>
    </head>
    <body>

    <h2>Medicine Dispensing / Stock-Out Report</h2>
    <p>Generated: ' . date('d M Y H:i:s') . '</p>

    <table>
    <tr><th>Date</th><th>Medicine</th><th>Batch Number</th><th>Quantity Dispensed</th><th>Recipient</th></tr>';

    foreach ($dispenseLogs as $log) {

        $html .= '<tr>';

        $fields = [
            $log['date'] ?? '', $log['inventory_name'] ?? '', $log['batch_number'] ?? '',
            $log['qty_out'] ?? 0, $log['recipient'] ?? ''
        ];

        foreach ($fields as $field) {
            $html .= '<td>' . h($field) . '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</table></body></html>';

    file_put_contents($file, $html);
}


/* ============================================================
   DOWNLOAD INVENTORY EXCEL
============================================================ */

function downloadInventoryExcel($medicineInventory)
{
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=medicine_inventory.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse:collapse; }
        th { background:#6d28d9; color:#fff; border:1px solid #999; padding:8px; }
        td { border:1px solid #999; padding:6px; }
    </style>
    </head>
    <body>

    <h2>Medicine Inventory</h2>

    <table>
    <tr>
        <th>SKU</th><th>Medicine</th><th>Strength</th><th>Unit</th><th>Dosage Form</th>
        <th>Generic Name</th><th>Category</th><th>Batch</th><th>Expiration</th>
        <th>Quantity</th><th>Low Stock Threshold</th><th>Status</th>
    </tr>';

    foreach ($medicineInventory as $med) {

        $qty = intval($med['quantity'] ?? 0);
        $threshold = getThreshold($med);

        $today = strtotime(date('Y-m-d'));
        $warningDate = strtotime('+30 days', $today);

        $exp = !empty($med['expiration_date']) ? strtotime($med['expiration_date']) : false;

        $status = 'Available';

        if ($exp !== false && $exp <= $today) {
            $status = 'EXPIRED';
        } elseif ($exp !== false && $exp <= $warningDate) {
            $status = 'EXPIRING SOON';
        } elseif ($qty <= $threshold) {
            $status = 'LOW STOCK';
        }

        echo '<tr>';
        echo '<td>' . h($med['sku'] ?? '') . '</td>';
        echo '<td>' . h($med['inventory_name'] ?? '') . '</td>';
        echo '<td>' . h($med['strength'] ?? '') . '</td>';
        echo '<td>' . h($med['unit'] ?? '') . '</td>';
        echo '<td>' . h($med['dosage_form'] ?? '') . '</td>';
        echo '<td>' . h($med['generic_name'] ?? '') . '</td>';
        echo '<td>' . h($med['category'] ?? '') . '</td>';
        echo '<td>' . h($med['batch_number'] ?? '') . '</td>';
        echo '<td>' . h($med['expiration_date'] ?? '') . '</td>';
        echo '<td>' . $qty . '</td>';
        echo '<td>' . $threshold . '</td>';
        echo '<td>' . h($status) . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
}


/* ============================================================
   DOWNLOAD DISPENSING EXCEL
============================================================ */

function downloadDispenseExcel($month, $year, $dispenseLogs)
{
    header("Content-Type: application/vnd.ms-excel");

    $filename = "medicine_dispensing_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

    header("Content-Disposition: attachment; filename=$filename");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse:collapse; }
        th { background:#4c1d95; color:white; border:1px solid #999; padding:8px; }
        td { border:1px solid #999; padding:6px; }
    </style>
    </head>
    <body>

    <h2>Medicine Dispensing Report</h2>
    <h3>' . date('F Y', strtotime("$year-$month-01")) . '</h3>

    <table>
    <tr><th>Date</th><th>Medicine</th><th>Batch Number</th><th>Quantity Dispensed</th><th>Recipient</th></tr>';

    foreach ($dispenseLogs as $log) {

        $isoDate = $log['date_iso'] ?? '';

        if (!empty($isoDate)) {

            $timestamp = strtotime($isoDate);

            if ($timestamp !== false) {

                if (
                    intval(date('m', $timestamp)) != intval($month) ||
                    intval(date('Y', $timestamp)) != intval($year)
                ) {
                    continue;
                }
            }
        }

        echo '<tr>';
        echo '<td>' . h($log['date'] ?? '') . '</td>';
        echo '<td>' . h($log['inventory_name'] ?? '') . '</td>';
        echo '<td>' . h($log['batch_number'] ?? '') . '</td>';
        echo '<td>' . intval($log['qty_out'] ?? 0) . '</td>';
        echo '<td>' . h($log['recipient'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
}


/* ============================================================
   CONNECT + BOOTSTRAP SCHEMA
============================================================ */

try {

    ensureSchema();

    /* --------------------------------------------------------
       LOGOUT
    -------------------------------------------------------- */

    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    /* --------------------------------------------------------
       LOGIN
    -------------------------------------------------------- */

    $loginError = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {

        $usernameInput = trim($_POST['username'] ?? '');
        $passwordInput = $_POST['password'] ?? '';
        $user = findUserByUsername($usernameInput);

        if ($user && password_verify($passwordInput, $user['password_hash'])) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];

            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;

        } else {

            $loginError = "Invalid username or password.";
        }
    }

    /* --------------------------------------------------------
       AUTH GATE — everything below requires a logged-in user
    -------------------------------------------------------- */

    if (!isLoggedIn()) {
        renderLoginPage($loginError);
        exit;
    }

    processExpiredMedicinesDb();

    $medicineInventory = fetchAllMedicines();
    $dispenseLogs = fetchAllDispenseLogs();

} catch (PDOException $e) {

    $dbError = $e->getMessage();
    $medicineInventory = [];
    $dispenseLogs = [];
}


/* ============================================================
   EXPORT ACTIONS
============================================================ */

if (empty($dbError)) {

    if (isset($_GET['export']) && $_GET['export'] === 'inventory') {
        downloadInventoryExcel($medicineInventory);
    }

    if (isset($_GET['export']) && $_GET['export'] === 'dispensing') {
        $month = intval($_GET['month'] ?? date('m'));
        $year = intval($_GET['year'] ?? date('Y'));
        downloadDispenseExcel($month, $year, $dispenseLogs);
    }
}


/* ============================================================
   POST ACTIONS
============================================================ */

$tabByAction = [
    'add_medicine' => 'products',
    'edit_medicine' => 'products',
    'delete_medicine' => 'products',
    'stock_out' => 'stockout'
];

$activeTab = $tabByAction[$_POST['action'] ?? ''] ?? 'dashboard';

if (empty($dbError) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        /* ====================================================
           ADD MEDICINE
        ==================================================== */

        if ($action === 'add_medicine') {

            $inventoryName = trim($_POST['inventory_name'] ?? '');
            $strength = trim($_POST['strength'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $dosageForm = trim($_POST['dosage_form'] ?? '');
            $genericName = trim($_POST['generic_name'] ?? '');
            $quantity = max(0, intval($_POST['quantity'] ?? 0));
            $batchNumber = trim($_POST['batch_number'] ?? '');
            $expirationDate = $_POST['expiration_date'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $lowStockThreshold = max(1, intval($_POST['low_stock_threshold'] ?? $DEFAULT_LOW_STOCK));

            if ($inventoryName === '') {

                $message = "Medicine name is required.";
                $messageType = "danger";

            } else {

                $autoSku = generateMedicineKey();

                insertMedicine($autoSku, [
                    'inventory_name' => $inventoryName,
                    'strength' => $strength,
                    'unit' => $unit,
                    'dosage_form' => $dosageForm,
                    'generic_name' => $genericName,
                    'quantity' => $quantity,
                    'batch_number' => $batchNumber,
                    'expiration_date' => $expirationDate,
                    'category' => $category,
                    'low_stock_threshold' => $lowStockThreshold
                ]);

                $message = "Medicine successfully added to inventory.";
                $messageType = "success";
            }
        }


        /* ====================================================
           EDIT MEDICINE
        ==================================================== */

        elseif ($action === 'edit_medicine') {

            $key = $_POST['medicine_key'] ?? '';
            $existing = fetchMedicine($key);

            if ($existing) {

                $updated = updateMedicine($key, [
                    'inventory_name' => trim($_POST['inventory_name'] ?? ''),
                    'strength' => trim($_POST['strength'] ?? ''),
                    'unit' => trim($_POST['unit'] ?? ''),
                    'dosage_form' => trim($_POST['dosage_form'] ?? ''),
                    'generic_name' => trim($_POST['generic_name'] ?? ''),
                    'quantity' => max(0, intval($_POST['quantity'] ?? 0)),
                    'batch_number' => trim($_POST['batch_number'] ?? ''),
                    'expiration_date' => $_POST['expiration_date'] ?? '',
                    'category' => trim($_POST['category'] ?? ''),
                    'low_stock_threshold' => max(1, intval($_POST['low_stock_threshold'] ?? $DEFAULT_LOW_STOCK))
                ]);

                $message = "Medicine information successfully updated.";
                $messageType = "success";

            } else {

                $message = "Medicine could not be found.";
                $messageType = "danger";
            }
        }


        /* ====================================================
           DELETE MEDICINE
        ==================================================== */

        elseif ($action === 'delete_medicine') {

            $key = $_POST['medicine_key'] ?? '';
            $existing = fetchMedicine($key);

            if ($existing) {

                $deletedName = $existing['inventory_name'] ?? 'Medicine';
                deleteMedicineDb($key);

                $message = "$deletedName was removed from inventory.";
                $messageType = "success";

            } else {

                $message = "Medicine could not be found.";
                $messageType = "danger";
            }
        }


        /* ====================================================
           STOCK OUT / DISPENSE
        ==================================================== */

        elseif ($action === 'stock_out') {

            $key = $_POST['medicine_key'] ?? '';
            $qtyOut = intval($_POST['qty_out'] ?? 0);
            $recipient = trim($_POST['recipient'] ?? '');

            processExpiredMedicinesDb();

            $medItem = fetchMedicine($key);

            if (!$medItem) {

                $message = "Please select a valid medicine.";
                $messageType = "danger";

            } elseif ($qtyOut < 1) {

                $message = "Quantity must be at least 1.";
                $messageType = "danger";

            } else {

                $expiration = $medItem['expiration_date'] ?? '';
                $today = strtotime(date('Y-m-d'));
                $isExpired = false;

                if (!empty($expiration)) {

                    $expirationTimestamp = strtotime($expiration);

                    if ($expirationTimestamp !== false && $expirationTimestamp <= $today) {
                        $isExpired = true;
                    }
                }

                if ($isExpired) {

                    $message = "This medicine has already expired and cannot be dispensed.";
                    $messageType = "danger";

                } elseif (intval($medItem['quantity']) < $qtyOut) {

                    $message = "Error: Stock is not enough for this medicine.";
                    $messageType = "danger";

                } else {

                    decrementMedicineStock($key, $qtyOut);

                    $medItem = fetchMedicine($key);
                    $fullName = medicineFullName($medItem);
                    $dateIso = date('Y-m-d');

                    insertDispenseLog($dateIso, $fullName, $medItem['batch_number'] ?? '', $qtyOut, $recipient);

                    $message = "Successfully dispensed " . $qtyOut . " unit(s) of " . $fullName . ".";
                    $messageType = "success";
                }
            }
        }

        /* Refresh in-memory data after any mutation */

        processExpiredMedicinesDb();
        $medicineInventory = fetchAllMedicines();
        $dispenseLogs = fetchAllDispenseLogs();

    } catch (PDOException $e) {

        $dbError = $e->getMessage();
    }
}


/* ============================================================
   SNAPSHOT EXCEL FILES + DASHBOARD METRICS
============================================================ */

if (empty($dbError)) {

    saveInventoryExcel($medicineInventory);
    saveDispenseExcel($dispenseLogs);
}


$totalProducts = count($medicineInventory);
$totalStockInHand = 0;
$lowStockCount = 0;
$expiringSoonCount = 0;
$expiredCount = 0;
$categoryCounts = [];

$todayTimestamp = strtotime(date('Y-m-d'));
$expiryThreshold = strtotime("+{$EXPIRY_WARNING_DAYS} days", $todayTimestamp);

$expiredMedicines = [];
$expiringMedicines = [];
$lowStockMedicines = [];

foreach ($medicineInventory as $key => $med) {

    $quantity = intval($med['quantity'] ?? 0);
    $totalStockInHand += $quantity;

    $threshold = getThreshold($med);

    $expiration = $med['expiration_date'] ?? '';
    $expTimestamp = !empty($expiration) ? strtotime($expiration) : false;

    if ($expTimestamp !== false && $expTimestamp <= $todayTimestamp) {

        $expiredCount++;
        $expiredMedicines[$key] = $med;

    } elseif ($expTimestamp !== false && $expTimestamp <= $expiryThreshold) {

        $expiringSoonCount++;
        $expiringMedicines[$key] = $med;
    }

    if ($quantity <= $threshold && !($expTimestamp !== false && $expTimestamp <= $todayTimestamp)) {
        $lowStockCount++;
        $lowStockMedicines[$key] = $med;
    }

    $category = trim($med['category'] ?? '');

    if ($category === '') {
        $category = 'General';
    }

    if (!isset($categoryCounts[$category])) {
        $categoryCounts[$category] = 0;
    }

    $categoryCounts[$category] += $quantity;
}


/* ============================================================
   MONTHLY DISPENSING REPORT
============================================================ */

$selectedMonth = intval($_GET['report_month'] ?? date('m'));
$selectedYear = intval($_GET['report_year'] ?? date('Y'));

$monthlyDispense = [];
$monthlyTotalOut = 0;

foreach ($dispenseLogs as $log) {

    $isoDate = $log['date_iso'] ?? '';

    if (empty($isoDate)) {
        continue;
    }

    $timestamp = strtotime($isoDate);

    if ($timestamp === false) {
        continue;
    }

    if (intval(date('m', $timestamp)) == $selectedMonth && intval(date('Y', $timestamp)) == $selectedYear) {
        $monthlyDispense[] = $log;
        $monthlyTotalOut += intval($log['qty_out'] ?? 0);
    }
}


/* ============================================================
   MONTHLY MEDICINE SUMMARY
============================================================ */

$monthlyMedicineTotals = [];

foreach ($monthlyDispense as $log) {

    $name = $log['inventory_name'] ?? 'Unknown';

    if (!isset($monthlyMedicineTotals[$name])) {
        $monthlyMedicineTotals[$name] = 0;
    }

    $monthlyMedicineTotals[$name] += intval($log['qty_out'] ?? 0);
}

arsort($monthlyMedicineTotals);


/* ============================================================
   6-MONTH DISPENSING TREND (for line chart)
============================================================ */

$trendLabels = [];
$trendData = [];

for ($i = 5; $i >= 0; $i--) {

    $ts = strtotime("-{$i} months", $todayTimestamp);
    $m = intval(date('m', $ts));
    $y = intval(date('Y', $ts));

    $trendLabels[] = date('M Y', $ts);

    $sum = 0;

    foreach ($dispenseLogs as $log) {

        $isoDate = $log['date_iso'] ?? '';

        if (empty($isoDate)) {
            continue;
        }

        $t = strtotime($isoDate);

        if ($t === false) {
            continue;
        }

        if (intval(date('m', $t)) == $m && intval(date('Y', $t)) == $y) {
            $sum += intval($log['qty_out'] ?? 0);
        }
    }

    $trendData[] = $sum;
}


/* ============================================================
   STOCK STATUS BREAKDOWN (for donut chart)
============================================================ */

$availableCount = max(0, $totalProducts - $lowStockCount - $expiringSoonCount - $expiredCount);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Pharmacy Inventory Management</title>


<!-- ==========================================================
     BOOTSTRAP
=========================================================== -->

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
rel="stylesheet">


<!-- ==========================================================
     FONT AWESOME
=========================================================== -->

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


<!-- ==========================================================
     GOOGLE FONT
=========================================================== -->

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">


<!-- ==========================================================
     CHOICES.JS (searchable "type or select" dropdowns)
=========================================================== -->

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@10.2.0/public/assets/styles/choices.min.css">
<style>
/* Make Choices.js match the existing purple theme / Bootstrap form-select look */
.choices { margin-bottom: 0; }
.choices__inner {
    background-color: #fff;
    border: 1px solid #dcd6ec;
    border-radius: .5rem;
    min-height: calc(3.0rem);
    padding: .5rem .75rem;
    font-size: 1rem;
}
.choices__input {
    background-color: transparent;
}
.choices__list--dropdown, .choices__list[aria-expanded] {
    border-color: #dcd6ec;
    border-radius: .5rem;
    z-index: 2000;
}
.choices__list--dropdown .choices__item--selectable.is-highlighted,
.choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
    background-color: #7c3aed;
    color: #fff;
}
.choices__list--dropdown .choices__item.is-disabled {
    opacity: .55;
}
.is-focused .choices__inner {
    border-color: #7c3aed;
    box-shadow: 0 0 0 .2rem rgba(124, 58, 237, .15);
}
</style>


<!-- ==========================================================
     CHART.JS
=========================================================== -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>


<!-- ==========================================================
     DASHBOARD-STYLE PURPLE THEME
=========================================================== -->

<style>

:root {

    --purple-950: #150a2b;
    --purple-900: #1f1040;
    --purple-800: #2b1656;
    --purple-700: #3f1e83;
    --purple-600: #5b21b6;
    --purple-500: #7c3aed;
    --purple-400: #9061f9;
    --purple-300: #b18ffb;
    --purple-200: #d9c8fb;
    --purple-100: #f0eafd;

    --pink-500: #ec4899;

    --background: #f5f3fb;
    --card: #ffffff;
    --border: #eae4f7;

    --text: #241a3d;
    --muted: #8b81a3;

    --success: #16a34a;
    --warning: #d97706;
    --danger: #e11d48;

    --radius-lg: 16px;
    --radius-md: 12px;
}


* {
    font-family: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
}


body {
    background: var(--background);
    color: var(--text);
}


/* ============================================================
   SIDEBAR
============================================================ */

.sidebar {

    background:
        linear-gradient(
            190deg,
            var(--purple-950) 0%,
            var(--purple-900) 45%,
            var(--purple-800) 100%
        );

    min-height: 100vh;
    color: var(--purple-200);
    padding: 20px 16px !important;
}


.sidebar-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 8px 22px 8px;
}


.sidebar-logo .logo-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: linear-gradient(135deg, var(--purple-400), var(--pink-500));
    box-shadow: 0 6px 16px rgba(124, 58, 237, .45);
    color: #fff;
}


.sidebar-logo .logo-text {
    line-height: 1.15;
}


.sidebar-logo .logo-text strong {
    display: block;
    color: #ffffff;
    font-weight: 800;
    font-size: 14px;
    letter-spacing: .3px;
}


.sidebar-logo .logo-text small {
    color: var(--purple-300);
    font-size: 10.5px;
    letter-spacing: .5px;
}


.sidebar .nav-link {
    color: var(--purple-200);
    padding: 11px 16px;
    border-radius: 10px;
    margin-bottom: 4px;
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all .2s ease;
    border: 1px solid transparent;
}


.sidebar .nav-link i {
    width: 18px;
    text-align: center;
    color: var(--purple-300);
}


.sidebar .nav-link:hover {
    background: rgba(255, 255, 255, .06);
    color: #ffffff;
}


.sidebar .nav-link.active {
    background: linear-gradient(135deg, var(--purple-500), var(--purple-600));
    color: #ffffff;
    box-shadow: 0 6px 16px rgba(124, 58, 237, .40);
}


.sidebar .nav-link.active i {
    color: #ffffff;
}


/* QUICK INSIGHTS PANEL */

.sidebar-insights {
    background: rgba(255, 255, 255, .05);
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: var(--radius-md);
    padding: 16px 14px;
    margin-top: 14px;
}


.sidebar-insights h6 {
    color: #fff;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    margin-bottom: 10px;
}


.sidebar-insights ul {
    list-style: none;
    padding: 0;
    margin: 0;
}


.sidebar-insights li {
    font-size: 12.5px;
    color: var(--purple-200);
    padding: 5px 0;
    display: flex;
    gap: 8px;
    line-height: 1.35;
}


.sidebar-insights li i {
    color: var(--purple-300);
    margin-top: 2px;
    font-size: 11px;
}


.sidebar-footer {
    color: var(--purple-300);
    font-size: 11px;
    padding: 14px 6px 4px;
    border-top: 1px solid rgba(255, 255, 255, .08);
    margin-top: 10px;
}


/* ============================================================
   MAIN AREA
============================================================ */

.col-md-10 {
    background: var(--background) !important;
    min-height: 100vh;
}


/* ============================================================
   TOP NAVBAR
============================================================ */

.top-navbar {
    background: #ffffff;
    border-bottom: 1px solid var(--border);
}


.top-navbar h4 {
    color: var(--purple-950);
    font-weight: 800;
    letter-spacing: -.3px;
}


.top-navbar small {
    color: var(--muted);
}


/* ============================================================
   CARDS
============================================================ */

.card-custom {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 2px rgba(76, 29, 149, .04);
    transition: all .2s ease;
}


.card-custom:hover {
    box-shadow: 0 8px 24px rgba(76, 29, 149, .10);
}


.card-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}


.card-title-row h5, .card-title-row h6 {
    margin: 0;
    font-weight: 700;
}


/* ============================================================
   KPI CARDS (icon-badge style)
============================================================ */

.kpi-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 20px;
}


.kpi-icon {
    width: 46px;
    height: 46px;
    min-width: 46px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    color: #ffffff;
    background: linear-gradient(135deg, var(--purple-400), var(--purple-600));
    box-shadow: 0 6px 14px rgba(124, 58, 237, .30);
}


.kpi-icon.warn { background: linear-gradient(135deg, #fbbf24, #d97706); box-shadow: 0 6px 14px rgba(217, 119, 6, .30); }
.kpi-icon.danger { background: linear-gradient(135deg, #fb7185, #e11d48); box-shadow: 0 6px 14px rgba(225, 29, 72, .30); }
.kpi-icon.dark { background: linear-gradient(135deg, var(--purple-800), var(--purple-950)); box-shadow: 0 6px 14px rgba(21, 10, 43, .30); }


.kpi-body small {
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
}


.kpi-number {
    font-size: 26px;
    font-weight: 800;
    color: var(--purple-950);
    line-height: 1.25;
    margin-top: 2px;
}


.kpi-trend {
    font-size: 11.5px;
    font-weight: 600;
    margin-top: 4px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 20px;
}


.kpi-trend.good { color: var(--success); background: #ecfdf3; }
.kpi-trend.bad { color: var(--danger); background: #fef1f3; }
.kpi-trend.neutral { color: var(--purple-600); background: var(--purple-100); }


/* ============================================================
   HEADINGS
============================================================ */

h4, h5, h6 { color: var(--purple-950); }


/* ============================================================
   TABLES
============================================================ */

.table-custom { --bs-table-bg: transparent; }

.table-custom th {
    background: var(--purple-100);
    color: #5b4b75;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: .3px;
    font-weight: 700;
    white-space: nowrap;
    border-bottom: 2px solid #e3d9fb;
}

.table-custom td { vertical-align: middle; border-color: #eee9f7; font-size: 13.5px; }
.table-custom tbody tr:hover { background: #faf8ff; }


/* ============================================================
   BUTTONS
============================================================ */

.btn-primary {
    background: linear-gradient(135deg, var(--purple-500), var(--purple-600));
    border-color: var(--purple-600);
    color: #fff;
    box-shadow: 0 3px 8px rgba(109, 40, 217, .20);
}

.btn-primary:hover, .btn-primary:focus {
    background: linear-gradient(135deg, var(--purple-600), var(--purple-700));
    border-color: var(--purple-700);
    color: #fff;
}

.btn-outline-primary { color: var(--purple-600); border-color: var(--purple-400); }
.btn-outline-primary:hover { background: var(--purple-600); border-color: var(--purple-600); color: #fff; }

.btn-success { background: var(--success); border-color: var(--success); }
.btn-success:hover { background: #128a3e; border-color: #128a3e; }

.btn-danger { background: var(--danger); border-color: var(--danger); }
.btn-danger:hover { background: #be123c; border-color: #be123c; }

.btn-secondary { background: #6b7280; border-color: #6b7280; }


/* ============================================================
   FORM INPUTS
============================================================ */

.form-control, .form-select {
    border: 1px solid #ddd2f7;
    border-radius: 9px;
    color: #302746;
    background: #ffffff;
}

.form-control:hover, .form-select:hover { border-color: #c0a8f5; }

.form-control:focus, .form-select:focus {
    border-color: var(--purple-400);
    box-shadow: 0 0 0 .20rem rgba(124, 58, 237, .16);
}

.form-label { color: #514365; font-weight: 600; font-size: 13.5px; }


/* ============================================================
   TEXT / BADGES
============================================================ */

.text-primary { color: var(--purple-600) !important; }
.text-dark { color: var(--text) !important; }

.badge.bg-primary { background: var(--purple-600) !important; }
.badge.bg-warning { background: #f59e0b !important; }
.badge.bg-danger { background: var(--danger) !important; }
.badge.bg-success { background: var(--success) !important; }


/* ============================================================
   ROW STATES
============================================================ */

.expired-row { background: #fff1f2 !important; }
.expiring-row { background: #fff7ed !important; }
.low-row { background: #fffbeb !important; }


/* ============================================================
   ALERTS
============================================================ */

.alert { border-radius: 12px; }
.alert-danger { color: #881337; background: #fff1f2; border-color: #fecdd3; }
.alert-success { color: #166534; background: #f0fdf4; border-color: #bbf7d0; }


/* ============================================================
   PROGRESS BAR
============================================================ */

.progress { background: var(--purple-100); border-radius: 20px; height: 20px; }
.progress-bar {
    background: linear-gradient(90deg, var(--purple-500), var(--purple-300)) !important;
    border-radius: 20px;
}


/* ============================================================
   MODAL
============================================================ */

.modal-content { border: 1px solid #e3d9fb; border-radius: var(--radius-lg); box-shadow: 0 15px 40px rgba(46, 16, 101, .20); }
.modal-header { background: linear-gradient(135deg, var(--purple-900), var(--purple-600)); color: #fff; border-bottom: none; }
.modal-header .modal-title { color: #fff; }
.modal-header .btn-close { filter: invert(1); }
.modal-footer { border-top: 1px solid #eee9f7; }


/* LOW STOCK ALERT LIST (image-style rows) */

.alert-list-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 4px;
    border-bottom: 1px solid var(--border);
}

.alert-list-item:last-child { border-bottom: none; }

.alert-list-item .med-name { font-weight: 700; font-size: 13.5px; }
.alert-list-item .med-cat { font-size: 11.5px; color: var(--muted); }

.reorder-pill {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    background: #fef1f3;
    color: var(--danger);
}


hr { border-color: var(--border); opacity: 1; }
a { color: var(--purple-600); }
a:hover { color: var(--purple-700); }


::-webkit-scrollbar { width: 9px; height: 9px; }
::-webkit-scrollbar-track { background: #f1edf8; }
::-webkit-scrollbar-thumb { background: var(--purple-300); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: var(--purple-500); }


@media (max-width: 768px) {
    .sidebar { min-height: auto; }
    .kpi-number { font-size: 21px; }
    .top-navbar { padding: 15px !important; }
    .top-navbar h4 { font-size: 17px; }
}

</style>

</head>


<body>

<div class="container-fluid p-0">

<div class="row g-0">


<!-- ==========================================================
     SIDEBAR
=========================================================== -->

<div class="col-md-2 sidebar d-flex flex-column justify-content-between">

<div>

<div class="sidebar-logo">
    <div class="logo-icon"><i class="fa-solid fa-pills"></i></div>
    <div class="logo-text">
        <strong>PHARMACY</strong>
        <small>INVENTORY CONTROL</small>
    </div>
</div>


<ul class="nav nav-pills flex-column" id="sidebarNav">

<li class="nav-item">
<button class="nav-link <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?> w-100 text-start" data-bs-toggle="pill" data-bs-target="#pane-dashboard">
<i class="fa-solid fa-gauge-high me-2"></i>Dashboard
</button>
</li>

<li class="nav-item">
<button class="nav-link <?php echo $activeTab === 'products' ? 'active' : ''; ?> w-100 text-start" data-bs-toggle="pill" data-bs-target="#pane-products">
<i class="fa-solid fa-boxes-stacked me-2"></i>Medicine Inventory
</button>
</li>

<li class="nav-item">
<button class="nav-link <?php echo $activeTab === 'stockout' ? 'active' : ''; ?> w-100 text-start" data-bs-toggle="pill" data-bs-target="#pane-stockout">
<i class="fa-solid fa-truck-ramp-box me-2"></i>Dispense / Stock-Out
</button>
</li>

<li class="nav-item">
<button class="nav-link w-100 text-start" data-bs-toggle="pill" data-bs-target="#pane-reports">
<i class="fa-solid fa-chart-pie me-2"></i>Reports &amp; Analytics
</button>
</li>

</ul>


<div class="sidebar-insights">
    <h6><i class="fa-regular fa-lightbulb me-1"></i> Quick Insights</h6>
    <ul>
        <li><i class="fa-solid fa-circle"></i> Total stock on hand: <strong>&nbsp;<?php echo number_format($totalStockInHand); ?> units</strong></li>
        <li><i class="fa-solid fa-circle"></i> <?php echo $lowStockCount; ?> item(s) running low on stock</li>
        <li><i class="fa-solid fa-circle"></i> <?php echo $expiredCount; ?> item(s) are expired</li>
        <li><i class="fa-solid fa-circle"></i> <?php echo $expiringSoonCount; ?> item(s) expiring within 30 days</li>
    </ul>
</div>

</div>


<div class="sidebar-footer">
    <i class="fa-solid fa-file-excel text-success me-1"></i>
    Excel reports automatically updated
    <div class="mt-1">Data as of <?php echo date('M j, Y g:i A'); ?></div>
</div>

</div>


<!-- ==========================================================
     MAIN
=========================================================== -->

<div class="col-md-10">


<!-- ==========================================================
     TOP NAV
=========================================================== -->

<div class="top-navbar px-4 py-3 d-flex justify-content-between align-items-center">

<div>
<h4 class="mb-0">Pharmacy Dashboard</h4>
<small>Real-time overview of medicine stock levels</small>
</div>

<div class="d-flex align-items-center">

<span class="text-muted small me-3">
<i class="fa-solid fa-circle-user me-1"></i>
<?php echo h(currentUsername()); ?>
</span>

<a href="?export=inventory" class="btn btn-success btn-sm me-2">
<i class="fa-solid fa-file-excel me-1"></i>Inventory Excel
</a>

<a href="?export=dispensing&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="btn btn-danger btn-sm me-2">
<i class="fa-solid fa-file-excel me-1"></i>Dispensing Excel
</a>

<a href="?logout=1" class="btn btn-outline-secondary btn-sm">
<i class="fa-solid fa-right-from-bracket me-1"></i>Logout
</a>

</div>

</div>


<div class="p-4">


<!-- ========================================================
     MESSAGE
========================================================= -->

<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo h($messageType); ?> alert-dismissible fade show">
<?php echo h($message); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>


<?php if (!empty($dbError)): ?>
<div class="alert alert-danger">
<strong><i class="fa-solid fa-database me-1"></i> Database connection failed.</strong>
Check the <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, and <code>DB_PASS</code>
values at the top of <code>config.php</code>, and make sure the Supabase project's
Postgres database is reachable (check your network/firewall allows outbound
connections to Supabase, and that SSL is enabled).
<div class="mt-1"><small class="text-muted"><?php echo h($dbError); ?></small></div>
</div>
<?php endif; ?>


<div class="tab-content">


<!-- ========================================================
     DASHBOARD
========================================================= -->

<div class="tab-pane fade <?php echo $activeTab === 'dashboard' ? 'show active' : ''; ?>" id="pane-dashboard">


<!-- KPI ROW -->

<div class="row g-3 mb-4">

<div class="col-xl-3 col-md-6">
<div class="card-custom kpi-card">
<div class="kpi-icon"><i class="fa-solid fa-capsules"></i></div>
<div class="kpi-body">
<small>Total Medicines</small>
<div class="kpi-number"><?php echo $totalProducts; ?></div>
<span class="kpi-trend neutral"><i class="fa-solid fa-circle-check"></i> Active items</span>
</div>
</div>
</div>

<div class="col-xl-3 col-md-6">
<div class="card-custom kpi-card">
<div class="kpi-icon dark"><i class="fa-solid fa-layer-group"></i></div>
<div class="kpi-body">
<small>Total Stock On Hand</small>
<div class="kpi-number"><?php echo number_format($totalStockInHand); ?></div>
<span class="kpi-trend neutral"><i class="fa-solid fa-cubes"></i> Units in inventory</span>
</div>
</div>
</div>

<div class="col-xl-3 col-md-6">
<div class="card-custom kpi-card">
<div class="kpi-icon warn"><i class="fa-solid fa-triangle-exclamation"></i></div>
<div class="kpi-body">
<small>Low Stock</small>
<div class="kpi-number"><?php echo $lowStockCount; ?></div>
<span class="kpi-trend bad"><i class="fa-solid fa-arrow-down"></i> Needs reorder</span>
</div>
</div>
</div>

<div class="col-xl-3 col-md-6">
<div class="card-custom kpi-card">
<div class="kpi-icon danger"><i class="fa-solid fa-calendar-xmark"></i></div>
<div class="kpi-body">
<small>Expired</small>
<div class="kpi-number"><?php echo $expiredCount; ?></div>
<span class="kpi-trend bad"><i class="fa-solid fa-ban"></i> Stock zeroed</span>
</div>
</div>
</div>

</div>


<!-- CHART ROW -->

<div class="row g-3 mb-4">

<div class="col-lg-5">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h6><i class="fa-solid fa-chart-line text-primary me-1"></i> Dispensing Trend (6 Months)</h6>
</div>
<canvas id="trendChart" height="220"></canvas>
</div>
</div>

<div class="col-lg-4">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h6><i class="fa-solid fa-chart-pie text-primary me-1"></i> Stock by Category</h6>
</div>
<canvas id="categoryDonut" height="220"></canvas>
</div>
</div>

<div class="col-lg-3">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h6><i class="fa-solid fa-chart-simple text-primary me-1"></i> Stock Status</h6>
</div>
<canvas id="statusDonut" height="220"></canvas>
</div>
</div>

</div>


<!-- EXPIRATION DASHBOARD -->

<div class="row g-3 mb-4">


<!-- EXPIRED -->

<div class="col-lg-6">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h5><i class="fa-solid fa-calendar-xmark text-danger me-2"></i>Expired Medicines</h5>
<span class="badge bg-danger"><?php echo $expiredCount; ?></span>
</div>

<?php if (empty($expiredMedicines)): ?>
<div class="text-muted text-center py-4">No expired medicines.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-custom">
<thead><tr><th>Medicine</th><th>Expiration</th><th>Stock</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($expiredMedicines as $med): ?>
<tr class="expired-row">
<td class="fw-bold"><?php echo h(medicineFullName($med)); ?></td>
<td><?php echo h($med['expiration_date'] ?? ''); ?></td>
<td><strong>0</strong></td>
<td><span class="badge bg-dark">EXPIRED</span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</div>
</div>


<!-- EXPIRING -->

<div class="col-lg-6">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h5><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i>Expiration Within 30 Days</h5>
<span class="badge bg-warning text-dark"><?php echo $expiringSoonCount; ?></span>
</div>

<?php if (empty($expiringMedicines)): ?>
<div class="text-muted text-center py-4">No medicine will expire within 30 days.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-custom">
<thead><tr><th>Medicine</th><th>Expiration</th><th>Days Left</th></tr></thead>
<tbody>
<?php foreach ($expiringMedicines as $med): ?>
<?php
$exp = strtotime($med['expiration_date']);
$daysLeft = ceil(($exp - $todayTimestamp) / 86400);
?>
<tr class="expiring-row">
<td class="fw-bold"><?php echo h(medicineFullName($med)); ?></td>
<td><?php echo h($med['expiration_date'] ?? ''); ?></td>
<td><span class="badge bg-warning text-dark"><?php echo $daysLeft; ?> day(s)</span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</div>
</div>

</div>


<!-- LOW STOCK + RECENT DISPENSING -->

<div class="row g-3 mb-4">

<div class="col-lg-6">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h5><i class="fa-solid fa-box-open text-warning me-2"></i>Low Stock Alerts</h5>
<span class="badge bg-warning text-dark">Individual Threshold</span>
</div>

<?php if (empty($lowStockMedicines)): ?>
<p class="text-muted text-center py-3">No low-stock medicines.</p>
<?php else: ?>
<div>
<?php foreach ($lowStockMedicines as $med): ?>
<div class="alert-list-item">
<div>
<div class="med-name"><?php echo h(medicineFullName($med)); ?></div>
<div class="med-cat"><?php echo h($med['category'] ?? 'General'); ?> &middot; Batch <?php echo h($med['batch_number'] ?? ''); ?></div>
</div>
<div class="text-end">
<div class="fw-bold text-danger"><?php echo intval($med['quantity'] ?? 0); ?> left</div>
<span class="reorder-pill">Reorder at <?php echo getThreshold($med); ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</div>

<div class="col-lg-6">
<div class="card-custom p-4 h-100">
<div class="card-title-row">
<h5>Recent Medicines Dispensed</h5>
<button class="btn btn-sm btn-outline-primary" data-bs-toggle="pill" data-bs-target="#pane-stockout">View All</button>
</div>

<div class="table-responsive">
<table class="table table-custom">
<thead><tr><th>Date</th><th>Medicine</th><th>Qty</th><th>Recipient</th></tr></thead>
<tbody>
<?php $recentLogs = array_slice($dispenseLogs, 0, 6); ?>
<?php foreach ($recentLogs as $log): ?>
<tr>
<td><?php echo h($log['date'] ?? ''); ?></td>
<td class="fw-bold"><?php echo h($log['inventory_name'] ?? ''); ?></td>
<td class="text-danger fw-bold">-<?php echo intval($log['qty_out'] ?? 0); ?></td>
<td><?php echo h($log['recipient'] ?? ''); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>
</div>

</div>

</div>


<!-- ========================================================
     INVENTORY
========================================================= -->

<div class="tab-pane fade <?php echo $activeTab === 'products' ? 'show active' : ''; ?>" id="pane-products">

<div class="d-flex justify-content-between align-items-center mb-3">
<div>
<h4 class="mb-1">Medicine Inventory</h4>
<small class="text-muted">Set a different low-stock level for every medicine.</small>
</div>
<a href="?export=inventory" class="btn btn-success">
<i class="fa-solid fa-file-excel me-1"></i>Save / Download Inventory Excel
</a>
</div>


<!-- ADD MEDICINE -->

<div class="card-custom p-4 mb-4">
<h5 class="mb-3">Register New Medicine</h5>

<form method="POST">
<input type="hidden" name="action" value="add_medicine">

<div class="row g-3">

<div class="col-md-3">
<label class="form-label">Medicine Name</label>
<input type="text" name="inventory_name" class="form-control" placeholder="Omeprazole" required>
</div>

<div class="col-md-2">
<label class="form-label">Strength</label>
<input type="text" name="strength" class="form-control" placeholder="20" required>
</div>

<div class="col-md-2">
<label class="form-label">Unit</label>
<select name="unit" class="form-select">
<option>mg</option><option>ml</option><option>g</option><option>mcg</option><option>%</option>
</select>
</div>

<div class="col-md-2">
<label class="form-label">Dosage Form</label>
<input type="text" name="dosage_form" class="form-control" placeholder="Tablet" required>
</div>

<div class="col-md-3">
<label class="form-label">Generic Name</label>
<input type="text" name="generic_name" class="form-control" placeholder="Generic name" required>
</div>

<div class="col-md-3">
<label class="form-label">Category</label>
<input type="text" name="category" class="form-control" placeholder="Antibiotics" required>
</div>

<div class="col-md-2">
<label class="form-label">Quantity</label>
<input type="number" name="quantity" class="form-control" min="0" value="100" required>
</div>

<div class="col-md-2">
<label class="form-label">Batch Number</label>
<input type="text" name="batch_number" class="form-control" required>
</div>

<div class="col-md-3">
<label class="form-label">Expiration Date</label>
<input type="date" name="expiration_date" class="form-control" required>
</div>

<div class="col-md-2">
<label class="form-label">Low Stock Warning At</label>
<input type="number" name="low_stock_threshold" class="form-control" min="1" value="200" required>
<small class="text-muted">Can be different per medicine.</small>
</div>

<div class="col-12">
<button class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i>Add Medicine</button>
</div>

</div>
</form>
</div>


<!-- INVENTORY TABLE -->

<div class="card-custom p-4">
<div class="card-title-row">
<h5>Complete Medicine List</h5>
<span class="badge bg-primary"><?php echo $totalProducts; ?> items</span>
</div>

<div class="table-responsive">
<table class="table table-bordered table-custom">
<thead>
<tr>
<th>Medicine</th><th>Strength</th><th>Form</th><th>Category</th><th>Batch</th>
<th>Expiration</th><th>Stock</th><th>Low Stock At</th><th>Status</th><th>Actions</th>
</tr>
</thead>
<tbody>

<?php foreach ($medicineInventory as $key => $med): ?>

<?php
$qty = intval($med['quantity'] ?? 0);
$threshold = getThreshold($med);
$expiration = $med['expiration_date'] ?? '';
$exp = !empty($expiration) ? strtotime($expiration) : false;
$rowClass = '';

if ($exp !== false && $exp <= $todayTimestamp) {
    $status = '<span class="badge bg-dark">EXPIRED</span>';
    $rowClass = 'expired-row';
} elseif ($exp !== false && $exp <= $expiryThreshold) {
    $status = '<span class="badge bg-warning text-dark">EXPIRING SOON</span>';
    $rowClass = 'expiring-row';
} elseif ($qty <= $threshold) {
    $status = '<span class="badge bg-warning text-dark">LOW STOCK</span>';
    $rowClass = 'low-row';
} else {
    $status = '<span class="badge bg-success">AVAILABLE</span>';
}
?>

<tr class="<?php echo $rowClass; ?>">
<td class="fw-bold"><?php echo h(medicineFullName($med)); ?><br><small class="text-muted">SKU: <?php echo h($med['sku'] ?? ''); ?></small></td>
<td><?php echo h($med['strength'] ?? ''); ?> <?php echo h($med['unit'] ?? ''); ?></td>
<td><?php echo h($med['dosage_form'] ?? ''); ?></td>
<td><?php echo h($med['category'] ?? ''); ?></td>
<td><?php echo h($med['batch_number'] ?? ''); ?></td>
<td><?php echo h($med['expiration_date'] ?? ''); ?></td>
<td class="fw-bold"><?php echo $qty; ?></td>
<td class="fw-bold text-warning"><?php echo $threshold; ?></td>
<td><?php echo $status; ?></td>
<td>
<button class="btn btn-sm btn-primary mb-1" data-bs-toggle="modal" data-bs-target="#editModal<?php echo h($key); ?>"><i class="fa-solid fa-pen"></i></button>
<form method="POST" style="display:inline" onsubmit="return confirm('Remove this medicine from inventory?');">
<input type="hidden" name="action" value="delete_medicine">
<input type="hidden" name="medicine_key" value="<?php echo h($key); ?>">
<button class="btn btn-sm btn-danger mb-1"><i class="fa-solid fa-trash"></i></button>
</form>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>
</div>


<!-- ========================================================
     EDIT MODALS
========================================================= -->

<?php foreach ($medicineInventory as $key => $med): ?>

<div class="modal fade" id="editModal<?php echo h($key); ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">

<div class="modal-header">
<h5 class="modal-title">Edit Medicine</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form method="POST">
<input type="hidden" name="action" value="edit_medicine">
<input type="hidden" name="medicine_key" value="<?php echo h($key); ?>">

<div class="modal-body">
<div class="row g-3">

<div class="col-md-4">
<label class="form-label">Medicine Name</label>
<input name="inventory_name" class="form-control" value="<?php echo h($med['inventory_name'] ?? ''); ?>" required>
</div>

<div class="col-md-2">
<label class="form-label">Strength</label>
<input name="strength" class="form-control" value="<?php echo h($med['strength'] ?? ''); ?>" required>
</div>

<div class="col-md-2">
<label class="form-label">Unit</label>
<select name="unit" class="form-select">
<?php $units = ['mg','ml','g','mcg','%']; foreach ($units as $unit): ?>
<option value="<?php echo h($unit); ?>" <?php echo (($med['unit'] ?? '') === $unit) ? 'selected' : ''; ?>><?php echo h($unit); ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-4">
<label class="form-label">Dosage Form</label>
<input name="dosage_form" class="form-control" value="<?php echo h($med['dosage_form'] ?? ''); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label">Generic Name</label>
<input name="generic_name" class="form-control" value="<?php echo h($med['generic_name'] ?? ''); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label">Category</label>
<input name="category" class="form-control" value="<?php echo h($med['category'] ?? ''); ?>" required>
</div>

<div class="col-md-3">
<label class="form-label">Quantity</label>
<input type="number" name="quantity" class="form-control" min="0" value="<?php echo intval($med['quantity'] ?? 0); ?>" required>
</div>

<div class="col-md-3">
<label class="form-label">Batch Number</label>
<input name="batch_number" class="form-control" value="<?php echo h($med['batch_number'] ?? ''); ?>" required>
</div>

<div class="col-md-3">
<label class="form-label">Expiration</label>
<input type="date" name="expiration_date" class="form-control" value="<?php echo h($med['expiration_date'] ?? ''); ?>" required>
</div>

<div class="col-md-3">
<label class="form-label">Low Stock Warning</label>
<input type="number" name="low_stock_threshold" class="form-control" min="1" value="<?php echo getThreshold($med); ?>" required>
</div>

</div>
</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>Save Changes</button>
</div>

</form>
</div>
</div>
</div>

<?php endforeach; ?>

</div>


<!-- ========================================================
     STOCK OUT
========================================================= -->

<div class="tab-pane fade <?php echo $activeTab === 'stockout' ? 'show active' : ''; ?>" id="pane-stockout">

<div class="d-flex justify-content-between align-items-center mb-3">
<div>
<h4>Dispense / Stock-Out</h4>
<small class="text-muted">Every transaction is automatically recorded.</small>
</div>
<a href="?export=dispensing&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="btn btn-danger">
<i class="fa-solid fa-file-excel me-1"></i>Current Month Excel
</a>
</div>


<div class="card-custom p-4 mb-4">
<h5 class="mb-3"><i class="fa-solid fa-truck-ramp-box text-danger me-2"></i>Dispense Medicine</h5>

<form method="POST">
<input type="hidden" name="action" value="stock_out">

<div class="row g-3">

<div class="col-md-6">
<label class="form-label">Select Medicine</label>
<select name="medicine_key" id="medicineSelect" class="form-select" required>
<option value="">Type or select a medicine...</option>
<?php foreach ($medicineInventory as $key => $med): ?>
<?php
$exp = !empty($med['expiration_date']) ? strtotime($med['expiration_date']) : false;
$expired = $exp !== false && $exp <= $todayTimestamp;
?>
<option value="<?php echo h($key); ?>" <?php echo $expired ? 'disabled' : ''; ?>>
<?php echo h(medicineFullName($med) . ' | Stock: ' . intval($med['quantity'] ?? 0) . ' | Batch: ' . ($med['batch_number'] ?? '') . ($expired ? ' | EXPIRED' : '')); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-2">
<label class="form-label">Quantity Out</label>
<input type="number" name="qty_out" class="form-control" min="1" value="1" required>
</div>

<div class="col-md-4">
<label class="form-label">Patient / Recipient</label>
<input type="text" name="recipient" class="form-control" placeholder="Patient / Ward" required>
</div>

<div class="col-12">
<button class="btn btn-danger"><i class="fa-solid fa-minus-circle me-1"></i>Confirm Dispense</button>
</div>

</div>
</form>
</div>


<!-- DISPENSING HISTORY -->

<div class="card-custom p-4">
<h5 class="mb-3">Dispensing History</h5>

<div class="table-responsive">
<table class="table table-bordered table-custom">
<thead><tr><th>Date</th><th>Medicine</th><th>Batch</th><th>Qty Out</th><th>Recipient</th></tr></thead>
<tbody>
<?php foreach ($dispenseLogs as $log): ?>
<tr>
<td><?php echo h($log['date']); ?></td>
<td class="fw-bold"><?php echo h($log['inventory_name']); ?></td>
<td><?php echo h($log['batch_number']); ?></td>
<td class="text-danger fw-bold">-<?php echo intval($log['qty_out']); ?></td>
<td><?php echo h($log['recipient']); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

</div>


<!-- ========================================================
     REPORTS
========================================================= -->

<div class="tab-pane fade" id="pane-reports">

<div class="d-flex justify-content-between align-items-center mb-3">
<div>
<h4>Reports &amp; Analytics</h4>
<small class="text-muted">Expiration, inventory and monthly dispensing reports.</small>
</div>
<a href="?export=inventory" class="btn btn-success"><i class="fa-solid fa-file-excel me-1"></i>Inventory Excel</a>
</div>


<!-- REPORT KPI -->

<div class="row g-3 mb-4">

<div class="col-md-3">
<div class="card-custom kpi-card">
<div class="kpi-icon"><i class="fa-solid fa-capsules"></i></div>
<div class="kpi-body"><small>Total Medicines</small><div class="kpi-number"><?php echo $totalProducts; ?></div></div>
</div>
</div>

<div class="col-md-3">
<div class="card-custom kpi-card">
<div class="kpi-icon dark"><i class="fa-solid fa-cubes"></i></div>
<div class="kpi-body"><small>Total Units</small><div class="kpi-number"><?php echo number_format($totalStockInHand); ?></div></div>
</div>
</div>

<div class="col-md-3">
<div class="card-custom kpi-card">
<div class="kpi-icon danger"><i class="fa-solid fa-calendar-xmark"></i></div>
<div class="kpi-body"><small>Expired</small><div class="kpi-number"><?php echo $expiredCount; ?></div></div>
</div>
</div>

<div class="col-md-3">
<div class="card-custom kpi-card">
<div class="kpi-icon warn"><i class="fa-solid fa-triangle-exclamation"></i></div>
<div class="kpi-body"><small>Expiring in 30 Days</small><div class="kpi-number"><?php echo $expiringSoonCount; ?></div></div>
</div>
</div>

</div>


<!-- MONTHLY DISPENSING -->

<div class="card-custom p-4 mb-4">

<div class="card-title-row">
<div>
<h5 class="mb-1"><i class="fa-solid fa-chart-column text-primary me-2"></i>Monthly Medicines Dispensed</h5>
<small class="text-muted">See what medicines were released during the selected month.</small>
</div>
</div>

<form method="GET" class="row g-2 mb-4">

<div class="col-md-3">
<label class="form-label">Month</label>
<select name="report_month" class="form-select">
<?php for ($m = 1; $m <= 12; $m++): ?>
<option value="<?php echo $m; ?>" <?php echo $m == $selectedMonth ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
<?php endfor; ?>
</select>
</div>

<div class="col-md-2">
<label class="form-label">Year</label>
<select name="report_year" class="form-select">
<?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
<option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
<?php endfor; ?>
</select>
</div>

<div class="col-md-3 d-flex align-items-end">
<button class="btn btn-primary"><i class="fa-solid fa-filter me-1"></i>Show Report</button>
</div>

<div class="col-md-4 d-flex align-items-end justify-content-end">
<a href="?export=dispensing&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-success">
<i class="fa-solid fa-file-excel me-1"></i>Export This Month to Excel
</a>
</div>

</form>


<div class="alert alert-danger">
<strong><?php echo date('F Y', strtotime("$selectedYear-$selectedMonth-01")); ?></strong>
&mdash; Total medicines dispensed: <strong><?php echo number_format($monthlyTotalOut); ?> units</strong>
</div>


<!-- MEDICINE SUMMARY -->

<h6 class="fw-bold mt-4">Medicines Released This Month</h6>

<?php if (empty($monthlyMedicineTotals)): ?>
<div class="text-muted text-center py-4">No medicines were dispensed during this month.</div>
<?php else: ?>

<div class="table-responsive mb-4">
<table class="table table-bordered table-custom">
<thead><tr><th>Medicine</th><th>Total Released</th><th>Percentage</th></tr></thead>
<tbody>
<?php foreach ($monthlyMedicineTotals as $name => $qty): ?>
<?php $percentage = $monthlyTotalOut > 0 ? ($qty / $monthlyTotalOut) * 100 : 0; ?>
<tr>
<td class="fw-bold"><?php echo h($name); ?></td>
<td class="text-danger fw-bold"><?php echo $qty; ?> units</td>
<td>
<div class="progress">
<div class="progress-bar" style="width:<?php echo $percentage; ?>%"><?php echo number_format($percentage, 1); ?>%</div>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endif; ?>


<!-- MONTHLY TRANSACTIONS -->

<h6 class="fw-bold">Detailed Transactions</h6>

<div class="table-responsive">
<table class="table table-bordered table-custom">
<thead><tr><th>Date</th><th>Medicine</th><th>Batch</th><th>Quantity</th><th>Recipient</th></tr></thead>
<tbody>
<?php if (empty($monthlyDispense)): ?>
<tr><td colspan="5" class="text-center text-muted">No dispensing transactions for this month.</td></tr>
<?php else: ?>
<?php foreach ($monthlyDispense as $log): ?>
<tr>
<td><?php echo h($log['date']); ?></td>
<td class="fw-bold"><?php echo h($log['inventory_name']); ?></td>
<td><?php echo h($log['batch_number']); ?></td>
<td class="text-danger fw-bold">-<?php echo intval($log['qty_out']); ?></td>
<td><?php echo h($log['recipient']); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

</div>


<!-- ========================================================
     EXPIRATION REPORT
========================================================= -->

<div class="card-custom p-4">

<div class="card-title-row">
<h5><i class="fa-solid fa-calendar-check text-warning me-2"></i>Expiration Report</h5>
<span class="badge bg-warning text-dark">30-Day Warning</span>
</div>

<hr>

<h6 class="fw-bold text-danger">Already Expired</h6>

<?php if (empty($expiredMedicines)): ?>
<p class="text-muted">No expired medicines.</p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-bordered table-custom">
<thead><tr><th>Medicine</th><th>Batch</th><th>Expiration Date</th><th>Inventory Stock</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($expiredMedicines as $med): ?>
<tr class="expired-row">
<td class="fw-bold"><?php echo h(medicineFullName($med)); ?></td>
<td><?php echo h($med['batch_number'] ?? ''); ?></td>
<td><?php echo h($med['expiration_date'] ?? ''); ?></td>
<td class="text-danger fw-bold">0</td>
<td><span class="badge bg-dark">EXPIRED - REMOVED FROM AVAILABLE STOCK</span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<hr>

<h6 class="fw-bold text-warning">Expiring Within 30 Days</h6>

<?php if (empty($expiringMedicines)): ?>
<p class="text-muted">No medicines expiring within the next 30 days.</p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-bordered table-custom">
<thead><tr><th>Medicine</th><th>Batch</th><th>Expiration Date</th><th>Days Remaining</th><th>Stock</th></tr></thead>
<tbody>
<?php foreach ($expiringMedicines as $med): ?>
<?php $daysLeft = ceil((strtotime($med['expiration_date']) - $todayTimestamp) / 86400); ?>
<tr class="expiring-row">
<td class="fw-bold"><?php echo h(medicineFullName($med)); ?></td>
<td><?php echo h($med['batch_number'] ?? ''); ?></td>
<td><?php echo h($med['expiration_date'] ?? ''); ?></td>
<td><span class="badge bg-warning text-dark"><?php echo $daysLeft; ?> day(s)</span></td>
<td><?php echo intval($med['quantity'] ?? 0); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</div>

</div>

</div>

</div>

</div>

</div>

</div>

</div>


<!-- ==========================================================
     BOOTSTRAP JAVASCRIPT
=========================================================== -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


<!-- ==========================================================
     CHOICES.JS INIT (Dispense Medicine: type OR select)
=========================================================== -->

<script src="https://cdn.jsdelivr.net/npm/choices.js@10.2.0/public/assets/scripts/choices.min.js"></script>
<script>
(function () {
    var medicineSelectEl = document.getElementById('medicineSelect');
    if (medicineSelectEl && typeof Choices !== 'undefined') {
        new Choices(medicineSelectEl, {
            searchEnabled: true,
            searchPlaceholderValue: 'Type to search medicines...',
            itemSelectText: '',
            shouldSort: false,
            placeholder: true,
            searchResultLimit: 50,
            noResultsText: 'No matching medicine found',
            fuseOptions: { threshold: 0.3 }
        });
    }
})();
</script>


<!-- ==========================================================
     CHARTS
=========================================================== -->

<script>

const purple500 = '#7c3aed';
const purple300 = '#b18ffb';
const purple100 = '#f0eafd';

Chart.defaults.font.family = "Plus Jakarta Sans, system-ui, sans-serif";
Chart.defaults.color = '#8b81a3';

/* DISPENSING TREND LINE CHART */

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trendLabels); ?>,
        datasets: [{
            label: 'Units Dispensed',
            data: <?php echo json_encode($trendData); ?>,
            borderColor: purple500,
            backgroundColor: 'rgba(124, 58, 237, 0.10)',
            borderWidth: 2.5,
            tension: 0.35,
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: purple500
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#eee9f7' } },
            x: { grid: { display: false } }
        }
    }
});


/* CATEGORY DONUT CHART */

new Chart(document.getElementById('categoryDonut'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($categoryCounts)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($categoryCounts)); ?>,
            backgroundColor: ['#7c3aed', '#a78bfa', '#c4b5fd', '#ec4899', '#f472b6', '#5b21b6', '#9061f9'],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        cutout: '65%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
    }
});


/* STOCK STATUS DONUT CHART */

new Chart(document.getElementById('statusDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Available', 'Low Stock', 'Expiring Soon', 'Expired'],
        datasets: [{
            data: [
                <?php echo $availableCount; ?>,
                <?php echo $lowStockCount; ?>,
                <?php echo $expiringSoonCount; ?>,
                <?php echo $expiredCount; ?>
            ],
            backgroundColor: ['#16a34a', '#f59e0b', '#fb923c', '#e11d48'],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        cutout: '65%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
    }
});

</script>


</body>

</html>
