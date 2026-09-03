/* ============================================================
   6-MONTH DISPENSING TREND
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

        if (
            intval(date('m', $t)) === $m &&
            intval(date('Y', $t)) === $y
        ) {
            $sum += intval($log['qty_out'] ?? 0);
        }
    }

    $trendData[] = $sum;
}


/* ============================================================
   MONTHLY DELIVERY TREND
============================================================ */

$deliveryTrendLabels = [];
$deliveryTrendData = [];

for ($i = 5; $i >= 0; $i--) {

    $ts = strtotime("-{$i} months", $todayTimestamp);
    $m = intval(date('m', $ts));
    $y = intval(date('Y', $ts));

    $deliveryTrendLabels[] = date('M Y', $ts);

    $sum = 0;

    foreach ($deliveryLogs as $delivery) {

        $isoDate = $delivery['date_iso'] ?? '';

        if (empty($isoDate)) {
            continue;
        }

        $t = strtotime($isoDate);

        if ($t === false) {
            continue;
        }

        if (
            intval(date('m', $t)) === $m &&
            intval(date('Y', $t)) === $y
        ) {
            $sum += intval($delivery['quantity_delivered'] ?? 0);
        }
    }

    $deliveryTrendData[] = $sum;
}


/* ============================================================
   MONTHLY DELIVERY MEDICINE SUMMARY
============================================================ */

$selectedDeliveryMedicineTotals = [];

foreach ($deliveryLogs as $delivery) {

    $isoDate = $delivery['date_iso'] ?? '';

    if (empty($isoDate)) {
        continue;
    }

    $timestamp = strtotime($isoDate);

    if ($timestamp === false) {
        continue;
    }

    if (
        intval(date('m', $timestamp)) === $selectedDeliveryMonth &&
        intval(date('Y', $timestamp)) === $selectedDeliveryYear
    ) {

        $name = medicineFullName($delivery);

        if ($name === '') {
            $name = $delivery['inventory_name'] ?? 'Unknown';
        }

        if (!isset($selectedDeliveryMedicineTotals[$name])) {
            $selectedDeliveryMedicineTotals[$name] = 0;
        }

        $selectedDeliveryMedicineTotals[$name] +=
            intval($delivery['quantity_delivered'] ?? 0);
    }
}

arsort($selectedDeliveryMedicineTotals);


/* ============================================================
   MONTH/YEAR OPTIONS
============================================================ */

$monthNames = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$currentYear = intval(date('Y'));

$yearOptions = [];

for ($y = $currentYear - 5; $y <= $currentYear + 2; $y++) {
    $yearOptions[] = $y;
}


/* ============================================================
   SAFE JSON FOR JAVASCRIPT
============================================================ */

