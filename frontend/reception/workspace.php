<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/reception_page.php';

$context = requireReceptionPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'overview' => ['Reception overview', 'index.php'],
    'find-patient' => ['Find patient', 'find-patient.php'],
    'register-patient' => ['Register patient', 'register-patient.php'],
    'appointments' => ['Appointments', 'appointments.php'],
    'arrivals' => ['Arrivals and check-in', 'arrivals.php'],
    'patient-records' => ['Patient records', 'patient-records.php'],
    'queue' => ['Patient queue', 'queue.php'],
    'referrals' => ['Referrals', 'referrals.php'],
];
if (!isset($pages[$receptionPage])) {
    http_response_code(404);
    exit('Reception page not found.');
}
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'R';
$roleLabel = ucwords(str_replace('_', ' ', (string) $staff['role_name']));
$pageTitle = $pages[$receptionPage][0];
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
    <link rel="stylesheet" href="../assets/css/reception.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/reception-workspace.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page reception-page" data-base-path="<?= $escape($basePath) ?>" data-reception-page="<?= $escape($receptionPage) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">RECEPTION / OPD</p>
            <nav class="side-nav" aria-label="Reception navigation">
                <?php foreach ($pages as $key => [$label, $href]): ?>
                    <a class="side-link<?= $key === $receptionPage ? ' active' : '' ?>" href="<?= $escape($href) ?>"<?= $key === $receptionPage ? ' aria-current="page"' : '' ?>>
                        <span aria-hidden="true"><?= $key === 'overview' ? '⌂' : ($key === 'appointments' ? '◷' : '＋') ?></span>
                        <?= $escape($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="staff-identity-card">
                    <span class="user-avatar"><?= $escape($initial) ?></span>
                    <div><strong><?= $escape($staff['display_name']) ?></strong><small>Staff ID <?= (int) $staff['user_id'] ?></small><small><?= $escape($roleLabel) ?> · Active</small></div>
                </div>
                <span class="sidebar-version">CareBridge<br>Academic prototype</span>
            </div>
        </aside>
        <main class="dashboard-main">
        <header class="topbar">
            <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span><?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Reception · <?= $escape($roleLabel) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content reception-content" aria-labelledby="page-heading">
                <div class="admin-welcome reception-welcome">
                    <div><p class="eyebrow">CAREBRIDGE / RECEPTION</p><h1 id="page-heading"><?= $escape($pageTitle) ?></h1><p class="dashboard-subtitle">Manage patient arrivals and keep each visit moving through the correct hospital workflow.</p></div>
                    <span class="reception-date" data-current-date></span>
                </div>
                <div class="admin-notice" data-reception-message role="status" aria-live="polite" hidden></div>
                <section class="admin-panel reception-ticket-payment" data-ticket-payment-panel hidden aria-labelledby="ticket-payment-heading">
                    <div class="admin-panel-heading">
                        <div><p class="eyebrow">ARRIVAL PAYMENT</p><h2 id="ticket-payment-heading">Ticket payment</h2><p data-ticket-payment-summary></p></div>
                    </div>
                    <dl class="reception-ticket-details">
                        <div><dt>Patient</dt><dd data-ticket-patient></dd></div>
                        <div><dt>Patient number</dt><dd data-ticket-patient-number></dd></div>
                        <div><dt>Visit ID</dt><dd data-ticket-visit-id></dd></div>
                        <div><dt>Ticket fee</dt><dd data-ticket-fee></dd></div>
                    </dl>
                    <form class="reception-ticket-form" data-ticket-payment-form hidden>
                        <label for="reception-ticket-payment-method">Payment method</label>
                        <select id="reception-ticket-payment-method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="card">Card (simulated)</option>
                            <option value="medical_aid">Medical aid (simulated)</option>
                        </select>
                        <button class="button button-primary" type="submit" data-ticket-payment-submit>Confirm payment</button>
                    </form>
                    <p class="billing-note">Card and medical-aid approvals are simulated for this academic prototype.</p>
                    <p class="reception-ticket-status" data-ticket-payment-status role="status" aria-live="polite"></p>
                    <a class="button button-outline reception-ticket-receipt" data-ticket-receipt hidden>View receipt</a>
                    <button class="button button-outline button-small" type="button" data-ticket-payment-retry hidden>Reload ticket details</button>
                </section>
                <?php require __DIR__ . '/pages/' . $receptionPage . '.php'; ?>
            </section>
        </main>
    </div>
</body>
</html>
