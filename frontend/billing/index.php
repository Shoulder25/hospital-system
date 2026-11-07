<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/billing_page.php';
$context = requireBillingPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'C';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Cashier | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/billing.css">
    <script src="../assets/js/billing.js" defer></script>
</head>
<body class="dashboard-page admin-page billing-page" data-base-path="<?= $escape($basePath) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">BILLING</p>
            <nav class="side-nav" aria-label="Cashier navigation">
                <a class="side-link active" href="index.php"><span aria-hidden="true">$</span> Ticket payments</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Ticket payment</strong><small>School appointments and referrals also pay the ticket fee.</small></div></div>
                <span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Cashier / Ticket payments</span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(str_replace('_', ' ', $staff['role_name'])) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content billing-content" aria-labelledby="billing-heading">
                <div class="admin-welcome billing-welcome">
                    <div><p class="eyebrow">ARRIVAL PAYMENT</p><h1 id="billing-heading">Ticket payment queue</h1><p class="dashboard-subtitle">Record the ticket fee to send eligible visits to Nursing. Fee amounts come from the demo fee catalogue.</p></div>
                    <span class="billing-demo-badge">DEMO FEE</span>
                </div>
                <div class="admin-notice" data-billing-message role="status" aria-live="polite" hidden></div>
                <section class="admin-panel billing-panel" aria-labelledby="queue-heading">
                    <div class="admin-panel-heading billing-panel-heading">
                        <div><h2 id="queue-heading">Awaiting ticket payment</h2><p>Emergency and medication-collection-only visits are excluded.</p></div>
                        <div class="billing-search">
                            <label for="billing-search">Search patient number, name or visit ID</label>
                            <div><input id="billing-search" type="search" maxlength="100" placeholder="Search queue"><button class="button button-outline button-small" type="button" data-search-button>Search</button></div>
                        </div>
                    </div>
                    <div class="billing-table-wrap">
                        <table class="admin-table billing-table">
                            <thead><tr><th scope="col">Patient</th><th scope="col">Visit</th><th scope="col">Source</th><th scope="col">Organization</th><th scope="col">Arrived</th><th scope="col">Ticket fee</th><th scope="col">Action</th></tr></thead>
                            <tbody data-billing-rows><tr><td class="table-message" colspan="7">Loading ticket-payment queue…</td></tr></tbody>
                        </table>
                    </div>
                </section>
                <section class="admin-panel billing-visit-panel" data-billing-visit hidden aria-labelledby="billing-visit-heading">
                    <div class="admin-panel-heading">
                        <div><p class="eyebrow">VISIT DETAILS</p><h2 id="billing-visit-heading">Ticket payment</h2><p data-billing-visit-summary></p></div>
                        <button class="button button-outline button-small" type="button" data-close-visit>Close</button>
                    </div>
                    <dl class="billing-visit-details">
                        <div><dt>Patient number</dt><dd data-visit-patient-number></dd></div>
                        <div><dt>Visit source</dt><dd data-visit-source></dd></div>
                        <div><dt>Source organization</dt><dd data-visit-organization></dd></div>
                        <div><dt>Arrived</dt><dd data-visit-arrived></dd></div>
                        <div><dt>Current status</dt><dd data-visit-status></dd></div>
                        <div><dt>Ticket fee</dt><dd data-ticket-fee></dd></div>
                    </dl>
                    <form class="billing-payment-form billing-visit-payment" data-ticket-payment-form>
                        <label for="ticket-payment-method">Payment method</label>
                        <select id="ticket-payment-method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="card">Card (simulated)</option>
                            <option value="medical_aid">Medical aid (simulated)</option>
                        </select>
                        <button class="button button-primary" type="submit" data-ticket-payment-submit>Take payment</button>
                    </form>
                    <p class="billing-note">Card and medical-aid approvals are simulated for this academic prototype.</p>
                </section>
                <p class="billing-note">Card and medical-aid approvals are simulated for this academic prototype. Successful payments create a receipt and move the visit to Nursing.</p>
            </section>
        </main>
    </div>
</body>
</html>
