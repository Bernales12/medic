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
| - Delivery / Stock-In module
| - Daily and monthly delivery reports
| - Automatic stock addition on delivery
| - Monthly dispensing report
| - Excel-compatible inventory export
| - Excel-compatible dispensing export
| - Monthly Delivery Excel export
| - Save/print delivery history by selected month
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
   ONLY ONCE — the very first time the app runs against a
   brand new database. A dedicated app_settings flag tracks
   whether seeding has already happened, so deleting every
   medicine afterwards does NOT bring the starter data back.

   NOTE: You can also run supabase_schema.sql once in the
   Supabase SQL editor instead of relying on this. Either
   path is safe — this function only creates tables that
   don't already exist and only seeds once, ever.
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delivery_logs (
            id SERIAL PRIMARY KEY,
            delivery_date DATE NOT NULL DEFAULT CURRENT_DATE,
            medicine_sku VARCHAR(20) NOT NULL,
            inventory_name VARCHAR(255) NOT NULL,
            strength VARCHAR(50) NOT NULL DEFAULT '',
            unit VARCHAR(20) NOT NULL DEFAULT '',
            dosage_form VARCHAR(50) NOT NULL DEFAULT '',
            generic_name VARCHAR(255) NOT NULL DEFAULT '',
            category VARCHAR(100) NOT NULL DEFAULT '',
            batch_number VARCHAR(100) NOT NULL DEFAULT '',
            expiration_date DATE NULL,
            quantity_delivered INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // ------------------------------------------------------------------
    // Settings table: used as a one-time "have we seeded before?" flag.
    // This is what actually fixes the "deleted items keep coming back"
    // bug — seeding used to be triggered by "medicines table is empty",
    // which becomes true again the moment you delete every medicine.
    // ------------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            key_name VARCHAR(50) PRIMARY KEY,
            value VARCHAR(255) NOT NULL DEFAULT ''
        )
    ");

    $userCount = $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];

    if (intval($userCount) === 0) {

        $pdo->prepare("
            INSERT INTO users (username, password_hash)
            VALUES (?, ?)
        ")->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }

    $seededFlag = $pdo->query("
        SELECT value FROM app_settings WHERE key_name = 'medicines_seeded'
    ")->fetch();

    if (!$seededFlag) {

        $seedMedicines = [
            ['M001', 'Paracetamol', '500', 'mg', 'Tablet', 'Paracetamol (Acetaminophen)', 150, 'BCH-2026-01A', '2028-05-15', 'Analgesics', 200],
            ['M002', 'Amoxicillin', '500', 'mg', 'Capsule', 'Amoxicillin Trihydrate', 12, 'BCH-2025-09C', '2027-11-20', 'Antibiotics', 20],
            ['M003', 'Cetirizine', '10', 'mg', 'Tablet', 'Cetirizine Dihydrochloride', 8, 'BCH-2026-03X', '2028-01-10', 'Antihistamines', 15]
        ];

        $stmt = $pdo->prepare("
            INSERT INTO medicines
                (sku, inventory_name, strength, unit, dosage_form, generic_name, quantity, batch_number, expiration_date, category, low_stock_threshold)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (sku) DO NOTHING
        ");

        foreach ($seedMedicines as $row) {
            $stmt->execute($row);
        }

        $pdo->prepare("
            INSERT INTO dispense_logs (dispense_date, inventory_name, batch_number, qty_out, recipient)
            VALUES (?, ?, ?, ?, ?)
        ")->execute(['2026-05-20', 'Paracetamol 500 mg Tablet', 'BCH-2026-01A', 10, 'John Doe']);

        // Mark seeding as done FOREVER — even if every medicine
        // is later deleted, this flag will still exist and
        // prevent the starter data from being re-inserted.
        $pdo->prepare("
            INSERT INTO app_settings (key_name, value)
            VALUES ('medicines_seeded', '1')
            ON CONFLICT (key_name) DO NOTHING
        ")->execute();
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

function findUserById($id)
{
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function updateUserCredentials($id, $newUsername, $newPasswordHash = null)
{
    if ($newPasswordHash !== null) {

        $stmt = db()->prepare("UPDATE users SET username = ?, password_hash = ? WHERE id = ?");
        $stmt->execute([$newUsername, $newPasswordHash, $id]);

    } else {

        $stmt = db()->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->execute([$newUsername, $id]);
    }
}

function authCookieName()
{
    return 'pharmacy_auth';
}

function authSecret()
{
    if (defined('AUTH_SECRET') && AUTH_SECRET !== '') {
        return AUTH_SECRET;
    }

    return hash('sha256', DB_PASS . '|pharmacy-auth-v2');
}

function base64UrlEncode($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode($value)
{
    $remainder = strlen($value) % 4;

    if ($remainder) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'));
}

function setAuthCookie($user)
{
    $payload = base64UrlEncode(json_encode([
        'id' => intval($user['id']),
        'username' => $user['username'],
        'exp' => time() + (7 * 24 * 60 * 60)
    ]));

    $signature = hash_hmac('sha256', $payload, authSecret());

    setcookie(authCookieName(), $payload . '.' . $signature, [
        'expires' => time() + (7 * 24 * 60 * 60),
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $_SESSION['user_id'] = intval($user['id']);
    $_SESSION['username'] = $user['username'];
}

function clearAuthCookie()
{
    setcookie(authCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $_SESSION = [];
    session_destroy();
}

function getAuthenticatedUser()
{
    static $checked = false;
    static $cachedUser = null;

    if ($checked) {
        return $cachedUser;
    }

    $checked = true;

    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([intval($_SESSION['user_id'])]);
        $user = $stmt->fetch();

        if ($user) {
            $cachedUser = $user;
            return $cachedUser;
        }
    }

    $cookie = $_COOKIE[authCookieName()] ?? '';

    if ($cookie === '' || strpos($cookie, '.') === false) {
        return null;
    }

    [$payload, $signature] = explode('.', $cookie, 2);
    $expected = hash_hmac('sha256', $payload, authSecret());

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $decoded = base64UrlDecode($payload);
    $data = json_decode($decoded ?: '', true);

    if (!is_array($data) || empty($data['id']) || empty($data['username']) || empty($data['exp'])) {
        return null;
    }

    if (intval($data['exp']) < time()) {
        return null;
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND username = ?");
    $stmt->execute([intval($data['id']), $data['username']]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    $_SESSION['user_id'] = intval($user['id']);
    $_SESSION['username'] = $user['username'];

    $cachedUser = $user;
    return $cachedUser;
}

function isLoggedIn()
{
    return getAuthenticatedUser() !== null;
}

function currentUsername()
{
    $user = getAuthenticatedUser();
    return $user['username'] ?? '';
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
    min-height: 100dvh;
    width: 100%;
    overflow-x: hidden;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.login-card {
    width: min(100%, 400px);
    max-width: 400px;
    background: #ffffff;
    border-radius: 1rem;
    padding: 2.5rem;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}
.login-icon {
    width: 112px;
    height: 112px;
    margin: 0 auto 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.login-logo {
    width: 112px;
    height: 112px;
    object-fit: contain;
    display: block;
    border-radius: 50%;
    filter: drop-shadow(0 6px 14px rgba(0, 0, 0, .20));
}
.btn-purple {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    border: none;
    color: #fff;
}
.btn-purple:hover {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    color: #fff;
}
.form-control:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 .2rem rgba(124, 58, 237, .15);
}
.password-toggle-wrap { position: relative; }
.password-toggle-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #8b81a3;
    padding: 4px 6px;
}
@media (max-width: 480px) {
    .login-card { padding: 1.75rem 1.5rem; border-radius: .75rem; }
    .login-icon, .login-logo {
        width: 88px;
        height: 88px;
    }
}
</style>
</head>
<body>
<div class="login-card">
<div class="login-icon"><img src="https://raw.githubusercontent.com/Bernales12/medic/main/api/pharmacy.png" alt="Pharmacy Logo" class="login-logo"></div>
<h4 class="text-center fw-bold mb-1">Pharmacy Inventory</h4>
<p class="text-center text-muted mb-4">Authorized access only</p>

<?php if (!empty($loginError)): ?>
<div class="alert alert-danger py-2 small"><?php echo h($loginError); ?></div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="action" value="login">

<div class="mb-3">
<label class="form-label">Username</label>
<input type="text" name="username" class="form-control" required autofocus autocomplete="username">
</div>

<div class="mb-3">
<label class="form-label">Password</label>
<div class="password-toggle-wrap">
<input type="password" name="password" id="loginPassword" class="form-control" required autocomplete="current-password">
<button type="button" class="password-toggle-btn" id="togglePassword" aria-label="Show password">
<i class="fa-solid fa-eye"></i>
</button>
</div>
</div>

<button type="submit" class="btn btn-purple w-100 mt-2">
<i class="fa-solid fa-right-to-bracket me-1"></i>Log In
</button>

</form>
</div>

<script>
(function () {
    var toggleBtn = document.getElementById('togglePassword');
    var pwInput = document.getElementById('loginPassword');
    if (toggleBtn && pwInput) {
        toggleBtn.addEventListener('click', function () {
            var isHidden = pwInput.type === 'password';
            pwInput.type = isHidden ? 'text' : 'password';
            toggleBtn.innerHTML = isHidden
                ? '<i class="fa-solid fa-eye-slash"></i>'
                : '<i class="fa-solid fa-eye"></i>';
        });
    }
})();
</script>


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

function findMatchingMedicines($data, $excludeSku = null)
{
    /*
     * Match ONLY these medicine-identifying fields:
     * inventory_name, strength, unit, dosage_form, generic_name, category.
     * Ignore batch_number, expiration_date, quantity, sku, and low_stock_threshold.
     * Therefore different batches and different expiration dates merge.
     */
    $sql = "
        SELECT *
        FROM medicines
        WHERE LOWER(TRIM(COALESCE(inventory_name, ''))) = LOWER(TRIM(:inventory_name))
          AND LOWER(TRIM(COALESCE(strength, ''))) = LOWER(TRIM(:strength))
          AND LOWER(TRIM(COALESCE(unit, ''))) = LOWER(TRIM(:unit))
          AND LOWER(TRIM(COALESCE(dosage_form, ''))) = LOWER(TRIM(:dosage_form))
          AND LOWER(TRIM(COALESCE(generic_name, ''))) = LOWER(TRIM(:generic_name))
          AND LOWER(TRIM(COALESCE(category, ''))) = LOWER(TRIM(:category))
    ";

    if ($excludeSku !== null && $excludeSku !== '') {
        $sql .= " AND sku <> :exclude_sku";
    }

    $sql .= " ORDER BY created_at ASC, sku ASC FOR UPDATE";

    $stmt = db()->prepare($sql);

    $params = [
        'inventory_name' => $data['inventory_name'],
        'strength' => $data['strength'],
        'unit' => $data['unit'],
        'dosage_form' => $data['dosage_form'],
        'generic_name' => $data['generic_name'],
        'category' => $data['category']
    ];

    if ($excludeSku !== null && $excludeSku !== '') {
        $params['exclude_sku'] = $excludeSku;
    }

    $stmt->execute($params);
    return $stmt->fetchAll();
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

function fetchAllDeliveryLogs()
{
    $rows = db()->query("SELECT * FROM delivery_logs ORDER BY delivery_date DESC, id DESC")->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id' => intval($row['id']), 'date' => date('d M Y', strtotime($row['delivery_date'])),
            'date_iso' => $row['delivery_date'], 'medicine_sku' => $row['medicine_sku'],
            'inventory_name' => $row['inventory_name'], 'strength' => $row['strength'],
            'unit' => $row['unit'], 'dosage_form' => $row['dosage_form'],
            'generic_name' => $row['generic_name'], 'category' => $row['category'],
            'batch_number' => $row['batch_number'], 'expiration_date' => $row['expiration_date'],
            'quantity_delivered' => intval($row['quantity_delivered'])
        ];
    }
    return $result;
}

function insertDeliveryLog($dateIso, $medicine, $quantity)
{
    $stmt = db()->prepare("
        INSERT INTO delivery_logs
        (delivery_date, medicine_sku, inventory_name, strength, unit, dosage_form, generic_name, category, batch_number, expiration_date, quantity_delivered)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $dateIso, $medicine['sku'], $medicine['inventory_name'], $medicine['strength'],
        $medicine['unit'], $medicine['dosage_form'], $medicine['generic_name'], $medicine['category'],
        $medicine['batch_number'], $medicine['expiration_date'] ?: null, $quantity
    ]);
}

function syncDeliveryLogsForMedicine($sku, $medicine)
{
    // Keep delivery history display synchronized with the inventory row after an edit.
    $stmt = db()->prepare("
        UPDATE delivery_logs SET
            inventory_name = ?,
            strength = ?,
            unit = ?,
            dosage_form = ?,
            generic_name = ?,
            category = ?,
            batch_number = ?,
            expiration_date = ?
        WHERE medicine_sku = ?
    ");
    $stmt->execute([
        $medicine['inventory_name'],
        $medicine['strength'],
        $medicine['unit'],
        $medicine['dosage_form'],
        $medicine['generic_name'],
        $medicine['category'],
        $medicine['batch_number'],
        $medicine['expiration_date'] ?: null,
        $sku
    ]);
}


/* ============================================================
   SAVE INVENTORY EXCEL
============================================================ */

function saveInventoryExcel($medicineInventory)
{
    $file = sys_get_temp_dir() . '/inventory_report.xls';

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

    @file_put_contents($file, $html);
}


/* ============================================================
   SAVE DISPENSE EXCEL
============================================================ */

function saveDispenseExcel($dispenseLogs)
{
    $file = sys_get_temp_dir() . '/dispensing_report.xls';

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

    @file_put_contents($file, $html);
}



/* ============================================================
   DOWNLOAD DELIVERY EXCEL — SELECTED MONTH / YEAR
============================================================ */
function downloadDeliveryExcel($month, $year, $deliveryLogs)
{
    $month = max(1, min(12, intval($month)));
    $year = max(2000, min(2100, intval($year)));
    $filename = "medicine_delivery_" . $year . "_" . str_pad($month, 2, '0', STR_PAD_LEFT) . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=" . $filename);
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html><head><meta charset="UTF-8"><style>
    table{border-collapse:collapse}th{background:#16a34a;color:#fff;border:1px solid #999;padding:8px}
    td{border:1px solid #999;padding:6px}h2,h3{font-family:Arial,sans-serif}
    </style></head><body>';

    echo '<h2>Medicine Delivery / Stock-In Report</h2>';
    echo '<h3>' . h(date('F Y', strtotime("$year-$month-01"))) . '</h3>';
    echo '<table><tr>
        <th>Date</th><th>Medicine</th><th>Strength</th><th>Unit</th>
        <th>Dosage Form</th><th>Generic Name</th><th>Category</th>
        <th>Batch Number</th><th>Expiration Date</th><th>Quantity Delivered</th>
    </tr>';

    $total = 0;
    foreach ($deliveryLogs as $delivery) {
        $iso = $delivery['date_iso'] ?? '';
        $ts = $iso !== '' ? strtotime($iso) : false;
        if ($ts === false || intval(date('m',$ts)) !== $month || intval(date('Y',$ts)) !== $year) continue;

        $qty = intval($delivery['quantity_delivered'] ?? 0);
        $total += $qty;
        $fields = [
            $delivery['date'] ?? '',
            medicineFullName($delivery),
            $delivery['strength'] ?? '',
            $delivery['unit'] ?? '',
            $delivery['dosage_form'] ?? '',
            $delivery['generic_name'] ?? '',
            $delivery['category'] ?? '',
            $delivery['batch_number'] ?? '',
            $delivery['expiration_date'] ?? '',
            $qty
        ];
        echo '<tr>';
        foreach ($fields as $field) echo '<td>' . h($field) . '</td>';
        echo '</tr>';
    }

    echo '<tr><th colspan="9" style="text-align:right">TOTAL DELIVERED</th><th>' . number_format($total) . '</th></tr>';
    echo '</table></body></html>';
    exit;
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
        clearAuthCookie();
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

            setAuthCookie($user);

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

    /*
     * DISPENSE ONLY:
     * Inventory rows remain separate so the user can see every
     * batch, expiration date, quantity and low-stock warning.
     *
     * In Dispense Medicine, rows are grouped by ONLY:
     * medicine name + strength + unit + dosage form + generic name + category.
     *
     * Batch, expiration, quantity, low-stock warning and SKU do not affect
     * the grouping. Expired rows are not counted as available stock.
     */
    $dispenseGroups = [];

    foreach ($medicineInventory as $med) {
        $groupKey = strtolower(implode('|', [
            trim((string)($med['inventory_name'] ?? '')),
            trim((string)($med['strength'] ?? '')),
            trim((string)($med['unit'] ?? '')),
            trim((string)($med['dosage_form'] ?? '')),
            trim((string)($med['generic_name'] ?? '')),
            trim((string)($med['category'] ?? ''))
        ]));

        $expiration = $med['expiration_date'] ?? '';
        $expired = false;

        if ($expiration !== '') {
            $expirationTs = strtotime($expiration);
            $expired = $expirationTs !== false && $expirationTs <= strtotime(date('Y-m-d'));
        }

        if (!isset($dispenseGroups[$groupKey])) {
            $dispenseGroups[$groupKey] = [
                'group_key' => $groupKey,
                'inventory_name' => $med['inventory_name'] ?? '',
                'strength' => $med['strength'] ?? '',
                'unit' => $med['unit'] ?? '',
                'dosage_form' => $med['dosage_form'] ?? '',
                'generic_name' => $med['generic_name'] ?? '',
                'category' => $med['category'] ?? '',
                'quantity' => 0,
                'rows' => 0
            ];
        }

        $dispenseGroups[$groupKey]['rows']++;

        // Do not count expired stock as available for dispensing.
        if (!$expired) {
            $dispenseGroups[$groupKey]['quantity'] += intval($med['quantity'] ?? 0);
        }
    }

    $dispenseInventory = array_values($dispenseGroups);
    $dispenseLogs = fetchAllDispenseLogs();
    $deliveryLogs = fetchAllDeliveryLogs();

} catch (PDOException $e) {

    $dbError = $e->getMessage();
    $medicineInventory = [];
    $dispenseLogs = [];
    $deliveryLogs = [];
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

    if (isset($_GET['export']) && $_GET['export'] === 'delivery') {
        $month = intval($_GET['month'] ?? date('m'));
        $year = intval($_GET['year'] ?? date('Y'));
        downloadDeliveryExcel($month, $year, $deliveryLogs);
    }
}


/* ============================================================
   POST ACTIONS
============================================================ */

/*
 * Post/Redirect/Get:
 * A POST must never remain as the browser's current page.
 * Otherwise Refresh resubmits the INSERT and creates duplicates.
 */
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'success';

    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}


$tabByAction = [
    'add_medicine' => 'products',
    'edit_medicine' => 'products',
    'delete_medicine' => 'products',
    'receive_delivery' => 'delivery',
    'stock_out' => 'stockout',
    'stock_out_batch' => 'stockout'
];

$activeTab = $_GET['tab'] ?? ($tabByAction[$_POST['action'] ?? ''] ?? 'dashboard');

if (empty($dbError) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        /* ====================================================
           CLEAR DELIVERY HISTORY — PASSWORD PROTECTED
        ==================================================== */
        if ($action === 'clear_delivery_history') {
            $clearPassword = $_POST['clear_password'] ?? '';
            $currentUser = findUserById($_SESSION['user_id'] ?? 0);

            if (!$currentUser || !password_verify($clearPassword, $currentUser['password_hash'])) {
                $message = "Incorrect password. Delivery history was not cleared.";
                $messageType = "danger";
            } else {
                db()->exec("DELETE FROM delivery_logs");
                $message = "Delivery history cleared successfully.";
                $messageType = "success";
            }
        }


        /* ====================================================
           CLEAR DISPENSING HISTORY — PASSWORD PROTECTED
        ==================================================== */
        if ($action === 'clear_dispensing_history') {
            $clearPassword = $_POST['clear_password'] ?? '';
            $currentUser = findUserById($_SESSION['user_id'] ?? 0);

            if (!$currentUser || !password_verify($clearPassword, $currentUser['password_hash'])) {
                $message = "Incorrect password. Dispensing history was not cleared.";
                $messageType = "danger";
            } else {
                db()->exec("DELETE FROM dispense_logs");
                $message = "Dispensing history cleared successfully.";
                $messageType = "success";
            }
        }



        /* ====================================================
           ADD MEDICINE
        ==================================================== */

        if ($action === 'add_medicine') {

            $medicineData = [
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
            ];

            if ($medicineData['inventory_name'] === '') {
                $message = "Medicine name is required.";
                $messageType = "danger";
            } else {
                // IMPORTANT: every batch remains a separate inventory row.
                insertMedicine(generateMedicineKey(), $medicineData);

                $message = "Medicine successfully added to inventory.";
                $messageType = "success";
            }
        }


        /* ====================================================
           EDIT MEDICINE
        ==================================================== */

        elseif ($action === 'edit_medicine') {

            $key = $_POST['medicine_key'] ?? '';
            $returnTab = $_POST['return_tab'] ?? 'products';
            $returnTab = in_array($returnTab, ['products', 'delivery'], true) ? $returnTab : 'products';
            $existing = fetchMedicine($key);

            if ($existing) {

                $updatedMedicine = [
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
                ];

                updateMedicine($key, $updatedMedicine);
                $updatedRow = fetchMedicine($key);
                if ($updatedRow) {
                    syncDeliveryLogsForMedicine($key, $updatedRow);
                }

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

        elseif ($action === 'receive_delivery') {

            $sku = trim($_POST['delivery_sku'] ?? '');
            $deliveryData = [
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
            ];

            if ($deliveryData['inventory_name'] === '') {
                $message = "Medicine name is required."; $messageType = "danger";
            } elseif ($deliveryData['quantity'] < 1) {
                $message = "Delivery quantity must be at least 1."; $messageType = "danger";
            } else {
                $pdo = db(); $pdo->beginTransaction();
                try {
                    $medicine = $sku !== '' ? fetchMedicine($sku) : null;
                    if ($medicine) {
                        updateMedicine($sku, [
                            'inventory_name' => $deliveryData['inventory_name'],
                            'strength' => $deliveryData['strength'], 'unit' => $deliveryData['unit'],
                            'dosage_form' => $deliveryData['dosage_form'], 'generic_name' => $deliveryData['generic_name'],
                            'quantity' => intval($medicine['quantity']) + $deliveryData['quantity'],
                            'batch_number' => $deliveryData['batch_number'], 'expiration_date' => $deliveryData['expiration_date'],
                            'category' => $deliveryData['category'], 'low_stock_threshold' => $deliveryData['low_stock_threshold']
                        ]);
                        $medicine = fetchMedicine($sku);
                    } else {
                        $newSku = generateMedicineKey(); insertMedicine($newSku, $deliveryData); $medicine = fetchMedicine($newSku);
                    }
                    insertDeliveryLog(date('Y-m-d'), $medicine, $deliveryData['quantity']);
                    $pdo->commit();
                    $message = "Delivery recorded: +" . number_format($deliveryData['quantity']) . " unit(s) of " . medicineFullName($medicine) . " added to current stock.";
                    $messageType = "success";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack(); throw $e;
                }
            }
        }

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
           STOCK OUT / DISPENSE  (single item — kept for
           backward compatibility, no longer used by the UI)
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


        /* ====================================================
           STOCK OUT / DISPENSE — BATCH (multi-medicine list)
           Lets the user add several medicines to a list first,
           then process/confirm the whole dispense transaction
           at once. All-or-nothing: if any line item fails
           validation, nothing is dispensed and the specific
           problem(s) are reported back.
        ==================================================== */

        elseif ($action === 'stock_out_batch') {

            $recipient = trim($_POST['recipient'] ?? '');
            $itemsJson = $_POST['items_json'] ?? '[]';
            $items = json_decode($itemsJson, true);

            if ($recipient === '') {

                $message = "Patient / Recipient is required.";
                $messageType = "danger";

            } elseif (!is_array($items) || count($items) === 0) {

                $message = "Please add at least one medicine to the list before confirming dispense.";
                $messageType = "danger";

            } else {

                processExpiredMedicinesDb();

                $pdo = db();
                $errors = [];
                $dispensedSummaries = [];

                $pdo->beginTransaction();

                try {

                    foreach ($items as $item) {

                        $groupKey = trim((string)($item['medicine_key'] ?? ''));
                        $qtyOut = intval($item['qty_out'] ?? 0);

                        if ($groupKey === '' || $qtyOut < 1) {
                            $errors[] = "Invalid medicine or quantity.";
                            continue;
                        }

                        // Get all inventory rows and find the same medicine identity.
                        $allRows = fetchAllMedicines();
                        $matches = [];

                        foreach ($allRows as $row) {

                            $rowGroupKey = strtolower(implode('|', [
                                trim((string)($row['inventory_name'] ?? '')),
                                trim((string)($row['strength'] ?? '')),
                                trim((string)($row['unit'] ?? '')),
                                trim((string)($row['dosage_form'] ?? '')),
                                trim((string)($row['generic_name'] ?? '')),
                                trim((string)($row['category'] ?? ''))
                            ]));

                            if ($rowGroupKey !== $groupKey) {
                                continue;
                            }

                            $expiration = $row['expiration_date'] ?? '';
                            $expired = false;

                            if ($expiration !== '') {
                                $expirationTs = strtotime($expiration);
                                $expired = $expirationTs !== false && $expirationTs <= strtotime(date('Y-m-d'));
                            }

                            // Expired inventory stays visible but cannot be dispensed.
                            if (!$expired && intval($row['quantity']) > 0) {
                                $matches[] = $row;
                            }
                        }

                        if (!$matches) {
                            $errors[] = "No available unexpired stock was found for the selected medicine.";
                            continue;
                        }

                        $totalAvailable = 0;
                        foreach ($matches as $row) {
                            $totalAvailable += intval($row['quantity']);
                        }

                        if ($qtyOut > $totalAvailable) {
                            $errors[] =
                                medicineFullName($matches[0]) .
                                ": not enough stock (available " .
                                $totalAvailable .
                                ", requested " .
                                $qtyOut .
                                ").";
                            continue;
                        }

                        /*
                         * FEFO:
                         * consume the earliest-expiring batch first,
                         * while keeping all inventory rows separate.
                         */
                        usort($matches, function ($a, $b) {
                            return strcmp(
                                $a['expiration_date'] ?: '9999-12-31',
                                $b['expiration_date'] ?: '9999-12-31'
                            );
                        });

                        $remaining = $qtyOut;

                        foreach ($matches as $row) {

                            if ($remaining <= 0) {
                                break;
                            }

                            $locked = fetchMedicine($row['sku']);

                            if (!$locked || intval($locked['quantity']) <= 0) {
                                continue;
                            }

                            $take = min($remaining, intval($locked['quantity']));

                            decrementMedicineStock($locked['sku'], $take);

                            insertDispenseLog(
                                date('Y-m-d'),
                                medicineFullName($locked),
                                $locked['batch_number'] ?? '',
                                $take,
                                $recipient
                            );

                            $remaining -= $take;
                        }

                        if ($remaining > 0) {
                            $errors[] = "Dispensing could not be completed for " . medicineFullName($matches[0]) . ".";
                        } else {
                            $dispensedSummaries[] =
                                $qtyOut . ' unit(s) of ' . medicineFullName($matches[0]);
                        }
                    }

                    if (!empty($errors)) {

                        $pdo->rollBack();

                        $message = "Dispense cancelled — " . implode(' ', $errors);
                        $messageType = "danger";

                    } else {

                        $pdo->commit();

                        $message =
                            "Successfully dispensed to " .
                            $recipient .
                            ": " .
                            implode(', ', $dispensedSummaries) .
                            ".";

                        $messageType = "success";
                    }

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = "Dispense failed: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        }

        /* ====================================================
           UPDATE ACCOUNT (username / password)
        ==================================================== */

        elseif ($action === 'update_account') {

            $currentUser = findUserById($_SESSION['user_id'] ?? 0);

            $newUsername = trim($_POST['new_username'] ?? '');
            $currentPasswordInput = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!$currentUser || !password_verify($currentPasswordInput, $currentUser['password_hash'])) {

                $message = "Current password is incorrect.";
                $messageType = "danger";

            } elseif ($newUsername === '') {

                $message = "Username cannot be empty.";
                $messageType = "danger";

            } elseif ($newPassword !== '' && strlen($newPassword) < 6) {

                $message = "New password must be at least 6 characters.";
                $messageType = "danger";

            } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {

                $message = "New password and confirmation do not match.";
                $messageType = "danger";

            } else {

                $existingUser = findUserByUsername($newUsername);

                if ($existingUser && intval($existingUser['id']) !== intval($currentUser['id'])) {

                    $message = "That username is already taken.";
                    $messageType = "danger";

                } else {

                    $newHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : null;
                    updateUserCredentials($currentUser['id'], $newUsername, $newHash);

                    $_SESSION['username'] = $newUsername;

                    $message = "Account settings updated successfully.";
                    $messageType = "success";
                }
            }
        }

        /*
         * IMPORTANT: redirect after every POST.
         * This is the fix for "refresh creates another duplicate".
         */
        $_SESSION['flash_message'] = $message ?? '';
        $_SESSION['flash_message_type'] = $messageType ?? 'success';

        $redirectTab = ($action === 'edit_medicine' && isset($returnTab))
            ? $returnTab
            : ($tabByAction[$action] ?? 'dashboard');
        $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');

        header('Location: ' . $baseUrl . '?tab=' . urlencode($redirectTab));
        exit;

    } catch (PDOException $e) {

        $_SESSION['flash_message'] = 'Database error: ' . $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';

        $redirectTab = $tabByAction[$action] ?? 'dashboard';
        $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');

        header('Location: ' . $baseUrl . '?tab=' . urlencode($redirectTab));
        exit;
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
   DELIVERY / STOCK-IN REPORT
============================================================ */
$todayDeliveryTotal = 0; $todayDeliveryByMedicine = [];
$selectedDeliveryMonth = intval($_GET['delivery_month'] ?? date('m'));
$selectedDeliveryYear = intval($_GET['delivery_year'] ?? date('Y'));
$monthlyDeliveryTotal = 0; $monthlyDeliveryByMedicine = [];
foreach ($deliveryLogs as $delivery) {
    $iso = $delivery['date_iso'] ?? ''; $ts = $iso !== '' ? strtotime($iso) : false;
    if ($ts === false) continue;
    $qty = intval($delivery['quantity_delivered'] ?? 0); $name = $delivery['inventory_name'] ?? 'Unknown';
    if (date('Y-m-d',$ts) === date('Y-m-d')) { $todayDeliveryTotal += $qty; $todayDeliveryByMedicine[$name] = ($todayDeliveryByMedicine[$name] ?? 0) + $qty; }
    if (intval(date('m',$ts)) === $selectedDeliveryMonth && intval(date('Y',$ts)) === $selectedDeliveryYear) { $monthlyDeliveryTotal += $qty; $monthlyDeliveryByMedicine[$name] = ($monthlyDeliveryByMedicine[$name] ?? 0) + $qty; }
}
arsort($todayDeliveryByMedicine); arsort($monthlyDeliveryByMedicine);

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
