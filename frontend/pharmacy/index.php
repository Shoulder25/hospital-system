<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/pharmacy_page.php';
$context = requirePharmacyPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'P';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Pharmacy | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/pharmacy.css">
    <script src="../assets/js/pharmacy.js" defer></script>
</head>
<body class="dashboard-page admin-page pharmacy-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">PHARMACY</p>
            <nav class="side-nav" aria-label="Pharmacy navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">＋</span> Pharmacy queue</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Payment and dispensing</strong><small>Settle visit charges before dispensing prescribed medicines.</small></div></div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Pharmacy payment point</span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content pharmacy-content" aria-labelledby="pharmacy-heading">
                <div class="admin-welcome pharmacy-welcome">
                    <div><p class="eyebrow">PAYMENT AND DISPENSING</p><h1 id="pharmacy-heading">Pharmacy queue</h1><p class="dashboard-subtitle">Settle consolidated visit charges and dispense active prescriptions after approval.</p></div>
                    <span class="pharmacy-demo-badge">CARD / MEDICAL AID SIMULATED</span>
                </div>
                <div class="admin-notice" data-pharmacy-message role="status" aria-live="polite" hidden></div>
                <div class="pharmacy-layout">
                    <section class="admin-panel pharmacy-queue-panel" aria-labelledby="queue-heading">
                        <div class="admin-panel-heading pharmacy-panel-heading">
                            <div><h2 id="queue-heading">Awaiting pharmacy or payment</h2><p>Includes deferred charges and post-therapy visits.</p></div>
                            <div class="pharmacy-search">
                                <label for="pharmacy-search">Search patient number, name or visit ID</label>
                                <div><input id="pharmacy-search" type="search" maxlength="100" placeholder="Search queue"><button class="button button-outline button-small" type="button" data-search>Search</button></div>
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
                                <dl class="pharmacy-totals">
                                    <div><dt>Total</dt><dd data-bill-total>0.00</dd></div>
                                    <div><dt>Approved payments</dt><dd data-approved-payments>0.00</dd></div>
                                    <div class="pharmacy-balance"><dt>Balance due</dt><dd data-balance>0.00</dd></div>
                                </dl>
                            </section>
                            <section class="pharmacy-section">
                                <h3>Payments and receipts</h3>
                                <div class="pharmacy-payments" data-payments><p class="pharmacy-record-empty">No payments recorded.</p></div>
                            </section>
                            <section class="pharmacy-section pharmacy-payment-section">
                                <form class="pharmacy-payment-form" data-payment-form>
                                    <h3>Take payment</h3>
                                    <div class="pharmacy-form-grid">
                                        <label class="pharmacy-field"><span>Payment method</span><select name="payment_method" required><option value="cash">Cash</option><option value="card">Card (simulated)</option><option value="medical_aid">Medical aid (simulated)</option></select></label>
                                        <label class="pharmacy-field"><span>Amount</span><input name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required></label>
                                    </div>
                                    <div class="pharmacy-medical-aid-fields" data-medical-aid-fields hidden>
                                        <label class="pharmacy-field"><span>Scheme</span><select name="medical_aid_scheme_id" data-scheme-select></select></label>
                                        <label class="pharmacy-field"><span>Member number</span><input name="member_number" maxlength="100" type="text"></label>
                                        <label class="pharmacy-field"><span>Member name <small>(defaults to patient)</small></span><input name="member_name" maxlength="150" type="text"></label>
                                    </div>
                                    <button class="button button-primary" type="submit" data-take-payment>Record payment</button>
                                </form>
                                <div class="pharmacy-dispense">
                                    <p>Dispensing is enabled only after all bill charges are covered and an active prescription is available.</p>
                                    <button class="button button-outline" type="button" data-dispense disabled>Dispense medication</button>
                                </div>
                            </section>
                        </div>
                    </section>
                </div>
                <p class="pharmacy-note">Medication-only visits skip ticket payment and nursing; the medicine charge is billed and paid here (working assumption to confirm). Card and medical-aid approvals are simulated for this academic prototype.</p>
            </section>
        </main>
    </div>
</body>
</html>
