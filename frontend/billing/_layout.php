<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/billing_page.php';
$context = requireBillingPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'overview' => ['Billing overview', 'index.php'],
    'find' => ['Find patient / visit', 'find.php'],
    'ticket-payments' => ['Initial ticket payments', 'ticket-payments.php'],
    'recent-payments' => ['Recent payments', 'recent-payments.php'],
];
if (!isset($pages[$billingPage])) {
    http_response_code(404);
    exit('Billing page not found.');
}
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'C';
$pageTitle = $pages[$billingPage][0];
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
    <link rel="stylesheet" href="../assets/css/billing.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/billing.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page billing-page" data-base-path="<?= $escape($basePath) ?>" data-billing-page="<?= $escape($billingPage) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">CASHIER / BILLING</p>
            <nav class="side-nav" aria-label="Cashier navigation">
                <?php foreach ($pages as $key => [$label, $href]): ?>
                    <a class="side-link<?= $key === $billingPage ? ' active' : '' ?>" href="<?= $escape($href) ?>"<?= $key === $billingPage ? ' aria-current="page"' : '' ?>>
                        <span aria-hidden="true"><?= $key === 'overview' ? '⌂' : ($key === 'find' ? '⌕' : ($key === 'ticket-payments' ? '$' : '◷')) ?></span>
                        <?= $escape($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span class="help-icon" aria-hidden="true">i</span><div><strong>Payment approval</strong><small>Card and medical aid are simulated. Clinical visit status is not changed by cashier payments.</small></div></div>
                <span class="sidebar-version">CareBridge<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(ucwords(str_replace('_', ' ', $staff['role_name']))) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content billing-content" aria-labelledby="billing-heading">
                <div class="admin-notice" data-billing-message role="status" aria-live="polite" hidden></div>
                <?php require __DIR__ . '/_' . $billingPage . '.php'; ?>
            </section>
        </main>
    </div>
</body>
</html>