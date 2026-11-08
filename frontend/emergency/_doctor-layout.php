<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/emergency_doctor_page.php';
$context = requireEmergencyDoctorPage();
$staff = $context['user'];
$basePath = $context['base_path'];
$pages = [
    'dashboard' => ['Emergency Doctor dashboard', 'doctor.php'],
    'queue' => ['Awaiting Doctor', 'doctor-queue.php'],
    'handovers' => ['Nurse Handovers', 'doctor-handover.php'],
    'assessment' => ['Clinical Assessment', 'doctor-assessment.php'],
    'treatment' => ['Treatment & Management', 'doctor-treatment.php'],
    'orders' => ['Investigations & Prescriptions', 'doctor-orders.php'],
    'referrals' => ['Specialist & Outside Referrals', 'doctor-referrals.php'],
    'outcomes' => ['Patient Outcomes', 'doctor-outcomes.php'],
    'history' => ['Emergency History', 'doctor-history.php'],
];
$contentFiles = [
    'dashboard' => '_doctor-dashboard.php',
    'queue' => '_doctor-list.php',
    'handovers' => '_doctor-list.php',
    'assessment' => '_doctor-assessment.php',
    'treatment' => '_doctor-treatment.php',
    'orders' => '_doctor-orders.php',
    'referrals' => '_doctor-referrals.php',
    'outcomes' => '_doctor-outcomes.php',
    'history' => '_doctor-list.php',
];
if (!isset($pages[$doctorPage], $contentFiles[$doctorPage])) {
    http_response_code(404);
    exit('Emergency Doctor page not found.');
}
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$initialMatch = [];
preg_match('/^./us', (string) $staff['display_name'], $initialMatch);
$initial = $initialMatch[0] ?? 'E';
$pageTitle = $pages[$doctorPage][0];
$canEdit = $staff['role_name'] === 'emergency_doctor';
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
    <link rel="stylesheet" href="../assets/css/emergency-doctor.css">
    <link rel="stylesheet" href="../assets/css/staff-layout.css">
    <script src="../assets/js/emergency-doctor.js" defer></script>
    <script src="../assets/js/staff-sidebar.js" defer></script>
</head>
<body class="dashboard-page admin-page emergency-page emergency-doctor-page"
      data-base-path="<?= $escape($basePath) ?>"
      data-role="<?= $escape($staff['role_name']) ?>"
      data-emergency-doctor-page="<?= $escape($doctorPage) ?>"
      data-can-edit="<?= $canEdit ? 'true' : 'false' ?>">
    <div class="dashboard-layout">
        <aside class="sidebar admin-sidebar" aria-label="CareBridge navigation">
            <a class="brand-lockup sidebar-brand" href="../dashboard.php"><span class="brand-mark" aria-hidden="true">+</span><span>Care<span class="brand-light">Bridge</span></span></a>
            <p class="sidebar-caption">CAREBRIDGE WORKSPACE</p>
        </aside>
        <main class="dashboard-main">
            <header class="topbar">
                <div class="topbar-title"><span class="mobile-brand-mark" aria-hidden="true">+</span><span>Emergency / <?= $escape($pageTitle) ?></span></div>
                <div class="topbar-actions"><span class="user-avatar"><?= $escape($initial) ?></span><div class="user-summary"><strong><?= $escape($staff['display_name']) ?></strong><span><?= $escape(ucwords(str_replace('_', ' ', $staff['role_name']))) ?></span></div><button class="button button-outline button-logout" type="button" data-sign-out>Sign out</button></div>
            </header>
            <section class="dashboard-content admin-content emergency-content emergency-doctor-content" aria-labelledby="emergency-doctor-heading">
                <div class="admin-notice" data-emergency-doctor-message role="status" aria-live="polite" hidden></div>
                <?php require __DIR__ . '/' . $contentFiles[$doctorPage]; ?>
            </section>
        </main>
    </div>
</body>
</html>
