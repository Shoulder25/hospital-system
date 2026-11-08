<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/testing_page.php';
$context = requireTestingPage('laboratory');
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'L';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f8fa">
    <title>Laboratory | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css"><link rel="stylesheet" href="../assets/css/admin.css"><link rel="stylesheet" href="../assets/css/testing.css"><link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/testing.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page testing-page" data-base-path="<?= $escape($basePath) ?>" data-testing-scope="laboratory">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar"><a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a><p class="sidebar-caption">LABORATORY</p><nav class="side-nav" aria-label="Laboratory navigation"><a class="side-link active" href="index.php"><span aria-hidden="true">⌂</span> Laboratory orders</a><a class="side-link" data-module-link data-roles="testing_desk_clerk,system_administrator" href="../testing-desk/index.php" hidden><span aria-hidden="true">◷</span> Testing desk</a><a class="side-link" data-module-link data-roles="radiographer,system_administrator" href="../radiology/index.php" hidden><span aria-hidden="true">＋</span> Radiology</a><a class="side-link" data-module-link data-roles="cardiac_technician,system_administrator" href="../cardiac/index.php" hidden><span aria-hidden="true">＋</span> Cardiac</a></nav><div class="sidebar-bottom"><div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Laboratory tests</strong><small>Blood, glucose, urine, pregnancy and pap smear.</small></div></div><span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span></div></aside>
        <main class="dashboard-main"><header class="topbar"><div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Laboratory</span></div><div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Laboratory Technician</span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div></header>
            <section class="dashboard-content admin-content testing-content" aria-labelledby="testing-heading"><div class="admin-welcome testing-welcome"><div><p class="eyebrow">LABORATORY SERVICES</p><h1 id="testing-heading">Laboratory orders</h1><p class="dashboard-subtitle">Process laboratory orders routed by the testing centre and record results against the original order.</p></div><span class="testing-date" data-current-date></span></div><div class="admin-notice" data-testing-message role="status" aria-live="polite" hidden></div>
                <section class="testing-stats" aria-label="Laboratory totals"><article><span>Ordered</span><strong data-count="ordered">—</strong></article><article><span>In progress</span><strong data-count="in_progress">—</strong></article><article><span>Completed</span><strong data-count="completed">—</strong></article></section>
                <section class="admin-panel testing-panel" aria-labelledby="orders-heading"><div class="admin-panel-heading"><div><h2 id="orders-heading">Laboratory test orders</h2><p>Only blood, glucose, urine, pregnancy and pap smear orders are shown.</p></div><div class="testing-filters"><label><span class="visually-hidden">Search laboratory orders</span><input type="search" maxlength="100" placeholder="Patient, visit or order ID" data-order-search></label><label><span class="visually-hidden">Filter by status</span><select data-status-filter><option value="">All statuses</option><option value="ordered">Ordered</option><option value="in_progress">In progress</option><option value="completed">Completed</option></select></label></div></div><div class="testing-table-wrap"><table class="testing-table"><thead><tr><th>Order</th><th>Patient</th><th>Visit</th><th>Test</th><th>Ordered</th><th>Status</th><th>Action</th></tr></thead><tbody data-order-rows><tr><td class="table-message" colspan="7">Loading laboratory orders…</td></tr></tbody></table></div></section>
                <section class="admin-panel testing-detail" data-order-detail hidden aria-labelledby="order-detail-heading"><div class="admin-panel-heading"><div><p class="eyebrow">ORIGINAL TEST ORDER</p><h2 id="order-detail-heading">Test details</h2><p data-order-summary></p></div><button class="button button-outline" type="button" data-close-detail>Close</button></div><dl class="testing-details" data-order-details></dl><div class="testing-history"><h3>Previous results for this test order</h3><div data-result-history></div></div><form class="testing-result-form" data-result-form hidden><label><span>Test result <b aria-hidden="true">*</b></span><textarea name="result_text" rows="5" maxlength="50000" required></textarea></label><label><span>Result notes</span><textarea name="notes" rows="3" maxlength="10000"></textarea></label><div class="testing-actions"><button class="button button-primary" type="submit" data-save-result>Save final result</button></div></form></section>
            </section>
        </main>
    </div>
</body>
</html>
