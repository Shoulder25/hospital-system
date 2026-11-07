<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/testing_page.php';
$context = requireTestingPage('testing_desk');
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'T';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Testing Centre Desk | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/testing.css">
    <script src="../assets/js/testing.js" defer></script>
</head>
<body class="dashboard-page admin-page testing-page" data-base-path="<?= $escape($basePath) ?>" data-testing-scope="testing_desk">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">TESTING CENTRE</p>
            <nav class="side-nav" aria-label="Testing centre navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">⌂</span> Order routing</a>
                <a class="side-link" data-module-link data-roles="laboratory_technician,system_administrator" href="../laboratory/index.php" hidden><span aria-hidden="true">＋</span> Laboratory</a>
                <a class="side-link" data-module-link data-roles="radiographer,system_administrator" href="../radiology/index.php" hidden><span aria-hidden="true">＋</span> Radiology</a>
                <a class="side-link" data-module-link data-roles="cardiac_technician,system_administrator" href="../cardiac/index.php" hidden><span aria-hidden="true">＋</span> Cardiac</a>
            </nav>
            <div class="sidebar-bottom"><div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Route existing orders</strong><small>Test requests come from the nursing and doctor workflows.</small></div></div><span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span></div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar"><div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Testing Centre Desk</span></div><div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Testing Desk Clerk</span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div></header>
            <section class="dashboard-content admin-content testing-content" aria-labelledby="testing-heading">
                <div class="admin-welcome testing-welcome"><div><p class="eyebrow">TEST ORDER ROUTING</p><h1 id="testing-heading">Testing centre</h1><p class="dashboard-subtitle">Review valid orders, direct each patient to the listed department, and track progress.</p></div><span class="testing-date" data-current-date></span></div>
                <div class="admin-notice" data-testing-message role="status" aria-live="polite" hidden></div>
                <section class="testing-stats" aria-label="Order totals"><article><span>Awaiting routing</span><strong data-count="ordered">—</strong></article><article><span>In progress</span><strong data-count="in_progress">—</strong></article><article><span>Completed</span><strong data-count="completed">—</strong></article></section>
                <section class="admin-panel testing-panel" aria-labelledby="orders-heading">
                    <div class="admin-panel-heading"><div><h2 id="orders-heading">Valid test orders</h2><p>Department is determined by the approved test order and cannot be changed here.</p></div><div class="testing-filters"><label><span class="visually-hidden">Search test orders</span><input type="search" maxlength="100" placeholder="Patient, visit or order ID" data-order-search></label><label><span class="visually-hidden">Filter by department</span><select data-department-filter><option value="">All departments</option><option value="laboratory">Laboratory</option><option value="radiology">Radiology</option><option value="cardiac">Cardiac</option></select></label><label><span class="visually-hidden">Filter by status</span><select data-status-filter><option value="">All statuses</option><option value="ordered">Ordered</option><option value="in_progress">In progress</option><option value="completed">Completed</option></select></label></div></div>
                    <div class="testing-table-wrap"><table class="testing-table"><thead><tr><th>Order</th><th>Patient</th><th>Visit</th><th>Test</th><th>Department</th><th>Ordered</th><th>Progress</th><th>Result</th><th>Routing</th></tr></thead><tbody data-order-rows><tr><td class="table-message" colspan="9">Loading test orders…</td></tr></tbody></table></div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
