<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/nursing_page.php';
$context = requireNursingPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'overview' => ['Nursing overview', 'index.php'],
    'queue' => ['Incoming patient queue', 'queue.php'],
    'completed' => ['Completed assessments', 'completed.php'],
    'referrals' => ['Doctor referrals', 'referrals.php'],
    'assessment' => ['Patient assessment', 'assessment.php'],
];
if (!isset($pages[$nursingPage])) {
    http_response_code(404);
    exit('Nursing page not found.');
}
$activePage = $nursingPage === 'assessment' ? 'queue' : $nursingPage;
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'N';
$roleLabel = ucwords(str_replace('_', ' ', (string) $staff['role_name']));
$pageTitle = $pages[$nursingPage][0];
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
    <link rel="stylesheet" href="../assets/css/nursing.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/nursing.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/nursing.js') ?>" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page nursing-page" data-base-path="<?= $escape($basePath) ?>" data-nursing-page="<?= $escape($nursingPage) ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar">
            <a class="brand-lockup sidebar-brand" href="index.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">NURSING</p>
            <nav class="side-nav" aria-label="Nursing navigation">
                <?php foreach (['overview', 'queue', 'completed', 'referrals'] as $key): ?>
                    <?php [$label, $href] = $pages[$key]; ?>
                    <a class="side-link<?= $key === $activePage ? ' active' : '' ?>" href="<?= $escape($href) ?>"<?= $key === $activePage ? ' aria-current="page"' : '' ?>>
                        <span aria-hidden="true"><?= $key === 'overview' ? '⌂' : ($key === 'queue' ? '◷' : ($key === 'completed' ? '✓' : '↗')) ?></span>
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
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Nursing / <?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span>Nursing · <?= $escape($roleLabel) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content nursing-content" aria-labelledby="nursing-heading">
                <div class="admin-notice" data-nursing-message role="status" aria-live="polite" hidden></div>
                <?php require __DIR__ . '/_' . $nursingPage . '.php'; ?>
            </section>
        </main>
    </div>
</body>
</html>