$trendLabelsJson = json_encode(
    $trendLabels,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

$trendDataJson = json_encode(
    $trendData,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

$deliveryTrendLabelsJson = json_encode(
    $deliveryTrendLabels,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

$deliveryTrendDataJson = json_encode(
    $deliveryTrendData,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

$categoryLabelsJson = json_encode(
    array_keys($categoryCounts),
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

$categoryDataJson = json_encode(
    array_values($categoryCounts),
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);


/* ============================================================
   HTML
============================================================ */
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Pharmacy Inventory System</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
>

<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
    background: #f5f3fa;
    color: #29223d;
}

.navbar {
    background: linear-gradient(
        135deg,
        #2a1a4a,
        #4c1d95,
        #6d28d9
    );
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
}

.navbar-brand {
    font-weight: 800;
    color: white !important;
}

.nav-link {
    color: rgba(255,255,255,.85) !important;
    font-weight: 600;
}

.nav-link.active,
.nav-link:hover {
    color: white !important;
}

.container-main {
    max-width: 1400px;
    margin: auto;
    padding: 25px 15px 50px;
}

.dashboard-card {
    border: none;
    border-radius: 18px;
    background: white;
    padding: 22px;
    box-shadow: 0 8px 25px rgba(46,31,77,.08);
    height: 100%;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ede9fe;
    color: #6d28d9;
    font-size: 20px;
}

.stat-number {
    font-size: 28px;
    font-weight: 800;
}

.stat-label {
    color: #7c7391;
    font-size: 13px;
    font-weight: 600;
}

.section-card {
    background: white;
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 8px 25px rgba(46,31,77,.08);
}

.section-title {
    font-size: 19px;
    font-weight: 800;
    color: #3b2463;
}

.btn-purple {
    background: linear-gradient(
        135deg,
        #8b5cf6,
        #7c3aed
    );
    border: none;
    color: white;
}

.btn-purple:hover {
    color: white;
    background: linear-gradient(
        135deg,
        #7c3aed,
        #6d28d9
    );
}

.table {
    vertical-align: middle;
}

.table thead th {
    background: #4c1d95;
    color: white;
    white-space: nowrap;
}

.badge-low {
    background: #f59e0b;
}

.badge-expiring {
    background: #dc2626;
}

.badge-expired {
    background: #7f1d1d;
}

.badge-good {
    background: #16a34a;
}

.form-control:focus,
.form-select:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 .2rem rgba(124,58,237,.15);
}

.tab-pane {
    padding-top: 10px;
}

.search-box {
    max-width: 400px;
}

.chart-container {
    position: relative;
    height: 320px;
}

.empty-state {
    padding: 35px;
    text-align: center;
    color: #8a8298;
}

@media (max-width: 768px) {

    .container-main {
        padding: 15px 10px 40px;
    }

    .section-card {
        padding: 15px;
        border-radius: 14px;
    }

    .table-responsive {
        font-size: 13px;
    }

    .chart-container {
        height: 250px;
    }
}

</style>

</head>

<body>


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg">

<div class="container-fluid px-3">

<a
    class="navbar-brand"
    href="?tab=dashboard"
>
    <i class="fa-solid fa-pills me-2"></i>
    Pharmacy Inventory
</a>

<button
    class="navbar-toggler"
    type="button"
    data-bs-toggle="collapse"
    data-bs-target="#mainNav"
>
    <i class="fa-solid fa-bars text-white"></i>
</button>

<div
    class="collapse navbar-collapse"
    id="mainNav"
>

<ul class="navbar-nav me-auto">

<li class="nav-item">
<a
    class="nav-link <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>"
    href="?tab=dashboard"
>
    <i class="fa-solid fa-chart-line me-1"></i>
    Dashboard
</a>
</li>

<li class="nav-item">
<a
    class="nav-link <?php echo $activeTab === 'products' ? 'active' : ''; ?>"
    href="?tab=products"
>
    <i class="fa-solid fa-boxes-stacked me-1"></i>
    Inventory
</a>
</li>

<li class="nav-item">
<a
    class="nav-link <?php echo $activeTab === 'delivery' ? 'active' : ''; ?>"
    href="?tab=delivery"
>
    <i class="fa-solid fa-truck-medical me-1"></i>
    Delivery
</a>
</li>

<li class="nav-item">
<a
    class="nav-link <?php echo $activeTab === 'stockout' ? 'active' : ''; ?>"
    href="?tab=stockout"
>
    <i class="fa-solid fa-prescription-bottle-medical me-1"></i>
    Dispense
</a>
</li>

</ul>

<div class="text-white me-3">
    <i class="fa-solid fa-user me-1"></i>
    <?php echo h(currentUsername()); ?>
</div>

<a
    href="?logout=1"
    class="btn btn-sm btn-light"
>
    <i class="fa-solid fa-right-from-bracket me-1"></i>
    Logout
</a>

</div>
</div>

</nav>


<div class="container-main">


<?php if (!empty($dbError)): ?>

<div class="alert alert-danger">
    <strong>Database Error:</strong>
    <?php echo h($dbError); ?>
</div>

<?php endif; ?>


<?php if (!empty($message)): ?>

<div
    class="alert alert-<?php echo h($messageType ?: 'success'); ?> alert-dismissible fade show"
>
    <?php echo h($message); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<!-- =========================================================
     DASHBOARD
========================================================= -->

<?php if ($activeTab === 'dashboard'): ?>

<div class="d-flex justify-content-between align-items-center mb-4">

<div>
<h2 class="fw-bold mb-1">
    Pharmacy Dashboard
</h2>

<p class="text-muted mb-0">
    Medicine inventory overview
</p>
</div>

<a
    href="?tab=products"
    class="btn btn-purple"
>
    <i class="fa-solid fa-plus me-1"></i>
    Add Medicine
</a>

</div>


<div class="row g-3 mb-4">

<div class="col-6 col-lg-3">
<div class="dashboard-card">

<div class="d-flex justify-content-between">

<div>
<div class="stat-label">
Products
</div>

<div class="stat-number">
<?php echo number_format($totalProducts); ?>
</div>
</div>

<div class="stat-icon">
<i class="fa-solid fa-box"></i>
</div>

</div>

</div>
</div>


<div class="col-6 col-lg-3">
<div class="dashboard-card">

<div class="d-flex justify-content-between">

<div>
<div class="stat-label">
Stock In Hand
</div>

<div class="stat-number">
<?php echo number_format($totalStockInHand); ?>
</div>
</div>

<div class="stat-icon">
<i class="fa-solid fa-cubes"></i>
</div>

</div>

</div>
</div>


<div class="col-6 col-lg-3">
<div class="dashboard-card">

<div class="d-flex justify-content-between">

<div>
<div class="stat-label">
Low Stock
</div>

<div class="stat-number text-warning">
<?php echo number_format($lowStockCount); ?>
</div>
</div>

<div class="stat-icon">
<i class="fa-solid fa-triangle-exclamation"></i>
</div>

</div>

</div>
</div>


<div class="col-6 col-lg-3">
<div class="dashboard-card">

<div class="d-flex justify-content-between">

<div>
<div class="stat-label">
Expiring / Expired
</div>

<div class="stat-number text-danger">
<?php
echo number_format(
    $expiringSoonCount + $expiredCount
);
?>
</div>
</div>

<div class="stat-icon">
<i class="fa-solid fa-calendar-xmark"></i>
</div>

</div>

</div>
</div>

</div>


<div class="row g-4">

<div class="col-lg-8">

<div class="section-card">

<div class="section-title mb-3">
<i class="fa-solid fa-chart-line me-2"></i>
6-Month Dispensing Trend
</div>

<div class="chart-container">
<canvas id="dispenseChart"></canvas>
</div>

</div>

</div>


<div class="col-lg-4">

<div class="section-card">

<div class="section-title mb-3">
<i class="fa-solid fa-chart-pie me-2"></i>
Inventory by Category
</div>

<div class="chart-container">
<canvas id="categoryChart"></canvas>
</div>

</div>

</div>

</div>


<div class="row g-4">

<div class="col-lg-6">

<div class="section-card">

<div class="section-title mb-3">
<i class="fa-solid fa-truck-medical me-2"></i>
Today's Delivery
</div>

<h2 class="fw-bold">
<?php echo number_format($todayDeliveryTotal); ?>
</h2>

<p class="text-muted">
Total units received today
</p>

<?php if (empty($todayDeliveryByMedicine)): ?>

<div class="empty-state">
No deliveries recorded today.
</div>

<?php else: ?>

<ul class="list-group list-group-flush">

<?php foreach ($todayDeliveryByMedicine as $name => $qty): ?>

<li class="list-group-item d-flex justify-content-between">
<span><?php echo h($name); ?></span>
<strong><?php echo number_format($qty); ?></strong>
</li>

<?php endforeach; ?>

</ul>

<?php endif; ?>

</div>

</div>


<div class="col-lg-6">

<div class="section-card">

<div class="section-title mb-3">
<i class="fa-solid fa-arrow-right-from-bracket me-2"></i>
Monthly Dispensing
</div>

<h2 class="fw-bold">
<?php echo number_format($monthlyTotalOut); ?>
</h2>

<p class="text-muted">
<?php
echo h(
    $monthNames[$selectedMonth] .
    ' ' .
    $selectedYear
);
?>
</p>

<?php if (empty($monthlyMedicineTotals)): ?>

<div class="empty-state">
No dispensing records for this month.
</div>

<?php else: ?>

<ul class="list-group list-group-flush">

<?php foreach ($monthlyMedicineTotals as $name => $qty): ?>

<li class="list-group-item d-flex justify-content-between">
<span><?php echo h($name); ?></span>
<strong><?php echo number_format($qty); ?></strong>
</li>

<?php endforeach; ?>

</ul>

<?php endif; ?>

</div>

</div>

</div>


<?php endif; ?>


<!-- =========================================================
     INVENTORY
========================================================= -->

<?php if ($activeTab === 'products'): ?>

<div class="section-card">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

<div>

<h2 class="section-title mb-1">
<i class="fa-solid fa-boxes-stacked me-2"></i>
Medicine Inventory
</h2>

<p class="text-muted mb-0">
Manage medicine stock and expiration information.
</p>

</div>

<div class="d-flex gap-2">

<a
    href="?export=inventory"
    class="btn btn-success"
>
    <i class="fa-solid fa-file-excel me-1"></i>
    Export Excel
</a>

<button
    class="btn btn-purple"
    data-bs-toggle="modal"
    data-bs-target="#addMedicineModal"
>
    <i class="fa-solid fa-plus me-1"></i>
    Add Medicine
</button>

</div>

</div>


<input
    type="search"
    id="inventorySearch"
    class="form-control search-box mb-3"
    placeholder="Search medicine..."
>


<div class="table-responsive">

<table
    class="table table-bordered table-hover"
    id="inventoryTable"
>

<thead>

<tr>
<th>SKU</th>
<th>Medicine</th>
<th>Strength</th>
<th>Form</th>
<th>Generic Name</th>
<th>Category</th>
<th>Batch</th>
<th>Expiration</th>
<th>Quantity</th>
<th>Status</th>
<th>Action</th>
</tr>

</thead>

<tbody>

<?php if (empty($medicineInventory)): ?>

<tr>
<td colspan="11" class="empty-state">
No medicine in inventory.
</td>
</tr>

<?php else: ?>

<?php foreach ($medicineInventory as $med): ?>

<?php

$qty = intval($med['quantity'] ?? 0);
$threshold = getThreshold($med);

$expiration = $med['expiration_date'] ?? '';
$expTimestamp = !empty($expiration)
    ? strtotime($expiration)
    : false;

$status = 'Available';
$statusClass = 'badge-good';

if (
    $expTimestamp !== false &&
    $expTimestamp <= $todayTimestamp
) {
    $status = 'EXPIRED';
    $statusClass = 'badge-expired';
} elseif (
    $expTimestamp !== false &&
    $expTimestamp <= $expiryThreshold
) {
    $status = 'EXPIRING SOON';
    $statusClass = 'badge-expiring';
} elseif ($qty <= $threshold) {
    $status = 'LOW STOCK';
    $statusClass = 'badge-low';
}

?>

<tr class="inventory-row">

<td>
<?php echo h($med['sku']); ?>
</td>

<td>
<strong>
<?php echo h($med['inventory_name']); ?>
</strong>
</td>

<td>
<?php
echo h(
    trim(
        ($med['strength'] ?? '') .
        ' ' .
        ($med['unit'] ?? '')
    )
);
?>
</td>

<td>
<?php echo h($med['dosage_form']); ?>
</td>

<td>
<?php echo h($med['generic_name']); ?>
</td>

<td>
<?php echo h($med['category']); ?>
</td>

<td>
<?php echo h($med['batch_number']); ?>
</td>

<td>
<?php echo h($med['expiration_date'] ?? ''); ?>
</td>

<td>
<strong>
<?php echo number_format($qty); ?>
</strong>
</td>

<td>
<span class="badge <?php echo h($statusClass); ?>">
<?php echo h($status); ?>
</span>
</td>

<td>

<div class="d-flex gap-1">

<button
    type="button"
    class="btn btn-sm btn-outline-primary"
    onclick='editMedicine(<?php echo json_encode($med); ?>)'
>
    <i class="fa-solid fa-pen"></i>
</button>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="delete_medicine"
>

<input
    type="hidden"
    name="medicine_key"
    value="<?php echo h($med['sku']); ?>"
>

<button
    type="submit"
    class="btn btn-sm btn-outline-danger"
    onclick="return confirm('Delete this medicine from inventory?')"
>
    <i class="fa-solid fa-trash"></i>
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>


<!-- =========================================================
     ADD MEDICINE MODAL
========================================================= -->

<div
    class="modal fade"
    id="addMedicineModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title fw-bold">
<i class="fa-solid fa-plus me-2"></i>
Add Medicine
</h5>

<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>

</div>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="add_medicine"
>

<div class="modal-body">

<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Medicine Name
</label>

<input
    type="text"
    name="inventory_name"
    class="form-control"
    required
>

</div>

<div class="col-md-3">

<label class="form-label">
Strength
</label>

<input
    type="text"
    name="strength"
    class="form-control"
>

</div>

<div class="col-md-3">

<label class="form-label">
Unit
</label>

<input
    type="text"
    name="unit"
    class="form-control"
    placeholder="mg"
>

</div>

<div class="col-md-4">

<label class="form-label">
Dosage Form
</label>

<input
    type="text"
    name="dosage_form"
    class="form-control"
    placeholder="Tablet"
>

</div>

<div class="col-md-8">

<label class="form-label">
Generic Name
</label>

<input
    type="text"
    name="generic_name"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Category
</label>

<input
    type="text"
    name="category"
    class="form-control"
    placeholder="General"
>

</div>

<div class="col-md-4">

<label class="form-label">
Quantity
</label>

<input
    type="number"
    name="quantity"
    class="form-control"
    min="0"
    value="0"
>

</div>

<div class="col-md-4">

<label class="form-label">
Batch Number
</label>

<input
    type="text"
    name="batch_number"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Expiration Date
</label>

<input
    type="date"
    name="expiration_date"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Low Stock Threshold
</label>

<input
    type="number"
    name="low_stock_threshold"
    class="form-control"
    min="1"
    value="<?php echo h($DEFAULT_LOW_STOCK); ?>"
>

</div>

</div>

</div>

<div class="modal-footer">

<button
    type="button"
    class="btn btn-secondary"
    data-bs-dismiss="modal"
>
Cancel
</button>

<button
    type="submit"
    class="btn btn-purple"
>
<i class="fa-solid fa-save me-1"></i>
Save Medicine
</button>

</div>

</form>

</div>

</div>

</div>


<!-- =========================================================
     EDIT MEDICINE MODAL
========================================================= -->

<div
    class="modal fade"
    id="editMedicineModal"
    tabindex="-1"
>

<div class="modal-dialog modal-lg">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title fw-bold">
<i class="fa-solid fa-pen me-2"></i>
Edit Medicine
</h5>

<button
    type="button"
    class="btn-close"
    data-bs-dismiss="modal"
></button>

</div>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="edit_medicine"
>

<input
    type="hidden"
    name="medicine_key"
    id="edit_medicine_key"
>

<input
    type="hidden"
    name="return_tab"
    value="products"
>

<div class="modal-body">

<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Medicine Name
</label>

<input
    type="text"
    name="inventory_name"
    id="edit_inventory_name"
    class="form-control"
    required
>

</div>

<div class="col-md-3">

<label class="form-label">
Strength
</label>

<input
    type="text"
    name="strength"
    id="edit_strength"
    class="form-control"
>

</div>

<div class="col-md-3">

<label class="form-label">
Unit
</label>

<input
    type="text"
    name="unit"
    id="edit_unit"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Dosage Form
</label>

<input
    type="text"
    name="dosage_form"
    id="edit_dosage_form"
    class="form-control"
>

</div>

<div class="col-md-8">

<label class="form-label">
Generic Name
</label>

<input
    type="text"
    name="generic_name"
    id="edit_generic_name"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Category
</label>

<input
    type="text"
    name="category"
    id="edit_category"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Quantity
</label>

<input
    type="number"
    name="quantity"
    id="edit_quantity"
    class="form-control"
    min="0"
>

</div>

<div class="col-md-4">

<label class="form-label">
Batch Number
</label>

<input
    type="text"
    name="batch_number"
    id="edit_batch_number"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Expiration Date
</label>

<input
    type="date"
    name="expiration_date"
    id="edit_expiration_date"
    class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Low Stock Threshold
</label>

<input
    type="number"
    name="low_stock_threshold"
    id="edit_low_stock_threshold"
    class="form-control"
    min="1"
>

</div>

</div>

</div>

<div class="modal-footer">

<button
    type="button"
    class="btn btn-secondary"
    data-bs-dismiss="modal"
>
Cancel
</button>

<button
    type="submit"
    class="btn btn-purple"
>
<i class="fa-solid fa-save me-1"></i>
Update Medicine
</button>

</div>

</form>

</div>

</div>

</div>


<?php endif; ?>


<!-- =========================================================
     DELIVERY
========================================================= -->

<?php if ($activeTab === 'delivery'): ?>

<div class="section-card">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">

<div>

<h2 class="section-title">
<i class="fa-solid fa-truck-medical me-2"></i>
Delivery / Stock-In
</h2>

<p class="text-muted mb-0">
Receive medicine and automatically add it to current stock.
</p>

</div>

</div>


<form method="POST">

<input
    type="hidden"
    name="action"
    value="receive_delivery"
>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Existing Medicine SKU
</label>

<select
    name="delivery_sku"
    class="form-select"
>

<option value="">
New Medicine
</option>

<?php foreach ($medicineInventory as $med): ?>

<option value="<?php echo h($med['sku']); ?>">

<?php
echo h(
    $med['sku'] .
    ' - ' .
    medicineFullName($med)
);
?>

</option>

<?php endforeach; ?>

</select>

</div>


<div class="col-md-6">

<label class="form-label">
Medicine Name
</label>

<input
    type="text"
    name="inventory_name"
    class="form-control"
    required
>

</div>


<div class="col-md-3">

<label class="form-label">
Strength
</label>

<input
    type="text"
    name="strength"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label">
Unit
</label>

<input
    type="text"
    name="unit"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label">
Dosage Form
</label>

<input
    type="text"
    name="dosage_form"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label">
Category
</label>

<input
    type="text"
    name="category"
    class="form-control"
>

</div>


<div class="col-md-6">

<label class="form-label">
Generic Name
</label>

<input
    type="text"
    name="generic_name"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label">
Delivery Quantity
</label>

<input
    type="number"
    name="quantity"
    class="form-control"
    min="1"
    required
>

</div>


<div class="col-md-3">

<label class="form-label">
Batch Number
</label>

<input
    type="text"
    name="batch_number"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label">
Expiration Date
</label>

<input
    type="date"
    name="expiration_date"
    class="form-control"
>

</div>


<div class="col-md-3">

<label class="form-label">
Low Stock Threshold
</label>

<input
    type="number"
    name="low_stock_threshold"
    class="form-control"
    min="1"
    value="<?php echo h($DEFAULT_LOW_STOCK); ?>"
>

</div>

<div class="col-12">

<button
    type="submit"
    class="btn btn-purple"
>
<i class="fa-solid fa-truck-medical me-1"></i>
Record Delivery
</button>

</div>

</div>

</form>

</div>


<div class="section-card">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

<h3 class="section-title mb-0">
Delivery History
</h3>

<form
    method="GET"
    class="d-flex gap-2"
>

<input
    type="hidden"
    name="tab"
    value="delivery"
>

<select
    name="delivery_month"
    class="form-select"
>

<?php foreach ($monthNames as $num => $name): ?>

<option
    value="<?php echo $num; ?>"
    <?php echo $num === $selectedDeliveryMonth ? 'selected' : ''; ?>
>

<?php echo h($name); ?>

</option>

<?php endforeach; ?>

</select>


<select
    name="delivery_year"
    class="form-select"
>

<?php foreach ($yearOptions as $year): ?>

<option
    value="<?php echo $year; ?>"
    <?php echo $year === $selectedDeliveryYear ? 'selected' : ''; ?>
>

<?php echo $year; ?>

</option>

<?php endforeach; ?>

</select>

<button
    class="btn btn-secondary"
    type="submit"
>
View
</button>

<a
    class="btn btn-success"
    href="?export=delivery&month=<?php echo $selectedDeliveryMonth; ?>&year=<?php echo $selectedDeliveryYear; ?>"
>
<i class="fa-solid fa-file-excel me-1"></i>
Excel
</a>

</form>

</div>


<div class="alert alert-light border">

<strong>
<?php
echo h(
    $monthNames[$selectedDeliveryMonth] .
    ' ' .
    $selectedDeliveryYear
);
?>
</strong>

<br>

Total delivered:
<strong>
<?php echo number_format($monthlyDeliveryTotal); ?>
</strong>
unit(s)

</div>


<div class="table-responsive">

<table class="table table-bordered table-hover">

<thead>

<tr>
<th>Date</th>
<th>Medicine</th>
<th>Strength</th>
<th>Form</th>
<th>Batch</th>
<th>Expiration</th>
<th>Quantity</th>
</tr>

</thead>

<tbody>

<?php

$hasDeliveryRows = false;

foreach ($deliveryLogs as $delivery):

    $iso = $delivery['date_iso'] ?? '';
    $ts = $iso !== '' ? strtotime($iso) : false;

    if (
        $ts === false ||
        intval(date('m', $ts)) !== $selectedDeliveryMonth ||
        intval(date('Y', $ts)) !== $selectedDeliveryYear
    ) {
        continue;
    }

    $hasDeliveryRows = true;

?>

<tr>

<td>
<?php echo h($delivery['date']); ?>
</td>

<td>
<strong>
<?php echo h($delivery['inventory_name']); ?>
</strong>
</td>

<td>
<?php
echo h(
    trim(
        ($delivery['strength'] ?? '') .
        ' ' .
        ($delivery['unit'] ?? '')
    )
);
?>
</td>

<td>
<?php echo h($delivery['dosage_form']); ?>
</td>

<td>
<?php echo h($delivery['batch_number']); ?>
</td>

<td>
<?php echo h($delivery['expiration_date'] ?? ''); ?>
</td>

<td>
<strong>
<?php
echo number_format(
    intval($delivery['quantity_delivered'])
);
?>
</strong>
</td>

</tr>

<?php endforeach; ?>


<?php if (!$hasDeliveryRows): ?>

<tr>
<td colspan="7" class="empty-state">
No delivery records for this month.
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<?php endif; ?>


<!-- =========================================================
     STOCK OUT / DISPENSE
========================================================= -->

<?php if ($activeTab === 'stockout'): ?>

<div class="section-card">

<h2 class="section-title mb-1">

<i class="fa-solid fa-prescription-bottle-medical me-2"></i>

Dispense Medicine

</h2>

<p class="text-muted">
Select medicines and quantities to dispense.
</p>


<form
    method="POST"
    id="dispenseForm"
>

<input
    type="hidden"
    name="action"
    value="stock_out_batch"
>

<input
    type="hidden"
    name="items_json"
    id="items_json"
>


<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Patient / Recipient
</label>

<input
    type="text"
    name="recipient"
    id="recipient"
    class="form-control"
    required
>

</div>


<div class="col-md-6">

<label class="form-label">
Medicine
</label>

<select
    id="dispenseMedicine"
    class="form-select"
>

<option value="">
Select medicine
</option>

<?php foreach ($dispenseInventory as $group): ?>

<?php if (intval($group['quantity']) > 0): ?>

<option
    value="<?php echo h($group['group_key']); ?>"
    data-name="<?php echo h(medicineFullName($group)); ?>"
    data-stock="<?php echo intval($group['quantity']); ?>"
>

<?php
echo h(
    medicineFullName($group) .
    ' — Available: ' .
    number_format($group['quantity'])
);
?>

</option>

<?php endif; ?>

<?php endforeach; ?>

</select>

</div>


<div class="col-md-3">

<label class="form-label">
Quantity
</label>

<input
    type="number"
    id="dispenseQty"
    class="form-control"
    min="1"
    value="1"
>

</div>


<div class="col-md-3 d-flex align-items-end">

<button
    type="button"
    class="btn btn-purple w-100"
    id="addDispenseBtn"
>
<i class="fa-solid fa-plus me-1"></i>
Add
</button>

</div>

</div>


<hr class="my-4">


<h5 class="fw-bold">
Dispense List
</h5>


<div class="table-responsive">

<table class="table table-bordered">

<thead>

<tr>
<th>Medicine</th>
<th>Quantity</th>
<th>Action</th>
</tr>

</thead>

<tbody id="dispenseList">

<tr id="emptyDispenseRow">

<td
    colspan="3"
    class="empty-state"
>
No medicine added yet.
</td>

</tr>

</tbody>

</table>

</div>


<button
    type="submit"
    class="btn btn-success"
    id="confirmDispenseBtn"
    disabled
>

<i class="fa-solid fa-check me-1"></i>

Confirm Dispense

</button>

</form>

</div>


<div class="section-card">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">

<h3 class="section-title mb-0">
Dispensing History
</h3>

<form
    method="GET"
    class="d-flex gap-2"
>

<input
    type="hidden"
    name="tab"
    value="stockout"
>

<select
    name="report_month"
    class="form-select"
>

<?php foreach ($monthNames as $num => $name): ?>

<option
    value="<?php echo $num; ?>"
    <?php echo $num === $selectedMonth ? 'selected' : ''; ?>
>

<?php echo h($name); ?>

</option>

<?php endforeach; ?>

</select>


<select
    name="report_year"
    class="form-select"
>

<?php foreach ($yearOptions as $year): ?>

<option
    value="<?php echo $year; ?>"
    <?php echo $year === $selectedYear ? 'selected' : ''; ?>
>

<?php echo $year; ?>

</option>

<?php endforeach; ?>

</select>

<button
    class="btn btn-secondary"
    type="submit"
>
View
</button>

<a
    class="btn btn-success"
    href="?export=dispensing&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>"
>
<i class="fa-solid fa-file-excel me-1"></i>
Excel
</a>

</form>

</div>


<div class="alert alert-light border">

<strong>
<?php
echo h(
    $monthNames[$selectedMonth] .
    ' ' .
    $selectedYear
);
?>
</strong>

<br>

Total dispensed:
<strong>
<?php echo number_format($monthlyTotalOut); ?>
</strong>
unit(s)

</div>


<div class="table-responsive">

<table class="table table-bordered table-hover">

<thead>

<tr>
<th>Date</th>
<th>Medicine</th>
<th>Batch</th>
<th>Quantity</th>
<th>Recipient</th>
</tr>

</thead>

<tbody>

<?php if (empty($monthlyDispense)): ?>

<tr>
<td colspan="5" class="empty-state">
No dispensing records for this month.
</td>
</tr>

<?php else: ?>

<?php foreach ($monthlyDispense as $log): ?>

<tr>

<td>
<?php echo h($log['date']); ?>
</td>

<td>
<?php echo h($log['inventory_name']); ?>
</td>

<td>
<?php echo h($log['batch_number']); ?>
</td>

<td>
<strong>
<?php echo number_format($log['qty_out']); ?>
</strong>
</td>

<td>
<?php echo h($log['recipient']); ?>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<?php endif; ?>


</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/* ============================================================
   EDIT MEDICINE
============================================================ */

function editMedicine(medicine) {

    document.getElementById('edit_medicine_key').value =
        medicine.sku || '';

    document.getElementById('edit_inventory_name').value =
        medicine.inventory_name || '';

    document.getElementById('edit_strength').value =
        medicine.strength || '';

    document.getElementById('edit_unit').value =
        medicine.unit || '';

    document.getElementById('edit_dosage_form').value =
        medicine.dosage_form || '';

    document.getElementById('edit_generic_name').value =
        medicine.generic_name || '';

    document.getElementById('edit_category').value =
        medicine.category || '';

    document.getElementById('edit_quantity').value =
        medicine.quantity || 0;

    document.getElementById('edit_batch_number').value =
        medicine.batch_number || '';

    document.getElementById('edit_expiration_date').value =
        medicine.expiration_date || '';

    document.getElementById('edit_low_stock_threshold').value =
        medicine.low_stock_threshold || 200;

    var modal =
        new bootstrap.Modal(
            document.getElementById('editMedicineModal')
        );

    modal.show();
}


/* ============================================================
   INVENTORY SEARCH
============================================================ */

(function () {

    var search =
        document.getElementById('inventorySearch');

    if (!search) {
        return;
    }

    search.addEventListener('input', function () {

        var value =
            this.value.toLowerCase().trim();

        document
            .querySelectorAll('.inventory-row')
            .forEach(function (row) {

                row.style.display =
                    row.textContent
                        .toLowerCase()
                        .includes(value)
                        ? ''
                        : 'none';

            });

    });

})();


/* ============================================================
   DISPENSE LIST
============================================================ */

(function () {

    var select =
        document.getElementById('dispenseMedicine');

    var qtyInput =
        document.getElementById('dispenseQty');

    var addButton =
        document.getElementById('addDispenseBtn');

    var list =
        document.getElementById('dispenseList');

    var hidden =
        document.getElementById('items_json');

    var confirmButton =
        document.getElementById('confirmDispenseBtn');

    if (
        !select ||
        !qtyInput ||
        !addButton ||
        !list ||
        !hidden
    ) {
        return;
    }

    var items = [];


    function renderList() {

        list.innerHTML = '';

        if (items.length === 0) {

            list.innerHTML =
                '<tr>' +
                '<td colspan="3" class="empty-state">' +
                'No medicine added yet.' +
                '</td>' +
                '</tr>';

            confirmButton.disabled = true;

            hidden.value = '[]';

            return;
        }


        items.forEach(function (item, index) {

            var row =
                document.createElement('tr');

            row.innerHTML =
                '<td>' +
                    '<strong>' +
                        escapeHtml(item.name) +
                    '</strong>' +
                '</td>' +

                '<td>' +
                    item.qty +
                '</td>' +

                '<td>' +
                    '<button type="button" ' +
                    'class="btn btn-sm btn-outline-danger" ' +
                    'data-index="' + index + '">' +
                    '<i class="fa-solid fa-trash"></i>' +
                    '</button>' +
                '</td>';

            list.appendChild(row);

        });


        list
            .querySelectorAll('button[data-index]')
            .forEach(function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        var index =
                            parseInt(
                                this.dataset.index,
                                10
                            );

                        items.splice(index, 1);

                        renderList();

                    }
                );

            });


        hidden.value =
            JSON.stringify(items);

        confirmButton.disabled = false;

    }


    function escapeHtml(value) {

        var div =
            document.createElement('div');

        div.textContent = value;

        return div.innerHTML;

    }


    addButton.addEventListener(
        'click',
        function () {

            var selected =
                select.options[select.selectedIndex];

            if (
                !selected ||
                !selected.value
            ) {

                alert('Please select a medicine.');

                return;
            }


            var qty =
                parseInt(
                    qtyInput.value,
                    10
                );


            if (
                isNaN(qty) ||
                qty < 1
            ) {

                alert(
                    'Quantity must be at least 1.'
                );

                return;
            }


            var stock =
                parseInt(
                    selected.dataset.stock,
                    10
                );


            var existingIndex =
                items.findIndex(function (item) {

                    return item.medicine_key ===
                        selected.value;

                });


            var alreadyAdded = 0;


            if (existingIndex !== -1) {

                alreadyAdded =
                    items[existingIndex].qty;

            }


            if (
                qty + alreadyAdded >
                stock
            ) {

                alert(
                    'Not enough available stock. ' +
                    'Available: ' +
                    stock
                );

                return;
            }


            if (existingIndex !== -1) {

                items[existingIndex].qty += qty;

            } else {

                items.push({

                    medicine_key:
                        selected.value,

                    name:
                        selected.dataset.name,

                    qty:
                        qty

                });

            }


            renderList();

            select.value = '';

            qtyInput.value = 1;

        }
    );


    renderList();

})();


/* ============================================================
   DISPENSING CHART
============================================================ */

(function () {

    var canvas =
        document.getElementById('dispenseChart');

    if (!canvas) {
        return;
    }

    new Chart(canvas, {

        type: 'line',

        data: {

            labels:
                <?php echo $trendLabelsJson ?: '[]'; ?>,

            datasets: [{

                label: 'Units Dispensed',

                data:
                    <?php echo $trendDataJson ?: '[]'; ?>,

                borderWidth: 3,

                tension: .35,

                fill: false,

                pointRadius: 4

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: true
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    });

})();


/* ============================================================
   CATEGORY CHART
============================================================ */

(function () {

    var canvas =
        document.getElementById('categoryChart');

    if (!canvas) {
        return;
    }

    new Chart(canvas, {

        type: 'doughnut',

        data: {

            labels:
                <?php echo $categoryLabelsJson ?: '[]'; ?>,

            datasets: [{

                data:
                    <?php echo $categoryDataJson ?: '[]'; ?>,

                borderWidth: 1

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {

                    position: 'bottom'

                }

            }

        }

    });

})();

</script>

</body>
</html>
