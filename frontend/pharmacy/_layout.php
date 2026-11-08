<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/pharmacy_page.php';
$context = requirePharmacyPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'index' => ['Pharmacy dashboard', 'Review pharmacy activity, incoming prescriptions and outstanding visits.'],
    'prescriptions' => ['Incoming prescriptions', 'Review prescriptions written by general doctors and specialists.'],
    'pending' => ['Pending prescriptions', 'Check active prescriptions awaiting verification or dispensing.'],
    'patients' => ['Pharmacy patients', 'Find patients with prescription or pharmacy follow-up needs.'],
    'prescription-details' => ['Prescription details', 'Verify the clinical order and record pharmacy notes or exceptions.'],
    'dispensing' => ['Dispensing', 'Check stock, payment approval and record dispensed medicines.'],
    'inventory' => ['Medicine inventory', 'Review medicine stock, reorder levels and expiry dates.'],
    'stock-entry' => ['Record received stock', 'Add received medicine quantities with optional expiry and notes.'],
    'stock-alerts' => ['Stock alerts', 'Identify low-stock and expired medicines before dispensing.'],
    'payments' => ['Pharmacy charges and payments', 'Review consolidated bills, balances and recorded payment status.'],
    'history' => ['Prescription history', 'Search prior prescriptions and dispensing records.'],
    'reports' => ['Pharmacy reports', 'Review pharmacy activity, stock movement and outcomes.'],
];
if (!isset($pages[$pharmacyPage])) {
    http_response_code(404);
    exit('Pharmacy page not found.');
}
[$pageTitle, $pageDescription] = $pages[$pharmacyPage];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'P';
$isVisitWorkspace = in_array($pharmacyPage, ['index', 'dispensing', 'payments'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title><?= $escape($pageTitle) ?> | CareBridge</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/pharmacy.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/pharmacy.css') ?>">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/pharmacy.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/pharmacy.js') ?>" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page pharmacy-page"
      data-base-path="<?= $escape($basePath) ?>"
      data-pharmacy-page="<?= $escape($pharmacyPage) ?>"
      data-prescription-id="<?= $pharmacyPage === 'prescription-details' && isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? $escape($_GET['id']) : '' ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Pharmacy · <?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content pharmacy-content" aria-labelledby="pharmacy-heading">
                <div class="admin-welcome pharmacy-welcome">
                    <div><p class="eyebrow">CAREBRIDGE · PHARMACY</p><h1 id="pharmacy-heading"><?= $escape($pageTitle) ?></h1><p class="dashboard-subtitle"><?= $escape($pageDescription) ?></p></div>
                    <?php if (in_array($pharmacyPage, ['index', 'payments'], true)): ?>
                        <span class="pharmacy-demo-badge">CARD / MEDICAL AID SIMULATED</span>
                    <?php endif; ?>
                </div>
                <div class="admin-notice" data-pharmacy-message role="status" aria-live="polite" hidden></div>

                <?php if (in_array($pharmacyPage, ['index', 'reports'], true)): ?>
                    <section class="pharmacy-stats" aria-label="Pharmacy statistics">
                        <a href="pending.php"><span>Pending prescriptions</span><strong data-pharmacy-stat="pending">—</strong></a>
                        <a href="patients.php"><span>Visits awaiting pharmacy</span><strong data-pharmacy-stat="visits">—</strong></a>
                        <a href="stock-alerts.php"><span>Low-stock medicines</span><strong data-pharmacy-stat="low_stock">—</strong></a>
                        <a href="stock-alerts.php"><span>Expired medicines</span><strong data-pharmacy-stat="expired">—</strong></a>
                    </section>
                <?php endif; ?>

                <?php if ($isVisitWorkspace): ?>
                    <div class="pharmacy-layout">
                        <section class="admin-panel pharmacy-queue-panel" aria-labelledby="queue-heading">
                            <div class="admin-panel-heading pharmacy-panel-heading">
                                <div><h2 id="queue-heading"><?= $pharmacyPage === 'payments' ? 'Visits with pharmacy charges' : 'Awaiting pharmacy or payment' ?></h2><p>Patient ID, visit, prescriber, status and balance are loaded from existing clinical and billing records.</p></div>
                                <div class="pharmacy-search">
                                    <label for="pharmacy-search">Search patient, patient ID or visit ID</label>
                                    <div><input id="pharmacy-search" type="search" maxlength="100" placeholder="Search pharmacy visits"><button class="button button-outline button-small" type="button" data-search>Search</button></div>
                                </div>
                            </div>
                            <div class="pharmacy-queue" data-pharmacy-queue><p class="pharmacy-empty">Loading pharmacy queue…</p></div>
                        </section>
                        <section class="admin-panel pharmacy-visit-panel" data-visit-panel hidden>
                            <div class="admin-panel-heading">
                                <div><h2 data-visit-heading>Visit details</h2><p data-visit-subheading></p></div>
                                <span class="pharmacy-status" data-visit-status></span>
                            </div>
                            <div class="pharmacy-visit-content">
                                <section class="pharmacy-section" data-available-section hidden>
                                    <h3>Medication-only prescription</h3>
                                    <p>Select an active, undispensed prescription from an earlier visit for this patient. No ticket fee is added.</p>
                                    <div class="pharmacy-select-row"><label for="prescription-select">Existing prescription</label><select id="prescription-select" data-prescription-select></select><button class="button button-outline button-small" type="button" data-select-prescription>Select and bill</button></div>
                                </section>
                                <section class="pharmacy-section">
                                    <div class="pharmacy-section-heading"><h3>Prescription(s)</h3><span data-prescription-summary></span></div>
                                    <div class="pharmacy-prescriptions" data-prescriptions><p class="pharmacy-record-empty">No active prescription on this visit.</p></div>
                                </section>
                                <section class="pharmacy-section">
                                    <div class="pharmacy-section-heading"><h3>Consolidated bill</h3><span data-bill-number></span></div>
                                    <div class="pharmacy-bill-groups" data-bill-groups><p class="pharmacy-record-empty">No charges recorded.</p></div>
                                    <dl class="pharmacy-totals"><div><dt>Total</dt><dd data-bill-total>0.00</dd></div><div><dt>Approved payments</dt><dd data-approved-payments>0.00</dd></div><div class="pharmacy-balance"><dt>Balance due</dt><dd data-balance>0.00</dd></div></dl>
                                </section>
                                <section class="pharmacy-section"><h3>Payments and receipts</h3><div class="pharmacy-payments" data-payments><p class="pharmacy-record-empty">No payments recorded.</p></div></section>
                                <section class="pharmacy-section"><h3>Dispensing history</h3><div class="pharmacy-dispensings" data-dispensings><p class="pharmacy-record-empty">No dispensing records for this visit.</p></div></section>
                                <section class="pharmacy-section pharmacy-payment-section">
                                    <form class="pharmacy-payment-form" data-payment-form>
                                        <h3>Take payment</h3>
                                        <div class="pharmacy-form-grid">
                                            <label class="pharmacy-field"><span>Payment method</span><select name="payment_method" required><option value="cash">Cash</option><option value="card">Card (simulated)</option><option value="medical_aid">Medical aid (simulated)</option></select></label>
                                            <label class="pharmacy-field"><span>Amount</span><input name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required></label>
                                        </div>
                                        <div class="pharmacy-medical-aid-fields" data-medical-aid-fields hidden>
                                            <label class="pharmacy-field"><span>Saved membership</span><select name="saved_membership" data-membership-select><option value="">No saved memberships</option></select></label>
                                            <label class="pharmacy-field"><span>Scheme</span><select name="medical_aid_scheme_id" data-scheme-select></select></label>
                                            <label class="pharmacy-field"><span>Member number</span><input name="member_number" maxlength="100" type="text"></label>
                                            <label class="pharmacy-field"><span>Member name <small>(defaults to patient)</small></span><input name="member_name" maxlength="150" type="text"></label>
                                        </div>
                                        <button class="button button-primary" type="submit" data-take-payment>Record payment</button>
                                    </form>
                                    <div class="pharmacy-dispense">
                                        <label class="pharmacy-field"><span>Dispensing notes (optional)</span><input type="text" maxlength="5000" data-dispensing-notes></label>
                                        <p>Dispensing requires full approved payment, an active prescription, and sufficient unexpired stock.</p>
                                        <button class="button button-outline" type="button" data-dispense disabled>Verify and dispense</button>
                                    </div>
                                </section>
                            </div>
                        </section>
                    </div>
                <?php elseif (in_array($pharmacyPage, ['prescriptions', 'pending', 'patients', 'history'], true)): ?>
                    <section class="admin-panel pharmacy-list-panel">
                        <div class="admin-panel-heading pharmacy-panel-heading">
                            <div><h2><?= $escape($pageTitle) ?></h2><p>Prescription and patient details come from existing visits and clinician orders.</p></div>
                            <div class="pharmacy-search"><label for="pharmacy-record-search">Search patient, ID, visit or prescription</label><div><input id="pharmacy-record-search" type="search" maxlength="100" placeholder="Search records" data-activity-search><button class="button button-outline button-small" type="button" data-activity-refresh>Search</button></div></div>
                        </div>
                        <div class="table-wrap"><table class="admin-table pharmacy-record-table"><thead><tr><th>Prescription</th><th>Patient / ID</th><th>Visit</th><th>Prescriber</th><th>Date</th><th>Medicines</th><th>Status</th><th>Balance</th><th>Details</th></tr></thead><tbody data-prescription-rows><tr><td class="table-message" colspan="9">Loading prescriptions…</td></tr></tbody></table></div>
                    </section>
                <?php elseif ($pharmacyPage === 'prescription-details'): ?>
                    <p class="pharmacy-back-link"><a class="text-link" href="prescriptions.php">← Back to prescriptions</a></p>
                    <section class="admin-panel pharmacy-detail-panel"><div class="admin-panel-heading"><div><h2>Prescription and verification</h2><p>Prescribed instructions are read-only. Contact the prescriber for clinical corrections.</p></div></div><div class="pharmacy-prescription-detail" data-prescription-detail><p class="pharmacy-empty">Loading prescription…</p></div></section>
                    <section class="admin-panel pharmacy-detail-panel"><div class="admin-panel-heading"><div><h2>Record an unavailable-medicine or dispensing exception</h2><p>This records an exception for follow-up; it does not change the prescription or dose.</p></div></div><form class="pharmacy-exception-form" data-exception-form><label class="pharmacy-field"><span>Prescription item</span><select name="prescription_item_id" data-exception-items required></select></label><label class="pharmacy-field"><span>Reason / follow-up</span><textarea name="reason" maxlength="5000" required></textarea></label><button class="button button-primary" type="submit">Record exception</button></form><div class="pharmacy-exceptions" data-prescription-exceptions></div></section>
                <?php elseif (in_array($pharmacyPage, ['inventory', 'stock-alerts'], true)): ?>
                    <section class="admin-panel pharmacy-list-panel"><div class="admin-panel-heading pharmacy-panel-heading"><div><h2><?= $pharmacyPage === 'stock-alerts' ? 'Low-stock and expired medicines' : 'Medicine stock' ?></h2><p><?= $pharmacyPage === 'stock-alerts' ? 'Expired medicine is blocked from dispensing; low stock is compared with the configured reorder level.' : 'Expiry date is tracked at medicine level in this academic prototype.' ?></p></div><div class="pharmacy-search"><label for="inventory-search">Search medicine name or ID</label><div><input id="inventory-search" type="search" maxlength="100" placeholder="Search inventory" data-activity-search><button class="button button-outline button-small" type="button" data-activity-refresh>Search</button></div></div></div><div class="table-wrap"><table class="admin-table pharmacy-record-table"><thead><tr><th>Medicine</th><th>Medicine ID</th><th>Stock</th><th>Reorder level</th><th>Expiry</th><th>Unit price</th><th>Alert</th><th>Stock entry</th></tr></thead><tbody data-inventory-rows><tr><td class="table-message" colspan="8">Loading inventory…</td></tr></tbody></table></div></section>
                <?php elseif ($pharmacyPage === 'stock-entry'): ?>
                    <section class="admin-panel pharmacy-detail-panel"><div class="admin-panel-heading"><div><h2>Receive or adjust stock</h2><p>Every stock change is recorded with its user, reason and resulting quantity.</p></div></div><form class="pharmacy-stock-form" data-stock-form><label class="pharmacy-field"><span>Medicine</span><select name="medicine_id" data-stock-medicines required></select></label><label class="pharmacy-field"><span>Change type</span><select name="movement_type" required><option value="received">Received stock</option><option value="adjustment">Stock adjustment</option></select></label>                    <label class="pharmacy-field"><span>Quantity</span><input name="quantity" type="number" min="-9999999999.99" max="9999999999.99" step="0.01" required><small>For adjustments, use a positive number to add or a negative number to remove stock.</small></label><label class="pharmacy-field"><span>Expiry date (optional)</span><input name="expiry_date" type="date"></label><label class="pharmacy-field"><span>Reason / reference</span><input name="notes" type="text" maxlength="1000" required></label><button class="button button-primary" type="submit">Save stock movement</button></form></section>
                    <section class="admin-panel pharmacy-list-panel"><div class="admin-panel-heading"><div><h2>Recent stock movements</h2></div></div><div class="table-wrap"><table class="admin-table pharmacy-record-table"><thead><tr><th>Date</th><th>Medicine</th><th>Type</th><th>Change</th><th>Stock after</th><th>Recorded by</th><th>Notes</th></tr></thead><tbody data-stock-movements><tr><td class="table-message" colspan="7">Loading stock activity…</td></tr></tbody></table></div></section>
                <?php elseif ($pharmacyPage === 'reports'): ?>
                    <section class="admin-panel pharmacy-list-panel"><div class="admin-panel-heading"><div><h2>Recent dispensing and stock activity</h2></div><button class="button button-outline button-small" type="button" data-activity-refresh>Refresh</button></div><div class="table-wrap"><table class="admin-table pharmacy-record-table"><thead><tr><th>Date</th><th>Medicine</th><th>Activity</th><th>Quantity change</th><th>Stock after</th><th>Recorded by</th><th>Notes</th></tr></thead><tbody data-stock-movements><tr><td class="table-message" colspan="7">Loading report…</td></tr></tbody></table></div></section>
                <?php endif; ?>
                <p class="pharmacy-note">Card and medical-aid approvals are simulated for this academic prototype and are not real transactions. Prescriptions and doses are not edited by pharmacy staff.</p>
            </section>
        </main>
    </div>
</body>
</html>
