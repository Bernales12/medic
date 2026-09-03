
<?php
/**
 * Rural Health Unit - Pharmacy & Inventory Management System
 * Single-file PHP/MySQL application.
 */

declare(strict_types=1);
session_start();

// Enable error reporting during development; set to 0 in production
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'rhu_pharmacy');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Establish PDO Database Connection
 */
function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            ensureSchema($pdo);
        } catch (PDOException $e) {
            die("<div style='padding:20px; font-family:sans-serif; background:#fee; border:1px solid #f99; color:#900;'>
                    <h3>Database Connection Error</h3>
                    <p>" . htmlspecialchars($e->getMessage()) . "</p>
                    <p>Please ensure MySQL is running and credentials in script are correct.</p>
                 </div>");
        }
    }
    return $pdo;
}

/**
 * Ensure Schema and Tables Exist
 */
function ensureSchema(PDO $pdo): void {
    // 1. Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    // 2. Create medicines table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS medicines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(50) NOT NULL UNIQUE,
            inventory_name VARCHAR(255) NOT NULL,
            strength VARCHAR(50) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            dosage_form VARCHAR(50) NOT NULL,
            generic_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            batch_number VARCHAR(100) NOT NULL,
            expiration_date DATE NOT NULL,
            category VARCHAR(100) NOT NULL,
            low_stock_threshold INT NOT NULL DEFAULT 10,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");

    // 3. Create dispense_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispense_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispense_date DATETIME NOT NULL,
            inventory_name VARCHAR(255) NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            qty_out INT NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");

    // NOTE: Ang auto-seeding logic para sa Paracetamol, Amoxicillin, at Cetirizine 
    // ay tinanggal na rito upang hindi na sila kusang bumalik kapag 0 na ang items.
}

/**
 * Helper: Flash Message Management
 */
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Initialize Database Connection
$pdo = getDBConnection();

