<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/emergency_nurse_page.php';
$context = requireEmergencyNursePage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'index' => ['Emergency Nurse dashboard', 'index.php'],
    'queue' => ['Incoming Patients', 'queue.php'],
    'assessment' => ['Patient Assessment', 'assessment.php'],
    'active' => ['Active Patients', 'active.php'],
    'handover' => ['Doctor Handover', 'handover.php'],
    'history' => ['Emergency History', 'history.php'],
];
if (!isset($pages[$nursePage])) {
    http_response_code(404);
    exit('Emergency nursing page not found.');
}
$pageContent = [
    'index' => '_nurse-index.php',
    'queue' => '_nurse-queue.php',
    'assessment' => '_nurse-assessment.php',
    'active' => '_nurse-queue.php',
    'handover' => '_nurse-queue.php',
    'history' => '_nurse-queue.php',
][$nursePage];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'E';
$pageTitle = $pages[$nursePage][0];
$canEdit = $staff['role_name'] === 'emergency_nurse';
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
    <link rel="stylesheet" href="../assets/css/emergency.css">
    <link rel="stylesheet" href="../assets/css/emergency-nurse.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/emergency-nurse.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page emergency-page emergency-nurse-page"
      data-base-path="<?= $escape($basePath) ?>"
      data-role="<?= $escape($staff['role_name']) ?>"
      data-emergency-nurse-page="<?= $escape($nursePage) ?>"
      data-can-edit="<?= $canEdit ? 'true' : 'false' ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation"></aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Emergency / <?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(ucwords(str_replace('_', ' ', $staff['role_name']))) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content emergency-content emergency-nurse-content" aria-labelledby="emergency-nurse-heading">
                <div class="admin-notice" data-emergency-nurse-message role="status" aria-live="polite" hidden></div>
                <?php require __DIR__ . '/' . $pageContent; ?>
            </section>
        </main>
    </div>
</body>
</html>
