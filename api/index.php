
<?php
/**
 * Main Inventory & Dashboard Script
 */

// Error reporting for debugging (disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Helper function to sanitize output
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper function to display full medicine name
function medicineFullName($med) {
    $name = $med['name'] ?? 'Unknown Medicine';
    $dosage = !empty($med['dosage']) ? " ({$med['dosage']})" : '';
    return $name . $dosage;
}

// Dummy data for initialization if database is not connected
$expiredMedicines = $expiredMedicines ?? [];
$expiringMedicines = $expiringMedicines ?? [];
$expiringSoonCount = $expiringSoonCount ?? count($expiringMedicines);
$expiredCount = $expiredCount ?? count($expiredMedicines);
$availableCount = $availableCount ?? 0;
$lowStockCount = $lowStockCount ?? 0;
$trendLabels = $trendLabels ?? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
$trendData = $trendData ?? [12, 19, 3, 5, 2, 3];
$categoryCounts = $categoryCounts ?? ['Antibiotics' => 10, 'Analgesics' => 15, 'Vitamins' => 8];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Management Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card-custom { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .table-custom th { background-color: #f1f5f9; color: #475569; font-weight: 600; }
        .expired-row { background-color: #fef2f2; }
        .expiring-row { background-color: #fffbeb; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Main Content Area -->
        <div class="col-md-12 p-4">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="pane-dashboard">
                    <div class="row g-4">
                        
                        <!-- EXPIRED MEDICINES -->
                        <div class="col-lg-6">
                            <div class="card-custom p-4 h-100">
                                <div class="card-title-row">
                                    <h5><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Expired Medicines</h5>
                                    <span class="badge bg-danger"><?php echo $expiredCount; ?></span>
                                </div>

                                <?php if (empty($expiredMedicines)): ?>
                                    <div class="text-muted text-center py-4">No expired medicines found.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Medicine</th>
                                                    <th>Expiration</th>
                                                    <th>Stock</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($expiredMedicines as $med): ?>
                                                    <tr class="expired-row">
                                                        <td class="fw-bold"><?php echo h(medicineFullName($med)); ?></td>
                                                        <td><?php echo h($med['expiration_date'] ?? ''); ?></td>
                                                        <td><?php echo intval($med['quantity'] ?? 0); ?></td>
                                                        <td><span class="badge bg-danger">EXPIRED</span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- EXPIRING SOON -->
                        <div class="col-lg-6">
                            <div class="card-custom p-4 h-100">
                                <div class="card-title-row">
                                    <h5><i class="fa-solid fa-clock text-warning me-2"></i>Expiring Soon (30 Days)</h5>
                                    <span class="badge bg-warning text-dark"><?php echo $expiringSoonCount; ?></span>
                                </div>

                                <?php if (empty($expiringMedicines)): ?>
                                    <div class="text-muted text-center py-4">No medicines expiring soon.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-custom">
                                            <thead>
                                                <tr>
                                                    <th>Medicine</th>
                                                    <th>Expiration</th>
                                                    <th>Stock</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($expiringMedicines as $med): ?>
                                                    <tr class="expiring-row">
                                                        <td class="fw-bold"><?php echo h(medicineFullName($med)); ?></td>
                                                        <td><?php echo h($med['expiration_date'] ?? ''); ?></td>
                                                        <td><?php echo intval($med['quantity'] ?? 0); ?></td>
                                                        <td><span class="badge bg-warning text-dark">EXPIRING SOON</span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div><!-- /.row -->

                    <!-- CHARTS ROW -->
                    <div class="row g-4 mt-2">
                        <div class="col-lg-6">
                            <div class="card-custom p-4">
                                <h5>Dispensing Trends</h5>
                                <div style="height: 250px;">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="card-custom p-4">
                                <h5>Categories</h5>
                                <div style="height: 250px;">
                                    <canvas id="categoryDonut"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="card-custom p-4">
                                <h5>Stock Status</h5>
                                <div style="height: 250px;">
                                    <canvas id="statusDonut"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /#pane-dashboard -->
            </div><!-- /.tab-content -->
        </div><!-- /.col-md-12 -->
    </div><!-- /.row -->
</div><!-- /.container-fluid -->

<script>
// Chart.js & UI Initialization
document.addEventListener('DOMContentLoaded', function () {
    // Mobile Menu Toggle logic (if present in DOM)
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebarPanel');
    const overlay = document.getElementById('sidebarOverlay');

    if (mobileBtn && sidebar && overlay) {
        mobileBtn.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // Trend Chart
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trendLabels); ?>,
                datasets: [{
                    label: 'Units Dispensed',
                    data: <?php echo json_encode($trendData); ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124, 58, 237, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }

    // Category Donut Chart
    const catCtx = document.getElementById('categoryDonut');
    if (catCtx) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($categoryCounts)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($categoryCounts)); ?>,
                    backgroundColor: ['#7c3aed', '#ec4899', '#3b82f6', '#10b981', '#f59e0b', '#6b7280']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }

    // Status Donut Chart
    const statusCtx = document.getElementById('statusDonut');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Available', 'Low Stock', 'Expiring Soon', 'Expired'],
                datasets: [{
                    data: [<?php echo "$availableCount, $lowStockCount, $expiringSoonCount, $expiredCount"; ?>],
                    backgroundColor: ['#16a34a', '#d97706', '#f59e0b', '#e11d48']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
