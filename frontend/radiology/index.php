<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/testing_page.php';
$context = requireTestingPage('radiology');
$staff = $context['user'];
$basePath = $context['base_path'];
$radiologyPage = isset($radiologyPage) ? $radiologyPage : 'orders';
$pageTitle = $radiologyPage === 'imaging' ? 'Radiology image management' : 'Radiology orders';
$pageSubtitle = $radiologyPage === 'imaging'
    ? 'Open, review, upload, and manage files for routed X-ray examinations.'
    : 'Review routed X-ray examinations, record report drafts, and submit findings.';
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'R';
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f8fa"><title><?= $escape($pageTitle) ?> | CareBridge</title><link rel="stylesheet" href="../assets/css/auth.css"><link rel="stylesheet" href="../assets/css/admin.css"><link rel="stylesheet" href="../assets/css/testing.css"><link rel="stylesheet" href="../assets/css/radiology.css"><link rel="stylesheet" href="../assets/css/staff-layout.css"><script src="../assets/js/radiology-image-viewer.js" defer></script><script src="../assets/js/testing.js" defer></script><script src="../assets/js/staff-sidebar.js" defer></script></head>
<body class="dashboard-page admin-page testing-page" data-base-path="<?= $escape($basePath) ?>" data-testing-scope="radiology" data-testing-page="<?= $escape($radiologyPage) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main"><header class="topbar"><div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div><div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Radiographer</span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div></header>
            <section class="dashboard-content admin-content testing-content" aria-labelledby="testing-heading"><div class="admin-welcome testing-welcome"><div><p class="eyebrow">RADIOLOGY SERVICES</p><h1 id="testing-heading"><?= $escape($pageTitle) ?></h1><p class="dashboard-subtitle"><?= $escape($pageSubtitle) ?></p></div><div class="radiology-page-actions"><span class="testing-date" data-current-date></span><a class="button button-outline" href="<?= $radiologyPage === 'imaging' ? 'index.php' : 'imaging.php' ?>"><?= $radiologyPage === 'imaging' ? 'Radiology orders' : 'Manage images' ?></a></div></div><div class="admin-notice" data-testing-message role="status" aria-live="polite" hidden></div>
                <section class="testing-stats" aria-label="Radiology totals"><article><span>Ordered</span><strong data-count="ordered">—</strong></article><article><span>In progress</span><strong data-count="in_progress">—</strong></article><article><span>Completed</span><strong data-count="completed">—</strong></article></section>
                <?php if ($radiologyPage === 'imaging'): ?>
                    <section class="admin-panel testing-panel radiology-gallery-panel" aria-labelledby="imaging-heading">
                        <div class="admin-panel-heading"><div><h2 id="imaging-heading">Uploaded radiology files</h2><p>Images and supporting documents are grouped with their patient, visit, and X-ray order.</p></div></div>
                        <div class="radiology-gallery" data-radiology-gallery><p class="testing-empty">Loading radiology files…</p></div>
                    </section>
                <?php endif; ?>
                <section class="admin-panel testing-panel" aria-labelledby="orders-heading"><div class="admin-panel-heading"><div><h2 id="orders-heading">Radiology orders</h2><p>Only X-ray orders are shown.</p></div><div class="testing-filters"><label><span class="visually-hidden">Search radiology orders</span><input type="search" maxlength="100" placeholder="Patient, visit or order ID" data-order-search></label><label><span class="visually-hidden">Filter by status</span><select data-status-filter><option value="">All statuses</option><option value="ordered">Ordered</option><option value="in_progress">In progress</option><option value="completed">Completed</option></select></label></div></div><div class="testing-table-wrap"><table class="testing-table"><thead><tr><th>Order</th><th>Patient</th><th>Visit</th><th>Examination</th><th>Ordered</th><th>Status</th><th>Action</th></tr></thead><tbody data-order-rows><tr><td class="table-message" colspan="7">Loading radiology orders…</td></tr></tbody></table></div></section>
                <section class="admin-panel testing-detail" data-order-detail hidden aria-labelledby="order-detail-heading"><div class="admin-panel-heading"><div><p class="eyebrow">ORIGINAL TEST ORDER</p><h2 id="order-detail-heading">X-ray details</h2><p data-order-summary></p></div><button class="button button-outline" type="button" data-close-detail>Close</button></div><dl class="testing-details" data-order-details></dl>
                    <section class="testing-history"><h3>Images and supporting documents</h3><div class="radiology-order-files" data-radiology-files></div></section>
                    <form class="radiology-upload-form" data-radiology-upload hidden>
                        <h3>Add images or supporting documents</h3>
                        <p>Accepted formats: JPG, JPEG, PNG, PDF. Maximum 15 MB per file and 10 files per upload.</p>
                        <label><span>Select files</span><input type="file" name="files[]" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" multiple required></label>
                        <div class="radiology-upload-preview" data-upload-preview aria-live="polite"></div>
                        <button class="button button-primary" type="submit">Upload selected files</button>
                    </form>
                    <div class="testing-history"><h3>Report history</h3><div data-result-history></div></div>
                    <form class="testing-result-form" data-result-form hidden>
                        <label><span>X-ray examination findings <b aria-hidden="true">*</b></span><textarea name="result_text" rows="5" maxlength="50000" required></textarea></label>
                        <label><span>Report notes</span><textarea name="notes" rows="3" maxlength="10000"></textarea></label>
                        <label><span>Report status</span><select name="result_status" data-result-status><option value="preliminary">Draft report</option><option value="final">Submit report</option></select></label>
                        <div class="testing-actions"><button class="button button-primary" type="submit" data-save-result>Save report</button></div>
                    </form>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
