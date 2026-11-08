<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/testing_page.php';
$context = requireTestingPage('testing_desk');
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'dashboard' => [
        'Dashboard',
        'Testing Centre Desk',
        'Track test orders and open the next desk activity.',
        'all',
    ],
    'queue' => [
        'Queue',
        'Orders awaiting routing',
        'Review valid doctor and authorised workflow requests before directing patients.',
        'ordered',
    ],
    'route' => [
        'Route order',
        'Route a test order',
        'Confirm the existing request and direct the patient to its assigned department.',
        'ordered',
    ],
    'in-progress' => [
        'In progress',
        'Orders in progress',
        'Track orders accepted by Laboratory, Radiology, and Cardiac.',
        'in_progress',
    ],
    'completed' => [
        'Completed',
        'Completed orders',
        'Review completed investigations and the result status recorded by the technical department.',
        'completed',
    ],
    'details' => [
        'Order details',
        'Test order details',
        'Review the requested test, routing history, and results without changing clinical records.',
        'all',
    ],
    'history' => [
        'History',
        'Test order history',
        'Search existing test orders, including separate retest orders.',
        'all',
    ],
];
if (!isset($pages[$testingPage])) {
    http_response_code(404);
    exit('Testing Desk page not found.');
}
[$activePage, $pageTitle, $subtitle, $defaultStatus] = $pages[$testingPage];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'T';
$quickLinks = [
    ['queue.php', 'Awaiting routing', 'Review and route approved investigation orders.'],
    ['in-progress.php', 'In progress', 'Track patients accepted by a testing department.'],
    ['completed.php', 'Completed', 'View finished tests and status updates.'],
    ['history.php', 'Order history', 'Search orders and preserve retest history.'],
];
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
    <link rel="stylesheet" href="../assets/css/testing.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/testing.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page testing-page" data-base-path="<?= $escape($basePath) ?>"
      data-testing-scope="testing_desk"
      data-testing-page="<?= $escape($testingPage) ?>"
      data-default-status="<?= $escape($defaultStatus) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Testing Desk Clerk</span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content testing-content" aria-labelledby="testing-heading">
                <div class="admin-welcome testing-welcome">
                    <div><p class="eyebrow">TESTING CENTRE DESK</p><h1 id="testing-heading"><?= $escape($pageTitle) ?></h1><p class="dashboard-subtitle"><?= $escape($subtitle) ?></p></div>
                    <span class="testing-date" data-current-date></span>
                </div>
                <div class="admin-notice" data-testing-message role="status" aria-live="polite" hidden></div>

                <section class="testing-stats" aria-label="Test order totals">
                    <a href="queue.php"><span>Awaiting routing</span><strong data-count="ordered">—</strong></a>
                    <a href="in-progress.php"><span>In progress</span><strong data-count="in_progress">—</strong></a>
                    <a href="completed.php"><span>Completed</span><strong data-count="completed">—</strong></a>
                </section>

                <?php if ($testingPage === 'dashboard'): ?>
                    <section class="admin-panel testing-panel" aria-labelledby="quick-actions-heading">
                        <div class="admin-panel-heading"><div><h2 id="quick-actions-heading">Desk activities</h2><p>Technical departments perform tests and enter results; the desk only routes and tracks orders.</p></div></div>
                        <div class="testing-quick-links">
                            <?php foreach ($quickLinks as [$href, $label, $description]): ?>
                                <a href="<?= $escape($href) ?>"><strong><?= $escape($label) ?></strong><span><?= $escape($description) ?></span></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="admin-panel testing-panel" aria-labelledby="orders-heading">
                    <div class="admin-panel-heading">
                        <div><h2 id="orders-heading"><?= $testingPage === 'queue' || $testingPage === 'route' ? 'Valid orders awaiting routing' : $escape($pageTitle) ?></h2><p>Department is fixed by the approved test type. The desk cannot change requests or record clinical results.</p></div>
                        <div class="testing-filters">
                            <label><span class="visually-hidden">Search by patient, visit, or order</span><input type="search" maxlength="100" placeholder="Patient number, name, visit or order ID" data-order-search></label>
                            <?php if ($testingPage === 'dashboard'): ?>
                                <label><span class="visually-hidden">Filter by department</span><select data-department-filter><option value="">All departments</option><option value="laboratory">Laboratory</option><option value="radiology">Radiology</option><option value="cardiac">Cardiac</option></select></label>
                            <?php endif; ?>
                            <?php if ($testingPage === 'dashboard' || $testingPage === 'history' || $testingPage === 'details'): ?>
                                <label><span class="visually-hidden">Filter by order status</span><select data-status-filter><option value="all">All statuses</option><option value="ordered">Ordered</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></label>
                            <?php else: ?>
                                <select data-status-filter hidden aria-hidden="true"><option value="<?= $escape($defaultStatus) ?>" selected><?= $escape($defaultStatus) ?></option></select>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="testing-table-wrap">
                        <table class="testing-table">
                            <thead><tr><th>Order</th><th>Patient</th><th>Visit</th><th>Test</th><th>Department</th><th>Ordered by</th><th>Ordered</th><th>Status</th><th>Latest result</th><th>Action</th></tr></thead>
                            <tbody data-order-rows><tr><td class="table-message" colspan="10">Loading test orders…</td></tr></tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel testing-detail" data-order-detail hidden aria-labelledby="order-detail-heading">
                    <div class="admin-panel-heading"><div><p class="eyebrow">EXISTING TEST REQUEST</p><h2 id="order-detail-heading">Order details</h2><p data-order-summary></p></div><button class="button button-outline" type="button" data-close-detail>Close</button></div>
                    <dl class="testing-details" data-order-details></dl>
                    <div class="testing-actions testing-desk-actions" data-desk-actions></div>
                    <div class="testing-history"><h3>Results recorded by the testing department</h3><div data-result-history></div></div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
