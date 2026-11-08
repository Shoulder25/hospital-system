<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/testing_page.php';
$context = requireTestingPage('laboratory');
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'dashboard' => [
        'Laboratory dashboard',
        'Laboratory services',
        'Review routed laboratory orders and continue specimen and result activities.',
        'all',
    ],
    'orders' => [
        'Incoming orders',
        'Incoming laboratory orders',
        'Review valid laboratory requests routed by the Testing Centre Desk.',
        'ordered',
    ],
    'pending' => [
        'Pending collection',
        'Orders awaiting specimen collection',
        'Receive a routed order to open its first specimen collection attempt.',
        'ordered',
    ],
    'specimens' => [
        'Specimens',
        'Specimen collection and tracking',
        'Record collection, rejection, and recollection while retaining the attempt history.',
        'in_progress',
    ],
    'processing' => [
        'Processing',
        'Tests in progress',
        'Track specimens marked as being processed by laboratory staff.',
        'in_progress',
    ],
    'results' => [
        'Results',
        'Laboratory results',
        'Save authored preliminary entries, final results, and later amendments.',
        'all',
    ],
    'completed' => [
        'Completed',
        'Completed laboratory orders',
        'Review completed investigations and all authored result entries.',
        'completed',
    ],
    'history' => [
        'History',
        'Laboratory order history',
        'Search existing orders, specimens, results, and separate retest orders.',
        'all',
    ],
    'details' => [
        'Order details',
        'Laboratory order details',
        'Review the original request, specimen attempts, and result history.',
        'all',
    ],
];
if (!isset($pages[$laboratoryPage])) {
    http_response_code(404);
    exit('Laboratory page not found.');
}
[$activePage, $pageTitle, $subtitle, $defaultStatus] = $pages[$laboratoryPage];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'L';
$quickLinks = [
    ['orders.php', 'Incoming orders', 'Review valid orders routed by the Testing Centre.'],
    ['specimens.php', 'Specimen tracking', 'Collect, reject, or recollect a specimen.'],
    ['processing.php', 'Processing', 'Track active laboratory processing.'],
    ['results.php', 'Results', 'Save drafts, final results, or supplemental entries.'],
    ['completed.php', 'Completed', 'Review completed orders and result history.'],
    ['history.php', 'Order history', 'Search orders and distinct retest records.'],
];
$testTypes = [
    'blood' => 'Blood',
    'glucose' => 'Glucose',
    'urine' => 'Urine',
    'pregnancy' => 'Pregnancy',
    'pap_smear' => 'Pap smear',
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
    <link rel="stylesheet" href="../assets/css/testing.css?v=20261108-laboratory-layout">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/testing.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page testing-page" data-base-path="<?= $escape($basePath) ?>"
      data-testing-scope="laboratory"
      data-testing-page="<?= $escape($laboratoryPage) ?>"
      data-default-status="<?= $escape($defaultStatus) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Laboratory Technician</span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content testing-content" aria-labelledby="testing-heading">
                <div class="admin-welcome testing-welcome">
                    <div><p class="eyebrow">LABORATORY SERVICES</p><h1 id="testing-heading"><?= $escape($pageTitle) ?></h1><p class="dashboard-subtitle"><?= $escape($subtitle) ?></p></div>
                    <span class="testing-date" data-current-date></span>
                </div>
                <div class="admin-notice" data-testing-message role="status" aria-live="polite" hidden></div>
                <section class="testing-stats" aria-label="Laboratory order totals">
                    <a href="pending.php"><span>Awaiting collection</span><strong data-count="ordered">—</strong></a>
                    <a href="processing.php"><span>In progress</span><strong data-count="in_progress">—</strong></a>
                    <a href="completed.php"><span>Completed</span><strong data-count="completed">—</strong></a>
                </section>

                <?php if ($laboratoryPage === 'dashboard'): ?>
                    <section class="admin-panel testing-panel" aria-labelledby="quick-actions-heading">
                        <div class="admin-panel-heading"><div><h2 id="quick-actions-heading">Laboratory activities</h2><p>Technicians record investigation results; doctors remain responsible for diagnosis.</p></div></div>
                        <div class="testing-quick-links">
                            <?php foreach ($quickLinks as [$href, $label, $description]): ?>
                                <a href="<?= $escape($href) ?>"><strong><?= $escape($label) ?></strong><span><?= $escape($description) ?></span></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="admin-panel testing-panel" aria-labelledby="orders-heading">
                    <div class="admin-panel-heading">
                        <div><h2 id="orders-heading"><?= $escape($pageTitle) ?></h2><p>Blood, glucose, urine, pregnancy, and pap smear orders routed by the Testing Centre.</p></div>
                        <div class="testing-filters">
                            <label><span class="visually-hidden">Search by patient, visit, or order</span><input type="search" maxlength="100" placeholder="Patient number, name, visit or order ID" data-order-search></label>
                            <label><span class="visually-hidden">Filter by test type</span><select data-test-type-filter><option value="">All laboratory tests</option><?php foreach ($testTypes as $type => $label): ?><option value="<?= $escape($type) ?>"><?= $escape($label) ?></option><?php endforeach; ?></select></label>
                            <?php if (in_array($laboratoryPage, ['dashboard', 'orders', 'history', 'details', 'results'], true)): ?>
                                <label><span class="visually-hidden">Filter by order status</span><select data-status-filter><option value="all">All statuses</option><option value="ordered">Ordered</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></label>
                            <?php else: ?>
                                <select data-status-filter hidden aria-hidden="true"><option value="<?= $escape($defaultStatus) ?>" selected><?= $escape($defaultStatus) ?></option></select>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="testing-table-wrap">
                        <table class="testing-table">
                            <thead><tr><th>Order</th><th>Patient</th><th>Patient number</th><th>Visit</th><th>Test</th><th>Ordering doctor</th><th>Ordered</th><th>Order status</th><th>Specimen status</th><th>Action</th></tr></thead>
                            <tbody data-order-rows><tr><td class="table-message" colspan="10">Loading laboratory orders…</td></tr></tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel testing-detail" data-order-detail hidden aria-labelledby="order-detail-heading">
                    <div class="admin-panel-heading"><div><p class="eyebrow">ORIGINAL TEST ORDER</p><h2 id="order-detail-heading">Order details</h2><p data-order-summary></p></div><button class="button button-outline" type="button" data-close-detail>Close</button></div>
                    <dl class="testing-details" data-order-details></dl>
                    <section class="testing-history" aria-labelledby="specimen-history-heading"><h3 id="specimen-history-heading">Specimen history</h3><div data-specimen-history></div></section>
                    <div class="testing-actions testing-desk-actions" data-specimen-actions></div>
                    <section class="testing-history"><h3>Authored result history</h3><div data-result-history></div></section>
                    <form class="testing-result-form" data-result-form hidden>
                        <label><span>Test result <b aria-hidden="true">*</b></span><textarea name="result_text" rows="5" maxlength="50000" required></textarea></label>
                        <label><span>Result notes</span><textarea name="notes" rows="3" maxlength="10000"></textarea></label>
                        <label data-result-status-wrap><span>Entry type</span><select name="result_status" data-result-status><option value="preliminary">Preliminary entry</option><option value="final">Final result</option><option value="amended">Additional result entry</option></select></label>
                        <div class="testing-actions"><button class="button button-primary" type="submit" data-save-result>Save result</button></div>
                    </form>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
