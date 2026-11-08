<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/doctor_page.php';
$context = requireDoctorPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'dashboard' => ['Doctor dashboard', 'index.php'],
    'queue' => ['Consultation queue', 'queue.php'],
    'clinical-record' => ['Clinical record', 'clinical-record.php'],
    'consultation' => ['Consultation', 'consultation.php'],
    'history' => ['Completed consultations', 'history.php'],
    'referrals' => ['Referrals', 'referrals.php'],
];
if (!isset($pages[$doctorPage])) {
    http_response_code(404);
    exit('Doctor page not found.');
}
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'D';
$roleLabel = ucwords(str_replace('_', ' ', (string) $staff['role_name']));
$pageTitle = $pages[$doctorPage][0];
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
    <link rel="stylesheet" href="../assets/css/doctor.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/doctor.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/doctor.js') ?>" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page doctor-page" data-base-path="<?= $escape($basePath) ?>" data-doctor-page="<?= $escape($doctorPage) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">GENERAL DOCTOR</p>
            <nav class="side-nav" aria-label="Doctor navigation">
                <?php foreach ($pages as $key => [$label, $href]): ?>
                    <a class="side-link<?= $key === $doctorPage ? ' active' : '' ?>" href="<?= $escape($href) ?>"<?= $key === $doctorPage ? ' aria-current="page"' : '' ?>>
                        <span aria-hidden="true"><?= $key === 'dashboard' ? '⌂' : ($key === 'queue' ? '◷' : ($key === 'history' ? '✓' : ($key === 'referrals' ? '↗' : '+'))) ?></span>
                        <?= $escape($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom"><div class="staff-identity-card"><span class="user-avatar"><?= $escape($initial) ?></span><div><strong><?= $escape($staff['display_name']) ?></strong><small>Staff ID <?= (int) $staff['user_id'] ?></small><small><?= $escape($roleLabel) ?> · Active</small></div></div><span class="sidebar-version">CareBridge<br>Academic prototype</span></div>
        </aside>
        <main class="dashboard-main">
            <header class="topbar"><div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>General doctor / <?= $escape($pageTitle) ?></span></div><div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape($roleLabel) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div></header>
            <section class="dashboard-content admin-content doctor-content" aria-labelledby="doctor-heading">
                <div class="admin-notice" data-doctor-message role="status" aria-live="polite" hidden></div>
                <?php require __DIR__ . '/_' . $doctorPage . '.php'; ?>
            </section>
        </main>
    </div>
</body>
</html>