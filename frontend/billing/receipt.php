<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/billing_page.php';
$context = requireBillingPage();
$basePath = $context['base_path'];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Payment receipt | Hospital Patient Processing</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/billing.css">
    <script src="../assets/js/billing.js" defer></script>
</head>
<body class="dashboard-page admin-page billing-page receipt-page" data-base-path="<?= $escape($basePath) ?>" data-receipt-page>
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">BILLING</p>
            <nav class="side-nav" aria-label="Cashier navigation">
                <a class="side-link" href="index.php"><span aria-hidden="true">$</span> Ticket payments</a>
                <a class="side-link" href="../dashboard.php"><span aria-hidden="true">⌂</span> Staff workspace</a>
            </nav>
            <div class="sidebar-bottom"><span class="sidebar-version">Hospital Patient Processing<br>Academic prototype</span></div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar"><div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Payment receipt</span></div><div class="topbar-actions"><button class="button button-outline button-small" type="button" data-print>Print receipt</button><a class="button button-outline button-small" href="index.php">Back to queue</a></div></header>
            <section class="dashboard-content admin-content billing-content">
                <div class="admin-notice" data-billing-message role="status" aria-live="polite" hidden></div>
                <article class="admin-panel receipt-card" data-receipt hidden>
                    <div class="receipt-heading"><div><p class="eyebrow">PAYMENT RECEIPT</p><h1>CareBridge Hospital</h1></div><span class="billing-demo-badge" data-simulated-badge hidden>SIMULATED APPROVAL</span></div>
                    <dl class="receipt-details">
                        <div><dt>Receipt number</dt><dd data-receipt-number></dd></div>
                        <div><dt>Bill number</dt><dd data-bill-number></dd></div>
                        <div><dt>Patient</dt><dd data-patient></dd></div>
                        <div><dt>Patient number</dt><dd data-patient-number></dd></div>
                        <div><dt>Visit ID</dt><dd data-visit></dd></div>
                        <div><dt>Visit source</dt><dd data-source></dd></div>
                        <div><dt>Payment date</dt><dd data-paid-at></dd></div>
                        <div><dt>Payment method</dt><dd data-method></dd></div>
                        <div><dt>Recorded by</dt><dd data-cashier></dd></div>
                    </dl>
                    <div class="billing-table-wrap receipt-items-wrap"><table class="admin-table receipt-items"><thead><tr><th scope="col">Charge</th><th scope="col">Qty</th><th scope="col">Unit amount</th><th scope="col">Line total</th></tr></thead><tbody data-receipt-items></tbody></table></div>
                    <div class="receipt-total"><span>Paid</span><strong data-paid-amount></strong></div>
                    <p class="receipt-footnote">For this academic prototype, card and medical-aid approvals are simulated. Retain this receipt with the visit record.</p>
                </article>
            </section>
        </main>
    </div>
</body>
</html>
