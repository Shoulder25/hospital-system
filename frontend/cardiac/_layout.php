<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/testing_page.php';
$context = requireTestingPage('cardiac');
$staff = $context['user'];
$basePath = $context['base_path'];
$cardiacPage = isset($cardiacPage) ? $cardiacPage : 'dashboard';
$pages = [
    'dashboard' => ['ECG dashboard', 'ECG statistics and recent examinations.'],
    'orders' => ['Incoming ECG orders', 'ECG orders routed to Cardiac by the Testing Centre.'],
    'pending' => ['Pending examinations', 'Routed ECG examinations awaiting technician attention.'],
    'schedule' => ['Examination scheduling', 'Scheduling availability for ECG examinations.'],
    'processing' => ['Examinations in progress', 'ECG examinations currently being processed.'],
    'record-results' => ['Record ECG findings', 'Enter technical findings and observations for an active ECG examination.'],
    'imaging' => ['ECG tracings and files', 'Upload, preview, open, zoom, and download ECG tracings and supporting files.'],
    'reports' => ['ECG reports', 'Save report drafts and submit completed ECG findings to the requesting doctor.'],
    'completed' => ['Completed examinations', 'Submitted ECG reports and completed examination records.'],
    'history' => ['ECG history', 'Previous ECG orders, examination attempts, reports, and attachments.'],
    'order-details' => ['ECG order details', 'Patient, visit, ordering doctor, examination, results, and history.'],
];
if (!isset($pages[$cardiacPage])) {
    http_response_code(404);
    exit('Cardiac page not found.');
}
$pageTitle = $pages[$cardiacPage][0];
$pageSubtitle = $pages[$cardiacPage][1];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'C';
$defaultStatus = $cardiacPage === 'history' || $cardiacPage === 'order-details'
    ? 'all'
    : '';
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
    <link rel="stylesheet" href="../assets/css/radiology.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <link rel="stylesheet" href="../assets/css/cardiac.css">
    <script src="../assets/js/radiology-image-viewer.js" defer></script>
    <script src="../assets/js/testing.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/testing.js') ?>" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page testing-page cardiac-page"
      data-base-path="<?= $escape($basePath) ?>"
      data-testing-scope="cardiac"
      data-testing-page="<?= $escape($cardiacPage) ?>"
      data-default-status="<?= $escape($defaultStatus) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions">
                    <span class="user-avatar"><?= $escape($initial) ?></span>
                    <div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Cardiac Technician</span></div>
                    <button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button>
                </div>
            </header>
            <section class="dashboard-content admin-content testing-content cardiac-content"
                     aria-labelledby="cardiac-heading">
                <div class="admin-welcome testing-welcome">
                    <div>
                        <p class="eyebrow">CARDIAC SERVICES</p>
                        <h1 id="cardiac-heading"><?= $escape($pageTitle) ?></h1>
                        <p class="dashboard-subtitle"><?= $escape($pageSubtitle) ?></p>
                    </div>
                    <span class="testing-date" data-current-date></span>
                </div>
                <div class="admin-notice" data-testing-message role="status" aria-live="polite" hidden></div>
                <section class="testing-stats" aria-label="ECG totals">
                    <a href="pending.php"><span>Ordered</span><strong data-count="ordered">—</strong></a>
                    <a href="processing.php"><span>In progress</span><strong data-count="in_progress">—</strong></a>
                    <a href="completed.php"><span>Completed</span><strong data-count="completed">—</strong></a>
                </section>

                <?php if ($cardiacPage === 'dashboard'): ?>
                    <section class="admin-panel testing-panel testing-quick-links" aria-label="Cardiac activities">
                        <a href="orders.php"><strong>Incoming ECG orders</strong><span>View ECG orders routed from the Testing Centre.</span></a>
                        <a href="record-results.php"><strong>Record findings</strong><span>Enter technical findings and observations.</span></a>
                        <a href="imaging.php"><strong>ECG tracings</strong><span>Manage files attached to an ECG examination.</span></a>
                        <a href="reports.php"><strong>ECG reports</strong><span>Save report drafts or submit results to the doctor.</span></a>
                    </section>
                <?php elseif ($cardiacPage === 'schedule'): ?>
                    <section class="admin-panel cardiac-scheduling-note">
                        <h2>Scheduling is not configured</h2>
                        <p>The current visit and test-order model does not include appointment slots for ECG examinations. Routed orders appear in Pending and are handled from the existing visit queue.</p>
                        <a class="button button-primary" href="pending.php">Open pending examinations</a>
                    </section>
                <?php endif; ?>

                <?php if ($cardiacPage === 'imaging'): ?>
                    <section class="admin-panel testing-panel radiology-gallery-panel" aria-labelledby="imaging-heading">
                        <div class="admin-panel-heading"><div><h2 id="imaging-heading">Uploaded ECG files</h2><p>Files are grouped with their patient, visit, and ECG order.</p></div></div>
                        <div class="radiology-gallery" data-cardiac-gallery><p class="testing-empty">Loading ECG files…</p></div>
                    </section>
                <?php endif; ?>

                <section class="admin-panel testing-panel" aria-labelledby="orders-heading">
                    <div class="admin-panel-heading">
                        <div><h2 id="orders-heading"><?= $cardiacPage === 'dashboard' ? 'Recent ECG examinations' : $escape($pageTitle) ?></h2><p>Only ECG orders routed by the Testing Centre are eligible for examination.</p></div>
                        <div class="testing-filters">
                            <label><span class="visually-hidden">Search ECG orders</span><input type="search" maxlength="100" placeholder="Patient, patient ID, visit or order ID" data-order-search></label>
                            <label><span class="visually-hidden">Filter by status</span><select data-status-filter><option value="">All statuses</option><option value="ordered">Ordered</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="all">Include cancelled</option></select></label>
                        </div>
                    </div>
                    <div class="testing-table-wrap">
                        <table class="testing-table">
                            <thead><tr><th>Order</th><th>Patient</th><th>Patient ID</th><th>Visit</th><th>Examination</th><th>Ordering doctor</th><th>Ordered</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody data-order-rows><tr><td class="table-message" colspan="9">Loading ECG orders…</td></tr></tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-panel testing-detail cardiac-detail" data-order-detail hidden aria-labelledby="order-detail-heading">
                    <div class="admin-panel-heading">
                        <div><p class="eyebrow">ORIGINAL TEST ORDER</p><h2 id="order-detail-heading">ECG examination details</h2><p data-order-summary></p></div>
                        <button class="button button-outline" type="button" data-close-detail>Close</button>
                    </div>
                    <dl class="testing-details" data-order-details></dl>
                    <section class="testing-history"><h3>Examination attempt history</h3><div data-cardiac-attempt-history></div></section>
                    <section class="testing-history"><h3>ECG tracings and supporting files</h3><div class="radiology-order-files" data-cardiac-files></div></section>
                    <form class="radiology-upload-form" data-cardiac-upload hidden>
                        <h3>Add ECG tracings or supporting files</h3>
                        <p>Accepted formats: JPG, JPEG, PNG, PDF. Maximum 15 MB per file and 10 files per upload. DICOM is not configured.</p>
                        <label><span>Select files</span><input type="file" name="files[]" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" multiple required></label>
                        <div class="radiology-upload-preview" data-upload-preview aria-live="polite"></div>
                        <button class="button button-primary" type="submit">Upload selected files</button>
                    </form>
                    <form class="cardiac-failure-form" data-cardiac-failure hidden>
                        <label><span>Reason the examination was unsuccessful <b aria-hidden="true">*</b></span><textarea name="reason" rows="3" maxlength="10000" required></textarea></label>
                        <button class="button button-outline" type="submit">Record unsuccessful examination</button>
                    </form>
                    <section class="testing-history"><h3>Report history</h3><div data-result-history></div></section>
                    <form class="testing-result-form" data-result-form hidden>
                        <p class="cardiac-technician-scope">Record technical findings and observations only. Diagnosis and prescribing are outside the technician role.</p>
                        <label><span>ECG findings and technical observations <b aria-hidden="true">*</b></span><textarea name="result_text" rows="5" maxlength="50000" required></textarea></label>
                        <label><span>Technical notes</span><textarea name="notes" rows="3" maxlength="10000"></textarea></label>
                        <label><span>Report status</span><select name="result_status" data-result-status><option value="preliminary">Save draft</option><option value="final">Submit report</option></select></label>
                        <div class="testing-actions"><button class="button button-primary" type="submit" data-save-result>Save report</button></div>
                    </form>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