// ==========================================
// REQUEST ROUTING & FORM PROCESSING
// ==========================================
$action = $_GET['action'] ?? 'inventory';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    // --- ADD MEDICINE ---
    if ($postAction === 'add_medicine') {
        $sku                = trim($_POST['sku'] ?? '');
        $inventory_name     = trim($_POST['inventory_name'] ?? '');
        $strength           = trim($_POST['strength'] ?? '');
        $unit               = trim($_POST['unit'] ?? '');
        $dosage_form        = trim($_POST['dosage_form'] ?? '');
        $generic_name       = trim($_POST['generic_name'] ?? '');
        $quantity           = (int)($_POST['quantity'] ?? 0);
        $batch_number       = trim($_POST['batch_number'] ?? '');
        $expiration_date    = trim($_POST['expiration_date'] ?? '');
        $category           = trim($_POST['category'] ?? '');
        $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 10);

        if ($sku === '' || $inventory_name === '' || $expiration_date === '') {
            setFlash('danger', 'Please fill in all required fields (SKU, Inventory Name, Expiration Date).');
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO medicines 
                        (sku, inventory_name, strength, unit, dosage_form, generic_name, quantity, batch_number, expiration_date, category, low_stock_threshold)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$sku, $inventory_name, $strength, $unit, $dosage_form, $generic_name, $quantity, $batch_number, $expiration_date, $category, $low_stock_threshold]);
                setFlash('success', 'Medicine added successfully!');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    setFlash('danger', 'Error: A medicine with this SKU already exists.');
                } else {
                    setFlash('danger', 'Database Error: ' . $e->getMessage());
                }
            }
        }
        header("Location: ?action=inventory");
        exit;
    }

    // --- EDIT MEDICINE ---
    if ($postAction === 'edit_medicine') {
        $id                 = (int)($_POST['id'] ?? 0);
        $sku                = trim($_POST['sku'] ?? '');
        $inventory_name     = trim($_POST['inventory_name'] ?? '');
        $strength           = trim($_POST['strength'] ?? '');
        $unit               = trim($_POST['unit'] ?? '');
        $dosage_form        = trim($_POST['dosage_form'] ?? '');
        $generic_name       = trim($_POST['generic_name'] ?? '');
        $quantity           = (int)($_POST['quantity'] ?? 0);
        $batch_number       = trim($_POST['batch_number'] ?? '');
        $expiration_date    = trim($_POST['expiration_date'] ?? '');
        $category           = trim($_POST['category'] ?? '');
        $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 10);

        if ($id <= 0 || $sku === '' || $inventory_name === '') {
            setFlash('danger', 'Invalid input data for medicine update.');
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE medicines SET 
                        sku = ?, inventory_name = ?, strength = ?, unit = ?, dosage_form = ?, 
                        generic_name = ?, quantity = ?, batch_number = ?, expiration_date = ?, 
                        category = ?, low_stock_threshold = ?
                    WHERE id = ?
                ");
                $stmt->execute([$sku, $inventory_name, $strength, $unit, $dosage_form, $generic_name, $quantity, $batch_number, $expiration_date, $category, $low_stock_threshold, $id]);
                setFlash('success', 'Medicine item updated successfully!');
            } catch (PDOException $e) {
                setFlash('danger', 'Failed to update medicine: ' . $e->getMessage());
            }
        }
        header("Location: ?action=inventory");
        exit;
    }

    // --- DELETE MEDICINE ---
    if ($postAction === 'delete_medicine') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('success', 'Medicine deleted successfully.');
        } else {
            setFlash('danger', 'Invalid item ID for deletion.');
        }
        header("Location: ?action=inventory");
        exit;
    }

    // --- DISPENSE MEDICINE ---
    if ($postAction === 'dispense_medicine') {
        $id        = (int)($_POST['medicine_id'] ?? 0);
        $qty_out   = (int)($_POST['qty_out'] ?? 0);
        $recipient = trim($_POST['recipient'] ?? 'Walk-in Patient');

        if ($id <= 0 || $qty_out <= 0) {
            setFlash('danger', 'Please specify a valid quantity to dispense.');
        } else {
            $stmt = $pdo->prepare("SELECT * FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            $med = $stmt->fetch();

            if (!$med) {
                setFlash('danger', 'Selected medicine not found.');
            } elseif ($med['quantity'] < $qty_out) {
                setFlash('danger', "Insufficient stock! Current stock: {$med['quantity']}, requested: {$qty_out}.");
            } else {
                $pdo->beginTransaction();
                try {
                    // Deduct inventory
                    $updateStmt = $pdo->prepare("UPDATE medicines SET quantity = quantity - ? WHERE id = ?");
                    $updateStmt->execute([$qty_out, $id]);

                    // Log dispensing event
                    $dispenseName = $med['inventory_name'] . ' ' . $med['strength'] . ' ' . $med['unit'] . ' ' . $med['dosage_form'];
                    $logStmt = $pdo->prepare("
                        INSERT INTO dispense_logs (dispense_date, inventory_name, batch_number, qty_out, recipient)
                        VALUES (NOW(), ?, ?, ?, ?)
                    ");
                    $logStmt->execute([trim($dispenseName), $med['batch_number'], $qty_out, $recipient]);

                    $pdo->commit();
                    setFlash('success', "Dispensed {$qty_out} unit(s) of " . htmlspecialchars($med['inventory_name']) . " successfully.");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('danger', 'Transaction failed: ' . $e->getMessage());
                }
            }
        }
        header("Location: ?action=inventory");
        exit;
    }
}

// Fetch dashboard alert metrics
$totalItems      = (int)$pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
$lowStockCount   = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE quantity <= low_stock_threshold AND quantity > 0")->fetchColumn();
$outOfStockCount = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE quantity = 0")->fetchColumn();
$expiredCount    = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE expiration_date <= CURDATE()")->fetchColumn();

// Fetch flash message if exists
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RHU Pharmacy & Inventory System</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-teal: #0d9488;
            --primary-dark: #0f766e;
            --bg-light: #f8fafc;
        }
        body {
            background-color: var(--bg-light);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .bg-teal {
            background-color: var(--primary-teal) !important;
        }
        .card-stat {
            border: none;
            border-radius: 10px;
            transition: transform 0.2s ease-in-out;
        }
        .card-stat:hover {
            transform: translateY(-3px);
        }
        .badge-expired {
            background-color: #ef4444;
        }
        .badge-low {
            background-color: #f59e0b;
        }
        .badge-ok {
            background-color: #10b981;
        }
        .table-responsive {
            background: #ffffff;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>

<!-- TOP NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-teal shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="?action=inventory">
            <i class="fa-solid fa-hospital-user me-2"></i>RHU Pharmacy Management
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $action === 'inventory' ? 'active fw-bold' : '' ?>" href="?action=inventory">
                        <i class="fa-solid fa-pills me-1"></i> Inventory Stock
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $action === 'logs' ? 'active fw-bold' : '' ?>" href="?action=logs">
                        <i class="fa-solid fa-clipboard-list me-1"></i> Dispense History Logs
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 my-4">

    <!-- DISPLAY ALERT FLASH MESSAGES -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i>
            <?= htmlspecialchars($flash['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- SUMMARY METRIC CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-md-3">
            <div class="card card-stat bg-white shadow-sm p-3 border-start border-4 border-primary">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold small">Total SKU Listed</span>
                        <h3 class="mb-0 fw-bold text-dark mt-1"><?= $totalItems ?></h3>
                    </div>
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-circle">
                        <i class="fa-solid fa-boxes-stacked fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="card card-stat bg-white shadow-sm p-3 border-start border-4 border-warning">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold small">Low Stock Items</span>
                        <h3 class="mb-0 fw-bold text-warning mt-1"><?= $lowStockCount ?></h3>
                    </div>
                    <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-circle">
                        <i class="fa-solid fa-triangle-exclamation fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="card card-stat bg-white shadow-sm p-3 border-start border-4 border-danger">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold small">Out of Stock</span>
                        <h3 class="mb-0 fw-bold text-danger mt-1"><?= $outOfStockCount ?></h3>
                    </div>
                    <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-circle">
                        <i class="fa-solid fa-cubes-stacked fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="card card-stat bg-white shadow-sm p-3 border-start border-4 border-dark">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold small">Expired Stock</span>
                        <h3 class="mb-0 fw-bold text-secondary mt-1"><?= $expiredCount ?></h3>
                    </div>
                    <div class="p-3 bg-secondary bg-opacity-10 text-secondary rounded-circle">
                        <i class="fa-solid fa-calendar-xmark fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN VIEW CONTENT -->
    <?php if ($action === 'inventory'): ?>
        
        <!-- INVENTORY TABLE SECTION -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                <h5 class="mb-0 fw-bold text-secondary">
                    <i class="fa-solid fa-tablets me-2 text-teal"></i>Medicine Inventory
                </h5>
                <button class="btn btn-teal text-white fw-semibold" data-bs-toggle="modal" data-bs-target="#addMedicineModal" style="background-color: var(--primary-teal);">
                    <i class="fa-solid fa-plus me-1"></i> Add New Medicine
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="inventoryTable" class="table table-hover align-middle w-100">
                        <thead class="table-light">
                            <tr>
                                <th>SKU</th>
                                <th>Inventory Name</th>
                                <th>Form / Strength</th>
                                <th>Generic Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Batch No.</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT * FROM medicines ORDER BY inventory_name ASC");
                            $today = date('Y-m-d');
                            while ($row = $stmt->fetch()):
                                $isExpired = ($row['expiration_date'] <= $today);
                                $isLow = ($row['quantity'] <= $row['low_stock_threshold'] && $row['quantity'] > 0);
                                $isOut = ($row['quantity'] == 0);
                            ?>
                            <tr>
                                <td class="fw-bold text-secondary"><?= htmlspecialchars($row['sku']) ?></td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($row['inventory_name']) ?></td>
                                <td><small class="text-muted"><?= htmlspecialchars($row['strength'] . ' ' . $row['unit'] . ' (' . $row['dosage_form'] . ')') ?></small></td>
                                <td><?= htmlspecialchars($row['generic_name']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['category']) ?></span></td>
                                <td class="fw-bold <?= $isOut ? 'text-danger' : ($isLow ? 'text-warning' : 'text-dark') ?>">
                                    <?= number_format($row['quantity']) ?>
                                </td>
                                <td><code class="text-dark"><?= htmlspecialchars($row['batch_number']) ?></code></td>
                                <td>
                                    <span class="<?= $isExpired ? 'text-danger fw-bold' : '' ?>">
                                        <?= htmlspecialchars($row['expiration_date']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isExpired): ?>
                                        <span class="badge badge-expired">Expired</span>
                                    <?php elseif ($isOut): ?>
                                        <span class="badge bg-danger">Out of Stock</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge badge-low">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge badge-ok">Sufficient</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-success btn-dispense" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#dispenseModal"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['inventory_name'] . ' ' . $row['strength'] . ' ' . $row['unit']) ?>"
                                                data-qty="<?= $row['quantity'] ?>"
                                                <?= ($row['quantity'] == 0 || $isExpired) ? 'disabled' : '' ?>
                                                title="Dispense Stock">
                                            <i class="fa-solid fa-hand-holding-medical"></i>
                                        </button>
                                        <button class="btn btn-outline-primary btn-edit" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editMedicineModal"
                                                data-json='<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>'
                                                title="Edit Item">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btn-delete" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#deleteMedicineModal"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['inventory_name']) ?>"
                                                title="Delete Item">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'logs'): ?>

        <!-- DISPENSE HISTORY LOGS -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-secondary">
                    <i class="fa-solid fa-history me-2 text-teal"></i>Dispensing Audit Logs
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="logsTable" class="table table-hover align-middle w-100">
                        <thead class="table-light">
                            <tr>
                                <th>Log ID</th>
                                <th>Date & Time</th>
                                <th>Medicine Item</th>
                                <th>Batch No.</th>
                                <th>Qty Dispensed</th>
                                <th>Recipient / Patient</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT * FROM dispense_logs ORDER BY dispense_date DESC");
                            while ($log = $stmt->fetch()):
                            ?>
                            <tr>
                                <td>#<?= sprintf('%05d', $log['id']) ?></td>
                                <td><?= htmlspecialchars($log['dispense_date']) ?></td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($log['inventory_name']) ?></td>
                                <td><code><?= htmlspecialchars($log['batch_number']) ?></code></td>
                                <td><span class="badge bg-info text-dark">+<?= $log['qty_out'] ?></span></td>
                                <td><?= htmlspecialchars($log['recipient']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- ==========================================
     MODALS SECTION
=========================================== -->

<!-- ADD MEDICINE MODAL -->
<div class="modal fade" id="addMedicineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="?action=inventory">
                <input type="hidden" name="form_action" value="add_medicine">
                <div class="modal-header bg-teal text-white" style="background-color: var(--primary-teal);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i>Add New Medicine Entry</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">SKU Code *</label>
                        <input type="text" name="sku" class="form-control" placeholder="e.g. M004" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Inventory Brand/Item Name *</label>
                        <input type="text" name="inventory_name" class="form-control" placeholder="e.g. Biogesic Paracetamol" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Strength</label>
                        <input type="text" name="strength" class="form-control" placeholder="e.g. 500">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Unit</label>
                        <input type="text" name="unit" class="form-control" placeholder="e.g. mg, ml">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Dosage Form</label>
                        <input type="text" name="dosage_form" class="form-control" placeholder="e.g. Tablet, Syrup">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Generic Name</label>
                        <input type="text" name="generic_name" class="form-control" placeholder="e.g. Paracetamol">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Category</label>
                        <input type="text" name="category" class="form-control" placeholder="e.g. Analgesics, Antibiotics">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Initial Stock Quantity</label>
                        <input type="number" name="quantity" class="form-control" value="0" min="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Batch Number</label>
                        <input type="text" name="batch_number" class="form-control" placeholder="e.g. BATCH-123">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Expiration Date *</label>
                        <input type="date" name="expiration_date" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Low Stock Alert Threshold</label>
                        <input type="number" name="low_stock_threshold" class="form-control" value="10" min="1">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal text-white" style="background-color: var(--primary-teal);">Save Medicine</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MEDICINE MODAL -->
<div class="modal fade" id="editMedicineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="?action=inventory">
                <input type="hidden" name="form_action" value="edit_medicine">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Medicine Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">SKU Code *</label>
                        <input type="text" name="sku" id="edit_sku" class="form-control" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Inventory Name *</label>
                        <input type="text" name="inventory_name" id="edit_inventory_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Strength</label>
                        <input type="text" name="strength" id="edit_strength" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Unit</label>
                        <input type="text" name="unit" id="edit_unit" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Dosage Form</label>
                        <input type="text" name="dosage_form" id="edit_dosage_form" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Generic Name</label>
                        <input type="text" name="generic_name" id="edit_generic_name" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Category</label>
                        <input type="text" name="category" id="edit_category" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Current Stock Quantity</label>
                        <input type="number" name="quantity" id="edit_quantity" class="form-control" min="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Batch Number</label>
                        <input type="text" name="batch_number" id="edit_batch_number" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Expiration Date *</label>
                        <input type="date" name="expiration_date" id="edit_expiration_date" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Low Stock Threshold</label>
                        <input type="number" name="low_stock_threshold" id="edit_low_stock_threshold" class="form-control" min="1">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DISPENSE MODAL -->
<div class="modal fade" id="dispenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?action=inventory">
                <input type="hidden" name="form_action" value="dispense_medicine">
                <input type="hidden" name="medicine_id" id="dispense_id">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-medical me-2"></i>Dispense Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-muted">Item Name</label>
                        <input type="text" id="dispense_name" class="form-control fw-bold" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted">Available Stock</label>
                        <input type="text" id="dispense_available" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity to Dispense *</label>
                        <input type="number" name="qty_out" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Recipient / Patient Name</label>
                        <input type="text" name="recipient" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Dispense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE CONFIRMATION MODAL -->
<div class="modal fade" id="deleteMedicineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?action=inventory">
                <input type="hidden" name="form_action" value="delete_medicine">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete <strong id="delete_name"></strong> from the inventory?</p>
                    <p class="text-danger small mb-0"><i class="fa-solid fa-info-circle me-1"></i>This action cannot be undone.</p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Medicine</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables
    if ($('#inventoryTable').length) {
        $('#inventoryTable').DataTable({
            pageLength: 10,
            order: [[1, 'asc']]
        });
    }
    if ($('#logsTable').length) {
        $('#logsTable').DataTable({
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }

    // Populate Edit Modal
    $(document).on('click', '.btn-edit', function() {
        const data = $(this).data('json');
        $('#edit_id').val(data.id);
        $('#edit_sku').val(data.sku);
        $('#edit_inventory_name').val(data.inventory_name);
        $('#edit_strength').val(data.strength);
        $('#edit_unit').val(data.unit);
        $('#edit_dosage_form').val(data.dosage_form);
        $('#edit_generic_name').val(data.generic_name);
        $('#edit_category').val(data.category);
        $('#edit_quantity').val(data.quantity);
        $('#edit_batch_number').val(data.batch_number);
        $('#edit_expiration_date').val(data.expiration_date);
        $('#edit_low_stock_threshold').val(data.low_stock_threshold);
    });

    // Populate Dispense Modal
    $(document).on('click', '.btn-dispense', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const qty = $(this).data('qty');
        $('#dispense_id').val(id);
        $('#dispense_name').val(name);
        $('#dispense_available').val(qty);
    });

    // Populate Delete Modal
    $(document).on('click', '.btn-delete', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        $('#delete_id').val(id);
        $('#delete_name').text(name);
    });
});
</script>
</body>
</html>
