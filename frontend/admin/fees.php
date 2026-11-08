<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/admin_page.php';
$context = requireAdministratorPage();
$admin = $context['user'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $admin['display_name'], $initialMatch);
$adminInitial = $initialMatch[0] ?? 'A';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Fee management | CareBridge</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/fees.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/admin.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page" data-page="admin-fees">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">ADMINISTRATION</p>
            <nav class="side-nav" aria-label="Administration navigation">
                <a class="side-link" href="dashboard.php"><span aria-hidden="true">⌂</span> Overview</a>
                <a class="side-link" href="users.php"><span aria-hidden="true">♙</span> Staff accounts</a>
                <a class="side-link active" href="fees.php" aria-current="page"><span aria-hidden="true">$</span> Fee management</a>
                <a class="side-link" href="../visit-status.php"><span aria-hidden="true">◷</span> Visit status</a>
                <a class="side-link" href="../reception/index.php"><span aria-hidden="true">⌕</span> Reception desk</a>
                <a class="side-link" href="../nursing/index.php"><span aria-hidden="true">＋</span> Nursing</a>
                <a class="side-link" href="../doctor/index.php"><span aria-hidden="true">＋</span> General doctor</a>
                <a class="side-link" href="../emergency/index.php"><span aria-hidden="true">＋</span> Emergency</a>
                <a class="side-link" href="../testing-desk/index.php"><span aria-hidden="true">◷</span> Testing centre desk</a>
                <a class="side-link" href="../laboratory/index.php"><span aria-hidden="true">＋</span> Laboratory</a>
                <a class="side-link" href="../radiology/index.php"><span aria-hidden="true">＋</span> Radiology</a>
                <a class="side-link" href="../cardiac/index.php"><span aria-hidden="true">＋</span> Cardiac</a>
                <a class="side-link" href="../billing/index.php"><span aria-hidden="true">$</span> Cashier / Billing</a>
                <a class="side-link" href="../specialists/index.php"><span aria-hidden="true">＋</span> Specialists</a>
                <a class="side-link" href="../therapy/index.php"><span aria-hidden="true">＋</span> Therapy</a>
                <a class="side-link" href="../pharmacy/index.php"><span aria-hidden="true">＋</span> Pharmacy</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Fee configuration</strong><small>Deactivate historical services; do not delete fee records.</small></div></div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Administration / Fee management</span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($adminInitial) ?></span><div class="user-summary"><strong><?= $escape($admin['display_name']) ?></strong><span>System administrator</span></div><button class="button button-outline button-logout" type="button" data-admin-logout>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content fees-content" aria-labelledby="fees-heading">
                <div class="admin-welcome">
                    <div><p class="eyebrow">SERVICE CONFIGURATION</p><h1 id="fees-heading">Fee management</h1><p class="dashboard-subtitle">Manage active prices from the fee catalog. Existing bill-item amounts remain unchanged.</p></div>
                    <span class="demo-badge">ADMINISTRATOR ONLY</span>
                </div>
                <div class="admin-notice" data-admin-message role="status" aria-live="polite" hidden></div>
                <section class="admin-panel fee-editor-panel" aria-labelledby="fee-editor-heading">
                    <div class="admin-panel-heading"><div><h2 id="fee-editor-heading" data-fee-editor-heading>Add a service fee</h2><p>Workflow-managed fees remain controlled by Reception, Testing, Therapy, Pharmacy, Emergency and Referral workflows.</p></div></div>
                    <form class="staff-form fee-form" data-fee-form>
                        <input type="hidden" name="fee_id">
                        <div class="staff-form-field"><label for="fee-code">Fee code</label><input id="fee-code" name="fee_code" maxlength="60" pattern="[A-Z][A-Z0-9_]{1,59}" required><small>Unique, uppercase identifier. Codes for department-managed services cannot be cashier-added.</small></div>
                        <div class="staff-form-field"><label for="fee-description">Service name / description</label><input id="fee-description" name="description" maxlength="180" required></div>
                        <div class="staff-form-field"><label for="fee-amount">Configured amount</label><input id="fee-amount" name="amount" type="number" min="0" max="99999999.99" step="0.01" required></div>
                        <div class="staff-form-field fee-currency"><span>Currency</span><strong data-fee-currency>Catalog currency</strong></div>
                        <label class="fee-check"><input name="is_active" type="checkbox" checked> Active for new charges</label>
                        <label class="fee-check"><input name="cashier_addable" type="checkbox"> Cashier may add as a standalone service</label>
                        <div class="fee-form-actions"><button class="button button-primary" type="submit" data-fee-submit>Add service</button><button class="button button-outline" type="button" data-fee-cancel hidden>Cancel edit</button></div>
                    </form>
                </section>
                <section class="admin-panel fee-list-panel" aria-labelledby="fee-list-heading">
                    <div class="admin-panel-heading fee-list-heading">
                        <div><h2 id="fee-list-heading">Configured services</h2><p>Search all active and inactive fee codes. Historical entries are retained.</p></div>
                        <label class="search-field"><span aria-hidden="true">⌕</span><input type="search" maxlength="100" placeholder="Search code or name" data-fee-search></label>
                    </div>
                    <div class="table-wrap">
                        <table class="admin-table fee-table">
                            <thead><tr><th>Fee code</th><th>Service</th><th>Amount</th><th>Status</th><th>Cashier add</th><th>Last updated</th><th>Administrator</th><th>Action</th></tr></thead>
                            <tbody data-fee-rows><tr><td class="table-message" colspan="8">Loading configured services…</td></tr></tbody>
                        </table>
                    </div>
                    <p class="admin-footnote">Changing a fee only changes future charges. Existing bill items retain their recorded unit amount. Fees cannot be deleted; deactivate them instead.</p>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
